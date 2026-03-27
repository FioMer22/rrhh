<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_once __DIR__ . '/../../app/helpers/utils.php';
require_once __DIR__ . '/../../app/helpers/ausencias_helper.php';

require_login();
require_role('admin', 'rrhh');

$pdo = DB::pdo();

// ── Filtros ───────────────────────────────────────────────────────────────────
$hoy   = new DateTime('now');
$desde = $_GET['desde'] ?? $hoy->format('Y-m-01');
$hasta = $_GET['hasta'] ?? $hoy->format('Y-m-t');
$area  = (int)($_GET['area_id'] ?? 0);
$uid   = (int)($_GET['uid']     ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = $hoy->format('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = $hoy->format('Y-m-t');

if (!defined('JOR_MIN'))  define('JOR_MIN',  540);
if (!defined('TOL_MIN'))  define('TOL_MIN',  10);

// ── Usuarios ──────────────────────────────────────────────────────────────────
$whereU = "u.activo=1";
$paramsU = [];
if ($area > 0) { $whereU .= " AND u.area_id=?"; $paramsU[] = $area; }
if ($uid  > 0) { $whereU .= " AND u.id=?";      $paramsU[] = $uid;  }

$stU = $pdo->prepare("
    SELECT u.id, u.nombre, u.apellido, u.email,
           a.nombre AS area_nombre,
           t.nombre AS turno_nombre,
           t.hora_inicio, t.hora_fin
    FROM usuarios u
    LEFT JOIN areas a ON a.id=u.area_id
    LEFT JOIN usuario_turnos ut
        ON ut.usuario_id=u.id
       AND ut.vigente_desde <= ? AND (ut.vigente_hasta IS NULL OR ut.vigente_hasta >= ?)
    LEFT JOIN turnos t ON t.id=ut.turno_id
    WHERE $whereU
    ORDER BY a.nombre, u.apellido, u.nombre
");
$stU->execute(array_merge([$desde, $hasta], $paramsU));
$usuarios = $stU->fetchAll();

// ── Marcas ────────────────────────────────────────────────────────────────────
$stM = $pdo->prepare("
    SELECT usuario_id, DATE(fecha_hora) AS fecha,
           MIN(CASE WHEN tipo='inicio_jornada' THEN fecha_hora END) AS entrada,
           MIN(CASE WHEN tipo='pausa_inicio'   THEN fecha_hora END) AS alm_ini,
           MAX(CASE WHEN tipo='pausa_fin'      THEN fecha_hora END) AS alm_fin,
           MAX(CASE WHEN tipo='fin_jornada'    THEN fecha_hora END) AS salida
    FROM asistencia_marcas
    WHERE fecha_hora >= ? AND fecha_hora <= ?
    GROUP BY usuario_id, DATE(fecha_hora)
");
$stM->execute([$desde.' 00:00:00', $hasta.' 23:59:59']);
$marcasTodas = [];
foreach ($stM->fetchAll() as $m)
    $marcasTodas[(int)$m['usuario_id']][$m['fecha']] = $m;

// ── Viajes ────────────────────────────────────────────────────────────────────
$stV = $pdo->prepare("
    SELECT v.usuario_id, v.titulo, v.destino, v.fecha_inicio, v.fecha_fin,
           TIMESTAMPDIFF(MINUTE,
               IFNULL(v.inicio_dt, CONCAT(v.fecha_inicio,' 00:00:00')),
               IFNULL(v.fin_dt, NOW())) AS min_total,
           (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,d.inicio,IFNULL(d.fin,NOW()))),0)
            FROM viaje_descansos d WHERE d.viaje_id=v.id) AS min_descanso
    FROM viajes v
    WHERE v.fecha_inicio >= ? AND v.fecha_inicio <= ?
");
$stV->execute([$desde, $hasta]);
$viajesTodos = [];
foreach ($stV->fetchAll() as $v)
    $viajesTodos[(int)$v['usuario_id']][] = $v;

// ── Actividades (para Entrega de Víveres) ────────────────────────────────────
$stA = $pdo->prepare("
    SELECT usuario_id, tipo,
           COALESCE(inicio_real, inicio_plan) AS inicio,
           COALESCE(fin_real, fin_plan)       AS fin
    FROM actividades
    WHERE tipo = 'entrega'
      AND estado IN ('completada','en_curso','finalizada','en_progreso')
      AND COALESCE(inicio_real, inicio_plan) >= ?
      AND COALESCE(inicio_real, inicio_plan) <= ?
");
$stA->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
$actividadesTodas = [];
foreach ($stA->fetchAll() as $a)
    $actividadesTodas[(int)$a['usuario_id']][] = $a;

// ── Avisos de tardanza del período ───────────────────────────────────────────
$stAT = $pdo->prepare("
    SELECT usuario_id, fecha, hora_estimada, motivo, estado
    FROM avisos_tardanza WHERE fecha BETWEEN ? AND ?
");
$stAT->execute([$desde, $hasta]);
$avisosTardanzaPdf = [];
foreach ($stAT->fetchAll() as $a)
    $avisosTardanzaPdf[(int)$a['usuario_id']][$a['fecha']] = $a;

// ── Ausencias aprobadas del rango ────────────────────────────────────────────
$userIds = array_column($usuarios, 'id');
$ausenciasMap = cargar_ausencias_aprobadas($pdo, $desde, $hasta, $userIds);

// ── Días hábiles del rango ────────────────────────────────────────────────────
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
    return sprintf('%02d:%02d', intdiv(abs($m),60), abs($m)%60);
}
function hFmt(?string $dt): string { return $dt ? date('H:i', strtotime($dt)) : '—'; }

// ── Construir reporte ─────────────────────────────────────────────────────────
$reporte = [];
foreach ($usuarios as $u) {
    $uId   = (int)$u['id'];
    $marca = $marcasTodas[$uId] ?? [];
    $viajes= $viajesTodos[$uId] ?? [];

    $ausenciasUsr = $ausenciasMap[$uId] ?? [];
    $dias = []; $sumTrab = $sumExtra = $sumTard = $ausentes = $trabajados = 0;
    $sumViaje = 0;

    foreach ($diasHabiles as $fecha) {
        if (!isset($marca[$fecha])) {
            if ($fecha <= date('Y-m-d')) {
                $permiso = $ausenciasUsr[$fecha] ?? null;
                if ($permiso) {
                    $dias[] = ['fecha'=>$fecha,'estado'=>'permiso',
                               'tipo_permiso'=>$permiso['tipo'],
                               'color_permiso'=>$permiso['color'],
                               'entrada'=>null,'salida'=>null,'alm'=>null,
                               'min_neto'=>0,'min_extra'=>0,'min_tard'=>0];
                } else {
                    $ausentes++;
                    $dias[] = ['fecha'=>$fecha,'estado'=>'ausente',
                               'entrada'=>null,'salida'=>null,'alm'=>null,
                               'min_neto'=>0,'min_extra'=>0,'min_tard'=>0];
                }
            }
            continue;
        }
        $m = $marca[$fecha];
        $minAlm  = mins($m['alm_ini'], $m['alm_fin']);

        // Salida implícita desde inicio de actividad si no hay marca de salida
        $salidaImplicita = false;
        $salida = $m['salida'];
        if ($m['entrada'] && !$salida) {
            $actsUsr = $actividadesTodas[$uId] ?? [];
            $inicioActiv = null;
            foreach ($actsUsr as $a) {
                if (empty($a['inicio'])) continue;
                $aFecha = date('Y-m-d', strtotime($a['inicio']));
                if ($aFecha !== $fecha) continue;
                $ts = strtotime($a['inicio']);
                if ($ts && strtotime($m['entrada']) < $ts) {
                    if (!$inicioActiv || $ts < strtotime($inicioActiv))
                        $inicioActiv = $a['inicio'];
                }
            }
            if ($inicioActiv) {
                $salida = $inicioActiv;
                $salidaImplicita = true;
            }
        }

        $minNeto = max(0, mins($m['entrada'], $salida) - $minAlm);
        $minExtra= max(0, $minNeto - JOR_MIN);
        $minTard  = 0;
        if ($m['entrada']) {
            $lim = strtotime("$fecha 07:30 +" . TOL_MIN . " minutes");
            $te  = strtotime($m['entrada']);
            if ($te > $lim) $minTard = (int)floor(($te - $lim) / 60);
        }
        $trabajados++; $sumTrab += $minNeto;
        $sumExtra += $minExtra; $sumTard += $minTard;
        $dias[] = [
            'fecha'=>$fecha,'estado'=>'ok',
            'entrada'=>$m['entrada'],'salida'=>$salida,
            'salida_implicita'=>$salidaImplicita,
            'alm'=>($m['alm_ini']&&$m['alm_fin'])
                  ? hFmt($m['alm_ini']).'→'.hFmt($m['alm_fin']) : null,
            'min_neto'=>$minNeto,'min_extra'=>$minExtra,'min_tard'=>$minTard,
        ];
    }

    $actUsuario = $actividadesTodas[$uId] ?? [];
    $avisosUsr  = $avisosTardanzaPdf[$uId] ?? [];
    $sumEntrega = 0;
    foreach ($actUsuario as $a) {
        if (empty($a['inicio']) || empty($a['fin'])) continue;
        $ini = strtotime($a['inicio']);
        $fin = strtotime($a['fin']);
        if ($ini && $fin && $fin > $ini)
            $sumEntrega += (int)round(($fin - $ini) / 60);
    }

    // Agregar aviso_tardanza a cada día
    foreach ($dias as &$dia) {
        $dia['aviso_tardanza'] = $avisosUsr[$dia['fecha']] ?? null;
    }
    unset($dia);

    foreach ($viajes as $v)
        $sumViaje += max(0, (int)$v['min_total'] - (int)$v['min_descanso']);

    $reporte[] = [
        'id'=>$uId,'nombre'=>trim($u['nombre'].' '.$u['apellido']),
        'email'=>$u['email'],'area'=>$u['area_nombre']??'Sin área',
        'turno'=>$u['turno_nombre']??'—',
        'dias'=>$dias,'viajes'=>$viajes,
        'sum_trab'=>$sumTrab,'sum_extra'=>$sumExtra,
        'sum_tard'=>$sumTard,'ausentes'=>$ausentes,
        'trabajados'=>$trabajados,'sum_viaje'=>$sumViaje,
        'sum_entrega'=>$sumEntrega,
    ];
}
usort($reporte, fn($a,$b)=>strcmp($a['area'].$a['nombre'],$b['area'].$b['nombre']));

// Combos filtros
$areas    = $pdo->query("SELECT id,nombre FROM areas WHERE activo=1 ORDER BY nombre")->fetchAll();
$usuariosF= $pdo->query("SELECT id,CONCAT(nombre,' ',apellido) AS label FROM usuarios WHERE activo=1 ORDER BY nombre")->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Informe Asistencia — <?= e($desde) ?> al <?= e($hasta) ?></title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        /* ── Pantalla ── */
        .filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px}
        .persona{border:2px solid #e5e7eb;border-radius:14px;padding:18px;
                 margin-bottom:24px;page-break-inside:avoid}
        .persona-header{display:flex;justify-content:space-between;
                        align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:12px}
        .kpi-mini{display:flex;gap:10px;flex-wrap:wrap}
        .kpi-m{text-align:center;padding:8px 14px;border-radius:10px;
               background:#f9fafb;border:1px solid #e5e7eb;min-width:80px}
        .kpi-m .val{font-size:18px;font-weight:700}
        .kpi-m .lab{font-size:10px;color:#6b7280;margin-top:1px}
        .verde{color:#0a5c2e}.rojo{color:#842029}
        .amarillo{color:#856404}.azul{color:#114c97}

        /* ── Tabla días ── */
        table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
        th{background:#f3f4f6;padding:6px 8px;text-align:left;font-size:11px;
           font-weight:600;color:#4b5563}
        td{padding:6px 8px;border-bottom:1px solid #f3f4f6}
        .ausente{background:#fff5f5}
        .badge{padding:1px 7px;border-radius:999px;font-size:10px;font-weight:600}
        .b-g{background:#d1e7dd;color:#0a3622}
        .b-a{background:#fff3cd;color:#856404}
        .b-r{background:#f8d7da;color:#842029}

        /* ── Print ── */
        @media print {
            .no-print{display:none!important}
            .persona{border:1px solid #ccc;border-radius:0;
                     page-break-after:always;margin:0;padding:14px}
            .persona:last-child{page-break-after:avoid}
            body{background:#fff!important}
            th{background:#eee!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .ausente{background:#ffe4e4!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1050px">

    <!-- ── Filtros ────────────────────────────── -->
    <div class="no-print">
        <form method="get" class="filtros">
            <div>
                <label class="meta">Desde</label>
                <input type="date" name="desde" value="<?= e($desde) ?>">
            </div>
            <div>
                <label class="meta">Hasta</label>
                <input type="date" name="hasta" value="<?= e($hasta) ?>">
            </div>
            <div>
                <label class="meta">Área</label>
                <select name="area_id">
                    <option value="0">Todas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $area===(int)$a['id']?'selected':'' ?>>
                            <?= e($a['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta">Empleado</label>
                <select name="uid">
                    <option value="0">Todos</option>
                    <?php foreach ($usuariosF as $uf): ?>
                        <option value="<?= (int)$uf['id'] ?>" <?= $uid===(int)$uf['id']?'selected':'' ?>>
                            <?= e($uf['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:6px;align-items:flex-end">
                <button type="submit">Generar</button>
                <button type="button" onclick="window.print()"
                        style="background:#374151">🖨 Imprimir / PDF</button>
            </div>
        </form>
        <div class="meta" style="margin-bottom:16px">
            Período: <strong><?= e(date('d/m/Y',strtotime($desde))) ?></strong>
            al <strong><?= e(date('d/m/Y',strtotime($hasta))) ?></strong>
            · Jornada estándar 07:30–17:30 (9h netas)
            · <?= count($diasHabiles) ?> días hábiles
            · <?= count($reporte) ?> empleados
        </div>
    </div>

    <!-- Encabezado solo en impresión -->
    <div style="display:none" class="print-only">
        <h2 style="margin:0 0 4px">Informe de Asistencia — Jesús Responde</h2>
        <p style="margin:0 0 16px;font-size:12px;color:#6b7280">
            Período: <?= e(date('d/m/Y',strtotime($desde))) ?>
            al <?= e(date('d/m/Y',strtotime($hasta))) ?>
            · Generado: <?= date('d/m/Y H:i') ?>
        </p>
    </div>

    <!-- ── Una sección por persona ────────────── -->
    <?php foreach ($reporte as $u): ?>
    <div class="persona">

        <!-- Cabecera persona -->
        <div class="persona-header">
            <div>
                <div style="font-size:16px;font-weight:700"><?= e($u['nombre']) ?></div>
                <div class="meta"><?= e($u['email']) ?></div>
                <div class="meta">
                    📂 <?= e($u['area']) ?> &nbsp;·&nbsp;
                    🕐 <?= e($u['turno']) ?>
                </div>
            </div>
            <div class="kpi-mini">
                <div class="kpi-m">
                    <div class="val azul"><?= hm($u['sum_trab']) ?></div>
                    <div class="lab">Trabajado</div>
                </div>
                <div class="kpi-m">
                    <div class="val verde"><?= hm($u['sum_extra']) ?></div>
                    <div class="lab">Extras</div>
                </div>
                <div class="kpi-m">
                    <div class="val amarillo"><?= hm($u['sum_tard']) ?></div>
                    <div class="lab">Tardanzas</div>
                </div>
                <div class="kpi-m">
                    <div class="val rojo"><?= $u['ausentes'] ?></div>
                    <div class="lab">Sin marcar</div>
                </div>
                <?php if ($u['sum_viaje'] > 0): ?>
                <div class="kpi-m">
                    <div class="val" style="color:#92400e"><?= hm($u['sum_viaje']) ?></div>
                    <div class="lab">En viaje</div>
                </div>
                <?php endif; ?>
                <?php if (($u['sum_entrega'] ?? 0) > 0): ?>
                <div class="kpi-m" style="background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;padding:6px 10px">
                    <div class="val" style="color:#92400e">🥫 <?= hm($u['sum_entrega']) ?></div>
                    <div class="lab" style="color:#92400e;font-weight:700">Víveres</div>
                </div>
                <?php endif; ?>
                <div class="kpi-m">
                    <div class="val" style="color:#7c3aed"><?= $u['trabajados'] ?></div>
                    <div class="lab">Días trab.</div>
                </div>
            </div>
        </div>

        <!-- Viajes -->
        <?php if ($u['viajes']): ?>
        <div style="margin-bottom:10px;padding:8px 10px;background:#fffbeb;
                    border-radius:8px;border:1px solid #fde68a">
            <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:4px">
                ✈️ VIAJES EN EL PERÍODO
            </div>
            <?php foreach ($u['viajes'] as $v):
                $mn = max(0, (int)$v['min_total'] - (int)$v['min_descanso']); ?>
            <div style="font-size:12px;display:flex;gap:12px;flex-wrap:wrap;
                        padding:3px 0;border-bottom:1px solid #fde68a">
                <span><strong><?= e($v['titulo']) ?></strong></span>
                <?php if ($v['destino']): ?>
                    <span class="meta">📍 <?= e($v['destino']) ?></span>
                <?php endif; ?>
                <span class="meta">
                    <?= e(date('d/m/Y', strtotime($v['fecha_inicio']))) ?>
                    <?= $v['fecha_fin'] && $v['fecha_fin']!==$v['fecha_inicio']
                        ? '→ '.e(date('d/m/Y', strtotime($v['fecha_fin']))) : '' ?>
                </span>
                <span style="color:#92400e;font-weight:700"><?= hm($mn) ?> activas</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tabla de días -->
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Día</th>
                    <th>Entrada</th>
                    <th>Almuerzo</th>
                    <th>Salida</th>
                    <th>Trabajado</th>
                    <th>Extras</th>
                    <th>Tardanza</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $dias_es = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie'];
            foreach ($u['dias'] as $d):
                $dL = $dias_es[date('D', strtotime($d['fecha']))] ?? '';
            ?>
            <tr class="<?= $d['estado']==='ausente'?'ausente':'' ?>"
                style="<?= $d['estado']==='permiso'?'background:#fffbeb':'' ?>">
                <td><?= e(date('d/m/Y', strtotime($d['fecha']))) ?></td>
                <td><?= $dL ?></td>
                <?php if ($d['estado']==='permiso'): ?>
                    <td colspan="6" style="color:#92400e;font-weight:600">
                        📋 <?= e($d['tipo_permiso'] ?? '—') ?>
                    </td>
                <?php elseif ($d['estado']==='ausente'): ?>
                    <td colspan="6" style="color:#842029;font-weight:600">Sin registro</td>
                <?php else: ?>
                    <td><?= hFmt($d['entrada']) ?></td>
                    <td style="color:#6b7280"><?= e($d['alm']??'—') ?></td>
                    <td>
                        <?= hFmt($d['salida']) ?>
                        <?php if (!empty($d['salida_implicita'])): ?>
                            <span style="color:#f59e0b;font-size:10px" title="Salida implícita">*</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= hm($d['min_neto']) ?></strong></td>
                    <td><?= $d['min_extra']>0
                        ? '<span class="badge b-g">+'.hm($d['min_extra']).'</span>'
                        : '—' ?></td>
                    <td>
                        <?= $d['min_tard']>0
                            ? '<span class="badge b-a">'.hm($d['min_tard']).'</span>'
                            : '—' ?>
                        <?php if (!empty($d['aviso_tardanza'])): ?>
                            <div style="font-size:9px;color:#92400e;margin-top:2px;font-weight:600">
                                ⚠️ Avisó
                                <?php if ($d['aviso_tardanza']['hora_estimada']): ?>
                                    · <?= e(date('H:i', strtotime($d['aviso_tardanza']['hora_estimada']))) ?>
                                <?php endif; ?>
                                <?php if (!empty($d['aviso_tardanza']['tipo_descuento'])): ?>
                                    · <?= $d['aviso_tardanza']['tipo_descuento'] === 'horas_viveres'
                                        ? '🥫 desc. víveres'
                                        : '⏱ desc. horas extra' ?>
                                <?php endif; ?>
                                <?php if (!empty($d['aviso_tardanza']['motivo'])): ?>
                                    <div style="font-weight:400;font-style:italic;color:#78350f">
                                        "<?= e($d['aviso_tardanza']['motivo']) ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    </div>
    <?php endforeach; ?>

    <?php if (!$reporte): ?>
        <div class="card"><p class="meta">No hay datos para el período seleccionado.</p></div>
    <?php endif; ?>

</div>
</body>
</html>