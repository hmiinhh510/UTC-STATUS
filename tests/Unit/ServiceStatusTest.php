<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ServiceStatusTest extends TestCase {
    protected bool $useTransaction = true; // DB Transaction rollback sau mỗi test

    public function testDirectServiceStatusUpdateAndHistoryLogging(): void {
        // 1. Tạo dịch vụ tạm thời trong transaction
        $stmt = $this->pdo->prepare(
            'INSERT INTO services (group_id, name, description, icon, status, sort_order, created_at, updated_at)
             VALUES (1, "Dịch vụ Test Tạm thời", "Mô tả test", "fa-flask", "operational", 999, NOW(), NOW())'
        );
        $stmt->execute();
        $testSvcId = (int) $this->pdo->lastInsertId();

        $this->assertGreaterThanOrEqual(1, $testSvcId, 'Phải tạo thành công dịch vụ test');

        // 2. Gọi reconcileServiceStatus ép đổi sang major_outage
        $res = reconcileServiceStatus(
            $this->pdo,
            $testSvcId,
            1, // adminId
            'Kiểm thử đổi trạng thái sự cố',
            'major_outage',
            true
        );

        $this->assertNotNull($res);
        $this->assertEquals('operational', $res['old_status']);
        $this->assertEquals('major_outage', $res['new_status']);

        // 3. Kiểm tra DB đã cập nhật
        $chkStmt = $this->pdo->prepare('SELECT status FROM services WHERE id = :id');
        $chkStmt->execute([':id' => $testSvcId]);
        $this->assertEquals('major_outage', $chkStmt->fetchColumn());

        // 4. Kiểm tra service_status_history đã ghi nhận
        $histStmt = $this->pdo->prepare(
            'SELECT old_status, new_status, reason FROM service_status_history WHERE service_id = :id ORDER BY id DESC LIMIT 1'
        );
        $histStmt->execute([':id' => $testSvcId]);
        $history = $histStmt->fetch();
        $this->assertNotNull($history);
        $this->assertEquals('operational', $history['old_status']);
        $this->assertEquals('major_outage', $history['new_status']);
        $this->assertStringContainsString('Kiểm thử đổi trạng thái sự cố', $history['reason']);
    }

    public function testReconcileServiceStatusIncidentHierarchy(): void {
        // Tạo dịch vụ test
        $stmt = $this->pdo->prepare(
            'INSERT INTO services (group_id, name, description, icon, status, sort_order, created_at, updated_at)
             VALUES (1, "Dịch vụ Test Hierarchy", "Test", "fa-microchip", "operational", 998, NOW(), NOW())'
        );
        $stmt->execute();
        $svcId = (int) $this->pdo->lastInsertId();

        // Tạo sự cố severity = critical
        $incStmt = $this->pdo->prepare(
            'INSERT INTO incidents (title, severity, status, started_at, created_by, created_at, updated_at)
             VALUES ("Sự cố nghiêm trọng test", "critical", "investigating", NOW(), 1, NOW(), NOW())'
        );
        $incStmt->execute();
        $incId = (int) $this->pdo->lastInsertId();

        $relStmt = $this->pdo->prepare('INSERT INTO incident_services (incident_id, service_id) VALUES (:inc_id, :svc_id)');
        $relStmt->execute([':inc_id' => $incId, ':svc_id' => $svcId]);

        // Reconcile không ép trạng thái -> phải nhận major_outage từ critical incident
        reconcileServiceStatus($this->pdo, $svcId, 1, 'Reconcile incident');

        $status = $this->pdo->query("SELECT status FROM services WHERE id = {$svcId}")->fetchColumn();
        $this->assertEquals('major_outage', $status, 'Severity critical phải đưa dịch vụ về major_outage');

        // Đóng sự cố -> Reconcile phải đưa dịch vụ trở về operational
        $this->pdo->exec("UPDATE incidents SET status = 'resolved' WHERE id = {$incId}");
        reconcileServiceStatus($this->pdo, $svcId, 1, 'Khôi phục sau đóng sự cố');

        $restoredStatus = $this->pdo->query("SELECT status FROM services WHERE id = {$svcId}")->fetchColumn();
        $this->assertEquals('operational', $restoredStatus, 'Sau khi đóng sự cố, dịch vụ phải trở về operational');
    }

    public function testReconcileServiceStatusMaintenance(): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO services (group_id, name, description, icon, status, sort_order, created_at, updated_at)
             VALUES (1, "Dịch vụ Test Maint", "Test", "fa-wrench", "operational", 997, NOW(), NOW())'
        );
        $stmt->execute();
        $svcId = (int) $this->pdo->lastInsertId();

        // Tạo lịch bảo trì đang diễn ra (in_progress)
        $maintStmt = $this->pdo->prepare(
            'INSERT INTO maintenances (title, description, status, scheduled_at, expected_end, created_by, created_at, updated_at)
             VALUES ("Kế hoạch bảo trì test", "Mô tả", "in_progress", NOW(), NOW(), 1, NOW(), NOW())'
        );
        $maintStmt->execute();
        $maintId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO maintenance_services (maintenance_id, service_id) VALUES ({$maintId}, {$svcId})");

        reconcileServiceStatus($this->pdo, $svcId, 1, 'Bảo trì đang diễn ra');

        $status = $this->pdo->query("SELECT status FROM services WHERE id = {$svcId}")->fetchColumn();
        $this->assertEquals('maintenance', $status, 'Khi có kế hoạch bảo trì in_progress, dịch vụ phải chuyển sang maintenance');
    }
}
