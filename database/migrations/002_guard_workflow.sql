CREATE TABLE IF NOT EXISTS guard_undo_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('admin_save', 'admin_delete', 'exchange', 'takeover') NOT NULL,
    before_data LONGTEXT NOT NULL,
    after_data LONGTEXT NOT NULL,
    undone_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guard_undo_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_guard_undo_user (user_id, undone_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code = 'doctor_og' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view', 'progop.create', 'progop.edit_own'))
   OR (r.code = 'doctor_ati' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view', 'progop.manage_all'))
   OR (r.code = 'doctor_nn' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view'))
   OR (r.code = 'guards_admin' AND p.code LIKE 'guards.%');
