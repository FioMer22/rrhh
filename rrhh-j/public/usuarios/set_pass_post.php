<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../_layout.php';

if (!has_any_role('admin','rrhh')) {
  http_response_code(403);
  exit('No autorizado');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  exit('Método no permitido');
}

csrf_verify();

$pdo = DB::pdo();

$usuarioId = (int)($_POST['usuario_id'] ?? 0);
$pass1 = (string)($_POST['pass1'] ?? '');
$pass2 = (string)($_POST['pass2'] ?? '');
$mustChange = isset($_POST['must_change_password']) ? 1 : 0;
$unlock = isset($_POST['unlock']);

if ($usuarioId <= 0) {
  http_response_code(400);
  exit('Usuario inválido');
}

if (trim($pass1) === '' || trim($pass2) === '' || $pass1 !== $pass2) {
  // volvemos con error simple por querystring (podemos mejorar luego)
  redirect('set_pass.php?id='.$usuarioId.'&err=pass');
}

if (strlen($pass1) < 6) {
  redirect('set_pass.php?id='.$usuarioId.'&err=len');
}

// Verificar usuario existe
$st = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? LIMIT 1");
$st->execute([$usuarioId]);
if (!$st->fetchColumn()) {
  http_response_code(404);
  exit('Usuario no encontrado');
}

$hash = password_hash($pass1, PASSWORD_DEFAULT);

// UPSERT: si ya existe, UPDATE; si no existe, INSERT
// Nota: esto requiere que usuarios_auth.usuario_id sea PRIMARY o UNIQUE.
$sql = "
INSERT INTO usuarios_auth (usuario_id, password_hash, must_change_password, failed_attempts, locked_until, created_at)
VALUES (:uid, :hash, :must, :fa, :lu, NOW())
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  must_change_password = VALUES(must_change_password)
";

$fa = $unlock ? 0 : null;
$lu = $unlock ? null : null;

// Para INSERT necesitamos valores explícitos:
$st = $pdo->prepare($sql);
$st->execute([
  ':uid'  => $usuarioId,
  ':hash' => $hash,
  ':must' => $mustChange,
  ':fa'   => $unlock ? 0 : 0,   // en insert dejamos 0 por defecto
  ':lu'   => null,
]);

if ($unlock) {
  $st2 = $pdo->prepare("UPDATE usuarios_auth SET failed_attempts=0, locked_until=NULL WHERE usuario_id=?");
  $st2->execute([$usuarioId]);
}

// listo
redirect('set_pass.php?id='.$usuarioId.'&ok=1');