<?php
declare(strict_types=1);

class AlertEngine
{
    public static function nextAlertId(PDO $pdo): string
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
        return sprintf('ALT-%03d', $count + 1);
    }

    public static function nextDeviceId(PDO $pdo): string
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
        return sprintf('DEV-%03d', $count + 1);
    }

    public static function createAlert(
        PDO $pdo,
        int $sessionId,
        array $device,
        bool $isNewJoin
    ): ?string {
        if (!empty($device['cleared_by'])) {
            return null;
        }

        $dup = $pdo->prepare(
            'SELECT alert_id FROM alerts WHERE session_id = ? AND device_id = ? AND cleared = 0 ORDER BY id DESC LIMIT 1'
        );
        $dup->execute([$sessionId, $device['device_id']]);
        if ($dup->fetch() && !$isNewJoin) {
            return null;
        }

        $level = $device['alert_level'] ?? 'MEDIUM';
        if ($isNewJoin && ($device['status'] ?? '') !== 'CLEARED') {
            $level = 'CRITICAL';
        }
        if (!empty($device['mac_randomized'])) {
            $level = 'CRITICAL';
        }

        $zone = DeviceClassifier::estimateZone($device['signal_strength'] ?? null);
        $alertId = self::nextAlertId($pdo);
        $now = date('H:i:s');

        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO alerts (
    alert_id, session_id, device_id, alert_level, alert_time,
    mac_address, mac_randomized, vendor, category, signal_strength,
    estimated_zone, recommended_action, acknowledged, cleared
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
SQL);

        $stmt->execute([
            $alertId,
            $sessionId,
            $device['device_id'],
            $level,
            $now,
            $device['mac'],
            !empty($device['mac_randomized']) ? 1 : 0,
            $device['vendor'] ?? 'Unknown',
            $device['category'] ?? 'UNKNOWN / UNIDENTIFIED',
            $device['signal_strength'] ?? '',
            $zone,
            DeviceClassifier::recommendedAction($level, $zone),
        ]);

        return $alertId;
    }

    public static function escalationStatus(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM alerts WHERE session_id = ? AND alert_level = 'CRITICAL' AND cleared = 0 AND acknowledged = 0"
        );
        $stmt->execute([$sessionId]);
        $critical = (int) $stmt->fetchColumn();

        $level = 'normal';
        $message = '';
        if ($critical >= 5) {
            $level = 'disciplinary';
            $message = 'ORGANIZED CHEATING ALERT: 5+ CRITICAL devices. Escalate to disciplinary committee. Preserve full network log.';
        } elseif ($critical >= 3) {
            $level = 'coordinator';
            $message = 'EXAM COORDINATOR: 3+ simultaneous CRITICAL alerts. Consider pausing exam timer per policy.';
        } elseif ($critical >= 1) {
            $level = 'inspect';
            $message = 'Invigilator: physically inspect flagged zone(s).';
        }

        return [
            'critical_count' => $critical,
            'escalation_level' => $level,
            'escalation_message' => $message,
        ];
    }
}
