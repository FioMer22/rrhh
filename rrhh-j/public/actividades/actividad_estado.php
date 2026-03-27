<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../app/bootstrap.php';

$token = trim($_POST['token'] ?? '');
$actId = (int)($_POST['actividad_id'] ?? 0);

if (!$token || !$actId) {
    echo json_encode(['en_progreso' => false]);
    exit;
}

$pdo = DB::pdo();
$st  = $pdo->prepare("
    SELECT a.estado FROM actividades a
    JOIN usuarios u ON u.id = a.usuario_id
    WHERE a.id = ? AND u.widget_token = ?
    LIMIT 1
");
$st->execute([$actId, $token]);
$row = $st->fetch();

echo json_encode([
    'en_progreso' => $row && $row['estado'] === 'en_progreso'
]);