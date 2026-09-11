ALTER TABLE guard_assignments
    MODIFY assignment_state ENUM('initial', 'exchanged', 'transferred', 'admin_corrected', 'manual_corrected') NOT NULL DEFAULT 'initial';

ALTER TABLE guard_undo_actions
    MODIFY action_type ENUM('admin_save', 'manual_save', 'admin_delete', 'manual_delete', 'exchange', 'takeover') NOT NULL;
