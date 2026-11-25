<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getInstance();
    $sql = file_get_contents(__DIR__ . '/update_accounts_feature.sql');
    $pdo->exec($sql);
    echo "SQL Migration executed successfully!\n";

    // Verify columns in users
    $hasStatus = false;
    foreach ($pdo->query('DESCRIBE users') as $col) {
        if ($col['Field'] === 'status') {
            $hasStatus = true;
            echo "users.status column: " . $col['Type'] . " default: " . $col['Default'] . "\n";
        }
    }
    if (!$hasStatus) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active', 'locked') NOT NULL DEFAULT 'active' AFTER `role`");
        echo "Manually added users.status column.\n";
    }

    // Verify password_reset_requests
    $res = $pdo->query("SHOW TABLES LIKE 'password_reset_requests'")->fetch();
    echo "password_reset_requests table: " . ($res ? "EXISTS" : "MISSING") . "\n";

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

