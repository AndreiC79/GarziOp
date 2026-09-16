SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_specialty_settings (
    specialty ENUM('OG', 'ATI', 'NN') PRIMARY KEY,
    line_count TINYINT UNSIGNED NOT NULL,
    line_labels LONGTEXT NOT NULL,
    line1_protected TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_guard_line_count CHECK (line_count BETWEEN 1 AND 9)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO guard_specialty_settings (specialty, line_count, line_labels, line1_protected) VALUES
('OG', 6, '["L1 Spec.","L2 Spec.","L3 Rez.","L4 Rez.","L5 Rez.","L6 Rez."]', 1),
('ATI', 1, '["L1 ATI"]', 0),
('NN', 2, '["L1 NN","L2 NN"]', 0);

CREATE TABLE people (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(220) NOT NULL,
    title VARCHAR(100) NULL,
    specialty ENUM('OG', 'ATI', 'NN', 'ASISTENTA') NOT NULL,
    professional_category VARCHAR(100) NULL,
    rotation_position INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    guard_eligible TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_people_specialty_active (specialty, active),
    INDEX idx_people_guard_eligible (specialty, guard_eligible, active),
    INDEX idx_people_rotation (specialty, rotation_position),
    INDEX idx_people_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive', 'locked') NOT NULL DEFAULT 'active',
    api_access_level ENUM('none', 'read', 'schedule', 'full') NOT NULL DEFAULT 'none',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    password_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_person FOREIGN KEY (person_id) REFERENCES people(id),
    UNIQUE KEY uq_users_person (person_id),
    INDEX idx_users_status (status),
    INDEX idx_users_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    module ENUM('guards', 'progop', 'admin', 'reports') NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permission_overrides (
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    allowed TINYINT(1) NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_user_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    last_activity_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    source ENUM('system', 'admin') NOT NULL DEFAULT 'admin',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_holidays_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_holidays_year (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    specialty ENUM('OG', 'ATI', 'NN') NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    current_version_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guard_plans_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_guard_plans_period (specialty, line_no, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_plan_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    status ENUM('draft', 'published', 'superseded') NOT NULL DEFAULT 'draft',
    start_person_id BIGINT UNSIGNED NULL,
    next_person_id BIGINT UNSIGNED NULL,
    restored_from_version_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    published_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    CONSTRAINT fk_guard_versions_plan FOREIGN KEY (plan_id) REFERENCES guard_plans(id),
    CONSTRAINT fk_guard_versions_start_person FOREIGN KEY (start_person_id) REFERENCES people(id),
    CONSTRAINT fk_guard_versions_next_person FOREIGN KEY (next_person_id) REFERENCES people(id),
    CONSTRAINT fk_guard_versions_restored FOREIGN KEY (restored_from_version_id) REFERENCES guard_plan_versions(id),
    CONSTRAINT fk_guard_versions_created_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_guard_versions_published_user FOREIGN KEY (published_by) REFERENCES users(id),
    UNIQUE KEY uq_guard_version (plan_id, version_no),
    INDEX idx_guard_versions_status (plan_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guard_plans
    ADD CONSTRAINT fk_guard_plans_current_version
    FOREIGN KEY (current_version_id) REFERENCES guard_plan_versions(id);

CREATE TABLE guard_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_id BIGINT UNSIGNED NOT NULL,
    guard_date DATE NOT NULL,
    specialty ENUM('OG', 'ATI', 'NN') NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    original_person_id BIGINT UNSIGNED NOT NULL,
    current_person_id BIGINT UNSIGNED NOT NULL,
    assignment_state ENUM('initial', 'exchanged', 'transferred', 'admin_corrected', 'manual_corrected') NOT NULL DEFAULT 'initial',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignments_version FOREIGN KEY (version_id) REFERENCES guard_plan_versions(id),
    CONSTRAINT fk_assignments_original_person FOREIGN KEY (original_person_id) REFERENCES people(id),
    CONSTRAINT fk_assignments_current_person FOREIGN KEY (current_person_id) REFERENCES people(id),
    CONSTRAINT fk_guard_assignment_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_assignment_slot (version_id, guard_date, specialty, line_no),
    INDEX idx_assignments_date (guard_date, specialty),
    INDEX idx_assignments_current_person (current_person_id, guard_date),
    INDEX idx_assignments_original_person (original_person_id, guard_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_type ENUM('exchange', 'transfer', 'admin_correction', 'restore') NOT NULL,
    initiated_by BIGINT UNSIGNED NOT NULL,
    reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guard_changes_user FOREIGN KEY (initiated_by) REFERENCES users(id),
    INDEX idx_guard_changes_date (created_at),
    INDEX idx_guard_changes_user (initiated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_change_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    from_person_id BIGINT UNSIGNED NOT NULL,
    to_person_id BIGINT UNSIGNED NOT NULL,
    previous_state VARCHAR(40) NOT NULL,
    new_state VARCHAR(40) NOT NULL,
    CONSTRAINT fk_guard_change_items_change FOREIGN KEY (change_id) REFERENCES guard_changes(id),
    CONSTRAINT fk_guard_change_items_assignment FOREIGN KEY (assignment_id) REFERENCES guard_assignments(id),
    CONSTRAINT fk_guard_change_items_from_person FOREIGN KEY (from_person_id) REFERENCES people(id),
    CONSTRAINT fk_guard_change_items_to_person FOREIGN KEY (to_person_id) REFERENCES people(id),
    INDEX idx_guard_change_items_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_undo_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('admin_save', 'manual_save', 'admin_delete', 'manual_delete', 'exchange', 'takeover') NOT NULL,
    before_data LONGTEXT NOT NULL,
    after_data LONGTEXT NOT NULL,
    undone_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guard_undo_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_guard_undo_user (user_id, undone_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE og_line1_rotation (
    person_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_og_rotation_person FOREIGN KEY (person_id) REFERENCES people(id),
    UNIQUE KEY uq_og_rotation_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_generation_batches (
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

CREATE TABLE guard_generation_batch_versions (
    batch_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (batch_id, version_id),
    CONSTRAINT fk_batch_versions_batch FOREIGN KEY (batch_id) REFERENCES guard_generation_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_batch_versions_plan FOREIGN KEY (plan_id) REFERENCES guard_plans(id),
    CONSTRAINT fk_batch_versions_version FOREIGN KEY (version_id) REFERENCES guard_plan_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operation_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operation_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    block_date DATE NOT NULL,
    reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_by BIGINT UNSIGNED NULL,
    removed_at DATETIME NULL,
    CONSTRAINT fk_room_blocks_room FOREIGN KEY (room_id) REFERENCES operation_rooms(id),
    CONSTRAINT fk_room_blocks_created_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_room_blocks_removed_user FOREIGN KEY (removed_by) REFERENCES users(id),
    INDEX idx_room_blocks_date (block_date, room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scheduled_date DATE NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    patient_name VARCHAR(220) NOT NULL,
    diagnostic TEXT NULL,
    operation_type_id BIGINT UNSIGNED NULL,
    scheduled_doctor_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    status ENUM('scheduled', 'completed', 'cancelled', 'deleted') NOT NULL DEFAULT 'scheduled',
    custom_notes TEXT NULL,
    is_extra TINYINT(1) NOT NULL DEFAULT 0,
    extra_reason VARCHAR(255) NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_operations_room FOREIGN KEY (room_id) REFERENCES operation_rooms(id),
    CONSTRAINT fk_operations_type FOREIGN KEY (operation_type_id) REFERENCES operation_types(id),
    CONSTRAINT fk_operations_doctor FOREIGN KEY (scheduled_doctor_id) REFERENCES people(id),
    CONSTRAINT fk_operations_created_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_operations_date_room (scheduled_date, room_id, display_order),
    INDEX idx_operations_author (created_by, scheduled_date),
    INDEX idx_operations_doctor (scheduled_doctor_id, scheduled_date),
    INDEX idx_operations_status (status, scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operation_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created', 'updated', 'moved', 'reordered', 'completed', 'cancelled', 'deleted', 'restored') NOT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_operation_events_operation FOREIGN KEY (operation_id) REFERENCES operations(id),
    CONSTRAINT fk_operation_events_user FOREIGN KEY (performed_by) REFERENCES users(id),
    INDEX idx_operation_events_operation (operation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operation_imports (
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

CREATE TABLE operation_import_items (
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

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_unread (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    api_token_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_audit_logs_api_token FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL,
    INDEX idx_audit_logs_date (created_at),
    INDEX idx_audit_logs_user (user_id, created_at),
    INDEX idx_audit_logs_api_token (api_token_id, created_at),
    INDEX idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type ENUM('monthly', 'annual') NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NULL,
    file_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reports_user FOREIGN KEY (generated_by) REFERENCES users(id),
    INDEX idx_reports_period (period_year, period_month, report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_archives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_year SMALLINT UNSIGNED NOT NULL UNIQUE,
    report_id BIGINT UNSIGNED NOT NULL,
    deleted_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    requested_by BIGINT UNSIGNED NOT NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_archives_report FOREIGN KEY (report_id) REFERENCES reports(id),
    CONSTRAINT fk_audit_archives_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code, name, description, is_system) VALUES
('super_admin', 'Superuser', 'Acces complet; poate fi administrat de Administratorul platformei', 1),
('platform_admin', 'Administrator platforma', 'Acces complet, inclusiv utilizatori, loguri si tokenuri', 1),
('doctor_admin', 'Medic administrator', 'Control operational complet pentru garzi si Program operator', 1),
('doctor_standard', 'Medic standard', 'Garzi implicate si operatii proprii', 1),
('guards_admin', 'Administrator garzi', 'Configurare, generare si publicare garzi', 1),
('doctor_og', 'Medic OG', 'Acces medic OG', 1),
('doctor_ati', 'Medic ATI', 'Acces medic ATI si control ProgOp', 1),
('doctor_nn', 'Medic NN', 'Acces medic NN si vizualizare ProgOp', 1),
('assistant_viewer', 'Asistenta - vizualizare si tiparire', 'Consulta si exporta Programul operator', 1),
('assistant_editor', 'Asistenta - modificare Program operator', 'Consulta si modifica Programul operator', 1),
('operating_room_dashboard', 'Panou bloc operator', 'Ecran tactil pentru programul curent al salilor', 1);

INSERT INTO permissions (code, module, name) VALUES
('guards.view', 'guards', 'Vizualizare garzi'),
('guards.manage_people', 'guards', 'Administrare personal si ordine'),
('guards.generate', 'guards', 'Generare program OG Linia 1'),
('guards.publish', 'guards', 'Publicare program'),
('guards.restore', 'guards', 'Restaurare versiune'),
('guards.own_changes', 'guards', 'Schimb si cedare pentru garzile proprii'),
('guards.admin_correction', 'guards', 'Corectie administrativa'),
('progop.view', 'progop', 'Vizualizare program operator'),
('progop.create', 'progop', 'Creare programare'),
('progop.edit_own', 'progop', 'Modificare programare proprie'),
('progop.manage_all', 'progop', 'Control complet program operator'),
('progop.manage_settings', 'progop', 'Administrare sali si tipuri de operatii'),
('admin.users', 'admin', 'Administrare utilizatori'),
('admin.permissions', 'admin', 'Administrare drepturi'),
('admin.audit', 'admin', 'Vizualizare audit'),
('reports.generate', 'reports', 'Generare rapoarte'),
('reports.download', 'reports', 'Descarcare rapoarte'),
('reports.archive_logs', 'reports', 'Arhivare si stergere loguri');

UPDATE roles SET description = CASE code
    WHEN 'platform_admin' THEN 'Acces complet: utilizatori, drepturi, loguri, tokenuri, garzi, Program operator si rapoarte.'
    WHEN 'super_admin' THEN 'Acces complet operational si administrativ; poate fi administrat sau eliminat de Administratorul platformei.'
    WHEN 'doctor_admin' THEN 'Poate corecta orice garda si poate crea, modifica, muta sau sterge orice operatie; fara utilizatori, loguri ori tokenuri.'
    WHEN 'doctor_standard' THEN 'Gestioneaza garzile pe liniile libere ale propriei specialitati, schimburile proprii OG L1, operatiile proprii si descarcarea rapoartelor.'
    WHEN 'guards_admin' THEN 'Administreaza personalul si ordinea, genereaza, publica, restaureaza si corecteaza programul de garzi.'
    WHEN 'doctor_og' THEN 'Rol OG vechi, pastrat pentru compatibilitate; foloseste aceleasi drepturi operationale ca Medic standard.'
    WHEN 'doctor_ati' THEN 'Gestioneaza garzile ATI, are control complet asupra Programului operator si poate descarca rapoarte.'
    WHEN 'doctor_nn' THEN 'Gestioneaza garzile NN, vizualizeaza Programul operator si poate descarca rapoarte.'
    WHEN 'assistant_editor' THEN 'Vede garzile si poate crea, modifica, muta sau sterge operatii; poate vizualiza si descarca rapoarte.'
    WHEN 'assistant_viewer' THEN 'Poate doar vedea garzile si Programul operator si poate tipari sau descarca rapoarte.'
    WHEN 'operating_room_dashboard' THEN 'Acces exclusiv la panoul tactil al salilor pentru ieri, astazi si maine; poate actualiza fluxul, adauga urgente si muta operatii nefinalizate.'
    ELSE description END;

UPDATE permissions SET description = CASE code
    WHEN 'guards.view' THEN 'Poate deschide calendarele si listele de garzi, fara a modifica programul.'
    WHEN 'guards.manage_people' THEN 'Poate administra medicii eligibili si ordinea folosita la generarea OG Linia 1.'
    WHEN 'guards.generate' THEN 'Poate reconstrui un draft OG Linia 1 pentru perioada si medicul de inceput alese.'
    WHEN 'guards.publish' THEN 'Poate transforma draftul de garzi in program vizibil utilizatorilor.'
    WHEN 'guards.restore' THEN 'Poate restaura o versiune anterioara salvata a programului de garzi.'
    WHEN 'guards.own_changes' THEN 'Poate schimba, ceda sau prelua garzi conform regulilor si implicarii proprii.'
    WHEN 'guards.admin_correction' THEN 'Poate adauga, inlocui sau sterge administrativ garzi, inclusiv pentru alti medici.'
    WHEN 'progop.view' THEN 'Poate vedea calendarul, salile si operatiile programate.'
    WHEN 'progop.create' THEN 'Poate adauga o operatie noua in Programul operator.'
    WHEN 'progop.edit_own' THEN 'Poate modifica sau sterge operatiile pe care le-a programat ori care ii apartin.'
    WHEN 'progop.manage_all' THEN 'Poate modifica, muta sau sterge orice operatie, indiferent cine a programat-o.'
    WHEN 'progop.manage_settings' THEN 'Poate configura salile si nomenclatorul tipurilor de operatii.'
    WHEN 'admin.users' THEN 'Poate crea, activa, bloca si modifica utilizatori si parole temporare.'
    WHEN 'admin.permissions' THEN 'Poate atribui roluri si drepturi individuale si poate administra tokenurile API.'
    WHEN 'admin.audit' THEN 'Poate vedea jurnalul complet si utilizatorii conectati.'
    WHEN 'reports.generate' THEN 'Poate genera rapoarte lunare si anuale din datele aplicatiei.'
    WHEN 'reports.download' THEN 'Poate descarca sau tipari rapoartele disponibile.'
    WHEN 'reports.archive_logs' THEN 'Poate arhiva raportul anual si apoi sterge controlat logurile aferente.'
    ELSE description END;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code IN ('super_admin','platform_admin'))
   OR (r.code = 'doctor_admin' AND p.code IN ('guards.view','guards.own_changes','guards.admin_correction','progop.view','progop.create','progop.manage_all'))
   OR (r.code = 'doctor_standard' AND p.code IN ('guards.view','guards.own_changes','progop.view','progop.create','progop.edit_own','reports.download'))
   OR (r.code = 'doctor_og' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view', 'progop.create', 'progop.edit_own', 'reports.download'))
   OR (r.code = 'doctor_ati' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view', 'progop.manage_all', 'reports.download'))
   OR (r.code = 'doctor_nn' AND p.code IN ('guards.view', 'guards.own_changes', 'progop.view', 'reports.download'))
   OR (r.code = 'assistant_viewer' AND p.code IN ('guards.view','progop.view', 'reports.download'))
   OR (r.code = 'assistant_editor' AND p.code IN ('guards.view','progop.view', 'progop.create', 'progop.manage_all', 'reports.download'))
   OR (r.code = 'operating_room_dashboard' AND p.code IN ('progop.view', 'progop.create', 'progop.manage_all'))
   OR (r.code = 'guards_admin' AND p.code LIKE 'guards.%');

INSERT INTO operation_rooms (name, display_order) VALUES
('Sala 1', 1), ('Sala 2', 2), ('Sala 3', 3), ('Sala 4', 4);
