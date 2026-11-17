<?php
declare(strict_types=1);

/**
 * UTC STATUS - AI Chatbot API Endpoint
 * POST /api/ai_chat.php
 * Trợ lý ảo hỗ trợ tra cứu trạng thái dịch vụ, bãi xe sân trường và sự cố UTC
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $hasKey = !empty(GEMINI_API_KEY);
    echo json_encode([
        'success'   => true,
        'message'   => 'UTC AI Chatbot API sẵn sàng hoạt động.',
        'providers' => [
            'gemini_available' => $hasKey,
            'smart_offline'    => true
        ],
        'default_suggestions' => [
            '🅿️ Bãi xe sân trường lúc này thế nào?',
            '⚠️ Có dịch vụ nào đang gặp sự cố không?',
            '🛠️ Lịch trình bảo trì trong tuần tới?',
            '📶 Hướng dẫn kết nối Wi-Fi eduroam?'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức HTTP không được hỗ trợ. Vui lòng sử dụng POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Rate Limiting bảo vệ hệ thống (Tối đa 30 requests / phút / session) ───────
$now = time();
if (!isset($_SESSION['ai_chat_rate'])) {
    $_SESSION['ai_chat_rate'] = ['count' => 0, 'start' => $now];
}

if ($now - $_SESSION['ai_chat_rate']['start'] > 60) {
    $_SESSION['ai_chat_rate'] = ['count' => 1, 'start' => $now];
} else {
    $_SESSION['ai_chat_rate']['count']++;
    if ($_SESSION['ai_chat_rate']['count'] > 30) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn đang gửi tin nhắn quá nhanh. Vui lòng đợi vài giây và thử lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Lấy nội dung câu hỏi (hỗ trợ cả JSON body lẫn $_POST) ─────────────────────
$rawInput = file_get_contents('php://input');
$data = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$userMessage = trim($data['message'] ?? $_POST['message'] ?? '');

if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng nhập nội dung câu hỏi trước khi gửi.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($userMessage, 'UTF-8') > 1000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Nội dung câu hỏi quá dài (tối đa 1000 ký tự).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();
    $contextData = getLiveSystemContext($pdo);

    $aiResponse = null;

    // 1. Thử gọi Google Gemini API nếu đã cấu hình Key
    if (!empty(GEMINI_API_KEY)) {
        $geminiResult = callGeminiApi($userMessage, $contextData, GEMINI_API_KEY);
        if ($geminiResult['success']) {
            $aiResponse = $geminiResult;
        }
    }

    // 2. Tự động fallback về Smart Live Context Engine nếu chưa có key hoặc Gemini bận
    if (!$aiResponse || empty($aiResponse['success'])) {
        $aiResponse = querySmartOfflineEngine($userMessage, $contextData);
    }

    $defaultSuggestions = [
        '🅿️ Bãi xe sân trường còn chỗ không?',
        '⚠️ Dịch vụ CNTT nào đang bảo trì?',
        '📶 Cách kết nối Wi-Fi eduroam?',
        '📝 Hướng dẫn gửi báo cáo sự cố?'
    ];

    echo json_encode([
        'success'     => true,
        'message'     => $aiResponse['message'],
        'provider'    => $aiResponse['provider'] ?? 'smart_offline',
        'timestamp'   => date('H:i:s - d/m/Y'),
        'suggestions' => $defaultSuggestions
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Đã xảy ra lỗi khi xử lý câu hỏi: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
