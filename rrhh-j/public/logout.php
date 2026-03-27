<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (!empty($_SESSION['uid'])) {
    try {
        $pdo = DB::pdo();
        $pdo->prepare("UPDATE usuarios SET widget_token=NULL WHERE id=?")
            ->execute([(int)$_SESSION['uid']]);
    } catch (Throwable) {}
}

$_SESSION = [];
session_destroy();

// Si viene de la app nativa, mostrar página intermedia que llama al bridge
// antes de redirigir al login
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$esApp = str_contains($userAgent, 'Android') || isset($_GET['app']);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    
</head>
<body>
<script>
    function redirigir() {
        window.location.href = '/rrhh-j/public/login.php';
    }

    if (window.Android && window.Android.cerrarSesion) {
        window.Android.cerrarSesion();
        // Dar tiempo a Kotlin para limpiar y actualizar widget
        setTimeout(redirigir, 600);
    } else {
        setTimeout(redirigir, 100);
    }
</script>
</body>
</html>