ALTER TABLE guard_assignments
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER assignment_state,
    ADD CONSTRAINT fk_guard_assignment_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    ADD INDEX idx_guard_assignment_creator (created_by_user_id);

ALTER TABLE users
    ADD COLUMN api_access_level ENUM('none','read','schedule','full') NOT NULL DEFAULT 'none' AFTER status;

INSERT INTO roles (code,name,description,is_system) VALUES
    ('platform_admin','Administrator platforma','Acces complet, inclusiv utilizatori, loguri si tokenuri',1),
    ('doctor_admin','Medic administrator','Control operational complet pentru garzi si Program operator',1),
    ('doctor_standard','Medic standard','Garzi implicate si operatii proprii',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),is_system=VALUES(is_system);

UPDATE roles SET name='Superuser',description='Acces complet; poate fi administrat de Administratorul platformei' WHERE code='super_admin';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='platform_admin'
   OR (r.code='doctor_admin' AND p.code IN ('guards.view','guards.own_changes','guards.admin_correction','progop.view','progop.create','progop.manage_all'))
   OR (r.code='doctor_standard' AND p.code IN ('guards.view','guards.own_changes','progop.view','progop.create','progop.edit_own'))
   OR (r.code='assistant_editor' AND p.code IN ('guards.view','progop.view','progop.create','progop.manage_all','reports.download'))
   OR (r.code='assistant_viewer' AND p.code IN ('guards.view','progop.view','reports.download'));

UPDATE users u
JOIN user_roles ur ON ur.user_id=u.id
JOIN roles r ON r.id=ur.role_id AND r.code IN ('super_admin','platform_admin')
SET u.api_access_level='full';
