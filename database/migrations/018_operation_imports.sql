CREATE TABLE IF NOT EXISTS operation_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    source_filename VARCHAR(255) NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('completed', 'undone') NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    undone_at DATETIME NULL,
    undone_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_operation_imports_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_operation_imports_undone_by FOREIGN KEY (undone_by) REFERENCES users(id),
    INDEX idx_operation_imports_user (user_id, created_at),
    INDEX idx_operation_imports_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operation_import_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    source_row INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_operation_import_items_import FOREIGN KEY (import_id) REFERENCES operation_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_operation_import_items_operation FOREIGN KEY (operation_id) REFERENCES operations(id),
    UNIQUE KEY uq_operation_import_item (import_id, operation_id),
    INDEX idx_operation_import_items_operation (operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
