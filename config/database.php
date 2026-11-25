<?php
/**
 * Database Configuration & PDO Connection
 * Status Page - Trung tâm CNTT Trường Đại học
 *
 * @author  System Architect
 * @version 1.0.0
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

define('DB_HOST', getenv('UTC_STATUS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('UTC_STATUS_DB_PORT') ?: '3306');
define('DB_NAME', getenv('UTC_STATUS_DB_NAME') ?: 'status_page_db');
define('DB_USER', getenv('UTC_STATUS_DB_USER') ?: 'root');
define('DB_PASS', getenv('UTC_STATUS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

class Database
{
    private static ?PDO $instance = null;

    /**
     * Private constructor – singleton pattern.
     */
    private function __construct() {}

    /**
     * Returns the single PDO instance (lazy initialization).
     *
     * @return PDO
     * @throws RuntimeException if connection fails.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'",
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                // In production, log this and never expose raw messages.
                error_log('[DB ERROR] ' . $e->getMessage());
                throw new RuntimeException('Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.');
            }
        }

        return self::$instance;
    }

    /** Prevent cloning */
    private function __clone() {}
}
