<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    jsonError('Invalid JSON');
}

$pdo = Database::connection();
$session = SessionManager::activeSession($pdo);
if (!$session) {
    jsonError('No active exam session. Start a session from the dashboard.', 409);
}

try {
    $result = ScanProcessor::process((int) $session['id'], $body);
    $devices = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? ORDER BY alert_level DESC, last_seen DESC');
    $devices->execute([$session['id']]);
    $formatted = array_map([ScanProcessor::class, 'formatDeviceRow'], $devices->fetchAll());

    jsonResponse([
        'ok' => true,
        'scan_id' => $result['scan_id'],
        'scan_time' => $result['scan_time'],
        'subnets_scanned' => $body['subnets_scanned'] ?? [],
        'total_devices' => $result['total_devices'],
        'devices' => $formatted,
        'new_alerts' => $result['new_alerts'],
        'escalation' => $result['escalation'],
        'bluetooth_phones' => $result['bluetooth_phones'] ?? [],
        'new_bluetooth_phones' => $result['new_bluetooth_phones'] ?? [],
        'new_wifi_phones' => $result['new_wifi_phones'] ?? [],
        'wifi_phones_detected' => count(array_filter($formatted, static fn ($d) => !empty($d['wifi_likely_phone']))),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
