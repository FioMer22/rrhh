<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_once __DIR__ . '/../../app/helpers/utils.php';
require_once __DIR__ . '/../../app/helpers/notificaciones.php';
require_once __DIR__ . '/../../app/helpers/push.php';

require_login();

$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

// ¿Quién soy?
$yo = $pdo->prepare("SELECT id, nombre, apellido, area_id, jefe_id FROM usuarios WHERE id = ?");
$yo->execute([$uid]);
$yo = $yo->fetch();

$esAdminRrhh  = has_any_role('admin', 'rrhh');
$esCoord      = has_any_role('coordinador');
$puedeAprobar = puede_aprobar_ausencias();

$ok = $err = null;

// ── POST: nueva solicitud ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'solicitar') {
    csrf_verify();

    $tipo_id  = (int)($_POST['tipo_ausencia_id'] ?? 0);
    $inicio   = trim($_POST['inicio'] ?? '');
    $fin      = trim($_POST['fin']    ?? '');
    $comentario = trim($_POST['comentario'] ?? '');

    if (!$tipo_id || !$inicio || !$fin) {
        $err = 'Completá todos los campos obligatorios.';
    } elseif ($fin < $inicio) {
        $err = 'La fecha de fin no puede ser anterior al inicio.';
    } else {
        $pdo->prepare("
            INSERT INTO solicitudes_ausencia
                (usuario_id, tipo_ausencia_id, inicio, fin, comentario, estado)
            VALUES (?, ?, ?, ?, ?, 'pendiente')
        ")->execute([$uid, $tipo_id, $inicio, $fin, $comentario ?: null]);

        // Notificar a admins/rrhh vía push
        $nombreSol = trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''));
        push_notificar_admins($pdo,
            "📋 Nueva solicitud de ausencia",
            "$nombreSol solicitó ausencia del $inicio al $fin",
            '/rrhh-j/public/ausencias/index.php'
        );
        crear_notificacion_admins($pdo,
            '📋 Nueva solicitud de ausencia',
            "$nombreSol solicitó ausencia del $inicio al $fin",
            $uid
        );
        $ok = 'Solicitud enviada correctamente. Tu coordinador recibirá la notificación.';
    }
}

// ── POST: aprobar / rechazar ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'resolver') {
    csrf_verify();

    if (!$puedeAprobar) { $err = 'No tenés permiso para aprobar ausencias.'; }
    else {
        $solId  = (int)($_POST['solicitud_id'] ?? 0);
        $accion = $_POST['decision'] ?? '';
        $nota   = trim($_POST['nota_aprobador'] ?? '');

        if (!in_array($accion, ['aprobado', 'rechazado'], true)) {
            $err = 'Acción inválida.';
        } else {
            // Verificar que el coordinador solo resuelva solicitudes de su área
            if ($esCoord && !$esAdminRrhh) {
                $check = $pdo->prepare("
                    SELECT sa.id FROM solicitudes_ausencia sa
                    JOIN usuarios u ON u.id = sa.usuario_id
                    WHERE sa.id = ? AND u.area_id = ?
                ");
                $check->execute([$solId, $yo['area_id']]);
                if (!$check->fetch()) {
                    $err = 'Solo podés resolver solicitudes de tu área.';
                }
            }

            if (!$err) {
                // Obtener datos de la solicitud para notificar al solicitante
                $stSol = $pdo->prepare("
                    SELECT sa.usuario_id, sa.inicio, sa.fin,
                           u.nombre, u.apellido
                    FROM solicitudes_ausencia sa
                    JOIN usuarios u ON u.id = sa.usuario_id
                    WHERE sa.id = ? LIMIT 1
                ");
                $stSol->execute([$solId]);
                $solData = $stSol->fetch();

                $pdo->prepare("
                    UPDATE solicitudes_ausencia
                    SET estado = ?, aprobador_id = ?, aprobado_en = NOW(),
                        comentario = CASE
                            WHEN ? != '' THEN CONCAT(IFNULL(comentario,''), ' | Nota: ', ?)
                            ELSE comentario
                        END
                    WHERE id = ? AND estado = 'pendiente'
                ")->execute([$accion, $uid, $nota, $nota, $solId]);

                // Push al empleado
                if ($solData) {
                    $emoji  = $accion === 'aprobado' ? '✅' : '❌';
                    $estado = $accion === 'aprobado' ? 'aprobada' : 'rechazada';
                    $tituloEmp = "$emoji Tu ausencia fue $estado";
                    $cuerpoEmp = "Del {$solData['inicio']} al {$solData['fin']}" . ($nota ? " — $nota" : '');
                    push_notificar($pdo, (int)$solData['usuario_id'], $tituloEmp, $cuerpoEmp, '/rrhh-j/public/ausencias/index.php');
                    crear_notificacion($pdo, (int)$solData['usuario_id'], 'ausencia_resuelta', $tituloEmp, $cuerpoEmp, $uid, $solId);
                }

                $ok = $accion === 'aprobado' ? '✅ Ausencia aprobada.' : '❌ Ausencia rechazada.';
            }
        }
    }
}

// ── Tipos de ausencia ─────────────────────────────────────────────────────────
$tipos = $pdo->query("SELECT * FROM tipos_ausencia WHERE activo=1 ORDER BY nombre")->fetchAll();

// ── Mis solicitudes ───────────────────────────────────────────────────────────
$misSols = $pdo->prepare("
    SELECT sa.*, ta.nombre AS tipo_nombre,
           CONCAT(u.nombre,' ',u.apellido) AS aprobador_nombre
    FROM solicitudes_ausencia sa
    JOIN tipos_ausencia ta ON ta.id = sa.tipo_ausencia_id
    LEFT JOIN usuarios u   ON u.id  = sa.aprobador_id
    WHERE sa.usuario_id = ?
    ORDER BY sa.created_at DESC
    LIMIT 20
");
$misSols->execute([$uid]);
$misSols = $misSols->fetchAll();

// ── Solicitudes pendientes para aprobar ──────────────────────────────────────
$pendientes = [];
if ($puedeAprobar) {
    if ($esAdminRrhh) {
        // Admin y RRHH ven TODAS las pendientes
        $stPend = $pdo->query("
            SELECT sa.*,
                   ta.nombre AS tipo_nombre,
                   CONCAT(u.nombre,' ',u.apellido)  AS empleado_nombre,
                   a.nombre                          AS area_nombre,
                   CONCAT(j.nombre,' ',j.apellido)  AS jefe_nombre
            FROM solicitudes_ausencia sa
            JOIN tipos_ausencia ta ON ta.id = sa.tipo_ausencia_id
            JOIN usuarios u        ON u.id  = sa.usuario_id
            LEFT JOIN areas a      ON a.id  = u.area_id
            LEFT JOIN usuarios j   ON j.id  = u.jefe_id
            WHERE sa.estado = 'pendiente'
            ORDER BY sa.created_at ASC
        ");
        $pendientes = $stPend->fetchAll();
    } else {
        // Coordinador: solo ve las de su área
        $stPend = $pdo->prepare("
            SELECT sa.*,
                   ta.nombre AS tipo_nombre,
                   CONCAT(u.nombre,' ',u.apellido) AS empleado_nombre,
                   a.nombre                         AS area_nombre
            FROM solicitudes_ausencia sa
            JOIN tipos_ausencia ta ON ta.id = sa.tipo_ausencia_id
            JOIN usuarios u        ON u.id  = sa.usuario_id
            LEFT JOIN areas a      ON a.id  = u.area_id
            WHERE sa.estado = 'pendiente' AND u.area_id = ?
            ORDER BY sa.created_at ASC
        ");
        $stPend->execute([$yo['area_id']]);
        $pendientes = $stPend->fetchAll();
    }
}

function estado_badge(string $estado): string {
    return match($estado) {
        'pendiente'  => '<span style="background:#fff3cd;color:#856404;padding:2px 10px;border-radius:999px;font-size:12px">⏳ Pendiente</span>',
        'aprobado'   => '<span style="background:#d1e7dd;color:#0a3622;padding:2px 10px;border-radius:999px;font-size:12px">✅ Aprobado</span>',
        'rechazado'  => '<span style="background:#f8d7da;color:#842029;padding:2px 10px;border-radius:999px;font-size:12px">❌ Rechazado</span>',
        default      => e($estado),
    };
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ausencias</title>
  <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:900px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok)  ?></div><?php endif; ?>

    <!-- ── Solicitudes pendientes para aprobar ────────────────── -->
    <?php if ($puedeAprobar && $pendientes): ?>
    <div class="card" style="border-left:4px solid #f59e0b;margin-bottom:14px">
        <h3 style="margin:0 0 12px;color:#92400e">
            ⏳ Solicitudes pendientes
            <?= $esAdminRrhh ? '(todas las áreas)' : '(tu área)' ?>
            — <?= count($pendientes) ?>
        </h3>

        <?php foreach ($pendientes as $p): ?>
        <div style="border-bottom:1px solid #fde68a;padding:12px 0;
                    display:flex;justify-content:space-between;
                    align-items:flex-start;flex-wrap:wrap;gap:12px">
            <div>
                <strong><?= e($p['empleado_nombre']) ?></strong>
                <?php if ($esAdminRrhh && $p['area_nombre']): ?>
                    <span style="background:#e8f0fe;color:#1a56db;padding:1px 8px;
                                 border-radius:999px;font-size:11px;margin-left:6px">
                        <?= e($p['area_nombre']) ?>
                    </span>
                <?php endif; ?>
                <div class="meta" style="margin-top:4px">
                    📋 <?= e($p['tipo_nombre']) ?> &nbsp;·&nbsp;
                    🗓 <?= e(fmt_fecha($p['inicio'])) ?> → <?= e(fmt_fecha($p['fin'])) ?>
                </div>
                <?php if ($p['comentario']): ?>
                    <div class="meta" style="margin-top:4px;font-style:italic">
                        "<?= e($p['comentario']) ?>"
                    </div>
                <?php endif; ?>
                <?php if ($esAdminRrhh && isset($p['jefe_nombre']) && $p['jefe_nombre']): ?>
                    <div class="meta" style="margin-top:4px">
                        👤 Jefe directo: <?= e($p['jefe_nombre']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" style="display:flex;flex-direction:column;gap:6px;min-width:200px">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="resolver">
                <input type="hidden" name="solicitud_id" value="<?= (int)$p['id'] ?>">
                <input type="text" name="nota_aprobador"
                       placeholder="Nota (opcional)"
                       style="font-size:12px;padding:6px;border-radius:8px;border:1px solid #e5e7eb">
                <div style="display:flex;gap:6px">
                    <button name="decision" value="aprobado"
                        style="background:#0a5c2e;flex:1;padding:8px;font-size:13px">
                        ✅ Aprobar
                    </button>
                    <button name="decision" value="rechazado"
                        style="background:#842029;flex:1;padding:8px;font-size:13px">
                        ❌ Rechazar
                    </button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($puedeAprobar): ?>
    <div class="card" style="margin-bottom:14px;border-left:4px solid #0a5c2e">
        <p class="meta">✅ No hay solicitudes pendientes<?= $esAdminRrhh ? '' : ' en tu área' ?>.</p>
    </div>
    <?php endif; ?>

    <!-- ── Nueva solicitud ────────────────────────────────────── -->
    <div class="card" style="margin-bottom:14px">
        <h3 style="margin:0 0 14px">Solicitar ausencia</h3>
        <form method="post"
              style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="solicitar">

            <div style="grid-column:span 2">
                <label class="meta">Tipo de ausencia *</label>
                <select name="tipo_ausencia_id" required>
                    <option value="">Seleccionar...</option>
                    <?php foreach ($tipos as $t): ?>
                        <option value="<?= (int)$t['id'] ?>">
                            <?= e($t['nombre']) ?>
                            <?= $t['requiere_aprobacion'] ? '' : ' (sin aprobación)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="meta">Desde *</label>
                <input type="date" name="inicio" required
                       min="<?= date('Y-m-d') ?>">
            </div>

            <div>
                <label class="meta">Hasta *</label>
                <input type="date" name="fin" required
                       min="<?= date('Y-m-d') ?>">
            </div>

            <div style="grid-column:span 2">
                <label class="meta">Comentario / Motivo</label>
                <textarea name="comentario" rows="2"
                    placeholder="Detallá el motivo de tu solicitud..."></textarea>
            </div>

            <div style="grid-column:span 2">
                <button type="submit">Enviar solicitud</button>
            </div>
        </form>
    </div>

    <!-- ── Mis solicitudes ────────────────────────────────────── -->
    <div class="card">
        <h3 style="margin:0 0 12px">Mis solicitudes</h3>

        <?php if (!$misSols): ?>
            <p class="meta">Todavía no hiciste ninguna solicitud.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Estado</th>
                    <th>Aprobado por</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($misSols as $s): ?>
                <tr>
                    <td><?= e($s['tipo_nombre']) ?></td>
                    <td><?= e(fmt_fecha($s['inicio'])) ?></td>
                    <td><?= e(fmt_fecha($s['fin'])) ?></td>
                    <td><?= estado_badge($s['estado']) ?></td>
                    <td class="meta">
                        <?php if ($s['aprobador_nombre']): ?>
                            <?= e($s['aprobador_nombre']) ?>
                            <?php if ($s['aprobado_en']): ?>
                                <br><?= e(fmt_fecha($s['aprobado_en'], true)) ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $s['estado'] === 'pendiente'
                                ? '<span style="color:#856404">Esperando aprobación</span>'
                                : '—' ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>