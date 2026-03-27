<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_once __DIR__ . '/../../app/helpers/utils.php';

require_login();
require_role('admin', 'rrhh');

$pdo = DB::pdo();

// Filtros
$filtroEstado  = $_GET['estado']   ?? '';
$filtroUsuario = (int)($_GET['usuario_id'] ?? 0);
$filtroMes     = $_GET['mes']      ?? date('Y-m'); // default: mes actual
$q             = trim($_GET['q']   ?? '');

// Construir WHERE
$where  = ['1=1'];
$params = [];

if ($filtroEstado !== '') {
    $where[]  = 's.estado = ?';
    $params[] = $filtroEstado;
}

if ($filtroUsuario > 0) {
    $where[]  = 's.usuario_id = ?';
    $params[] = $filtroUsuario;
}

if ($filtroMes !== '') {
    $where[]  = "DATE_FORMAT(s.inicio, '%Y-%m') <= ? AND DATE_FORMAT(s.fin, '%Y-%m') >= ?";
    $params[] = $filtroMes;
    $params[] = $filtroMes;
}

if ($q !== '') {
    $where[]  = "(u.nombre LIKE ? OR u.apellido LIKE ? OR t.nombre LIKE ?)";
    $like     = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$solicitudes = $pdo->prepare("
    SELECT
        s.id, s.inicio, s.fin, s.comentario, s.estado,
        s.created_at, s.aprobado_en,
        t.nombre  AS tipo,
        t.requiere_aprobacion,
        CONCAT(u.nombre,' ',u.apellido)  AS empleado,
        u.email                          AS empleado_email,
        CONCAT(a.nombre,' ',a.apellido)  AS aprobador
    FROM solicitudes_ausencia s
    JOIN tipos_ausencia t ON t.id = s.tipo_ausencia_id
    JOIN usuarios u       ON u.id = s.usuario_id
    LEFT JOIN usuarios a  ON a.id = s.aprobador_id
    WHERE {$whereSql}
    ORDER BY s.inicio ASC
    LIMIT 200
");
$solicitudes->execute($params);
$solicitudes = $solicitudes->fetchAll();

// Para el filtro de hoy: ausencias que están activas HOY
$hoy = date('Y-m-d');
$ausentesHoy = $pdo->query("
    SELECT
        CONCAT(u.nombre,' ',u.apellido) AS empleado,
        u.email,
        t.nombre AS tipo,
        s.inicio, s.fin, s.comentario
    FROM solicitudes_ausencia s
    JOIN tipos_ausencia t ON t.id = s.tipo_ausencia_id
    JOIN usuarios u       ON u.id = s.usuario_id
    WHERE s.estado = 'aprobado'
      AND s.inicio <= '{$hoy}'
      AND s.fin    >= '{$hoy}'
    ORDER BY u.nombre, u.apellido
")->fetchAll();

// Lista de usuarios para el filtro
$usuarios = $pdo->query("
    SELECT id, CONCAT(nombre,' ',apellido) AS label
    FROM usuarios WHERE activo = 1
    ORDER BY nombre, apellido
")->fetchAll();

// Meses disponibles (últimos 12)
$meses = [];
for ($i = 0; $i < 12; $i++) {
    $meses[] = date('Y-m', strtotime("-{$i} months"));
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Historial de Ausencias</title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .filtros { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px }
        .filtros input, .filtros select { width:auto; padding:8px 10px }
        .hoy-card { background:#fff8f0; border:2px solid #f59e0b; border-radius:14px; padding:14px; margin-bottom:14px }
        .hoy-card h3 { margin:0 0 10px; color:#92400e }
        .ausente-row { display:flex; justify-content:space-between; align-items:center;
                       padding:8px 0; border-bottom:1px solid #fde68a; flex-wrap:wrap; gap:6px }
        .ausente-row:last-child { border-bottom:none }
        .resumen { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px }
        .stat-box { background:#fff; border-radius:14px; padding:14px 20px; border:1px solid #e5e7eb;
                    text-align:center; flex:1; min-width:120px }
        .stat-box .num { font-size:28px; font-weight:700; color:#0b1f3a }
        .stat-box .lbl { font-size:12px; color:#6b7280; margin-top:4px }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1100px">
    <h2>Historial de Ausencias</h2>

    <!-- ── Ausentes HOY ─────────────────────────────────────────────── -->
    <?php if ($ausentesHoy): ?>
    <div class="hoy-card">
        <h3>📅 Ausentes HOY (<?= e(date('d/m/Y')) ?>) — <?= count($ausentesHoy) ?> persona(s)</h3>
        <?php foreach ($ausentesHoy as $ah): ?>
        <div class="ausente-row">
            <div>
                <strong><?= e($ah['empleado']) ?></strong>
                <span class="meta"> — <?= e($ah['tipo']) ?></span>
                <?php if ($ah['comentario']): ?>
                    <span class="meta"> · <?= e(mb_substr($ah['comentario'], 0, 60)) ?></span>
                <?php endif; ?>
            </div>
            <div class="meta">
                <?= fmt_fecha($ah['inicio']) ?> → <?= fmt_fecha($ah['fin']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card" style="background:#f0fdf4;border:1px solid #86efac;margin-bottom:14px">
        <p style="margin:0;color:#166534">✅ No hay ausencias aprobadas para hoy.</p>
    </div>
    <?php endif; ?>

    <!-- ── Resumen del mes ──────────────────────────────────────────── -->
    <?php
    $totales = ['pendiente'=>0,'aprobado'=>0,'rechazado'=>0,'borrador'=>0];
    foreach ($solicitudes as $s) {
        $totales[$s['estado']] = ($totales[$s['estado']] ?? 0) + 1;
    }
    ?>
    <div class="resumen">
        <div class="stat-box">
            <div class="num"><?= count($solicitudes) ?></div>
            <div class="lbl">Total en filtro</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#0a5c2e"><?= $totales['aprobado'] ?></div>
            <div class="lbl">Aprobadas</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#856404"><?= $totales['pendiente'] ?></div>
            <div class="lbl">Pendientes</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#842029"><?= $totales['rechazado'] ?></div>
            <div class="lbl">Rechazadas</div>
        </div>
    </div>

    <!-- ── Filtros ──────────────────────────────────────────────────── -->
    <div class="card">
        <form method="get" class="filtros">
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Mes</label>
                <select name="mes">
                    <?php foreach ($meses as $m): ?>
                        <option value="<?= e($m) ?>" <?= $filtroMes===$m?'selected':'' ?>>
                            <?= e(date('F Y', strtotime($m.'-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="">Todos</option>
                </select>
            </div>
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Estado</label>
                <select name="estado">
                    <option value="">Todos</option>
                    <option value="aprobado"  <?= $filtroEstado==='aprobado'  ?'selected':''?>>Aprobadas</option>
                    <option value="pendiente" <?= $filtroEstado==='pendiente' ?'selected':''?>>Pendientes</option>
                    <option value="rechazado" <?= $filtroEstado==='rechazado' ?'selected':''?>>Rechazadas</option>
                </select>
            </div>
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Empleado</label>
                <select name="usuario_id">
                    <option value="0">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filtroUsuario===(int)$u['id']?'selected':''?>>
                            <?= e($u['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta" style="display:block;margin-bottom:4px">Buscar</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Nombre o tipo...">
            </div>
            <div style="display:flex;gap:6px;align-items:flex-end">
                <button type="submit">Filtrar</button>
                <a class="btn" href="historial.php" style="background:#6b7280">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- ── Tabla de solicitudes ─────────────────────────────────────── -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tipo</th>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Días</th>
                    <th>Estado</th>
                    <th>Comentario</th>
                    <th>Aprobado por</th>
                    <th>Aprobado el</th>
                    <th>Solicitado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($solicitudes as $s):
                $dias = (int)((strtotime($s['fin']) - strtotime($s['inicio'])) / 86400) + 1;
                // Resaltar si el permiso es HOY
                $esHoy = $s['estado'] === 'aprobado'
                    && $s['inicio'] <= $hoy
                    && $s['fin']    >= $hoy;
            ?>
                <tr style="<?= $esHoy ? 'background:#fff8f0;font-weight:600' : '' ?>">
                    <td>
                        <?= $esHoy ? '📅 ' : '' ?>
                        <?= e($s['empleado']) ?>
                        <div class="meta"><?= e($s['empleado_email']) ?></div>
                    </td>
                    <td><?= e($s['tipo']) ?></td>
                    <td><?= fmt_fecha($s['inicio']) ?></td>
                    <td><?= fmt_fecha($s['fin']) ?></td>
                    <td style="text-align:center"><?= $dias ?></td>
                    <td><?= estado_ausencia_badge($s['estado']) ?></td>
                    <td><?= e($s['comentario'] ?? '—') ?></td>
                    <td><?= e($s['aprobador'] ?? '—') ?></td>
                    <td class="meta"><?= $s['aprobado_en'] ? fmt_fecha($s['aprobado_en']) : '—' ?></td>
                    <td class="meta"><?= e($s['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$solicitudes): ?>
                <tr><td colspan="10" class="meta" style="padding:20px">Sin resultados para los filtros seleccionados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
