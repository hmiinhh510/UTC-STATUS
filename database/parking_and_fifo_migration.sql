-- ============================================================
-- UTC STATUS - Migration: Parking Zones & Incident FIFO Queue
-- Trường Đại học Giao thông Vận tải (UTC)
-- ============================================================

-- 1. BẢNG BÃI XE SÂN TRƯỜNG (PARKING ZONES)
CREATE TABLE IF NOT EXISTS `parking_zones` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `zone_code`      VARCHAR(20) NOT NULL UNIQUE,
    `name`           VARCHAR(100) NOT NULL,
    `vehicle_type`   VARCHAR(50) NOT NULL DEFAULT 'Xe máy',
    `total_capacity` INT UNSIGNED NOT NULL DEFAULT 500,
    `occupied_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `status`         ENUM('available', 'crowded', 'full') NOT NULL DEFAULT 'available',
    `location_note`  VARCHAR(255) DEFAULT NULL,
    `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pz_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thêm dữ liệu khởi tạo mẫu cho 3 khu vực bãi xe sân trường UTC
INSERT INTO `parking_zones` (`zone_code`, `name`, `vehicle_type`, `total_capacity`, `occupied_count`, `status`, `location_note`, `sort_order`, `updated_at`)
VALUES 
    ('KHU_A', 'Khu A - Sân Nhà A1 (Xe máy)', 'Xe máy', 500, 450, 'crowded', 'Khu vực cổng số 1 đường Cầu Giấy (Gần Hội trường A1)', 1, NOW()),
    ('KHU_B', 'Khu B - Sau Giảng Đường (Xe máy)', 'Xe máy', 350, 210, 'available', 'Khu vực phía sau Nhà A6 - A7 (Gần Thư viện số)', 2, NOW()),
    ('KHU_C', 'Khu C - Cán Bộ & Giảng Viên (Ô tô)', 'Ô tô / Giảng viên', 50, 50, 'full', 'Bãi đỗ xe ô tô cán bộ trước Nhà Hiệu Bộ', 3, NOW())
ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`),
    `vehicle_type` = VALUES(`vehicle_type`),
    `total_capacity` = VALUES(`total_capacity`),
    `occupied_count` = VALUES(`occupied_count`),
    `status` = VALUES(`status`),
    `location_note` = VALUES(`location_note`),
    `sort_order` = VALUES(`sort_order`),
    `updated_at` = NOW();

-- 2. CẬP NHẬT BẢNG BÁO CÁO SỰ CỐ (INCIDENT REPORTS) CHO HÀNG ĐỢI FIFO
ALTER TABLE `incident_reports` 
    ADD COLUMN IF NOT EXISTS `student_code` VARCHAR(50) DEFAULT NULL AFTER `reporter_name`,
    ADD COLUMN IF NOT EXISTS `location` VARCHAR(150) DEFAULT NULL AFTER `service_id`,
    ADD COLUMN IF NOT EXISTS `admin_note` TEXT DEFAULT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
    MODIFY COLUMN `status` ENUM('pending', 'in_progress', 'resolved', 'rejected', 'triaged', 'confirmed') NOT NULL DEFAULT 'pending';

-- Thêm một vài mẫu báo cáo sự cố có timestamp chính xác để minh họa hàng đợi FIFO
INSERT INTO `incident_reports` (`service_id`, `reporter_name`, `student_code`, `reporter_email`, `location`, `title`, `description`, `severity`, `status`, `admin_note`, `created_at`, `updated_at`)
VALUES
    (2, 'Nguyễn Văn Hải', '201200189', 'hai.nv@student.utc.edu.vn', 'Nhà A7 - P302', 'Lỗi không tải được bài tập trắc nghiệm LMS', 'Khi ấn nộp bài tập lớn thì hệ thống báo lỗi 504 Gateway Timeout.', 'high', 'pending', NULL, '2026-11-09 08:15:22', '2026-11-09 08:15:22'),
    (6, 'Trần Minh Tuấn', '211200452', 'tuan.tm@student.utc.edu.vn', 'Khuôn viên Thư viện', 'Mạng Wi-Fi eduroam liên tục ngắt kết nối', 'Điện thoại nhận IP nhưng không truy cập được mạng internet nội bộ.', 'medium', 'pending', NULL, '2026-11-09 09:30:45', '2026-11-09 09:30:45'),
    (3, 'Lê Thị Thu Thảo', '191200781', 'thao.ltt@student.utc.edu.vn', 'Ký túc xá Cầu Giấy', 'Cổng sinh viên báo sai điểm rèn luyện học kỳ 2', 'Điểm rèn luyện của lớp hiển thị là 0 mặc dù khoa đã duyệt bảng điểm.', 'low', 'in_progress', 'Đội IT đang đồng bộ dữ liệu từ phòng CTSV.', '2026-11-09 10:12:05', '2026-11-09 10:45:10'),
    (4, 'Phạm Quốc Huy', '221200933', 'huy.pq@student.utc.edu.vn', 'Phòng máy thực hành CNTT', 'Không vào được trang Đăng ký học phần', 'Trang web báo dịch vụ đang bảo trì đột xuất trong giờ đăng ký tín chỉ.', 'critical', 'resolved', 'Đã nâng cấp băng thông máy chủ lúc 11:30.', '2026-11-08 14:05:18', '2026-11-08 15:00:00')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

