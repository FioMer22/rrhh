<?php
declare(strict_types=1);

/**
 * Crea una notificación interna para un usuario.
 * Estructura real de la tabla:
 *   id, usuario_id, tipo, titulo, mensaje, leido, url, created_at
 */
function crear_notificacion(
    PDO    $pdo,
    int    $para_uid,
    string $tipo,
    string $titulo,
    string $mensaje  = '',
    ?int   $de_uid   = null,   // guardado en mensaje como referencia
    ?int   $ref_id   = null
): void {
    // Evitar duplicados del mismo tipo en el mismo día para el mismo usuario
    try {
        $ex = $pdo->prepare("
            SELECT id FROM notificaciones
            WHERE usuario_id=? AND tipo=? AND DATE(created_at)=CURDATE()
            LIMIT 1
        ");
        $ex->execute([$para_uid, $tipo]);
        if ($ex->fetch()) {
            // Actualizar en vez de duplicar
            $pdo->prepare("
                UPDATE notificaciones
                SET titulo=?, mensaje=?, leido=0
                WHERE usuario_id=? AND tipo=? AND DATE(created_at)=CURDATE()
            ")->execute([$titulo, $mensaje ?: null, $para_uid, $tipo]);
            return;
        }
    } catch (\PDOException $e) { /* tabla puede no existir */ return; }

    $pdo->prepare("
        INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, leido)
        VALUES (?, ?, ?, ?, 0)
    ")->execute([$para_uid, $tipo, $titulo, $mensaje ?: null]);
}

/**
 * Cuenta notificaciones no leídas del usuario.
 */
function contar_no_leidas(PDO $pdo, int $uid): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leido=0");
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/**
 * Marca como leídas todas las notificaciones de un usuario.
 */
function marcar_leidas(PDO $pdo, int $uid, ?string $tipo = null): void {
    $where  = $tipo ? "usuario_id=? AND tipo=? AND leido=0" : "usuario_id=? AND leido=0";
    $params = $tipo ? [$uid, $tipo] : [$uid];
    $pdo->prepare("UPDATE notificaciones SET leido=1 WHERE $where")->execute($params);
}

/**
 * Crear notificación interna para todos los admins/rrhh.
 */
function crear_notificacion_admins(PDO $pdo, string $titulo, string $mensaje, int $de_uid = 0): void {
    try {
        $st = $pdo->query("
            SELECT DISTINCT ur.usuario_id FROM usuarios_roles ur
            JOIN roles_sistema r ON r.id = ur.rol_id
            WHERE r.nombre IN ('admin','rrhh')
        ");
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['usuario_id'] !== $de_uid) {
                crear_notificacion($pdo, (int)$r['usuario_id'], 'ausencia_solicitud', $titulo, $mensaje, $de_uid, 0);
            }
        }
    } catch (Throwable) {}
}