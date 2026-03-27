<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/ausencias_helper.php';
require_once __DIR__ . '/../../helpers/utils.php';

require_login();
$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

if (!defined('JORNADA_MIN')) define('JORNADA_MIN', 540);
if (!defined('TOLERANCIA'))  define('TOLERANCIA',  10);

$hoy = new DateTime('now');
$mes = $_GET['mes'] ?? $hoy->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = $hoy->format('Y-m');
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));

// Datos del usuario
$yo = $pdo->prepare("
    SELECT u.id, u.nombre, u.apellido, u.email,
           a.nombre AS area_nombre,
           CONCAT(j.nombre,' ',j.apellido) AS jefe_nombre,
           t.nombre AS turno_nombre
    FROM usuarios u
    LEFT JOIN areas a    ON a.id = u.area_id
    LEFT JOIN usuarios j ON j.id = u.jefe_id
    LEFT JOIN usuario_turnos ut
        ON ut.usuario_id = u.id
       AND ut.vigente_desde <= CURDATE()
       AND (ut.vigente_hasta IS NULL OR ut.vigente_hasta >= CURDATE())
    LEFT JOIN turnos t ON t.id = ut.turno_id
    WHERE u.id = ? LIMIT 1
");
$yo->execute([$uid]);
$yo = $yo->fetch();

// Marcas del mes
$stM = $pdo->prepare("
    SELECT DATE(fecha_hora) AS fecha,
           MIN(CASE WHEN tipo='inicio_jornada' THEN fecha_hora END) AS entrada,
           MIN(CASE WHEN tipo='pausa_inicio'   THEN fecha_hora END) AS alm_ini,
           MAX(CASE WHEN tipo='pausa_fin'      THEN fecha_hora END) AS alm_fin,
           MAX(CASE WHEN tipo='fin_jornada'    THEN fecha_hora END) AS salida
    FROM asistencia_marcas
    WHERE usuario_id=? AND fecha_hora >= ? AND fecha_hora <= ?
    GROUP BY DATE(fecha_hora)
");
$stM->execute([$uid, $desde.' 00:00:00', $hasta.' 23:59:59']);
$marcas = [];
foreach ($stM->fetchAll() as $m) $marcas[$m['fecha']] = $m;

// Ausencias aprobadas del mes
$ausenciasMap = cargar_ausencias_aprobadas($pdo, $desde, $hasta, [$uid]);
$ausenciasUsr = $ausenciasMap[$uid] ?? [];

// Viajes del mes
$stV = $pdo->prepare("
    SELECT v.*,
        TIMESTAMPDIFF(MINUTE,
            IFNULL(v.inicio_dt, CONCAT(v.fecha_inicio,' 00:00:00')),
            IFNULL(v.fin_dt, NOW())) AS min_total,
        (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,d.inicio,IFNULL(d.fin,NOW()))),0)
         FROM viaje_descansos d WHERE d.viaje_id=v.id) AS min_descanso
    FROM viajes v
    WHERE v.usuario_id=? AND v.fecha_inicio >= ? AND v.fecha_inicio <= ?
    ORDER BY v.fecha_inicio ASC
");
$stV->execute([$uid, $desde, $hasta]);
$viajes = $stV->fetchAll();

// Días hábiles
$diasHabiles = [];
$cur = new DateTime($desde); $fin = new DateTime($hasta);
while ($cur <= $fin) {
    if ((int)$cur->format('N') <= 5) $diasHabiles[] = $cur->format('Y-m-d');
    $cur->modify('+1 day');
}

function mins(?string $a, ?string $b): int {
    if (!$a || !$b) return 0;
    return max(0, (int)round((strtotime($b) - strtotime($a)) / 60));
}
function hm(int $m): string {
    return sprintf('%02d:%02d', intdiv(abs($m), 60), abs($m) % 60);
}
function hFmt(?string $dt): string { return $dt ? date('H:i', strtotime($dt)) : '—'; }

$detalle = []; $sumTrabajo = $sumExtra = $sumTardanza = 0;
$diasTrabajados = $diasAusente = 0;

foreach ($diasHabiles as $fecha) {
    if (!isset($marcas[$fecha])) {
        if ($fecha <= $hoy->format('Y-m-d')) {
            $permiso = $ausenciasUsr[$fecha] ?? null;
            if ($permiso) {
                // Día con permiso aprobado — no cuenta como ausente
                $detalle[] = ['fecha'=>$fecha,'estado'=>'permiso',
                              'tipo_permiso'=>$permiso['tipo'],
                              'color_permiso'=>$permiso['color'],
                              'entrada'=>null,'salida'=>null,'alm'=>null,
                              'min_neto'=>0,'min_extra'=>0,'min_tard'=>0];
            } else {
                $diasAusente++;
                $detalle[] = ['fecha'=>$fecha,'estado'=>'ausente',
                              'entrada'=>null,'salida'=>null,'alm'=>null,
                              'min_neto'=>0,'min_extra'=>0,'min_tard'=>0];
            }
        }
        continue;
    }
    $m = $marcas[$fecha];
    $minAlm   = mins($m['alm_ini'], $m['alm_fin']);
    $minNeto  = max(0, mins($m['entrada'], $m['salida']) - $minAlm);
    $minExtra = max(0, $minNeto - JORNADA_MIN);
    $minTard  = 0;
    if ($m['entrada']) {
        $lim = strtotime("$fecha 07:30 +" . TOLERANCIA . " minutes");
        $te  = strtotime($m['entrada']);
        if ($te > $lim) $minTard = (int)floor(($te - $lim) / 60);
    }
    $diasTrabajados++; $sumTrabajo += $minNeto;
    $sumExtra += $minExtra; $sumTardanza += $minTard;
    $detalle[] = [
        'fecha'    => $fecha, 'estado' => 'trabajado',
        'entrada'  => $m['entrada'], 'salida' => $m['salida'],
        'alm'      => ($m['alm_ini'] && $m['alm_fin'])
                      ? hFmt($m['alm_ini']).'→'.hFmt($m['alm_fin']) : null,
        'min_neto' => $minNeto, 'min_extra' => $minExtra, 'min_tard' => $minTard,
    ];
}

$sumViajeMin = 0;
foreach ($viajes as $v)
    $sumViajeMin += max(0, (int)$v['min_total'] - (int)$v['min_descanso']);

$meses = [];
for ($i = -6; $i <= 0; $i++) $meses[] = date('Y-m', strtotime("$i months"));

$esperado = $diasTrabajados * JORNADA_MIN;
$pct = $esperado > 0 ? min(100, round($sumTrabajo * 100 / $esperado)) : 0;
$pctColor = $pct >= 95 ? '#0a5c2e' : ($pct >= 80 ? '#856404' : '#842029');
$dias_es  = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mi asistencia</title>
  <?php require_once __DIR__ . '/../../../public/_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:14px}
        .kpi{background:#fff;border-radius:12px;padding:14px 10px;text-align:center;border:1px solid #e5e7eb}
        .kpi-val{font-size:24px;font-weight:700}
        .kpi-lab{font-size:11px;color:#6b7280;margin-top:3px}
        .verde{color:#0a5c2e}.rojo{color:#842029}.amarillo{color:#856404}
        .azul{color:#114c97}.naranja{color:#92400e}
        .fila-ausente{background:#fff5f5}
        .badge{padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
        .b-verde{background:#d1e7dd;color:#0a3622}
        .b-amarillo{background:#fff3cd;color:#856404}
        .b-rojo{background:#f8d7da;color:#842029}
        .b-azul{background:#dbeafe;color:#1e40af}
        @media print{.no-print{display:none!important}.card{box-shadow:none!important;border:1px solid #ddd}}
    </style>
</head>
<body>
<?php require __DIR__ . '/../../../public/_layout.php'; ?>

<div class="wrap" style="max-width:900px">

    <!-- Filtro mes -->
    <div class="no-print" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="get" style="display:flex;gap:8px;align-items:center">
            <select name="mes" onchange="this.form.submit()"
                    style="padding:8px 12px;border-radius:10px;border:1px solid #d1d5db">
                <?php foreach ($meses as $m): ?>
                    <option value="<?= e($m) ?>" <?= $mes===$m?'selected':'' ?>>
                        <?= e(date('F Y', strtotime($m.'-01'))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button onclick="window.print()"
                style="padding:8px 14px;background:#374151;color:#fff;border:none;border-radius:10px;cursor:pointer">
            🖨 Imprimir
        </button>
    </div>

    <!-- Perfil + KPIs -->
    <div class="card" style="margin-bottom:14px">
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <div style="width:50px;height:50px;background:#114c97;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;
                        color:#fff;font-size:22px;font-weight:700;flex-shrink:0">
                <?= strtoupper(mb_substr($yo['nombre'],0,1)) ?>
            </div>
            <div style="flex:1;min-width:180px">
                <div style="font-size:17px;font-weight:700">
                    <?= e(trim($yo['nombre'].' '.$yo['apellido'])) ?>
                </div>
                <div class="meta"><?= e($yo['email']) ?></div>
                <div class="meta" style="margin-top:2px">
                    <?php if ($yo['area_nombre']): ?>📂 <?= e($yo['area_nombre']) ?>&nbsp;·&nbsp;<?php endif; ?>
                    <?php if ($yo['turno_nombre']): ?>🕐 <?= e($yo['turno_nombre']) ?>&nbsp;·&nbsp;<?php endif; ?>
                    <?php if ($yo['jefe_nombre']): ?>👤 <?= e($yo['jefe_nombre']) ?><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-size:13px;font-weight:600;color:#374151">
                    <?= e(date('F Y', strtotime($desde))) ?>
                </div>
                <div class="meta"><?= count($diasHabiles) ?> días hábiles</div>
            </div>
        </div>

        <div class="kpi-row">
            <div class="kpi"><div class="kpi-val azul"><?= hm($sumTrabajo) ?></div><div class="kpi-lab">Horas trabajadas</div></div>
            <div class="kpi"><div class="kpi-val verde"><?= hm($sumExtra) ?></div><div class="kpi-lab">Horas extras</div></div>
            <div class="kpi"><div class="kpi-val amarillo"><?= hm($sumTardanza) ?></div><div class="kpi-lab">En tardanzas</div></div>
            <div class="kpi"><div class="kpi-val rojo"><?= $diasAusente ?></div><div class="kpi-lab">Días sin marcar</div></div>
            <div class="kpi"><div class="kpi-val" style="color:#7c3aed"><?= $diasTrabajados ?></div><div class="kpi-lab">Días trabajados</div></div>
            <?php if ($sumViajeMin > 0): ?>
            <div class="kpi"><div class="kpi-val naranja"><?= hm($sumViajeMin) ?></div><div class="kpi-lab">Horas en viaje</div></div>
            <?php endif; ?>
        </div>

        <!-- Barra de cumplimiento -->
        <div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:4px">
                <span>Cumplimiento de jornada</span>
                <span style="font-weight:700;color:<?= $pctColor ?>"><?= $pct ?>%</span>
            </div>
            <div style="background:#f3f4f6;border-radius:999px;height:8px;overflow:hidden">
                <div style="width:<?= $pct ?>%;background:<?= $pctColor ?>;height:8px;border-radius:999px"></div>
            </div>
        </div>
    </div>

    <!-- Viajes del mes -->
    <?php if ($viajes): ?>
    <div class="card" style="margin-bottom:14px">
        <h3 style="margin:0 0 12px">✈️ Viajes — <?= e(date('F Y', strtotime($desde))) ?></h3>
        <table>
            <thead><tr><th>Viaje</th><th>Destino</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Horas activas</th></tr></thead>
            <tbody>
            <?php foreach ($viajes as $v):
                $mn = max(0, (int)$v['min_total'] - (int)$v['min_descanso']); ?>
            <tr>
                <td><strong><?= e($v['titulo']) ?></strong></td>
                <td class="meta"><?= e($v['destino'] ?? '—') ?></td>
                <td><?= e(date('d/m/Y', strtotime($v['fecha_inicio']))) ?>
                    <?php if ($v['inicio_dt']): ?><div class="meta"><?= e(date('H:i', strtotime($v['inicio_dt']))) ?></div><?php endif; ?>
                </td>
                <td><?= $v['fecha_fin'] ? e(date('d/m/Y', strtotime($v['fecha_fin']))) : '—' ?>
                    <?php if ($v['fin_dt']): ?><div class="meta"><?= e(date('H:i', strtotime($v['fin_dt']))) ?></div><?php endif; ?>
                </td>
                <td><span class="badge <?= $v['estado']==='en_curso'?'b-azul':'b-verde' ?>">
                    <?= $v['estado']==='en_curso'?'En curso':'Finalizado' ?></span></td>
                <td><strong style="color:#114c97"><?= hm($mn) ?></strong>
                    <?php if ((int)$v['min_descanso']>0): ?>
                        <div class="meta"><?= hm((int)$v['min_descanso']) ?> descanso</div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Detalle diario -->
    <div class="card">
        <h3 style="margin:0 0 12px">Detalle día a día — <?= e(date('F Y', strtotime($desde))) ?></h3>
        <table>
            <thead>
                <tr><th>Fecha</th><th>Día</th><th>Entrada</th><th>Almuerzo</th>
                    <th>Salida</th><th>Trabajado</th><th>Extras</th><th>Tardanza</th></tr>
            </thead>
            <tbody>
            <?php foreach ($detalle as $d):
                $dLabel = $dias_es[date('D', strtotime($d['fecha']))] ?? ''; ?>
            <tr class="<?= $d['estado']==='ausente'?'fila-ausente':'' ?>" style="<?= $d['estado']==='permiso'?'background:#fffbeb':''  ?>">
                <td><?= e(date('d/m/Y', strtotime($d['fecha']))) ?></td>
                <td class="meta"><?= $dLabel ?></td>
                <?php if ($d['estado']==='permiso'): ?>
                    <td colspan="6">
                        <span class="badge" style="background:<?= e($d['color_permiso']??'#e5e7eb') ?>;color:#1f2937">
                            📋 <?= e($d['tipo_permiso']) ?>
                        </span>
                    </td>
                <?php elseif ($d['estado']==='ausente'): ?>
                    <td colspan="6"><span class="badge b-rojo">Sin registro</span></td>
                <?php else: ?>
                    <td><?= hFmt($d['entrada']) ?></td>
                    <td class="meta"><?= e($d['alm'] ?? '—') ?></td>
                    <td><?= hFmt($d['salida']) ?></td>
                    <td><strong><?= hm($d['min_neto']) ?></strong></td>
                    <td><?= $d['min_extra']>0 ? '<span class="badge b-verde">+'.hm($d['min_extra']).'</span>' : '<span class="meta">—</span>' ?></td>
                    <td><?= $d['min_tard']>0  ? '<span class="badge b-amarillo">'.hm($d['min_tard']).'</span>' : '<span class="meta">—</span>' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$detalle): ?>
                <tr><td colspan="8" class="meta" style="text-align:center;padding:20px">Sin registros este mes.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
