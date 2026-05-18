<?php
declare(strict_types=1);

class SessionManager
{
    public static function activeSession(PDO $pdo): ?array
    {
        $stmt = $pdo->query("SELECT * FROM exam_sessions WHERE status = 'active' ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function logTimeline(PDO $pdo, int $sessionId, string $type, ?string $deviceId, string $details): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO timeline_events (session_id, event_time, event_type, device_id, details) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sessionId, date('Y-m-d H:i:s'), $type, $deviceId, $details]);
    }

    public static function logAction(PDO $pdo, int $sessionId, string $name, string $type, ?string $deviceId, string $details): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO invigilator_actions (session_id, action_time, invigilator_name, action_type, device_id, details) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sessionId, date('Y-m-d H:i:s'), $name, $type, $deviceId, $details]);
    }

    public static function elapsed(string $startedAt): string
    {
        $start = strtotime($startedAt);
        $diff = max(0, time() - $start);
        $h = intdiv($diff, 3600);
        $m = intdiv($diff % 3600, 60);
        $s = $diff % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
