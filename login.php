<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Exam Phone Detection</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .login-box { max-width: 380px; margin: 4rem auto; padding: 2rem; background: var(--panel); border: 1px solid var(--border); border-radius: 8px; }
        .login-box h2 { margin-bottom: 0.5rem; }
        .login-box label { display: block; font-size: 0.85rem; color: var(--muted); margin-top: 1rem; }
        .login-box input { width: 100%; padding: 0.6rem; margin-top: 0.25rem; background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 4px; }
        .login-error { color: var(--critical); font-size: 0.85rem; margin-top: 1rem; display: none; }
        .login-hint { font-size: 0.75rem; color: var(--muted); margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Invigilator Login</h2>
        <p style="color:var(--muted);font-size:0.9rem">ZERO EXEMPTION mode — chief account required to clear devices and manage sessions.</p>
        <form id="login-form">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" required>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <p class="login-error" id="login-error"></p>
            <button type="submit" class="btn" style="width:100%;margin-top:1.25rem">Sign in</button>
        </form>
        <p class="login-hint">Default chief: <code>chief</code> / <code>ExamChief2026!</code><br>View-only: <code>invigilator</code> / <code>Invigilator2026!</code></p>
    </div>
    <script>
        (async function () {
            const check = await fetch('api/auth.php', { credentials: 'include' });
            const data = await check.json();
            if (data.authenticated) {
                window.location.href = 'index.php';
                return;
            }
            document.getElementById('login-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const err = document.getElementById('login-error');
                err.style.display = 'none';
                const res = await fetch('api/auth.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'login',
                        username: document.getElementById('username').value.trim(),
                        password: document.getElementById('password').value,
                    }),
                });
                const body = await res.json();
                if (!body.ok) {
                    err.textContent = body.error || 'Login failed';
                    err.style.display = 'block';
                    return;
                }
                window.location.href = 'index.php';
            });
        })();
    </script>
</body>
</html>
