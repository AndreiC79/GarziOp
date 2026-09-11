START TRANSACTION;

ALTER TABLE guard_specialty_settings
    DROP CONSTRAINT chk_guard_line_count;

ALTER TABLE guard_specialty_settings
    ADD COLUMN line1_protected TINYINT(1) NOT NULL DEFAULT 0 AFTER line_labels,
    ADD CONSTRAINT chk_guard_line_count CHECK (line_count BETWEEN 1 AND 9);

UPDATE guard_specialty_settings SET line1_protected=1 WHERE specialty='OG';

CREATE TABLE api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    token_prefix VARCHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    permissions LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    last_ip VARCHAR(45) NULL,
    revoked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_api_tokens_created_user FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    INDEX idx_api_tokens_user (user_id, revoked_at, expires_at),
    INDEX idx_api_tokens_prefix (token_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE audit_logs
    ADD COLUMN api_token_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD CONSTRAINT fk_audit_logs_api_token FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL,
    ADD INDEX idx_audit_logs_api_token (api_token_id, created_at);

COMMIT;
