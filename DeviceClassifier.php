<?php
declare(strict_types=1);

class DeviceClassifier
{
    private static ?array $phoneMacData = null;

    private const MOBILE_VENDORS = [
        'apple', 'samsung', 'xiaomi', 'oppo', 'vivo', 'huawei', 'oneplus', 'realme',
        'tecno', 'itel', 'infinix', 'nokia', 'motorola', 'google', 'sony', 'transsion',
        'zte', 'alcatel', 'htc', 'lg electronics', 'lg ', 'meizu', 'honor', 'nothing',
        'fairphone', 'bbk', 'gionee', 'lenovo mobile', 'micromax',
    ];

    private const TABLET_WEARABLE_KEYWORDS = [
        'ipad', 'galaxy tab', 'tablet', 'smartwatch', 'watch', 'fitbit', 'garmin',
        'amazfit', 'earbud', 'airpods', 'buds', 'wearable', 'band',
    ];

    private const LAPTOP_VENDORS = [
        'hewlett', 'hp ', ' hp', 'dell', 'lenovo', 'acer', 'asus', 'microsoft',
        'toshiba', 'msi', 'razer', 'matebook', 'intel corporate',
    ];

    private const NETWORK_VENDORS = [
        'cisco', 'tp-link', 'tplink', 'netgear', 'ubiquiti', 'mikrotik', 'aruba',
        'ruckus', 'd-link', 'linksys', 'juniper', 'fortinet', 'meraki', 'zyxel',
    ];

    private const PERIPHERAL_VENDORS = [
        'canon', 'epson', 'ricoh', 'optoma', 'brother', 'xerox', 'kyocera',
        'benq', 'viewsonic', 'nec display', 'sharp', 'konica',
    ];

    public static function isMacRandomized(string $mac): bool
    {
        $mac = strtolower(str_replace(['-', '.'], ':', $mac));
        $parts = explode(':', $mac);
        if (count($parts) < 1 || $parts[0] === '') {
            return false;
        }
        $firstOctet = hexdec($parts[0]);
        if ($firstOctet === 0 && $parts[0] !== '00') {
            return false;
        }
        return ($firstOctet & 0x02) !== 0;
    }

    public static function extractOui(string $mac): string
    {
        $mac = strtoupper(str_replace(['-', '.'], ':', $mac));
        $parts = explode(':', $mac);
        return implode(':', array_slice($parts, 0, 3));
    }

    public static function normalizeMac(string $mac): string
    {
        $mac = preg_replace('/[^a-fA-F0-9]/', '', $mac) ?? '';
        if (strlen($mac) !== 12) {
            return strtoupper(str_replace(['-', '.'], ':', $mac));
        }
        return strtoupper(implode(':', str_split($mac, 2)));
    }

    public static function lookupVendor(string $oui, PDO $pdo): ?string
    {
        $stmt = $pdo->prepare('SELECT vendor FROM oui_cache WHERE oui = ?');
        $stmt->execute([$oui]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['vendor'];
        }

        $config = exam_config();
        $url = $config['mac_vendor_api'] . urlencode(str_replace(':', '', $oui));
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $vendor = @file_get_contents($url, false, $ctx);
        if ($vendor === false || str_contains((string) $vendor, 'Not Found')) {
            return null;
        }
        $vendor = trim($vendor);
        $now = date('Y-m-d H:i:s');
        if (Database::isMysql()) {
            $ins = $pdo->prepare('REPLACE INTO oui_cache (oui, vendor, fetched_at) VALUES (?, ?, ?)');
        } else {
            $ins = $pdo->prepare('INSERT OR REPLACE INTO oui_cache (oui, vendor, fetched_at) VALUES (?, ?, ?)');
        }
        $ins->execute([$oui, $vendor, $now]);
        return $vendor;
    }

    private const BLUETOOTH_PHONE_KEYWORDS = [
        'iphone', 'ipad', 'galaxy', 'pixel', 'redmi', 'oppo', 'vivo', 'oneplus', 'huawei',
        'honor', 'nokia', 'motorola', 'xperia', 'tecno', 'infinix', 'itel', 'realme',
        'phone', 'mobile', 'android', 'smartphone', 'nothing phone',
    ];

    public static function isBluetoothPhoneName(?string $hostname): bool
    {
        if ($hostname === null || trim($hostname) === '' || $hostname === 'unknown') {
            return false;
        }
        $n = strtolower($hostname);
        foreach (self::BLUETOOTH_PHONE_KEYWORDS as $kw) {
            if (str_contains($n, $kw)) {
                return true;
            }
        }
        return (bool) preg_match('/\bsm-[a-z]\d{3}/i', $hostname);
    }

    private static function phoneMacData(): array
    {
        if (self::$phoneMacData !== null) {
            return self::$phoneMacData;
        }
        $path = APP_ROOT . '/data/phone_mac_oui.json';
        if (!is_readable($path)) {
            self::$phoneMacData = ['oui_prefixes' => [], 'vendor_keywords' => [], 'hostname_keywords' => []];
            return self::$phoneMacData;
        }
        $json = json_decode((string) file_get_contents($path), true);
        self::$phoneMacData = is_array($json) ? $json : ['oui_prefixes' => [], 'vendor_keywords' => [], 'hostname_keywords' => []];
        return self::$phoneMacData;
    }

    public static function isKnownPhoneOui(string $mac): bool
    {
        $oui = self::extractOui($mac);
        if ($oui === '') {
            return false;
        }
        $prefixes = array_map(
            static fn ($p) => strtoupper(str_replace('-', ':', (string) $p)),
            self::phoneMacData()['oui_prefixes'] ?? []
        );
        return in_array($oui, $prefixes, true);
    }

    public static function isLikelyGateway(?string $ip, ?string $hostname = null): bool
    {
        if ($ip === null || $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $last = (int) substr(strrchr($ip, '.'), 1);
        if (in_array($last, [0, 1, 254], true) && !self::isWifiPhoneHostname($hostname)) {
            return true;
        }
        if ($hostname !== null && $hostname !== '') {
            $h = strtolower($hostname);
            foreach (['router', 'gateway', 'switch', 'mikrotik', 'tplink', 'archer', 'decoh', 'extender'] as $kw) {
                if (str_contains($h, $kw)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function isWifiPhoneHostname(?string $hostname): bool
    {
        if ($hostname === null || trim($hostname) === '' || strtolower(trim($hostname)) === 'unknown') {
            return false;
        }
        $h = strtolower($hostname);
        foreach (self::phoneMacData()['hostname_keywords'] ?? [] as $kw) {
            if (str_contains($h, strtolower((string) $kw))) {
                return true;
            }
        }
        if (preg_match('/\b(tecno|oppo|vivo|redmi|infinix|realme|samsung|iphone|pixel|honor|nokia|motorola|oneplus)-/i', $hostname)) {
            return true;
        }
        return (bool) preg_match('/\bsm-[a-z]\d{3}/i', $hostname)
            || (bool) preg_match('/\brmx\d{4}/i', $hostname);
    }

    public static function isWifiPhoneVendor(?string $vendor): bool
    {
        if ($vendor === null || trim($vendor) === '') {
            return false;
        }
        $v = strtolower($vendor);
        if (str_contains($v, 'locally administered')) {
            return false;
        }
        foreach (['router', 'switch', 'access point', 'laptop', 'notebook', 'desktop', 'server', 'printer'] as $bad) {
            if (str_contains($v, $bad)) {
                return false;
            }
        }
        foreach (self::phoneMacData()['vendor_keywords'] ?? [] as $kw) {
            if (str_contains($v, strtolower((string) $kw))) {
                return true;
            }
        }
        foreach (self::MOBILE_VENDORS as $mv) {
            if (str_contains($v, $mv)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wi-Fi mobile phone detection from MAC OUI, vendor string, and hostname.
     */
    public static function isWifiPhoneByMac(
        string $mac,
        ?string $vendor,
        ?string $hostname = null,
        bool $macRandomized = false,
        bool $hasWifiIp = true,
        ?string $ip = null
    ): bool {
        if (self::isLikelyGateway($ip, $hostname)) {
            return false;
        }
        if (self::isKnownPhoneOui($mac)) {
            return true;
        }
        if (self::isWifiPhoneVendor($vendor)) {
            return true;
        }
        if (self::isWifiPhoneHostname($hostname)) {
            return true;
        }
        return false;
    }

    public static function classify(
        ?string $vendor,
        bool $macRandomized,
        ?string $hostname = null,
        bool $bluetoothPhone = false,
        bool $wifiPhone = false
    ): array {
        if ($bluetoothPhone || self::isBluetoothPhoneName($hostname)) {
            return [
                'category' => 'MOBILE PHONE (BLUETOOTH)',
                'alert_level' => 'CRITICAL',
            ];
        }

        if ($wifiPhone) {
            return [
                'category' => 'MOBILE PHONE (WI-FI)',
                'alert_level' => 'CRITICAL',
            ];
        }

        if ($macRandomized) {
            return [
                'category' => 'MAC RANDOMIZED DEVICE',
                'alert_level' => 'CRITICAL',
            ];
        }

        if ($vendor === null || trim($vendor) === '') {
            return [
                'category' => 'UNKNOWN / UNIDENTIFIED',
                'alert_level' => 'CRITICAL',
            ];
        }

        $v = strtolower($vendor);

        foreach (self::TABLET_WEARABLE_KEYWORDS as $kw) {
            if (str_contains($v, $kw)) {
                return ['category' => 'TABLET OR WEARABLE', 'alert_level' => 'HIGH'];
            }
        }

        foreach (self::MOBILE_VENDORS as $mv) {
            if (str_contains($v, $mv)) {
                if (str_contains($v, 'ipad')) {
                    return ['category' => 'TABLET OR WEARABLE', 'alert_level' => 'HIGH'];
                }
                return ['category' => 'MOBILE PHONE', 'alert_level' => 'CRITICAL'];
            }
        }

        foreach (self::LAPTOP_VENDORS as $lv) {
            if (str_contains($v, $lv)) {
                return ['category' => 'LAPTOP OR COMPUTER', 'alert_level' => 'HIGH'];
            }
        }

        foreach (self::NETWORK_VENDORS as $nv) {
            if (str_contains($v, $nv)) {
                return ['category' => 'NETWORK INFRASTRUCTURE', 'alert_level' => 'MEDIUM'];
            }
        }

        foreach (self::PERIPHERAL_VENDORS as $pv) {
            if (str_contains($v, $pv)) {
                return ['category' => 'PERIPHERAL DEVICE', 'alert_level' => 'MEDIUM'];
            }
        }

        return [
            'category' => 'UNKNOWN / UNIDENTIFIED',
            'alert_level' => 'CRITICAL',
        ];
    }

    public static function estimateZone(?string $rssi): string
    {
        if ($rssi === null || $rssi === '') {
            return 'Zone — signal unknown';
        }
        preg_match('/-?\d+/', $rssi, $m);
        $dbm = (int) ($m[0] ?? -80);
        if ($dbm >= -50) {
            return 'Zone A, Rows 1-4 (very close)';
        }
        if ($dbm >= -60) {
            return 'Zone B, Rows 5-8';
        }
        if ($dbm >= -70) {
            return 'Zone C, Rows 9-12';
        }
        if ($dbm >= -80) {
            return 'Zone D, Rows 13-16';
        }
        return 'Zone E, perimeter / weak signal';
    }

    public static function recommendedAction(string $alertLevel, string $zone): string
    {
        return match ($alertLevel) {
            'CRITICAL' => "Inspect {$zone} immediately — physical check required",
            'HIGH' => "Verify device in {$zone} within 2 minutes",
            'MEDIUM' => "Log and review {$zone} at next scheduled check",
            'LOW' => 'Device cleared — remain visible on dashboard',
            default => 'Review device',
        };
    }
}
