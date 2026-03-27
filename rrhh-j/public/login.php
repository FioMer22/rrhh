<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = DB::pdo();
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $err = 'Completa email y contraseña.';
  } else {
    $st = $pdo->prepare("
      SELECT u.id, u.nombre, u.apellido, u.email, u.activo, u.rol_base_id,
             ua.password_hash, ua.must_change_password, ua.failed_attempts, ua.locked_until
      FROM usuarios u
      JOIN usuarios_auth ua ON ua.usuario_id = u.id
      WHERE u.email = ?
      LIMIT 1
    ");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['activo'] !== 1) {
      $err = 'Credenciales inválidas.';
    } else {
      if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
        $err = 'Cuenta bloqueada temporalmente. Intenta más tarde.';
      } elseif (!password_verify($pass, (string)$row['password_hash'])) {
        $failed = (int)$row['failed_attempts'] + 1;
        $lockedUntil = null;

        // 5 intentos => bloqueo 10 minutos
        if ($failed >= 5) {
          $lockedUntil = date('Y-m-d H:i:s', time() + 10 * 60);
          $failed = 0;
        }

        $pdo->prepare("UPDATE usuarios_auth SET failed_attempts=?, locked_until=? WHERE usuario_id=?")
            ->execute([$failed, $lockedUntil, (int)$row['id']]);

        $err = 'Credenciales inválidas.';
      } else {
        // reset + last login
        $pdo->prepare("UPDATE usuarios_auth SET failed_attempts=0, locked_until=NULL, last_login_at=NOW() WHERE usuario_id=?")
            ->execute([(int)$row['id']]);

        session_regenerate_id(true);

        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['email'] = (string)$row['email'];
        $_SESSION['nombre'] = (string)($row['nombre'] ?? '');
        $_SESSION['apellido'] = (string)($row['apellido'] ?? '');

        // ===== ROLES (rol base + roles extra) =====
        $roles = [];

        // 1) Rol base desde usuarios.rol_base_id
        $baseId = (int)($row['rol_base_id'] ?? 0);
        if ($baseId > 0) {
          $rb = $pdo->prepare("SELECT nombre FROM roles_sistema WHERE id=? LIMIT 1");
          $rb->execute([$baseId]);
          $base = $rb->fetchColumn();
          if ($base) $roles[] = (string)$base;
        }

        // 2) Roles extra desde tabla puente usuarios_roles
        // (si no existe la tabla o está vacía, no pasa nada)
        try {
          $rolesSt = $pdo->prepare("
            SELECT r.nombre
            FROM usuarios_roles ur
            JOIN roles_sistema r ON r.id = ur.rol_id
            WHERE ur.usuario_id = ?
          ");
          $rolesSt->execute([(int)$row['id']]);
          $extras = array_map(
            fn($x) => (string)($x['nombre'] ?? ''),
            $rolesSt->fetchAll(PDO::FETCH_ASSOC)
          );
          $roles = array_merge($roles, $extras);
        } catch (Throwable $e) {
          // Si aún no existe usuarios_roles en este entorno, ignorar
        }

        // Normalizar: únicos + sin vacíos
        $roles = array_values(array_unique(array_filter($roles, fn($r) => $r !== '')));
        $_SESSION['roles'] = $roles;
        // ===== FIN ROLES =====

        $widgetToken = bin2hex(random_bytes(32));
        $_SESSION['widget_token'] = $widgetToken;

        // Guardarlo también en la tabla usuarios
        $pdo->prepare("UPDATE usuarios SET widget_token=? WHERE id=?")
            ->execute([$widgetToken, (int)$row['id']]);


        // auditoría
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare("
          INSERT INTO auditoria_logs (actor_id, entidad, entidad_id, accion, cambios_json, ip)
          VALUES (?, 'usuarios', ?, 'login', NULL, ?)
        ")->execute([(int)$row['id'], (int)$row['id'], $ip]);

        if ((int)$row['must_change_password'] === 1) {
          redirect('/cambiar_password.php');
        }

        $_SESSION['pending_widget_token'] = $widgetToken;
        $_SESSION['pending_widget_uid']   = (int)$row['id'];
        redirect('/dashboard.php');
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login — JR RRHH</title>
  <?php require_once __DIR__ . '/_pwa_head.php'; ?>
  <style>
    body{font-family:system-ui;margin:0;background:#f4f6f8}
    .box{max-width:380px;margin:8vh auto;background:#fff;padding:22px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
    .logo{text-align:center;margin-bottom:16px}
    .logo img{height:48px;opacity:.9}
    label{display:block;margin:10px 0 6px;font-size:14px;color:#374151}
    input[type=email],input[type=password]{width:100%;padding:10px;border:1px solid #d7dde3;border-radius:10px;box-sizing:border-box;font-size:15px}
    .remember{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#6b7280;cursor:pointer}
    .remember input{width:auto;margin:0}
    button{margin-top:14px;width:100%;padding:11px;border:0;border-radius:10px;background:#0b1f3a;color:#fff;cursor:pointer;font-size:15px;font-weight:600}
    button:hover{background:#1a3a6b}
    .err{background:#ffecec;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px;font-size:14px}
    h2{margin:0 0 16px;font-size:20px;color:#0b1f3a;text-align:center}
  </style>
</head>
<body>
  <div class="box">
    <div class="logo">
      <img src="<?= url('/assets/img/logo-jr.png') ?>" alt="JR" onerror="this.style.display='none'">
    </div>
    <h2>JR RRHH</h2>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>

    <form method="post" id="loginForm">
      <?= csrf_field() ?>
      <label>Email</label>
      <input type="email" name="email" id="email" required autocomplete="username">
      <label>Contraseña</label>
      <input type="password" name="password" id="password" required autocomplete="current-password">
      <label class="remember">
        <input type="checkbox" id="recordar">
        Recordar mis datos en este dispositivo
      </label>
      <button type="submit">Entrar</button>
    </form>
  </div>

  <script>
    // Clave única para este sistema (no colisiona con otros sistemas del dominio)
    const STORAGE_KEY = 'jr_rrhh_creds';

    // Al cargar: restaurar credenciales guardadas
    (function() {
      try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
          const { email, password } = JSON.parse(saved);
          document.getElementById('email').value    = email    || '';
          document.getElementById('password').value = password || '';
          document.getElementById('recordar').checked = true;
        }
      } catch(e) {}
    })();

    // Al enviar: guardar o borrar según checkbox
    document.getElementById('loginForm').addEventListener('submit', function() {
      try {
        if (document.getElementById('recordar').checked) {
          localStorage.setItem(STORAGE_KEY, JSON.stringify({
            email:    document.getElementById('email').value,
            password: document.getElementById('password').value
          }));
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
      } catch(e) {}
    });
  </script>
</body>
</html>