<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';

require_login();

$pdo = DB::pdo();
$err = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $p1 = (string)($_POST['p1'] ?? '');
  $p2 = (string)($_POST['p2'] ?? '');

  if ($p1 === '' || $p2 === '') {
    $err = 'Completa ambos campos.';
  } elseif ($p1 !== $p2) {
    $err = 'Las contraseñas no coinciden.';
  } elseif (mb_strlen($p1) < 8) {
    $err = 'Usa al menos 8 caracteres.';
  } else {
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios_auth SET password_hash=?, must_change_password=0 WHERE usuario_id=?")
        ->execute([$hash, (int)$_SESSION['uid']]);
    $ok = 'Contraseña actualizada.';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cambiar contraseña</title>
  <style>
    body{font-family:system-ui;margin:0;background:#f4f6f8}
    .box{max-width:420px;margin:8vh auto;background:#fff;padding:22px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
    label{display:block;margin:10px 0 6px}
    input{width:100%;padding:10px;border:1px solid #d7dde3;border-radius:10px}
    button{margin-top:14px;width:100%;padding:11px;border:0;border-radius:10px;background:#111;color:#fff;cursor:pointer}
    .err{background:#ffecec;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .ok{background:#eaffea;color:#0c5a0c;padding:10px;border-radius:10px;margin-bottom:12px}
    a{color:#111}
  </style>
</head>
<body>
  <div class="box">
    <h2>Cambiar contraseña</h2>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= e($ok) ?> — <a href="/dashboard.php">Ir al dashboard</a></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <label>Nueva contraseña</label>
      <input type="password" name="p1" required>
      <label>Repetir contraseña</label>
      <input type="password" name="p2" required>
      <button type="submit">Guardar</button>
    </form>
  </div>
</body>
</html>