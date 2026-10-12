
<?php
/**
 * TestMate - Learner Progress
 * PHP 8.5 + PDO SQLite
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id < 1) {
    header('Location: /login.php');
    exit;
}

function progressEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function progressDate($value, string $format = 'd M Y'): string
{
    $timestamp = strtotime((string)($value ?? ''));
    return $timestamp === false
        ? 'Date unavailable'
        : date($format, $timestamp);
}

$topic_scores = [];
$mock_history = [];
$total_quizzes = 0;
$mock_stats = ['cnt' => 0, 'passed_cnt' => 0];

try {
    // Quiz averages grouped by topic.
    $stmt = $conn->prepare("
        SELECT
            t.id AS topic_id,
            t.name,
            t.icon,
            ROUND(
                AVG(
                    CASE
                        WHEN qs.total > 0
                        THEN qs.score * 100.0 / qs.total
                        ELSE NULL
                    END
                )
            ) AS avg_pct,
            COUNT(qs.id) AS attempts,
            MAX(qs.score) AS best_score,
            MAX(qs.total) AS total_q
        FROM quiz_scores AS qs
        INNER JOIN topics AS t
            ON qs.topic_id = t.id
        WHERE qs.user_id = ?
        GROUP BY t.id, t.name, t.icon
        ORDER BY avg_pct ASC
    ");

    $stmt->execute([$user_id]);
    $topic_scores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest ten Mock Test attempts.
    $stmt = $conn->prepare("
        SELECT
            score,
            total,
            passed,
            time_taken,
            taken_at
        FROM mock_scores
        WHERE user_id = ?
        ORDER BY taken_at DESC, id DESC
        LIMIT 10
    ");

    $stmt->execute([$user_id]);
    $mock_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total number of quizzes completed.
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM quiz_scores
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);
    $total_quizzes = (int)$stmt->fetchColumn();

    // Mock Test totals and passes.
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(passed), 0) AS passed_cnt
        FROM mock_scores
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);

    $mock_stats = $stmt->fetch(PDO::FETCH_ASSOC)
        ?: ['cnt' => 0, 'passed_cnt' => 0];

} catch (PDOException $e) {
    error_log('TestMate progress: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load progress data. Check the PHP server terminal.');
}

// Average performance across topics attempted.
$overall = 0;

if ($topic_scores) {
    $sum = 0.0;
    $count = 0;

    foreach ($topic_scores as $ts) {
        if ($ts['avg_pct'] !== null) {
            $sum += (float)$ts['avg_pct'];
            $count++;
        }
    }

    if ($count > 0) {
        $overall = (int)round($sum / $count);
    }
}

$overall = max(0, min(100, $overall));

// Topics with an average below 60%.
$weak = array_values(
    array_filter(
        $topic_scores,
        static fn($ts) =>
            $ts['avg_pct'] !== null &&
            (float)$ts['avg_pct'] < 60
    )
);

// Estimated completed topics: 80% average or higher.
$completed_topics = count(
    array_filter(
        $topic_scores,
        static fn($ts) =>
            $ts['avg_pct'] !== null &&
            (float)$ts['avg_pct'] >= 80
    )
);

include __DIR__ . '/includes/header.php';
?>

<style>
.tm-progress {
    max-width: 1300px;
    margin: 0 auto;
    padding: 28px 30px 55px;
}

.tm-progress-head {
    margin-bottom: 22px;
}

.tm-progress-head h1 {
    margin: 0 0 5px;
    font-size: 27px;
    color: #102744;
}

.tm-progress-head p {
    margin: 0;
    font-size: 14px;
    color: #71849c;
}

.tm-progress-stats {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.tm-progress-stat {
    min-height: 132px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 7px;
    border: 1px solid #e2eaf4;
    border-radius: 12px;
    background: white;
    color: #102744;
    text-decoration: none;
    box-shadow: 0 3px 12px rgba(16,39,68,.04);
}

.tm-progress-stat .tm-stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #e5f2ff;
    color: #187fe0;
    font-size: 17px;
}

.tm-progress-stat strong {
    font-size: 23px;
}

.tm-progress-stat small {
    font-size: 12px;
    color: #71849c;
    text-align: center;
}

.tm-progress-donut {
    width: 90px;
    height: 90px;
    margin: auto;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: conic-gradient(
        #14b88a var(--pct),
        #e7edf5 0
    );
}

.tm-progress-donut-inner {
    width: 73px;
    height: 73px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: white;
    color: #102744;
    font-size: 21px;
    font-weight: 800;
}

.tm-progress-lower {
    display: grid;
    grid-template-columns: minmax(0,1.12fr) minmax(0,.88fr);
    gap: 16px;
}

.tm-progress-panel {
    min-width: 0;
    padding: 20px;
    border: 1px solid #e2eaf4;
    border-radius: 12px;
    background: white;
    box-shadow: 0 3px 12px rgba(16,39,68,.04);
}

.tm-progress-panel h2 {
    margin: 0 0 20px;
    font-size: 16px;
    color: #102744;
}

.tm-topic-row {
    display: grid;
    grid-template-columns: 125px minmax(0,1fr) 44px;
    align-items: center;
    gap: 12px;
    margin-bottom: 17px;
    color: #102744;
    font-size: 13px;
}

.tm-topic-track {
    height: 8px;
    border-radius: 9px;
    overflow: hidden;
    background: #edf2f7;
}

.tm-topic-fill {
    height: 100%;
    border-radius: 9px;
}

.tm-topic-row strong {
    text-align: right;
    font-size: 12px;
}

.tm-activity-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 0;
    border-bottom: 1px solid #eef2f7;
    color: #324b64;
    font-size: 12px;
}

.tm-activity-row:last-child {
    border-bottom: 0;
}

.tm-activity-row i {
    color: #15a878;
}

.tm-activity-row time,
.tm-activity-row .tm-activity-count {
    margin-left: auto;
    white-space: nowrap;
    color: #93a2b6;
    font-size: 11px;
}

.tm-progress-extra {
    margin-top: 22px;
}

.tm-progress-extra h2 {
    font-size: 18px;
    color: #102744;
}

.tm-progress-extra .table-wrap {
    overflow-x: auto;
    background: white;
    border-radius: 12px;
    border: 1px solid #e2eaf4;
}

.tm-progress-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13px;
}

.tm-progress-table th,
.tm-progress-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #edf1f5;
    white-space: nowrap;
}

.tm-progress-table th {
    background: #f3f7fc;
    color: #274963;
    font-size: 12px;
}

.tm-progress-table td {
    color: #35536d;
}

.tm-progress-table tbody tr:last-child td {
    border-bottom: none;
}

.tm-pass-label,
.tm-fail-label {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
}

.tm-pass-label {
    background: #e4f8ec;
    color: #16834e;
}

.tm-fail-label {
    background: #fdeaea;
    color: #b93434;
}

.tm-weak-box {
    margin-bottom: 28px;
    padding: 20px;
    border: 1px solid #f5cba7;
    border-radius: 12px;
    background: #fff8f0;
    scroll-margin-top: 80px;
}

.tm-weak-box h3 {
    margin: 0 0 12px;
    color: #e67e22;
    font-size: 16px;
}

.tm-weak-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.tm-weak-tag {
    padding: 6px 14px;
    border-radius: 20px;
    background: #fdebd0;
    color: #ca6f1e;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

.tm-empty-card {
    padding: 40px;
    margin-bottom: 28px;
    border: 1px solid #e2eaf4;
    border-radius: 12px;
    background: white;
    text-align: center;
}

@media (max-width: 900px) {
    .tm-progress-stats {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }

    .tm-progress-lower {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    .tm-progress {
        padding: 22px 15px;
    }

    .tm-topic-row {
        grid-template-columns: 94px minmax(0,1fr) 38px;
        gap: 7px;
        font-size: 11px;
    }

    .tm-progress-stat {
        min-height: 120px;
    }
}
</style>

<main class="tm-progress">

    <header class="tm-progress-head">
        <h1>Your Progress</h1>
        <p>
            Track your learning journey and see how you are improving.
        </p>
    </header>

    <!-- SUMMARY CARDS -->
    <section
        class="tm-progress-stats"
        aria-label="Progress summary"
    >

        <div class="tm-progress-stat">

            <div
                class="tm-progress-donut"
                style="--pct:<?= $overall ?>%"
            >
                <div class="tm-progress-donut-inner">
                    <?= $overall ?>%
                </div>
            </div>

            <small>Average Topic Score</small>

        </div>

        <a
            class="tm-progress-stat"
            href="/quiz-history.php"
        >
            <span class="tm-stat-icon">
                <i class="fa-solid fa-pen-to-square"></i>
            </span>

            <strong><?= $total_quizzes ?></strong>
            <small>Quizzes Attempted</small>
        </a>

        <a
            class="tm-progress-stat"
            href="#mockHistory"
        >
            <span
                class="tm-stat-icon"
                style="background:#dff8ef;color:#13a878;"
            >
                <i class="fa-solid fa-check-double"></i>
            </span>

            <strong>
                <?= (int)$mock_stats['passed_cnt'] ?>
            </strong>

            <small>Mock Tests Passed</small>
        </a>

        <a
            class="tm-progress-stat"
            href="/study-materials.php"
        >
            <span class="tm-stat-icon">
                <i class="fa-solid fa-book-open"></i>
            </span>

            <strong><?= $completed_topics ?></strong>
            <small>Study Topics Completed*</small>
        </a>

    </section>

    <!-- TOPIC PERFORMANCE AND RECENT ACTIVITY -->
    <section class="tm-progress-lower">

        <div class="tm-progress-panel">

            <h2>Topic Performance</h2>

            <?php if (!$topic_scores): ?>

                <p>
                    No quiz results yet.
                    <a href="/quiz.php">Start a quiz</a>
                    to see your progress.
                </p>

            <?php endif; ?>

            <?php foreach ($topic_scores as $ts): ?>

                <?php
                $pct = max(
                    0,
                    min(100, (int)round((float)$ts['avg_pct']))
                );

                $bar = $pct >= 80
                    ? '#17b66b'
                    : (
                        $pct >= 60
                        ? '#1da4c1'
                        : (
                            $pct >= 40
                            ? '#f59e0b'
                            : '#ef4444'
                        )
                    );
                ?>

                <div class="tm-topic-row">

                    <span>
                        <?= progressEsc($ts['name']) ?>
                    </span>

                    <div class="tm-topic-track">
                        <div
                            class="tm-topic-fill"
                            style="width:<?= $pct ?>%;background:<?= $bar ?>;"
                        ></div>
                    </div>

                    <strong><?= $pct ?>%</strong>

                </div>

            <?php endforeach; ?>

        </div>

        <div class="tm-progress-panel">

            <h2>Recent Activity</h2>

            <?php if (!$mock_history && !$topic_scores): ?>

                <p>No recent activity yet.</p>

            <?php endif; ?>

            <?php foreach (array_slice($mock_history, 0, 4) as $m): ?>

                <div class="tm-activity-row">

                    <i class="fa-solid fa-<?= (int)$m['passed'] ? 'circle-check' : 'clipboard-check' ?>"></i>

                    <span>
                        Completed mock test ·
                        <?= (int)$m['score'] ?>/<?= (int)$m['total'] ?>
                    </span>

                    <time>
                        <?= progressEsc(
                            progressDate($m['taken_at'])
                        ) ?>
                    </time>

                </div>

            <?php endforeach; ?>

            <?php foreach (array_slice($topic_scores, 0, 4) as $ts): ?>

                <div class="tm-activity-row">

                    <i class="fa-solid fa-book-open"></i>

                    <span>
                        Practised
                        <?= progressEsc($ts['name']) ?>
                    </span>

                    <span class="tm-activity-count">
                        <?= (int)$ts['attempts'] ?> attempt(s)
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

    <p style="
        font-size:11px;
        color:#8797aa;
        margin:12px 0;
    ">
        *Study topics completed is estimated from
        topic quiz averages of 80% or higher.
    </p>

    <section class="tm-progress-extra">

        <!-- WEAK LEARNING TOPICS -->
        <?php if (!empty($weak)): ?>

            <div id="weakTopics" class="tm-weak-box">

                <h3>⚠️ Focus on These Topics</h3>

                <div class="tm-weak-tags">

                    <?php foreach ($weak as $wt): ?>

                        <a
                            href="/study-materials.php?topic=<?= (int)$wt['topic_id'] ?>"
                            class="tm-weak-tag"
                        >
                            <?= progressEsc($wt['icon'] ?? '') ?>

                            <?= progressEsc($wt['name']) ?>

                            —
                            <?= (int)round((float)$wt['avg_pct']) ?>%
                        </a>

                    <?php endforeach; ?>

                </div>

                <p style="
                    margin:0;
                    font-size:13px;
                    color:#888;
                ">
                    These topics are below 60%.
                    Study the materials and retake those quizzes.
                </p>

            </div>

        <?php endif; ?>

        <!-- MOCK TEST HISTORY -->
        <h2
            id="mockHistory"
            style="margin-bottom:16px;scroll-margin-top:80px;"
        >
            ⏱️ Mock Test History
        </h2>

        <?php if (empty($mock_history)): ?>

            <div class="tm-empty-card">

                <div style="
                    font-size:40px;
                    margin-bottom:12px;
                ">
                    ⏱️
                </div>

                <p style="
                    color:#888;
                    margin-bottom:16px;
                ">
                    No mock tests taken yet.
                </p>

                <a
                    href="/mock_test.php"
                    class="btn btn-primary"
                >
                    Take a Mock Test
                </a>

            </div>

        <?php else: ?>

            <div class="table-wrap" style="margin-bottom:30px;">

                <table class="tm-progress-table">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Time Used</th>
                            <th>Result</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($mock_history as $m): ?>

                            <?php
                            $score = (int)$m['score'];
                            $total = (int)$m['total'];

                            $pct = $total > 0
                                ? (int)round(
                                    $score * 100.0 / $total
                                )
                                : 0;

                            $seconds = max(
                                0,
                                (int)$m['time_taken']
                            );

                            $mins = intdiv($seconds, 60);
                            $secs = $seconds % 60;
                            ?>

                            <tr>

                                <td>
                                    <?= progressEsc(
                                        progressDate(
                                            $m['taken_at'],
                                            'd M Y, H:i'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= $score ?>/<?= $total ?>
                                </td>

                                <td>
                                    <?= $pct ?>%
                                </td>

                                <td>
                                    <?= $mins ?>m <?= $secs ?>s
                                </td>

                                <td>
                                    <?php if ((int)$m['passed'] === 1): ?>

                                        <span class="tm-pass-label">
                                            ✅ PASSED
                                        </span>

                                    <?php else: ?>

                                        <span class="tm-fail-label">
                                            ❌ FAILED
                                        </span>

                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php
include __DIR__ . '/includes/footer.php';
?>
