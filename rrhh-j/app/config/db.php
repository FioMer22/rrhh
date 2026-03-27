<?php
// app/config/db.php
declare(strict_types=1);

final class DB {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (self::$pdo instanceof PDO) return self::$pdo;

        $host = 'localhost';
        $db   = 'asistencia';   // ← la que creaste en phpMyAdmin
        $user = 'root';
        $pass = ''; 
        $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    self::$pdo = new PDO($dsn, $user, $pass, $options);

    // ✅ Zona horaria de la sesión MySQL (automático con DST)
    self::$pdo->exec("SET time_zone = '" . date('P') . "'");

    return self::$pdo;
  }
}