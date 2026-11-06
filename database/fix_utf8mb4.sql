-- ====================================================================
-- UTC STATUS - Script Chuyển Đổi Collation UTF-8 & Khắc Phục Lỗi Dấu ????
-- Database: status_page_db (MySQL / MariaDB / phpMyAdmin)
-- ====================================================================

-- 1. Chuyển Collation của Database sang utf8mb4_unicode_ci
ALTER DATABASE `status_page_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Chuyển Collation toàn bộ các bảng và cột văn bản sang utf8mb4_unicode_ci
ALTER TABLE `activity_logs` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `incidents` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `incident_reports` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `incident_services` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `incident_updates` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `maintenances` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `maintenance_services` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `services` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `service_daily_uptime` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `service_groups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `service_status_history` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `subscriptions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Cập nhật lại các bản ghi tiếng Việt bị lỗi hiển thị ???? trong CSDL

-- 3.1. Nhóm Dịch Vụ (service_groups)
UPDATE `service_groups` SET `name` = 'Hạ tầng Mạng', `description` = 'Hệ thống mạng, Wi-Fi và kết nối Internet' WHERE `id` = 1;
UPDATE `service_groups` SET `name` = 'Phần mềm & Ứng dụng', `description` = 'Các hệ thống phần mềm phục vụ đào tạo' WHERE `id` = 2;
UPDATE `service_groups` SET `name` = 'Hệ thống Email & Giao tiếp', `description` = 'Email và công cụ liên lạc nội bộ' WHERE `id` = 3;

-- 3.2. Dịch Vụ (services)
UPDATE `services` SET `name` = 'Email sinh viên', `description` = 'Hệ thống hòm thư điện tử dành cho sinh viên @student.utc.edu.vn' WHERE `id` = 1;
UPDATE `services` SET `name` = 'LMS (Moodle)', `description` = 'Hệ thống học tập, nộp bài giảng và thi trực tuyến của trường' WHERE `id` = 2;
UPDATE `services` SET `name` = 'Cổng thông tin sinh viên', `description` = 'Tra cứu lịch thi, điểm số rèn luyện, đăng ký thủ tục hành chính' WHERE `id` = 3;
UPDATE `services` SET `name` = 'Đăng ký học phần', `description` = 'Hệ thống đăng ký môn học và thời khóa biểu theo tín chỉ' WHERE `id` = 4;
UPDATE `services` SET `name` = 'Học phí trực tuyến', `description` = 'Cổng thanh toán học phí qua cổng ngân hàng và ví điện tử' WHERE `id` = 5;
UPDATE `services` SET `name` = 'Wifi eduroam', `description` = 'Mạng Wi-Fi chuyển vùng toàn cầu trong khuôn viên trường' WHERE `id` = 6;
UPDATE `services` SET `name` = 'Thư viện số', `description` = 'Hệ thống mượn trả sách, tra cứu tài liệu số và luận văn' WHERE `id` = 7;
UPDATE `services` SET `name` = 'Website trường', `description` = 'Cổng thông tin điện tử Trường Đại học Giao thông Vận tải (utc.edu.vn)' WHERE `id` = 8;
UPDATE `services` SET `name` = 'Microsoft Teams', `description` = 'Hệ thống phòng học trực tuyến và họp khoa/viện' WHERE `id` = 9;

-- 3.3. Sự Cố (incidents)
UPDATE `incidents` SET `title` = 'LMS (Moodle) đang gặp sự cố quá tải máy chủ' WHERE `id` = 1;
UPDATE `incidents` SET `title` = 'Lỗi kết nối cổng thanh toán học phí trực tuyến' WHERE `id` = 2;
UPDATE `incidents` SET `title` = 'Chậm đường truyền cáp quang quốc tế' WHERE `id` = 3;

-- 3.4. Tiến Trình Sự Cố (incident_updates)
UPDATE `incident_updates` SET `message` = 'Đội ngũ kỹ thuật Trung tâm CNTT đang kiểm tra sự cố gián đoạn kết nối máy chủ cơ sở dữ liệu LMS. Sinh viên tạm thời không truy cập để tránh nghẽn hàng đợi.' WHERE `id` = 1;
UPDATE `incident_updates` SET `message` = 'Đã khắc phục lỗi đồng bộ webhook giữa cổng ngân hàng và Cổng học phí UTC.' WHERE `id` = 2;
UPDATE `incident_updates` SET `message` = 'Nhà mạng ISP đã định tuyến lưu lượng sang tuyến cáp đất liền dự phòng.' WHERE `id` = 3;

-- 3.5. Lịch Bảo Trì (maintenances)
UPDATE `maintenances` SET `title` = 'Bảo trì nâng cấp Cơ sở dữ liệu Thư viện số', `description` = 'Tối ưu chỉ mục và sao lưu dữ liệu máy chủ thư viện số định kỳ.' WHERE `id` = 1;
UPDATE `maintenances` SET `title` = 'Nâng cấp thiết bị phát Wi-Fi Khu giảng đường A7', `description` = 'Thay thế 8 Access Point chuẩn Wi-Fi 6 tại giảng đường A7.' WHERE `id` = 2;
UPDATE `maintenances` SET `title` = 'Bảo trì Switch mạng khu B', `description` = 'Kiểm tra đường truyền trục cáp quang nối giữa khu A và B.' WHERE `id` = 3;
UPDATE `maintenances` SET `title` = 'Nâng cấp Firewall trung tâm', `description` = 'Nâng cấp bản vá firmware cho Firewall Fortinet Core.' WHERE `id` = 4;

-- 3.6. Tài Khoản Quản Trị (users)
UPDATE `users` SET `full_name` = 'Quản trị viên UTC' WHERE `id` = 1;
UPDATE `users` SET `full_name` = 'Biên tập viên Vận hành' WHERE `id` = 2;

