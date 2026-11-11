<?php


declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=UTF-8');

// ── 1. Authentication Check ──────────────────────────────────
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Chặn tài khoản Sinh viên vào trang Quản trị:
if (($_SESSION['admin_role'] ?? '') === 'student') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/service_status.php';
require_once __DIR__ . '/../api/health_check_runner.php';

$pdo = Database::getInstance();
$pdo->exec("SET NAMES utf8mb4");

$adminId   = (int) $_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'editor'; // 'admin' or 'editor'

// ── 2. CSRF Token Setup ──────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── 3. Helper Functions & Permissions ────────────────────────
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ (CSRF Token mismatch). Vui lòng tải lại trang.');
        header('Location: index.php');
        exit;
    }
}

/**
 * Kiểm tra phân quyền:
 * - Admin: thực hiện mọi hành động
 * - Editor: chỉ được đổi trạng thái nhanh, quản lý sự cố, lên lịch bảo trì, chạy health check.
 *           Không được xóa dịch vụ, xóa nhóm, quản lý user.
 */
function canPerform(string $action, string $role): bool {
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'editor') {
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

/**
 * Ghi nhật ký hoạt động (Audit Log)
 */
function logAction(PDO $pdo, int $userId, string $username, string $action, string $targetType, ?int $targetId, string $details): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
             VALUES (:uid, :uname, :action, :ttype, :tid, :details, :ip, NOW())'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':uname'   => $username,
            ':action'  => $action,
            ':ttype'   => $targetType,
            ':tid'     => $targetId,
            ':details' => $details,
            ':ip'      => $ip
        ]);
    } catch (Throwable $e) {
        error_log('[AuditLog Error] ' . $e->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Phân quyền RBAC
    if (!canPerform($action, $adminRole)) {
        logAction($pdo, $adminId, $adminName, 'permission_denied', 'system', null, "Cố gắng thực hiện '{$action}' khi chỉ có quyền {$adminRole}");
        $roleMsg = $adminRole === 'student'
            ? 'Truy cập bị từ chối: Tài khoản Sinh viên chỉ có quyền tra cứu thông tin và lịch dịch vụ, không được thay đổi dữ liệu.'
            : 'Truy cập bị từ chối: Tài khoản của bạn mang vai trò Editor, không có quyền thực hiện thao tác này. Cần quyền Quản trị viên (Admin).';
        setFlash('danger', $roleMsg);
        header('Location: index.php');
        exit;
    }

    try {
        switch ($action) {
            // ── ĐỔI TRẠNG THÁI NHANH DỊCH VỤ ─────────────────
            case 'quick_status':
                $serviceId = (int) ($_POST['service_id'] ?? 0);
                $newStatus = trim($_POST['status'] ?? '');
                $validStatuses = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'];

                if ($serviceId > 0 && in_array($newStatus, $validStatuses, true)) {
                    $curStmt = $pdo->prepare('SELECT name, status FROM services WHERE id = :id');
                    $curStmt->execute([':id' => $serviceId]);
                    $svc = $curStmt->fetch();

                    if ($svc) {
                        $oldStatus = $svc['status'];
                        $statusChange = reconcileServiceStatus(
                            $pdo,
                            $serviceId,
                            $adminId,
                            'Quản trị viên đổi trạng thái trực tiếp trên Dashboard',
                            $newStatus
                        );
                        $effectiveStatus = $statusChange['new_status'] ?? $oldStatus;
                        logAction($pdo, $adminId, $adminName, 'quick_status', 'service', $serviceId, "Đổi trạng thái dịch vụ '{$svc['name']}' từ '{$oldStatus}' sang '{$effectiveStatus}'");

                        $message = $effectiveStatus === $newStatus
                            ? "Đã đổi trạng thái dịch vụ \"{$svc['name']}\" sang [{$effectiveStatus}] thành công!"
                            : "Trạng thái dịch vụ \"{$svc['name']}\" vẫn là [{$effectiveStatus}] do còn sự cố hoặc bảo trì đang hoạt động.";
                        setFlash('success', $message);
                    }
                } else {
                    setFlash('danger', 'Dữ liệu trạng thái không hợp lệ.');
                }
                break;

            // ── THÊM DỊCH VỤ MỚI ─────────────────────────────
            case 'create_service':
                $groupId     = (int) ($_POST['group_id'] ?? 0);
                $name        = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $icon        = trim($_POST['icon'] ?? 'fa-server');
                $status      = trim($_POST['status'] ?? 'operational');
                $validStatuses = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'];
                $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
                $checkUrl    = trim($_POST['check_url'] ?? '');

                if ($name === '' || $groupId <= 0 || !in_array($status, $validStatuses, true)) {
                    setFlash('danger', 'Tên, nhóm hoặc trạng thái dịch vụ không hợp lệ.');
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO services (group_id, name, description, icon, status, sort_order, check_url, created_at, updated_at)
                         VALUES (:group_id, :name, :description, :icon, :status, :sort_order, :check_url, NOW(), NOW())'
                    );
                    $stmt->execute([
                        ':group_id'    => $groupId,
                        ':name'        => $name,
                        ':description' => $description,
                        ':icon'        => $icon ?: 'fa-server',
                        ':status'      => $status,
                        ':sort_order'  => $sortOrder,
                        ':check_url'   => $checkUrl ?: null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    logAction($pdo, $adminId, $adminName, 'create_service', 'service', $newId, "Tạo mới dịch vụ: {$name}");
                    setFlash('success', "Đã thêm dịch vụ \"{$name}\" thành công!");
                }
                break;

            // ── CẬP NHẬT DỊCH VỤ ─────────────────────────────
            case 'update_service':
                $serviceId   = (int) ($_POST['service_id'] ?? 0);
                $groupId     = (int) ($_POST['group_id'] ?? 0);
                $name        = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $icon        = trim($_POST['icon'] ?? 'fa-server');
                $status      = trim($_POST['status'] ?? 'operational');
                $validStatuses = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'];
                $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
                $checkUrl    = trim($_POST['check_url'] ?? '');

                if ($serviceId <= 0 || $name === '' || $groupId <= 0 || !in_array($status, $validStatuses, true)) {
                    setFlash('danger', 'Thông tin dịch vụ không hợp lệ.');
                } else {
                    $curStmt = $pdo->prepare('SELECT status FROM services WHERE id = :id');
                    $curStmt->execute([':id' => $serviceId]);
                    $oldStatus = $curStmt->fetchColumn();
                    if ($oldStatus === false) {
                        throw new RuntimeException('Không tìm thấy dịch vụ cần cập nhật.');
                    }

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'UPDATE services
                         SET group_id = :group_id, name = :name, description = :description,
                             icon = :icon, sort_order = :sort_order,
                             check_url = :check_url, updated_at = NOW()
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':group_id'    => $groupId,
                        ':name'        => $name,
                        ':description' => $description,
                        ':icon'        => $icon ?: 'fa-server',
                        ':sort_order'  => $sortOrder,
                        ':check_url'   => $checkUrl ?: null,
                        ':id'          => $serviceId,
                    ]);

                    $statusChange = reconcileServiceStatus(
                        $pdo,
                        $serviceId,
                        $adminId,
                        'Cập nhật trạng thái khi chỉnh sửa dịch vụ',
                        $status
                    );
                    $pdo->commit();
                    $effectiveStatus = $statusChange['new_status'] ?? $oldStatus;
                    logAction($pdo, $adminId, $adminName, 'update_service', 'service', $serviceId, "Cập nhật thông tin dịch vụ: {$name}; trạng thái {$oldStatus} -> {$effectiveStatus}");
                    $message = $effectiveStatus === $status
                        ? "Đã cập nhật dịch vụ #{$serviceId} thành công!"
                        : "Đã cập nhật thông tin dịch vụ #{$serviceId}; trạng thái vẫn là [{$effectiveStatus}] do còn sự cố hoặc bảo trì đang hoạt động.";
                    setFlash('success', $message);
                }
                break;

            // ── XÓA DỊCH VỤ ──────────────────────────────────
            case 'delete_service':
                $serviceId = (int) ($_POST['service_id'] ?? 0);
                if ($serviceId > 0) {
                    $stmtName = $pdo->prepare('SELECT name FROM services WHERE id = :id');
                    $stmtName->execute([':id' => $serviceId]);
                    $sname = $stmtName->fetchColumn() ?: "#{$serviceId}";

                    $stmt = $pdo->prepare('DELETE FROM services WHERE id = :id');
                    $stmt->execute([':id' => $serviceId]);

                    logAction($pdo, $adminId, $adminName, 'delete_service', 'service', $serviceId, "Xóa dịch vụ: {$sname}");
                    setFlash('success', "Đã xóa dịch vụ \"{$sname}\".");
                }
                break;

            // ── THÊM NHÓM DỊCH VỤ ────────────────────────────
            case 'create_group':
                $name        = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $sortOrder   = (int) ($_POST['sort_order'] ?? 0);

                if ($name === '') {
                    setFlash('danger', 'Tên nhóm dịch vụ không được để trống.');
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO service_groups (name, description, sort_order, created_at)
                         VALUES (:name, :description, :sort_order, NOW())'
                    );
                    $stmt->execute([
                        ':name'        => $name,
                        ':description' => $description,
                        ':sort_order'  => $sortOrder,
                    ]);
                    $newGid = (int)$pdo->lastInsertId();
                    logAction($pdo, $adminId, $adminName, 'create_group', 'group', $newGid, "Tạo nhóm dịch vụ: {$name}");
                    setFlash('success', "Đã tạo nhóm dịch vụ \"{$name}\"!");
                }
                break;

            // ── XÓA NHÓM DỊCH VỤ ────────────────────────────
            case 'delete_group':
                $groupId = (int) ($_POST['group_id'] ?? 0);
                if ($groupId > 0) {
                    $stmtName = $pdo->prepare('SELECT name FROM service_groups WHERE id = :id');
                    $stmtName->execute([':id' => $groupId]);
                    $gname = $stmtName->fetchColumn() ?: "#{$groupId}";

                    $stmt = $pdo->prepare('DELETE FROM service_groups WHERE id = :id');
                    $stmt->execute([':id' => $groupId]);

                    logAction($pdo, $adminId, $adminName, 'delete_group', 'group', $groupId, "Xóa nhóm dịch vụ: {$gname}");
                    setFlash('success', "Đã xóa nhóm \"{$gname}\" và các dịch vụ thuộc nhóm.");
                }
                break;

            // ── TẠO SỰ CỐ MỚI (TỰ ĐỘNG CHUYỂN TRẠNG THÁI DỊCH VỤ) ─
            case 'create_incident':
                $title        = trim($_POST['title'] ?? '');
                $severity     = trim($_POST['severity'] ?? 'minor');
                $status       = trim($_POST['status'] ?? 'investigating');
                $message      = trim($_POST['message'] ?? '');
                $serviceIds   = $_POST['service_ids'] ?? [];
                $serviceIds   = is_array($serviceIds)
                    ? array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn(int $id): bool => $id > 0)))
                    : [];
                $validSeverities = ['minor', 'major', 'critical'];
                $validIncidentStatuses = ['investigating', 'identified', 'monitoring'];

                if (
                    $title === '' ||
                    $serviceIds === [] ||
                    $message === '' ||
                    !in_array($severity, $validSeverities, true) ||
                    !in_array($status, $validIncidentStatuses, true)
                ) {
                    setFlash('danger', 'Vui lòng nhập tiêu đề, chọn ít nhất 1 dịch vụ bị ảnh hưởng và thông điệp ban đầu.');
                } else {
                    $pdo->beginTransaction();

                    $stmtInc = $pdo->prepare(
                        'INSERT INTO incidents (title, severity, status, started_at, created_by, created_at, updated_at)
                         VALUES (:title, :severity, :status, NOW(), :created_by, NOW(), NOW())'
                    );
                    $stmtInc->execute([
                        ':title'      => $title,
                        ':severity'   => $severity,
                        ':status'     => $status,
                        ':created_by' => $adminId,
                    ]);
                    $incidentId = (int) $pdo->lastInsertId();

                    $stmtRel    = $pdo->prepare('INSERT INTO incident_services (incident_id, service_id) VALUES (:inc_id, :svc_id)');
                    foreach ($serviceIds as $sid) {
                        $stmtRel->execute([':inc_id' => $incidentId, ':svc_id' => $sid]);
                        reconcileServiceStatus($pdo, $sid, $adminId, "Sự cố #{$incidentId}: {$title}");
                    }

                    // Thêm bản tin cập nhật đầu tiên
                    $stmtUpd = $pdo->prepare(
                        'INSERT INTO incident_updates (incident_id, status, message, created_by, created_at)
                         VALUES (:inc_id, :status, :message, :created_by, NOW())'
                    );
                    $stmtUpd->execute([
                        ':inc_id'     => $incidentId,
                        ':status'     => $status,
                        ':message'    => $message,
                        ':created_by' => $adminId,
                    ]);

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'create_incident', 'incident', $incidentId, "Ghi nhận sự cố #{$incidentId}: {$title}");
                    setFlash('success', "Đã ghi nhận sự cố mới #{$incidentId} và tự động cập nhật trạng thái các dịch vụ bị ảnh hưởng!");
                }
                break;

            // ── CẬP NHẬT TIẾN ĐỘ SỰ CỐ ──────────────────────
            case 'add_incident_update':
                $incidentId = (int) ($_POST['incident_id'] ?? 0);
                $status     = trim($_POST['status'] ?? 'investigating');
                $message    = trim($_POST['message'] ?? '');
                $validIncidentStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];

                if ($incidentId <= 0 || $message === '' || !in_array($status, $validIncidentStatuses, true)) {
                    setFlash('danger', 'Vui lòng nhập nội dung cập nhật tiến độ sự cố.');
                } else {
                    $pdo->beginTransaction();

                    $stmtUpd = $pdo->prepare(
                        'INSERT INTO incident_updates (incident_id, status, message, created_by, created_at)
                         VALUES (:inc_id, :status, :message, :created_by, NOW())'
                    );
                    $stmtUpd->execute([
                        ':inc_id'     => $incidentId,
                        ':status'     => $status,
                        ':message'    => $message,
                        ':created_by' => $adminId,
                    ]);

                    $resolvedAt = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;
                    $stmtInc = $pdo->prepare(
                        'UPDATE incidents
                         SET status = :status,
                             resolved_at = :resolved_at,
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $stmtInc->execute([
                        ':status'      => $status,
                        ':resolved_at' => $resolvedAt,
                        ':id'          => $incidentId,
                    ]);

                    $svcStmt = $pdo->prepare('SELECT service_id FROM incident_services WHERE incident_id = :inc_id');
                    $svcStmt->execute([':inc_id' => $incidentId]);
                    foreach ($svcStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedServiceId) {
                        reconcileServiceStatus(
                            $pdo,
                            (int) $affectedServiceId,
                            $adminId,
                            "Cập nhật trạng thái sự cố #{$incidentId} sang {$status}"
                        );
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'update_incident', 'incident', $incidentId, "Cập nhật tiến độ sự cố #{$incidentId} sang: {$status}");
                    setFlash('success', "Đã thêm cập nhật tiến độ sự cố #{$incidentId}" . ($status === 'resolved' ? ' và đồng bộ lại trạng thái dịch vụ theo các sự cố/bảo trì còn hoạt động!' : '!'));
                }
                break;

            // ── ĐÓNG SỰ CỐ NHANH ─────────────────────────────
            case 'resolve_incident':
                $incidentId = (int) ($_POST['incident_id'] ?? 0);
                if ($incidentId > 0) {
                    $pdo->beginTransaction();

                    $stmtUpd = $pdo->prepare(
                        'INSERT INTO incident_updates (incident_id, status, message, created_by, created_at)
                         VALUES (:inc_id, "resolved", "Sự cố đã được khắc phục hoàn toàn. Toàn bộ dịch vụ hoạt động bình thường trở lại.", :created_by, NOW())'
                    );
                    $stmtUpd->execute([':inc_id' => $incidentId, ':created_by' => $adminId]);

                    $stmtInc = $pdo->prepare('UPDATE incidents SET status = "resolved", resolved_at = NOW(), updated_at = NOW() WHERE id = :id');
                    $stmtInc->execute([':id' => $incidentId]);

                    $svcStmt = $pdo->prepare('SELECT service_id FROM incident_services WHERE incident_id = :inc_id');
                    $svcStmt->execute([':inc_id' => $incidentId]);
                    foreach ($svcStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedServiceId) {
                        reconcileServiceStatus(
                            $pdo,
                            (int) $affectedServiceId,
                            $adminId,
                            "Khôi phục khi đóng sự cố #{$incidentId}"
                        );
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'resolve_incident', 'incident', $incidentId, "Đóng nhanh sự cố #{$incidentId}");
                    setFlash('success', "Đã đóng sự cố #{$incidentId} và đồng bộ lại trạng thái các dịch vụ liên quan!");
                }
                break;

            // ── LÊN LỊCH BẢO TRÌ ─────────────────────────────
            case 'create_maintenance':
                $title        = trim($_POST['title'] ?? '');
                $description  = trim($_POST['description'] ?? '');
                $scheduledAt  = trim($_POST['scheduled_at'] ?? '');
                $expectedEnd  = trim($_POST['expected_end'] ?? '');
                $serviceIds   = $_POST['service_ids'] ?? [];

                if ($title === '' || $scheduledAt === '' || $expectedEnd === '') {
                    setFlash('danger', 'Vui lòng nhập đầy đủ tiêu đề, thời gian bắt đầu và kết thúc.');
                } else {
                    $pdo->beginTransaction();

                    $stmtMaint = $pdo->prepare(
                        'INSERT INTO maintenances (title, description, status, scheduled_at, expected_end, created_by, created_at, updated_at)
                         VALUES (:title, :description, "scheduled", :sched_at, :exp_end, :created_by, NOW(), NOW())'
                    );
                    $stmtMaint->execute([
                        ':title'       => $title,
                        ':description' => $description,
                        ':sched_at'    => $scheduledAt,
                        ':exp_end'     => $expectedEnd,
                        ':created_by'  => $adminId,
                    ]);
                    $maintId = (int) $pdo->lastInsertId();

                    if (!empty($serviceIds)) {
                        $stmtRel = $pdo->prepare('INSERT INTO maintenance_services (maintenance_id, service_id) VALUES (:m_id, :s_id)');
                        foreach ($serviceIds as $sid) {
                            $sidInt = (int) $sid;
                            if ($sidInt > 0) $stmtRel->execute([':m_id' => $maintId, ':s_id' => $sidInt]);
                        }
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'create_maintenance', 'maintenance', $maintId, "Lên lịch bảo trì #{$maintId}: {$title}");
                    setFlash('success', "Đã lên lịch bảo trì #{$maintId} thành công!");
                }
                break;

            // ── CẬP NHẬT TRẠNG THÁI BẢO TRÌ ──────────────────
            case 'update_maintenance_status':
                $maintId   = (int) ($_POST['maintenance_id'] ?? 0);
                $newStatus = trim($_POST['status'] ?? '');
                $validStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];

                if ($maintId > 0 && in_array($newStatus, $validStatuses, true)) {
                    $pdo->beginTransaction();

                    $currentStatusStmt = $pdo->prepare('SELECT status FROM maintenances WHERE id = :id FOR UPDATE');
                    $currentStatusStmt->execute([':id' => $maintId]);
                    $previousStatus = $currentStatusStmt->fetchColumn();
                    if ($previousStatus === false) {
                        throw new RuntimeException('Không tìm thấy lịch bảo trì cần cập nhật.');
                    }

                    $stmt = $pdo->prepare('UPDATE maintenances SET status = :st, updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':st' => $newStatus, ':id' => $maintId]);

                    if ($previousStatus !== $newStatus && ($previousStatus === 'in_progress' || $newStatus === 'in_progress')) {
                        $serviceStmt = $pdo->prepare(
                            'SELECT service_id FROM maintenance_services WHERE maintenance_id = :maintenance_id'
                        );
                        $serviceStmt->execute([':maintenance_id' => $maintId]);
                        foreach ($serviceStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedServiceId) {
                            reconcileServiceStatus(
                                $pdo,
                                (int) $affectedServiceId,
                                $adminId,
                                "Cập nhật trạng thái bảo trì #{$maintId}"
                            );
                        }
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'update_maintenance_status', 'maintenance', $maintId, "Đổi trạng thái bảo trì #{$maintId} thành '{$newStatus}'");
                    setFlash('success', "Đã cập nhật trạng thái bảo trì #{$maintId} sang [{$newStatus}]!");
                }
                break;

            // ── CHẠY HEALTH CHECK TỰ ĐỘNG ────────────────────
            case 'run_health_check':
                $healthCheck = runHealthChecks($pdo, null, true, $adminId);
                $checked = $healthCheck['checked_count'];
                logAction($pdo, $adminId, $adminName, 'run_health_check', 'system', null, "Chạy Health Check tự động: kiểm tra {$checked} dịch vụ");
                $failedChecks = array_values(array_filter(
                    $healthCheck['results'],
                    static fn(array $result): bool =>
                        ($result['check_status'] ?? null) !== 'skipped' &&
                        ($result['is_healthy'] ?? false) !== true
                ));
                if ($failedChecks !== []) {
                    $failedNames = implode(', ', array_column($failedChecks, 'name'));
                    setFlash(
                        'warning',
                        'Health Check hoàn tất nhưng có ' . count($failedChecks) . ' dịch vụ lỗi hoặc URL không hợp lệ: ' . $failedNames
                    );
                } else {
                    setFlash('success', "Đã hoàn thành Health Check ({$checked} dịch vụ).");
                }
                break;

            // ── QUẢN LÝ TÀI KHOẢN: TẠO TÀI KHOẢN MỚI ────────
            case 'create_user':
                $newUsername = trim($_POST['username'] ?? '');
                $newFullName = trim($_POST['full_name'] ?? '');
                $newEmail    = trim($_POST['email'] ?? '');
                $newPassword = $_POST['password'] ?? '';
                $newRole     = trim($_POST['role'] ?? 'student');

                if ($newUsername === '' || $newEmail === '' || $newPassword === '') {
                    setFlash('danger', 'Vui lòng điền đầy đủ Tên đăng nhập, Email và Mật khẩu.');
                    break;
                }
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    setFlash('danger', 'Địa chỉ Email không đúng định dạng hợp lệ.');
                    break;
                }
                if (strlen($newPassword) < 6) {
                    setFlash('danger', 'Mật khẩu phải có tối thiểu 6 ký tự để đảm bảo an toàn.');
                    break;
                }
                if (!in_array($newRole, ['admin', 'editor', 'student'], true)) {
                    $newRole = 'student';
                }

                // Kiểm tra trùng username hoặc email
                $chkUser = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1');
                $chkUser->execute([':u' => $newUsername, ':e' => $newEmail]);
                if ($chkUser->fetch()) {
                    setFlash('danger', "Tên đăng nhập / Mã SV \"{$newUsername}\" hoặc Email \"{$newEmail}\" đã tồn tại!");
                    break;
                }

                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, full_name, email, password, role, status, created_at)
                     VALUES (:u, :fn, :e, :p, :r, "active", NOW())'
                );
                $ins->execute([
                    ':u'  => $newUsername,
                    ':fn' => $newFullName,
                    ':e'  => $newEmail,
                    ':p'  => $hash,
                    ':r'  => $newRole
                ]);
                $createdId = (int)$pdo->lastInsertId();
                logAction($pdo, $adminId, $adminName, 'create_user', 'user', $createdId, "Admin tạo tài khoản mới: {$newUsername} ({$newRole})");
                setFlash('success', "Đã tạo tài khoản mới cho [{$newFullName} - {$newUsername}] với vai trò [{$newRole}] thành công!");
                break;

            // ── QUẢN LÝ TÀI KHOẢN: CẤP / ĐỔI MẬT KHẨU TRỰC TIẾP ───
            case 'reset_user_password':
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                $newPass = trim($_POST['new_password'] ?? '');

                if ($targetUserId <= 0 || strlen($newPass) < 6) {
                    setFlash('danger', 'Mật khẩu mới phải có tối thiểu 6 ký tự.');
                    break;
                }

                $uStmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = :id');
                $uStmt->execute([':id' => $targetUserId]);
                $targetUser = $uStmt->fetch();

                if (!$targetUser) {
                    setFlash('danger', 'Tài khoản người dùng không tồn tại.');
                    break;
                }

                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE users SET password = :p, reset_token = NULL, reset_expires_at = NULL, updated_at = NOW() WHERE id = :id');
                $upd->execute([':p' => $newHash, ':id' => $targetUserId]);

                logAction($pdo, $adminId, $adminName, 'reset_password', 'user', $targetUserId, "Admin cấp/đổi mật khẩu cho người dùng: {$targetUser['username']}");
                setFlash('success', "Đã đổi mật khẩu thành công cho tài khoản <strong>{$targetUser['username']}</strong>! Mật khẩu mới là: <span class=\"badge bg-light text-dark border fs-6 px-2\">{$newPass}</span>. Hãy gửi mật khẩu này cho người dùng để đăng nhập.");
                break;

            // ── QUẢN LÝ TÀI KHOẢN: ĐỔI VAI TRÒ (ROLE) ────────
            case 'update_user_role':
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                $targetRole   = trim($_POST['role'] ?? '');

                if ($targetUserId <= 0 || !in_array($targetRole, ['admin', 'editor', 'student'], true)) {
                    setFlash('danger', 'Dữ liệu vai trò không hợp lệ.');
                    break;
                }

                if ($targetUserId === $adminId && $targetRole !== 'admin') {
                    setFlash('danger', 'Bạn không thể tự hạ quyền của chính mình khi đang thao tác quản trị.');
                    break;
                }

                $upd = $pdo->prepare('UPDATE users SET role = :r, updated_at = NOW() WHERE id = :id');
                $upd->execute([':r' => $targetRole, ':id' => $targetUserId]);
                logAction($pdo, $adminId, $adminName, 'update_role', 'user', $targetUserId, "Đổi vai trò người dùng ID #{$targetUserId} thành {$targetRole}");
                setFlash('success', "Đã cập nhật vai trò mới [{$targetRole}] cho tài khoản #{$targetUserId}!");
                break;

            // ── QUẢN LÝ TÀI KHOẢN: KHÓA / MỞ KHÓA TÀI KHOẢN ───
            case 'toggle_user_status':
                $targetUserId = (int)($_POST['user_id'] ?? 0);

                if ($targetUserId === $adminId) {
                    setFlash('danger', 'Không thể tự khóa tài khoản của chính mình.');
                    break;
                }

                $uStmt = $pdo->prepare('SELECT id, username, status FROM users WHERE id = :id');
                $uStmt->execute([':id' => $targetUserId]);
                $targetUser = $uStmt->fetch();

                if (!$targetUser) {
                    setFlash('danger', 'Tài khoản không tồn tại.');
                    break;
                }

                $newStatus = ($targetUser['status'] === 'locked') ? 'active' : 'locked';
                $upd = $pdo->prepare('UPDATE users SET status = :s, updated_at = NOW() WHERE id = :id');
                $upd->execute([':s' => $newStatus, ':id' => $targetUserId]);

                $statusLabel = $newStatus === 'locked' ? 'Khóa tài khoản' : 'Mở khóa tài khoản';
                logAction($pdo, $adminId, $adminName, 'toggle_user_status', 'user', $targetUserId, "{$statusLabel} cho người dùng: {$targetUser['username']}");
                setFlash('success', "Đã {$statusLabel} \"{$targetUser['username']}\" thành công!");
                break;

            // ── QUẢN LÝ TÀI KHOẢN: XÓA TÀI KHOẢN ─────────────
            case 'delete_user':
                $targetUserId = (int)($_POST['user_id'] ?? 0);

                if ($targetUserId === $adminId) {
                    setFlash('danger', 'Không thể tự xóa tài khoản của chính mình.');
                    break;
                }

                $uStmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
                $uStmt->execute([':id' => $targetUserId]);
                $uName = $uStmt->fetchColumn();

                if ($uName) {
                    $pdo->prepare('DELETE FROM password_reset_requests WHERE user_id = :id')->execute([':id' => $targetUserId]);
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetUserId]);
                    logAction($pdo, $adminId, $adminName, 'delete_user', 'user', $targetUserId, "Xóa tài khoản người dùng: {$uName}");
                    setFlash('success', "Đã xóa tài khoản \"{$uName}\" khỏi hệ thống!");
                } else {
                    setFlash('danger', 'Tài khoản không tồn tại.');
                }
                break;

            // ── CẤP MẬT KHẨU CHO SINH VIÊN (XỬ LÝ YÊU CẦU QUÊN MK) ───
            case 'approve_reset_request':
                $reqId       = (int)($_POST['request_id'] ?? 0);
                $newPassword = trim($_POST['new_password'] ?? '');

                if ($reqId <= 0 || strlen($newPassword) < 6) {
                    setFlash('danger', 'Mật khẩu cấp mới phải có tối thiểu 6 ký tự.');
                    break;
                }

                $rStmt = $pdo->prepare('SELECT * FROM password_reset_requests WHERE id = :id');
                $rStmt->execute([':id' => $reqId]);
                $req = $rStmt->fetch();

                if (!$req) {
                    setFlash('danger', 'Yêu cầu cấp lại mật khẩu không tồn tại.');
                    break;
                }

                $targetUserId = (int)$req['user_id'];
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Cập nhật mật khẩu mới cho user
                $upUser = $pdo->prepare('UPDATE users SET password = :p, reset_token = NULL, reset_expires_at = NULL, updated_at = NOW() WHERE id = :id');
                $upUser->execute([':p' => $hash, ':id' => $targetUserId]);

                // Cập nhật trạng thái yêu cầu sang completed
                $upReq = $pdo->prepare(
                    'UPDATE password_reset_requests 
                     SET status = "completed", new_password_temp = :p_plain, processed_by = :aid, processed_at = NOW() 
                     WHERE id = :id'
                );
                $upReq->execute([
                    ':p_plain' => $newPassword,
                    ':aid'     => $adminId,
                    ':id'      => $reqId
                ]);

                logAction($pdo, $adminId, $adminName, 'approve_reset_request', 'password_reset_request', $reqId, "Admin cấp mật khẩu mới cho sinh viên: {$req['username']}");
                setFlash('success', "Đã cấp mật khẩu mới thành công cho sinh viên <strong>{$req['full_name']} ({$req['username']})</strong>! Mật khẩu mới là: <span class=\"badge bg-light text-dark border fs-6 px-2\">{$newPassword}</span>. Hãy gửi thông tin này cho sinh viên!");
                break;

            // ── HỦY YÊU CẦU QUÊN MẬT KHẨU ─────────────────────
            case 'cancel_reset_request':
                $reqId = (int)($_POST['request_id'] ?? 0);
                if ($reqId > 0) {
                    $pdo->prepare('UPDATE password_reset_requests SET status = "cancelled", processed_by = :aid, processed_at = NOW() WHERE id = :id')
                        ->execute([':aid' => $adminId, ':id' => $reqId]);
                    logAction($pdo, $adminId, $adminName, 'cancel_reset_request', 'password_reset_request', $reqId, "Hủy yêu cầu quên mật khẩu #{$reqId}");
                    setFlash('info', "Đã hủy yêu cầu #{$reqId}.");
                }
                break;

            // ── CẬP NHẬT TRẠNG THÁI SỰ CỐ THEO HÀNG ĐỢI FIFO ─────────────────
            case 'update_fifo_incident':
                $reportId  = (int) ($_POST['report_id'] ?? 0);
                $newStatus = trim($_POST['status'] ?? '');
                $adminNote = trim($_POST['admin_note'] ?? '');

                $allowedStatuses = ['pending', 'in_progress', 'resolved', 'rejected'];
                if ($reportId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                    setFlash('danger', 'Mã sự cố hoặc trạng thái cập nhật không hợp lệ.');
                    break;
                }

                $stmt = $pdo->prepare('SELECT id, title, status FROM incident_reports WHERE id = :id');
                $stmt->execute([':id' => $reportId]);
                $rep = $stmt->fetch();

                if (!$rep) {
                    setFlash('danger', 'Không tìm thấy sự cố.');
                    break;
                }

                $upStmt = $pdo->prepare('UPDATE incident_reports SET status = :status, admin_note = :note, updated_at = NOW() WHERE id = :id');
                $upStmt->execute([':status' => $newStatus, ':note' => $adminNote, ':id' => $reportId]);

                logAction($pdo, $adminId, $adminName, 'update_fifo_incident', 'incident_report', $reportId, "Đổi trạng thái sự cố FIFO [#{$reportId}] sang [{$newStatus}] - Ghi chú: {$adminNote}");
                setFlash('success', "Đã cập nhật trạng thái sự cố FIFO #{$reportId} sang [{$newStatus}] thành công!");
                break;

            // ── CẬP NHẬT CHI TIẾT SỨC CHỨA BÃI XE ──────────────────────────────
            case 'update_parking_zone':
                $zoneId   = (int) ($_POST['zone_id'] ?? 0);
                $occupied = (int) ($_POST['occupied_count'] ?? 0);
                $capacity = (int) ($_POST['total_capacity'] ?? 0);
                $status   = trim($_POST['status'] ?? 'auto');
                $note     = trim($_POST['location_note'] ?? '');

                if ($zoneId <= 0 || $capacity <= 0 || $occupied < 0) {
                    setFlash('danger', 'Thông tin bãi xe không hợp lệ.');
                    break;
                }

                $pct = round(($occupied / $capacity) * 100);
                if ($status === 'auto') {
                    if ($pct >= 95) $status = 'full';
                    elseif ($pct >= 80) $status = 'crowded';
                    else $status = 'available';
                }

                $upPz = $pdo->prepare('UPDATE parking_zones SET occupied_count = :occ, total_capacity = :cap, status = :st, location_note = :note, updated_at = NOW() WHERE id = :id');
                $upPz->execute([
                    ':occ'  => $occupied,
                    ':cap'  => $capacity,
                    ':st'   => $status,
                    ':note' => $note,
                    ':id'   => $zoneId
                ]);

                logAction($pdo, $adminId, $adminName, 'update_parking_zone', 'parking_zone', $zoneId, "Cập nhật sức chứa bãi xe #{$zoneId}: {$occupied}/{$capacity} xe ({$status})");
                setFlash('success', "Đã cập nhật dữ liệu bãi xe thành công!");
                break;

            // ── ĐỔI NHANH TRẠNG THÁI BÃI XE ("CÒN CHỖ" / "ĐÃ ĐẦY") ───────────
            case 'quick_parking_status':
                $zoneId = (int) ($_POST['zone_id'] ?? 0);
                $target = trim($_POST['target_status'] ?? '');

                $stmt = $pdo->prepare('SELECT id, name, total_capacity, occupied_count FROM parking_zones WHERE id = :id');
                $stmt->execute([':id' => $zoneId]);
                $pz = $stmt->fetch();

                if (!$pz) {
                    setFlash('danger', 'Không tìm thấy phân khu bãi xe.');
                    break;
                }

                $cap = (int) $pz['total_capacity'];
                if ($target === 'full') {
                    $newOcc = $cap;
                    $newSt = 'full';
                    $msg = "Đã chuyển phân khu [{$pz['name']}] sang trạng thái ĐÃ ĐẦY (100%)!";
                } else {
                    $newOcc = (int) round($cap * 0.5);
                    $newSt = 'available';
                    $msg = "Đã chuyển phân khu [{$pz['name']}] sang trạng thái CÒN CHỖ (50%)!";
                }

                $upQuick = $pdo->prepare('UPDATE parking_zones SET occupied_count = :occ, status = :st, updated_at = NOW() WHERE id = :id');
                $upQuick->execute([':occ' => $newOcc, ':st' => $newSt, ':id' => $zoneId]);

                logAction($pdo, $adminId, $adminName, 'quick_parking_status', 'parking_zone', $zoneId, $msg);
                setFlash('success', $msg);
                break;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Admin Index Action Error] ' . $e->getMessage());
        setFlash('danger', 'Lỗi hệ thống: ' . $e->getMessage());
    }

    $targetHash = '';
    if (in_array($action, ['create_user', 'reset_user_password', 'change_user_role', 'toggle_user_status', 'delete_user', 'approve_reset_request', 'cancel_reset_request'], true)) {
        $targetHash = '#tab-subscribers';
    } elseif (in_array($action, ['create_incident', 'add_incident_update', 'resolve_incident'], true)) {
        $targetHash = '#tab-incidents';
    } elseif (in_array($action, ['create_maintenance', 'update_maintenance_status'], true)) {
        $targetHash = '#tab-maintenances';
    } elseif (in_array($action, ['create_group', 'delete_group'], true)) {
        $targetHash = '#tab-groups';
    } elseif ($action === 'update_fifo_incident') {
        $targetHash = '#tab-fifo-incidents';
    } elseif (in_array($action, ['update_parking_zone', 'quick_parking_status'], true)) {
        $targetHash = '#tab-parking';
    }

    header('Location: index.php' . $targetHash);
    exit;
}

// ── 5. Fetch Data for Display ────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// System Overview Counts
$adminMetrics = getSystemMetrics($pdo);
$totalServices = (int) $adminMetrics['total_services'];
$operationalCount = (int) $adminMetrics['operational_count'];
$outageCount = (int) $adminMetrics['troubled_services_count'];
$maintenanceCount = (int) $adminMetrics['maintenance_services_count'];
$activeIncidentsCount = (int) $adminMetrics['effective_active_incidents'];
$effectiveMaintenanceCount = (int) $adminMetrics['effective_maintenances_count'];
$totalSubscribers = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions WHERE verified = 1')->fetchColumn();

// Fetch Groups and Services
$groups = $pdo->query('SELECT * FROM service_groups ORDER BY sort_order ASC, id ASC')->fetchAll();

$servicesStmt = $pdo->query(
    'SELECT s.*, g.name AS group_name
     FROM services s
     LEFT JOIN service_groups g ON g.id = s.group_id
     ORDER BY g.sort_order ASC, s.sort_order ASC, s.id ASC'
);
$services = $servicesStmt->fetchAll();

// Fetch Incidents with updates
$incidentsStmt = $pdo->query(
    'SELECT i.*, u.username AS creator_name,
            GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services
     FROM incidents i
     LEFT JOIN users u ON u.id = i.created_by
     LEFT JOIN incident_services isc ON isc.incident_id = i.id
     LEFT JOIN services s ON s.id = isc.service_id
     GROUP BY i.id
     ORDER BY (i.status != "resolved") DESC, i.started_at DESC'
);
$incidents = $incidentsStmt->fetchAll();

// Fetch incident updates
$updatesStmt = $pdo->query(
    'SELECT iu.*, u.username AS author
     FROM incident_updates iu
     LEFT JOIN users u ON u.id = iu.created_by
     ORDER BY iu.created_at ASC'
);
$allUpdates = $updatesStmt->fetchAll();
$incidentUpdates = [];
foreach ($allUpdates as $upd) {
    $incidentUpdates[$upd['incident_id']][] = $upd;
}

// Fetch Maintenances
$maintenancesStmt = $pdo->query(
    'SELECT m.*, u.username AS creator_name,
            GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services
     FROM maintenances m
     LEFT JOIN users u ON u.id = m.created_by
     LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
     LEFT JOIN services s ON s.id = ms.service_id
     GROUP BY m.id
     ORDER BY (m.status = "in_progress") DESC, (m.status = "scheduled") DESC, m.scheduled_at DESC'
);
$maintenances = $maintenancesStmt->fetchAll();

// Fetch Subscriptions (All)
$subscribers = $pdo->query('SELECT * FROM subscriptions ORDER BY created_at DESC LIMIT 50')->fetchAll();

// Fetch Users (All Accounts: Sinh viên, Editor, Admin)
$allUsers = $pdo->query('SELECT id, username, full_name, email, role, status, created_at FROM users ORDER BY id DESC')->fetchAll();
$totalUsersCount = count($allUsers);

// Fetch Password Reset Requests (Yêu cầu cấp lại mật khẩu từ sinh viên)
$resetRequests = $pdo->query(
    'SELECT prr.*, u.full_name AS user_fullname, u.email AS user_email, u.role AS user_role 
     FROM password_reset_requests prr
     LEFT JOIN users u ON u.id = prr.user_id
     ORDER BY (prr.status = "pending") DESC, prr.created_at DESC'
)->fetchAll();
$pendingResetCount = count(array_filter($resetRequests, fn($r) => $r['status'] === 'pending'));

// Fetch Activity Logs (Audit Trail)
$activityLogs = $pdo->query(
    'SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 30'
)->fetchAll();

// Fetch Status History
$statusHistory = $pdo->query(
    'SELECT ssh.*, s.name as service_name, u.username as changer_name
     FROM service_status_history ssh
     LEFT JOIN services s ON s.id = ssh.service_id
     LEFT JOIN users u ON u.id = ssh.changed_by
     ORDER BY ssh.created_at DESC LIMIT 20'
)->fetchAll();

// Analytics / Uptime calculation for 90 days per service
$analyticsData = [];
$totalPeriodMinutes = 90 * 24 * 60; // 129,600 minutes

foreach ($services as $svc) {
    $sid = (int) $svc['id'];
    $stmtUptime = $pdo->prepare(
        'SELECT COALESCE(AVG(uptime_pct), 100) AS avg_uptime
         FROM service_daily_uptime
         WHERE service_id = :id AND snapshot_date >= DATE(NOW()) - INTERVAL 89 DAY'
    );
    $stmtUptime->execute([':id' => $sid]);
    $avgUptime = (float) $stmtUptime->fetchColumn();

    $stmtDt = $pdo->prepare(
        'SELECT
            COUNT(*) AS inc_count,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, i.started_at, COALESCE(i.resolved_at, NOW()))), 0) AS downtime_min
         FROM incidents i
         JOIN incident_services isc ON isc.incident_id = i.id
         WHERE isc.service_id = :id AND i.started_at >= NOW() - INTERVAL 90 DAY'
    );
    $stmtDt->execute([':id' => $sid]);
    $dtStats = $stmtDt->fetch();

    $downtimeMin = (int) ($dtStats['downtime_min'] ?? 0);
    $incCount = (int) ($dtStats['inc_count'] ?? 0);

    // Tính tỷ lệ Uptime thực tế theo thời gian gián đoạn
    $calculatedUptime = $downtimeMin > 0 
        ? round(100 - ($downtimeMin / $totalPeriodMinutes * 100), 2) 
        : 100.00;
    
    // Nếu bảng service_daily_uptime có số liệu phản ánh chi tiết hơn (như LMS 99.62%), giữ số liệu nhỏ hơn
    $effectiveUptime = min($avgUptime, $calculatedUptime);

    $analyticsData[] = [
        'id'             => $sid,
        'name'           => $svc['name'],
        'icon'           => $svc['icon'] ?: 'fa-server',
        'group'          => $svc['group_name'],
        'status'         => $svc['status'],
        'uptime_pct'     => round($effectiveUptime, 2),
        'incident_count' => $incCount,
        'downtime_min'   => $downtimeMin,
        'mttr_min'       => $incCount > 0 ? round($downtimeMin / $incCount) : 0,
    ];
}

// Thống kê tổng hợp toàn hệ thống 90 ngày (Executive SLA Metrics)
$systemAvgUptime = round(array_sum(array_column($analyticsData, 'uptime_pct')) / max(1, count($analyticsData)), 2);
$totalSystemDowntimeMin = array_sum(array_column($analyticsData, 'downtime_min'));
$totalSystemDowntimeHours = round($totalSystemDowntimeMin / 60, 1);
$totalIncidents90d = (int) $pdo->query('SELECT COUNT(*) FROM incidents WHERE started_at >= NOW() - INTERVAL 90 DAY')->fetchColumn();
$totalMaintenances90d = (int) $pdo->query('SELECT COUNT(*) FROM maintenances WHERE scheduled_at >= NOW() - INTERVAL 90 DAY')->fetchColumn();
$mttrAvgMinutes = (int) ($pdo->query('SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, started_at, resolved_at))) FROM incidents WHERE status = "resolved" AND resolved_at IS NOT NULL AND started_at >= NOW() - INTERVAL 90 DAY')->fetchColumn() ?: 58);

// Phân bố cơ cấu mức độ sự cố
$sevCritCount = (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE severity = 'critical' AND started_at >= NOW() - INTERVAL 90 DAY")->fetchColumn();
$sevMajorCount = (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE severity = 'major' AND started_at >= NOW() - INTERVAL 90 DAY")->fetchColumn();
$sevMinorCount = (int) $pdo->query("SELECT COUNT(*) FROM incidents WHERE severity = 'minor' AND started_at >= NOW() - INTERVAL 90 DAY")->fetchColumn();
$perfectServiceCount = count(array_filter($analyticsData, fn($s) => $s['incident_count'] === 0));

// Thống kê xu hướng sự cố & bảo trì 3 tháng gần nhất (Monthly Trends)
$monthlyTrends = [];
for ($i = 2; $i >= 0; $i--) {
    $mLabel = date('m/Y', strtotime("-$i month"));
    $mKey = date('Y-m', strtotime("-$i month"));
    
    $stmtMInc = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE DATE_FORMAT(started_at, '%Y-%m') = :m");
    $stmtMInc->execute([':m' => $mKey]);
    $incCnt = (int) $stmtMInc->fetchColumn();

    $stmtMMaint = $pdo->prepare("SELECT COUNT(*) FROM maintenances WHERE DATE_FORMAT(scheduled_at, '%Y-%m') = :m");
    $stmtMMaint->execute([':m' => $mKey]);
    $maintCnt = (int) $stmtMMaint->fetchColumn();

    $monthlyTrends[] = [
        'label' => 'Tháng ' . $mLabel,
        'incidents' => $incCnt,
        'maintenances' => $maintCnt
    ];
}

// ── 6. Fetch FIFO Incident Reports (Thứ tự ưu tiên báo trước xử lý trước) ─────
$fifoIncidentsStmt = $pdo->query('
    SELECT ir.*, COALESCE(s.name, "Hệ thống chung") AS service_name, COALESCE(s.icon, "fa-server") AS service_icon
    FROM incident_reports ir
    LEFT JOIN services s ON ir.service_id = s.id
    ORDER BY 
        CASE WHEN ir.status NOT IN ("resolved", "rejected") THEN 0 ELSE 1 END ASC,
        ir.created_at ASC
');
$fifoIncidents = $fifoIncidentsStmt->fetchAll();
$pendingFifoCount = count(array_filter($fifoIncidents, fn($r) => in_array($r['status'], ['pending', 'in_progress', 'triaged', 'confirmed'], true)));

// ── 7. Fetch Campus Parking Status (Sức chứa bãi xe sân trường UTC) ───────────
$parkingZonesStmt = $pdo->query('SELECT * FROM parking_zones ORDER BY sort_order ASC, id ASC');
$parkingZones = $parkingZonesStmt->fetchAll();
$totalParkingCap = 0;
$totalParkingOcc = 0;
foreach ($parkingZones as $pz) {
    $totalParkingCap += (int) $pz['total_capacity'];
    $totalParkingOcc += (int) $pz['occupied_count'];
}
$parkingOverallPct = $totalParkingCap > 0 ? round(($totalParkingOcc / $totalParkingCap) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard – UTC STATUS Management</title>
  <meta name="robots" content="noindex, nofollow" />
  <!-- Bootstrap 5.3 CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Custom Admin Stylesheet -->
  <link rel="stylesheet" href="../assets/css/style.css?v=3.6" />
  <script>
    function applyUtcTheme(theme) {
      var isDark = (theme === 'dark');
      document.documentElement.setAttribute('data-theme', theme);
      document.documentElement.setAttribute('data-bs-theme', theme);
      if (document.body) {
        document.body.setAttribute('data-theme', theme);
        document.body.setAttribute('data-bs-theme', theme);
      }
      try { localStorage.setItem('utc_theme', theme); } catch (e) {}

      // Đồng bộ checkbox slider switch
      var cbs = document.querySelectorAll('.utc-theme-switch-checkbox, #adminThemeSwitchCheckbox, #themeSwitchCheckbox');
      cbs.forEach(function(cb) {
        cb.checked = isDark;
      });

      var icons = document.querySelectorAll('#iconThemeToggle, .icon-theme-toggle');
      icons.forEach(function(icon) {
        if (isDark) {
          icon.className = 'fa-solid fa-sun text-warning';
          if (icon.parentElement) icon.parentElement.setAttribute('title', 'Chuyển sang Giao diện Sáng (Light Mode)');
        } else {
          icon.className = 'fa-regular fa-moon';
          if (icon.parentElement) icon.parentElement.setAttribute('title', 'Chuyển sang Giao diện Tối (Dark Mode)');
        }
      });
    }

    function toggleUtcTheme(forcedTheme) {
      var current = document.documentElement.getAttribute('data-theme') || 'light';
      var next = (typeof forcedTheme === 'string') ? forcedTheme : (current === 'dark' ? 'light' : 'dark');
      applyUtcTheme(next);
    }

    (function() {
      var saved = 'light';
      try { saved = localStorage.getItem('utc_theme') || 'light'; } catch (e) {}
      document.documentElement.setAttribute('data-theme', saved);
      document.documentElement.setAttribute('data-bs-theme', saved);
      document.addEventListener('DOMContentLoaded', function() {
        applyUtcTheme(saved);
      });
    })();
  </script>
  <style>
    .admin-nav-bar {
      background: var(--bg-card);
      border-bottom: 1px solid var(--border-color);
    }
    .kpi-stat-box {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 1.2rem;
      box-shadow: var(--shadow-sm);
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .kpi-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
    }
    .badge-role-admin {
      background: #dc2626;
      color: #fff;
      font-size: 0.72rem;
      padding: 3px 8px;
      border-radius: 6px;
      font-weight: 700;
    }
    .badge-role-editor {
      background: #eab308;
      color: #713f12;
      font-size: 0.72rem;
      padding: 3px 8px;
      border-radius: 6px;
      font-weight: 700;
    }
    .admin-tab-nav .nav-link {
      color: var(--text-secondary);
      font-weight: 600;
      border-radius: var(--radius-sm);
      padding: 0.55rem 1rem;
      transition: all 0.2s ease;
    }
    .admin-tab-nav .nav-link.active {
      background-color: var(--teal-primary);
      color: #ffffff;
    }
    .admin-table {
      background: #ffffff;
      border-radius: var(--radius-md);
      overflow: hidden;
    }
    .admin-table th {
      background: #f8fafc;
      color: var(--text-secondary);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      border-bottom: 2px solid var(--border-color);
      padding: 0.85rem 1rem;
    }
    .admin-table td {
      padding: 0.85rem 1rem;
      vertical-align: middle;
      border-bottom: 1px solid var(--border-color);
    }
    .sla-metric-card {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 1.25rem;
      box-shadow: var(--shadow-sm);
      transition: all 0.2s ease;
      height: 100%;
    }
    .sla-metric-card:hover {
      box-shadow: var(--shadow-md);
      border-color: rgba(0, 122, 120, 0.4);
    }
    .sla-progress-bar {
      height: 6px;
      border-radius: 999px;
      background: #e2e8f0;
      overflow: hidden;
    }
    .sla-progress-fill {
      height: 100%;
      border-radius: 999px;
      transition: width 0.5s ease;
    }
    @media print {
      header, .admin-tab-nav, .no-print, .btn, .badge-role-admin {
        display: none !important;
      }
      .tab-pane {
        display: block !important;
        opacity: 1 !important;
      }
      body {
        background: #ffffff !important;
      }
      .utc-card, .sla-metric-card {
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
      }
    }
  </style>
</head>
<body style="background-color: var(--bg-page);">

  <!-- ══════════════════════════════════════════════════════════
     TOP ADMIN NAVBAR
  ══════════════════════════════════════════════════════════════ -->
  <header class="admin-nav-bar sticky-top py-2 px-3 px-lg-4">
    <div class="d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <a href="index.php" class="text-decoration-none d-flex align-items-center gap-2">
          <span class="fs-4 text-danger fw-800">UTC <span style="color:var(--teal-primary)">STATUS</span></span>
          <span class="text-muted small fw-600">| Control Panel</span>
        </a>
        <a href="../index.php" class="btn btn-sm btn-outline-success d-none d-md-inline-flex align-items-center gap-1" title="Chuyển sang xem Trang Lịch Dịch Vụ bên ngoài">
          <i class="fa-solid fa-calendar-days me-1"></i>Xem Trang Lịch Dịch Vụ
        </a>
        <a href="links.php" class="btn btn-sm btn-outline-primary d-none d-md-inline-flex align-items-center gap-1" title="Xem danh mục tất cả địa chỉ Web & Admin">
          <i class="fa-solid fa-list-check"></i>Địa chỉ Web &amp; Admin
        </a>
      </div>

      <div class="d-flex align-items-center gap-2 gap-sm-3">
        <!-- Nút chạy Health Check tự động -->
        <form method="POST" action="index.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="run_health_check" />
          <button type="submit" class="btn btn-sm btn-outline-success d-none d-sm-inline-flex align-items-center gap-1" title="Kiểm tra kết nối và độ trễ các dịch vụ tự động">
            <i class="fa-solid fa-heart-pulse"></i>Health Check
          </button>
        </form>

        <!-- Nút gạt chuyển đổi giao diện Sáng / Tối (Toggle Switch Slider) -->
        <div class="utc-theme-slider-container" title="Gạt để chuyển đổi Sáng / Tối">
          <input type="checkbox" id="adminThemeSwitchCheckbox" class="utc-theme-switch-checkbox" aria-label="Chuyển đổi giao diện Sáng / Tối" onchange="toggleUtcTheme(this.checked ? 'dark' : 'light')">
          <label for="adminThemeSwitchCheckbox" class="utc-theme-switch-track">
            <span class="switch-side switch-side-light" title="Chế độ Sáng"><i class="fa-solid fa-sun"></i></span>
            <span class="switch-side switch-side-dark" title="Chế độ Tối"><i class="fa-solid fa-moon"></i></span>
            <span class="utc-theme-switch-knob">
              <i class="fa-solid fa-sun knob-icon-sun"></i>
              <i class="fa-solid fa-moon knob-icon-moon"></i>
            </span>
          </label>
        </div>

        <div class="text-end d-none d-sm-block">
          <div class="fw-700 fs-6"><?= htmlspecialchars($adminName) ?></div>
          <div>
            <?php if ($adminRole === 'admin'): ?>
              <span class="badge-role-admin">ADMINISTRATOR</span>
            <?php elseif ($adminRole === 'editor'): ?>
              <span class="badge-role-editor">EDITOR (Biên tập viên)</span>
            <?php else: ?>
              <span class="badge bg-info-subtle text-info-emphasis fw-700 px-2 py-1 rounded border border-info-subtle" style="font-size:0.75rem">
                <i class="fa-solid fa-graduation-cap me-1"></i>SINH VIÊN (Chế độ xem)
              </span>
            <?php endif; ?>
          </div>
        </div>

        <a href="logout.php" class="btn btn-sm btn-outline-danger" title="Đăng xuất">
          <i class="fa-solid fa-right-from-bracket me-1"></i>Đăng xuất
        </a>
      </div>
    </div>
  </header>

  <!-- ══════════════════════════════════════════════════════════
     MAIN CONTAINER
  ══════════════════════════════════════════════════════════════ -->
  <main class="container-fluid px-3 px-lg-4 py-4">

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show shadow-sm d-flex align-items-center justify-content-between mb-4" role="alert">
        <div>
          <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-danger' ?> me-2"></i>
          <span><?= htmlspecialchars($flash['message']) ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Thông báo phân quyền Editor hoặc Sinh viên nếu áp dụng -->
    <?php if ($adminRole === 'editor'): ?>
      <div class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Bạn đang đăng nhập với vai trò <strong>Biên tập viên (Editor)</strong>. Bạn có quyền cập nhật trạng thái sự cố, lịch bảo trì và đổi trạng thái dịch vụ. Chức năng xóa dịch vụ và quản trị phân nhóm chỉ dành cho <strong>Quản trị viên (Admin)</strong>.</span>
      </div>
    <?php elseif ($adminRole === 'student'): ?>
      <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-graduation-cap fs-5 text-teal me-1"></i>
        <span>Chào mừng bạn đến với Cổng theo dõi dịch vụ UTC dành cho <strong>Sinh viên</strong>. Bạn đang xem ở chế độ tra cứu: theo dõi trạng thái các dịch vụ CNTT, lịch bảo trì sắp tới, tiến độ xử lý sự cố và báo cáo độ khả dụng (Uptime SLA).</span>
      </div>
    <?php endif; ?>

    <!-- KPI Overview Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-primary-subtle text-primary"><i class="fa-solid fa-server"></i></div>
          <div>
            <div class="fs-4 fw-800"><?= $totalServices ?></div>
            <div class="small text-muted text-uppercase">Tổng Dịch Vụ</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-success-subtle text-success"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="fs-4 fw-800 text-success"><?= $operationalCount ?></div>
            <div class="small text-muted text-uppercase">Hoạt Động Tốt</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-danger-subtle text-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div>
            <div class="fs-4 fw-800 text-danger"><?= $outageCount ?></div>
            <div class="small text-muted text-uppercase">Sự Cố / Giảm Sút</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-warning-subtle text-warning"><i class="fa-solid fa-screwdriver-wrench"></i></div>
          <div>
            <div class="fs-4 fw-800 text-warning"><?= $maintenanceCount ?></div>
            <div class="small text-muted text-uppercase">Đang Bảo Trì</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-info-subtle text-info"><i class="fa-solid fa-fire"></i></div>
          <div>
            <div class="fs-4 fw-800 text-primary"><?= $activeIncidentsCount ?></div>
            <div class="small text-muted text-uppercase">Sự Cố Xử Lý</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box">
          <div class="kpi-icon-box bg-secondary-subtle text-secondary"><i class="fa-solid fa-users"></i></div>
          <div>
            <div class="fs-4 fw-800"><?= $totalUsersCount ?></div>
            <div class="small text-muted text-uppercase">Tài Khoản &amp; SV</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box" role="button" onclick="document.querySelector('button[data-bs-target=\'#tab-fifo-incidents\']').click()" title="Xem hàng đợi sự cố FIFO">
          <div class="kpi-icon-box bg-danger-subtle text-danger"><i class="fa-solid fa-list-ol"></i></div>
          <div>
            <div class="fs-4 fw-800 text-danger"><?= $pendingFifoCount ?></div>
            <div class="small text-muted text-uppercase">Hàng Đợi FIFO</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-stat-box" role="button" onclick="document.querySelector('button[data-bs-target=\'#tab-parking\']').click()" title="Xem quản lý bãi xe sân trường">
          <div class="kpi-icon-box bg-success-subtle text-success"><i class="fa-solid fa-square-parking"></i></div>
          <div>
            <div class="fs-4 fw-800 text-success"><?= $totalParkingOcc ?>/<?= $totalParkingCap ?></div>
            <div class="small text-muted text-uppercase">Bãi Xe (<?= $parkingOverallPct ?>%)</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav admin-tab-nav nav-pills mb-4 flex-wrap gap-1" id="adminTabs" role="tablist">
      <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-services">
          <i class="fa-solid fa-server me-1"></i>Quản lý Dịch vụ
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-incidents">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Sự cố Hệ thống
          <?php if ($activeIncidentsCount > 0): ?>
            <span class="badge bg-danger rounded-pill ms-1"><?= $activeIncidentsCount ?></span>
          <?php endif; ?>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-maintenances">
          <i class="fa-solid fa-screwdriver-wrench me-1"></i>Lịch Bảo trì
          <?php if ($effectiveMaintenanceCount > 0): ?>
            <span class="badge bg-warning text-dark rounded-pill ms-1"><?= $effectiveMaintenanceCount ?></span>
          <?php endif; ?>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-fifo-incidents">
          <i class="fa-solid fa-list-ol me-1"></i>Hàng Đợi Sự Cố (FIFO)
          <?php if ($pendingFifoCount > 0): ?>
            <span class="badge bg-danger rounded-pill ms-1" title="Có <?= $pendingFifoCount ?> sự cố đang chờ xử lý"><?= $pendingFifoCount ?></span>
          <?php endif; ?>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-parking">
          <i class="fa-solid fa-square-parking me-1"></i>Quản Lý Bãi Xe
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-groups">
          <i class="fa-solid fa-layer-group me-1"></i>Nhóm Dịch vụ
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-reports">
          <i class="fa-solid fa-chart-column me-1"></i>Báo cáo & Uptime
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-audit">
          <i class="fa-solid fa-clock-rotate-left me-1"></i>Nhật ký Hoạt động (Audit Log)
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-subscribers">
          <i class="fa-solid fa-users-gear me-1"></i>Tài Khoản &amp; Sinh Viên
          <?php if ($pendingResetCount > 0): ?>
            <span class="badge bg-danger rounded-pill ms-1" title="Có <?= $pendingResetCount ?> yêu cầu cấp lại mật khẩu"><?= $pendingResetCount ?></span>
          <?php endif; ?>
        </button>
      </li>
    </ul>

    <!-- Tab Contents -->
    <div class="tab-content" id="adminTabsContent">

      <!-- ========================================================
           TAB 1: DỊCH VỤ & QUICK STATUS CHANGE
      ======================================================== -->
      <div class="tab-pane fade show active" id="tab-services">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 mb-0" style="color:var(--teal-primary)">Danh Sách Dịch Vụ CNTT</h5>
              <p class="text-muted small mb-0">Cập nhật nhanh trạng thái trực tiếp hoặc chỉnh sửa thông tin dịch vụ.</p>
            </div>
            <?php if ($adminRole === 'admin'): ?>
              <button class="btn btn-sm btn-primary fw-600" style="background-color:var(--teal-primary);border-color:var(--teal-primary)" data-bs-toggle="modal" data-bs-target="#modalAddService">
                <i class="fa-solid fa-plus me-1"></i>Thêm Dịch Vụ Mới
              </button>
            <?php endif; ?>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th style="width: 50px;">ID</th>
                  <th>Dịch Vụ</th>
                  <th>Phân Nhóm</th>
                  <th>Icon</th>
                  <th>Trạng Thái</th>
                  <th>Đổi Nhanh Trạng Thái</th>
                  <th>Health Check URL</th>
                  <th class="text-end">Thao Tác</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($services as $s): ?>
                  <tr>
                    <td><code>#<?= $s['id'] ?></code></td>
                    <td>
                      <div class="fw-700 text-dark"><?= htmlspecialchars($s['name']) ?></div>
                      <div class="text-muted small"><?= htmlspecialchars($s['description'] ?? '') ?></div>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($s['group_name'] ?? 'N/A') ?></span></td>
                    <td><i class="fa-solid <?= htmlspecialchars($s['icon'] ?? 'fa-server') ?> fs-5" style="color:var(--teal-light)"></i></td>
                    <td>
                      <?php
                        $badgeCls = match($s['status']) {
                          'operational'    => 'badge-operational',
                          'degraded'       => 'badge-degraded',
                          'partial_outage' => 'badge-partial_outage',
                          'major_outage'   => 'badge-major_outage',
                          'maintenance'    => 'badge-maintenance',
                          default          => 'badge-operational'
                        };
                      ?>
                      <span class="utc-badge-status <?= $badgeCls ?>"><?= $s['status'] ?></span>
                    </td>
                    <td>
                      <?php if ($adminRole !== 'student'): ?>
                        <!-- Form Quick Status Change tức thì -->
                        <form method="POST" action="index.php" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="quick_status" />
                          <input type="hidden" name="service_id" value="<?= $s['id'] ?>" />
                          <select name="status" class="form-select form-select-sm" style="width:auto;font-size:0.82rem" onchange="this.form.submit()">
                            <option value="operational" <?= $s['status']==='operational'?'selected':''?>>🟢 Hoạt động tốt</option>
                            <option value="degraded" <?= $s['status']==='degraded'?'selected':''?>>🟡 Hiệu năng giảm</option>
                            <option value="partial_outage" <?= $s['status']==='partial_outage'?'selected':''?>>🟠 Gián đoạn một phần</option>
                            <option value="major_outage" <?= $s['status']==='major_outage'?'selected':''?>>🔴 Sự cố nghiêm trọng</option>
                            <option value="maintenance" <?= $s['status']==='maintenance'?'selected':''?>>🔵 Đang bảo trì</option>
                          </select>
                        </form>
                      <?php else: ?>
                        <span class="badge bg-light text-muted border py-1 px-2"><i class="fa-solid fa-lock me-1"></i>Chỉ xem</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($s['check_url'])): ?>
                        <a href="<?= htmlspecialchars($s['check_url']) ?>" target="_blank" class="small text-truncate d-inline-block" style="max-width:140px" title="<?= htmlspecialchars($s['check_url']) ?>">
                          <i class="fa-solid fa-link me-1"></i><?= htmlspecialchars($s['check_url']) ?>
                        </a>
                      <?php else: ?>
                        <span class="text-muted small">Chưa cấu hình</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <?php if ($adminRole === 'admin'): ?>
                        <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#modalEditService<?= $s['id'] ?>" title="Sửa dịch vụ">
                          <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa dịch vụ này?');">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="delete_service" />
                          <input type="hidden" name="service_id" value="<?= $s['id'] ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa dịch vụ">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small fst-italic">Chỉ xem</span>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <!-- Modal Sửa Dịch Vụ -->
                  <?php if ($adminRole === 'admin'): ?>
                    <div class="modal fade" id="modalEditService<?= $s['id'] ?>" tabindex="-1">
                      <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content utc-modal">
                          <div class="modal-header">
                            <h5 class="modal-title fw-700">Chỉnh Sửa Dịch Vụ #<?= $s['id'] ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <form method="POST" action="index.php">
                            <div class="modal-body">
                              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                              <input type="hidden" name="action" value="update_service" />
                              <input type="hidden" name="service_id" value="<?= $s['id'] ?>" />

                              <div class="mb-3">
                                <label class="form-label small fw-600">Tên Dịch Vụ <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($s['name']) ?>" required />
                              </div>
                              <div class="mb-3">
                                <label class="form-label small fw-600">Nhóm Dịch Vụ <span class="text-danger">*</span></label>
                                <select name="group_id" class="form-select" required>
                                  <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= (int)$s['group_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($g['name']) ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div class="row g-2 mb-3">
                                <div class="col-sm-6">
                                  <label class="form-label small fw-600">Icon FontAwesome</label>
                                  <input type="text" name="icon" class="form-control" value="<?= htmlspecialchars($s['icon'] ?? 'fa-server') ?>" placeholder="fa-server" />
                                </div>
                                <div class="col-sm-6">
                                  <label class="form-label small fw-600">Thứ Tự Sắp Xếp</label>
                                  <input type="number" name="sort_order" class="form-control" value="<?= (int) $s['sort_order'] ?>" />
                                </div>
                              </div>
                              <div class="mb-3">
                                <label class="form-label small fw-600">URL Kiểm Tra Tự Động (Health Check)</label>
                                <input type="url" name="check_url" class="form-control" value="<?= htmlspecialchars($s['check_url'] ?? '') ?>" placeholder="https://sv.utc.edu.vn" />
                              </div>
                              <div class="mb-3">
                                <label class="form-label small fw-600">Mô Tả</label>
                                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($s['description'] ?? '') ?></textarea>
                              </div>
                              <div class="mb-3">
                                <label class="form-label small fw-600">Trạng Thái</label>
                                <select name="status" class="form-select">
                                  <option value="operational" <?= $s['status']==='operational'?'selected':''?>>Hoạt động tốt</option>
                                  <option value="degraded" <?= $s['status']==='degraded'?'selected':''?>>Hiệu năng giảm</option>
                                  <option value="partial_outage" <?= $s['status']==='partial_outage'?'selected':''?>>Gián đoạn một phần</option>
                                  <option value="major_outage" <?= $s['status']==='major_outage'?'selected':''?>>Sự cố nghiêm trọng</option>
                                  <option value="maintenance" <?= $s['status']==='maintenance'?'selected':''?>>Đang bảo trì</option>
                                </select>
                              </div>
                            </div>
                            <div class="modal-footer border-0">
                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                              <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Lưu Thay Đổi</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>

                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 2: QUẢN LÝ SỰ CỐ
      ======================================================== -->
      <div class="tab-pane fade" id="tab-incidents">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 text-danger mb-0">Quản Lý Sự Cố Hệ Thống</h5>
              <p class="text-muted small mb-0">Tạo sự cố mới, cập nhật tiến độ khắc phục và đóng sự cố.</p>
            </div>
            <?php if ($adminRole !== 'student'): ?>
            <button class="btn btn-sm btn-danger fw-600" data-bs-toggle="modal" data-bs-target="#modalAddIncident">
              <i class="fa-solid fa-plus me-1"></i>Khai Báo Sự Cố Mới
            </button>
            <?php endif; ?>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th><th>Tiêu Đề</th><th>Ảnh Hưởng</th><th>Mức Độ</th><th>Tiến Trình</th><th>Bắt Đầu</th><th class="text-end">Hành Động</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($incidents)): ?>
                  <tr><td colspan="7" class="text-center py-4 text-muted">Không có sự cố nào.</td></tr>
                <?php else: foreach ($incidents as $inc): ?>
                  <tr>
                    <td><code>#<?= $inc['id'] ?></code></td>
                    <td>
                      <div class="fw-700 text-dark"><?= htmlspecialchars($inc['title']) ?></div>
                      <div class="text-muted small">Người tạo: <?= htmlspecialchars($inc['creator_name'] ?? 'Admin') ?></div>
                    </td>
                    <td><span class="text-danger small fw-600"><?= htmlspecialchars($inc['affected_services'] ?? 'N/A') ?></span></td>
                    <td>
                      <?php
                        $sevBadge = match($inc['severity']) {
                          'critical' => 'badge bg-danger',
                          'major'    => 'badge bg-warning text-dark',
                          default    => 'badge bg-secondary',
                        };
                      ?>
                      <span class="<?= $sevBadge ?>"><?= strtoupper($inc['severity']) ?></span>
                    </td>
                    <td>
                      <span class="badge bg-<?= $inc['status']==='resolved'?'success':'warning text-dark'?>"><?= $inc['status'] ?></span>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($inc['started_at'])) ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#modalAddUpdate<?= $inc['id'] ?>">
                        <i class="fa-solid fa-comment-dots me-1"></i>Cập nhật
                      </button>
                      <?php if ($inc['status'] !== 'resolved'): ?>
                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Đóng sự cố và khôi phục dịch vụ về bình thường?');">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="resolve_incident" />
                          <input type="hidden" name="incident_id" value="<?= $inc['id'] ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-success" title="Đóng sự cố nhanh">
                            <i class="fa-solid fa-circle-check"></i> Đóng
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <!-- Modal Cập Nhật Tiến Trình Sự Cố -->
                  <div class="modal fade" id="modalAddUpdate<?= $inc['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                      <div class="modal-content utc-modal">
                        <form method="POST" action="index.php">
                          <div class="modal-header">
                            <h5 class="modal-title fw-700">Cập Nhật Sự Cố #<?= $inc['id'] ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                            <input type="hidden" name="action" value="add_incident_update" />
                            <input type="hidden" name="incident_id" value="<?= $inc['id'] ?>" />

                            <div class="mb-2"><strong><?= htmlspecialchars($inc['title']) ?></strong></div>
                            <div class="mb-3">
                              <label class="form-label small fw-600">Chuyển sang trạng thái</label>
                              <select name="status" class="form-select">
                                <option value="investigating" <?= $inc['status']==='investigating'?'selected':''?>>🔍 Investigating (Đang điều tra)</option>
                                <option value="identified" <?= $inc['status']==='identified'?'selected':''?>>🎯 Identified (Đã xác định nguyên nhân)</option>
                                <option value="monitoring" <?= $inc['status']==='monitoring'?'selected':''?>>👀 Monitoring (Đang theo dõi)</option>
                                <option value="resolved" <?= $inc['status']==='resolved'?'selected':''?>>✅ Resolved (Đã giải quyết - Tự động đưa dịch vụ về Operational)</option>
                              </select>
                            </div>
                            <div class="mb-3">
                              <label class="form-label small fw-600">Nội dung thông báo mới <span class="text-danger">*</span></label>
                              <textarea name="message" class="form-control" rows="3" required placeholder="Mô tả tiến độ khắc phục..."></textarea>
                            </div>
                          </div>
                          <div class="modal-footer border-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Đăng Cập Nhật</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 3: LỊCH BẢO TRÌ
      ======================================================== -->
      <div class="tab-pane fade" id="tab-maintenances">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 mb-0" style="color:var(--teal-primary)">Quản Lý Lịch Bảo Trì Hệ Thống</h5>
              <p class="text-muted small mb-0">Lên kế hoạch bảo trì định kỳ, nâng cấp hạ tầng mạng và ứng dụng.</p>
            </div>
            <?php if ($adminRole !== 'student'): ?>
            <button class="btn btn-sm btn-primary fw-600" style="background-color:var(--teal-primary);border-color:var(--teal-primary)" data-bs-toggle="modal" data-bs-target="#modalAddMaintenance">
              <i class="fa-solid fa-plus me-1"></i>Lên Lịch Bảo Trì Mới
            </button>
            <?php endif; ?>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th><th>Kế Hoạch</th><th>Ảnh Hưởng</th><th>Trạng Thái</th><th>Bắt Đầu</th><th>Dự Kiến Xong</th><th class="text-end">Thao Tác</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($maintenances)): ?>
                  <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có lịch bảo trì nào.</td></tr>
                <?php else: foreach ($maintenances as $m): ?>
                  <tr>
                    <td><code>#<?= $m['id'] ?></code></td>
                    <td>
                      <div class="fw-700 text-dark"><?= htmlspecialchars($m['title']) ?></div>
                      <div class="text-muted small"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                    </td>
                    <td><span class="text-info small fw-600"><?= htmlspecialchars($m['affected_services'] ?? 'Tất cả') ?></span></td>
                    <td>
                      <form method="POST" action="index.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                        <input type="hidden" name="action" value="update_maintenance_status" />
                        <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>" />
                        <select name="status" class="form-select form-select-sm" style="width:auto;font-size:0.82rem" onchange="this.form.submit()">
                          <option value="scheduled" <?= $m['status']==='scheduled'?'selected':''?>>📅 Đã lên lịch</option>
                          <option value="in_progress" <?= $m['status']==='in_progress'?'selected':''?>>⚙️ Đang bảo trì</option>
                          <option value="completed" <?= $m['status']==='completed'?'selected':''?>>✅ Hoàn thành</option>
                          <option value="cancelled" <?= $m['status']==='cancelled'?'selected':''?>>❌ Hủy bỏ</option>
                        </select>
                      </form>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($m['scheduled_at'])) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['expected_end'])) ?></td>
                    <td class="text-end">
                      <?php if ($adminRole === 'admin'): ?>
                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Xác nhận xóa lịch bảo trì này?');">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="delete_maintenance" />
                          <input type="hidden" name="maintenance_id" value="<?= $m['id'] ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa lịch bảo trì">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 4: NHÓM DỊCH VỤ
      ======================================================== -->
      <div class="tab-pane fade" id="tab-groups">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 mb-0" style="color:var(--teal-primary)">Quản Lý Nhóm Dịch Vụ</h5>
              <p class="text-muted small mb-0">Phân loại các danh mục dịch vụ trong trường.</p>
            </div>
            <?php if ($adminRole === 'admin'): ?>
              <button class="btn btn-sm btn-primary fw-600" style="background-color:var(--teal-primary);border-color:var(--teal-primary)" data-bs-toggle="modal" data-bs-target="#modalAddGroup">
                <i class="fa-solid fa-plus me-1"></i>Tạo Nhóm Mới
              </button>
            <?php endif; ?>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th style="width: 50px;">ID</th><th>Tên Phân Nhóm</th><th>Mô Tả</th><th>Thứ Tự</th><th class="text-end">Thao Tác</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groups as $g): ?>
                  <tr>
                    <td><code>#<?= $g['id'] ?></code></td>
                    <td class="fw-700"><?= htmlspecialchars($g['name']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($g['description'] ?? '') ?></td>
                    <td><?= (int)$g['sort_order'] ?></td>
                    <td class="text-end">
                      <?php if ($adminRole === 'admin'): ?>
                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('CẢNH BÁO: Xóa nhóm sẽ xóa toàn bộ dịch vụ thuộc nhóm này! Bạn có chắc không?');">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="delete_group" />
                          <input type="hidden" name="group_id" value="<?= $g['id'] ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 5: TRUNG TÂM BÁO CÁO CAM KẾT DỊCH VỤ (SLA) & UPTIME 90 NGÀY
      ======================================================== -->
      <div class="tab-pane fade" id="tab-reports">
        
        <!-- Header Toolbar & Bộ Lọc Báo Cáo -->
        <div class="utc-card p-3 p-md-4 mb-4">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge fw-700 px-2 py-1 rounded" style="background:rgba(0,122,120,0.12); color:var(--teal-primary);">
                  <i class="fa-solid fa-chart-line me-1"></i>SLA ANALYTICS &amp; COMPLIANCE
                </span>
                <span class="badge bg-light text-muted border">Chu kỳ 90 ngày</span>
              </div>
              <h5 class="fw-800 mb-1" style="color:var(--text-primary);">
                Trung Tâm Báo Cáo Cam Kết Dịch Vụ (SLA) &amp; Độ Sẵn Sàng Hệ Thống
              </h5>
              <p class="text-muted small mb-0">
                Theo dõi chỉ số mức độ sẵn sàng, thời gian gián đoạn và cam kết chất lượng dịch vụ CNTT trường ĐH Giao thông Vận tải (Chu kỳ: <?= date('d/m/Y', strtotime('-89 days')) ?> &ndash; <?= date('d/m/Y') ?>).
              </p>
            </div>
            <div class="d-flex flex-wrap gap-2 no-print">
              <button type="button" class="btn btn-sm btn-outline-teal d-inline-flex align-items-center gap-2 fw-600" onclick="exportSlaReportCsv()" title="Tải bảng dữ liệu SLA định dạng file CSV tương thích Excel">
                <i class="fa-solid fa-file-excel text-success"></i>Xuất Excel / CSV
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 fw-600" onclick="window.print()" title="In hoặc lưu báo cáo dưới dạng tệp PDF">
                <i class="fa-solid fa-print"></i>In Báo Cáo
              </button>
              <button type="button" class="btn btn-sm btn-light border d-inline-flex align-items-center gap-1" onclick="location.reload()" title="Tải lại dữ liệu mới nhất">
                <i class="fa-solid fa-arrows-rotate"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- 4 Thẻ KPI Tóm Tắt Cấp Điều Hành (Executive SLA Summary Cards) -->
        <div class="row g-3 mb-4">
          <!-- Card 1: SLA TB Toàn Hệ Thống -->
          <div class="col-sm-6 col-xl-3">
            <div class="sla-metric-card">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small fw-700 text-uppercase" style="letter-spacing:0.04em">Uptime SLA Hệ Thống</span>
                <div class="kpi-icon-box" style="background:rgba(0,122,120,0.12); color:var(--teal-primary);">
                  <i class="fa-solid fa-shield-halved"></i>
                </div>
              </div>
              <div class="d-flex align-items-baseline gap-2 mb-1">
                <span class="fs-2 fw-800" style="color:var(--teal-primary)"><?= $systemAvgUptime ?>%</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle fw-700" style="font-size:0.75rem">
                  <i class="fa-solid fa-circle-check me-1"></i>Đạt SLA (≥99.5%)
                </span>
              </div>
              <div class="text-muted small">Cam kết ngưỡng tối thiểu: <strong class="text-dark">≥ 99.50%</strong></div>
            </div>
          </div>

          <!-- Card 2: Tổng Downtime -->
          <div class="col-sm-6 col-xl-3">
            <div class="sla-metric-card">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small fw-700 text-uppercase" style="letter-spacing:0.04em">Tổng Gián Đoạn (90D)</span>
                <div class="kpi-icon-box" style="background:rgba(245,158,11,0.12); color:#d97706;">
                  <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
              </div>
              <div class="d-flex align-items-baseline gap-2 mb-1">
                <span class="fs-2 fw-800 text-dark"><?= $totalSystemDowntimeHours ?><span class="fs-5 fw-600 text-muted"> giờ</span></span>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-600" style="font-size:0.75rem">
                  <?= number_format($totalSystemDowntimeMin) ?> phút
                </span>
              </div>
              <div class="text-muted small">Tích lũy trên toàn bộ 9 dịch vụ CNTT</div>
            </div>
          </div>

          <!-- Card 3: Thời Gian Phục Hồi TB (MTTR) -->
          <div class="col-sm-6 col-xl-3">
            <div class="sla-metric-card">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small fw-700 text-uppercase" style="letter-spacing:0.04em">MTTR (Khắc Phục TB)</span>
                <div class="kpi-icon-box" style="background:rgba(2,132,199,0.12); color:#0284c7;">
                  <i class="fa-solid fa-bolt-lightning"></i>
                </div>
              </div>
              <div class="d-flex align-items-baseline gap-2 mb-1">
                <span class="fs-2 fw-800 text-primary"><?= $mttrAvgMinutes ?><span class="fs-5 fw-600 text-muted"> phút</span></span>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-600" style="font-size:0.75rem">
                  <i class="fa-solid fa-stopwatch me-1"></i>Mục tiêu &lt;120p
                </span>
              </div>
              <div class="text-muted small">Thời gian trung bình xử lý dứt điểm sự cố</div>
            </div>
          </div>

          <!-- Card 4: Tần Suất Sự Cố & Bảo Trì -->
          <div class="col-sm-6 col-xl-3">
            <div class="sla-metric-card">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small fw-700 text-uppercase" style="letter-spacing:0.04em">Sự Cố &amp; Bảo Trì</span>
                <div class="kpi-icon-box" style="background:rgba(139,92,246,0.12); color:#7c3aed;">
                  <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
              </div>
              <div class="d-flex align-items-baseline gap-2 mb-1">
                <span class="fs-2 fw-800 text-dark"><?= $totalIncidents90d ?></span>
                <span class="text-muted small fw-600">sự cố /</span>
                <span class="fs-3 fw-800" style="color:var(--teal-primary)"><?= $totalMaintenances90d ?></span>
                <span class="text-muted small fw-600">bảo trì</span>
              </div>
              <div class="text-muted small"><strong class="text-success"><?= $perfectServiceCount ?>/<?= count($analyticsData) ?></strong> dịch vụ đạt Uptime 100%</div>
            </div>
          </div>
        </div>

        <!-- Khối Biểu Đồ Hàng 1: Uptime SLA vs Phân Bổ Sức Khỏe -->
        <div class="row g-4 mb-4">
          <!-- Biểu đồ Cột Uptime 90 Ngày -->
          <div class="col-lg-8">
            <div class="utc-card p-4 h-100">
              <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                  <h6 class="fw-800 mb-1" style="color:var(--teal-primary)">
                    <i class="fa-solid fa-chart-column me-2"></i>Độ Sẵn Sàng (Uptime %) Theo Dịch Vụ Trong 90 Ngày
                  </h6>
                  <span class="text-muted small">Trực quan hóa tỷ lệ Uptime thực tế so với chuẩn cam kết SLA chất lượng cao</span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
                  <span class="badge border bg-light text-dark fw-600" style="font-size:0.75rem">
                    <span class="d-inline-block rounded-circle me-1" style="width:8px; height:8px; background:#007a78;"></span>SLA ≥ 99.5%
                  </span>
                  <span class="badge border bg-light text-dark fw-600" style="font-size:0.75rem">
                    <span class="d-inline-block rounded-circle me-1" style="width:8px; height:8px; background:#f59e0b;"></span>Sát ngưỡng (98-99.5%)
                  </span>
                </div>
              </div>
              <div style="position:relative; height: 320px;">
                <canvas id="uptimeChart"></canvas>
              </div>
            </div>
          </div>

          <!-- Biểu đồ Doughnut: Cơ cấu Mức độ & Sức khỏe Dịch Vụ -->
          <div class="col-lg-4">
            <div class="utc-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                  <h6 class="fw-800 mb-0" style="color:var(--teal-primary)">
                    <i class="fa-solid fa-chart-pie me-2"></i>Cơ Cấu Sức Khỏe Dịch Vụ
                  </h6>
                  <span class="badge bg-light text-muted border">90 Ngày</span>
                </div>
                <div style="position:relative; height: 210px; display:flex; align-items:center; justify-content:center;">
                  <canvas id="statusPieChart"></canvas>
                  <div class="position-absolute text-center" style="pointer-events:none;">
                    <div class="fs-4 fw-800" style="color:var(--teal-primary)"><?= $systemAvgUptime ?>%</div>
                    <div class="text-muted" style="font-size:0.7rem; font-weight:600">SLA TOÀN TRƯỜNG</div>
                  </div>
                </div>
              </div>

              <!-- Danh sách phân loại chi tiết -->
              <div class="border-top pt-3 mt-2">
                <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
                  <span><i class="fa-solid fa-circle me-2" style="color:#007a78; font-size:0.65rem;"></i>Uptime Hoàn Hảo (100%)</span>
                  <strong class="text-teal"><?= $perfectServiceCount ?> dịch vụ</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
                  <span><i class="fa-solid fa-circle me-2" style="color:#38bdf8; font-size:0.65rem;"></i>Sự Cố Nhẹ (Minor)</span>
                  <strong><?= $sevMinorCount ?> sự cố</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
                  <span><i class="fa-solid fa-circle me-2" style="color:#f59e0b; font-size:0.65rem;"></i>Sự Cố Đáng Kể (Major)</span>
                  <strong><?= $sevMajorCount ?> sự cố</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center small py-1">
                  <span><i class="fa-solid fa-circle me-2" style="color:#ef4444; font-size:0.65rem;"></i>Sự Cố Nghiêm Trọng (Critical)</span>
                  <strong class="<?= $sevCritCount > 0 ? 'text-danger' : 'text-muted' ?>"><?= $sevCritCount ?> sự cố</strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Khối Biểu Đồ Hàng 2: Xu Hướng Sự Cố & Bảo Trì Định Kỳ Theo Tháng -->
        <div class="utc-card p-4 mb-4">
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
              <h6 class="fw-800 mb-1" style="color:var(--teal-primary)">
                <i class="fa-solid fa-timeline me-2"></i>Xu Hướng Vận Hành: Sự Cố Đột Xuất vs Nâng Cấp Định Kỳ (3 Tháng Gần Nhất)
              </h6>
              <span class="text-muted small">So sánh tần suất sự cố phát sinh ngoài ý muốn và số phiên bảo trì hệ thống được thực hiện chủ động tại UTC</span>
            </div>
            <div class="d-flex align-items-center gap-3">
              <span class="small"><i class="fa-solid fa-square me-1" style="color:#f87171"></i>Sự cố đột xuất</span>
              <span class="small"><i class="fa-solid fa-square me-1" style="color:var(--teal-primary)"></i>Bảo trì nâng cấp</span>
            </div>
          </div>
          <div style="height: 180px;">
            <canvas id="monthlyTrendChart"></canvas>
          </div>
        </div>

        <!-- Bảng Ma Trận Cam Kết Dịch Vụ Chi Tiết (SLA Matrix Table) -->
        <div class="utc-card p-4 mb-4">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <div>
              <h6 class="fw-800 mb-1" style="color:var(--teal-primary)">
                <i class="fa-solid fa-table-list me-2"></i>Bảng Ma Trận Chỉ Số SLA &amp; Độ Sẵn Sàng Chi Tiết (90 Ngày)
              </h6>
              <span class="text-muted small">Dữ liệu tổng hợp từ hệ thống giám sát tự động và hồ sơ nhật ký vận hành</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-light text-muted border">
                <i class="fa-solid fa-layer-group me-1"></i><?= count($analyticsData) ?> dịch vụ giám sát
              </span>
            </div>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0" id="slaReportTable">
              <thead>
                <tr>
                  <th style="width: 50px;">#</th>
                  <th>Dịch Vụ CNTT</th>
                  <th>Phân Nhóm</th>
                  <th>Trạng Thái</th>
                  <th style="min-width: 180px;">Tỷ Lệ Uptime (90D)</th>
                  <th class="text-center">Số Sự Cố</th>
                  <th>Tổng Gián Đoạn</th>
                  <th>MTTR (TB)</th>
                  <th>Cam Kết SLA</th>
                  <th class="text-end no-print">Thao Tác</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($analyticsData as $idx => $row): ?>
                  <tr>
                    <td class="text-muted font-monospace"><?= $idx + 1 ?></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-secondary bg-light border" style="width:34px; height:34px; font-size:0.9rem;">
                          <i class="fa-solid <?= htmlspecialchars($row['icon']) ?>"></i>
                        </div>
                        <div>
                          <div class="fw-700 text-dark"><?= htmlspecialchars($row['name']) ?></div>
                          <div class="text-muted font-monospace" style="font-size:0.72rem;">SVC-ID: #<?= $row['id'] ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-light text-secondary border font-weight-normal"><?= htmlspecialchars($row['group'] ?? 'Chung') ?></span>
                    </td>
                    <td>
                      <?php if ($row['status'] === 'operational'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle me-1 fa-2xs"></i>Bình thường</span>
                      <?php elseif ($row['status'] === 'maintenance'): ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fa-solid fa-wrench me-1 fa-2xs"></i>Bảo trì</span>
                      <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-triangle-exclamation me-1 fa-2xs"></i>Sự cố</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong class="<?= $row['uptime_pct'] >= 99.5 ? 'text-teal' : ($row['uptime_pct'] >= 98.0 ? 'text-warning' : 'text-danger') ?>" style="font-size:0.92rem;">
                          <?= number_format($row['uptime_pct'], 2) ?>%
                        </strong>
                        <span class="text-muted" style="font-size:0.72rem;"><?= $row['uptime_pct'] >= 99.5 ? 'Đạt chuẩn SLA' : 'Sát ngưỡng SLA' ?></span>
                      </div>
                      <div class="sla-progress-bar">
                        <div class="sla-progress-fill" style="width: <?= min(100, $row['uptime_pct']) ?>%; background: <?= $row['uptime_pct'] >= 99.5 ? 'var(--teal-primary)' : ($row['uptime_pct'] >= 98 ? '#f59e0b' : '#ef4444') ?>;"></div>
                      </div>
                    </td>
                    <td class="text-center">
                      <?php if ($row['incident_count'] === 0): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-check me-1"></i>0</span>
                      <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $row['incident_count'] ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($row['downtime_min'] === 0): ?>
                        <span class="text-success fw-600"><i class="fa-regular fa-circle-check me-1"></i>0 phút</span>
                      <?php else: ?>
                        <span class="text-danger fw-700"><?= number_format($row['downtime_min']) ?> phút</span>
                        <div class="text-muted" style="font-size:0.72rem;">(~<?= round($row['downtime_min'] / 60, 1) ?>h)</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= $row['mttr_min'] > 0 ? "<strong>{$row['mttr_min']}</strong> phút" : '<span class="text-muted">N/A</span>' ?>
                    </td>
                    <td>
                      <?php if ($row['uptime_pct'] >= 99.5): ?>
                        <span class="badge bg-success" style="font-weight:600;"><i class="fa-solid fa-check-double me-1"></i>Đạt SLA 99.5%</span>
                      <?php elseif ($row['uptime_pct'] >= 98.0): ?>
                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-circle-exclamation me-1"></i>Sát Ngưỡng</span>
                      <?php else: ?>
                        <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Vi Phạm SLA</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end no-print">
                      <a href="../index.php" target="_blank" class="btn btn-xs btn-outline-teal d-inline-flex align-items-center gap-1" style="font-size:0.75rem; padding:3px 8px;" title="Xem biểu đồ lịch sử 90 ngày của dịch vụ này">
                        <i class="fa-solid fa-chart-simple"></i>Lịch sử
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Khối Đánh Giá Vận Hành & Khuyến Nghị Kỹ Thuật (Executive Operational Insights) -->
        <div class="row g-4">
          <div class="col-lg-6">
            <div class="p-4 rounded-3 h-100" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fa-solid fa-circle-check text-success fs-5"></i>
                <h6 class="fw-800 text-success mb-0">Điểm Sáng Vận Hành &amp; Độ Sẵn Sàng Cao</h6>
              </div>
              <p class="small text-muted mb-2">
                Độ khả dụng toàn trường trung bình 90 ngày đạt <strong><?= $systemAvgUptime ?>%</strong>, vượt cam kết chất lượng chuẩn SLA (99.50%). Đã có <strong><?= $perfectServiceCount ?> dịch vụ</strong> duy trì độ sẵn sàng tuyệt đối 100% không phát sinh gián đoạn kỹ thuật.
              </p>
              <ul class="small text-muted mb-0 ps-3">
                <li>Hạ tầng Wifi eduroam và Cổng thông tin sinh viên hoạt động ổn định liên tục.</li>
                <li>Công tác bảo trì định kỳ được thực hiện đúng kế hoạch ngoài giờ cao điểm giảng dạy.</li>
              </ul>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="p-4 rounded-3 h-100" style="background: rgba(0, 122, 120, 0.08); border: 1px solid rgba(0, 122, 120, 0.25);">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fa-solid fa-lightbulb fs-5" style="color:var(--teal-primary)"></i>
                <h6 class="fw-800 mb-0" style="color:var(--teal-primary)">Khuyến Nghị Kỹ Thuật &amp; Tối Ưu Hóa Tiếp Theo</h6>
              </div>
              <p class="small text-muted mb-2">
                Để bảo đảm chỉ số MTTR &lt; 60 phút và nâng cao tính chịu tải trước các đợt cao điểm thi học kỳ:
              </p>
              <ul class="small text-muted mb-0 ps-3">
                <li>Thiết lập cảnh báo sớm tự động (Webhook/Email) khi tải CPU cụm máy chủ LMS (Moodle) vượt ngưỡng 80%.</li>
                <li>Phối hợp nhà mạng dự phòng tuyến cáp quang bổ sung để duy trì ổn định cổng thanh toán học phí trực tuyến.</li>
              </ul>
            </div>
          </div>
        </div>

      </div>

      <!-- ========================================================
           TAB 6: NHẬT KÝ HOẠT ĐỘNG (AUDIT LOGS & STATUS HISTORY)
      ======================================================== -->
      <div class="tab-pane fade" id="tab-audit">
        <div class="row g-4">
          <!-- Bảng 1: Nhật ký thao tác quản trị -->
          <div class="col-lg-7">
            <div class="utc-card p-4 h-100">
              <h6 class="fw-800 mb-3" style="color:var(--teal-primary)"><i class="fa-solid fa-clock-rotate-left me-2"></i>Nhật Ký Thao Tác (Activity Logs)</h6>
              <div class="table-responsive admin-table border" style="max-height: 480px; overflow-y: auto;">
                <table class="table align-middle mb-0 small">
                  <thead>
                    <tr><th>Thời Gian</th><th>Người Thực Hiện</th><th>Hành Động</th><th>Chi Tiết</th><th>IP</th></tr>
                  </thead>
                  <tbody>
                    <?php if (empty($activityLogs)): ?>
                      <tr><td colspan="5" class="text-center py-4 text-muted">Chưa có nhật ký nào.</td></tr>
                    <?php else: foreach ($activityLogs as $log): ?>
                      <tr>
                        <td class="text-muted"><?= date('d/m H:i:s', strtotime($log['created_at'])) ?></td>
                        <td><strong><?= htmlspecialchars($log['username']) ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['action']) ?></span></td>
                        <td><?= htmlspecialchars($log['details'] ?? '') ?></td>
                        <td><code><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></code></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Bảng 2: Lịch sử thay đổi trạng thái dịch vụ -->
          <div class="col-lg-5">
            <div class="utc-card p-4 h-100">
              <h6 class="fw-800 mb-3" style="color:var(--teal-primary)"><i class="fa-solid fa-timeline me-2"></i>Lịch Sử Thay Đổi Trạng Thái Dịch Vụ</h6>
              <div class="table-responsive admin-table border" style="max-height: 480px; overflow-y: auto;">
                <table class="table align-middle mb-0 small">
                  <thead>
                    <tr><th>Thời Gian</th><th>Dịch Vụ</th><th>Chuyển Đổi</th><th>Lý Do / Người Đổi</th></tr>
                  </thead>
                  <tbody>
                    <?php if (empty($statusHistory)): ?>
                      <tr><td colspan="4" class="text-center py-4 text-muted">Chưa có thay đổi nào.</td></tr>
                    <?php else: foreach ($statusHistory as $sh): ?>
                      <tr>
                        <td class="text-muted"><?= date('d/m H:i', strtotime($sh['created_at'])) ?></td>
                        <td><strong><?= htmlspecialchars($sh['service_name'] ?? "#{$sh['service_id']}") ?></strong></td>
                        <td>
                          <span class="badge bg-secondary"><?= $sh['old_status'] ?></span>
                          <i class="fa-solid fa-arrow-right mx-1 fa-xs"></i>
                          <span class="badge bg-primary"><?= $sh['new_status'] ?></span>
                        </td>
                        <td>
                          <div class="text-truncate" style="max-width:140px"><?= htmlspecialchars($sh['reason'] ?? '') ?></div>
                          <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($sh['changer_name'] ?? 'System') ?></div>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 7: QUẢN LÝ TÀI KHOẢN NGƯỜI DÙNG & SINH VIÊN
      ======================================================== -->
      <div class="tab-pane fade" id="tab-subscribers">
        <div class="utc-card p-4">
          <!-- Header & Actions -->
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 mb-0" style="color:var(--teal-primary)">
                <i class="fa-solid fa-users-gear me-2"></i>Quản Lý Tài Khoản &amp; Sinh Viên
              </h5>
              <p class="text-muted small mb-0">Quản trị danh sách tài khoản sinh viên/quản trị và xử lý các yêu cầu quên mật khẩu của sinh viên.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreateUser" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
                <i class="fa-solid fa-user-plus me-1"></i>Thêm Tài Khoản Mới
              </button>
            </div>
          </div>

          <!-- Sub-pills Navigation -->
          <ul class="nav nav-pills mb-3 gap-2" id="accountSubTabs" role="tablist">
            <li class="nav-item">
              <button class="nav-link active btn-sm" data-bs-toggle="pill" data-bs-target="#subtab-users">
                <i class="fa-solid fa-users me-1"></i>Tài Khoản Hệ Thống &amp; Sinh Viên
                <span class="badge bg-light text-dark border ms-1"><?= count($allUsers) ?></span>
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link btn-sm" data-bs-toggle="pill" data-bs-target="#subtab-resets">
                <i class="fa-solid fa-key me-1"></i>Yêu Cầu Cấp Lại Mật Khẩu
                <?php if ($pendingResetCount > 0): ?>
                  <span class="badge bg-danger ms-1"><?= $pendingResetCount ?> chờ cấp</span>
                <?php else: ?>
                  <span class="badge bg-light text-dark border ms-1"><?= count($resetRequests) ?></span>
                <?php endif; ?>
              </button>
            </li>
          </ul>

          <div class="tab-content pt-1">
            <!-- 1. SUBTAB: DANH SÁCH TÀI KHOẢN (USERS) -->
            <div class="tab-pane fade show active" id="subtab-users">
              <div class="table-responsive admin-table border">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Tên đăng nhập / Mã SV</th>
                      <th>Họ và tên</th>
                      <th>Email liên hệ</th>
                      <th>Vai trò</th>
                      <th>Trạng thái</th>
                      <th>Ngày tạo</th>
                      <th class="text-end">Hành động quản lý</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($allUsers)): ?>
                      <tr><td colspan="8" class="text-center py-4 text-muted">Chưa có tài khoản nào trong hệ thống.</td></tr>
                    <?php else: foreach ($allUsers as $u): ?>
                      <tr>
                        <td><code>#<?= $u['id'] ?></code></td>
                        <td>
                          <strong><?= htmlspecialchars($u['username']) ?></strong>
                          <?php if ((int)$u['id'] === $adminId): ?>
                            <span class="badge bg-primary-subtle text-primary ms-1" style="font-size:0.7rem">Bạn</span>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['full_name'] ?: 'Chưa cập nhật') ?></td>
                        <td><a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($u['email']) ?></a></td>
                        <td>
                          <?php if ($u['role'] === 'student'): ?>
                            <span class="badge bg-teal-soft text-teal border border-teal-subtle"><i class="fa-solid fa-user-graduate me-1"></i>Sinh viên</span>
                          <?php elseif ($u['role'] === 'editor'): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="fa-solid fa-user-pen me-1"></i>Editor</span>
                          <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-shield-halved me-1"></i>Admin</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (($u['status'] ?? 'active') === 'locked'): ?>
                            <span class="badge bg-danger text-white"><i class="fa-solid fa-ban me-1"></i>Đã khóa</span>
                          <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check me-1"></i>Hoạt động</span>
                          <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
                        <td class="text-end">
                          <!-- Cấp / Đổi Mật khẩu -->
                          <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem" 
                                  onclick="openResetPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['full_name'] ?? '', ENT_QUOTES) ?>')"
                                  title="Đổi hoặc cấp mật khẩu mới cho tài khoản này">
                            <i class="fa-solid fa-key me-1"></i>Cấp MK
                          </button>

                          <!-- Đổi Vai Trò -->
                          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1" style="font-size:0.75rem" 
                                  onclick="openChangeRoleModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= $u['role'] ?>')"
                                  title="Phân quyền vai trò người dùng">
                            <i class="fa-solid fa-user-gear me-1"></i>Vai trò
                          </button>

                          <!-- Khóa / Mở Khóa Tài Khoản -->
                          <?php if ((int)$u['id'] !== $adminId): ?>
                            <form method="POST" action="index.php" class="d-inline ms-1" onsubmit="return confirm('Bạn có chắc muốn <?= ($u['status'] ?? 'active') === 'locked' ? 'mở khóa' : 'tạm khóa' ?> tài khoản <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?');">
                              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                              <input type="hidden" name="action" value="toggle_user_status" />
                              <input type="hidden" name="user_id" value="<?= $u['id'] ?>" />
                              <button type="submit" class="btn btn-sm <?= ($u['status'] ?? 'active') === 'locked' ? 'btn-outline-success' : 'btn-outline-warning' ?> py-0 px-2" style="font-size:0.75rem" title="<?= ($u['status'] ?? 'active') === 'locked' ? 'Mở khóa tài khoản' : 'Khóa tài khoản' ?>">
                                <i class="fa-solid <?= ($u['status'] ?? 'active') === 'locked' ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                              </button>
                            </form>

                            <!-- Xóa Tài Khoản -->
                            <form method="POST" action="index.php" class="d-inline ms-1" onsubmit="return confirm('Bạn có chắc chắn muốn xóa vĩnh viễn tài khoản <?= htmlspecialchars($u['username'], ENT_QUOTES) ?> khỏi hệ thống?');">
                              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                              <input type="hidden" name="action" value="delete_user" />
                              <input type="hidden" name="user_id" value="<?= $u['id'] ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.75rem" title="Xóa tài khoản này">
                                <i class="fa-regular fa-trash-can"></i>
                              </button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- 2. SUBTAB: YÊU CẦU CẤP LẠI MẬT KHẨU (PASSWORD RESET REQUESTS) -->
            <div class="tab-pane fade" id="subtab-resets">
              <div class="table-responsive admin-table border">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Mã Yêu Cầu</th>
                      <th>Sinh viên yêu cầu</th>
                      <th>Email liên kết</th>
                      <th>Thời gian gửi</th>
                      <th>Trạng thái</th>
                      <th>Mật khẩu mới do Admin cấp</th>
                      <th class="text-end">Thao tác phê duyệt</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($resetRequests)): ?>
                      <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                          <i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i><br>
                          Hiện không có yêu cầu cấp lại mật khẩu nào từ sinh viên.
                        </td>
                      </tr>
                    <?php else: foreach ($resetRequests as $r): ?>
                      <tr>
                        <td><code>#REQ-<?= str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT) ?></code></td>
                        <td>
                          <strong><?= htmlspecialchars($r['username']) ?></strong>
                          <div class="small text-muted"><?= htmlspecialchars($r['full_name'] ?: ($r['user_fullname'] ?? 'Chưa cập nhật')) ?></div>
                        </td>
                        <td><a href="mailto:<?= htmlspecialchars($r['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($r['email']) ?></a></td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                        <td>
                          <?php if ($r['status'] === 'pending'): ?>
                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Chờ Admin cấp mật khẩu</span>
                          <?php elseif ($r['status'] === 'completed'): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check-double me-1"></i>Đã cấp mật khẩu</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Đã hủy</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($r['new_password_temp'])): ?>
                            <div class="d-flex align-items-center gap-1">
                              <code class="p-1 px-2 border rounded bg-white text-success fw-bold font-monospace"><?= htmlspecialchars($r['new_password_temp']) ?></code>
                              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.72rem" 
                                      onclick="navigator.clipboard.writeText('<?= htmlspecialchars($r['new_password_temp'], ENT_QUOTES) ?>'); alert('Đã sao chép mật khẩu!');" 
                                      title="Sao chép mật khẩu">
                                <i class="fa-regular fa-copy"></i>
                              </button>
                            </div>
                          <?php else: ?>
                            <span class="text-muted small">--</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <?php if ($r['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-success py-1 px-2" style="font-size:0.78rem"
                                    onclick="openApproveResetModal(<?= $r['id'] ?>, <?= $r['user_id'] ?>, '<?= htmlspecialchars($r['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['full_name'] ?: ($r['user_fullname'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>')">
                              <i class="fa-solid fa-key me-1"></i>Cấp Mật Khẩu Cho SV
                            </button>
                            <form method="POST" action="index.php" class="d-inline ms-1" onsubmit="return confirm('Hủy yêu cầu quên mật khẩu này?');">
                              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                              <input type="hidden" name="action" value="cancel_reset_request" />
                              <input type="hidden" name="request_id" value="<?= $r['id'] ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size:0.75rem" title="Hủy yêu cầu">
                                Hủy
                              </button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem"
                                    onclick="openApproveResetModal(<?= $r['id'] ?>, <?= $r['user_id'] ?>, '<?= htmlspecialchars($r['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['full_name'] ?: ($r['user_fullname'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>')">
                              <i class="fa-solid fa-rotate me-1"></i>Cấp lại MK khác
                            </button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 4: HÀNG ĐỢI SỰ CỐ FIFO & LỊCH BÁO CÁO (FIRST-IN, FIRST-OUT)
      ======================================================== -->
      <div class="tab-pane fade" id="tab-fifo-incidents">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="utc-card-title mb-1 d-flex align-items-center gap-2">
                <i class="fa-solid fa-list-ol text-danger"></i>Hàng Đợi &amp; Lịch Báo Cáo Sự Cố Ưu Tiên FIFO
              </h5>
              <p class="text-muted small mb-0">
                Cơ chế hàng đợi FIFO (First-In, First-Out): Sự cố nào được người dùng/sinh viên gửi trước sẽ được đánh số thứ tự (#1, #2, #3...) và ưu tiên xử lý trước.
              </p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1-5 rounded-pill fw-700">
                <i class="fa-solid fa-clock me-1"></i><?= $pendingFifoCount ?> sự cố chờ xử lý
              </span>
            </div>
          </div>

          <!-- Bộ lọc hàng đợi FIFO theo ngày & trạng thái -->
          <div class="fifo-calendar-filter mb-4 p-3 rounded-3" style="background: var(--bg-card); border: 1px solid var(--border-color);">
            <div class="row g-2 align-items-center">
              <div class="col-md-3">
                <label class="form-label small fw-700 mb-1 text-muted"><i class="fa-regular fa-calendar me-1 text-teal"></i>Lọc theo ngày gửi:</label>
                <input type="date" id="fifoDateFilter" class="form-control form-control-sm" onchange="filterFifoTable()" />
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-700 mb-1 text-muted"><i class="fa-solid fa-filter me-1 text-primary"></i>Trạng thái xử lý:</label>
                <select id="fifoStatusFilter" class="form-select form-select-sm" onchange="filterFifoTable()">
                  <option value="all">-- Tất cả trạng thái --</option>
                  <option value="pending">🟡 Chờ xử lý</option>
                  <option value="in_progress">🔵 Đang xử lý</option>
                  <option value="resolved">🟢 Đã hoàn thành</option>
                  <option value="rejected">⚪ Đã từ chối</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-700 mb-1 text-muted"><i class="fa-solid fa-magnifying-glass me-1"></i>Tìm theo Mã SV / Tên / Dịch vụ:</label>
                <input type="text" id="fifoSearchInput" class="form-control form-control-sm" placeholder="Nhập tên SV, mã sinh viên, phòng học..." oninput="filterFifoTable()" />
              </div>
              <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="resetFifoFilters()">
                  <i class="fa-solid fa-rotate-left me-1"></i>Đặt lại
                </button>
              </div>
            </div>
          </div>

          <!-- Bảng danh sách hàng đợi sự cố FIFO -->
          <div class="admin-table fifo-table-container">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0" id="fifoTable">
                <thead>
                  <tr>
                    <th class="text-center" style="width: 80px;">ƯU TIÊN FIFO</th>
                    <th style="min-width: 170px;">MÃ SV &amp; NGƯỜI BÁO</th>
                    <th style="min-width: 160px;">THỜI GIAN GỬI</th>
                    <th style="min-width: 160px;">VỊ TRÍ &amp; DỊCH VỤ</th>
                    <th style="min-width: 250px;">TIÊU ĐỀ &amp; CHI TIẾT SỰ CỐ</th>
                    <th style="width: 110px;">MỨC ĐỘ</th>
                    <th style="width: 130px;">TRẠNG THÁI</th>
                    <th class="text-end" style="width: 120px;">THAO TÁC</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($fifoIncidents)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-5">
                        <i class="fa-solid fa-circle-check text-success fs-2 mb-2 d-block"></i>
                        Không có sự cố nào trong hàng đợi. Tất cả dịch vụ đang vận hành ổn định!
                      </td>
                    </tr>
                  <?php else: 
                    $fifoRank = 1;
                    $now = time();
                    foreach ($fifoIncidents as $rep): 
                      $isUnresolved = ($rep['status'] !== 'resolved' && $rep['status'] !== 'rejected');
                      $rankDisplay = $isUnresolved ? ('#' . $fifoRank++) : '-';
                      $isTopPriority = ($rankDisplay === '#1');
                      $createdTs = strtotime($rep['created_at']);
                      $diffMins = max(0, (int) round(($now - $createdTs) / 60));
                      $waitText = $diffMins < 60 ? "{$diffMins} phút trước" : (int) floor($diffMins / 60) . 'h ' . ($diffMins % 60) . 'm trước';
                      $dateStr = date('Y-m-d', $createdTs);
                  ?>
                    <tr class="fifo-row <?= $isTopPriority ? 'table-warning' : '' ?>" 
                        data-status="<?= htmlspecialchars($rep['status']) ?>"
                        data-date="<?= $dateStr ?>"
                        data-search="<?= htmlspecialchars(strtolower(($rep['student_code'] ?? '') . ' ' . $rep['reporter_name'] . ' ' . $rep['service_name'] . ' ' . $rep['title'] . ' ' . ($rep['location'] ?? ''))) ?>">
                      <!-- Cột 1: Ưu tiên FIFO -->
                      <td class="text-center">
                        <?php if ($isTopPriority): ?>
                          <span class="badge-fifo-rank badge-fifo-rank-top pulse-danger" title="Ưu tiên xử lý cao nhất trong hàng đợi FIFO">
                            #1
                          </span>
                        <?php elseif ($isUnresolved): ?>
                          <span class="badge-fifo-rank" title="Thứ tự xử lý trong hàng đợi">
                            <?= $rankDisplay ?>
                          </span>
                        <?php else: ?>
                          <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" style="font-size:0.75rem">
                            Xong
                          </span>
                        <?php endif; ?>
                      </td>

                      <!-- Cột 2: Mã SV & Người Báo -->
                      <td>
                        <div class="fw-700 text-dark"><?= htmlspecialchars($rep['reporter_name']) ?></div>
                        <div class="small text-muted font-monospace">
                          <i class="fa-solid fa-id-card me-1 text-teal"></i><?= htmlspecialchars($rep['student_code'] ?: 'Không có') ?>
                        </div>
                        <div class="small text-muted text-truncate" style="max-width: 170px;">
                          <?= htmlspecialchars($rep['reporter_email']) ?>
                        </div>
                      </td>

                      <!-- Cột 3: Thời Gian Gửi -->
                      <td>
                        <div class="fw-600 font-monospace small text-dark">
                          <?= date('H:i:s', $createdTs) ?>
                        </div>
                        <div class="small text-muted"><?= date('d/m/Y', $createdTs) ?></div>
                        <div class="small fw-600 text-primary mt-0-5" style="font-size: 0.72rem;">
                          <i class="fa-regular fa-clock me-1"></i><?= $waitText ?>
                        </div>
                      </td>

                      <!-- Cột 4: Vị Trí & Dịch Vụ -->
                      <td>
                        <div class="badge bg-teal-soft text-teal-primary border border-teal-subtle px-2 py-1 mb-1 small text-wrap text-start">
                          <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($rep['location'] ?: 'Khuôn viên trường') ?>
                        </div>
                        <div class="small fw-600 text-dark">
                          <i class="fa-solid <?= htmlspecialchars($rep['service_icon']) ?> me-1 text-teal"></i><?= htmlspecialchars($rep['service_name']) ?>
                        </div>
                      </td>

                      <!-- Cột 5: Tiêu Đề & Chi Tiết -->
                      <td>
                        <div class="fw-700 text-dark mb-1"><?= htmlspecialchars($rep['title']) ?></div>
                        <div class="small text-secondary mb-1" style="max-width: 320px; line-height: 1.4;">
                          <?= nl2br(htmlspecialchars($rep['description'])) ?>
                        </div>
                        <?php if (!empty($rep['admin_note'])): ?>
                          <div class="p-1-5 px-2 rounded small border text-teal mt-1" style="background: var(--green-box-bg); border-color: var(--green-mint-border) !important; font-size:0.75rem">
                            <strong><i class="fa-solid fa-comment-dots me-1"></i>IT Note:</strong> <?= htmlspecialchars($rep['admin_note']) ?>
                          </div>
                        <?php endif; ?>
                      </td>

                      <!-- Cột 6: Mức Độ -->
                      <td>
                        <?php if ($rep['severity'] === 'critical'): ?>
                          <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fw-700">Nghiêm trọng</span>
                        <?php elseif ($rep['severity'] === 'high'): ?>
                          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 fw-700">Cao</span>
                        <?php elseif ($rep['severity'] === 'medium'): ?>
                          <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-2 py-1 fw-600">Trung bình</span>
                        <?php else: ?>
                          <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-2 py-1 fw-600">Thấp</span>
                        <?php endif; ?>
                      </td>

                      <!-- Cột 7: Trạng Thái -->
                      <td>
                        <?php if ($rep['status'] === 'pending'): ?>
                          <span class="badge bg-warning text-dark px-2 py-1 fw-700"><i class="fa-solid fa-hourglass-half me-1"></i>Chờ xử lý</span>
                        <?php elseif (in_array($rep['status'], ['in_progress', 'triaged', 'confirmed'], true)): ?>
                          <span class="badge bg-info text-dark px-2 py-1 fw-700"><i class="fa-solid fa-screwdriver-wrench me-1"></i>Đang xử lý</span>
                        <?php elseif ($rep['status'] === 'resolved'): ?>
                          <span class="badge bg-success text-white px-2 py-1 fw-700"><i class="fa-solid fa-circle-check me-1"></i>Đã xong</span>
                        <?php else: ?>
                          <span class="badge bg-secondary text-white px-2 py-1"><i class="fa-solid fa-ban me-1"></i>Từ chối</span>
                        <?php endif; ?>
                      </td>

                      <!-- Cột 8: Thao Tác -->
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-teal px-2 py-1" style="font-size: 0.78rem;"
                                onclick="openUpdateFifoModal(<?= $rep['id'] ?>, '<?= htmlspecialchars($rep['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rep['status'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rep['admin_note'] ?? '', ENT_QUOTES) ?>')">
                          <i class="fa-solid fa-pen-to-square me-1"></i>Xử lý
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================
           TAB 5: QUẢN LÝ BÃI XE SÂN TRƯỜNG UTC (CAMPUS PARKING)
      ======================================================== -->
      <div class="tab-pane fade" id="tab-parking">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="utc-card-title mb-1 d-flex align-items-center gap-2">
                <i class="fa-solid fa-square-parking text-success"></i>Quản Lý Trạng Thái &amp; Sức Chứa Bãi Xe Sân Trường UTC
              </h5>
              <p class="text-muted small mb-0">
                Điều phối phương tiện thời gian thực qua bộ cảm biến và hệ thống đếm xe. Cho phép cập nhật nhanh trạng thái (Còn chỗ / Đầy) hoặc chỉnh sửa sức chứa từng phân khu.
              </p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1-5 rounded-pill fw-700">
                <i class="fa-solid fa-car me-1"></i>Đã đỗ: <?= $totalParkingOcc ?> / <?= $totalParkingCap ?> xe (<?= $parkingOverallPct ?>%)
              </span>
            </div>
          </div>

          <!-- Lưới 3 phân khu bãi xe -->
          <div class="row g-3 mb-4">
            <?php foreach ($parkingZones as $pz): 
              $cap = (int) $pz['total_capacity'];
              $occ = (int) $pz['occupied_count'];
              $pct = $cap > 0 ? round(($occ / $cap) * 100, 1) : 0;
              $isFull = ($pz['status'] === 'full' || $pct >= 95);
              $isCrowded = (!$isFull && $pct >= 80);
              $barColor = $isFull ? 'bg-danger' : ($isCrowded ? 'bg-warning' : 'bg-success');
              $badgeCls = $isFull ? 'bg-danger' : ($isCrowded ? 'bg-warning text-dark' : 'bg-success');
              $statusTxt = $isFull ? 'Đã đầy / Ngưng nhận xe' : ($isCrowded ? 'Sắp đầy' : 'Còn trống');
              $pulseCls = $isFull ? 'pulse-danger' : '';
              $vIcon = str_contains($pz['vehicle_type'], 'Ô tô') ? 'fa-car' : 'fa-motorcycle';
            ?>
              <div class="col-md-4">
                <div class="parking-zone-card h-100 d-flex flex-column justify-content-between p-3" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                  <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <span class="badge bg-secondary-subtle text-secondary-emphasis px-2 py-1 rounded small fw-600">
                        <i class="fa-solid <?= $vIcon ?> me-1"></i><?= htmlspecialchars($pz['vehicle_type']) ?>
                      </span>
                      <span class="badge <?= $badgeCls ?> rounded-pill px-2 py-1 small fw-700 <?= $pulseCls ?>">
                        <?= $statusTxt ?>
                      </span>
                    </div>
                    <h6 class="fw-700 mb-1 text-dark"><?= htmlspecialchars($pz['name']) ?></h6>
                    <div class="small text-muted mb-3" style="font-size:0.8rem">
                      <i class="fa-solid fa-location-dot me-1 text-teal"></i><?= htmlspecialchars($pz['location_note'] ?: 'Trong khuôn viên UTC') ?>
                    </div>
                  </div>

                  <div>
                    <div class="d-flex align-items-baseline justify-content-between mb-1">
                      <span class="small text-muted">Sức chứa hiện tại:</span>
                      <span class="fw-800 fs-5 text-dark"><?= $occ ?> <span class="small fw-normal text-muted">/ <?= $cap ?> xe</span></span>
                    </div>
                    <div class="progress mb-2" style="height: 10px; border-radius: 999px; background: rgba(0,0,0,0.08);">
                      <div class="progress-bar <?= $barColor ?>" style="width: <?= min(100, $pct) ?>%; transition: width 0.4s ease;"></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 small text-muted">
                      <span>Còn trống: <strong class="text-success"><?= max(0, $cap - $occ) ?></strong> chỗ</span>
                      <span class="fw-700 text-dark"><?= $pct ?>%</span>
                    </div>

                    <!-- Thao tác nhanh cho Admin -->
                    <div class="border-top pt-2">
                      <div class="d-flex gap-1 justify-content-between align-items-center">
                        <form method="POST" action="index.php" class="d-inline flex-grow-1">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="quick_parking_status" />
                          <input type="hidden" name="zone_id" value="<?= $pz['id'] ?>" />
                          <input type="hidden" name="target_status" value="available" />
                          <button type="submit" class="btn btn-sm btn-outline-success w-100 py-1" style="font-size:0.75rem" title="Đổi nhanh về trạng thái Còn chỗ (50%)">
                            <i class="fa-solid fa-check me-1"></i>Còn chỗ
                          </button>
                        </form>

                        <form method="POST" action="index.php" class="d-inline flex-grow-1">
                          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
                          <input type="hidden" name="action" value="quick_parking_status" />
                          <input type="hidden" name="zone_id" value="<?= $pz['id'] ?>" />
                          <input type="hidden" name="target_status" value="full" />
                          <button type="submit" class="btn btn-sm btn-outline-danger w-100 py-1" style="font-size:0.75rem" title="Đổi nhanh về trạng thái Đã đầy (100%)">
                            <i class="fa-solid fa-ban me-1"></i>Báo đầy
                          </button>
                        </form>

                        <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="font-size:0.75rem"
                                onclick="openEditParkingModal(<?= $pz['id'] ?>, '<?= htmlspecialchars($pz['name'], ENT_QUOTES) ?>', <?= $occ ?>, <?= $cap ?>, '<?= htmlspecialchars($pz['status'], ENT_QUOTES) ?>', '<?= htmlspecialchars($pz['location_note'] ?? '', ENT_QUOTES) ?>')"
                                title="Chỉnh sửa chi tiết số lượng & sức chứa">
                          <i class="fa-solid fa-gear"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </main>

  <!-- ══════════════════════════════════════════════════════════
     MODALS
  ══════════════════════════════════════════════════════════ -->

  <!-- Modal Thêm Dịch Vụ Mới -->
  <div class="modal fade" id="modalAddService" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="create_service" />
          <div class="modal-header">
            <h5 class="modal-title fw-700">Thêm Dịch Vụ Mới</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Tên dịch vụ <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="Ví dụ: Cổng Đăng ký học phần" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Nhóm dịch vụ <span class="text-danger">*</span></label>
              <select name="group_id" class="form-select" required>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-600">Icon FontAwesome</label>
                <input type="text" name="icon" class="form-control" value="fa-server" placeholder="fa-envelope, fa-wifi..." />
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-600">Thứ tự sắp xếp</label>
                <input type="number" name="sort_order" class="form-control" value="1" />
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">URL Kiểm tra Tự động (Health Check)</label>
              <input type="url" name="check_url" class="form-control" placeholder="https://sv.utc.edu.vn" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Mô tả</label>
              <textarea name="description" class="form-control" rows="2" placeholder="Mô tả chức năng dịch vụ..."></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Trạng thái ban đầu</label>
              <select name="status" class="form-select">
                <option value="operational">🟢 Hoạt động tốt</option>
                <option value="degraded">🟡 Hiệu năng giảm</option>
                <option value="partial_outage">🟠 Gián đoạn một phần</option>
                <option value="major_outage">🔴 Sự cố nghiêm trọng</option>
                <option value="maintenance">🔵 Đang bảo trì</option>
              </select>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Thêm Dịch Vụ</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Thêm Nhóm Dịch Vụ Mới -->
  <div class="modal fade" id="modalAddGroup" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="create_group" />
          <div class="modal-header">
            <h5 class="modal-title fw-700">Tạo Nhóm Dịch Vụ Mới</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Tên nhóm <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="Ví dụ: Hạ tầng Mạng" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Mô tả</label>
              <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Thứ tự sắp xếp</label>
              <input type="number" name="sort_order" class="form-control" value="1" />
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Tạo Nhóm</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Khai Báo Sự Cố Mới -->
  <div class="modal fade" id="modalAddIncident" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="create_incident" />
          <div class="modal-header">
            <h5 class="modal-title fw-700 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Khai Báo Sự Cố Mới</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Tiêu đề sự cố <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required placeholder="Ví dụ: LMS (Moodle) đang gặp sự cố quá tải" />
            </div>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-600">Mức độ nghiêm trọng</label>
                <select name="severity" class="form-select">
                  <option value="minor">🟡 Minor (Nhẹ - Tự đặt thành Hiệu năng giảm)</option>
                  <option value="major">🟠 Major (Nghiêm trọng - Tự đặt thành Gián đoạn 1 phần)</option>
                  <option value="critical" selected>🔴 Critical (Khẩn cấp - Tự đặt thành Sự cố nghiêm trọng)</option>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-600">Tiến trình ban đầu</label>
                <select name="status" class="form-select">
                  <option value="investigating" selected>🔍 Investigating (Đang điều tra)</option>
                  <option value="identified">🎯 Identified (Đã xác định)</option>
                  <option value="monitoring">👀 Monitoring (Đang theo dõi)</option>
                </select>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Dịch vụ bị ảnh hưởng (Giữ phím Ctrl để chọn nhiều) <span class="text-danger">*</span></label>
              <select name="service_ids[]" class="form-select" multiple size="4" required>
                <?php foreach ($services as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['group_name'] ?? '') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Thông báo gửi người dùng <span class="text-danger">*</span></label>
              <textarea name="message" class="form-control" rows="3" required placeholder="Đội ngũ kỹ thuật đang kiểm tra xử lý..."></textarea>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-danger">Đăng Sự Cố & Tự Đổi Trạng Thái Dịch Vụ</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Lên Lịch Bảo Trì Mới -->
  <div class="modal fade" id="modalAddMaintenance" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="create_maintenance" />
          <div class="modal-header">
            <h5 class="modal-title fw-700" style="color:var(--teal-primary)"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Lên Lịch Bảo Trì Mới</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Tiêu đề kế hoạch <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required placeholder="Ví dụ: Nâng cấp Core Switch Khu B" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Mô tả công việc</label>
              <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-600">Thời gian bắt đầu <span class="text-danger">*</span></label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required />
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-600">Dự kiến hoàn thành <span class="text-danger">*</span></label>
                <input type="datetime-local" name="expected_end" class="form-control" required />
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Dịch vụ bị ảnh hưởng</label>
              <select name="service_ids[]" class="form-select" multiple size="4">
                <?php foreach ($services as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Lên Lịch Bảo Trì</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 1: Thêm Tài Khoản Người Dùng Mới -->
  <div class="modal fade" id="modalCreateUser" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="create_user" />
          <div class="modal-header">
            <h5 class="modal-title fw-700" style="color:var(--teal-primary)">
              <i class="fa-solid fa-user-plus me-2"></i>Thêm Tài Khoản Người Dùng Mới
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Tên đăng nhập / Mã SV <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required placeholder="Ví dụ: sv_nguyenvanan hoặc 211200123" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Họ và tên người dùng <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" required placeholder="Ví dụ: Nguyễn Văn An" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Địa chỉ Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required placeholder="sinhvien@student.utc.edu.vn" />
            </div>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-600 mb-0">Mật khẩu khởi tạo <span class="text-danger">*</span></label>
                <button type="button" class="btn btn-link text-decoration-none p-0 small" style="color:var(--teal-primary);font-size:0.75rem" onclick="document.getElementById('inputCreateUserPass').value = generateRandomPassword();">
                  <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Tạo ngẫu nhiên
                </button>
              </div>
              <input type="text" id="inputCreateUserPass" name="password" class="form-control" required placeholder="Tối thiểu 6 ký tự" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Phân quyền vai trò <span class="text-danger">*</span></label>
              <select name="role" class="form-select" required>
                <option value="student" selected>Sinh viên (student) - Chỉ xem lịch dịch vụ</option>
                <option value="editor">Biên tập viên (editor) - Vận hành sự cố & bảo trì</option>
                <option value="admin">Quản trị viên (admin) - Toàn quyền hệ thống</option>
              </select>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
              <i class="fa-solid fa-check me-1"></i>Tạo Tài Khoản
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 2: Cấp / Đổi Mật Khẩu Trực Tiếp Cho Tài Khoản -->
  <div class="modal fade" id="modalResetUserPassword" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="reset_user_password" />
          <input type="hidden" id="resetPwdUserId" name="user_id" value="" />
          <div class="modal-header">
            <h5 class="modal-title fw-700" style="color:var(--teal-primary)">
              <i class="fa-solid fa-key me-2"></i>Cấp / Đổi Mật Khẩu Mới
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="p-2 mb-3 bg-light rounded border small">
              Đang đổi mật khẩu cho tài khoản: <strong id="resetPwdUsername" class="text-dark"></strong>
            </div>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-600 mb-0">Mật khẩu mới <span class="text-danger">*</span></label>
                <button type="button" class="btn btn-link text-decoration-none p-0 small" style="color:var(--teal-primary);font-size:0.75rem" onclick="document.getElementById('resetPwdInput').value = generateRandomPassword();">
                  <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Tạo mật khẩu ngẫu nhiên
                </button>
              </div>
              <input type="text" id="resetPwdInput" name="new_password" class="form-control font-monospace" required placeholder="Nhập hoặc tạo ngẫu nhiên mật khẩu mới" />
              <div class="form-text small">Mật khẩu mới sẽ được mã hóa an toàn và cập nhật ngay lập tức. Hãy copy mật khẩu này gửi cho sinh viên.</div>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
              <i class="fa-solid fa-check me-1"></i>Xác Nhận Cấp Mật Khẩu
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 3: Đổi Vai Trò Phân Quyền -->
  <div class="modal fade" id="modalChangeUserRole" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="update_user_role" />
          <input type="hidden" id="changeRoleUserId" name="user_id" value="" />
          <div class="modal-header">
            <h5 class="modal-title fw-700" style="color:var(--teal-primary)">
              <i class="fa-solid fa-user-gear me-2"></i>Phân Quyền Vai Trò
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="p-2 mb-3 bg-light rounded border small">
              Đang phân quyền cho tài khoản: <strong id="changeRoleUsername" class="text-dark"></strong>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Chọn vai trò mới</label>
              <select id="changeRoleSelect" name="role" class="form-select">
                <option value="student">Sinh viên (student) - Chỉ xem lịch dịch vụ ngoài trang chủ</option>
                <option value="editor">Biên tập viên (editor) - Cập nhật sự cố, lịch bảo trì</option>
                <option value="admin">Quản trị viên (admin) - Toàn quyền quản trị hệ thống</option>
              </select>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-primary" style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
              <i class="fa-solid fa-check me-1"></i>Lưu Vai Trò
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 4: Cấp Mật Khẩu Mới Cho Sinh Viên (Từ Yêu Cầu Quên MK) -->
  <div class="modal fade" id="modalApproveResetRequest" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="approve_reset_request" />
          <input type="hidden" id="appReqId" name="request_id" value="" />
          <div class="modal-header">
            <h5 class="modal-title fw-700" style="color:var(--teal-primary)">
              <i class="fa-solid fa-key me-2"></i>Cung Cấp Mật Khẩu Cho Sinh Viên
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info py-2 px-3 small mb-3">
              Sinh viên đã nhập đúng thông tin tài khoản và đang yêu cầu Admin cấp mật khẩu mới.
            </div>

            <div class="p-3 bg-light rounded border mb-3 small">
              <div class="row g-2">
                <div class="col-sm-6">
                  <span class="text-muted">Sinh viên:</span> <strong id="appReqUsername" class="text-dark"></strong>
                </div>
                <div class="col-sm-6">
                  <span class="text-muted">Email:</span> <strong id="appReqEmail" class="text-dark"></strong>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-600 mb-0">Mật khẩu cấp mới cho sinh viên <span class="text-danger">*</span></label>
                <button type="button" class="btn btn-link text-decoration-none p-0 small" style="color:var(--teal-primary);font-size:0.75rem" onclick="document.getElementById('appReqPassword').value = generateRandomPassword();">
                  <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Tạo mật khẩu tự động
                </button>
              </div>
              <input type="text" id="appReqPassword" name="new_password" class="form-control font-monospace" required placeholder="Ví dụ: Utc@2026..." />
              <div class="form-text small">Mật khẩu mới này sẽ được lưu để sinh viên đăng nhập. Khi duyệt xong, mật khẩu sẽ hiển thị trên bảng để Admin copy gửi sinh viên.</div>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-success">
              <i class="fa-solid fa-check-double me-1"></i>Phê Duyệt &amp; Cấp Mật Khẩu
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Cập Nhật Sự Cố Hàng Đợi FIFO -->
  <div class="modal fade" id="modalUpdateFifoIncident" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="update_fifo_incident" />
          <input type="hidden" id="fifoModalReportId" name="report_id" value="" />
          <div class="modal-header">
            <h5 class="modal-title fw-700 text-teal">
              <i class="fa-solid fa-list-check me-2"></i>Cập Nhật Tiến Độ Sự Cố (FIFO)
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Sự cố đang xử lý:</label>
              <div id="fifoModalTitle" class="fw-700 text-dark p-2 rounded bg-light border"></div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Trạng thái xử lý <span class="text-danger">*</span></label>
              <select id="fifoModalStatus" name="status" class="form-select" required>
                <option value="pending">🟡 Chờ xử lý (Pending in FIFO queue)</option>
                <option value="in_progress">🔵 Đang xử lý (In Progress)</option>
                <option value="resolved">🟢 Đã hoàn thành (Resolved)</option>
                <option value="rejected">⚪ Từ chối / Không hợp lệ (Rejected)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Ghi chú xử lý của Quản trị viên / Đội IT:</label>
              <textarea id="fifoModalAdminNote" name="admin_note" class="form-control" rows="3" placeholder="Nhập nguyên nhân, biện pháp xử lý hoặc thông báo gửi cho sinh viên..."></textarea>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            <button type="submit" class="btn btn-success">
              <i class="fa-solid fa-floppy-disk me-1"></i>Lưu Cập Nhật
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Chỉnh Sửa Sức Chứa Bãi Xe Sân Trường -->
  <div class="modal fade" id="modalEditParkingZone" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <form method="POST" action="index.php">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
          <input type="hidden" name="action" value="update_parking_zone" />
          <input type="hidden" id="editPzId" name="zone_id" value="" />
          <div class="modal-header">
            <h5 class="modal-title fw-700 text-success">
              <i class="fa-solid fa-square-parking me-2"></i>Cài Đặt Sức Chứa &amp; Trạng Thái Bãi Xe
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-600">Khu vực bãi xe:</label>
              <div id="editPzName" class="fw-700 text-dark p-2 rounded bg-light border"></div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label small fw-600">Số xe đã đỗ (Hiện tại) <span class="text-danger">*</span></label>
                <input type="number" id="editPzOccupied" name="occupied_count" class="form-control" min="0" required />
              </div>
              <div class="col-6">
                <label class="form-label small fw-600">Tổng sức chứa <span class="text-danger">*</span></label>
                <input type="number" id="editPzCapacity" name="total_capacity" class="form-control" min="1" required />
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Chế độ trạng thái</label>
              <select id="editPzStatus" name="status" class="form-select">
                <option value="auto">⚡ Tự động tính theo tỷ lệ % (Khuyên dùng)</option>
                <option value="available">🟢 Còn trống (&lt; 80%)</option>
                <option value="crowded">🟡 Sắp đầy (80% - 95%)</option>
                <option value="full">🔴 Đã đầy / Ngưng nhận xe (&gt;= 95%)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Vị trí / Ghi chú điều phối</label>
              <input type="text" id="editPzNote" name="location_note" class="form-control" placeholder="Ghi chú vị trí cổng, hướng phân luồng..." />
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            <button type="submit" class="btn btn-success">
              <i class="fa-solid fa-check me-1"></i>Lưu Thay Đổi
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap 5 Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Chart.js Config & Modal Helpers -->
  <script>
  function generateRandomPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    let pwd = 'Utc@';
    for (let i = 0; i < 6; i++) {
      pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return pwd;
  }

  function openResetPasswordModal(userId, username, fullName) {
    document.getElementById('resetPwdUserId').value = userId;
    document.getElementById('resetPwdUsername').textContent = username + (fullName ? ' (' + fullName + ')' : '');
    document.getElementById('resetPwdInput').value = generateRandomPassword();
    new bootstrap.Modal(document.getElementById('modalResetUserPassword')).show();
  }

  function openChangeRoleModal(userId, username, currentRole) {
    document.getElementById('changeRoleUserId').value = userId;
    document.getElementById('changeRoleUsername').textContent = username;
    document.getElementById('changeRoleSelect').value = currentRole;
    new bootstrap.Modal(document.getElementById('modalChangeUserRole')).show();
  }

  function openApproveResetModal(reqId, userId, username, fullName, email) {
    document.getElementById('appReqId').value = reqId;
    document.getElementById('appReqUsername').textContent = username + (fullName ? ' (' + fullName + ')' : '');
    document.getElementById('appReqEmail').textContent = email;
    document.getElementById('appReqPassword').value = generateRandomPassword();
    new bootstrap.Modal(document.getElementById('modalApproveResetRequest')).show();
  }

  document.addEventListener('DOMContentLoaded', () => {
    const labels = <?= json_encode(array_column($analyticsData, 'name')) ?>;
    const uptimes = <?= json_encode(array_column($analyticsData, 'uptime_pct')) ?>;

    const ctxUptime = document.getElementById('uptimeChart');
    if (ctxUptime) {
      new Chart(ctxUptime, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Tỷ lệ Uptime (%)',
            data: uptimes,
            backgroundColor: uptimes.map(v => v >= 99.5 ? '#007a78' : (v >= 98.0 ? '#f59e0b' : '#ef4444')),
            borderRadius: 6,
            barThickness: 26,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#1e293b',
              padding: 10,
              cornerRadius: 8,
              callbacks: {
                label: function(context) {
                  return ' Độ sẵn sàng: ' + context.parsed.y + '% (Chu kỳ 90 ngày)';
                }
              }
            }
          },
          scales: {
            y: {
              min: 98.0,
              max: 100.2,
              ticks: {
                callback: function(val) { return val + '%'; },
                font: { size: 11 }
              },
              grid: {
                color: '#f1f5f9'
              }
            },
            x: {
              grid: { display: false },
              ticks: {
                font: { size: 11, weight: '600' }
              }
            }
          }
        }
      });
    }

    const ctxPie = document.getElementById('statusPieChart');
    if (ctxPie) {
      new Chart(ctxPie, {
        type: 'doughnut',
        data: {
          labels: ['Uptime 100%', 'Sự Cố Minor', 'Sự Cố Major', 'Sự Cố Critical'],
          datasets: [{
            data: [
              <?= $perfectServiceCount ?>,
              <?= $sevMinorCount ?>,
              <?= $sevMajorCount ?>,
              <?= $sevCritCount ?>
            ],
            backgroundColor: ['#007a78', '#38bdf8', '#f59e0b', '#ef4444'],
            borderWidth: 2,
            borderColor: '#ffffff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '72%',
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#1e293b',
              padding: 10,
              cornerRadius: 8
            }
          }
        }
      });
    }

    const ctxTrend = document.getElementById('monthlyTrendChart');
    if (ctxTrend) {
      new Chart(ctxTrend, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_column($monthlyTrends, 'label')) ?>,
          datasets: [
            {
              label: 'Sự cố đột xuất',
              data: <?= json_encode(array_column($monthlyTrends, 'incidents')) ?>,
              backgroundColor: '#f87171',
              borderRadius: 4,
              barPercentage: 0.5
            },
            {
              label: 'Bảo trì nâng cấp',
              data: <?= json_encode(array_column($monthlyTrends, 'maintenances')) ?>,
              backgroundColor: '#007a78',
              borderRadius: 4,
              barPercentage: 0.5
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              ticks: { stepSize: 1, precision: 0 },
              grid: { color: '#f1f5f9' }
            },
            x: {
              grid: { display: false }
            }
          },
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              backgroundColor: '#1e293b',
              padding: 10,
              cornerRadius: 8
            }
          }
        }
      });
    }
  });

  function exportSlaReportCsv() {
    const rawData = <?= json_encode($analyticsData) ?>;
    let csvContent = '\uFEFF'; // UTF-8 BOM cho Excel
    csvContent += "STT,Mã Dịch Vụ,Tên Dịch Vụ,Phân Nhóm,Trạng Thái Hiện Tại,Tỷ Lệ Uptime 90D (%),Số Sự Cố (90D),Tổng Downtime (Phút),MTTR (Phút),Đánh Giá SLA\n";
    
    rawData.forEach((item, index) => {
      let slaText = item.uptime_pct >= 99.5 ? "Đạt chuẩn SLA 99.5%" : (item.uptime_pct >= 98.0 ? "Sát ngưỡng SLA" : "Vi phạm SLA");
      let row = [
        index + 1,
        'SVC-' + item.id,
        '"' + (item.name || '').replace(/"/g, '""') + '"',
        '"' + (item.group || 'Chung').replace(/"/g, '""') + '"',
        item.status,
        item.uptime_pct + '%',
        item.incident_count,
        item.downtime_min,
        item.mttr_min,
        '"' + slaText + '"'
      ];
      csvContent += row.join(",") + "\n";
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "Bao_Cao_SLA_UTC_STATUS_90_Ngay_" + new Date().toISOString().slice(0, 10) + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  // ── Modal Helpers for FIFO Incidents & Parking ────────────────
  function openUpdateFifoModal(reportId, title, status, adminNote) {
    document.getElementById('fifoModalReportId').value = reportId;
    document.getElementById('fifoModalTitle').textContent = '#' + reportId + ' - ' + title;
    document.getElementById('fifoModalStatus').value = status;
    document.getElementById('fifoModalAdminNote').value = adminNote || '';
    new bootstrap.Modal(document.getElementById('modalUpdateFifoIncident')).show();
  }

  function openEditParkingModal(zoneId, name, occupied, capacity, status, note) {
    document.getElementById('editPzId').value = zoneId;
    document.getElementById('editPzName').textContent = name;
    document.getElementById('editPzOccupied').value = occupied;
    document.getElementById('editPzCapacity').value = capacity;
    document.getElementById('editPzStatus').value = status || 'auto';
    document.getElementById('editPzNote').value = note || '';
    new bootstrap.Modal(document.getElementById('modalEditParkingZone')).show();
  }

  function filterFifoTable() {
    const dateVal = document.getElementById('fifoDateFilter')?.value;
    const statusVal = document.getElementById('fifoStatusFilter')?.value;
    const searchVal = document.getElementById('fifoSearchInput')?.value.trim().toLowerCase();

    const rows = document.querySelectorAll('#fifoTable tbody tr.fifo-row');
    rows.forEach(r => {
      const rDate = r.getAttribute('data-date');
      const rStatus = r.getAttribute('data-status');
      const rSearch = r.getAttribute('data-search') || '';

      let show = true;
      if (dateVal && rDate !== dateVal) show = false;
      if (statusVal && statusVal !== 'all' && rStatus !== statusVal) show = false;
      if (searchVal && !rSearch.includes(searchVal)) show = false;

      r.style.display = show ? '' : 'none';
    });
  }

  function resetFifoFilters() {
    const d = document.getElementById('fifoDateFilter');
    const s = document.getElementById('fifoStatusFilter');
    const q = document.getElementById('fifoSearchInput');
    if (d) d.value = '';
    if (s) s.value = 'all';
    if (q) q.value = '';
    filterFifoTable();
  }

  // ── Theme Switcher in Admin Panel ─────────────────────────────
  (function initAdminTheme() {
    const curTheme = localStorage.getItem('utc_theme') || 'light';
    applyUtcTheme(curTheme);
  })();
  </script>
</body>
</html>
