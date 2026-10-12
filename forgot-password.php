<?php
declare(strict_types=1);

// TestMate - Forgot Password
// PHP 8.5 + PDO SQLite + Gmail SMTP

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/send_reset_email.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function forgotEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['forgot_csrf'])) {
    $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$email = '';

$successMessage =
    'If an account exists with this email address, ' .
    'a password reset link will be sent. ' .
    'Check your inbox and spam folder.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(
        trim((string)($_POST['email'] ?? ''))
    );

    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['forgot_csrf'], $csrf)) {

        $error = 'Session expired. Refresh the page.';

    } elseif ($email === '') {

        $error = 'Please enter your email address.';

    } elseif (
        strlen($email) > 254 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $error = 'Please enter a valid email address.';

    } else {

        $success = $successMessage;

        $now = gmdate('Y-m-d H:i:s');
        $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $emailHash = hash('sha256', $email);
        $ipHash = hash('sha256', $ip);

        try {

            // Remove rate-limit records older than one hour.
            $stmt = $conn->prepare("
                DELETE FROM password_reset_requests
                WHERE requested_at < ?
            ");

            $stmt->execute([$oneHourAgo]);

            // Count requests for this email.
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM password_reset_requests
                WHERE email_hash = ?
                  AND requested_at >= ?
            ");

            $stmt->execute([$emailHash, $oneHourAgo]);
            $emailRequests = (int)$stmt->fetchColumn();

            // Count requests from this IP.
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM password_reset_requests
                WHERE ip_hash = ?
                  AND requested_at >= ?
            ");

            $stmt->execute([$ipHash, $oneHourAgo]);
            $ipRequests = (int)$stmt->fetchColumn();

            if ($emailRequests < 3 && $ipRequests < 10) {

                $stmt = $conn->prepare("
                    INSERT INTO password_reset_requests (
                        email_hash,
                        ip_hash,
                        requested_at
                    )
                    VALUES (?, ?, ?)
                ");

                $stmt->execute([
                    $emailHash,
                    $ipHash,
                    $now
                ]);

                // Do not reveal whether the account exists.
                $stmt = $conn->prepare("
                    SELECT id, name, email
                    FROM users
                    WHERE LOWER(email) = ?
                    LIMIT 1
                ");

                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {

                    // Generate a cryptographically random token.
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $expiresAt = gmdate(
                        'Y-m-d H:i:s',
                        time() + 1800
                    );

                    // Revoke earlier unused tokens.
                    $stmt = $conn->prepare("
                        DELETE FROM password_reset_tokens
                        WHERE user_id = ?
                          AND used_at IS NULL
                    ");

                    $stmt->execute([(int)$user['id']]);

                    // Store only the token hash.
                    $stmt = $conn->prepare("
                        INSERT INTO password_reset_tokens (
                            user_id,
                            token_hash,
                            created_at,
                            expires_at
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        (int)$user['id'],
                        $tokenHash,
                        $now,
                        $expiresAt
                    ]);

                    $baseUrl = rtrim(
                        (string)(
                            getenv('TESTMATE_BASE_URL')
                            ?: 'http://127.0.0.1:8000'
                        ),
                        '/'
                    );

                    $resetUrl =
                        $baseUrl .
                        '/reset-password.php?token=' .
                        rawurlencode($token);

                    $sent = sendTestMateResetEmail(
                        (string)$user['email'],
                        (string)$user['name'],
                        $resetUrl
                    );

                    if (!$sent) {

                        // An undelivered token cannot be used.
                        $stmt = $conn->prepare("
                            DELETE FROM password_reset_tokens
                            WHERE token_hash = ?
                        ");

                        $stmt->execute([$tokenHash]);
                    }
                }
            }

        } catch (Throwable $e) {

            error_log(
                'TestMate password reset request error (' .
                get_class($e) . ').'
            );
        }

        $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - TestMate</title>
<link rel="stylesheet" href="/css/style.css">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: linear-gradient(135deg,#1a252f,#2c3e50);
    font-family: Arial,Helvetica,sans-serif;
}

.login-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}

.logo-area {
    text-align: center;
    margin-bottom: 35px;
}

.logo-icon {
    width: 64px;
    height: 64px;
    margin-bottom: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    background: #3498db;
    color: white;
    font-size: 32px;
    font-weight: 800;
}

.logo-area h1 {
    margin: 0;
    color: white;
    font-size: 28px;
}

.logo-area p {
    margin-top: 6px;
    color: rgba(255,255,255,.65);
    font-size: 14px;
}

.login-box {
    width: 100%;
    max-width: 430px;
    padding: 36px;
    border-radius: 16px;
    background: white;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}

.login-box h2 {
    margin: 0 0 8px;
    font-size: 22px;
    color: #2c3e50;
}

.login-box .sub {
    margin-bottom: 25px;
    color: #888;
    font-size: 13px;
    line-height: 1.7;
}

.form-group {
    margin-bottom: 17px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: 700;
    color: #34495e;
}

.form-group input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    outline: none;
}

.form-group input:focus {
    border-color: #3498db;
}

.submit-btn {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 8px;
    background: #2c3e50;
    color: white;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

.submit-btn:hover {
    background: #1a252f;
}

.alert-error,
.alert-success {
    padding: 13px 14px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.7;
}

.alert-error {
    background: #fdecea;
    color: #a93232;
    border-left: 4px solid #e74c3c;
}

.alert-success {
    background: #eafaf1;
    color: #176b42;
    border-left: 4px solid #27ae60;
}

.auth-switch {
    margin-top: 22px;
    color: #888;
    text-align: center;
    font-size: 14px;
}

.auth-switch a,
.back-link {
    color: #3498db;
    font-weight: 700;
    text-decoration: none;
}

.back-link {
    display: inline-block;
    margin-bottom: 22px;
    font-size: 13px;
}

.auth-footer {
    padding: 18px;
    color: rgba(255,255,255,.4);
    font-size: 12px;
    text-align: center;
}

@media(max-width:500px) {
    .login-box {
        padding: 25px 20px;
    }
}
</style>
</head>

<body>

<main class="login-wrap">

    <div class="logo-area">
        <div class="logo-icon">T</div>
        <h1>TestMate</h1>
        <p>Namibia Learner's Licence Preparation</p>
    </div>

    <section class="login-box">

        <a href="/login.php" class="back-link">
            ← Back to Login
        </a>

        <h2>Forgot Password?</h2>

        <p class="sub">
            Enter your registered email address.
            We'll email you a secure link to create
            a new password.
        </p>

        <?php if ($error !== ''): ?>

            <div class="alert-error" role="alert">
                <?= forgotEsc($error) ?>
            </div>

        <?php endif; ?>

        <?php if ($success !== ''): ?>

            <div class="alert-success" role="status">
                <?= forgotEsc($success) ?>
            </div>

        <?php else: ?>

            <form method="POST" action="/forgot-password.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= forgotEsc(
                        $_SESSION['forgot_csrf']
                    ) ?>"
                >

                <div class="form-group">
                    <label for="email">Email Address</label>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        placeholder="you@example.com"
                        autocomplete="email"
                        maxlength="254"
                        value="<?= forgotEsc($email) ?>"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="submit-btn">
                    Send Reset Link
                </button>

            </form>

        <?php endif; ?>

        <div class="auth-switch">
            Remember your password?
            <a href="/login.php">Login here</a>
        </div>

    </section>

</main>

<footer class="auth-footer">
    © <?= date('Y') ?> TestMate Namibia
</footer>

</body>
</html>
