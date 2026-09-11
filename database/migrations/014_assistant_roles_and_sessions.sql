ALTER TABLE people
    MODIFY specialty ENUM('OG', 'ATI', 'NN', 'ASISTENTA') NOT NULL;

INSERT INTO roles (code, name, description, is_system) VALUES
    ('assistant_viewer', 'Asistenta - vizualizare si tiparire', 'Consulta si exporta Programul operator', 1),
    ('assistant_editor', 'Asistenta - modificare Program operator', 'Consulta si modifica Programul operator', 1)
ON DUPLICATE KEY UPDATE
    name=VALUES(name), description=VALUES(description), is_system=VALUES(is_system);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE (r.code = 'assistant_viewer' AND p.code IN ('progop.view', 'reports.download'))
   OR (r.code = 'assistant_editor' AND p.code IN ('progop.view', 'progop.create', 'progop.manage_all', 'reports.download'));
