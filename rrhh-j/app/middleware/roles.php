<?php
// app/middleware/roles.php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';

function user_roles(): array {
  return $_SESSION['roles'] ?? [];
}

function has_role(string $role): bool {
  return in_array($role, user_roles(), true);
}

function require_role(string ...$roles): void {
  foreach ($roles as $r) {
    if (has_role($r)) return;
  }
  http_response_code(403);
  exit('No autorizado');
}