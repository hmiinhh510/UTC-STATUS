<?php
/**
 * Unsubscribe Endpoint
 * /unsubscribe.php?token=...
 */

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/config/database.php';

$token = trim((string) ($_GET['token'] ?? ''));
$success = false;
$message = '';

if ($token !== '' && strlen($token) === 64) {
    try {
        $pdo = Database::getInstance();
        $pdo->exec("SET NAMES utf8mb4");

        $stmt = $pdo->prepare('SELECT id, endpoint FROM subscriptions WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $sub = $stmt->fetch();

        if ($sub) {
            $del = $pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
            $del->execute([':id' => $sub['id']]);

            // Ghi log
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log = $pdo->prepare(
                'INSERT INTO activity_logs (username, action, target_type, target_id, details, ip_address, created_at)
                 VALUES ("Guest", "unsubscribe", "subscription", :sid, :details, :ip, NOW())'
            );
            $log->execute([
                ':sid'     => $sub['id'],
                ':details' => "Hủy nhận thông báo: {$sub['endpoint']}",
                ':ip'      => $ip
            ]);

            $success = true;
            $message = "Đã hủy đăng ký thành công cho địa chỉ \"{$sub['endpoint']}\".";
        } else {
            $message = "Mã xác thực không tồn tại hoặc đã được hủy trước đó.";
        }
    } catch (Throwable $e) {
        $message = "Lỗi hệ thống: " . $e->getMessage();
    }
} else {
    $message = "Mã xác thực không hợp lệ.";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hủy Nhận Thông Báo – UTC STATUS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="assets/css/style.css?v=2.3" />
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background-color: var(--bg-page); }
    .box { max-width: 480px; width: 100%; background: #ffffff; border-radius: 16px; padding: 2.5rem; text-align: center; border: 1px solid var(--border-color); }
  </style>
</head>
<body>
  <div class="box">
    <h4 class="fw-800 mb-3 <?= $success ? 'text-success' : 'text-danger' ?>">
      <?= $success ? 'Đã Hủy Đăng Ký' : 'Không Thể Xử Lý' ?>
    </h4>
    <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
    <a href="index.php" class="btn btn-outline-secondary">Trở về Trang chủ</a>
  </div>
</body>
</html>
