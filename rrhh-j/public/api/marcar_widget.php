<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../app/bootstrap.php';

// Autenticación por token (no por sesión)
$token = trim($_POST['token'] ?? '');
if (!$token) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token requerido']);
    exit;
}

$pdo = DB::pdo();

// Verificar token y obtener usuario
$stU = $pdo->prepare("
    SELECT id, nombre, apellido, sede_id, activo
    FROM usuarios
    WHERE widget_token = ? AND activo = 1
    LIMIT 1
");
$stU->execute([$token]);
$usuario = $stU->fetch();

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o usuario inactivo']);
    exit;
}

$uid  = (int)$usuario['id'];
$tipo = trim($_POST['tipo'] ?? '');
$allowed = ['inicio_jornada', 'fin_jornada'];

if (!in_array($tipo, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
    exit;
}

// Verificar can_action igual que marcar.php
function today_widget(): string { return date('Y-m-d'); }

$stLast = $pdo->prepare("
    SELECT tipo FROM asistencia_marcas
    WHERE usuario_id=? AND DATE(fecha_hora)=?
    ORDER BY fecha_hora DESC, id DESC LIMIT 1
");
$stLast->execute([$uid, today_widget()]);
$lastTipo = $stLast->fetch()['tipo'] ?? null;

// Insertar pausa_fin automáticamente
    $pdo->prepare("
        INSERT INTO asistencia_marcas
          (usuario_id, tipo, fecha_hora, origen, sede_id, tipo_jornada_id,
           metodo, lat, lng, accuracy_m, location_status, location_note, ip, user_agent, nota)
        VALUES (?,?,?,?,?,?,'widget',?,?,?,?,?,?,?,?)
    ")->execute([
        $uid,
        'pausa_fin',
        date('Y-m-d H:i:s'),
        'widget',
        $usuario['sede_id'],
        $tipoJornadaId,
        null, null, null,  // sin GPS para este automático
        'auto',
        'Cierre automático de pausa',
        $_SERVER['REMOTE_ADDR'] ?? null,
        'AndroidWidget/auto',
        'Auto-cierre por salida'
    ]);

// Misma lógica que can_action() en marcar.php
$puedeActuar = false;
if ($tipo === 'inicio_jornada' && $lastTipo === null)        $puedeActuar = true;
if ($tipo === 'fin_jornada'    && $lastTipo !== null
                               && $lastTipo !== 'fin_jornada') $puedeActuar = true;

if (!$puedeActuar) {
    $msgError = $tipo === 'inicio_jornada'
        ? 'Ya marcaste entrada hoy'
        : 'No podés marcar salida aún';
    echo json_encode(['ok' => false, 'error' => $msgError]);
    exit;
}

// Capturar datos GPS
$lat       = isset($_POST['lat'])        && $_POST['lat']        !== '' ? (float)$_POST['lat']        : null;
$lng       = isset($_POST['lng'])        && $_POST['lng']        !== '' ? (float)$_POST['lng']        : null;
$acc       = isset($_POST['accuracy_m']) && $_POST['accuracy_m'] !== '' ? (int)$_POST['accuracy_m']   : null;
$locStatus = $_POST['location_status'] ?? 'unavailable';

// Validar geofence server-side
if ($locStatus === 'ok' && $lat !== null && $lng !== null) {
    $empresaLat = -25.370632;
    $empresaLng = -57.560991;
    $radioM     = 550;

    // Fórmula de Haversine
    $R    = 6371000; // radio tierra en metros
    $dLat = deg2rad($lat - $empresaLat);
    $dLng = deg2rad($lng - $empresaLng);
    $a    = sin($dLat/2) * sin($dLat/2) +
            cos(deg2rad($empresaLat)) * cos(deg2rad($lat)) *
            sin($dLng/2) * sin($dLng/2);
    $distM = $R * 2 * atan2(sqrt($a), sqrt(1-$a));

    if ($distM > $radioM) {
        echo json_encode([
            'ok'     => false,
            'error'  => 'Fuera de rango (' . round($distM) . 'm). Acercate a la empresa para marcar.',
            'distancia_m' => round($distM),
        ]);
        exit;
    }
}

if (!in_array($locStatus, ['ok','denied','unavailable','error'], true)) $locStatus = 'error';
$locNote   = mb_substr(trim($_POST['location_note'] ?? ''), 0, 255) ?: null;

if ($locStatus === 'ok' && ($lat === null || $lng === null)) {
    $locStatus = 'error'; $lat = $lng = $acc = null;
}

// Obtener tipo_jornada_id
$tj = $pdo->query("SELECT id FROM asistencia_tipos_jornada WHERE codigo='presencial' LIMIT 1")->fetch();
$tipoJornadaId = (int)($tj['id'] ?? 0);

if ($tipoJornadaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Configuración de jornada no encontrada']);
    exit;
}

// Insertar marca — mismos campos que marcar.php
$pdo->prepare("
    INSERT INTO asistencia_marcas
      (usuario_id, tipo, fecha_hora, origen, sede_id, tipo_jornada_id,
       metodo, lat, lng, accuracy_m, location_status, location_note, ip, user_agent, nota)
    VALUES (?,?,?,?,?,?,'widget',?,?,?,?,?,?,?,?)
")->execute([
    $uid,
    $tipo,
    date('Y-m-d H:i:s'),
    'widget',
    $usuario['sede_id'],
    $tipoJornadaId,
    $lat, $lng, $acc,
    $locStatus, $locNote,
    $_SERVER['REMOTE_ADDR'] ?? null,
    'AndroidWidget/1.0',
    null
]);

$label = $tipo === 'inicio_jornada' ? 'Entrada' : 'Salida';
$hora  = date('H:i');

echo json_encode([
    'ok'      => true,
    'mensaje' => "$label registrada a las $hora",
    'tipo'    => $tipo,
    'hora'    => $hora,
]);