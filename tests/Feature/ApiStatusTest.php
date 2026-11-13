<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase {
    protected bool $useTransaction = false;

    public function testStatusApiRejectsUnauthenticatedRequests(): void {
        $res = $this->callEndpoint('api/status.php', [], [], []);
        $this->assertTrue($res['is_json'], 'Response phải là định dạng JSON');
        $this->assertEquals(401, $res['json']['code'] ?? 0, 'Phải trả về HTTP 401 Unauthorized');
        $this->assertStringContainsString('Truy cập bị từ chối', $res['json']['message'] ?? '');
    }

    public function testStatusApiSucceedsWithStudentSession(): void {
        $session = [
            'admin_id'       => 8,
            'admin_name'     => 'Nguyễn Minh Ngọc',
            'admin_username' => '098765',
            'admin_role'     => 'student'
        ];

        $res = $this->callEndpoint('api/status.php', [], [], $session);
        $this->assertTrue($res['is_json'], 'Response phải là định dạng JSON');
        $this->assertEquals(0, $res['exit_code']);

        $json = $res['json'];
        $this->assertArrayHasKey('overall', $json);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('services', $json);
        $this->assertArrayHasKey('service_groups', $json);
        $this->assertArrayHasKey('active_incidents', $json);
        $this->assertArrayHasKey('upcoming_maintenances', $json);
        $this->assertArrayHasKey('calendar_events', $json);

        // Kiểm tra tính nhất quán số lượng
        $this->assertEquals(
            count($json['active_incidents']),
            $json['summary']['active_incidents_count'],
            'Số lượng trong mảng active_incidents phải khớp active_incidents_count'
        );

        $this->assertEquals(
            count($json['upcoming_maintenances']),
            $json['summary']['upcoming_maintenances_count'],
            'Số lượng trong mảng upcoming_maintenances phải khớp upcoming_maintenances_count'
        );
    }

    public function testStatusApiSucceedsWithAdminSession(): void {
        $session = [
            'admin_id'       => 1,
            'admin_name'     => 'Quản trị viên UTC',
            'admin_username' => 'admin',
            'admin_role'     => 'admin'
        ];

        $res = $this->callEndpoint('api/status.php', [], [], $session);
        $this->assertTrue($res['is_json']);
        $this->assertEquals(0, $res['exit_code']);
        $this->assertGreaterThanOrEqual(1, $res['json']['summary']['total_services']);
    }
}

