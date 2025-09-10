<?php
// config/db.php
// Ustaw dane dostępowe do MySQL (XAMPP: user 'root', hasło puste)
$DB_HOST = '127.0.0.1';
$DB_NAME = 'librus';
$DB_USER = 'root';
$DB_PASS = '';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die('Błąd połączenia z bazą danych: ' . htmlspecialchars($e->getMessage()));
}
?>
