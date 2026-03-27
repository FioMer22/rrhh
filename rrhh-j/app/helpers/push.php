<?php
/**
 * push.php — Web Push con RFC 8291 (aes128gcm) + VAPID RFC 8292
 * PHP 8 + OpenSSL 3 + GMP. Sin dependencias externas.
 */
declare(strict_types=1);

if (!defined('VAPID_PUBLIC'))  define('VAPID_PUBLIC',  'BCAT8a2TX2aIBbb5mWFDwbv_VH01psYljAS-nFmzRNQlNCV5zjD3vwIJT95pMC3R39ArLLYs5ApGkX0uaLdg_Cc');
if (!defined('VAPID_PRIVATE')) define('VAPID_PRIVATE', '63PCAPVb6tQX-HDQ8xnzGnfVCV7AxwIesyw9r5MSGiE');
if (!defined('VAPID_SUBJECT')) define('VAPID_SUBJECT', 'mailto:israel@jesusresponde.com');

// ── API pública ───────────────────────────────────────────────────────────────

function push_notificar(PDO $pdo, int|array $usuario_ids, string $titulo, string $cuerpo, string $url = '/rrhh-j/public/dashboard.php'): void {
    if (is_int($usuario_ids)) $usuario_ids = [$usuario_ids];
    if (empty($usuario_ids)) return;
    $ph = implode(',', array_fill(0, count($usuario_ids), '?'));
    $st = $pdo->prepare("SELECT * FROM push_suscripciones WHERE usuario_id IN ($ph)");
    $st->execute(array_values($usuario_ids));
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($subs)) return;
    $payload = json_encode([
        'title' => $titulo,
        'body'  => $cuerpo,
        'url'   => $url,
        'icon'  => '/rrhh-j/public/assets/img/jr-icon-192.png',
        'badge' => '/rrhh-j/public/assets/img/jr-icon-192.png',
    ]);
    foreach ($subs as $sub) {
        try {
            push_enviar($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '410') || str_contains($msg, '404') || str_contains($msg, '401')) {
                $pdo->prepare("DELETE FROM push_suscripciones WHERE id=?")->execute([$sub['id']]);
            }
        }
    }
}

function push_notificar_admins(PDO $pdo, string $titulo, string $cuerpo, string $url = '/rrhh-j/public/dashboard.php'): void {
    try {
        $st = $pdo->query("SELECT DISTINCT ur.usuario_id FROM usuarios_roles ur JOIN roles_sistema r ON r.id=ur.rol_id WHERE r.nombre IN ('admin','rrhh')");
        $ids = array_map('intval', array_column($st->fetchAll(), 'usuario_id'));
        if ($ids) push_notificar($pdo, $ids, $titulo, $cuerpo, $url);
    } catch (Throwable) {}
}

// ── Motor Web Push RFC 8291 (aes128gcm) ──────────────────────────────────────

function push_enviar(string $endpoint, string $p256dh, string $auth, string $payload): void {
    // 1. Cifrar con aes128gcm (RFC 8291)
    $enc = push_encrypt_8291($payload, $p256dh, $auth);

    // 2. JWT VAPID
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $jwt = push_make_jwt($audience);

    // 3. Enviar
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $enc['ciphertext'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . $jwt . ',k=' . VAPID_PUBLIC,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Content-Length: ' . strlen($enc['ciphertext']),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) throw new RuntimeException("cURL: $curlErr");
    if ($httpCode >= 400) throw new RuntimeException("HTTP $httpCode: $resp");
}

/**
 * RFC 8291 — cifrado aes128gcm para Web Push.
 * Retorna ciphertext con el header RFC 8291 incluido.
 */
function push_encrypt_8291(string $plaintext, string $p256dh, string $auth): array {
    // Clave pública del suscriptor
    $recvPub  = push_b64d($p256dh);
    $authBytes = push_b64d($auth);

    // Clave efímera EC P-256
    $ephKey     = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ephDetails = openssl_pkey_get_details($ephKey);
    $ephX = str_pad($ephDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $ephY = str_pad($ephDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $ephPub = "\x04" . $ephX . $ephY; // 65 bytes uncompressed

    // ECDH shared secret
    $recvDetails = openssl_pkey_get_details(openssl_pkey_get_public(push_pub_to_pem($recvPub)));
    $sharedSecret = push_ecdh($ephKey, $recvDetails['ec']['x'], $recvDetails['ec']['y']);

    // Salt 16 bytes
    $salt = random_bytes(16);

    // HKDF — RFC 8291 key derivation
    // IKM = ECDH with auth as salt
    $ikm = push_hkdf_extract($authBytes, $sharedSecret);

    // PRK usando el IKM extraído con los parámetros de contexto
    $keyInfo   = "WebPush: info\x00" . $recvPub . $ephPub;
    $prk = push_hkdf_expand($ikm, $keyInfo, 32);

    $prk2 = push_hkdf_extract($salt, $prk);
    $cek   = push_hkdf_expand($prk2, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = push_hkdf_expand($prk2, "Content-Encoding: nonce\x00",     12);

    // Padding + cifrado AES-128-GCM
    // RFC 8291: delimiter 0x02 al final del plaintext antes de cifrar
    $padded = $plaintext . "\x02";
    $tag = '';
    $ct  = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

    // RFC 8291 header: salt(16) + rs(4) + idlen(1) + keyid(65)
    $rs     = pack('N', strlen($ct . $tag) + 16 + 1); // record size
    $header = $salt                                    // salt: 16 bytes
            . pack('N', 4096)                          // rs: record size (4096 default)
            . chr(65)                                  // idlen: longitud de ephPub
            . $ephPub;                                 // keyid: ephemeral public key

    return ['ciphertext' => $header . $ct . $tag];
}

// ── JWT VAPID ─────────────────────────────────────────────────────────────────

function push_make_jwt(string $audience): string {
    $header  = push_b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = push_b64(json_encode(['aud' => $audience, 'exp' => time() + 3600, 'sub' => VAPID_SUBJECT]));
    $signing = "$header.$payload";
    $rawSig  = push_es256_sign($signing, push_b64d(VAPID_PRIVATE), push_b64d(VAPID_PUBLIC));
    return "$signing." . push_b64($rawSig);
}

function push_es256_sign(string $msg, string $privBytes, string $pubBytes): string {
    $p  = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF', 16);
    $n  = gmp_init('FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551', 16);
    $a  = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFC', 16);
    $Gx = gmp_init('6B17D1F2E12C4247F8BCE6E563A440F277037D812DEB33A0F4A13945D898C296', 16);
    $Gy = gmp_init('4FE342E2FE1A7F9B8EE7EB4A7C0F9E162BCE33576B315ECECBB6406837BF51F5', 16);
    $G  = [$Gx, $Gy];
    $d  = gmp_import($privBytes);
    $z  = gmp_init(bin2hex(hash('sha256', $msg, true)), 16);
    $k  = push_rfc6979($privBytes, hash('sha256', $msg, true), $n);
    [$rx] = push_ec_mul($k, $G, $p, $a);
    $r = gmp_mod($rx, $n);
    $s = gmp_mod(gmp_mul(gmp_invert($k, $n), gmp_mod(gmp_add($z, gmp_mul($r, $d)), $n)), $n);
    if (gmp_cmp($s, gmp_div($n, 2)) > 0) $s = gmp_sub($n, $s);
    return str_pad(gmp_export($r), 32, "\x00", STR_PAD_LEFT)
         . str_pad(gmp_export($s), 32, "\x00", STR_PAD_LEFT);
}

function push_rfc6979(string $privBytes, string $msgHash, GMP $n): GMP {
    $x  = str_pad($privBytes, 32, "\x00", STR_PAD_LEFT);
    $h1 = str_pad($msgHash,   32, "\x00", STR_PAD_LEFT);
    $V  = str_repeat("\x01", 32);
    $K  = str_repeat("\x00", 32);
    $K  = hash_hmac('sha256', $V . "\x00" . $x . $h1, $K, true);
    $V  = hash_hmac('sha256', $V, $K, true);
    $K  = hash_hmac('sha256', $V . "\x01" . $x . $h1, $K, true);
    $V  = hash_hmac('sha256', $V, $K, true);
    while (true) {
        $V = hash_hmac('sha256', $V, $K, true);
        $k = gmp_init(bin2hex($V), 16);
        if (gmp_cmp($k, gmp_init(1)) >= 0 && gmp_cmp($k, gmp_sub($n, gmp_init(1))) <= 0) return $k;
        $K = hash_hmac('sha256', $V . "\x00", $K, true);
        $V = hash_hmac('sha256', $V, $K, true);
    }
}

// ── Helpers EC ───────────────────────────────────────────────────────────────

function push_ecdh($ephPrivKey, string $recvX, string $recvY): string {
    // Reconstruir punto público receptor
    $pubBytes = "\x04" . str_pad($recvX, 32, "\x00", STR_PAD_LEFT)
                       . str_pad($recvY, 32, "\x00", STR_PAD_LEFT);

    // Exportar clave privada efímera
    openssl_pkey_export($ephPrivKey, $privPem);
    $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($privPem));
    $dBytes = str_pad($privDetails['ec']['d'] ?? '', 32, "\x00", STR_PAD_LEFT);

    // Clave pública receptora como objeto OpenSSL
    $recvPem = push_pub_to_pem($pubBytes);
    $recvKey = openssl_pkey_get_public($recvPem);
    $recvDetails = openssl_pkey_get_details($recvKey);

    // Multiplicación escalar via GMP: d * Q
    $p  = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF', 16);
    $a  = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFC', 16);
    $d  = gmp_import($dBytes);
    $Qx = gmp_import(str_pad($recvDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT));
    $Qy = gmp_import(str_pad($recvDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT));

    [$Rx] = push_ec_mul($d, [$Qx, $Qy], $p, $a);
    return str_pad(gmp_export($Rx), 32, "\x00", STR_PAD_LEFT);
}

function push_ec_mul(GMP $k, array $P, GMP $p, GMP $a): array {
    $R = null;
    $bits = gmp_strval($k, 2);
    for ($i = 0; $i < strlen($bits); $i++) {
        if ($R !== null) $R = push_ec_double($R, $p, $a);
        if ($bits[$i] === '1') $R = ($R === null) ? $P : push_ec_add($R, $P, $p);
    }
    return $R ?? [gmp_init(0), gmp_init(0)];
}

function push_ec_double(array $P, GMP $p, GMP $a): array {
    [$x, $y] = $P;
    $m  = gmp_mod(gmp_mul(gmp_add(gmp_mul(gmp_init(3), gmp_powm($x, gmp_init(2), $p)), $a), gmp_invert(gmp_mul(gmp_init(2), $y), $p)), $p);
    $xr = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, gmp_init(2), $p), $x), $x), $p);
    $yr = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($x, $xr)), $y), $p);
    return [$xr, $yr];
}

function push_ec_add(array $P, array $Q, GMP $p): array {
    [$px,$py] = $P; [$qx,$qy] = $Q;
    $m  = gmp_mod(gmp_mul(gmp_sub($qy, $py), gmp_invert(gmp_sub($qx, $px), $p)), $p);
    $xr = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, gmp_init(2), $p), $px), $qx), $p);
    $yr = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($px, $xr)), $py), $p);
    return [$xr, $yr];
}

// ── Helpers criptográficos ────────────────────────────────────────────────────

function push_b64(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function push_b64d(string $d): string {
    $p = strlen($d) % 4;
    if ($p) $d .= str_repeat('=', 4 - $p);
    return base64_decode(strtr($d, '-_', '+/'));
}

function push_hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function push_hkdf_expand(string $prk, string $info, int $len): string {
    $out = ''; $T = ''; $i = 1;
    while (strlen($out) < $len) { $T = hash_hmac('sha256', $T . $info . chr($i++), $prk, true); $out .= $T; }
    return substr($out, 0, $len);
}

function push_pub_to_pem(string $pub): string {
    $alg = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $bs  = "\x03" . push_asn1len(strlen($pub) + 1) . "\x00" . $pub;
    $der = "\x30" . push_asn1len(strlen($alg) + strlen($bs)) . $alg . $bs;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
}

function push_asn1len(int $n): string {
    if ($n < 128) return chr($n);
    if ($n < 256) return "\x81" . chr($n);
    return "\x82" . chr($n >> 8) . chr($n & 0xFF);
}