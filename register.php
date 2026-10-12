
<?php
/**
 * TestMate - Learner Registration
 * PHP 8.5 + PDO SQLite
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connect.php';

// Already logged in? Redirect to the appropriate dashboard.
if (!empty($_SESSION['user_id'])) {
    $role = strtolower((string)($_SESSION['role'] ?? 'user'));

    header('Location: ' . (
        $role === 'admin' ? '/admin/index.php' : '/dashboard.php'
    ));
    exit;
}

function registerEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// CSRF protection for the registration form.
if (empty($_SESSION['register_csrf'])) {
    $_SESSION['register_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    // Validate form security.
    if (!hash_equals($_SESSION['register_csrf'], $csrf)) {

        $error = 'Your form session has expired. Refresh the page and try again.';

    } elseif ($name === '' || $email === '' ||
              $password === '' || $confirm === '') {

        $error = 'All fields are required.';

    } elseif (strlen($name) > 150) {

        $error = 'Your name is too long.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (strlen($email) > 254) {

        $error = 'Email address is too long.';

    } elseif (strlen($password) < 6) {

        $error = 'Password must be at least 6 characters.';

    } elseif ($password !== $confirm) {

        $error = 'Passwords do not match.';

    } else {

        try {

            // Check whether the email is already registered.
            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE LOWER(email) = LOWER(?)
                LIMIT 1
            ");

            $check->execute([$email]);

            $existingUser = $check->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {

                $error = 'That email is already registered. Please log in.';

            } else {

                // Hash the learner's password securely.
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Explicitly give public registrations
                // the learner role, never admin.
                $stmt = $conn->prepare("
                    INSERT INTO users (
                        name,
                        email,
                        password,
                        role
                    )
                    VALUES (?, ?, ?, 'user')
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $hashedPassword
                ]);

                $success = 'Account created successfully! You can now log in.';

                $name = '';
                $email = '';

                // Rotate the form token after successful registration.
                $_SESSION['register_csrf'] = bin2hex(
                    random_bytes(32)
                );
            }

        } catch (PDOException $e) {

            error_log(
                '[TestMate Registration] ' . $e->getMessage()
            );

            // Also handle possible concurrent registrations.
            if (
                str_contains(strtolower($e->getMessage()), 'unique') ||
                str_contains(strtolower($e->getMessage()), 'constraint')
            ) {
                $error = 'That email may already be registered. Please log in.';
            } else {
                $error = 'Unable to create your account right now. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Create Account – TestMate</title>

    <link rel="stylesheet" href="/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background:
                radial-gradient(
                    circle at top right,
                    rgba(244,122,31,.12),
                    transparent 35%
                ),
                linear-gradient(
                    135deg,
                    #f4f8fc,
                    #edf4fb
                );
            font-family: Arial, Helvetica, sans-serif;
            color: #173452;
        }

        .register-navbar {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            background: linear-gradient(
                100deg,
                #08283b,
                #173953
            );
        }

        .register-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            text-decoration: none;
            font-size: 22px;
            font-weight: 800;
        }

        .register-brand-icon {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #008bff;
            color: white;
            font-size: 23px;
        }

        .register-navbar-links {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .register-navbar-links a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .register-login-nav {
            padding: 10px 17px;
            border-radius: 7px;
            background: #0876ff;
            font-weight: 700;
        }

        .register-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 64px);
            padding: 40px 16px;
        }

        .register-card {
            width: 100%;
            max-width: 480px;
            padding: 35px;
            border: 1px solid #e0e8f2;
            border-radius: 16px;
            background: white;
            box-shadow: 0 12px 35px rgba(15,44,81,.10);
        }

        .register-logo {
            display: flex;
            justify-content: center;
            margin-bottom: 22px;
        }

        .register-logo span {
            width: 54px;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background: #3498db;
            color: white;
            font-size: 28px;
            font-weight: 900;
        }

        .register-card h1 {
            margin: 0 0 8px;
            color: #173452;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
        }

        .register-subtitle {
            margin: 0 0 26px;
            color: #71859a;
            text-align: center;
            font-size: 14px;
            line-height: 1.6;
        }

        .register-form-group {
            margin-bottom: 18px;
        }

        .register-form-group label {
            display: block;
            margin-bottom: 7px;
            color: #28435e;
            font-size: 13px;
            font-weight: 700;
        }

        .register-form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d8e3ef;
            border-radius: 8px;
            outline: none;
            background: #fff;
            color: #173452;
            font: inherit;
            font-size: 14px;
            transition:
                border-color .2s ease,
                box-shadow .2s ease;
        }

        .register-form-group input:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52,152,219,.12);
        }

        .register-submit {
            display: block;
            width: 100%;
            padding: 13px 18px;
            margin-top: 8px;
            border: none;
            border-radius: 8px;
            background: #075bf2;
            color: white;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }

        .register-submit:hover {
            background: #064bd0;
        }

        .register-alert {
            padding: 13px 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
        }

        .register-alert-error {
            color: #a92c2c;
            background: #fdecea;
            border: 1px solid #f5c8c8;
        }

        .register-alert-success {
            color: #176b42;
            background: #e9f9ef;
            border: 1px solid #bce8cb;
        }

        .register-alert-success a {
            display: inline-block;
            margin-top: 8px;
            color: #176b42;
            font-weight: 800;
        }

        .register-switch {
            margin-top: 23px;
            padding-top: 20px;
            border-top: 1px solid #e8edf4;
            color: #71859a;
            text-align: center;
            font-size: 13px;
        }

        .register-switch a {
            color: #0876ff;
            text-decoration: none;
            font-weight: 800;
        }

        .register-switch a:hover {
            text-decoration: underline;
        }

        .register-footer {
            margin-top: 20px;
            color: #8b9eb0;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 600px) {
            .register-navbar {
                padding: 0 15px;
            }

            .register-navbar-links {
                gap: 12px;
            }

            .register-navbar-links a {
                font-size: 12px;
            }

            .register-card {
                padding: 25px 20px;
            }

            .register-card h1 {
                font-size: 23px;
            }

            .register-page {
                padding: 24px 13px;
            }
        }
    </style>
</head>

<body>

    <!-- Public navigation -->
    <nav class="register-navbar">

        <a href="/index.php" class="register-brand">
            <span class="register-brand-icon">T</span>
            TestMate
        </a>

        <div class="register-navbar-links">

            <a href="/index.php">
                Home
            </a>

            <a href="/login.php" class="register-login-nav">
                Login
            </a>

        </div>

    </nav>

    <!-- Registration content -->
    <main class="register-page">

        <div class="register-card">

            <div class="register-logo">
                <span>T</span>
            </div>

            <h1>Create Your Account</h1>

            <p class="register-subtitle">
                Start preparing for your NATIS test today.
                Create your learner account to access study
                materials, quizzes and simulations.
            </p>

            <?php if ($error !== ''): ?>

                <div
                    class="register-alert register-alert-error"
                    role="alert"
                >
                    <?= registerEsc($error) ?>
                </div>

            <?php endif; ?>

            <?php if ($success !== ''): ?>

                <div
                    class="register-alert register-alert-success"
                    role="status"
                >
                    <?= registerEsc($success) ?>

                    <br>

                    <a href="/login.php">
                        Click here to login →
                    </a>
                </div>

            <?php else: ?>

                <form method="POST" action="/register.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= registerEsc(
                            $_SESSION['register_csrf']
                        ) ?>"
                    >

                    <div class="register-form-group">

                        <label for="registerName">
                            Full Name
                        </label>

                        <input
                            id="registerName"
                            type="text"
                            name="name"
                            placeholder="e.g. John Nghipandua"
                            value="<?= registerEsc($name) ?>"
                            maxlength="150"
                            autocomplete="name"
                            required
                        >

                    </div>

                    <div class="register-form-group">

                        <label for="registerEmail">
                            Email Address
                        </label>

                        <input
                            id="registerEmail"
                            type="email"
                            name="email"
                            placeholder="you@example.com"
                            value="<?= registerEsc($email) ?>"
                            maxlength="254"
                            autocomplete="email"
                            required
                        >

                    </div>

                    <div class="register-form-group">

                        <label for="registerPassword">
                            Password
                        </label>

                        <input
                            id="registerPassword"
                            type="password"
                            name="password"
                            placeholder="At least 6 characters"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <div class="register-form-group">

                        <label for="registerConfirm">
                            Confirm Password
                        </label>

                        <input
                            id="registerConfirm"
                            type="password"
                            name="confirm_password"
                            placeholder="Repeat your password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <button
                        type="submit"
                        class="register-submit"
                    >
                        Create Account
                    </button>

                </form>

            <?php endif; ?>

            <div class="register-switch">
                Already have an account?

                <a href="/login.php">
                    Login here
                </a>
            </div>

            <div class="register-footer">
                TestMate – Namibia Learner's Licence
            </div>

        </div>

    </main>

</body>
</html>
