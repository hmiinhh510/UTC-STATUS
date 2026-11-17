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

    public function testAiChatAnswersParkingQuestionsWithLiveZones(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Tình trạng bãi xe sân trường lúc này thế nào?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('message', $json);
        $this->assertArrayHasKey('suggestions', $json);

        $reply = $json['message'];
        $this->assertStringContainsString('BÃI XE SÂN TRƯỜNG', $reply);
        $this->assertStringContainsString('Khu A', $reply);
        $this->assertStringContainsString('Khu B', $reply);
    }

    public function testAiChatAnswersSpecificZoneQuestion(): void {
        $res = $this->callEndpoint('api/ai_chat.php', [], [
            'message' => 'Bãi xe khu A hiện tại còn chỗ không bot?'
        ], [], 'POST');

        $this->assertTrue($res['is_json']);
        $json = $res['json'];

        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString('Khu A', $json['message']);
        $this->assertStringContainsString('chỗ', $json['message']);
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
}
