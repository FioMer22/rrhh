<?php
declare(strict_types=1);

/**
 * public/asistencia/marcar_api.php
 * Endpoint REST para la app Android nativa.
 * Autenticación por token simple (no sesión).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';

// ── Autenticación por token ──────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
}

if ($token === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token requerido.']);
    exit;
}

$pdo = DB::pdo();

// Buscar usuario por token (columna api_token en tabla usuarios)
$st = $pdo->prepare("SELECT id, sede_id FROM usuarios WHERE api_token = ? AND activo = 1 LIMIT 1");
$st->execute([$token]);
$usuario = $st->fetch();

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token inválido o usuario inactivo.']);
    exit;
}

$uid    = (int)$usuario['id'];
$sedeId = $usuario['sede_id'] ?? null;

// ── Leer body JSON ───────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Body JSON inválido.']);
    exit;
}

// ── Misma lógica que marcar.php ──────────────────────────────────────────────
function today_date_api(): string { return date('Y-m-d'); }

function last_mark_today_api(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("
        SELECT id, tipo, fecha_hora FROM asistencia_marcas
        WHERE usuario_id = ? AND DATE(fecha_hora) = ?
        ORDER BY fecha_hora DESC, id DESC LIMIT 1
    ");
    $st->execute([$uid, today_date_api()]);
    return $st->fetch() ?: null;
}

function can_action_api(?string $lastTipo, string $action): bool {
    if ($lastTipo === null)               return $action === 'inicio_jornada';
    if ($lastTipo === 'inicio_jornada')   return in_array($action, ['pausa_inicio', 'fin_jornada'], true);
    if ($lastTipo === 'pausa_inicio')     return $action === 'pausa_fin';
    if ($lastTipo === 'pausa_fin')        return in_array($action, ['pausa_inicio', 'fin_jornada'], true);
    if ($lastTipo === 'fin_jornada')      return false;
    return false;
}

function tipo_label_api(string $tipo): string {
    return match ($tipo) {
        'inicio_jornada' => 'Entrada',
        'pausa_inicio'   => 'Inicio almuerzo',
        'pausa_fin'      => 'Fin almuerzo',
        'fin_jornada'    => 'Salida',
        default          => $tipo,
    };
}

$last     = last_mark_today_api($pdo, $uid);
$lastTipo = $last['tipo'] ?? null;

$tipo = (string)($body['tipo'] ?? '');
$allowed = ['inicio_jornada', 'pausa_inicio', 'pausa_fin', 'fin_jornada'];

if (!in_array($tipo, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipo inválido.']);
    exit;
}

if (!can_action_api($lastTipo, $tipo)) {
    http_response_code(409);
    echo json_encode([
        'success'    => false,
        'message'    => 'Acción no permitida. Última marca: ' . ($lastTipo ?? 'ninguna'),
        'last_tipo'  => $lastTipo,
    ]);
    exit;
}

// Hora Paraguay desde PHP (igual que el original)
$fechaHora = date('Y-m-d H:i:s');

$nota      = trim((string)($body['nota'] ?? '')) ?: null;
$locStatus = (string)($body['location_status'] ?? 'unavailable');
if (!in_array($locStatus, ['ok', 'denied', 'unavailable', 'error'], true)) $locStatus = 'error';

$lat = $lng = $acc = null;
$locNote = trim((string)($body['location_note'] ?? '')) ?: null;

if ($locStatus === 'ok') {
    $lat = isset($body['lat']) ? (float)$body['lat'] : null;
    $lng = isset($body['lng']) ? (float)$body['lng'] : null;
    $acc = isset($body['accuracy_m']) ? (int)$body['accuracy_m'] : null;

    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $locStatus = 'error';
        $locNote   = 'Coordenadas inválidas.';
        $lat = $lng = $acc = null;
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$tj = $pdo->query("SELECT id FROM asistencia_tipos_jornada WHERE codigo='presencial' LIMIT 1")->fetch();
$tipoJornadaId = (int)($tj['id'] ?? 0);

if ($tipoJornadaId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuración incompleta en servidor.']);
    exit;
}

$pdo->prepare("
    INSERT INTO asistencia_marcas
      (usuario_id, tipo, fecha_hora, origen, sede_id, tipo_jornada_id, metodo,
       lat, lng, accuracy_m, location_status, location_note, ip, user_agent, nota)
    VALUES (?, ?, ?, 'sede', ?, ?, 'android', ?, ?, ?, ?, ?, ?, ?, ?)
")->execute([
    $uid, $tipo, $fechaHora, $sedeId, $tipoJornadaId,
    $lat, $lng, $acc, $locStatus, $locNote, $ip, $ua, $nota
]);

$insertId = (int)$pdo->lastInsertId();

echo json_encode([
    'success'   => true,
    'message'   => 'Registrado: ' . tipo_label_api($tipo),
    'id'        => $insertId,
    'tipo'      => $tipo,
    'fecha_hora'=> $fechaHora,
    'last_tipo' => $tipo,
]);