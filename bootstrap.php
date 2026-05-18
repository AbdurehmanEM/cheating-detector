<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config.php'; // defines exam_config()
require_once APP_ROOT . '/includes/Database.php';
require_once APP_ROOT . '/includes/DeviceClassifier.php';
require_once APP_ROOT . '/includes/AlertEngine.php';
require_once APP_ROOT . '/includes/SessionManager.php';
require_once APP_ROOT . '/includes/ScanProcessor.php';
require_once APP_ROOT . '/includes/Auth.php';

Auth::startSession();

function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $code = 400): void
{
    jsonResponse(['ok' => false, 'error' => $message], $code);
}

function requireApiKey(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    if ($key !== API_KEY) {
        jsonError('Unauthorized', 401);
    }
}
