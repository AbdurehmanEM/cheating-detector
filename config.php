<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
if (!defined('DB_PATH')) {
    define('DB_PATH', APP_ROOT . '/data/exam_detection.db');
}
if (!defined('SCAN_INTERVAL_SECONDS')) {
    define('SCAN_INTERVAL_SECONDS', 20);
}
if (!defined('API_KEY')) {
    define('API_KEY', getenv('EXAM_SCAN_API_KEY') ?: 'change-me-in-production');
}

if (!function_exists('exam_config')) {
    function exam_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        date_default_timezone_set(getenv('TZ') ?: 'Africa/Lagos');
        $config = [
            'app_name' => 'Exam Phone Detection System',
            'mode' => 'ZERO_EXEMPTION',
            'scan_interval' => SCAN_INTERVAL_SECONDS,
            'default_subnets' => ['192.168.1.0/24', '192.168.0.0/24', '10.0.0.0/24'],
            'mac_vendor_api' => 'https://api.macvendors.com/',
            'oui_cache_ttl_hours' => 168,
            'database' => [
                'driver' => getenv('EXAM_DB_DRIVER') ?: 'mysql',
                'mysql' => [
                    'host' => getenv('EXAM_DB_HOST') ?: '127.0.0.1',
                    'port' => (int) (getenv('EXAM_DB_PORT') ?: 3306),
                    'database' => getenv('EXAM_DB_NAME') ?: 'Exam_phone_detector',
                    'username' => getenv('EXAM_DB_USER') ?: 'root',
                    'password' => getenv('EXAM_DB_PASS') ?: '',
                    'charset' => 'utf8mb4',
                ],
            ],
            'auth' => [
                'session_name' => 'EXAM_DETECTION_SESS',
                'session_lifetime' => 28800,
            ],
        ];
        $localConfig = APP_ROOT . '/config.local.php';
        if (is_file($localConfig)) {
            $local = require $localConfig;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
        return $config;
    }
}

return exam_config();
