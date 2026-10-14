<?php
/**
 * TestMate – Rankings
 * PHP 8.5 + PDO SQLite
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

function rankEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$top_mock = [];
$top_quiz = [];
try {
    // Best mock test result per learner (100.0 avoids SQLite integer division).
    $top_mock = $conn->query("
        WITH ranked AS (
            SELECT ms.user_id, ms.score, ms.total, ms.passed,
                   ms.score * 100.0 / NULLIF(ms.total, 0) AS pct,
                   ROW_NUMBER() OVER (PARTITION BY ms.user_id ORDER BY ms.score * 1.0 / NULLIF(ms.total, 0) DESC, ms.score DESC) AS rn,
                   COUNT(*) OVER (PARTITION BY ms.user_id) AS attempts,
                   SUM(CASE WHEN ms.passed = 1 THEN 1 ELSE 0 END) OVER (PARTITION BY ms.user_id) AS passes
            FROM mock_scores ms
            WHERE COALESCE(ms.status, 'approved') NOT IN ('pending', 'rejected')
        )
        SELECT r.user_id, u.name, r.score AS best_score, r.total, ROUND(r.pct) AS best_pct, r.attempts, r.passes
        FROM ranked r
        JOIN users u ON r.user_id = u.id
        WHERE r.rn = 1 AND LOWER(COALESCE(u.role, '')) <> 'admin'
        ORDER BY best_pct DESC, r.attempts ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Average topic quiz result per learner.
    $top_quiz = $conn->query("
        SELECT qs.user_id, u.name,
               COUNT(qs.id) AS total_quizzes,
               ROUND(AVG(qs.score * 100.0 / NULLIF(qs.total, 0))) AS avg_pct,
               SUM(qs.score) AS total_correct
        FROM quiz_scores qs
        JOIN users u ON qs.user_id = u.id
        WHERE LOWER(COALESCE(u.role, '')) <> 'admin'
          AND COALESCE(qs.status, 'approved') NOT IN ('pending', 'rejected')
        GROUP BY qs.user_id, u.name
        ORDER BY avg_pct DESC, total_quizzes DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[TestMate Rankings] ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
?>
<style>
.rk-page{padding:28px 32px 56px;background:#f4f8fd;min-height:100%;box-sizing:border-box;color:#173452}
.rk-page *{box-sizing:border-box}
.rk-kicker{display:block;font-size:12px;font-weight:800;letter-spacing:.12em;color:#2c7bd6;text-transform:uppercase;margin-bottom:6px}
.rk-page h1{margin:0 0 6px;font-size:32px;font-weight:800;color:#102744}
.rk-sub{margin:0 0 24px;color:#5b7189;font-size:15px}
.rk-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.rk-card{background:#fff;border:1px solid #e2eaf3;border-radius:18px;box-shadow:0 4px 16px rgba(16,39,68,.05);overflow:hidden}
.rk-card h2{margin:0;padding:18px 20px;font-size:18px;color:#102744;border-bottom:1px solid #eef2f7}
.rk-row{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid #f0f4f8}
.rk-row:last-child{border-bottom:0}
.rk-row.me{background:#eef6ff}
.rk-pos{flex:0 0 36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:#f1f6fc;color:#5b7189}
.rk-pos.p1{background:#fff3c4;color:#a87900}.rk-pos.p2{background:#eceff3;color:#6b7785}.rk-pos.p3{background:#f7e3d2;color:#9a5a24}
.rk-name{flex:1;min-width:0;font-weight:700;color:#102744}
.rk-name small{display:block;font-weight:400;color:#7a8ea4;font-size:12px;margin-top:2px}
.rk-score{text-align:right;font-weight:800;font-size:18px}
.rk-score small{display:block;font-size:12px;font-weight:400;color:#7a8ea4}
.rk-good{color:#0b8a5c}.rk-mid{color:#c27410}
.rk-empty{padding:30px;text-align:center;color:#7a8ea4}
.rk-actions{display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:28px}
.rk-btn{display:inline-flex;align-items:center;height:44px;padding:0 20px;border-radius:10px;font-weight:700;text-decoration:none}
.rk-btn.primary{background:#147df5;color:#fff!important}.rk-btn.ghost{background:#fff;color:#102744!important;border:1px solid #dbe6f2}
@media(max-width:900px){.rk-grid{grid-template-columns:1fr}.rk-page{padding:20px 16px 40px}.rk-page h1{font-size:26px}}
</style>

<main class="rk-page">
    <span class="rk-kicker">TestMate community</span>
    <h1>Rankings</h1>
    <p class="rk-sub">Top performers on TestMate. See how you compare.</p>

    <div class="rk-grid">
        <section class="rk-card">
            <h2>Mock Test: Top Scores</h2>
            <?php if (!$top_mock): ?>
                <div class="rk-empty">No mock test scores yet. Be the first!</div>
            <?php else: foreach ($top_mock as $i => $row): $pct = (int)$row['best_pct']; ?>
                <div class="rk-row<?= (int)$row['user_id'] === $user_id ? ' me' : '' ?>">
                    <span class="rk-pos<?= $i < 3 ? ' p' . ($i + 1) : '' ?>"><?= $i + 1 ?></span>
                    <div class="rk-name"><?= rankEsc($row['name']) ?><?= (int)$row['user_id'] === $user_id ? ' (you)' : '' ?>
                        <small><?= (int)$row['attempts'] ?> attempt<?= (int)$row['attempts'] === 1 ? '' : 's' ?> &middot; <?= (int)$row['passes'] ?> passed</small></div>
                    <div class="rk-score <?= $pct >= 80 ? 'rk-good' : 'rk-mid' ?>"><?= $pct ?>%<small><?= (int)$row['best_score'] ?>/<?= (int)$row['total'] ?></small></div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <section class="rk-card">
            <h2>Topic Quizzes: Top Performers</h2>
            <?php if (!$top_quiz): ?>
                <div class="rk-empty">No quiz scores yet.</div>
            <?php else: foreach ($top_quiz as $i => $row): $pct = (int)$row['avg_pct']; ?>
                <div class="rk-row<?= (int)$row['user_id'] === $user_id ? ' me' : '' ?>">
                    <span class="rk-pos<?= $i < 3 ? ' p' . ($i + 1) : '' ?>"><?= $i + 1 ?></span>
                    <div class="rk-name"><?= rankEsc($row['name']) ?><?= (int)$row['user_id'] === $user_id ? ' (you)' : '' ?>
                        <small><?= (int)$row['total_quizzes'] ?> quiz<?= (int)$row['total_quizzes'] === 1 ? '' : 'zes' ?> &middot; <?= (int)$row['total_correct'] ?> correct answers</small></div>
                    <div class="rk-score <?= $pct >= 80 ? 'rk-good' : 'rk-mid' ?>"><?= $pct ?>%<small>average</small></div>
                </div>
            <?php endforeach; endif; ?>
        </section>
    </div>

    <div class="rk-actions">
        <a href="/mock-test.php" class="rk-btn primary">Take the Mock Test to Rank Up</a>
        <a href="/quiz.php" class="rk-btn ghost">Take a Quiz</a>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>