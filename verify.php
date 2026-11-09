<?php
/**
 * Email Verification Endpoint
 * /verify.php?token=...
 */

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mailer.php';

$token = trim((string) ($_GET['token'] ?? ''));
$status = 'error';
$message = '';
$endpoint = '';

if ($token === '' || strlen($token) !== 64) {
    $message = 'Mã xác thực không hợp lệ hoặc thiếu thông tin.';
} else {
    try {
        $pdo = Database::getInstance();
        $pdo->exec("SET NAMES utf8mb4");

        $stmt = $pdo->prepare('SELECT id, type, endpoint, verified FROM subscriptions WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $sub = $stmt->fetch();

        if (!$sub) {
            $message = 'Mã xác thực không tồn tại hoặc đã bị hủy.';
        } elseif ((int)$sub['verified'] === 1) {
            $status   = 'already';
            $endpoint = $sub['endpoint'];
            $message  = "Địa chỉ \"{$sub['endpoint']}\" đã được xác minh trước đó.";
        } else {
            // Xác thực thành công
            $update = $pdo->prepare('UPDATE subscriptions SET verified = 1, verified_at = NOW() WHERE id = :id');
            $update->execute([':id' => $sub['id']]);

            // Gửi email thông báo kích hoạt thành công
            if ($sub['type'] === 'email') {
                sendSubscriberActivationEmail($sub['endpoint'], $token);
            }

            // Ghi nhật ký
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $logStmt = $pdo->prepare(
                'INSERT INTO activity_logs (username, action, target_type, target_id, details, ip_address, created_at)
                 VALUES ("Guest", "verify_email", "subscription", :sid, :details, :ip, NOW())'
            );
            $logStmt->execute([
                ':sid'     => $sub['id'],
                ':details' => "Xác minh email thành công: {$sub['endpoint']}",
                ':ip'      => $ip
            ]);

            $status   = 'success';
            $endpoint = $sub['endpoint'];
            $message  = "Chúc mừng! Địa chỉ \"{$sub['endpoint']}\" đã được kích hoạt thành công.";
        }
    } catch (Throwable $e) {
        error_log('[Verify Error] ' . $e->getMessage());
        $message = 'Lỗi hệ thống khi xử lý xác minh. Vui lòng thử lại sau.';
    }
}

// Nếu yêu cầu trả về JSON
if ((isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) || isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'   => $status,
        'message'  => $message,
        'endpoint' => $endpoint
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Xác minh Đăng ký Nhận Thông báo – UTC STATUS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/style.css?v=2.3" />
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background-color: var(--bg-page); }
    .verify-box {
      max-width: 480px; width: 100%; background: #ffffff;
      border: 1px solid var(--border-color); border-radius: 18px;
      padding: 2.5rem; text-align: center; box-shadow: var(--shadow-hover);
    }
    .verify-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1.5rem auto; }
  </style>
</head>
<body>

  <div class="verify-box">
    <?php if ($status === 'success'): ?>
      <div class="verify-icon bg-success-subtle text-success">
        <i class="fa-solid fa-circle-check"></i>
      </div>
      <h4 class="fw-800 text-success mb-2">Xác Minh Thành Công!</h4>
      <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
      <div class="p-3 bg-light rounded text-muted small mb-4">
        Từ bây giờ, bạn sẽ nhận được thông báo tự động mỗi khi hệ thống có sự cố hoặc lịch bảo trì.
      </div>
    <?php elseif ($status === 'already'): ?>
      <div class="verify-icon bg-info-subtle text-info">
        <i class="fa-solid fa-envelope-circle-check"></i>
      </div>
      <h4 class="fw-800 text-primary mb-2">Đã Được Xác Thực</h4>
      <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
    <?php else: ?>
      <div class="verify-icon bg-danger-subtle text-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
      </div>
      <h4 class="fw-800 text-danger mb-2">Xác Minh Thất Bại</h4>
      <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <a href="index.php" class="btn btn-primary w-100 py-2 fw-600" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
      <i class="fa-solid fa-arrow-left me-2"></i>Trở về Trang Trạng Thái UTC
    </a>
  </div>

</body>
</html>
