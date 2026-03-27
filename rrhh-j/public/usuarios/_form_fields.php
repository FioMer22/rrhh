<?php
declare(strict_types=1);
// _form_fields.php — usa jr.css, sin Tailwind, con AJAX para equipo y campo CI

$pdo = DB::pdo();

$areaSel   = (int)($usuario['area_id']     ?? 0);
$equipoSel = (int)($usuario['equipo_id']   ?? 0);
$sedeSel   = (int)($usuario['sede_id']     ?? 0);
$jefeSel   = (int)($usuario['jefe_id']     ?? 0);
$rolSel    = (int)($usuario['rol_base_id'] ?? 0);

$areas  = $pdo->query("SELECT id, nombre FROM areas  WHERE activo=1 ORDER BY nombre")->fetchAll();
$sedes  = $pdo->query("SELECT id, nombre FROM sedes  WHERE activo=1 ORDER BY nombre")->fetchAll();
$roles  = $pdo->query("SELECT id, nombre FROM roles_sistema ORDER BY nombre")->fetchAll();
$jefes  = $pdo->query("
    SELECT id, CONCAT(nombre,' ',apellido) AS label
    FROM usuarios WHERE activo=1 AND rol_base_id=3
    ORDER BY nombre, apellido
")->fetchAll();

// Equipos del área ya seleccionada (edición)
$equipos = [];
if ($areaSel > 0) {
    $st = $pdo->prepare("SELECT id, nombre FROM equipos WHERE activo=1 AND area_id=? ORDER BY nombre");
    $st->execute([$areaSel]);
    $equipos = $st->fetchAll();
}

// Detectar columnas existentes
$colsRaw  = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(fn($c) => strtolower($c['Field']), $colsRaw);
$hasCi    = in_array('ci', $colNames, true);

// Columna de contraseña
$pwdCol = null;
foreach ($colsRaw as $c) {
    if (in_array(strtolower($c['Field']), ['password_hash','pass_hash','password','pass','clave'], true)) {
        $pwdCol = $c['Field']; break;
    }
}

// Roles extra actuales del usuario (si está editando)
$rolesExtra = [];
$usrId = (int)($usuario['id'] ?? 0);
if ($usrId > 0) {
    try {
        $stRE = $pdo->prepare("SELECT rol_id FROM usuarios_roles WHERE usuario_id = ?");
        $stRE->execute([$usrId]);
        $rolesExtra = array_column($stRE->fetchAll(), 'rol_id');
    } catch (Exception $e) { /* tabla puede no existir aún */ }
}

function opt(array $rows, int $sel, string $ph = 'Seleccionar...'): void {
    echo '<option value="0">'.e($ph).'</option>';
    foreach ($rows as $r) {
        $id    = (int)$r['id'];
        $label = (string)($r['label'] ?? $r['nombre'] ?? '');
        echo '<option value="'.$id.'"'.($id===$sel?' selected':'').'>'.e($label).'</option>';
    }
}

$esEdicion = (int)($usuario['id'] ?? 0) > 0;
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

    <!-- Nombre -->
    <div>
        <label class="meta">Nombre *</label>
        <input type="text" name="nombre" required
               value="<?= e($usuario['nombre'] ?? '') ?>"
               placeholder="Ej: María">
    </div>

    <!-- Apellido -->
    <div>
        <label class="meta">Apellido *</label>
        <input type="text" name="apellido" required
               value="<?= e($usuario['apellido'] ?? '') ?>"
               placeholder="Ej: González">
    </div>

    <!-- Email -->
    <div style="grid-column:span 2">
        <label class="meta">Email *</label>
        <input type="email" name="email" required
               value="<?= e($usuario['email'] ?? '') ?>"
               placeholder="correo@jesusresponde.com">
    </div>

    <!-- CI -->
    <?php if ($hasCi): ?>
    <div>
        <label class="meta">Cédula de Identidad</label>
        <input type="text" name="ci"
               value="<?= e($usuario['ci'] ?? '') ?>"
               placeholder="Ej: 4.567.890">
    </div>
    <div>
        <label class="meta">Teléfono</label>
        <input type="text" name="telefono"
               value="<?= e($usuario['telefono'] ?? '') ?>"
               placeholder="Ej: 0981 123 456">
    </div>
    <?php else: ?>
    <div style="grid-column:span 2;background:#fff3cd;border:1px solid #fde68a;
                border-radius:10px;padding:10px 14px;font-size:13px;color:#856404">
        ⚠ El campo <strong>CI</strong> no existe todavía en la tabla <code>usuarios</code>.
        Ejecutá esto en phpMyAdmin y recargá:
        <code style="display:block;margin-top:6px;background:#fff;padding:6px 10px;
                     border-radius:6px;border:1px solid #fde68a;font-size:12px">
            ALTER TABLE usuarios ADD COLUMN ci VARCHAR(20) NULL AFTER apellido;
        </code>
    </div>
    <div>
        <label class="meta">Teléfono</label>
        <input type="text" name="telefono"
               value="<?= e($usuario['telefono'] ?? '') ?>"
               placeholder="Ej: 0981 123 456">
    </div>
    <?php endif; ?>

    <!-- Área -->
    <div>
        <label class="meta">Área</label>
        <select id="sel_area" name="area_id">
            <?php opt($areas, $areaSel, 'Sin área asignada'); ?>
        </select>
    </div>

    <!-- Equipo — carga por AJAX sin recargar página -->
    <div>
        <label class="meta">Equipo</label>
        <select id="sel_equipo" name="equipo_id">
            <?php if ($areaSel > 0): ?>
                <?php opt($equipos, $equipoSel, 'Sin equipo'); ?>
            <?php else: ?>
                <option value="0">Seleccioná un área primero</option>
            <?php endif; ?>
        </select>
    </div>

    <!-- Sede -->
    <div>
        <label class="meta">Sede</label>
        <select name="sede_id">
            <?php opt($sedes, $sedeSel, 'Sin sede específica'); ?>
        </select>
    </div>

    <!-- Jefe -->
    <div>
        <label class="meta">Jefe directo</label>
        <select name="jefe_id">
            <?php opt($jefes, $jefeSel, 'Sin jefe asignado'); ?>
        </select>
    </div>

    <!-- Rol base -->
    <div>
        <label class="meta">Rol principal</label>
        <select name="rol_base_id">
            <?php opt($roles, $rolSel, 'Seleccionar rol'); ?>
        </select>
        <div class="meta" style="margin-top:4px">
            Define el acceso principal del usuario.
        </div>
    </div>

    <!-- Activo -->
    <div style="display:flex;align-items:center;gap:8px;padding-top:10px">
        <input type="checkbox" id="chk_activo" name="activo" value="1"
               <?= ((int)($usuario['activo'] ?? 1) === 1) ? 'checked' : '' ?>>
        <label for="chk_activo" class="meta">Usuario activo</label>
    </div>

    <!-- Roles adicionales -->
    <div style="grid-column:span 2">
        <label class="meta" style="display:block;margin-bottom:8px">
            Roles adicionales
            <span style="color:#6b7280;font-weight:normal">
                — el usuario tendrá los permisos de TODOS los roles marcados
            </span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            <?php foreach ($roles as $r):
                $rId     = (int)$r['id'];
                $rNombre = $r['nombre'];
                // No mostrar el mismo que ya es su rol base
                // Descripción corta por rol
                $desc = match($rNombre) {
                    'admin'       => 'Acceso total al sistema',
                    'rrhh'        => 'Gestión de personal e informes',
                    'direccion'   => 'Crear eventos, ver reportes',
                    'coordinador' => 'Gestiona su área',
                    'colaborador' => 'Acceso básico',
                    default       => '',
                };
            ?>
            <label style="display:flex;align-items:flex-start;gap:8px;padding:10px 14px;
                          border:1px solid #e5e7eb;border-radius:10px;cursor:pointer;
                          background:#f9fafb;min-width:160px;transition:background .1s"
                   onmouseover="this.style.background='#f0f7ff';this.style.borderColor='#93c5fd'"
                   onmouseout="this.style.background='#f9fafb';this.style.borderColor='#e5e7eb'">
                <input type="checkbox" name="roles_extra[]"
                       value="<?= $rId ?>"
                       style="margin-top:2px;accent-color:#114c97"
                       <?= in_array($rId, $rolesExtra, true) ? 'checked' : '' ?>>
                <div>
                    <div style="font-size:13px;font-weight:600"><?= e($rNombre) ?></div>
                    <?php if ($desc): ?>
                    <div style="font-size:11px;color:#6b7280;margin-top:1px"><?= e($desc) ?></div>
                    <?php endif; ?>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="meta" style="margin-top:8px">
            💡 Ejemplo: Paola Verdún tiene rol principal <strong>rrhh</strong> 
            y rol adicional <strong>direccion</strong> para crear eventos institucionales.
        </div>
    </div>

    <!-- Contraseña -->
    <?php if ($pwdCol): ?>
    <div style="grid-column:span 2">
        <label class="meta">
            <?= $esEdicion ? 'Nueva contraseña (dejá vacío para no cambiar)' : 'Contraseña inicial *' ?>
        </label>
        <input type="password" name="new_password"
               placeholder="<?= $esEdicion ? 'Sin cambios' : 'Contraseña para el usuario' ?>"
               <?= !$esEdicion ? 'required' : '' ?>>
    </div>
    <?php endif; ?>

</div>

<script>
(function () {
    const selArea   = document.getElementById('sel_area');
    const selEquipo = document.getElementById('sel_equipo');
    if (!selArea || !selEquipo) return;

    const ajaxUrl   = '<?= url('/usuarios/equipos_ajax.php') ?>';
    const selActual = <?= $equipoSel ?>;

    function cargarEquipos(areaId, preseleccionar) {
        if (!areaId || areaId == 0) {
            selEquipo.innerHTML = '<option value="0">Seleccioná un área primero</option>';
            return;
        }
        selEquipo.innerHTML = '<option value="0">Cargando...</option>';
        fetch(ajaxUrl + '?area_id=' + areaId)
            .then(r => r.json())
            .then(data => {
                selEquipo.innerHTML = '<option value="0">Sin equipo</option>';
                data.forEach(eq => {
                    const opt = document.createElement('option');
                    opt.value = eq.id;
                    opt.textContent = eq.nombre;
                    if (eq.id == preseleccionar) opt.selected = true;
                    selEquipo.appendChild(opt);
                });
            })
            .catch(() => {
                selEquipo.innerHTML = '<option value="0">Error al cargar</option>';
            });
    }

    selArea.addEventListener('change', function () {
        cargarEquipos(this.value, 0);
    });

    // Modo edición: cargar equipos del área ya seleccionada
    if (selArea.value && selArea.value != '0') {
        cargarEquipos(selArea.value, selActual);
    }
})();
</script>
