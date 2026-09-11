ALTER TABLE people
    ADD COLUMN guard_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER active,
    ADD INDEX idx_people_guard_eligible (specialty, guard_eligible, active);
