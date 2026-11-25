<?php
/**
 * Security & Session Utilities
 * UTC STATUS - Đại học Giao thông Vận tải
 */
declare(strict_types=1);

/**
 * Khởi tạo Session với cấu hình bảo mật chuẩn OWASP
 */
function initSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        // Cấu hình cookie an toàn
        session_set_cookie_params([
            'lifetime' => 0,              // Hết hạn khi đóng trình duyệt
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,      // Chỉ gửi qua HTTPS nếu có
            'httponly' => true,           // Chống XSS đọc cookie session
            'samesite' => 'Lax',          // Chống CSRF qua third-party context
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
    }
}

/**
 * Thiết lập các Security Headers chuẩn (OWASP Secure Headers)
 */
function sendSecurityHeaders(): void
{
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

/**
 * Xử lý CORS an toàn cho API nội bộ
 */
function sendCorsHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedHosts = [
        'localhost',
        '127.0.0.1',
    ];

    // Cho phép cấu hình thêm qua biến môi trường nếu chạy tên miền riêng
    $customHost = getenv('UTC_STATUS_ALLOWED_HOST');
    if ($customHost) {
        $allowedHosts[] = $customHost;
    }

    if ($origin !== '') {
        $parsed = parse_url($origin);
        $host = $parsed['host'] ?? '';
        if (in_array($host, $allowedHosts, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
        }
    } else {
        // Cho phép truy cập direct cùng origin
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
}

