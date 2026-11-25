<?php
declare(strict_types=1);

/**
 * UTH STATUS – AI Chatbot API Endpoint v2.0
 * POST /api/ai_chat.php
<<<<<<< HEAD
 * Trợ lý ảo hỗ trợ tra cứu trạng thái dịch vụ, bãi xe sân trường và sự cố UTH
=======
 * Trợ lý ảo hỗ trợ tra cứu trạng thái dịch vụ và sự cố UTH
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET Endpoint: Trả về trạng thái sẵn sàng của Trợ lý AI ───────────────────
if ($method === 'GET') {
    $settings = loadAiSettings();
    $hasKey = !empty($settings['gemini_api_key'] ?? '') || !empty(getenv('GEMINI_API_KEY'));

    echo json_encode([
        'success'   => true,
        'message'   => 'UTH AI Chatbot API sẵn sàng hoạt động.',
        'providers' => [
            'gemini_available' => $hasKey,
            'smart_offline'    => true,
            'active_model'     => $settings['gemini_model'] ?? 'gemini-1.5-flash',
            'sound_enabled'    => $settings['sound_enabled'] ?? true
        ],
        'default_suggestions' => [
<<<<<<< HEAD
            '🅿️ Bãi xe sân trường lúc này thế nào?',
=======
            '🎓 Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
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

// ── Rate Limiting bảo vệ hệ thống (Tối đa 40 requests / phút / session) ───────
$now = time();
if (!isset($_SESSION['ai_chat_rate'])) {
    $_SESSION['ai_chat_rate'] = ['count' => 0, 'start' => $now];
}

if ($now - $_SESSION['ai_chat_rate']['start'] > 60) {
    $_SESSION['ai_chat_rate'] = ['count' => 1, 'start' => $now];
} else {
    $_SESSION['ai_chat_rate']['count']++;
    if ($_SESSION['ai_chat_rate']['count'] > 40) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn đang gửi tin nhắn quá nhanh. Vui lòng đợi vài giây và thử lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Lấy nội dung câu hỏi & lịch sử trò chuyện (hỗ trợ cả JSON body lẫn $_POST) ─
$rawInput = file_get_contents('php://input');
$data = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$userMessage = trim((string) ($data['message'] ?? $_POST['message'] ?? ''));
$history = $data['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}

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

$startTime = microtime(true);

try {
    $pdo = Database::getInstance();
    $contextData = getLiveSystemContext($pdo);
    $settings = loadAiSettings();

    $aiResponse = null;
    $apiKey = $settings['gemini_api_key'] ?: (getenv('GEMINI_API_KEY') ?: '');
    $modelName = $settings['gemini_model'] ?: 'gemini-1.5-flash';
    $preferOffline = !empty($settings['prefer_smart_offline']);

    // 1. Nếu có Gemini API Key và không ép chạy Offline thì gọi Google Gemini
    if (!empty($apiKey) && !$preferOffline) {
        $geminiResult = callGeminiApi($userMessage, $contextData, $apiKey, $history, $modelName);
        if (!empty($geminiResult['success'])) {
            $aiResponse = $geminiResult;
            // Bổ sung actions và suggestions từ smart engine để giao diện có nút bấm
            $offlineBackup = querySmartOfflineEngine($userMessage, $contextData, $history);
            $aiResponse['actions'] = $offlineBackup['actions'] ?? [];
            $aiResponse['suggestions'] = $offlineBackup['suggestions'] ?? [];
        }
    }

    // 2. Tự động fallback về Smart Live Context Engine nếu chưa có key hoặc Gemini bận
    if (!$aiResponse || empty($aiResponse['success'])) {
        $aiResponse = querySmartOfflineEngine($userMessage, $contextData, $history);
    }

    $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

    $defaultSuggestions = [
<<<<<<< HEAD
        '🅿️ Bãi xe sân trường còn chỗ không?',
=======
        '🎓 Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        '⚠️ Dịch vụ CNTT nào đang bảo trì?',
        '📶 Cách kết nối Wi-Fi eduroam?',
        '📝 Hướng dẫn gửi báo cáo sự cố?'
    ];

    echo json_encode([
        'success'          => true,
        'message'          => $aiResponse['message'],
        'provider'         => $aiResponse['provider'] ?? 'smart_offline',
        'model'            => $aiResponse['model'] ?? 'Supercharged Smart Engine v2.0',
        'actions'          => $aiResponse['actions'] ?? [],
        'suggestions'      => !empty($aiResponse['suggestions']) ? $aiResponse['suggestions'] : $defaultSuggestions,
        'response_time_ms' => $elapsedMs,
        'timestamp'        => date('H:i:s - d/m/Y')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Đã xảy ra lỗi khi xử lý câu hỏi: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
