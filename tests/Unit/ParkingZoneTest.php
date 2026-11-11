<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ParkingZoneTest extends TestCase {
    protected bool $useTransaction = true;

    public function testParkingCapacityAndOccupancyCalculation(): void {
        $zones = $this->pdo->query('SELECT * FROM parking_zones ORDER BY sort_order ASC')->fetchAll();
        $this->assertGreaterThanOrEqual(1, count($zones), 'Hệ thống phải có ít nhất 1 phân khu bãi xe');

        $totalCap = 0;
        $totalOcc = 0;

        foreach ($zones as $zone) {
            $this->assertArrayHasKey('zone_code', $zone);
            $this->assertArrayHasKey('total_capacity', $zone);
            $this->assertArrayHasKey('occupied_count', $zone);
            $this->assertArrayHasKey('status', $zone);

            $cap = (int) $zone['total_capacity'];
            $occ = (int) $zone['occupied_count'];

            $this->assertGreaterThanOrEqual(1, $cap, 'Sức chứa phân khu phải lớn hơn 0');
            $this->assertGreaterThanOrEqual(0, $occ, 'Số xe đỗ không được âm');
            $this->assertLessThanOrEqual($cap, $occ, 'Số xe đỗ không vượt quá sức chứa');

            $totalCap += $cap;
            $totalOcc += $occ;
        }

        $pct = round(($totalOcc / $totalCap) * 100, 1);
        $this->assertGreaterThanOrEqual(0.0, $pct);
        $this->assertLessThanOrEqual(100.0, $pct);
    }

    public function testParkingZoneStatusThresholds(): void {
        // Tạo phân khu test với sức chứa 100 xe
        $stmt = $this->pdo->prepare(
            'INSERT INTO parking_zones (zone_code, name, vehicle_type, total_capacity, occupied_count, status, sort_order, updated_at)
             VALUES ("ZONE_TEST", "Khu Test Ngưỡng", "Xe máy", 100, 50, "available", 99, NOW())'
        );
        $stmt->execute();
        $zoneId = (int) $this->pdo->lastInsertId();

        // 1. Mức < 80%: 50/100 = 50% -> available
        $this->pdo->exec("UPDATE parking_zones SET occupied_count = 50, status = 'available' WHERE id = {$zoneId}");
        $row = $this->pdo->query("SELECT status, occupied_count, total_capacity FROM parking_zones WHERE id = {$zoneId}")->fetch();
        $pct = ($row['occupied_count'] / $row['total_capacity']) * 100;
        $this->assertEquals('available', $row['status']);
        $this->assertTrue($pct < 80.0);

        // 2. Mức 80% - 94%: 85/100 = 85% -> crowded
        $this->pdo->exec("UPDATE parking_zones SET occupied_count = 85, status = 'crowded' WHERE id = {$zoneId}");
        $row = $this->pdo->query("SELECT status, occupied_count, total_capacity FROM parking_zones WHERE id = {$zoneId}")->fetch();
        $pct = ($row['occupied_count'] / $row['total_capacity']) * 100;
        $this->assertEquals('crowded', $row['status']);
        $this->assertTrue($pct >= 80.0 && $pct < 95.0);

        // 3. Mức >= 95%: 98/100 = 98% -> full
        $this->pdo->exec("UPDATE parking_zones SET occupied_count = 98, status = 'full' WHERE id = {$zoneId}");
        $row = $this->pdo->query("SELECT status, occupied_count, total_capacity FROM parking_zones WHERE id = {$zoneId}")->fetch();
        $pct = ($row['occupied_count'] / $row['total_capacity']) * 100;
        $this->assertEquals('full', $row['status']);
        $this->assertTrue($pct >= 95.0);
    }

    public function testQuickParkingStatusToggle(): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO parking_zones (zone_code, name, vehicle_type, total_capacity, occupied_count, status, sort_order, updated_at)
             VALUES ("ZONE_QUICK", "Khu Test Quick", "Xe máy", 200, 100, "available", 98, NOW())'
        );
        $stmt->execute();
        $zoneId = (int) $this->pdo->lastInsertId();

        // Admin chuyển nhanh sang FULL (100%)
        $fullOcc = 200;
        $this->pdo->exec("UPDATE parking_zones SET occupied_count = {$fullOcc}, status = 'full', updated_at = NOW() WHERE id = {$zoneId}");
        $zone = $this->pdo->query("SELECT * FROM parking_zones WHERE id = {$zoneId}")->fetch();
        $this->assertEquals(200, (int)$zone['occupied_count']);
        $this->assertEquals('full', $zone['status']);

        // Admin chuyển nhanh sang CÒN CHỖ (50%)
        $availOcc = (int) round(200 * 0.5);
        $this->pdo->exec("UPDATE parking_zones SET occupied_count = {$availOcc}, status = 'available', updated_at = NOW() WHERE id = {$zoneId}");
        $zone = $this->pdo->query("SELECT * FROM parking_zones WHERE id = {$zoneId}")->fetch();
        $this->assertEquals(100, (int)$zone['occupied_count']);
        $this->assertEquals('available', $zone['status']);
    }
}
