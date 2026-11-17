-- Website analytics schema (MySQL / MariaDB)
CREATE DATABASE IF NOT EXISTS site_analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE site_analytics;

-- One row per unique browser/device (identified by a random ID stored in the browser)
CREATE TABLE IF NOT EXISTS visitors (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    visitor_id     CHAR(36)     NOT NULL UNIQUE,
    first_seen     DATETIME     NOT NULL,
    last_seen      DATETIME     NOT NULL,
    ip_hash        CHAR(64)     NULL,          -- salted hash, raw IP is never stored
    user_agent     VARCHAR(500) NULL,
    device         VARCHAR(20)  NULL,          -- Desktop / Mobile / Tablet
    browser        VARCHAR(40)  NULL,
    os             VARCHAR(40)  NULL,
    total_sessions INT          NOT NULL DEFAULT 0,
    INDEX idx_first_seen (first_seen)
) ENGINE=InnoDB;

-- One row per visit (a new session starts after 30 minutes of inactivity)
CREATE TABLE IF NOT EXISTS sessions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    session_id       CHAR(36)     NOT NULL UNIQUE,
    visitor_id       CHAR(36)     NOT NULL,
    started_at       DATETIME     NOT NULL,
    last_activity    DATETIME     NOT NULL,
    duration_seconds INT          NOT NULL DEFAULT 0,   -- active time on site
    page_views       INT          NOT NULL DEFAULT 0,
    entry_page       VARCHAR(500) NULL,
    referrer_host    VARCHAR(255) NULL,
    INDEX idx_visitor (visitor_id),
    INDEX idx_started (started_at),
    INDEX idx_activity (last_activity)
) ENGINE=InnoDB;

-- One row per page opened
CREATE TABLE IF NOT EXISTS pageviews (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    pageview_id CHAR(36)     NOT NULL UNIQUE,
    session_id  CHAR(36)     NOT NULL,
    url         VARCHAR(500) NOT NULL,
    title       VARCHAR(255) NULL,
    viewed_at   DATETIME     NOT NULL,
    time_spent  INT          NOT NULL DEFAULT 0,        -- seconds the page was visible
    INDEX idx_session (session_id),
    INDEX idx_viewed (viewed_at)
) ENGINE=InnoDB;
