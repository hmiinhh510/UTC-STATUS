<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/service_status.php';

function runHealthChecks(PDO $pdo, ?int $serviceId, bool $autoUpdate, ?int $changedBy): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for health checks.');
    }

    if ($serviceId !== null) {
        $stmt = $pdo->prepare('SELECT id, name, status, check_url FROM services WHERE id = :id');
        $stmt->execute([':id' => $serviceId]);
        $services = $stmt->fetchAll();
    } else {
        $services = $pdo->query(
            'SELECT id, name, status, check_url FROM services ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    }

    $results = [];
    foreach ($services as $service) {
        $id = (int) $service['id'];
        $url = trim((string) ($service['check_url'] ?? ''));
        if ($url === '') {
            $results[] = [
                'service_id' => $id,
                'name' => $service['name'],
                'check_url' => null,
                'current_status' => $service['status'],
                'check_status' => 'skipped',
                'message' => 'Không cấu hình URL kiểm tra (Dịch vụ nội bộ/phần cứng)',
            ];
            continue;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true) ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            $results[] = [
                'service_id' => $id,
                'name' => $service['name'],
                'check_url' => $url,
                'current_status' => $service['status'],
                'check_status' => 'invalid_url',
                'error' => 'URL kiểm tra không hợp lệ.',
            ];
            continue;
        }

        $startedAt = microtime(true);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL health check.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'UTC-StatusPage-HealthCheck/2.0',
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = $response === false ? curl_error($curl) : null;
        curl_close($curl);
        $latencyMs = round((microtime(true) - $startedAt) * 1000, 1);

        $isHealthy = $httpCode >= 200 && $httpCode < 400;
        $observedStatus = $isHealthy
            ? 'operational'
            : ($httpCode >= 500 || $httpCode === 0 ? 'major_outage' : 'degraded');
        $statusChange = null;

        if ($autoUpdate) {
            $pdo->beginTransaction();
            try {
                $statusChange = reconcileServiceStatus(
                    $pdo,
                    $id,
                    $changedBy,
                    "Tự động phát hiện qua Health Check (HTTP {$httpCode}, {$latencyMs}ms)",
                    $observedStatus
                );

                if ($statusChange !== null) {
                    $activityStmt = $pdo->prepare(
                        'INSERT INTO activity_logs
                            (user_id, username, action, target_type, target_id, details, ip_address, created_at)
                         VALUES (:user_id, :username, "auto_health_check", "service", :service_id, :details, :ip, NOW())'
                    );
                    $activityStmt->execute([
                        ':user_id' => $changedBy,
                        ':username' => 'HealthCheckBot',
                        ':service_id' => $id,
                        ':details' => "Health Check đổi trạng thái dịch vụ \"{$service['name']}\" từ {$statusChange['old_status']} sang {$statusChange['new_status']} (HTTP {$httpCode})",
                        ':ip' => '127.0.0.1',
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $results[] = [
            'service_id' => $id,
            'name' => $service['name'],
            'check_url' => $url,
            'http_code' => $httpCode,
            'latency_ms' => $latencyMs,
            'is_healthy' => $isHealthy,
            'current_status' => $service['status'],
            'suggested_status' => $observedStatus,
            'effective_status' => $statusChange['new_status'] ?? $service['status'],
            'status_updated' => $statusChange !== null,
            'error' => $curlError,
        ];
    }

    return [
        'success' => true,
        'checked_count' => count($results),
        'timestamp' => date('c'),
        'results' => $results,
    ];
}
