<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class MailerAndSubscriptionTest extends TestCase {
    protected bool $useTransaction = true;

    public function testEmailValidationLogic(): void {
        $validEmails = [
            'sinhvien@student.utc.edu.vn',
            'ngoc@gmail.com',
            'admin@utc.edu.vn',
            'user.test+tag@domain.com'
        ];

        foreach ($validEmails as $email) {
            $this->assertNotEquals(
                false,
                filter_var($email, FILTER_VALIDATE_EMAIL),
                "Email '{$email}' phải hợp lệ"
            );
        }

        $invalidEmails = [
            'invalid-email',
            'user@',
            '@domain.com',
            'user@domain..com',
            'user space@domain.com'
        ];

        foreach ($invalidEmails as $email) {
            $this->assertEquals(
                false,
                filter_var($email, FILTER_VALIDATE_EMAIL),
                "Email '{$email}' phải không hợp lệ"
            );
        }
    }

    public function testSubscriberActivationEmailValidation(): void {
        require_once __DIR__ . '/../../config/mailer.php';

        // Email không hợp lệ phải trả về success = false
        $res = sendSubscriberActivationEmail('email_sai_dinh_dang', 'token123');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('không hợp lệ', $res['message']);

        // Email hợp lệ (chạy ở môi trường local giả lập ghi log hoặc mail)
        $resValid = sendSubscriberActivationEmail('student@student.utc.edu.vn', 'sample_token_xyz', 'http://localhost/UTC-STATUS');
        $this->assertArrayHasKey('success', $resValid);
        $this->assertArrayHasKey('message', $resValid);
    }

    public function testSubscriptionTokensInDatabase(): void {
        $token = bin2hex(random_bytes(32));
        $email = 'unit_test_sub_' . time() . '@student.utc.edu.vn';

        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (type, endpoint, token, verified, created_at)
             VALUES ("email", :endpoint, :token, 1, NOW())'
        );
        $stmt->execute([':endpoint' => $email, ':token' => $token]);
        $subId = (int) $this->pdo->lastInsertId();

        $this->assertGreaterThanOrEqual(1, $subId);

        // Tra cứu lại qua token
        $fetchStmt = $this->pdo->prepare('SELECT id, endpoint, token, verified FROM subscriptions WHERE token = :token');
        $fetchStmt->execute([':token' => $token]);
        $sub = $fetchStmt->fetch();

        $this->assertNotNull($sub);
        $this->assertEquals($email, $sub['endpoint']);
        $this->assertEquals(1, (int)$sub['verified']);
    }
}
