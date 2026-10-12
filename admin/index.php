
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

function adminEsc($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$name = $_SESSION['name'] ?? 'Administrator';

$total_users = 0;
$total_q = 0;
$total_quizzes = 0;
$total_mocks = 0;
$total_passed = 0;
$total_assigned = 0;
$recent_users = [];
$topic_stats = [];
$error = '';

try {
    $total_users = (int)$conn->query("
        SELECT COUNT(*)
        FROM users
        WHERE LOWER(role) IN ('user','learner')
    ")->fetchColumn();

    $total_q = (int)$conn->query("
        SELECT COUNT(*) FROM questions
    ")->fetchColumn();

    $total_quizzes = (int)$conn->query("
        SELECT COUNT(*) FROM quiz_scores
    ")->fetchColumn();

    $total_mocks = (int)$conn->query("
        SELECT COUNT(*) FROM mock_scores
    ")->fetchColumn();

    $total_passed = (int)$conn->query("
        SELECT COUNT(*)
        FROM mock_scores
        WHERE passed = 1
    ")->fetchColumn();

    $total_assigned = (int)$conn->query("
        SELECT COUNT(*)
        FROM assigned_quizzes
        WHERE is_completed = 0
    ")->fetchColumn();

    $recent_users = $conn->query("
        SELECT id, name, email, created_at
        FROM users
        WHERE LOWER(role) IN ('user','learner')
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topic_stats = $conn->query("
        SELECT
            t.id,
            t.name,
            COUNT(qs.id) AS attempts,
            ROUND(
                AVG(
                    CASE WHEN qs.total > 0
                    THEN qs.score * 100.0 / qs.total
                    ELSE NULL END
                )
            ) AS avg_pct
        FROM topics t
        LEFT JOIN quiz_scores qs ON t.id = qs.topic_id
        GROUP BY t.id, t.name
        ORDER BY
            CASE WHEN COUNT(qs.id) = 0 THEN 1 ELSE 0 END,
            avg_pct ASC,
            t.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
    $error = 'Some dashboard statistics could not be loaded.';
}

$pass_rate = $total_mocks > 0
    ? round($total_passed / $total_mocks * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin – TestMate</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f6fa;color:#2c3e50}
.navbar{min-height:64px;background:#2c3e50;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:12px 26px}
.navbar a{color:white;text-decoration:none}
.brand{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}
.brand-icon{display:grid;place-items:center;background:#3498db;width:36px;height:36px;border-radius:7px}
.nav-links{display:flex;align-items:center;gap:25px;font-size:14px}
.btn-logout{background:#e74c3c;padding:11px 17px;border-radius:7px}
.admin-wrap{display:flex;min-height:calc(100vh - 64px)}
.sidebar{width:247px;background:#1a252f;color:white;padding:25px 0;flex-shrink:0}
.sidebar h3{font-size:11px;letter-spacing:1px;color:#8191a0;padding:0 21px;margin:25px 0 10px;text-transform:uppercase}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:block;padding:13px 21px;color:#e4eaf0;text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:#303e49;color:white}
.main-content{flex:1;min-width:0;padding:32px;background:#f5f6fa}
.page-title{font-size:24px;margin:0 0 5px}
.page-subtitle{color:#888;font-size:14px;margin:0 0 25px}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:16px;margin-bottom:28px}
.stat-card{background:white;border:1px solid #edf0f4;border-radius:10px;padding:22px 14px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.stat-card .num{font-size:2.2rem;font-weight:800;display:block;line-height:1.2}
.stat-card .lbl{font-size:13px;color:#888;margin-top:7px;display:block}
.content-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}
.content-grid h2{font-size:17px;margin:0 0 14px}
.table-wrap{background:white;border-radius:10px;overflow-x:auto;box-shadow:0 2px 8px rgba(0,0,0,.05)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;padding:14px;background:#eef2f7;color:#3d5165}
td{padding:13px;border-bottom:1px solid #eef0f4;vertical-align:middle}
.topic-list{display:flex;flex-direction:column;gap:10px}
.topic-card{background:white;border-radius:10px;padding:14px 16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.topic-heading{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;font-size:14px;font-weight:700}
.progress-bar{background:#e9eef4;height:9px;border-radius:20px;overflow:hidden}
.progress-fill{height:100%;border-radius:20px}
.view-all{display:inline-block;margin-top:12px;color:#3498db;text-decoration:none;font-size:13px;font-weight:700}
.notice{padding:14px;border-radius:8px;background:#fdecea;color:#a93226;margin-bottom:18px}
@media(max-width:1000px){.content-grid{grid-template-columns:1fr}}
@media(max-width:760px){
.admin-wrap{display:block}
.sidebar{width:100%;padding:10px 0}
.sidebar a{display:inline-block;padding:10px}
.sidebar h3{margin:12px 15px 5px}
.main-content{padding:20px 14px}
.navbar{flex-wrap:wrap}
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
    <a href="/admin/index.php" class="active">Dashboard</a>
    <a href="/admin/review-scores.php">Review Scores</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/add-user.php">Add User</a>
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

    <h1 class="page-title">
        Welcome, <?= adminEsc($name) ?>!
    </h1>
    <p class="page-subtitle">
        Here is an overview of TestMate.
    </p>

    <?php if ($error !== ''): ?>
        <div class="notice"><?= adminEsc($error) ?></div>
    <?php endif; ?>

    <section class="stat-grid">
        <div class="stat-card">
            <span class="num" style="color:#3498db">
                <?= $total_users ?>
            </span>
            <span class="lbl">Registered Users</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#2c3e50">
                <?= $total_q ?>
            </span>
            <span class="lbl">Questions</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#e67e22">
                <?= $total_quizzes ?>
            </span>
            <span class="lbl">Quizzes Taken</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#2c3e50">
                <?= $total_mocks ?>
            </span>
            <span class="lbl">Mock Tests Taken</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#27ae60">
                <?= $total_passed ?>
            </span>
            <span class="lbl">Mock Tests Passed</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#e74c3c">
                <?= $pass_rate ?>%
            </span>
            <span class="lbl">Pass Rate</span>
        </div>

        <div class="stat-card">
            <span class="num" style="color:#3498db">
                <?= $total_assigned ?>
            </span>
            <span class="lbl">Pending Assignments</span>
        </div>
    </section>

    <div class="content-grid">

        <section>
            <h2>Recent Users</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_users)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center;color:#888">
                                No users yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_users as $user): ?>
                            <?php
                            $timestamp = strtotime(
                                (string)($user['created_at'] ?? '')
                            );
                            ?>
                            <tr>
                                <td>
                                    <?= adminEsc($user['name']) ?>
                                </td>
                                <td style="font-size:13px;color:#888">
                                    <?= adminEsc($user['email']) ?>
                                </td>
                                <td style="font-size:13px;color:#888">
                                    <?= $timestamp !== false
                                        ? date('d M Y', $timestamp)
                                        : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <a href="/admin/users.php" class="view-all">
                View all users
            </a>
        </section>

        <section>
            <h2>Topic Performance</h2>
            <div class="topic-list">
                <?php if (empty($topic_stats)): ?>
                    <div class="topic-card">
                        No topic performance available.
                    </div>
                <?php else: ?>
                    <?php foreach ($topic_stats as $topic): ?>
                        <?php
                        $pct = max(
                            0,
                            min(100, (int)($topic['avg_pct'] ?? 0))
                        );
                        $attempts = (int)$topic['attempts'];
                        $color = $pct >= 80
                            ? '#27ae60'
                            : ($pct >= 60 ? '#e67e22' : '#e74c3c');
                        ?>
                        <div class="topic-card">
                            <div class="topic-heading">
                                <span><?= adminEsc($topic['name']) ?></span>
                                <span style="color:<?= $color ?>">
                                    <?= $pct ?>%
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill"
                                     style="width:<?= $pct ?>%;background:<?= $color ?>">
                                </div>
                            </div>
                            <div style="color:#999;font-size:12px;margin-top:6px">
                                <?= $attempts ?>
                                <?= $attempts === 1 ? 'attempt' : 'attempts' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </div>

</main>
</div>
</body>
</html>
