<?php
/**
 * UTC STATUS – Phân Quyền Vai Trò & Hành Động (Role-Based Access Control)
 */

declare(strict_types=1);

const ROLE_ADMIN   = 'admin';
const ROLE_EDITOR  = 'editor';
const ROLE_STUDENT = 'student';

/**
 * Kiểm tra phân quyền thực hiện hành động:
 * - Admin: toàn quyền quản trị
 * - Editor: chỉ được đổi trạng thái nhanh, quản lý sự cố, lên lịch bảo trì, health check, hàng đợi FIFO, bãi xe.
 *           Không được xóa dịch vụ, xóa nhóm, quản lý user.
 * - Student: chỉ được xem thông tin, gửi báo cáo sự cố và đổi mật khẩu.
 */
function canPerform(string $action, string $role): bool {
    if ($role === ROLE_ADMIN) {
        return true;
    }

    if ($role === ROLE_EDITOR) {
        $editorAllowedActions = [
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
        return in_array($action, $editorAllowedActions, true);
    }

    // Role 'student' (Sinh viên / Người xem) chỉ có quyền tra cứu thông tin
    return false;
}
