<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/utils.php';
require_login();

$pdo = DB::pdo();

echo "PDO OK<br>";

// Test 1: query viajes
try {
    $st = $pdo->prepare("
        SELECT v.id, v.usuario_id, v.inicio_dt, v.fin_dt, v.titulo, v.destino,
               0 AS min_descanso
        FROM viajes v
        WHERE v.estado IN ('aprobado','en_curso','completado','finalizado')
          AND v.inicio_dt <= '2026-03-31 23:59:59'
          AND v.fin_dt    >= '2026-03-01 00:00:00'
    ");
    $st->execute();
    echo "Query viajes OK: " . count($st->fetchAll()) . " registros<br>";
} catch(Exception $e) {
    echo "ERROR viajes: " . $e->getMessage() . "<br>";
}

// Test 2: query actividades
try {
    $st = $pdo->prepare("
        SELECT usuario_id, titulo, tipo,
               DATE(COALESCE(inicio_real, inicio_plan)) AS fecha,
               COALESCE(inicio_real, inicio_plan) AS inicio,
               COALESCE(fin_real, fin_plan) AS fin
        FROM actividades
        WHERE estado IN ('completada','en_curso','finalizada','en_progreso','planificada')
          AND COALESCE(inicio_real, inicio_plan) >= '2026-03-01 00:00:00'
          AND COALESCE(inicio_real, inicio_plan) <= '2026-03-31 23:59:59'
    ");
    $st->execute();
    echo "Query actividades OK: " . count($st->fetchAll()) . " registros<br>";
} catch(Exception $e) {
    echo "ERROR actividades: " . $e->getMessage() . "<br>";
}

echo "Todo OK - el problema es en otra parte del informe.php";