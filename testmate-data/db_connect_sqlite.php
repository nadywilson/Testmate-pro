<?php
/**
 * TestMate SQLite connection. Replace the project copy of includes/db_connect.php
 * only when ready to migrate the PHP pages from mysqli to PDO.
 * Database is deliberately stored OUTSIDE the PHP web document root.
 */
$dbPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'testmate-data' . DIRECTORY_SEPARATOR . 'testmate.sqlite';
if (!is_file($dbPath)) {
    throw new RuntimeException('TestMate SQLite file not found: ' . $dbPath);
}
try {
    $conn = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $conn->exec('PRAGMA busy_timeout = 5000');
    // Enable only after schema has been reviewed and migrated with foreign keys.
    // $conn->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    error_log('TestMate SQLite connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection unavailable.');
}
