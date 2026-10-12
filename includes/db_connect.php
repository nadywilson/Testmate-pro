
<?php
/**
 * TestMate – Namibia Learner's Licence
 * Database Connection
 *
 * PHP 8.5 + SQLite
 * No XAMPP, Apache or MySQL required.
 */

// Location of the SQLite database
$databasePath = 'C:/projects/testmate-data/testmate.sqlite';

try {

    // Check whether the database file exists
    if (!is_file($databasePath)) {
        throw new RuntimeException(
            'SQLite database file not found: ' . $databasePath
        );
    }

    // Check whether the database is readable
    if (!is_readable($databasePath)) {
        throw new RuntimeException(
            'SQLite database file is not readable: ' . $databasePath
        );
    }

    // Establish the SQLite connection
    $conn = new PDO(
        'sqlite:' . $databasePath,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Allow SQLite to wait briefly when the database is busy
    $conn->exec('PRAGMA busy_timeout = 5000');

    // Enable foreign key enforcement
    $conn->exec('PRAGMA foreign_keys = ON');

} catch (Throwable $e) {

    // Record the detailed error in the PHP server terminal
    error_log(
        '[TestMate Database Error] ' . $e->getMessage()
    );

    // Display a safe message to website visitors
    http_response_code(500);

    exit(
        'Database connection failed. ' .
        'Please check the PHP server terminal.'
    );
}
