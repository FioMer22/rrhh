<?php
declare(strict_types=1);

/**
 * Carga ausencias APROBADAS en un rango para un conjunto de usuarios.
 * Retorna: [usuario_id => [fecha => ['tipo'=>'Vacaciones', 'color'=>'#...']]]
 */
function cargar_ausencias_aprobadas(PDO $pdo, string $desde, string $hasta, array $userIds = []): array {
    $whereU = $userIds ? 'AND sa.usuario_id IN (' . implode(',', array_map('intval', $userIds)) . ')' : '';

    $colores = [
        'vacaciones'           => '#0369a1',
        'licencia médica'      => '#be123c',
        'licencia medica'      => '#be123c',
        'permiso personal'     => '#7c3aed',
        'permiso por horas'    => '#0891b2',
        'trabajo remoto'       => '#059669',
        'trabajo remoto (día)' => '#059669',
    ];

    $st = $pdo->prepare("
        SELECT sa.usuario_id,
               sa.inicio,
               sa.fin,
               ta.nombre AS tipo_nombre
        FROM solicitudes_ausencia sa
        JOIN tipos_ausencia ta ON ta.id = sa.tipo_ausencia_id
        WHERE sa.estado = 'aprobado'
          AND sa.inicio <= :hasta
          AND sa.fin    >= :desde
          $whereU
        ORDER BY sa.inicio ASC
    ");
    $st->execute([':desde' => $desde, ':hasta' => $hasta]);
    $rows = $st->fetchAll();

    // Expandir por días
    $mapa = [];
    foreach ($rows as $r) {
        $uid   = (int)$r['usuario_id'];
        $label = $r['tipo_nombre'];
        $color = $colores[strtolower(trim($label))] ?? '#6b7280';

        $cur = new DateTime($r['inicio']);
        $fin = new DateTime($r['fin']);
        while ($cur <= $fin) {
            $fecha = $cur->format('Y-m-d');
            if ($fecha >= $desde && $fecha <= $hasta) {
                $mapa[$uid][$fecha] = ['tipo' => $label, 'color' => $color];
            }
            $cur->modify('+1 day');
        }
    }
    return $mapa;
}

/**
 * Estado actual de un usuario para el panel de presencia.
 * Retorna: ['estado' => 'oficina|viaje|actividad|ausente|desconocido', 'detalle' => '...']
 */
function estado_actual_usuario(PDO $pdo, int $uid): array {
    $hoy  = date('Y-m-d');
    $ahora= date('Y-m-d H:i:s');

    // ¿En viaje?
    $stV = $pdo->prepare("SELECT titulo, destino FROM viajes WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
    $stV->execute([$uid]);
    if ($v = $stV->fetch()) {
        return ['estado'=>'viaje','detalle'=>$v['titulo'].($v['destino'] ? ' · '.$v['destino'] : '')];
    }

    // ¿En actividad en progreso?
    $stA = $pdo->prepare("
        SELECT titulo FROM actividades
        WHERE usuario_id=? AND estado='en_progreso'
          AND (inicio_real IS NULL OR inicio_real <= ?)
        LIMIT 1
    ");
    $stA->execute([$uid, $ahora]);
    if ($a = $stA->fetch()) {
        return ['estado'=>'actividad','detalle'=>$a['titulo']];
    }

    // ¿Ausencia aprobada hoy?
    $stAu = $pdo->prepare("
        SELECT ta.nombre FROM solicitudes_ausencia sa
        JOIN tipos_ausencia ta ON ta.id=sa.tipo_ausencia_id
        WHERE sa.usuario_id=? AND sa.estado='aprobado'
          AND sa.inicio <= ? AND sa.fin >= ?
        LIMIT 1
    ");
    $stAu->execute([$uid, $hoy, $hoy]);
    if ($au = $stAu->fetch()) {
        return ['estado'=>'ausente','detalle'=>$au['nombre']];
    }

    // ¿Marcó entrada hoy?
    $stM = $pdo->prepare("
        SELECT tipo FROM asistencia_marcas
        WHERE usuario_id=? AND DATE(fecha_hora)=?
        ORDER BY fecha_hora DESC LIMIT 1
    ");
    $stM->execute([$uid, $hoy]);
    $marca = $stM->fetch();
    if ($marca) {
        if ($marca['tipo'] === 'fin_jornada') {
            return ['estado'=>'fuera','detalle'=>'Salió'];
        }
        if ($marca['tipo'] === 'pausa_inicio') {
            return ['estado'=>'almuerzo','detalle'=>'En almuerzo'];
        }
        return ['estado'=>'oficina','detalle'=>'En oficina'];
    }

    return ['estado'=>'desconocido','detalle'=>'Sin registro hoy'];
}
