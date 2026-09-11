START TRANSACTION;

CREATE TEMPORARY TABLE incoming_doctors (
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(220) NOT NULL,
    title VARCHAR(100) NOT NULL,
    specialty ENUM('OG', 'ATI', 'NN') NOT NULL,
    department VARCHAR(255) NOT NULL,
    guard_eligible TINYINT(1) NOT NULL,
    rotation_position INT UNSIGNED NULL,
    PRIMARY KEY (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO incoming_doctors
    (first_name, last_name, display_name, title, specialty, department, guard_eligible, rotation_position)
VALUES
    ('Diana Elena', 'COMANDASU', 'Diana Elena COMANDASU', 'MEDIC PRIMAR', 'OG', 'COMP. INFERTILITATII CUPLULUI SI RUA', 1, 6),
    ('Dan Virgil', 'DEVA', 'Dan Virgil DEVA', 'MEDIC PRIMAR', 'OG', 'COMP. MEDICINA MATERNO-FETALA', 1, 10),
    ('Andrei Aurelian', 'CUCU', 'Andrei Aurelian CUCU', 'MEDIC PRIMAR', 'OG', 'COMP. UROGINECOLOGIE', 1, 9),
    ('Sami', 'SALAMA', 'Sami SALAMA', 'MEDIC PRIMAR', 'OG', 'CRPFC', 1, 20),

    ('Izabela Nicoleta', 'ANGHELESCU', 'Izabela Nicoleta ANGHELESCU', 'MEDIC PRIMAR', 'ATI', 'SECTIA A T I', 1, 1),
    ('Livia Alina', 'FRANGULEA', 'Livia Alina FRANGULEA', 'MEDIC PRIMAR', 'ATI', 'SECTIA A T I', 1, 2),
    ('Ioana Simona', 'GANGURA', 'Ioana Simona GANGURA', 'MEDIC SPECIALIST', 'ATI', 'SECTIA A T I', 1, 3),
    ('Madalina Corina Lucia', 'MOISA', 'Madalina Corina Lucia MOISA', 'MEDIC PRIMAR', 'ATI', 'SECTIA A T I', 1, 4),
    ('Oana', 'PODASCA', 'Oana PODASCA', 'MEDIC PRIMAR', 'ATI', 'SECTIA A T I', 1, 5),
    ('Ioana Daniela', 'PREDESCU', 'Ioana Daniela PREDESCU', 'MEDIC PRIMAR', 'ATI', 'SECTIA A T I', 1, 6),

    ('Maria Bianca', 'ANCA-STANCIU', 'Maria Bianca ANCA-STANCIU', 'MEDIC SPECIALIST', 'OG', 'SECTIA I - OG', 1, 1),
    ('Alexandra Irma Gabriela', 'BAUSIC', 'Alexandra Irma Gabriela BAUSIC', 'MEDIC SPECIALIST', 'OG', 'SECTIA III - OG', 1, 2),
    ('Bogdan', 'BOTEZATU', 'Bogdan BOTEZATU', 'MEDIC PRIMAR', 'OG', 'SECTIA I - OG', 1, 3),
    ('Elvira', 'BRATILA', 'Elvira BRATILA', 'MEDIC PRIMAR SEF', 'OG', 'SECTIA II - OG', 1, 4),
    ('Andreea Marilena', 'CARP-VELISCU', 'Andreea Marilena CARP-VELISCU', 'MEDIC PRIMAR SEF', 'OG', 'SECTIA I - OG', 1, 5),
    ('Bogdan Catalin', 'COROLEUCA', 'Bogdan Catalin COROLEUCA', 'MEDIC PRIMAR', 'OG', 'SECTIA II - OG', 1, 7),
    ('Ciprian Andrei', 'COROLEUCA', 'Ciprian Andrei COROLEUCA', 'MEDIC PRIMAR', 'OG', 'SECTIA III - OG', 1, 8),
    ('Laura Elena', 'DORDEA LEONTE', 'Laura Elena DORDEA LEONTE', 'MEDIC PRIMAR', 'OG', 'SECTIA I - OG', 1, 11),
    ('Calina', 'MAIER', 'Calina MAIER', 'MEDIC PRIMAR', 'OG', 'SECTIA II - OG', 1, 12),
    ('Traian', 'MAIER', 'Traian MAIER', 'MEDIC PRIMAR', 'OG', 'SECTIA I - OG', 1, 13),
    ('Andrei', 'MANU', 'Andrei MANU', 'MEDIC SPECIALIST', 'OG', 'SECTIA II - OG', 1, 14),
    ('Mihai', 'MITRAN', 'Mihai MITRAN', 'MEDIC PRIMAR SEF', 'OG', 'SECTIA III - OG', 1, 15),
    ('Ovidiu Marcel', 'MOISA', 'Ovidiu Marcel MOISA', 'MEDIC PRIMAR', 'OG', 'SECTIA II - OG', 1, 16),
    ('Elena Silvia', 'NADA', 'Elena Silvia NADA', 'MEDIC SPECIALIST', 'OG', 'SECTIA II - OG', 1, 17),
    ('Manuela Stefana', 'NEAGU', 'Manuela Stefana NEAGU', 'MEDIC PRIMAR', 'OG', 'SECTIA I - OG', 1, 18),
    ('Petrisor Doru', 'PANA', 'Petrisor Doru PANA', 'MEDIC PRIMAR', 'OG', 'SECTIA I - OG', 1, 19),
    ('Lavinia Madalina', 'SICHIM', 'Lavinia Madalina SICHIM', 'MEDIC SPECIALIST', 'OG', 'SECTIA III - OG', 1, 21),

    ('Ana Daniela', 'ANDRONE', 'Ana Daniela ANDRONE', 'MEDIC SPECIALIST', 'NN', 'SECTIA NEONATOLOGIE II', 1, 1),
    ('Roxana Mihaela', 'BRATU', 'Roxana Mihaela BRATU', 'MEDIC PRIMAR SEF', 'NN', 'SECTIA NEONATOLOGIE II', 1, 2),
    ('Catalina Elena', 'HOFMAN', 'Catalina Elena HOFMAN', 'MEDIC SPECIALIST', 'NN', 'SECTIA NEONATOLOGIE II', 1, 3),
    ('Ancuta', 'MOCANU', 'Ancuta MOCANU', 'MEDIC SPECIALIST', 'NN', 'SECTIA NEONATOLOGIE I', 1, 4),
    ('Elena', 'PANA', 'Elena PANA', 'MEDIC SPECIALIST', 'NN', 'SECTIA NEONATOLOGIE I', 1, 5),
    ('Andrei Gheorghe', 'PREDA', 'Andrei Gheorghe PREDA', 'MEDIC PRIMAR', 'NN', 'SECTIA NEONATOLOGIE II', 1, 6),
    ('Ioana', 'ROSCA', 'Ioana ROSCA', 'MEDIC PRIMAR', 'NN', 'SECTIA NEONATOLOGIE I', 1, 7),
    ('Oana', 'SAULEA', 'Oana SAULEA', 'MEDIC PRIMAR', 'NN', 'SECTIA NEONATOLOGIE I', 1, 8),
    ('Marcela', 'SERBAN', 'Marcela SERBAN', 'MEDIC PRIMAR', 'NN', 'SECTIA NEONATOLOGIE II', 1, 9),
    ('Monica', 'SUSANU', 'Monica SUSANU', 'MEDIC SPECIALIST', 'NN', 'SECTIA NEONATOLOGIE I', 1, 10),
    ('Raluca', 'TOCARIU', 'Raluca TOCARIU', 'MEDIC PRIMAR SEF', 'NN', 'SECTIA NEONATOLOGIE I', 1, 11),
    ('Brindusa Nicoleta', 'VASILIU', 'Brindusa Nicoleta VASILIU', 'MEDIC PRIMAR', 'NN', 'SECTIA NEONATOLOGIE I', 1, 12);

-- Preserve existing primary keys so old guards, operations and user accounts remain linked.
UPDATE people SET first_name='Bogdan', last_name='BOTEZATU' WHERE id=17;
UPDATE people SET first_name='Traian', last_name='MAIER' WHERE id=18;
UPDATE people SET first_name='Calina', last_name='MAIER' WHERE id=19;
UPDATE people SET first_name='Andrei Aurelian', last_name='CUCU' WHERE id=20;
UPDATE people SET first_name='Petrisor Doru', last_name='PANA' WHERE id=21;
UPDATE people SET first_name='Ciprian Andrei', last_name='COROLEUCA' WHERE id=22;
UPDATE people SET first_name='Andrei', last_name='MANU' WHERE id=23;
UPDATE people SET first_name='Bogdan Catalin', last_name='COROLEUCA' WHERE id=24;
UPDATE people SET first_name='Elena Silvia', last_name='NADA' WHERE id=25;
UPDATE people SET first_name='Lavinia Madalina', last_name='SICHIM' WHERE id=26;
UPDATE people SET first_name='Mihai', last_name='MITRAN' WHERE id=27;
UPDATE people SET first_name='Alexandra Irma Gabriela', last_name='BAUSIC' WHERE id=28;
UPDATE people SET first_name='Elvira', last_name='BRATILA' WHERE id=32;

INSERT INTO people
    (first_name, last_name, display_name, title, specialty, professional_category, rotation_position, active, guard_eligible, notes)
SELECT
    i.first_name, i.last_name, i.display_name, i.title, i.specialty, 'Medic',
    i.rotation_position, 1, i.guard_eligible, CONCAT('Structura: ', i.department)
FROM incoming_doctors i
WHERE NOT EXISTS (
    SELECT 1 FROM people p
    WHERE p.first_name = i.first_name AND p.last_name = i.last_name
);

UPDATE people p
JOIN incoming_doctors i ON i.first_name = p.first_name AND i.last_name = p.last_name
SET p.display_name = i.display_name,
    p.title = i.title,
    p.specialty = i.specialty,
    p.professional_category = 'Medic',
    p.rotation_position = i.rotation_position,
    p.active = 1,
    p.guard_eligible = i.guard_eligible,
    p.notes = CONCAT('Structura: ', i.department);

UPDATE people p
LEFT JOIN incoming_doctors i ON i.first_name = p.first_name AND i.last_name = p.last_name
SET p.active = 0,
    p.guard_eligible = 0,
    p.rotation_position = NULL
WHERE i.first_name IS NULL;

INSERT INTO audit_logs
    (user_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent)
VALUES
    (5, 'admin.roster_imported', 'people_roster', NULL, NULL,
     JSON_OBJECT('source', 'lista medici 2026-08-06', 'active_people', 39, 'OG', 21, 'ATI', 6, 'NN', 12),
     '127.0.0.1', 'Codex roster migration');

DROP TEMPORARY TABLE incoming_doctors;

COMMIT;
