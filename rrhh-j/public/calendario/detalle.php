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
$esAdmin = has_any_role('admin', 'rrhh'); // acceso total
$esCoord = has_any_role('admin', 'rrhh', 'coordinador', 'direccion');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('/calendario/index.php'); }

$ok = $err = null;

// ── Área del usuario logueado ────────────────────────────────────────────────
$stAreaUid = $pdo->prepare("SELECT area_id FROM usuarios WHERE id = ?");
$stAreaUid->execute([$uid]);
$areaDelUsuario = (int)($stAreaUid->fetchColumn() ?? 0);

// Acceso total: admin y rrhh pueden editar cualquier área
// Coordinador: solo su propia área
function puede_editar_area(int $areaId, bool $esAdmin, int $areaDelUsuario): bool {
    if ($esAdmin) return true; // admin y rrhh — acceso total
    return $areaDelUsuario === $areaId;
}

// ── POST: agregar ítem de checklist ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'add_item') {
    csrf_verify();
    $area_id     = (int)($_POST['area_id']       ?? 0);
    $titulo      = trim($_POST['titulo']          ?? '');
    $responsable = (int)($_POST['responsable_id'] ?? 0);
    $fecha_obj   = trim($_POST['fecha_objetivo']  ?? '');

    if ($titulo === '' || $area_id <= 0) {
        $err = 'El título y el área son obligatorios.';
    } elseif (!puede_editar_area($area_id, $esAdmin, $areaDelUsuario)) {
        $err = 'Solo podés agregar ítems a tu propia área.';
    } else {
        $pdo->prepare("
            INSERT INTO eventos_checklists
                (evento_id, area_id, titulo, estado, responsable_id, fecha_objetivo)
            VALUES (?, ?, ?, 'pendiente', ?, ?)
        ")->execute([
            $id, $area_id, $titulo,
            $responsable > 0 ? $responsable : null,
            $fecha_obj !== '' ? $fecha_obj : null,
        ]);
        $ok = 'Ítem agregado.';
    }
}

// ── POST: cambiar estado de un ítem ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_estado') {
    csrf_verify();
    $itemId  = (int)($_POST['item_id'] ?? 0);
    $estado  = $_POST['estado'] ?? '';
    $validos = ['pendiente', 'en_progreso', 'hecho'];

    if ($itemId > 0 && in_array($estado, $validos, true)) {
        // Verificar que el ítem pertenece al área del usuario (o es admin)
        $stArea = $pdo->prepare("SELECT area_id FROM eventos_checklists WHERE id = ? AND evento_id = ?");
        $stArea->execute([$itemId, $id]);
        $itemAreaId = (int)($stArea->fetchColumn() ?? 0);

        if (!puede_editar_area($itemAreaId, $esAdmin, $areaDelUsuario)) {
            $err = 'Solo podés cambiar el estado de ítems de tu propia área.';
        } else {
            $pdo->prepare("UPDATE eventos_checklists SET estado = ? WHERE id = ? AND evento_id = ?")
                ->execute([$estado, $itemId, $id]);
            $ok = 'Estado actualizado.';
        }
    }
}

// ── POST: eliminar ítem (admin o miembro del área) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_item') {
    csrf_verify();
    $itemId = (int)($_POST['item_id'] ?? 0);
    if ($itemId > 0) {
        $stArea = $pdo->prepare("SELECT area_id FROM eventos_checklists WHERE id = ? AND evento_id = ?");
        $stArea->execute([$itemId, $id]);
        $itemAreaId = (int)($stArea->fetchColumn() ?? 0);

        if (!puede_editar_area($itemAreaId, $esAdmin, $areaDelUsuario)) {
            $err = 'Solo podés eliminar ítems de tu propia área.';
        } else {
            $pdo->prepare("DELETE FROM eventos_checklists WHERE id = ? AND evento_id = ?")
                ->execute([$itemId, $id]);
            $ok = 'Ítem eliminado.';
        }
    }
}

// ── Cargar evento ─────────────────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT e.*, CONCAT(u.nombre,' ',u.apellido) AS creador, s.nombre AS sede_nombre
    FROM eventos e
    LEFT JOIN usuarios u ON u.id = e.creado_por
    LEFT JOIN sedes s    ON s.id = e.sede_id
    WHERE e.id = ?
");
$st->execute([$id]);
$evento = $st->fetch();
if (!$evento) { redirect('/calendario/index.php'); }

$tiposEvento = [
    'reunion'        => '🤝 Reunión',
    'capacitacion'   => '📚 Capacitación',
    'actividad'      => '🗺 Actividad de campo',
    'celebracion'    => '🎉 Celebración',
    'administrativo' => '📋 Administrativo',
    'otro'           => '📌 Otro',
];

// ── Participantes ─────────────────────────────────────────────────────────────
$stPart = $pdo->prepare("
    SELECT ep.estado_respuesta, ep.respondio_en, ep.nota,
           u.nombre, u.apellido, u.email,
           a.nombre AS area
    FROM eventos_participantes ep
    JOIN usuarios u   ON u.id = ep.usuario_id
    LEFT JOIN areas a ON a.id = u.area_id
    WHERE ep.evento_id = ?
    ORDER BY ep.estado_respuesta ASC, a.nombre ASC, u.nombre ASC
");
$stPart->execute([$id]);
$participantes = $stPart->fetchAll();
$confirmados   = array_filter($participantes, fn($p) => $p['estado_respuesta'] === 'confirmado');
$pendientesConf= array_filter($participantes, fn($p) => $p['estado_respuesta'] === 'pendiente');
$totalPart     = count($participantes);
$pctConf       = $totalPart > 0 ? round((count($confirmados) / $totalPart) * 100) : 0;

// ── Áreas convocadas ──────────────────────────────────────────────────────────
$areasConvocadas = $pdo->prepare("
    SELECT a.id, a.nombre
    FROM eventos_convocatorias ec
    JOIN areas a ON a.id = ec.target_id
    WHERE ec.evento_id = ? AND ec.target_tipo = 'area'
    ORDER BY a.nombre
");
$areasConvocadas->execute([$id]);
$areasConvocadas = $areasConvocadas->fetchAll();

// ── Checklists agrupadas por área ─────────────────────────────────────────────
$stCheck = $pdo->prepare("
    SELECT ch.*,
           a.nombre  AS area_nombre,
           CONCAT(u.nombre,' ',u.apellido) AS responsable_nombre
    FROM eventos_checklists ch
    JOIN areas a    ON a.id = ch.area_id
    LEFT JOIN usuarios u ON u.id = ch.responsable_id
    WHERE ch.evento_id = ?
    ORDER BY a.nombre ASC, ch.estado ASC, ch.titulo ASC
");
$stCheck->execute([$id]);
$todosItems = $stCheck->fetchAll();

// Agrupar por área
$checklistPorArea = [];
foreach ($todosItems as $item) {
    $checklistPorArea[$item['area_id']]['nombre'] = $item['area_nombre'];
    $checklistPorArea[$item['area_id']]['items'][] = $item;
}

// Progreso global de checklist
$totalItems  = count($todosItems);
$hechos      = count(array_filter($todosItems, fn($i) => $i['estado'] === 'hecho'));
$enProgreso  = count(array_filter($todosItems, fn($i) => $i['estado'] === 'en_progreso'));
$pctChecklist= $totalItems > 0 ? round(($hechos / $totalItems) * 100) : 0;

// Usuarios para asignar responsable (miembros de áreas convocadas)
$idsAreas = array_column($areasConvocadas, 'id');
$usuariosResp = [];
if ($idsAreas) {
    $placeholders = implode(',', array_fill(0, count($idsAreas), '?'));
    $stResp = $pdo->prepare("
        SELECT id, CONCAT(nombre,' ',apellido) AS label, area_id
        FROM usuarios
        WHERE area_id IN ($placeholders) AND activo = 1
        ORDER BY area_id, nombre
    ");
    $stResp->execute($idsAreas);
    $usuariosResp = $stResp->fetchAll();
}

function estado_check_badge(string $estado): string {
    return match($estado) {
        'pendiente'   => '<span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:999px;font-size:11px">⏳ Pendiente</span>',
        'en_progreso' => '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:999px;font-size:11px">🔄 En progreso</span>',
        'hecho'       => '<span style="background:#d1e7dd;color:#0a3622;padding:2px 8px;border-radius:999px;font-size:11px">✅ Hecho</span>',
        default       => e($estado),
    };
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Detalle — <?= e($evento['titulo']) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .dos-col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        @media(max-width:750px){.dos-col{grid-template-columns:1fr}}
        .tres-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
        @media(max-width:900px){.tres-col{grid-template-columns:1fr 1fr}}
        @media(max-width:600px){.tres-col{grid-template-columns:1fr}}

        /* Progreso */
        .prog-bar{background:#e5e7eb;border-radius:999px;height:12px;margin-top:6px;overflow:hidden}
        .prog-fill{border-radius:999px;height:12px;transition:width .4s ease}
        .prog-verde{background:linear-gradient(90deg,#0a5c2e,#22c55e)}
        .prog-azul{background:linear-gradient(90deg,#114c97,#3b82f6)}

        /* Checklist */
        .check-area{background:#fff;border-radius:14px;border:1px solid #e5e7eb;
                    margin-bottom:14px;overflow:hidden}
        .check-area-header{background:#0b1f3a;color:#fff;padding:12px 16px;
                           display:flex;justify-content:space-between;align-items:center}
        .check-area-header h4{margin:0;font-size:15px}
        .check-item{display:grid;grid-template-columns:1fr auto auto;
                    gap:10px;align-items:center;padding:10px 16px;
                    border-bottom:1px solid #f3f4f6}
        .check-item:last-child{border-bottom:none}
        .check-item.hecho .item-titulo{text-decoration:line-through;color:#9ca3af}
        .item-titulo{font-size:14px}
        .item-meta{font-size:11px;color:#6b7280;margin-top:2px}

        /* Botones estado inline */
        .estado-btns{display:flex;gap:4px;flex-wrap:wrap}
        .btn-est{border:none;border-radius:8px;padding:4px 8px;font-size:11px;
                 cursor:pointer;opacity:.7;transition:opacity .15s}
        .btn-est:hover{opacity:1}
        .btn-est.activo{opacity:1;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.15)}

        /* Form agregar ítem */
        .add-item-form{background:#f9fafb;padding:14px 16px;border-top:2px dashed #e5e7eb}
        .add-item-form .fila{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
        .add-item-form input,.add-item-form select{padding:7px 10px;border-radius:8px;
            border:1px solid #e5e7eb;font-size:13px}

        /* Tabs */
        .tabs{display:flex;gap:2px;margin-bottom:14px;border-bottom:2px solid #e5e7eb}
        .tab{padding:10px 18px;cursor:pointer;border-radius:10px 10px 0 0;
             font-size:14px;font-weight:600;color:#6b7280;border:none;background:none}
        .tab.activo{background:#0b1f3a;color:#fff}
        .tab-panel{display:none}
        .tab-panel.activo{display:block}
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1000px">

    <p style="margin-bottom:14px">
        <a href="index.php" style="color:#114c97">← Volver a eventos</a>
    </p>

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

    <!-- ── Cabecera del evento ─────────────────────────────────────── -->
    <div class="card" style="margin-bottom:14px">
        <span style="display:inline-block;padding:2px 10px;border-radius:999px;
                     font-size:11px;background:#e8f0fe;color:#1a56db;margin-bottom:8px">
            <?= e($tiposEvento[$evento['tipo']] ?? $evento['tipo']) ?>
        </span>
        <h2 style="margin:0 0 8px"><?= e($evento['titulo']) ?></h2>
        <p class="meta">
            🗓 <?= e(date('d/m/Y H:i', strtotime($evento['inicio']))) ?>
            &nbsp;→&nbsp;
            <?= e(date('d/m/Y H:i', strtotime($evento['fin']))) ?>
        </p>
        <?php if ($evento['lugar_texto']): ?>
            <p class="meta">📍 <?= e($evento['lugar_texto']) ?></p>
        <?php endif; ?>
        <?php if ($evento['sede_nombre']): ?>
            <p class="meta">🏢 <?= e($evento['sede_nombre']) ?></p>
        <?php endif; ?>
        <?php if ($evento['descripcion']): ?>
            <p style="margin-top:10px;line-height:1.6"><?= nl2br(e($evento['descripcion'])) ?></p>
        <?php endif; ?>
        <p class="meta" style="margin-top:10px">
            👤 Creado por <?= e($evento['creador']) ?>
        </p>
    </div>

    <!-- ── Barras de progreso resumen ─────────────────────────────── -->
    <div class="dos-col" style="margin-bottom:14px">

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <strong>📣 Confirmaciones de recepción</strong>
                <span style="font-size:22px;font-weight:700;color:#114c97"><?= $pctConf ?>%</span>
            </div>
            <div class="prog-bar">
                <div class="prog-fill prog-azul" style="width:<?= $pctConf ?>%"></div>
            </div>
            <p class="meta" style="margin-top:6px">
                <?= count($confirmados) ?> de <?= $totalPart ?> participantes confirmaron
            </p>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <strong>✅ Progreso de preparación</strong>
                <span style="font-size:22px;font-weight:700;color:#0a5c2e"><?= $pctChecklist ?>%</span>
            </div>
            <div class="prog-bar">
                <div class="prog-fill prog-verde" style="width:<?= $pctChecklist ?>%"></div>
            </div>
            <p class="meta" style="margin-top:6px">
                <?= $hechos ?> hechos · <?= $enProgreso ?> en progreso · <?= $totalItems - $hechos - $enProgreso ?> pendientes
                <?php if ($totalItems === 0): ?>(Sin ítems aún)<?php endif; ?>
            </p>
        </div>

    </div>

    <!-- ── Tabs ────────────────────────────────────────────────────── -->
    <div class="tabs">
        <button class="tab activo" onclick="showTab('checklist', this)">
            📋 Checklist por área
        </button>
        <button class="tab" onclick="showTab('participantes', this)">
            👥 Participantes
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         TAB 1: CHECKLIST POR ÁREA
    ════════════════════════════════════════════════════════════════ -->
    <div id="tab-checklist" class="tab-panel activo">

        <?php if (!$areasConvocadas): ?>
            <div class="card">
                <p class="meta">Este evento no tiene áreas convocadas con checklist.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($areasConvocadas as $area):
            $aId     = (int)$area['id'];
            $aNombre = $area['nombre'];
            $items   = $checklistPorArea[$aId]['items'] ?? [];
            $aTotal  = count($items);
            $aHechos = count(array_filter($items, fn($i) => $i['estado'] === 'hecho'));
            $aPct    = $aTotal > 0 ? round(($aHechos / $aTotal) * 100) : 0;

            // Usuarios del área para el select de responsable
            $usuariosArea = array_filter($usuariosResp, fn($u) => (int)$u['area_id'] === $aId);
        ?>
        <div class="check-area">

            <!-- Cabecera del área -->
            <div class="check-area-header">
                <div>
                    <h4><?= e($aNombre) ?></h4>
                    <div style="font-size:12px;opacity:.8;margin-top:2px">
                        <?= $aHechos ?>/<?= $aTotal ?> completados
                    </div>
                </div>
                <div style="text-align:right;min-width:80px">
                    <div style="font-size:24px;font-weight:700"><?= $aPct ?>%</div>
                    <div style="background:rgba(255,255,255,.2);border-radius:999px;height:6px;margin-top:4px">
                        <div style="background:#22c55e;border-radius:999px;height:6px;width:<?= $aPct ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Ítems del área -->
            <?php if (!$items): ?>
                <div style="padding:14px 16px;color:#6b7280;font-size:13px">
                    Sin ítems aún. Agregá el primero abajo. ↓
                </div>
            <?php endif; ?>

            <?php foreach ($items as $item): ?>
            <div class="check-item <?= $item['estado'] === 'hecho' ? 'hecho' : '' ?>">

                <div>
                    <div class="item-titulo"><?= e($item['titulo']) ?></div>
                    <div class="item-meta">
                        <?= estado_check_badge($item['estado']) ?>
                        <?php if ($item['responsable_nombre']): ?>
                            &nbsp;· 👤 <?= e($item['responsable_nombre']) ?>
                        <?php endif; ?>
                        <?php if ($item['fecha_objetivo']): ?>
                            &nbsp;· 📅 <?= e(date('d/m/Y', strtotime($item['fecha_objetivo']))) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Botones de estado — solo si puede editar esta área -->
                <?php if (puede_editar_area($aId, $esAdmin, $areaDelUsuario)): ?>
                <div class="estado-btns">
                    <?php
                    $estados = [
                        'pendiente'   => ['⏳', '#e5e7eb', '#374151'],
                        'en_progreso' => ['🔄', '#fff3cd', '#856404'],
                        'hecho'       => ['✅', '#d1e7dd', '#0a3622'],
                    ];
                    foreach ($estados as $val => [$icon, $bg, $color]):
                        $activo = $item['estado'] === $val ? 'activo' : '';
                    ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion"   value="cambiar_estado">
                        <input type="hidden" name="item_id"  value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="estado"   value="<?= e($val) ?>">
                        <button type="submit" class="btn-est <?= $activo ?>"
                            style="background:<?= $bg ?>;color:<?= $color ?>"
                            title="<?= ucfirst(str_replace('_',' ',$val)) ?>">
                            <?= $icon ?>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="min-width:80px"><?= estado_check_badge($item['estado']) ?></div>
                <?php endif; ?>

                <!-- Eliminar — solo si puede editar esta área -->
                <?php if (puede_editar_area($aId, $esAdmin, $areaDelUsuario)): ?>
                <form method="post" onsubmit="return confirm('¿Eliminar este ítem?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion"  value="eliminar_item">
                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <button type="submit" style="background:none;border:none;color:#9ca3af;
                            cursor:pointer;padding:4px 6px;font-size:14px" title="Eliminar">✕</button>
                </form>
                <?php else: ?>
                <div style="min-width:24px"></div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>

            <!-- Form agregar ítem — solo si pertenece a esta área o es admin -->
            <?php if (puede_editar_area($aId, $esAdmin, $areaDelUsuario)): ?>
            <div class="add-item-form">
                <div style="font-size:12px;color:#6b7280;margin-bottom:8px;font-weight:600">
                    ＋ Agregar ítem para <?= e($aNombre) ?>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion"  value="add_item">
                    <input type="hidden" name="area_id" value="<?= $aId ?>">
                    <div class="fila">
                        <input type="text" name="titulo" required
                               placeholder="Ej: Sistema de sonido, Pantalla LED..."
                               style="flex:2;min-width:200px">

                        <select name="responsable_id" style="flex:1;min-width:140px">
                            <option value="0">— Responsable —</option>
                            <?php foreach ($usuariosArea as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= e($u['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="date" name="fecha_objetivo"
                               style="flex:1;min-width:130px">

                        <button type="submit"
                            style="background:#0b1f3a;color:#fff;padding:7px 14px;
                                   border-radius:8px;font-size:13px;white-space:nowrap">
                            ＋ Agregar
                        </button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div style="padding:10px 16px;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6">
                Solo los miembros de <?= e($aNombre) ?> pueden agregar ítems a esta sección.
            </div>
            <?php endif; ?>

        </div><!-- /.check-area -->
        <?php endforeach; ?>

    </div><!-- /#tab-checklist -->

    <!-- ════════════════════════════════════════════════════════════════
         TAB 2: PARTICIPANTES
    ════════════════════════════════════════════════════════════════ -->
    <div id="tab-participantes" class="tab-panel">

        <?php if ($pendientesConf): ?>
        <div class="card" style="border-left:4px solid #f59e0b;margin-bottom:14px">
            <h3 style="margin:0 0 10px;color:#92400e">
                ⏳ Sin confirmar recepción (<?= count($pendientesConf) ?>)
            </h3>
            <table>
                <thead><tr><th>Nombre</th><th>Email</th><th>Área</th></tr></thead>
                <tbody>
                <?php foreach ($pendientesConf as $p): ?>
                    <tr>
                        <td><?= e($p['nombre'].' '.$p['apellido']) ?></td>
                        <td><?= e($p['email']) ?></td>
                        <td><?= e($p['area'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($confirmados): ?>
        <div class="card">
            <h3 style="margin:0 0 10px;color:#0a3622">
                ✅ Confirmaron recepción (<?= count($confirmados) ?>)
            </h3>
            <table>
                <thead>
                    <tr><th>Nombre</th><th>Área</th><th>Confirmó el</th><th>Nota</th></tr>
                </thead>
                <tbody>
                <?php foreach ($confirmados as $p): ?>
                    <tr>
                        <td><?= e($p['nombre'].' '.$p['apellido']) ?></td>
                        <td><?= e($p['area'] ?? '—') ?></td>
                        <td class="meta">
                            <?= $p['respondio_en']
                                ? e(date('d/m/Y H:i', strtotime($p['respondio_en'])))
                                : '—' ?>
                        </td>
                        <td><?= e($p['nota'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /#tab-participantes -->

</div>

<script>
function showTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('activo'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('activo'));
    document.getElementById('tab-' + name).classList.add('activo');
    btn.classList.add('activo');
}
</script>
</body>
</html>
