-- ============================================================
-- UTC STATUS - DATABASE UPDATE SCRIPT (VERSION 2.0)
-- Bổ sung các cột và bảng còn thiếu, đảm bảo tính toàn vẹn
-- An toàn: Không xóa hoặc ghi đè dữ liệu cũ
-- ============================================================

USE `status_page_db`;
SET NAMES utf8mb4;

-- 1. Bổ sung cột `icon` và `check_url` vào bảng `services` nếu chưa có
SET @dbname = DATABASE();
SET @tablename = "services";
SET @columnname = "icon";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `services` ADD COLUMN `icon` VARCHAR(60) NOT NULL DEFAULT 'fa-server' AFTER `description`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "check_url";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `services` ADD COLUMN `check_url` VARCHAR(255) DEFAULT NULL AFTER `sort_order`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Bổ sung cột `verified_at` vào bảng `subscriptions` nếu chưa có
SET @tablename = "subscriptions";
SET @columnname = "verified_at";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `subscriptions` ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `verified`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 3. Tạo bảng `activity_logs` (Nhật ký thao tác quản trị)
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

-- 4. Tạo bảng `service_status_history` (Lịch sử thay đổi trạng thái dịch vụ)
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

-- 5. Cập nhật tài khoản Admin và Editor với Bcrypt hash chuẩn:
-- Admin: admin / Admin@123
-- Chỉ tạo tài khoản mẫu nếu chưa có; không đặt lại mật khẩu/vai trò hiện tại.
INSERT INTO `users` (`username`, `email`, `password`, `role`)
VALUES ('admin', 'admin@utc.edu.vn', '$2y$10$OntUUzXIfsOfmaIrPFKl/OWKbf4iGGVIwvbhCUXCrpaRyW3pfa0Za', 'admin')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Editor: editor / Editor@123
INSERT INTO `users` (`username`, `email`, `password`, `role`)
VALUES ('editor', 'editor@utc.edu.vn', '$2y$10$speVxhNBxpiXp8PBQMnt8e..3y2I27hzbUQ41/hRlegoQYM7UETB2', 'editor')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- 6. Ghi lại một bản ghi khởi tạo trong nhật ký hoạt động
INSERT INTO `activity_logs` (`username`, `action`, `target_type`, `details`, `ip_address`, `created_at`)
VALUES ('System', 'database_update', 'system', 'Cập nhật cơ sở dữ liệu lên phiên bản Version 2.0 thành công', '127.0.0.1', NOW());


-- 7. Bổ sung các cột xác thực tài khoản quản trị (Auth Upgrade: full_name, reset_token, reset_expires_at, remember_token)
SET @tablename = "users";

SET @columnname = "full_name";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(150) NULL DEFAULT NULL AFTER `username`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "reset_token";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(100) NULL DEFAULT NULL AFTER `role`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "reset_expires_at";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `reset_expires_at` DATETIME NULL DEFAULT NULL AFTER `reset_token`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "remember_token";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `remember_token` VARCHAR(255) NULL DEFAULT NULL AFTER `reset_expires_at`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

UPDATE `users` SET `full_name` = 'Quản trị viên UTC' WHERE `username` = 'admin' AND (`full_name` IS NULL OR `full_name` = '');
UPDATE `users` SET `full_name` = 'Biên tập viên Vận hành' WHERE `username` = 'editor' AND (`full_name` IS NULL OR `full_name` = '');

-- Cập nhật vai trò mặc định thành 'student'
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','editor','student') NOT NULL DEFAULT 'student';
