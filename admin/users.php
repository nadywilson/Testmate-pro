
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

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function userEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function userDate($value, bool $withTime = false): string
{
    if (empty($value)) {
        return '—';
    }

    $time = strtotime((string)$value);

    return $time === false
        ? '—'
        : date($withTime ? 'd M Y H:i' : 'd M Y', $time);
}

function userPercent($score, $total): int
{
    $total = (float)$total;

    if ($total <= 0) {
        return 0;
    }

    return (int)round((float)$score / $total * 100);
}

$error = '';
$users = [];
$user_detail = null;
$user_quizzes = [];
$user_mocks = [];
$user_failed = [];
$user_assigned = [];

$view_user = max(0, (int)($_GET['view'] ?? 0));

// Secure learner deletion
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete_user'
) {
    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $deleteId = (int)($_POST['user_id'] ?? 0);

    if ($deleteId <= 0) {
        $error = 'Invalid learner ID.';
    } elseif ($deleteId === (int)$_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT id, role
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$deleteId]);
            $target = $stmt->fetch();

            if (
                !$target ||
                !in_array(
                    strtolower((string)$target['role']),
                    ['user', 'learner'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Only learner accounts can be deleted.'
                );
            }

            // Existing learner-related records
            $relatedTables = [
                'quiz_scores',
                'mock_scores',
                'failed_questions',
                'chat_history',
                'assigned_quizzes'
            ];

            foreach ($relatedTables as $table) {
                $check = $conn->prepare("
                    SELECT COUNT(*)
                    FROM sqlite_master
                    WHERE type = 'table' AND name = ?
                ");
                $check->execute([$table]);

                if ((int)$check->fetchColumn() > 0) {
                    $delete = $conn->prepare(
                        "DELETE FROM \"$table\" WHERE user_id = ?"
                    );
                    $delete->execute([$deleteId]);
                }
            }

            $deleteUser = $conn->prepare("
                DELETE FROM users
                WHERE id = ?
                  AND LOWER(role) IN ('user', 'learner')
            ");
            $deleteUser->execute([$deleteId]);

            $conn->commit();

            header('Location: /admin/users.php?deleted=1');
            exit;

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log(
                'TestMate user deletion: ' . $e->getMessage()
            );

            $error = 'Could not delete the learner. Other records may depend on this account.';
        }
    }
}

// Individual learner details
try {
    if ($view_user > 0) {
        $stmt = $conn->prepare("
            SELECT *
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$view_user]);
        $user_detail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_detail) {
            $stmt = $conn->prepare("
                SELECT qs.*, t.name AS topic_name
                FROM quiz_scores qs
                LEFT JOIN topics t ON qs.topic_id = t.id
                WHERE qs.user_id = ?
                ORDER BY qs.taken_at DESC
            ");
            $stmt->execute([$view_user]);
            $user_quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                SELECT *
                FROM mock_scores
                WHERE user_id = ?
                ORDER BY taken_at DESC
            ");
            $stmt->execute([$view_user]);
            $user_mocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                SELECT
                    fq.*,
                    q.question,
                    q.option_a,
                    q.option_b,
                    q.option_c,
                    q.option_d,
                    q.correct_answer,
                    t.name AS topic_name
                FROM failed_questions fq
                LEFT JOIN questions q
                    ON fq.question_id = q.id
                LEFT JOIN topics t
                    ON fq.topic_id = t.id
                WHERE fq.user_id = ?
                ORDER BY fq.times_failed DESC
            ");
            $stmt->execute([$view_user]);
            $user_failed = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                SELECT
                    aq.*,
                    q.question AS question_text
                FROM assigned_quizzes aq
                LEFT JOIN questions q
                    ON aq.question_id = q.id
                WHERE aq.user_id = ?
                ORDER BY aq.assigned_at DESC
            ");
            $stmt->execute([$view_user]);
            $user_assigned = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Complete user list and progress counts
    $users = $conn->query("
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at,
            u.licence_code,

            (SELECT COUNT(*)
             FROM quiz_scores qs
             WHERE qs.user_id = u.id) AS quizzes,

            (SELECT COUNT(*)
             FROM mock_scores ms
             WHERE ms.user_id = u.id) AS mocks,

            (SELECT COUNT(*)
             FROM failed_questions fq
             WHERE fq.user_id = u.id) AS failed_q,

            (SELECT COUNT(*)
             FROM assigned_quizzes aq
             WHERE aq.user_id = u.id
               AND aq.is_completed = 0) AS assigned

        FROM users u
        ORDER BY u.created_at DESC, u.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log(
        'TestMate users page: ' . $e->getMessage()
    );

    $error = 'Unable to load some learner information.';
}

$passed_mocks = 0;
foreach ($user_mocks as $m) {
    if ((int)($m['passed'] ?? 0) === 1) {
        $passed_mocks++;
    }
}

$pending_assigned = 0;
foreach ($user_assigned as $a) {
    if ((int)($a['is_completed'] ?? 0) === 0) {
        $pending_assigned++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users – TestMate Admin</title>
<link rel="stylesheet" href="/css/style.css">

<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f6fa;color:#2c3e50}
.navbar{min-height:60px;background:#2c3e50;display:flex;justify-content:space-between;align-items:center;padding:12px 24px;gap:12px}
.navbar a{color:white;text-decoration:none}
.brand{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}
.brand-icon{background:#3498db;border-radius:8px;width:34px;height:34px;display:grid;place-items:center}
.nav-links{display:flex;align-items:center;gap:18px;font-size:14px}
.btn-logout{background:#e74c3c;padding:9px 15px;border-radius:6px}
.admin-wrap{display:flex;min-height:calc(100vh - 60px)}
.sidebar{width:230px;background:#1a252f;color:white;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:calc(100vh - 60px);overflow-y:auto}
.sidebar h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);padding:0 20px;margin:20px 0 8px}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.8);text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:white}
.main-content{flex:1;min-width:0;padding:30px;background:#f5f6fa;overflow-x:auto}
.card{background:white;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.table-wrap{background:white;border-radius:10px;overflow-x:auto;box-shadow:0 2px 8px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{background:#eef2f7;text-align:left;padding:12px;color:#41566d;white-space:nowrap}
td{padding:12px;border-bottom:1px solid #edf0f4;vertical-align:middle}
.btn{display:inline-block;padding:9px 15px;border-radius:7px;text-decoration:none;font-size:14px;font-weight:700;border:1px solid #d9e4ef;cursor:pointer}
.btn-primary{background:#3498db;color:white;border-color:#3498db}
.btn-outline{background:white;color:#2c3e50}
.badge{display:inline-block;border-radius:6px;padding:5px 9px;font-size:12px;font-weight:700}
.badge-pass{background:#eafaf1;color:#229954}
.badge-fail{background:#fdecea;color:#c0392b}
.failed-tag{background:#fdecea;color:#c0392b;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pending-tag{background:#eaf4ff;color:#2471a3;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px}
.stat-num{font-size:2rem;font-weight:800}
.options-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.notice{padding:13px 16px;margin-bottom:16px;border-radius:8px;background:#fdecea;color:#a93226}
.page-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.inline-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.delete-button{background:none;border:none;padding:0;color:#e74c3c;font-size:13px;cursor:pointer}
@media(max-width:750px){
.admin-wrap{flex-direction:column}
.sidebar{width:100%;height:auto;position:static}
.main-content{padding:18px 12px}
.navbar{flex-wrap:wrap}
.options-grid{grid-template-columns:1fr}
}
</style>
</head>

<body>
<nav class="navbar">
    <a href="/admin/index.php" class="brand">
        <span class="brand-icon">T</span> TestMate Admin
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
    <a href="/admin/users.php" class="active">Users</a>
    <a href="/admin/add-user.php">Add User</a>
    <a href="/admin/stats.php">Statistics</a>

    <h3>Questions</h3>
    <a href="/admin/questions.php">All Questions</a>
    <a href="/admin/add-question.php">Add Question</a>
    <a href="/admin/assign-quiz.php">Assign Quiz</a>

    <h3>Content</h3>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

<?php if ($error !== ''): ?>
    <div class="notice"><?= userEsc($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="margin-bottom:16px;color:#27ae60;">
        User deleted successfully.
    </div>
<?php endif; ?>

<?php if ($view_user > 0 && !$user_detail): ?>
    <div class="card" style="margin-bottom:20px;">
        User not found or details unavailable.
        <a href="/admin/users.php">Back to Users</a>
    </div>
<?php endif; ?>

<?php if ($user_detail): ?>

    <!-- User details -->
    <div class="page-actions">
        <div>
            <h1 style="font-size:22px;margin:0 0 5px;">
                <?= userEsc($user_detail['name']) ?>
            </h1>
            <p style="color:#888;font-size:14px;margin:0;">
                <?= userEsc($user_detail['email']) ?>
                · Joined <?= userDate($user_detail['created_at'] ?? '') ?>
                · <?= userEsc($user_detail['licence_code'] ?? 'Code2') ?>
            </p>
        </div>

        <div class="inline-actions">
            <a href="/admin/assign-quiz.php" class="btn btn-primary">
                Assign Questions
            </a>
            <a href="/admin/users.php" class="btn btn-outline">
                Back to Users
            </a>

            <?php if (strtolower((string)$user_detail['role']) !== 'admin'): ?>
                <form
                    method="POST"
                    action="/admin/users.php"
                    onsubmit="return confirm('Delete this learner and their related learning records? This cannot be undone.');"
                >
                    <input type="hidden" name="csrf_token"
                           value="<?= userEsc($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id"
                           value="<?= (int)$user_detail['id'] ?>">
                    <button type="submit" class="btn"
                            style="background:#e74c3c;color:white;">
                        Delete User
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress summary -->
    <div class="stats-grid">

        <?php
        $userStats = [
            ['Quizzes Taken', count($user_quizzes), '#3498db'],
            ['Mock Tests', count($user_mocks), '#2c3e50'],
            ['Tests Passed', $passed_mocks, '#27ae60'],
            ['Failed Questions', count($user_failed), '#e74c3c'],
            ['Pending Assignments', $pending_assigned, '#3498db']
        ];
        ?>

        <?php foreach ($userStats as $item): ?>
            <div class="card" style="text-align:center;">
                <div class="stat-num"
                     style="color:<?= $item[2] ?>;">
                    <?= $item[1] ?>
                </div>
                <div style="font-size:13px;color:#888;">
                    <?= userEsc($item[0]) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Failed questions -->
    <?php if (!empty($user_failed)): ?>
        <h2 style="font-size:18px;margin-bottom:14px;">
            Failed Questions (<?= count($user_failed) ?>)
        </h2>

        <div style="margin-bottom:24px;">
        <?php foreach ($user_failed as $fq): ?>
            <div class="card" style="margin-bottom:12px;border-left:4px solid #e74c3c;">
                <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:8px;">
                    <span class="badge" style="background:#f0f0f0;color:#666;">
                        <?= userEsc($fq['topic_name'] ?? 'Unknown Topic') ?>
                    </span>

                    <span class="failed-tag">
                        Failed <?= (int)$fq['times_failed'] ?>
                        time<?= (int)$fq['times_failed'] === 1 ? '' : 's' ?>
                    </span>
                </div>

                <p style="font-size:14px;font-weight:600;margin:0 0 10px;">
                    <?= userEsc($fq['question'] ?? 'Question unavailable') ?>
                </p>

                <div class="options-grid">
                    <?php foreach (['A','B','C','D'] as $letter): ?>
                        <?php
                        $option = $fq['option_' . strtolower($letter)] ?? '';
                        $correct = strtoupper(
                            (string)($fq['correct_answer'] ?? '')
                        ) === $letter;
                        ?>
                        <div style="
                            background:<?= $correct ? '#eafaf1' : '#f8f9fa' ?>;
                            border-radius:6px;
                            padding:7px 10px;
                            font-size:13px;
                        ">
                            <strong><?= $letter ?>.</strong>
                            <?= userEsc($option) ?>
                            <?= $correct ? ' ✓' : '' ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Assigned questions -->
    <?php if (!empty($user_assigned)): ?>
        <h2 style="font-size:18px;margin-bottom:14px;">
            Assigned Questions (<?= count($user_assigned) ?>)
        </h2>

        <div class="table-wrap" style="margin-bottom:24px;">
            <table>
                <thead>
                    <tr>
                        <th>Question</th>
                        <th>Assigned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($user_assigned as $a): ?>
                    <tr>
                        <td><?= userEsc(mb_substr(
                            (string)($a['question_text'] ?? 'Question unavailable'),
                            0, 80, 'UTF-8'
                        )) ?>...</td>
                        <td><?= userDate($a['assigned_at'] ?? '') ?></td>
                        <td>
                            <?php if ((int)$a['is_completed'] === 1): ?>
                                <span class="badge badge-pass">Completed</span>
                            <?php else: ?>
                                <span class="pending-tag">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Quiz history -->
    <h2 style="font-size:18px;margin-bottom:14px;">Quiz History</h2>

    <?php if (empty($user_quizzes)): ?>
        <div class="card" style="text-align:center;color:#888;margin-bottom:24px;">
            No quizzes taken yet.
        </div>
    <?php else: ?>
        <div class="table-wrap" style="margin-bottom:24px;">
            <table>
                <thead>
                    <tr>
                        <th>Topic</th>
                        <th>Score</th>
                        <th>Percentage</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($user_quizzes as $uq): ?>
                    <?php
                    $pct = userPercent($uq['score'], $uq['total']);
                    ?>
                    <tr>
                        <td><?= userEsc($uq['topic_name'] ?? 'Unknown') ?></td>
                        <td><?= (int)$uq['score'] ?>/<?= (int)$uq['total'] ?></td>
                        <td>
                            <span class="badge <?= $pct >= 80 ? 'badge-pass' : 'badge-fail' ?>">
                                <?= $pct ?>%
                            </span>
                        </td>
                        <td><?= userDate($uq['taken_at'] ?? '', true) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Mock test history -->
    <h2 style="font-size:18px;margin-bottom:14px;">Mock Test History</h2>

    <?php if (empty($user_mocks)): ?>
        <div class="card" style="text-align:center;color:#888;">
            No mock tests taken yet.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Score</th>
                        <th>Percentage</th>
                        <th>Time Used</th>
                        <th>Result</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($user_mocks as $um): ?>
                    <?php
                    $pct = userPercent($um['score'], $um['total']);
                    $seconds = max(0, (int)($um['time_taken'] ?? 0));
                    $minutes = intdiv($seconds, 60);
                    $remaining = $seconds % 60;
                    $passed = (int)($um['passed'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td><?= (int)$um['score'] ?>/<?= (int)$um['total'] ?></td>
                        <td><?= $pct ?>%</td>
                        <td><?= $minutes ?>m <?= $remaining ?>s</td>
                        <td>
                            <span class="badge <?= $passed ? 'badge-pass' : 'badge-fail' ?>">
                                <?= $passed ? 'PASSED' : 'FAILED' ?>
                            </span>
                        </td>
                        <td><?= userDate($um['taken_at'] ?? '', true) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>

    <!-- User listing -->
    <div class="page-actions">
        <h1 style="font-size:22px;margin:0;">
            All Users (<?= count($users) ?>)
        </h1>

        <div class="inline-actions">
            <a href="/admin/add-user.php" class="btn btn-primary">
                Add User
            </a>
            <a href="/admin/assign-quiz.php" class="btn btn-primary">
                Assign Quiz
            </a>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Code</th>
                    <th>Role</th>
                    <th>Quizzes</th>
                    <th>Tests</th>
                    <th>Failed</th>
                    <th>Pending</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($users as $u): ?>
                <tr>
                    <td style="font-weight:600;">
                        <?= userEsc($u['name']) ?>
                    </td>

                    <td style="color:#888;font-size:13px;">
                        <?= userEsc($u['email']) ?>
                    </td>

                    <td><?= userEsc($u['licence_code'] ?? 'Code2') ?></td>

                    <td>
                        <?php if (strtolower((string)$u['role']) === 'admin'): ?>
                            <span class="badge badge-fail">Admin</span>
                        <?php else: ?>
                            <span class="badge badge-pass">User</span>
                        <?php endif; ?>
                    </td>

                    <td><?= (int)$u['quizzes'] ?></td>
                    <td><?= (int)$u['mocks'] ?></td>

                    <td>
                        <?php if ((int)$u['failed_q'] > 0): ?>
                            <span class="failed-tag">
                                <?= (int)$u['failed_q'] ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#27ae60;">None</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ((int)$u['assigned'] > 0): ?>
                            <span class="pending-tag">
                                <?= (int)$u['assigned'] ?>
                            </span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>

                    <td><?= userDate($u['created_at'] ?? '') ?></td>

                    <td style="white-space:nowrap;">
                        <a
                            href="/admin/users.php?view=<?= (int)$u['id'] ?>"
                            style="color:#3498db;font-size:13px;text-decoration:none;margin-right:10px;"
                        >
                            View
                        </a>

                        <?php if (strtolower((string)$u['role']) !== 'admin'): ?>
                            <form
                                method="POST"
                                action="/admin/users.php"
                                style="display:inline;"
                                onsubmit="return confirm('Permanently delete this learner and their learning history?');"
                            >
                                <input type="hidden" name="csrf_token"
                                       value="<?= userEsc($_SESSION['admin_csrf']) ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id"
                                       value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="delete-button">
                                    Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;color:#888;">
                        No users found.
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>
    </div>

<?php endif; ?>

</main>
</div>
</body>
</html>
