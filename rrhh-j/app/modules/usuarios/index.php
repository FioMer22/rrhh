<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

// Detectar columna de password si existe
$pwdCol = null;
$cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
  $f = strtolower((string)$c['Field']);
  if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
    $pwdCol = $c['Field'];
    break;
  }
}

$busca = trim($_GET['q'] ?? '');

$sql = "SELECT id, nombre, apellido, email, telefono, foto_url, activo, area_id, equipo_id, sede_id, jefe_id, rol_base_id, created_at, updated_at
        FROM usuarios WHERE 1=1";
$params = [];

if ($busca !== '') {
  $sql .= " AND (nombre LIKE :q OR apellido LIKE :q OR email LIKE :q OR telefono LIKE :q)";
  $params[':q'] = "%$busca%";
}
$sql .= " ORDER BY activo DESC, nombre ASC, apellido ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Tu layout.php: no sé si usa layout_header/layout_footer o renderLayout().
// En tu captura existe layout.php; asumo que ya tenés funciones.
layout_header('Usuarios');
?>
<div class="max-w-6xl mx-auto p-4">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <h1 class="text-2xl font-semibold">Usuarios</h1>

    <div class="flex gap-2">
      <form class="flex gap-2" method="get">
        <input name="q" value="<?=u($busca)?>" class="border rounded px-3 py-2 w-64" placeholder="Buscar (nombre, email, tel)">
        <button class="px-4 py-2 rounded bg-gray-900 text-white">Buscar</button>
      </form>
      <a href="form.php" class="px-4 py-2 rounded bg-blue-600 text-white">+ Nuevo</a>
    </div>
  </div>

  <div class="mt-4 overflow-x-auto bg-white rounded-xl shadow">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr class="text-left">
          <th class="p-3">ID</th>
          <th class="p-3">Nombre</th>
          <th class="p-3">Email</th>
          <th class="p-3">Tel</th>
          <th class="p-3">Área</th>
          <th class="p-3">Equipo</th>
          <th class="p-3">Sede</th>
          <th class="p-3">Jefe</th>
          <th class="p-3">Rol base</th>
          <th class="p-3">Activo</th>
          <th class="p-3">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr class="border-t">
          <td class="p-3"><?= (int)$r['id'] ?></td>
          <td class="p-3">
            <div class="font-medium"><?=u($r['nombre'].' '.$r['apellido'])?></div>
            <div class="text-gray-500 text-xs">Creado: <?=u($r['created_at'] ?? '')?></div>
          </td>
          <td class="p-3"><?=u($r['email'])?></td>
          <td class="p-3"><?=u($r['telefono'] ?? '')?></td>
          <td class="p-3"><?=u($r['area_id'] ?? '')?></td>
          <td class="p-3"><?=u($r['equipo_id'] ?? '')?></td>
          <td class="p-3"><?=u($r['sede_id'] ?? '')?></td>
          <td class="p-3"><?=u($r['jefe_id'] ?? '')?></td>
          <td class="p-3"><?=u($r['rol_base_id'] ?? '')?></td>
          <td class="p-3">
            <?php if ((int)$r['activo'] === 1): ?>
              <span class="px-2 py-1 rounded bg-green-100 text-green-800">Sí</span>
            <?php else: ?>
              <span class="px-2 py-1 rounded bg-red-100 text-red-800">No</span>
            <?php endif; ?>
          </td>
          <td class="p-3 whitespace-nowrap flex gap-2">
            <a class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200" href="form.php?id=<?= (int)$r['id'] ?>">Editar</a>

            <form method="post" action="toggle_activo.php" onsubmit="return confirm('¿Cambiar estado activo?')" class="inline">
              <input type="hidden" name="csrf" value="<?=u(csrf())?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="px-3 py-1 rounded bg-yellow-100 hover:bg-yellow-200">Activar/Desactivar</button>
            </form>

            <?php if ($pwdCol): ?>
            <form method="post" action="reset_pass.php" onsubmit="return confirm('¿Resetear contraseña de este usuario?')" class="inline">
              <input type="hidden" name="csrf" value="<?=u(csrf())?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="px-3 py-1 rounded bg-red-100 hover:bg-red-200">Reset pass</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <tr><td class="p-4 text-gray-500" colspan="11">Sin resultados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$pwdCol): ?>
    <div class="mt-4 p-3 rounded bg-amber-50 border border-amber-200 text-amber-900 text-sm">
      Nota: No detecté columna de contraseña (password_hash/password/etc). Por eso no muestro “Reset pass”.
    </div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>