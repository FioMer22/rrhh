<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';

require_login();
require_role('admin', 'rrhh');

$pdo = DB::pdo();

$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $fecha)) $fecha = date('Y-m-d');

$q = trim((string)($_GET['q'] ?? ''));

$params = [$fecha];
$whereQ = '';
if ($q !== '') {
  $whereQ = " AND (u.email LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ?)";
  $like = "%{$q}%";
  $params[] = $like; $params[] = $like; $params[] = $like;
}

$st = $pdo->prepare("
  SELECT
    m.id,
    m.fecha_hora,
    m.tipo,
    m.origen,
    m.metodo,
    m.lat,
    m.lng,
    m.accuracy_m,
    m.location_status,
    m.nota,
    u.id AS usuario_id,
    u.email,
    u.nombre,
    u.apellido
  FROM asistencia_marcas m
  JOIN usuarios u ON u.id = m.usuario_id
  WHERE DATE(m.fecha_hora) = ?
  {$whereQ}
  ORDER BY m.fecha_hora DESC, m.id DESC
");
$st->execute($params);
$rows = $st->fetchAll();

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RRHH - Movimientos</title>
  <style>
    body{font-family:system-ui;margin:0;background:#f4f6f8}
    .wrap{max-width:1200px;margin:18px auto;padding:0 14px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    th{font-size:12px;opacity:.7}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#111;color:#fff;font-size:12px}
    .muted{opacity:.7}
    input{padding:10px;border:1px solid #d7dde3;border-radius:10px}
    a.btn{display:inline-block;padding:10px 12px;border-radius:10px;background:#111;color:#fff;text-decoration:none}
  </style>
</head>
<body>
  <?php require __DIR__ . '/../_layout.php'; ?>

  <div class="wrap">
    <div class="card">
      <h2>RRHH · Movimientos</h2>
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label class="muted">Fecha</label>
        <input type="date" name="fecha" value="<?= e($fecha) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por email o nombre">
        <button class="btn" style="border:0;cursor:pointer" type="submit">Filtrar</button>
        <a class="btn" href="<?= url('/rrhh/movimientos_hoy.php') ?>">Hoy</a>
      </form>
    </div>

    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Hora</th>
            <th>Usuario</th>
            <th>Tipo</th>
            <th>Origen</th>
            <th>Ubicación</th>
            <th>Obs.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(date('H:i:s', strtotime($r['fecha_hora']))) ?></td>
              <td>
                <div><strong><?= e($r['nombre'].' '.$r['apellido']) ?></strong></div>
                <div class="muted"><?= e($r['email']) ?></div>
              </td>
              <td><?= tipo_label((string)$r['tipo']) ?></td>
              <td class="muted"><?= e((string)$r['origen']) ?> · <?= e((string)$r['metodo']) ?></td>
              <td>
                <?php if (($r['location_status'] ?? '') === 'ok' && $r['lat'] !== null && $r['lng'] !== null): ?>
                  <?= e((string)$r['lat']) ?>, <?= e((string)$r['lng']) ?>
                  <?php if ($r['accuracy_m'] !== null): ?><span class="muted">(±<?= e((string)$r['accuracy_m']) ?>m)</span><?php endif; ?>
                <?php else: ?>
                  <span class="muted"><?= e((string)($r['location_status'] ?? 'unavailable')) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e((string)($r['nota'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted">Sin movimientos en esta fecha.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>