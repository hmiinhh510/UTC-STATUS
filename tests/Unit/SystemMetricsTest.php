<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class SystemMetricsTest extends TestCase {
    protected bool $useTransaction = true;

    public function testMetricsStructureAndInvariants(): void {
        $m = getSystemMetrics($this->pdo);

        $requiredKeys = [
            'all_services',
            'total_services',
            'operational_count',
            'outage_count',
            'degraded_count',
            'troubled_services_count',
            'maintenance_services_count',
            'overall_status',
            'overall_message',
            'overall_color',
            'avg_uptime_90d',
            'active_incidents',
            'standalone_troubled_services',
            'effective_active_incidents',
            'active_maintenances',
            'standalone_maint_services',
            'effective_maintenances_count',
            'scheduled_maint_count',
            'in_progress_maint_count'
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $m, "System metrics phải chứa trường: {$key}");
        }

        // Bất biến tổng số: Total services = Operational + Outage + Degraded + Maintenance
        $sum = $m['operational_count'] + $m['outage_count'] + $m['degraded_count'] + $m['maintenance_services_count'];
        $this->assertEquals(
            $m['total_services'],
            $sum,
            'Tổng số dịch vụ phải bằng tổng số lượng từng trạng thái cộng lại'
        );

        $this->assertEquals(
            $m['outage_count'] + $m['degraded_count'],
            $m['troubled_services_count'],
            'Số dịch vụ lỗi phải bằng outage_count + degraded_count'
        );

        $this->assertGreaterThanOrEqual(0, $m['effective_active_incidents']);
        $this->assertGreaterThanOrEqual(0, $m['effective_maintenances_count']);
        $this->assertGreaterThanOrEqual(0.0, $m['avg_uptime_90d']);
        $this->assertLessThanOrEqual(100.0, $m['avg_uptime_90d']);
    }

    public function testMetricsReflectsNewOutageAndMaintenance(): void {
        $initial = getSystemMetrics($this->pdo);

        // Tạo 1 dịch vụ mới đang gặp sự cố nghiêm trọng
        $this->pdo->exec(
            'INSERT INTO services (group_id, name, description, icon, status, sort_order, created_at, updated_at)
             VALUES (1, "Svc New Outage", "Desc", "fa-bolt", "major_outage", 991, NOW(), NOW())'
        );

        // Tạo 1 dịch vụ mới đang bảo trì
        $this->pdo->exec(
            'INSERT INTO services (group_id, name, description, icon, status, sort_order, created_at, updated_at)
             VALUES (1, "Svc New Maint", "Desc", "fa-screwdriver", "maintenance", 992, NOW(), NOW())'
        );

        $updated = getSystemMetrics($this->pdo);

        $this->assertEquals($initial['total_services'] + 2, $updated['total_services']);
        $this->assertEquals($initial['outage_count'] + 1, $updated['outage_count']);
        $this->assertEquals($initial['maintenance_services_count'] + 1, $updated['maintenance_services_count']);
        $this->assertEquals($initial['effective_active_incidents'] + 1, $updated['effective_active_incidents']);
        $this->assertEquals($initial['effective_maintenances_count'] + 1, $updated['effective_maintenances_count']);
    }
}

