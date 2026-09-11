START TRANSACTION;

UPDATE people
SET guard_eligible = 1
WHERE active = 1;

UPDATE people
SET rotation_position = CASE
    WHEN last_name = 'ANCA-STANCIU' AND first_name = 'Maria Bianca' THEN 1
    WHEN last_name = 'BAUSIC' AND first_name = 'Alexandra Irma Gabriela' THEN 2
    WHEN last_name = 'BOTEZATU' AND first_name = 'Bogdan' THEN 3
    WHEN last_name = 'BRATILA' AND first_name = 'Elvira' THEN 4
    WHEN last_name = 'CARP-VELISCU' AND first_name = 'Andreea Marilena' THEN 5
    WHEN last_name = 'COMANDASU' AND first_name = 'Diana Elena' THEN 6
    WHEN last_name = 'COROLEUCA' AND first_name = 'Bogdan Catalin' THEN 7
    WHEN last_name = 'COROLEUCA' AND first_name = 'Ciprian Andrei' THEN 8
    WHEN last_name = 'CUCU' AND first_name = 'Andrei Aurelian' THEN 9
    WHEN last_name = 'DEVA' AND first_name = 'Dan Virgil' THEN 10
    WHEN last_name = 'DORDEA LEONTE' AND first_name = 'Laura Elena' THEN 11
    WHEN last_name = 'MAIER' AND first_name = 'Calina' THEN 12
    WHEN last_name = 'MAIER' AND first_name = 'Traian' THEN 13
    WHEN last_name = 'MANU' AND first_name = 'Andrei' THEN 14
    WHEN last_name = 'MITRAN' AND first_name = 'Mihai' THEN 15
    WHEN last_name = 'MOISA' AND first_name = 'Ovidiu Marcel' THEN 16
    WHEN last_name = 'NADA' AND first_name = 'Elena Silvia' THEN 17
    WHEN last_name = 'NEAGU' AND first_name = 'Manuela Stefana' THEN 18
    WHEN last_name = 'PANA' AND first_name = 'Petrisor Doru' THEN 19
    WHEN last_name = 'SALAMA' AND first_name = 'Sami' THEN 20
    WHEN last_name = 'SICHIM' AND first_name = 'Lavinia Madalina' THEN 21
    ELSE rotation_position
END
WHERE active = 1 AND specialty = 'OG';

INSERT INTO audit_logs
    (user_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent)
VALUES
    (5, 'admin.guard_eligibility_all_enabled', 'people_roster', NULL, NULL,
     JSON_OBJECT('active_people', 39, 'OG', 21, 'ATI', 6, 'NN', 12),
     '127.0.0.1', 'Codex roster migration');

COMMIT;
