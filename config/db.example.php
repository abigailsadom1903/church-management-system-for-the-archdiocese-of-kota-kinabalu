<?php
// Template for config/db.php — copy this file to config/db.php and fill
// in your own local MySQL credentials. config/db.php is gitignored so
// each developer's real credentials never get committed.

// Sabah time for every date and "today" on the site (PHP on XAMPP otherwise uses a European clock).
date_default_timezone_set('Asia/Kuching');

define('DB_HOST', 'localhost');
define('DB_NAME', 'church_management_system');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
