-- ============================================================
-- STATUS PAGE DATABASE SCHEMA: status_page_db (VERSION 2.0)
-- Trung tâm CNTT – Trường Đại học Giao thông Vận tải (UTC)
-- Charset: utf8mb4 | Engine: InnoDB
-- ============================================================

CREATE DATABASE IF NOT EXISTS `status_page_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `status_page_db`;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. USERS (Tài khoản Quản trị & Biên tập viên)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(80)  NOT NULL,
    `full_name`  VARCHAR(150) DEFAULT NULL COMMENT 'Họ và tên quản trị viên / biên tập viên',
    `email`      VARCHAR(191) NOT NULL,
    `password`   VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash từ password_hash()',
    `role`       ENUM('admin','editor','student') NOT NULL DEFAULT 'student',
    `reset_token` VARCHAR(100) DEFAULT NULL COMMENT 'Mã xác nhận khôi phục mật khẩu (OTP / Token)',
    `reset_expires_at` DATETIME DEFAULT NULL COMMENT 'Thời hạn của mã xác thực khôi phục',
    `remember_token` VARCHAR(255) DEFAULT NULL COMMENT 'Token phiên ghi nhớ đăng nhập',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. SERVICE GROUPS (Nhóm Dịch vụ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_groups` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150) NOT NULL,
    `description` TEXT,
    `sort_order`  TINYINT      UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. SERVICES (Dịch vụ CNTT)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id`    INT          UNSIGNED NOT NULL,
    `name`        VARCHAR(191) NOT NULL,
    `description` TEXT,
    `icon`        VARCHAR(60)  NOT NULL DEFAULT 'fa-server',
    `status`      ENUM(
                    'operational',
                    'degraded',
                    'partial_outage',
                    'major_outage',
                    'maintenance'
                  ) NOT NULL DEFAULT 'operational',
    `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `check_url`   VARCHAR(255) DEFAULT NULL COMMENT 'URL kiểm tra tình trạng dịch vụ tự động',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_services_group` (`group_id`),
    KEY `idx_services_status` (`status`),
    CONSTRAINT `fk_services_group`
        FOREIGN KEY (`group_id`) REFERENCES `service_groups` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. SERVICE DAILY UPTIME (Lịch sử Uptime 90 ngày)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_daily_uptime` (
    `id`              INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_id`      INT      UNSIGNED NOT NULL,
    `snapshot_date`   DATE     NOT NULL,
    `uptime_pct`      DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT '0.00 - 100.00',
    `incident_count`  TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uptime_service_date` (`service_id`, `snapshot_date`),
    KEY `idx_uptime_date` (`snapshot_date`),
    CONSTRAINT `fk_uptime_service`
        FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. INCIDENTS (Sự cố Hệ thống)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `severity`    ENUM('minor','major','critical') NOT NULL DEFAULT 'minor',
    `status`      ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
    `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME     DEFAULT NULL,
    `created_by`  INT          UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_incidents_status`   (`status`),
    KEY `idx_incidents_severity` (`severity`),
    KEY `idx_incidents_started`  (`started_at`),
    CONSTRAINT `fk_incidents_user`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. INCIDENT_SERVICES (Bảng liên kết N-N: Sự cố & Dịch vụ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_services` (
    `incident_id` INT UNSIGNED NOT NULL,
    `service_id`  INT UNSIGNED NOT NULL,
    PRIMARY KEY (`incident_id`, `service_id`),
    CONSTRAINT `fk_is_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_is_service`  FOREIGN KEY (`service_id`)  REFERENCES `services`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. INCIDENT_UPDATES (Tiến trình cập nhật sự cố theo Timeline)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_updates` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `incident_id` INT          UNSIGNED NOT NULL,
    `status`      ENUM('investigating','identified','monitoring','resolved') NOT NULL,
    `message`     TEXT         NOT NULL,
    `created_by`  INT          UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_iu_incident` (`incident_id`),
    CONSTRAINT `fk_iu_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iu_user`     FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. MAINTENANCES (Lịch bảo trì hệ thống)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `maintenances` (
    `id`           INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT,
    `status`       ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `scheduled_at` DATETIME     NOT NULL,
    `expected_end` DATETIME     NOT NULL,
    `created_by`   INT          UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_maint_scheduled` (`scheduled_at`),
    KEY `idx_maint_status`    (`status`),
    CONSTRAINT `fk_maint_user`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. MAINTENANCE_SERVICES (Liên kết N-N: Bảo trì & Dịch vụ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `maintenance_services` (
    `maintenance_id` INT UNSIGNED NOT NULL,
    `service_id`     INT UNSIGNED NOT NULL,
    PRIMARY KEY (`maintenance_id`, `service_id`),
    CONSTRAINT `fk_ms_maintenance` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenances` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ms_service`     FOREIGN KEY (`service_id`)     REFERENCES `services`     (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. SUBSCRIPTIONS (Đăng ký nhận thông báo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`        ENUM('email','slack') NOT NULL DEFAULT 'email',
    `endpoint`    VARCHAR(500) NOT NULL COMMENT 'Email hoặc Webhook URL Slack',
    `token`       VARCHAR(64)  NOT NULL,
    `verified`    TINYINT(1)   NOT NULL DEFAULT 0,
    `verified_at` DATETIME     DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sub_endpoint` (`endpoint`(255)),
    KEY `idx_sub_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. ACTIVITY_LOGS (Nhật ký thao tác quản trị / Audit Log)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT          UNSIGNED DEFAULT NULL,
    `username`    VARCHAR(80)  NOT NULL DEFAULT 'System',
    `action`      VARCHAR(80)  NOT NULL,
    `target_type` VARCHAR(50)  NOT NULL DEFAULT 'service',
    `target_id`   INT          UNSIGNED DEFAULT NULL,
    `details`     TEXT         DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_log_user`   (`user_id`),
    KEY `idx_log_action` (`action`),
    KEY `idx_log_date`   (`created_at`),
    CONSTRAINT `fk_log_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. SERVICE_STATUS_HISTORY (Lịch sử thay đổi trạng thái dịch vụ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_status_history` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_id`  INT UNSIGNED NOT NULL,
    `old_status`  ENUM('operational','degraded','partial_outage','major_outage','maintenance') NOT NULL,
    `new_status`  ENUM('operational','degraded','partial_outage','major_outage','maintenance') NOT NULL,
    `changed_by`  INT UNSIGNED DEFAULT NULL,
    `reason`      VARCHAR(255) DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ssh_service` (`service_id`),
    KEY `idx_ssh_date`    (`created_at`),
    CONSTRAINT `fk_ssh_service`
        FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ssh_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. INCIDENT_REPORTS (Tiếp nhận báo cáo sự cố từ người dùng / User Triage)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_reports` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_id`     INT UNSIGNED DEFAULT NULL,
    `reporter_name`  VARCHAR(100) NOT NULL,
    `reporter_email` VARCHAR(150) NOT NULL,
    `title`          VARCHAR(255) NOT NULL,
    `description`    TEXT NOT NULL,
    `severity`       ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `status`         ENUM('pending', 'triaged', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ir_service_id` (`service_id`),
    KEY `idx_ir_status`     (`status`),
    KEY `idx_ir_date`       (`created_at`),
    CONSTRAINT `fk_ir_service`
        FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DỮ LIỆU KHỞI TẠO MẪU (SEED DATA CHUẨN UTC STATUS)
-- ============================================================

-- 1. Tài khoản Quản trị:
-- Admin: admin / Admin@123
-- Editor: editor / Editor@123
INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`) VALUES
  (1, 'admin',    'admin@utc.edu.vn',           '$2y$10$OntUUzXIfsOfmaIrPFKl/OWKbf4iGGVIwvbhCUXCrpaRyW3pfa0Za', 'admin'),
  (2, 'editor',   'editor@utc.edu.vn',          '$2y$10$YEtnDp7QLF0bY/pWsgGwq.93mhHCdEcJHRhjoGtA86NaBTs8Tqbsm', 'editor'),
  (3, 'sinhvien', 'sinhvien@student.utc.edu.vn', '$2y$10$m7Cci3kTQC6r.TBFtkrXBeti.8WGAmqPk1A3TSjC8.gWGX1f2dtnu', 'student')
  ON DUPLICATE KEY UPDATE `id` = `id`;

-- 2. Nhóm Dịch Vụ
INSERT INTO `service_groups` (`id`, `name`, `description`, `sort_order`) VALUES
(1, 'Hạ tầng Mạng',                'Hệ thống mạng, Wi-Fi và kết nối Internet', 1),
(2, 'Phần mềm & Ứng dụng',         'Các hệ thống phần mềm phục vụ đào tạo',   2),
(3, 'Hệ thống Email & Giao tiếp',   'Email và công cụ liên lạc nội bộ',        3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Dịch Vụ Chuẩn UTC Status
INSERT INTO `services` (`id`, `group_id`, `name`, `description`, `icon`, `status`, `sort_order`, `check_url`) VALUES
(1, 3, 'Email sinh viên',          'Dịch vụ mail @student.utc.edu.vn',                'fa-envelope',       'operational',   1, 'https://mail.google.com'),
(2, 2, 'LMS (Moodle)',             'Hệ thống bài giảng và thi trực tuyến của trường', 'fa-graduation-cap', 'major_outage',  2, 'https://elearning.utc.edu.vn'),
(3, 2, 'Cổng thông tin sinh viên', 'Tra cứu điểm thi, lịch thi và thủ tục sinh viên', 'fa-user',           'operational',   3, 'https://sv.utc.edu.vn'),
(4, 2, 'Đăng ký học phần',         'Hệ thống đăng ký tín chỉ và thời khóa biểu',      'fa-calendar-check', 'operational',   4, 'https://dkmh.utc.edu.vn'),
(5, 2, 'Học phí trực tuyến',       'Cổng thanh toán học phí qua ngân hàng điện tử',   'fa-dollar-sign',    'operational',   5, 'https://hocphi.utc.edu.vn'),
(6, 1, 'Wifi eduroam',             'Mạng Wi-Fi chuyển vùng toàn cầu khuôn viên UTC',  'fa-wifi',           'operational',   6, NULL),
(7, 2, 'Thư viện số',              'Hệ thống mượn trả sách và tài liệu điện tử',      'fa-book-open',      'maintenance',   7, 'https://thuvien.utc.edu.vn'),
(8, 2, 'Website trường',           'Cổng thông tin điện tử trường (utc.edu.vn)',      'fa-globe',          'operational',   8, 'https://utc.edu.vn'),
(9, 3, 'Microsoft Teams',          'Hệ thống phòng họp và học online khoa/viện',      'fa-video',          'operational',   9, 'https://teams.microsoft.com')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `icon` = VALUES(`icon`), `status` = VALUES(`status`), `check_url` = VALUES(`check_url`);

-- 4. Sự cố mẫu
INSERT INTO `incidents` (`id`, `title`, `severity`, `status`, `started_at`, `resolved_at`, `created_by`) VALUES
(1, 'LMS (Moodle) đang gặp sự cố quá tải máy chủ', 'critical', 'investigating', NOW() - INTERVAL 1 HOUR, NULL, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `incident_services` (`incident_id`, `service_id`) VALUES (1, 2)
ON DUPLICATE KEY UPDATE `service_id` = VALUES(`service_id`);

INSERT INTO `incident_updates` (`id`, `incident_id`, `status`, `message`, `created_by`, `created_at`) VALUES
(1, 1, 'investigating', 'Đội ngũ kỹ thuật Trung tâm CNTT đang kiểm tra sự cố gián đoạn kết nối máy chủ cơ sở dữ liệu LMS. Sinh viên tạm thời không truy cập để tránh nghẽn hàng đợi.', 1, NOW() - INTERVAL 45 MINUTE)
ON DUPLICATE KEY UPDATE `message` = VALUES(`message`);

-- 5. Lịch bảo trì mẫu
INSERT INTO `maintenances` (`id`, `title`, `description`, `status`, `scheduled_at`, `expected_end`, `created_by`) VALUES
(1, 'Bảo trì nâng cấp Cơ sở dữ liệu Thư viện số', 'Tối ưu chỉ mục và sao lưu dữ liệu máy chủ thư viện số định kỳ.', 'in_progress', NOW() - INTERVAL 2 HOUR, NOW() + INTERVAL 4 HOUR, 1),
(2, 'Nâng cấp thiết bị phát Wi-Fi Khu giảng đường A7', 'Thay thế 8 Access Point chuẩn Wi-Fi 6 tại giảng đường A7.', 'scheduled', NOW() + INTERVAL 2 DAY, NOW() + INTERVAL 2 DAY + INTERVAL 3 HOUR, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `status` = VALUES(`status`);

INSERT INTO `maintenance_services` (`maintenance_id`, `service_id`) VALUES (1, 7), (2, 6)
ON DUPLICATE KEY UPDATE `service_id` = VALUES(`service_id`);
