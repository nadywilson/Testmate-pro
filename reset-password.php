
<?php

/**
 * TestMate - Reset Password
 * PHP 8.5 + PDO SQLite
 */

require_once __DIR__ . '/includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function resetEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$record = null;

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['token'] ?? '')
    : (string)($_GET['token'] ?? '');

$tokenValidFormat =
    preg_match('/^[a-f0-9]{64}$/', $token) === 1;

$tokenHash = $tokenValidFormat
    ? hash('sha256', $token)
    : '';

try {

    if ($tokenValidFormat) {

        $stmt = $conn->prepare("
            SELECT
                id,
                user_id,
                expires_at,
                used_at
            FROM password_reset_tokens
            WHERE token_hash = ?
              AND used_at IS NULL
              AND expires_at > ?
            LIMIT 1
        ");

        $stmt->execute([
            $tokenHash,
            gmdate('Y-m-d H:i:s')
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

} catch (PDOException $e) {

    error_log(
        'TestMate reset token lookup failed (' .
        get_class($e) . ').'
    );

    $error = 'Unable to check this reset link.';
}

/* =====================================================
   PROCESS NEW PASSWORD
   ===================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $record
) {

    $csrf = (string)($_POST['csrf_token'] ?? '');

    $password = (string)(
        $_POST['new_password'] ?? ''
    );

    $confirm = (string)(
        $_POST['confirm_password'] ?? ''
    );

    if (!hash_equals($_SESSION['reset_csrf'], $csrf)) {

        $error = 'Session expired. Refresh the page.';

    } elseif ($password === '' || $confirm === '') {

        $error = 'Both password fields are required.';

    } elseif (strlen($password) < 8) {

        $error = 'Password must be at least 8 characters.';

    } elseif (strlen($password) > 4096) {

        $error = 'Password is too long.';

    } elseif ($password !== $confirm) {

        $error = 'Passwords do not match.';

    } else {

        try {

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $conn->beginTransaction();

            $now = gmdate('Y-m-d H:i:s');

            // Claim the token atomically to prevent reuse.
            $stmt = $conn->prepare("
                UPDATE password_reset_tokens
                SET used_at = ?
                WHERE id = ?
                  AND user_id = ?
                  AND used_at IS NULL
                  AND expires_at > ?
            ");

            $stmt->execute([
                $now,
                (int)$record['id'],
                (int)$record['user_id'],
                $now
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The reset link is no longer valid.'
                );
            }

            $stmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $passwordHash,
                (int)$record['user_id']
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'User account is unavailable.'
                );
            }

            // Revoke all other unused tokens for this user.
            $stmt = $conn->prepare("
                UPDATE password_reset_tokens
                SET used_at = ?
                WHERE user_id = ?
                  AND used_at IS NULL
            ");

            $stmt->execute([
                $now,
                (int)$record['user_id']
            ]);

            $conn->commit();

            $success =
                'Your password has been changed successfully. ' .
                'You can now log in.';

            $record = null;
            $token = '';

            $_SESSION['reset_csrf'] = bin2hex(
                random_bytes(32)
            );

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log(
                'TestMate password reset failed (' .
                get_class($e) . ').'
            );

            $error =
                'Unable to reset your password. ' .
                'Please request a new link.';
        }
    }
}

$invalidLink = $success === '' && $record === null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - TestMate</title>
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

.reset-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 40px 20px;
}

.logo-area {
    text-align: center;
    margin-bottom: 30px;
}

.logo-icon {
    width: 64px;
    height: 64px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    margin-bottom: 12px;
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
    font-size: 13px;
}

.reset-box {
    width: 100%;
    max-width: 430px;
    padding: 36px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}

.reset-box h2 {
    margin: 0 0 10px;
    font-size: 22px;
    color: #2c3e50;
}

.reset-box p {
    line-height: 1.7;
    font-size: 13px;
    color: #718096;
}

.reset-field {
    margin-bottom: 18px;
}

.reset-field label {
    display: block;
    margin-bottom: 7px;
    color: #34495e;
    font-size: 13px;
    font-weight: 700;
}

.reset-field input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #ddd;
    border-radius: 8px;
    outline: none;
    font-size: 15px;
}

.reset-field input:focus {
    border-color: #3498db;
}

.reset-button {
    display: block;
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 8px;
    background: #2c3e50;
    color: white;
    font-size: 15px;
    font-weight: 700;
    text-decoration: none;
    text-align: center;
    cursor: pointer;
}

.reset-button:hover {
    background: #1a252f;
}

.alert-error,
.alert-success {
    padding: 14px;
    border-radius: 8px;
    margin: 18px 0;
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

.back-link {
    display: block;
    margin-top: 22px;
    color: #3498db;
    text-align: center;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.reset-footer {
    padding: 18px;
    text-align: center;
    color: rgba(255,255,255,.4);
    font-size: 12px;
}

@media(max-width:500px) {
    .reset-box {
        padding: 25px 20px;
    }
}
</style>
</head>

<body>

<main class="reset-wrap">

    <div class="logo-area">
        <div class="logo-icon">T</div>
        <h1>TestMate</h1>
        <p>Namibia Learner's Licence Preparation</p>
    </div>

    <section class="reset-box">

        <?php if ($success !== ''): ?>

            <h2>Password Updated</h2>

            <div class="alert-success" role="status">
                <?= resetEsc($success) ?>
            </div>

            <a href="/login.php" class="reset-button">
                Continue to Login
            </a>

        <?php elseif ($invalidLink): ?>

            <h2>Invalid or Expired Link</h2>

            <p>
                This link is invalid, expired or has
                already been used.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert-error">
                    <?= resetEsc($error) ?>
                </div>
            <?php endif; ?>

            <a
                href="/forgot-password.php"
                class="reset-button"
            >
                Request a New Link
            </a>

        <?php else: ?>

            <h2>Create New Password</h2>

            <p>
                Enter and confirm your new TestMate password.
            </p>

            <?php if ($error !== ''): ?>

                <div class="alert-error" role="alert">
                    <?= resetEsc($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="/reset-password.php">

                <input
                    type="hidden"
                    name="token"
                    value="<?= resetEsc($token) ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= resetEsc(
                        $_SESSION['reset_csrf']
                    ) ?>"
                >

                <div class="reset-field">
                    <label for="newPassword">
                        New Password
                    </label>

                    <input
                        id="newPassword"
                        type="password"
                        name="new_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="reset-field">
                    <label for="confirmPassword">
                        Confirm New Password
                    </label>

                    <input
                        id="confirmPassword"
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="reset-button">
                    Update Password
                </button>

            </form>

            <p style="margin-top:15px;">
                This link expires after 30 minutes
                and works only once.
            </p>

        <?php endif; ?>

        <a href="/login.php" class="back-link">
            ← Back to Login
        </a>

    </section>

</main>

<footer class="reset-footer">
    © <?= date('Y') ?> TestMate Namibia
</footer>

</body>
</html>
