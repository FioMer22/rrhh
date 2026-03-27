<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';

require_login();
require_role('admin', 'rrhh');

$pdo = DB::pdo();

$ok = $err = null;

// Listas
$usuarios = $pdo->query("SELECT id, email, nombre, apellido FROM usuarios ORDER BY apellido, nombre")->fetchAll();
$turnos   = $pdo->query("SELECT id, nombre, hora_inicio, hora_fin, tolerancia_min, activo FROM turnos WHERE activo=1 ORDER BY nombre")->fetchAll();

function user_label(array $u): string {
  $n = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
  return ($n !== '' ? $n : $u['email']) . ' — ' . $u['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $modo = (string)($_POST['modo'] ?? '');
  $usuarioId = (int)($_POST['usuario_id'] ?? 0);

  if ($usuarioId <= 0) {
    $err = 'Seleccioná un usuario.';
  } else {

    if ($modo === 'fijo') {
      $turnoId = (int)($_POST['turno_id'] ?? 0);
      $desde = (string)($_POST['desde'] ?? date('Y-m-d'));
      $hasta = trim((string)($_POST['hasta'] ?? ''));

      if ($turnoId <= 0) {
        $err = 'Seleccioná un turno.';
      } elseif (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        $err = 'Fecha "desde" inválida.';
      } elseif ($hasta !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $hasta)) {
        $err = 'Fecha "hasta" inválida.';
      } else {
        // cerramos vigencias previas abiertas si corresponde
        $pdo->prepare("
          UPDATE usuario_turnos
          SET vigente_hasta = DATE_SUB(?, INTERVAL 1 DAY)
          WHERE usuario_id = ?
            AND vigente_hasta IS NULL
            AND vigente_desde <= ?
        ")->execute([$desde, $usuarioId, $desde]);

        $pdo->prepare("
          INSERT INTO usuario_turnos (usuario_id, turno_id, vigente_desde, vigente_hasta, es_oficina_100)
          VALUES (?, ?, ?, ?, 1)
        ")->execute([$usuarioId, $turnoId, $desde, ($hasta !== '' ? $hasta : null)]);

        $ok = 'Turno fijo asignado correctamente.';
      }

    } elseif ($modo === 'variable') {
      $fecha = (string)($_POST['fecha'] ?? date('Y-m-d'));
      $estado = (string)($_POST['estado'] ?? 'normal'); // normal/libre/permiso/vacaciones/feriado
      $turnoId = (int)($_POST['turno_id_var'] ?? 0);
      $nota = trim((string)($_POST['nota'] ?? ''));
      if ($nota === '') $nota = null;
      if ($nota !== null) $nota = mb_substr($nota, 0, 255);

      if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $fecha)) {
        $err = 'Fecha inválida.';
      } elseif (!in_array($estado, ['normal','libre','permiso','vacaciones','feriado'], true)) {
        $err = 'Estado inválido.';
      } else {
        // Si estado es normal, puede llevar turno_id o dejar null (usa el fijo)
        $turnoGuardar = null;
        if ($estado === 'normal' && $turnoId > 0) $turnoGuardar = $turnoId;

        // Si es libre/permiso/vacaciones/feriado, turno_id puede quedar null
        $pdo->prepare("
          INSERT INTO usuario_turno_excepciones (usuario_id, fecha, turno_id, estado, nota)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            turno_id = VALUES(turno_id),
            estado   = VALUES(estado),
            nota     = VALUES(nota)
        ")->execute([$usuarioId, $fecha, $turnoGuardar, $estado, $nota]);

        $ok = 'Excepción (horario variable) guardada.';
      }

    } else {
      $err = 'Modo inválido.';
    }
  }
}

// Mostrar asignaciones actuales (fijo + próximas excepciones)
$asignaciones = $pdo->query("
  SELECT ut.id, ut.usuario_id, ut.vigente_desde, ut.vigente_hasta, t.nombre AS turno
  FROM usuario_turnos ut
  JOIN turnos t ON t.id = ut.turno_id
  ORDER BY ut.vigente_desde DESC
  LIMIT 80
")->fetchAll();

$excepciones = $pdo->query("
  SELECT e.id, e.usuario_id, e.fecha, e.estado, e.nota, t.nombre AS turno
  FROM usuario_turno_excepciones e
  LEFT JOIN turnos t ON t.id = e.turno_id
  WHERE e.fecha >= CURDATE()
  ORDER BY e.fecha ASC
  LIMIT 120
")->fetchAll();

$userMap = [];
foreach ($usuarios as $u) $userMap[(int)$u['id']] = user_label($u);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RRHH · Asignar turnos</title>
  <style>
    body{font-family:system-ui;margin:0;background:#f4f6f8}
    .wrap{max-width:1100px;margin:18px auto;padding:0 14px}
    .card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.06);margin-bottom:14px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    select,input{padding:10px;border:1px solid #d7dde3;border-radius:10px}
    button{padding:10px 12px;border-radius:10px;background:#111;color:#fff;border:0;cursor:pointer}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    th{font-size:12px;opacity:.7}
    .ok{background:#eaffea;color:#0c5a0c;padding:10px;border-radius:10px;margin-bottom:12px}
    .err{background:#ffecec;color:#9b1c1c;padding:10px;border-radius:10px;margin-bottom:12px}
    .muted{opacity:.7}
    .tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
    .tab{padding:8px 10px;border-radius:10px;border:1px solid #ddd;background:#fafafa;cursor:pointer}
    .tab.active{background:#111;color:#fff;border-color:#111}
  </style>
</head>
<body>
  <?php require __DIR__ . '/../_layout.php'; ?>

  <div class="wrap">
    <div class="card">
      <h2>RRHH · Asignar turnos (fijo / variable)</h2>
      <?php if ($ok): ?><div class="ok"><?= e($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>

      <div class="tabs">
        <button type="button" class="tab active" id="tabFijo" onclick="showTab('fijo')">Horario fijo</button>
        <button type="button" class="tab" id="tabVar" onclick="showTab('variable')">Horario variable (por fecha)</button>
      </div>

      <!-- FIJO -->
      <form method="post" id="formFijo">
        <?= csrf_field() ?>
        <input type="hidden" name="modo" value="fijo">
        <div class="row">
          <div style="min-width:360px">
            <div class="muted" style="font-size:12px">Usuario</div>
            <select name="usuario_id" required>
              <option value="">— seleccionar —</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e(user_label($u)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="min-width:320px">
            <div class="muted" style="font-size:12px">Turno</div>
            <select name="turno_id" required>
              <option value="">— seleccionar —</option>
              <?php foreach ($turnos as $t): ?>
                <option value="<?= (int)$t['id'] ?>">
                  <?= e($t['nombre']) ?> (<?= e(substr($t['hora_inicio'],0,5)) ?>–<?= e(substr($t['hora_fin'],0,5)) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="muted" style="font-size:12px">Vigente desde</div>
            <input type="date" name="desde" value="<?= e(date('Y-m-d')) ?>" required>
          </div>

          <div>
            <div class="muted" style="font-size:12px">Vigente hasta (opcional)</div>
            <input type="date" name="hasta" value="">
          </div>

          <button type="submit">Guardar fijo</button>
        </div>
      </form>

      <!-- VARIABLE -->
      <form method="post" id="formVar" style="display:none;margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="modo" value="variable">
        <div class="row">
          <div style="min-width:360px">
            <div class="muted" style="font-size:12px">Usuario</div>
            <select name="usuario_id" required>
              <option value="">— seleccionar —</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e(user_label($u)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="muted" style="font-size:12px">Fecha</div>
            <input type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required>
          </div>

          <div>
            <div class="muted" style="font-size:12px">Estado</div>
            <select name="estado">
              <option value="normal">Normal (puede cambiar turno)</option>
              <option value="libre">Libre</option>
              <option value="permiso">Permiso</option>
              <option value="vacaciones">Vacaciones</option>
              <option value="feriado">Feriado</option>
            </select>
          </div>

          <div style="min-width:320px">
            <div class="muted" style="font-size:12px">Turno (solo si “Normal”)</div>
            <select name="turno_id_var">
              <option value="0">— usar turno fijo —</option>
              <?php foreach ($turnos as $t): ?>
                <option value="<?= (int)$t['id'] ?>">
                  <?= e($t['nombre']) ?> (<?= e(substr($t['hora_inicio'],0,5)) ?>–<?= e(substr($t['hora_fin'],0,5)) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="min-width:280px">
            <div class="muted" style="font-size:12px">Nota (opcional)</div>
            <input type="text" name="nota" placeholder="Ej: entra 09:00 por viaje">
          </div>

          <button type="submit">Guardar variable</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>Asignaciones fijas (últimas)</h3>
      <table>
        <thead><tr><th>Usuario</th><th>Turno</th><th>Desde</th><th>Hasta</th></tr></thead>
        <tbody>
          <?php foreach ($asignaciones as $a): ?>
            <tr>
              <td><?= e($userMap[(int)$a['usuario_id']] ?? ('ID '.$a['usuario_id'])) ?></td>
              <td><?= e((string)$a['turno']) ?></td>
              <td><?= e((string)$a['vigente_desde']) ?></td>
              <td><?= e((string)($a['vigente_hasta'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$asignaciones): ?><tr><td colspan="4" class="muted">Sin asignaciones.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>Excepciones / horarios variables (próximas)</h3>
      <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Estado</th><th>Turno</th><th>Nota</th></tr></thead>
        <tbody>
          <?php foreach ($excepciones as $e): ?>
            <tr>
              <td><?= e((string)$e['fecha']) ?></td>
              <td><?= e($userMap[(int)$e['usuario_id']] ?? ('ID '.$e['usuario_id'])) ?></td>
              <td><?= e((string)$e['estado']) ?></td>
              <td><?= e((string)($e['turno'] ?? '')) ?></td>
              <td><?= e((string)($e['nota'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$excepciones): ?><tr><td colspan="5" class="muted">Sin excepciones futuras.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

<script>
function showTab(which){
  const fijo = document.getElementById('formFijo');
  const vari = document.getElementById('formVar');
  const tabF = document.getElementById('tabFijo');
  const tabV = document.getElementById('tabVar');

  if(which === 'variable'){
    fijo.style.display = 'none';
    vari.style.display = 'block';
    tabF.classList.remove('active');
    tabV.classList.add('active');
  } else {
    fijo.style.display = 'block';
    vari.style.display = 'none';
    tabV.classList.remove('active');
    tabF.classList.add('active');
  }
}
</script>
</body>
</html>