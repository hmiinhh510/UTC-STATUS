<?php
declare(strict_types=1);

/**
 * UTC STATUS - FIFO Incident Priority Queue & Schedule API
 * GET  /api/incident_queue.php - Lấy danh sách sự cố theo cơ chế FIFO (Ưu tiên báo trước xử lý trước)
 * POST /api/incident_queue.php - Admin/Editor cập nhật trạng thái sự cố và ghi chú giải quyết
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $pdo = Database::getInstance();

        $dateFilter = trim($_GET['date'] ?? '');
        $statusFilter = trim($_GET['status'] ?? 'all');

        $whereClauses = [];
        $params = [];

        if ($dateFilter !== '') {
            $whereClauses[] = 'DATE(ir.created_at) = :filter_date';
            $params[':filter_date'] = $dateFilter;
        }

        if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'in_progress', 'resolved', 'rejected'], true)) {
            $whereClauses[] = 'ir.status = :filter_status';
            $params[':filter_status'] = $statusFilter;
        }

        $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Cơ chế FIFO cốt lõi: Báo cáo chưa giải quyết xếp lên đầu, sự cố gửi trước (created_at ASC) ưu tiên trước
        $sql = "
            SELECT 
                ir.*,
                COALESCE(s.name, 'Hệ thống chung') AS service_name,
                COALESCE(s.icon, 'fa-server') AS service_icon
            FROM incident_reports ir
            LEFT JOIN services s ON ir.service_id = s.id
            {$whereSql}
            ORDER BY 
                CASE WHEN ir.status NOT IN ('resolved', 'rejected') THEN 0 ELSE 1 END ASC,
                ir.created_at ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fifoRank = 1;
        $items = [];
        $counts = [
            'total'       => count($rows),
            'pending'     => 0,
            'in_progress' => 0,
            'resolved'    => 0,
        ];

        $now = time();

        foreach ($rows as $row) {
            $st = $row['status'];
            $isUnresolved = ($st !== 'resolved' && $st !== 'rejected');

            if ($st === 'pending') {
                $counts['pending']++;
            } elseif ($st === 'in_progress' || $st === 'triaged' || $st === 'confirmed') {
                $counts['in_progress']++;
            } elseif ($st === 'resolved') {
                $counts['resolved']++;
            }

            // Gắn số thứ tự ưu tiên FIFO cho các việc đang tồn đọng
            $rankDisplay = $isUnresolved ? ('#' . $fifoRank++) : '-';

            // Nhãn trạng thái tiếng Việt
            $statusLabel = 'Chờ xử lý';
            $statusBadgeClass = 'bg-warning text-dark';
            if ($st === 'in_progress' || $st === 'triaged' || $st === 'confirmed') {
                $statusLabel = 'Đang xử lý';
                $statusBadgeClass = 'bg-info text-dark';
            } elseif ($st === 'resolved') {
                $statusLabel = 'Đã hoàn thành';
                $statusBadgeClass = 'bg-success';
            } elseif ($st === 'rejected') {
                $statusLabel = 'Đã từ chối';
                $statusBadgeClass = 'bg-secondary';
            }

            // Mức độ ưu tiên
            $severityLabel = 'Trung bình';
            $severityBadgeClass = 'bg-info-subtle text-info-emphasis';
            switch ($row['severity']) {
                case 'low':
                    $severityLabel = 'Thấp';
                    $severityBadgeClass = 'bg-secondary-subtle text-secondary-emphasis';
                    break;
                case 'medium':
                    $severityLabel = 'Trung bình';
                    $severityBadgeClass = 'bg-primary-subtle text-primary-emphasis';
                    break;
                case 'high':
                    $severityLabel = 'Cao';
                    $severityBadgeClass = 'bg-warning-subtle text-warning-emphasis';
                    break;
                case 'critical':
                    $severityLabel = 'Nghiêm trọng';
                    $severityBadgeClass = 'bg-danger-subtle text-danger-emphasis';
                    break;
            }

            $createdAtTs = strtotime($row['created_at']);
            $diffMins = max(0, (int) round(($now - $createdAtTs) / 60));
            $waitTimeStr = $diffMins < 60 ? "{$diffMins} phút trước" : (int) floor($diffMins / 60) . 'h ' . ($diffMins % 60) . 'm trước';

            $items[] = [
                'id'                   => (int) $row['id'],
                'fifo_rank'            => $rankDisplay,
                'is_unresolved'        => $isUnresolved,
                'student_code'         => (string) ($row['student_code'] ?? 'N/A'),
                'reporter_name'        => (string) $row['reporter_name'],
                'reporter_email'       => (string) $row['reporter_email'],
                'service_id'           => $row['service_id'] ? (int) $row['service_id'] : null,
                'service_name'         => (string) $row['service_name'],
                'service_icon'         => (string) $row['service_icon'],
                'location'             => (string) ($row['location'] ?? 'Khuôn viên trường'),
                'title'                => (string) $row['title'],
                'description'          => (string) $row['description'],
                'severity'             => (string) $row['severity'],
                'severity_label'       => $severityLabel,
                'severity_badge_class' => $severityBadgeClass,
                'status'               => (string) $st,
                'status_label'         => $statusLabel,
                'status_badge_class'   => $statusBadgeClass,
                'admin_note'           => (string) ($row['admin_note'] ?? ''),
                'created_at'           => (string) $row['created_at'],
                'formatted_time'       => date('H:i:s - d/m/Y', $createdAtTs),
                'created_date'         => date('Y-m-d', $createdAtTs),
                'wait_time_text'       => $waitTimeStr
            ];
        }

        echo json_encode([
            'success'     => true,
            'summary'     => $counts,
            'filter_date' => $dateFilter,
            'incidents'   => $items
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi máy chủ khi lấy danh sách sự cố FIFO: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($method === 'POST') {
    $userRole = $_SESSION['admin_role'] ?? $_SESSION['user_role'] ?? '';
    $userId   = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $userName = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Quản trị viên';

    if (!in_array($userRole, ['admin', 'editor'], true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Chỉ Quản trị viên (Admin) hoặc Biên tập viên (Editor) mới có quyền xử lý hàng đợi sự cố.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $reportId  = (int) ($data['report_id'] ?? 0);
    $newStatus = trim($data['status'] ?? '');
    $adminNote = trim($data['admin_note'] ?? '');

    $allowedStatuses = ['pending', 'in_progress', 'resolved', 'rejected'];
    if ($reportId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => 'Mã sự cố hoặc trạng thái cập nhật không hợp lệ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = Database::getInstance();
        $findStmt = $pdo->prepare('SELECT id, title, status FROM incident_reports WHERE id = :id');
        $findStmt->execute([':id' => $reportId]);
        $report = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Không tìm thấy báo cáo sự cố.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $updateStmt = $pdo->prepare('
            UPDATE incident_reports 
            SET status = :status, admin_note = :note, updated_at = NOW() 
            WHERE id = :id
        ');
        $updateStmt->execute([
            ':status' => $newStatus,
            ':note'   => $adminNote,
            ':id'     => $reportId
        ]);

        // Ghi nhật ký vào activity_logs
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log = $pdo->prepare(
                'INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
                 VALUES (:uid, :uname, "update_incident_fifo", "incident_report", :rid, :details, :ip, NOW())'
            );
            $log->execute([
                ':uid'     => $userId,
                ':uname'   => $userName,
                ':rid'     => $reportId,
                ':details' => "Chuyển trạng thái sự cố FIFO [#{$reportId}]: '{$report['title']}' sang [{$newStatus}] - Ghi chú: {$adminNote}",
                ':ip'      => $ip
            ]);
        } catch (Throwable $t) {
            error_log('[ActivityLog Error] ' . $t->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => "Đã cập nhật trạng thái sự cố #{$reportId} thành công!"
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Lỗi máy chủ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Phương thức không được hỗ trợ.'], JSON_UNESCAPED_UNICODE);

