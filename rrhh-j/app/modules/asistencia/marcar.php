<?php
declare(strict_types=1);

/**
 * public/asistencia/marcar.php
 * Marcación: Entrada / Inicio Almuerzo / Fin Almuerzo / Salida
 * Hora correcta Paraguay (PHP) + Ubicación + Nota/Observación
 */

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';

require_login();

$pdo = DB::pdo();
$uid = (int)($_SESSION['uid'] ?? 0);

$err = $ok = null;

function today_date(): string { return date('Y-m-d'); }

function last_mark_today(PDO $pdo, int $uid): ?array {
  $st = $pdo->prepare("
    SELECT id, tipo, fecha_hora
    FROM asistencia_marcas
    WHERE usuario_id=? AND DATE(fecha_hora)=?
    ORDER BY fecha_hora DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$uid, today_date()]);
  $r = $st->fetch();
  return $r ?: null;
}

function can_action(?string $lastTipo, string $action): bool {
  if ($lastTipo === null) return $action === 'inicio_jornada';
  if ($lastTipo === 'inicio_jornada') return in_array($action, ['pausa_inicio','fin_jornada'], true);
  if ($lastTipo === 'pausa_inicio') return $action === 'pausa_fin';
  if ($lastTipo === 'pausa_fin') return in_array($action, ['pausa_inicio','fin_jornada'], true);
  if ($lastTipo === 'fin_jornada') return false;
  return false;
}

function tipo_label(string $tipo): string {
  return match ($tipo) {
    'inicio_jornada' => 'Entrada',
    'pausa_inicio'   => 'Inicio almuerzo',
    'pausa_fin'      => 'Fin almuerzo',
    'fin_jornada'    => 'Salida',
    default          => $tipo,
  };
}

$last = last_mark_today($pdo, $uid);
$lastTipo = $last['tipo'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $tipo = (string)($_POST['tipo'] ?? '');
  $allowed = ['inicio_jornada','pausa_inicio','pausa_fin','fin_jornada'];

  if (!in_array($tipo, $allowed, true)) {
    $err = 'Tipo inválido.';
  } elseif (!can_action($lastTipo, $tipo)) {
    $err = 'Acción no permitida según tu última marca.';
  } else {
    // Hora Paraguay desde PHP
    $fechaHora = date('Y-m-d H:i:s');

    // Nota
    $nota = trim((string)($_POST['nota'] ?? ''));
    if ($nota === '') $nota = null;

    // Ubicación
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $acc = isset($_POST['accuracy_m']) && $_POST['accuracy_m'] !== '' ? (int)$_POST['accuracy_m'] : null;

    $locStatus = (string)($_POST['location_status'] ?? 'unavailable');
    if (!in_array($locStatus, ['ok','denied','unavailable','error'], true)) $locStatus = 'error';

    $locNote = trim((string)($_POST['location_note'] ?? ''));
    if ($locNote === '') $locNote = null;
    if ($locNote !== null) $locNote = mb_substr($locNote, 0, 255);

    if ($locStatus === 'ok') {
      if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $locStatus = 'error';
        $locNote = 'Coordenadas inválidas.';
        $lat = $lng = null;
        $acc = null;
      }
    } else {
      $lat = $lng = null;
      $acc = null;
    }

    // IP / User agent
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // Sede fija por usuario
    $st = $pdo->prepare("SELECT sede_id FROM usuarios WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $sedeId = $st->fetch()['sede_id'] ?? null;

    // Tipo jornada
    $tj = $pdo->query("SELECT id FROM asistencia_tipos_jornada WHERE codigo='presencial' LIMIT 1")->fetch();
    $tipoJornadaId = (int)($tj['id'] ?? 0);

    if ($tipoJornadaId <= 0) {
      $err = 'No existe asistencia_tipos_jornada.presencial.';
    } else {
      $origen = 'sede';

      $pdo->prepare("
        INSERT INTO asistencia_marcas
          (usuario_id, tipo, fecha_hora, origen, sede_id, tipo_jornada_id, metodo,
           lat, lng, accuracy_m, location_status, location_note, ip, user_agent,
           nota)
        VALUES
          (?, ?, ?, ?, ?, ?, 'web',
           ?, ?, ?, ?, ?, ?, ?,
           ?)
      ")->execute([
        $uid, $tipo, $fechaHora, $origen, $sedeId, $tipoJornadaId,
        $lat, $lng, $acc, $locStatus, $locNote, $ip, $ua,
        $nota
      ]);

      $ok = 'Registrado: ' . tipo_label($tipo) . '.';
      $last = last_mark_today($pdo, $uid);
      $lastTipo = $last['tipo'] ?? null;
    }
  }
}

// Marcas de hoy
$st = $pdo->prepare("
  SELECT tipo, fecha_hora, origen, metodo, lat, lng, accuracy_m, location_status, nota
  FROM asistencia_marcas
  WHERE usuario_id=? AND DATE(fecha_hora)=?
  ORDER BY fecha_hora ASC, id ASC
");
$st->execute([$uid, today_date()]);
$hoy = $st->fetchAll();

$canEntrada = can_action($lastTipo, 'inicio_jornada');
$canPausaIn = can_action($lastTipo, 'pausa_inicio');
$canPausaFi = can_action($lastTipo, 'pausa_fin');
$canSalida  = can_action($lastTipo, 'fin_jornada');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Marcación</title>

  <!-- ✅ Estilo institucional unificado -->
  <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
</head>

<body>
  <?php require __DIR__ . '/../_layout.php'; ?>

  <div class="wrap">

    <div class="card">
      <h2>Marcación</h2>

      <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

      <p class="meta" id="locMsg">Ubicación: intentando obtener…</p>

      <form method="post" id="markForm">
        <?= csrf_field() ?>
        <input type="hidden" name="tipo" id="tipo">

        <input type="hidden" name="lat" id="lat">
        <input type="hidden" name="lng" id="lng">
        <input type="hidden" name="accuracy_m" id="accuracy_m">
        <input type="hidden" name="location_status" id="location_status" value="unavailable">
        <input type="hidden" name="location_note" id="location_note">

        <label class="meta" style="display:block;margin:10px 0 6px;">Observación (opcional)</label>
        <input type="text" name="nota" id="nota" placeholder="Ej: reunión externa, visita, etc.">

        <div class="btns">
          <button class="btn dark" type="submit"
            onclick="document.getElementById('tipo').value='inicio_jornada';"
            <?= $canEntrada ? '' : 'disabled' ?>
          >🟢 Marcar ENTRADA</button>

          <button class="btn secondary" type="submit"
            onclick="document.getElementById('tipo').value='pausa_inicio';"
            <?= $canPausaIn ? '' : 'disabled' ?>
          >🍽️ Inicio ALMUERZO</button>

          <button class="btn secondary" type="submit"
            onclick="document.getElementById('tipo').value='pausa_fin';"
            <?= $canPausaFi ? '' : 'disabled' ?>
          >✅ Fin ALMUERZO</button>

          <button class="btn dark" type="submit"
            onclick="document.getElementById('tipo').value='fin_jornada';"
            <?= $canSalida ? '' : 'disabled' ?>
          >🔴 Marcar SALIDA</button>
        </div>
      </form>

      <div class="meta" style="margin-top:10px">
        <?php if ($lastTipo): ?>
          Última marca hoy: <strong><?= e(tipo_label($lastTipo)) ?></strong>
          <?php if (!empty($last['fecha_hora'])): ?> — <?= e($last['fecha_hora']) ?><?php endif; ?>
        <?php else: ?>
          Aún no marcaste hoy.
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h3>Marcas de hoy (<?= e(date('d/m/Y')) ?>)</h3>

      <table class="table">
        <thead>
          <tr>
            <th>Hora</th>
            <th>Tipo</th>
            <th>Ubicación</th>
            <th>Obs.</th>
            <th>Método</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hoy as $m): ?>
            <tr>
              <td><?= e(date('H:i:s', strtotime($m['fecha_hora']))) ?></td>
              <td><?= e(tipo_label((string)$m['tipo'])) ?></td>
              <td>
                <?php
                  $stt = (string)($m['location_status'] ?? 'unavailable');
                  if ($stt === 'ok' && $m['lat'] !== null && $m['lng'] !== null) {
                    $accTxt = $m['accuracy_m'] !== null ? (' ±' . (int)$m['accuracy_m'] . 'm') : '';
                    echo '<span class="pill">OK</span> ' . e((string)$m['lat']) . ', ' . e((string)$m['lng']) . $accTxt;
                  } else {
                    echo '<span class="meta">' . e($stt) . '</span>';
                  }
                ?>
              </td>
              <td><?= e((string)($m['nota'] ?? '')) ?></td>
              <td><?= e((string)($m['metodo'] ?? 'web')) ?></td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$hoy): ?>
            <tr><td colspan="5" class="meta">Sin marcas hoy.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

<script>
(function(){
  const msg = document.getElementById('locMsg');
  const latEl = document.getElementById('lat');
  const lngEl = document.getElementById('lng');
  const accEl = document.getElementById('accuracy_m');
  const stEl  = document.getElementById('location_status');
  const noteEl= document.getElementById('location_note');

  if (!navigator.geolocation){
    msg.textContent = 'Ubicación: no disponible en este navegador.';
    stEl.value = 'unavailable';
    noteEl.value = 'Geolocation no disponible';
    return;
  }

  navigator.geolocation.getCurrentPosition(
    (pos) => {
      latEl.value = pos.coords.latitude;
      lngEl.value = pos.coords.longitude;
      accEl.value = Math.round(pos.coords.accuracy || 0);
      stEl.value = 'ok';
      noteEl.value = '';
      msg.textContent = `Ubicación: OK (±${accEl.value}m)`;
    },
    (err) => {
      let st = 'error';
      if (err && err.code === 1) st = 'denied';
      if (err && (err.code === 2 || err.code === 3)) st = 'unavailable';

      latEl.value = '';
      lngEl.value = '';
      accEl.value = '';
      stEl.value = st;
      noteEl.value = (err && err.message) ? err.message : 'No se pudo obtener ubicación';

      msg.textContent = `Ubicación: ${st} (se registrará igual).`;
    },
    { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
  );
})();
</script>

</body>
</html>