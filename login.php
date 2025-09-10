<?php
// login.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
    if ($base === '') { $base = '.'; }
    header("Location: $base/index.php");
    exit;
}

$errors = [];
$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';
$csrf = $_POST['csrf'] ?? '';

if (!verify_csrf($csrf)) {
    $errors[] = 'Nieprawidłowy token formularza.';
}

if ($login === '' || $password === '') {
    $errors[] = 'Podaj login i hasło.';
}

if (empty($errors)) {
    $stmt = $pdo->prepare('SELECT id, login, password_hash, first_name, last_name, is_active FROM users WHERE login = :login LIMIT 1');
    $stmt->execute([':login' => $login]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Nieprawidłowy login lub hasło.';
    } elseif (!$user['is_active']) {
        $errors[] = 'Konto jest zablokowane.';
    } else {
        $rolesStmt = $pdo->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid');
        $rolesStmt->execute([':uid' => $user['id']]);
        $roles = array_column($rolesStmt->fetchAll(), 'name');

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'login' => $user['login'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'roles' => $roles
        ];

        $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
        if ($base === '') { $base = '.'; }
        header("Location: $base/dashboard.php");
        exit;
    }
}

$_SESSION['flash_errors'] = $errors;
$_SESSION['flash_old'] = ['login' => $login];
$base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
if ($base === '') { $base = '.'; }
header("Location: $base/index.php");
exit;
