<?php
/**
 * TestMate – Topic Quizzes (PHP 8.5 / PDO SQLite)
 * One-file implementation, compatible with existing quiz tables.
 * Five multiple-choice questions, optional two matching pairs, 15-minute timer.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id < 1) { header('Location: /login.php'); exit; }
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function quizEsc($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cleanQuestionText($value): string {
    // Keep original question wording, including sign numbers; avoid corrupted regexes.
    return trim((string)($value ?? ''));
}
function quizMedia($value): string {
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
function quizFail(string $message, int $status = 400): never {
    http_response_code($status);
    include __DIR__ . '/includes/header.php';
    echo '<main style="max-width:700px;margin:50px auto;padding:28px;background:#fff;border-radius:15px">'
       . '<h2>Quiz Unavailable</h2><p>' . quizEsc($message) . '</p>'
       . '<p><a href="/quiz.php">Return to Topic Quizzes</a></p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
function quizJson(array $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function quizSnapshot(array $attempt, array $post): array {
    $answers = []; $matches = []; $used = [];
    foreach ($attempt['question_ids'] as $qid) {
        $choice = strtoupper(trim((string)($post['ans_' . $qid] ?? '')));
        if (in_array($choice, ['A','B','C','D'], true)) $answers[(int)$qid] = $choice;
    }
    $bonusIds = array_map('intval', $attempt['bonus_ids']);
    foreach ($bonusIds as $id) {
        $placed = (int)($post['dd_zone_' . $id] ?? 0);
        if (!in_array($placed, $bonusIds, true) || in_array($placed, $used, true)) $placed = 0;
        if ($placed) $used[] = $placed;
        $matches[$id] = $placed;
    }
    return ['answers' => $answers, 'matches' => $matches];
}
function quizLoadRows(PDO $db, string $table, array $ids, int $topicId): array {
    if (!$ids) return [];
    // Table is a fixed, internal string; IDs and topic are always bound.
    if (!in_array($table, ['questions', 'dragdrop_pairs'], true)) throw new LogicException('Invalid table');
    $params = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM $table WHERE id IN ($params) AND topic_id = ?");
    $stmt->execute([...$ids, $topicId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']] = $row;
    if (count($rows) !== count($ids)) throw new RuntimeException('A quiz question is no longer available.');
    return array_map(static fn($id) => $rows[(int)$id], $ids);
}
/**
 * SQLite migration compatibility:
 * Legacy tables may have "id INTEGER NOT NULL" + UNIQUE INDEX,
 * instead of the auto-generating "id INTEGER PRIMARY KEY".
 * Allocate an ID within the current scoring transaction.
 */
function quizNextId(PDO $db, string $table): int {
    if (!in_array($table, ['quiz_scores', 'failed_questions'], true)) {
        throw new LogicException('Invalid score table for ID allocation.');
    }
    return (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM ' . $table)->fetchColumn();
}

function quizMark(PDO $db, int $userId, array $attempt): int {
    $qids = array_map('intval', $attempt['question_ids']);
    $bonusIds = array_map('intval', $attempt['bonus_ids']);
    $topicId = (int)$attempt['topic_id'];
    $questions = quizLoadRows($db, 'questions', $qids, $topicId);
    $pairs = quizLoadRows($db, 'dragdrop_pairs', $bonusIds, $topicId);
    $answers = (array)($attempt['answers'] ?? []);
    $matches = (array)($attempt['matches'] ?? []);
    $total = count($questions) + count($pairs);
    $score = 0; $review = [];
    if (!$questions) throw new RuntimeException('Quiz has no questions.');

    $db->beginTransaction();
    try {
        $updateFailed = $db->prepare('UPDATE failed_questions SET times_failed = times_failed + 1, last_failed = CURRENT_TIMESTAMP, topic_id = ? WHERE user_id = ? AND question_id = ?');
        $insertFailed = $db->prepare('INSERT INTO failed_questions (id, user_id, question_id, topic_id, times_failed, last_failed) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP)');
        $deleteFailed = $db->prepare('DELETE FROM failed_questions WHERE user_id = ? AND question_id = ?');
        foreach ($questions as $q) {
            $id = (int)$q['id'];
            $answer = (string)($answers[$id] ?? '');
            $right = strtoupper(trim((string)($q['correct_answer'] ?? '')));
            $correct = $answer !== '' && $answer === $right;
            if ($correct) $score++;
            $review[] = [
                'type' => 'mcq', 'question_id' => $id,
                'question' => cleanQuestionText($q['question'] ?? ''),
                'user_answer' => $answer, 'correct' => $right, 'is_correct' => $correct,
                'option_a' => $q['option_a'] ?? '', 'option_b' => $q['option_b'] ?? '',
                'option_c' => $q['option_c'] ?? '', 'option_d' => $q['option_d'] ?? '',
                'explanation' => $q['explanation'] ?? '',
                'image_path' => $q['image_path'] ?? '', 'file_type' => $q['file_type'] ?? 'image'
            ];
            if ($correct) {
                $deleteFailed->execute([$userId, $id]);
            } else {
                // UPDATE then INSERT does not require a UNIQUE(user_id, question_id) constraint.
                $updateFailed->execute([$topicId, $userId, $id]);
                if ($updateFailed->rowCount() === 0) {
                    $newFailedId = quizNextId($db, 'failed_questions');
                    $insertFailed->execute([$newFailedId, $userId, $id, $topicId]);
                }
            }
        }
        $byId = [];
        foreach ($pairs as $p) $byId[(int)$p['id']] = $p;
        $used = [];
        foreach ($pairs as $pair) {
            $id = (int)$pair['id'];
            $selected = (int)($matches[$id] ?? 0);
            if (!isset($byId[$selected]) || in_array($selected, $used, true)) $selected = 0;
            if ($selected) $used[] = $selected;
            $correct = $selected === $id;
            if ($correct) $score++;
            $review[] = [
                'type' => 'dragdrop', 'is_correct' => $correct,
                'value' => $selected ? ((string)$byId[$selected]['value'] !== '' ? (string)$byId[$selected]['value'] : 'Picture') : 'Not matched',
                'value_image' => $selected ? (string)($byId[$selected]['image_path'] ?? '') : '',
                'target' => $pair['target'], 'correct_value' => (string)$pair['value'] !== '' ? $pair['value'] : 'Picture',
                'correct_image' => (string)($pair['image_path'] ?? '')
            ];
        }
        $json = json_encode($review, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $resultId = quizNextId($db, 'quiz_scores');
        $save = $db->prepare("INSERT INTO quiz_scores (id, user_id, topic_id, score, total, status, results_json, submitted_at) VALUES (?, ?, ?, ?, ?, 'approved', ?, CURRENT_TIMESTAMP)");
        $save->execute([$resultId, $userId, $topicId, $score, $total, $json]);
        $db->commit();
        return $resultId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if (!isset($_SESSION['quiz_attempts']) || !is_array($_SESSION['quiz_attempts'])) $_SESSION['quiz_attempts'] = [];
$attempts =& $_SESSION['quiz_attempts'];
$topic_id = max(0, (int)($_GET['topic'] ?? 0));
$result_id = max(0, (int)($_GET['result_id'] ?? 0));
$mode = ($_GET['mode'] ?? '') === 'retry' ? 'retry' : 'normal';

// Save/start/submit all refer to a server-held, user-owned, randomly generated token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['quiz_action'] ?? 'finish');
    $token = (string)($_POST['quiz_token'] ?? '');
    $a = $attempts[$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($a) || (int)$a['user_id'] !== $user_id) {
        if (in_array($action, ['start','save'], true)) quizJson(['ok'=>false, 'message'=>'Quiz session not found. Reload the topic.'], 403);
        quizFail('Quiz session not found. Reload the topic.');
    }
    if ($action === 'start') {
        if (empty($a['started_at'])) $attempts[$token]['started_at'] = time();
        quizJson(['ok'=>true, 'deadline'=>(int)$attempts[$token]['started_at'] + 900]);
    }
    if (empty($a['started_at'])) {
        if ($action === 'save') quizJson(['ok'=>false, 'message'=>'Start the quiz first.'], 409);
        quizFail('Start the quiz before submitting.');
    }
    $expired = time() >= (int)$a['started_at'] + 900;
    if ($action === 'save') {
        if ($expired) quizJson(['ok'=>false, 'expired'=>true, 'message'=>'Time has expired. Your saved answers will be marked.'], 409);
        $snapshot = quizSnapshot($a, $_POST);
        $attempts[$token]['answers'] = $snapshot['answers'];
        $attempts[$token]['matches'] = $snapshot['matches'];
        $attempts[$token]['current_step'] = max(0, min(
            count($a['question_ids']) + (count($a['bonus_ids']) ? 1 : 0) - 1,
            (int)($_POST['current_step'] ?? 0)
        ));
        quizJson(['ok'=>true, 'deadline'=>(int)$a['started_at'] + 900]);
    }
    if ($action !== 'finish') quizFail('Unsupported request.');
    // Check question IDs before marking, but never trust posted answer keys.
    $postedIds = $_POST['qids'] ?? [];
    if (!is_array($postedIds) || array_map('intval', $postedIds) !== array_map('intval', $a['question_ids']) || (int)($_POST['topic_id'] ?? 0) !== (int)$a['topic_id']) {
        quizFail('The question set does not match your active quiz.');
    }
    // Late submissions are graded from last SAVED answers, not newly posted answers.
    if (!$expired) {
        $snapshot = quizSnapshot($a, $_POST);
        $attempts[$token]['answers'] = $snapshot['answers'];
        $attempts[$token]['matches'] = $snapshot['matches'];
    }
    try {
        $id = quizMark($conn, $user_id, $attempts[$token]);
        unset($attempts[$token]);
        header('Location: /quiz.php?result_id=' . $id); exit;
    } catch (Throwable $e) {
        error_log('[TestMate Quiz Save] ' . $e->getMessage());
        quizFail('Your quiz could not be saved. Your answers have been retained; please retry. Check the PHP terminal for the database error.', 500);
    }
}

// Finish timed-out attempts when the learner next makes a request.
if ($result_id === 0) {
    foreach ($attempts as $token => $a) {
        if (!is_array($a) || (int)($a['user_id'] ?? 0) !== $user_id) continue;
        if (empty($a['started_at']) || time() < (int)$a['started_at'] + 900) continue;
        try {
            $id = quizMark($conn, $user_id, $a);
            unset($attempts[$token]);
            header('Location: /quiz.php?result_id=' . $id); exit;
        } catch (Throwable $e) {
            error_log('[TestMate Auto Quiz Save] ' . $e->getMessage());
            quizFail('The expired quiz could not be saved. Your draft remains available in the session. Check the PHP terminal for details.', 500);
        }
    }
}

$excluded = ["What is a Learner's Licence", 'Licence Categories & Codes', 'Licence Conditions', "What You're Tested On", 'Guidance Signs', 'Information Signs', 'Road Markings', 'Right of Way', 'Wet Weather & Tyres', 'Seatbelts & Distractions', 'Animals on the Road'];
$marks = implode(',', array_fill(0, count($excluded), '?'));
$stmt = $conn->prepare("SELECT * FROM topics WHERE name NOT IN ($marks) ORDER BY id ASC");
$stmt->execute($excluded);
$topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
$topic = null; $questions = []; $bonus = []; $quiz_token = ''; $draft = [];
$score_row = null; $review_results = []; $failed_count = 0; $failed_by_topic = [];
if ($result_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM quiz_scores WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$result_id, $user_id]);
    $score_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($score_row) {
        $topic_id = (int)$score_row['topic_id'];
        $review_results = json_decode((string)($score_row['results_json'] ?? '[]'), true) ?: [];
    }
}
if ($topic_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM topics WHERE id = ? LIMIT 1');
    $stmt->execute([$topic_id]);
    $topic = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($topic && $result_id === 0) {
    $requestedMode = $mode;
    foreach ($attempts as $token => $a) {
        if (!is_array($a) || (int)($a['user_id'] ?? 0) !== $user_id || (int)($a['topic_id'] ?? 0) !== $topic_id || ($a['mode'] ?? 'normal') !== $requestedMode) continue;
        try {
            $questions = quizLoadRows($conn, 'questions', array_map('intval', $a['question_ids']), $topic_id);
            $bonus = quizLoadRows($conn, 'dragdrop_pairs', array_map('intval', $a['bonus_ids']), $topic_id);
            $quiz_token = $token; $draft = $a;
            break;
        } catch (Throwable $e) {
            error_log('[TestMate Resume] ' . $e->getMessage());
            unset($attempts[$token]);
        }
    }
    if (!$quiz_token) {
        if ($mode === 'retry') {
            $stmt = $conn->prepare('SELECT q.* FROM questions q INNER JOIN failed_questions f ON q.id = f.question_id WHERE f.user_id = ? AND f.topic_id = ? ORDER BY f.times_failed DESC, f.last_failed DESC LIMIT 5');
            $stmt->execute([$user_id, $topic_id]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$questions) $mode = 'normal';
        }
        if ($mode === 'normal') {
            $stmt = $conn->prepare('SELECT * FROM questions WHERE topic_id = ? ORDER BY RANDOM() LIMIT 5');
            $stmt->execute([$topic_id]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($questions) {
                $stmt = $conn->prepare('SELECT * FROM dragdrop_pairs WHERE topic_id = ? ORDER BY RANDOM() LIMIT 2');
                $stmt->execute([$topic_id]);
                $bonus = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if ($questions) {
            $quiz_token = bin2hex(random_bytes(16));
            $draft = ['user_id'=>$user_id, 'topic_id'=>$topic_id, 'mode'=>$requestedMode, 'question_ids'=>array_map('intval', array_column($questions,'id')), 'bonus_ids'=>array_map('intval', array_column($bonus,'id')), 'created_at'=>time(), 'started_at'=>0, 'answers'=>[], 'matches'=>[], 'current_step'=>0];
            $attempts[$quiz_token] = $draft;
        }
    }
}
$stmt = $conn->prepare('SELECT topic_id, COUNT(*) AS count_failed FROM failed_questions WHERE user_id = ? GROUP BY topic_id');
$stmt->execute([$user_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $failed_by_topic[(int)$r['topic_id']] = (int)$r['count_failed'];
if ($topic_id) $failed_count = $failed_by_topic[$topic_id] ?? 0;

/**
 * Study links shown under a wrong answer: the topic notes, its videos
 * and the simulation that best matches the question. Read-only queries.
 */
function quizHelpResources(PDO $conn, int $topicId): array {
    $res = ['videos' => 0, 'sims' => []];
    if ($topicId <= 0) return $res;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM materials WHERE topic_id = ? AND LOWER(TRIM(file_type)) = 'video' AND TRIM(COALESCE(file_path,'')) <> ''");
        $st->execute([$topicId]);
        $res['videos'] = (int)$st->fetchColumn();
        $st = $conn->prepare('SELECT id, title, description FROM simulations WHERE topic_id = ? AND is_active = 1 ORDER BY id ASC');
        $st->execute([$topicId]);
        $res['sims'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[TestMate Quiz help links] ' . $e->getMessage());
    }
    return $res;
}

function quizHelpLinks(array $res, int $topicId, string $topicName, string $questionText): array {
    $links = [['read', 'Read the ' . $topicName . ' notes', '/study-materials.php?topic=' . $topicId . '&mode=read#topic-content']];
    if ($res['videos'] > 0) {
        $links[] = ['video', 'Watch the video', '/video-demonstrations.php?topic=' . $topicId];
    }
    if ($res['sims']) {
        // Pick the simulation whose title/description shares the most words with the question.
        $words = static fn(string $t): array => array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [],
            static fn(string $w): bool => strlen($w) > 3
        ));
        $q = $words($questionText);
        $best = $res['sims'][0]; $bestScore = -1;
        foreach ($res['sims'] as $sim) {
            $score = count(array_intersect($q, $words(($sim['title'] ?? '') . ' ' . ($sim['description'] ?? ''))));
            if ($score > $bestScore) { $best = $sim; $bestScore = $score; }
        }
        $links[] = ['sim', 'Try the simulation', '/simulations.php?id=' . (int)$best['id'] . '&mode=study'];
    }
    return $links;
}

/**
 * Topic card artwork (road-sign style SVG drawn for TestMate) and category.
 * Returns [category label, filter group ('signs'|'rules'), inline SVG].
 */
function quizTopicCard(string $name): array {
    $n = strtolower($name);
    $svg = static fn(string $label, string $body, int $size = 58): string =>
        '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" role="img" aria-label="' . quizEsc($label) . '">' . $body . '</svg>';
    $text = static fn(string $t, int $size, string $fill, int $y): string =>
        '<text x="50" y="' . $y . '" text-anchor="middle" font-family="Arial, sans-serif" font-weight="700" font-size="' . $size . '" fill="' . $fill . '">' . $t . '</text>';
    if (str_contains($n, 'warning')) {
        return ['Warning signs', 'signs', $svg('Yellow warning sign', '<path d="M50 7L95 89H5Z" fill="#f5c518" stroke="#111" stroke-width="7" stroke-linejoin="round"/><rect x="45" y="34" width="10" height="30" rx="4" fill="#111"/><circle cx="50" cy="75" r="6" fill="#111"/>', 62)];
    }
    if (str_contains($n, 'regulatory')) {
        return ['Regulatory signs', 'signs', $svg('No entry sign', '<circle cx="50" cy="50" r="46" fill="#c8102e"/><rect x="18" y="41" width="64" height="18" rx="3" fill="#fff"/>')];
    }
    if (str_contains($n, 'signal')) {
        return ['Road signals', 'signs', $svg('Traffic light', '<rect x="32" y="4" width="36" height="92" rx="10" fill="#1d2329"/><circle cx="50" cy="24" r="10" fill="#e5394a"/><circle cx="50" cy="50" r="10" fill="#f5b400"/><circle cx="50" cy="76" r="10" fill="#22b968"/>')];
    }
    if (str_contains($n, 'sign')) {
        return ['Control signs', 'signs', $svg('Stop sign', '<path d="M30 4h40l26 26v40L70 96H30L4 70V30Z" fill="#c8102e"/><path d="M32 9h36l23 23v36L68 91H32L9 68V32Z" fill="none" stroke="#fff" stroke-width="3"/>' . $text('STOP', 27, '#fff', 60), 56)];
    }
    if (str_contains($n, 'speed')) {
        return ['Speed limits', 'rules', $svg('Speed limit 60 sign', '<circle cx="50" cy="50" r="44" fill="#fff" stroke="#c8102e" stroke-width="10"/>' . $text('60', 34, '#111', 62))];
    }
    if (str_contains($n, 'parking')) {
        return ['Parking &amp; reservation', 'rules', $svg('Parking sign', '<rect x="8" y="8" width="84" height="84" rx="10" fill="#1d5fbf"/><rect x="13" y="13" width="74" height="74" rx="7" fill="none" stroke="#fff" stroke-width="2.5"/>' . $text('P', 56, '#fff', 70), 56)];
    }
    if (str_contains($n, 'overtak') || str_contains($n, 'passing')) {
        return ['Overtaking', 'rules', $svg('No overtaking sign', '<circle cx="50" cy="50" r="44" fill="#fff" stroke="#c8102e" stroke-width="10"/><rect x="24" y="36" width="22" height="30" rx="5" fill="#c8102e"/><rect x="54" y="36" width="22" height="30" rx="5" fill="#111"/><rect x="28" y="40" width="14" height="7" rx="2" fill="#fff"/><rect x="58" y="40" width="14" height="7" rx="2" fill="#fff"/>')];
    }
    if (str_contains($n, 'vehicle') || str_contains($n, 'control')) {
        return ['Vehicle controls', 'rules', $svg('Steering wheel', '<circle cx="50" cy="50" r="38" fill="none" stroke="#1d2329" stroke-width="9"/><circle cx="50" cy="52" r="10" fill="#1d2329"/><path d="M14 46h72M50 60v28" stroke="#1d2329" stroke-width="9"/>', 56)];
    }
    if (str_contains($n, 'night')) {
        return ['Night driving', 'rules', $svg('Dipped headlight symbol', '<rect x="4" y="4" width="92" height="92" rx="18" fill="#13233a"/><path d="M40 28c-12 0-19 9-19 22s7 22 19 22c4 0 5-9 5-22s-1-22-5-22Z" fill="#7be0a0"/><path d="M55 36l25 7M55 47l25 7M55 58l25 7M55 69l25 7" stroke="#7be0a0" stroke-width="5" stroke-linecap="round"/>', 56)];
    }
    if (str_contains($n, 'legislation') || str_contains($n, 'law')) {
        return ['Road traffic law', 'rules', $svg('Licence card', '<rect x="8" y="22" width="84" height="56" rx="8" fill="#fff" stroke="#102744" stroke-width="4"/><path d="M8 30a8 8 0 0 1 8-8h68a8 8 0 0 1 8 8v6H8Z" fill="#102744"/><circle cx="28" cy="56" r="10" fill="#9fb3c8"/><path d="M46 50h32M46 60h24M46 70h28" stroke="#9fb3c8" stroke-width="5" stroke-linecap="round"/>', 62)];
    }
    if (str_contains($n, 'traffic') || str_contains($n, 'rule') || str_contains($n, 'right of way')) {
        return ['Rules of the road', 'rules', $svg('Yield sign', '<path d="M8 14h84L50 90Z" fill="#fff" stroke="#c8102e" stroke-width="10" stroke-linejoin="round"/>', 60)];
    }
    return ['Topic quiz', 'rules', $svg('Topic', '<rect x="14" y="10" width="72" height="80" rx="10" fill="#e6f1ff"/><path d="M30 34h40M30 50h40M30 66h26" stroke="#147df5" stroke-width="7" stroke-linecap="round"/>', 56)];
}
include __DIR__ . '/includes/header.php';
?>
<style>
.tq-page,.tq-page *{box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
.tq-page{width:min(1310px,calc(100% - 40px));margin:0 auto;padding:28px 0 60px;color:#122b4c}
.tq-heading{margin-bottom:22px}.tq-kicker{display:block;color:#457eb8;font-weight:800;font-size:11px;letter-spacing:.12em;text-transform:uppercase}
.tq-heading h1{font-size:clamp(28px,3vw,36px);margin:8px 0;color:#102744;font-weight:850}.tq-heading p{color:#72869a;font-size:14px;margin:0}
.tq-card{background:#fff;border:1px solid #dfebf5;border-radius:15px;padding:24px;box-shadow:0 6px 22px #1030500a}
.tq-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:17px}.tq-topic{display:flex;flex-direction:column;align-items:center;min-height:225px;text-align:center;gap:10px}
.tq-icon{display:grid;place-items:center;width:56px;height:56px;border-radius:14px;background:#e8f3ff;font-size:25px}.tq-topic h2{font-size:16px;margin:0;color:#153252}.tq-topic p{flex:1;font-size:12px;color:#718498;line-height:1.6;margin:0}
.tq-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:9px;border:1px solid #147df5;padding:9px 17px;background:#147df5;color:white!important;font-weight:800;font-size:13px;text-decoration:none;cursor:pointer}
.tq-button:hover{background:#086bd8}.tq-button.secondary{background:#fff;color:#1667b6!important;border-color:#cddded}.tq-button.danger{background:#fff4f3;color:#b74744!important;border-color:#f1cac6}
.tq-topic footer{display:grid;gap:8px;width:100%}.tq-topic footer .tq-button{width:100%}.tq-topic:nth-child(4n+2) .tq-icon{background:#e2f8ed}.tq-topic:nth-child(4n+3) .tq-icon{background:#fff2df}.tq-topic:nth-child(4n+4) .tq-icon{background:#f0e8ff}
.tq-topic:nth-child(4n+2) footer .tq-button:not(.danger){background:#09a875;border-color:#09a875}.tq-topic:nth-child(4n+3) footer .tq-button:not(.danger){background:#f09017;border-color:#f09017}.tq-topic:nth-child(4n+4) footer .tq-button:not(.danger){background:#8550df;border-color:#8550df}
.tq-toolbar{display:flex;gap:12px;margin:19px 0}.tq-toolbar input,.tq-toolbar select{height:46px;border:1px solid #dce8f4;border-radius:9px;padding:0 14px;background:white;color:#234461}.tq-toolbar input{flex:1;min-width:0}.tq-toolbar select{width:190px}
.tq-layout{display:grid;grid-template-columns:minmax(0,1fr) 285px;align-items:start;gap:22px}.tq-main{min-width:0}.tq-question{display:none;min-height:330px}.tq-question.current{display:block}
.tq-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:25px}.tq-meta strong{font-size:14px}.tq-pill{background:#eaf4ff;color:#286aac;font-size:11px;padding:6px 12px;border-radius:40px}
.tq-question-text{font-weight:800;color:#153756;font-size:17px;line-height:1.65;margin:0 0 18px}.tq-media{max-width:100%;max-height:270px;object-fit:contain;display:block;border-radius:10px;margin:0 0 18px}.tq-iframe{width:100%;height:275px;border:1px solid #dce7f2;border-radius:10px;margin-bottom:18px}
.tq-options{display:grid;gap:10px}.tq-option{display:flex;align-items:center;gap:12px;padding:15px;border:1px solid #dce7f2;border-radius:10px;cursor:pointer;font-size:14px;color:#264766}.tq-option:hover{background:#f5faff}.tq-option:has(input:checked){background:#e7f8ef;border-color:#12b981;color:#087855;font-weight:700}.tq-option input{accent-color:#12a878}.tq-letter{font-size:14px;font-weight:900;min-width:20px;color:#123d68}
.tq-navigation{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:15px 0}.tq-navigation button{border:1px solid #dbe6f3;background:#edf5ff;color:#1769b8;border-radius:9px;padding:11px 18px;font-weight:800;cursor:pointer}.tq-navigation button:disabled{opacity:.45}.tq-navigation .next{background:#147df5;color:#fff;border-color:#147df5}
.tq-submit-strip{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}.tq-submit-strip small{color:#6c8298}.tq-save-status{font-size:12px;color:#64809d}
.tq-side{position:sticky;top:90px}.tq-side h2{font-size:15px;color:#132f4d;margin:0 0 12px}.tq-timer{background:#eaf3ff;border-radius:11px;color:#147df5;text-align:center;font-size:30px;font-weight:900;padding:15px;margin-bottom:19px;font-variant-numeric:tabular-nums}.tq-timer.warning{background:#fff4e3;color:#bb7610}.tq-timer.danger{background:#ffebeb;color:#c73c42}.tq-count{font-size:12px;color:#6d8198;margin-bottom:9px}.tq-track{height:9px;border-radius:25px;background:#e6edf5;overflow:hidden;margin-bottom:20px}.tq-track div{height:100%;background:#12b77c;width:0}.tq-numbers{display:grid;grid-template-columns:repeat(5,1fr);gap:7px}.tq-numbers button{height:36px;border:1px solid #dce7f3;border-radius:8px;background:#f1f6fb;color:#53728e;font-weight:800;cursor:pointer}.tq-numbers button.current{background:#147df5;color:#fff;border-color:#147df5}.tq-numbers button.answered:not(.current){background:#dcf7e9;color:#078559;border-color:#b7e8cf}.tq-legend{font-size:11px;color:#70849a;line-height:2;margin-top:14px}
.tq-overlay{position:fixed;inset:0;z-index:1700;display:grid;place-items:center;padding:18px;background:#102744bf}.tq-overlay[hidden]{display:none!important}.tq-modal{width:min(490px,100%);padding:34px;text-align:center}.tq-modal h2{color:#132f4d;font-size:24px;margin:0 0 12px}.tq-modal p{color:#657d95;font-size:14px;line-height:1.65}.tq-modal-actions{display:flex;justify-content:center;gap:11px;flex-wrap:wrap;margin-top:22px}
.dd-board{display:grid;grid-template-columns:1fr 1fr;gap:15px}.dd-title{font-size:13px;font-weight:850;margin-bottom:11px;color:#234b6f}.dd-chip{width:100%;text-align:left;border:1px solid #c6dbf1;background:#eaf4ff;border-radius:9px;color:#255985;padding:12px;cursor:grab;margin-bottom:9px}.dd-chip.selected{outline:2px solid #147df5}.dd-chip,.dd-zone-slot{display:flex;align-items:center;gap:10px}.dd-img{width:56px;height:56px;object-fit:contain;flex:0 0 auto;background:#fff;border-radius:6px;vertical-align:middle;margin-right:6px}.dd-zone-slot .dd-img{width:44px;height:44px}.dd-chip.used{opacity:.55}.dd-zone{background:#f8fbff;border:1px solid #dce8f4;border-radius:10px;padding:12px;margin-bottom:9px}.dd-zone-label{font-size:13px;color:#42617c;margin-bottom:9px}.dd-zone-slot{min-height:43px;padding:11px;border:1px dashed #a6c5e7;background:white;color:#6687a4;border-radius:8px;cursor:pointer}.dd-zone-slot.filled{background:#e5f8ef;border:1px solid #a7e6c9;color:#087953}
.tq-result{padding:30px;border-radius:16px;text-align:center;color:white;margin-bottom:21px}.tq-result strong{display:block;font-size:45px}.tq-result h2{color:white;margin:5px}.tq-actions{display:flex;flex-wrap:wrap;gap:10px;margin:19px 0 23px}.tq-study{border-left:5px solid #147df5;background:#eff6ff;margin-bottom:24px;padding:22px 25px}.tq-study h2{margin:0 0 8px;font-size:19px;color:#113e69}.tq-study p{color:#42617d;margin:0 0 15px;line-height:1.7}.tq-study strong{color:#0c4b8d;font-weight:900;font-size:16px}
.tq-feedback-focus{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin:15px 0 20px;padding:17px 20px;border:1px solid #c9e2fb;border-left:4px solid #147df5;border-radius:11px;background:#f1f7ff}
.tq-feedback-focus-text{flex:1;min-width:190px}
.tq-feedback-caption{display:block;color:#567795;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;margin-bottom:4px}
.tq-feedback-topic{display:block;color:#102f53;font-size:18px;font-weight:900;line-height:1.4}
.tq-feedback-focus-text p{margin:5px 0 0;color:#4f6c85;font-size:12px;line-height:1.6}
.tq-feedback-link{flex:0 0 auto;min-height:41px;white-space:normal;text-align:center}
.tq-review{margin-bottom:14px}.tq-review h3{color:#163753;font-size:17px}.tq-tag{display:inline-block;border-radius:25px;padding:6px 11px;font-weight:850;font-size:12px}.tq-tag.correct{background:#e0f7eb;color:#078455}.tq-tag.wrong{background:#ffebeb;color:#b94141}.tq-review-options{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:15px 0}.tq-review-option{padding:14px;border:1px solid #e1eaf3;border-radius:10px;background:#f7faff;color:#254763;font-size:13px;line-height:1.65}.tq-review-option strong{font-size:14px;color:#153b62;font-weight:900;margin-right:5px}.tq-review-option.correct{background:#e5f8ef;border-color:#a6e5c6}.tq-review-option.wrong{background:#ffeded;border-color:#edbfc2}.tq-explanation{border-left:4px solid #147df5;background:#eef6ff;padding:16px;border-radius:8px;font-size:13px;line-height:1.7;color:#315c7f}.tq-answer-details{display:flex;gap:15px;flex-wrap:wrap;margin:12px 0;font-size:13px;color:#42617d}.tq-answer-details strong{color:#123a5d}
@media(max-width:1200px){.tq-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:1000px){.tq-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tq-layout{grid-template-columns:1fr}.tq-side{position:static}}@media(max-width:640px){.tq-page{width:calc(100% - 26px)}.tq-toolbar{flex-direction:column}.tq-toolbar select{width:100%}.tq-card{padding:19px}.tq-review-options,.dd-board{grid-template-columns:1fr}.tq-meta{flex-wrap:wrap}.tq-submit-strip{justify-content:center}}
@media(max-width:380px){.tq-grid{grid-template-columns:1fr}}
/* ===== Wrong answer: study help links ===== */
.tq-help{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-top:16px;padding-top:14px;border-top:1px dashed #dbe6f2}
.tq-help-label{font-size:13px;font-weight:700;color:#5b7189;margin-right:4px}
.tq-help-link{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 14px;border-radius:999px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid transparent}
.tq-help-link.read{background:#eaf3ff;color:#0f63c4!important;border-color:#cfe3fb}
.tq-help-link.video{background:#fff3e6;color:#b45d09!important;border-color:#fbdcb8}
.tq-help-link.sim{background:#e6f6f1;color:#0b6b4f!important;border-color:#bfe6d8}
.tq-help-link:hover{filter:brightness(.96);text-decoration:underline}
@media(max-width:560px){.tq-help-link{flex:1 1 100%;justify-content:center}}
/* ===== Topic list: compact two-column rows ===== */
.tq-list-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin:6px 0 20px}
.tq-search{display:flex;align-items:center;gap:10px;flex:1 1 280px;max-width:380px;height:46px;padding:0 15px;background:#fff;border:1px solid #dbe6f2;border-radius:12px;color:#7a8ea4}
.tq-search:focus-within{border-color:#81baff;box-shadow:0 0 0 3px rgba(20,125,245,.1)}
.tq-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:14px;color:#102744}
.tq-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:5px;background:#fff;border:1px solid #dbe6f2;border-radius:12px}
.tq-tab{height:40px;padding:0 16px;border:0;border-radius:9px;background:transparent;color:#42607d;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.tq-tab:hover{background:#f1f6fc}
.tq-tab.is-retry{color:#b42f2a}
.tq-tab[aria-pressed="true"]{background:#102744;color:#fff}
.tq-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.tq-row{display:flex;align-items:center;gap:18px;padding:18px 20px;background:#fff;border:1px solid #e2eaf3;border-radius:16px;box-shadow:0 4px 16px rgba(16,39,68,.04);transition:box-shadow .2s,transform .2s}
.tq-row:hover{box-shadow:0 10px 24px rgba(16,39,68,.09);transform:translateY(-2px)}
.tq-row[hidden]{display:none}
.tq-row-art{flex:0 0 76px;width:76px;height:76px;border-radius:18px;background:#f4f8fd;display:flex;align-items:center;justify-content:center}
.tq-row-art svg{display:block}
.tq-row-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
.tq-row-cat{font-size:11px;font-weight:800;letter-spacing:.06em;color:#0d7a57;text-transform:uppercase}
.tq-row-body h2{margin:0;font-size:18px;font-weight:800;color:#102744}
.tq-row-body p{margin:0;font-size:13px;color:#5b7189;line-height:1.5}
.tq-row-actions{flex:0 0 150px;display:flex;flex-direction:column;gap:8px}
.tq-row-start{display:flex;align-items:center;justify-content:center;height:44px;border-radius:10px;background:#147df5;color:#fff!important;font-size:14px;font-weight:700;text-decoration:none}
.tq-row-start:hover{background:#0b68d6}
.tq-row-retry{display:flex;align-items:center;justify-content:center;height:36px;border-radius:10px;color:#b42f2a!important;font-size:13px;font-weight:700;text-decoration:none}
.tq-row-retry:hover{background:#fff3f2}
.tq-row-none{display:flex;align-items:center;justify-content:center;height:36px;font-size:12px;font-weight:700;color:#7a8ea4}
.tq-empty{padding:26px;text-align:center;color:#5b7189}
@media(max-width:1100px){.tq-list{grid-template-columns:1fr}}
@media(max-width:560px){.tq-row{flex-wrap:wrap;gap:14px}.tq-row-art{flex-basis:64px;width:64px;height:64px}.tq-row-actions{flex:1 1 100%;flex-direction:row}.tq-row-actions>*{flex:1}.tq-search{max-width:none}.tq-tabs{width:100%;display:grid;grid-template-columns:1fr 1fr}.tq-tab{padding:0 10px}}
</style>
<main class="tq-page">
<header class="tq-heading"><span class="tq-kicker">TestMate Practice</span><h1><?= $result_id ? 'Quiz Results' : ($topic ? quizEsc($topic['name']) . ' Quiz' : 'Topic Quizzes') ?></h1><p>Practise one topic at a time, review feedback, and improve your road knowledge.</p></header>
<?php if ($result_id > 0): ?>
  <?php if (!$score_row): ?>
    <section class="tq-card"><h2>Result Not Found</h2><p>This result is not available for your account.</p><a class="tq-button" href="/quiz.php">All Topics</a></section>
  <?php elseif (in_array((string)($score_row['status'] ?? 'approved'), ['pending','rejected'], true)): ?>
    <section class="tq-card"><h2><?= $score_row['status'] === 'pending' ? 'Awaiting Review' : 'Result Not Approved' ?></h2><p>Your quiz has not yet been approved.</p><a class="tq-button" href="/quiz.php">All Topics</a></section>
  <?php else: ?>
    <?php $sc=(int)$score_row['score']; $total=(int)$score_row['total']; $pct=$total?round($sc/$total*100):0; $grade=$pct>=80?'#098e6f':($pct>=60?'#c37b16':'#bb3944'); ?>
    <section class="tq-result" style="background:<?= $grade ?>"><strong><?= $sc ?> / <?= $total ?></strong><h2><?= $pct ?>%</h2><p><?= $pct>=80?'Excellent work!':($pct>=60?'Good progress.':'Keep practising and reviewing your weak areas.') ?> <b><?= quizEsc($topic['name'] ?? '') ?></b></p></section>
    <nav class="tq-actions"><a class="tq-button" href="/quiz.php?topic=<?= $topic_id ?>">Try Again</a><?php if($failed_count): ?><a class="tq-button danger" href="/quiz.php?topic=<?= $topic_id ?>&amp;mode=retry">Retry Failed Questions (<?= $failed_count ?>)</a><?php endif; ?><a class="tq-button secondary" href="/quiz.php">All Topics</a><a class="tq-button secondary" href="/quiz-history.php">Quiz History</a></nav>
    <?php if($pct<80): ?><section class="tq-card tq-study"><h2>Recommended Study Topic</h2><p>Before your next attempt, focus on <strong><?= quizEsc($topic['name'] ?? 'this topic') ?></strong>. Read the lesson carefully, review road signs and explanations, then practise again.</p><a class="tq-button" href="/study-materials.php?topic=<?= $topic_id ?>&amp;mode=read">Study <?= quizEsc($topic['name'] ?? 'Topic') ?></a></section><?php endif; ?>
    <h2 style="font-size:21px;color:#173855">Your Answer Review</h2>
    <?php $quizHelpRes = quizHelpResources($conn, (int)$topic_id); $quizHelpTopic = (string)($topic['name'] ?? 'topic'); ?>
    <?php foreach($review_results as $i=>$r): $correct=!empty($r['is_correct']); $match=($r['type']??'mcq')==='dragdrop'; ?>
      <article class="tq-card tq-review">
        <span class="tq-tag <?= $correct?'correct':'wrong' ?>"><?= $correct?'Correct Answer':'Needs Improvement' ?></span>
        <?php if($match): ?>
          <h3>Matching Exercise</h3><p><b>Description:</b> <?= quizEsc($r['target']??'') ?></p>
          <div class="tq-answer-details"><span>Your match: <?php if(!empty($r['value_image'])): ?><img class="dd-img" src="<?= quizEsc(quizMedia($r['value_image'])) ?>" alt=""><?php endif; ?><strong><?= quizEsc($r['value']??'Not matched') ?></strong></span><span>Correct match: <?php if(!empty($r['correct_image'])): ?><img class="dd-img" src="<?= quizEsc(quizMedia($r['correct_image'])) ?>" alt=""><?php endif; ?><strong><?= quizEsc($r['correct_value']??'') ?></strong></span></div>
        <?php else: ?>
          <h3>Question <?= $i+1 ?></h3><p class="tq-question-text"><?= quizEsc(cleanQuestionText($r['question']??'')) ?></p>
          <?php $media=quizMedia($r['image_path']??''); $type=strtolower((string)($r['file_type']??'image')); ?>
          <?php if($media): ?>
            <?php if($type==='video'): ?><video class="tq-media" src="<?= quizEsc($media) ?>" controls></video>
            <?php elseif($type==='pdf'): ?><iframe class="tq-iframe" src="<?= quizEsc($media) ?>" title="Question document"></iframe>
            <?php else: ?><img class="tq-media" src="<?= quizEsc($media) ?>" alt="Question illustration"><?php endif; ?>
          <?php endif; ?>
          <div class="tq-review-options">
            <?php foreach(['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $letter=>$key): $right=($r['correct']??'')===$letter; $wrong=($r['user_answer']??'')===$letter&&!$right; ?>
              <div class="tq-review-option <?= $right?'correct':($wrong?'wrong':'') ?>"><strong><?= $letter ?>.</strong> <?= quizEsc($r[$key]??'') ?><?php if($right): ?> <b>(Correct)</b><?php elseif($wrong): ?> <b>(Your answer)</b><?php endif; ?></div>
            <?php endforeach; ?>
          </div>
          <div class="tq-answer-details"><span>Your answer: <strong><?= quizEsc($r['user_answer']?:'Not answered') ?></strong></span><span>Correct answer: <strong><?= quizEsc($r['correct']??'') ?></strong></span></div>
          <?php if(!empty($r['explanation'])): ?><div class="tq-explanation"><strong>Explanation</strong><p><?= quizEsc($r['explanation']) ?></p></div><?php endif; ?>
        <?php endif; ?>
        <?php if (!$correct && $topic_id): ?>
          <div class="tq-help">
            <span class="tq-help-label">Need help with this?</span>
            <?php foreach (quizHelpLinks($quizHelpRes, (int)$topic_id, $quizHelpTopic, (string)($r['question'] ?? $r['target'] ?? '')) as [$kind, $label, $href]): ?>
              <a class="tq-help-link <?= $kind ?>" href="<?= quizEsc($href) ?>"><?php if ($kind === 'read'): ?><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2zM22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg><?php elseif ($kind === 'video'): ?><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15l13-7.5z"/></svg><?php else: ?><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M3 16h18v3H3zM7 19v2M17 19v2"/><circle cx="7.5" cy="13.5" r=".5"/><circle cx="16.5" cy="13.5" r=".5"/></svg><?php endif; ?><?= quizEsc($label) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
<?php elseif ($topic && $questions): ?>
  <form id="quizForm" method="post" action="/quiz.php" autocomplete="off">
    <input type="hidden" name="quiz_action" value="finish"><input type="hidden" name="quiz_token" value="<?= quizEsc($quiz_token) ?>"><input type="hidden" name="topic_id" value="<?= $topic_id ?>">
    <div class="tq-layout"><div class="tq-main">
      <?php foreach($questions as $i=>$q): $qid=(int)$q['id']; $media=quizMedia($q['image_path']??''); $type=strtolower((string)($q['file_type']??'image')); ?>
        <article class="tq-card tq-question" data-step="<?= $i ?>"><div class="tq-meta"><strong>Question <?= $i+1 ?> of <?= count($questions) ?></strong><span class="tq-pill"><?= quizEsc($topic['name']) ?></span></div><p class="tq-question-text"><?= quizEsc(cleanQuestionText($q['question']??'')) ?></p>
          <?php if($media): ?><?php if($type==='video'): ?><video class="tq-media" src="<?= quizEsc($media) ?>" controls></video><?php elseif($type==='pdf'): ?><iframe class="tq-iframe" src="<?= quizEsc($media) ?>" title="Question document"></iframe><?php else: ?><img class="tq-media" src="<?= quizEsc($media) ?>" alt="Question illustration"><?php endif; ?><?php endif; ?>
          <input type="hidden" name="qids[]" value="<?= $qid ?>"><div class="tq-options">
            <?php foreach(['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $letter=>$column): ?><label class="tq-option"><input type="radio" name="ans_<?= $qid ?>" value="<?= $letter ?>" <?= ($draft['answers'][$qid]??'')===$letter?'checked':'' ?>><strong class="tq-letter"><?= $letter ?>.</strong><span><?= quizEsc($q[$column]??'') ?></span></label><?php endforeach; ?>
          </div></article>
      <?php endforeach; ?>
      <?php if($bonus): ?><article class="tq-card tq-question" id="matchingExercise" data-step="<?= count($questions) ?>"><div class="tq-meta"><strong>Matching Exercise</strong><span class="tq-pill"><?= count($bonus) ?> Bonus Questions</span></div><p>Match each road sign or description to its correct meaning. Select an item, then select its matching description.</p><div class="dd-board"><section><h3 class="dd-title">Available Items</h3><?php $shuffled=$bonus; shuffle($shuffled); foreach($shuffled as $p): ?><button type="button" class="dd-chip" draggable="true" data-id="<?= (int)$p['id'] ?>"><?php $ddImg = quizMedia($p['image_path'] ?? ''); if ($ddImg !== ''): ?><img class="dd-img" src="<?= quizEsc($ddImg) ?>" alt="<?= quizEsc($p['value'] ?: 'Picture') ?>" draggable="false"><?php endif; ?><span><?= quizEsc($p['value']) ?></span></button><?php endforeach; ?></section><section><h3 class="dd-title">Descriptions</h3><?php foreach($bonus as $p): $id=(int)$p['id']; ?><div class="dd-zone" data-zone="<?= $id ?>"><div class="dd-zone-label"><?= quizEsc($p['target']) ?></div><div class="dd-zone-slot" role="button" tabindex="0">Select matching item</div><input type="hidden" name="dd_zone_<?= $id ?>" value="<?= (int)($draft['matches'][$id]??0) ?>"></div><?php endforeach; ?></section></div></article><?php endif; ?>
      <div class="tq-navigation"><button type="button" id="quizPrevious">Previous</button><span id="quizPosition"></span><button type="button" class="next" id="quizNext">Next</button></div>
      <div class="tq-card tq-submit-strip"><div><strong>Finished answering?</strong><div><small>You can submit before the timer ends.</small></div><div class="tq-save-status" id="quizSaveStatus" role="status">Answers saved automatically</div></div><button class="tq-button" type="button" id="submitQuizButton">Submit Quiz</button></div>
    </div><aside class="tq-card tq-side"><h2>Time Remaining</h2><div class="tq-timer" id="quizTimer">15:00</div><h2>Quiz Progress</h2><div class="tq-count" id="quizAnswered"></div><div class="tq-track"><div id="quizProgressFill"></div></div><div class="tq-numbers" id="quizNumbers"></div><div class="tq-legend">Blue: Current question<br>Green: Answered question<br>Grey: Not answered</div><div style="margin-top:18px"><button type="button" class="tq-button" id="sideSubmit" style="width:100%">Submit Quiz</button></div></aside></div>
  </form>
  <div class="tq-overlay" id="quizStartOverlay" <?= !empty($draft['started_at'])?'hidden':'' ?>><div class="tq-card tq-modal"><h2>Ready to Start?</h2><p><strong><?= quizEsc($topic['name']) ?></strong></p><p>You have 15 minutes for <?= count($questions) ?> multiple-choice questions<?= $bonus?' and a matching exercise':'' ?>. Your answers are saved as you go, and you can resume before time expires.</p><button type="button" class="tq-button" id="startOverlayBtn">Start Quiz</button></div></div>
  <div class="tq-overlay" id="quizConfirm" hidden><div class="tq-card tq-modal" role="dialog" aria-modal="true" aria-labelledby="quizConfirmTitle"><h2 id="quizConfirmTitle">Submit Your Quiz?</h2><p id="quizConfirmMessage"></p><p><strong>Once submitted, your answers cannot be changed.</strong></p><div class="tq-modal-actions"><button type="button" class="tq-button secondary" id="cancelSubmit">Continue Quiz</button><button type="button" class="tq-button" id="confirmSubmit">Confirm Submission</button></div></div></div>
<?php elseif ($topic): ?>
  <section class="tq-card"><h2>No Questions Available</h2><p>No quiz questions are currently available for <?= quizEsc($topic['name']) ?>.</p><a class="tq-button" href="/quiz.php">Choose a Topic</a></section>
<?php else: ?>
  <div class="tq-list-head">
    <label class="tq-search" for="quizTopicSearch">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input id="quizTopicSearch" type="search" placeholder="Search quiz topics..." aria-label="Search quiz topics">
    </label>
    <div class="tq-tabs" role="group" aria-label="Filter topics">
      <button type="button" class="tq-tab" data-filter="all" aria-pressed="true">All topics</button>
      <button type="button" class="tq-tab" data-filter="signs" aria-pressed="false">Road signs</button>
      <button type="button" class="tq-tab" data-filter="rules" aria-pressed="false">Rules of the road</button>
      <button type="button" class="tq-tab is-retry" data-filter="retry" aria-pressed="false">Needs retry</button>
    </div>
  </div>
  <section class="tq-list" id="quizTopicGrid">
    <?php foreach($topics as $tp): $id=(int)$tp['id']; $failed=(int)($failed_by_topic[$id]??0); [$tpCat, $tpGroup, $tpArt] = quizTopicCard((string)$tp['name']); ?>
    <article class="tq-row" data-retry="<?= $failed?'yes':'no' ?>" data-group="<?= quizEsc($tpGroup) ?>">
      <div class="tq-row-art"><?= $tpArt ?></div>
      <div class="tq-row-body">
        <span class="tq-row-cat"><?= $tpCat ?></span>
        <h2><?= quizEsc($tp['name']) ?></h2>
        <p><?= quizEsc($tp['description']??'') ?></p>
      </div>
      <div class="tq-row-actions">
        <a class="tq-row-start" href="/quiz.php?topic=<?= $id ?>">Start Quiz</a>
        <?php if($failed): ?><a class="tq-row-retry" href="/quiz.php?topic=<?= $id ?>&amp;mode=retry">Retry <?= $failed ?> failed</a>
        <?php else: ?><span class="tq-row-none">No failed questions</span><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <p id="quizNoResults" class="tq-card tq-empty" hidden>No matching topics found.</p>
<?php endif; ?>
</main>
<?php if($topic && $questions && !$result_id): ?>
<script>
(() => {
  'use strict';
  const form = document.getElementById('quizForm');
  const cards = [...form.querySelectorAll('.tq-question')];
  const mcqCount = <?= count($questions) ?>;
  const token = <?= json_encode($quiz_token) ?>;
  const resumed = <?= !empty($draft['started_at']) ? 'true' : 'false' ?>;
  const existingDeadline = <?= (int)($draft['started_at']??0) + (!empty($draft['started_at'])?900:0) ?>;
  let deadline = existingDeadline;
  let index = <?= max(0,(int)($draft['current_step']??0)) ?>;
  let saving = Promise.resolve(), savingCount = 0, submitted = false, timerId = null;
  const timer = document.getElementById('quizTimer');
  const startOverlay = document.getElementById('quizStartOverlay');
  const confirmOverlay = document.getElementById('quizConfirm');
  const status = document.getElementById('quizSaveStatus');
  const nav = document.getElementById('quizNumbers');
  const buttons = cards.map((card, i) => {
    const b = document.createElement('button'); b.type = 'button';
    b.textContent = i < mcqCount ? i+1 : 'M';
    b.addEventListener('click', () => show(i)); nav.appendChild(b); return b;
  });
  const getData = (action) => {
    const data = new FormData(form);
    data.set('quiz_action', action);
    data.set('quiz_token', token);
    data.set('current_step', String(index));
    return data;
  };
  const saveDraft = () => {
    if (submitted || !deadline) return saving;
    const body = getData('save');
    savingCount++;
    status.textContent = 'Saving answers...';
    saving = saving.catch(() => {}).then(async () => {
      const response = await fetch('/quiz.php', {method:'POST', body, credentials:'same-origin'});
      const data = await response.json();
      if (data.expired) { setTimeout(() => finish(true), 0); return; }
      if (!response.ok || !data.ok) throw Error(data.message || 'Save failed');
      savingCount--; if (!savingCount) status.textContent = 'Answers saved automatically';
    }).catch((e) => { savingCount = 0; status.textContent = 'Connection problem: your latest answers may not be saved'; console.error(e); });
    return saving;
  };
  const choiceCount = () => [...form.querySelectorAll('.tq-question input[type="radio"]:checked')].length;
  const answered = (i) => i < mcqCount
    ? Boolean(cards[i].querySelector('input[type="radio"]:checked'))
    : [...cards[i].querySelectorAll('input[name^="dd_zone_"]')].some(x => x.value !== '0');
  const render = () => {
    cards.forEach((card,i) => card.classList.toggle('current',i===index));
    buttons.forEach((b,i) => {b.classList.toggle('current',i===index);b.classList.toggle('answered',answered(i));});
    document.getElementById('quizPrevious').disabled = index === 0;
    document.getElementById('quizNext').disabled = index === cards.length-1;
    document.getElementById('quizPosition').textContent = `${index+1} of ${cards.length}`;
    const count = choiceCount();
    document.getElementById('quizAnswered').textContent = `${count} of ${mcqCount} answered`;
    document.getElementById('quizProgressFill').style.width = `${mcqCount ? 100*count/mcqCount : 0}%`;
  };
  const show = i => {index=Math.max(0,Math.min(cards.length-1,i)); render(); saveDraft();};
  document.getElementById('quizPrevious').onclick = () => show(index-1);
  document.getElementById('quizNext').onclick = () => show(index+1);
  form.addEventListener('change', () => {render(); saveDraft();});
  const tick = () => {
    if (!deadline || submitted) return;
    const left=Math.max(0,deadline-Math.floor(Date.now()/1000));
    timer.textContent = `${String(Math.floor(left/60)).padStart(2,'0')}:${String(left%60).padStart(2,'0')}`;
    timer.classList.toggle('warning',left<=300&&left>60);timer.classList.toggle('danger',left<=60);
    if (left===0) finish(true);
  };
  const finish = async (auto=false) => {
    if (submitted) return;
    submitted = true;
    confirmOverlay.hidden = true;
    clearInterval(timerId);
    document.getElementById('confirmSubmit').disabled = true;
    document.getElementById('submitQuizButton').disabled = true;
    document.getElementById('sideSubmit').disabled = true;
    status.textContent = auto ? 'Time expired. Submitting saved answers...' : 'Submitting your quiz...';
    try { await saving; } catch (_) {}
    // Final submission reads all current fields; backend enforces the deadline.
    form.submit();
  };
  const askSubmit = () => {
    if (!deadline || submitted) return;
    const left = mcqCount-choiceCount();
    const matching = [...form.querySelectorAll('input[name^="dd_zone_"]')];
    const incomplete = matching.filter(x=>x.value==='0').length;
    document.getElementById('quizConfirmMessage').textContent = `${left} unanswered multiple-choice question(s) and ${incomplete} unmatched item(s). You can submit now, or return to the quiz.`;
    confirmOverlay.hidden = false;
  };
  document.getElementById('submitQuizButton').onclick = askSubmit;
  document.getElementById('sideSubmit').onclick = askSubmit;
  document.getElementById('cancelSubmit').onclick = () => {confirmOverlay.hidden = true;};
  document.getElementById('confirmSubmit').onclick = () => finish(false);
  form.addEventListener('submit', e => { e.preventDefault(); askSubmit(); });
  document.getElementById('startOverlayBtn').onclick = async () => {
    const body = getData('start');
    try {
      const res = await fetch('/quiz.php',{method:'POST',body,credentials:'same-origin'});
      const data = await res.json();
      if (!res.ok || !data.ok) throw Error(data.message || 'Unable to start');
      deadline = Number(data.deadline); startOverlay.hidden = true; tick(); timerId = setInterval(tick,500);
    } catch(e) { alert(e.message); }
  };
  const chips = [...document.querySelectorAll('.dd-chip')];
  const zones = [...document.querySelectorAll('.dd-zone')];
  let selected = null;
  const chip = id => chips.find(c=>c.dataset.id===String(id));
  const redrawPairs = () => {
    const used = new Set();
    zones.forEach(z=>{const id=z.querySelector('input').value;const found=chip(id); const slot=z.querySelector('.dd-zone-slot');if(found){slot.innerHTML=found.innerHTML;}else{slot.textContent='Select matching item';}slot.classList.toggle('filled',!!found);if(found)used.add(id);});
    chips.forEach(c=>c.classList.toggle('used',used.has(c.dataset.id)));
    render();
  };
  const place = (c,z) => {
    if(!c || !z) return;
    const id=c.dataset.id;
    zones.forEach(x=>{const input=x.querySelector('input');if(input.value===id)input.value='0';});
    z.querySelector('input').value=id;
    selected=null;chips.forEach(c=>c.classList.remove('selected'));
    redrawPairs();saveDraft();
  };
  chips.forEach(c=>{
    c.onclick=()=>{selected=c;chips.forEach(x=>x.classList.toggle('selected',x===c));};
    c.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',c.dataset.id));
  });
  zones.forEach(z=>{
    const slot=z.querySelector('.dd-zone-slot');
    const apply=()=>{if(selected)place(selected,z);else if(z.querySelector('input').value!=='0'){z.querySelector('input').value='0';redrawPairs();saveDraft();}};
    slot.addEventListener('click',apply);
    slot.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();apply();}});
    z.addEventListener('dragover',e=>e.preventDefault());
    z.addEventListener('drop',e=>{e.preventDefault();place(chip(e.dataTransfer.getData('text/plain')),z);});
  });
  index=Math.max(0,Math.min(cards.length-1,index));redrawPairs();render();
  if (resumed) {startOverlay.hidden = true;tick();timerId=setInterval(tick,500);}
  else timer.textContent='15:00';
})();
</script>
<?php else: ?>
<script>
(() => {
  const search = document.getElementById('quizTopicSearch');
  const tabs = [...document.querySelectorAll('.tq-tab')];
  const rows = [...document.querySelectorAll('#quizTopicGrid .tq-row')];
  const empty = document.getElementById('quizNoResults');
  if (!search || !rows.length) return;
  let filter = 'all';
  const apply = () => {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(r => {
      const okText = r.textContent.toLowerCase().includes(q);
      const okFilter = filter === 'all'
        || (filter === 'retry' && r.dataset.retry === 'yes')
        || r.dataset.group === filter;
      r.hidden = !(okText && okFilter);
      if (!r.hidden) shown++;
    });
    empty.hidden = shown !== 0;
  };
  tabs.forEach(t => t.addEventListener('click', () => {
    filter = t.dataset.filter;
    tabs.forEach(x => x.setAttribute('aria-pressed', String(x === t)));
    apply();
  }));
  search.addEventListener('input', apply);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>