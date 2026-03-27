<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';

// Solo responde si hay sesión activa — si no, devuelve array vacío silenciosamente
if (!isset($_SESSION['uid'])) {
    echo json_encode(['notificaciones' => []]);
    exit;
}

$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

// Trae notificaciones no leídas creadas en los últimos 10 minutos
// (el JS consulta cada 30s, así que 10 min es más que suficiente)
$st = $pdo->prepare("
    SELECT id, titulo, mensaje, tipo,
           COALESCE(url, '/rrhh-j/public/notificaciones.php') AS url
    FROM notificaciones
    WHERE usuario_id = ?
      AND leido = 0
      AND created_at >= NOW() - INTERVAL 10 MINUTE
    ORDER BY created_at DESC
    LIMIT 5
");
$st->execute([$uid]);
$notifs = $st->fetchAll();

// Marcarlas como "en proceso" para no mandarlas dos veces
// Usamos un campo extra o simplemente las marcamos leídas aquí
// IMPORTANTE: las marcamos con leido=2 para diferenciar
// "visto en app nativa" de "leído en la web" (leido=1)
// Si preferís marcarlas leído=1 directamente, cambiá el 2 por 1
if ($notifs) {
    $ids = implode(',', array_map('intval', array_column($notifs, 'id')));
    $pdo->exec("UPDATE notificaciones SET leido=2 WHERE id IN ($ids)");
}

echo json_encode(['notificaciones' => $notifs]);