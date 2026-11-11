<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class IncidentsApiTest extends TestCase {
    protected bool $useTransaction = false;

    public function testGetIncidentsListStructure(): void {
        $session = [
            'admin_id'   => 8,
            'admin_role' => 'student'
        ];

        $res = $this->callEndpoint('api/incidents.php', ['per_page' => 10], [], $session, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('pagination', $json);
        $this->assertGreaterThanOrEqual(1, count($json['data']));

        $first = $json['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('severity', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayHasKey('affected_services', $first);
        $this->assertArrayHasKey('updates', $first);
    }

    public function testFilterIncidentsByStatus(): void {
        $session = [
            'admin_id'   => 1,
            'admin_role' => 'admin'
        ];

        $res = $this->callEndpoint('api/incidents.php', ['status' => 'resolved'], [], $session, 'GET');
        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertArrayHasKey('data', $json);
        foreach ($json['data'] as $inc) {
            $this->assertEquals(
                'resolved',
                $inc['status'],
                "Khi lọc status=resolved, mọi sự cố trả về phải có trạng thái 'resolved'"
            );
        }
    }
}
