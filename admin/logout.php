<?php
/**
 * Admin & Editor Logout
 * /admin/logout.php
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['admin_id'])) {
    try {
        $pdo = Database::getInstance();
        $pdo->exec("SET NAMES utf8mb4");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Xóa remember_token trong CSDL
        $up = $pdo->prepare('UPDATE users SET remember_token = NULL WHERE id = :id');
        $up->execute([':id' => (int)$_SESSION['admin_id']]);

        // Ghi nhật ký đăng xuất
        $log = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
             VALUES (:uid, :uname, "logout", "auth", :uid, "Đăng xuất khỏi hệ thống an toàn", :ip, NOW())'
        );
        $log->execute([
            ':uid'   => (int)$_SESSION['admin_id'],
            ':uname' => $_SESSION['admin_name'] ?? 'User',
            ':ip'    => $ip
        ]);
    } catch (Throwable) {
        // Bỏ qua lỗi nhật ký khi đăng xuất
    }
}

// Xóa Cookie Remember Me nếu có
if (isset($_COOKIE['status_admin_remember'])) {
    setcookie('status_admin_remember', '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE['status_admin_remember']);
}

// Hủy Session hiện tại
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Khởi tạo phiên mới để truyền Flash thông báo đăng xuất
session_start();
$_SESSION['flash'] = [
    'type'    => 'info',
    'message' => 'Bạn đã đăng xuất khỏi hệ thống thành công.'
];

header('Location: login.php');
exit;
