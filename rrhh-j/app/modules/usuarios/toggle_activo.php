<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método no permitido'); }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) {
  http_response_code(403); exit('CSRF inválido');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

$pdo->prepare("UPDATE usuarios SET activo = IF(activo=1,0,1) WHERE id=?")->execute([$id]);

header("Location: index.php");
exit;