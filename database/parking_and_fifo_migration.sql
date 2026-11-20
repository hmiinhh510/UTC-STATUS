-- ============================================================
-- UTH STATUS - Migration: Incident FIFO Queue
-- Trường Đại học Giao thông Vận tải (UTH)
-- ============================================================

-- 1. CẬP NHẬT BẢNG BÁO CÁO SỰ CỐ (INCIDENT REPORTS) CHO HÀNG ĐỢI FIFO
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

