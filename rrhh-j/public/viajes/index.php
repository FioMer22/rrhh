<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_once __DIR__ . '/../../app/helpers/utils.php';

require_login();

$pdo     = DB::pdo();
$uid     = (int)$_SESSION['uid'];
$esAdmin = has_any_role('admin', 'rrhh');
$ok = $err = null;

// ── POST: iniciar viaje ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'iniciar') {
    csrf_verify();
    $titulo  = trim($_POST['titulo']  ?? '');
    $destino = trim($_POST['destino'] ?? '');
    if ($titulo === '') { $err = 'El título del viaje es obligatorio.'; }
    else {
        $enCurso = $pdo->prepare("SELECT id FROM viajes WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
        $enCurso->execute([$uid]);
        if ($enCurso->fetch()) {
            $err = 'Ya tenés un viaje en curso. Finalizalo antes de iniciar otro.';
        } else {
            $pdo->prepare("
                INSERT INTO viajes (usuario_id, titulo, destino, fecha_inicio, inicio_dt, estado, creado_por)
                VALUES (?, ?, ?, CURDATE(), NOW(), 'en_curso', ?)
            ")->execute([$uid, $titulo, $destino ?: null, $uid]);
            $ok = '🚀 Viaje iniciado. Podés registrar descansos cuando necesites.';
        }
    }
}

// ── POST: descanso ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'descanso') {
    csrf_verify();
    $viajeId = (int)($_POST['viaje_id'] ?? 0);
    $tipo    = $_POST['tipo'] ?? '';
    $motivo  = trim($_POST['motivo'] ?? 'sueño');

    $stV = $pdo->prepare("SELECT id FROM viajes WHERE id=? AND usuario_id=? AND estado='en_curso'");
    $stV->execute([$viajeId, $uid]);
    if (!$stV->fetch()) { $err = 'Viaje no encontrado.'; }
    elseif ($tipo === 'iniciar_descanso') {
        $stD = $pdo->prepare("SELECT id FROM viaje_descansos WHERE viaje_id=? AND fin IS NULL");
        $stD->execute([$viajeId]);
        if ($stD->fetch()) { $err = 'Ya hay un descanso en curso.'; }
        else {
            $pdo->prepare("INSERT INTO viaje_descansos (viaje_id, inicio, motivo) VALUES (?, NOW(), ?)")
                ->execute([$viajeId, $motivo]);
            $ok = '😴 Descanso registrado.';
        }
    } elseif ($tipo === 'fin_descanso') {
        $pdo->prepare("UPDATE viaje_descansos SET fin=NOW() WHERE viaje_id=? AND fin IS NULL")
            ->execute([$viajeId]);
        $ok = '✅ Descanso finalizado. Seguís contando horas.';
    }
}

// ── POST: finalizar viaje ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'finalizar') {
    csrf_verify();
    $viajeId = (int)($_POST['viaje_id'] ?? 0);
    $stV = $pdo->prepare("SELECT id FROM viajes WHERE id=? AND usuario_id=? AND estado='en_curso'");
    $stV->execute([$viajeId, $uid]);
    if (!$stV->fetch()) { $err = 'Viaje no encontrado.'; }
    else {
        $pdo->prepare("UPDATE viaje_descansos SET fin=NOW() WHERE viaje_id=? AND fin IS NULL")
            ->execute([$viajeId]);
        $pdo->prepare("UPDATE viajes SET estado='finalizado', fecha_fin=CURDATE(), fin_dt=NOW() WHERE id=?")
            ->execute([$viajeId]);
        $ok = '🏁 Viaje finalizado correctamente.';
    }
}

// ── Datos ─────────────────────────────────────────────────────────────────────
$stActivo = $pdo->prepare("SELECT * FROM viajes WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
$stActivo->execute([$uid]);
$viajeActivo = $stActivo->fetch();

$descansoAbierto = null;
$descansos       = [];
if ($viajeActivo) {
    $stDA = $pdo->prepare("SELECT * FROM viaje_descansos WHERE viaje_id=? AND fin IS NULL LIMIT 1");
    $stDA->execute([$viajeActivo['id']]);
    $descansoAbierto = $stDA->fetch();

    $stDesc = $pdo->prepare("SELECT * FROM viaje_descansos WHERE viaje_id=? ORDER BY inicio DESC");
    $stDesc->execute([$viajeActivo['id']]);
    $descansos = $stDesc->fetchAll();
}

$stHist = $pdo->prepare("
    SELECT v.*,
        TIMESTAMPDIFF(MINUTE,
            IFNULL(v.inicio_dt, CONCAT(v.fecha_inicio,' 00:00:00')),
            IFNULL(v.fin_dt, NOW())) AS min_total,
        (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, d.inicio, IFNULL(d.fin,NOW()))),0)
         FROM viaje_descansos d WHERE d.viaje_id = v.id) AS min_descanso
    FROM viajes v
    WHERE v.usuario_id = ? AND v.estado = 'finalizado'
    ORDER BY v.fecha_inicio DESC LIMIT 10
");
$stHist->execute([$uid]);
$historial = $stHist->fetchAll();

// Viajes activos de toda la org (admin/rrhh)
$viajesActivos = [];
if ($esAdmin) {
    $viajesActivos = $pdo->query("
        SELECT v.*, CONCAT(u.nombre,' ',u.apellido) AS empleado,
               a.nombre AS area_nombre,
               TIMESTAMPDIFF(MINUTE, IFNULL(v.inicio_dt, CONCAT(v.fecha_inicio,' 00:00:00')), NOW()) AS min_transcurrido,
               (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,d.inicio,IFNULL(d.fin,NOW()))),0)
                FROM viaje_descansos d WHERE d.viaje_id=v.id) AS min_descanso
        FROM viajes v
        JOIN usuarios u ON u.id=v.usuario_id
        LEFT JOIN areas a ON a.id=u.area_id
        WHERE v.estado='en_curso'
        ORDER BY v.fecha_inicio ASC
    ")->fetchAll();
}

function hm(int $m): string {
    return sprintf('%02d:%02d', intdiv(abs($m),60), abs($m)%60);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Viajes</title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .viaje-activo{background:linear-gradient(135deg,#0b1f3a,#1a3a6b);color:#fff;
                      border-radius:16px;padding:22px;margin-bottom:14px}
        .viaje-activo.dormido{background:linear-gradient(135deg,#374151,#1f2937)}
        .viaje-activo h3{margin:0 0 4px;font-size:18px}
        .vmeta{color:rgba(255,255,255,.65);font-size:13px}
        .timer{font-size:44px;font-weight:700;letter-spacing:3px;
               margin:16px 0 4px;font-variant-numeric:tabular-nums}
        .estado-badge{padding:5px 12px;border-radius:999px;font-size:12px;font-weight:700}
        .chip-des{background:rgba(255,255,255,.12);border-radius:8px;
                  padding:5px 10px;font-size:12px;margin:3px;display:inline-block}
        .btn-des {background:#f59e0b;color:#1c1917;border:none;padding:10px 16px;
                  border-radius:10px;font-weight:700;cursor:pointer}
        .btn-wake{background:#22c55e;color:#fff;border:none;padding:10px 16px;
                  border-radius:10px;font-weight:700;cursor:pointer}
        .btn-fin {background:#ef4444;color:#fff;border:none;padding:10px 16px;
                  border-radius:10px;font-weight:700;cursor:pointer}
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:820px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok)  ?></div><?php endif; ?>

    <!-- ── Viaje en curso ──────────────────────────────────── -->
    <?php if ($viajeActivo): ?>
    <div class="viaje-activo <?= $descansoAbierto ? 'dormido' : '' ?>">
        <div style="display:flex;justify-content:space-between;
                    align-items:flex-start;flex-wrap:wrap;gap:8px">
            <div>
                <div class="vmeta" style="margin-bottom:4px">
                    ✈️ VIAJE EN CURSO · desde <?= e(date('d/m/Y', strtotime($viajeActivo['fecha_inicio']))) ?>
                </div>
                <h3><?= e($viajeActivo['titulo']) ?></h3>
                <?php if ($viajeActivo['destino']): ?>
                    <div class="vmeta">📍 <?= e($viajeActivo['destino']) ?></div>
                <?php endif; ?>
            </div>
            <span class="estado-badge"
                  style="background:<?= $descansoAbierto ? '#f59e0b;color:#1c1917' : '#22c55e;color:#fff' ?>">
                <?= $descansoAbierto ? '😴 DESCANSANDO' : '⚡ ACTIVO' ?>
            </span>
        </div>

        <div class="timer" id="timer-viaje"
             data-inicio="<?= e($viajeActivo['inicio_dt'] ?? $viajeActivo['fecha_inicio']) ?>"
             data-des-ini="<?= $descansoAbierto ? e($descansoAbierto['inicio']) : '' ?>"
             style="color:<?= $descansoAbierto ? '#f59e0b' : '#22c55e' ?>">
            --:-- hs activas
        </div>
        <div class="vmeta" style="margin-bottom:14px">
            <?php if ($descansoAbierto): ?>
                Pausado desde <?= e(date('H:i', strtotime($descansoAbierto['inicio']))) ?> 
                (<?= e($descansoAbierto['motivo']) ?>) — el tiempo no está contando
            <?php else: ?>
                Cada minuto cuenta. Registrá un descanso cuando vayas a dormir o pausar.
            <?php endif; ?>
        </div>

        <!-- Descansos -->
        <?php if ($descansos): ?>
        <div style="margin-bottom:16px">
            <div class="vmeta" style="font-size:11px;margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">
                Descansos registrados
            </div>
            <?php foreach ($descansos as $d): ?>
            <span class="chip-des">
                <?= match($d['motivo']) { 'sueño'=>'😴','comida'=>'🍽','pausa'=>'☕', default=>'⏸' } ?>
                <?= e(date('H:i', strtotime($d['inicio']))) ?>
                → <?= $d['fin'] ? e(date('H:i', strtotime($d['fin']))) : 'ahora' ?>
                · <?= e($d['motivo']) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Acciones -->
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if (!$descansoAbierto): ?>
            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"   value="descanso">
                <input type="hidden" name="viaje_id" value="<?= (int)$viajeActivo['id'] ?>">
                <input type="hidden" name="tipo"     value="iniciar_descanso">
                <select name="motivo" style="padding:9px;border-radius:10px;
                        border:1px solid rgba(255,255,255,.25);
                        background:rgba(255,255,255,.1);color:#fff;font-size:13px">
                    <option value="sueño">😴 Sueño</option>
                    <option value="comida">🍽 Comida</option>
                    <option value="pausa">☕ Pausa</option>
                </select>
                <button type="submit" class="btn-des">Iniciar descanso</button>
            </form>
            <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"   value="descanso">
                <input type="hidden" name="viaje_id" value="<?= (int)$viajeActivo['id'] ?>">
                <input type="hidden" name="tipo"     value="fin_descanso">
                <button type="submit" class="btn-wake">✅ Retomar actividad</button>
            </form>
            <?php endif; ?>

            <form method="post"
                  onsubmit="return confirm('¿Confirmás que el viaje terminó?')">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"   value="finalizar">
                <input type="hidden" name="viaje_id" value="<?= (int)$viajeActivo['id'] ?>">
                <button type="submit" class="btn-fin">🏁 Finalizar viaje</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Iniciar nuevo viaje ──────────────────────────────── -->
    <div class="card" style="margin-bottom:14px">
        <h3 style="margin:0 0 14px">✈️ Iniciar viaje</h3>
        <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="iniciar">
            <div style="grid-column:span 2">
                <label class="meta">Título del viaje *</label>
                <input type="text" name="titulo" required
                       placeholder="Ej: Misión Concepción, Campaña Alto Paraguay">
            </div>
            <div style="grid-column:span 2">
                <label class="meta">Destino</label>
                <input type="text" name="destino"
                       placeholder="Ej: Concepción, Amambay">
            </div>
            <div style="grid-column:span 2">
                <button type="submit" style="padding:12px 24px;font-size:15px">
                    🚀 Iniciar viaje
                </button>
                <p class="meta" style="margin-top:8px">
                    Las horas contarán desde ahora. Registrá descansos (sueño, comida) 
                    para que sean descontados del total de horas activas.
                </p>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Viajes activos de la org (admin/rrhh) ────────────── -->
    <?php if ($esAdmin && $viajesActivos): ?>
    <div class="card" style="margin-bottom:14px;border-left:4px solid #f59e0b">
        <h3 style="margin:0 0 12px">✈️ En viaje ahora — toda la organización</h3>
        <?php foreach ($viajesActivos as $v):
            $minNeto = max(0, (int)$v['min_transcurrido'] - (int)$v['min_descanso']);
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;
                    padding:10px 0;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px">
            <div>
                <strong><?= e($v['empleado']) ?></strong>
                <span style="background:#e8f0fe;color:#1a56db;padding:1px 8px;
                             border-radius:999px;font-size:11px;margin-left:6px">
                    <?= e($v['area_nombre'] ?? '—') ?>
                </span>
                <div class="meta"><?= e($v['titulo']) ?>
                    <?php if ($v['destino']): ?> · 📍 <?= e($v['destino']) ?><?php endif; ?>
                </div>
                <div class="meta">Desde <?= e(date('d/m/Y', strtotime($v['fecha_inicio']))) ?></div>
            </div>
            <div style="text-align:right">
                <div style="font-size:22px;font-weight:700;color:#114c97">
                    <?= hm($minNeto) ?>
                </div>
                <div class="meta">horas activas</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Mis viajes anteriores ────────────────────────────── -->
    <?php if ($historial): ?>
    <div class="card">
        <h3 style="margin:0 0 12px">📋 Mis viajes anteriores</h3>
        <table>
            <thead>
                <tr>
                    <th>Viaje</th>
                    <th>Destino</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                    <th>Horas activas</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $v):
                $minNeto = max(0, (int)$v['min_total'] - (int)$v['min_descanso']);
            ?>
            <tr>
                <td><strong><?= e($v['titulo']) ?></strong></td>
                <td class="meta"><?= e($v['destino'] ?? '—') ?></td>
                <td><?= e(date('d/m/Y', strtotime($v['fecha_inicio']))) ?></td>
                <td><?= $v['fecha_fin'] ? e(date('d/m/Y', strtotime($v['fecha_fin']))) : '—' ?></td>
                <td>
                    <strong style="color:#114c97"><?= hm($minNeto) ?></strong>
                    <?php if ((int)$v['min_descanso'] > 0): ?>
                        <div class="meta"><?= hm((int)$v['min_descanso']) ?> en descansos</div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
(function() {
    const el = document.getElementById('timer-viaje');
    if (!el) return;
    const fechaInicio  = el.dataset.inicio;   // YYYY-MM-DD
    const descansoIni  = el.dataset.desIni;   // datetime o vacío

    function calcular() {
        const ahora  = new Date();
        // Soporta tanto 'YYYY-MM-DD HH:MM:SS' como 'YYYY-MM-DD'
        const inicioStr = fechaInicio.includes(' ')
            ? fechaInicio.replace(' ', 'T')
            : fechaInicio + 'T00:00:00';
        const inicio = new Date(inicioStr);
        let minTotal = Math.floor((ahora - inicio) / 60000);

        if (descansoIni) {
            const dIni  = new Date(descansoIni.replace(' ', 'T'));
            const minDes = Math.floor((ahora - dIni) / 60000);
            minTotal = Math.max(0, minTotal - minDes);
        }

        const h = Math.floor(minTotal / 60);
        const m = minTotal % 60;
        el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ' hs activas';
    }

    calcular();
    setInterval(calcular, 30000);
})();
</script>
</body>
</html>
