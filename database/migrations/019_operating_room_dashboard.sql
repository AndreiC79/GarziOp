INSERT INTO roles (code, name, description, is_system)
VALUES (
    'operating_room_dashboard',
    'Panou bloc operator',
    'Acces exclusiv la panoul tactil al salilor pentru ieri, astazi si maine; poate actualiza fluxul, adauga urgente si muta operatii nefinalizate.',
    1
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('progop.view', 'progop.create', 'progop.manage_all')
WHERE r.code = 'operating_room_dashboard';
