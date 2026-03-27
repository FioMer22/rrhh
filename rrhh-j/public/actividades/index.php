<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/utils.php';

require_login();

$pdo     = DB::pdo();
$uid     = (int)$_SESSION['uid'];
$esAdmin = has_any_role('admin', 'rrhh');
$ok = $err = null;

// ── POST: crear actividad rápida ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    $tipo   = trim($_POST['tipo']   ?? 'otro');
    $titulo = trim($_POST['titulo'] ?? '');
    $ubi    = trim($_POST['ubicacion_texto'] ?? '');
    $desc   = trim($_POST['descripcion']     ?? '');
    $inicio = trim($_POST['inicio_plan']     ?? '');
    $fin    = trim($_POST['fin_plan']        ?? '');

    $tiposOk = ['visita','reunion_externa','entrega','soporte','capacitacion','otro'];
    if (!in_array($tipo, $tiposOk, true)) $tipo = 'otro';

    if ($titulo === '') {
        $err = 'El título es obligatorio.';
    } else {
        $pdo->prepare("
            INSERT INTO actividades
                (usuario_id, tipo, titulo, descripcion, ubicacion_texto,
                 inicio_plan, fin_plan, estado)
            VALUES (?,?,?,?,?,?,?,'planificada')
        ")->execute([$uid, $tipo, $titulo,
                     $desc ?: null, $ubi ?: null,
                     $inicio ?: null, $fin ?: null]);
        $ok = '✅ Actividad creada.';
    }
}

// ── POST: crear e iniciar actividad de inmediato ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_e_iniciar') {
    csrf_verify();
    $tipo   = trim($_POST['tipo']   ?? 'otro');
    $titulo = trim($_POST['titulo'] ?? '');
    $ubi    = trim($_POST['ubicacion_texto'] ?? '');
    $desc   = trim($_POST['descripcion']     ?? '');
    $inicio = trim($_POST['inicio_plan']     ?? '');
    $fin    = trim($_POST['fin_plan']        ?? '');

    $tiposOk = ['visita','reunion_externa','entrega','soporte','capacitacion','otro'];
    if (!in_array($tipo, $tiposOk, true)) $tipo = 'otro';

    if ($titulo === '') {
        $err = 'El título es obligatorio.';
    } else {
        $pdo->prepare("
            INSERT INTO actividades
                (usuario_id, tipo, titulo, descripcion, ubicacion_texto,
                 inicio_plan, fin_plan, estado, inicio_real)
            VALUES (?,?,?,?,?,?,?,'en_progreso', NOW())
        ")->execute([$uid, $tipo, $titulo,
                     $desc ?: null, $ubi ?: null,
                     $inicio ?: null, $fin ?: null]);

        $nuevoId = (int)$pdo->lastInsertId();
        $_SESSION['actividad_iniciada_id']    = $nuevoId;
        $_SESSION['actividad_iniciada_titulo'] = $titulo;

        header('Location: ' . url('/actividades/index.php?estado=en_progreso&ok=iniciada'));
        exit;
    }
}

// ── POST: iniciar actividad ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'iniciar') {
    csrf_verify();
    $actId = (int)($_POST['actividad_id'] ?? 0);
    $where = $esAdmin ? "id=?" : "id=? AND usuario_id=$uid";
    $pdo->prepare("UPDATE actividades SET estado='en_progreso', inicio_real=NOW() WHERE $where")
        ->execute([$actId]);
    $ok = '⚡ Actividad iniciada.';
}

// ── POST: finalizar actividad ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'finalizar') {
    csrf_verify();
    $actId = (int)($_POST['actividad_id'] ?? 0);
    $nota  = trim($_POST['nota_cierre'] ?? '');
    $where = $esAdmin ? "id=?" : "id=? AND usuario_id=$uid";
    $pdo->prepare("
        UPDATE actividades
        SET estado='finalizada', fin_real=NOW(),
            descripcion = CASE WHEN ? != '' AND descripcion IS NULL THEN ?
                               WHEN ? != '' THEN CONCAT(IFNULL(descripcion,''), ' | Cierre: ', ?)
                               ELSE descripcion END
        WHERE $where
    ")->execute([$nota, $nota, $nota, $nota, $actId]);
    $_SESSION['actividad_finalizada_id'] = $actId;
    $ok = '🏁 Actividad finalizada.';
}

// ── POST: cancelar ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
    csrf_verify();
    $actId = (int)($_POST['actividad_id'] ?? 0);
    $where = $esAdmin ? "id=?" : "id=? AND usuario_id=$uid";
    $pdo->prepare("UPDATE actividades SET estado='cancelada' WHERE $where")->execute([$actId]);
    $_SESSION['actividad_finalizada_id'] = $actId;
    $ok = 'Actividad cancelada.';
}

// ── Recuperar de sesión (SIEMPRE después de todos los POST) ──────────────────
$actividadRecienIniciada   = null;
$actividadRecienFinalizada = null;

if (!empty($_SESSION['actividad_iniciada_id'])) {
    $actividadRecienIniciada = [
        'id'     => (int)$_SESSION['actividad_iniciada_id'],
        'titulo' => (string)($_SESSION['actividad_iniciada_titulo'] ?? 'Actividad'),
    ];
    unset($_SESSION['actividad_iniciada_id'], $_SESSION['actividad_iniciada_titulo']);
}

if (!empty($_SESSION['actividad_finalizada_id'])) {
    $actividadRecienFinalizada = (int)$_SESSION['actividad_finalizada_id'];
    unset($_SESSION['actividad_finalizada_id']);
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$filtroEstado = $_GET['estado'] ?? 'activas';
$q            = trim($_GET['q'] ?? '');

if (!$ok && isset($_GET['ok'])) {
    $ok = match($_GET['ok']) {
        'iniciada' => '⚡ Actividad creada e iniciada.',
        default    => null,
    };
}

$where  = ["1=1"];
$params = [];

if (!$esAdmin) {
    $where[]  = "(a.usuario_id=? OR ap.usuario_id=?)";
    $params[] = $uid;
    $params[] = $uid;
}

if ($filtroEstado === 'activas') {
    $where[] = "a.estado IN ('planificada','en_progreso')";
} elseif ($filtroEstado !== '') {
    $where[] = "a.estado=?";
    $params[] = $filtroEstado;
}

if ($q !== '') {
    $where[] = "(a.titulo LIKE ? OR a.ubicacion_texto LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$whereStr = implode(' AND ', $where);
$stA = $pdo->prepare("
    SELECT DISTINCT a.*,
           CONCAT(u.nombre,' ',u.apellido) AS autor_nombre
    FROM actividades a
    JOIN usuarios u ON u.id=a.usuario_id
    LEFT JOIN actividades_participantes ap ON ap.actividad_id=a.id
    WHERE $whereStr
    ORDER BY
        FIELD(a.estado,'en_progreso','planificada','finalizada','cancelada'),
        COALESCE(a.inicio_real, a.inicio_plan, a.created_at) DESC
");
$stA->execute($params);
$actividades = $stA->fetchAll();

// ── Actividad en progreso del usuario actual ──────────────────────────────────
$enProgreso = null;
$stEP = $pdo->prepare("
    SELECT * FROM actividades
    WHERE usuario_id=? AND estado='en_progreso'
    ORDER BY inicio_real DESC LIMIT 1
");
$stEP->execute([$uid]);
$enProgreso = $stEP->fetch() ?: null;

// ── Detectar iniciada por accion=iniciar (no redirige, $enProgreso ya está) ───
if ($ok && str_contains($ok, 'iniciada') && $enProgreso && !$actividadRecienIniciada) {
    $actividadRecienIniciada = [
        'id'     => (int)$enProgreso['id'],
        'titulo' => (string)$enProgreso['titulo'],
    ];
}

// ── Helpers de vista ──────────────────────────────────────────────────────────
$tiposMap = [
    'visita'         => ['label' => 'Visita',            'emoji' => '🏠'],
    'reunion_externa'=> ['label' => 'Reunión externa',    'emoji' => '🤝'],
    'entrega'        => ['label' => 'Entrega de Víveres', 'emoji' => '🥫'],
    'soporte'        => ['label' => 'Soporte',            'emoji' => '🔧'],
    'capacitacion'   => ['label' => 'Capacitación',       'emoji' => '📚'],
    'otro'           => ['label' => 'Otro',               'emoji' => '📌'],
];

function estadoBadge(string $e): string {
    return match($e) {
        'planificada' => '<span style="background:#dbeafe;color:#1e40af;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600">📅 Planificada</span>',
        'en_progreso' => '<span style="background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600">⚡ En progreso</span>',
        'finalizada'  => '<span style="background:#e5e7eb;color:#374151;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600">✅ Finalizada</span>',
        'cancelada'   => '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600">❌ Cancelada</span>',
        default       => e($e),
    };
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Actividades</title>
    <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .act-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;
                  margin-bottom:10px;transition:box-shadow .15s}
        .act-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
        .act-card.en-progreso{border-left:4px solid #059669;background:#f0fdf4}
        .act-header{display:flex;justify-content:space-between;
                    align-items:flex-start;gap:10px;flex-wrap:wrap}
        .act-tipo{font-size:11px;color:#6b7280;margin-bottom:3px}
        .act-titulo{font-size:15px;font-weight:700}
        .act-meta{font-size:12px;color:#6b7280;margin-top:4px}
        .acciones{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:10px}
        .btn-xs{padding:6px 12px;border-radius:8px;border:none;cursor:pointer;
                font-size:12px;font-weight:600}
        .btn-ini{background:#059669;color:#fff}
        .btn-fin{background:#114c97;color:#fff}
        .btn-can{background:#f3f4f6;color:#374151}
        .prog-widget{background:linear-gradient(135deg,#064e3b,#065f46);
                     color:#fff;border-radius:14px;padding:16px;margin-bottom:14px}
        .prog-widget h4{margin:0 0 4px;font-size:16px}
        .prog-widget .meta{color:rgba(255,255,255,.7);font-size:12px}
        .form-rapido{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .form-rapido .span2{grid-column:span 2}
        @media(max-width:600px){.form-rapido{grid-template-columns:1fr}.form-rapido .span2{grid-column:1}}
        .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
        .tab{padding:7px 14px;border-radius:10px;border:1px solid #e5e7eb;
             background:#fff;cursor:pointer;font-size:13px;text-decoration:none;color:#374151}
        .tab.active{background:#114c97;color:#fff;border-color:#114c97}
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap" style="max-width:900px">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok)  ?></div><?php endif; ?>

    <!-- ── Actividad en progreso (widget rápido) ─────────────────────────── -->
    <?php if ($enProgreso): ?>
    <div class="prog-widget">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
            <div>
                <div class="meta" style="margin-bottom:3px">
                    ⚡ EN PROGRESO — iniciada
                    <?= $enProgreso['inicio_real'] ? date('H:i', strtotime($enProgreso['inicio_real'])) : 'ahora' ?>
                </div>
                <h4><?= e($enProgreso['titulo']) ?></h4>
                <?php if ($enProgreso['ubicacion_texto']): ?>
                    <div class="meta">📍 <?= e($enProgreso['ubicacion_texto']) ?></div>
                <?php endif; ?>
            </div>
            <form method="post" style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="finalizar">
                <input type="hidden" name="actividad_id" value="<?= (int)$enProgreso['id'] ?>">
                <input type="text" name="nota_cierre"
                       placeholder="Nota de cierre (opcional)"
                       style="padding:8px 12px;border-radius:10px;
                              border:1px solid rgba(255,255,255,.3);
                              background:rgba(255,255,255,.1);color:#fff;
                              font-size:12px;min-width:180px">
                <button type="submit" class="btn-xs"
                        style="background:#fff;color:#065f46;padding:8px 16px">
                    🏁 Finalizar
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Nueva actividad ───────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:14px">
        <h3 style="margin:0 0 12px">+ Nueva actividad</h3>
        <form method="post" class="form-rapido">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">
            <div>
                <label class="meta">Tipo</label>
                <select name="tipo">
                    <?php foreach ($tiposMap as $key => $t): ?>
                        <option value="<?= $key ?>"><?= $t['emoji'] ?> <?= $t['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="meta">Título *</label>
                <input type="text" name="titulo" required
                       placeholder="Ej: Visita a socio García">
            </div>
            <div class="span2">
                <label class="meta">Ubicación</label>
                <input type="text" name="ubicacion_texto"
                       placeholder="Ej: Av. España 1234, Asunción">
            </div>
            <div class="span2">
                <a href="#"
                   onclick="document.getElementById('mas-det').style.display='grid';this.style.display='none';return false"
                   style="font-size:12px;color:#6b7280">
                    ▸ Agregar más detalles (hora de inicio/fin, descripción)
                </a>
            </div>
            <div id="mas-det"
                 style="display:none;grid-column:span 2;grid-template-columns:1fr 1fr;gap:10px">
                <div>
                    <label class="meta">Inicio planificado</label>
                    <input type="datetime-local" name="inicio_plan">
                </div>
                <div>
                    <label class="meta">Fin planificado</label>
                    <input type="datetime-local" name="fin_plan">
                </div>
                <div style="grid-column:span 2">
                    <label class="meta">Descripción</label>
                    <textarea name="descripcion" rows="2" placeholder="Detalle..."></textarea>
                </div>
            </div>
            <div class="span2" style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit">Crear actividad</button>
                <button type="submit" style="background:#059669"
                        onclick="document.querySelector('[name=accion]').value='crear_e_iniciar'">
                    ⚡ Crear e iniciar ahora
                </button>
            </div>
        </form>
    </div>

    <!-- ── Tabs de filtro ────────────────────────────────────────────────── -->
    <div class="tabs">
        <?php
        $tabs = [
            'activas'     => '⚡ Activas',
            'planificada' => '📅 Planificadas',
            'en_progreso' => '🔄 En progreso',
            'finalizada'  => '✅ Finalizadas',
            'cancelada'   => '❌ Canceladas',
            ''            => '📋 Todas',
        ];
        foreach ($tabs as $val => $label):
            $active = $filtroEstado === $val ? 'active' : '';
            $href   = url('/actividades/index.php?estado=' . urlencode($val) . ($q ? '&q=' . urlencode($q) : ''));
        ?>
        <a href="<?= $href ?>" class="tab <?= $active ?>"><?= $label ?></a>
        <?php endforeach; ?>

        <?php if ($esAdmin): ?>
        <a href="<?= url('/rrhh/presencia.php') ?>"
           style="margin-left:auto;padding:7px 14px;border-radius:10px;
                  background:#f0f7ff;color:#114c97;border:1px solid #bfdbfe;
                  text-decoration:none;font-size:13px">
            👥 Ver presencia del personal
        </a>
        <?php endif; ?>
    </div>

    <!-- ── Buscador ──────────────────────────────────────────────────────── -->
    <div class="no-print" style="margin-bottom:12px">
        <form method="get" style="display:flex;gap:8px">
            <input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
            <input type="text" name="q" value="<?= e($q) ?>"
                   placeholder="Buscar actividad..." style="flex:1">
            <button type="submit">Buscar</button>
            <?php if ($q): ?>
                <a class="btn"
                   href="<?= url('/actividades/index.php?estado=' . urlencode($filtroEstado)) ?>"
                   style="background:#6b7280">✕</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="meta" style="margin-bottom:10px"><?= count($actividades) ?> actividades</div>

    <!-- ── Lista ─────────────────────────────────────────────────────────── -->
    <?php foreach ($actividades as $a):
        $tipo   = $tiposMap[$a['tipo']] ?? ['label' => $a['tipo'], 'emoji' => '📌'];
        $enProg = $a['estado'] === 'en_progreso';
    ?>
    <div class="act-card <?= $enProg ? 'en-progreso' : '' ?>">
        <div class="act-header">
            <div style="flex:1;min-width:0">
                <div class="act-tipo">
                    <?= $tipo['emoji'] ?> <?= $tipo['label'] ?>
                    <?php if ($esAdmin): ?> · <?= e($a['autor_nombre']) ?><?php endif; ?>
                </div>
                <div class="act-titulo"><?= e($a['titulo']) ?></div>
                <div class="act-meta">
                    <?php if ($a['ubicacion_texto']): ?>
                        📍 <?= e($a['ubicacion_texto']) ?> &nbsp;
                    <?php endif; ?>
                    <?php if ($a['inicio_real']): ?>
                        🕐 Inició <?= date('d/m H:i', strtotime($a['inicio_real'])) ?>
                    <?php elseif ($a['inicio_plan']): ?>
                        📅 Planif. <?= date('d/m H:i', strtotime($a['inicio_plan'])) ?>
                    <?php endif; ?>
                    <?php if ($a['fin_real']): ?>
                        → Finalizó <?= date('H:i', strtotime($a['fin_real'])) ?>
                    <?php endif; ?>
                </div>
                <?php if ($a['descripcion']): ?>
                    <div class="act-meta" style="margin-top:3px;font-style:italic">
                        <?= e(mb_strimwidth($a['descripcion'], 0, 120, '...')) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div><?= estadoBadge($a['estado']) ?></div>
        </div>

        <?php if ($a['estado'] === 'planificada'): ?>
        <div class="acciones">
            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="iniciar">
                <input type="hidden" name="actividad_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-xs btn-ini">⚡ Iniciar ahora</button>
            </form>
            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="finalizar">
                <input type="hidden" name="actividad_id" value="<?= (int)$a['id'] ?>">
                <input type="text" name="nota_cierre" placeholder="Nota (opcional)"
                       style="font-size:11px;padding:5px 10px;border-radius:8px;
                              border:1px solid #e5e7eb;min-width:140px">
                <button type="submit" class="btn-xs btn-fin">✅ Marcar completada</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="cancelar">
                <input type="hidden" name="actividad_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-xs btn-can"
                        onclick="return confirm('¿Cancelar esta actividad?')">✕ Cancelar</button>
            </form>
        </div>

        <?php elseif ($a['estado'] === 'en_progreso'): ?>
        <div class="acciones">
            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="accion"       value="finalizar">
                <input type="hidden" name="actividad_id" value="<?= (int)$a['id'] ?>">
                <input type="text" name="nota_cierre" placeholder="Nota de cierre (opcional)"
                       style="font-size:11px;padding:5px 10px;border-radius:8px;
                              border:1px solid #d1fae5;min-width:180px">
                <button type="submit" class="btn-xs btn-fin">🏁 Finalizar</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!$actividades): ?>
    <div class="card">
        <p class="meta" style="text-align:center;padding:20px">
            No hay actividades <?= $filtroEstado === 'activas' ? 'activas' : '' ?> en este momento.
        </p>
    </div>
    <?php endif; ?>

</div>

<script>
<?php if ($actividadRecienIniciada): ?>
(function() {
    function tryActividadIniciada(intentos) {
        if (window.Android && window.Android.actividadIniciada) {
            window.Android.actividadIniciada(
                <?= (int)$actividadRecienIniciada['id'] ?>,
                <?= json_encode($actividadRecienIniciada['titulo']) ?>
            );
        } else if (intentos > 0) {
            setTimeout(function() { tryActividadIniciada(intentos - 1); }, 150);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { tryActividadIniciada(5); });
    } else {
        tryActividadIniciada(5);
    }
})();
<?php endif; ?>

<?php if ($actividadRecienFinalizada): ?>
(function() {
    function tryActividadFinalizada(intentos) {
        if (window.Android && window.Android.actividadFinalizada) {
            window.Android.actividadFinalizada(<?= (int)$actividadRecienFinalizada ?>);
        } else if (intentos > 0) {
            setTimeout(function() { tryActividadFinalizada(intentos - 1); }, 150);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { tryActividadFinalizada(5); });
    } else {
        tryActividadFinalizada(5);
    }
})();
<?php endif; ?>
</script>

</body>
</html>