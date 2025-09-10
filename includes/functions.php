<?php
// includes/functions.php

function start_secure_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function csrf_token() {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    start_secure_session();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function require_auth() {
    start_secure_session();
    if (empty($_SESSION['user'])) {
        // dynamiczna baza URL (działa z /librus/)
        $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
        if ($base === '') { $base = '.'; }
        header("Location: $base/index.php");
        exit;
    }
}

function current_user() {
    start_secure_session();
    return $_SESSION['user'] ?? null;
}

function user_has_role($roleName) {
    $u = current_user();
    if (!$u) return false;
    return in_array($roleName, $u['roles'] ?? []);
}
