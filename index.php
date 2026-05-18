<?php
declare(strict_types=1);

/**
 * One-time installer: creates MySQL database tables and default accounts.
 * Remove or protect this folder after installation.
 */

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config.php';

$config = exam_config();
$driver = $config['database']['driver'] ?? 'sqlite';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($driver !== 'mysql') {
            require APP_ROOT . '/includes/Database.php';
            Database::connection();
            $message = 'SQLite database initialized. Default users created.';
        } else {
            $m = $config['database']['mysql'];
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $m['host'], $m['port']);
            $root = new PDO($dsn, $m['username'], $m['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $root->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $m['database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            require APP_ROOT . '/includes/Database.php';
            Database::connection();
            $message = 'MySQL database "' . htmlspecialchars($m['database']) . '" ready. Default users: chief / ExamChief2026!';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Install — Exam Detection</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 520px; margin: 3rem auto; padding: 1rem; background: #0f0f1a; color: #eee; }
        .ok { background: #1e4620; padding: 1rem; border-radius: 8px; }
        .err { background: #4a1515; padding: 1rem; border-radius: 8px; }
        button { background: #3498db; color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; margin-top: 1rem; }
        code { background: #1a1a2e; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Exam Detection — Install</h1>
    <p>Driver: <code><?= htmlspecialchars($driver) ?></code></p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="ok"><?= $message ?></div><?php endif; ?>
    <form method="post">
        <button type="submit">Run installation / migrate tables</button>
    </form>
    <p style="margin-top:2rem;font-size:0.9rem;color:#888">
        Copy <code>config.local.php.example</code> to <code>config.local.php</code> for MySQL.
        <br>Then <a href="../login.php" style="color:#6cf">log in</a>.
    </p>
</body>
</html>

