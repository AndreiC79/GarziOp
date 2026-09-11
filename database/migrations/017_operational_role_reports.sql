INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'reports.download'
WHERE r.code IN ('doctor_standard', 'doctor_og', 'doctor_ati', 'doctor_nn');

UPDATE roles SET description = CASE code
    WHEN 'doctor_standard' THEN 'Gestioneaza garzile pe liniile libere ale propriei specialitati, schimburile proprii OG L1, operatiile proprii si descarcarea rapoartelor.'
    WHEN 'doctor_og' THEN 'Rol OG vechi, pastrat pentru compatibilitate; foloseste aceleasi drepturi operationale ca Medic standard.'
    WHEN 'doctor_ati' THEN 'Gestioneaza garzile ATI, are control complet asupra Programului operator si poate descarca rapoarte.'
    WHEN 'doctor_nn' THEN 'Gestioneaza garzile NN, vizualizeaza Programul operator si poate descarca rapoarte.'
    ELSE description END;
