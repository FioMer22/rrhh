<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../_layout.php';

if (!has_any_role('admin','rrhh')) { http_response_code(403); exit('No autorizado'); }

$usuario = [
    'id'=>0,'nombre'=>'','apellido'=>'','email'=>'','ci'=>'',
    'telefono'=>'','activo'=>1,
    'area_id'=>0,'equipo_id'=>0,'sede_id'=>0,'jefe_id'=>0,'rol_base_id'=>0,
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nuevo usuario</title>
    <link rel="stylesheet" href="<?= url('/assets/css/jr.css') ?>">
</head>
<body>
<div class="wrap" style="max-width:700px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h2>Nuevo usuario</h2>
        <a class="btn" href="<?= url('/usuarios/index.php') ?>"
           style="background:#6b7280">← Volver</a>
    </div>
    <div class="card">
        <form method="post" action="<?= url('/usuarios/guardar.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="0">
            <?php require __DIR__ . '/_form_fields.php'; ?>
            <div style="display:flex;gap:10px;margin-top:18px">
                <button type="submit">Crear usuario</button>
                <a class="btn" href="<?= url('/usuarios/index.php') ?>"
                   style="background:#6b7280">Cancelar</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
