<?php
declare(strict_types=1);

function reconcileServiceStatus(
    PDO $pdo,
    int $serviceId,
    ?int $changedBy,
    string $reason,
    ?string $observedStatus = null,
    bool $forceObserved = false
): ?array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $serviceStmt = $pdo->prepare('SELECT status FROM services WHERE id = :id FOR UPDATE');
        $serviceStmt->execute([':id' => $serviceId]);
        $currentStatus = $serviceStmt->fetchColumn();

        if ($currentStatus === false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        if ($forceObserved && $observedStatus !== null) {
            // Khi Admin / Editor can thiệp trực tiếp, áp dụng trạng thái được chỉ định
            $newStatus = $observedStatus;
        } else {
            $incidentStmt = $pdo->prepare(
                'SELECT i.severity
                 FROM incidents i
                 INNER JOIN incident_services ix ON ix.incident_id = i.id
                 WHERE ix.service_id = :service_id AND i.status <> "resolved"'
            );
            $incidentStmt->execute([':service_id' => $serviceId]);
            $severityRank = ['minor' => 1, 'major' => 2, 'critical' => 3];
            $incidentStatus = null;
            $highestSeverity = 0;

            foreach ($incidentStmt->fetchAll(PDO::FETCH_COLUMN) as $severity) {
                $rank = $severityRank[$severity] ?? 0;
                if ($rank > $highestSeverity) {
                    $highestSeverity = $rank;
                    $incidentStatus = match ($severity) {
                        'critical' => 'major_outage',
                        'major' => 'partial_outage',
                        default => 'degraded',
                    };
                }
            }

            if ($incidentStatus !== null) {
                $newStatus = $incidentStatus;
            } else {
                $maintenanceStmt = $pdo->prepare(
                    'SELECT 1
                     FROM maintenances m
                     INNER JOIN maintenance_services mx ON mx.maintenance_id = m.id
                     WHERE mx.service_id = :service_id AND m.status = "in_progress"
                     LIMIT 1'
                );
                $maintenanceStmt->execute([':service_id' => $serviceId]);
                $newStatus = $maintenanceStmt->fetchColumn() !== false
                    ? 'maintenance'
                    : ($observedStatus ?? 'operational');
            }
        }

        if ($newStatus === $currentStatus) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $updateStmt = $pdo->prepare('UPDATE services SET status = :status, updated_at = NOW() WHERE id = :id');
        $updateStmt->execute([':status' => $newStatus, ':id' => $serviceId]);

        $historyStmt = $pdo->prepare(
            'INSERT INTO service_status_history (service_id, old_status, new_status, changed_by, reason, created_at)
             VALUES (:service_id, :old_status, :new_status, :changed_by, :reason, NOW())'
        );
        $historyStmt->execute([
            ':service_id' => $serviceId,
            ':old_status' => $currentStatus,
            ':new_status' => $newStatus,
            ':changed_by' => $changedBy,
            ':reason' => mb_substr($reason, 0, 255),
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['old_status' => $currentStatus, 'new_status' => $newStatus];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Trả về toàn bộ chỉ số vận hành hệ thống (System Metrics) chuẩn xác,
 * đồng bộ giữa Server-side Pre-rendering và RESTful API.
 * Đảm bảo các cập nhật trực tiếp trạng thái dịch vụ (Quick Status / Service Edit)
 * và các sự cố / lịch bảo trì từ trang Quản trị đều được phản ánh đồng bộ tới
 * tất cả các loại tài khoản (Admin, Editor, Student).
 */
function getSystemMetrics(PDO $pdo): array {
    // 1. Toàn bộ danh sách dịch vụ
    $stmtServices = $pdo->query('SELECT id, group_id, name, description, icon, status, sort_order, updated_at FROM services ORDER BY sort_order ASC, id ASC');
    $allServices = $stmtServices->fetchAll(PDO::FETCH_ASSOC);
    $totalServices = count($allServices);
    $operationalCount = 0;
    $outageCount = 0;
    $degradedCount = 0;
    $maintenanceServicesCount = 0;
    $troubledServiceName = '';
    $troubledServices = [];
    $maintenanceServices = [];

    foreach ($allServices as $svc) {
        $st = $svc['status'];
        if ($st === 'operational') {
            $operationalCount++;
        } elseif (in_array($st, ['major_outage', 'partial_outage'], true)) {
            $outageCount++;
            $troubledServices[$svc['id']] = $svc;
            if ($troubledServiceName === '') {
                $troubledServiceName = $svc['name'];
            }
        } elseif ($st === 'degraded') {
            $degradedCount++;
            $troubledServices[$svc['id']] = $svc;
            if ($troubledServiceName === '') {
                $troubledServiceName = $svc['name'];
            }
        } elseif ($st === 'maintenance') {
            $maintenanceServicesCount++;
            $maintenanceServices[$svc['id']] = $svc;
        }
    }
    $troubledServicesCount = $outageCount + $degradedCount;

    // Tổng quan trạng thái banner
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
    } elseif ($maintenanceServicesCount > 0) {
        $overallStatus  = 'maintenance';
        $overallMessage = "Một số hệ thống đang trong thời gian bảo trì";
        $overallColor   = '#3b82f6';
    } else {
        $overallStatus  = 'operational';
        $overallMessage = "Tất cả hệ thống đang hoạt động bình thường";
        $overallColor   = '#22c55e';
    }

    // 2. Uptime trung bình 90 ngày
    $stmtAvgUptime = $pdo->query(
        'SELECT COALESCE(AVG(uptime_pct), 99.3) AS avg_uptime
         FROM service_daily_uptime
         WHERE snapshot_date >= DATE(NOW()) - INTERVAL 89 DAY'
    );
    $avgUptime = round((float) $stmtAvgUptime->fetchColumn(), 1);
    if ($avgUptime <= 0) $avgUptime = 99.3;

    // 3. Sự cố đang hoạt động (Active incidents)
    $stmtInc = $pdo->query(
        'SELECT i.id, i.title, i.severity, i.status, i.started_at, i.resolved_at, i.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services,
                GROUP_CONCAT(DISTINCT s.id) AS affected_service_ids
         FROM incidents i
         LEFT JOIN incident_services isc ON isc.incident_id = i.id
         LEFT JOIN services s            ON s.id = isc.service_id
         WHERE i.status != "resolved"
         GROUP BY i.id
         ORDER BY i.started_at DESC'
    );
    $activeIncidents = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

    $coveredServiceIds = [];
    foreach ($activeIncidents as $inc) {
        if (!empty($inc['affected_service_ids'])) {
            foreach (explode(',', (string)$inc['affected_service_ids']) as $sid) {
                $coveredServiceIds[(int)trim($sid)] = true;
            }
        }
    }

    $standaloneTroubledServices = [];
    foreach ($troubledServices as $sid => $svc) {
        if (!isset($coveredServiceIds[$sid])) {
            $standaloneTroubledServices[] = $svc;
        }
    }

    $effectiveActiveIncidentsCount = count($activeIncidents) + count($standaloneTroubledServices);

    // 4. Lịch bảo trì (Maintenances)
    $stmtMaint = $pdo->query(
        'SELECT m.id, m.title, m.description, m.status, m.scheduled_at, m.expected_end, m.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") AS affected_services,
                GROUP_CONCAT(DISTINCT s.id) AS affected_service_ids
         FROM maintenances m
         LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
         LEFT JOIN services s              ON s.id = ms.service_id
         WHERE m.status IN ("scheduled", "in_progress")
         GROUP BY m.id
         ORDER BY (m.status = "in_progress") DESC, m.scheduled_at ASC'
    );
    $activeMaintenances = $stmtMaint->fetchAll(PDO::FETCH_ASSOC);

    $coveredMaintServiceIds = [];
    $scheduledMaintCount = 0;
    $inProgressMaintCount = 0;
    foreach ($activeMaintenances as $m) {
        if ($m['status'] === 'scheduled') {
            $scheduledMaintCount++;
        } elseif ($m['status'] === 'in_progress') {
            $inProgressMaintCount++;
        }
        if (!empty($m['affected_service_ids'])) {
            foreach (explode(',', (string)$m['affected_service_ids']) as $sid) {
                $coveredMaintServiceIds[(int)trim($sid)] = true;
            }
        }
    }

    $standaloneMaintServices = [];
    foreach ($maintenanceServices as $sid => $svc) {
        if (!isset($coveredMaintServiceIds[$sid])) {
            $standaloneMaintServices[] = $svc;
            $inProgressMaintCount++;
        }
    }

    $effectiveMaintenanceCount = count($activeMaintenances) + count($standaloneMaintServices);

    return [
        'all_services'                  => $allServices,
        'total_services'                => $totalServices,
        'operational_count'             => $operationalCount,
        'outage_count'                  => $outageCount,
        'degraded_count'                => $degradedCount,
        'troubled_services_count'       => $troubledServicesCount,
        'maintenance_services_count'    => $maintenanceServicesCount,
        'overall_status'                => $overallStatus,
        'overall_message'               => $overallMessage,
        'overall_color'                 => $overallColor,
        'avg_uptime_90d'                => $avgUptime,
        'active_incidents'              => $activeIncidents,
        'standalone_troubled_services'  => $standaloneTroubledServices,
        'effective_active_incidents'    => $effectiveActiveIncidentsCount,
        'active_maintenances'           => $activeMaintenances,
        'standalone_maint_services'     => $standaloneMaintServices,
        'effective_maintenances_count'  => $effectiveMaintenanceCount,
        'scheduled_maint_count'         => $scheduledMaintCount,
        'in_progress_maint_count'       => $inProgressMaintCount,
    ];
}
