<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$chief = Auth::requireChief();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
$deviceId = $body['device_id'] ?? '';
$invigilator = trim($body['invigilator_name'] ?? $chief['display_name']);

if ($deviceId === '') {
    jsonError('device_id required');
}

$pdo = Database::connection();
$session = SessionManager::activeSession($pdo);
if (!$session) {
    jsonError('No active session', 404);
}

$stmt = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? AND device_id = ?');
$stmt->execute([$session['id'], $deviceId]);
$device = $stmt->fetch();
if (!$device) {
    jsonError('Device not found', 404);
}

$now = date('Y-m-d H:i:s');
$pdo->prepare(
    'UPDATE devices SET status = ?, alert_level = ?, cleared_by = ?, cleared_at = ? WHERE id = ?'
)->execute(['CLEARED', 'LOW', $invigilator, $now, $device['id']]);

$pdo->prepare('UPDATE alerts SET cleared = 1 WHERE session_id = ? AND device_id = ?')->execute([$session['id'], $deviceId]);

$actionStmt = $pdo->prepare(
    'INSERT INTO invigilator_actions (session_id, action_time, invigilator_name, user_id, action_type, device_id, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$actionStmt->execute([
    $session['id'],
    Database::now(),
    $invigilator,
    $chief['id'],
    'clear_device',
    $deviceId,
    "Cleared {$device['mac']} — still visible on dashboard",
]);
SessionManager::logTimeline($pdo, (int) $session['id'], 'device_cleared', $deviceId, "Cleared by {$invigilator}");

jsonResponse([
    'ok' => true,
    'device_id' => $deviceId,
    'mac' => $device['mac'],
    'cleared_by' => $invigilator,
    'cleared_at' => $now,
    'alert_level' => 'LOW',
    'note' => 'Device remains visible. Reconnect after disconnect will re-flag.',
]);
