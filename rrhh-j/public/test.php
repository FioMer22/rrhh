<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "PHP OK - versión: " . PHP_VERSION . "<br>";

require_once __DIR__ . '/../app/bootstrap.php';
echo "Bootstrap OK<br>";

require_once '../app/config/db.php';

try {
    $pdo = DB::pdo();
    echo "Conexión exitosa a la base de datos!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}