<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_login();

header('Content-Type: text/plain; charset=UTF-8');

$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

require_once __DIR__ . '/../../app/helpers/push.php';

// Test 1: Push SIN payload (body vacío) — el SW mostrará notificación genérica
$st = $pdo->prepare("SELECT * FROM push_suscripciones WHERE usuario_id=? LIMIT 1");
$st->execute([$uid]);
$sub = $st->fetch();

if (!$sub) { echo "Sin suscripciones\n"; exit; }

echo "Enviando push SIN payload...\n";
try {
    // Construir JWT VAPID
    $audience = parse_url($sub['endpoint'], PHP_URL_SCHEME) . '://' . parse_url($sub['endpoint'], PHP_URL_HOST);
    $jwt = push_make_jwt($audience);

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . $jwt . ',k=' . VAPID_PUBLIC,
            'TTL: 86400',
            'Content-Length: 0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "HTTP: $code\n";
    echo "Response: $resp\n";
    if ($err) echo "cURL error: $err\n";
    if ($code === 201 || $code === 200) {
        echo "\n✅ Push enviado. Si no llega la notif, el problema es el cifrado del payload.\n";
    } elseif ($code === 400) {
        echo "\n❌ Error 400 — problema con VAPID JWT o formato.\n";
    } elseif ($code === 401) {
        echo "\n❌ Error 401 — VAPID keys incorrectas.\n";
    } elseif ($code === 410 || $code === 404) {
        echo "\n❌ Suscripción expirada.\n";
    }
} catch (Throwable $e) {
    echo "Excepción: " . $e->getMessage() . "\n";
}