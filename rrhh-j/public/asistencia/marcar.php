<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/notificaciones.php';
require_once __DIR__ . '/../../app/helpers/push.php';

require_login();

$pdo = DB::pdo();
$uid = (int)($_SESSION['uid'] ?? 0);
$err = $ok = null;

// ── Constantes de jornada ─────────────────────────────────────────────────────
const HORA_INICIO   = '07:30';
const HORA_LIMITE   = '07:40'; // tolerancia 10 min
const HORA_LIMITE_T = '07:40'; // alias para avisos

function today_date(): string { return date('Y-m-d'); }

function last_mark_today(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("
        SELECT id, tipo, fecha_hora FROM asistencia_marcas
        WHERE usuario_id=? AND DATE(fecha_hora)=?
        ORDER BY fecha_hora DESC, id DESC LIMIT 1
    ");
    $st->execute([$uid, today_date()]);
    return $st->fetch() ?: null;
}

function can_action(?string $lastTipo, string $action): bool {
    if ($lastTipo === null)              return $action === 'inicio_jornada';
    if ($lastTipo === 'inicio_jornada') return in_array($action, ['pausa_inicio','fin_jornada'], true);
    if ($lastTipo === 'pausa_inicio')   return $action === 'pausa_fin';
    if ($lastTipo === 'pausa_fin')      return in_array($action, ['pausa_inicio','fin_jornada'], true);
    if ($lastTipo === 'fin_jornada')    return false;
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

// ── Calcular si hay tardanza ──────────────────────────────────────────────────
$ahora        = date('H:i');
$esTardanza   = $ahora > HORA_LIMITE;
$yaEntro      = false;
$yaEnvioAviso = false;

// ¿Ya marcó entrada hoy?
$stE = $pdo->prepare("
    SELECT id FROM asistencia_marcas
    WHERE usuario_id=? AND tipo='inicio_jornada' AND DATE(fecha_hora)=?
    LIMIT 1
");
$stE->execute([$uid, today_date()]);
$yaEntro = (bool)$stE->fetch();

// ¿Ya envió aviso hoy?
$stA = $pdo->prepare("
    SELECT id FROM avisos_tardanza
    WHERE usuario_id=? AND fecha=?
    LIMIT 1
");
$stA->execute([$uid, today_date()]);
$avisoDia = $stA->fetch();
$yaEnvioAviso = (bool)$avisoDia;

// ── POST: enviar aviso de tardanza ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'aviso_tardanza') {
    csrf_verify();

    $motivo        = trim($_POST['motivo']        ?? '');
    $horaEstim     = trim($_POST['hora_estimada'] ?? '');
    $tipoDescuento = trim($_POST['tipo_descuento'] ?? '');

    // Validar hora estimada
    if (!preg_match('/^\d{2}:\d{2}$/', $horaEstim)) $horaEstim = null;

    // Obtener jefe_id del usuario
    $stJ = $pdo->prepare("SELECT jefe_id, nombre, apellido FROM usuarios WHERE id=? LIMIT 1");
    $stJ->execute([$uid]);
    $uData = $stJ->fetch();
    $jefeId = $uData['jefe_id'] ? (int)$uData['jefe_id'] : null;
    $nombreEmp = trim(($uData['nombre'] ?? '').' '.($uData['apellido'] ?? ''));

    // Insertar o actualizar aviso
    $pdo->prepare("
        INSERT INTO avisos_tardanza (usuario_id, jefe_id, fecha, hora_estimada, motivo, tipo_descuento, estado)
        VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
        ON DUPLICATE KEY UPDATE
            hora_estimada  = VALUES(hora_estimada),
            motivo         = VALUES(motivo),
            tipo_descuento = VALUES(tipo_descuento),
            estado         = 'pendiente'
    ")->execute([$uid, $jefeId, today_date(),
                 $horaEstim ?: null, $motivo ?: null,
                 $tipoDescuento ?: null]);

    $avisoDia = $pdo->prepare("SELECT id FROM avisos_tardanza WHERE usuario_id=? AND fecha=? LIMIT 1");
    $avisoDia->execute([$uid, today_date()]);
    $avisoId = (int)($avisoDia->fetch()['id'] ?? 0);

    // Notificar al jefe (si tiene)
    if ($jefeId) {
        $titulo = "⚠️ $nombreEmp avisa que llegará tarde";
        $cuerpo = $motivo ?: 'Sin motivo especificado.';
        if ($horaEstim) $cuerpo .= " — Hora estimada: $horaEstim";
        crear_notificacion($pdo, $jefeId, 'aviso_tardanza', $titulo, $cuerpo, $uid, $avisoId);
        push_notificar($pdo, $jefeId, $titulo, $cuerpo, '/rrhh-j/public/notificaciones.php');
    }

    // Notificar también a RRHH (usuarios con rol rrhh o admin)
    $stRrhh = $pdo->query("
        SELECT DISTINCT u.id FROM usuarios u
        JOIN usuarios_roles ur ON ur.usuario_id = u.id
        JOIN roles_sistema r   ON r.id = ur.rol_id
        WHERE r.nombre IN ('rrhh','admin') AND u.activo=1 AND u.id != $uid
        LIMIT 5
    ");
    $idsRrhh = [];
    foreach ($stRrhh->fetchAll() as $r) {
        crear_notificacion($pdo, (int)$r['id'], 'aviso_tardanza',
            "⚠️ $nombreEmp avisa que llegará tarde",
            ($motivo ?: 'Sin motivo.').($horaEstim ? " Est: $horaEstim" : ''),
            $uid, $avisoId);
        $idsRrhh[] = (int)$r['id'];
    }
    if ($idsRrhh) {
        push_notificar($pdo, $idsRrhh,
            "⚠️ $nombreEmp avisa que llegará tarde",
            ($motivo ?: 'Sin motivo.').($horaEstim ? " Est: $horaEstim" : ''),
            '/rrhh-j/public/notificaciones.php'
        );
    }

    $ok = '✅ Aviso enviado. Tu jefe y RRHH fueron notificados.';
    $yaEnvioAviso = true;
}

// ── POST: marcar asistencia ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') !== 'aviso_tardanza') {
    csrf_verify();

    $tipo = (string)($_POST['tipo'] ?? '');
    $allowed = ['inicio_jornada','pausa_inicio','pausa_fin','fin_jornada'];
    $last     = last_mark_today($pdo, $uid);
    $lastTipo = $last['tipo'] ?? null;

    if (!in_array($tipo, $allowed, true)) {
        $err = 'Tipo inválido.';
    } elseif (!can_action($lastTipo, $tipo)) {
        $err = 'Acción no permitida según tu última marca.';
    } else {
        $fechaHora = date('Y-m-d H:i:s');
        $nota      = trim((string)($_POST['nota'] ?? '')) ?: null;
        $lat       = isset($_POST['lat'])        && $_POST['lat']       !== '' ? (float)$_POST['lat']       : null;
        $lng       = isset($_POST['lng'])        && $_POST['lng']       !== '' ? (float)$_POST['lng']       : null;
        $acc       = isset($_POST['accuracy_m']) && $_POST['accuracy_m']!== '' ? (int)$_POST['accuracy_m'] : null;
        $locStatus = (string)($_POST['location_status'] ?? 'unavailable');
        if (!in_array($locStatus, ['ok','denied','unavailable','error'], true)) $locStatus = 'error';
        $locNote   = mb_substr(trim((string)($_POST['location_note'] ?? '')), 0, 255) ?: null;

        if ($locStatus === 'ok' && ($lat === null || $lng === null || $lat < -90 || $lat > 90)) {
            $locStatus = 'error'; $locNote = 'Coordenadas inválidas.'; $lat = $lng = $acc = null;
        } elseif ($locStatus !== 'ok') { $lat = $lng = $acc = null; }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $stSede = $pdo->prepare("SELECT sede_id FROM usuarios WHERE id=? LIMIT 1");
        $stSede->execute([$uid]);
        $sedeId = $stSede->fetch()['sede_id'] ?? null;

        $tj = $pdo->query("SELECT id FROM asistencia_tipos_jornada WHERE codigo='presencial' LIMIT 1")->fetch();
        $tipoJornadaId = (int)($tj['id'] ?? 0);

        if ($tipoJornadaId <= 0) {
            $err = 'No existe asistencia_tipos_jornada.presencial.';
        } else {
            $pdo->prepare("
                INSERT INTO asistencia_marcas
                  (usuario_id, tipo, fecha_hora, origen, sede_id, tipo_jornada_id, metodo,
                   lat, lng, accuracy_m, location_status, location_note, ip, user_agent, nota)
                VALUES (?,?,?,?,?,?,'web',?,?,?,?,?,?,?,?)
            ")->execute([$uid, $tipo, $fechaHora, 'sede', $sedeId, $tipoJornadaId,
                         $lat, $lng, $acc, $locStatus, $locNote, $ip, $ua, $nota]);

            // Si llegó, actualizar aviso a 'llegó'
            if ($tipo === 'inicio_jornada' && $yaEnvioAviso) {
                $pdo->prepare("
                    UPDATE avisos_tardanza SET estado='llegó' WHERE usuario_id=? AND fecha=?
                ")->execute([$uid, today_date()]);
            }

            $ok       = 'Registrado: ' . tipo_label($tipo) . '.';
            $yaEntro  = ($tipo === 'inicio_jornada') ? true : $yaEntro;
        }
    }
}

$last     = last_mark_today($pdo, $uid);
$lastTipo = $last['tipo'] ?? null;

$canEntrada = can_action($lastTipo, 'inicio_jornada');
$canPausaIn = can_action($lastTipo, 'pausa_inicio');
$canPausaFi = can_action($lastTipo, 'pausa_fin');
$canSalida  = can_action($lastTipo, 'fin_jornada');

// Recalcular estado aviso
$stA2 = $pdo->prepare("SELECT * FROM avisos_tardanza WHERE usuario_id=? AND fecha=? LIMIT 1");
$stA2->execute([$uid, today_date()]);
$avisoActual = $stA2->fetch();

$stHoy = $pdo->prepare("
    SELECT tipo, fecha_hora, origen, metodo, lat, lng, accuracy_m, location_status, nota
    FROM asistencia_marcas
    WHERE usuario_id=? AND DATE(fecha_hora)=?
    ORDER BY fecha_hora ASC, id ASC
");
$stHoy->execute([$uid, today_date()]);
$marcasHoy = $stHoy->fetchAll();

// Minutos de tardanza actuales
$minTard = 0;
if ($esTardanza && !$yaEntro) {
    $lim = strtotime(today_date().' '.HORA_LIMITE);
    $minTard = (int)floor((time() - $lim) / 60);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Marcación</title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        /* ── Banner tardanza ─────────────────────────────────────── */
        .banner-tard {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            color: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 14px;
        }
        .banner-tard h3 { margin: 0 0 4px; font-size: 16px; display:flex;align-items:center;gap:8px }
        .banner-tard p  { margin: 0 0 12px; font-size: 13px; opacity: .85 }

        .banner-enviado {
            background: linear-gradient(135deg, #14532d, #166534);
            color: #fff;
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 14px;
        }
        .banner-enviado p { margin: 0; font-size: 13px }

        .aviso-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 12px;
        }
        .aviso-form .span2 { grid-column: span 2 }
        @media (max-width: 600px) {
            .aviso-form { grid-template-columns: 1fr }
            .aviso-form .span2 { grid-column: 1 }
        }

        /* inputs dentro del banner (fondo oscuro) */
        .banner-tard input[type=text],
        .banner-tard input[type=time],
        .banner-tard textarea,
        .banner-tard select {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25);
            color: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            width: 100%;
            font-size: 13px;
        }
        .banner-tard input::placeholder { color: rgba(255,255,255,.5) }
        .banner-tard button[type=submit] {
            background: #fff;
            color: #991b1b;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 9px 20px;
            cursor: pointer;
            font-size: 13px;
        }
        .banner-tard button[type=submit]:hover { background: #fee2e2 }

        .tard-chip {
            display: inline-block;
            background: rgba(255,255,255,.2);
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 700;
            margin-left: 6px;
        }

        /* ── Notificación de llegada ─────────────────────────────── */
        .notif-bell {
            position: relative;
            display: inline-block;
        }
        .notif-count {
            position: absolute;
            top: -4px; right: -6px;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            border-radius: 999px;
            padding: 1px 5px;
            min-width: 16px;
            text-align: center;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:700px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok" ><?= e($ok)  ?></div><?php endif; ?>

    <!-- ── Banner tardanza / aviso anticipado ───────────────── -->
    <?php if (!$yaEntro): ?>

        <?php if ($avisoActual && $avisoActual['estado'] !== 'llegó'): ?>
        <!-- Aviso ya enviado (antes o después del límite) -->
        <div class="banner-enviado">
            <p>
                ✅ <strong>Aviso enviado.</strong>
                <?php if ($avisoActual['hora_estimada']): ?>
                    Hora estimada de llegada: <strong><?= e(date('H:i', strtotime($avisoActual['hora_estimada']))) ?></strong>.
                <?php endif; ?>
                Tu jefe y RRHH fueron notificados.
                <?php if ($avisoActual['motivo']): ?>
                    <br><span style="opacity:.8">Motivo: <?= e($avisoActual['motivo']) ?></span>
                <?php endif; ?>
            </p>
            <p style="margin-top:6px;font-size:12px;opacity:.75">
                ¿Cambió algo? Podés enviar un nuevo aviso actualizando el motivo u hora.
            </p>
        </div>

        <?php elseif ($esTardanza): ?>
        <!-- Ya pasó el límite y no avisó — banner urgente -->
        <div class="banner-tard">
            <h3>
                ⚠️ Llegada tarde
                <span class="tard-chip"><?= $minTard ?> min fuera del horario</span>
            </h3>
            <p>Son las <?= date('H:i') ?> y aún no marcaste entrada (límite: <?= HORA_LIMITE ?>).<br>
               Avisá a tu jefe directamente desde acá — sin WhatsApp, sin llamadas.</p>

            <form method="post" class="aviso-form">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="aviso_tardanza">
                <div>
                    <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                        Hora estimada de llegada *
                    </label>
                    <input type="time" name="hora_estimada" required
                           value="<?= date('H:i', strtotime('+15 minutes')) ?>">
                </div>
                <div>
                    <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                        Descontar de *
                    </label>
                    <select name="tipo_descuento" required
                            style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);
                                   color:#fff;border-radius:8px;padding:8px 12px;width:100%;font-size:13px">
                        <option value="" style="color:#000">Seleccionar...</option>
                        <option value="horas_extra" style="color:#000">⏱ Horas extras estándar</option>
                        <option value="horas_viveres" style="color:#000">🥫 Horas extras Entrega de Víveres</option>
                    </select>
                </div>
                <div class="span2">
                    <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                        Motivo (opcional)
                    </label>
                    <input type="text" name="motivo"
                           placeholder="Ej: tráfico, cita médica, transporte...">
                </div>
                <div class="span2">
                    <button type="submit">📨 Enviar aviso al jefe</button>
                    <span style="font-size:11px;opacity:.6;margin-left:10px">
                        Se notifica a tu jefe directo y a RRHH
                    </span>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- Antes del límite — botón discreto de aviso anticipado -->
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;
                    padding:14px 18px;margin-bottom:14px;
                    display:flex;align-items:center;justify-content:space-between;
                    flex-wrap:wrap;gap:12px">
            <div>
                <div style="font-size:13px;font-weight:600;color:#374151">
                    ¿Sabés que vas a llegar tarde?
                </div>
                <div style="font-size:12px;color:#6b7280;margin-top:2px">
                    Avisá ahora antes de las <?= HORA_LIMITE ?> — tu jefe lo verá de inmediato.
                </div>
            </div>
            <button onclick="document.getElementById('aviso-anticipado').style.display='block';this.parentElement.style.display='none';"
                    style="padding:8px 16px;background:#f97316;color:#fff;border:none;
                           border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;
                           white-space:nowrap">
                📨 Avisar tardanza
            </button>
        </div>

        <!-- Formulario oculto de aviso anticipado -->
        <div id="aviso-anticipado" style="display:none">
            <div class="banner-tard" style="background:linear-gradient(135deg,#7c2d12,#9a3412)">
                <h3>📨 Avisar llegada tarde</h3>
                <p>Tu jefe y RRHH recibirán una notificación inmediata.</p>
                <form method="post" class="aviso-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="aviso_tardanza">
                    <div>
                        <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                            Hora estimada de llegada *
                        </label>
                        <input type="time" name="hora_estimada" required
                               value="<?= date('H:i', strtotime('+30 minutes')) ?>">
                    </div>
                    <div>
                        <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                            Descontar de *
                        </label>
                        <select name="tipo_descuento" required
                                style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);
                                       color:#fff;border-radius:8px;padding:8px 12px;width:100%;font-size:13px">
                            <option value="" style="color:#000">Seleccionar...</option>
                            <option value="horas_extra" style="color:#000">⏱ Horas extras estándar</option>
                            <option value="horas_viveres" style="color:#000">🥫 Horas extras Entrega de Víveres</option>
                        </select>
                    </div>
                    <div class="span2">
                        <label style="font-size:11px;opacity:.7;display:block;margin-bottom:3px">
                            Motivo (opcional)
                        </label>
                        <input type="text" name="motivo"
                               placeholder="Ej: tráfico, urgencia familiar, transporte...">
                    </div>
                    <div class="span2" style="display:flex;align-items:center;gap:10px">
                        <button type="submit">📨 Enviar aviso</button>
                        <button type="button"
                                onclick="document.getElementById('aviso-anticipado').style.display='none';"
                                style="background:rgba(255,255,255,.15);color:#fff;
                                       border:1px solid rgba(255,255,255,.3)">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($yaEntro && $avisoActual && $avisoActual['estado'] !== 'llegó'): ?>
    <!-- Llegó después de enviar aviso — confirmación -->
    <div class="banner-enviado">
        <p>✅ Ya marcaste entrada. El aviso de tardanza fue cerrado automáticamente.</p>
    </div>
    <?php endif; ?>

    <!-- ── Formulario principal de marcación ─────────────── -->
    <div class="card">
        <h2>Marcación</h2>
        <p class="meta" id="locMsg">Ubicación: intentando obtener…</p>

        <form method="post" id="markForm">
            <?= csrf_field() ?>
            <input type="hidden" name="tipo" id="tipo">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">
            <input type="hidden" name="accuracy_m" id="accuracy_m">
            <input type="hidden" name="location_status" id="location_status" value="unavailable">
            <input type="hidden" name="location_note" id="location_note">

            <label style="display:block;margin:10px 0 6px;opacity:.8">Observación (opcional)</label>
            <input type="text" name="nota" id="nota"
                   placeholder="Ej: reunión externa, visita, etc.">

            <div class="btns">
                <button class="btn" type="submit" data-tipo="inicio_jornada"
                        <?= $canEntrada ? '' : 'disabled' ?>>
                    Marcar ENTRADA
                </button>
                <button class="btn" type="submit" data-tipo="pausa_inicio"
                        <?= $canPausaIn ? '' : 'disabled' ?>>
                    Inicio ALMUERZO
                </button>
                <button class="btn" type="submit" data-tipo="pausa_fin"
                        <?= $canPausaFi ? '' : 'disabled' ?>>
                    Fin ALMUERZO
                </button>
                <button class="btn" type="submit" data-tipo="fin_jornada"
                        <?= $canSalida ? '' : 'disabled' ?>>
                    Marcar SALIDA
                </button>
            </div>
        </form>

        <div class="meta" style="margin-top:12px">
            <?php if ($lastTipo): ?>
                Última marca hoy: <strong><?= e(tipo_label($lastTipo)) ?></strong>
                <?php if (!empty($last['fecha_hora'])): ?>
                    — <?= e(date('H:i', strtotime($last['fecha_hora']))) ?>
                <?php endif; ?>
            <?php else: ?>
                Aún no marcaste hoy.
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Marcas de hoy ─────────────────────────────────── -->
    <div class="card">
        <h3>Marcas de hoy (<?= e(date('d/m/Y')) ?>)</h3>
        <table>
            <thead>
                <tr><th>Hora</th><th>Tipo</th><th>Ubicación</th><th>Obs.</th><th>Método</th></tr>
            </thead>
            <tbody>
            <?php foreach ($marcasHoy as $m): ?>
                <tr>
                    <td><?= e(date('H:i:s', strtotime($m['fecha_hora']))) ?></td>
                    <td><?= e(tipo_label((string)$m['tipo'])) ?></td>
                    <td>
                    <?php
                        $stt = (string)($m['location_status'] ?? 'unavailable');
                        if ($stt === 'ok' && $m['lat'] !== null) {
                            $accTxt = $m['accuracy_m'] !== null ? ' ±'.(int)$m['accuracy_m'].'m' : '';
                            echo '<span class="pill">OK</span> '.e((string)$m['lat']).', '.e((string)$m['lng']).$accTxt;
                        } else {
                            echo '<span style="opacity:.6">'.e($stt).'</span>';
                        }
                    ?>
                    </td>
                    <td class="meta"><?= e((string)($m['nota'] ?? '')) ?></td>
                    <td class="meta"><?= e((string)($m['metodo'] ?? 'web')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$marcasHoy): ?>
                <tr><td colspan="5" class="meta">Sin marcas hoy.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<script>
(function () {
    const msg   = document.getElementById('locMsg');
    const latEl = document.getElementById('lat');
    const lngEl = document.getElementById('lng');
    const accEl = document.getElementById('accuracy_m');
    const stEl  = document.getElementById('location_status');
    const ntEl  = document.getElementById('location_note');

    const esNativa = !!(window.Android && window.Android.esAppNativa &&
                        window.Android.esAppNativa());

    let esperandoGps   = false;
    let accionPendiente = null;

    // ── Mostrar mensaje temporal ──────────────────────────────────
    function msgTemporal(texto, color, segundos) {
        msg.textContent = texto;
        msg.style.color = color || '';
        if (segundos) {
            setTimeout(function() {
                msg.textContent = 'Ubicación: lista para marcar.';
                msg.style.color = '';
            }, segundos * 1000);
        }
    }

    // ── Deshabilitar / habilitar botones ─────────────────────────
    function setBotones(habilitado) {
        document.querySelectorAll('#markForm button[type=submit]')
            .forEach(function(b) {
                // Respetar los disabled del PHP (can_action)
                if (b.dataset.phpDisabled !== 'true') {
                    b.disabled = !habilitado;
                }
            });
    }

    // Marcar cuáles botones el PHP deshabilita para no pisarlos
    document.querySelectorAll('#markForm button[type=submit][disabled]')
        .forEach(function(b) { b.dataset.phpDisabled = 'true'; });

    // ── Callback que Kotlin invoca con el resultado GPS ───────────
    window.rrhhNative = {
        onGpsResult: function (data) {
            esperandoGps = false;

            if (data.status !== 'ok' || data.lat === null) {
                // GPS falló — registrar igual sin coordenadas
                latEl.value = '';
                lngEl.value = '';
                accEl.value = '';
                stEl.value  = data.status || 'error';
                ntEl.value  = data.note   || '';

                msgTemporal(
                    'Ubicación no disponible (' + (data.status || 'error') + ') — se registrará sin coordenadas.',
                    '#b45309', 4
                );

                // Continuar con el submit igual (sin coordenadas)
                if (accionPendiente) {
                    enviarMarcacion(accionPendiente);
                }
                accionPendiente = null;
                setBotones(true);
                return;
            }

            // ✅ GPS ok — validar geofence AHORA con coordenadas frescas
            if (data.dentro_geofence === false) {
                var dist = data.distancia_m ? ' (' + data.distancia_m + 'm de distancia)' : '';
                msgTemporal(
                    '📍 Fuera de rango' + dist + ' — acercate a la empresa para marcar.',
                    '#dc2626', 5  // desaparece en 5 segundos
                );
                accionPendiente = null;
                setBotones(true);
                return; // ← NO se submitea
            }

            // Dentro del geofence — llenar campos y submitear
            latEl.value = data.lat;
            lngEl.value = data.lng;
            accEl.value = data.accuracy_m !== null ? data.accuracy_m : '';
            stEl.value  = 'ok';
            ntEl.value  = '';

            msgTemporal('Ubicación: OK ±' + (data.accuracy_m || '?') + 'm', '', 0);

            if (accionPendiente) {
                enviarMarcacion(accionPendiente);
            }
            accionPendiente = null;
            setBotones(true);
        }
    };

    // ── Enviar el form con el tipo ya seteado ─────────────────────
    function enviarMarcacion(tipo) {
        document.getElementById('tipo').value = tipo;
        document.getElementById('markForm').submit();
    }

    // ── Solicitar GPS fresco y esperar resultado ──────────────────
    function solicitarGpsYMarcar(tipo) {
        if (esperandoGps) return; // evitar doble tap

        accionPendiente = tipo;
        esperandoGps    = true;
        setBotones(false); // deshabilitar mientras espera

        if (esNativa) {
            msg.textContent = 'Obteniendo ubicación…';
            msg.style.color = '#1d4ed8';
            window.Android.solicitarGps();
        } else {
            // Fallback navegador web
            if (!navigator.geolocation) {
                stEl.value = 'unavailable';
                enviarMarcacion(tipo);
                accionPendiente = null;
                esperandoGps    = false;
                setBotones(true);
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    latEl.value = pos.coords.latitude;
                    lngEl.value = pos.coords.longitude;
                    accEl.value = Math.round(pos.coords.accuracy || 0);
                    stEl.value  = 'ok';
                    ntEl.value  = '';
                    esperandoGps = false;
                    setBotones(true);
                    enviarMarcacion(tipo);
                    accionPendiente = null;
                },
                function(err) {
                    var st = 'error';
                    if (err && err.code === 1) st = 'denied';
                    if (err && (err.code === 2 || err.code === 3)) st = 'unavailable';
                    stEl.value   = st;
                    ntEl.value   = (err && err.message) ? err.message : '';
                    esperandoGps = false;
                    setBotones(true);
                    enviarMarcacion(tipo); // igual registra sin coords
                    accionPendiente = null;
                },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
        }
    }

    // ── Interceptar clicks de los botones ────────────────────────
    document.querySelectorAll('#markForm button[type=submit]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (btn.dataset.phpDisabled === 'true') return;
            e.preventDefault(); // siempre prevenir — GPS se pide primero

            var tipoMap = {
                'Marcar ENTRADA':   'inicio_jornada',
                'Inicio ALMUERZO':  'pausa_inicio',
                'Fin ALMUERZO':     'pausa_fin',
                'Marcar SALIDA':    'fin_jornada'
            };
            // Leer el tipo del onclick original o del texto del botón
            var tipo = btn.getAttribute('data-tipo') || tipoMap[btn.textContent.trim()];
            if (!tipo) return;

            solicitarGpsYMarcar(tipo);
        });
    });

    // Mensaje inicial
    msg.textContent = 'Ubicación: lista para marcar.';
    msg.style.color = '';

})();
</script>

</body>
</html>