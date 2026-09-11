CREATE TABLE IF NOT EXISTS guard_specialty_settings (
    specialty ENUM('OG', 'ATI', 'NN') PRIMARY KEY,
    line_count TINYINT UNSIGNED NOT NULL,
    line_labels LONGTEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_guard_line_count CHECK (line_count BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO guard_specialty_settings (specialty, line_count, line_labels) VALUES
('OG', 6, '["L1 Spec.","L2 Spec.","L3 Rez.","L4 Rez.","L5 Rez.","L6 Rez."]'),
('ATI', 1, '["L1 ATI"]'),
('NN', 2, '["L1 NN","L2 NN"]');
