<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function driver(): string
    {
        if (self::$driver === null) {
            $config = exam_config();
            self::$driver = strtolower($config['database']['driver'] ?? 'sqlite');
        }
        return self::$driver;
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            if (self::isMysql()) {
                $config = exam_config();
                $m = $config['database']['mysql'];
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $m['host'],
                    $m['port'],
                    $m['database'],
                    $m['charset'] ?? 'utf8mb4'
                );
                self::$pdo = new PDO($dsn, $m['username'], $m['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                $dir = dirname(DB_PATH);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                self::$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
            self::migrate();
        }
        return self::$pdo;
    }

    private static function migrate(): void
    {
        if (self::isMysql()) {
            self::migrateMysql();
        } else {
            self::migrateSqlite();
        }
        self::seedDefaultChief();
    }

    private static function migrateSqlite(): void
    {
        $pdo = self::$pdo;
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS invigilators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'invigilator',
    hall_code TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS exam_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hall_code TEXT,
    exam_name TEXT NOT NULL,
    exam_date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    started_at TEXT,
    closed_at TEXT,
    status TEXT DEFAULT 'pending',
    chief_invigilator TEXT,
    started_by_user_id INTEGER
);

CREATE TABLE IF NOT EXISTS scans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_id TEXT UNIQUE NOT NULL,
    session_id INTEGER NOT NULL,
    scan_time TEXT NOT NULL,
    subnets_scanned TEXT NOT NULL,
    total_devices INTEGER DEFAULT 0,
    raw_json TEXT,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id)
);

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id TEXT UNIQUE NOT NULL,
    session_id INTEGER NOT NULL,
    mac TEXT NOT NULL,
    ip TEXT,
    hostname TEXT,
    signal_strength TEXT,
    band TEXT,
    first_seen TEXT NOT NULL,
    last_seen TEXT NOT NULL,
    status TEXT DEFAULT 'FLAGGED',
    mac_randomized INTEGER DEFAULT 0,
    oui TEXT,
    vendor TEXT,
    category TEXT,
    alert_level TEXT DEFAULT 'MEDIUM',
    cleared_by TEXT,
    cleared_at TEXT,
    joined_after_exam INTEGER DEFAULT 0,
    disconnect_count INTEGER DEFAULT 0,
    discovery_source TEXT,
    bt_likely_phone INTEGER DEFAULT 0,
    UNIQUE(session_id, mac),
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id)
);

CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id TEXT UNIQUE NOT NULL,
    session_id INTEGER NOT NULL,
    device_id TEXT NOT NULL,
    alert_level TEXT NOT NULL,
    alert_time TEXT NOT NULL,
    mac_address TEXT NOT NULL,
    mac_randomized INTEGER DEFAULT 0,
    vendor TEXT,
    category TEXT,
    signal_strength TEXT,
    estimated_zone TEXT,
    recommended_action TEXT,
    acknowledged INTEGER DEFAULT 0,
    cleared INTEGER DEFAULT 0,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id)
);

CREATE TABLE IF NOT EXISTS timeline_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    event_time TEXT NOT NULL,
    event_type TEXT NOT NULL,
    device_id TEXT,
    details TEXT,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id)
);

CREATE TABLE IF NOT EXISTS invigilator_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    action_time TEXT NOT NULL,
    invigilator_name TEXT NOT NULL,
    user_id INTEGER,
    action_type TEXT NOT NULL,
    device_id TEXT,
    details TEXT,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id)
);

CREATE TABLE IF NOT EXISTS oui_cache (
    oui TEXT PRIMARY KEY,
    vendor TEXT,
    fetched_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_devices_session ON devices(session_id);
CREATE INDEX IF NOT EXISTS idx_alerts_session ON alerts(session_id);
SQL);
        self::sqliteAddColumnIfMissing($pdo, 'exam_sessions', 'hall_code', 'TEXT');
        self::sqliteAddColumnIfMissing($pdo, 'exam_sessions', 'started_by_user_id', 'INTEGER');
        self::sqliteAddColumnIfMissing($pdo, 'devices', 'discovery_source', 'TEXT');
        self::sqliteAddColumnIfMissing($pdo, 'devices', 'bt_likely_phone', 'INTEGER DEFAULT 0');
        self::sqliteAddColumnIfMissing($pdo, 'devices', 'wifi_likely_phone', 'INTEGER DEFAULT 0');
        self::sqliteAddColumnIfMissing($pdo, 'devices', 'mac_phone_reason', 'TEXT');
        self::sqliteAddColumnIfMissing($pdo, 'invigilator_actions', 'user_id', 'INTEGER');
    }

    private static function sqliteAddColumnIfMissing(PDO $pdo, string $table, string $column, string $type): void
    {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    }

    private static function migrateMysql(): void
    {
        $sql = file_get_contents(APP_ROOT . '/database/schema.mysql.sql');
        if ($sql === false) {
            throw new RuntimeException('MySQL schema file missing');
        }
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            if (preg_match('/^(CREATE DATABASE|USE )\b/i', $stmt)) {
                try {
                    self::$pdo->exec($stmt);
                } catch (PDOException) {
                    // USE may fail if already selected; CREATE DB if permissions allow
                }
                continue;
            }
            self::$pdo->exec($stmt);
        }
        self::mysqlAddColumnIfMissing('devices', 'bt_likely_phone', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::mysqlAddColumnIfMissing('devices', 'wifi_likely_phone', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::mysqlAddColumnIfMissing('devices', 'mac_phone_reason', 'VARCHAR(32) NULL');
    }

    private static function mysqlAddColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = self::$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . self::$pdo->quote($column));
        if ($stmt->fetch()) {
            return;
        }
        self::$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private static function seedDefaultChief(): void
    {
        $pdo = self::$pdo;
        $count = (int) $pdo->query('SELECT COUNT(*) FROM invigilators')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $hash = password_hash('ExamChief2026!', PASSWORD_DEFAULT);
        $now = self::now();
        $stmt = $pdo->prepare(
            'INSERT INTO invigilators (username, password_hash, display_name, role, hall_code, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute(['chief', $hash, 'Chief Invigilator', 'chief', null, $now]);
        $stmt->execute([
            'invigilator',
            password_hash('Invigilator2026!', PASSWORD_DEFAULT),
            'Hall Invigilator',
            'invigilator',
            null,
            $now,
        ]);
    }
}
