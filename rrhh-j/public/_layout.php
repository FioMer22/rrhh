<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';
require_once __DIR__ . '/../app/middleware/roles.php';
if (file_exists(__DIR__ . '/../app/helpers/notificaciones.php')) {
    require_once __DIR__ . '/../app/helpers/notificaciones.php';
}

require_login();

$nombre  = trim((string)(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '')));
$roles   = $_SESSION['roles'] ?? [];
$current = basename($_SERVER['PHP_SELF']);
$_notifCount = 0;
if (function_exists('contar_no_leidas') && isset($_SESSION['uid'])) {
    try { $_notifCount = contar_no_leidas(DB::pdo(), (int)$_SESSION['uid']); } catch(Throwable $e) { $_notifCount = 0; }
}
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

if (!function_exists('has_any_role')) {
  function has_any_role(string ...$wanted): bool {
    $roles = $_SESSION['roles'] ?? [];
    foreach ($wanted as $w) {
      if (in_array($w, $roles, true)) return true;
    }
    return false;
  }
}

// Detectar página activa para resaltar en nav
function nav_active(string $path): string {
    $current = $_SERVER['PHP_SELF'] ?? '';
    return (str_contains($current, $path)) ? ' nav-active' : '';
}
?>
<?php
// ── Registrar Service Worker si no está ya incluido ──────────────
if (!defined('PWA_HEAD_INCLUDED')) {
    define('PWA_HEAD_INCLUDED', true);
    ?>
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/rrhh-j/public/manifest.json">
    <meta name="theme-color" content="#0b1f3a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RRHH JR">
    <!-- Íconos Apple (iOS no usa manifest) -->
    <link rel="apple-touch-icon" href="/rrhh-j/public/assets/img/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/rrhh-j/public/assets/img/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/rrhh-j/public/assets/img/icon-192x192.png">
    <!-- Service Worker + Web Push -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                const reg = await navigator.serviceWorker.register('/rrhh-j/public/sw.js', {
                    scope: '/rrhh-j/public/'
                });

                // Suscribir a push si aún no está suscrito
                await pushSuscribir(reg);
            } catch(e) {}
        });
    }

    async function pushSuscribir(reg) {
        try {
            // Ver si ya hay suscripción activa
            let sub = await reg.pushManager.getSubscription();
            if (sub) return; // ya suscrito

            // Pedir permiso
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') return;

            // Obtener VAPID public key del servidor
            const r = await fetch('/rrhh-j/public/push_vapid_key.php');
            const { publicKey } = await r.json();

            // Convertir base64url a Uint8Array
            const vapidKey = Uint8Array.from(
                atob(publicKey.replace(/-/g,'+').replace(/_/g,'/')),
                c => c.charCodeAt(0)
            );

            // Crear suscripción
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: vapidKey
            });

            // Guardar suscripción en el servidor
            await fetch('/rrhh-j/public/push_suscribir.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sub)
            });
        } catch(e) {}
    }
    </script>
    <?php
}
?>

<style>
  /* ── Variables ─────────────────────────────────── */
  :root {
    --nav-bg: #0b1f3a;
    --nav-h:  50px;
    --nav-accent: #3b82f6;
    --nav-hover: rgba(255,255,255,.08);
    --nav-text: rgba(255,255,255,.88);
    --nav-muted: rgba(255,255,255,.45);
    --drop-bg: #0f2847;
    --drop-border: rgba(255,255,255,.1);
  }

  /* ── Barra principal ───────────────────────────── */
  #main-nav {
    background: var(--nav-bg);
    height: var(--nav-h);
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 2px;
    position: sticky;
    top: 0;
    z-index: 1000;
    box-shadow: 0 2px 12px rgba(0,0,0,.35);
    user-select: none;
  }

  /* ── Logo ──────────────────────────────────────── */
  .nav-logo {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    margin-right: 10px;
    flex-shrink: 0;
  }
  .nav-logo img { height: 26px; filter: brightness(0) invert(1); }
  .nav-logo span {
    font-weight: 800;
    font-size: 13px;
    letter-spacing: 1px;
    color: #fff;
  }

  /* ── Links y dropdowns ─────────────────────────── */
  .nav-item {
    position: relative;
    height: var(--nav-h);
    display: flex;
    align-items: center;
  }

  .nav-link {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 0 11px;
    height: 100%;
    color: var(--nav-text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    border-radius: 0;
    transition: background .15s, color .15s;
    cursor: pointer;
    border: none;
    background: none;
  }
  .nav-link:hover, .nav-item:hover > .nav-link {
    background: var(--nav-hover);
    color: #fff;
  }
  .nav-link.nav-active {
    color: var(--nav-accent);
    background: rgba(59,130,246,.12);
  }
  .nav-link .caret {
    font-size: 9px;
    opacity: .6;
    transition: transform .2s;
    margin-left: 1px;
  }
  .nav-item:hover > .nav-link .caret { transform: rotate(180deg); }

  /* ── Divisor ───────────────────────────────────── */
  .nav-sep {
    width: 1px;
    height: 20px;
    background: var(--drop-border);
    margin: 0 6px;
    flex-shrink: 0;
  }

  /* ── Dropdown panel ────────────────────────────── */
  .nav-dropdown {
    display: none;
    position: absolute;
    top: calc(var(--nav-h) - 2px);
    left: 0;
    min-width: 200px;
    background: var(--drop-bg);
    border: 1px solid var(--drop-border);
    border-radius: 0 0 10px 10px;
    overflow: hidden;
    box-shadow: 0 12px 32px rgba(0,0,0,.4);
    padding: 4px 0;
  }
  .nav-item:hover .nav-dropdown { display: block; }

  .nav-dropdown a {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 16px;
    color: var(--nav-text);
    text-decoration: none;
    font-size: 13px;
    transition: background .12s;
    white-space: nowrap;
  }
  .nav-dropdown a:hover {
    background: rgba(255,255,255,.07);
    color: #fff;
  }
  .nav-dropdown a.nav-active {
    color: var(--nav-accent);
    background: rgba(59,130,246,.1);
  }
  .nav-dropdown .drop-section {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .8px;
    text-transform: uppercase;
    color: var(--nav-muted);
    padding: 10px 16px 4px;
  }
  .nav-dropdown .drop-sep {
    height: 1px;
    background: var(--drop-border);
    margin: 4px 0;
  }

  /* ── Usuario (derecha) ─────────────────────────── */
  .nav-user {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    padding-left: 10px;
  }
  .nav-user-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--nav-text);
  }
  .nav-user-roles {
    font-size: 10px;
    color: var(--nav-muted);
    background: rgba(255,255,255,.08);
    padding: 2px 7px;
    border-radius: 999px;
  }
  .nav-avatar {
    width: 30px; height: 30px;
    background: var(--nav-accent);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff;
    flex-shrink: 0;
  }
  .nav-logout {
    font-size: 12px;
    color: var(--nav-muted);
    text-decoration: none;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background .15s, color .15s;
  }
  .nav-logout:hover { background: rgba(255,255,255,.08); color: #fff; }

  /* ── Hamburguesa mobile ────────────────────────── */
  .nav-burger {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: 8px;
    margin-left: auto;
  }
  .nav-burger span {
    display: block;
    width: 22px; height: 2px;
    background: #fff;
    border-radius: 2px;
    transition: .3s;
  }

  #nav-mobile-panel {
    display: none;
    background: var(--drop-bg);
    border-bottom: 1px solid var(--drop-border);
    padding: 8px 0;
  }
  #nav-mobile-panel a {
    display: block;
    padding: 10px 20px;
    color: var(--nav-text);
    text-decoration: none;
    font-size: 14px;
  }
  #nav-mobile-panel a:hover { background: var(--nav-hover); }
  #nav-mobile-panel .mob-section {
    font-size: 10px; font-weight: 700;
    letter-spacing: .8px; text-transform: uppercase;
    color: var(--nav-muted); padding: 12px 20px 4px;
  }
  #nav-mobile-panel .mob-sep {
    height: 1px; background: var(--drop-border); margin: 4px 0;
  }

  @media (max-width: 768px) {
    .nav-desktop { display: none !important; }
    .nav-burger   { display: flex; }
    .nav-user-name, .nav-user-roles { display: none; }
    #nav-mobile-panel.open { display: block; }
  }
  @media (min-width: 769px) {
    .nav-burger { display: none; }
  }
</style>

<nav id="main-nav">
  <!-- Logo -->
  <a href="<?= url('/dashboard.php') ?>" class="nav-logo">
    <img src="<?= url('/assets/img/logo-jr.png') ?>" alt="JR" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
    <span class="nav-logo-fallback" style="display:none;width:26px;height:26px;background:#fff;border-radius:50%;color:#0b1f3a;font-weight:700;font-size:11px;align-items:center;justify-content:center;">JR</span>
    <span>RRHH</span>
  </a>

  <!-- ── Links desktop ─────────────────────────── -->
  <div class="nav-desktop" style="display:flex;align-items:center;gap:2px;height:100%">

    <!-- Dashboard -->
    <div class="nav-item">
      <a href="<?= url('/dashboard.php') ?>" class="nav-link<?= nav_active('/dashboard') ?>">
        🏠
      </a>
    </div>

    <!-- Mi trabajo (dropdown) -->
    <div class="nav-item">
      <span class="nav-link">
        Mi trabajo <span class="caret">▼</span>
      </span>
      <div class="nav-dropdown">
        <a href="<?= url('/asistencia/marcar.php') ?>"   class="<?= nav_active('/asistencia/marcar') ?>">⏱ Marcar asistencia</a>
        <a href="<?= url('/asistencia/mi.php') ?>"       class="<?= nav_active('/asistencia/mi') ?>">📊 Mi asistencia</a>
        <div class="drop-sep"></div>
        <a href="<?= url('/ausencias/index.php') ?>"     class="<?= nav_active('/ausencias/index') ?>">📋 Ausencias</a>
        <a href="<?= url('/viajes/index.php') ?>"        class="<?= nav_active('/viajes') ?>">✈️ Viajes</a>
        <a href="<?= url('/actividades/index.php') ?>"   class="<?= nav_active('/actividades') ?>">⚡ Actividades</a>
        <a href="<?= url('/calendario/index.php') ?>"    class="<?= nav_active('/calendario') ?>">📅 Eventos</a>
      </div>
    </div>

    <?php if (has_any_role('admin','rrhh','coordinador','direccion')): ?>
    <div class="nav-sep"></div>

    <!-- Panel RRHH / Admin (dropdown) -->
    <?php if (has_any_role('admin','rrhh')): ?>
    <div class="nav-item">
      <span class="nav-link<?= (str_contains($_SERVER['PHP_SELF']??'','/rrhh/') || str_contains($_SERVER['PHP_SELF']??'','/usuarios/') || str_contains($_SERVER['PHP_SELF']??'','/areas/')) ? ' nav-active' : '' ?>">
        👥 Personal <span class="caret">▼</span>
      </span>
      <div class="nav-dropdown">
        <div class="drop-section">Presencia</div>
        <a href="<?= url('/rrhh/presencia.php') ?>"         class="<?= nav_active('/rrhh/presencia') ?>">🟢 Estado actual</a>
        <a href="<?= url('/rrhh/movimientos_hoy.php') ?>"   class="<?= nav_active('/movimientos_hoy') ?>">🕐 Movimientos</a>
        <div class="drop-sep"></div>
        <div class="drop-section">Informes</div>
        <a href="<?= url('/rrhh/informe.php') ?>"           class="<?= nav_active('/rrhh/informe') ?>">📈 Informe mensual</a>
        <a href="<?= url('/rrhh/informe_pdf.php') ?>"       class="<?= nav_active('/rrhh/informe_pdf') ?>">🖨 Informe imprimible</a>
        <div class="drop-sep"></div>
        <div class="drop-section">Gestión</div>
        <a href="<?= url('/usuarios/index.php') ?>"         class="<?= nav_active('/usuarios') ?>">👤 Usuarios</a>
        <a href="<?= url('/areas/index.php') ?>"            class="<?= nav_active('/areas') ?>">🏢 Áreas</a>
        <a href="<?= url('/rrhh/asignar_turnos.php') ?>"    class="<?= nav_active('/asignar_turnos') ?>">🕑 Turnos</a>
        <a href="<?= url('/ausencias/historial.php') ?>"    class="<?= nav_active('/ausencias/historial') ?>">📁 Historial ausencias</a>
        <a href="<?= url('/rrhh/notificacion_enviar.php') ?>" class="<?= nav_active('/rrhh/notificacion_enviar') ?>">📣 Enviar notificación</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if (has_any_role('coordinador','direccion') && !has_any_role('admin','rrhh')): ?>
    <!-- Solo coordinador o dirección sin admin -->
    <div class="nav-item">
      <a href="<?= url('/ausencias/index.php') ?>" class="nav-link<?= nav_active('/ausencias') ?>">Ausencias equipo</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <!-- ── Usuario derecha ──────────────────────── -->
  <div class="nav-user">
    <!-- Campana de notificaciones -->
    <a href="<?= url('/notificaciones.php') ?>"
       style="position:relative;color:var(--nav-text);text-decoration:none;
              font-size:18px;display:flex;align-items:center;padding:4px 6px;
              border-radius:8px;transition:background .15s"
       title="Notificaciones">
      🔔
      <?php if ($_notifCount > 0): ?>
        <span style="position:absolute;top:0;right:0;background:#ef4444;color:#fff;
                     font-size:9px;font-weight:700;border-radius:999px;
                     padding:1px 4px;min-width:14px;text-align:center;line-height:14px">
          <?= $_notifCount > 9 ? '9+' : $_notifCount ?>
        </span>
      <?php endif; ?>
    </a>
    <div class="nav-avatar"><?= strtoupper(mb_substr($nombre ?: 'U', 0, 1)) ?></div>
    <div>
      <div class="nav-user-name"><?= e($nombre ?: ($_SESSION['email'] ?? '')) ?></div>
      <?php if (!empty($roles)): ?>
        <div class="nav-user-roles"><?= e(implode(', ', $roles)) ?></div>
      <?php endif; ?>
    </div>
    <a href="<?= url('/logout.php') ?>" class="nav-logout" title="Salir">Salir</a>
  </div>

  <!-- Hamburguesa mobile -->
  <button class="nav-burger" onclick="toggleMobile()" aria-label="Menú">
    <span></span><span></span><span></span>
  </button>
</nav>

<!-- ── Panel mobile ─────────────────────────────────── -->
<div id="nav-mobile-panel">
  <a href="<?= url('/dashboard.php') ?>">🏠 Dashboard</a>
  <div class="mob-sep"></div>
  <div class="mob-section">Mi trabajo</div>
  <a href="<?= url('/asistencia/marcar.php') ?>">⏱ Marcar asistencia</a>
  <a href="<?= url('/asistencia/mi.php') ?>">📊 Mi asistencia</a>
  <a href="<?= url('/ausencias/index.php') ?>">📋 Ausencias</a>
  <a href="<?= url('/viajes/index.php') ?>">✈️ Viajes</a>
  <a href="<?= url('/actividades/index.php') ?>">⚡ Actividades</a>
  <a href="<?= url('/calendario/index.php') ?>">📅 Eventos</a>

  <?php if (has_any_role('admin','rrhh')): ?>
  <div class="mob-sep"></div>
  <div class="mob-section">Personal</div>
  <a href="<?= url('/rrhh/presencia.php') ?>">🟢 Estado actual</a>
  <a href="<?= url('/rrhh/movimientos_hoy.php') ?>">🕐 Movimientos</a>
  <a href="<?= url('/rrhh/informe.php') ?>">📈 Informe mensual</a>
  <a href="<?= url('/rrhh/informe_pdf.php') ?>">🖨 Informe imprimible</a>
  <div class="mob-sep"></div>
  <a href="<?= url('/usuarios/index.php') ?>">👤 Usuarios</a>
  <a href="<?= url('/areas/index.php') ?>">🏢 Áreas</a>
  <a href="<?= url('/rrhh/asignar_turnos.php') ?>">🕑 Turnos</a>
  <a href="<?= url('/ausencias/historial.php') ?>">📁 Historial ausencias</a>
  <a href="<?= url('/rrhh/notificacion_enviar.php') ?>">📣 Enviar notificación</a>
  <?php endif; ?>

  <div class="mob-sep"></div>
  <a href="<?= url('/logout.php') ?>">⬡ Salir</a>
</div>

<script>
function toggleMobile() {
  document.getElementById('nav-mobile-panel').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const panel = document.getElementById('nav-mobile-panel');
  const burger = document.querySelector('.nav-burger');
  if (panel.classList.contains('open') && !panel.contains(e.target) && !burger.contains(e.target)) {
    panel.classList.remove('open');
  }
});

// ── Widget flotante de marcado ─────────────────────────────────────────────
(function() {
    const ENTRADA_INI = 7 * 60;      // 07:00
    const ENTRADA_FIN = 8 * 60 + 30; // 08:30
    const SALIDA_INI  = 17 * 60;     // 17:00
    const SALIDA_FIN  = 18 * 60 + 30;// 18:30

    function ahora() {
        const d = new Date();
        return d.getHours() * 60 + d.getMinutes();
    }

    function getTipo() {
        const m = ahora();
        if (m >= ENTRADA_INI && m <= ENTRADA_FIN) return 'entrada';
        if (m >= SALIDA_INI  && m <= SALIDA_FIN)  return 'salida';
        return null;
    }

    function crearWidget(tipo) {
        // No mostrar si ya estamos en la página de marcar
        if (window.location.pathname.includes('marcar')) return;

        const emoji  = tipo === 'entrada' ? '⏱' : '🏁';
        const label  = tipo === 'entrada' ? 'Marcar entrada' : 'Marcar salida';
        const color  = tipo === 'entrada' ? '#059669' : '#dc2626';

        const btn = document.createElement('a');
        btn.href  = '<?= url('/asistencia/marcar.php') ?>';
        btn.id    = 'widget-marcar';
        btn.innerHTML = `<span style="font-size:22px">${emoji}</span><span style="font-size:13px;font-weight:700">${label}</span>`;
        btn.style.cssText = `
            position:fixed; bottom:24px; right:20px; z-index:9999;
            display:flex; align-items:center; gap:10px;
            background:${color}; color:#fff;
            padding:14px 20px; border-radius:999px;
            box-shadow:0 4px 20px rgba(0,0,0,.3);
            text-decoration:none;
            animation:widget-pulse 2s infinite;
            transition:transform .15s, box-shadow .15s;
        `;
        btn.onmouseenter = () => btn.style.transform = 'scale(1.05)';
        btn.onmouseleave = () => btn.style.transform = 'scale(1)';

        // Botón cerrar
        const x = document.createElement('span');
        x.textContent = '×';
        x.style.cssText = 'position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:#374151;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;color:#fff;line-height:1';
        x.onclick = (e) => { e.preventDefault(); e.stopPropagation(); btn.remove(); };
        btn.style.position = 'fixed';
        btn.appendChild(x);

        // Animación pulse
        const style = document.createElement('style');
        style.textContent = `
            @keyframes widget-pulse {
                0%,100% { box-shadow: 0 4px 20px rgba(0,0,0,.3), 0 0 0 0 ${color}66; }
                50%      { box-shadow: 0 4px 20px rgba(0,0,0,.3), 0 0 0 10px ${color}00; }
            }
        `;
        document.head.appendChild(style);
        document.body.appendChild(btn);
    }

    // Crear al cargar si corresponde
    const tipo = getTipo();
    if (tipo) crearWidget(tipo);
})();
</script>
<script>
  if (window.Android && window.Android.esAppNativa && window.Android.esAppNativa()) {
    var widgetToken = '<?= $_SESSION["widget_token"] ?? "" ?>';
    var widgetUid   = <?= (int)($_SESSION['uid'] ?? 0) ?>;
    if (widgetToken && widgetUid) {
        window.Android.guardarSesion(widgetToken, widgetUid);
    }
}
</script>
<script>
(function () {
    var API_URL = '/rrhh-j/public/rrhh/notificaciones_pendientes.php';

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