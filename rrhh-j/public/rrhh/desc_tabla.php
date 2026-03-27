<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_login();
$pdo = DB::pdo();
echo "<pre>";
// Ver columnas de notificaciones
$cols = $pdo->query("DESCRIBE notificaciones")->fetchAll(PDO::FETCH_ASSOC);
echo "=== notificaciones ===\n";
foreach ($cols as $c) echo $c['Field'] . " - " . $c['Type'] . "\n";
echo "\n=== avisos_tardanza ===\n";
$cols2 = $pdo->query("DESCRIBE avisos_tardanza")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols2 as $c) echo $c['Field'] . " - " . $c['Type'] . "\n";
echo "</pre>";
