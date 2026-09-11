ALTER TABLE guard_undo_actions
    MODIFY action_type ENUM('admin_save', 'manual_save', 'admin_delete', 'exchange', 'takeover') NOT NULL;
