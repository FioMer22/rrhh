<!-- PWA -->
<link rel="manifest" href="/rrhh-j/public/manifest.json">
<meta name="theme-color" content="#0b1f3a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="JR RRHH">
<link rel="apple-touch-icon" href="/rrhh-j/public/assets/icons/icon-152x152.png">
<link rel="apple-touch-icon" sizes="192x192" href="/rrhh-j/public/assets/icons/icon-192x192.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/rrhh-j/public/sw.js', { scope: '/rrhh-j/' })
      .catch(e => console.warn('SW:', e));
  });
}
</script>
<script>
(function () {
    var API_URL = '/rrhh-j/public/rrhh/noficaciones_pendientes.php';

    function consultarNotificaciones() {
        // Verificar bridge en cada llamada, no solo al inicio
        if (!(window.Android && window.Android.esAppNativa &&
              window.Android.esAppNativa())) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL, true);
        xhr.withCredentials = true;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            // LOG TEMPORAL — sacar en producción
            console.log('RRHH polling status=' + xhr.status + ' resp=' + xhr.responseText.substring(0, 100));
            if (xhr.status !== 200) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.notificaciones && data.notificaciones.length > 0) {
                    data.notificaciones.forEach(function (n) {
                        window.Android.mostrarNotificacion(
                            n.titulo  || 'RRHH',
                            n.mensaje || '',
                            n.url     || ''
                        );
                    });
                }
            } catch (e) {}
        };
        xhr.send();
    }

    // Esperar a que el DOM cargue completamente antes de empezar
    // Así window.Android ya está inyectado por el WebView
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            consultarNotificaciones();
            setInterval(consultarNotificaciones, 30000);
        });
    } else {
        // Ya cargó (por si el script se ejecuta tarde)
        consultarNotificaciones();
        setInterval(consultarNotificaciones, 30000);
    }
})();
</script>

<!-- ... todo tu código existente de _pwa_head.php ... -->

<?php if (!empty($_SESSION['pending_widget_token'])): ?>
<script>
(function() {
    // Esperar al bridge de Android antes de intentar llamarlo
    function tryGuardarSesion(intentos) {
        if (window.Android && window.Android.guardarSesion) {
            window.Android.guardarSesion(
                <?= json_encode($_SESSION['pending_widget_token']) ?>,
                <?= json_encode((int)$_SESSION['pending_widget_uid']) ?>
            );
        } else if (intentos > 0) {
            // El bridge puede tardar unos ms en inyectarse
            setTimeout(function() { tryGuardarSesion(intentos - 1); }, 100);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { tryGuardarSesion(5); });
    } else {
        tryGuardarSesion(5);
    }
})();
</script>
<?php
    unset($_SESSION['pending_widget_token'], $_SESSION['pending_widget_uid']);
endif;
?>

