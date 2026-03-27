<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap.php';

// 🔥 MISMA lógica que tu sistema web
function can_action(?string $lastTipo, string $action): bool {
    if ($lastTipo === null)              return $action === 'inicio_jornada';
    if ($lastTipo === 'inicio_jornada') return in_array($action, ['pausa_inicio','fin_jornada'], true);
    if ($lastTipo === 'pausa_inicio')   return $action === 'pausa_fin'; //return in_array($action, ['pausa_fin','fin_jornada'], true);
    if ($lastTipo === 'pausa_fin')      return in_array($action, ['pausa_inicio','fin_jornada'], true);
    if ($lastTipo === 'fin_jornada')    return false;
    return false;
}

$token = $_POST['token'] ?? '';

if (!$token) {
    echo json_encode(['ok' => false]);
    exit;
}

$pdo = DB::pdo();

// Buscar usuario por token
$st = $pdo->prepare("SELECT id FROM usuarios WHERE widget_token=? LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    echo json_encode(['ok' => false]);
    exit;
}

$uid = (int)$user['id'];

// ✅ IMPORTANTE: mismo ORDER BY que tu sistema
$st2 = $pdo->prepare("
    SELECT tipo FROM asistencia_marcas
    WHERE usuario_id=? AND DATE(fecha_hora)=CURDATE()
    ORDER BY fecha_hora DESC, id DESC
    LIMIT 1
");
$st2->execute([$uid]);
$row = $st2->fetch();
$lastTipo = $row ? $row['tipo'] : null;

// 🚀 Reemplazo total del switch
$canEntrada = can_action($lastTipo, 'inicio_jornada');
$canSalida  = can_action($lastTipo, 'fin_jornada');

$hora = date('H:i');

echo json_encode([
    'ok' => true,
    'canEntrada' => $canEntrada,
    'canSalida'  => $canSalida,
    'lastTipo'   => $lastTipo,
    "serverTime" => $hora,
    "tipo_entrada" => $lastTipo
]);