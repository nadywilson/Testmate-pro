
<?php
/**
 * TestMate - One-time Password Reset Database Setup
 *
 * Location:
 * C:\projects\testmate-data\setup_password_reset.php
 *
 * Run from PowerShell, not from the browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line access only.');
}

require_once dirname(__DIR__) .
    '/testmate/includes/db_connect.php';

if (!isset($conn) || !($conn instanceof PDO)) {
    exit("Error: db_connect.php must provide a PDO connection.\n");
}

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Store only SHA-256 hashes of reset tokens.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT DEFAULT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id)
                ON DELETE CASCADE
        )
    ");

    $conn->exec("
        CREATE INDEX IF NOT EXISTS idx_reset_user
        ON password_reset_tokens(user_id)
    ");

    $conn->exec("
        CREATE INDEX IF NOT EXISTS idx_reset_expiry
        ON password_reset_tokens(expires_at)
    ");

    // Record requests for basic rate limiting.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_hash TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            requested_at TEXT NOT NULL
        )
    ");

    $conn->exec("
        CREATE INDEX IF NOT EXISTS idx_reset_email_request
        ON password_reset_requests(email_hash, requested_at)
    ");

    $conn->exec("
        CREATE INDEX IF NOT EXISTS idx_reset_ip_request
        ON password_reset_requests(ip_hash, requested_at)
    ");

    echo "Password reset database setup completed.\n";
    echo "Tables are ready in the existing TestMate database.\n";

} catch (PDOException $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    exit(1);
}
