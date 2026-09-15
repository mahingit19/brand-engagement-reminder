CREATE DATABASE IF NOT EXISTS brand_engagement
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE brand_engagement;

CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    latest_post_url VARCHAR(1000) NULL,
    rss_feed_url VARCHAR(1000) NULL,
    last_feed_check_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_brands_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS social_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NOT NULL,
    platform VARCHAR(50) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    rss_feed_url VARCHAR(1000) NULL,
    last_feed_check_at DATETIME NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_social_links_brand
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_social_links_brand_status (brand_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    window_start TIME NOT NULL DEFAULT '10:00:00',
    window_end TIME NOT NULL DEFAULT '17:30:00',
    reminder_interval_minutes INT UNSIGNED NOT NULL DEFAULT 45,
    browser_poll_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    last_global_reminder_at DATETIME NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (id, window_start, window_end, reminder_interval_minutes, browser_poll_seconds)
VALUES (1, '10:00:00', '17:30:00', 45, 60)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS daily_engagements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NOT NULL,
    engagement_date DATE NOT NULL,
    like_done TINYINT(1) NOT NULL DEFAULT 0,
    comment_done TINYINT(1) NOT NULL DEFAULT 0,
    share_done TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','completed','skipped') NOT NULL DEFAULT 'pending',
    snoozed_until DATETIME NULL,
    last_reminded_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_daily_engagement_brand
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    UNIQUE KEY uq_brand_day (brand_id, engagement_date),
    INDEX idx_due_lookup (engagement_date, status, snoozed_until, last_reminded_at),
    INDEX idx_daily_brand (brand_id, engagement_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brand_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NOT NULL,
    social_link_id INT UNSIGNED NULL,
    post_url VARCHAR(1000) NOT NULL,
    post_guid VARCHAR(255) NOT NULL,
    title VARCHAR(500) NULL,
    content_snippet TEXT NULL,
    published_at DATETIME NULL,
    is_notified TINYINT(1) NOT NULL DEFAULT 0,
    is_engaged TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_brand_posts_brand
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    CONSTRAINT fk_brand_posts_social
        FOREIGN KEY (social_link_id) REFERENCES social_links(id) ON DELETE SET NULL,
    UNIQUE KEY uq_brand_guid (brand_id, post_guid),
    INDEX idx_notified_lookup (brand_id, is_notified),
    INDEX idx_social_link (social_link_id)
) ENGINE=InnoDB;

