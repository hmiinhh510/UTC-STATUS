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
            'run_health_check'
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

/**
 * Ghi nhận lịch sử thay đổi trạng thái dịch vụ
 */
function recordStatusChange(PDO $pdo, int $serviceId, string $oldStatus, string $newStatus, int $changedBy, string $reason): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO service_status_history (service_id, old_status, new_status, changed_by, reason, created_at)
             VALUES (:sid, :old, :new, :by, :reason, NOW())'
        );
        $stmt->execute([
            ':sid'    => $serviceId,
            ':old'    => $oldStatus,
            ':new'    => $newStatus,
            ':by'     => $changedBy,
            ':reason' => $reason
        ]);
    } catch (Throwable $e) {
        error_log('[StatusHistory Error] ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                        $stmt = $pdo->prepare('UPDATE services SET status = :status, updated_at = NOW() WHERE id = :id');
                        $stmt->execute([':status' => $newStatus, ':id' => $serviceId]);

                        recordStatusChange($pdo, $serviceId, $oldStatus, $newStatus, $adminId, "Quản trị viên đổi trạng thái trực tiếp trên Dashboard");
                        logAction($pdo, $adminId, $adminName, 'quick_status', 'service', $serviceId, "Đổi trạng thái dịch vụ '{$svc['name']}' từ '{$oldStatus}' sang '{$newStatus}'");

                        setFlash('success', "Đã đổi trạng thái dịch vụ \"{$svc['name']}\" sang [{$newStatus}] thành công!");
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
                $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
                $checkUrl    = trim($_POST['check_url'] ?? '');

                if ($name === '' || $groupId <= 0) {
                    setFlash('danger', 'Tên dịch vụ và nhóm phân loại không được để trống.');
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
                $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
                $checkUrl    = trim($_POST['check_url'] ?? '');

                if ($serviceId <= 0 || $name === '' || $groupId <= 0) {
                    setFlash('danger', 'Thông tin dịch vụ không hợp lệ.');
                } else {
                    $curStmt = $pdo->prepare('SELECT status FROM services WHERE id = :id');
                    $curStmt->execute([':id' => $serviceId]);
                    $oldStatus = $curStmt->fetchColumn() ?: 'operational';

                    $stmt = $pdo->prepare(
                        'UPDATE services
                         SET group_id = :group_id, name = :name, description = :description,
                             icon = :icon, status = :status, sort_order = :sort_order,
                             check_url = :check_url, updated_at = NOW()
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':group_id'    => $groupId,
                        ':name'        => $name,
                        ':description' => $description,
                        ':icon'        => $icon ?: 'fa-server',
                        ':status'      => $status,
                        ':sort_order'  => $sortOrder,
                        ':check_url'   => $checkUrl ?: null,
                        ':id'          => $serviceId,
                    ]);

                    if ($oldStatus !== $status) {
                        recordStatusChange($pdo, $serviceId, $oldStatus, $status, $adminId, "Chỉnh sửa dịch vụ");
                    }
                    logAction($pdo, $adminId, $adminName, 'update_service', 'service', $serviceId, "Cập nhật thông tin dịch vụ: {$name}");
                    setFlash('success', "Đã cập nhật dịch vụ #{$serviceId} thành công!");
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

                if ($title === '' || empty($serviceIds) || $message === '') {
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

                    // Xác định trạng thái dịch vụ theo mức độ nghiêm trọng
                    $targetSvcStatus = match ($severity) {
                        'critical' => 'major_outage',
                        'major'    => 'partial_outage',
                        default    => 'degraded',
                    };

                    $stmtRel    = $pdo->prepare('INSERT INTO incident_services (incident_id, service_id) VALUES (:inc_id, :svc_id)');
                    $stmtSvcCur = $pdo->prepare('SELECT status FROM services WHERE id = :svc_id');
                    $stmtSvcUpd = $pdo->prepare('UPDATE services SET status = :st, updated_at = NOW() WHERE id = :svc_id');

                    foreach ($serviceIds as $sid) {
                        $sidInt = (int) $sid;
                        if ($sidInt > 0) {
                            $stmtRel->execute([':inc_id' => $incidentId, ':svc_id' => $sidInt]);
                            $stmtSvcCur->execute([':svc_id' => $sidInt]);
                            $oldSt = $stmtSvcCur->fetchColumn() ?: 'operational';

                            $stmtSvcUpd->execute([':st' => $targetSvcStatus, ':svc_id' => $sidInt]);
                            recordStatusChange($pdo, $sidInt, $oldSt, $targetSvcStatus, $adminId, "Sự cố #{$incidentId}: {$title}");
                        }
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

                if ($incidentId <= 0 || $message === '') {
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
                             resolved_at = COALESCE(:resolved_at, resolved_at),
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $stmtInc->execute([
                        ':status'      => $status,
                        ':resolved_at' => $resolvedAt,
                        ':id'          => $incidentId,
                    ]);

                    // Nếu đã giải quyết -> Tự động đưa các dịch vụ về operational
                    if ($status === 'resolved') {
                        $svcStmt = $pdo->prepare('SELECT service_id FROM incident_services WHERE incident_id = :inc_id');
                        $svcStmt->execute([':inc_id' => $incidentId]);
                        $affServices = $svcStmt->fetchAll(PDO::FETCH_COLUMN);

                        $stmtRevert = $pdo->prepare('UPDATE services SET status = "operational", updated_at = NOW() WHERE id = :id');
                        foreach ($affServices as $affSid) {
                            $stmtRevert->execute([':id' => $affSid]);
                            recordStatusChange($pdo, (int)$affSid, 'outage', 'operational', $adminId, "Sự cố #{$incidentId} đã giải quyết xong");
                        }
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'update_incident', 'incident', $incidentId, "Cập nhật tiến độ sự cố #{$incidentId} sang: {$status}");
                    setFlash('success', "Đã thêm cập nhật tiến độ sự cố #{$incidentId}" . ($status === 'resolved' ? ' và tự động khôi phục dịch vụ về Hoạt động tốt!' : '!'));
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
                    $affServices = $svcStmt->fetchAll(PDO::FETCH_COLUMN);

                    $stmtRevert = $pdo->prepare('UPDATE services SET status = "operational", updated_at = NOW() WHERE id = :id');
                    foreach ($affServices as $affSid) {
                        $stmtRevert->execute([':id' => $affSid]);
                        recordStatusChange($pdo, (int)$affSid, 'outage', 'operational', $adminId, "Khôi phục khi đóng sự cố #{$incidentId}");
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'resolve_incident', 'incident', $incidentId, "Đóng nhanh sự cố #{$incidentId}");
                    setFlash('success', "Đã đóng sự cố #{$incidentId} và đưa toàn bộ dịch vụ về Hoạt động tốt!");
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

                    $stmt = $pdo->prepare('UPDATE maintenances SET status = :st, updated_at = NOW() WHERE id = :id');
                    $stmt->execute([':st' => $newStatus, ':id' => $maintId]);

                    if ($newStatus === 'in_progress') {
                        $stmtSvc = $pdo->prepare(
                            'UPDATE services SET status = "maintenance", updated_at = NOW()
                             WHERE id IN (SELECT service_id FROM maintenance_services WHERE maintenance_id = :m_id)'
                        );
                        $stmtSvc->execute([':m_id' => $maintId]);
                    } elseif ($newStatus === 'completed' || $newStatus === 'cancelled') {
                        $stmtSvc = $pdo->prepare(
                            'UPDATE services SET status = "operational", updated_at = NOW()
                             WHERE id IN (SELECT service_id FROM maintenance_services WHERE maintenance_id = :m_id)'
                        );
                        $stmtSvc->execute([':m_id' => $maintId]);
                    }

                    $pdo->commit();
                    logAction($pdo, $adminId, $adminName, 'update_maintenance_status', 'maintenance', $maintId, "Đổi trạng thái bảo trì #{$maintId} thành '{$newStatus}'");
                    setFlash('success', "Đã cập nhật trạng thái bảo trì #{$maintId} sang [{$newStatus}]!");
                }
                break;

            // ── CHẠY HEALTH CHECK TỰ ĐỘNG ────────────────────
            case 'run_health_check':
                // Gọi API kiểm tra sức khỏe tự động
                $hcUrl = "http://127.0.0.1/status_page/api/health_check.php?auto_update=1";
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $hcResult = @file_get_contents($hcUrl, false, $ctx);
                $hcJson   = $hcResult ? json_decode($hcResult, true) : null;

                $checked = $hcJson['checked_count'] ?? 0;
                logAction($pdo, $adminId, $adminName, 'run_health_check', 'system', null, "Chạy Health Check tự động: kiểm tra {$checked} dịch vụ");
                setFlash('success', "Đã hoàn thành kiểm tra sức khỏe tự động ({$checked} dịch vụ). Hệ thống đã tự động ghi nhận độ trễ và trạng thái!");
                break;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Admin Index Action Error] ' . $e->getMessage());
        setFlash('danger', 'Lỗi hệ thống: ' . $e->getMessage());
    }

    header('Location: index.php');
    exit;
}

// ── 5. Fetch Data for Display ────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// System Overview Counts
$totalServices = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
$operationalCount = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE status = "operational"')->fetchColumn();
$outageCount = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE status IN ("degraded", "partial_outage", "major_outage")')->fetchColumn();
$maintenanceCount = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE status = "maintenance"')->fetchColumn();
$activeIncidentsCount = (int) $pdo->query('SELECT COUNT(*) FROM incidents WHERE status != "resolved"')->fetchColumn();
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
$subscribers = $pdo->query('SELECT * FROM subscriptions ORDER BY created_at DESC LIMIT 20')->fetchAll();

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

    $analyticsData[] = [
        'id'             => $sid,
        'name'           => $svc['name'],
        'group'          => $svc['group_name'],
        'status'         => $svc['status'],
        'uptime_pct'     => round($avgUptime, 2),
        'incident_count' => (int) ($dtStats['inc_count'] ?? 0),
        'downtime_min'   => (int) ($dtStats['downtime_min'] ?? 0),
    ];
}
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
  <link rel="stylesheet" href="../assets/css/style.css?v=2.3" />
  <style>
    .admin-nav-bar {
      background: #ffffff;
      border-bottom: 1px solid var(--border-color);
    }
    .kpi-stat-box {
      background: #ffffff;
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
            <div class="fs-4 fw-800"><?= $totalSubscribers ?></div>
            <div class="small text-muted text-uppercase">Người Đăng Ký</div>
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
          <i class="fa-solid fa-envelope me-1"></i>Người Đăng ký
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
           TAB 5: BÁO CÁO & BIỂU ĐỒ UPTIME
      ======================================================== -->
      <div class="tab-pane fade" id="tab-reports">
        <div class="row g-4 mb-4">
          <div class="col-lg-8">
            <div class="utc-card p-4 h-100">
              <h6 class="fw-700 mb-3"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Tỷ Lệ Uptime (%) Theo Dịch Vụ (90 Ngày)</h6>
              <div style="height: 300px;"><canvas id="uptimeChart"></canvas></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="utc-card p-4 h-100">
              <h6 class="fw-700 mb-3"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Phân Bổ Trạng Thái Dịch Vụ</h6>
              <div style="height: 300px; display:flex; align-items:center; justify-content:center;">
                <canvas id="statusPieChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div class="utc-card p-4">
          <h6 class="fw-700 mb-3">Thống Kê Chi Tiết SLA & Downtime</h6>
          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr><th>Dịch Vụ</th><th>Nhóm</th><th>Uptime TB</th><th>Sự Cố</th><th>Downtime</th><th>Đánh Giá SLA</th></tr>
              </thead>
              <tbody>
                <?php foreach ($analyticsData as $row): ?>
                  <tr>
                    <td class="fw-700"><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['group'] ?? 'N/A') ?></td>
                    <td><strong class="<?= $row['uptime_pct']>=99?'text-success':($row['uptime_pct']>=95?'text-warning':'text-danger') ?>"><?= $row['uptime_pct'] ?>%</strong></td>
                    <td><?= $row['incident_count'] ?></td>
                    <td><?= $row['downtime_min'] ?> phút</td>
                    <td>
                      <?php if ($row['uptime_pct'] >= 99.5): ?>
                        <span class="badge bg-success">Đạt SLA 99.5%</span>
                      <?php elseif ($row['uptime_pct'] >= 98.0): ?>
                        <span class="badge bg-warning text-dark">Cần Cải Thiện</span>
                      <?php else: ?>
                        <span class="badge bg-danger">Vi Phạm SLA</span>
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
           TAB 7: NGƯỜI ĐĂNG KÝ (SUBSCRIPTIONS)
      ======================================================== -->
      <div class="tab-pane fade" id="tab-subscribers">
        <div class="utc-card p-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-800 mb-0" style="color:var(--teal-primary)">Danh Sách Đăng Ký Nhận Cảnh Báo</h5>
              <p class="text-muted small mb-0">Quản lý các email và webhook slack đã đăng ký nhận thông báo sự cố.</p>
            </div>
          </div>

          <div class="table-responsive admin-table border">
            <table class="table align-middle mb-0">
              <thead>
                <tr><th>ID</th><th>Hình Thức</th><th>Địa Chỉ Nhận Thông Báo</th><th>Trạng Thái Xác Thực</th><th>Thời Gian Đăng Ký</th><th>Link Kích Hoạt / Hủy</th></tr>
              </thead>
              <tbody>
                <?php if (empty($subscribers)): ?>
                  <tr><td colspan="6" class="text-center py-4 text-muted">Chưa có ai đăng ký.</td></tr>
                <?php else: foreach ($subscribers as $sub): ?>
                  <tr>
                    <td><code>#<?= $sub['id'] ?></code></td>
                    <td><span class="badge bg-light text-dark border"><?= strtoupper($sub['type']) ?></span></td>
                    <td><strong><?= htmlspecialchars($sub['endpoint']) ?></strong></td>
                    <td>
                      <?php if ((int)$sub['verified'] === 1): ?>
                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Đã Xác Thực</span>
                      <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Chờ Xác Minh</span>
                      <?php endif; ?>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($sub['created_at'])) ?></td>
                    <td>
                      <a href="../verify.php?token=<?= htmlspecialchars($sub['token']) ?>" target="_blank" class="btn btn-sm btn-outline-info py-0" style="font-size:0.75rem" title="Mở liên kết xác minh">Kích hoạt</a>
                      <a href="../unsubscribe.php?token=<?= htmlspecialchars($sub['token']) ?>" target="_blank" class="btn btn-sm btn-outline-danger py-0 ms-1" style="font-size:0.75rem" title="Mở liên kết hủy">Hủy</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
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

  <!-- Bootstrap 5 Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Chart.js Config -->
  <script>
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
            backgroundColor: uptimes.map(v => v >= 99 ? '#22c55e' : (v >= 95 ? '#eab308' : '#ef4444')),
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { min: 80, max: 100 }
          }
        }
      });
    }

    const ctxPie = document.getElementById('statusPieChart');
    if (ctxPie) {
      new Chart(ctxPie, {
        type: 'doughnut',
        data: {
          labels: ['Hoạt động tốt', 'Sự cố / Giảm sút', 'Đang bảo trì'],
          datasets: [{
            data: [<?= $operationalCount ?>, <?= $outageCount ?>, <?= $maintenanceCount ?>],
            backgroundColor: ['#22c55e', '#ef4444', '#f59e0b']
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false
        }
      });
    }
  });
  </script>
</body>
</html>
