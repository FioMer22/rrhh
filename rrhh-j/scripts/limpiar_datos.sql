-- ============================================================
-- SCRIPT DE LIMPIEZA Y NORMALIZACIÓN — RRHH Jesús Responde
-- Ejecutar en phpMyAdmin con la BD d046530d seleccionada
-- Leer TODO antes de ejecutar. Hacerlo sección por sección.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- SECCIÓN 1: ROLES DEL SISTEMA
-- Conservar: admin, rrhh, lider, colaborador
-- Renombrar: unificar criterio de nombres
-- ────────────────────────────────────────────────────────────

-- Ver roles actuales antes de tocar nada:
SELECT * FROM roles_sistema;

-- Eliminar rol coordinador_eventos si no tiene usuarios asignados
-- (verificar primero):
SELECT COUNT(*) FROM usuarios WHERE rol_base_id = (
    SELECT id FROM roles_sistema WHERE nombre = 'coordinador_eventos'
);
-- Si devuelve 0, es seguro eliminarlo:
DELETE FROM roles_sistema WHERE nombre = 'coordinador_eventos';


-- ────────────────────────────────────────────────────────────
-- SECCIÓN 2: ÁREAS — ajustar según organigrama real
-- ────────────────────────────────────────────────────────────

-- Ver áreas actuales:
SELECT * FROM areas;

-- Renombrar áreas que no coinciden con el organigrama:
UPDATE areas SET nombre = 'Administración'     WHERE nombre IN ('Operaciones', 'IT');
-- NOTA: revisar cuál es cuál antes de ejecutar. Hacerlo una por una si hay dudas.

-- Áreas que faltan según el organigrama (agregar las que no existen):
INSERT IGNORE INTO areas (nombre, activo) VALUES
    ('Dirección Ejecutiva',       1),
    ('Secretaría General',        1),
    ('Dirección Administrativa',  1),
    ('Dirección Operativa',       1),
    ('Contabilidad',              1),
    ('Recepción',                 1),
    ('Tesorería',                 1),
    ('Servicios Generales',       1),
    ('Logística',                 1),
    ('Informática',               1),
    ('Sonido',                    1),
    ('Evangelismo',               1),
    ('Equipo JR',                 1),
    ('Comunicaciones',            1),
    ('Base de Datos',             1),
    ('Bienestar del Personal',    1),
    ('Fundraising',               1),
    ('Maratón',                   1),
    ('Producción de Materiales',  1),
    ('Capellanía Hospitalaria',   1);


-- ────────────────────────────────────────────────────────────
-- SECCIÓN 3: USUARIOS — limpiar datos de prueba
-- ────────────────────────────────────────────────────────────

-- Ver usuarios actuales:
SELECT id, nombre, apellido, email, rol_base_id FROM usuarios ORDER BY id;

-- Eliminar usuarios de prueba (verificar IDs antes):
-- "Eli Test" (id=3) y "Berenice Test" (id=4) son datos de prueba
-- SOLO ejecutar si estás seguro de que no tienen datos asociados:
SELECT COUNT(*) FROM asistencia       WHERE usuario_id IN (3, 4);
SELECT COUNT(*) FROM solicitudes_ausencia WHERE usuario_id IN (3, 4);
-- Si ambas devuelven 0:
DELETE FROM usuarios WHERE id IN (3, 4) AND email LIKE '%test%';

-- Consolidar Israel Medina: el usuario real es israel@jesusresponde.com (id=1)
-- Desactivar el duplicado isramedi86@gmail.com (id=10):
UPDATE usuarios SET activo = 0 WHERE id = 10 AND email = 'isramedi86@gmail.com';


-- ────────────────────────────────────────────────────────────
-- SECCIÓN 4: USUARIOS REALES — asignar áreas y roles correctos
-- Basado en el organigrama
-- ────────────────────────────────────────────────────────────

-- Israel Medina → Área Técnica, rol admin
UPDATE usuarios SET
    area_id = (SELECT id FROM areas WHERE nombre = 'Técnica' LIMIT 1),
    rol_base_id = (SELECT id FROM roles_sistema WHERE nombre = 'admin' LIMIT 1)
WHERE email = 'israel@jesusresponde.com';

-- Paola Verdún → RRHH / Secretaría General, rol rrhh
UPDATE usuarios SET
    area_id = (SELECT id FROM areas WHERE nombre = 'RRHH' LIMIT 1),
    rol_base_id = (SELECT id FROM roles_sistema WHERE nombre = 'rrhh' LIMIT 1)
WHERE email = 'paola@jesusresponde.com';

-- Sandra Ortega → Base de Datos, rol lider
UPDATE usuarios SET
    area_id = (SELECT id FROM areas WHERE nombre = 'Base de Datos' LIMIT 1),
    rol_base_id = (SELECT id FROM roles_sistema WHERE nombre = 'lider' LIMIT 1)
WHERE email = 'sandra@jesusresponde.com';

-- Katherin Lopez → Contabilidad (Cajas), rol colaborador
UPDATE usuarios SET
    area_id = (SELECT id FROM areas WHERE nombre = 'Contabilidad' LIMIT 1)
WHERE email = 'caja@jesusresponde.com';

-- Alma Sosa → Comunicaciones (Community Manager), rol colaborador
UPDATE usuarios SET
    area_id = (SELECT id FROM areas WHERE nombre = 'Comunicaciones' LIMIT 1)
WHERE email = 'alma@jesusresponde.com';


-- ────────────────────────────────────────────────────────────
-- SECCIÓN 5: QUIÉN PUEDE CREAR EVENTOS
-- Dirección Operativa (Verónica Neufeld) y Secretaría General (Paola Verdún)
-- deben tener rol 'lider' para poder crear eventos en el sistema
-- ────────────────────────────────────────────────────────────

-- Cuando cargues a Verónica Neufeld, asignarle:
-- area_id = Dirección Operativa, rol_base_id = lider
-- (o rol 'rrhh' si querés que también vea los informes de RRHH)

-- ────────────────────────────────────────────────────────────
-- SECCIÓN 6: CAMPO CI — agregar si no existe
-- ────────────────────────────────────────────────────────────

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS ci VARCHAR(20) NULL AFTER apellido;

-- Verificar resultado final:
SELECT u.id, u.nombre, u.apellido, u.email, u.activo,
       a.nombre AS area, r.nombre AS rol
FROM usuarios u
LEFT JOIN areas a        ON a.id = u.area_id
LEFT JOIN roles_sistema r ON r.id = u.rol_base_id
ORDER BY r.nombre, a.nombre, u.nombre;
