<?php
/**
 * UTC STATUS – Unit & Feature Test Base Case
 * Cung cấp hệ thống assertion, transaction rollback và runner mô phỏng HTTP API
 */

declare(strict_types=1);

namespace Tests;

use PDO;
use RuntimeException;

class AssertionException extends RuntimeException {}

abstract class TestCase {
    protected ?PDO $pdo = null;
    protected int $assertionCount = 0;
    protected bool $useTransaction = true;

    public function setUp(): void {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../config/permissions.php';
        require_once __DIR__ . '/../config/service_status.php';

        $this->pdo = \Database::getInstance();

        if ($this->useTransaction) {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
        }
    }

    public function tearDown(): void {
        if ($this->useTransaction && $this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function getAssertionCount(): int {
        return $this->assertionCount;
    }

    // ══════════════════════════════════════════════════════════════
    //  ASSERTIONS
    // ══════════════════════════════════════════════════════════════

    public function assertTrue(mixed $condition, string $message = ''): void {
        $this->assertionCount++;
        if ($condition !== true) {
            $msg = $message !== '' ? $message : 'Failed asserting that condition is true.';
            throw new AssertionException($msg);
        }
    }

    public function assertFalse(mixed $condition, string $message = ''): void {
        $this->assertionCount++;
        if ($condition !== false) {
            $msg = $message !== '' ? $message : 'Failed asserting that condition is false.';
            throw new AssertionException($msg);
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($expected != $actual) {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            $msg = $message !== '' ? $message : "Failed asserting that {$actStr} equals expected {$expStr}.";
            throw new AssertionException($msg);
        }
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($expected == $actual) {
            $expStr = var_export($expected, true);
            $msg = $message !== '' ? $message : "Failed asserting that actual is not equal to {$expStr}.";
            throw new AssertionException($msg);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($expected !== $actual) {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            $msg = $message !== '' ? $message : "Failed asserting that {$actStr} is strictly identical to {$expStr}.";
            throw new AssertionException($msg);
        }
    }

    public function assertNull(mixed $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($actual !== null) {
            $actStr = var_export($actual, true);
            $msg = $message !== '' ? $message : "Failed asserting that {$actStr} is null.";
            throw new AssertionException($msg);
        }
    }

    public function assertNotNull(mixed $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($actual === null) {
            $msg = $message !== '' ? $message : 'Failed asserting that value is not null.';
            throw new AssertionException($msg);
        }
    }

    public function assertCount(int $expectedCount, mixed $countable, string $message = ''): void {
        $this->assertionCount++;
        $actualCount = is_countable($countable) ? count($countable) : 0;
        if ($expectedCount !== $actualCount) {
            $msg = $message !== '' ? $message : "Failed asserting that count({$actualCount}) matches expected count({$expectedCount}).";
            throw new AssertionException($msg);
        }
    }

    public function assertArrayHasKey(int|string $key, array $array, string $message = ''): void {
        $this->assertionCount++;
        if (!array_key_exists($key, $array)) {
            $msg = $message !== '' ? $message : "Failed asserting that array has key '{$key}'.";
            throw new AssertionException($msg);
        }
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void {
        $this->assertionCount++;
        if (!str_contains($haystack, $needle)) {
            $msg = $message !== '' ? $message : "Failed asserting that string contains '{$needle}'.";
            throw new AssertionException($msg);
        }
    }

    public function assertGreaterThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($actual < $expected) {
            $msg = $message !== '' ? $message : "Failed asserting that {$actual} is greater than or equal to {$expected}.";
            throw new AssertionException($msg);
        }
    }

    public function assertLessThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($actual > $expected) {
            $msg = $message !== '' ? $message : "Failed asserting that {$actual} is less than or equal to {$expected}.";
            throw new AssertionException($msg);
        }
    }

    public function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void {
        $this->assertionCount++;
        if (preg_match($pattern, $string) !== 1) {
            $msg = $message !== '' ? $message : "Failed asserting that '{$string}' matches pattern '{$pattern}'.";
            throw new AssertionException($msg);
        }
    }

    public function fail(string $message = 'Test failed explicitly.'): void {
        throw new AssertionException($message);
    }

    // ══════════════════════════════════════════════════════════════
    //  API SUBPROCESS RUNNER
    // ══════════════════════════════════════════════════════════════

    /**
     * Thực thi một API script độc lập qua PHP CLI (cô lập hoàn toàn session & headers)
     */
    public function callEndpoint(
        string $relativePath,
        array $queryParams = [],
        array $postParams = [],
        array $sessionData = [],
        string $method = 'GET'
    ): array {
        $rootDir = realpath(__DIR__ . '/..');
        $scriptPath = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));

        if (!file_exists($scriptPath)) {
            throw new RuntimeException("Target script not found: {$scriptPath}");
        }

        $phpExe = 'C:\\xampp\\php\\php.exe';
        if (!file_exists($phpExe)) {
            $phpExe = 'php';
        }

        $sessionExport = var_export($sessionData, true);
        $getExport     = var_export($queryParams, true);
        $postExport    = var_export($postParams, true);
        $methodExport  = var_export(strtoupper($method), true);
        $scriptExport  = var_export($scriptPath, true);
        $rootExport    = var_export($rootDir, true);

        $bootstrapCode = <<<PHP
<?php
chdir({$rootExport});
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
\$_SERVER['REQUEST_METHOD'] = {$methodExport};
\$_GET  = {$getExport};
\$_POST = {$postExport};
\$_REQUEST = array_merge(\$_GET, \$_POST);

\$sData = {$sessionExport};
if (!empty(\$sData)) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    foreach (\$sData as \$k => \$v) {
        \$_SESSION[\$k] = \$v;
    }
}

register_shutdown_function(function() {
    \$code = http_response_code();
    if (\$code === false || \$code === 0) {
        \$code = 200;
    }
    echo "\n<!-- HTTP_STATUS: {\$code} -->\n";
    foreach (headers_list() as \$h) {
        echo "<!-- HTTP_HEADER: {\$h} -->\n";
    }
});

require {$scriptExport};
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open([$phpExe], $descriptors, $pipes, $rootDir);
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot start PHP subprocess for {$relativePath}");
        }

        fwrite($pipes[0], $bootstrapCode);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $httpCode = 200;
        if (preg_match('/<!-- HTTP_STATUS:\s*(\d+)\s*-->/', $stdout, $codeMatch)) {
            $httpCode = (int) $codeMatch[1];
        }

        $headers = [];
        if (preg_match_all('/<!-- HTTP_HEADER:\s*(.+?)\s*-->/', $stdout, $headerMatches)) {
            $headers = $headerMatches[1];
        }

        // Clean internal debug comments from stdout for JSON parsing
        $cleanOutput = preg_replace('/<!-- HTTP_STATUS:.*?-->\s*/s', '', $stdout);
        $cleanOutput = preg_replace('/<!-- HTTP_HEADER:.*?-->\s*/s', '', $cleanOutput);
        $cleanOutput = trim($cleanOutput);

        $json = null;
        $decodedDirect = json_decode($cleanOutput, true);
        if (is_array($decodedDirect)) {
            $json = $decodedDirect;
        } else {
            $firstBrace = strpos($cleanOutput, '{');
            $lastBrace  = strrpos($cleanOutput, '}');
            $firstBracket = strpos($cleanOutput, '[');
            $lastBracket  = strrpos($cleanOutput, ']');

            $startPos = false;
            $endPos = false;
            if ($firstBrace !== false && ($firstBracket === false || $firstBrace < $firstBracket)) {
                $startPos = $firstBrace;
                $endPos = $lastBrace;
            } elseif ($firstBracket !== false) {
                $startPos = $firstBracket;
                $endPos = $lastBracket;
            }

            if ($startPos !== false && $endPos !== false && $endPos >= $startPos) {
                $jsonCandidate = substr($cleanOutput, $startPos, $endPos - $startPos + 1);
                $decoded = json_decode($jsonCandidate, true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }
        }

        return [
            'exit_code' => $exitCode,
            'http_code' => $httpCode,
            'headers'   => $headers,
            'stdout'    => $cleanOutput,
            'raw_stdout'=> $stdout,
            'stderr'    => $stderr,
            'json'      => $json,
            'is_json'   => is_array($json),
        ];
    }
}
