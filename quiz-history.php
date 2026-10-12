<?php
/**
 * TestMate - Learner History (topic quizzes + mock tests)
 * PHP 8.5 + PDO SQLite
 *
 * /quiz-history.php            list of every quiz and mock test taken
 * /quiz-history.php?mock=ID    answer review for one mock test
 * Quiz reviews open the existing /quiz.php?result_id=ID page.
 * Read-only: this page never writes to the database.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id < 1) {
    header('Location: /login.php');
    exit;
}

function historyEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function historyDate(?string $value): string
{
    $ts = ($value ?? '') !== '' ? strtotime((string)$value) : false;
    return $ts !== false ? date('j M Y \a\t g:i A', $ts) : 'Date unavailable';
}

function historyMedia($value): string
{
    $path = trim(str_replace('\\', '/', (string)($value ?? '')));
    if ($path === '') return '';
    if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) return $path;
    if (str_contains($path, '://') || str_starts_with($path, 'data:')) return '';
    while (str_starts_with($path, './')) $path = substr($path, 2);
    $path = ltrim($path, '/');
    if (str_starts_with(strtolower($path), 'testmate/')) $path = substr($path, 9);
    if (in_array('..', explode('/', $path), true)) return '';
    return '/' . $path;
}

/** Study links for a wrong answer: topic notes, its videos, best-matching simulation. */
function historyHelpLinks(PDO $conn, int $topicId, string $topicName, string $questionText): array
{
    static $cache = [];
    if ($topicId <= 0) return [];
    if (!isset($cache[$topicId])) {
        $cache[$topicId] = ['videos' => 0, 'sims' => []];
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM materials WHERE topic_id = ? AND LOWER(TRIM(file_type)) = 'video' AND TRIM(COALESCE(file_path,'')) <> ''");
            $st->execute([$topicId]);
            $cache[$topicId]['videos'] = (int)$st->fetchColumn();
            $st = $conn->prepare('SELECT id, title, description FROM simulations WHERE topic_id = ? AND is_active = 1 ORDER BY id ASC');
            $st->execute([$topicId]);
            $cache[$topicId]['sims'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[TestMate History help links] ' . $e->getMessage());
        }
    }
    $res = $cache[$topicId];
    $links = [['read', 'Read the ' . $topicName . ' notes', '/study-materials.php?topic=' . $topicId . '&mode=read#topic-content']];
    if ($res['videos'] > 0) {
        $links[] = ['video', 'Watch the video', '/video-demonstrations.php?topic=' . $topicId];
    }
    if ($res['sims']) {
        $words = static fn(string $t): array => array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [],
            static fn(string $w): bool => strlen($w) > 3
        ));
        $q = $words($questionText);
        $best = $res['sims'][0];
        $bestScore = -1;
        foreach ($res['sims'] as $sim) {
            $score = count(array_intersect($q, $words(($sim['title'] ?? '') . ' ' . ($sim['description'] ?? ''))));
            if ($score > $bestScore) { $best = $sim; $bestScore = $score; }
        }
        $links[] = ['sim', 'Try the simulation', '/simulations.php?id=' . (int)$best['id'] . '&mode=study'];
    }
    return $links;
}

$mockId = max(0, (int)($_GET['mock'] ?? 0));
$mock = null;
$mockReview = [];
$topicNames = [];
$quizAttempts = [];
$mockAttempts = [];

try {
    foreach ($conn->query('SELECT id, name FROM topics')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $topicNames[(int)$t['id']] = (string)$t['name'];
    }

    if ($mockId > 0) {
        $stmt = $conn->prepare('SELECT * FROM mock_scores WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$mockId, $user_id]);
        $mock = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($mock && !empty($mock['results_json'])) {
            $decoded = json_decode((string)$mock['results_json'], true);
            $mockReview = is_array($decoded) ? $decoded : [];
        }
    } else {
        $stmt = $conn->prepare("
            SELECT qs.id, qs.topic_id, qs.score, qs.total, qs.submitted_at, qs.status, t.name AS topic_name
            FROM quiz_scores AS qs
            LEFT JOIN topics AS t ON qs.topic_id = t.id
            WHERE qs.user_id = ?
            ORDER BY qs.submitted_at DESC, qs.id DESC
        ");
        $stmt->execute([$user_id]);
        $quizAttempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT id, score, total, passed, time_taken, submitted_at, status
            FROM mock_scores
            WHERE user_id = ?
            ORDER BY submitted_at DESC, id DESC
        ");
        $stmt->execute([$user_id]);
        $mockAttempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('[TestMate History] ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your history. Please try again.');
}

/* ===== Combined list + statistics ===== */
$items = [];
$quizPctSum = 0;
foreach ($quizAttempts as $a) {
    $score = (int)$a['score'];
    $total = (int)$a['total'];
    $pct = $total > 0 ? (int)round($score / $total * 100) : 0;
    $quizPctSum += $pct;
    $items[] = [
        'kind'   => 'quiz',
        'title'  => $a['topic_name'] ?? 'Unknown topic',
        'score'  => $score,
        'total'  => $total,
        'pct'    => $pct,
        'date'   => (string)($a['submitted_at'] ?? ''),
        'status' => strtolower((string)($a['status'] ?? 'approved')),
        'passed' => null,
        'href'   => '/quiz.php?result_id=' . (int)$a['id'],
        'sort'   => (string)($a['submitted_at'] ?? '') . sprintf('%010d', (int)$a['id']),
    ];
}
$mockPassed = 0;
foreach ($mockAttempts as $a) {
    $score = (int)$a['score'];
    $total = (int)$a['total'];
    $pct = $total > 0 ? (int)round($score / $total * 100) : 0;
    $passed = (int)($a['passed'] ?? 0) === 1;
    $mockPassed += $passed ? 1 : 0;
    $items[] = [
        'kind'   => 'mock',
        'title'  => 'Mock Test',
        'score'  => $score,
        'total'  => $total,
        'pct'    => $pct,
        'date'   => (string)($a['submitted_at'] ?? ''),
        'status' => strtolower((string)($a['status'] ?? 'approved')),
        'passed' => $passed,
        'href'   => '/quiz-history.php?mock=' . (int)$a['id'],
        'sort'   => (string)($a['submitted_at'] ?? '') . sprintf('%010d', (int)$a['id']),
    ];
}
usort($items, static fn(array $x, array $y): int => strcmp($y['sort'], $x['sort']));

$quizCount = count($quizAttempts);
$mockCount = count($mockAttempts);
$avgQuiz = $quizCount ? (int)round($quizPctSum / $quizCount) : 0;

include __DIR__ . '/includes/header.php';
?>
<style>
.th-page{padding:28px 32px 48px;background:#f4f8fd;min-height:100%;box-sizing:border-box;font-family:inherit}
.th-page *{box-sizing:border-box}
.th-kicker{display:block;font-size:12px;font-weight:800;letter-spacing:.12em;color:#2c7bd6;text-transform:uppercase;margin-bottom:6px}
.th-page h1{margin:0 0 6px;font-size:34px;font-weight:800;color:#102744;line-height:1.15}
.th-sub{margin:0 0 24px;color:#5b7189;font-size:15px}
.th-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;color:#147df5;font-weight:700;font-size:14px;text-decoration:none}
.th-back:hover{text-decoration:underline}
.th-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px}
.th-stat{display:flex;align-items:center;gap:14px;padding:18px 20px;background:#fff;border:1px solid #e2eaf3;border-radius:16px;box-shadow:0 4px 16px rgba(16,39,68,.04)}
.th-stat-icon{flex:0 0 46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center}
.th-stat-icon.blue{background:#e6f1ff;color:#147df5}.th-stat-icon.green{background:#e3f6ee;color:#0b8a5f}.th-stat-icon.navy{background:#e7ecf4;color:#102744}.th-stat-icon.amber{background:#fff4dc;color:#b7790b}
.th-stat strong{display:block;font-size:24px;font-weight:800;color:#102744;line-height:1.1}
.th-stat span{font-size:13px;color:#5b7189}
.th-tabs{display:inline-flex;flex-wrap:wrap;gap:6px;padding:5px;margin-bottom:18px;background:#fff;border:1px solid #dbe6f2;border-radius:12px}
.th-tab{height:40px;padding:0 18px;border:0;border-radius:9px;background:transparent;color:#42607d;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.th-tab:hover{background:#f1f6fc}
.th-tab[aria-pressed="true"]{background:#102744;color:#fff}
.th-list{display:flex;flex-direction:column;gap:12px}
.th-row{display:grid;grid-template-columns:56px minmax(0,1fr) auto 120px;align-items:center;gap:18px;padding:16px 20px;background:#fff;border:1px solid #e2eaf3;border-radius:16px;box-shadow:0 4px 16px rgba(16,39,68,.04)}
.th-row[hidden]{display:none}
.th-row-icon{width:56px;height:56px;border-radius:15px;display:flex;align-items:center;justify-content:center}
.th-row-icon.quiz{background:#e6f1ff;color:#147df5}.th-row-icon.mock{background:#e7ecf4;color:#102744}
.th-type{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#0d7a57}
.th-type.mock{color:#42607d}
.th-row h2{margin:2px 0 3px;font-size:17px;font-weight:800;color:#102744}
.th-date{font-size:13px;color:#7a8ea4}
.th-pill{display:inline-block;margin-left:8px;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;vertical-align:middle}
.th-pill.pass{background:#e3f6ee;color:#0b7a53}.th-pill.fail{background:#fdeceb;color:#b42f2a}.th-pill.pending{background:#fff4dc;color:#93610a}.th-pill.rejected{background:#fdeceb;color:#b42f2a}
.th-score{text-align:right;min-width:90px}
.th-score strong{display:block;font-size:22px;font-weight:800}
.th-score span{font-size:12px;color:#7a8ea4}
.th-good{color:#0b8a5f}.th-mid{color:#c27a0e}.th-low{color:#c8393f}
.th-btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:10px;background:#147df5;color:#fff!important;font-size:14px;font-weight:700;text-decoration:none;border:0}
.th-btn:hover{background:#0b68d6}
.th-btn.ghost{background:#fff;color:#102744!important;border:1px solid #dbe6f2}
.th-btn.ghost:hover{background:#f1f6fc}
.th-empty{padding:44px 20px;text-align:center;background:#fff;border:1px solid #e2eaf3;border-radius:16px;color:#5b7189}
.th-empty h2{margin:0 0 8px;color:#102744;font-size:19px}
.th-empty p{margin:0 0 18px}
.th-empty .th-btn+.th-btn{margin-left:8px}
/* mock review */
.th-banner{display:flex;flex-wrap:wrap;align-items:center;gap:24px;padding:24px 28px;margin-bottom:18px;border-radius:18px;color:#fff}
.th-banner.pass{background:linear-gradient(115deg,#0a7a57,#11a578)}.th-banner.fail{background:linear-gradient(115deg,#a3262f,#d0484f)}
.th-banner-score{font-size:44px;font-weight:900;line-height:1}
.th-banner h2{margin:0 0 4px;font-size:20px;color:#fff}
.th-banner p{margin:0;color:rgba(255,255,255,.88);font-size:14px}
.th-banner-meta{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
.th-banner-meta span{padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.16);font-size:13px;font-weight:700}
.th-actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:24px}
.th-review{padding:22px 24px;margin-bottom:16px;background:#fff;border:1px solid #e2eaf3;border-left:4px solid #c8393f;border-radius:16px}
.th-review.correct{border-left-color:#0b8a5f}
.th-review-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
.th-review-num{font-size:12px;font-weight:800;letter-spacing:.06em;color:#7a8ea4;text-transform:uppercase}
.th-review-num em{font-style:normal;color:#0d7a57;margin-left:8px}
.th-tag{padding:4px 11px;border-radius:999px;font-size:12px;font-weight:800}
.th-tag.ok{background:#e3f6ee;color:#0b7a53}.th-tag.bad{background:#fdeceb;color:#b42f2a}
.th-q{margin:0 0 14px;font-size:17px;font-weight:700;color:#102744;line-height:1.5}
.th-media{display:block;max-width:420px;width:100%;border-radius:12px;margin-bottom:14px;background:#0d1b2a}
.th-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.th-option{padding:12px 14px;border:1px solid #e2eaf3;border-radius:10px;background:#f7fafd;color:#28415c;font-size:14px}
.th-option.right{background:#e7f8ef;border-color:#b7e6cd}.th-option.wrong{background:#fdecec;border-color:#f3c3c3}
.th-option b{color:#102744;margin-right:4px}
.th-option small{font-weight:800;margin-left:6px}
.th-option.right small{color:#0b7a53}.th-option.wrong small{color:#b42f2a}
.th-explain{margin-top:14px;padding:12px 16px;border-left:3px solid #147df5;border-radius:8px;background:#f1f7ff;color:#35506b;font-size:14px;line-height:1.6}
.tq-help{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-top:16px;padding-top:14px;border-top:1px dashed #dbe6f2}
.tq-help-label{font-size:13px;font-weight:700;color:#5b7189;margin-right:4px}
.tq-help-link{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 14px;border-radius:999px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid transparent}
.tq-help-link.read{background:#eaf3ff;color:#0f63c4!important;border-color:#cfe3fb}
.tq-help-link.video{background:#fff3e6;color:#b45d09!important;border-color:#fbdcb8}
.tq-help-link.sim{background:#e6f6f1;color:#0b6b4f!important;border-color:#bfe6d8}
.tq-help-link:hover{filter:brightness(.96);text-decoration:underline}
@media(max-width:1100px){.th-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){
  .th-page{padding:20px 16px 36px}.th-page h1{font-size:28px}
  .th-stats{gap:10px}.th-stat{padding:14px;gap:10px}.th-stat-icon{flex-basis:38px;height:38px}.th-stat strong{font-size:20px}
  .th-tabs{display:grid;grid-template-columns:repeat(3,1fr);width:100%}.th-tab{padding:0 6px}
  .th-row{grid-template-columns:46px minmax(0,1fr) auto;gap:12px;padding:14px}
  .th-row-icon{width:46px;height:46px}
  .th-row .th-btn{grid-column:1/-1}
  .th-options{grid-template-columns:1fr}
  .th-banner{padding:20px}.th-banner-meta{margin-left:0}
  .tq-help-link{flex:1 1 100%;justify-content:center}
}
</style>

<?php
$icoQuiz = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3h6v3H9zM9 11l1.5 1.5L13 10M9 16h6"/></svg>';
$icoMock = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg>';
$icoAvg  = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>';
$icoPass = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="9" r="6"/><path d="M8.5 14 7 22l5-3 5 3-1.5-8"/></svg>';
$icoRead = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2zM22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>';
$icoPlay = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15l13-7.5z"/></svg>';
$icoCar  = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M3 16h18v3H3zM7 19v2M17 19v2"/></svg>';
?>

<main class="th-page">

<?php if ($mockId > 0): ?>

    <a class="th-back" href="/quiz-history.php">&larr; Back to History</a>

    <?php if (!$mock): ?>
        <div class="th-empty">
            <h2>Mock test not found</h2>
            <p>This result is not available for your account.</p>
            <a class="th-btn" href="/quiz-history.php">Back to History</a>
        </div>
    <?php else:
        $mScore = (int)$mock['score'];
        $mTotal = (int)$mock['total'];
        $mPct = $mTotal > 0 ? (int)round($mScore / $mTotal * 100) : 0;
        $mPassed = (int)($mock['passed'] ?? 0) === 1;
        $mSecs = (int)($mock['time_taken'] ?? 0);
        $mWrong = 0;
        foreach ($mockReview as $r) { if (empty($r['is_correct'])) $mWrong++; }
    ?>
        <span class="th-kicker">Mock test review</span>
        <h1>Mock Test Result</h1>
        <p class="th-sub">Taken <?= historyEsc(historyDate($mock['submitted_at'] ?? '')) ?></p>

        <section class="th-banner <?= $mPassed ? 'pass' : 'fail' ?>">
            <div class="th-banner-score"><?= $mPct ?>%</div>
            <div>
                <h2><?= $mPassed ? 'Passed' : 'Not passed' ?></h2>
                <p><?= $mPassed ? 'You reached the 80% pass mark.' : 'You need at least 80% to pass. Review the answers below.' ?></p>
            </div>
            <div class="th-banner-meta">
                <span><?= $mScore ?> / <?= $mTotal ?> correct</span>
                <?php if ($mSecs > 0): ?><span>Time <?= sprintf('%d:%02d', intdiv($mSecs, 60), $mSecs % 60) ?></span><?php endif; ?>
            </div>
        </section>

        <nav class="th-actions">
            <a class="th-btn" href="/mock-test.php">Try Another Mock Test</a>
            <a class="th-btn ghost" href="/quiz-history.php">All History</a>
        </nav>

        <?php if (!$mockReview): ?>
            <div class="th-empty">
                <h2>Answer details not available</h2>
                <p>This mock test was taken before answers were being saved, so only the score is available. Mock tests you take from now on can be reviewed question by question.</p>
                <a class="th-btn" href="/mock-test.php">Take a Mock Test</a>
            </div>
        <?php else: ?>
            <h2 style="font-size:21px;color:#102744;margin:0 0 14px">Answer Review<?= $mWrong ? ' &middot; ' . $mWrong . ' to revise' : '' ?></h2>
            <?php foreach ($mockReview as $i => $r):
                $ok = !empty($r['is_correct']);
                $tid = (int)($r['topic_id'] ?? 0);
                $tname = $topicNames[$tid] ?? '';
                $media = historyMedia($r['image_path'] ?? '');
                $type = strtolower((string)($r['file_type'] ?? 'image'));
            ?>
                <article class="th-review <?= $ok ? 'correct' : '' ?>">
                    <div class="th-review-head">
                        <span class="th-review-num">Question <?= $i + 1 ?><?php if ($tname !== ''): ?><em><?= historyEsc($tname) ?></em><?php endif; ?></span>
                        <span class="th-tag <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Correct' : (($r['user_answer'] ?? '') === '' ? 'Not answered' : 'Wrong') ?></span>
                    </div>
                    <?php if ($media): ?>
                        <?php if ($type === 'video'): ?><video class="th-media" src="<?= historyEsc($media) ?>" controls preload="metadata"></video>
                        <?php elseif ($type !== 'pdf'): ?><img class="th-media" src="<?= historyEsc($media) ?>" alt="Question illustration"><?php endif; ?>
                    <?php endif; ?>
                    <p class="th-q"><?= historyEsc($r['question'] ?? '') ?></p>
                    <div class="th-options">
                        <?php foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $letter => $key):
                            $right = ($r['correct'] ?? '') === $letter;
                            $wrong = ($r['user_answer'] ?? '') === $letter && !$right; ?>
                            <div class="th-option <?= $right ? 'right' : ($wrong ? 'wrong' : '') ?>"><b><?= $letter ?>.</b><?= historyEsc($r[$key] ?? '') ?><?php if ($right): ?><small>Correct answer</small><?php elseif ($wrong): ?><small>Your answer</small><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (trim((string)($r['explanation'] ?? '')) !== ''): ?>
                        <div class="th-explain"><?= historyEsc($r['explanation']) ?></div>
                    <?php endif; ?>
                    <?php if (!$ok && $tid > 0 && $tname !== ''): ?>
                        <div class="tq-help">
                            <span class="tq-help-label">Need help with this?</span>
                            <?php foreach (historyHelpLinks($conn, $tid, $tname, (string)($r['question'] ?? '')) as [$kind, $label, $href]): ?>
                                <a class="tq-help-link <?= $kind ?>" href="<?= historyEsc($href) ?>"><?= $kind === 'read' ? $icoRead : ($kind === 'video' ? $icoPlay : $icoCar) ?><?= historyEsc($label) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>

    <span class="th-kicker">Your activity</span>
    <h1>History</h1>
    <p class="th-sub">Every quiz and mock test you have taken. Open any attempt to review your answers.</p>

    <section class="th-stats">
        <div class="th-stat"><div class="th-stat-icon blue"><?= $icoQuiz ?></div><div><strong><?= $quizCount ?></strong><span>Quizzes taken</span></div></div>
        <div class="th-stat"><div class="th-stat-icon green"><?= $icoAvg ?></div><div><strong><?= $avgQuiz ?>%</strong><span>Average quiz score</span></div></div>
        <div class="th-stat"><div class="th-stat-icon navy"><?= $icoMock ?></div><div><strong><?= $mockCount ?></strong><span>Mock tests taken</span></div></div>
        <div class="th-stat"><div class="th-stat-icon amber"><?= $icoPass ?></div><div><strong><?= $mockPassed ?></strong><span>Mock tests passed</span></div></div>
    </section>

    <?php if (!$items): ?>
        <div class="th-empty">
            <h2>Nothing here yet</h2>
            <p>Take a topic quiz or a mock test and it will appear here for review.</p>
            <a class="th-btn" href="/quiz.php">Browse Quizzes</a><a class="th-btn ghost" href="/mock-test.php">Take a Mock Test</a>
        </div>
    <?php else: ?>
        <div class="th-tabs" role="group" aria-label="Filter history">
            <button type="button" class="th-tab" data-filter="all" aria-pressed="true">All (<?= count($items) ?>)</button>
            <button type="button" class="th-tab" data-filter="quiz" aria-pressed="false">Quizzes (<?= $quizCount ?>)</button>
            <button type="button" class="th-tab" data-filter="mock" aria-pressed="false">Mock tests (<?= $mockCount ?>)</button>
        </div>

        <section class="th-list" id="historyList">
            <?php foreach ($items as $it):
                $cls = $it['pct'] >= 80 ? 'th-good' : ($it['pct'] >= 60 ? 'th-mid' : 'th-low'); ?>
                <article class="th-row" data-kind="<?= $it['kind'] ?>">
                    <div class="th-row-icon <?= $it['kind'] ?>"><?= $it['kind'] === 'mock' ? $icoMock : $icoQuiz ?></div>
                    <div>
                        <span class="th-type <?= $it['kind'] ?>"><?= $it['kind'] === 'mock' ? 'Mock test' : 'Topic quiz' ?></span>
                        <h2><?= historyEsc($it['title']) ?>
                            <?php if ($it['status'] === 'pending'): ?><span class="th-pill pending">Pending</span>
                            <?php elseif ($it['status'] === 'rejected'): ?><span class="th-pill rejected">Rejected</span>
                            <?php elseif ($it['passed'] !== null): ?><span class="th-pill <?= $it['passed'] ? 'pass' : 'fail' ?>"><?= $it['passed'] ? 'Passed' : 'Not passed' ?></span><?php endif; ?>
                        </h2>
                        <div class="th-date"><?= historyEsc(historyDate($it['date'])) ?></div>
                    </div>
                    <div class="th-score"><strong class="<?= $cls ?>"><?= $it['pct'] ?>%</strong><span><?= $it['score'] ?> / <?= $it['total'] ?></span></div>
                    <a class="th-btn" href="<?= historyEsc($it['href']) ?>">Review</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

</main>

<script>
(() => {
  const tabs = [...document.querySelectorAll('.th-tab')];
  const rows = [...document.querySelectorAll('#historyList .th-row')];
  tabs.forEach(t => t.addEventListener('click', () => {
    tabs.forEach(x => x.setAttribute('aria-pressed', String(x === t)));
    rows.forEach(r => { r.hidden = t.dataset.filter !== 'all' && r.dataset.kind !== t.dataset.filter; });
  }));
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>