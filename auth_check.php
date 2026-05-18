<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/includes/Database.php';
require_once APP_ROOT . '/includes/Auth.php';

Auth::startSession();
if (!Auth::user()) {
    header('Location: login.php');
    exit;
}
