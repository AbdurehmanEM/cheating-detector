<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$authUser = Auth::requireLogin();
$pdo = Database::connection();
$session = SessionManager::activeSession($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse([
        'ok' => true,
        'user' => $authUser,
        'session' => $session ? [
            'id' => (int) $session['id'],
            'exam_name' => $session['exam_name'],
            'status' => $session['status'],
        ] : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('GET or POST required', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    jsonError('Invalid JSON');
}

if (!$session) {
    jsonError('No active exam session', 409);
}

$action = $body['action'] ?? 'detect';
$sid = (int) $session['id'];

if ($action === 'detect') {
    $magnitude = isset($body['magnitude']) ? round((float) $body['magnitude'], 2) : 0;
    $delta = isset($body['delta']) ? round((float) $body['delta'], 2) : 0;
    $peak = isset($body['peak']) ? round((float) $body['peak'], 2) : $delta;
    $label = trim((string) ($body['label'] ?? 'electronics'));
    $details = sprintf(
        'Magnetometer spike — likely %s (Δ%.1f µT, field %.1f µT, peak %.1f µT)',
        $label,
        $delta,
        $magnitude,
        $peak
    );
    SessionManager::logTimeline($pdo, $sid, 'magnetometer_electronics', null, $details);
    SessionManager::logAction(
        $pdo,
        $sid,
        $authUser['display_name'] ?? 'Invigilator',
        'magnetometer_scan',
        null,
        $details
    );

    jsonResponse([
        'ok' => true,
        'logged' => true,
        'details' => $details,
    ]);
}

jsonError('Unknown action', 400);
