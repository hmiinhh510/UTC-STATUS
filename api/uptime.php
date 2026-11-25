<?php
/**
 * API Endpoint: GET /api/uptime.php?service_id={id}
 *
 * Returns 90-day daily uptime bars and summary statistics for
 * a given service.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $pdo = Database::getInstance();

    $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$serviceId || $serviceId < 1) {
        jsonOut(400, ['error' => 'Tham số service_id không hợp lệ.']);
    }

    // ── Verify service exists ────────────────────────────────
    $svcStmt = $pdo->prepare(
        'SELECT s.id, s.name, s.status, s.icon, s.description, g.name AS group_name
         FROM services s
         LEFT JOIN service_groups g ON g.id = s.group_id
         WHERE s.id = :id'
    );
    $svcStmt->execute([':id' => $serviceId]);
    $service = $svcStmt->fetch();

    if (!$service) {
        jsonOut(404, ['error' => 'Dịch vụ không tồn tại.']);
    }

    // ── Fetch 90 days of snapshot data ───────────────────────
    $stmt = $pdo->prepare(
        'SELECT snapshot_date, uptime_pct, incident_count
         FROM service_daily_uptime
         WHERE service_id = :id
           AND snapshot_date >= DATE(NOW()) - INTERVAL 89 DAY
         ORDER BY snapshot_date ASC'
    );
    $stmt->execute([':id' => $serviceId]);
    $rows = $stmt->fetchAll();

    // ── Fill missing dates with 100% uptime ──────────────────
    $existing = [];
    foreach ($rows as $row) {
        $existing[$row['snapshot_date']] = $row;
    }

    $days    = [];
    $totalPct= 0;
    for ($i = 89; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        if (isset($existing[$date])) {
            $day = $existing[$date];
        } else {
            $day = ['snapshot_date' => $date, 'uptime_pct' => 100.00, 'incident_count' => 0];
        }
        $totalPct += (float) $day['uptime_pct'];
        $days[] = [
            'date'           => $day['snapshot_date'],
            'uptime_pct'     => round((float) $day['uptime_pct'], 2),
            'incident_count' => (int) $day['incident_count'],
        ];
    }

    $avgUptime = round($totalPct / 90, 2);

    // ── Downtime duration from resolved incidents ─────────────
    $dtStmt = $pdo->prepare(
        'SELECT
            SUM(TIMESTAMPDIFF(MINUTE, i.started_at, COALESCE(i.resolved_at, NOW()))) AS total_downtime_minutes,
            COUNT(*) AS total_incidents
         FROM incidents i
         JOIN incident_services isc ON isc.incident_id = i.id
         WHERE isc.service_id = :id
           AND i.started_at >= NOW() - INTERVAL 90 DAY'
    );
    $dtStmt->execute([':id' => $serviceId]);
    $stats = $dtStmt->fetch();

    // ── Fetch incidents for this service in past 90 days ─────
    $incStmt = $pdo->prepare(
        'SELECT i.id, i.title, i.severity, i.status, i.started_at, i.resolved_at,
                TIMESTAMPDIFF(MINUTE, i.started_at, COALESCE(i.resolved_at, NOW())) AS duration_minutes,
                (SELECT u.message FROM incident_updates u WHERE u.incident_id = i.id ORDER BY u.created_at DESC LIMIT 1) AS latest_update
         FROM incidents i
         JOIN incident_services isc ON isc.incident_id = i.id
         WHERE isc.service_id = :id
           AND i.started_at >= NOW() - INTERVAL 90 DAY
         ORDER BY i.started_at DESC'
    );
    $incStmt->execute([':id' => $serviceId]);
    $serviceIncidents = $incStmt->fetchAll();

    jsonOut(200, [
        'service' => [
            'id'          => (int) $service['id'],
            'name'        => $service['name'],
            'status'      => $service['status'],
            'icon'        => $service['icon'] ?? 'fa-server',
            'group_name'  => $service['group_name'] ?? 'Hệ thống',
            'description' => $service['description'] ?? '',
        ],
        'summary' => [
            'avg_uptime_pct'          => $avgUptime,
            'total_incidents_90d'     => (int) ($stats['total_incidents'] ?? 0),
            'total_downtime_minutes'  => (int) ($stats['total_downtime_minutes'] ?? 0),
        ],
        'daily'        => $days,
        'incidents'    => $serviceIncidents,
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('[API/uptime] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ.']);
}
