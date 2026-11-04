<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

/**
 * UTC STATUS - User Incident Reporting API
 * POST /api/report_incident.php
 * Kênh tiếp nhận báo cáo sự cố từ người dùng / sinh viên / giảng viên với quy trình triage nhanh
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức không được hỗ trợ (chỉ chấp nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

if (!is_array($body)) {
    $body = $_POST;
}

$reporterName  = trim($body['reporter_name'] ?? '');
$reporterEmail = trim($body['reporter_email'] ?? '');
$title         = trim($body['title'] ?? '');
$description   = trim($body['description'] ?? '');
$serviceId     = !empty($body['service_id']) ? (int) $body['service_id'] : null;
$severity      = trim($body['severity'] ?? 'medium');

$allowedSeverities = ['low', 'medium', 'high', 'critical'];
if (!in_array($severity, $allowedSeverities, true)) {
    $severity = 'medium';
}

if ($reporterName === '' || $reporterEmail === '' || $title === '' || $description === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng điền đầy đủ họ tên, email, tiêu đề và mô tả sự cố.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($reporterEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Địa chỉ email không đúng định dạng.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Kiểm tra service_id nếu có
    $serviceName = 'Hệ thống chung';
    if ($serviceId !== null) {
        $svcStmt = $pdo->prepare('SELECT name FROM services WHERE id = :id');
        $svcStmt->execute([':id' => $serviceId]);
        $found = $svcStmt->fetchColumn();
        if ($found) {
            $serviceName = (string) $found;
        } else {
            $serviceId = null;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO incident_reports 
            (service_id, reporter_name, reporter_email, title, description, severity, status, created_at)
         VALUES 
            (:service_id, :reporter_name, :reporter_email, :title, :description, :severity, "pending", NOW())'
    );
    $stmt->execute([
        ':service_id'     => $serviceId,
        ':reporter_name'  => $reporterName,
        ':reporter_email' => $reporterEmail,
        ':title'          => $title,
        ':description'    => $description,
        ':severity'       => $severity,
    ]);

    $reportId = (int) $pdo->lastInsertId();

    // Ghi nhật ký vào activity_logs
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $log = $pdo->prepare(
            'INSERT INTO activity_logs (username, action, target_type, target_id, details, ip_address, created_at)
             VALUES (:uname, "user_incident_report", "incident_report", :tid, :details, :ip, NOW())'
        );
        $log->execute([
            ':uname'   => $reporterName . ' (' . $reporterEmail . ')',
            ':tid'     => $reportId,
            ':details' => "Báo cáo sự cố [#{$reportId}]: {$title} - Dịch vụ: {$serviceName} (Mức độ: {$severity})",
            ':ip'      => $ip
        ]);
    } catch (Throwable $e) {
        error_log('[ActivityLog Error] ' . $e->getMessage());
    }

    http_response_code(201);
    echo json_encode([
        'success'      => true,
        'report_id'    => $reportId,
        'message'      => "Cảm ơn bạn đã báo cáo sự cố! Báo cáo mã [#{$reportId}] đã được tiếp nhận và chuyển đến đội ngũ IT trực vận hành.",
        'service_name' => $serviceName,
        'status'       => 'pending'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[Report Incident Error] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi máy chủ khi tiếp nhận báo cáo. Vui lòng thử lại sau.'
    ], JSON_UNESCAPED_UNICODE);
}

