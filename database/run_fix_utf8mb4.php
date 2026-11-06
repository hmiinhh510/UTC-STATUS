<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::getInstance();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$sql = file_get_contents(__DIR__ . '/fix_utf8mb4.sql');

// Split by semicolon followed by optional spaces and newline
$rawStatements = preg_split('/;\s*(?:\r\n|\n|\r)/', $sql);

echo "Found " . count($rawStatements) . " raw statements...\n";

foreach ($rawStatements as $stmt) {
    // Strip comments
    $lines = array_filter(
        explode("\n", str_replace("\r", "", $stmt)),
        fn($l) => !str_starts_with(trim($l), '--')
    );
    $cleanStmt = trim(implode("\n", $lines));
    if (empty($cleanStmt)) continue;

    try {
        $pdo->exec($cleanStmt);
        $preview = str_replace(["\n", "\r"], ' ', substr($cleanStmt, 0, 70));
        echo "[OK] " . $preview . "...\n";
    } catch (PDOException $e) {
        echo "[ERROR] " . $e->getMessage() . "\nSQL: " . $cleanStmt . "\n";
    }
}

echo "=== FINISHED EXECUTING FIX UTF-8 SQL ===\n";
