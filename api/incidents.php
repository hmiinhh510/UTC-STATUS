<?php
/**
 * API Endpoint: GET /api/incidents.php
 *
 * Query params:
 *   ?page=1          – pagination (default 1)
 *   ?per_page=10     – items per page (max 50)
 *   ?status=resolved – filter by status
 *   ?date_from=YYYY-MM-DD
 *   ?date_to=YYYY-MM-DD
 */

declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/service_status.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOut(405, ['error' => 'Phương thức không được hỗ trợ (Method Not Allowed).']);
}

try {
    $pdo = Database::getInstance();

    // ── Input sanitization ────────────────────────────────────
    $page     = max(1, (int) ($_GET['page']     ?? 1));
    $perPage  = min(50, max(1, (int) ($_GET['per_page'] ?? 10)));
    $offset   = ($page - 1) * $perPage;
    $statusFilter   = $_GET['status']    ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo   = $_GET['date_to']   ?? null;

    $validStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];
    if ($statusFilter && !in_array($statusFilter, $validStatuses, true)) {
        jsonOut(400, ['error' => 'Invalid status filter']);
    }

    // ── Build WHERE clause ────────────────────────────────────
    $where  = ['1=1'];
    $params = [];

    if ($statusFilter) {
        $where[]  = 'i.status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($dateFrom) {
        $where[]  = 'i.started_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $where[]  = 'i.started_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $whereSQL = implode(' AND ', $where);

    // ── Count total ───────────────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM incidents i WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // ── Fetch incidents ───────────────────────────────────────
    $params[':limit']  = $perPage;
    $params[':offset'] = $offset;

    $stmt = $pdo->prepare(
        "SELECT i.id, i.title, i.severity, i.status,
                i.started_at, i.resolved_at, i.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS affected_services
         FROM incidents i
         LEFT JOIN incident_services isc ON isc.incident_id = i.id
         LEFT JOIN services s ON s.id = isc.service_id
         WHERE $whereSQL
         GROUP BY i.id
         ORDER BY i.started_at DESC
         LIMIT :limit OFFSET :offset"
    );
    // Bind named params first, then int params separately for PDO quirk
    foreach ($params as $key => $val) {
        if (in_array($key, [':limit', ':offset'], true)) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $val);
        }
    }
    $stmt->execute();
    $incidents = $stmt->fetchAll();

    // ── Fetch updates for each incident ───────────────────────
    foreach ($incidents as &$inc) {
        $updStmt = $pdo->prepare(
            'SELECT id, status, message, created_at
             FROM incident_updates
             WHERE incident_id = :id
             ORDER BY created_at ASC'
        );
        $updStmt->execute([':id' => $inc['id']]);
        $inc['updates'] = $updStmt->fetchAll();
    }
    unset($inc);

    // Đồng bộ các dịch vụ đang gặp sự cố chưa gắn vào ticket chính thức
    if ($statusFilter !== 'resolved' && $page === 1) {
        $metrics = getSystemMetrics($pdo);
        $syntheticIncidents = [];
        foreach ($metrics['standalone_troubled_services'] as $stSvc) {
            $sev = match($stSvc['status']) {
                'major_outage'   => 'critical',
                'partial_outage' => 'major',
                default          => 'minor'
            };
            $desc = match($stSvc['status']) {
                'major_outage'   => 'Sự cố nghiêm trọng (Major Outage)',
                'partial_outage' => 'Gián đoạn một phần (Partial Outage)',
                default          => 'Hiệu năng giảm sút (Degraded Performance)'
            };
            $syntheticIncidents[] = [
                'id' => 'svc-' . $stSvc['id'],
                'title' => 'Sự cố dịch vụ: ' . $stSvc['name'],
                'severity' => $sev,
                'status' => 'investigating',
                'started_at' => $stSvc['updated_at'] ?? date('Y-m-d H:i:s'),
                'resolved_at' => null,
                'updated_at' => $stSvc['updated_at'] ?? date('Y-m-d H:i:s'),
                'affected_services' => $stSvc['name'],
                'updates' => [
                    [
                        'id' => 0,
                        'status' => 'investigating',
                        'message' => "Hệ thống ghi nhận trạng thái [{$desc}]. Đội ngũ kỹ thuật đang tiến hành kiểm tra và khắc phục.",
                        'created_at' => $stSvc['updated_at'] ?? date('Y-m-d H:i:s')
                    ]
                ]
            ];
        }
        if (!empty($syntheticIncidents)) {
            $incidents = array_merge($syntheticIncidents, $incidents);
            $total += count($syntheticIncidents);
        }
    }

    jsonOut(200, [
        'data'       => $incidents,
        'incidents'  => $incidents,
        'pagination' => [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $total,
            'total_pages'=> (int) ceil($total / $perPage),
        ],
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('[API/incidents] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ.']);
}
