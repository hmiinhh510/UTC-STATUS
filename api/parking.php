<?php
declare(strict_types=1);

/**
 * UTC STATUS - Campus Parking Management & Real-time Status API
 * GET  /api/parking.php - Lấy trạng thái sức chứa các khu vực bãi xe theo thời gian thực
 * POST /api/parking.php - Admin/Editor cập nhật số lượng hoặc trạng thái nhanh bãi xe
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
        $stmt = $pdo->query('SELECT * FROM parking_zones ORDER BY sort_order ASC, id ASC');
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalCapacity = 0;
        $totalOccupied = 0;

        $processedZones = [];
        foreach ($zones as $zone) {
            $cap = (int) $zone['total_capacity'];
            $occ = (int) $zone['occupied_count'];
            $totalCapacity += $cap;
            $totalOccupied += $occ;

            $pct = $cap > 0 ? round(($occ / $cap) * 100, 1) : 0;
            if ($pct > 100) $pct = 100;

            // Tính toán nhãn trạng thái tự động hoặc ghi đè từ status trong DB
            $dbStatus = $zone['status'];
            $status = $dbStatus;
            if ($dbStatus === 'full' || $pct >= 95) {
                $statusKey = 'full';
                $statusLabel = 'Đã đầy / Ngưng nhận xe';
                $badgeClass = 'bg-danger';
                $colorHex = '#ef4444';
            } elseif ($pct >= 80) {
                $statusKey = 'crowded';
                $statusLabel = 'Sắp đầy';
                $badgeClass = 'bg-warning text-dark';
                $colorHex = '#f59e0b';
            } else {
                $statusKey = 'available';
                $statusLabel = 'Còn trống';
                $badgeClass = 'bg-success';
                $colorHex = '#10b981';
            }

            $processedZones[] = [
                'id'              => (int) $zone['id'],
                'zone_code'       => (string) $zone['zone_code'],
                'name'            => (string) $zone['name'],
                'vehicle_type'    => (string) $zone['vehicle_type'],
                'total_capacity'  => $cap,
                'occupied_count'  => $occ,
                'available_count' => max(0, $cap - $occ),
                'occupancy_pct'   => $pct,
                'status'          => $statusKey,
                'status_label'    => $statusLabel,
                'badge_class'     => $badgeClass,
                'color_hex'       => $colorHex,
                'location_note'   => (string) ($zone['location_note'] ?? ''),
                'updated_at'      => (string) $zone['updated_at'],
                'updated_time'    => date('H:i:s - d/m/Y', strtotime($zone['updated_at']))
            ];
        }

        $overallPct = $totalCapacity > 0 ? round(($totalOccupied / $totalCapacity) * 100, 1) : 0;
        if ($overallPct >= 95) {
            $overallStatus = 'full';
            $overallLabel  = 'Đã đầy / Ngưng nhận xe';
            $overallBadge  = 'bg-danger';
            $overallColor  = '#ef4444';
        } elseif ($overallPct >= 80) {
            $overallStatus = 'crowded';
            $overallLabel  = 'Sắp đầy';
            $overallBadge  = 'bg-warning text-dark';
            $overallColor  = '#f59e0b';
        } else {
            $overallStatus = 'available';
            $overallLabel  = 'Còn trống';
            $overallBadge  = 'bg-success';
            $overallColor  = '#10b981';
        }

        echo json_encode([
            'success' => true,
            'summary' => [
                'total_capacity'  => $totalCapacity,
                'occupied_count'  => $totalOccupied,
                'available_count' => max(0, $totalCapacity - $totalOccupied),
                'occupancy_pct'   => $overallPct,
                'status'          => $overallStatus,
                'status_label'    => $overallLabel,
                'badge_class'     => $overallBadge,
                'color_hex'       => $overallColor,
                'last_updated'    => date('H:i:s - d/m/Y')
            ],
            'zones'   => $processedZones
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi máy chủ khi lấy dữ liệu bãi xe: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($method === 'POST') {
    // Yêu cầu quyền admin hoặc editor
    $userRole = $_SESSION['admin_role'] ?? $_SESSION['user_role'] ?? '';
    $userId   = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $userName = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Quản trị viên';

    if (!in_array($userRole, ['admin', 'editor'], true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn không có quyền cập nhật trạng thái bãi xe (yêu cầu Admin/Editor).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $zoneId = (int) ($data['zone_id'] ?? 0);
    $action = trim($data['action'] ?? 'update_occupancy'); // 'update_occupancy', 'toggle_status', 'quick_full', 'quick_available'

    if ($zoneId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Thiếu mã khu vực bãi xe (zone_id).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM parking_zones WHERE id = :id');
        $stmt->execute([':id' => $zoneId]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$zone) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Không tìm thấy khu vực bãi xe.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $totalCap = (int) $zone['total_capacity'];
        $oldOcc   = (int) $zone['occupied_count'];
        $newOcc   = $oldOcc;
        $newStatus= $zone['status'];

        if ($action === 'quick_full') {
            $newOcc = $totalCap;
            $newStatus = 'full';
        } elseif ($action === 'quick_available') {
            $newOcc = (int) round($totalCap * 0.5);
            $newStatus = 'available';
        } elseif ($action === 'update_counts') {
            if (isset($data['occupied_count'])) {
                $newOcc = max(0, min((int) $data['occupied_count'], $totalCap));
            }
            if (!empty($data['total_capacity'])) {
                $totalCap = max(1, (int) $data['total_capacity']);
            }
            $pct = round(($newOcc / $totalCap) * 100);
            if ($pct >= 95) $newStatus = 'full';
            elseif ($pct >= 80) $newStatus = 'crowded';
            else $newStatus = 'available';
        }

        $upStmt = $pdo->prepare('UPDATE parking_zones SET total_capacity = :cap, occupied_count = :occ, status = :st, updated_at = NOW() WHERE id = :id');
        $upStmt->execute([
            ':cap' => $totalCap,
            ':occ' => $newOcc,
            ':st'  => $newStatus,
            ':id'  => $zoneId
        ]);

        // Ghi nhật ký audit
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log = $pdo->prepare(
                'INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
                 VALUES (:uid, :uname, "update_parking", "parking_zone", :zid, :details, :ip, NOW())'
            );
            $log->execute([
                ':uid'     => $userId,
                ':uname'   => $userName,
                ':zid'     => $zoneId,
                ':details' => "Cập nhật bãi xe [{$zone['name']}]: {$newOcc}/{$totalCap} xe, trạng thái [{$newStatus}]",
                ':ip'      => $ip
            ]);
        } catch (Throwable $t) {
            error_log('[ActivityLog Parking Error] ' . $t->getMessage());
        }

        echo json_encode([
            'success'        => true,
            'message'        => "Đã cập nhật trạng thái bãi xe {$zone['name']} thành công!",
            'occupied_count' => $newOcc,
            'total_capacity' => $totalCap,
            'status'         => $newStatus
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

