<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../_layout.php';

if (!has_any_role('admin','rrhh')) {
  http_response_code(403);
  exit('No autorizado');
}

$pdo = DB::pdo();

// Traer usuarios (activos primero)
$users = $pdo->query("
  SELECT id, nombre, apellido, email, activo
  FROM usuarios
  ORDER BY activo DESC, nombre ASC, apellido ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Mapa auth existente
$authRows = $pdo->query("
  SELECT usuario_id, must_change_password, last_login_at, failed_attempts, locked_until
  FROM usuarios_auth
")->fetchAll(PDO::FETCH_ASSOC);

$authMap = [];
foreach ($authRows as $a) {
  $authMap[(int)$a['usuario_id']] = $a;
}

$selectedId = (int)($_GET['id'] ?? 0);
?>
<script src="https://cdn.tailwindcss.com"></script>

<div class="max-w-2xl mx-auto p-4">
  <div class="bg-white rounded-2xl shadow p-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">Contraseñas</h1>
        <p class="text-sm text-gray-600 mt-1">
          Se guardan en <code>usuarios_auth.password_hash</code>.
        </p>
      </div>
      <a href="index.php" class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">Volver</a>
    </div>

    <form method="get" class="mt-4 flex gap-2">
      <select name="id" class="flex-1 border rounded-lg px-3 py-2">
        <option value="0">— Seleccionar usuario —</option>
        <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
          <option value="<?= $uid ?>" <?= $uid===$selectedId?'selected':'' ?>>
            <?= $uid ?> — <?= e(($u['nombre'] ?? '').' '.($u['apellido'] ?? '')) ?> (<?= e($u['email'] ?? '') ?>)
            <?= ((int)$u['activo']===1)?'':'[INACTIVO]' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="px-4 py-2 rounded-lg bg-gray-900 text-white">Ver</button>
    </form>

    <?php if ($selectedId > 0): ?>
      <?php
        $u = null;
        foreach ($users as $row) { if ((int)$row['id'] === $selectedId) { $u = $row; break; } }
        if (!$u) {
          echo '<div class="mt-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">Usuario no encontrado.</div>';
        } else {
          $auth = $authMap[$selectedId] ?? null;
      ?>
      <div class="mt-5 rounded-xl border p-4">
        <div class="text-sm">
          <div class="font-semibold"><?= e(($u['nombre'] ?? '').' '.($u['apellido'] ?? '')) ?></div>
          <div class="text-gray-600"><?= e($u['email'] ?? '') ?> · ID <?= (int)$u['id'] ?></div>
          <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-xs text-gray-600">
            <div><b>Auth existe:</b> <?= $auth ? 'Sí' : 'No' ?></div>
            <div><b>Must change:</b> <?= $auth ? (int)$auth['must_change_password'] : 0 ?></div>
            <div><b>Last login:</b> <?= $auth ? e($auth['last_login_at'] ?? '') : '' ?></div>
            <div><b>Failed attempts:</b> <?= $auth ? (int)($auth['failed_attempts'] ?? 0) : 0 ?></div>
            <div><b>Locked until:</b> <?= $auth ? e($auth['locked_until'] ?? '') : '' ?></div>
          </div>
        </div>

        <form method="post" action="set_pass_post.php" class="mt-4 space-y-4">
          <?= csrf_field() ?>
          <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium mb-1">Nueva contraseña</label>
              <input type="password" name="pass1" class="w-full border rounded-lg px-3 py-2" minlength="6" required>
              <div class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres.</div>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Confirmar contraseña</label>
              <input type="password" name="pass2" class="w-full border rounded-lg px-3 py-2" minlength="6" required>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-4">
            <label class="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="must_change_password" value="1" class="h-4 w-4"
                <?= ($auth && (int)$auth['must_change_password']===1) ? 'checked' : '' ?>>
              Forzar cambio al próximo login
            </label>

            <label class="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="unlock" value="1" class="h-4 w-4">
              Desbloquear (failed_attempts=0 y locked_until=NULL)
            </label>
          </div>

          <div class="flex gap-2">
            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">
              Guardar contraseña
            </button>
            <a href="set_pass.php?id=<?= (int)$u['id'] ?>" class="px-4 py-2 rounded-lg bg-gray-100">Cancelar</a>
          </div>
        </form>
      </div>
      <?php } ?>
    <?php else: ?>
      <div class="mt-5 p-4 rounded-xl bg-gray-50 border text-sm text-gray-700">
        Seleccioná un usuario para asignar o cambiar su contraseña.
      </div>
    <?php endif; ?>
  </div>
</div>