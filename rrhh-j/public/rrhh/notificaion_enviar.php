<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';
require_once __DIR__ . '/../../app/helpers/utils.php';
require_once __DIR__ . '/../../app/helpers/push.php';
require_once __DIR__ . '/../../app/helpers/notificaciones.php';
require_login();
require_role('admin', 'rrhh');
$pdo = DB::pdo();
$ok = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $titulo       = trim($_POST['titulo']       ?? '');
    $cuerpo       = trim($_POST['cuerpo']       ?? '');
    $destinatario = $_POST['destinatario']      ?? 'todos';
    $usuario_id   = (int)($_POST['usuario_id']  ?? 0);
    $url          = trim($_POST['url']          ?? '/rrhh-j/public/dashboard.php');
    if (!$titulo || !$cuerpo) {
        $err = 'El título y el mensaje son obligatorios.';
    } else {
        if ($destinatario === 'todos') {
            $st  = $pdo->query("SELECT id FROM usuarios WHERE activo = 1");
            $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
        } elseif ($destinatario === 'admins') {
            $st  = $pdo->query("SELECT DISTINCT ur.usuario_id AS id FROM usuarios_roles ur JOIN roles_sistema r ON r.id = ur.rol_id WHERE r.nombre IN ('admin','rrhh')");
            $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
        } elseif ($destinatario === 'uno' && $usuario_id > 0) {
            $ids = [$usuario_id];
        } else {
            $ids = [];
            $err = 'Seleccioná un destinatario válido.';
        }
        if ($ids && !$err) {
            push_notificar($pdo, $ids, $titulo, $cuerpo, $url ?: '/rrhh-j/public/dashboard.php');
            foreach ($ids as $id) {
                crear_notificacion($pdo, $id, 'anuncio', $titulo, $cuerpo, (int)$_SESSION['uid'], 0);
            }
            $ok = '✅ Notificación enviada a ' . count($ids) . ' usuario(s).';
        }
    }
}
$usuarios = $pdo->query("
    SELECT u.id, u.nombre, u.apellido, a.nombre AS area
    FROM usuarios u LEFT JOIN areas a ON a.id = u.area_id
    WHERE u.activo = 1 ORDER BY a.nombre, u.apellido, u.nombre
")->fetchAll();
$totalSubs    = (int)$pdo->query("SELECT COUNT(*) FROM push_suscripciones")->fetchColumn();
$totalConPush = (int)$pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM push_suscripciones")->fetchColumn();
$totalUsuarios = count($usuarios);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enviar Notificación</title>
    <?php require_once __DIR__ . '/../_pwa_head.php'; ?>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .form-card { max-width: 600px; margin: 0 auto; }
        .dest-options { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .dest-btn {
            padding: 8px 16px !important;
            border-radius: 10px !important;
            border: 2px solid #e5e7eb !important;
            cursor: pointer;
            font-size: 14px !important;
            font-weight: 500 !important;
            background: #f9fafb !important;
            color: #374151 !important;
            transition: all .15s;
        }
        .dest-btn:hover {
            border-color: #9ca3af !important;
            background: #f3f4f6 !important;
            color: #111827 !important;
        }
        .dest-btn.active {
            border-color: #3b82f6 !important;
            background: #eff6ff !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
        }
        .stat-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f0f9ff; border: 1px solid #bae6fd;
            color: #0369a1; padding: 4px 12px; border-radius: 999px; font-size: 13px;
        }
        #campo_usuario { display: none; margin-top: 10px; }
        textarea { min-height: 100px; resize: vertical; }
        .preview {
            background: #1a1a2e; color: #fff; border-radius: 12px;
            padding: 14px 16px; margin-top: 16px; font-size: 13px;
        }
        .preview-title { font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .preview-icon { width: 32px; height: 32px; border-radius: 8px; background: #0b1f3a; }
        .preview-body { color: #ccc; font-size: 12px; }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>
<div class="wrap" style="max-width:700px">
    <div style="margin-bottom:16px">
        <h2 style="margin:0 0 4px">📣 Enviar Notificación</h2>
        <p class="meta">Enviá una notificación push + interna a los empleados.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <span class="stat-chip">📱 <?= $totalSubs ?> dispositivos suscritos (<?= $totalConPush ?> usuarios)</span>
            <span class="stat-chip">👥 <?= $totalUsuarios ?> empleados activos</span>
        </div>
    </div>
    <?php if ($ok): ?>
        <div class="alert ok" style="margin-bottom:14px"><?= e($ok) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert err" style="margin-bottom:14px"><?= e($err) ?></div>
    <?php endif; ?>
    <div class="card form-card">
        <form method="post" id="formNotif">
            <?= csrf_field() ?>
            <div style="margin-bottom:14px">
                <label class="meta">Destinatario</label>
                <div class="dest-options">
                    <button type="button" class="dest-btn active" onclick="setDest('todos')" id="btn_todos">
                        👥 Todos los empleados
                    </button>
                    <button type="button" class="dest-btn" onclick="setDest('admins')" id="btn_admins">
                        🔑 Solo Admin / RRHH
                    </button>
                    <button type="button" class="dest-btn" onclick="setDest('uno')" id="btn_uno">
                        👤 Un empleado específico
                    </button>
                </div>
                <input type="hidden" name="destinatario" id="destinatario" value="todos">
                <div id="campo_usuario">
                    <select name="usuario_id" style="width:100%">
                        <option value="">— Seleccioná un empleado —</option>
                        <?php
                        $areaActual = null;
                        foreach ($usuarios as $u):
                            if ($u['area'] !== $areaActual) {
                                if ($areaActual !== null) echo '</optgroup>';
                                $areaActual = $u['area'];
                                echo '<optgroup label="' . e($areaActual ?: 'Sin área') . '">';
                            }
                        ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($areaActual !== null) echo '</optgroup>'; ?>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px">
                <label class="meta">Título *</label>
                <input type="text" name="titulo" id="titulo" maxlength="80" required
                       placeholder="Ej: 📢 Reunión mañana a las 9:00"
                       oninput="actualizarPreview()"
                       value="<?= e($_POST['titulo'] ?? '') ?>">
            </div>
            <div style="margin-bottom:12px">
                <label class="meta">Mensaje *</label>
                <textarea name="cuerpo" id="cuerpo" maxlength="200" required
                          placeholder="Escribí el mensaje aquí..."
                          oninput="actualizarPreview()"><?= e($_POST['cuerpo'] ?? '') ?></textarea>
                <div class="meta" id="charCount" style="text-align:right">0 / 200</div>
            </div>
            <div style="margin-bottom:16px">
                <label class="meta">URL al tocar la notificación (opcional)</label>
                <select name="url">
                    <option value="/rrhh-j/public/dashboard.php">Dashboard</option>
                    <option value="/rrhh-j/public/asistencia/marcar.php">Marcar asistencia</option>
                    <option value="/rrhh-j/public/ausencias/index.php">Ausencias</option>
                    <option value="/rrhh-j/public/actividades/index.php">Actividades</option>
                    <option value="/rrhh-j/public/notificaciones.php">Notificaciones</option>
                </select>
            </div>
            <div class="preview" id="preview" style="display:none">
                <div style="font-size:11px;color:#888;margin-bottom:8px">Vista previa de la notificación:</div>
                <div class="preview-title">
                    <img src="<?= url('/assets/img/jr-icon-192.png') ?>" class="preview-icon" onerror="this.style.display='none'">
                    <span id="prev_titulo">Título</span>
                </div>
                <div class="preview-body" id="prev_cuerpo">Mensaje...</div>
            </div>
            <div style="margin-top:16px;display:flex;gap:10px">
                <button type="submit" style="background:#0b1f3a">📣 Enviar notificación</button>
                <a href="<?= url('/dashboard.php') ?>" class="btn" style="background:#6b7280">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<script>
function setDest(tipo) {
    document.getElementById('destinatario').value = tipo;
    ['todos','admins','uno'].forEach(t => {
        document.getElementById('btn_' + t).classList.toggle('active', t === tipo);
    });
    document.getElementById('campo_usuario').style.display = tipo === 'uno' ? 'block' : 'none';
}

function actualizarPreview() {
    const t     = document.getElementById('titulo').value;
    const c     = document.getElementById('cuerpo').value;
    const prev  = document.getElementById('preview');
    const count = document.getElementById('charCount');
    count.textContent = c.length + ' / 200';
    if (t || c) {
        prev.style.display = 'block';
        document.getElementById('prev_titulo').textContent = t || 'Título';
        document.getElementById('prev_cuerpo').textContent = c || 'Mensaje...';
    } else {
        prev.style.display = 'none';
    }
}
</script>
</body>
</html>