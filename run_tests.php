<?php
/**
 * UTC STATUS – CLI Test Runner Entry Point
 * Chạy lệnh: php run_tests.php hoặc run_tests.bat
 */

declare(strict_types=1);

require_once __DIR__ . '/tests/TestRunner.php';

$runner = new \Tests\TestRunner();
$exitCode = $runner->run();
exit($exitCode);

