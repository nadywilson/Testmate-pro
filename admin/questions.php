<?php
/**
 * TestMate – Administrator: All Questions
 * PHP 8.5 + PDO SQLite (works with or without the mbstring extension)
 *
 * Tabs: All | Text only | With image | With video | Drag & drop
 * Filters: topic and search. Delete uses a CSRF token.
 */
require_once __DIR__ . '/../includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id']) || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: /login.php');
    exit;
}
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function questionEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Shorten text safely, with or without mbstring. */
function questionShort(string $text, int $max = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= $max) return $text;
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max - 1, 'UTF-8') : substr($text, 0, $max - 1);
    return rtrim($cut) . '…';
}

function questionMedia($path): string
{
    $path = trim(str_replace('\\', '/', (string)($path ?? '')));
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    $path = preg_replace('~^/?testmate/~i', '', $path);
    while (str_starts_with($path, './')) $path = substr($path, 2);
    if (in_array('..', explode('/', $path), true)) return '';
    return '/' . ltrim($path, '/');
}

/** text | image | video, from file_type and the file extension. */
function questionKind(array $q): string
{
    $path = strtolower(trim((string)($q['image_path'] ?? '')));
    if ($path === '') return 'text';
    $type = strtolower(trim((string)($q['file_type'] ?? '')));
    if ($type === 'video' || preg_match('/\.(mp4|webm|ogg|mov)$/', $path)) return 'video';
    if ($type === 'pdf' || str_ends_with($path, '.pdf')) return 'pdf';
    return 'image';
}

const QUESTION_TABS = [
    'all'      => 'All questions',
    'text'     => 'Text only',
    'image'    => 'With image',
    'video'    => 'With video',
    'dragdrop' => 'Drag & drop',
];

$error = '';
$message = '';

// Delete a question or a drag-and-drop pair
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['item_id'] ?? $_POST['question_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $back = '/admin/questions.php?' . http_build_query(array_filter([
        'tab' => (string)($_POST['tab'] ?? ''), 'topic' => (int)($_POST['topic'] ?? 0) ?: null, 'q' => (string)($_POST['q'] ?? ''),
    ]));
    if (!$id) {
        $error = 'Invalid item.';
    } else {
        try {
            if ($action === 'delete') {
                $conn->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
                header('Location: ' . $back . '&msg=deleted'); exit;
            }
            if ($action === 'delete_pair') {
                $conn->prepare('DELETE FROM dragdrop_pairs WHERE id = ?')->execute([$id]);
                header('Location: ' . $back . '&msg=pair_deleted'); exit;
            }
        } catch (PDOException $e) {
            error_log('Question deletion error: ' . $e->getMessage());
            $error = 'Could not delete the item. It may be referenced by existing learner records.';
        }
    }
}

$tab = (string)($_GET['tab'] ?? 'all');
if (!isset(QUESTION_TABS[$tab])) $tab = 'all';
$topicFilter = max(0, (int)($_GET['topic'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$searchLower = strtolower($search);

$questions = []; $pairs = []; $topics = [];
$counts = array_fill_keys(array_keys(QUESTION_TABS), 0);

try {
    $topics = $conn->query('SELECT id, name FROM topics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    $sql = 'SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON q.topic_id = t.id';
    $params = [];
    if ($topicFilter) { $sql .= ' WHERE q.topic_id = ?'; $params[] = $topicFilter; }
    $stmt = $conn->prepare($sql . ' ORDER BY q.id DESC');
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
        if ($searchLower !== '' && !str_contains(strtolower($q['question'] . ' ' . $q['explanation'] . ' ' . $q['id']), $searchLower)) continue;
        $q['_kind'] = questionKind($q);
        $counts['all']++;
        $countKey = $q['_kind'] === 'pdf' ? 'image' : $q['_kind'];
        $counts[$countKey]++;
        if ($tab === 'all' || $tab === $countKey) $questions[] = $q;
    }

    $sql = 'SELECT p.*, t.name AS topic_name FROM dragdrop_pairs p LEFT JOIN topics t ON p.topic_id = t.id';
    $params = [];
    if ($topicFilter) { $sql .= ' WHERE p.topic_id = ?'; $params[] = $topicFilter; }
    $stmt = $conn->prepare($sql . ' ORDER BY p.topic_id, p.id');
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ($searchLower !== '' && !str_contains(strtolower($p['value'] . ' ' . $p['target']), $searchLower)) continue;
        $pairs[] = $p;
    }
    $counts['dragdrop'] = count($pairs);
} catch (PDOException $e) {
    error_log('Questions page error: ' . $e->getMessage());
    $error = 'Unable to load questions from the database.';
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'deleted') $message = 'Question deleted successfully.';
elseif ($msg === 'pair_deleted') $message = 'Drag-and-drop pair deleted successfully.';
elseif ($msg === 'saved') $message = 'Question saved successfully.';

$tabUrl = static fn(string $t): string => '/admin/questions.php?' . http_build_query(array_filter(['tab' => $t === 'all' ? null : $t, 'topic' => $topicFilter ?: null, 'q' => $search !== '' ? $search : null]));
$topicName = '';
foreach ($topics as $t) if ((int)$t['id'] === $topicFilter) $topicName = $t['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Questions – TestMate Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f6fa;color:#2c3e50}
.navbar{min-height:60px;background:#2c3e50;display:flex;align-items:center;justify-content:space-between;padding:12px 24px;gap:15px}
.brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-size:18px;font-weight:700}
.brand-icon{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:8px;background:#3498db;color:#fff}
.nav-links{display:flex;gap:20px;align-items:center}
.nav-links a{color:#fff;text-decoration:none;font-size:14px}
.btn-logout{padding:9px 15px;background:#e74c3c;border-radius:6px}
.admin-wrap{display:flex;min-height:calc(100vh - 60px)}
.sidebar{width:230px;background:#1a252f;color:#fff;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:calc(100vh - 60px);overflow-y:auto}
.sidebar h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);padding:0 20px;margin:20px 0 8px}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.8);text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:#fff}
.main-content{flex:1;min-width:0;padding:30px;background:#f5f6fa}
.page-heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px}
.page-heading h1{font-size:22px;margin:0}
.page-heading p{margin:4px 0 0;color:#7a8794;font-size:13px}
.page-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.primary-button{display:inline-block;background:#3498db;color:#fff;text-decoration:none;padding:10px 16px;border-radius:7px;font-size:14px;font-weight:700;border:0;cursor:pointer}
.primary-button:hover{background:#247db7}
.secondary-button{display:inline-block;background:#fff;color:#2c3e50;text-decoration:none;padding:10px 16px;border-radius:7px;font-size:14px;font-weight:700;border:1px solid #d5dde6}
.qtabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.qtab{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;background:#fff;border:1px solid #dfe5ec;color:#44576b;text-decoration:none;font-size:14px;font-weight:700}
.qtab:hover{border-color:#9cc7ef}
.qtab.active{background:#2c3e50;border-color:#2c3e50;color:#fff}
.qtab .n{min-width:26px;padding:2px 8px;border-radius:999px;background:#eef2f7;color:#44576b;font-size:12px;text-align:center}
.qtab.active .n{background:rgba(255,255,255,.18);color:#fff}
.qtab svg{flex:0 0 auto}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.filters select,.filters input{padding:9px 12px;border:1px solid #d5dde6;border-radius:8px;font-size:14px;background:#fff}
.filters input{flex:1 1 240px;min-width:0}
.notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
.notice.success{background:#e8f8ef;color:#1e7c4a;border:1px solid #bfe8d0}
.notice.error{background:#fdecec;color:#b42f2a;border:1px solid #f5c8c6}
.table-wrap{background:#fff;border-radius:10px;overflow-x:auto;box-shadow:0 2px 8px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{background:#eef2f7;padding:12px 14px;text-align:left;color:#44576b;font-size:13px;white-space:nowrap}
td{padding:12px 14px;border-top:1px solid #eef1f5;vertical-align:middle}
tr:hover td{background:#fafcff}
.qid{color:#8a96a3;font-size:13px}
.topic{font-size:13px;color:#44576b}
.qtext{max-width:420px;line-height:1.45}
.media{width:84px;height:56px;border-radius:6px;overflow:hidden;background:#eef2f7;display:grid;place-items:center;color:#6b7c8d;font-size:11px;font-weight:700}
.media img{width:100%;height:100%;object-fit:contain;background:#fff}
.type{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
.type.text{background:#eef2f7;color:#44576b}
.type.image{background:#e6f1ff;color:#1667b6}
.type.video{background:#fff1e6;color:#b45d09}
.type.pdf{background:#f3eaff;color:#6b3fb8}
.badge{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#e8f8ef;color:#1e7c4a;font-weight:700}
.edit-link{color:#3498db;text-decoration:none;font-weight:700;font-size:13px;margin-right:10px}
.delete-button{background:none;border:0;color:#e74c3c;font-weight:700;font-size:13px;cursor:pointer;padding:0}
.empty{text-align:center;color:#888;padding:28px}
.pair-arrow{color:#9aa7b4;font-weight:700;text-align:center}
.tab-help{margin:0 0 12px;color:#6b7c8d;font-size:13px}
@media(max-width:900px){.admin-wrap{display:block}.sidebar{width:100%;height:auto;position:static;padding:10px 0}.sidebar a{display:inline-block;padding:8px 12px}.sidebar h3{display:none}.main-content{padding:18px}}
</style>
</head>
<body>
<nav class="navbar">
    <a href="/admin/index.php" class="brand"><span class="brand-icon">T</span> TestMate Admin</a>
    <div class="nav-links">
        <a href="/index.php">View Site</a>
        <a href="/logout.php" class="btn-logout">Logout</a>
    </div>
</nav>

<div class="admin-wrap">
    <aside class="sidebar">
        <h3>Main</h3>
        <a href="/admin/index.php">Dashboard</a>
        <a href="/admin/review-scores.php">Review Scores</a>
        <a href="/admin/users.php">Users</a>
        <a href="/admin/add-user.php">Add User</a>
        <a href="/admin/stats.php">Statistics</a>
        <h3>Questions</h3>
        <a href="/admin/questions.php" class="active">All Questions</a>
        <a href="/admin/add-question.php">Add Question</a>
        <a href="/admin/dragdrop_pairs.php">Drag &amp; Drop</a>
        <a href="/admin/assign-quiz.php">Assign Quiz</a>
        <h3>Content</h3>
        <a href="/admin/videos.php">Videos</a>
        <a href="/admin/simulations.php">Simulations</a>
        <a href="/admin/materials.php">Materials</a>
        <a href="/admin/add-material.php">Add Material</a>
    </aside>

    <main class="main-content">
        <div class="page-heading">
            <div>
                <h1>Questions</h1>
                <p><?= $counts['all'] ?> multiple-choice questions and <?= $counts['dragdrop'] ?> drag-and-drop pairs<?= $topicName !== '' ? ' in ' . questionEsc($topicName) : '' ?><?= $search !== '' ? ' matching “' . questionEsc($search) . '”' : '' ?></p>
            </div>
            <div class="page-actions">
                <?php if ($tab === 'dragdrop'): ?>
                    <a href="/admin/dragdrop_pairs.php" class="primary-button">Add Drag &amp; Drop Pair</a>
                <?php else: ?>
                    <a href="/admin/add-question.php" class="primary-button">Add Question</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message !== ''): ?><div class="notice success"><?= questionEsc($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice error"><?= questionEsc($error) ?></div><?php endif; ?>

        <nav class="qtabs" aria-label="Question types">
            <?php
            $icons = [
                'all' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
                'text' => '<path d="M5 6h14M5 10h14M5 14h10M5 18h7"/>',
                'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/>',
                'video' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
                'dragdrop' => '<path d="M9 4H5a1 1 0 0 0-1 1v4M15 4h4a1 1 0 0 1 1 1v4M9 20H5a1 1 0 0 1-1-1v-4M15 20h4a1 1 0 0 0 1-1v-4M8 12h8M13 9l3 3-3 3"/>',
            ];
            foreach (QUESTION_TABS as $key => $label): ?>
                <a class="qtab<?= $tab === $key ? ' active' : '' ?>" href="<?= questionEsc($tabUrl($key)) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$key] ?></svg>
                    <?= questionEsc($label) ?> <span class="n"><?= $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form class="filters" method="get" action="/admin/questions.php">
            <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= questionEsc($tab) ?>"><?php endif; ?>
            <select name="topic" aria-label="Filter by topic" onchange="this.form.submit()">
                <option value="0">All topics</option>
                <?php foreach ($topics as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $topicFilter === (int)$t['id'] ? 'selected' : '' ?>><?= questionEsc($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="q" value="<?= questionEsc($search) ?>" placeholder="Search question text, explanation or ID..." aria-label="Search questions">
            <button class="primary-button" type="submit">Search</button>
            <?php if ($search !== '' || $topicFilter): ?><a class="secondary-button" href="<?= questionEsc('/admin/questions.php' . ($tab !== 'all' ? '?tab=' . $tab : '')) ?>">Clear</a><?php endif; ?>
        </form>

        <?php if ($tab === 'dragdrop'): ?>
            <p class="tab-help">Drag-and-drop pairs are used in the matching exercise at the end of each topic quiz. Learners match each item to its meaning.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Topic</th><th>Item (dragged)</th><th></th><th>Matches with</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$pairs): ?>
                        <tr><td colspan="6" class="empty">No drag-and-drop pairs found.</td></tr>
                    <?php else: foreach ($pairs as $p): ?>
                        <tr>
                            <td class="qid"><?= (int)$p['id'] ?></td>
                            <td class="topic"><?= questionEsc($p['topic_name'] ?? 'Uncategorised') ?></td>
                            <td class="qtext"><?php $pi = questionMedia($p['image_path'] ?? ''); if ($pi !== ''): ?><img src="<?= questionEsc($pi) ?>" alt="" style="width:44px;height:44px;object-fit:contain;vertical-align:middle;margin-right:10px;background:#fff;border:1px solid #e1e8ef;border-radius:6px"><?php endif; ?><strong><?= questionEsc($p['value']) ?></strong></td>
                            <td class="pair-arrow">&rarr;</td>
                            <td class="qtext"><?= questionEsc($p['target']) ?></td>
                            <td style="white-space:nowrap">
                                <a class="edit-link" href="/admin/dragdrop_pairs.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                                <form method="POST" action="/admin/questions.php" style="display:inline" onsubmit="return confirm('Delete this drag-and-drop pair?');">
                                    <input type="hidden" name="csrf_token" value="<?= questionEsc($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="delete_pair">
                                    <input type="hidden" name="item_id" value="<?= (int)$p['id'] ?>">
                                    <input type="hidden" name="tab" value="dragdrop">
                                    <input type="hidden" name="topic" value="<?= $topicFilter ?>">
                                    <input type="hidden" name="q" value="<?= questionEsc($search) ?>">
                                    <button type="submit" class="delete-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Type</th><th>Media</th><th>Topic</th><th>Question</th><th>Answer</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$questions): ?>
                        <tr><td colspan="7" class="empty">No questions found.</td></tr>
                    <?php else: foreach ($questions as $q): $kind = $q['_kind']; $media = questionMedia($q['image_path'] ?? ''); ?>
                        <tr>
                            <td class="qid"><?= (int)$q['id'] ?></td>
                            <td><span class="type <?= $kind ?>"><?= ['text' => 'Text', 'image' => 'Image', 'video' => 'Video', 'pdf' => 'PDF'][$kind] ?></span></td>
                            <td>
                                <?php if ($kind === 'image' && $media !== ''): ?>
                                    <a class="media" href="<?= questionEsc($media) ?>" target="_blank" rel="noopener"><img src="<?= questionEsc($media) ?>" alt="" loading="lazy"></a>
                                <?php elseif ($kind === 'video'): ?>
                                    <a class="media" href="<?= questionEsc($media) ?>" target="_blank" rel="noopener" style="text-decoration:none">&#9654; Video</a>
                                <?php elseif ($kind === 'pdf'): ?>
                                    <a class="media" href="<?= questionEsc($media) ?>" target="_blank" rel="noopener" style="text-decoration:none">PDF</a>
                                <?php else: ?>
                                    <span class="media">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="topic"><?= questionEsc($q['topic_name'] ?? 'Uncategorised') ?></td>
                            <td class="qtext" title="<?= questionEsc($q['question']) ?>"><?= questionEsc(questionShort((string)$q['question'])) ?></td>
                            <td><span class="badge"><?= questionEsc($q['correct_answer'] ?? '') ?></span></td>
                            <td style="white-space:nowrap">
                                <a class="edit-link" href="/admin/add-question.php?edit=<?= (int)$q['id'] ?>">Edit</a>
                                <form method="POST" action="/admin/questions.php" style="display:inline" onsubmit="return confirm('Permanently delete this question? Existing learning records may reference it.');">
                                    <input type="hidden" name="csrf_token" value="<?= questionEsc($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="item_id" value="<?= (int)$q['id'] ?>">
                                    <input type="hidden" name="tab" value="<?= $tab === 'all' ? '' : questionEsc($tab) ?>">
                                    <input type="hidden" name="topic" value="<?= $topicFilter ?>">
                                    <input type="hidden" name="q" value="<?= questionEsc($search) ?>">
                                    <button type="submit" class="delete-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>