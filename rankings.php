<?php
require 'includes/auth.php';
require 'includes/db_connect.php';

$user_id = $_SESSION['user_id'];

$top_mock = $conn->query("
    SELECT u.name,
           MAX(ms.score) AS best_score,
           ms.total,
           ROUND(MAX(ms.score / ms.total * 100)) AS best_pct,
           COUNT(ms.id) AS attempts,
           SUM(ms.passed) AS passes
    FROM mock_scores ms
    JOIN users u ON ms.user_id = u.id
    WHERE u.role = 'user'
    GROUP BY ms.user_id
    ORDER BY best_pct DESC, attempts ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$top_quiz = $conn->query("
    SELECT u.name,
           COUNT(qs.id) AS total_quizzes,
           ROUND(AVG(qs.score / qs.total * 100)) AS avg_pct,
           SUM(qs.score) AS total_correct
    FROM quiz_scores qs
    JOIN users u ON qs.user_id = u.id
    WHERE u.role = 'user'
    GROUP BY qs.user_id
    ORDER BY avg_pct DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
    <h1>Rankings</h1>
    <p>Top performers on TestMate — see how you compare</p>
</div>

<div class="container">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;flex-wrap:wrap;">

        <div>
            <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;">Practice Test — Top Scores</h2>
            <?php if (empty($top_mock)): ?>
            <div class="card" style="text-align:center;padding:30px;color:#888;">No scores yet. Be the first!</div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Rank</th><th>Name</th><th>Best Score</th><th>Attempts</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top_mock as $i => $row): ?>
                    <tr style="<?= $i === 0 ? 'background:#fffbea;' : '' ?>">
                        <td>
                            <?php if ($i === 0): ?>
                                <span style="color:#f1c40f;font-weight:800;font-size:18px;">1</span>
                            <?php elseif ($i === 1): ?>
                                <span style="color:#aaa;font-weight:700;">2</span>
                            <?php elseif ($i === 2): ?>
                                <span style="color:#cd7f32;font-weight:700;">3</span>
                            <?php else: ?>
                                <span style="color:#888;"><?= $i + 1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;"><?= htmlspecialchars($row['name']) ?></td>
                        <td>
                            <span style="font-weight:700;color:<?= $row['best_pct'] >= 80 ? '#27ae60' : '#e67e22' ?>">
                                <?= $row['best_pct'] ?>%
                            </span>
                            <span style="font-size:12px;color:#888;">(<?= $row['best_score'] ?>/<?= $row['total'] ?>)</span>
                        </td>
                        <td style="color:#888;font-size:14px;"><?= $row['attempts'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;">Topic Quizzes — Top Performers</h2>
            <?php if (empty($top_quiz)): ?>
            <div class="card" style="text-align:center;padding:30px;color:#888;">No quiz scores yet.</div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Rank</th><th>Name</th><th>Avg Score</th><th>Quizzes</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top_quiz as $i => $row): ?>
                    <tr style="<?= $i === 0 ? 'background:#fffbea;' : '' ?>">
                        <td>
                            <?php if ($i === 0): ?>
                                <span style="color:#f1c40f;font-weight:800;font-size:18px;">1</span>
                            <?php elseif ($i === 1): ?>
                                <span style="color:#aaa;font-weight:700;">2</span>
                            <?php elseif ($i === 2): ?>
                                <span style="color:#cd7f32;font-weight:700;">3</span>
                            <?php else: ?>
                                <span style="color:#888;"><?= $i + 1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;"><?= htmlspecialchars($row['name']) ?></td>
                        <td>
                            <span style="font-weight:700;color:<?= $row['avg_pct'] >= 80 ? '#27ae60' : '#e67e22' ?>">
                                <?= $row['avg_pct'] ?>%
                            </span>
                        </td>
                        <td style="color:#888;font-size:14px;"><?= $row['total_quizzes'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <div style="text-align:center;margin-top:30px;">
        <a href="/testmate/mock-test.php" class="btn btn-primary">Take Practice Test to Rank Up</a>
        <a href="/testmate/quiz.php" class="btn btn-outline" style="margin-left:10px;">Take a Quiz</a>
    </div>

</div>

<?php include 'includes/footer.php'; ?>