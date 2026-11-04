-- ============================================================
-- SCRIPT NÂNG CẤP HỆ THỐNG XÁC THỰC (AUTH UPGRADE MIGRATION)
-- Thêm các trường hỗ trợ: Họ tên, Quên mật khẩu OTP/Token, Ghi nhớ đăng nhập
-- Status Page - Trung tâm CNTT Trường ĐH Giao thông Vận tải (UTC)
-- ============================================================

USE `status_page_db`;

-- 1. Bổ sung các cột vào bảng `users` nếu chưa tồn tại
SET @dbname = DATABASE();
SET @tablename = 'users';

-- Thêm cột full_name (Họ tên đầy đủ của quản trị viên / biên tập viên)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'full_name'
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(150) NULL DEFAULT NULL AFTER `username`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Thêm cột reset_token (Mã xác thực khôi phục mật khẩu OTP / Reset Token)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'reset_token'
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(100) NULL DEFAULT NULL AFTER `role`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Thêm cột reset_expires_at (Thời gian hết hạn của mã khôi phục)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'reset_expires_at'
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `reset_expires_at` DATETIME NULL DEFAULT NULL AFTER `reset_token`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Thêm cột remember_token (Token ghi nhớ đăng nhập trình duyệt)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'remember_token'
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD COLUMN `remember_token` VARCHAR(255) NULL DEFAULT NULL AFTER `reset_expires_at`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Cập nhật Họ tên mẫu cho tài khoản mặc định
UPDATE `users` SET `full_name` = 'Quản trị viên UTC' WHERE `username` = 'admin' AND (`full_name` IS NULL OR `full_name` = '');
UPDATE `users` SET `full_name` = 'Biên tập viên Vận hành' WHERE `username` = 'editor' AND (`full_name` IS NULL OR `full_name` = '');

