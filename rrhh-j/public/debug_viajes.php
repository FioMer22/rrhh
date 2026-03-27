<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_login();
require_once __DIR__ . '/../app/helpers/utils.php';

$pdo = DB::pdo();

// Ver todos los viajes del usuario 1 con sus campos
$st = $pdo->prepare("
    SELECT id, usuario_id, titulo, destino, estado,
           fecha_inicio, fecha_fin,
           inicio_dt, fin_dt
    FROM viajes
    WHERE usuario_id = 1
    ORDER BY id DESC
");
$st->execute();
$rows = $st->fetchAll();

header('Content-Type: text/plain');
foreach ($rows as $r) {
    echo "ID:{$r['id']} | estado:{$r['estado']} | titulo:{$r['titulo']}\n";
    echo "  fecha_inicio:{$r['fecha_inicio']} | fecha_fin:{$r['fecha_fin']}\n";
    echo "  inicio_dt:{$r['inicio_dt']} | fin_dt:{$r['fin_dt']}\n\n";
}