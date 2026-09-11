START TRANSACTION;

CREATE TEMPORARY TABLE incoming_residents (
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(220) NOT NULL,
    resident_year VARCHAR(40) NOT NULL,
    detail VARCHAR(100) NULL,
    rotation_position INT UNSIGNED NOT NULL,
    PRIMARY KEY (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO incoming_residents
    (first_name, last_name, display_name, resident_year, detail, rotation_position)
VALUES
    ('Carina', 'IANCU', 'Carina IANCU', 'Rezident an I', 'OG3', 22),
    ('Alexandra', 'IURUC', 'Alexandra IURUC', 'Rezident an I', 'OG3', 23),
    ('Catalina', 'DASCALETE', 'Catalina DASCALETE', 'Rezident an I', 'OG2', 24),
    ('Diana', 'NIȚU', 'Diana NIȚU', 'Rezident an I', 'OG1', 25),
    ('Ilinca', 'PREDESCU', 'Ilinca PREDESCU', 'Rezident an I', 'OG1', 26),
    ('Mara', 'STEFAN', 'Mara STEFAN', 'Rezident an I', 'CF2', 27),
    ('Ioana', 'ILIE', 'Ioana ILIE', 'Rezident an II', NULL, 28),
    ('Bianca Maria', 'NEACSU', 'Bianca Maria NEACSU', 'Rezident an II', NULL, 29),
    ('Alexandra', 'DUMITRACHE', 'Alexandra DUMITRACHE', 'Rezident an III', NULL, 30),
    ('Ioana', 'BUJU', 'Ioana BUJU', 'Rezident an III', NULL, 31),
    ('Miruna', 'PEIU', 'Miruna PEIU', 'Rezident an III', NULL, 32),
    ('Bianca', 'NITESCU', 'Bianca NITESCU', 'Rezident an IV', NULL, 33),
    ('Arina', 'BANU', 'Arina BANU', 'Rezident an IV', NULL, 34),
    ('Ionela', 'BADESCU', 'Ionela BADESCU', 'Rezident an IV', NULL, 35),
    ('Diana', 'BADILA', 'Diana BADILA', 'Rezident an IV', NULL, 36),
    ('Anca', 'HASHEMI', 'Anca HASHEMI', 'Rezident an IV', NULL, 37),
    ('Cristina', 'LAIOS', 'Cristina LAIOS', 'Rezident an IV', NULL, 38),
    ('Bianca', 'VITAN', 'Bianca VITAN', 'Rezident an IV', NULL, 39),
    ('Andrei', 'PRICOPE', 'Andrei PRICOPE', 'Rezident an IV', NULL, 40),
    ('Stefania', 'LITEANU', 'Stefania LITEANU', 'Rezident an V', NULL, 41),
    ('Diana', 'PIRLEA', 'Diana PIRLEA', 'Rezident an V', NULL, 42),
    ('Alexandra', 'SULEA', 'Alexandra SULEA', 'Rezident an V', NULL, 43),
    ('Cristina', 'IACOB', 'Cristina IACOB', 'Rezident an V', NULL, 44);

-- Keep the former resident ids where possible so historical records remain linked.
UPDATE people SET first_name='Bianca Maria', last_name='NEACSU' WHERE id=30;
UPDATE people SET first_name='Alexandra', last_name='SULEA' WHERE id=31;

INSERT INTO people
    (first_name, last_name, display_name, title, specialty, professional_category,
     rotation_position, active, guard_eligible, notes)
SELECT
    r.first_name, r.last_name, r.display_name, '', 'OG', r.resident_year,
    r.rotation_position, 1, 1,
    CONCAT('Rezident OG', IF(r.detail IS NULL, '', CONCAT(' - ', r.detail)))
FROM incoming_residents r
WHERE NOT EXISTS (
    SELECT 1 FROM people p
    WHERE p.first_name=r.first_name AND p.last_name=r.last_name
);

UPDATE people p
JOIN incoming_residents r ON r.first_name=p.first_name AND r.last_name=p.last_name
SET p.display_name=r.display_name,
    p.title='',
    p.specialty='OG',
    p.professional_category=r.resident_year,
    p.rotation_position=r.rotation_position,
    p.active=1,
    p.guard_eligible=1,
    p.notes=CONCAT('Rezident OG', IF(r.detail IS NULL, '', CONCAT(' - ', r.detail)));

INSERT INTO audit_logs
    (user_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent)
VALUES
    (5, 'admin.resident_roster_imported', 'people_roster', NULL, NULL,
     JSON_OBJECT('specialty', 'OG', 'residents', 23), '127.0.0.1', 'Codex resident migration');

DROP TEMPORARY TABLE incoming_residents;
COMMIT;
