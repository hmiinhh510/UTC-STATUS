<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AiChatApiTest extends TestCase {
    protected bool $useTransaction = false;

    public function testAiChatGetEndpointReturnsReadyStatus(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [], [], 'GET');

        $this->assertTrue($res['is_json'], 'GET api/ai_chat.php phải trả về JSON');
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('providers', $json);
        $this->assertArrayHasKey('default_suggestions', $json);
        $this->assertGreaterThanOrEqual(1, count($json['default_suggestions']));
    }

    public function testAiChatRejectsEmptyMessage(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], ['message' => ''], [], 'POST');

        $this->assertTrue($res['is_json']);
        $this->assertFalse($res['json']['success'] ?? true);
        $this->assertEquals(400, $res['http_code']);
        $this->assertStringContainsString('nhập nội dung', $res['json']['error'] ?? '');
    }

    public function testAiChatAnswersCourseRegistrationGuide(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Cổng đăng ký tín chỉ học phần UTH thế nào?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('message', $json);
        $this->assertArrayHasKey('suggestions', $json);

        $reply = $json['message'];
        $this->assertStringContainsString('CỔNG ĐĂNG KÝ HỌC PHẦN', $reply);
        $this->assertStringContainsString('tinchi.uth.edu.vn', $reply);
    }

    public function testAiChatAnswersLmsMoodleGuide(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Cách nộp bài tập trên hệ thống LMS Moodle'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString('LMS (MOODLE)', $json['message']);
        $this->assertStringContainsString('nộp bài', $json['message']);
    }

    public function testAiChatAnswersWifiEduroamGuide(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Hướng dẫn tôi cách đăng nhập wifi eduroam với'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $reply = $json['message'];
        $this->assertStringContainsString('eduroam', $reply);
        $this->assertStringContainsString('PEAP', $reply);
        $this->assertStringContainsString('MSCHAPV2', $reply);
    }

    public function testAiChatAnswersSystemStatusAndIncidents(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Hôm nay hệ thống có bị lỗi hay sự cố gì không?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString('SỰ CỐ', $json['message']);
    }

    public function testAiChatReturnsActionTriggersForIncidents(): void {
        // Test sự cố có action openIncidentModal
        $resInc = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Hệ thống đang gặp lỗi sự cố gì?'
        ], [], 'POST');
        $this->assertTrue($resInc['is_json']);
        $jsonInc = $resInc['json'];
        $this->assertTrue($jsonInc['success'] ?? false);
        $this->assertArrayHasKey('actions', $jsonInc);
        $actionsInc = array_column($jsonInc['actions'], 'action');
        $this->assertTrue(in_array('openIncidentModal', $actionsInc, true), 'Phải có action openIncidentModal');
    }

    public function testAiChatAnswersSlaMetrics(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Độ khả dụng SLA và uptime 90 ngày của trường thế nào?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];
        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString('99.88%', $json['message']);
        $this->assertStringContainsString('SLA', $json['message']);
    }

    public function testAiChatAnswersHotlineAndContacts(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Số điện thoại hotline hỗ trợ kỹ thuật IT của UTH là gì?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];
        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString('it@uth.edu.vn', $json['message']);
        $this->assertStringContainsString('Trung tâm CNTT', $json['message']);
    }
}
