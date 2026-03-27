<?php
// app/helpers/security.php
declare(strict_types=1);

/**
 * Escape seguro para HTML.
 * Acepta cualquier tipo (int, float, null, string).
 */
if (!function_exists('e')) {
  function e($s): string {
    if ($s === null) return '';
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/**
 * Detecta HTTPS.
 */
if (!function_exists('is_https')) {
  function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
  }
}

/**
 * Inicia sesión con configuración segura.
 * $cfg viene de app.php: $app['session'].
 */
if (!function_exists('start_secure_session')) {
  function start_secure_session(array $cfg = []): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $name = (string)($cfg['name'] ?? 'APPSESSID');
    session_name($name);

    $cookieLifetime = (int)($cfg['cookie_lifetime'] ?? 0);
    $secure   = isset($cfg['secure']) && $cfg['secure'] !== null
                  ? (bool)$cfg['secure']
                  : is_https();
    $httponly = (bool)($cfg['httponly'] ?? true);
    $samesite = (string)($cfg['samesite'] ?? 'Lax'); // Lax/Strict/None

    // PHP 7.3+ soporta array con samesite
    $params = session_get_cookie_params();

    session_set_cookie_params([
      'lifetime' => $cookieLifetime,
      'path'     => $params['path'] ?? '/',
      'domain'   => $params['domain'] ?? '',
      'secure'   => $secure,
      'httponly' => $httponly,
      'samesite' => $samesite,
    ]);

    // Endurecer un poco la sesión
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', $httponly ? '1' : '0');
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    session_start();
  }
}

/**
 * Helpers de sesión.
 */
if (!function_exists('session_get')) {
  function session_get(string $key, $default = null) {
    return $_SESSION[$key] ?? $default;
  }
}

if (!function_exists('session_set')) {
  function session_set(string $key, $value): void {
    $_SESSION[$key] = $value;
  }
}

if (!function_exists('is_post')) {
  function is_post(): bool {
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
  }
}