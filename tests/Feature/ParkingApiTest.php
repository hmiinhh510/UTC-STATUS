<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ParkingApiTest extends TestCase {
    protected bool $useTransaction = false;

    public function testGetParkingZonesReturnsValidStructure(): void {
        $res = $this->callEndpoint('api/parking.php', [], [], [], 'GET');

        $this->assertTrue($res['is_json'], 'API Parking phải trả về JSON');
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('zones', $json);
        $this->assertGreaterThanOrEqual(1, count($json['zones']));

        $firstZone = $json['zones'][0];
        $this->assertArrayHasKey('zone_code', $firstZone);
        $this->assertArrayHasKey('total_capacity', $firstZone);
        $this->assertArrayHasKey('occupied_count', $firstZone);
        $this->assertArrayHasKey('status', $firstZone);
        $this->assertArrayHasKey('occupancy_pct', $firstZone);
    }

    public function testStudentRoleCannotUpdateParking(): void {
        $studentSession = [
            'admin_id'   => 8,
            'admin_role' => 'student',
            'admin_name' => 'Sinh viên'
        ];

        $postData = [
            'zone_id' => 1,
            'action'  => 'quick_full'
        ];

        $res = $this->callEndpoint('api/parking.php', [], $postData, $studentSession, 'POST');
        $this->assertTrue($res['is_json']);
        $this->assertFalse($res['json']['success'] ?? true);
        $this->assertStringContainsString('không có quyền', $res['json']['error'] ?? '');
    }

    public function testAdminCanQuickToggleParkingStatus(): void {
        $adminSession = [
            'admin_id'   => 1,
            'admin_role' => 'admin',
            'admin_name' => 'Admin'
        ];

        // 1. Chuyển nhanh sang FULL
        $resFull = $this->callEndpoint('api/parking.php', [], [
            'zone_id' => 1,
            'action'  => 'quick_full'
        ], $adminSession, 'POST');

        $this->assertTrue($resFull['is_json']);
        $this->assertTrue($resFull['json']['success'] ?? false);
        $this->assertEquals('full', $resFull['json']['status']);
        $this->assertEquals($resFull['json']['total_capacity'], $resFull['json']['occupied_count']);

        // 2. Chuyển nhanh sang CÒN CHỖ (50%)
        $resAvail = $this->callEndpoint('api/parking.php', [], [
            'zone_id' => 1,
            'action'  => 'quick_available'
        ], $adminSession, 'POST');

        $this->assertTrue($resAvail['is_json']);
        $this->assertTrue($resAvail['json']['success'] ?? false);
        $this->assertEquals('available', $resAvail['json']['status']);
        $this->assertEquals(
            (int) round($resAvail['json']['total_capacity'] * 0.5),
            (int) $resAvail['json']['occupied_count']
        );
    }
}
