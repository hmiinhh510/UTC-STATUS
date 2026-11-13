<?php
/**
 * UTC STATUS – Test Runner CLI
 * Tự động khám phá và thực thi toàn bộ Unit Test & Feature Test
 */

declare(strict_types=1);

namespace Tests;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

require_once __DIR__ . '/TestCase.php';

class TestRunner {
    private int $testsRun = 0;
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private int $totalAssertions = 0;
    private array $failures = [];
    private float $startTime = 0.0;

    public function run(): int {
        $this->startTime = microtime(true);
        $this->printHeader();

        $testFiles = $this->discoverTestFiles();
        if (empty($testFiles)) {
            echo "  \033[33m[!] Không tìm thấy file test nào trong tests/Unit/ hoặc tests/Feature/\033[0m\n\n";
            return 0;
        }

        foreach ($testFiles as $file) {
            $this->runTestFile($file);
        }

        $this->printSummary();
        return $this->testsFailed > 0 ? 1 : 0;
    }

    private function discoverTestFiles(): array {
        $files = [];
        $dirs = [__DIR__ . '/Unit', __DIR__ . '/Feature'];

        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $dirFiles = glob($dir . '/*Test.php');
                if ($dirFiles !== false) {
                    $files = array_merge($files, $dirFiles);
                }
            }
        }

        return $files;
    }

    private function runTestFile(string $filePath): void {
        require_once $filePath;

        $className = 'Tests\\' . basename(dirname($filePath)) . '\\' . basename($filePath, '.php');
        if (!class_exists($className)) {
            // Thử namespace Tests\ClassName nếu không có subfolder
            $className = 'Tests\\' . basename($filePath, '.php');
        }

        if (!class_exists($className)) {
            return;
        }

        $refClass = new ReflectionClass($className);
        if ($refClass->isAbstract() || !$refClass->isSubclassOf(TestCase::class)) {
            return;
        }

        $testMethods = array_filter(
            $refClass->getMethods(ReflectionMethod::IS_PUBLIC),
            fn(ReflectionMethod $m) => str_starts_with($m->getName(), 'test')
        );

        if (empty($testMethods)) {
            return;
        }

        $suiteName = $refClass->getShortName();
        $groupName = basename(dirname($filePath));
        echo " \033[1;37m• [{$groupName}] {$suiteName}\033[0m\n";

        foreach ($testMethods as $method) {
            $methodName = $method->getName();
            $this->testsRun++;

            /** @var TestCase $testInstance */
            $testInstance = new $className();
            $t0 = microtime(true);
            $passed = false;
            $errorMsg = '';
            $errorTrace = '';

            try {
                $testInstance->setUp();
                $testInstance->$methodName();
                $testInstance->tearDown();
                $passed = true;
                $this->testsPassed++;
            } catch (Throwable $e) {
                try {
                    $testInstance->tearDown();
                } catch (Throwable) {}

                $this->testsFailed++;
                $errorMsg = $e->getMessage();
                $errorTrace = $e->getFile() . ':' . $e->getLine();
                $this->failures[] = [
                    'class'  => $suiteName,
                    'method' => $methodName,
                    'error'  => $errorMsg,
                    'trace'  => $errorTrace,
                ];
            }

            $duration = round((microtime(true) - $t0) * 1000, 1);
            $this->totalAssertions += $testInstance->getAssertionCount();

            if ($passed) {
                echo "    \033[32m✔ PASS\033[0m  {$methodName} \033[90m({$duration}ms)\033[0m\n";
            } else {
                echo "    \033[31m✖ FAIL\033[0m  {$methodName} \033[90m({$duration}ms)\033[0m\n";
                echo "          \033[31m↳ {$errorMsg}\033[0m \033[90m[{$errorTrace}]\033[0m\n";
            }
        }

        echo "\n";
    }

    private function printHeader(): void {
        echo "\n";
        echo "\033[1;36m======================================================================\033[0m\n";
        echo "\033[1;36m  UTC STATUS – BỘ KIỂM THỬ TỰ ĐỘNG (AUTOMATED TEST SUITE)\033[0m\n";
        echo "\033[1;36m  Môi trường: PHP " . PHP_VERSION . " | Cơ sở dữ liệu: MySQL (InnoDB Transactions)\033[0m\n";
        echo "\033[1;36m======================================================================\033[0m\n\n";
    }

    private function printSummary(): void {
        $totalTime = round(microtime(true) - $this->startTime, 3);

        echo "\033[1;36m----------------------------------------------------------------------\033[0m\n";
        if ($this->testsFailed === 0) {
            echo "\033[1;32m  ✔ THÀNH CÔNG: TẤT CẢ TÍNH NĂNG ĐỀU VƯỢT QUA KIỂM THỬ!\033[0m\n";
        } else {
            echo "\033[1;31m  ✖ THẤT BẠI: Có {$this->testsFailed} ca kiểm thử không vượt qua!\033[0m\n";
            echo "\n  \033[1;31mChi tiết các ca kiểm thử thất bại:\033[0m\n";
            foreach ($this->failures as $i => $fail) {
                $idx = $i + 1;
                echo "   {$idx}) {$fail['class']}::{$fail['method']}\n";
                echo "      \033[31m{$fail['error']}\033[0m\n";
                echo "      \033[90m{$fail['trace']}\033[0m\n\n";
            }
        }

        echo "  Tổng số test : \033[1m{$this->testsRun}\033[0m\n";
        echo "  Thành công   : \033[32m{$this->testsPassed}\033[0m\n";
        echo "  Thất bại     : " . ($this->testsFailed > 0 ? "\033[31m{$this->testsFailed}\033[0m" : "0") . "\n";
        echo "  Assertions   : \033[1m{$this->totalAssertions}\033[0m\n";
        echo "  Thời gian    : \033[1m{$totalTime} giây\033[0m\n";
        echo "\033[1;36m======================================================================\033[0m\n\n";
    }
}

