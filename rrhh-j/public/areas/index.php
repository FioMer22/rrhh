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
$ok = $err = null;

// POST crear/editar área
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_area') {
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        if ($nombre === '') {
            $err = 'El nombre del área es obligatorio.';
        } else {
            try {
                $pdo->prepare("INSERT INTO areas (nombre, descripcion, activo) VALUES (?, ?, 1)")
                    ->execute([$nombre, $desc !== '' ? $desc : null]);
                $ok = "Área '{$nombre}' creada.";
            } catch (\Throwable) {
                $err = 'Ya existe un área con ese nombre.';
            }
        }
    }

    if ($accion === 'toggle_area') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE areas SET activo = 1 - activo WHERE id = ?")->execute([$id]);
        $ok = 'Estado del área actualizado.';
    }

    if ($accion === 'crear_equipo') {
        $nombre  = trim($_POST['nombre'] ?? '');
        $area_id = (int)($_POST['area_id'] ?? 0);
        if ($nombre === '' || $area_id <= 0) {
            $err = 'Nombre y área son obligatorios para crear un equipo.';
        } else {
            $pdo->prepare("INSERT INTO equipos (nombre, area_id, activo) VALUES (?, ?, 1)")
                ->execute([$nombre, $area_id]);
            $ok = "Equipo '{$nombre}' creado.";
        }
    }

    if ($accion === 'toggle_equipo') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE equipos SET activo = 1 - activo WHERE id = ?")->execute([$id]);
        $ok = 'Estado del equipo actualizado.';
    }
}

// Datos
$areas = $pdo->query("
    SELECT a.*,
        (SELECT COUNT(*) FROM usuarios u WHERE u.area_id = a.id AND u.activo = 1) AS total_usuarios,
        (SELECT COUNT(*) FROM equipos e WHERE e.area_id = a.id AND e.activo = 1) AS total_equipos
    FROM areas a
    ORDER BY a.activo DESC, a.nombre ASC
")->fetchAll();

$equipos = $pdo->query("
    SELECT e.*,
        a.nombre AS area_nombre,
        (SELECT COUNT(*) FROM usuarios u WHERE u.equipo_id = e.id AND u.activo = 1) AS total_usuarios
    FROM equipos e
    JOIN areas a ON a.id = e.area_id
    ORDER BY e.activo DESC, a.nombre ASC, e.nombre ASC
")->fetchAll();

$areasActivas = array_filter($areas, fn($a) => (int)$a['activo'] === 1);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Áreas y Equipos</title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
    <style>
        .dos-col { display:grid; grid-template-columns:1fr 1fr; gap:14px }
        @media(max-width:700px){ .dos-col{grid-template-columns:1fr} }
        .area-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #eee; flex-wrap:wrap; gap:8px }
        .area-row:last-child { border-bottom:none }
        .stat { font-size:12px; color:#6b7280; margin-left:6px }
        .inactivo { opacity:.45 }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_layout.php'; ?>

<div class="wrap">

    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

    <div class="dos-col">

        <!-- ÁREAS -->
        <div>
            <div class="card">
                <h2>Áreas</h2>
                <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="crear_area">
                    <input type="text" name="nombre" required placeholder="Nombre del área" style="flex:1;min-width:150px">
                    <input type="text" name="descripcion" placeholder="Descripción (opcional)" style="flex:2;min-width:150px">
                    <button type="submit" style="white-space:nowrap">+ Crear área</button>
                </form>

                <?php foreach ($areas as $a): ?>
                <div class="area-row <?= (int)$a['activo'] === 0 ? 'inactivo' : '' ?>">
                    <div>
                        <strong><?= e($a['nombre']) ?></strong>
                        <span class="stat">👥 <?= (int)$a['total_usuarios'] ?> usuarios</span>
                        <span class="stat">🗂 <?= (int)$a['total_equipos'] ?> equipos</span>
                        <?php if ($a['descripcion']): ?>
                            <div class="meta"><?= e($a['descripcion']) ?></div>
                        <?php endif; ?>
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="toggle_area">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" style="background:<?= (int)$a['activo'] ? '#6b7280' : '#0a5c2e' ?>;font-size:12px;padding:5px 10px">
                            <?= (int)$a['activo'] ? 'Desactivar' : 'Activar' ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>

                <?php if (!$areas): ?>
                    <p class="meta">Aún no hay áreas creadas.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- EQUIPOS -->
        <div>
            <div class="card">
                <h2>Equipos</h2>
                <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="crear_equipo">
                    <input type="text" name="nombre" required placeholder="Nombre del equipo" style="flex:1;min-width:150px">
                    <select name="area_id" required style="flex:1;min-width:150px">
                        <option value="">— Área —</option>
                        <?php foreach ($areasActivas as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="white-space:nowrap">+ Crear equipo</button>
                </form>

                <?php foreach ($equipos as $eq): ?>
                <div class="area-row <?= (int)$eq['activo'] === 0 ? 'inactivo' : '' ?>">
                    <div>
                        <strong><?= e($eq['nombre']) ?></strong>
                        <span class="meta" style="font-size:12px"> — <?= e($eq['area_nombre']) ?></span>
                        <span class="stat">👥 <?= (int)$eq['total_usuarios'] ?> usuarios</span>
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="toggle_equipo">
                        <input type="hidden" name="id" value="<?= (int)$eq['id'] ?>">
                        <button type="submit" style="background:<?= (int)$eq['activo'] ? '#6b7280' : '#0a5c2e' ?>;font-size:12px;padding:5px 10px">
                            <?= (int)$eq['activo'] ? 'Desactivar' : 'Activar' ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>

                <?php if (!$equipos): ?>
                    <p class="meta">Aún no hay equipos creados.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</body>
</html>
