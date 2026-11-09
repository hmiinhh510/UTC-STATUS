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
