START TRANSACTION;

CREATE TABLE IF NOT EXISTS og_line1_rotation (
    person_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_og_rotation_person FOREIGN KEY (person_id) REFERENCES people(id),
    UNIQUE KEY uq_og_rotation_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guard_generation_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    specialty ENUM('OG', 'ATI', 'NN') NOT NULL DEFAULT 'OG',
    line_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    months_count SMALLINT UNSIGNED NOT NULL,
    start_person_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft', 'published', 'superseded') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    published_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    CONSTRAINT fk_generation_start_person FOREIGN KEY (start_person_id) REFERENCES people(id),
    CONSTRAINT fk_generation_created_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_generation_published_user FOREIGN KEY (published_by) REFERENCES users(id),
    INDEX idx_generation_status (specialty, line_no, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guard_generation_batch_versions (
    batch_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (batch_id, version_id),
    CONSTRAINT fk_batch_versions_batch FOREIGN KEY (batch_id) REFERENCES guard_generation_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_batch_versions_plan FOREIGN KEY (plan_id) REFERENCES guard_plans(id),
    CONSTRAINT fk_batch_versions_version FOREIGN KEY (version_id) REFERENCES guard_plan_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @next_og_rotation_position := COALESCE((SELECT MAX(position) FROM og_line1_rotation), 0);

INSERT INTO og_line1_rotation (person_id, position)
SELECT candidates.id, (@next_og_rotation_position := @next_og_rotation_position + 1)
FROM (
    SELECT p.id
    FROM people p
    WHERE p.active=1 AND p.guard_eligible=1 AND p.specialty='OG'
      AND p.professional_category NOT LIKE 'Rezident%'
      AND NOT EXISTS (
          SELECT 1 FROM og_line1_rotation existing WHERE existing.person_id=p.id
      )
    ORDER BY p.rotation_position IS NULL, p.rotation_position, p.last_name, p.first_name, p.id
) candidates;

COMMIT;
