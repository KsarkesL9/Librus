<?php
// db.php — proste połączenie PDO
// ZAPISZ: C:\xampp\htdocs\librus\db.php

// --- konfiguracja: ustaw swoje dane bazy tutaj ---
$dbHost = '127.0.0.1';
$dbName = 'librus';        // nazwę bazy ustaw zgodnie z Twoją
$dbUser = 'root';          // domyślnie w XAMPP: root
$dbPass = '';              // domyślnie puste w XAMPP
$dbCharset = 'utf8mb4';
// -----------------------------------------------

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // W środowisku produkcyjnym nie wyświetlaj pełnego stacka
    http_response_code(500);
    echo "Błąd połączenia z bazą danych: " . htmlspecialchars($e->getMessage());
    exit;
}
