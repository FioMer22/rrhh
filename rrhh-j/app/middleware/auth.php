<?php
// app/middleware/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';

function require_login(): void {
  if (empty($_SESSION['uid'])) {
    redirect('/login.php');
  }
}