<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../_layout.php';

if (!has_any_role('admin','rrhh')) { http_response_code(403); exit('No autorizado'); }

$pdo = DB::pdo();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('/usuarios/index.php'); }

$st = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$st->execute([$id]);
$usuario = $st->fetch(PDO::FETCH_ASSOC);
if (!$usuario) { redirect('/usuarios/index.php'); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Editar usuario</title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
</head>
<body>
<div class="wrap" style="max-width:700px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h2>Editar usuario</h2>
        <a class="btn" href="<?= url('/usuarios/index.php') ?>"
           style="background:#6b7280">← Volver</a>
    </div>
    <div class="card">
        <form method="post" action="<?= url('/usuarios/guardar.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
            <?php require __DIR__ . '/_form_fields.php'; ?>
            <div style="display:flex;gap:10px;margin-top:18px">
                <button type="submit">Guardar cambios</button>
                <a class="btn" href="<?= url('/usuarios/index.php') ?>"
                   style="background:#6b7280">Cancelar</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
