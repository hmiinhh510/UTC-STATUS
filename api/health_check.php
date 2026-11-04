<?php
/**
 * API Endpoint: GET /api/health_check.php
 *
 * Tự động kiểm tra tình trạng thực tế của các dịch vụ (Automated Health Check).
 * Hỗ trợ tham số:
 *   ?service_id=X   – chỉ kiểm tra 1 dịch vụ cụ thể
 *   ?auto_update=1  – tự động cập nhật trạng thái dịch vụ trong CSDL nếu phát hiện thay đổi
 */

declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache');

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->exec("SET NAMES utf8mb4");

    $targetServiceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    $autoUpdate      = (bool) ($_GET['auto_update'] ?? false);

    if ($targetServiceId) {
        $stmt = $pdo->prepare('SELECT id, name, status, check_url FROM services WHERE id = :id');
        $stmt->execute([':id' => $targetServiceId]);
        $services = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query('SELECT id, name, status, check_url FROM services ORDER BY sort_order ASC, id ASC');
        $services = $stmt->fetchAll();
    }

    $results = [];
    $updateStmt = $pdo->prepare('UPDATE services SET status = :status, updated_at = NOW() WHERE id = :id');
    $logHistoryStmt = $pdo->prepare(
        'INSERT INTO service_status_history (service_id, old_status, new_status, changed_by, reason, created_at)
         VALUES (:sid, :old, :new, NULL, :reason, NOW())'
    );
    $logActivityStmt = $pdo->prepare(
        'INSERT INTO activity_logs (username, action, target_type, target_id, details, ip_address, created_at)
         VALUES ("HealthCheckBot", "auto_health_check", "service", :sid, :details, "127.0.0.1", NOW())'
    );

    foreach ($services as $svc) {
        $sid      = (int) $svc['id'];
        $url      = $svc['check_url'];
        $currentSt= $svc['status'];

        if (empty($url)) {
            $results[] = [
                'service_id'     => $sid,
                'name'           => $svc['name'],
                'check_url'      => null,
                'current_status' => $currentSt,
                'check_status'   => 'skipped',
                'message'        => 'Không cấu hình URL kiểm tra (Dịch vụ nội bộ/phần cứng)'
            ];
            continue;
        }

        // Thực hiện probe HTTP bằng cURL với timeout 3s
        $startTime = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true, // HEAD request để nhanh và tiết kiệm băng thông
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'UTC-StatusPage-HealthCheck/2.0'
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);
        $durationMs = round((microtime(true) - $startTime) * 1000, 1);

        $isHealthy = ($httpCode >= 200 && $httpCode < 400);
        $suggestedStatus = $isHealthy ? 'operational' : ($httpCode >= 500 || $httpCode === 0 ? 'major_outage' : 'degraded');

        $statusChanged = false;
        if ($autoUpdate && $suggestedStatus !== $currentSt) {
            // Cập nhật CSDL
            $updateStmt->execute([':status' => $suggestedStatus, ':id' => $sid]);
            $reason = "Tự động phát hiện qua Health Check (HTTP {$httpCode}, {$durationMs}ms)";
            $logHistoryStmt->execute([
                ':sid'    => $sid,
                ':old'    => $currentSt,
                ':new'    => $suggestedStatus,
                ':reason' => $reason
            ]);
            $logActivityStmt->execute([
                ':sid'     => $sid,
                ':details' => "Health Check đổi trạng thái dịch vụ \"{$svc['name']}\" từ {$currentSt} sang {$suggestedStatus} (HTTP {$httpCode})"
            ]);
            $statusChanged = true;
        }

        $results[] = [
            'service_id'       => $sid,
            'name'             => $svc['name'],
            'check_url'        => $url,
            'http_code'        => $httpCode,
            'latency_ms'       => $durationMs,
            'is_healthy'       => $isHealthy,
            'current_status'   => $currentSt,
            'suggested_status' => $suggestedStatus,
            'status_updated'   => $statusChanged,
            'error'            => $curlError ?: null
        ];
    }

    jsonOut(200, [
        'success'      => true,
        'checked_count'=> count($results),
        'timestamp'    => date('c'),
        'results'      => $results
    ]);

} catch (Throwable $e) {
    error_log('[API/health_check] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ khi kiểm tra: ' . $e->getMessage()]);
}

