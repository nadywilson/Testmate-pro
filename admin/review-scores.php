
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

function reviewEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function reviewTableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM sqlite_master
        WHERE type = 'table' AND name = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function reviewColumnExists(
    PDO $conn,
    string $table,
    string $column
): bool {
    $allowed = ['quiz_scores', 'mock_scores'];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    if (!reviewTableExists($conn, $table)) {
        return false;
    }

    $columns = $conn->query(
        "PRAGMA table_info(\"$table\")"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if ($col['name'] === $column) {
            return true;
        }
    }

    return false;
}

$error = '';
$message = '';

$pending_quiz = [];
$pending_mock = [];

$has_quiz_status = reviewColumnExists(
    $conn, 'quiz_scores', 'status'
);

$has_quiz_time = reviewColumnExists(
    $conn, 'quiz_scores', 'submitted_at'
);

$mock_table_exists = reviewTableExists(
    $conn, 'mock_scores'
);

$has_mock_status = reviewColumnExists(
    $conn, 'mock_scores', 'status'
);

$has_mock_time = reviewColumnExists(
    $conn, 'mock_scores', 'submitted_at'
);

// Review an attempt
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'], $_POST['score_id'])
) {
    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $scoreId = (int)$_POST['score_id'];
    $action = (string)$_POST['action'];

    $table = ($_POST['table'] ?? '') === 'mock_scores'
        ? 'mock_scores'
        : 'quiz_scores';

    if (
        $scoreId <= 0 ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        $error = 'Invalid review action.';
    } elseif (!reviewColumnExists($conn, $table, 'status')) {
        $error = 'The selected score table does not have a status column.';
    } else {
        try {
            $status = $action === 'approve'
                ? 'approved'
                : 'rejected';

            $stmt = $conn->prepare("
                UPDATE \"$table\"
                SET status = :status
                WHERE id = :id
                  AND status = 'pending'
            ");

            $stmt->execute([
                ':status' => $status,
                ':id' => $scoreId
            ]);

            header(
                'Location: /admin/review-scores.php?msg=' .
                ($stmt->rowCount() > 0 ? $status : 'unchanged')
            );
            exit;

        } catch (PDOException $e) {
            error_log(
                'Score review error: ' . $e->getMessage()
            );

            $error = 'Could not update this score.';
        }
    }
}

try {
    if ($has_quiz_status && $has_quiz_time) {
        $pending_quiz = $conn->query("
            SELECT
                qs.id,
                qs.user_id,
                qs.topic_id,
                qs.score,
                qs.total,
                qs.submitted_at,
                qs.status,
                u.name AS user_name,
                t.name AS topic_name
            FROM quiz_scores qs
            JOIN users u ON qs.user_id = u.id
            LEFT JOIN topics t ON qs.topic_id = t.id
            WHERE qs.status = 'pending'
            ORDER BY qs.submitted_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($mock_table_exists && $has_mock_status) {
        $timeExpression = $has_mock_time
            ? 'ms.submitted_at'
            : 'NULL AS submitted_at';

        $pending_mock = $conn->query("
            SELECT
                ms.id,
                ms.user_id,
                ms.score,
                ms.total,
                $timeExpression,
                ms.status,
                u.name AS user_name
            FROM mock_scores ms
            JOIN users u ON ms.user_id = u.id
            WHERE ms.status = 'pending'
            ORDER BY ms.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log('Pending scores error: ' . $e->getMessage());
    $error = 'Unable to load pending scores.';
}

$quiz_pending_count = count($pending_quiz);
$mock_pending_count = count($pending_mock);

switch ($_GET['msg'] ?? '') {
    case 'approved':
        $message = 'Score approved successfully.';
        break;
    case 'rejected':
        $message = 'Score rejected successfully.';
        break;
    case 'unchanged':
        $message = 'The score was already reviewed or no longer pending.';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Scores – TestMate Admin</title>
<link rel="stylesheet" href="/css/style.css">

<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6fa;color:#2c3e50;font-family:Arial,Helvetica,sans-serif}
.navbar{background:#2c3e50;min-height:60px;display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:15px}
.navbar a{color:white;text-decoration:none}
.brand{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}
.brand-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:8px;background:#3498db}
.nav-links{display:flex;align-items:center;gap:20px;font-size:14px}
.btn-logout{background:#e74c3c;padding:9px 15px;border-radius:6px}
.admin-wrap{display:flex;min-height:calc(100vh - 60px)}
.sidebar{width:230px;background:#1a252f;color:white;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:calc(100vh - 60px);overflow-y:auto}
.sidebar h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);padding:0 20px;margin:20px 0 8px}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.8);text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:white}
.main-content{flex:1;min-width:0;padding:30px;background:#f5f6fa}
.review-card{background:white;border-radius:10px;padding:18px 20px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.review-meta{font-size:13px;color:#888;margin-top:5px}
.review-score{font-weight:800;font-size:20px;color:#2c3e50}
.alert-box{background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:16px 20px;border-radius:8px;margin-bottom:24px}
.badge-pending{background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600}
.card{background:white;border-radius:10px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.btn{border:1px solid #d6e1ed;border-radius:7px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer}
.btn-primary{background:#3498db;color:white;border-color:#3498db}
.btn-outline{background:white;color:#e74c3c;border-color:#e74c3c}
.message{padding:13px 16px;border-radius:8px;margin-bottom:16px;background:#eafaf1;color:#216e43}
.error{background:#fdecea;color:#a93226}
@media(max-width:750px){
.admin-wrap{flex-direction:column}
.sidebar{width:100%;height:auto;position:static}
.main-content{padding:20px 14px}
.navbar{padding:12px 14px;flex-wrap:wrap}
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
    <a href="/admin/users.php">Users</a>
    <a href="/admin/stats.php">Statistics</a>

    <h3>Questions</h3>
    <a href="/admin/questions.php">All Questions</a>
    <a href="/admin/add-question.php">Add Question</a>
    <a href="/admin/review-scores.php" class="active">Review Scores</a>

    <h3>Content</h3>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

    <h1 style="font-size:22px;margin:0 0 6px;">
        Review Pending Scores
    </h1>

    <p style="color:#888;font-size:14px;margin:0 0 24px;">
        Learner scores can be approved instantly or rejected
        after administrator review.
    </p>

    <?php if ($message !== ''): ?>
        <div class="message"><?= reviewEsc($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= reviewEsc($error) ?></div>
    <?php endif; ?>

    <?php if (!$has_quiz_status || !$has_quiz_time): ?>
        <div class="alert-box">
            The quiz_scores table is missing:
            <strong>
                <?= !$has_quiz_status ? 'status ' : '' ?>
                <?= !$has_quiz_time ? 'submitted_at' : '' ?>
            </strong>.
            These columns must be added to support pending-score review.
        </div>
    <?php endif; ?>

    <?php if ($mock_table_exists && !$has_mock_status): ?>
        <div class="alert-box">
            The mock_scores table is missing its
            <strong>status</strong> column.
        </div>
    <?php endif; ?>

    <!-- Topic quizzes -->
    <h2 style="font-size:16px;margin:0 0 12px;">
        Topic Quizzes (<?= $quiz_pending_count ?> pending)
    </h2>

    <?php if (!$has_quiz_status || !$has_quiz_time): ?>

        <div class="card" style="text-align:center;color:#888;margin-bottom:30px;">
            Pending quiz scores cannot be loaded until the
            required database columns are available.
        </div>

    <?php elseif (empty($pending_quiz)): ?>

        <div class="card" style="text-align:center;color:#888;margin-bottom:30px;">
            Nothing pending right now.
        </div>

    <?php else: ?>

        <div style="margin-bottom:30px;">
            <?php foreach ($pending_quiz as $row): ?>
                <?php
                $submitted = strtotime(
                    (string)($row['submitted_at'] ?? '')
                );
                $elapsed = $submitted !== false
                    ? max(0, time() - $submitted)
                    : 0;
                $secs_left = max(0, 120 - $elapsed);
                ?>

                <div class="review-card">
                    <div>
                        <div style="font-weight:700;">
                            <?= reviewEsc($row['user_name']) ?>
                        </div>
                        <div class="review-meta">
                            <?= reviewEsc($row['topic_name'] ?? 'Unknown topic') ?>
                            · submitted
                            <?= reviewEsc($row['submitted_at']) ?>
                        </div>
                    </div>

                    <div class="review-score">
                        <?= (int)$row['score'] ?>/<?= (int)$row['total'] ?>
                    </div>

                    <div class="review-meta">
                        <?php if ($submitted === false): ?>
                            Submission time unavailable
                        <?php elseif ($secs_left > 0): ?>
                            Eligible for automatic reveal in
                            <?= $secs_left ?>s
                        <?php else: ?>
                            <span style="color:#27ae60;">
                                Eligible for automatic reveal
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token"
                                   value="<?= reviewEsc($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="score_id"
                                   value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="table"
                                   value="quiz_scores">
                            <input type="hidden" name="action"
                                   value="approve">
                            <button type="submit" class="btn btn-primary">
                                Approve Now
                            </button>
                        </form>

                        <form method="POST"
                              onsubmit="return confirm('Reject this quiz attempt?');">
                            <input type="hidden" name="csrf_token"
                                   value="<?= reviewEsc($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="score_id"
                                   value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="table"
                                   value="quiz_scores">
                            <input type="hidden" name="action"
                                   value="reject">
                            <button type="submit" class="btn btn-outline">
                                Reject
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <!-- Mock tests -->
    <h2 style="font-size:16px;margin:0 0 12px;">
        Mock Tests (<?= $mock_pending_count ?> pending)
    </h2>

    <?php if (!$mock_table_exists): ?>

        <div class="card" style="color:#888;">
            The mock_scores table was not found.
        </div>

    <?php elseif (!$has_mock_status): ?>

        <div class="card" style="color:#888;">
            Pending mock-test scores require a status column.
        </div>

    <?php elseif (empty($pending_mock)): ?>

        <div class="card" style="text-align:center;color:#888;">
            Nothing pending right now.
        </div>

    <?php else: ?>

        <?php foreach ($pending_mock as $row): ?>
            <div class="review-card">

                <div>
                    <div style="font-weight:700;">
                        <?= reviewEsc($row['user_name']) ?>
                    </div>

                    <?php if (!empty($row['submitted_at'])): ?>
                        <div class="review-meta">
                            Submitted <?= reviewEsc($row['submitted_at']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="review-score">
                    <?= (int)$row['score'] ?>/<?= (int)$row['total'] ?>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token"
                               value="<?= reviewEsc($_SESSION['admin_csrf']) ?>">
                        <input type="hidden" name="score_id"
                               value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="table"
                               value="mock_scores">
                        <input type="hidden" name="action"
                               value="approve">
                        <button type="submit" class="btn btn-primary">
                            Approve Now
                        </button>
                    </form>

                    <form method="POST"
                          onsubmit="return confirm('Reject this mock-test attempt?');">
                        <input type="hidden" name="csrf_token"
                               value="<?= reviewEsc($_SESSION['admin_csrf']) ?>">
                        <input type="hidden" name="score_id"
                               value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="table"
                               value="mock_scores">
                        <input type="hidden" name="action"
                               value="reject">
                        <button type="submit" class="btn btn-outline">
                            Reject
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</main>
</div>
</body>
</html>
