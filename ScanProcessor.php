<?php
declare(strict_types=1);

require_once APP_ROOT . '/includes/Database.php';
require_once APP_ROOT . '/includes/DeviceClassifier.php';
require_once APP_ROOT . '/includes/AlertEngine.php';
require_once APP_ROOT . '/includes/SessionManager.php';

class ScanProcessor
{
    public static function process(int $sessionId, array $payload): array
    {
        $pdo = Database::connection();
        $session = $pdo->prepare('SELECT * FROM exam_sessions WHERE id = ?');
        $session->execute([$sessionId]);
        $exam = $session->fetch();
        if (!$exam || $exam['status'] !== 'active') {
            throw new RuntimeException('No active exam session');
        }

        $examStarted = !empty($exam['started_at']);
        $scanTime = $payload['scan_time'] ?? date('Y-m-d H:i:s');
        $scanId = $payload['scan_id'] ?? ('SCN-' . str_pad((string) ((int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn() + 1), 3, '0', STR_PAD_LEFT));
        $subnets = $payload['subnets_scanned'] ?? [];
        $incoming = $payload['devices'] ?? [];

        $pdo->prepare(
            'INSERT INTO scans (scan_id, session_id, scan_time, subnets_scanned, total_devices, raw_json) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $scanId,
            $sessionId,
            $scanTime,
            json_encode($subnets),
            count($incoming),
            json_encode($payload),
        ]);

        $seenMacs = [];
        $newAlerts = [];
        $newBluetoothPhones = [];
        $newWifiPhones = [];

        foreach ($incoming as $raw) {
            $mac = DeviceClassifier::normalizeMac($raw['mac'] ?? '');
            if ($mac === '' || $mac === '00:00:00:00:00:00') {
                continue;
            }
            $seenMacs[] = $mac;

            $macRandomized = DeviceClassifier::isMacRandomized($mac);
            $oui = DeviceClassifier::extractOui($mac);
            $vendorHint = trim((string) ($raw['vendor_hint'] ?? ''));
            $hasWifiIp = !empty($raw['ip']) && filter_var($raw['ip'], FILTER_VALIDATE_IP);
            if ($macRandomized && $vendorHint === '') {
                $vendor = 'Locally Administered (Randomized)';
            } else {
                $vendor = DeviceClassifier::lookupVendor($oui, $pdo);
                if (($vendor === null || $vendor === '') && $vendorHint !== '') {
                    $vendor = $vendorHint;
                }
                if ($macRandomized && $vendorHint !== '') {
                    $vendor = $vendorHint;
                }
            }
            $hostname = $raw['hostname'] ?? 'unknown';
            $btPhone = !empty($raw['bt_likely_phone']);
            $wifiPhone = !empty($raw['wifi_likely_phone'])
                || DeviceClassifier::isWifiPhoneByMac(
                    $mac,
                    $vendor,
                    $hostname,
                    $macRandomized,
                    $hasWifiIp,
                    $raw['ip'] ?? null
                );
            $macReason = trim((string) ($raw['mac_phone_reason'] ?? ''));
            if ($wifiPhone && $macReason === '') {
                if (DeviceClassifier::isKnownPhoneOui($mac)) {
                    $macReason = 'known_phone_oui';
                } elseif (DeviceClassifier::isWifiPhoneVendor($vendor)) {
                    $macReason = 'phone_vendor_oui';
                } elseif (DeviceClassifier::isWifiPhoneHostname($hostname)) {
                    $macReason = 'phone_hostname';
                } elseif ($macRandomized && $hasWifiIp) {
                    $macReason = 'wifi_privacy_mac';
                }
            }
            $class = DeviceClassifier::classify($vendor, $macRandomized, $hostname, $btPhone, $wifiPhone);
            if ($wifiPhone && !$btPhone) {
                $class = [
                    'category' => 'MOBILE PHONE (WI-FI)',
                    'alert_level' => 'CRITICAL',
                ];
            }

            $existing = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? AND mac = ?');
            $existing->execute([$sessionId, $mac]);
            $device = $existing->fetch();

            $now = date('H:i:s');
            $isNew = !$device;
            $joinedAfterExam = $examStarted && $isNew;

            if ($device && !empty($device['cleared_by']) && (int) $device['disconnect_count'] > 0) {
                $pdo->prepare(
                    'UPDATE devices SET cleared_by = NULL, cleared_at = NULL, status = ?, alert_level = ?, last_seen = ?, disconnect_count = 0 WHERE id = ?'
                )->execute([
                    $joinedAfterExam ? 'CRITICAL' : 'FLAGGED',
                    $class['alert_level'],
                    $now,
                    $device['id'],
                ]);
                SessionManager::logTimeline($pdo, $sessionId, 'reconnect_reflagged', $device['device_id'], "Device {$mac} reconnected after clearance — re-flagged");
                $refetch = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? AND mac = ?');
                $refetch->execute([$sessionId, $mac]);
                $device = $refetch->fetch();
                $isNew = false;
            }

            $status = 'FLAGGED';
            $alertLevel = $class['alert_level'];
            if ($joinedAfterExam) {
                $status = 'CRITICAL';
                $alertLevel = 'CRITICAL';
            }
            if ($macRandomized) {
                $alertLevel = 'CRITICAL';
                $status = $status === 'CLEARED' ? 'FLAGGED' : ($joinedAfterExam ? 'CRITICAL' : $status);
            }
            if ($btPhone || $wifiPhone) {
                $alertLevel = 'CRITICAL';
                $status = $joinedAfterExam ? 'CRITICAL' : ($status === 'CLEARED' ? 'FLAGGED' : $status);
            }

            if ($isNew) {
                $deviceId = AlertEngine::nextDeviceId($pdo);
                $firstSeen = $raw['first_seen'] ?? $now;
                $pdo->prepare(<<<'SQL'
INSERT INTO devices (
    device_id, session_id, mac, ip, hostname, signal_strength, band,
    first_seen, last_seen, status, mac_randomized, oui, vendor, category,
    alert_level, joined_after_exam, discovery_source, bt_likely_phone, wifi_likely_phone, mac_phone_reason
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL)->execute([
                    $deviceId,
                    $sessionId,
                    $mac,
                    $raw['ip'] ?? '',
                    $raw['hostname'] ?? 'unknown',
                    $raw['signal_strength'] ?? '',
                    $raw['band'] ?? 'unknown',
                    $firstSeen,
                    $raw['last_seen'] ?? $now,
                    $status,
                    $macRandomized ? 1 : 0,
                    $oui,
                    $vendor ?? 'Unknown',
                    $class['category'],
                    $alertLevel,
                    $joinedAfterExam ? 1 : 0,
                    $raw['discovery_source'] ?? 'scan',
                    $btPhone ? 1 : 0,
                    $wifiPhone ? 1 : 0,
                    $macReason ?: null,
                ]);
                SessionManager::logTimeline($pdo, $sessionId, 'device_join', $deviceId, "New device {$mac} — {$status}");
                if ($joinedAfterExam) {
                    SessionManager::logTimeline($pdo, $sessionId, 'critical_join', $deviceId, 'Device joined after exam start');
                }
                if ($btPhone) {
                    SessionManager::logTimeline($pdo, $sessionId, 'bluetooth_phone', $deviceId, "Bluetooth phone detected: {$hostname} ({$mac})");
                    $newBluetoothPhones[] = ['device_id' => $deviceId, 'mac' => $mac, 'hostname' => $hostname];
                }
                if ($wifiPhone) {
                    $reason = $macReason ? " ({$macReason})" : '';
                    SessionManager::logTimeline($pdo, $sessionId, 'wifi_phone', $deviceId, "Wi-Fi phone detected by MAC{$reason}: {$hostname} ({$mac})");
                    $newWifiPhones[] = ['device_id' => $deviceId, 'mac' => $mac, 'hostname' => $hostname, 'reason' => $macReason];
                }
            } else {
                $deviceId = $device['device_id'];
                $wasBt = !empty($device['bt_likely_phone']);
                $wasWifi = !empty($device['wifi_likely_phone']);
                if ($btPhone && !$wasBt) {
                    SessionManager::logTimeline($pdo, $sessionId, 'bluetooth_phone', $deviceId, "Bluetooth phone detected: {$hostname} ({$mac})");
                    $newBluetoothPhones[] = ['device_id' => $deviceId, 'mac' => $mac, 'hostname' => $hostname];
                }
                if ($wifiPhone && !$wasWifi) {
                    $reason = $macReason ? " ({$macReason})" : '';
                    SessionManager::logTimeline($pdo, $sessionId, 'wifi_phone', $deviceId, "Wi-Fi phone detected by MAC{$reason}: {$hostname} ({$mac})");
                    $newWifiPhones[] = ['device_id' => $deviceId, 'mac' => $mac, 'hostname' => $hostname, 'reason' => $macReason];
                }
                if (empty($device['cleared_by'])) {
                    $pdo->prepare(
                        'UPDATE devices SET ip = ?, hostname = ?, signal_strength = ?, band = ?, last_seen = ?, vendor = ?, category = ?, alert_level = ?, mac_randomized = ?, status = ?, discovery_source = ?, bt_likely_phone = ?, wifi_likely_phone = ?, mac_phone_reason = ? WHERE id = ?'
                    )->execute([
                        $raw['ip'] ?? $device['ip'],
                        $raw['hostname'] ?? $device['hostname'],
                        $raw['signal_strength'] ?? $device['signal_strength'],
                        $raw['band'] ?? $device['band'],
                        $raw['last_seen'] ?? $now,
                        $vendor ?? $device['vendor'],
                        $class['category'],
                        $alertLevel,
                        $macRandomized ? 1 : 0,
                        $device['cleared_by'] ? 'CLEARED' : $status,
                        $raw['discovery_source'] ?? $device['discovery_source'],
                        $btPhone ? 1 : 0,
                        $wifiPhone ? 1 : 0,
                        $macReason !== '' ? $macReason : ($device['mac_phone_reason'] ?? null),
                        $device['id'],
                    ]);
                } else {
                    $pdo->prepare(
                        'UPDATE devices SET ip = ?, last_seen = ?, signal_strength = ?, band = ? WHERE id = ?'
                    )->execute([
                        $raw['ip'] ?? $device['ip'],
                        $raw['last_seen'] ?? $now,
                        $raw['signal_strength'] ?? $device['signal_strength'],
                        $raw['band'] ?? $device['band'],
                        $device['id'],
                    ]);
                }
            }

            $stmt = $pdo->prepare('SELECT * FROM devices WHERE session_id = ? AND mac = ?');
            $stmt->execute([$sessionId, $mac]);
            $updated = $stmt->fetch();

            if (empty($updated['cleared_by'])) {
                $alertId = AlertEngine::createAlert($pdo, $sessionId, $updated, $joinedAfterExam);
                if ($alertId) {
                    $newAlerts[] = $alertId;
                }
            }
        }

        // Mark disconnects (not seen this scan)
        $all = $pdo->prepare('SELECT * FROM devices WHERE session_id = ?');
        $all->execute([$sessionId]);
        foreach ($all->fetchAll() as $d) {
            if (!in_array($d['mac'], $seenMacs, true)) {
                $pdo->prepare('UPDATE devices SET disconnect_count = disconnect_count + 1 WHERE id = ?')->execute([$d['id']]);
                if ((int) $d['disconnect_count'] === 0) {
                    SessionManager::logTimeline($pdo, $sessionId, 'device_disconnect', $d['device_id'], "Device {$d['mac']} not seen in scan");
                }
            }
        }

        $btPhones = $pdo->prepare(
            "SELECT device_id, mac, hostname FROM devices WHERE session_id = ? AND bt_likely_phone = 1 AND (cleared_by IS NULL OR cleared_by = '')"
        );
        $btPhones->execute([$sessionId]);

        return [
            'scan_id' => $scanId,
            'scan_time' => $scanTime,
            'total_devices' => count($seenMacs),
            'new_alerts' => $newAlerts,
            'escalation' => AlertEngine::escalationStatus($pdo, $sessionId),
            'bluetooth_phones' => $btPhones->fetchAll(),
            'new_bluetooth_phones' => $newBluetoothPhones,
            'new_wifi_phones' => $newWifiPhones,
        ];
    }

    public static function formatDeviceRow(array $d): array
    {
        $first = strtotime('today ' . ($d['first_seen'] ?? '00:00:00'));
        $last = strtotime('today ' . ($d['last_seen'] ?? '00:00:00'));
        $dur = max(0, $last - $first);
        $duration = sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60);

        return [
            'id' => $d['device_id'],
            'ip' => $d['ip'],
            'mac' => $d['mac'],
            'hostname' => $d['hostname'],
            'signal_strength' => $d['signal_strength'],
            'band' => $d['band'],
            'first_seen' => $d['first_seen'],
            'last_seen' => $d['last_seen'],
            'duration' => $duration,
            'status' => $d['cleared_by'] ? 'CLEARED' : $d['status'],
            'mac_randomized' => (bool) $d['mac_randomized'],
            'oui' => $d['oui'],
            'vendor' => $d['vendor'],
            'category' => $d['category'],
            'alert_level' => $d['cleared_by'] ? 'LOW' : $d['alert_level'],
            'cleared_by' => $d['cleared_by'],
            'cleared_at' => $d['cleared_at'],
            'discovery_source' => $d['discovery_source'] ?? null,
            'bt_likely_phone' => (bool) ($d['bt_likely_phone'] ?? 0),
            'wifi_likely_phone' => (bool) ($d['wifi_likely_phone'] ?? 0),
            'mac_phone_reason' => $d['mac_phone_reason'] ?? '',
        ];
    }
}
