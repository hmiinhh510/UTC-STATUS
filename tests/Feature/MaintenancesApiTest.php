<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MaintenancesApiTest extends TestCase {
    protected bool $useTransaction = false;

    public function testGetMaintenancesStructure(): void {
        $session = [
            'admin_id'   => 8,
            'admin_role' => 'student'
        ];

        $res = $this->callEndpoint('api/maintenances.php', ['tab' => 'all'], [], $session, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('upcoming', $json);
        $this->assertArrayHasKey('past', $json);
        $this->assertArrayHasKey('counts', $json);

        $counts = $json['counts'];
        $this->assertArrayHasKey('total', $counts);
        $this->assertArrayHasKey('upcoming', $counts);
        $this->assertArrayHasKey('in_progress', $counts);
        $this->assertArrayHasKey('scheduled', $counts);
        $this->assertArrayHasKey('past', $counts);

        $this->assertEquals(
            count($json['upcoming']),
            $counts['upcoming'],
            'Số lượng mảng upcoming phải khớp counts.upcoming'
        );
    }

    public function testGetUpcomingMaintenancesTab(): void {
        $session = [
            'admin_id'   => 1,
            'admin_role' => 'admin'
        ];

        $res = $this->callEndpoint('api/maintenances.php', ['tab' => 'upcoming'], [], $session, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        foreach ($json['data'] as $maint) {
            $this->assertTrue(
                in_array($maint['status'], ['in_progress', 'scheduled'], true),
                'Tab upcoming chỉ được chứa trạng thái in_progress hoặc scheduled'
            );
        }
    }

    public function testGetPastMaintenancesTab(): void {
        $session = [
            'admin_id'   => 1,
            'admin_role' => 'admin'
        ];

        $res = $this->callEndpoint('api/maintenances.php', ['tab' => 'past'], [], $session, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        foreach ($json['data'] as $maint) {
            $this->assertEquals(
                'completed',
                $maint['status'],
                'Tab past chỉ được chứa trạng thái completed'
            );
        }
    }
}
