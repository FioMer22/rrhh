<?php
/**
 * cron_recordatorios.php
 * Envía recordatorios de marcar asistencia a todos los empleados activos.
 * 
 * Configurar en cPanel Cron Jobs:
 *   07:20 lun-vie → recordatorio entrada
 *   17:25 lun-vie → recordatorio salida
 * 
 * Comando cron:
 *   /usr/bin/php /www/htdocs/w00dc982/new-site/rrhh-j/scripts/cron_recordatorios.php entrada
 *   /usr/bin/php /www/htdocs/w00dc982/new-site/rrhh-j/scripts/cron_recordatorios.php salida
 */
declare(strict_types=1);

// Solo ejecutar en días hábiles (lunes a viernes)
$dow = (int)date('N'); // 1=lun ... 7=dom
if ($dow > 5) {
    echo "Fin de semana, sin recordatorio.\n";
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/helpers/push.php';

$pdo  = DB::pdo();
$tipo = $argv[1] ?? 'entrada'; // 'entrada' o 'salida'
$hoy  = date('Y-m-d');

if ($tipo === 'entrada') {
    // Solo notificar a quienes AÚN no marcaron entrada hoy
    $st = $pdo->query("
        SELECT DISTINCT u.id
        FROM usuarios u
        LEFT JOIN asistencia_marcas am
            ON am.usuario_id = u.id
            AND am.tipo = 'inicio_jornada'
            AND DATE(am.fecha_hora) = '$hoy'
        WHERE u.activo = 1
          AND am.id IS NULL
    ");
    $ids    = array_map('intval', array_column($st->fetchAll(), 'id'));
    $titulo = '⏰ Recordatorio de entrada';
    $cuerpo = 'No olvides marcar tu llegada. Jornada: 07:30 - 17:30.';
    $url    = '/rrhh-j/public/asistencia/marcar.php';

} else {
    // Solo notificar a quienes marcaron entrada pero AÚN no marcaron salida hoy
    $st = $pdo->query("
        SELECT DISTINCT u.id
        FROM usuarios u
        JOIN asistencia_marcas am_e
            ON am_e.usuario_id = u.id
            AND am_e.tipo = 'inicio_jornada'
            AND DATE(am_e.fecha_hora) = '$hoy'
        LEFT JOIN asistencia_marcas am_s
            ON am_s.usuario_id = u.id
            AND am_s.tipo = 'fin_jornada'
            AND DATE(am_s.fecha_hora) = '$hoy'
        WHERE u.activo = 1
          AND am_s.id IS NULL
    ");
    $ids    = array_map('intval', array_column($st->fetchAll(), 'id'));
    $titulo = '🏁 Recordatorio de salida';
    $cuerpo = 'No olvides marcar tu salida antes de irte.';
    $url    = '/rrhh-j/public/asistencia/marcar.php';
}

if (empty($ids)) {
    echo "Sin empleados para notificar ($tipo).\n";
    exit;
}

echo "Enviando recordatorio de $tipo a " . count($ids) . " empleados...\n";
push_notificar($pdo, $ids, $titulo, $cuerpo, $url);
echo "✅ Listo — " . date('Y-m-d H:i:s') . "\n";