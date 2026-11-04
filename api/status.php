<?php
/**
 * API Endpoint: GET /api/status.php
 *
 * Enhanced for UTC STATUS Page (Đại học GTVT):
 * Returns:
 *  - overall banner status
 *  - summary counts (total, operational, outage, maintenance, active incidents, upcoming maintenances)
 *  - calendar event marks (incidents and maintenances mapped by date)
 *  - service list with icons
 *  - service groups
 *  - active incidents & upcoming maintenances
 */

declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

const STATUS_LABELS = [
    'operational'   => ['label' => 'Hoạt động tốt',      'color' => '#22c55e', 'dot' => '#22c55e', 'badge' => 'operational'],
    'degraded'      => ['label' => 'Hiệu năng giảm',     'color' => '#eab308', 'dot' => '#eab308', 'badge' => 'degraded'],
    'partial_outage'=> ['label' => 'Gián đoạn một phần',  'color' => '#f97316', 'dot' => '#f97316', 'badge' => 'partial_outage'],
    'major_outage'  => ['label' => 'Sự cố nghiêm trọng', 'color' => '#ef4444', 'dot' => '#ef4444', 'badge' => 'major_outage'],
    'maintenance'   => ['label' => 'Đang bảo trì',       'color' => '#f59e0b', 'dot' => '#f59e0b', 'badge' => 'maintenance'],
];

try {
    $pdo = Database::getInstance();
    $pdo->exec("SET NAMES utf8mb4");

    // 1. Fetch groups & services
    $stmtGroups = $pdo->prepare('SELECT id, name, description, sort_order FROM service_groups ORDER BY sort_order ASC, id ASC');
    $stmtGroups->execute();
    $groups = $stmtGroups->fetchAll();

    $stmtServices = $pdo->prepare(
        'SELECT s.id, s.group_id, s.name, s.description, s.icon, s.status, s.sort_order, s.updated_at,
                g.name AS group_name
         FROM services s
         LEFT JOIN service_groups g ON g.id = s.group_id
         ORDER BY s.sort_order ASC, s.id ASC'
    );
    $stmtServices->execute();
    $allServices = $stmtServices->fetchAll();

    // 2. Counts & Summary
    $totalServices    = count($allServices);
    $operationalCount = 0;
    $outageCount      = 0;
    $maintenanceCount = 0;
    $degradedCount    = 0;

    $troubledServiceName = '';

    foreach ($allServices as &$svc) {
        $st = $svc['status'];
        if ($st === 'operational') {
            $operationalCount++;
        } elseif (in_array($st, ['major_outage', 'partial_outage'], true)) {
            $outageCount++;
            if ($troubledServiceName === '') {
                $troubledServiceName = $svc['name'];
            }
        } elseif ($st === 'maintenance') {
            $maintenanceCount++;
        } elseif ($st === 'degraded') {
            $degradedCount++;
            if ($troubledServiceName === '') {
                $troubledServiceName = $svc['name'];
            }
        }

        $info = STATUS_LABELS[$st] ?? STATUS_LABELS['operational'];
        $svc['status_label'] = $info['label'];
        $svc['status_color'] = $info['color'];
        $svc['dot_color']    = $info['dot'];
    }
    unset($svc);

    // 3. Overall status message
    if ($outageCount > 0) {
        $overallStatus  = 'major_outage';
        $overallMessage = ($troubledServiceName !== '')
            ? "{$troubledServiceName} đang gặp sự cố"
            : "Một số hệ thống đang gặp sự cố";
        $overallColor   = '#ef4444';
    } elseif ($degradedCount > 0) {
        $overallStatus  = 'degraded';
        $overallMessage = ($troubledServiceName !== '')
            ? "{$troubledServiceName} có hiệu năng giảm sút"
            : "Một số hệ thống đang có hiệu năng giảm sút";
        $overallColor   = '#eab308';
    } elseif ($maintenanceCount > 0) {
        $overallStatus  = 'maintenance';
        $overallMessage = "Một số hệ thống đang trong thời gian bảo trì";
        $overallColor   = '#3b82f6';
    } else {
        $overallStatus  = 'operational';
        $overallMessage = "Tất cả hệ thống đang hoạt động bình thường";
        $overallColor   = '#22c55e';
    }

    // 4. Average 90-day Uptime calculation
    $stmtAvgUptime = $pdo->query(
        'SELECT COALESCE(AVG(uptime_pct), 99.3) AS avg_uptime
         FROM service_daily_uptime
         WHERE snapshot_date >= DATE(NOW()) - INTERVAL 89 DAY'
    );
    $avgUptime = round((float) $stmtAvgUptime->fetchColumn(), 1);
    if ($avgUptime <= 0) $avgUptime = 99.3;

    // 5. Active incidents
    $stmtInc = $pdo->prepare(
        'SELECT i.id, i.title, i.severity, i.status, i.started_at, i.resolved_at, i.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services
         FROM incidents i
         LEFT JOIN incident_services isc ON isc.incident_id = i.id
         LEFT JOIN services s            ON s.id = isc.service_id
         WHERE i.status != "resolved"
         GROUP BY i.id
         ORDER BY i.started_at DESC'
    );
    $stmtInc->execute();
    $activeIncidents = $stmtInc->fetchAll();

    // 6. Upcoming and in-progress maintenances
    $stmtMaint = $pdo->prepare(
        'SELECT m.id, m.title, m.description, m.status, m.scheduled_at, m.expected_end,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services
         FROM maintenances m
         LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
         LEFT JOIN services s              ON s.id = ms.service_id
         WHERE m.status IN ("scheduled","in_progress")
         GROUP BY m.id
         ORDER BY (m.status = "in_progress") DESC, m.scheduled_at ASC'
    );
    $stmtMaint->execute();
    $upcomingMaintenances = $stmtMaint->fetchAll();

    $inProgressCount = 0;
    $scheduledCount  = 0;
    foreach ($upcomingMaintenances as $m) {
        if ($m['status'] === 'in_progress') $inProgressCount++;
        if ($m['status'] === 'scheduled')   $scheduledCount++;
    }

    // 7. Calendar Events mapping purely from MySQL (incidents and maintenances)
    $calendarEvents = [];

    // Incidents dates
    $stmtIncDates = $pdo->query(
        'SELECT DATE(started_at) as event_date, COUNT(*) as cnt, GROUP_CONCAT(title SEPARATOR "; ") as titles
         FROM incidents
         GROUP BY DATE(started_at)'
    );
    while ($row = $stmtIncDates->fetch()) {
        $calendarEvents[$row['event_date']]['incidents'] = (int) $row['cnt'];
        $calendarEvents[$row['event_date']]['incident_titles'] = $row['titles'];
    }

    // Maintenances dates
    $stmtMaintDates = $pdo->query(
        'SELECT DATE(scheduled_at) as event_date, COUNT(*) as cnt, GROUP_CONCAT(title SEPARATOR "; ") as titles
         FROM maintenances
         GROUP BY DATE(scheduled_at)'
    );
    while ($row = $stmtMaintDates->fetch()) {
        $calendarEvents[$row['event_date']]['maintenances'] = (int) $row['cnt'];
        $calendarEvents[$row['event_date']]['maintenance_titles'] = $row['titles'];
    }

    // 8. Service Groups payload
    $servicesByGroup = [];
    foreach ($allServices as $svc) {
        $servicesByGroup[$svc['group_id']][] = $svc;
    }
    $groupsPayload = [];
    foreach ($groups as $g) {
        $groupsPayload[] = [
            'id'          => (int) $g['id'],
            'name'        => $g['name'],
            'description' => $g['description'],
            'sort_order'  => (int) $g['sort_order'],
            'services'    => $servicesByGroup[$g['id']] ?? []
        ];
    }

    jsonResponse(200, [
        'overall' => [
            'status'  => $overallStatus,
            'color'   => $overallColor,
            'message' => $overallMessage,
            'label'   => STATUS_LABELS[$overallStatus]['label'] ?? 'Hoạt động',
        ],
        'summary' => [
            'total_services'            => $totalServices,
            'operational_count'         => $operationalCount,
            'outage_count'              => $outageCount,
            'maintenance_count'         => $maintenanceCount,
            'degraded_count'            => $degradedCount,
            'avg_uptime_90d'            => $avgUptime,
            'active_incidents_count'    => count($activeIncidents),
            'upcoming_maintenances_count'=> count($upcomingMaintenances),
            'in_progress_count'         => $inProgressCount,
            'scheduled_count'           => $scheduledCount,
        ],
        'services'              => $allServices,
        'service_groups'        => $groupsPayload,
        'active_incidents'      => $activeIncidents,
        'upcoming_maintenances' => $upcomingMaintenances,
        'calendar_events'       => $calendarEvents,
        'generated_at'          => date('c'),
        'server_time'           => date('H:i'),
    ]);

} catch (Throwable $e) {
    error_log('[API/status] ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Lỗi máy chủ nội bộ. Vui lòng thử lại sau.']);
}
