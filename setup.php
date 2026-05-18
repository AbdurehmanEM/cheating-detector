<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config.php';
require APP_ROOT . '/includes/Database.php';

$pdo = Database::connection();
$cfg = exam_config();
$db = $cfg['database']['mysql']['database'] ?? 'Exam_phone_detector';

echo "Database driver: " . Database::driver() . PHP_EOL;
echo "Database name: {$db}" . PHP_EOL;
echo "Tables:" . PHP_EOL;
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo "  - {$t}" . PHP_EOL;
}
echo "Invigilators: " . $pdo->query('SELECT COUNT(*) FROM invigilators')->fetchColumn() . PHP_EOL;
