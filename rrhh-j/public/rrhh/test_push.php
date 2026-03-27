<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/push.php';
require_login();

header('Content-Type: text/plain; charset=UTF-8');

$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

echo "=== TEST PUSH ===\n\n";

// Ver suscripciones del usuario actual
$st = $pdo->prepare("SELECT id, LEFT(endpoint,50) AS ep, user_agent, created_at FROM push_suscripciones WHERE usuario_id=?");
$st->execute([$uid]);
$subs = $st->fetchAll();
echo "Suscripciones tuyas: " . count($subs) . "\n";
foreach ($subs as $s) {
    echo "  [{$s['id']}] {$s['ep']}... | {$s['created_at']}\n";
}
echo "\n";

// Enviar push de prueba
echo "Enviando push de prueba...\n";
try {
    push_notificar($pdo, $uid,
        '🔔 Test JR RRHH',
        '¡Las notificaciones push funcionan correctamente!',
        '/rrhh-j/public/dashboard.php'
    );
    echo "✅ Push enviado correctamente a todos tus dispositivos.\n";
    echo "   Deberías recibir la notificación en unos segundos.\n";
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}