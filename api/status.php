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
require_once __DIR__ . '/../config/service_status.php';

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

    // Attach presentation labels to services
    foreach ($allServices as &$svc) {
        $st = $svc['status'];
        $info = STATUS_LABELS[$st] ?? STATUS_LABELS['operational'];
        $svc['status_label'] = $info['label'];
        $svc['status_color'] = $info['color'];
        $svc['dot_color']    = $info['dot'];
    }
    unset($svc);

    // 2. Load unified system metrics
    $metrics = getSystemMetrics($pdo);

    $totalServices    = $metrics['total_services'];
    $operationalCount = $metrics['operational_count'];
    $outageCount      = $metrics['outage_count'];
    $maintenanceCount = $metrics['maintenance_services_count'];
    $degradedCount    = $metrics['degraded_count'];
    $overallStatus    = $metrics['overall_status'];
    $overallMessage   = $metrics['overall_message'];
    $overallColor     = $metrics['overall_color'];
    $avgUptime        = $metrics['avg_uptime_90d'];
    $activeIncidents  = $metrics['active_incidents'];
    $upcomingMaintenances = $metrics['active_maintenances'];
    $inProgressCount  = $metrics['in_progress_maint_count'];
    $scheduledCount   = $metrics['scheduled_maint_count'];
    $effectiveActiveIncidentsCount = $metrics['effective_active_incidents'];
    $effectiveMaintenanceCount    = $metrics['effective_maintenances_count'];

    // Append standalone troubled services to activeIncidents so modal & lists reflect them
    foreach ($metrics['standalone_troubled_services'] as $stSvc) {
        $activeIncidents[] = [
            'id' => 'svc-' . $stSvc['id'],
            'title' => 'Sự cố dịch vụ ' . $stSvc['name'],
            'severity' => match($stSvc['status']) {
                'major_outage' => 'critical',
                'partial_outage' => 'major',
                default => 'minor'
            },
            'status' => 'investigating',
            'started_at' => $stSvc['updated_at'] ?? date('Y-m-d H:i:s'),
            'resolved_at' => null,
            'updated_at' => $stSvc['updated_at'] ?? date('Y-m-d H:i:s'),
            'affected_services' => $stSvc['name'],
        ];
    }

    // Append standalone maintenance services to upcomingMaintenances so modal & lists reflect them
    foreach ($metrics['standalone_maint_services'] as $stMaint) {
        $upcomingMaintenances[] = [
            'id' => 'svc-' . $stMaint['id'],
            'title' => 'Bảo trì kỹ thuật: ' . $stMaint['name'],
            'description' => 'Dịch vụ ' . $stMaint['name'] . ' đang được bảo trì và nâng cấp kỹ thuật.',
            'status' => 'in_progress',
            'scheduled_at' => $stMaint['updated_at'] ?? date('Y-m-d H:i:s'),
            'expected_end' => 'Đang thực hiện',
            'updated_at' => $stMaint['updated_at'] ?? date('Y-m-d H:i:s'),
            'affected_services' => $stMaint['name'],
        ];
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

    // Đảm bảo các sự cố và bảo trì đang hoạt động hôm nay hiển thị chấm trên lịch
    $todayDate = date('Y-m-d');
    if ($effectiveActiveIncidentsCount > 0) {
        $calendarEvents[$todayDate]['incidents'] = max((int)($calendarEvents[$todayDate]['incidents'] ?? 0), $effectiveActiveIncidentsCount);
        if (empty($calendarEvents[$todayDate]['incident_titles'])) {
            $calendarEvents[$todayDate]['incident_titles'] = $overallMessage;
        }
    }
    if ($effectiveMaintenanceCount > 0) {
        $calendarEvents[$todayDate]['maintenances'] = max((int)($calendarEvents[$todayDate]['maintenances'] ?? 0), $effectiveMaintenanceCount);
        if (empty($calendarEvents[$todayDate]['maintenance_titles'])) {
            $calendarEvents[$todayDate]['maintenance_titles'] = 'Bảo trì hệ thống đang diễn ra';
        }
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
