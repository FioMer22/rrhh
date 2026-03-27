<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_once __DIR__ . '/../app/helpers/notificaciones.php';

require_login();
$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

// POST: marcar una leída
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_leida') {
    csrf_verify();
    $nid = (int)($_POST['nid'] ?? 0);
    if ($nid) {
        $pdo->prepare("UPDATE notificaciones SET leido=1 WHERE id=? AND usuario_id=?")
            ->execute([$nid, $uid]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// POST: marcar todas leídas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_todas') {
    csrf_verify();
    marcar_leidas($pdo, $uid);
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// Filtro
$soloNoLeidas = ($_GET['filtro'] ?? '') !== 'todas';
$where  = "usuario_id = ?";
$params = [$uid];
if ($soloNoLeidas) { $where .= " AND leido = 0"; }

$notifs = $pdo->prepare("
    SELECT * FROM notificaciones
    WHERE $where
    ORDER BY created_at DESC
    LIMIT 100
");
$notifs->execute($params);
$notifs   = $notifs->fetchAll();
$noLeidas = contar_no_leidas($pdo, $uid);

// Avisos de tardanza pendientes (para jefes y admin/rrhh)
$avisosPendientes = [];
if (has_any_role('admin', 'rrhh', 'coordinador')) {
    $esAdmin    = has_any_role('admin', 'rrhh');
    $whereJefe  = $esAdmin ? "1=1" : "at.jefe_id = $uid";
    try {
        $stAv = $pdo->prepare("
            SELECT at.*,
                   CONCAT(u.nombre,' ',u.apellido) AS empleado_nombre,
                   u.email AS empleado_email
            FROM avisos_tardanza at
            JOIN usuarios u ON u.id = at.usuario_id
            WHERE $whereJefe AND at.estado = 'pendiente' AND at.fecha = CURDATE()
            ORDER BY at.created_at DESC
        ");
        $stAv->execute();
        $avisosPendientes = $stAv->fetchAll();
    } catch (\PDOException $e) { $avisosPendientes = []; }
}

$iconos = [
    'aviso_tardanza' => '⚠️',
    'ausencia'       => '📋',
    'evento'         => '📅',
    'sistema'        => '⚙️',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notificaciones</title>
  <?php require_once __DIR__ . '/./_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .notif-item {
            display: flex; gap: 12px; padding: 14px;
            border-radius: 12px; border: 1px solid #e5e7eb;
            margin-bottom: 8px; background: #fff;
        }
        .notif-item.no-leida { background: #f0f7ff; border-color: #bfdbfe; }
        .notif-icon { font-size: 22px; flex-shrink: 0; width: 36px; text-align: center; }
        .notif-body { flex: 1; min-width: 0 }
        .notif-titulo { font-size: 14px; font-weight: 600 }
        .notif-cuerpo { font-size: 13px; color: #4b5563; margin-top: 2px }
        .notif-meta   { font-size: 11px; color: #9ca3af; margin-top: 4px }
        .aviso-card {
            background: #fff7ed; border: 1px solid #fed7aa;
            border-radius: 12px; padding: 14px; margin-bottom: 8px;
            display: flex; gap: 12px; align-items: flex-start;
        }
        .aviso-nombre { font-size: 14px; font-weight: 700; color: #92400e }
        .aviso-det    { font-size: 12px; color: #78350f; margin-top: 2px }
    </style>
</head>
<body>
<?php require __DIR__ . '/_layout.php'; ?>

<div class="wrap" style="max-width:750px">

    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <h2 style="margin:0">
            🔔 Notificaciones
            <?php if ($noLeidas > 0): ?>
                <span style="background:#ef4444;color:#fff;font-size:13px;
                             font-weight:700;padding:2px 9px;border-radius:999px;
                             margin-left:6px"><?= $noLeidas ?></span>
            <?php endif; ?>
        </h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="?filtro=<?= $soloNoLeidas ? 'todas' : '' ?>"
               style="font-size:13px;color:#114c97;text-decoration:none;
                      padding:6px 12px;border:1px solid #bfdbfe;border-radius:8px;
                      background:<?= $soloNoLeidas ? '#fff' : '#dbeafe' ?>">
                <?= $soloNoLeidas ? 'Ver todas' : 'Solo no leídas' ?>
            </a>
            <?php if ($noLeidas > 0): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="marcar_todas">
                <button type="submit"
                        style="font-size:13px;padding:6px 12px;border:1px solid #e5e7eb;
                               border-radius:8px;background:#fff;cursor:pointer;color:#374151">
                    ✓ Marcar todas como leídas
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Avisos de tardanza activos hoy -->
    <?php if ($avisosPendientes): ?>
    <div class="card" style="margin-bottom:16px;border-left:4px solid #f97316">
        <div style="font-size:12px;font-weight:700;color:#92400e;
                    text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
            ⚠️ Llegadas tarde hoy — <?= count($avisosPendientes) ?> aviso<?= count($avisosPendientes) > 1 ? 's' : '' ?>
        </div>
        <?php foreach ($avisosPendientes as $av): ?>
        <div class="aviso-card">
            <div style="font-size:26px">🕐</div>
            <div style="flex:1">
                <div class="aviso-nombre"><?= e($av['empleado_nombre']) ?></div>
                <div class="aviso-det"><?= e($av['empleado_email']) ?></div>
                <?php if ($av['hora_estimada']): ?>
                    <div class="aviso-det">
                        Llega a las <strong><?= e(date('H:i', strtotime($av['hora_estimada']))) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($av['motivo']): ?>
                    <div class="aviso-det" style="font-style:italic">"<?= e($av['motivo']) ?>"</div>
                <?php endif; ?>
                <div class="aviso-det" style="color:#9ca3af;margin-top:4px">
                    Enviado a las <?= e(date('H:i', strtotime($av['created_at']))) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Lista de notificaciones -->
    <?php foreach ($notifs as $n): ?>
    <div class="notif-item <?= !$n['leido'] ? 'no-leida' : '' ?>">
        <div class="notif-icon"><?= $iconos[$n['tipo']] ?? '🔔' ?></div>
        <div class="notif-body">
            <div class="notif-titulo"><?= e($n['titulo']) ?></div>
            <?php if ($n['mensaje']): ?>
                <div class="notif-cuerpo"><?= e($n['mensaje']) ?></div>
            <?php endif; ?>
            <div class="notif-meta">
                <?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?>
                <?php if ($n['leido']): ?>
                    · <span style="color:#22c55e">✓ Leída</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$n['leido']): ?>
        <form method="post" style="flex-shrink:0">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="marcar_leida">
            <input type="hidden" name="nid"    value="<?= (int)$n['id'] ?>">
            <button type="submit"
                    style="background:none;border:none;cursor:pointer;
                           color:#6b7280;font-size:18px;padding:4px"
                    title="Marcar como leída">✓</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!$notifs): ?>
    <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:48px;margin-bottom:10px">🔔</div>
        <div class="meta">No tenés notificaciones<?= $soloNoLeidas ? ' sin leer' : '' ?>.</div>
        <?php if ($soloNoLeidas): ?>
            <a href="?filtro=todas" style="font-size:13px;color:#114c97;text-decoration:none;
                margin-top:8px;display:inline-block">Ver historial completo →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
