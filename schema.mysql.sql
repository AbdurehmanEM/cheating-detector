-- Exam Phone Detection System — MySQL schema (multi-hall)
CREATE DATABASE IF NOT EXISTS `Exam_phone_detector` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `Exam_phone_detector`;

CREATE TABLE IF NOT EXISTS invigilators (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(128) NOT NULL,
    role ENUM('chief', 'invigilator') NOT NULL DEFAULT 'invigilator',
    hall_code VARCHAR(32) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hall_code VARCHAR(32) DEFAULT NULL,
    exam_name VARCHAR(255) NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    started_at DATETIME NULL,
    closed_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    chief_invigilator VARCHAR(128) NULL,
    started_by_user_id INT UNSIGNED NULL,
    INDEX idx_sessions_status (status),
    INDEX idx_sessions_hall (hall_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(32) NOT NULL UNIQUE,
    session_id INT UNSIGNED NOT NULL,
    scan_time DATETIME NOT NULL,
    subnets_scanned TEXT NOT NULL,
    total_devices INT NOT NULL DEFAULT 0,
    raw_json LONGTEXT NULL,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL UNIQUE,
    session_id INT UNSIGNED NOT NULL,
    mac VARCHAR(17) NOT NULL,
    ip VARCHAR(45) NULL,
    hostname VARCHAR(255) NULL,
    signal_strength VARCHAR(32) NULL,
    band VARCHAR(16) NULL,
    first_seen TIME NOT NULL,
    last_seen TIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'FLAGGED',
    mac_randomized TINYINT(1) NOT NULL DEFAULT 0,
    oui VARCHAR(11) NULL,
    vendor VARCHAR(255) NULL,
    category VARCHAR(64) NULL,
    alert_level VARCHAR(16) NOT NULL DEFAULT 'MEDIUM',
    cleared_by VARCHAR(128) NULL,
    cleared_at DATETIME NULL,
    joined_after_exam TINYINT(1) NOT NULL DEFAULT 0,
    disconnect_count INT NOT NULL DEFAULT 0,
    discovery_source VARCHAR(32) NULL,
    bt_likely_phone TINYINT(1) NOT NULL DEFAULT 0,
    wifi_likely_phone TINYINT(1) NOT NULL DEFAULT 0,
    mac_phone_reason VARCHAR(32) NULL,
    UNIQUE KEY uq_session_mac (session_id, mac),
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE,
    INDEX idx_devices_session (session_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id VARCHAR(32) NOT NULL UNIQUE,
    session_id INT UNSIGNED NOT NULL,
    device_id VARCHAR(32) NOT NULL,
    alert_level VARCHAR(16) NOT NULL,
    alert_time TIME NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    mac_randomized TINYINT(1) NOT NULL DEFAULT 0,
    vendor VARCHAR(255) NULL,
    category VARCHAR(64) NULL,
    signal_strength VARCHAR(32) NULL,
    estimated_zone VARCHAR(128) NULL,
    recommended_action TEXT NULL,
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    cleared TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE,
    INDEX idx_alerts_session (session_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS timeline_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    event_time DATETIME NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    device_id VARCHAR(32) NULL,
    details TEXT NULL,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invigilator_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    action_time DATETIME NOT NULL,
    invigilator_name VARCHAR(128) NOT NULL,
    user_id INT UNSIGNED NULL,
    action_type VARCHAR(64) NOT NULL,
    device_id VARCHAR(32) NULL,
    details TEXT NULL,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS oui_cache (
    oui VARCHAR(11) PRIMARY KEY,
    vendor VARCHAR(255) NOT NULL,
    fetched_at DATETIME NOT NULL
) ENGINE=InnoDB;
