-- UTC STATUS - Migration: Account Management & Password Reset Requests
-- File: database/update_accounts_feature.sql

-- 1. Bổ sung cột status vào bảng users nếu chưa có
SET @exist_status := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'users' 
      AND COLUMN_NAME = 'status'
);

SET @sql_status := IF(@exist_status = 0, 
    'ALTER TABLE `users` ADD COLUMN `status` ENUM(\'active\', \'locked\') NOT NULL DEFAULT \'active\' AFTER `role`', 
    'SELECT 1'
);
PREPARE stmt_status FROM @sql_status;
EXECUTE stmt_status;
DEALLOCATE PREPARE stmt_status;

-- 2. Tạo bảng password_reset_requests (Yêu cầu cấp lại mật khẩu từ Sinh viên gửi Admin)
CREATE TABLE IF NOT EXISTS `password_reset_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(80) NOT NULL,
    `full_name` VARCHAR(150) NULL,
    `email` VARCHAR(191) NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `new_password_temp` VARCHAR(255) NULL,
    `admin_note` VARCHAR(255) NULL,
    `processed_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

