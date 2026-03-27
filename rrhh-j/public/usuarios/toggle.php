<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_login();
if (!has_any_role('admin','rrhh')) { http_response_code(403); exit('No autorizado'); }
header('Content-Type: text/html; charset=UTF-8');

if (!has_any_role('admin','rrhh')) {
  http_response_code(403);
  exit('No autorizado');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método no permitido'); }
csrf_verify();

$pdo = DB::pdo();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect(url('/usuarios/index.php'));

$pdo->prepare("UPDATE usuarios SET activo = IF(activo=1,0,1) WHERE id=?")->execute([$id]);

redirect(url('/usuarios/index.php'));