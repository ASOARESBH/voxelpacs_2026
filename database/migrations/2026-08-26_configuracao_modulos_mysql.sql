CREATE TABLE IF NOT EXISTS bi_system_module_config (
    module_key VARCHAR(80) NOT NULL,
    globally_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id BIGINT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (module_key),
    CONSTRAINT fk_bi_system_module_config_user FOREIGN KEY (updated_by_user_id) REFERENCES bi_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
