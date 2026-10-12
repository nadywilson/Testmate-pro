<?php

require '../includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();



if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {

    header("Location: /testmate/login.php");

    exit();

}



// ── Helper: check if a column exists in a table ──

function column_exists($conn, $table, $column) {

    $res = $conn->query("SHOW COLUMNS FROM \`$table\` LIKE '$column'");

    return $res && $res->num_rows > 0;

}



$has_quiz_status  = column_exists($conn, 'quiz_scores', 'status');

$has_quiz_time    = column_exists($conn, 'quiz_scores', 'submitted_at');

$has_mock_status  = column_exists($conn, 'mock_scores', 'status');

$mock_table_exists = $conn->query("SHOW TABLES LIKE 'mock_scores'")->num_rows > 0;



// Handle approve/reject actions

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['score_id'])) {

    $scoreId = (int)$_POST['score_id'];

    $action  = $_POST['action'];

    $table   = ($_POST['table'] ?? 'quiz_scores') === 'mock_scores' ? 'mock_scores' : 'quiz_scores';



    if ($action === 'approve') {

        $conn->query("UPDATE \`$table\` SET status = 'approved' WHERE id = $scoreId");

    } elseif ($action === 'reject') {

        $conn->query("UPDATE \`$table\` SET status = 'rejected' WHERE id = $scoreId");

    }

    header("Location: /testmate/admin/review-scores.php");

    exit();

}



// Fetch pending quiz scores (only if columns exist)

$pending_quiz = [];

if ($has_quiz_status && $has_quiz_time) {

    $pending_quiz = $conn->query("

        SELECT qs.id, qs.user_id, qs.topic_id, qs.score, qs.total, qs.submitted_at, qs.status,

               u.name AS user_name, t.name AS topic_name

        FROM quiz_scores qs

        JOIN users u ON qs.user_id = u.id

        LEFT JOIN topics t ON qs.topic_id = t.id

        WHERE qs.status = 'pending'

        ORDER BY qs.submitted_at ASC

    ")->fetch_all(MYSQLI_ASSOC);

}



// Fetch pending mock scores (only if table + columns exist)

$pending_mock = [];

if ($mock_table_exists && $has_mock_status) {

    $pending_mock = $conn->query("

        SELECT ms.id, ms.user_id, ms.score, ms.total, ms.submitted_at, ms.status,

               u.name AS user_name

        FROM mock_scores ms

        JOIN users u ON ms.user_id = u.id

        WHERE ms.status = 'pending'

        ORDER BY ms.id ASC

    ")->fetch_all(MYSQLI_ASSOC);

}



// Counts for display

$quiz_pending_count = count($pending_quiz);

$mock_pending_count = count($pending_mock);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Review Scores – TestMate Admin</title>

    <link rel="stylesheet" href="/testmate/css/style.css">

    <style>

        .admin-wrap{display:flex;min-height:calc(100vh - 60px);}

        .sidebar{width:230px;background:#1a252f;color:white;padding:24px 0;flex-shrink:0;position:sticky;top:60px;height:calc(100vh - 60px);overflow-y:auto;}

        .sidebar h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);padding:0 20px;margin-bottom:8px;margin-top:20px;}

        .sidebar h3:first-child{margin-top:0;}

        .sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.8);text-decoration:none;font-size:14px;transition:all .15s;}

        .sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:white;}

        .main-content{
            flex:1;
            padding:30px;
            background:
                radial-gradient(circle at top right,rgba(244,122,31,.22),transparent 35%),
                linear-gradient(135deg,#5A2C18 0%,#3A2117 48%,#2B1A12 100%);
        }

        .review-card{background:white;border-radius:10px;padding:18px 20px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}

        .review-meta{font-size:13px;color:#888;}

        .review-score{font-weight:800;font-size:20px;color:#2c3e50;}

        .alert-box{background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:16px 20px;border-radius:8px;margin-bottom:24px;}

        .badge-pending{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;}

    </style>

</head>

<body>

<nav class="navbar">

    <a href="/testmate/admin/index.php" class="brand"><span class="brand-icon">T</span> TestMate Admin</a>

    <div class="nav-links">

        <a href="/testmate/index.php" style="color:rgba(255,255,255,.8);font-size:14px;">← View Site</a>

        <a href="/testmate/logout.php" class="btn-logout">Logout</a>

    </div>

</nav>

<div class="admin-wrap">

    <div class="sidebar">

        <h3>Main</h3>

        <a href="/testmate/admin/index.php">📊 Dashboard</a>

        <a href="/testmate/admin/users.php">👥 Users</a>

        <a href="/testmate/admin/stats.php">📈 Statistics</a>

        <h3>Questions</h3>

        <a href="/testmate/admin/questions.php">❓ All Questions</a>

        <a href="/testmate/admin/add-question.php">➕ Add Question</a>

        <a href="/testmate/admin/review-scores.php" class="active">✅ Review Scores</a>

        <h3>Content</h3>

        <a href="/testmate/admin/materials.php">📚 Materials</a>

        <a href="/testmate/admin/add-material.php">➕ Add Material</a>

    </div>

    <div class="main-content">

        <h1 style="font-size:22px;margin-bottom:6px;">✅ Review Pending Scores</h1>

        <p style="color:#888;font-size:14px;margin-bottom:24px;">

            Learner scores auto-reveal after 2 minutes. Approve here to reveal instantly, or reject if you suspect cheating.

        </p>



        <?php if (!$has_quiz_status || !$has_quiz_time): ?>

        <div class="alert-box">

            <strong>Database issue:</strong> Your <code>quiz_scores</code> table is missing the

            <code><?= !$has_quiz_status ? 'status' : 'submitted_at' ?></code> column.

            Run the SQL below to fix it, then refresh this page.

        </div>

        <?php endif; ?>



        <?php if ($mock_table_exists && !$has_mock_status): ?>

        <div class="alert-box">

            <strong>Database issue:</strong> Your <code>mock_scores</code> table is missing the

            <code>status</code> column. Run the SQL below to fix it.

        </div>

        <?php endif; ?>



        <h2 style="font-size:16px;font-weight:700;margin-bottom:12px;">Topic Quizzes (<?= $quiz_pending_count ?> pending)</h2>

        <?php if (!$has_quiz_status): ?>

        <div class="card" style="padding:24px;text-align:center;color:#888;margin-bottom:30px;">

            Cannot load — <code>status</code> column missing in <code>quiz_scores</code>.

        </div>

        <?php elseif (empty($pending_quiz)): ?>

        <div class="card" style="padding:24px;text-align:center;color:#888;margin-bottom:30px;">Nothing pending right now.</div>

        <?php else: ?>

        <div style="margin-bottom:30px;">

            <?php foreach ($pending_quiz as $row):

                $elapsed = time() - strtotime($row['submitted_at']);

                $secs_left = max(0, 120 - $elapsed);

            ?>

            <div class="review-card">

                <div>

                    <div style="font-weight:600;color:#2c3e50;"><?= htmlspecialchars($row['user_name']) ?></div>

                    <div class="review-meta"><?= htmlspecialchars($row['topic_name'] ?? 'Unknown topic') ?> · submitted <?= htmlspecialchars($row['submitted_at']) ?></div>

                </div>

                <div class="review-score"><?= $row['score'] ?>/<?= $row['total'] ?></div>

                <div class="review-meta">

                    <?php if ($secs_left > 0): ?>

                        auto-reveals in <?= $secs_left ?>s

                    <?php else: ?>

                        <span style="color:#27ae60;">ready to auto-reveal</span>

                    <?php endif; ?>

                </div>

                <div style="display:flex;gap:8px;">

                    <form method="POST" style="display:inline;">

                        <input type="hidden" name="score_id" value="<?= $row['id'] ?>">

                        <input type="hidden" name="table" value="quiz_scores">

                        <input type="hidden" name="action" value="approve">

                        <button type="submit" class="btn btn-primary" style="font-size:13px;padding:7px 14px;">Approve Now</button>

                    </form>

                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this attempt? The learner will not see their score.');">

                        <input type="hidden" name="score_id" value="<?= $row['id'] ?>">

                        <input type="hidden" name="table" value="quiz_scores">

                        <input type="hidden" name="action" value="reject">

                        <button type="submit" class="btn btn-outline" style="font-size:13px;padding:7px 14px;border-color:#e74c3c;color:#e74c3c;">Reject</button>

                    </form>

                </div>

            </div>

            <?php endforeach; ?>

        </div>

        <?php endif; ?>



        <h2 style="font-size:16px;font-weight:700;margin-bottom:12px;">Mock Tests (<?= $mock_pending_count ?> pending)</h2>

        <?php if (!$mock_table_exists): ?>

        <div class="card" style="padding:24px;text-align:center;color:#888;">mock_scores table not found.</div>

        <?php elseif (!$has_mock_status): ?>

        <div class="card" style="padding:24px;text-align:center;color:#888;">Cannot load — <code>status</code> column missing in <code>mock_scores</code>.</div>

        <?php elseif (empty($pending_mock)): ?>

        <div class="card" style="padding:24px;text-align:center;color:#888;">Nothing pending right now.</div>

        <?php else: ?>

        <?php foreach ($pending_mock as $row): ?>

        <div class="review-card">

            <div style="font-weight:600;color:#2c3e50;"><?= htmlspecialchars($row['user_name']) ?></div>

            <div class="review-score"><?= $row['score'] ?>/<?= $row['total'] ?></div>

            <div style="display:flex;gap:8px;">

                <form method="POST" style="display:inline;">

                    <input type="hidden" name="score_id" value="<?= $row['id'] ?>">

                    <input type="hidden" name="table" value="mock_scores">

                    <input type="hidden" name="action" value="approve">

                    <button type="submit" class="btn btn-primary" style="font-size:13px;padding:7px 14px;">Approve Now</button>

                </form>

                <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this attempt?');">

                    <input type="hidden" name="score_id" value="<?= $row['id'] ?>">

                    <input type="hidden" name="table" value="mock_scores">

                    <input type="hidden" name="action" value="reject">

                    <button type="submit" class="btn btn-outline" style="font-size:13px;padding:7px 14px;border-color:#e74c3c;color:#e74c3c;">Reject</button>

                </form>

            </div>

        </div>

        <?php endforeach; ?>

        <?php endif; ?>



        <?php if (!$has_quiz_status || !$has_quiz_time || ($mock_table_exists && !$has_mock_status)): ?>

        <div class="card" style="margin-top:30px;background:#f8f9fa;">

            <h3 style="font-size:15px;margin-bottom:12px;">🔧 Fix SQL (run in phpMyAdmin)</h3>

            <pre style="background:#1a252f;color:#a8e6cf;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;">

<?php if (!$has_quiz_status): ?>ALTER TABLE quiz_scores ADD COLUMN status VARCHAR(20) DEFAULT 'pending';

<?php endif; ?>

<?php if (!$has_quiz_time): ?>ALTER TABLE quiz_scores ADD COLUMN submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

<?php endif; ?>

<?php if ($mock_table_exists && !$has_mock_status): ?>ALTER TABLE mock_scores ADD COLUMN status VARCHAR(20) DEFAULT 'pending';

ALTER TABLE mock_scores ADD COLUMN submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

<?php endif; ?></pre>

        </div>

        <?php endif; ?>



    </div>

</div>

</body>

</html>