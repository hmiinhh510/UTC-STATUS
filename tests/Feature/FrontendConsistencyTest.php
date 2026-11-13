<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class FrontendConsistencyTest extends TestCase {
    protected bool $useTransaction = false;

    public function testIndexPhpRedirectsUnauthenticatedUser(): void {
        $res = $this->callEndpoint('index.php', [], [], []);
        // Không có session admin_id -> redirect login hoặc không render nội dung chính
        $headersText = implode(' | ', $res['headers']);
        $isRedirected = str_contains($headersText, 'Location: admin/login.php') || empty($res['stdout']);
        $this->assertTrue($isRedirected, 'Người dùng chưa đăng nhập phải bị chuyển hướng hoặc chặn hiển thị portal.');
        $this->assertFalse(str_contains($res['stdout'], 'Danh mục chức năng hệ thống'), 'Tuyệt đối không render nội dung trang khi chưa đăng nhập.');
    }

    public function testAdminPortalBlocksStudentRole(): void {
        $studentSession = [
            'admin_id'   => 8,
            'admin_name' => 'Nguyễn Minh Ngọc',
            'admin_role' => 'student'
        ];

        $res = $this->callEndpoint('admin/index.php', [], [], $studentSession);
        // Student vào admin/index.php -> redirect ../index.php hoặc không render dashboard admin
        $headersText = implode(' | ', $res['headers']);
        $isBlocked = str_contains($headersText, 'Location: ../index.php') || empty($res['stdout']);
        $this->assertTrue($isBlocked, 'Sinh viên không được phép truy cập Dashboard Quản trị.');
        $this->assertFalse(str_contains($res['stdout'], 'Bảng Điều Khiển Quản Trị'), 'Tuyệt đối không render trang admin cho sinh viên.');
    }

    public function testAdminFormsHaveMatchingPostHandlers(): void {
        $adminSource = file_get_contents(__DIR__ . '/../../admin/index.php');
        $this->assertTrue(is_string($adminSource), 'Không thể đọc trang quản trị để kiểm tra các action.');

        preg_match_all('/name="action"\s+value="([^"]+)"/', $adminSource, $formMatches);
        preg_match_all("/case\\s+'([^']+)'\\s*:/", $adminSource, $handlerMatches);

        $formActions = array_values(array_unique($formMatches[1]));
        $handlers = array_values(array_unique($handlerMatches[1]));
        $unhandledActions = array_values(array_diff($formActions, $handlers));

        $this->assertSame(
            [],
            $unhandledActions,
            'Mọi action của form admin phải có handler POST tương ứng.'
        );
    }

    public function testPreRenderedMetricsConsistencyAcrossAllThreeRoles(): void {
        $roles = [
            'admin'   => ['admin_id' => 1, 'admin_name' => 'Admin User', 'admin_role' => 'admin'],
            'editor'  => ['admin_id' => 2, 'admin_name' => 'Editor User', 'admin_role' => 'editor'],
            'student' => ['admin_id' => 8, 'admin_name' => 'Nguyễn Minh Ngọc', 'admin_role' => 'student'],
        ];

        $capturedData = [];

        foreach ($roles as $roleKey => $sessionData) {
            $res = $this->callEndpoint('index.php', [], [], $sessionData);
            $html = $res['stdout'];

            $this->assertStringContainsString('UTC STATUS', $html);
            $this->assertStringContainsString('metricIncidentsCount', $html);
            $this->assertStringContainsString('metricMaintenancesCount', $html);
            $this->assertStringContainsString('cardParkingAction', $html);

            preg_match('/id="metricIncidentsCount"[^>]*>([^<]+)<\/div>/', $html, $incMatch);
            preg_match('/id="metricMaintenancesCount"[^>]*>([^<]+)<\/div>/', $html, $maintMatch);
            preg_match('/id="statActiveIncidents"[^>]*>([^<]+)<\/strong>/', $html, $statIncMatch);

            $capturedData[$roleKey] = [
                'incidents'    => trim($incMatch[1] ?? ''),
                'maintenances' => trim($maintMatch[1] ?? ''),
                'statInc'      => trim($statIncMatch[1] ?? '')
            ];
        }

        // Kiểm tra tính nhất quán 100% giữa Admin, Editor và Student
        $this->assertEquals(
            $capturedData['admin']['incidents'],
            $capturedData['student']['incidents'],
            'Số sự cố hiển thị cho Admin và Student phải hoàn toàn giống nhau'
        );

        $this->assertEquals(
            $capturedData['admin']['maintenances'],
            $capturedData['student']['maintenances'],
            'Số bảo trì hiển thị cho Admin và Student phải hoàn toàn giống nhau'
        );

        $this->assertEquals(
            $capturedData['editor']['incidents'],
            $capturedData['student']['incidents'],
            'Số sự cố hiển thị cho Editor và Student phải hoàn toàn giống nhau'
        );

        $this->assertEquals(
            $capturedData['editor']['maintenances'],
            $capturedData['student']['maintenances'],
            'Số bảo trì hiển thị cho Editor và Student phải hoàn toàn giống nhau'
        );
    }
}
