
<?php
/**
 * TestMate – Administrator Statistics
 * PHP 8.5 + PDO SQLite
 */

require_once __DIR__ . '/../includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin authentication
if (
    empty($_SESSION['user_id']) ||
    strtolower((string)($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: /login.php');
    exit;
}

// Safe HTML output
function statsEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Safe percentage
function statsPercent($passed, $total): int
{
    $total = (float)$total;

    if ($total <= 0) {
        return 0;
    }

    return (int)round((float)$passed / $total * 100);
}

$topic_stats = [];
$mock_by_day = [];

$mock_overall = [
    'total' => 0,
    'passed' => 0,
    'avg_pct' => 0
];

$error = '';

try {

    // Quiz performance for each topic
    $stmt = $conn->query("
        SELECT
            t.id,
            t.name,
            t.icon,

            COUNT(qs.id) AS attempts,

            ROUND(
                AVG(
                    CASE
                        WHEN qs.total > 0
                        THEN qs.score * 100.0 / qs.total
                        ELSE NULL
                    END
                )
            ) AS avg_pct,

            SUM(
                CASE
                    WHEN qs.total > 0
                     AND qs.score * 1.0 / qs.total >= 0.8
                    THEN 1
                    ELSE 0
                END
            ) AS passed

        FROM topics t

        LEFT JOIN quiz_scores qs
            ON t.id = qs.topic_id

        GROUP BY
            t.id,
            t.name,
            t.icon

        ORDER BY
            CASE WHEN COUNT(qs.id) = 0 THEN 1 ELSE 0 END,
            avg_pct ASC,
            t.name ASC
    ");

    $topic_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mock-test statistics grouped by date
    $stmt = $conn->query("
        SELECT
            DATE(taken_at) AS day,

            COUNT(*) AS total,

            COALESCE(
                SUM(
                    CASE WHEN passed = 1
                    THEN 1 ELSE 0 END
                ), 0
            ) AS passed

        FROM mock_scores

        GROUP BY DATE(taken_at)

        ORDER BY day DESC

        LIMIT 10
    ");

    $mock_by_day = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Overall mock-test statistics
    $stmt = $conn->query("
        SELECT
            COUNT(*) AS total,

            COALESCE(
                SUM(
                    CASE WHEN passed = 1
                    THEN 1 ELSE 0 END
                ), 0
            ) AS passed,

            COALESCE(
                ROUND(
                    AVG(
                        CASE
                            WHEN total > 0
                            THEN score * 100.0 / total
                            ELSE NULL
                        END
                    )
                ), 0
            ) AS avg_pct

        FROM mock_scores
    ");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $mock_overall = $result;
    }

} catch (PDOException $e) {

    error_log(
        'TestMate Statistics Error: ' . $e->getMessage()
    );

    $error = 'Some statistics could not be loaded. Check the PHP server terminal.';
}

// Convert values to safe numeric types
$total_mocks = (int)($mock_overall['total'] ?? 0);

$passed_mocks = (int)($mock_overall['passed'] ?? 0);

$failed_mocks = max(
    0,
    $total_mocks - $passed_mocks
);

$average_score = (int)($mock_overall['avg_pct'] ?? 0);

$average_score = max(
    0,
    min(100, $average_score)
);

$overall_pass_rate = statsPercent(
    $passed_mocks,
    $total_mocks
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Statistics – TestMate Admin</title>

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

/* Navigation */
.navbar {
    background: #2c3e50;
    min-height: 64px;
    padding: 12px 26px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.navbar a {
    color: white;
    text-decoration: none;
}

.brand {
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
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
    padding: 10px 17px;
    border-radius: 7px;
    background: #e74c3c;
}

/* Main layout */
.admin-wrap {
    display: flex;
    min-height: calc(100vh - 64px);
}

.sidebar {
    width: 247px;
    flex-shrink: 0;
    background: #1a252f;
    color: white;
    padding: 25px 0;
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
    text-decoration: none;
    color: #e4eaf0;
    font-size: 14px;
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
}

/* Overview statistics */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(165px, 1fr)
    );
    gap: 16px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 24px 18px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    border: 1px solid #edf0f4;
}

.stat-card .number {
    font-size: 2.1rem;
    font-weight: 800;
    line-height: 1.2;
}

.stat-card .label {
    font-size: 13px;
    color: #888;
    margin-top: 7px;
}

/* Detailed statistics */
.content-grid {
    display: grid;
    grid-template-columns: repeat(
        2,
        minmax(0, 1fr)
    );
    gap: 24px;
    align-items: start;
}

.section-title {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 15px;
}

.card {
    background: white;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.topic-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.topic-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.topic-name {
    font-size: 14px;
    font-weight: 600;
}

.topic-percent {
    font-size: 14px;
    font-weight: 800;
}

.progress-bar {
    width: 100%;
    height: 9px;
    border-radius: 20px;
    background: #e9eef4;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 20px;
    transition: width .3s ease;
}

.topic-meta {
    color: #999;
    margin-top: 7px;
    font-size: 12px;
}

/* Daily mock results */
.table-wrap {
    background: white;
    border-radius: 10px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

th {
    background: #eef2f7;
    color: #41566d;
    text-align: left;
    padding: 14px;
    white-space: nowrap;
    font-weight: 700;
}

td {
    padding: 13px 14px;
    border-bottom: 1px solid #edf0f4;
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background: #fbfcfe;
}

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
}

.badge-pass {
    background: #eafaf1;
    color: #229954;
}

.badge-fail {
    background: #fdecea;
    color: #c0392b;
}

.empty-state {
    text-align: center;
    padding: 35px 20px;
    color: #888;
    font-size: 14px;
}

.notice-error {
    background: #fdecea;
    color: #a93226;
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Responsive layout */
@media (max-width: 1000px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .admin-wrap {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        padding: 10px 0;
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
        padding: 12px 15px;
        flex-wrap: wrap;
    }
}
</style>
</head>

<body>

<!-- Top Navigation -->
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

    <!-- Sidebar -->
    <aside class="sidebar">

        <h3>Main</h3>
        <a href="/admin/index.php">Dashboard</a>
        <a href="/admin/review-scores.php">Review Scores</a>
        <a href="/admin/users.php">Users</a>
        <a href="/admin/add-user.php">Add User</a>
        <a href="/admin/stats.php" class="active">Statistics</a>

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

    <!-- Statistics Content -->
    <main class="main-content">

        <h1 style="font-size:23px;margin:0 0 6px;">
            Statistics
        </h1>

        <p style="font-size:14px;color:#888;margin:0 0 25px;">
            Overview of learners' quiz and mock-test performance.
        </p>

        <?php if ($error !== ''): ?>
            <div class="notice-error">
                <?= statsEsc($error) ?>
            </div>
        <?php endif; ?>

        <!-- Mock Test Overview -->
        <section class="stat-grid">

            <div class="stat-card">
                <div class="number" style="color:#2c3e50;">
                    <?= $total_mocks ?>
                </div>
                <div class="label">
                    Total Mock Tests
                </div>
            </div>

            <div class="stat-card">
                <div class="number" style="color:#27ae60;">
                    <?= $passed_mocks ?>
                </div>
                <div class="label">
                    Passed
                </div>
            </div>

            <div class="stat-card">
                <div class="number" style="color:#e74c3c;">
                    <?= $failed_mocks ?>
                </div>
                <div class="label">
                    Failed
                </div>
            </div>

            <div class="stat-card">
                <div class="number" style="color:#3498db;">
                    <?= $average_score ?>%
                </div>
                <div class="label">
                    Average Score
                </div>
            </div>

        </section>

        <!-- Detailed Statistics -->
        <div class="content-grid">

            <!-- Quiz Topic Performance -->
            <section>

                <h2 class="section-title">
                    Quiz Performance by Topic
                </h2>

                <div class="topic-list">

                    <?php if (empty($topic_stats)): ?>

                        <div class="card empty-state">
                            No quiz topic data available.
                        </div>

                    <?php else: ?>

                        <?php foreach ($topic_stats as $ts): ?>

                            <?php
                            $pct = max(
                                0,
                                min(
                                    100,
                                    (int)($ts['avg_pct'] ?? 0)
                                )
                            );

                            $attempts = (int)($ts['attempts'] ?? 0);
                            $passed = (int)($ts['passed'] ?? 0);

                            $color = $pct >= 80
                                ? '#27ae60'
                                : ($pct >= 60
                                    ? '#e67e22'
                                    : '#e74c3c');
                            ?>

                            <div class="card">

                                <div class="topic-header">

                                    <span class="topic-name">
                                        <?= statsEsc($ts['icon'] ?? '') ?>
                                        <?= statsEsc($ts['name']) ?>
                                    </span>

                                    <span
                                        class="topic-percent"
                                        style="color:<?= $color ?>;"
                                    >
                                        <?= $pct ?>%
                                    </span>

                                </div>

                                <div class="progress-bar">
                                    <div
                                        class="progress-fill"
                                        style="
                                            width:<?= $pct ?>%;
                                            background:<?= $color ?>;
                                        "
                                    ></div>
                                </div>

                                <div class="topic-meta">
                                    <?= $attempts ?>
                                    <?= $attempts === 1
                                        ? 'attempt'
                                        : 'attempts' ?>

                                    ·

                                    <?= $passed ?> passed
                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>

            <!-- Daily Mock Test Results -->
            <section>

                <h2 class="section-title">
                    Recent Mock Tests by Day
                </h2>

                <?php if (empty($mock_by_day)): ?>

                    <div class="card empty-state">
                        No mock tests taken yet.
                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Passed</th>
                                    <th>Pass Rate</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($mock_by_day as $day): ?>

                                    <?php
                                    $total = (int)($day['total'] ?? 0);
                                    $passed = (int)($day['passed'] ?? 0);

                                    $rate = statsPercent(
                                        $passed,
                                        $total
                                    );

                                    $timestamp = !empty($day['day'])
                                        ? strtotime((string)$day['day'])
                                        : false;
                                    ?>

                                    <tr>

                                        <td style="font-size:13px;">
                                            <?= $timestamp !== false
                                                ? date('d M Y', $timestamp)
                                                : 'Unknown Date' ?>
                                        </td>

                                        <td>
                                            <?= $total ?>
                                        </td>

                                        <td style="
                                            color:#27ae60;
                                            font-weight:600;
                                        ">
                                            <?= $passed ?>
                                        </td>

                                        <td>
                                            <span class="badge <?= $rate >= 80
                                                ? 'badge-pass'
                                                : 'badge-fail' ?>">
                                                <?= $rate ?>%
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

</body>
</html>
