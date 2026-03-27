<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_login();
require_once __DIR__ . '/../app/helpers/push.php';
header('Content-Type: application/json');
echo json_encode(['publicKey' => VAPID_PUBLIC]);