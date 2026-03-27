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
$esAdmin = has_any_role('admin', 'rrhh', 'lider');
$ok = $err = null;

/*
 * Estructura real de la BD:
 *   eventos_convocatorias : id, evento_id, target_tipo(area|equipo|rol|usuario),
 *                           target_id, obligatorio, cupos, mensaje,
 *                           fecha_limite_confirmacion, created_at
 *   eventos_participantes : id, evento_id, usuario_id, origen_convocatoria_id,
 *                           estado_respuesta(pendiente|confirmado|tal_vez|no_puedo),
 *                           respondio_en, obligatorio, rol_evento_id,
 *                           turno_inicio, turno_fin, nota, created_at, updated_at
 *   eventos_checklists    : id, evento_id, area_id, titulo, estado, responsable_id,
 *                           fecha_objetivo, created_at, updated_at
 *   eventos_roles         : id, nombre, descripcion, activo, created_at
 *
 * NOTA: No existe tabla "eventos" — los eventos se identifican por evento_id
 * en las tablas relacionadas. Necesitamos crearla o bien mostrar convocatorias
 * agrupadas por evento_id. Por ahora mostramos convocatorias como "eventos".
 */

// POST: crear convocatoria (equivale a crear un evento con su primera convocatoria)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    if (!$esAdmin) { http_response_code(403); exit('No autorizado'); }

    $mensaje   = trim($_POST['mensaje'] ?? '');
    $limite    = trim($_POST['fecha_limite_confirmacion'] ?? '');
    $targetTipo = $_POST['target_tipo'] ?? 'usuario';
    $targetId   = (int)($_POST['target_id'] ?? 0);
    $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;

    $tiposValidos = ['area','equipo','rol','usuario'];
    if (!in_array($targetTipo, $tiposValidos, true)) $targetTipo = 'usuario';

    if ($targetId <= 0) {
        $err = 'Seleccioná a quién convocar.';
    } else {
        // Generamos un nuevo evento_id secuencial (máx actual + 1)
        $maxId = (int)($pdo->query("SELECT COALESCE(MAX(evento_id),0) FROM eventos_convocatorias")->fetchColumn());
        $nuevoEventoId = $maxId + 1;

        $pdo->prepare("
            INSERT INTO eventos_convocatorias
                (evento_id, target_tipo, target_id, obligatorio, mensaje, fecha_limite_confirmacion)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $nuevoEventoId, $targetTipo, $targetId, $obligatorio,
            $mensaje !== '' ? $mensaje : null,
            $limite  !== '' ? $limite  : null,
        ]);

        // Si es convocatoria a usuario directo, crear participante inmediatamente
        if ($targetTipo === 'usuario') {
            $convId = (int)$pdo->lastInsertId();
            $pdo->prepare("
                INSERT IGNORE INTO eventos_participantes
                    (evento_id, usuario_id, origen_convocatoria_id, estado_respuesta, obligatorio)
                VALUES (?, ?, ?, 'pendiente', ?)
            ")->execute([$nuevoEventoId, $targetId, $convId, $obligatorio]);
        }

        $ok = "Convocatoria #$nuevoEventoId creada.";
    }
}

// POST: responder convocatoria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'responder') {
    csrf_verify();
    $eventoId = (int)($_POST['evento_id'] ?? 0);
    $respuesta = $_POST['respuesta'] ?? '';
    $nota      = trim($_POST['nota'] ?? '');
    $validas   = ['confirmado','tal_vez','no_puedo'];

    if ($eventoId > 0 && in_array($respuesta, $validas, true)) {
        $pdo->prepare("
            UPDATE eventos_participantes
            SET estado_respuesta = ?, respondio_en = NOW(), nota = ?
            WHERE evento_id = ? AND usuario_id = ?
        ")->execute([$respuesta, $nota !== '' ? $nota : null, $eventoId, $uid]);
        $ok = 'Respuesta registrada.';
    }
}

// Mis convocatorias pendientes de respuesta
$misPendientes = $pdo->prepare("
    SELECT ep.id, ep.evento_id, ep.estado_respuesta, ep.obligatorio,
           ep.turno_inicio, ep.turno_fin,
           ec.mensaje, ec.fecha_limite_confirmacion
    FROM eventos_participantes ep
    JOIN eventos_convocatorias ec ON ec.evento_id = ep.evento_id
    WHERE ep.usuario_id = ? AND ep.estado_respuesta = 'pendiente'
    ORDER BY ec.fecha_limite_confirmacion ASC, ep.created_at ASC
    LIMIT 20
");
$misPendientes->execute([$uid]);
$misPendientes = $misPendientes->fetchAll();

// Mis respuestas históricas
$misRespuestas = $pdo->prepare("
    SELECT ep.evento_id, ep.estado_respuesta, ep.respondio_en, ep.nota,
           ec.mensaje, ec.fecha_limite_confirmacion
    FROM eventos_participantes ep
    JOIN eventos_convocatorias ec ON ec.evento_id = ep.evento_id
    WHERE ep.usuario_id = ? AND ep.estado_respuesta != 'pendiente'
    ORDER BY ep.updated_at DESC
    LIMIT 30
");
$misRespuestas->execute([$uid]);
$misRespuestas = $misRespuestas->fetchAll();

// Para admin: resumen de convocatorias activas
$resumen = [];
if ($esAdmin) {
    $resumen = $pdo->query("
        SELECT ec.evento_id,
               ec.target_tipo, ec.target_id, ec.obligatorio,
               ec.mensaje, ec.fecha_limite_confirmacion, ec.created_at,
               COUNT(ep.id)                                           AS total,
               SUM(ep.estado_respuesta = 'confirmado')                AS confirmados,
               SUM(ep.estado_respuesta = 'pendiente')                 AS pendientes,
               SUM(ep.estado_respuesta = 'no_puedo')                  AS no_pueden
        FROM eventos_convocatorias ec
        LEFT JOIN eventos_participantes ep ON ep.evento_id = ec.evento_id
        GROUP BY ec.id
        ORDER BY ec.created_at DESC
        LIMIT 40
    ")->fetchAll();
}

// Listas para el formulario
$usuarios = $pdo->query("SELECT id, CONCAT(nombre,' ',apellido,' — ',email) AS label FROM usuarios WHERE activo=1 ORDER BY nombre,apellido")->fetchAll();
$areas    = $pdo->query("SELECT id, nombre FROM areas WHERE activo=1 ORDER BY nombre")->fetchAll();
$equipos  = $pdo->query("SELECT id, nombre FROM equipos WHERE activo=1 ORDER BY nombre")->fetchAll();
$roles    = $pdo->query("SELECT id, nombre FROM roles_sistema ORDER BY nombre")->fetchAll();

function respuesta_badge(string $r): string {
    return match($r) {
        'confirmado' => '<span style="background:#d1e7dd;color:#0a3622;padding:2px 8px;border-radius:999px;font-size:12px">✅ Confirmado</span>',
        'tal_vez'    => '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:999px;font-size:12px">🤔 Tal vez</span>',
        'no_puedo'   => '<span style="background:#f8d7da;color:#842029;padding:2px 8px;border-radius:999px;font-size:12px">❌ No puedo</span>',
        'pendiente'  => '<span style="background:#e2e3e5;color:#41464b;padding:2px 8px;border-radius:999px;font-size:12px">⏳ Pendiente</span>',
        default      => e($r),
    };
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Eventos y Convocatorias</title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .dos-col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        @media(max-width:700px){.dos-col{grid-template-columns:1fr}}
        #target-selector > div { display:none }
        #target-selector > div.visible { display:block }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:1100px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

    <!-- ── Mis convocatorias pendientes ─────────────────────────────── -->
    <?php if ($misPendientes): ?>
    <div class="card" style="border-left:4px solid #f59e0b">
        <h3>📣 Tenés <?= count($misPendientes) ?> convocatoria(s) sin responder</h3>
        <?php foreach ($misPendientes as $cp): ?>
        <div style="border-bottom:1px solid #eee;padding:12px 0">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
                <div>
                    <strong>Convocatoria #<?= (int)$cp['evento_id'] ?></strong>
                    <?= $cp['obligatorio'] ? ' <span style="color:#842029;font-size:12px">● Obligatoria</span>' : '' ?>
                    <?php if ($cp['mensaje']): ?>
                        <p class="meta"><?= e(mb_substr($cp['mensaje'],0,200)) ?></p>
                    <?php endif; ?>
                    <?php if ($cp['fecha_limite_confirmacion']): ?>
                        <p class="meta">⏰ Límite: <?= e(date('d/m/Y H:i', strtotime($cp['fecha_limite_confirmacion']))) ?></p>
                    <?php endif; ?>
                </div>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion"    value="responder">
                    <input type="hidden" name="evento_id" value="<?= (int)$cp['evento_id'] ?>">
                    <input type="text"   name="nota"      placeholder="Nota opcional" style="width:160px;padding:7px">
                    <button name="respuesta" value="confirmado" style="background:#0a5c2e;padding:7px 10px;font-size:13px">✅ Confirmo</button>
                    <button name="respuesta" value="tal_vez"    style="background:#856404;padding:7px 10px;font-size:13px">🤔 Tal vez</button>
                    <button name="respuesta" value="no_puedo"   style="background:#842029;padding:7px 10px;font-size:13px">❌ No puedo</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Crear convocatoria (admin) ───────────────────────────────── -->
    <?php if ($esAdmin): ?>
    <div class="card">
        <h2>Nueva convocatoria</h2>
        <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">

            <div>
                <label class="meta">Convocar a (tipo)</label>
                <select name="target_tipo" id="target_tipo" onchange="cambiarTarget(this.value)">
                    <option value="usuario">Usuario específico</option>
                    <option value="area">Área completa</option>
                    <option value="equipo">Equipo</option>
                    <option value="rol">Rol</option>
                </select>
            </div>

            <div id="target-selector">
                <div id="sel-usuario" class="visible">
                    <label class="meta">Usuario</label>
                    <select name="target_id_usuario">
                        <option value="0">— seleccionar —</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="sel-area">
                    <label class="meta">Área</label>
                    <select name="target_id_area">
                        <option value="0">— seleccionar —</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="sel-equipo">
                    <label class="meta">Equipo</label>
                    <select name="target_id_equipo">
                        <option value="0">— seleccionar —</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="sel-rol">
                    <label class="meta">Rol</label>
                    <select name="target_id_rol">
                        <option value="0">— seleccionar —</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= e($r['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="meta">Límite de confirmación (opcional)</label>
                <input type="datetime-local" name="fecha_limite_confirmacion">
            </div>

            <div style="grid-column:span 2">
                <label class="meta">Mensaje / descripción</label>
                <textarea name="mensaje" rows="2" placeholder="Describí el evento o actividad convocada..."></textarea>
            </div>

            <div style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="obligatorio" id="oblig" value="1" checked>
                <label for="oblig" class="meta">Asistencia obligatoria</label>
            </div>

            <div style="display:flex;align-items:flex-end">
                <button type="submit">Crear convocatoria</button>
            </div>
        </form>
    </div>

    <!-- Resumen convocatorias -->
    <div class="card">
        <h3>Convocatorias recientes</h3>
        <table>
            <thead><tr><th>#</th><th>A quién</th><th>Mensaje</th><th>Límite</th><th>Total</th><th>✅</th><th>⏳</th><th>❌</th><th>Creada</th></tr></thead>
            <tbody>
            <?php foreach ($resumen as $r): ?>
                <tr>
                    <td><?= (int)$r['evento_id'] ?></td>
                    <td><span class="pill"><?= e($r['target_tipo']) ?></span> #<?= (int)$r['target_id'] ?></td>
                    <td><?= e(mb_substr($r['mensaje'] ?? '—', 0, 60)) ?></td>
                    <td class="meta"><?= $r['fecha_limite_confirmacion'] ? e(date('d/m/Y H:i', strtotime($r['fecha_limite_confirmacion']))) : '—' ?></td>
                    <td style="text-align:center"><?= (int)$r['total'] ?></td>
                    <td style="text-align:center;color:#0a5c2e"><strong><?= (int)$r['confirmados'] ?></strong></td>
                    <td style="text-align:center;color:#856404"><?= (int)$r['pendientes'] ?></td>
                    <td style="text-align:center;color:#842029"><?= (int)$r['no_pueden'] ?></td>
                    <td class="meta"><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$resumen): ?><tr><td colspan="9" class="meta">Sin convocatorias aún.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Mis respuestas ───────────────────────────────────────────── -->
    <?php if ($misRespuestas): ?>
    <div class="card">
        <h3>Mis respuestas anteriores</h3>
        <table>
            <thead><tr><th>#</th><th>Mensaje</th><th>Mi respuesta</th><th>Nota</th><th>Respondido</th></tr></thead>
            <tbody>
            <?php foreach ($misRespuestas as $mr): ?>
                <tr>
                    <td><?= (int)$mr['evento_id'] ?></td>
                    <td><?= e(mb_substr($mr['mensaje'] ?? '—', 0, 80)) ?></td>
                    <td><?= respuesta_badge($mr['estado_respuesta']) ?></td>
                    <td><?= e($mr['nota'] ?? '—') ?></td>
                    <td class="meta"><?= $mr['respondio_en'] ? e(date('d/m/Y H:i', strtotime($mr['respondio_en']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
function cambiarTarget(tipo) {
    ['usuario','area','equipo','rol'].forEach(t => {
        const el = document.getElementById('sel-' + t);
        if (el) el.classList.toggle('visible', t === tipo);
    });
    // Mover el valor al campo target_id que usa el form
    document.querySelectorAll('[name^="target_id_"]').forEach(sel => {
        sel.name = sel.id.replace('sel-','') === tipo
            ? 'target_id'
            : 'target_id_' + sel.id.replace('sel-','');
    });
}
// Inicializar
cambiarTarget(document.getElementById('target_tipo')?.value || 'usuario');
</script>
</body>
</html>
