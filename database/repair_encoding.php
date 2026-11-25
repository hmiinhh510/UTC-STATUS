<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::getInstance();
$markers = ['─', '╗', '┼', '├', '╔', '╩', '░'];
$columns = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
     ORDER BY TABLE_NAME, ORDINAL_POSITION"
)->fetchAll();

$repairedCount = 0;
$pdo->beginTransaction();

try {
    foreach ($columns as $column) {
        $table = $column['TABLE_NAME'];
        $columnName = $column['COLUMN_NAME'];
        $primaryKeyStmt = $pdo->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION"
        );
        $primaryKeyStmt->execute([':table' => $table]);
        $primaryKeys = $primaryKeyStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($primaryKeys === []) {
            continue;
        }

        $updatedColumnsStmt = $pdo->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND EXTRA LIKE '%on update CURRENT_TIMESTAMP%'"
        );
        $updatedColumnsStmt->execute([':table' => $table]);
        $autoUpdatedColumns = $updatedColumnsStmt->fetchAll(PDO::FETCH_COLUMN);

        $quoteIdentifier = static fn(string $identifier): string =>
            '`' . str_replace('`', '``', $identifier) . '`';

        $quotedTable = $quoteIdentifier($table);
        $quotedColumn = $quoteIdentifier($columnName);
        $selectedColumns = array_unique(array_merge($primaryKeys, [$columnName], $autoUpdatedColumns));
        $rows = $pdo->query(
            'SELECT ' . implode(', ', array_map($quoteIdentifier, $selectedColumns)) .
            ' FROM ' . $quotedTable
        )->fetchAll();

        foreach ($rows as $row) {
            $value = $row[$columnName];
            if (!is_string($value)) {
                continue;
            }

            $hasMarker = false;
            foreach ($markers as $marker) {
                if (strpos($value, $marker) !== false) {
                    $hasMarker = true;
                    break;
                }
            }
            if (!$hasMarker) {
                continue;
            }

            $repaired = @iconv('UTF-8', 'CP850', $value);
            if (
                $repaired === false ||
                preg_match('//u', $repaired) !== 1 ||
                @iconv('CP850', 'UTF-8', $repaired) !== $value ||
                $repaired === $value
            ) {
                continue;
            }

            if (!$dryRun) {
                $conditions = [];
                $parameters = [':value' => $repaired];
                foreach ($primaryKeys as $index => $key) {
                    $placeholder = ':pk' . $index;
                    $conditions[] = $quoteIdentifier($key) . ' = ' . $placeholder;
                    $parameters[$placeholder] = $row[$key];
                }

                $assignments = [$quotedColumn . ' = :value'];
                foreach ($autoUpdatedColumns as $index => $updatedColumn) {
                    $placeholder = ':updated_at' . $index;
                    $assignments[] = $quoteIdentifier($updatedColumn) . ' = ' . $placeholder;
                    $parameters[$placeholder] = $row[$updatedColumn];
                }

                $update = $pdo->prepare(
                    'UPDATE ' . $quotedTable .
                    ' SET ' . implode(', ', $assignments) . ' WHERE ' .
                    implode(' AND ', $conditions)
                );
                $update->execute($parameters);
            }

            $repairedCount++;
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "Dry run: {$repairedCount} value(s) can be repaired.\n";
    } else {
        $pdo->commit();
        echo "Repaired {$repairedCount} value(s).\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
