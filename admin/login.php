<?php
/**
 * UTC STATUS - Cổng Xác Thực Quản Trị & Người Dùng Hệ Thống
 * File: admin/login.php
 *
 * Tính năng đầy đủ:
 *  1. Đăng nhập (Login): Username/Email, Mật khẩu, Ghi nhớ đăng nhập (Remember me), Anti brute-force delay.
 *     - Sinh viên (student): Chuyển hướng thẳng vào trang lịch dịch vụ ../index.php
 *     - Quản trị (admin / editor): Chuyển hướng vào trang quản trị index.php
 *  2. Đăng ký (Register): Họ tên, Tên đăng nhập / Mã SV, Email, Mật khẩu (thanh đo độ mạnh), mặc định role 'student'.
 *  3. Quên mật khẩu (Forgot Password): Quy trình 2 bước:
 *     - Bước 1: Nhập Username/Mã SV VÀ Email đã đăng ký để xác minh và sinh mã OTP 6 số.
 *     - Bước 2: Nhập OTP và đặt lại mật khẩu mới.
 *  4. Bảo mật: CSRF Token bảo vệ toàn bộ POST, password_hash(PASSWORD_DEFAULT), PDO Prepared Statements, Audit Logging.
 */

declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=UTF-8');
unset($_SESSION['last_reset_otp'], $_SESSION['reset_username'], $_SESSION['reset_email']);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::getInstance();
$pdo->exec("SET NAMES utf8mb4");

// ── Helper: Audit Logging ─────────────────────────────────────
function logAuthAction(PDO $pdo, ?int $userId, string $username, string $action, string $details): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
             VALUES (:uid, :uname, :action, "auth", :tid, :details, :ip, NOW())'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':uname'   => $username,
            ':action'  => $action,
            ':tid'     => $userId,
            ':details' => $details,
            ':ip'      => $ip
        ]);
    } catch (Throwable $e) {
        error_log('[Auth Audit Log Error] ' . $e->getMessage());
    }
}

// ── Helper: Flash Message ─────────────────────────────────────
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// ── Auto Login via Remember Me Cookie ─────────────────────────
if (!isset($_SESSION['admin_id']) && !empty($_COOKIE['status_admin_remember'])) {
    $cookieParts = explode(':', $_COOKIE['status_admin_remember'], 2);
    if (count($cookieParts) === 2) {
        $cUserId = (int) $cookieParts[0];
        $cToken  = $cookieParts[1];
        if ($cUserId > 0 && $cToken !== '') {
            $stmt = $pdo->prepare('SELECT id, username, full_name, email, role, remember_token FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $cUserId]);
            $u = $stmt->fetch();
            if ($u && !empty($u['remember_token']) && password_verify($cToken, $u['remember_token'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id']       = (int) $u['id'];
                $_SESSION['admin_name']     = !empty($u['full_name']) ? $u['full_name'] : $u['username'];
                $_SESSION['admin_username'] = $u['username'];
                $_SESSION['admin_role']     = $u['role'];
                logAuthAction($pdo, (int)$u['id'], $u['username'], 'login_remember', 'Tự động đăng nhập qua phiên ghi nhớ');

                if ($u['role'] === 'student') {
                    header('Location: ../index.php');
                } else {
                    header('Location: index.php');
                }
                exit;
            }
        }
    }
}

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    if (($_SESSION['admin_role'] ?? '') === 'student') {
        header('Location: ../index.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// ── CSRF Token Setup ──────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Active Tab & Step Setup ───────────────────────────────────
$activeTab = $activeTab ?? ($_GET['tab'] ?? 'login');
if (!in_array($activeTab, ['login', 'register', 'forgot'], true)) {
    $activeTab = 'login';
}

$forgotStep = (int) ($_GET['step'] ?? (!empty($_SESSION['reset_email']) ? 2 : 1));
if ($forgotStep !== 1 && $forgotStep !== 2) {
    $forgotStep = 1;
}

// ── Handle POST Submissions ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn hoặc không hợp lệ (CSRF Token mismatch). Vui lòng thử lại.');
        header("Location: login.php?tab={$activeTab}");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ==========================================================
    // ACTION: LOGIN
    // ==========================================================
    if ($action === 'login') {
        $account  = trim($_POST['account'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember_me']);

        if ($account === '' || $password === '') {
            setFlash('danger', 'Vui lòng nhập đầy đủ Tên đăng nhập (hoặc Email) và Mật khẩu.');
            header('Location: login.php?tab=login');
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, username, full_name, email, password, role 
                 FROM users 
                 WHERE username = :acc1 OR email = :acc2 
                 LIMIT 1'
            );
            $stmt->execute([':acc1' => $account, ':acc2' => $account]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id']       = (int) $user['id'];
                $_SESSION['admin_name']     = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role']     = $user['role'];

                // Ghi nhớ đăng nhập (Remember Me)
                if ($remember) {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
                    $upRemember = $pdo->prepare('UPDATE users SET remember_token = :rt WHERE id = :id');
                    $upRemember->execute([':rt' => $tokenHash, ':id' => $user['id']]);

                    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
                    setcookie(
                        'status_admin_remember',
                        "{$user['id']}:{$rawToken}",
                        [
                            'expires'  => time() + 86400 * 30, // 30 ngày
                            'path'     => '/',
                            'secure'   => $isSecure,
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]
                    );
                }

                // Ghi nhật ký đăng nhập thành công
                logAuthAction(
                    $pdo,
                    (int) $user['id'],
                    $user['username'],
                    'login',
                    "Đăng nhập thành công với vai trò: " . strtoupper($user['role'])
                );

                // Phân quyền chuyển hướng sau đăng nhập:
                if ($user['role'] === 'student') {
                    setFlash('success', 'Chào mừng sinh viên ' . htmlspecialchars($_SESSION['admin_name']) . ' đã đăng nhập vào Hệ thống Lịch Dịch Vụ UTC.');
                    header('Location: ../index.php');
                } else {
                    setFlash('success', 'Đăng nhập thành công! Chào mừng ' . htmlspecialchars($_SESSION['admin_name']) . ' quay trở lại Bảng Quản trị.');
                    header('Location: index.php');
                }
                exit;
            } else {
                // Anti-brute force delay
                sleep(1);

                logAuthAction(
                    $pdo,
                    null,
                    $account ?: 'Unknown',
                    'login_failed',
                    "Đăng nhập thất bại: sai thông tin tài khoản hoặc mật khẩu"
                );

                setFlash('danger', 'Tài khoản (Username / Email) hoặc Mật khẩu không chính xác.');
                header('Location: login.php?tab=login');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[Login Error] ' . $e->getMessage());
            setFlash('danger', 'Lỗi hệ thống trong quá trình đăng nhập. Vui lòng thử lại sau.');
            header('Location: login.php?tab=login');
            exit;
        }
    }

    // ==========================================================
    // ACTION: REGISTER (Mặc định Role: 'student')
    // ==========================================================
    if ($action === 'register') {
        $fullName        = trim($_POST['full_name'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Kiểm tra dữ liệu hợp lệ
        if ($fullName === '' || $username === '' || $email === '' || $password === '') {
            setFlash('danger', 'Vui lòng điền đầy đủ tất cả các trường thông tin đăng ký.');
            header('Location: login.php?tab=register');
            exit;
        }

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            setFlash('danger', 'Họ và tên phải có độ dài từ 2 đến 150 ký tự.');
            header('Location: login.php?tab=register');
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9_\.]{3,50}$/', $username)) {
            setFlash('danger', 'Tên đăng nhập / Mã SV từ 3 - 50 ký tự, chỉ gồm chữ cái không dấu, số, dấu gạch dưới (_) hoặc chấm (.).');
            header('Location: login.php?tab=register');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Địa chỉ Email không đúng định dạng hợp lệ.');
            header('Location: login.php?tab=register');
            exit;
        }

        if (strlen($password) < 6) {
            setFlash('danger', 'Mật khẩu phải chứa ít nhất 6 ký tự để đảm bảo an toàn.');
            header('Location: login.php?tab=register');
            exit;
        }

        if ($password !== $passwordConfirm) {
            setFlash('danger', 'Mật khẩu nhập lại không trùng khớp với mật khẩu đã nhập.');
            header('Location: login.php?tab=register');
            exit;
        }

        try {
            // Kiểm tra trùng lặp username hoặc email
            $checkStmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
            $checkStmt->execute([':u' => $username, ':e' => $email]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if (strcasecmp($existing['username'], $username) === 0) {
                    setFlash('danger', "Tên đăng nhập / Mã SV \"{$username}\" đã tồn tại. Vui lòng chọn tên khác.");
                } else {
                    setFlash('danger', "Địa chỉ Email \"{$email}\" đã được đăng ký tài khoản trong hệ thống.");
                }
                header('Location: login.php?tab=register');
                exit;
            }

            // Tạo tài khoản mới: Mặc định vai trò là 'student'
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = $pdo->prepare(
                'INSERT INTO users (username, full_name, email, password, role, created_at)
                 VALUES (:u, :fn, :e, :p, "student", NOW())'
            );
            $insertStmt->execute([
                ':u'  => $username,
                ':fn' => $fullName,
                ':e'  => $email,
                ':p'  => $passwordHash
            ]);

            $newId = (int) $pdo->lastInsertId();

            logAuthAction(
                $pdo,
                $newId,
                $username,
                'register',
                "Đăng ký tài khoản Sinh viên mới: {$fullName} ({$username} - {$email})"
            );

            setFlash('success', "Chúc mừng bạn đã tạo tài khoản Sinh viên thành công! Bạn có thể đăng nhập ngay để xem lịch dịch vụ UTC.");
            header('Location: login.php?tab=login');
            exit;
        } catch (Throwable $e) {
            error_log('[Register Error] ' . $e->getMessage());
            setFlash('danger', 'Có lỗi xảy ra khi tạo tài khoản. Vui lòng thử lại sau.');
            header('Location: login.php?tab=register');
            exit;
        }
    }

    // ==========================================================
    // ACTION: FORGOT STEP 1 - REQUEST OTP (Xác minh cả Username VÀ Email)
    // ==========================================================
    if ($action === 'forgot_request') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $mailFrom = trim((string) (getenv('UTC_STATUS_MAIL_FROM') ?: ''));

        if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Vui lòng nhập chính xác Tên đăng nhập (hoặc Mã SV) và Địa chỉ Email đã đăng ký.');
            header('Location: login.php?tab=forgot&step=1');
            exit;
        }

        if ($mailFrom === '' || !filter_var($mailFrom, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            setFlash('danger', 'Chức năng gửi email khôi phục chưa được cấu hình. Vui lòng liên hệ quản trị viên.');
            header('Location: login.php?tab=forgot&step=1');
            exit;
        }

        try {
            // Xác thực cả Username VÀ Email cùng khớp với tài khoản
            $stmt = $pdo->prepare('SELECT id, username, full_name, email FROM users WHERE username = :u AND email = :e LIMIT 1');
            $stmt->execute([':u' => $username, ':e' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Tạo mã xác nhận OTP 6 chữ số
                $otp = (string) random_int(100000, 999999);
                $otpHash = hash('sha256', $otp);

                $upStmt = $pdo->prepare(
                    'UPDATE users 
                     SET reset_token = :t, 
                         reset_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) 
                     WHERE id = :id'
                );
                $upStmt->execute([':t' => $otpHash, ':id' => $user['id']]);

                $mailBody = "Mã xác thực UTC STATUS của bạn là {$otp}. Mã có hiệu lực trong 15 phút. Nếu bạn không yêu cầu đặt lại mật khẩu, hãy bỏ qua email này.";
                $headers = [
                    "From: UTC STATUS <{$mailFrom}>",
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                ];
                if (!mail($user['email'], 'UTC STATUS - Khôi phục mật khẩu', $mailBody, implode("\r\n", $headers))) {
                    $clearStmt = $pdo->prepare(
                        'UPDATE users SET reset_token = NULL, reset_expires_at = NULL
                         WHERE id = :id AND reset_token = :token'
                    );
                    $clearStmt->execute([':id' => $user['id'], ':token' => $otpHash]);
                    throw new RuntimeException('Unable to deliver password recovery email.');
                }

                logAuthAction(
                    $pdo,
                    (int) $user['id'],
                    $user['username'],
                    'forgot_otp_request',
                    'Yêu cầu mã OTP khôi phục mật khẩu'
                );

                setFlash('info', 'Nếu thông tin khớp với tài khoản, mã xác thực đã được gửi đến email đăng ký.');
                header('Location: login.php?tab=forgot&step=2');
                exit;
            } else {
                setFlash('info', 'Nếu thông tin khớp với tài khoản, mã xác thực đã được gửi đến email đăng ký.');
                header('Location: login.php?tab=forgot&step=2');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[Forgot Request Error] ' . $e->getMessage());
            setFlash('danger', 'Không thể gửi email khôi phục mật khẩu. Vui lòng thử lại sau hoặc liên hệ quản trị viên.');
            header('Location: login.php?tab=forgot&step=1');
            exit;
        }
    }

    // ==========================================================
    // ACTION: FORGOT STEP 2 - VERIFY OTP & RESET PASSWORD
    // ==========================================================
    if ($action === 'forgot_reset') {
        $username        = trim($_POST['username'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $otp             = trim($_POST['otp'] ?? '');
        $newPassword     = $_POST['new_password'] ?? '';
        $newPassConfirm  = $_POST['new_password_confirm'] ?? '';

        if ($username === '' || $email === '' || !preg_match('/^\d{6}$/', $otp) || $newPassword === '') {
            setFlash('danger', 'Vui lòng nhập đầy đủ thông tin xác nhận và mật khẩu mới.');
            header('Location: login.php?tab=forgot&step=2');
            exit;
        }

        if (strlen($newPassword) < 6) {
            setFlash('danger', 'Mật khẩu mới phải có tối thiểu 6 ký tự.');
            header('Location: login.php?tab=forgot&step=2');
            exit;
        }

        if ($newPassword !== $newPassConfirm) {
            setFlash('danger', 'Xác nhận mật khẩu mới không trùng khớp.');
            header('Location: login.php?tab=forgot&step=2');
            exit;
        }

        try {
            $userIdStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND email = :email LIMIT 1');
            $userIdStmt->execute([':username' => $username, ':email' => $email]);
            $userId = $userIdStmt->fetchColumn();

            $upStmt = $pdo->prepare(
                'UPDATE users
                 SET password = :password,
                     reset_token = NULL,
                     reset_expires_at = NULL,
                     remember_token = NULL
                 WHERE username = :username
                   AND email = :email
                   AND reset_token = :token
                   AND reset_expires_at >= NOW()'
            );
            $upStmt->execute([
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':username' => $username,
                ':email' => $email,
                ':token' => hash('sha256', $otp),
            ]);

            if ($upStmt->rowCount() === 1) {

                logAuthAction(
                    $pdo,
                    $userId === false ? null : (int) $userId,
                    $username,
                    'password_reset',
                    "Khôi phục và đặt lại mật khẩu mới thành công qua mã OTP"
                );

                setFlash('success', 'Mật khẩu đã được đặt lại thành công! Bạn có thể đăng nhập ngay bằng mật khẩu mới.');
                header('Location: login.php?tab=login');
                exit;
            } else {
                setFlash('danger', 'Mã xác thực OTP không chính xác hoặc đã hết thời hạn hiệu lực (15 phút). Vui lòng thử lại hoặc yêu cầu mã mới.');
                header('Location: login.php?tab=forgot&step=2');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[Forgot Reset Error] ' . $e->getMessage());
            setFlash('danger', 'Lỗi hệ thống khi đặt lại mật khẩu. Vui lòng thử lại sau.');
            header('Location: login.php?tab=forgot&step=2');
            exit;
        }
    }
}

// ── Read and Clear Flash Message ──────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Cổng Xác Thực – UTC STATUS</title>
  <meta name="robots" content="noindex, nofollow" />
  
  <!-- CSS Frameworks & Typography -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/style.css?v=2.3" />
  
  <style>
    :root {
      --auth-brand: #007a78;
      --auth-brand-dark: #006764;
      --auth-brand-light: #0d9488;
      --auth-brand-soft: #e6f4f1;
    }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: var(--bg-page);
      background-image: radial-gradient(#cbd5e1 1.2px, transparent 1.2px);
      background-size: 26px 26px;
      font-family: var(--font-family);
      padding: 2rem 1rem;
      margin: 0;
      color: var(--text-primary);
    }

    .auth-wrapper {
      width: 100%;
      max-width: 490px;
      margin: auto;
    }

    .auth-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 22px;
      padding: 2.25rem;
      box-shadow: 0 20px 45px -10px rgba(0, 122, 120, 0.12), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
      position: relative;
      overflow: hidden;
      transition: all 0.3s ease;
    }

    .auth-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--auth-brand), var(--auth-brand-light), #10b981);
    }

    /* Brand Header */
    .brand-badge {
      width: 52px;
      height: 52px;
      background-color: var(--auth-brand-soft);
      color: var(--auth-brand);
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.6rem;
      margin: 0 auto 0.85rem auto;
      box-shadow: 0 4px 10px rgba(0, 122, 120, 0.12);
    }

    .auth-title {
      font-weight: 800;
      font-size: 1.35rem;
      letter-spacing: -0.5px;
      color: var(--text-primary);
      margin-bottom: 0.2rem;
    }

    .auth-subtitle {
      font-size: 0.82rem;
      color: var(--text-muted);
    }

    /* Navigation Tab Pills */
    .auth-nav-pills {
      background: #f1f5f9;
      border-radius: 12px;
      padding: 4px;
      display: flex;
      margin-bottom: 1.75rem;
      border: 1px solid var(--border-color);
      gap: 3px;
    }

    .auth-nav-link {
      flex: 1;
      text-align: center;
      border: none;
      background: transparent;
      color: var(--text-secondary);
      font-size: 0.82rem;
      font-weight: 600;
      padding: 0.55rem 0.4rem;
      border-radius: 9px;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
    }

    .auth-nav-link:hover {
      color: var(--auth-brand);
      background: rgba(255, 255, 255, 0.6);
    }

    .auth-nav-link.active {
      background: #ffffff;
      color: var(--auth-brand);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
      font-weight: 700;
    }

    /* Tab Panes */
    .tab-pane {
      display: none;
      animation: fadeInTab 0.25s ease-in-out forwards;
    }

    .tab-pane.active {
      display: block;
    }

    @keyframes fadeInTab {
      from {
        opacity: 0;
        transform: translateY(6px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Form Inputs */
    .form-label {
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-secondary);
      margin-bottom: 0.35rem;
    }

    .input-group {
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--border-color);
      transition: border-color 0.2s, box-shadow 0.2s;
      background-color: #ffffff;
    }

    .input-group:focus-within {
      border-color: var(--auth-brand-light);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
    }

    .input-group-text {
      background-color: #f8fafc;
      border: none;
      color: #94a3b8;
      font-size: 0.95rem;
      padding-left: 0.85rem;
      padding-right: 0.85rem;
    }

    .form-control {
      border: none;
      font-size: 0.9rem;
      padding: 0.65rem 0.75rem;
      color: var(--text-primary);
      box-shadow: none !important;
    }

    .form-control::placeholder {
      color: #cbd5e1;
      font-size: 0.85rem;
    }

    .btn-toggle-pwd {
      background: transparent;
      border: none;
      color: #94a3b8;
      padding: 0 0.85rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      transition: color 0.15s;
    }

    .btn-toggle-pwd:hover {
      color: var(--auth-brand);
    }

    /* Submit Button */
    .btn-auth-submit {
      background-color: var(--auth-brand);
      border: 1px solid var(--auth-brand);
      color: #ffffff;
      font-weight: 700;
      font-size: 0.92rem;
      padding: 0.72rem 1.25rem;
      border-radius: 10px;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(0, 122, 120, 0.2);
      transition: all 0.2s;
    }

    .btn-auth-submit:hover {
      background-color: var(--auth-brand-dark);
      border-color: var(--auth-brand-dark);
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0, 122, 120, 0.28);
    }

    .btn-auth-submit:active {
      transform: translateY(0);
    }

    /* Password Strength Meter */
    .strength-meter-container {
      margin-top: 0.4rem;
    }

    .strength-bars {
      display: flex;
      gap: 4px;
      height: 4px;
      margin-bottom: 0.35rem;
    }

    .strength-bar {
      flex: 1;
      height: 100%;
      background-color: #e2e8f0;
      border-radius: 2px;
      transition: background-color 0.3s ease;
    }

    .strength-text {
      font-size: 0.72rem;
      color: var(--text-muted);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .strength-label {
      font-weight: 700;
    }

    /* Match Feedback */
    .match-feedback {
      font-size: 0.73rem;
      margin-top: 0.35rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* Role Hint & Testing Badge */
    .testing-box {
      background: #f8fafc;
      border: 1px dashed var(--border-color);
      border-radius: 12px;
      padding: 0.85rem 1rem;
      font-size: 0.77rem;
      color: var(--text-secondary);
      margin-top: 1.5rem;
    }

    .testing-pill {
      font-size: 0.72rem;
      padding: 0.2rem 0.5rem;
      border-radius: 6px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      color: var(--text-primary);
      cursor: pointer;
      transition: all 0.15s;
    }

    .testing-pill:hover {
      background: var(--auth-brand-soft);
      border-color: var(--auth-brand-light);
      color: var(--auth-brand-dark);
    }

    /* Step indicator for Forgot Password */
    .step-indicator {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-bottom: 1.25rem;
    }

    .step-dot {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      font-weight: 700;
      background: #e2e8f0;
      color: var(--text-muted);
      transition: all 0.2s;
    }

    .step-dot.active {
      background: var(--auth-brand);
      color: #ffffff;
      box-shadow: 0 2px 6px rgba(0, 122, 120, 0.25);
    }

    .step-dot.completed {
      background: #10b981;
      color: #ffffff;
    }

    .step-line {
      width: 36px;
      height: 2px;
      background: #e2e8f0;
    }

    .step-line.active {
      background: var(--auth-brand);
    }

    .otp-input {
      font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
      letter-spacing: 8px;
      font-size: 1.4rem;
      font-weight: 700;
      text-align: center;
      padding: 0.75rem;
    }

    .tracking-wider {
      letter-spacing: 2px;
    }
  </style>
</head>
<body>

  <div class="auth-wrapper">
    <div class="auth-card">
      
      <!-- Brand Header -->
      <div class="text-center mb-4">
        <div class="brand-badge">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h1 class="auth-title">UTC STATUS</h1>
        <p class="auth-subtitle mb-0">Hệ Thống Theo Dõi Trạng Thái & Lịch Dịch Vụ</p>
      </div>

      <!-- Navigation Tabs -->
      <div class="auth-nav-pills" role="tablist">
        <button type="button" class="auth-nav-link <?= $activeTab === 'login' ? 'active' : '' ?>" id="tab-btn-login" onclick="switchAuthTab('login')">
          <i class="fa-solid fa-right-to-bracket"></i>
          <span>Đăng nhập</span>
        </button>
        <button type="button" class="auth-nav-link <?= $activeTab === 'register' ? 'active' : '' ?>" id="tab-btn-register" onclick="switchAuthTab('register')">
          <i class="fa-solid fa-user-plus"></i>
          <span>Đăng ký SV</span>
        </button>
        <button type="button" class="auth-nav-link <?= $activeTab === 'forgot' ? 'active' : '' ?>" id="tab-btn-forgot" onclick="switchAuthTab('forgot')">
          <i class="fa-solid fa-key"></i>
          <span>Quên MK</span>
        </button>
      </div>

      <!-- Flash Message Notification -->
      <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show py-2 px-3 small d-flex align-items-start gap-2 mb-3 shadow-sm" role="alert">
          <div class="pt-1">
            <?php if ($flash['type'] === 'success'): ?>
              <i class="fa-solid fa-circle-check text-success fs-6"></i>
            <?php elseif ($flash['type'] === 'info'): ?>
              <i class="fa-solid fa-circle-info text-info fs-6"></i>
            <?php else: ?>
              <i class="fa-solid fa-circle-exclamation text-danger fs-6"></i>
            <?php endif; ?>
          </div>
          <div class="flex-grow-1">
            <?= htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- ======================================================== -->
      <!-- TAB 1: ĐĂNG NHẬP (LOGIN)                                  -->
      <!-- ======================================================== -->
      <div class="tab-pane <?= $activeTab === 'login' ? 'active' : '' ?>" id="pane-login">
        <form method="POST" action="login.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="hidden" name="action" value="login" />

          <!-- Username or Email -->
          <div class="mb-3">
            <label for="login_account" class="form-label">Tên đăng nhập hoặc Email</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
              <input 
                type="text" 
                id="login_account" 
                name="account" 
                class="form-control" 
                placeholder="sinhvien, admin hoặc email@utc.edu.vn" 
                required 
                autocomplete="username"
                autofocus
              />
            </div>
          </div>

          <!-- Password with Eye Toggle -->
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <label for="login_password" class="form-label mb-0">Mật khẩu</label>
            </div>
            <div class="input-group mt-1">
              <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
              <input 
                type="password" 
                id="login_password" 
                name="password" 
                class="form-control" 
                placeholder="••••••••" 
                required 
                autocomplete="current-password"
              />
              <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility('login_password', this)" title="Ẩn/Hiện mật khẩu">
                <i class="fa-regular fa-eye"></i>
              </button>
            </div>
          </div>

          <!-- Remember Me & Forgot Password Link -->
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check small">
              <input class="form-check-input" type="checkbox" name="remember_me" id="remember_me" value="1" />
              <label class="form-check-label text-secondary user-select-none" for="remember_me">
                Ghi nhớ đăng nhập
              </label>
            </div>
            <a href="javascript:void(0)" onclick="switchAuthTab('forgot')" class="small text-decoration-none fw-600" style="color:var(--auth-brand)">
              Quên mật khẩu?
            </a>
          </div>

          <!-- Submit Button -->
          <button type="submit" class="btn-auth-submit">
            <i class="fa-solid fa-right-to-bracket"></i>
            <span>Đăng nhập hệ thống</span>
          </button>

          <!-- Switch to Register -->
          <div class="text-center mt-3 small text-muted">
            Chưa có tài khoản sinh viên? 
            <a href="javascript:void(0)" onclick="switchAuthTab('register')" class="fw-700 text-decoration-none" style="color:var(--auth-brand)">
              Đăng ký tài khoản Sinh viên
            </a>
          </div>
        </form>

        <!-- Quick Test Account Helper -->
        <div class="testing-box">
          <div class="fw-700 text-secondary mb-2 d-flex align-items-center justify-content-between">
            <span><i class="fa-solid fa-vial-circle-check text-success me-1"></i> Tài khoản kiểm thử 3 vai trò:</span>
            <span class="badge bg-light text-muted border">Nhấp để điền</span>
          </div>
          <div class="d-flex flex-column gap-1">
            <div class="d-flex justify-content-between align-items-center">
              <div>&bull; <strong>Sinh viên:</strong> <code>sinhvien</code> / <code>SinhVien@123</code></div>
              <button type="button" class="testing-pill" onclick="autofillLogin('sinhvien', 'SinhVien@123')">Điền Sinh viên</button>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <div>&bull; <strong>Editor:</strong> <code>editor</code> / <code>Editor@123</code></div>
              <button type="button" class="testing-pill" onclick="autofillLogin('editor', 'Editor@123')">Điền Editor</button>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <div>&bull; <strong>Admin:</strong> <code>admin</code> / <code>Admin@123</code></div>
              <button type="button" class="testing-pill" onclick="autofillLogin('admin', 'Admin@123')">Điền Admin</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- TAB 2: ĐĂNG KÝ (REGISTER - Mặc định Role: 'student')      -->
      <!-- ======================================================== -->
      <div class="tab-pane <?= $activeTab === 'register' ? 'active' : '' ?>" id="pane-register">
        <form method="POST" action="login.php" novalidate id="registerForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="hidden" name="action" value="register" />

          <!-- Full Name -->
          <div class="mb-3">
            <label for="reg_fullname" class="form-label">Họ và tên</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-id-card"></i></span>
              <input 
                type="text" 
                id="reg_fullname" 
                name="full_name" 
                class="form-control" 
                placeholder="Ví dụ: Nguyễn Văn An" 
                required 
              />
            </div>
          </div>

          <!-- Username / Mã SV -->
          <div class="mb-3">
            <label for="reg_username" class="form-label">Tên đăng nhập / Mã SV</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
              <input 
                type="text" 
                id="reg_username" 
                name="username" 
                class="form-control" 
                placeholder="Mã SV hoặc tên viết liền (VD: 201200123)" 
                required 
                autocomplete="username"
              />
            </div>
          </div>

          <!-- Email -->
          <div class="mb-3">
            <label for="reg_email" class="form-label">Địa chỉ Email</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
              <input 
                type="email" 
                id="reg_email" 
                name="email" 
                class="form-control" 
                placeholder="masv@student.utc.edu.vn" 
                required 
                autocomplete="email"
              />
            </div>
          </div>

          <!-- Password with Strength Meter -->
          <div class="mb-3">
            <label for="reg_password" class="form-label">Mật khẩu</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
              <input 
                type="password" 
                id="reg_password" 
                name="password" 
                class="form-control" 
                placeholder="Tối thiểu 6 ký tự" 
                required 
                autocomplete="new-password"
                oninput="checkPasswordStrength(this.value)"
              />
              <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility('reg_password', this)" title="Ẩn/Hiện mật khẩu">
                <i class="fa-regular fa-eye"></i>
              </button>
            </div>
            
            <!-- Dynamic Strength Indicator -->
            <div class="strength-meter-container">
              <div class="strength-bars">
                <div class="strength-bar" id="str-bar-1"></div>
                <div class="strength-bar" id="str-bar-2"></div>
                <div class="strength-bar" id="str-bar-3"></div>
                <div class="strength-bar" id="str-bar-4"></div>
              </div>
              <div class="strength-text">
                <span id="strength-hint">Độ mạnh mật khẩu</span>
                <span class="strength-label" id="strength-label">Chưa nhập</span>
              </div>
            </div>
          </div>

          <!-- Confirm Password -->
          <div class="mb-3">
            <label for="reg_password_confirm" class="form-label">Nhập lại mật khẩu</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-shield-check"></i></span>
              <input 
                type="password" 
                id="reg_password_confirm" 
                name="password_confirm" 
                class="form-control" 
                placeholder="Nhập lại chính xác mật khẩu trên" 
                required 
                autocomplete="new-password"
                oninput="checkPasswordMatch()"
              />
              <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility('reg_password_confirm', this)" title="Ẩn/Hiện mật khẩu">
                <i class="fa-regular fa-eye"></i>
              </button>
            </div>
            <div id="match-feedback" class="match-feedback d-none"></div>
          </div>

          <!-- Role Notification Note -->
          <div class="p-2 mb-3 bg-light rounded border border-light-subtle small text-muted d-flex align-items-center gap-2">
            <i class="fa-solid fa-graduation-cap text-teal-primary fs-5"></i>
            <span>Tài khoản đăng ký mới sẽ mặc định mang vai trò <strong>Sinh viên (Student)</strong> để xem lịch dịch vụ.</span>
          </div>

          <!-- Submit Button -->
          <button type="submit" class="btn-auth-submit" id="btnSubmitRegister">
            <i class="fa-solid fa-user-plus"></i>
            <span>Tạo tài khoản Sinh viên</span>
          </button>

          <!-- Back to Login -->
          <div class="text-center mt-3 small text-muted">
            Đã có tài khoản? 
            <a href="javascript:void(0)" onclick="switchAuthTab('login')" class="fw-700 text-decoration-none" style="color:var(--auth-brand)">
              Đăng nhập ngay
            </a>
          </div>
        </form>
      </div>

      <!-- ======================================================== -->
      <!-- TAB 3: QUÊN MẬT KHẨU (FORGOT PASSWORD - 2 BƯỚC)           -->
      <!-- ======================================================== -->
      <div class="tab-pane <?= $activeTab === 'forgot' ? 'active' : '' ?>" id="pane-forgot">
        
        <!-- Step Progress Dots -->
        <div class="step-indicator">
          <div class="step-dot <?= $forgotStep === 1 ? 'active' : 'completed' ?>" id="dot-step-1">
            <?= $forgotStep > 1 ? '<i class="fa-solid fa-check"></i>' : '1' ?>
          </div>
          <div class="step-line <?= $forgotStep >= 2 ? 'active' : '' ?>"></div>
          <div class="step-dot <?= $forgotStep === 2 ? 'active' : '' ?>" id="dot-step-2">2</div>
        </div>

        <?php if ($forgotStep === 1): ?>
          <!-- STEP 1: NHẬP USERNAME VÀ EMAIL ĐỂ XÁC MINH CẤP OTP -->
          <div class="text-center mb-3">
            <h6 class="fw-700 text-secondary mb-1">Bước 1: Xác minh tài khoản & Nhận mã OTP</h6>
            <p class="text-muted small">Nhập chính xác Tên đăng nhập (hoặc Mã SV) và Email đã đăng ký.</p>
          </div>

          <form method="POST" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <input type="hidden" name="action" value="forgot_request" />

            <!-- Username / Mã SV -->
            <div class="mb-3">
              <label for="forgot_username" class="form-label">Tên đăng nhập / Mã SV</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                <input 
                  type="text" 
                  id="forgot_username" 
                  name="username" 
                  class="form-control" 
                  placeholder="Ví dụ: sinhvien hoặc 201200123" 
                  required 
                  autofocus
                />
              </div>
            </div>

            <!-- Email -->
            <div class="mb-4">
              <label for="forgot_email" class="form-label">Địa chỉ Email đã đăng ký</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                <input 
                  type="email" 
                  id="forgot_email" 
                  name="email" 
                  class="form-control" 
                  placeholder="sinhvien@student.utc.edu.vn" 
                  required 
                />
              </div>
            </div>

            <button type="submit" class="btn-auth-submit">
              <i class="fa-solid fa-paper-plane"></i>
              <span>Xác minh & Gửi mã OTP</span>
            </button>

            <div class="text-center mt-3">
              <a href="javascript:void(0)" onclick="switchAuthTab('login')" class="small text-muted text-decoration-none">
                <i class="fa-solid fa-arrow-left me-1"></i> Quay lại Đăng nhập
              </a>
            </div>
          </form>

        <?php else: ?>
          <!-- STEP 2: NHẬP OTP & ĐẶT LẠI MẬT KHẨU MỚI -->
          <div class="text-center mb-3">
            <h6 class="fw-700 text-secondary mb-1">Bước 2: Xác nhận OTP & Đặt lại mật khẩu</h6>
            <p class="text-muted small">Nhập mã OTP 6 số và thiết lập mật khẩu bảo mật mới.</p>
          </div>

          <form method="POST" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <input type="hidden" name="action" value="forgot_reset" />

            <!-- Username & Email (Pre-filled / Readonly) -->
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label mb-1">Tài khoản</label>
                <input 
                  type="text" 
                  name="username" 
                  class="form-control bg-light border text-muted small py-1-5 px-2" 
                  required 
                  readonly 
                />
              </div>
              <div class="col-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label mb-0">Email</label>
                  <a href="login.php?tab=forgot&step=1" class="small text-decoration-none" style="color:var(--auth-brand);font-size:0.7rem">Đổi lại</a>
                </div>
                <input 
                  type="email" 
                  name="email" 
                  class="form-control bg-light border text-muted small py-1-5 px-2" 
                  required 
                  readonly 
                />
              </div>
            </div>

            <!-- OTP Input -->
            <div class="mb-3">
              <label for="reset_otp" class="form-label">Mã xác thực OTP (6 chữ số)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-shield"></i></span>
                <input 
                  type="text" 
                  id="reset_otp" 
                  name="otp" 
                  class="form-control otp-input" 
                  placeholder="------" 
                  maxlength="6"
                  required 
                  autofocus
                  autocomplete="one-time-code"
                />
              </div>
            </div>

            <!-- New Password -->
            <div class="mb-3">
              <label for="new_password" class="form-label">Mật khẩu mới</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                <input 
                  type="password" 
                  id="new_password" 
                  name="new_password" 
                  class="form-control" 
                  placeholder="Tối thiểu 6 ký tự" 
                  required 
                  autocomplete="new-password"
                  oninput="checkNewPasswordStrength(this.value)"
                />
                <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility('new_password', this)" title="Ẩn/Hiện mật khẩu">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
              
              <!-- Strength Bars for Reset Password -->
              <div class="strength-meter-container">
                <div class="strength-bars">
                  <div class="strength-bar" id="new-str-bar-1"></div>
                  <div class="strength-bar" id="new-str-bar-2"></div>
                  <div class="strength-bar" id="new-str-bar-3"></div>
                  <div class="strength-bar" id="new-str-bar-4"></div>
                </div>
              </div>
            </div>

            <!-- Confirm New Password -->
            <div class="mb-4">
              <label for="new_password_confirm" class="form-label">Xác nhận mật khẩu mới</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-shield-check"></i></span>
                <input 
                  type="password" 
                  id="new_password_confirm" 
                  name="new_password_confirm" 
                  class="form-control" 
                  placeholder="Nhập lại mật khẩu mới" 
                  required 
                  autocomplete="new-password"
                  oninput="checkNewPasswordMatch()"
                />
                <button type="button" class="btn-toggle-pwd" onclick="togglePasswordVisibility('new_password_confirm', this)" title="Ẩn/Hiện mật khẩu">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
              <div id="new-match-feedback" class="match-feedback d-none"></div>
            </div>

            <button type="submit" class="btn-auth-submit">
              <i class="fa-solid fa-check-circle"></i>
              <span>Cập nhật mật khẩu mới</span>
            </button>

            <div class="text-center mt-3 d-flex justify-content-between">
              <a href="login.php?tab=forgot&step=1" class="small text-muted text-decoration-none">
                <i class="fa-solid fa-rotate-left me-1"></i> Yêu cầu lại mã OTP
              </a>
              <a href="javascript:void(0)" onclick="switchAuthTab('login')" class="small text-decoration-none fw-600" style="color:var(--auth-brand)">
                Đăng nhập ngay
              </a>
            </div>
          </form>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- JavaScript Framework & Interactive Logic -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    /**
     * Chuyển đổi tab hiển thị mượt mà
     */
    function switchAuthTab(tabName) {
      const validTabs = ['login', 'register', 'forgot'];
      if (!validTabs.includes(tabName)) tabName = 'login';

      validTabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const pane = document.getElementById('pane-' + t);
        if (btn) btn.classList.remove('active');
        if (pane) pane.classList.remove('active');
      });

      const activeBtn = document.getElementById('tab-btn-' + tabName);
      const activePane = document.getElementById('pane-' + tabName);
      if (activeBtn) activeBtn.classList.add('active');
      if (activePane) activePane.classList.add('active');

      if (history.pushState) {
        history.pushState(null, null, '#tab-' + tabName);
      } else {
        location.hash = '#tab-' + tabName;
      }
    }

    /**
     * Tự động nhận diện tab từ URL hash khi tải trang
     */
    window.addEventListener('DOMContentLoaded', () => {
      const hash = window.location.hash.replace('#tab-', '').replace('#', '');
      if (['login', 'register', 'forgot'].includes(hash)) {
        switchAuthTab(hash);
      }
    });

    /**
     * Ẩn / Hiện mật khẩu với nút con mắt
     */
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
          icon.classList.remove('fa-eye', 'fa-regular');
          icon.classList.add('fa-eye-slash', 'fa-solid');
        }
      } else {
        input.type = 'password';
        if (icon) {
          icon.classList.remove('fa-eye-slash', 'fa-solid');
          icon.classList.add('fa-eye', 'fa-regular');
        }
      }
    }

    /**
     * Điền nhanh thông tin tài khoản kiểm thử
     */
    function autofillLogin(user, pass) {
      const accountInput = document.getElementById('login_account');
      const passInput = document.getElementById('login_password');
      if (accountInput && passInput) {
        accountInput.value = user;
        passInput.value = pass;
        passInput.focus();
      }
    }

    /**
     * Tính toán và hiển thị thanh đo độ mạnh mật khẩu (Register)
     */
    function checkPasswordStrength(password) {
      let score = 0;
      if (!password) {
        updateStrengthUI(0, 'Chưa nhập', '#e2e8f0');
        return;
      }

      if (password.length >= 6) score += 1;
      if (password.length >= 10) score += 1;
      if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score += 1;
      if (/[0-9]/.test(password)) score += 1;
      if (/[^A-Za-z0-9]/.test(password)) score += 1;

      let level = 1;
      let label = 'Rất yếu';
      let color = '#ef4444'; // Đỏ

      if (score >= 4) {
        level = 4;
        label = 'Rất mạnh';
        color = '#10b981'; // Xanh lá
      } else if (score === 3) {
        level = 3;
        label = 'Tốt';
        color = '#14b8a6'; // Teal
      } else if (score === 2) {
        level = 2;
        label = 'Trung bình';
        color = '#f59e0b'; // Vàng cam
      } else {
        level = 1;
        label = 'Yếu';
        color = '#ef4444'; // Đỏ
      }

      updateStrengthUI(level, label, color);
      checkPasswordMatch();
    }

    function updateStrengthUI(level, label, color) {
      const bars = [
        document.getElementById('str-bar-1'),
        document.getElementById('str-bar-2'),
        document.getElementById('str-bar-3'),
        document.getElementById('str-bar-4')
      ];
      const labelEl = document.getElementById('strength-label');
      if (labelEl) {
        labelEl.textContent = label;
        labelEl.style.color = color;
      }

      bars.forEach((bar, index) => {
        if (!bar) return;
        if (index < level) {
          bar.style.backgroundColor = color;
        } else {
          bar.style.backgroundColor = '#e2e8f0';
        }
      });
    }

    /**
     * Kiểm tra trùng khớp mật khẩu (Register)
     */
    function checkPasswordMatch() {
      const pwd = document.getElementById('reg_password')?.value || '';
      const confirmPwd = document.getElementById('reg_password_confirm')?.value || '';
      const feedback = document.getElementById('match-feedback');
      if (!feedback) return;

      if (!confirmPwd) {
        feedback.classList.add('d-none');
        return;
      }

      feedback.classList.remove('d-none');
      if (pwd === confirmPwd) {
        feedback.innerHTML = '<i class="fa-solid fa-circle-check text-success"></i> <span class="text-success">Mật khẩu trùng khớp</span>';
      } else {
        feedback.innerHTML = '<i class="fa-solid fa-circle-xmark text-danger"></i> <span class="text-danger">Mật khẩu chưa khớp</span>';
      }
    }

    /**
     * Kiểm tra độ mạnh mật khẩu khi đặt lại (Forgot Step 2)
     */
    function checkNewPasswordStrength(password) {
      const bars = [
        document.getElementById('new-str-bar-1'),
        document.getElementById('new-str-bar-2'),
        document.getElementById('new-str-bar-3'),
        document.getElementById('new-str-bar-4')
      ];

      let score = 0;
      if (password.length >= 6) score += 1;
      if (password.length >= 10) score += 1;
      if (/[A-Za-z]/.test(password) && /[0-9]/.test(password)) score += 1;
      if (/[^A-Za-z0-9]/.test(password)) score += 1;

      let color = score >= 3 ? '#10b981' : (score === 2 ? '#f59e0b' : '#ef4444');

      bars.forEach((bar, i) => {
        if (!bar) return;
        bar.style.backgroundColor = i < score ? color : '#e2e8f0';
      });

      checkNewPasswordMatch();
    }

    function checkNewPasswordMatch() {
      const pwd = document.getElementById('new_password')?.value || '';
      const confirmPwd = document.getElementById('new_password_confirm')?.value || '';
      const feedback = document.getElementById('new-match-feedback');
      if (!feedback) return;

      if (!confirmPwd) {
        feedback.classList.add('d-none');
        return;
      }

      feedback.classList.remove('d-none');
      if (pwd === confirmPwd) {
        feedback.innerHTML = '<i class="fa-solid fa-circle-check text-success"></i> <span class="text-success">Mật khẩu mới trùng khớp</span>';
      } else {
        feedback.innerHTML = '<i class="fa-solid fa-circle-xmark text-danger"></i> <span class="text-danger">Mật khẩu mới chưa khớp</span>';
      }
    }
  </script>
</body>
</html>
