<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_once __DIR__ . '/../app/middleware/roles.php';

require_login();
require_role('admin','rrhh');

$pdo = DB::pdo();
$err = $ok = null;

// cargar roles para selector
$roles = $pdo->query("SELECT id, nombre FROM roles_sistema ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $nombre = trim((string)($_POST['nombre'] ?? ''));
  $apellido = trim((string)($_POST['apellido'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $rolId = (int)($_POST['rol_id'] ?? 0);

  if ($nombre==='' || $apellido==='' || $email==='' || $password==='' || $rolId<=0) {
    $err = 'Completa todos los campos.';
  } else {
    // evitar duplicado email
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetch()) {
      $err = 'Ya existe un usuario con ese email.';
    } else {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO usuarios (nombre, apellido, email, activo) VALUES (?,?,?,1)")
            ->execute([$nombre, $apellido, $email]);
        $uid = (int)$pdo->lastInsertId();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO usuarios_auth (usuario_id, password_hash, must_change_password) VALUES (?,?,1)")
            ->execute([$uid, $hash]);

        $pdo->prepare("INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (?,?)")
            ->execute([$uid, $rolId]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare("
          INSERT INTO auditoria_logs (actor_id, entidad, entidad_id, accion, cambios_json, ip)
          VALUES (?, 'usuarios', ?, 'crear', JSON_OBJECT('email', ?, 'rol_id', ?), ?)
        ")->execute([(int)$_SESSION['uid'], $uid, $email, $rolId, $ip]);

        $pdo->commit();
        $ok = 'Usuario creado.';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = 'Error al crear usuario: ' . $e->getMessage();
      }
    }
  }
}

$usuarios = $pdo->query("
  SELECT u.id, u.nombre, u.apellido, u.email, u.activo,
         GROUP_CONCAT(r.nombre ORDER BY r.nombre SEPARATOR ', ') AS roles
  FROM usuarios u
  LEFT JOIN usuarios_roles ur ON ur.usuario_id = u.id
  LEFT JOIN roles_sistema r ON r.id = ur.rol_id
  GROUP BY u.id
  ORDER BY u.id DESC
  LIMIT 200
")->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Usuarios</title>
  <style>
    body{font-family:system-ui;margin:0;background:#f4f6f8}
    .wrap{max-width:1100px;margin:18px auto;padding:0 14px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    input,select{width:100%;padding:10px;border:1px solid #d7dde3;border-radius:10px}
    .grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
    .err{background:#ffecec;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .ok{background:#eaffea;color:#0c5a0c;padding:10px;border-radius:10px;margin-bottom:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
    th{font-size:12px;opacity:.7}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#111;color:#fff;font-size:12px}
  </style>
</head>
<body>
  <?php require __DIR__ . '/_layout.php'; ?>
  <div class="wrap">

    <div class="card">
      <h2>Crear usuario</h2>
      <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?= e($ok) ?></div><?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <div class="grid">
          <div>
            <label>Nombre</label>
            <input name="nombre" required>
          </div>
          <div>
            <label>Apellido</label>
            <input name="apellido" required>
          </div>
          <div>
            <label>Email</label>
            <input type="email" name="email" required>
          </div>
          <div>
            <label>Contraseña (temporal)</label>
            <input type="password" name="password" required>
          </div>
          <div>
            <label>Rol</label>
            <select name="rol_id" required>
              <option value="">Elegir...</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p><button style="margin-top:10px;padding:10px 12px;border-radius:10px;background:#111;color:#fff;border:0;cursor:pointer">Crear</button></p>
      </form>
    </div>

    <div class="card">
      <h2>Listado</h2>
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Nombre</th><th>Email</th><th>Roles</th><th>Activo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= e($u['nombre'].' '.$u['apellido']) ?></td>
              <td><?= e($u['email']) ?></td>
              <td><?= e($u['roles'] ?? '') ?></td>
              <td><?= ((int)$u['activo']===1) ? '<span class="pill">Sí</span>' : 'No' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</body>
</html>