<?php
// app/helpers/csrf.php
declare(strict_types=1);

function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}

function csrf_field(): string {
  return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

  $sent = (string)($_POST['_csrf'] ?? '');
  $real = (string)($_SESSION['_csrf'] ?? '');

  if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
    http_response_code(419);
    exit('CSRF inválido');
  }
}