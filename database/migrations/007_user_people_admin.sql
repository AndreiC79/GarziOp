ALTER TABLE people
    ADD COLUMN title VARCHAR(100) NULL AFTER display_name;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_person (person_id);
