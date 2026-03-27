<?php
declare(strict_types=1);

return [
  'app_name' => 'RRHH Platform',
  'timezone' => 'America/Asuncion',

  // 👇 IMPORTANTE
  'base_url' => '/rrhh-j/public',

  'session' => [
    'name' => 'RRHHSESSID',
    'cookie_lifetime' => 60 * 60 * 24 * 30, // 30 días
    'secure' => null,       // null = detecta HTTPS automáticamente
    'httponly' => true,
    'samesite' => 'Lax',
  ],
];