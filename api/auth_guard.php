<?php
/**
 * UTC STATUS - API Authentication Guard
 * Bảo vệ các endpoint API nội bộ: Bắt buộc đăng nhập hệ thống
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'code' => 401,
        'message' => 'Truy cập bị từ chối: Vui lòng đăng nhập hệ thống UTC STATUS để truy cập dữ liệu.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

