<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$authUser = Auth::requireLogin();
$pdo = Database::connection();
$session = SessionManager::activeSession($pdo);

if (!$session) {
    jsonResponse([
        'ok' => true,
        'user' => $authUser,
        'session' => null,
        'summary' => ['total' => 0, 'CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0],
        'devices' => [],
        'alerts' => [],
        'timeline' => [],
        'actions' => [],
        'zones' => [],
        'escalation' => ['critical_count' => 0, 'escalation_level' => 'normal', 'escalation_message' => ''],
    ]);
}

$sid = (int) $session['id'];
$devices = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? ORDER BY CASE alert_level WHEN \'CRITICAL\' THEN 1 WHEN \'HIGH\' THEN 2 WHEN \'MEDIUM\' THEN 3 ELSE 4 END, last_seen DESC');
$devices->execute([$sid]);
$deviceRows = $devices->fetchAll();
$formatted = array_map([ScanProcessor::class, 'formatDeviceRow'], $deviceRows);

$counts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
foreach ($formatted as $d) {
    $lvl = $d['alert_level'] ?? 'MEDIUM';
    if (isset($counts[$lvl])) {
        $counts[$lvl]++;
    }
}

$alerts = $pdo->prepare('SELECT * FROM alerts WHERE session_id = ? ORDER BY id DESC LIMIT 200');
$alerts->execute([$sid]);

$timeline = $pdo->prepare('SELECT * FROM timeline_events WHERE session_id = ? ORDER BY id DESC LIMIT 100');
$timeline->execute([$sid]);

$actions = $pdo->prepare('SELECT * FROM invigilator_actions WHERE session_id = ? ORDER BY id DESC LIMIT 50');
$actions->execute([$sid]);

$zones = self_buildZoneHeatmap($formatted);

$bluetoothPhones = array_values(array_filter($formatted, static function (array $d): bool {
    return !empty($d['bt_likely_phone'])
        && ($d['status'] ?? '') !== 'CLEARED'
        && str_contains(strtoupper($d['category'] ?? ''), 'BLUETOOTH');
}));

$wifiPhones = array_values(array_filter($formatted, static function (array $d): bool {
    if (($d['status'] ?? '') === 'CLEARED') {
        return false;
    }
    if (!empty($d['wifi_likely_phone'])) {
        return true;
    }
    $cat = strtoupper($d['category'] ?? '');
    return str_contains($cat, 'WI-FI')
        || (str_contains($cat, 'MOBILE PHONE') && !str_contains($cat, 'BLUETOOTH') && !empty($d['ip']));
}));

jsonResponse([
    'ok' => true,
    'user' => $authUser,
    'session' => [
        'id' => $sid,
        'exam_name' => $session['exam_name'],
        'exam_date' => $session['exam_date'],
        'start_time' => $session['start_time'],
        'started_at' => $session['started_at'],
        'elapsed' => $session['started_at'] ? SessionManager::elapsed($session['started_at']) : '00:00:00',
        'status' => $session['status'],
        'chief_invigilator' => $session['chief_invigilator'],
        'mode' => 'ZERO_EXEMPTION',
    ],
    'summary' => [
        'total' => count($formatted),
        'CRITICAL' => $counts['CRITICAL'],
        'HIGH' => $counts['HIGH'],
        'MEDIUM' => $counts['MEDIUM'],
        'LOW' => $counts['LOW'],
    ],
    'devices' => $formatted,
    'alerts' => $alerts->fetchAll(),
    'timeline' => $timeline->fetchAll(),
    'actions' => $actions->fetchAll(),
    'zones' => $zones,
    'escalation' => AlertEngine::escalationStatus($pdo, $sid),
    'refresh_seconds' => SCAN_INTERVAL_SECONDS,
    'bluetooth' => [
        'phone_count' => count($bluetoothPhones),
        'phones' => $bluetoothPhones,
        'alarm_active' => count($bluetoothPhones) > 0,
    ],
    'wifi' => [
        'phone_count' => count($wifiPhones),
        'phones' => $wifiPhones,
        'alarm_active' => count($wifiPhones) > 0,
    ],
]);

function self_buildZoneHeatmap(array $devices): array
{
    $zones = [
        'Zone A' => ['risk' => 0, 'devices' => 0],
        'Zone B' => ['risk' => 0, 'devices' => 0],
        'Zone C' => ['risk' => 0, 'devices' => 0],
        'Zone D' => ['risk' => 0, 'devices' => 0],
        'Zone E' => ['risk' => 0, 'devices' => 0],
    ];
    foreach ($devices as $d) {
        if (!empty($d['cleared_by'])) {
            continue;
        }
        $zone = DeviceClassifier::estimateZone($d['signal_strength'] ?? null);
        $key = 'Zone A';
        if (str_contains($zone, 'Zone B')) {
            $key = 'Zone B';
        } elseif (str_contains($zone, 'Zone C')) {
            $key = 'Zone C';
        } elseif (str_contains($zone, 'Zone D')) {
            $key = 'Zone D';
        } elseif (str_contains($zone, 'Zone E')) {
            $key = 'Zone E';
        }
        $zones[$key]['devices']++;
        $weight = match ($d['alert_level'] ?? 'MEDIUM') {
            'CRITICAL' => 4,
            'HIGH' => 3,
            'MEDIUM' => 2,
            default => 1,
        };
        $zones[$key]['risk'] += $weight;
    }
    return $zones;
}
