<?php


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

    $includePast = (bool) ($_GET['include_past'] ?? false);
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

    $statusFilter = $includePast
        ? "m.status IN ('scheduled','in_progress','completed')"
        : "m.status IN ('scheduled','in_progress') AND m.expected_end >= NOW() - INTERVAL 1 DAY";

    $stmt = $pdo->prepare(
        "SELECT m.id, m.title, m.description, m.status,
                m.scheduled_at, m.expected_end, m.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS affected_services
         FROM maintenances m
         LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
         LEFT JOIN services s ON s.id = ms.service_id
         WHERE $statusFilter
         GROUP BY m.id
         ORDER BY m.scheduled_at ASC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    jsonOut(200, [
        'data' => $rows,
        'count' => count($rows),
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('[API/maintenances] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ.']);
}
