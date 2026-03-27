<?php
declare(strict_types=1);

// ── Roles con acceso total al sistema ────────────────────────────────────────
// admin y rrhh pueden hacer todo: crear eventos, modificar cualquier checklist,
// ver y gestionar todas las ausencias
const ROLES_ADMIN = ['admin', 'rrhh'];

// Roles que pueden crear eventos y ver reportes
const ROLES_GESTION = ['admin', 'rrhh', 'direccion'];

// Roles que pueden aprobar ausencias (de su área o todas)
const ROLES_APROBADOR = ['admin', 'rrhh', 'coordinador'];

if (!function_exists('has_any_role')) {
    function has_any_role(string ...$wanted): bool {
        $roles = $_SESSION['roles'] ?? [];
        foreach ($wanted as $w) {
            if (in_array($w, $roles, true)) return true;
        }
        return false;
    }
}

function is_admin_or_rrhh(): bool {
    return has_any_role('admin', 'rrhh');
}

function puede_crear_eventos(): bool {
    return has_any_role('admin', 'rrhh', 'direccion', 'coordinador');
}

function puede_aprobar_ausencias(): bool {
    return has_any_role('admin', 'rrhh', 'coordinador');
}

// ── Etiquetas de tipo de movimiento ──────────────────────────────────────────
if (!function_exists('tipo_label')) {
    function tipo_label(string $tipo): string {
        return match($tipo) {
            'entrada'        => '<span class="badge verde">↓ Entrada</span>',
            'salida'         => '<span class="badge rojo">↑ Salida</span>',
            'salida_almuerzo'=> '<span class="badge amarillo">☕ Salida almuerzo</span>',
            'regreso_almuerzo'=>'<span class="badge verde">↩ Regreso almuerzo</span>',
            default          => '<span class="badge gris">'.e($tipo).'</span>',
        };
    }
}

// ── Formateo de tiempo ────────────────────────────────────────────────────────
if (!function_exists('fmt_hm')) {
    function fmt_hm(int $minutos): string {
        if ($minutos <= 0) return '0h 0m';
        return intdiv($minutos, 60) . 'h ' . ($minutos % 60) . 'm';
    }
}

if (!function_exists('fmt_fecha')) {
    function fmt_fecha(string $fecha, bool $conHora = false): string {
        $fmt = $conHora ? 'd/m/Y H:i' : 'd/m/Y';
        return date($fmt, strtotime($fecha));
    }
}
