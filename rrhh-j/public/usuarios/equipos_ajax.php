<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$area_id = (int)($_GET['area_id'] ?? 0);
if ($area_id <= 0) { echo json_encode([]); exit; }

$pdo = DB::pdo();
$st  = $pdo->prepare("SELECT id, nombre FROM equipos WHERE activo=1 AND area_id=? ORDER BY nombre");
$st->execute([$area_id]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
