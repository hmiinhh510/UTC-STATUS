<?php


declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['error' => 'Phương thức không được hỗ trợ (Method Not Allowed).']);
}

// ── Đọc và giải mã dữ liệu đầu vào ──────────────────────────
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    jsonOut(400, ['error' => 'Dữ liệu JSON không hợp lệ.']);
}

$type = trim((string) ($data['type'] ?? 'email'));
$endpoint = trim((string) ($data['endpoint'] ?? ''));

// ── Kiểm tra tính hợp lệ của dữ liệu ────────────────────────
if (!in_array($type, ['email', 'slack'], true)) {
    jsonOut(400, ['error' => 'Loại thông báo không hợp lệ. Chỉ chấp nhận email hoặc slack.']);
}

if ($endpoint === '') {
    jsonOut(400, ['error' => 'Vui lòng cung cấp địa chỉ email hoặc URL Slack Webhook.']);
}

if ($type === 'email') {
    if (!filter_var($endpoint, FILTER_VALIDATE_EMAIL)) {
        jsonOut(400, ['error' => 'Địa chỉ email không đúng định dạng.']);
    }
} elseif ($type === 'slack') {
    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://hooks.slack.com/')) {
        jsonOut(400, ['error' => 'Webhook URL Slack phải bắt đầu bằng https://hooks.slack.com/']);
    }
}

try {
    $pdo = Database::getInstance();
    $pdo->exec("SET NAMES utf8mb4");

    // Kiểm tra xem endpoint đã tồn tại trong CSDL chưa
    $stmtCheck = $pdo->prepare('SELECT id, verified, token FROM subscriptions WHERE endpoint = :ep LIMIT 1');
    $stmtCheck->execute([':ep' => $endpoint]);
    $existing = $stmtCheck->fetch();

    $configuredBaseUrl = rtrim((string) (getenv('UTC_STATUS_BASE_URL') ?: ''), '/');
    if ($configuredBaseUrl !== '') {
        $baseUrl = $configuredBaseUrl;
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $serverPort = (int) ($_SERVER['SERVER_PORT'] ?? ($protocol === 'https' ? 443 : 80));
        $defaultPort = $protocol === 'https' ? 443 : 80;
        $portSuffix = $serverPort === $defaultPort ? '' : ':' . $serverPort;
        $applicationPath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
        $baseUrl = "{$protocol}://{$serverName}{$portSuffix}{$applicationPath}";
    }

    $baseUrlParts = parse_url($baseUrl);
    if (
        !is_array($baseUrlParts) ||
        !in_array(strtolower((string) ($baseUrlParts['scheme'] ?? '')), ['http', 'https'], true) ||
        empty($baseUrlParts['host']) ||
        isset($baseUrlParts['user']) ||
        isset($baseUrlParts['pass']) ||
        isset($baseUrlParts['query']) ||
        isset($baseUrlParts['fragment'])
    ) {
        throw new RuntimeException('UTC_STATUS_BASE_URL must be an absolute HTTP(S) URL.');
    }

    if ($existing) {
        if ((int) $existing['verified'] === 1) {
            jsonOut(200, [
                'success' => true,
                'verified' => true,
                'message' => 'Địa chỉ này đã được đăng ký và xác thực thành công từ trước!',
                'type' => $type
            ]);
        } else {
            // Chưa xác minh -> tái tạo token mới
            $token = bin2hex(random_bytes(32));
            $stmtUpdate = $pdo->prepare('UPDATE subscriptions SET token = :token, type = :type WHERE id = :id');
            $stmtUpdate->execute([':token' => $token, ':type' => $type, ':id' => $existing['id']]);
            $verifyUrl = "{$baseUrl}/verify.php?token={$token}";

            jsonOut(200, [
                'success' => true,
                'verified' => false,
                'message' => 'Đăng ký nhận thông báo thành công! Vui lòng truy cập liên kết kích hoạt để hoàn tất xác minh email.',
                'verification_url' => $verifyUrl,
                'type' => $type
            ]);
        }
    } else {
        // Đăng ký mới -> verified = 0 (chưa kích hoạt)
        $token = bin2hex(random_bytes(32));
        $stmtInsert = $pdo->prepare(
            'INSERT INTO subscriptions (type, endpoint, token, verified, created_at)
             VALUES (:type, :endpoint, :token, 0, NOW())'
        );
        $stmtInsert->execute([
            ':type' => $type,
            ':endpoint' => $endpoint,
            ':token' => $token,
        ]);

        $verifyUrl = "{$baseUrl}/verify.php?token={$token}";

        // Ghi nhật ký hệ thống
        $subId = (int) $pdo->lastInsertId();
        $stmtLog = $pdo->prepare(
            'INSERT INTO activity_logs (username, action, target_type, target_id, details, ip_address, created_at)
             VALUES ("Guest", "subscribe", "subscription", :sid, :details, :ip, NOW())'
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmtLog->execute([
            ':sid' => $subId,
            ':details' => "Đăng ký nhận thông báo mới ({$type}): {$endpoint} (Chờ xác minh)",
            ':ip' => $ip
        ]);

        jsonOut(200, [
            'success' => true,
            'verified' => false,
            'message' => 'Đăng ký thành công! Để tránh thư rác, vui lòng bấm vào liên kết kích hoạt bên dưới để xác minh email.',
            'verification_url' => $verifyUrl,
            'type' => $type
        ]);
    }

} catch (Throwable $e) {
    error_log('[API/subscribe] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ. Vui lòng thử lại sau.']);
}
