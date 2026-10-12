
<?php
/**
 * TestMate Authentication Protection
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent access without login
if (
    !isset($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    header('Location: /login.php');
    exit;
}

// Keep current user session information
$user_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Learner';
$user_role = strtolower(
    trim((string) ($_SESSION['role'] ?? 'learner'))
);
