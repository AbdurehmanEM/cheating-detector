<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = Auth::user();
    jsonResponse(['ok' => true, 'authenticated' => $user !== null, 'user' => $user]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $action = $body['action'] ?? 'login';

    if ($action === 'logout') {
        Auth::logout();
        jsonResponse(['ok' => true, 'logged_out' => true]);
    }

    if ($action === 'login') {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if ($username === '' || $password === '') {
            jsonError('Username and password required');
        }
        try {
            $user = Auth::login($username, $password);
            jsonResponse(['ok' => true, 'user' => $user]);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 401);
        }
    }

    jsonError('Unknown action');
}

jsonError('Method not allowed', 405);
