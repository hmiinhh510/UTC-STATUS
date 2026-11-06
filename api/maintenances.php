<?php


declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

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

    $tab   = $_GET['tab'] ?? 'all'; // 'upcoming', 'past', 'all'
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 30)));

    // 1. Lấy các kế hoạch sắp tới & đang diễn ra (in_progress, scheduled)
    $stmtUpcoming = $pdo->prepare(
        "SELECT m.id, m.title, m.description, m.status,
                m.scheduled_at, m.expected_end, m.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS affected_services
         FROM maintenances m
         LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
         LEFT JOIN services s ON s.id = ms.service_id
         WHERE m.status IN ('in_progress', 'scheduled')
         GROUP BY m.id
         ORDER BY (m.status = 'in_progress') DESC, m.scheduled_at ASC"
    );
    $stmtUpcoming->execute();
    $upcoming = $stmtUpcoming->fetchAll();

    // 2. Lấy các kế hoạch đã hoàn tất (completed)
    $stmtPast = $pdo->prepare(
        "SELECT m.id, m.title, m.description, m.status,
                m.scheduled_at, m.expected_end, m.updated_at,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS affected_services
         FROM maintenances m
         LEFT JOIN maintenance_services ms ON ms.maintenance_id = m.id
         LEFT JOIN services s ON s.id = ms.service_id
         WHERE m.status = 'completed'
         GROUP BY m.id
         ORDER BY m.expected_end DESC
         LIMIT 20"
    );
    $stmtPast->execute();
    $past = $stmtPast->fetchAll();

    $all = array_merge($upcoming, $past);

    $responseData = match ($tab) {
        'upcoming' => $upcoming,
        'past'     => $past,
        default    => $all,
    };

    jsonOut(200, [
        'data'     => $responseData,
        'upcoming' => $upcoming,
        'past'     => $past,
        'counts'   => [
            'total'       => count($all),
            'upcoming'    => count($upcoming),
            'in_progress' => count(array_filter($upcoming, fn($m) => $m['status'] === 'in_progress')),
            'scheduled'   => count(array_filter($upcoming, fn($m) => $m['status'] === 'scheduled')),
            'past'        => count($past),
        ],
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('[API/maintenances] ' . $e->getMessage());
    jsonOut(500, ['error' => 'Lỗi máy chủ nội bộ.']);
}
