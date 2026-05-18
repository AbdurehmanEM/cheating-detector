<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$method = $_SERVER['REQUEST_METHOD'];
$user = Auth::user();

if ($method === 'GET') {
    Auth::requireLogin();
    $session = SessionManager::activeSession($pdo);
    if (!$session) {
        jsonResponse(['ok' => true, 'session' => null]);
    }
    $elapsed = $session['started_at'] ? SessionManager::elapsed($session['started_at']) : '00:00:00';
    jsonResponse([
        'ok' => true,
        'session' => [
            'id' => (int) $session['id'],
            'exam_name' => $session['exam_name'],
            'exam_date' => $session['exam_date'],
            'start_time' => $session['start_time'],
            'started_at' => $session['started_at'],
            'elapsed' => $elapsed,
            'status' => $session['status'],
            'chief_invigilator' => $session['chief_invigilator'],
            'mode' => 'ZERO_EXEMPTION',
        ],
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $action = $body['action'] ?? 'start';

    if ($action === 'start') {
        $chief = Auth::requireChief();
        $now = Database::now();
        $closedAt = $now;
        $pdo->prepare("UPDATE exam_sessions SET status = 'closed', closed_at = ? WHERE status = 'active'")->execute([$closedAt]);
        $stmt = $pdo->prepare(
            'INSERT INTO exam_sessions (hall_code, exam_name, exam_date, start_time, started_at, status, chief_invigilator, started_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $body['hall_code'] ?? $chief['hall_code'],
            $body['exam_name'] ?? 'Exam Session',
            $body['exam_date'] ?? date('Y-m-d'),
            $body['start_time'] ?? date('H:i:s'),
            $now,
            'active',
            $body['chief_invigilator'] ?? $chief['display_name'],
            $chief['id'],
        ]);
        $id = (int) $pdo->lastInsertId();
        SessionManager::logTimeline($pdo, $id, 'session_start', null, 'Exam session started — ZERO EXEMPTION mode');
        jsonResponse(['ok' => true, 'session_id' => $id, 'started_at' => $now]);
    }

    if ($action === 'close') {
        Auth::requireChief();
        $session = SessionManager::activeSession($pdo);
        if (!$session) {
            jsonError('No active session', 404);
        }
        $pdo->prepare("UPDATE exam_sessions SET status = 'closed', closed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $session['id']]);
        SessionManager::logTimeline($pdo, (int) $session['id'], 'session_close', null, 'Exam session closed by chief invigilator');
        jsonResponse(['ok' => true, 'closed' => true]);
    }

    jsonError('Unknown action');
}

jsonError('Method not allowed', 405);
