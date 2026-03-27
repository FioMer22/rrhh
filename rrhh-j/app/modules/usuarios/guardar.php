<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método no permitido'); }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) {
  http_response_code(403); exit('CSRF inválido');
}

function nn($v){ $v = trim((string)$v); return $v === '' ? null : $v; }

$id         = (int)($_POST['id'] ?? 0);
$nombre     = trim((string)($_POST['nombre'] ?? ''));
$apellido   = trim((string)($_POST['apellido'] ?? ''));
$email      = trim((string)($_POST['email'] ?? ''));
$telefono   = nn($_POST['telefono'] ?? null);
$foto_url   = nn($_POST['foto_url'] ?? null);
$activo     = isset($_POST['activo']) ? 1 : 0;

$area_id     = nn($_POST['area_id'] ?? null);
$equipo_id   = nn($_POST['equipo_id'] ?? null);
$sede_id     = nn($_POST['sede_id'] ?? null);
$jefe_id     = nn($_POST['jefe_id'] ?? null);
$rol_base_id = nn($_POST['rol_base_id'] ?? null);

if ($nombre === '' || $apellido === '' || $email === '') {
  http_response_code(400); exit('Faltan campos obligatorios');
}

$pwdCol = null;
$cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
  $f = strtolower((string)$c['Field']);
  if (in_array($f, ['password_hash','pass_hash','password','pass','clave','hash'], true)) {
    $pwdCol = $c['Field'];
    break;
  }
}

$newPass = trim((string)($_POST['new_password'] ?? ''));
$setPass = ($pwdCol && $newPass !== '');
$passHash = $setPass ? password_hash($newPass, PASSWORD_DEFAULT) : null;

if ($id > 0) {
  $sql = "UPDATE usuarios SET
            nombre=:nombre, apellido=:apellido, email=:email,
            telefono=:telefono, foto_url=:foto_url, activo=:activo,
            area_id=:area_id, equipo_id=:equipo_id, sede_id=:sede_id, jefe_id=:jefe_id, rol_base_id=:rol_base_id
          WHERE id=:id";
  $params = [
    ':nombre'=>$nombre, ':apellido'=>$apellido, ':email'=>$email,
    ':telefono'=>$telefono, ':foto_url'=>$foto_url, ':activo'=>$activo,
    ':area_id'=>$area_id, ':equipo_id'=>$equipo_id, ':sede_id'=>$sede_id, ':jefe_id'=>$jefe_id, ':rol_base_id'=>$rol_base_id,
    ':id'=>$id
  ];

  if ($setPass) {
    $sql = str_replace("WHERE id=:id", ", {$pwdCol}=:ph WHERE id=:id", $sql);
    $params[':ph'] = $passHash;
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);

} else {
  $fields = "nombre,apellido,email,telefono,foto_url,activo,area_id,equipo_id,sede_id,jefe_id,rol_base_id";
  $values = ":nombre,:apellido,:email,:telefono,:foto_url,:activo,:area_id,:equipo_id,:sede_id,:jefe_id,:rol_base_id";

  $params = [
    ':nombre'=>$nombre, ':apellido'=>$apellido, ':email'=>$email,
    ':telefono'=>$telefono, ':foto_url'=>$foto_url, ':activo'=>$activo,
    ':area_id'=>$area_id, ':equipo_id'=>$equipo_id, ':sede_id'=>$sede_id, ':jefe_id'=>$jefe_id, ':rol_base_id'=>$rol_base_id
  ];

  if ($setPass) {
    $fields .= ",{$pwdCol}";
    $values .= ",:ph";
    $params[':ph'] = $passHash;
  }

  $st = $pdo->prepare("INSERT INTO usuarios ($fields) VALUES ($values)");
  $st->execute($params);
}

header("Location: index.php");
exit;