<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';

require_login();
require_role('admin', 'rrhh');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/ausencias/index.php'); }
csrf_verify();

$pdo    = DB::pdo();
$id     = (int)($_POST['id'] ?? 0);
$accion = $_POST['accion'] ?? '';
$actor  = (int)$_SESSION['uid'];

if ($id <= 0 || !in_array($accion, ['aprobar','rechazar'], true)) {
    redirect('/ausencias/index.php');
}

$nuevoEstado = $accion === 'aprobar' ? 'aprobado' : 'rechazado'; // enum real: aprobado/rechazado

$pdo->prepare("
    UPDATE solicitudes_ausencia
    SET estado = ?, aprobador_id = ?, aprobado_en = NOW()
    WHERE id = ? AND estado = 'pendiente'
")->execute([$nuevoEstado, $actor, $id]);

redirect('/ausencias/index.php');
