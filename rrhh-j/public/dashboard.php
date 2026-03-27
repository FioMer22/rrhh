<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/middleware/auth.php';

require_login();

$logoPath = url('/assets/LOGO-JR-1.png'); // <-- asegurate que exista ahí
$nombre = trim((string)($_SESSION['nombre'] ?? ''));
$roles  = $_SESSION['roles'] ?? [];
$isAdminRRHH = in_array('admin', $roles, true) || in_array('rrhh', $roles, true);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard — RRHH JR</title>
  <?php require_once __DIR__ . '/./_pwa_head.php'; ?>
  <style>
    :root{
      --jr-navy:#0B2A4A;     /* azul oscuro */
      --jr-blue:#0F4C81;     /* azul principal */
      --jr-sky:#1EA7E1;      /* acento celeste */
      --jr-bg:#F3F7FB;       /* fondo */
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --border:rgba(15,76,129,.14);
      --shadow:0 18px 50px rgba(2, 18, 40, .10);
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;
      background:
        radial-gradient(900px 420px at 16% -10%, rgba(30,167,225,.18), transparent 60%),
        radial-gradient(900px 420px at 86% -10%, rgba(15,76,129,.16), transparent 60%),
        var(--jr-bg);
      color:var(--text);
    }

    /* zona de contenido (dejamos tu navbar/_layout arriba) */
    .wrap{max-width:1100px;margin:18px auto 40px;padding:0 16px}

    /* header institucional */
    .hero{
      background:linear-gradient(135deg, var(--jr-navy), var(--jr-blue));
      border-radius:20px;
      padding:18px 18px;
      box-shadow:var(--shadow);
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
      border:1px solid rgba(255,255,255,.12);
    }
    .brand{
      display:flex;
      gap:14px;
      align-items:center;
      min-width:260px;
    }
    .brand img{
      height:48px;
      width:auto;
      filter: brightness(0) invert(1) drop-shadow(0 10px 22px rgba(0,0,0,.25));
    }
    .brand .titles{line-height:1.15}
    .brand .titles .kicker{font-size:12px;opacity:.85;letter-spacing:.06em;text-transform:uppercase}
    .brand .titles .title{font-size:18px;font-weight:700;margin-top:4px}

    .userbox{
      text-align:right;
      min-width:240px;
    }
    .userbox .hello{font-weight:700;font-size:14px}
    .userbox .meta{font-size:12px;opacity:.85;margin-top:2px}

    /* cards */
    .grid{
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap:14px;
      margin-top:14px;
    }
    .card{
      grid-column: span 12;
      background:var(--card);
      border-radius:18px;
      padding:16px;
      border:1px solid var(--border);
      box-shadow:0 10px 26px rgba(2,18,40,.06);
    }
    @media (min-width: 900px){
      .card.half{grid-column: span 6;}
    }

    .card h2{margin:0 0 6px 0;font-size:18px}
    .card p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}

    /* botones */
    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    a.btn{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:11px 14px;
      border-radius:14px;
      text-decoration:none;
      font-weight:700;
      font-size:13px;
      border:1px solid rgba(255,255,255,.14);
      transition: transform .08s ease, box-shadow .15s ease, opacity .15s ease;
      user-select:none;
      white-space:nowrap;
    }
    a.btn:active{transform:translateY(1px)}
    a.btn.primary{
      background:linear-gradient(135deg, var(--jr-blue), var(--jr-sky));
      color:#fff;
      box-shadow:0 16px 40px rgba(15,76,129,.22);
    }
    a.btn.secondary{
      background:#fff;
      color:var(--jr-blue);
      border:1px solid rgba(15,76,129,.22);
    }
    a.btn.danger{
      background:rgba(15,76,129,.08);
      color:var(--jr-navy);
      border:1px solid rgba(15,76,129,.18);
    }

    /* mini badges */
    .badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .badge{
      font-size:12px;
      padding:6px 10px;
      border-radius:999px;
      background:rgba(255,255,255,.14);
      border:1px solid rgba(255,255,255,.18);
      color:#fff;
      opacity:.95;
    }

    /* footer */
    .foot{
      margin-top:14px;
      color:var(--muted);
      font-size:12px;
      text-align:center;
    }
  </style>
</head>

<body>
  <?php require __DIR__ . '/_layout.php'; ?>

  <div class="wrap">

    <section class="hero">
      <div class="brand">
        <img src="<?= e($logoPath) ?>" alt="Jesús Responde">
        <div class="titles">
          <div class="kicker">Fundación</div>
          <div class="title">RRHH — Plataforma Interna</div>
          <div class="badges">
            <span class="badge">Asistencia</span>
            <span class="badge">Usuarios</span>
            <span class="badge">Reportes</span>
          </div>
        </div>
      </div>

      <div class="userbox">
        <div class="hello">Bienvenido, <?= e($nombre !== '' ? $nombre : ($_SESSION['email'] ?? '')) ?></div>
        <div class="meta">
          Roles: <?= e(!empty($roles) ? implode(', ', $roles) : '—') ?>
        </div>
      </div>
    </section>

    <section class="grid">
      <div class="card half">
        <h2>Mi espacio</h2>
        <p>Marcá tu entrada/salida, consultá tu historial y gestioná tus ausencias.</p>
        <div class="actions">
          <a class="btn primary" href="<?= url('/asistencia/marcar.php') ?>">🕒 Marcar</a>
          <a class="btn secondary" href="<?= url('/asistencia/mi.php') ?>">📌 Mi asistencia</a>
          <a class="btn secondary" href="<?= url('/ausencias/index.php') ?>">📋 Ausencias</a>
        </div>
      </div>

      <div class="card half">
        <h2>Actividades y Eventos</h2>
        <p>Registrá actividades de campo y consultá los eventos y convocatorias del equipo.</p>
        <div class="actions">
          <a class="btn primary" href="<?= url('/actividades/index.php') ?>">🗺 Actividades</a>
          <a class="btn secondary" href="<?= url('/eventos/index.php') ?>">📅 Eventos</a>
        </div>
      </div>

      <?php if ($isAdminRRHH): ?>
      <div class="card">
        <h2>Administración RRHH</h2>
        <p>Gestión de personal, turnos, informes y estructura organizacional.</p>
        <div class="actions">
          <a class="btn primary"   href="<?= url('/usuarios/index.php') ?>">👥 Usuarios</a>
          <a class="btn secondary" href="<?= url('/areas/index.php') ?>">🗂 Áreas y Equipos</a>
          <a class="btn secondary" href="<?= url('/rrhh/movimientos_hoy.php') ?>">📋 Movimientos hoy</a>
          <a class="btn secondary" href="<?= url('/rrhh/informe.php') ?>">📊 Informe</a>
          <a class="btn danger"    href="<?= url('/rrhh/asignar_turnos.php') ?>">🗓️ Asignar turnos</a>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <div class="foot">
      Jesús Responde · RRHH JR · <?= e(date('Y')) ?>
    </div>

  </div>
</body>
</html>