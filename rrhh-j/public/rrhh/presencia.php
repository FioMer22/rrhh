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

$pdo  = DB::pdo();
$hoy  = date('Y-m-d');
$ahora= date('H:i');

// Todos los usuarios activos con su área
$usuarios = $pdo->query("
    SELECT u.id, u.nombre, u.apellido, u.email,
           a.nombre AS area_nombre
    FROM usuarios u
    LEFT JOIN areas a ON a.id = u.area_id
    WHERE u.activo = 1
    ORDER BY a.nombre, u.apellido, u.nombre
")->fetchAll();

// Estado de cada uno
$porEstado = [
    'oficina'     => [],
    'viaje'       => [],
    'actividad'   => [],
    'ausente'     => [],
    'almuerzo'    => [],
    'fuera'       => [],
    'desconocido' => [],
];

foreach ($usuarios as $u) {
    $est = estado_actual_usuario($pdo, (int)$u['id']);
    $u['estado_label']  = $est['estado'];
    $u['estado_detalle']= $est['detalle'];
    $porEstado[$est['estado']][] = $u;
}

$config = [
    'oficina'     => ['label'=>'En oficina',         'color'=>'#0a5c2e','bg'=>'#d1e7dd','emoji'=>'🟢'],
    'viaje'       => ['label'=>'En viaje',            'color'=>'#1a3a6b','bg'=>'#dbeafe','emoji'=>'✈️'],
    'actividad'   => ['label'=>'En actividad',        'color'=>'#7c3aed','bg'=>'#ede9fe','emoji'=>'⚡'],
    'ausente'     => ['label'=>'Con permiso',         'color'=>'#856404','bg'=>'#fff3cd','emoji'=>'📋'],
    'almuerzo'    => ['label'=>'En almuerzo',         'color'=>'#92400e','bg'=>'#ffedd5','emoji'=>'🍽'],
    'fuera'       => ['label'=>'Salió',               'color'=>'#374151','bg'=>'#f3f4f6','emoji'=>'🚪'],
    'desconocido' => ['label'=>'Sin registro hoy',    'color'=>'#842029','bg'=>'#f8d7da','emoji'=>'⚪'],
];

$total = count($usuarios);
$enOficina = count($porEstado['oficina']) + count($porEstado['almuerzo']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Presencia — <?= $ahora ?></title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
        .kpi{padding:12px 18px;border-radius:12px;border:1px solid #e5e7eb;
             background:#fff;text-align:center;min-width:100px}
        .kpi-val{font-size:28px;font-weight:700}
        .kpi-lab{font-size:11px;color:#6b7280;margin-top:2px}
        .grupo{margin-bottom:18px}
        .grupo-titulo{display:flex;align-items:center;gap:8px;
                      font-size:13px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.5px;margin-bottom:8px}
        .personas{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}
        .persona-card{border-radius:10px;padding:10px 14px;
                      display:flex;align-items:flex-start;gap:10px}
        .avatar{width:36px;height:36px;border-radius:50%;display:flex;
                align-items:center;justify-content:center;
                font-size:15px;font-weight:700;color:#fff;flex-shrink:0}
        .persona-nombre{font-size:13px;font-weight:600;line-height:1.2}
        .persona-det{font-size:11px;opacity:.75;margin-top:2px}
        .update-badge{font-size:11px;color:#6b7280;margin-bottom:14px}
        .barra-presencia{height:10px;border-radius:999px;background:#f3f4f6;
                         overflow:hidden;margin-bottom:4px}
        .barra-fill{height:10px;background:#0a5c2e;border-radius:999px}
    </style>
    <!-- Auto-refresh cada 2 minutos -->
    <meta http-equiv="refresh" content="120">
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1050px">

    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <div>
            <h2 style="margin:0">Estado actual del personal</h2>
            <div class="update-badge">
                📅 <?= date('d/m/Y') ?> · 🕐 <?= $ahora ?> · 
                Se actualiza automáticamente cada 2 minutos
            </div>
        </div>
        <button onclick="location.reload()"
                style="padding:8px 14px;background:#114c97;color:#fff;
                       border:none;border-radius:10px;cursor:pointer">
            🔄 Actualizar ahora
        </button>
    </div>

    <!-- KPIs globales -->
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-val" style="color:#114c97"><?= $total ?></div>
            <div class="kpi-lab">Total personal</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#0a5c2e"><?= $enOficina ?></div>
            <div class="kpi-lab">En oficina ahora</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#1a3a6b"><?= count($porEstado['viaje']) ?></div>
            <div class="kpi-lab">En viaje</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#7c3aed"><?= count($porEstado['actividad']) ?></div>
            <div class="kpi-lab">En actividad</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#856404"><?= count($porEstado['ausente']) ?></div>
            <div class="kpi-lab">Con permiso</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#842029"><?= count($porEstado['desconocido']) ?></div>
            <div class="kpi-lab">Sin registro</div>
        </div>
    </div>

    <!-- Barra de presencia global -->
    <?php $pct = $total > 0 ? round($enOficina * 100 / $total) : 0; ?>
    <div style="margin-bottom:18px">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px">
            Presencia hoy: <strong style="color:#0a5c2e"><?= $pct ?>%</strong>
            (<?= $enOficina ?> de <?= $total ?>)
        </div>
        <div class="barra-presencia">
            <div class="barra-fill" style="width:<?= $pct ?>%"></div>
        </div>
    </div>

    <!-- Grupos por estado -->
    <?php foreach ($config as $key => $cfg):
        $personas = $porEstado[$key] ?? [];
        if (!$personas) continue;
    ?>
    <div class="grupo">
        <div class="grupo-titulo">
            <span><?= $cfg['emoji'] ?></span>
            <span style="color:<?= $cfg['color'] ?>"><?= $cfg['label'] ?></span>
            <span style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;
                         padding:1px 8px;border-radius:999px;font-size:11px;
                         font-weight:700"><?= count($personas) ?></span>
        </div>
        <div class="personas">
            <?php foreach ($personas as $p):
                $ini = strtoupper(mb_substr($p['nombre'],0,1));
                $avatarBg = match($key) {
                    'oficina'   => '#0a5c2e',
                    'viaje'     => '#1a3a6b',
                    'actividad' => '#7c3aed',
                    'ausente'   => '#856404',
                    'almuerzo'  => '#92400e',
                    'fuera'     => '#374151',
                    default     => '#9ca3af',
                };
            ?>
            <div class="persona-card" style="background:<?= $cfg['bg'] ?>">
                <div class="avatar" style="background:<?= $avatarBg ?>"><?= $ini ?></div>
                <div>
                    <div class="persona-nombre" style="color:<?= $cfg['color'] ?>">
                        <?= e(trim($p['nombre'].' '.$p['apellido'])) ?>
                    </div>
                    <div class="persona-det" style="color:<?= $cfg['color'] ?>">
                        <?= e($p['area_nombre'] ?? '—') ?>
                    </div>
                    <?php if ($p['estado_detalle'] && !in_array($key, ['oficina','fuera','desconocido'])): ?>
                    <div class="persona-det" style="color:<?= $cfg['color'] ?>;font-style:italic">
                        <?= e($p['estado_detalle']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

</div>
</body>
</html>
