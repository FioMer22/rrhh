<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_login();

header('Content-Type: application/json');
$pdo = DB::pdo();
$uid = (int)$_SESSION['uid'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Suscripción inválida']);
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO push_suscripciones (usuario_id, endpoint, p256dh, auth, user_agent)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            usuario_id = VALUES(usuario_id),
            p256dh     = VALUES(p256dh),
            auth       = VALUES(auth),
            updated_at = NOW()
    ")->execute([
        $uid,
        $data['endpoint'],
        $data['keys']['p256dh'],
        $data['keys']['auth'],
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}