<?php
// app/helpers/response.php
declare(strict_types=1);

function app_config(): array {
  return $GLOBALS['app'] ?? [];
}

function _starts_with(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return strncmp($haystack, $needle, strlen($needle)) === 0;
}

/**
 * Construye una URL absoluta a nivel de path (sin dominio) basada en base_url.
 *
 * url('/login.php')           -> /rrhh-jr/public/login.php
 * url('login.php')            -> /rrhh-jr/public/login.php
 * url('/usuarios/index.php')  -> /rrhh-jr/public/usuarios/index.php
 */
function url(string $path = ''): string {
  $app  = app_config();
  $base = (string)($app['base_url'] ?? '');

  // normalizar base a "/rrhh-jr/public" o "" si no hay
  $base = trim($base);
  if ($base !== '') $base = '/' . trim($base, '/');
  if ($base === '/') $base = '';

  $path = trim($path);

  // si es absoluta (http/https), no tocar
  if (preg_match('~^https?://~i', $path)) return $path;

  // normalizar path a "/algo" o "" (root)
  if ($path === '' || $path === '/') {
    return $base !== '' ? $base . '/' : '/';
  }
  $path = '/' . ltrim($path, '/');

  // si ya trae el base, no duplicar
  if ($base !== '' && _starts_with($path, $base . '/')) return $path;
  if ($base !== '' && $path === $base) return $path;

  return $base . $path;
}

/**
 * Redirección segura.
 * - Si $to es relativo ("index.php"), lo convierte con url().
 * - Si $to ya empieza con "/" ("/usuarios/index.php"), lo pasa por url() (para agregar base).
 * - Si es http(s), lo deja igual.
 */
function redirect(string $to): void {
  $to = trim($to);

  if ($to === '') $to = '/';

  if (preg_match('~^https?://~i', $to)) {
    header('Location: ' . $to);
    exit;
  }

  // Si empieza con "/" lo tratamos como ruta del "sitio"
  if ($to[0] === '/') {
    header('Location: ' . url($to));
    exit;
  }

  // Si es relativo, también lo llevamos con url()
  header('Location: ' . url('/' . $to));
  exit;
}