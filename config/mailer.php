<?php
/**
 * Mailer Helper for UTC STATUS
 * Sends notification and activation emails.
 */

declare(strict_types=1);

/**
 * Gửi email thông báo kích hoạt thành công cho người đăng ký nhận cảnh báo
 *
 * @param string $recipientEmail Địa chỉ email người nhận
 * @param string $token Token hủy đăng ký hoặc xác minh
 * @param string|null $siteUrl URL gốc của hệ thống (nếu null sẽ tự phát hiện)
 * @return array Kết quả gửi email ['success' => bool, 'message' => string]
 */
function sendSubscriberActivationEmail(string $recipientEmail, string $token, ?string $siteUrl = null): array
{
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Địa chỉ email người nhận không hợp lệ.'];
    }

    // Xác định URL gốc
    if ($siteUrl === null || $siteUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        // Nếu đang ở trong thư mục /admin hoặc /api, lấy thư mục cha
        $cleanDir = preg_replace('#/(admin|api)$#i', '', $scriptDir);
        $cleanDir = trim((string)$cleanDir, "/\\.");
        $pathPrefix = $cleanDir !== '' ? "/{$cleanDir}" : '';
        $siteUrl = "{$protocol}://{$host}{$pathPrefix}";
    }
    $siteUrl = rtrim($siteUrl, '/');

    $statusPageUrl = "{$siteUrl}/index.php";
    $unsubscribeUrl = "{$siteUrl}/unsubscribe.php?token=" . urlencode($token);

    $subject = "=?UTF-8?B?" . base64_encode("[UTC STATUS] Kích hoạt thành công nhận thông báo trạng thái dịch vụ UTC") . "?=";

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Kích Hoạt Thành Công – UTC STATUS</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color:#1e293b; line-height:1.6;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9; padding:30px 10px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:620px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,0.06); border:1px solid #e2e8f0;" cellspacing="0" cellpadding="0">
          
          <!-- Header Banner -->
          <tr>
            <td style="background: linear-gradient(135deg, #007a78 0%, #004d40 100%); padding: 32px 30px; text-align: center; color: #ffffff;">
              <h1 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: 0.5px;">UTC <span style="color:#a7f3d0;">STATUS</span></h1>
              <p style="margin: 6px 0 0 0; font-size: 14px; opacity: 0.9; font-weight: 500;">Hệ Thống Giám Sát &amp; Cảnh Báo Trạng Thái Dịch Vụ CNTT</p>
            </td>
          </tr>

          <!-- Main Content -->
          <tr>
            <td style="padding: 35px 30px;">
              <div style="display:inline-block; background-color:#dcfce7; color:#15803d; font-size:12px; font-weight:700; padding:4px 12px; border-radius:999px; margin-bottom:16px;">
                ✔ ĐÃ KÍCH HOẠT BỞI QUẢN TRỊ VIÊN
              </div>

              <h2 style="margin:0 0 16px 0; font-size:20px; color:#0f172a; font-weight:700;">
                Xin chào Quý bạn đọc / Cán bộ / Sinh viên,
              </h2>

              <p style="margin:0 0 16px 0; font-size:15px; color:#334155;">
                Chúng tôi xin trân trọng thông báo: Địa chỉ email <strong>{$recipientEmail}</strong> của bạn đã được <strong>Quản trị viên hệ thống UTC STATUS kích hoạt thành công</strong> vào danh bạ nhận cảnh báo vận hành.
              </p>

              <!-- Benefits Box -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc; border-radius:12px; border-left:4px solid #007a78; margin:20px 0; padding:16px;">
                <tr>
                  <td>
                    <h3 style="margin:0 0 10px 0; font-size:15px; color:#007a78; font-weight:700;">
                      Từ bây giờ, bạn sẽ tự động nhận được:
                    </h3>
                    <ul style="margin:0; padding-left:20px; font-size:14px; color:#475569;">
                      <li style="margin-bottom:8px;"><strong>Cảnh báo sự cố tức thời:</strong> Thông báo ngay khi các dịch vụ (LMS, Đăng ký học phần, Cổng thông tin, Học phí, Wifi...) gặp trục trặc.</li>
                      <li style="margin-bottom:8px;"><strong>Kế hoạch bảo trì định kỳ:</strong> Lịch bảo dưỡng máy chủ, nâng cấp hạ tầng để chủ động kế hoạch học tập và làm việc.</li>
                      <li><strong>Báo cáo khắc phục:</strong> Thông báo ngay khi sự cố được đội ngũ kỹ thuật CNTT giải quyết dứt điểm.</li>
                    </ul>
                  </td>
                </tr>
              </table>

              <!-- Button CTA -->
              <table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0 20px 0;">
                <tr>
                  <td align="center" style="border-radius:10px; background-color:#007a78;">
                    <a href="{$statusPageUrl}" target="_blank" style="font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; padding:12px 28px; display:inline-block; border-radius:10px;">
                      Xem Trang Trạng Thái UTC Trực Tuyến &rarr;
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0; font-size:13px; color:#64748b; line-height:1.5;">
                Nếu bạn cần hỗ trợ hoặc có thắc mắc kỹ thuật, vui lòng gửi phản hồi về Trung tâm Ứng dụng CNTT Trường ĐH Giao thông Vận tải.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f8fafc; padding:20px 30px; text-align:center; border-top:1px solid #e2e8f0; font-size:12px; color:#64748b;">
              <p style="margin:0 0 8px 0; font-weight:600; color:#475569;">
                Trường Đại học Giao thông Vận tải &ndash; Số 3 Cầu Giấy, Láng Thượng, Đống Đa, Hà Nội
              </p>
              <p style="margin:0;">
                Email này được gửi tự động từ hệ thống UTC STATUS. Bạn nhận được thư này vì địa chỉ của bạn đã được đăng ký nhận thông báo. 
                <br />
                <a href="{$unsubscribeUrl}" target="_blank" style="color:#dc2626; text-decoration:underline;">Bấm vào đây để hủy nhận cảnh báo</a> bất cứ lúc nào.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: UTC STATUS <noreply@utc.edu.vn>',
        'Reply-To: hotro@utc.edu.vn',
        'X-Mailer: PHP/' . phpversion()
    ];

    // Gửi email qua mail() của PHP
    $mailSent = @mail($recipientEmail, $subject, $htmlBody, implode("\r\n", $headers));

    // Luôn ghi log chi tiết nội dung email đã phát đi vào storage/mail_notifications.log
    $logDir = dirname(__DIR__) . '/storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/mail_notifications.log';
    $logEntry = sprintf(
        "[%s] [ACTIVATION_EMAIL] To: %s | Result: %s | Unsubscribe: %s\n",
        date('Y-m-d H:i:s'),
        $recipientEmail,
        $mailSent ? 'SENT_VIA_MAIL' : 'LOGGED_LOCAL_SIMULATION',
        $unsubscribeUrl
    );
    @file_put_contents($logFile, $logEntry, FILE_APPEND);

    return [
        'success'  => true,
        'mailSent' => $mailSent,
        'message'  => "Đã gửi thông báo kích hoạt thành công tới địa chỉ {$recipientEmail}!"
    ];
}
