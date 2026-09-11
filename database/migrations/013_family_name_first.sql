START TRANSACTION;

UPDATE people
SET display_name=TRIM(CONCAT(last_name, ' ', first_name))
WHERE display_name<>TRIM(CONCAT(last_name, ' ', first_name));

COMMIT;
