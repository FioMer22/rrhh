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
$esAdmin = has_any_role('admin', 'rrhh', 'direccion', 'coordinador'); // puede crear eventos
$ok = $err = null;

$tiposEvento = [
    'reunion'        => '🤝 Reunión',
    'capacitacion'   => '📚 Capacitación',
    'actividad'      => '🗺 Actividad de campo',
    'celebracion'    => '🎉 Celebración',
    'administrativo' => '📋 Administrativo',
    'otro'           => '📌 Otro',
];

// ── POST: crear evento ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear' && $esAdmin) {
    csrf_verify();

    $titulo      = trim($_POST['titulo']      ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $tipo        = $_POST['tipo']             ?? 'otro';
    $lugar_texto = trim($_POST['lugar_texto'] ?? '');
    $inicio      = trim($_POST['inicio']      ?? '');
    $fin         = trim($_POST['fin']         ?? '');
    $sede_id     = (int)($_POST['sede_id']    ?? 0);
    $areas_conv  = array_map('intval', (array)($_POST['areas_convocadas'] ?? []));

    if ($titulo === '' || $inicio === '' || $fin === '') {
        $err = 'Título, fecha inicio y fecha fin son obligatorios.';
    } elseif (empty($areas_conv)) {
        $err = 'Seleccioná al menos un área convocada.';
    } else {
        // Insertar en tabla eventos
        $pdo->prepare("
            INSERT INTO eventos
                (titulo, descripcion, tipo, sede_id, lugar_texto, inicio, fin, estado, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'programado', ?)
        ")->execute([
            $titulo,
            $descripcion !== '' ? $descripcion : null,
            $tipo,
            $sede_id > 0 ? $sede_id : null,
            $lugar_texto !== '' ? $lugar_texto : null,
            $inicio,
            $fin,
            $uid,
        ]);
        $eventoId = (int)$pdo->lastInsertId();

        // Convocar por área: una fila en eventos_convocatorias + participantes
        foreach ($areas_conv as $areaId) {
            if ($areaId <= 0) continue;

            $pdo->prepare("
                INSERT INTO eventos_convocatorias
                    (evento_id, target_tipo, target_id, obligatorio)
                VALUES (?, 'area', ?, 1)
            ")->execute([$eventoId, $areaId]);

            // Todos los miembros activos del área se agregan como participantes
            $miembros = $pdo->prepare("SELECT id FROM usuarios WHERE area_id = ? AND activo = 1");
            $miembros->execute([$areaId]);
            foreach ($miembros->fetchAll() as $m) {
                $pdo->prepare("
                    INSERT IGNORE INTO eventos_participantes
                        (evento_id, usuario_id, estado_respuesta, obligatorio)
                    VALUES (?, ?, 'pendiente', 1)
                ")->execute([$eventoId, $m['id']]);
            }
        }

        $ok = "Evento \"{$titulo}\" creado. Se notificó a " . count($areas_conv) . " área(s).";
    }
}

// ── POST: confirmar recepción ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'confirmar') {
    csrf_verify();
    $eventoId = (int)($_POST['evento_id'] ?? 0);
    $nota     = trim($_POST['nota'] ?? '');
    if ($eventoId > 0) {
        $pdo->prepare("
            UPDATE eventos_participantes
            SET estado_respuesta = 'confirmado', respondio_en = NOW(), nota = ?
            WHERE evento_id = ? AND usuario_id = ?
        ")->execute([$nota !== '' ? $nota : null, $eventoId, $uid]);
        $ok = '✅ Recepción confirmada correctamente.';
    }
}

// ── Mis convocatorias pendientes ─────────────────────────────────────────────
$misPendientes = $pdo->prepare("
    SELECT ep.evento_id, ep.estado_respuesta,
           e.titulo, e.tipo, e.inicio, e.fin,
           e.lugar_texto, e.descripcion
    FROM eventos_participantes ep
    JOIN eventos e ON e.id = ep.evento_id
    WHERE ep.usuario_id = ? AND ep.estado_respuesta = 'pendiente'
    ORDER BY e.inicio ASC
    LIMIT 20
");
$misPendientes->execute([$uid]);
$misPendientes = $misPendientes->fetchAll();

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtroMes  = $_GET['mes']     ?? date('Y-m');
$filtroArea = (int)($_GET['area_id'] ?? 0);
$filtroTipo = $_GET['tipo']    ?? '';

$whereEv  = ["e.inicio >= ?", "e.inicio < ?"];
$paramsEv = [
    $filtroMes . '-01',
    date('Y-m-01', strtotime($filtroMes . '-01 +1 month')),
];

if ($filtroArea > 0) {
    $whereEv[]  = "EXISTS (
        SELECT 1 FROM eventos_convocatorias ec
        WHERE ec.evento_id = e.id AND ec.target_tipo='area' AND ec.target_id = ?
    )";
    $paramsEv[] = $filtroArea;
}

if ($filtroTipo !== '') {
    $whereEv[]  = "e.tipo = ?";
    $paramsEv[] = $filtroTipo;
}

$eventos = $pdo->prepare("
    SELECT
        e.*,
        CONCAT(u.nombre,' ',u.apellido) AS creador,
        s.nombre AS sede_nombre,
        (SELECT COUNT(DISTINCT ep2.usuario_id)
         FROM eventos_participantes ep2 WHERE ep2.evento_id = e.id)              AS total_conv,
        (SELECT COUNT(*) FROM eventos_participantes ep3
         WHERE ep3.evento_id = e.id AND ep3.estado_respuesta='confirmado')       AS confirmados,
        (SELECT COUNT(*) FROM eventos_participantes ep4
         WHERE ep4.evento_id = e.id AND ep4.estado_respuesta='pendiente')        AS pendientes
    FROM eventos e
    LEFT JOIN usuarios u ON u.id = e.creado_por
    LEFT JOIN sedes s    ON s.id = e.sede_id
    WHERE " . implode(' AND ', $whereEv) . "
    ORDER BY e.inicio ASC
    LIMIT 100
");
$eventos->execute($paramsEv);
$eventos = $eventos->fetchAll();

// Datos para formulario
$areas  = $pdo->query("SELECT id, nombre FROM areas WHERE activo=1 ORDER BY nombre")->fetchAll();
$sedes  = $pdo->query("SELECT id, nombre FROM sedes WHERE activo=1 ORDER BY nombre")->fetchAll();

// Meses (2 pasados + 6 futuros)
$meses = [];
for ($i = -2; $i <= 6; $i++) {
    $meses[] = date('Y-m', strtotime("{$i} months"));
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Eventos Institucionales</title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .ev-card{background:#fff;border-radius:14px;padding:16px;margin-bottom:12px;
                 border:1px solid #e5e7eb;box-shadow:0 4px 14px rgba(0,0,0,.05)}
        .ev-card h4{margin:0 0 6px;font-size:16px;color:#0b1f3a}
        .ev-meta{font-size:12px;color:#6b7280;margin:3px 0}
        .tipo-tag{display:inline-block;padding:2px 10px;border-radius:999px;
                  font-size:11px;background:#e8f0fe;color:#1a56db;margin-bottom:8px}
        .prog-bar{background:#e5e7eb;border-radius:999px;height:8px;margin-top:6px}
        .prog-fill{background:#0a5c2e;border-radius:999px;height:8px;transition:width .3s}
        .filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
        .filtros select,.filtros input{width:auto;padding:8px 10px}
        .areas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:8px}
        .area-check{display:flex;align-items:center;gap:8px;padding:8px 12px;
                    border:1px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:background .1s}
        .area-check:hover{background:#f0f7ff;border-color:#93c5fd}
        .area-check input{margin:0;accent-color:#114c97}
        .notif-box{background:#fffbeb;border:2px solid #f59e0b;border-radius:14px;
                   padding:16px;margin-bottom:14px}
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1100px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

    <!-- ── Mis convocatorias pendientes ───────────────────────────── -->
    <?php if ($misPendientes): ?>
    <div class="notif-box">
        <h3 style="margin:0 0 12px;color:#92400e">
            📣 Tenés <?= count($misPendientes) ?> convocatoria(s) sin confirmar
        </h3>
        <?php foreach ($misPendientes as $mp): ?>
        <div style="border-bottom:1px solid #fde68a;padding:12px 0;
                    display:flex;justify-content:space-between;align-items:flex-start;
                    flex-wrap:wrap;gap:12px">
            <div>
                <strong><?= e($mp['titulo']) ?></strong>
                <span class="tipo-tag" style="margin-left:8px">
                    <?= e($tiposEvento[$mp['tipo']] ?? $mp['tipo']) ?>
                </span>
                <div class="ev-meta">
                    🗓 <?= e(date('d/m/Y H:i', strtotime($mp['inicio']))) ?>
                    → <?= e(date('d/m/Y H:i', strtotime($mp['fin']))) ?>
                </div>
                <?php if ($mp['lugar_texto']): ?>
                    <div class="ev-meta">📍 <?= e($mp['lugar_texto']) ?></div>
                <?php endif; ?>
                <?php if ($mp['descripcion']): ?>
                    <div class="ev-meta"><?= e(mb_substr($mp['descripcion'],0,150)) ?></div>
                <?php endif; ?>
            </div>
            <form method="post" style="display:flex;flex-direction:column;gap:6px;min-width:220px">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"    value="confirmar">
                <input type="hidden" name="evento_id" value="<?= (int)$mp['evento_id'] ?>">
                <textarea name="nota" rows="2" placeholder="Comentario opcional..."
                    style="font-size:12px;padding:6px;border-radius:8px;border:1px solid #e5e7eb"></textarea>
                <button style="background:#0a5c2e;padding:8px 14px;font-size:13px">
                    ✅ Confirmar recepción
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Crear evento ────────────────────────────────────────────── -->
    <?php if ($esAdmin): ?>
    <div class="card" style="margin-bottom:14px">
        <h2>Crear evento institucional</h2>
        <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">

            <div style="grid-column:span 2">
                <label class="meta">Título *</label>
                <input type="text" name="titulo" required
                       placeholder="Ej: Reunión mensual de equipos">
            </div>

            <div>
                <label class="meta">Tipo de evento</label>
                <select name="tipo">
                    <?php foreach ($tiposEvento as $val => $label): ?>
                        <option value="<?= e($val) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="meta">Ubicación / Lugar</label>
                <input type="text" name="lugar_texto"
                       placeholder="Ej: Sala principal, dirección o 'Virtual'">
            </div>

            <div>
                <label class="meta">Inicio *</label>
                <input type="datetime-local" name="inicio" required>
            </div>

            <div>
                <label class="meta">Fin *</label>
                <input type="datetime-local" name="fin" required>
            </div>

            <?php if ($sedes): ?>
            <div style="grid-column:span 2">
                <label class="meta">Sede (opcional)</label>
                <select name="sede_id">
                    <option value="0">— Sin sede específica —</option>
                    <?php foreach ($sedes as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="grid-column:span 2">
                <label class="meta">Descripción / Agenda</label>
                <textarea name="descripcion" rows="3"
                    placeholder="Objetivos, agenda, materiales necesarios..."></textarea>
            </div>

            <div style="grid-column:span 2">
                <label class="meta" style="display:block;margin-bottom:8px">
                    Áreas convocadas *
                    <span style="color:#6b7280;font-weight:normal">
                        — se notifica automáticamente a todos los miembros de cada área
                    </span>
                </label>
                <?php if ($areas): ?>
                <div class="areas-grid">
                    <?php foreach ($areas as $a): ?>
                    <label class="area-check">
                        <input type="checkbox" name="areas_convocadas[]"
                               value="<?= (int)$a['id'] ?>">
                        <?= e($a['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="meta" style="color:#842029">
                        ⚠ No hay áreas activas. Creá áreas primero en el menú Áreas.
                    </p>
                <?php endif; ?>
            </div>

            <div style="grid-column:span 2">
                <button type="submit" style="padding:12px 20px;font-size:15px">
                    📅 Crear evento y convocar áreas
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Filtros ─────────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:14px">
        <form method="get" class="filtros">
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Mes</label>
                <select name="mes">
                    <?php foreach ($meses as $m): ?>
                        <option value="<?= e($m) ?>" <?= $filtroMes===$m?'selected':'' ?>>
                            <?= e(date('F Y', strtotime($m.'-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Área</label>
                <select name="area_id">
                    <option value="0">Todas las áreas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"
                            <?= $filtroArea===(int)$a['id']?'selected':'' ?>>
                            <?= e($a['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Tipo</label>
                <select name="tipo">
                    <option value="">Todos</option>
                    <?php foreach ($tiposEvento as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $filtroTipo===$v?'selected':''?>>
                            <?= e($l) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:6px;align-items:flex-end">
                <button type="submit">Filtrar</button>
                <a class="btn" href="index.php" style="background:#6b7280">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- ── Listado de eventos ──────────────────────────────────────── -->
    <?php if (!$eventos): ?>
        <div class="card">
            <p class="meta">No hay eventos para el período seleccionado.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($eventos as $ev):
        $pct = $ev['total_conv'] > 0
            ? round(($ev['confirmados'] / $ev['total_conv']) * 100)
            : 0;

        // Áreas convocadas con su progreso
        $areasEv = $pdo->prepare("
            SELECT a.nombre,
                COUNT(ep.id)                              AS total,
                SUM(ep.estado_respuesta = 'confirmado')   AS conf
            FROM eventos_convocatorias ec
            JOIN areas a ON a.id = ec.target_id
            LEFT JOIN eventos_participantes ep
                ON ep.evento_id = ec.evento_id
                AND ep.usuario_id IN (
                    SELECT id FROM usuarios WHERE area_id = a.id AND activo = 1
                )
            WHERE ec.evento_id = ? AND ec.target_tipo = 'area'
            GROUP BY a.id, a.nombre
            ORDER BY a.nombre
        ");
        $areasEv->execute([$ev['id']]);
        $areasEv = $areasEv->fetchAll();
    ?>
    <div class="ev-card">
        <div style="display:flex;justify-content:space-between;
                    align-items:flex-start;flex-wrap:wrap;gap:14px">
            <div style="flex:1;min-width:260px">
                <span class="tipo-tag">
                    <?= e($tiposEvento[$ev['tipo']] ?? $ev['tipo']) ?>
                </span>
                <h4><?= e($ev['titulo']) ?></h4>

                <div class="ev-meta">
                    🗓 <?= e(date('d/m/Y H:i', strtotime($ev['inicio']))) ?>
                    → <?= e(date('d/m/Y H:i', strtotime($ev['fin']))) ?>
                </div>

                <?php if ($ev['lugar_texto']): ?>
                    <div class="ev-meta">📍 <?= e($ev['lugar_texto']) ?></div>
                <?php endif; ?>

                <?php if ($ev['sede_nombre']): ?>
                    <div class="ev-meta">🏢 <?= e($ev['sede_nombre']) ?></div>
                <?php endif; ?>

                <?php if ($ev['descripcion']): ?>
                    <div class="ev-meta" style="margin-top:8px;line-height:1.5">
                        <?= e(mb_substr($ev['descripcion'], 0, 220)) ?>
                        <?= mb_strlen($ev['descripcion']) > 220 ? '…' : '' ?>
                    </div>
                <?php endif; ?>

                <div class="ev-meta" style="margin-top:8px">
                    👤 Creado por <?= e($ev['creador'] ?? '—') ?>
                </div>

                <!-- Áreas convocadas -->
                <?php if ($areasEv): ?>
                <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">
                    <?php foreach ($areasEv as $ae):
                        $aConf  = (int)$ae['conf'];
                        $aTotal = (int)$ae['total'];
                        $color  = $aTotal > 0 && $aConf === $aTotal
                            ? '#0a5c2e'
                            : ($aConf > 0 ? '#856404' : '#374151');
                    ?>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;
                                border-radius:10px;padding:5px 10px;font-size:12px">
                        <?= e($ae['nombre']) ?>
                        <strong style="color:<?= $color ?>;margin-left:4px">
                            <?= $aConf ?>/<?= $aTotal ?>
                        </strong>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Progreso total -->
            <div style="min-width:150px;text-align:center">
                <div style="font-size:32px;font-weight:700;color:#0b1f3a"><?= $pct ?>%</div>
                <div class="meta">recibieron info</div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="meta" style="margin-top:4px">
                    <?= (int)$ev['confirmados'] ?> de <?= (int)$ev['total_conv'] ?>
                </div>

                <a href="detalle.php?id=<?= (int)$ev['id'] ?>"
                   style="display:inline-block;margin-top:10px;font-size:13px;
                          color:#fff;text-decoration:none;font-weight:600;
                          background:#114c97;padding:7px 14px;border-radius:10px;
                          width:100%;text-align:center;box-sizing:border-box">
                    📋 Ver detalle y checklist
                </a>
                <?php if ($ev['pendientes'] > 0): ?>
                <div style="margin-top:6px;font-size:11px;color:#856404">
                    ⚠ <?= (int)$ev['pendientes'] ?> sin confirmar recepción
                </div>
                <?php elseif ($pct === 100 && $ev['total_conv'] > 0): ?>
                <div style="margin-top:6px;color:#0a5c2e;font-size:11px;font-weight:600">
                    ✅ Todos confirmaron
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>
</body>
</html>
