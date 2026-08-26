CREATE TABLE IF NOT EXISTS bi_system_config (
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    updated_by_user_id BIGINT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (config_key),
    CONSTRAINT fk_bi_system_config_user FOREIGN KEY (updated_by_user_id) REFERENCES bi_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
