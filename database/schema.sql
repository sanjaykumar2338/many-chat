CREATE TABLE IF NOT EXISTS instagram_keyword_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    keyword VARCHAR(191) NOT NULL,
    match_type ENUM('exact', 'contains') NOT NULL DEFAULT 'exact',
    response_type ENUM('text', 'link', 'pdf_link') NOT NULL DEFAULT 'text',
    response_body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instagram_keyword_match (keyword, match_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instagram_bot_settings (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    default_reply_enabled TINYINT(1) NOT NULL DEFAULT 0,
    default_reply_text TEXT NULL,
    test_mode TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instagram_comment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id VARCHAR(100) NULL,
    media_id VARCHAR(100) NULL,
    instagram_user_id VARCHAR(100) NULL,
    instagram_username VARCHAR(191) NULL,
    comment_text TEXT NULL,
    normalized_comment_text TEXT NULL,
    caption_text TEXT NULL,
    prompt_keyword VARCHAR(191) NULL,
    matched_rule_id INT UNSIGNED NULL,
    matched_keyword VARCHAR(191) NULL,
    skip_reason VARCHAR(100) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'received',
    reply_payload LONGTEXT NULL,
    api_response LONGTEXT NULL,
    webhook_payload LONGTEXT NULL,
    delivery_count INT UNSIGNED NOT NULL DEFAULT 1,
    last_delivery_at DATETIME NULL,
    last_duplicate_at DATETIME NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instagram_comment_id (comment_id),
    KEY idx_instagram_comment_status (status),
    KEY idx_instagram_comment_created (created_at),
    KEY idx_instagram_comment_matched_rule (matched_rule_id),
    CONSTRAINT fk_instagram_comment_rule
        FOREIGN KEY (matched_rule_id) REFERENCES instagram_keyword_rules (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO instagram_bot_settings (id, is_enabled, default_reply_enabled, default_reply_text, test_mode, updated_at)
VALUES (1, 1, 0, '', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);
