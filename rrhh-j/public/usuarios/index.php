<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../_layout.php'; // incluye bootstrap + auth + roles + require_login()

if (!has_any_role('admin','rrhh')) {
  http_response_code(403);
  exit('No autorizado');
}

$pdo = DB::pdo();
$q = trim((string)($_GET['q'] ?? ''));

$sql = "
SELECT
  u.id,
  u.nombre,
  u.apellido,
  u.email,
  u.telefono,
  u.activo,
  u.created_at,

  a.nombre AS area_nombre,
  eq.nombre AS equipo_nombre,
  s.nombre AS sede_nombre,
  CONCAT(j.nombre,' ',j.apellido) AS jefe_nombre,
  r.nombre AS rol_nombre

FROM usuarios u
LEFT JOIN areas a         ON a.id = u.area_id
LEFT JOIN equipos eq      ON eq.id = u.equipo_id
LEFT JOIN sedes s         ON s.id = u.sede_id
LEFT JOIN usuarios j      ON j.id = u.jefe_id
LEFT JOIN roles_sistema r ON r.id = u.rol_base_id
WHERE 1=1
";

$params = [];

if ($q !== '') {
  $sql .= " AND (
      u.nombre   LIKE :q
      OR u.apellido LIKE :q
      OR u.email    LIKE :q
      OR u.telefono LIKE :q
      OR a.nombre   LIKE :q
      OR eq.nombre  LIKE :q
      OR s.nombre   LIKE :q
      OR r.nombre   LIKE :q
      OR CONCAT(j.nombre,' ',j.apellido) LIKE :q
    )";
  $params[':q'] = "%{$q}%";
}

$sql .= " ORDER BY u.activo DESC, u.nombre ASC, u.apellido ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Detectar si hay columna de password para mostrar botón reset
$pwdCol = null;
$cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
  $f = strtolower((string)$c['Field']);
  if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
    $pwdCol = $c['Field'];
    break;
  }
}
?>
<script src="https://cdn.tailwindcss.com"></script>

<div class="max-w-6xl mx-auto p-4">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <h1 class="text-2xl font-semibold">Usuarios</h1>

    <div class="flex gap-2">
      <form class="flex gap-2" method="get">
        <input name="q" value="<?= e($q) ?>" class="border rounded px-3 py-2 w-64" placeholder="Buscar (nombre, email, tel)">
        <button class="px-4 py-2 rounded bg-gray-900 text-white">Buscar</button>
      </form>

      <a href="nuevo.php" class="px-4 py-2 rounded bg-blue-600 text-white">+ Nuevo</a>
      <a href="set_pass.php" class="px-4 py-2 rounded bg-indigo-600 text-white">Contraseñas</a>
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
          <th class="p-3">Rol</th>
          <th class="p-3">Activo</th>
          <th class="p-3">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="border-t align-top">
          <td class="p-3"><?= (int)$r['id'] ?></td>

          <td class="p-3">
            <div class="font-medium"><?= e(($r['nombre'] ?? '').' '.($r['apellido'] ?? '')) ?></div>
            <div class="text-gray-500 text-xs">Creado: <?= e($r['created_at'] ?? '') ?></div>
          </td>

          <td class="p-3"><?= e($r['email'] ?? '') ?></td>
          <td class="p-3"><?= e($r['telefono'] ?? '') ?></td>

          <td class="p-3"><?= e($r['area_nombre'] ?? '') ?></td>
          <td class="p-3"><?= e($r['equipo_nombre'] ?? '') ?></td>
          <td class="p-3"><?= e($r['sede_nombre'] ?? '') ?></td>
          <td class="p-3"><?= e($r['jefe_nombre'] ?? '') ?></td>
          <td class="p-3"><?= e($r['rol_nombre'] ?? '') ?></td>

          <td class="p-3">
            <?php if ((int)$r['activo'] === 1): ?>
              <span class="px-2 py-1 rounded bg-green-100 text-green-800">Sí</span>
            <?php else: ?>
              <span class="px-2 py-1 rounded bg-red-100 text-red-800">No</span>
            <?php endif; ?>
          </td>

          <td class="p-3 whitespace-nowrap">
            <div class="flex flex-wrap gap-2">
              <a class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200" href="editar.php?id=<?= (int)$r['id'] ?>">Editar</a>

              <form method="post" action="toggle.php" onsubmit="return confirm('¿Cambiar estado activo?')" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="px-3 py-1 rounded bg-yellow-100 hover:bg-yellow-200">Activar/Desactivar</button>
              </form>

              <?php if ($pwdCol): ?>
                <form method="post" action="reset_pass.php" onsubmit="return confirm('¿Resetear contraseña?')" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="px-3 py-1 rounded bg-red-100 hover:bg-red-200">Reset pass</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <tr><td class="p-4 text-gray-500" colspan="11">Sin resultados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>