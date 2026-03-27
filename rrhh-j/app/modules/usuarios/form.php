<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../_layout.php';

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function csrf(): string { return $_SESSION['csrf']; }
function u($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit = $id > 0;

$pwdCol = null;
$cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
  $f = strtolower((string)$c['Field']);
  if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
    $pwdCol = $c['Field'];
    break;
  }
}

$usuario = [
  'id'=>null,'nombre'=>'','apellido'=>'','email'=>'','telefono'=>null,'foto_url'=>null,
  'activo'=>1,'area_id'=>null,'equipo_id'=>null,'sede_id'=>null,'jefe_id'=>null,'rol_base_id'=>null
];

if ($edit) {
  $st = $pdo->prepare("SELECT id,nombre,apellido,email,telefono,foto_url,activo,area_id,equipo_id,sede_id,jefe_id,rol_base_id
                       FROM usuarios WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); die('Usuario no encontrado'); }
  $usuario = $row;
}

layout_header($edit ? 'Editar usuario' : 'Nuevo usuario');
?>
<div class="max-w-3xl mx-auto p-4">
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-semibold"><?= $edit ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
    <a href="index.php" class="px-4 py-2 rounded bg-gray-100 hover:bg-gray-200">← Volver</a>
  </div>

  <form class="mt-4 bg-white rounded-xl shadow p-4 space-y-4" method="post" action="guardar.php">
    <input type="hidden" name="csrf" value="<?=u(csrf())?>">
    <input type="hidden" name="id" value="<?= (int)($usuario['id'] ?? 0) ?>">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm text-gray-600 mb-1">Nombre</label>
        <input name="nombre" required class="w-full border rounded px-3 py-2" value="<?=u($usuario['nombre'] ?? '')?>">
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Apellido</label>
        <input name="apellido" required class="w-full border rounded px-3 py-2" value="<?=u($usuario['apellido'] ?? '')?>">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1">Email (usuario)</label>
        <input type="email" name="email" required class="w-full border rounded px-3 py-2" value="<?=u($usuario['email'] ?? '')?>">
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Teléfono</label>
        <input name="telefono" class="w-full border rounded px-3 py-2" value="<?=u($usuario['telefono'] ?? '')?>">
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Foto URL</label>
        <input name="foto_url" class="w-full border rounded px-3 py-2" value="<?=u($usuario['foto_url'] ?? '')?>">
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Área ID</label>
        <input name="area_id" type="number" class="w-full border rounded px-3 py-2" value="<?=u($usuario['area_id'] ?? '')?>">
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Equipo ID</label>
        <input name="equipo_id" type="number" class="w-full border rounded px-3 py-2" value="<?=u($usuario['equipo_id'] ?? '')?>">
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Sede ID</label>
        <input name="sede_id" type="number" class="w-full border rounded px-3 py-2" value="<?=u($usuario['sede_id'] ?? '')?>">
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Jefe ID</label>
        <input name="jefe_id" type="number" class="w-full border rounded px-3 py-2" value="<?=u($usuario['jefe_id'] ?? '')?>">
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Rol base ID</label>
        <input name="rol_base_id" type="number" class="w-full border rounded px-3 py-2" value="<?=u($usuario['rol_base_id'] ?? '')?>">
      </div>

      <div class="flex items-center gap-2 mt-6">
        <input id="activo" type="checkbox" name="activo" value="1" <?= ((int)($usuario['activo'] ?? 1) === 1) ? 'checked' : '' ?>>
        <label for="activo" class="text-sm text-gray-700">Activo</label>
      </div>

      <?php if ($pwdCol): ?>
      <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1"><?= $edit ? 'Nueva contraseña (opcional)' : 'Contraseña inicial' ?></label>
        <input type="password" name="new_password" class="w-full border rounded px-3 py-2" placeholder="<?= $edit ? 'Dejar vacío para no cambiar' : 'Recomendado: mínimo 8 caracteres' ?>">
        <p class="text-xs text-gray-500 mt-1">Se guardará hasheada en la columna: <b><?=u($pwdCol)?></b></p>
      </div>
      <?php endif; ?>
    </div>

    <div class="flex gap-2">
      <button class="px-5 py-2 rounded bg-blue-600 text-white"><?= $edit ? 'Guardar cambios' : 'Crear usuario' ?></button>
      <a href="index.php" class="px-5 py-2 rounded bg-gray-100 hover:bg-gray-200">Cancelar</a>
    </div>
  </form>
</div>
<?php layout_footer(); ?>