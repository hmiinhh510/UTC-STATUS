<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class FifoIncidentQueueTest extends TestCase {
    protected bool $useTransaction = false;

    public function testStudentCanSubmitIncidentReport(): void {
        $studentSession = [
            'admin_id'   => 8,
            'admin_name' => 'Nguyễn Minh Ngọc',
            'admin_role' => 'student'
        ];

        // 1. Dữ liệu thiếu trường bắt buộc
        $resInvalid = $this->callEndpoint('api/report_incident.php', [], [
            'reporter_name' => '',
            'title'         => 'Test thiếu trường'
        ], $studentSession, 'POST');

        $this->assertTrue($resInvalid['is_json']);
        $this->assertFalse($resInvalid['json']['success'] ?? true);
        $this->assertStringContainsString('Vui lòng điền đầy đủ', $resInvalid['json']['error'] ?? '');

        // 2. Dữ liệu hợp lệ
        $validData = [
            'reporter_name'  => 'Nguyễn Minh Ngọc',
            'student_code'   => 'SV20261109',
            'reporter_email' => 'ngoc.test@student.utc.edu.vn',
            'location'       => 'Phòng máy C3-201',
            'title'          => 'Lỗi không đăng nhập được mạng Wi-Fi eduroam tầng 2',
            'description'    => 'Sinh viên nhập đúng tài khoản nhưng trang đăng nhập báo timeout.',
            'service_id'     => 6, // Wifi eduroam
            'severity'       => 'high'
        ];

        $resValid = $this->callEndpoint('api/report_incident.php', [], $validData, $studentSession, 'POST');
        $this->assertTrue($resValid['is_json']);
        $this->assertTrue($resValid['json']['success'] ?? false);
        $this->assertGreaterThanOrEqual(1, (int)($resValid['json']['report_id'] ?? 0));
    }

    public function testFifoQueueOrderingAndPriorityRanking(): void {
        $adminSession = [
            'admin_id'   => 1,
            'admin_role' => 'admin',
            'admin_name' => 'Admin'
        ];

        $res = $this->callEndpoint('api/incident_queue.php', [], [], $adminSession, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('incidents', $json);

        // Kiểm tra cơ chế FIFO: Nếu có nhiều báo cáo chưa xử lý, báo cáo gửi trước xếp trên
        $items = $json['incidents'];
        $unresolvedItems = array_values(array_filter($items, fn($it) => $it['status'] !== 'resolved' && $it['status'] !== 'rejected'));

        if (count($unresolvedItems) >= 2) {
            $firstTime = strtotime($unresolvedItems[0]['created_at']);
            $secondTime = strtotime($unresolvedItems[1]['created_at']);

            $this->assertLessThanOrEqual(
                $secondTime,
                $firstTime,
                'Cơ chế FIFO: Báo cáo gửi trước (created_at sớm hơn) phải xếp trên (#1 < #2)'
            );

            $this->assertEquals('#1', $unresolvedItems[0]['fifo_rank']);
            $this->assertEquals('#2', $unresolvedItems[1]['fifo_rank']);
        }
    }

    public function testAdminCanUpdateFifoIncidentStatus(): void {
        $adminSession = [
            'admin_id'   => 1,
            'admin_role' => 'admin',
            'admin_name' => 'Admin'
        ];

        // Lấy 1 report ID bất kỳ
        $listRes = $this->callEndpoint('api/incident_queue.php', [], [], $adminSession, 'GET');
        $this->assertTrue($listRes['is_json']);
        $items = $listRes['json']['incidents'] ?? [];

        if (!empty($items)) {
            $targetReportId = $items[0]['id'];

            // Cập nhật trạng thái
            $updateRes = $this->callEndpoint('api/incident_queue.php', [], [
                'report_id'  => $targetReportId,
                'status'     => 'resolved',
                'admin_note' => 'Kiểm thử giải quyết sự cố FIFO tự động.'
            ], $adminSession, 'POST');

            $this->assertTrue($updateRes['is_json']);
            $this->assertTrue($updateRes['json']['success'] ?? false);
        }
    }
}
