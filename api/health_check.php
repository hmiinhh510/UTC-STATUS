<?php
/**
 * GET /api/health_check.php performs read-only probes.
 * POST /api/health_check.php performs probes and updates service status.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/health_check_runner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jsonOut(405, ['error' => 'Method Not Allowed']);
}

if (!in_array($_SESSION['admin_role'] ?? '', ['admin', 'editor'], true)) {
    jsonOut(403, ['error' => 'Chỉ quản trị viên hoặc biên tập viên mới được chạy Health Check.']);
}

if ($method === 'GET' && isset($_GET['auto_update'])) {
    jsonOut(405, ['error' => 'State updates require a CSRF-protected POST request.']);
}

if ($method === 'POST') {
    $expectedCsrfToken = $_SESSION['csrf_token'] ?? '';
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($expectedCsrfToken === '' || $csrfToken === '' || !hash_equals($expectedCsrfToken, $csrfToken)) {
        jsonOut(403, ['error' => 'Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.']);
    }

}

$rawServiceId = $method === 'POST'
    ? ($_POST['service_id'] ?? $_GET['service_id'] ?? null)
    : ($_GET['service_id'] ?? null);
$serviceId = null;
if ($rawServiceId !== null) {
    $serviceId = filter_var($rawServiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($serviceId === false) {
        jsonOut(400, ['error' => 'Tham số service_id không hợp lệ.']);
    }
}

try {
    $pdo = Database::getInstance();
    $result = runHealthChecks(
        $pdo,
        $serviceId === null ? null : (int) $serviceId,
        $method === 'POST',
        $method === 'POST' ? (int) $_SESSION['admin_id'] : null
    );
    if ($serviceId !== null && $result['checked_count'] === 0) {
        jsonOut(404, ['error' => 'Không tìm thấy dịch vụ cần kiểm tra.']);
    }
    jsonOut(200, $result);
} catch (Throwable $e) {
    error_log('[API/health_check] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ khi kiểm tra dịch vụ.']);
}
