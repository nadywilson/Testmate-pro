
<?php
/**
 * TestMate Login Page
 * PHP 8.5 + PDO SQLite
 */

require_once __DIR__ . '/includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Redirect to the correct dashboard
 */
function redirectAfterLogin(string $role): never
{
    $role = strtolower(trim($role));

    if ($role === 'admin') {
        header('Location: /admin/index.php');
    } else {
        header('Location: /dashboard.php');
    }

    exit;
}

// Existing logged-in user
if (!empty($_SESSION['user_id'])) {
    redirectAfterLogin(
        (string) ($_SESSION['role'] ?? 'learner')
    );
}

$error = '';
$email = '';

// Login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {

        $error = 'Please enter your email and password.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } else {

        try {

            // Find existing learner or administrator
            $stmt = $conn->prepare("
                SELECT
                    id,
                    name,
                    email,
                    password,
                    role
                FROM users
                WHERE email = :email
                LIMIT 1
            ");

            $stmt->execute([
                ':email' => $email
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user !== false &&
                password_verify(
                    $password,
                    (string) $user['password']
                )
            ) {

                // Secure session renewal
                session_regenerate_id(true);

                $role = strtolower(
                    trim(
                        (string) ($user['role'] ?? 'learner')
                    )
                );

                if ($role === '') {
                    $role = 'learner';
                }

                // Preserve original database user ID
                $_SESSION['user_id'] = (int) $user['id'];

                $_SESSION['name'] = (string) $user['name'];

                $_SESSION['email'] = (string) $user['email'];

                $_SESSION['role'] = $role;

                // Redirect after successful authentication
                redirectAfterLogin($role);

            } else {

                $error = 'Incorrect email or password.';

            }

        } catch (PDOException $e) {

            error_log(
                '[TestMate Login Error] ' . $e->getMessage()
            );

            $error = 'Unable to process your login right now. Please try again.';
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

    <title>Login – TestMate</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            font-family: Arial, Helvetica, sans-serif;
            color: #17263a;
        }

        body {
            min-height: 100vh;
            background: #eef1f4;
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .login-main {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 58px 20px 40px;
        }

        .login-card {
            width: 100%;
            max-width: 540px;
            background: #ffffff;
            border-radius: 12px;
            padding: 42px 46px 38px;
            box-shadow: 0 12px 30px rgba(26, 42, 61, 0.18);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2f9de0;
            color: #ffffff;
            font-size: 25px;
            font-weight: 800;
        }

        .brand-name {
            font-size: 24px;
            font-weight: 800;
            color: #15324f;
        }

        .login-card h1 {
            margin: 0 0 24px;
            font-size: 32px;
            line-height: 1.2;
            color: #142337;
            font-weight: 700;
        }

        .login-card h1 span {
            color: #d4a52d;
        }

        .alert-error {
            margin-bottom: 18px;
            padding: 15px 16px;
            border: 1px solid #e8a39d;
            border-radius: 8px;
            background: #f8d8d4;
            color: #8f2f28;
            font-size: 14px;
            line-height: 1.45;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 700;
            color: #31465e;
        }

        .form-control {
            width: 100%;
            height: 52px;
            padding: 0 16px;
            border: 1px solid #9ba8b6;
            border-radius: 8px;
            background: #ffffff;
            color: #17263a;
            font-size: 16px;
            outline: none;
            transition: border-color .15s ease,
                        box-shadow .15s ease;
        }

        .form-control:focus {
            border-color: #2f9de0;
            box-shadow: 0 0 0 3px rgba(47, 157, 224, .13);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #64778b;
            font-size: 18px;
            cursor: pointer;
            padding: 6px;
        }

        .login-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 104px;
            height: 48px;
            margin-top: 4px;
            padding: 0 22px;
            border: 0;
            border-radius: 8px;
            background: #173b67;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s ease,
                        transform .15s ease;
        }

        .login-btn:hover {
            background: #102d50;
        }

        .login-btn:active {
            transform: translateY(1px);
        }

        .forgot-link {
            display: inline-block;
            margin-top: 18px;
            color: #cf4b36;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .divider {
            height: 1px;
            margin: 28px 0 24px;
            background: #dce2e8;
        }

        .account-help h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #17263a;
        }

        .account-help p {
            margin: 0 0 14px;
            color: #667789;
            font-size: 14px;
            line-height: 1.55;
        }

        .register-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border-radius: 7px;
            background: #d9e0e6;
            color: #23374c;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
        }

        .register-btn:hover {
            background: #cbd5de;
        }

        .login-footer {
            padding: 18px;
            text-align: center;
            color: #758596;
            font-size: 12px;
        }

        @media (max-width: 640px) {
            .login-main {
                padding: 24px 14px;
                align-items: center;
            }

            .login-card {
                padding: 30px 24px;
            }

            .login-card h1 {
                font-size: 27px;
            }
        }
    </style>
</head>

<body>

<div class="login-page">

    <main class="login-main">

        <section class="login-card">

            <div class="brand-row">
                <div class="brand-icon">T</div>
                <div class="brand-name">TestMate</div>
            </div>

            <h1>
                Log in to <span>TestMate</span>
            </h1>

            <?php if ($error !== ''): ?>

                <div class="alert-error">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>

            <?php endif; ?>

            <form
                method="POST"
                action="/login.php"
                autocomplete="on"
            >

                <div class="form-group">

                    <label for="email">
                        Email address
                    </label>

                    <input
                        class="form-control"
                        id="email"
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="Enter your email address"
                        autocomplete="email"
                        required
                        autofocus
                    >

                </div>

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="password-wrap">

                        <input
                            class="form-control"
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            class="password-toggle"
                            type="button"
                            id="passwordToggle"
                            aria-label="Show password"
                            title="Show password"
                        >
                            ◉
                        </button>

                    </div>

                </div>

                <button
                    class="login-btn"
                    type="submit"
                >
                    Log in
                </button>

            </form>

            <a
                class="forgot-link"
                href="/forgot-password.php"
            >
                Forgot password?
            </a>

            <div class="divider"></div>

            <div class="account-help">

                <h2>New to TestMate?</h2>

                <p>
                    Create a learner account to access
                    study materials, quizzes, mock tests
                    and progress tracking.
                </p>

                <a
                    class="register-btn"
                    href="/register.php"
                >
                    Register as a Learner
                </a>

            </div>

        </section>

    </main>

    <footer class="login-footer">
        © 2026 TestMate Namibia
    </footer>

</div>

<script>
(function () {

    const password = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');

    if (!password || !toggle) {
        return;
    }

    toggle.addEventListener('click', function () {

        const showing = password.type === 'text';

        password.type = showing ? 'password' : 'text';

        toggle.setAttribute(
            'aria-label',
            showing ? 'Show password' : 'Hide password'
        );

        toggle.setAttribute(
            'title',
            showing ? 'Show password' : 'Hide password'
        );

    });

})();
</script>

</body>
</html>
