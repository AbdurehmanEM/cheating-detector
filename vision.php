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
    $label = trim((string) ($body['label'] ?? 'cell phone'));
    $score = isset($body['score']) ? round((float) $body['score'], 3) : 0;
    $count = max(1, (int) ($body['count'] ?? 1));
    $details = sprintf(
        'AI vision — %s detected (confidence %.0f%%, %d in frame)',
        $label,
        $score * 100,
        $count
    );
    SessionManager::logTimeline($pdo, $sid, 'vision_phone', null, $details);
    SessionManager::logAction(
        $pdo,
        $sid,
        $authUser['display_name'] ?? 'Invigilator',
        'vision_scan',
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
