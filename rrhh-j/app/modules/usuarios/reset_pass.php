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

$pwdCol = null;
$cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
  $f = strtolower((string)$c['Field']);
  if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
    $pwdCol = $c['Field'];
    break;
  }
}
if (!$pwdCol) { http_response_code(400); exit('No existe columna de contraseña detectada'); }

$tmp = substr(bin2hex(random_bytes(8)), 0, 10);
$hash = password_hash($tmp, PASSWORD_DEFAULT);

$pdo->prepare("UPDATE usuarios SET {$pwdCol}=? WHERE id=?")->execute([$hash, $id]);

header('Content-Type: text/plain; charset=UTF-8');
echo "OK. Contraseña temporal para usuario ID {$id}: {$tmp}\n";