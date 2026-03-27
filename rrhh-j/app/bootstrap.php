<?php
// app/bootstrap.php
declare(strict_types=1);

$app = require __DIR__ . '/config/app.php';
$GLOBALS['app'] = $app;
date_default_timezone_set('America/Asuncion');

if (date('I') === '0') {
    $mes = (int)date('n');
    // Verano en Paraguay: octubre(10) a marzo(3)
    if ($mes >= 10 || $mes <= 3) {
        date_default_timezone_set('Etc/GMT+3'); // equivale a -03:00
    }
}      


/**
 * Orden importante:
 * 1) response.php primero (url(), redirect())
 * 2) security.php (e(), start_secure_session())
 * 3) csrf.php (csrf_*), db.php (DB::pdo())
 */
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/security.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/utils.php';
require_once __DIR__ . '/config/db.php';

// Iniciar sesión segura
start_secure_session($app['session'] ?? []);