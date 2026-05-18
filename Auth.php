<?php
declare(strict_types=1);

class Auth
{
    private static bool $sessionStarted = false;

    public static function startSession(): void
    {
        if (self::$sessionStarted) {
            return;
        }
        $config = exam_config();
        $name = $config['auth']['session_name'] ?? 'EXAM_DETECTION_SESS';
        $lifetime = (int) ($config['auth']['session_lifetime'] ?? 28800);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$sessionStarted = true;
    }

    public static function user(): ?array
    {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'display_name' => $_SESSION['display_name'] ?? '',
            'role' => $_SESSION['role'] ?? 'invigilator',
            'hall_code' => $_SESSION['hall_code'] ?? null,
        ];
    }

    public static function isChief(): bool
    {
        $u = self::user();
        return $u && ($u['role'] === 'chief');
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            jsonError('Login required', 401);
        }
        return $u;
    }

    public static function requireChief(): array
    {
        $u = self::requireLogin();
        if ($u['role'] !== 'chief') {
            jsonError('Chief invigilator access required', 403);
        }
        return $u;
    }

    public static function login(string $username, string $password): array
    {
        self::startSession();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM invigilators WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            throw new InvalidArgumentException('Invalid username or password');
        }
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['display_name'] = $row['display_name'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['hall_code'] = $row['hall_code'];

        $pdo->prepare('UPDATE invigilators SET last_login_at = ? WHERE id = ?')->execute([Database::now(), $row['id']]);

        return self::user();
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_destroy();
    }
}
