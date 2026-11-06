<?php
/**
 * Database Seeder Script for UTC STATUS (Đại học GTVT)
 * Chỉ bổ sung dữ liệu mẫu còn thiếu; không ghi đè trạng thái vận hành hoặc số liệu hiện có.
 *
 * Cách chạy:
 *   CLI: php database/seed_utc.php
 *   CLI (xóa và tạo lại dữ liệu dịch vụ mẫu): php database/seed_utc.php --force
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = Database::getInstance();
$pdo->exec("SET NAMES utf8mb4");

$forceReset = isset($argv[1]) && $argv[1] === '--force';

echo "=== KHỞI CHẠY SEED DỮ LIỆU UTC STATUS ===\n";

if ($forceReset) {
    echo "-> Đang thực hiện RESET toàn bộ dữ liệu mẫu theo yêu cầu (--force)...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE `service_daily_uptime`");
    $pdo->exec("TRUNCATE TABLE `service_status_history`");
    $pdo->exec("TRUNCATE TABLE `incident_services`");
    $pdo->exec("TRUNCATE TABLE `incident_updates`");
    $pdo->exec("TRUNCATE TABLE `incident_reports`");
    $pdo->exec("TRUNCATE TABLE `incidents`");
    $pdo->exec("TRUNCATE TABLE `maintenance_services`");
    $pdo->exec("TRUNCATE TABLE `maintenances`");
    $pdo->exec("TRUNCATE TABLE `services`");
    $pdo->exec("TRUNCATE TABLE `service_groups`");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

// 1. Nhóm Dịch Vụ
$groups = [
    ['id' => 1, 'name' => 'Hạ tầng Mạng',                'description' => 'Hệ thống mạng, Wi-Fi và kết nối Internet', 'sort_order' => 1],
    ['id' => 2, 'name' => 'Phần mềm & Ứng dụng',         'description' => 'Các hệ thống phần mềm phục vụ đào tạo',   'sort_order' => 2],
    ['id' => 3, 'name' => 'Hệ thống Email & Giao tiếp',   'description' => 'Email và công cụ liên lạc nội bộ',        'sort_order' => 3],
];

$stmtGrp = $pdo->prepare(
    'INSERT IGNORE INTO service_groups (id, name, description, sort_order, created_at)
     VALUES (:id, :name, :description, :sort_order, NOW())'
);
foreach ($groups as $g) {
    $stmtGrp->execute($g);
}

// 2. Danh Sách Dịch Vụ Chuẩn
$services = [
    [
        'id'          => 1,
        'group_id'    => 3,
        'name'        => 'Email sinh viên',
        'description' => 'Hệ thống hòm thư điện tử dành cho sinh viên @student.utc.edu.vn',
        'icon'        => 'fa-envelope',
        'status'      => 'operational',
        'sort_order'  => 1,
        'check_url'   => 'https://mail.google.com'
    ],
    [
        'id'          => 2,
        'group_id'    => 2,
        'name'        => 'LMS (Moodle)',
        'description' => 'Hệ thống học tập, nộp bài giảng và thi trực tuyến của trường',
        'icon'        => 'fa-graduation-cap',
        'status'      => 'major_outage',
        'sort_order'  => 2,
        'check_url'   => 'https://elearning.utc.edu.vn'
    ],
    [
        'id'          => 3,
        'group_id'    => 2,
        'name'        => 'Cổng thông tin sinh viên',
        'description' => 'Tra cứu lịch thi, điểm số rèn luyện, đăng ký thủ tục hành chính',
        'icon'        => 'fa-user',
        'status'      => 'operational',
        'sort_order'  => 3,
        'check_url'   => 'https://sv.utc.edu.vn'
    ],
    [
        'id'          => 4,
        'group_id'    => 2,
        'name'        => 'Đăng ký học phần',
        'description' => 'Hệ thống đăng ký môn học và thời khóa biểu theo tín chỉ',
        'icon'        => 'fa-calendar-check',
        'status'      => 'operational',
        'sort_order'  => 4,
        'check_url'   => 'https://dkmh.utc.edu.vn'
    ],
    [
        'id'          => 5,
        'group_id'    => 2,
        'name'        => 'Học phí trực tuyến',
        'description' => 'Cổng thanh toán học phí qua cổng ngân hàng và ví điện tử',
        'icon'        => 'fa-dollar-sign',
        'status'      => 'operational',
        'sort_order'  => 5,
        'check_url'   => 'https://hocphi.utc.edu.vn'
    ],
    [
        'id'          => 6,
        'group_id'    => 1,
        'name'        => 'Wifi eduroam',
        'description' => 'Mạng Wi-Fi chuyển vùng toàn cầu trong khuôn viên trường',
        'icon'        => 'fa-wifi',
        'status'      => 'operational',
        'sort_order'  => 6,
        'check_url'   => null
    ],
    [
        'id'          => 7,
        'group_id'    => 2,
        'name'        => 'Thư viện số',
        'description' => 'Hệ thống mượn trả sách, tra cứu tài liệu số và luận văn',
        'icon'        => 'fa-book-open',
        'status'      => 'maintenance',
        'sort_order'  => 7,
        'check_url'   => 'https://thuvien.utc.edu.vn'
    ],
    [
        'id'          => 8,
        'group_id'    => 2,
        'name'        => 'Website trường',
        'description' => 'Cổng thông tin điện tử Trường Đại học Giao thông Vận tải (utc.edu.vn)',
        'icon'        => 'fa-globe',
        'status'      => 'operational',
        'sort_order'  => 8,
        'check_url'   => 'https://utc.edu.vn'
    ],
    [
        'id'          => 9,
        'group_id'    => 3,
        'name'        => 'Microsoft Teams',
        'description' => 'Hệ thống phòng học trực tuyến và họp khoa/viện',
        'icon'        => 'fa-video',
        'status'      => 'operational',
        'sort_order'  => 9,
        'check_url'   => 'https://teams.microsoft.com'
    ]
];

$stmtSvc = $pdo->prepare(
    'INSERT IGNORE INTO services (id, group_id, name, description, icon, status, sort_order, check_url, created_at, updated_at)
     VALUES (:id, :group_id, :name, :description, :icon, :status, :sort_order, :check_url, NOW(), NOW())'
);

foreach ($services as $s) {
    $stmtSvc->execute($s);
}

// 3. Sự Cố Mẫu (Lưu trữ trực tiếp trong MySQL)
$incidents = [
    [
        'id'          => 1,
        'title'       => 'LMS (Moodle) đang gặp sự cố quá tải máy chủ',
        'severity'    => 'critical',
        'status'      => 'investigating',
        'started_at'  => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'resolved_at' => null,
        'service_id'  => 2,
        'message'     => 'Đội ngũ kỹ thuật Trung tâm CNTT đang kiểm tra sự cố gián đoạn kết nối máy chủ cơ sở dữ liệu LMS. Sinh viên tạm thời không truy cập để tránh nghẽn hàng đợi.'
    ],
    [
        'id'          => 2,
        'title'       => 'Lỗi kết nối cổng thanh toán học phí trực tuyến',
        'severity'    => 'major',
        'status'      => 'resolved',
        'started_at'  => date('Y-m-d 08:30:00', strtotime('-17 days')),
        'resolved_at' => date('Y-m-d 11:45:00', strtotime('-17 days')),
        'service_id'  => 5,
        'message'     => 'Đã khắc phục lỗi đồng bộ webhook giữa cổng ngân hàng và Cổng học phí UTC.'
    ],
    [
        'id'          => 3,
        'title'       => 'Chậm đường truyền cáp quang quốc tế',
        'severity'    => 'minor',
        'status'      => 'resolved',
        'started_at'  => date('Y-m-d 14:00:00', strtotime('-10 days')),
        'resolved_at' => date('Y-m-d 16:30:00', strtotime('-10 days')),
        'service_id'  => 8,
        'message'     => 'Nhà mạng ISP đã định tuyến lưu lượng sang tuyến cáp đất liền dự phòng.'
    ]
];

$stmtInc = $pdo->prepare(
    'INSERT IGNORE INTO incidents (id, title, severity, status, started_at, resolved_at, created_by, created_at, updated_at)
     VALUES (:id, :title, :severity, :status, :started_at, :resolved_at, 1, :created_at, NOW())'
);

$stmtIncSvc = $pdo->prepare('INSERT IGNORE INTO incident_services (incident_id, service_id) VALUES (:iid, :sid)');
$stmtIncUpd = $pdo->prepare(
    'INSERT IGNORE INTO incident_updates (id, incident_id, status, message, created_by, created_at)
     VALUES (:id, :iid, :status, :message, 1, :created_at)'
);

foreach ($incidents as $inc) {
    $stmtInc->execute([
        ':id'          => $inc['id'],
        ':title'       => $inc['title'],
        ':severity'    => $inc['severity'],
        ':status'      => $inc['status'],
        ':started_at'  => $inc['started_at'],
        ':resolved_at' => $inc['resolved_at'],
        ':created_at'  => $inc['started_at']
    ]);
    $stmtIncSvc->execute([':iid' => $inc['id'], ':sid' => $inc['service_id']]);
    $stmtIncUpd->execute([
        ':id'         => $inc['id'],
        ':iid'        => $inc['id'],
        ':status'     => $inc['status'],
        ':message'    => $inc['message'],
        ':created_at' => $inc['started_at']
    ]);
}

// 4. Lịch Bảo Trì Mẫu (Lưu trữ trực tiếp trong MySQL)
$maintenances = [
    [
        'id'           => 1,
        'title'        => 'Bảo trì nâng cấp Cơ sở dữ liệu Thư viện số',
        'description'  => 'Tối ưu chỉ mục và sao lưu dữ liệu máy chủ thư viện số định kỳ.',
        'status'       => 'in_progress',
        'scheduled_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'expected_end' => date('Y-m-d H:i:s', strtotime('+4 hours')),
        'service_id'   => 7
    ],
    [
        'id'           => 2,
        'title'        => 'Nâng cấp thiết bị phát Wi-Fi Khu giảng đường A7',
        'description'  => 'Thay thế 8 Access Point chuẩn Wi-Fi 6 tại giảng đường A7.',
        'status'       => 'scheduled',
        'scheduled_at' => date('Y-m-d 08:00:00', strtotime('+2 days')),
        'expected_end' => date('Y-m-d 11:30:00', strtotime('+2 days')),
        'service_id'   => 6
    ],
    [
        'id'           => 3,
        'title'        => 'Bảo trì Switch mạng khu B',
        'description'  => 'Kiểm tra đường truyền trục cáp quang nối giữa khu A và B.',
        'status'       => 'completed',
        'scheduled_at' => date('Y-m-05 09:00:00'),
        'expected_end' => date('Y-m-05 11:00:00'),
        'service_id'   => 6
    ],
    [
        'id'           => 4,
        'title'        => 'Nâng cấp Firewall trung tâm',
        'description'  => 'Nâng cấp bản vá firmware cho Firewall Fortinet Core.',
        'status'       => 'completed',
        'scheduled_at' => date('Y-m-06 22:00:00'),
        'expected_end' => date('Y-m-06 23:30:00'),
        'service_id'   => 8
    ]
];

$stmtMaint = $pdo->prepare(
    'INSERT IGNORE INTO maintenances (id, title, description, status, scheduled_at, expected_end, created_by, created_at, updated_at)
     VALUES (:id, :title, :description, :status, :scheduled_at, :expected_end, 1, NOW(), NOW())'
);
$stmtMaintSvc = $pdo->prepare('INSERT IGNORE INTO maintenance_services (maintenance_id, service_id) VALUES (:mid, :sid)');

foreach ($maintenances as $m) {
    $stmtMaint->execute([
        ':id'           => $m['id'],
        ':title'        => $m['title'],
        ':description'  => $m['description'],
        ':status'       => $m['status'],
        ':scheduled_at' => $m['scheduled_at'],
        ':expected_end' => $m['expected_end'],
    ]);
    $stmtMaintSvc->execute([':mid' => $m['id'], ':sid' => $m['service_id']]);
}

// 5. Bổ sung 90 ngày uptime mẫu nhưng không ghi đè các snapshot đã có
$stmtUpDay = $pdo->prepare(
    'INSERT IGNORE INTO service_daily_uptime (service_id, snapshot_date, uptime_pct, incident_count)
     VALUES (:sid, :sdate, :up, :inc)'
);

for ($sid = 1; $sid <= 9; $sid++) {
    for ($i = 89; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $isIncidentDay = ($sid === 2 && in_array($i, [0, 7, 14], true));
        $uptime = $isIncidentDay ? 88.50 : 100.00;
        $incCount = $isIncidentDay ? 1 : 0;
        $stmtUpDay->execute([
            ':sid'   => $sid,
            ':sdate' => $date,
            ':up'    => $uptime,
            ':inc'   => $incCount
        ]);
    }
}

echo "-> Seed dữ liệu hoàn tất an toàn và thành công 100%!\n";
