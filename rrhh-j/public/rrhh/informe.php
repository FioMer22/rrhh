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

// ── Constantes de jornada estándar ────────────────────────────────────────────
if (!defined('JORNADA_INICIO'))   define('JORNADA_INICIO',   '07:30');
if (!defined('JORNADA_FIN'))      define('JORNADA_FIN',      '17:30');
if (!defined('JORNADA_NETA_MIN')) define('JORNADA_NETA_MIN', 540);
if (!defined('TOLERANCIA_MIN'))   define('TOLERANCIA_MIN',   10);

// ── Filtros ───────────────────────────────────────────────────────────────────
$hoy  = new DateTime('now');
$mes  = $_GET['mes']     ?? $hoy->format('Y-m');
$area = (int)($_GET['area_id'] ?? 0);
$uid  = (int)($_GET['uid']     ?? 0);
$q    = trim($_GET['q']        ?? '');

// Rango del mes seleccionado
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));

// ── Usuarios con turno asignado ───────────────────────────────────────────────
$whereU = "u.activo = 1";
$paramsU = [];
if ($area > 0) { $whereU .= " AND u.area_id = ?"; $paramsU[] = $area; }
if ($uid  > 0) { $whereU .= " AND u.id = ?";      $paramsU[] = $uid;  }
if ($q !== '') {
    $whereU .= " AND (u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ?)";
    $like = "%$q%";
    $paramsU = array_merge($paramsU, [$like, $like, $like]);
}

$stU = $pdo->prepare("
    SELECT u.id, u.nombre, u.apellido, u.email, u.area_id,
           a.nombre AS area_nombre
    FROM usuarios u
    LEFT JOIN areas a ON a.id = u.area_id
    WHERE $whereU
    ORDER BY a.nombre, u.apellido, u.nombre
");
$stU->execute($paramsU);
$usuarios = $stU->fetchAll();

// ── Marcas del período ────────────────────────────────────────────────────────
$stM = $pdo->prepare("
    SELECT usuario_id,
           DATE(fecha_hora)                                                    AS fecha,
           MIN(CASE WHEN tipo='inicio_jornada' THEN fecha_hora END)           AS entrada,
           MIN(CASE WHEN tipo='pausa_inicio'   THEN fecha_hora END)           AS almuerzo_ini,
           MAX(CASE WHEN tipo='pausa_fin'      THEN fecha_hora END)           AS almuerzo_fin,
           MAX(CASE WHEN tipo='fin_jornada'    THEN fecha_hora END)           AS salida
    FROM asistencia_marcas
    WHERE fecha_hora >= ? AND fecha_hora <= ?
    GROUP BY usuario_id, DATE(fecha_hora)
");
$stM->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
$marcasRaw = $stM->fetchAll();

// Indexar por usuario_id → fecha
$marcas = [];
foreach ($marcasRaw as $m) {
    $marcas[(int)$m['usuario_id']][$m['fecha']] = $m;
}

// ── Días hábiles del mes (lunes a viernes) ────────────────────────────────────
$diasHabiles = [];
$cur = new DateTime($desde);
$fin = new DateTime($hasta);
while ($cur <= $fin) {
    $dow = (int)$cur->format('N'); // 1=lun … 7=dom
    if ($dow <= 5) $diasHabiles[] = $cur->format('Y-m-d');
    $cur->modify('+1 day');
}

// ── Avisos de tardanza del período ───────────────────────────────────────────
$stAT = $pdo->prepare("
    SELECT usuario_id, fecha, hora_estimada, motivo, estado
    FROM avisos_tardanza
    WHERE fecha BETWEEN ? AND ?
");
$stAT->execute([$desde, $hasta]);
$avisosTardanza = [];
foreach ($stAT->fetchAll() as $a) {
    $avisosTardanza[(int)$a['usuario_id']][$a['fecha']] = $a;
}

// ── Viajes del período ────────────────────────────────────────────────────────
$stV = $pdo->prepare("
    SELECT v.id, v.usuario_id,
           DATE(v.inicio_dt) AS fecha_ini,
           DATE(v.fin_dt)    AS fecha_fin,
           v.inicio_dt, v.fin_dt, v.titulo, v.destino,
           0 AS min_descanso
    FROM viajes v
    WHERE v.estado IN ('aprobado','en_curso','completado','finalizado')
      AND v.inicio_dt <= ? AND v.fin_dt >= ?
");
$stV->execute([$hasta . ' 23:59:59', $desde . ' 00:00:00']);
$viajesRaw = $stV->fetchAll();

// Indexar viajes por usuario → lista
$viajes = [];
foreach ($viajesRaw as $v) {
    $viajes[(int)$v['usuario_id']][] = $v;
}

// ── Actividades del período (TODAS, no solo fuera de horario) ─────────────────
$stA = $pdo->prepare("
    SELECT usuario_id, titulo, tipo,
           DATE(COALESCE(inicio_real, inicio_plan)) AS fecha,
           COALESCE(inicio_real, inicio_plan)       AS inicio,
           COALESCE(fin_real, fin_plan)             AS fin
    FROM actividades
    WHERE estado IN ('completada','en_curso','finalizada','en_progreso','planificada')
      AND COALESCE(inicio_real, inicio_plan) >= ?
      AND COALESCE(inicio_real, inicio_plan) <= ?
");
$stA->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
$actRaw = $stA->fetchAll();

// Indexar actividades por usuario → lista
$actividades = [];
foreach ($actRaw as $a) {
    $actividades[(int)$a['usuario_id']][] = $a;
}
// ── Calcular minutos activos de viaje en un día (descontando descansos) ───────
function minsViajeDia(array $viajes, string $fecha): int {
    $mins = 0;
    $diaIni = strtotime($fecha . ' 00:00:00');
    $diaFin = strtotime($fecha . ' 23:59:59');
    foreach ($viajes as $v) {
        $vIni = strtotime($v['inicio_dt']);
        $vFin = strtotime($v['fin_dt']);
        $solapIni = max($diaIni, $vIni);
        $solapFin = min($diaFin, $vFin);
        if ($solapFin > $solapIni) {
            $bruto = (int)round(($solapFin - $solapIni) / 60);
            $diasViaje = max(1, (int)ceil(($vFin - $vIni) / 86400));
            $descPorDia = (int)round((int)$v['min_descanso'] / $diasViaje);
            $mins += max(0, $bruto - $descPorDia);
        }
    }
    return $mins;
}

// ── Verificar si un día tiene viaje activo ────────────────────────────────────
function diaEnViaje(array $viajes, string $fecha): bool {
    $diaIni = strtotime($fecha . ' 00:00:00');
    $diaFin = strtotime($fecha . ' 23:59:59');
    foreach ($viajes as $v) {
        if (min($diaFin, strtotime($v['fin_dt'])) > max($diaIni, strtotime($v['inicio_dt']))) return true;
    }
    return false;
}

// ── Actividades de un día con clasificación dentro/fuera de horario ───────────
function actividadesDia(array $acts, string $fecha): array {
    return array_values(array_filter($acts, fn($a) => $a['fecha'] === $fecha));
}

function minsActividadDia(array $acts, string $fecha): array {
    $jorIni = strtotime($fecha . ' 07:30:00');
    $jorFin = strtotime($fecha . ' 17:30:00');
    $dentro = 0; $extra = 0;
    foreach ($acts as $a) {
        if ($a['fecha'] !== $fecha) continue;
        if (empty($a['inicio']) || empty($a['fin'])) continue;
        $aIni = strtotime((string)$a['inicio']);
        $aFin = strtotime((string)$a['fin']);
        if (!$aIni || !$aFin || $aFin <= $aIni) continue;
        $denIni = max($aIni, $jorIni);
        $denFin = min($aFin, $jorFin);
        $dentroMin = $denFin > $denIni ? (int)round(($denFin - $denIni) / 60) : 0;
        $totalMin  = (int)round(($aFin - $aIni) / 60);
        $dentro += $dentroMin;
        $extra  += max(0, $totalMin - $dentroMin);
    }
    return ['dentro' => $dentro, 'extra' => $extra, 'total' => $dentro + $extra];
}

// Compatibilidad con llamadas anteriores
function minsActividadExtraDia(array $acts, string $fecha): int {
    return minsActividadDia($acts, $fecha)['extra'];
}

// ── Calcular minutos de Entrega de Víveres en un día ─────────────────────────
function minsEntregaViveresDia(array $acts, string $fecha): int {
    $mins = 0;
    foreach ($acts as $a) {
        if ($a['fecha'] !== $fecha || $a['tipo'] !== 'entrega') continue;
        if (empty($a['inicio']) || empty($a['fin'])) continue;
        $ini = strtotime((string)$a['inicio']);
        $fin = strtotime((string)$a['fin']);
        if ($ini && $fin && $fin > $ini) {
            $mins += (int)round(($fin - $ini) / 60);
        }
    }
    return $mins;
}

// ── Calcular informe por usuario ──────────────────────────────────────────────
function mins(string $a, string $b): int {
    return max(0, (int)round((strtotime($b) - strtotime($a)) / 60));
}
function hm(int $m): string {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
function horaShort(?string $dt): string {
    return $dt ? date('H:i', strtotime($dt)) : '—';
}

$resumen  = [];
$totalOrg = ['dias_trabajados'=>0,'min_trabajo'=>0,'min_extra'=>0,'min_tardanza'=>0,'dias_ausente'=>0];

foreach ($usuarios as $u) {
    $uId      = (int)$u['id'];
    $mDias    = $marcas[$uId] ?? [];
    $uViajes  = $viajes[$uId] ?? [];
    $uActiv   = $actividades[$uId] ?? [];
    $uAvisos  = $avisosTardanza[$uId] ?? [];

    $dias_trabajados = 0;
    $dias_ausente    = 0;
    $min_trabajo     = 0;
    $min_extra       = 0;
    $min_tardanza    = 0;
    $min_viaje       = 0;
    $min_activ_extra = 0;
    $min_entrega     = 0;
    $detalle         = [];

    foreach ($diasHabiles as $fecha) {
        if (!isset($mDias[$fecha])) {
            // Día hábil sin marca — verificar si hay viaje o actividad
            if ($fecha <= $hoy->format('Y-m-d')) {
                $enViaje     = diaEnViaje($uViajes, $fecha);
                $minViajeDia = minsViajeDia($uViajes, $fecha);
                $activsDia   = actividadesDia($uActiv, $fecha);
                $actMinsDia  = minsActividadDia($uActiv, $fecha);

                if ($enViaje) {
                    // Día en viaje → NO cuenta como ausente, cuenta como trabajado
                    $dias_trabajados++;
                    $min_viaje += $minViajeDia;
                    $detalle[] = [
                        'fecha'      => $fecha,
                        'estado'     => 'viaje',
                        'entrada'    => null,
                        'salida'     => null,
                        'min_neto'   => 0,
                        'min_extra'  => 0,
                        'min_tard'   => 0,
                        'min_viaje'  => $minViajeDia,
                        'min_activ'  => 0,
                        'actividades'=> [],
                        'almuerzo'   => null,
                    ];
                    $totalOrg['dias_trabajados']++;
                } elseif (!empty($activsDia)) {
                    // Día con actividad externa sin marcar → no ausente
                    $dias_trabajados++;
                    $min_activ_extra += $actMinsDia['extra'];
                    $detalle[] = [
                        'fecha'      => $fecha,
                        'estado'     => 'actividad',
                        'entrada'    => null,
                        'salida'     => null,
                        'min_neto'   => $actMinsDia['dentro'],
                        'min_extra'  => $actMinsDia['extra'],
                        'min_tard'   => 0,
                        'min_viaje'  => 0,
                        'min_activ'  => $actMinsDia['extra'], // solo horas FUERA de horario
                        'actividades'=> $activsDia,
                        'almuerzo'   => null,
                    ];
                    $totalOrg['dias_trabajados']++;
                } else {
                    // Realmente ausente
                    $dias_ausente++;
                    $detalle[] = [
                        'fecha'      => $fecha,
                        'estado'     => 'ausente',
                        'entrada'    => null,
                        'salida'     => null,
                        'min_neto'   => 0,
                        'min_extra'  => 0,
                        'min_tard'   => 0,
                        'min_viaje'  => 0,
                        'min_activ'  => 0,
                        'actividades'=> [],
                        'almuerzo'   => null,
                    ];
                }
            }
            continue;
        }

        $m = $mDias[$fecha];
        $entrada = $m['entrada'] ? (string)$m['entrada'] : null;
        $salida  = $m['salida']  ? (string)$m['salida']  : null;

        // ── Salida implícita ───────────────────────────────────────────────────
        // Si hay entrada pero no salida, y hay una actividad iniciada ese día,
        // usar la hora de inicio de la actividad como salida implícita.
        $salidaImplicita = false;
        if ($entrada && !$salida) {
            $activsDiaCheck = actividadesDia($uActiv, $fecha);
            if (!empty($activsDiaCheck)) {
                // Buscar la actividad más temprana del día
                $inicioActiv = null;
                foreach ($activsDiaCheck as $a) {
                    if (empty($a['inicio'])) continue;
                    $ts = strtotime($a['inicio']);
                    if ($ts && strtotime($entrada) < $ts) {
                        if (!$inicioActiv || $ts < strtotime($inicioActiv)) {
                            $inicioActiv = $a['inicio'];
                        }
                    }
                }
                if ($inicioActiv) {
                    $salida = $inicioActiv;
                    $salidaImplicita = true;
                }
            }
        }

        // Almuerzo
        $minAlm = 0;
        if ($m['almuerzo_ini'] && $m['almuerzo_fin']) {
            $minAlm = mins($m['almuerzo_ini'], $m['almuerzo_fin']);
        }

        // Minutos brutos trabajados
        $minBruto = ($entrada && $salida) ? mins($entrada, $salida) : 0;
        $minNeto  = max(0, $minBruto - $minAlm);

        // Extras = neto - jornada estándar (solo si superó)
        $minExtraDia = max(0, $minNeto - JORNADA_NETA_MIN);

        // Tardanza
        $minTardDia = 0;
        if ($entrada) {
            $limiteEntrada = strtotime("$fecha " . JORNADA_INICIO . " +" . TOLERANCIA_MIN . " minutes");
            $tEntrada      = strtotime($entrada);
            if ($tEntrada > $limiteEntrada) {
                $minTardDia = (int)floor(($tEntrada - $limiteEntrada) / 60);
            }
        }

        $dias_trabajados++;
        $min_trabajo     += $minNeto;
        $min_extra       += $minExtraDia;
        $min_tardanza    += $minTardDia;

        // Viaje y actividades del día
        $minViajeDia   = minsViajeDia($uViajes, $fecha);
        $actMinsDia    = minsActividadDia($uActiv, $fecha);
        $activsDia     = actividadesDia($uActiv, $fecha);
        $minEntregaDia = minsEntregaViveresDia($uActiv, $fecha);
        $min_viaje       += $minViajeDia;
        $min_activ_extra += $actMinsDia['extra'];
        $min_entrega     += $minEntregaDia;

        // Actividades del día para mostrar
        $detalle[] = [
            'fecha'      => $fecha,
            'estado'     => 'trabajado',
            'entrada'    => $entrada,
            'salida'     => $salida,
            'almuerzo'   => ($m['almuerzo_ini'] && $m['almuerzo_fin'])
                ? horaShort($m['almuerzo_ini']).'→'.horaShort($m['almuerzo_fin'])
                : null,
            'min_neto'   => $minNeto,
            'min_extra'  => $minExtraDia,
            'min_tard'   => $minTardDia,
            'min_viaje'  => $minViajeDia,
            'min_activ'  => $actMinsDia['extra'], // solo horas FUERA de horario
            'actividades'=> $activsDia,
            'salida_implicita' => $salidaImplicita,
            'aviso_tardanza'   => $uAvisos[$fecha] ?? null,
        ];

        $totalOrg['dias_trabajados'] += 1;
        $totalOrg['min_trabajo']     += $minNeto;
        $totalOrg['min_extra']       += $minExtraDia;
        $totalOrg['min_tardanza']    += $minTardDia;
        $totalOrg['dias_ausente']    += 0;
    }

    $totalOrg['dias_ausente'] += $dias_ausente;

    // Horas esperadas = días trabajados × jornada estándar
    $min_esperado = $dias_trabajados * JORNADA_NETA_MIN;
    $min_deficit  = max(0, $min_esperado - $min_trabajo);

    // Viaje total del mes (sumando todos los días del período)
    $min_viaje_total = 0;
    foreach ($diasHabiles as $fecha) {
        $min_viaje_total += minsViajeDia($uViajes, $fecha);
    }

    $resumen[] = [
        'id'             => $uId,
        'nombre'         => trim($u['nombre'].' '.$u['apellido']),
        'email'          => $u['email'],
        'area'           => $u['area_nombre'] ?? '—',
        'dias_trabajados'=> $dias_trabajados,
        'dias_ausente'   => $dias_ausente,
        'min_trabajo'    => $min_trabajo,
        'min_extra'      => $min_extra,
        'min_tardanza'   => $min_tardanza,
        'min_deficit'    => $min_deficit,
        'min_viaje'      => $min_viaje_total,
        'min_activ_extra'=> $min_activ_extra,
        'min_entrega'    => $min_entrega,
        'cant_avisos'    => count($uAvisos),
        'viajes'         => $uViajes,
        'detalle'        => $detalle,
    ];
}

// Ordenar por área, luego apellido
usort($resumen, fn($a,$b) => strcmp($a['area'].$a['nombre'], $b['area'].$b['nombre']));

// ── Combos para filtros ───────────────────────────────────────────────────────
$areas    = $pdo->query("SELECT id, nombre FROM areas WHERE activo=1 ORDER BY nombre")->fetchAll();
$usuariosF= $pdo->query("SELECT id, CONCAT(nombre,' ',apellido) AS label FROM usuarios WHERE activo=1 ORDER BY nombre")->fetchAll();

// Meses (6 pasados + mes actual)
$meses = [];
for ($i = -6; $i <= 0; $i++) {
    $meses[] = date('Y-m', strtotime("$i months"));
}

$diasHabilesTotal = count($diasHabiles);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Informe de Asistencia</title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px}
        .kpi{background:#fff;border-radius:12px;padding:14px;text-align:center;
             border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,.04)}
        .kpi-val{font-size:26px;font-weight:700;line-height:1}
        .kpi-lab{font-size:11px;color:#6b7280;margin-top:4px}
        .verde {color:#0a5c2e} .rojo{color:#842029} .amarillo{color:#856404} .azul{color:#114c97}
        .fila-ausente{background:#fff5f5}
        .fila-extra td:nth-child(7){color:#0a5c2e;font-weight:700}
        .fila-tarde td:nth-child(8){color:#856404;font-weight:700}
        .fila-viaje   { background:#fffbf0 }
        .fila-actividad { background:#faf5ff }
        .badge-extra{background:#d1e7dd;color:#0a3622;padding:1px 7px;border-radius:999px;font-size:11px}
        .badge-tarde{background:#fff3cd;color:#856404;padding:1px 7px;border-radius:999px;font-size:11px}
        .badge-aus  {background:#f8d7da;color:#842029;padding:1px 7px;border-radius:999px;font-size:11px}
        .user-header{display:flex;justify-content:space-between;align-items:flex-start;
                     flex-wrap:wrap;gap:10px;margin-bottom:10px}
        .resumen-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        details summary{cursor:pointer;user-select:none}
        details summary::-webkit-details-marker{color:#6b7280}
        @media print{
            .no-print{display:none!important}
            .card{box-shadow:none;border:1px solid #ddd}
            details{open:true}
            details[open] summary{display:none}
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1100px">

    <!-- ── Filtros ──────────────────────────────────────────── -->
    <div class="card no-print" style="margin-bottom:14px">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label class="meta">Mes</label>
                <select name="mes">
                    <?php foreach ($meses as $m): ?>
                        <option value="<?= e($m) ?>" <?= $mes===$m?'selected':'' ?>>
                            <?= e(date('F Y', strtotime($m.'-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta">Área</label>
                <select name="area_id">
                    <option value="0">Todas las áreas</option>
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
                <button type="submit">Filtrar</button>
                <a class="btn" href="informe.php" style="background:#6b7280">Limpiar</a>
                <button type="button" onclick="window.print()"
                    style="background:#374151">🖨 Imprimir</button>
            </div>
        </form>
        <div class="meta" style="margin-top:8px">
            Jornada estándar: <strong>07:30 – 17:30</strong> · 
            Almuerzo: <strong>1 hora</strong> · 
            Neto diario: <strong>9h</strong> · 
            Tolerancia tardanza: <strong><?= TOLERANCIA_MIN ?> min</strong> · 
            Días hábiles del mes: <strong><?= $diasHabilesTotal ?></strong>
        </div>
    </div>

    <!-- ── KPIs globales ────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-val azul"><?= count($resumen) ?></div>
            <div class="kpi-lab">Empleados</div>
        </div>
        <div class="kpi">
            <div class="kpi-val verde"><?= hm($totalOrg['min_trabajo']) ?></div>
            <div class="kpi-lab">Horas trabajadas</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#0a5c2e"><?= hm($totalOrg['min_extra']) ?></div>
            <div class="kpi-lab">Horas extras</div>
        </div>
        <div class="kpi">
            <div class="kpi-val amarillo"><?= hm($totalOrg['min_tardanza']) ?></div>
            <div class="kpi-lab">Tardanzas acumuladas</div>
        </div>
        <div class="kpi">
            <div class="kpi-val rojo"><?= $totalOrg['dias_ausente'] ?></div>
            <div class="kpi-lab">Días sin marcar</div>
        </div>
    </div>

    <!-- ── Detalle por empleado ──────────────────────────────── -->
    <?php if (!$resumen): ?>
        <div class="card"><p class="meta">No hay datos para el período seleccionado.</p></div>
    <?php endif; ?>

    <?php
    $areaActual = null;
    foreach ($resumen as $u):
        // Cabecera de área
        if ($u['area'] !== $areaActual):
            $areaActual = $u['area'];
    ?>
    <div style="font-size:12px;font-weight:700;color:#6b7280;
                text-transform:uppercase;letter-spacing:1px;
                margin:18px 0 6px;padding-left:4px">
        📂 <?= e($areaActual) ?>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:10px">
        <div class="user-header">
            <div>
                <strong style="font-size:15px"><?= e($u['nombre']) ?></strong>
                <div class="meta"><?= e($u['email']) ?></div>
            </div>
            <div class="resumen-chips">
                <div style="text-align:center;padding:6px 12px;background:#f0f9ff;
                            border-radius:10px;border:1px solid #bae6fd">
                    <div style="font-size:18px;font-weight:700;color:#114c97">
                        <?= hm($u['min_trabajo']) ?>
                    </div>
                    <div class="meta">horas trabajadas</div>
                </div>
                <?php if ($u['min_extra'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#f0fdf4;
                            border-radius:10px;border:1px solid #86efac">
                    <div style="font-size:18px;font-weight:700;color:#0a5c2e">
                        +<?= hm($u['min_extra']) ?>
                    </div>
                    <div class="meta">horas extras</div>
                </div>
                <?php endif; ?>
                <?php if ($u['min_tardanza'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#fffbeb;
                            border-radius:10px;border:1px solid #fde68a">
                    <div style="font-size:18px;font-weight:700;color:#856404">
                        <?= hm($u['min_tardanza']) ?>
                    </div>
                    <div class="meta">en tardanzas</div>
                </div>
                <?php endif; ?>
                <?php if ($u['min_entrega'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#fef3c7;
                            border-radius:10px;border:2px solid #f59e0b">
                    <div style="font-size:18px;font-weight:700;color:#92400e">
                        🥫 <?= hm($u['min_entrega']) ?>
                    </div>
                    <div class="meta" style="font-size:10px;font-weight:700;color:#92400e">
                        ENTREGA DE VÍVERES
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($u['min_viaje'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#fff7ed;
                            border-radius:10px;border:1px solid #fed7aa">
                    <div style="font-size:18px;font-weight:700;color:#92400e">
                        <?= hm($u['min_viaje']) ?>
                    </div>
                    <div class="meta">en viaje</div>
                </div>
                <?php endif; ?>
                <?php if ($u['min_activ_extra'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#f5f3ff;
                            border-radius:10px;border:1px solid #c4b5fd">
                    <div style="font-size:18px;font-weight:700;color:#5b21b6">
                        <?= hm($u['min_activ_extra']) ?>
                    </div>
                    <div class="meta">actividades extra</div>
                </div>
                <?php endif; ?>
                <?php if ($u['dias_ausente'] > 0): ?>
                <div style="text-align:center;padding:6px 12px;background:#fff5f5;
                            border-radius:10px;border:1px solid #fca5a5">
                    <div style="font-size:18px;font-weight:700;color:#842029">
                        <?= $u['dias_ausente'] ?>
                    </div>
                    <div class="meta">días sin marcar</div>
                </div>
                <?php endif; ?>
                <div class="meta" style="font-size:11px;text-align:right">
                    <?= $u['dias_trabajados'] ?> días trabajados<br>
                    de <?= $diasHabilesTotal ?> hábiles
                </div>
            </div>
        </div>

        <!-- Detalle diario colapsable -->
        <details>
            <summary style="font-size:13px;color:#6b7280;padding:4px 0">
                Ver detalle día a día
            </summary>
            <table style="margin-top:10px;font-size:13px">
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
                        <th>Viaje</th>
                        <th title="Horas de actividades realizadas fuera del horario laboral (después de 17:30)">Act. extra</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($u['detalle'] as $d):
                    $diaSemana = date('D', strtotime($d['fecha']));
                    $dias_es   = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié',
                                  'Thu'=>'Jue','Fri'=>'Vie'];
                    $dLabel    = $dias_es[$diaSemana] ?? $diaSemana;
                    if ($d['estado'] === 'ausente') {
                        $rowClass = 'fila-ausente';
                    } elseif ($d['estado'] === 'viaje') {
                        $rowClass = 'fila-viaje';
                    } elseif ($d['estado'] === 'actividad') {
                        $rowClass = 'fila-actividad';
                    } else {
                        $rowClass = '';
                    }
                    if ($d['min_extra'] > 0) $rowClass .= ' fila-extra';
                    if ($d['min_tard']  > 0) $rowClass .= ' fila-tarde';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= e(date('d/m/Y', strtotime($d['fecha']))) ?></td>
                    <td class="meta"><?= $dLabel ?></td>
                    <?php if ($d['estado'] === 'ausente'): ?>
                        <td colspan="8">
                            <span class="badge-aus">Sin registro</span>
                        </td>
                    <?php elseif ($d['estado'] === 'viaje'): ?>
                        <td colspan="4" style="color:#92400e;font-style:italic">
                            🚗 En viaje
                        </td>
                        <td><span class="meta">—</span></td>
                        <td><span class="meta">—</span></td>
                        <td>
                            <span style="background:#fff7ed;color:#92400e;padding:1px 7px;border-radius:999px;font-size:11px">
                                🚗 <?= hm($d['min_viaje']) ?>
                            </span>
                        </td>
                        <td><span class="meta">—</span></td>
                    <?php elseif ($d['estado'] === 'actividad'): ?>
                        <td colspan="4" style="color:#5b21b6;font-style:italic">
                            📋 <?= e(implode(', ', array_column($d['actividades'], 'titulo'))) ?>
                        </td>
                        <td><span class="meta">—</span></td>
                        <td><span class="meta">—</span></td>
                        <td><span class="meta">—</span></td>
                        <td>
                            <span style="background:#f5f3ff;color:#5b21b6;padding:1px 7px;border-radius:999px;font-size:11px">
                                📋 <?= hm($d['min_activ']) ?>
                            </span>
                        </td>
                    <?php else: ?>
                        <td><?= e(horaShort($d['entrada'])) ?></td>
                        <td class="meta"><?= e($d['almuerzo'] ?? '—') ?></td>
                        <td>
                            <?= e(horaShort($d['salida'])) ?>
                            <?php if (!empty($d['salida_implicita'])): ?>
                                <span title="Salida implícita — hora de inicio de actividad" style="color:#f59e0b;font-size:11px;cursor:help">*</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= hm($d['min_neto']) ?></strong></td>
                        <td>
                            <?php if ($d['min_extra'] > 0): ?>
                                <span class="badge-extra">+<?= hm($d['min_extra']) ?></span>
                            <?php else: ?>
                                <span class="meta">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['min_tard'] > 0): ?>
                                <span class="badge-tarde"><?= hm($d['min_tard']) ?></span>
                            <?php else: ?>
                                <span class="meta">—</span>
                            <?php endif; ?>
                            <?php if (!empty($d['aviso_tardanza'])): ?>
                                <br><span title="<?= e($d['aviso_tardanza']['motivo'] ?? '') ?>"
                                      style="background:#fef3c7;color:#92400e;padding:1px 6px;
                                             border-radius:999px;font-size:10px;cursor:help;margin-top:2px;display:inline-block">
                                    ⚠️ Avisó
                                    <?php if ($d['aviso_tardanza']['hora_estimada']): ?>
                                        · <?= e(date('H:i', strtotime($d['aviso_tardanza']['hora_estimada']))) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if (!empty($d['aviso_tardanza']['tipo_descuento'])): ?>
                                    <br><span style="font-size:10px;color:#6b7280;margin-top:1px;display:inline-block">
                                        <?= $d['aviso_tardanza']['tipo_descuento'] === 'horas_viveres'
                                            ? '🥫 desc. víveres'
                                            : '⏱ desc. horas extra' ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['min_viaje'] > 0): ?>
                                <span style="background:#fff7ed;color:#92400e;padding:1px 7px;border-radius:999px;font-size:11px">
                                    🚗 <?= hm($d['min_viaje']) ?>
                                </span>
                            <?php else: ?>
                                <span class="meta">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['min_activ'] > 0): ?>
                                <span title="<?= e(implode(', ', array_column($d['actividades'], 'titulo'))) ?>"
                                      style="background:#f5f3ff;color:#5b21b6;padding:1px 7px;border-radius:999px;font-size:11px;cursor:help">
                                    📋 <?= hm($d['min_activ']) ?>
                                </span>
                            <?php else: ?>
                                <span class="meta">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $tieneSalidaImpl = !empty(array_filter($u['detalle'], fn($d) => !empty($d['salida_implicita'])));
            if ($tieneSalidaImpl): ?>
            <div class="meta" style="margin-top:6px;font-size:11px;color:#92400e">
                * Salida implícita — calculada desde el inicio de la actividad del día
            </div>
            <?php endif; ?>
        </details>

        <!-- Viajes del mes -->
        <?php if ($u['viajes']): ?>
        <details style="margin-top:8px">
            <summary style="font-size:13px;color:#92400e;padding:4px 0">
                🚗 <?= count($u['viajes']) ?> viaje(s) en el período
            </summary>
            <table style="margin-top:8px;font-size:12px">
                <thead>
                    <tr>
                        <th>Destino / Título</th>
                        <th>Inicio</th>
                        <th>Fin</th>
                        <th>Horas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($u['viajes'] as $v):
                    $minV = max(0, (int)round((strtotime($v['fin_dt']) - strtotime($v['inicio_dt'])) / 60));
                ?>
                <tr>
                    <td><?= e($v['titulo'] ?: $v['destino']) ?></td>
                    <td class="meta"><?= date('d/m H:i', strtotime($v['inicio_dt'])) ?></td>
                    <td class="meta"><?= date('d/m H:i', strtotime($v['fin_dt'])) ?></td>
                    <td><span style="background:#fff7ed;color:#92400e;padding:1px 7px;border-radius:999px;font-size:11px"><?= hm($minV) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

</div>
</body>
</html>