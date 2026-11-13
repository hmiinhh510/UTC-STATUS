<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class AuthAndRbacTest extends TestCase {
    protected bool $useTransaction = false; // Thuần logic, không cần DB transaction

    public function testAdminRoleHasFullPermissions(): void {
        $actions = [
            'create_service',
            'update_service',
            'delete_service',
            'create_group',
            'delete_group',
            'create_user',
            'delete_user',
            'change_user_status',
            'quick_status',
            'create_incident',
            'add_incident_update',
            'resolve_incident',
            'create_maintenance',
            'update_maintenance_status',
            'run_health_check',
            'update_fifo_incident',
            'update_parking_zone',
            'quick_parking_status',
            'any_custom_action'
        ];

        foreach ($actions as $action) {
            $this->assertTrue(
                canPerform($action, ROLE_ADMIN),
                "Admin phải có quyền thực hiện hành động: {$action}"
            );
        }
    }

    public function testEditorRoleHasOperationalPermissions(): void {
        $allowedActions = [
            'quick_status',
            'create_incident',
            'add_incident_update',
            'resolve_incident',
            'create_maintenance',
            'update_maintenance_status',
            'run_health_check',
            'update_fifo_incident',
            'update_parking_zone',
            'quick_parking_status'
        ];

        foreach ($allowedActions as $action) {
            $this->assertTrue(
                canPerform($action, ROLE_EDITOR),
                "Editor phải được phép thực hiện: {$action}"
            );
        }
    }

    public function testEditorRoleIsBlockedFromSensitiveActions(): void {
        $forbiddenActions = [
            'delete_service',
            'delete_group',
            'delete_maintenance',
            'create_user',
            'delete_user',
            'change_user_status',
            'assign_role',
            'drop_table',
            'reset_database'
        ];

        foreach ($forbiddenActions as $action) {
            $this->assertFalse(
                canPerform($action, ROLE_EDITOR),
                "Editor KHÔNG được phép thực hiện hành động nhạy cảm: {$action}"
            );
        }
    }

    public function testStudentRoleIsBlockedFromAllAdministrativeActions(): void {
        $actions = [
            'quick_status',
            'create_service',
            'delete_service',
            'create_incident',
            'resolve_incident',
            'create_maintenance',
            'update_maintenance_status',
            'update_parking_zone',
            'update_fifo_incident',
            'run_health_check'
        ];

        foreach ($actions as $action) {
            $this->assertFalse(
                canPerform($action, ROLE_STUDENT),
                "Sinh viên (student) KHÔNG được phép thực hiện hành động: {$action}"
            );
        }
    }

    public function testCsrfTokenValidation(): void {
        $secret = bin2hex(random_bytes(32));
        $validToken = $secret;
        $invalidToken = 'invalid_tampered_token_xyz';

        $this->assertTrue(hash_equals($secret, $validToken), 'Token hợp lệ phải pass hash_equals');
        $this->assertFalse(hash_equals($secret, $invalidToken), 'Token sai lệch phải bị từ chối');
        $this->assertFalse(hash_equals($secret, ''), 'Token rỗng phải bị từ chối');
    }

    public function testPasswordHashSecurity(): void {
        $rawPassword = 'SinhVienUTC@2026';
        $hashed = password_hash($rawPassword, PASSWORD_DEFAULT);

        $this->assertNotEquals($rawPassword, $hashed, 'Mật khẩu phải được băm bảo mật');
        $this->assertTrue(password_verify($rawPassword, $hashed), 'Mật khẩu đúng phải xác thực thành công');
        $this->assertFalse(password_verify('WrongPassword', $hashed), 'Mật khẩu sai phải bị từ chối');
    }
}
