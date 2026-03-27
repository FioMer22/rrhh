<?php
// Subir a /new-site/rrhh-j/ y abrir en navegador
// Detecta si Composer y sus dependencias funcionan

header('Content-Type: text/plain; charset=UTF-8');

echo "=== TEST ENTORNO PHP ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS . "\n\n";

// Verificar extensiones necesarias para Web Push
$ext = ['curl', 'openssl', 'mbstring', 'json', 'gmp'];
foreach ($ext as $e) {
    echo "ext-$e: " . (extension_loaded($e) ? "✓ OK" : "✗ FALTA") . "\n";
}

echo "\n=== OPENSSL ===\n";
echo "OpenSSL version: " . OPENSSL_VERSION_TEXT . "\n";

// Verificar si se puede crear clave EC (necesario para VAPID)
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
echo "EC P-256 keygen: " . ($key ? "✓ OK" : "✗ FALLA") . "\n";

// Verificar AES-128-GCM
echo "AES-128-GCM: " . (in_array('aes-128-gcm', openssl_get_cipher_methods()) ? "✓ OK" : "✗ FALLA") . "\n";

echo "\n=== COMPOSER ===\n";
// Verificar si vendor/autoload.php existe
$autoload = __DIR__ . '/vendor/autoload.php';
echo "vendor/autoload.php: " . (file_exists($autoload) ? "✓ EXISTS" : "✗ NO EXISTE") . "\n";

// Verificar si minishlink/web-push está instalado
if (file_exists($autoload)) {
    require $autoload;
    echo "web-push library: " . (class_exists('\Minishlink\WebPush\WebPush') ? "✓ INSTALADA" : "✗ NO INSTALADA") . "\n";
}

echo "\n=== CURL ===\n";
$ch = curl_init('https://fcm.googleapis.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Acceso HTTPS externo (FCM): " . ($code > 0 ? "✓ OK (HTTP $code)" : "✗ SIN ACCESO") . "\n";

// Verificar acceso a servicios push
$ch = curl_init('https://fcm.googleapis.com/fcm/send');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
echo "SSL: " . (curl_errno($ch) === 0 ? "✓ OK" : "✗ ERROR: " . curl_error($ch)) . "\n";
curl_close($ch);

echo "\nListo.\n";