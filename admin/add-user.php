
<?php
require_once __DIR__ . '/../includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_id']) ||
    strtolower((string)($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: /login.php');
    exit;
}

function addUserEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';

$name = '';
$email = '';
$role = 'learner';

$users = [];
$total_users = 0;
$learner_count = 0;
$admin_count = 0;

// Create a new account
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_user'])
) {
    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim(
        (string)($_POST['email'] ?? '')
    ));
    $password = (string)($_POST['password'] ?? '');
    $role = strtolower(trim(
        (string)($_POST['role'] ?? 'learner')
    ));

    if ($name === '' || $email === '' || $password === '') {
        $message = 'Name, email and password are required.';
        $message_type = 'error';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';

    } elseif (strlen($password) < 8) {
        $message = 'Password must contain at least 8 characters.';
        $message_type = 'error';

    } elseif (!in_array($role, ['learner', 'admin'], true)) {
        $message = 'Please select a valid account type.';
        $message_type = 'error';

    } else {
        try {
            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE LOWER(email) = LOWER(?)
                LIMIT 1
            ");

            $check->execute([$email]);

            if ($check->fetchColumn() !== false) {
                $message = 'A user with this email already exists.';
                $message_type = 'error';
            } else {
                $hashed = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    INSERT INTO users
                        (name, email, password, role, created_at)
                    VALUES
                        (:name, :email, :password, :role, CURRENT_TIMESTAMP)
                ");

                $stmt->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':password' => $hashed,
                    ':role' => $role
                ]);

                $message = 'User account created successfully!';
                $message_type = 'success';

                $name = '';
                $email = '';
                $role = 'learner';
            }

        } catch (PDOException $e) {
            error_log(
                'TestMate Add User Error: ' . $e->getMessage()
            );

            $message = 'Unable to create the account. Check the server terminal.';
            $message_type = 'error';
        }
    }
}

// Retrieve users and their progress statistics
try {
    $users = $conn->query("
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at,

            (
                SELECT COUNT(*)
                FROM quiz_scores qs
                WHERE qs.user_id = u.id
            ) AS quiz_count,

            (
                SELECT COUNT(*)
                FROM mock_scores ms
                WHERE ms.user_id = u.id
            ) AS mock_count

        FROM users u

        ORDER BY u.created_at DESC, u.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total_users = count($users);

    foreach ($users as $u) {
        if (
            strtolower((string)($u['role'] ?? '')) === 'admin'
        ) {
            $admin_count++;
        } else {
            $learner_count++;
        }
    }

} catch (PDOException $e) {
    error_log(
        'TestMate User List Error: ' . $e->getMessage()
    );

    if ($message === '') {
        $message = 'Could not load existing users.';
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Add User – TestMate Admin</title>

<link rel="stylesheet" href="/css/style.css">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f6fa;
    color: #2c3e50;
}

.navbar {
    min-height: 64px;
    background: #2c3e50;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 26px;
    gap: 15px;
}

.navbar a {
    color: white;
    text-decoration: none;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
}

.brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 7px;
    background: #3498db;
    color: white;
    display: grid;
    place-items: center;
    font-weight: 800;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 24px;
    font-size: 14px;
}

.btn-logout {
    background: #e74c3c;
    color: white;
    padding: 11px 17px;
    border-radius: 7px;
}

.admin-wrap {
    display: flex;
    min-height: calc(100vh - 64px);
}

.sidebar {
    width: 247px;
    background: #1a252f;
    color: white;
    padding: 25px 0;
    flex-shrink: 0;
}

.sidebar h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8191a0;
    padding: 0 21px;
    margin: 25px 0 10px;
}

.sidebar h3:first-child {
    margin-top: 0;
}

.sidebar a {
    display: block;
    padding: 13px 21px;
    color: #e4eaf0;
    text-decoration: none;
    font-size: 14px;
    transition: background .15s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #303e49;
    color: white;
}

.main-content {
    flex: 1;
    min-width: 0;
    padding: 32px;
    background: #f5f6fa;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    margin-bottom: 22px;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 22px;
}

.page-heading h1 {
    font-size: 23px;
    margin: 0;
}

.btn {
    display: inline-block;
    padding: 11px 18px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-outline {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.stat-value {
    font-size: 1.9rem;
    font-weight: 800;
    color: #2563eb;
}

.stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-top: 5px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
    font-size: 14px;
    color: #374151;
}

.form-control {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

.role-select {
    display: flex;
    gap: 14px;
    margin-bottom: 20px;
}

.role-option {
    flex: 1;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 18px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
}

.role-option:hover {
    border-color: #2563eb;
}

.role-option.selected {
    border-color: #2563eb;
    background: #eff6ff;
}

.role-option input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
}

.role-icon {
    font-size: 28px;
    margin-bottom: 8px;
}

.role-label {
    font-size: 14px;
    font-weight: 700;
    color: #374151;
}

.role-desc {
    font-size: 12px;
    color: #6b7280;
    margin-top: 5px;
}

.alert {
    padding: 14px 17px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 14px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

th, td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

th {
    background: #f9fafb;
    color: #4b5563;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .05em;
}

tbody tr:hover {
    background: #f9fafb;
}

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.badge-admin {
    background: #dbeafe;
    color: #1e40af;
}

.badge-learner {
    background: #d1fae5;
    color: #065f46;
}

@media (max-width: 900px) {
    .stats {
        grid-template-columns: 1fr;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .admin-wrap {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 10px;
    }

    .sidebar h3 {
        margin: 12px 15px 5px;
    }

    .sidebar a {
        display: inline-block;
        padding: 10px;
    }

    .main-content {
        padding: 20px 14px;
    }

    .navbar {
        flex-wrap: wrap;
        padding: 12px 15px;
    }

    .role-select {
        flex-direction: column;
    }
}
</style>

</head>

<body>

<nav class="navbar">
    <a href="/admin/index.php" class="brand">
        <span class="brand-icon">T</span>
        TestMate Admin
    </a>

    <div class="nav-links">
        <a href="/index.php">View Site</a>
        <a href="/logout.php" class="btn-logout">Logout</a>
    </div>
</nav>

<div class="admin-wrap">

<aside class="sidebar">

    <h3>Main</h3>
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/review-scores.php">Review Scores</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/add-user.php" class="active">Add User</a>
    <a href="/admin/stats.php">Statistics</a>

    <h3>Questions</h3>
    <a href="/admin/questions.php">All Questions</a>
    <a href="/admin/add-question.php">Add Question</a>
    <a href="/admin/assign-quiz.php">Assign Quiz</a>

    <h3>Content</h3>
    <a href="/admin/simulations.php">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>

</aside>

<main class="main-content">

    <div class="page-heading">
        <h1>Add User</h1>

        <a href="/admin/users.php" class="btn btn-outline">
            Back to Users
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= addUserEsc($message_type) ?>">
            <?= addUserEsc($message) ?>
        </div>
    <?php endif; ?>

    <!-- User Statistics -->

    <section class="stats">

        <div class="stat-card">
            <div class="stat-value">
                <?= $total_users ?>
            </div>
            <div class="stat-label">Total Users</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:#059669;">
                <?= $learner_count ?>
            </div>
            <div class="stat-label">Learners</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:#7c3aed;">
                <?= $admin_count ?>
            </div>
            <div class="stat-label">Administrators</div>
        </div>

    </section>

    <!-- Create Account Form -->

    <section class="card">

        <h2 style="font-size:17px;margin:0 0 20px;">
            New User Details
        </h2>

        <form method="POST" action="/admin/add-user.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= addUserEsc($_SESSION['admin_csrf']) ?>"
            >

            <div class="form-group">

                <label>Account Type</label>

                <div class="role-select">

                    <label class="role-option <?= $role === 'learner' ? 'selected' : '' ?>">
                        <input
                            type="radio"
                            name="role"
                            value="learner"
                            <?= $role === 'learner' ? 'checked' : '' ?>
                        >

                        <div class="role-icon">&#127891;</div>

                        <div class="role-label">Learner</div>

                        <div class="role-desc">
                            Take quizzes, mock tests and track progress
                        </div>
                    </label>

                    <label class="role-option <?= $role === 'admin' ? 'selected' : '' ?>">
                        <input
                            type="radio"
                            name="role"
                            value="admin"
                            <?= $role === 'admin' ? 'checked' : '' ?>
                        >

                        <div class="role-icon">&#128737;</div>

                        <div class="role-label">Administrator</div>

                        <div class="role-desc">
                            Manage questions, learners and study content
                        </div>
                    </label>

                </div>
            </div>

            <div class="form-row">

                <div class="form-group">
                    <label for="name">Full Name</label>

                    <input
                        class="form-control"
                        id="name"
                        type="text"
                        name="name"
                        value="<?= addUserEsc($name) ?>"
                        placeholder="Enter full name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>

                    <input
                        class="form-control"
                        id="email"
                        type="email"
                        name="email"
                        value="<?= addUserEsc($email) ?>"
                        placeholder="Enter email address"
                        required
                    >
                </div>

            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <input
                    class="form-control"
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Minimum 8 characters"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >
            </div>

            <button
                class="btn btn-primary"
                type="submit"
                name="add_user"
                value="1"
            >
                Create Account
            </button>

        </form>

    </section>

    <!-- Existing Users -->

    <h2 style="font-size:18px;margin:0 0 15px;">
        All Users
    </h2>

    <section class="card" style="padding:0;overflow:hidden;">

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Quizzes</th>
                        <th>Mocks</th>
                        <th>Joined</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="6"
                            style="text-align:center;color:#888;padding:30px;">
                            No users registered yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $u): ?>

                        <?php
                        $isAdmin = strtolower(
                            (string)($u['role'] ?? '')
                        ) === 'admin';

                        $timestamp = !empty($u['created_at'])
                            ? strtotime((string)$u['created_at'])
                            : false;
                        ?>

                        <tr>
                            <td style="font-weight:600;">
                                <?= addUserEsc($u['name']) ?>
                            </td>

                            <td>
                                <?= addUserEsc($u['email']) ?>
                            </td>

                            <td>
                                <span class="badge <?= $isAdmin
                                    ? 'badge-admin'
                                    : 'badge-learner' ?>">
                                    <?= $isAdmin
                                        ? 'Admin'
                                        : 'Learner' ?>
                                </span>
                            </td>

                            <td>
                                <?= (int)$u['quiz_count'] ?>
                            </td>

                            <td>
                                <?= (int)$u['mock_count'] ?>
                            </td>

                            <td style="font-size:13px;color:#888;">
                                <?= $timestamp !== false
                                    ? date('M j, Y', $timestamp)
                                    : '—' ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

</main>
</div>

<script>
// Keep visual role selector synchronized
document.querySelectorAll(
    '.role-option input[type="radio"]'
).forEach(function(input) {

    input.addEventListener('change', function() {

        document.querySelectorAll('.role-option')
            .forEach(function(option) {
                option.classList.remove('selected');
            });

        if (this.checked) {
            this.closest('.role-option')
                .classList.add('selected');
        }
    });
});
</script>

</body>
</html>
