<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

// Handler POST: NO usar _layout.php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/middleware/roles.php';

require_login();

/**
 * En tu sistema, has_any_role() estaba definido solo en public/_layout.php.
 * Acá lo definimos si no existe, usando la misma lógica.
 */
if (!function_exists('has_any_role')) {
  function has_any_role(string ...$wanted): bool {
    $roles = $_SESSION['roles'] ?? [];
    foreach ($wanted as $w) {
      if (in_array($w, $roles, true)) return true;
    }
    return false;
  }
}

if (!has_any_role('admin','rrhh')) {
  http_response_code(403);
  exit('No autorizado');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Método no permitido');
}

csrf_verify();

$pdo = DB::pdo();

// --- Helpers locales ---
function nn_str($v): ?string {
  $v = trim((string)$v);
  return $v === '' ? null : $v;
}
function nn_int($v): ?int {
  $v = (int)($v ?? 0);
  return $v > 0 ? $v : null;
}

try {
  // --- Datos ---
  $id       = (int)($_POST['id'] ?? 0);
  $nombre   = trim((string)($_POST['nombre'] ?? ''));
  $apellido = trim((string)($_POST['apellido'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));

  $telefono = nn_str($_POST['telefono'] ?? null);
  $ci       = nn_str($_POST['ci']       ?? null);
  $foto_url = nn_str($_POST['foto_url'] ?? null);
  $activo   = isset($_POST['activo']) ? 1 : 0;

  $area_id     = nn_int($_POST['area_id'] ?? 0);
  $equipo_id   = nn_int($_POST['equipo_id'] ?? 0);
  $sede_id     = nn_int($_POST['sede_id'] ?? 0);
  $jefe_id     = nn_int($_POST['jefe_id'] ?? 0);
  $rol_base_id = nn_int($_POST['rol_base_id'] ?? 0);

  if ($nombre === '' || $apellido === '' || $email === '') {
    http_response_code(400);
    exit('Faltan campos obligatorios');
  }

  // Detectar columna de contraseña si existe
  $pwdCol = null;
  $cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($cols as $c) {
    $f = strtolower((string)$c['Field']);
    if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
      $pwdCol = $c['Field'];
      break;
    }
  }

  $newPass  = trim((string)($_POST['new_password'] ?? ''));
  $setPass  = ($pwdCol && $newPass !== '');
  $passHash = $setPass ? password_hash($newPass, PASSWORD_DEFAULT) : null;

  // --- Guardar ---
  if ($id > 0) {
    $sql = "UPDATE usuarios SET
              nombre=:nombre,
              apellido=:apellido,
              email=:email,
              ci=:ci,
              telefono=:telefono,
              foto_url=:foto_url,
              activo=:activo,
              area_id=:area_id,
              equipo_id=:equipo_id,
              sede_id=:sede_id,
              jefe_id=:jefe_id,
              rol_base_id=:rol_base_id
            WHERE id=:id";

    $params = [
      ':nombre' => $nombre,
      ':apellido' => $apellido,
      ':email' => $email,
      ':ci'       => $ci,
      ':ci'       => $ci,
      ':telefono' => $telefono,
      ':foto_url' => $foto_url,
      ':activo' => $activo,
      ':area_id' => $area_id,
      ':equipo_id' => $equipo_id,
      ':sede_id' => $sede_id,
      ':jefe_id' => $jefe_id,
      ':rol_base_id' => $rol_base_id,
      ':id' => $id,
    ];

    if ($setPass) {
      $sql = str_replace("WHERE id=:id", ", {$pwdCol}=:ph WHERE id=:id", $sql);
      $params[':ph'] = $passHash;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

  } else {
    $fields = "nombre,apellido,email,ci,telefono,foto_url,activo,area_id,equipo_id,sede_id,jefe_id,rol_base_id";
    $values = ":nombre,:apellido,:email,:ci,:telefono,:foto_url,:activo,:area_id,:equipo_id,:sede_id,:jefe_id,:rol_base_id";

    $params = [
      ':nombre' => $nombre,
      ':apellido' => $apellido,
      ':email' => $email,
      ':ci'       => $ci,
      ':ci'       => $ci,
      ':telefono' => $telefono,
      ':foto_url' => $foto_url,
      ':activo' => $activo,
      ':area_id' => $area_id,
      ':equipo_id' => $equipo_id,
      ':sede_id' => $sede_id,
      ':jefe_id' => $jefe_id,
      ':rol_base_id' => $rol_base_id,
    ];

    if ($setPass) {
      $fields .= ",{$pwdCol}";
      $values .= ",:ph";
      $params[':ph'] = $passHash;
    }

    $st = $pdo->prepare("INSERT INTO usuarios ($fields) VALUES ($values)");
    $st->execute($params);
  }

  // --- Roles extra (multi-rol) ---
  try {
      $savedId    = $id > 0 ? $id : (int)$pdo->lastInsertId();
      $rolesExtra = array_map('intval', (array)($_POST['roles_extra'] ?? []));
      $pdo->prepare("DELETE FROM usuarios_roles WHERE usuario_id = ?")
          ->execute([$savedId]);
      foreach (array_unique($rolesExtra) as $rolId) {
          if ($rolId <= 0) continue;
          $pdo->prepare("INSERT IGNORE INTO usuarios_roles (usuario_id, rol_id, asignado_por) VALUES (?,?,?)")
              ->execute([$savedId, $rolId, (int)($_SESSION['uid'] ?? 0)]);
      }
  } catch (Throwable $e) { /* tabla puede no existir aún */ }

  redirect(url('/usuarios/index.php'));

} catch (Throwable $e) {
  http_response_code(500);
  // Dejar esto así mientras debuggeamos. Luego lo ocultamos y logueamos.
  echo "<h1>Error</h1><pre>".e($e->getMessage())."\n".e($e->getTraceAsString())."</pre>";
}