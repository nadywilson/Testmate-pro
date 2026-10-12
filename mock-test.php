<?php
/**
 * TestMate – Mock Test (PHP 8.5 / PDO SQLite)
 * Works like the topic quizzes: server-held attempt, server timer, autosave,
 * resume after refresh, auto-marking when time runs out, full answer review.
 *
 * /mock-test.php                 eligibility check, start screen, or the running test
 * /mock-test.php?result_id=ID    result, topic breakdown and answer review
 *
 * Rules
 * - Learners unlock the test after MOCK_MIN_QUIZZES quizzes with an average of MOCK_MIN_AVERAGE %.
 * - Admins can always take it.
 * - Every attempt gets its own random questions (spread over all topics), its own
 *   question order and its own answer order, so learners sitting at the same time
 *   never see the same paper.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id < 1) { header('Location: /login.php'); exit; }
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->exec('PRAGMA busy_timeout = 8000'); // wait instead of failing when several learners submit at once

const MOCK_QUESTIONS = 50;
const MOCK_SECONDS = 3600;      // 60 minutes
const MOCK_PASS_PERCENT = 80;   // 40 of 50
const MOCK_MIN_QUIZZES = 5;
const MOCK_MIN_AVERAGE = 60;

function mockEsc($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function mockMedia($value): string {
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
function mockFail(string $message, int $status = 400): never {
    http_response_code($status);
    include __DIR__ . '/includes/header.php';
    echo '<main style="max-width:700px;margin:50px auto;padding:28px;background:#fff;border-radius:15px">'
       . '<h2>Mock Test Unavailable</h2><p>' . mockEsc($message) . '</p>'
       . '<p><a href="/mock-test.php">Return to the Mock Test</a></p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
function mockJson(array $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function mockPassMark(int $total): int { return (int)ceil($total * MOCK_PASS_PERCENT / 100); }

/** Options of a question in the order this attempt shows them: [[original letter, text], ...] */
function mockOptions(array $q, array $order): array {
    $cols = ['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'];
    $out = [];
    foreach ($order as $orig) {
        $text = trim((string)($q[$cols[$orig]] ?? ''));
        if ($text !== '') $out[] = [$orig, $text];
    }
    return $out;
}

/** Choose a new paper: random questions spread over every topic, no repeated wording. */
function mockPickQuestions(PDO $db): array {
    $rows = $db->query("SELECT id, topic_id, question FROM questions
        WHERE TRIM(COALESCE(question,'')) <> '' AND TRIM(COALESCE(option_a,'')) <> '' AND TRIM(COALESCE(option_b,'')) <> ''
          AND UPPER(TRIM(COALESCE(correct_answer,''))) IN ('A','B','C','D')
        ORDER BY RANDOM()")->fetchAll(PDO::FETCH_ASSOC);
    $byTopic = []; $seen = [];
    foreach ($rows as $r) {
        $key = strtolower(preg_replace('/\s+/', ' ', trim((string)$r['question'])));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $byTopic[(int)$r['topic_id']][] = (int)$r['id'];
    }
    $topics = array_keys($byTopic);
    shuffle($topics);
    $picked = [];
    while (count($picked) < MOCK_QUESTIONS && $byTopic) {   // one from each topic in turn
        foreach ($topics as $t) {
            if (empty($byTopic[$t])) { unset($byTopic[$t]); continue; }
            $picked[] = array_shift($byTopic[$t]);
            if (count($picked) >= MOCK_QUESTIONS) break;
        }
    }
    shuffle($picked);
    return $picked;
}

function mockLoadQuestions(PDO $db, array $ids): array {
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON t.id = q.topic_id WHERE q.id IN ($marks)");
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']] = $row;
    if (count($rows) !== count($ids)) throw new RuntimeException('A mock test question is no longer available.');
    return array_map(static fn($id) => $rows[(int)$id], $ids);
}

function mockSnapshot(array $attempt, array $post): array {
    $answers = [];
    foreach ($attempt['question_ids'] as $qid) {
        $choice = strtoupper(trim((string)($post['ans_' . $qid] ?? '')));
        if (in_array($choice, $attempt['orders'][$qid] ?? [], true)) $answers[(int)$qid] = $choice;
    }
    return $answers;
}

/** Mark an attempt, update failed questions, save to mock_scores. Returns the new result id. */
function mockMark(PDO $db, int $userId, array $attempt): int {
    $questions = mockLoadQuestions($db, array_map('intval', $attempt['question_ids']));
    if (!$questions) throw new RuntimeException('Mock test has no questions.');
    $answers = (array)($attempt['answers'] ?? []);
    $total = count($questions);
    $score = 0; $review = [];
    $letters = ['A', 'B', 'C', 'D'];
    foreach ($questions as $q) {
        $id = (int)$q['id'];
        $order = $attempt['orders'][$id] ?? $letters;
        $shown = mockOptions($q, $order);
        $answer = (string)($answers[$id] ?? '');
        $right = strtoupper(trim((string)($q['correct_answer'] ?? '')));
        $correct = $answer !== '' && $answer === $right;
        if ($correct) $score++;
        // The review is stored in the order the learner saw the answers.
        $item = ['type' => 'mcq', 'question_id' => $id, 'topic_id' => (int)$q['topic_id'], 'topic_name' => (string)($q['topic_name'] ?? ''),
            'question' => trim((string)$q['question']), 'user_answer' => '', 'correct' => '', 'is_correct' => $correct,
            'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
            'explanation' => $q['explanation'] ?? '', 'image_path' => $q['image_path'] ?? '', 'file_type' => $q['file_type'] ?? 'image'];
        foreach ($shown as $i => [$orig, $text]) {
            $item['option_' . strtolower($letters[$i])] = $text;
            if ($orig === $right) $item['correct'] = $letters[$i];
            if ($orig === $answer) $item['user_answer'] = $letters[$i];
        }
        $review[] = $item;
    }
    $passed = $score >= mockPassMark($total) ? 1 : 0;
    $taken = max(0, min(MOCK_SECONDS, time() - (int)$attempt['started_at']));
    $json = json_encode($review, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    // BEGIN IMMEDIATE takes the write lock first, so two learners submitting together
    // can never be given the same new id.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $updateFailed = $db->prepare('UPDATE failed_questions SET times_failed = times_failed + 1, last_failed = CURRENT_TIMESTAMP, topic_id = ? WHERE user_id = ? AND question_id = ?');
        $insertFailed = $db->prepare('INSERT INTO failed_questions (id, user_id, question_id, topic_id, times_failed, last_failed) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP)');
        $deleteFailed = $db->prepare('DELETE FROM failed_questions WHERE user_id = ? AND question_id = ?');
        foreach ($review as $r) {
            if ($r['is_correct']) { $deleteFailed->execute([$userId, $r['question_id']]); continue; }
            $updateFailed->execute([$r['topic_id'], $userId, $r['question_id']]);
            if ($updateFailed->rowCount() === 0) {
                $fid = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM failed_questions')->fetchColumn();
                $insertFailed->execute([$fid, $userId, $r['question_id'], $r['topic_id']]);
            }
        }
        $resultId = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM mock_scores')->fetchColumn();
        $db->prepare("INSERT INTO mock_scores (id, user_id, score, total, passed, time_taken, taken_at, status, results_json, submitted_at)
                      VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'approved', ?, CURRENT_TIMESTAMP)")
           ->execute([$resultId, $userId, $score, $total, $passed, $taken, $json]);
        $db->exec('COMMIT');
        return $resultId;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        throw $e;
    }
}

/* Study links under a wrong answer (same as the quiz page). */
function mockHelpLinks(PDO $conn, int $topicId, string $topicName, string $questionText): array {
    static $cache = [];
    if ($topicId <= 0) return [];
    if (!isset($cache[$topicId])) {
        $res = ['videos' => 0, 'sims' => []];
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM materials WHERE topic_id = ? AND LOWER(TRIM(file_type)) = 'video' AND TRIM(COALESCE(file_path,'')) <> ''");
            $st->execute([$topicId]); $res['videos'] = (int)$st->fetchColumn();
            $st = $conn->prepare('SELECT id, title, description FROM simulations WHERE topic_id = ? AND is_active = 1 ORDER BY id ASC');
            $st->execute([$topicId]); $res['sims'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { error_log('[TestMate Mock help links] ' . $e->getMessage()); }
        $cache[$topicId] = $res;
    }
    $res = $cache[$topicId];
    $links = [['read', 'Read the ' . $topicName . ' notes', '/study-materials.php?topic=' . $topicId . '&mode=read#topic-content']];
    if ($res['videos'] > 0) $links[] = ['video', 'Watch the video', '/video-demonstrations.php?topic=' . $topicId];
    if ($res['sims']) {
        $words = static fn(string $t): array => array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], static fn(string $w): bool => strlen($w) > 3));
        $q = $words($questionText); $best = $res['sims'][0]; $bestScore = -1;
        foreach ($res['sims'] as $sim) {
            $s = count(array_intersect($q, $words(($sim['title'] ?? '') . ' ' . ($sim['description'] ?? ''))));
            if ($s > $bestScore) { $best = $sim; $bestScore = $s; }
        }
        $links[] = ['sim', 'Try the simulation', '/simulations.php?id=' . (int)$best['id'] . '&mode=study'];
    }
    return $links;
}

/* ===== Eligibility ===== */
$is_admin = strtolower((string)($_SESSION['role'] ?? '')) === 'admin';
$st = $conn->prepare("SELECT COUNT(*) AS cnt, AVG(CASE WHEN total > 0 THEN score * 100.0 / total END) AS avg_pct
                      FROM quiz_scores WHERE user_id = ? AND COALESCE(status, 'approved') NOT IN ('pending', 'rejected')");
$st->execute([$user_id]);
$eg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$quiz_count = (int)($eg['cnt'] ?? 0);
$quiz_avg = (int)round((float)($eg['avg_pct'] ?? 0));
$is_eligible = $is_admin || ($quiz_count >= MOCK_MIN_QUIZZES && $quiz_avg >= MOCK_MIN_AVERAGE);

if (!isset($_SESSION['mock_attempts']) || !is_array($_SESSION['mock_attempts'])) $_SESSION['mock_attempts'] = [];
$attempts =& $_SESSION['mock_attempts'];
$result_id = max(0, (int)($_GET['result_id'] ?? 0));

/* ===== start / save / finish ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['mock_action'] ?? 'finish');
    $token = (string)($_POST['mock_token'] ?? '');
    $a = $attempts[$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($a) || (int)$a['user_id'] !== $user_id) {
        if (in_array($action, ['start', 'save'], true)) mockJson(['ok' => false, 'message' => 'Mock test session not found. Reload the page.'], 403);
        mockFail('Mock test session not found. Reload the page.');
    }
    if ($action === 'start') {
        if (!$is_eligible) mockJson(['ok' => false, 'message' => 'You do not qualify for the mock test yet.'], 403);
        if (empty($a['started_at'])) $attempts[$token]['started_at'] = time();
        mockJson(['ok' => true, 'deadline' => (int)$attempts[$token]['started_at'] + MOCK_SECONDS]);
    }
    if (empty($a['started_at'])) {
        if ($action === 'save') mockJson(['ok' => false, 'message' => 'Start the test first.'], 409);
        mockFail('Start the test before submitting.');
    }
    $expired = time() >= (int)$a['started_at'] + MOCK_SECONDS;
    if ($action === 'save') {
        if ($expired) mockJson(['ok' => false, 'expired' => true, 'message' => 'Time has expired. Your saved answers will be marked.'], 409);
        $attempts[$token]['answers'] = mockSnapshot($a, $_POST);
        $attempts[$token]['current_step'] = max(0, min(count($a['question_ids']) - 1, (int)($_POST['current_step'] ?? 0)));
        mockJson(['ok' => true, 'deadline' => (int)$a['started_at'] + MOCK_SECONDS]);
    }
    if ($action !== 'finish') mockFail('Unsupported request.');
    $postedIds = $_POST['qids'] ?? [];
    if (!is_array($postedIds) || array_map('intval', $postedIds) !== array_map('intval', $a['question_ids'])) {
        mockFail('The question set does not match your active mock test.');
    }
    // After the deadline only the last saved answers count.
    if (!$expired) $attempts[$token]['answers'] = mockSnapshot($a, $_POST);
    try {
        $id = mockMark($conn, $user_id, $attempts[$token]);
        unset($attempts[$token]);
        header('Location: /mock-test.php?result_id=' . $id); exit;
    } catch (Throwable $e) {
        error_log('[TestMate Mock Save] ' . $e->getMessage());
        mockFail('Your mock test could not be saved. Your answers are kept; please try submitting again. Check the PHP terminal for the database error.', 500);
    }
}

/* ===== Finish attempts whose time ran out ===== */
if ($result_id === 0) {
    foreach ($attempts as $token => $a) {
        if (!is_array($a) || (int)($a['user_id'] ?? 0) !== $user_id) continue;
        if (empty($a['started_at']) || time() < (int)$a['started_at'] + MOCK_SECONDS) continue;
        try {
            $id = mockMark($conn, $user_id, $a);
            unset($attempts[$token]);
            header('Location: /mock-test.php?result_id=' . $id); exit;
        } catch (Throwable $e) {
            error_log('[TestMate Auto Mock Save] ' . $e->getMessage());
            mockFail('The expired mock test could not be saved. Check the PHP terminal for details.', 500);
        }
    }
}

/* ===== Load the result, or the running / new attempt ===== */
$score_row = null; $review = []; $questions = []; $token = ''; $draft = [];
if ($result_id > 0) {
    $st = $conn->prepare('SELECT * FROM mock_scores WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$result_id, $user_id]);
    $score_row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($score_row) $review = json_decode((string)($score_row['results_json'] ?? '[]'), true) ?: [];
} elseif ($is_eligible) {
    foreach ($attempts as $tk => $a) {
        if (!is_array($a) || (int)($a['user_id'] ?? 0) !== $user_id) continue;
        try { $questions = mockLoadQuestions($conn, array_map('intval', $a['question_ids'])); $token = $tk; $draft = $a; break; }
        catch (Throwable $e) { error_log('[TestMate Mock Resume] ' . $e->getMessage()); unset($attempts[$tk]); }
    }
    if (!$token) {
        $ids = mockPickQuestions($conn);
        if ($ids) {
            $questions = mockLoadQuestions($conn, $ids);
            $orders = [];
            foreach ($ids as $qid) { $o = ['A', 'B', 'C', 'D']; shuffle($o); $orders[$qid] = $o; }
            $token = bin2hex(random_bytes(16));
            $draft = ['user_id' => $user_id, 'question_ids' => $ids, 'orders' => $orders, 'created_at' => time(), 'started_at' => 0, 'answers' => [], 'current_step' => 0];
            $attempts[$token] = $draft;
        }
    }
}
$total_q = count($questions);
$pass_mark = mockPassMark($total_q ?: MOCK_QUESTIONS);
$started = !empty($draft['started_at']);

include __DIR__ . '/includes/header.php';
?>
<style>
.tq-page,.tq-page *{box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
.tq-page{width:min(1310px,calc(100% - 40px));margin:0 auto;padding:28px 0 60px;color:#122b4c}
.tq-heading{margin-bottom:22px}.tq-kicker{display:block;color:#457eb8;font-weight:800;font-size:11px;letter-spacing:.12em;text-transform:uppercase}
.tq-heading h1{font-size:clamp(28px,3vw,36px);margin:8px 0;color:#102744;font-weight:850}.tq-heading p{color:#72869a;font-size:14px;margin:0}
.tq-card{background:#fff;border:1px solid #dfebf5;border-radius:15px;padding:24px;box-shadow:0 6px 22px #1030500a}
.tq-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:9px;border:1px solid #147df5;padding:9px 17px;background:#147df5;color:white!important;font-weight:800;font-size:13px;text-decoration:none;cursor:pointer}
.tq-button:hover{background:#086bd8}.tq-button.secondary{background:#fff;color:#1667b6!important;border-color:#cddded}.tq-button.danger{background:#fff4f3;color:#b74744!important;border-color:#f1cac6}.tq-button:disabled{opacity:.6;cursor:default}
.tq-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;align-items:start;gap:22px}.tq-main{min-width:0}.tq-question{display:none;min-height:330px}.tq-question.current{display:block}
.tq-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:25px}.tq-meta strong{font-size:14px}.tq-pill{background:#eaf4ff;color:#286aac;font-size:11px;padding:6px 12px;border-radius:40px}
.tq-question-text{font-weight:800;color:#153756;font-size:17px;line-height:1.65;margin:0 0 18px}.tq-media{max-width:100%;max-height:270px;object-fit:contain;display:block;border-radius:10px;margin:0 0 18px}.tq-iframe{width:100%;height:275px;border:1px solid #dce7f2;border-radius:10px;margin-bottom:18px}
.tq-options{display:grid;gap:10px}.tq-option{display:flex;align-items:center;gap:12px;padding:15px;border:1px solid #dce7f2;border-radius:10px;cursor:pointer;font-size:14px;color:#264766}.tq-option:hover{background:#f5faff}.tq-option:has(input:checked){background:#e7f8ef;border-color:#12b981;color:#087855;font-weight:700}.tq-option input{accent-color:#12a878}.tq-letter{font-size:14px;font-weight:900;min-width:20px;color:#123d68}
.tq-navigation{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:15px 0}.tq-navigation button{border:1px solid #dbe6f3;background:#edf5ff;color:#1769b8;border-radius:9px;padding:11px 18px;font-weight:800;cursor:pointer}.tq-navigation button:disabled{opacity:.45}.tq-navigation .next{background:#147df5;color:#fff;border-color:#147df5}
.tq-submit-strip{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}.tq-submit-strip small{color:#6c8298}.tq-save-status{font-size:12px;color:#64809d}
.tq-side{position:sticky;top:90px}.tq-side h2{font-size:15px;color:#132f4d;margin:0 0 12px}.tq-timer{background:#eaf3ff;border-radius:11px;color:#147df5;text-align:center;font-size:30px;font-weight:900;padding:15px;margin-bottom:19px;font-variant-numeric:tabular-nums}.tq-timer.warning{background:#fff4e3;color:#bb7610}.tq-timer.danger{background:#ffebeb;color:#c73c42}.tq-count{font-size:12px;color:#6d8198;margin-bottom:9px}.tq-track{height:9px;border-radius:25px;background:#e6edf5;overflow:hidden;margin-bottom:20px}.tq-track div{height:100%;background:#12b77c;width:0}
.tq-numbers{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;max-height:330px;overflow:auto}.tq-numbers button{height:32px;border:1px solid #dce7f3;border-radius:7px;background:#f1f6fb;color:#53728e;font-weight:800;font-size:12px;cursor:pointer;padding:0}.tq-numbers button.current{background:#147df5;color:#fff;border-color:#147df5}.tq-numbers button.answered:not(.current){background:#dcf7e9;color:#078559;border-color:#b7e8cf}.tq-legend{font-size:11px;color:#70849a;line-height:2;margin-top:14px}
.tq-overlay{position:fixed;inset:0;z-index:1700;display:grid;place-items:center;padding:18px;background:#102744bf}.tq-overlay[hidden]{display:none!important}.tq-modal{width:min(490px,100%);padding:34px;text-align:center}.tq-modal h2{color:#132f4d;font-size:24px;margin:0 0 12px}.tq-modal p{color:#657d95;font-size:14px;line-height:1.65}.tq-modal-actions{display:flex;justify-content:center;gap:11px;flex-wrap:wrap;margin-top:22px}
.tq-result{padding:30px;border-radius:16px;text-align:center;color:white;margin-bottom:21px}.tq-result strong{display:block;font-size:45px}.tq-result h2{color:white;margin:5px}.tq-result p{margin:6px 0 0}.tq-actions{display:flex;flex-wrap:wrap;gap:10px;margin:19px 0 23px}
.tq-study{border-left:5px solid #147df5;background:#eff6ff;margin-bottom:24px;padding:22px 25px}.tq-study h2{margin:0 0 8px;font-size:19px;color:#113e69}.tq-study p{color:#42617d;margin:0 0 15px;line-height:1.7}
.tq-review{margin-bottom:14px}.tq-review[hidden]{display:none}.tq-review h3{color:#163753;font-size:17px}.tq-tag{display:inline-block;border-radius:25px;padding:6px 11px;font-weight:850;font-size:12px}.tq-tag.correct{background:#e0f7eb;color:#078455}.tq-tag.wrong{background:#ffebeb;color:#b94141}.tq-review-options{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:15px 0}.tq-review-option{padding:14px;border:1px solid #e1eaf3;border-radius:10px;background:#f7faff;color:#254763;font-size:13px;line-height:1.65}.tq-review-option strong{font-size:14px;color:#153b62;font-weight:900;margin-right:5px}.tq-review-option.correct{background:#e5f8ef;border-color:#a6e5c6}.tq-review-option.wrong{background:#ffeded;border-color:#edbfc2}.tq-explanation{border-left:4px solid #147df5;background:#eef6ff;padding:16px;border-radius:8px;font-size:13px;line-height:1.7;color:#315c7f}.tq-answer-details{display:flex;gap:15px;flex-wrap:wrap;margin:12px 0;font-size:13px;color:#42617d}.tq-answer-details strong{color:#123a5d}
.tq-help{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-top:16px;padding-top:14px;border-top:1px dashed #dbe6f2}
.tq-help-label{font-size:13px;font-weight:700;color:#5b7189;margin-right:4px}
.tq-help-link{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 14px;border-radius:999px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid transparent}
.tq-help-link.read{background:#eaf3ff;color:#0f63c4!important;border-color:#cfe3fb}
.tq-help-link.video{background:#fff3e6;color:#b45d09!important;border-color:#fbdcb8}
.tq-help-link.sim{background:#e6f6f1;color:#0b6b4f!important;border-color:#bfe6d8}
.tq-help-link:hover{filter:brightness(.96);text-decoration:underline}
/* mock specific */
.mk-intro[hidden]{display:none!important}
.mk-intro{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:22px;align-items:start}
.mk-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0}
.mk-stat{padding:16px;border:1px solid #dfebf5;border-radius:12px;background:#f6faff}.mk-stat b{display:block;font-size:28px;color:#147df5}.mk-stat span{font-size:12px;color:#5b7189;text-transform:uppercase;letter-spacing:.05em}
.mk-rules{margin:0;padding:0;list-style:none;display:grid;gap:10px}.mk-rules li{display:flex;gap:10px;font-size:14px;color:#33536f;line-height:1.5}.mk-rules li::before{content:"✓";color:#0b9b6c;font-weight:900}
.mk-req{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border:1px solid #e1eaf3;border-radius:12px;margin-bottom:10px;background:#f8fbff}
.mk-req b{font-size:15px;color:#132f4d}.mk-req small{display:block;color:#6c8298;font-size:12px;margin-top:2px}
.mk-badge{flex:0 0 auto;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:800}.mk-badge.ok{background:#e0f7eb;color:#078455}.mk-badge.bad{background:#ffebeb;color:#b94141}
.mk-bar{height:8px;border-radius:9px;background:#e6edf5;overflow:hidden;margin-top:8px}.mk-bar div{height:100%;border-radius:9px}
.mk-topics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:24px}
.mk-topic{padding:14px 16px;border:1px solid #e1eaf3;border-radius:12px;background:#fff}
.mk-topic-head{display:flex;justify-content:space-between;gap:10px;font-size:14px;font-weight:700;color:#173855}
.mk-topic-head span{color:#5b7189;font-weight:700;white-space:nowrap}
.mk-topic a{font-size:12px;font-weight:700;color:#147df5;text-decoration:none;display:inline-block;margin-top:8px}
.mk-filter{display:inline-flex;gap:6px;padding:5px;background:#fff;border:1px solid #dbe6f2;border-radius:12px;margin:0 0 16px}
.mk-filter button{height:38px;padding:0 14px;border:0;border-radius:9px;background:transparent;color:#42607d;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.mk-filter button[aria-pressed="true"]{background:#102744;color:#fff}
@media(max-width:1000px){.tq-layout,.mk-intro{grid-template-columns:1fr}.tq-side{position:static}.tq-numbers{grid-template-columns:repeat(10,1fr)}}
@media(max-width:640px){.tq-page{width:calc(100% - 26px)}.tq-card{padding:19px}.tq-review-options,.mk-topics{grid-template-columns:1fr}.tq-meta{flex-wrap:wrap}.tq-submit-strip{justify-content:center}.mk-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.mk-stat b{font-size:22px}.tq-numbers{grid-template-columns:repeat(7,1fr)}.tq-help-link{flex:1 1 100%;justify-content:center}.mk-filter{display:flex}.mk-filter button{flex:1}}
</style>
<main class="tq-page">
<header class="tq-heading"><span class="tq-kicker">TestMate Exam Practice</span><h1><?= $result_id ? 'Mock Test Results' : 'Full Practice Test' ?></h1><p>A full learner&rsquo;s licence practice test with questions from every topic, marked like the real test.</p></header>

<?php if ($result_id > 0): ?>
  <?php if (!$score_row): ?>
    <section class="tq-card"><h2>Result Not Found</h2><p>This result is not available for your account.</p><a class="tq-button" href="/mock-test.php">Mock Test</a></section>
  <?php else:
    $sc = (int)$score_row['score']; $total = (int)$score_row['total']; $pct = $total ? (int)round($sc / $total * 100) : 0;
    $passed = (int)$score_row['passed'] === 1; $need = mockPassMark($total);
    $mins = intdiv((int)$score_row['time_taken'], 60); $secs = (int)$score_row['time_taken'] % 60;
    // Per-topic breakdown
    $byTopic = [];
    foreach ($review as $r) {
        $tid = (int)($r['topic_id'] ?? 0);
        $byTopic[$tid] ??= ['name' => (string)($r['topic_name'] ?? 'Topic'), 'right' => 0, 'total' => 0];
        $byTopic[$tid]['total']++;
        if (!empty($r['is_correct'])) $byTopic[$tid]['right']++;
    }
    uasort($byTopic, static fn($x, $y) => ($x['right'] / max(1, $x['total'])) <=> ($y['right'] / max(1, $y['total'])));
    $weak = array_filter($byTopic, static fn($t) => $t['right'] / max(1, $t['total']) < MOCK_PASS_PERCENT / 100);
    $wrongCount = count(array_filter($review, static fn($r) => empty($r['is_correct'])));
  ?>
    <section class="tq-result" style="background:<?= $passed ? '#098e6f' : '#bb3944' ?>">
      <strong><?= $sc ?> / <?= $total ?></strong>
      <h2><?= $pct ?>% &middot; <?= $passed ? 'PASSED' : 'NOT PASSED' ?></h2>
      <p><?= $passed ? 'Well done! You reached the pass mark of ' . $need . ' / ' . $total . '.' : 'You needed ' . $need . ' / ' . $total . ' to pass. Review your weak topics below and try again.' ?></p>
      <p style="opacity:.85">Time taken: <?= $mins ?> min <?= $secs ?> s</p>
    </section>
    <nav class="tq-actions"><a class="tq-button" href="/mock-test.php">Take Another Mock Test</a><a class="tq-button secondary" href="/quiz-history.php">History</a><a class="tq-button secondary" href="/study-materials.php">Study Materials</a></nav>

    <?php if ($weak): ?>
      <section class="tq-card tq-study">
        <h2>Recommended Study Topics</h2>
        <p>You scored below <?= MOCK_PASS_PERCENT ?>% in these topics. Study them, take their quizzes, then try the mock test again.</p>
        <div style="display:flex;flex-wrap:wrap;gap:10px">
          <?php foreach ($weak as $tid => $t): ?><a class="tq-button" href="/study-materials.php?topic=<?= (int)$tid ?>&amp;mode=read">Study <?= mockEsc($t['name']) ?></a><?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <h2 style="font-size:21px;color:#173855">Score by Topic</h2>
    <div class="mk-topics">
      <?php foreach ($byTopic as $tid => $t): $tp = (int)round($t['right'] / max(1, $t['total']) * 100); $col = $tp >= 80 ? '#12b77c' : ($tp >= 60 ? '#f09017' : '#d94b4b'); ?>
        <div class="mk-topic">
          <div class="mk-topic-head"><?= mockEsc($t['name']) ?><span><?= $t['right'] ?> / <?= $t['total'] ?></span></div>
          <div class="mk-bar"><div style="width:<?= $tp ?>%;background:<?= $col ?>"></div></div>
          <?php if ($tp < 80 && $tid): ?><a href="/quiz.php?topic=<?= (int)$tid ?>">Practise this topic &rarr;</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:21px;color:#173855">Your Answer Review</h2>
    <div class="mk-filter" role="group" aria-label="Filter answers">
      <button type="button" data-show="all" aria-pressed="true">All (<?= count($review) ?>)</button>
      <button type="button" data-show="wrong" aria-pressed="false">Wrong (<?= $wrongCount ?>)</button>
      <button type="button" data-show="correct" aria-pressed="false">Correct (<?= count($review) - $wrongCount ?>)</button>
    </div>
    <?php foreach ($review as $i => $r): $correct = !empty($r['is_correct']); $tid = (int)($r['topic_id'] ?? 0); ?>
      <article class="tq-card tq-review" data-result="<?= $correct ? 'correct' : 'wrong' ?>">
        <span class="tq-tag <?= $correct ? 'correct' : 'wrong' ?>"><?= $correct ? 'Correct Answer' : (($r['user_answer'] ?? '') === '' ? 'Not Answered' : 'Needs Improvement') ?></span>
        <span class="tq-pill" style="margin-left:6px"><?= mockEsc($r['topic_name'] ?? '') ?></span>
        <h3>Question <?= $i + 1 ?></h3><p class="tq-question-text"><?= mockEsc($r['question'] ?? '') ?></p>
        <?php $media = mockMedia($r['image_path'] ?? ''); $type = strtolower((string)($r['file_type'] ?? 'image')); ?>
        <?php if ($media): ?>
          <?php if ($type === 'video'): ?><video class="tq-media" src="<?= mockEsc($media) ?>" controls></video>
          <?php elseif ($type === 'pdf'): ?><iframe class="tq-iframe" src="<?= mockEsc($media) ?>" title="Question document"></iframe>
          <?php else: ?><img class="tq-media" src="<?= mockEsc($media) ?>" alt="Question illustration" loading="lazy"><?php endif; ?>
        <?php endif; ?>
        <div class="tq-review-options">
          <?php foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $letter => $key): if (trim((string)($r[$key] ?? '')) === '') continue; $right = ($r['correct'] ?? '') === $letter; $wrong = ($r['user_answer'] ?? '') === $letter && !$right; ?>
            <div class="tq-review-option <?= $right ? 'correct' : ($wrong ? 'wrong' : '') ?>"><strong><?= $letter ?>.</strong> <?= mockEsc($r[$key]) ?><?php if ($right): ?> <b>(Correct)</b><?php elseif ($wrong): ?> <b>(Your answer)</b><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <div class="tq-answer-details"><span>Your answer: <strong><?= mockEsc(($r['user_answer'] ?? '') ?: 'Not answered') ?></strong></span><span>Correct answer: <strong><?= mockEsc($r['correct'] ?? '') ?></strong></span></div>
        <?php if (!empty($r['explanation'])): ?><div class="tq-explanation"><strong>Explanation</strong><p><?= mockEsc($r['explanation']) ?></p></div><?php endif; ?>
        <?php if (!$correct && $tid): ?>
          <div class="tq-help">
            <span class="tq-help-label">Need help with this?</span>
            <?php foreach (mockHelpLinks($conn, $tid, (string)($r['topic_name'] ?? 'topic'), (string)($r['question'] ?? '')) as [$kind, $label, $href]): ?>
              <a class="tq-help-link <?= $kind ?>" href="<?= mockEsc($href) ?>"><?php if ($kind === 'read'): ?><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2zM22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg><?php elseif ($kind === 'video'): ?><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15l13-7.5z"/></svg><?php else: ?><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M3 16h18v3H3zM7 19v2M17 19v2"/></svg><?php endif; ?><?= mockEsc($label) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

<?php elseif (!$is_eligible): ?>
  <?php $needQuiz = max(0, MOCK_MIN_QUIZZES - $quiz_count); ?>
  <section class="tq-card" style="max-width:760px">
    <h2 style="margin-top:0;color:#132f4d">&#128274; Mock Test Locked</h2>
    <p style="color:#5b7189;line-height:1.6">The full practice test unlocks when you are ready for it. Meet both requirements below.</p>
    <div class="mk-req">
      <div><b>Quizzes completed</b><small><?= $quiz_count ?> of <?= MOCK_MIN_QUIZZES ?> needed<?= $needQuiz ? ' &middot; ' . $needQuiz . ' more to go' : '' ?></small>
        <div class="mk-bar" style="width:220px"><div style="width:<?= min(100, (int)round($quiz_count / MOCK_MIN_QUIZZES * 100)) ?>%;background:#147df5"></div></div></div>
      <span class="mk-badge <?= $quiz_count >= MOCK_MIN_QUIZZES ? 'ok' : 'bad' ?>"><?= $quiz_count >= MOCK_MIN_QUIZZES ? 'Met' : 'Not yet' ?></span>
    </div>
    <div class="mk-req">
      <div><b>Average quiz score</b><small><?= $quiz_avg ?>% now &middot; <?= MOCK_MIN_AVERAGE ?>% needed</small>
        <div class="mk-bar" style="width:220px"><div style="width:<?= min(100, $quiz_avg) ?>%;background:<?= $quiz_avg >= MOCK_MIN_AVERAGE ? '#12b77c' : '#f09017' ?>"></div></div></div>
      <span class="mk-badge <?= $quiz_avg >= MOCK_MIN_AVERAGE ? 'ok' : 'bad' ?>"><?= $quiz_avg >= MOCK_MIN_AVERAGE ? 'Met' : 'Not yet' ?></span>
    </div>
    <p style="color:#5b7189;font-size:14px">Take more topic quizzes and review your mistakes. The mock test unlocks automatically once both are met.</p>
    <div class="tq-actions" style="margin-bottom:0"><a class="tq-button" href="/quiz.php">Take a Quiz</a><a class="tq-button secondary" href="/quiz-history.php">My History</a><a class="tq-button secondary" href="/study-materials.php">Study Materials</a></div>
  </section>

<?php elseif (!$questions): ?>
  <section class="tq-card"><h2>No Questions Available</h2><p>There are no questions in the question bank yet.</p></section>

<?php else: ?>
  <form id="mockForm" method="post" action="/mock-test.php" autocomplete="off" <?= $started ? '' : 'hidden' ?>>
    <input type="hidden" name="mock_action" value="finish"><input type="hidden" name="mock_token" value="<?= mockEsc($token) ?>">
    <div class="tq-layout"><div class="tq-main">
      <?php foreach ($questions as $i => $q): $qid = (int)$q['id']; $media = mockMedia($q['image_path'] ?? ''); $type = strtolower((string)($q['file_type'] ?? 'image')); ?>
        <article class="tq-card tq-question" data-step="<?= $i ?>"><div class="tq-meta"><strong>Question <?= $i + 1 ?> of <?= $total_q ?></strong><span class="tq-pill"><?= mockEsc($q['topic_name'] ?? '') ?></span></div>
          <p class="tq-question-text"><?= mockEsc(trim((string)$q['question'])) ?></p>
          <?php if ($media): ?><?php if ($type === 'video'): ?><video class="tq-media" src="<?= mockEsc($media) ?>" controls></video><?php elseif ($type === 'pdf'): ?><iframe class="tq-iframe" src="<?= mockEsc($media) ?>" title="Question document"></iframe><?php else: ?><img class="tq-media" src="<?= mockEsc($media) ?>" alt="Question illustration" loading="lazy"><?php endif; ?><?php endif; ?>
          <input type="hidden" name="qids[]" value="<?= $qid ?>"><div class="tq-options">
            <?php foreach (mockOptions($q, $draft['orders'][$qid] ?? ['A', 'B', 'C', 'D']) as $pos => [$orig, $text]): ?>
              <label class="tq-option"><input type="radio" name="ans_<?= $qid ?>" value="<?= $orig ?>" <?= ($draft['answers'][$qid] ?? '') === $orig ? 'checked' : '' ?>><strong class="tq-letter"><?= chr(65 + $pos) ?>.</strong><span><?= mockEsc($text) ?></span></label>
            <?php endforeach; ?>
          </div></article>
      <?php endforeach; ?>
      <div class="tq-navigation"><button type="button" id="mockPrevious">Previous</button><span id="mockPosition"></span><button type="button" class="next" id="mockNext">Next</button></div>
      <div class="tq-card tq-submit-strip"><div><strong>Finished answering?</strong><div><small>You can submit before the timer ends. Unanswered questions are marked wrong.</small></div><div class="tq-save-status" id="mockSaveStatus" role="status">Answers saved automatically</div></div><button class="tq-button" type="button" id="mockSubmitButton">Submit Test</button></div>
    </div><aside class="tq-card tq-side"><h2>Time Remaining</h2><div class="tq-timer" id="mockTimer">60:00</div><h2>Test Progress</h2><div class="tq-count" id="mockAnswered"></div><div class="tq-track"><div id="mockProgressFill"></div></div><div class="tq-numbers" id="mockNumbers"></div><div class="tq-legend">Blue: Current question<br>Green: Answered question<br>Grey: Not answered<br>Pass mark: <?= $pass_mark ?> / <?= $total_q ?> (<?= MOCK_PASS_PERCENT ?>%)</div><div style="margin-top:18px"><button type="button" class="tq-button" id="mockSideSubmit" style="width:100%">Submit Test</button></div></aside></div>
  </form>

  <section class="tq-card mk-intro" id="mockIntro" <?= $started ? 'hidden' : '' ?>>
    <div>
      <h2 style="margin:0;color:#132f4d;font-size:26px">Ready to test your knowledge?</h2>
      <div class="mk-stats">
        <div class="mk-stat"><b><?= $total_q ?></b><span>Questions</span></div>
        <div class="mk-stat"><b><?= MOCK_SECONDS / 60 ?></b><span>Minutes</span></div>
        <div class="mk-stat"><b><?= $pass_mark ?></b><span>To pass</span></div>
      </div>
      <button type="button" class="tq-button" id="mockStart" style="min-height:50px;padding:0 34px;font-size:15px">Start Test Now</button>
      <?php if ($is_admin): ?><p style="color:#5b7189;font-size:13px;margin-top:12px">Admin access: you can take the mock test at any time.</p><?php endif; ?>
    </div>
    <div>
      <h3 style="margin:0 0 12px;color:#132f4d;font-size:15px;letter-spacing:.04em;text-transform:uppercase">Before you start</h3>
      <ul class="mk-rules">
        <li>The timer starts when you press Start and cannot be paused.</li>
        <li>Questions come from every topic, and their order and answer order are different for every learner.</li>
        <li>Your answers are saved as you go. If the page reloads you continue where you left off.</li>
        <li>You need <?= $pass_mark ?> correct answers to pass (<?= MOCK_PASS_PERCENT ?>%). Unanswered questions are marked wrong.</li>
        <li>The test is submitted automatically when the timer reaches zero.</li>
        <li>After the test you see every answer with an explanation and links to study what you got wrong.</li>
      </ul>
    </div>
  </section>
  <div class="tq-overlay" id="mockConfirm" hidden><div class="tq-card tq-modal" role="dialog" aria-modal="true" aria-labelledby="mockConfirmTitle"><h2 id="mockConfirmTitle">Submit Your Test?</h2><p id="mockConfirmMessage"></p><p><strong>Once submitted, your answers cannot be changed.</strong></p><div class="tq-modal-actions"><button type="button" class="tq-button secondary" id="mockCancel">Continue Test</button><button type="button" class="tq-button" id="mockConfirmBtn">Confirm Submission</button></div></div></div>
<?php endif; ?>
</main>

<?php if ($result_id > 0 && $score_row): ?>
<script>
(() => {
  const buttons = [...document.querySelectorAll('.mk-filter button')];
  const cards = [...document.querySelectorAll('.tq-review')];
  buttons.forEach(b => b.addEventListener('click', () => {
    buttons.forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    cards.forEach(c => { c.hidden = b.dataset.show !== 'all' && c.dataset.result !== b.dataset.show; });
  }));
})();
</script>
<?php elseif ($is_eligible && $questions): ?>
<script>
(() => {
  'use strict';
  const form = document.getElementById('mockForm');
  const intro = document.getElementById('mockIntro');
  const cards = [...form.querySelectorAll('.tq-question')];
  const total = cards.length;
  const token = <?= json_encode($token) ?>;
  const LIMIT = <?= MOCK_SECONDS ?>;
  let deadline = <?= $started ? (int)$draft['started_at'] + MOCK_SECONDS : 0 ?>;
  let index = <?= max(0, (int)($draft['current_step'] ?? 0)) ?>;
  let saving = Promise.resolve(), savingCount = 0, submitted = false, timerId = null;
  const timer = document.getElementById('mockTimer');
  const confirmBox = document.getElementById('mockConfirm');
  const status = document.getElementById('mockSaveStatus');
  const nav = document.getElementById('mockNumbers');
  const buttons = cards.map((card, i) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = i + 1;
    b.setAttribute('aria-label', 'Go to question ' + (i + 1));
    b.addEventListener('click', () => show(i)); nav.appendChild(b); return b;
  });
  const fmt = s => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  const getData = action => {
    const data = new FormData(form);
    data.set('mock_action', action); data.set('mock_token', token); data.set('current_step', String(index));
    return data;
  };
  const saveDraft = () => {
    if (submitted || !deadline) return saving;
    const body = getData('save');
    savingCount++; status.textContent = 'Saving answers...';
    saving = saving.catch(() => {}).then(async () => {
      const response = await fetch('/mock-test.php', {method: 'POST', body, credentials: 'same-origin'});
      const data = await response.json();
      if (data.expired) { setTimeout(() => finish(true), 0); return; }
      if (!response.ok || !data.ok) throw Error(data.message || 'Save failed');
      savingCount--; if (!savingCount) status.textContent = 'Answers saved automatically';
    }).catch(e => { savingCount = 0; status.textContent = 'Connection problem: your latest answers may not be saved'; console.error(e); });
    return saving;
  };
  const answered = i => Boolean(cards[i].querySelector('input[type="radio"]:checked'));
  const count = () => form.querySelectorAll('.tq-question input[type="radio"]:checked').length;
  const render = () => {
    cards.forEach((c, i) => c.classList.toggle('current', i === index));
    buttons.forEach((b, i) => { b.classList.toggle('current', i === index); b.classList.toggle('answered', answered(i)); });
    document.getElementById('mockPrevious').disabled = index === 0;
    document.getElementById('mockNext').disabled = index === total - 1;
    document.getElementById('mockPosition').textContent = `${index + 1} of ${total}`;
    const n = count();
    document.getElementById('mockAnswered').textContent = `${n} of ${total} answered`;
    document.getElementById('mockProgressFill').style.width = `${100 * n / total}%`;
  };
  const show = i => { index = Math.max(0, Math.min(total - 1, i)); render(); saveDraft(); window.scrollTo({top: 0, behavior: 'smooth'}); };
  document.getElementById('mockPrevious').onclick = () => show(index - 1);
  document.getElementById('mockNext').onclick = () => show(index + 1);
  form.addEventListener('change', () => { render(); saveDraft(); });
  const tick = () => {
    if (!deadline || submitted) return;
    const left = Math.max(0, deadline - Math.floor(Date.now() / 1000));
    timer.textContent = fmt(left);
    timer.classList.toggle('warning', left <= 600 && left > 120); timer.classList.toggle('danger', left <= 120);
    if (left === 0) finish(true);
  };
  const finish = async (auto = false) => {
    if (submitted) return;
    submitted = true; confirmBox.hidden = true; clearInterval(timerId);
    ['mockConfirmBtn', 'mockSubmitButton', 'mockSideSubmit'].forEach(id => document.getElementById(id).disabled = true);
    status.textContent = auto ? 'Time expired. Submitting saved answers...' : 'Submitting your test...';
    try { await saving; } catch (_) {}
    form.submit();
  };
  const askSubmit = () => {
    if (!deadline || submitted) return;
    const left = total - count();
    document.getElementById('mockConfirmMessage').textContent = left
      ? `You have ${left} unanswered question(s). They will be marked wrong.`
      : 'You have answered every question.';
    confirmBox.hidden = false;
  };
  document.getElementById('mockSubmitButton').onclick = askSubmit;
  document.getElementById('mockSideSubmit').onclick = askSubmit;
  document.getElementById('mockCancel').onclick = () => { confirmBox.hidden = true; };
  document.getElementById('mockConfirmBtn').onclick = () => finish(false);
  form.addEventListener('submit', e => { if (!submitted) { e.preventDefault(); askSubmit(); } });
  const begin = () => { intro.hidden = true; form.hidden = false; render(); tick(); timerId = setInterval(tick, 500); };
  document.getElementById('mockStart').onclick = async () => {
    const btn = document.getElementById('mockStart'); btn.disabled = true;
    try {
      const res = await fetch('/mock-test.php', {method: 'POST', body: getData('start'), credentials: 'same-origin'});
      const data = await res.json();
      if (!res.ok || !data.ok) throw Error(data.message || 'Unable to start');
      deadline = Number(data.deadline); begin();
    } catch (e) { btn.disabled = false; alert(e.message); }
  };
  timer.textContent = fmt(LIMIT);
  index = Math.max(0, Math.min(total - 1, index));
  if (deadline) begin(); else render();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>