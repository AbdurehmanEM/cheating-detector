<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/includes/Database.php';
require_once APP_ROOT . '/includes/SessionManager.php';
require_once APP_ROOT . '/includes/ScanProcessor.php';
require_once APP_ROOT . '/includes/Auth.php';

Auth::startSession();
Auth::requireLogin();

$pdo = Database::connection();
$session = SessionManager::activeSession($pdo);
$format = $_GET['format'] ?? 'csv';

if (!$session) {
    http_response_code(404);
    echo 'No active session';
    exit;
}

$sid = (int) $session['id'];
$devices = $pdo->prepare('SELECT * FROM devices WHERE session_id = ?');
$devices->execute([$sid]);
$rows = array_map([ScanProcessor::class, 'formatDeviceRow'], $devices->fetchAll());

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $session['exam_name']);
$filename = $safeName . '_' . date('Ymd_His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['device_id', 'ip', 'mac', 'hostname', 'vendor', 'category', 'alert_level', 'status', 'band', 'signal', 'first_seen', 'last_seen', 'cleared_by', 'cleared_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['ip'], $r['mac'], $r['hostname'], $r['vendor'], $r['category'],
            $r['alert_level'], $r['status'], $r['band'], $r['signal_strength'],
            $r['first_seen'], $r['last_seen'], $r['cleared_by'] ?? '', $r['cleared_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam Report — <?= htmlspecialchars($session['exam_name']) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; }
        h1 { font-size: 1.25rem; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #1a1a2e; color: #fff; }
        .critical { color: #c0392b; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">Print / Save as PDF</button>
    <h1><?= htmlspecialchars($session['exam_name']) ?> — Session Report</h1>
    <p>Date: <?= htmlspecialchars($session['exam_date']) ?> | Mode: ZERO EXEMPTION | Generated: <?= date('Y-m-d H:i:s') ?></p>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>IP</th><th>MAC</th><th>Vendor</th><th>Category</th><th>Alert</th><th>Status</th><th>Cleared By</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['ip']) ?></td>
                <td><?= htmlspecialchars($r['mac']) ?></td>
                <td><?= htmlspecialchars($r['vendor']) ?></td>
                <td><?= htmlspecialchars($r['category']) ?></td>
                <td class="<?= strtolower($r['alert_level']) === 'critical' ? 'critical' : '' ?>"><?= htmlspecialchars($r['alert_level']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['cleared_by'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
