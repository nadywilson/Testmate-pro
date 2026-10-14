<?php
/**
 * TestMate – Administrator: Add / Edit Question
 * PHP 8.5 + PDO SQLite
 *
 * /admin/add-question.php            add a new question
 * /admin/add-question.php?edit=ID    edit an existing question
 *
 * Media: optional image (jpg, png, gif, webp), video (mp4, webm) or PDF,
 * saved to /uploads/questions/. file_type is set from the uploaded file.
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
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function questionEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
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
/** Delete an uploaded question file if no other question uses it. */
function questionRemoveFile(PDO $conn, string $stored): void
{
    $url = questionMedia($stored);
    if (!str_starts_with($url, '/uploads/questions/')) return;
    $count = $conn->prepare('SELECT COUNT(*) FROM questions WHERE image_path = ? OR image_path = ?');
    $count->execute([$stored, '/testmate' . $url]);
    if ((int)$count->fetchColumn() > 0) return;
    $file = dirname(__DIR__) . $url;
    if (is_file($file)) @unlink($file);
}

const QUESTION_UPLOAD_TYPES = [
    'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
    'mp4' => 'video', 'webm' => 'video', 'pdf' => 'pdf',
];

$editId = max(0, (int)($_GET['edit'] ?? $_POST['id'] ?? 0));
$errors = [];
$topics = $conn->query('SELECT id, name FROM topics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$q = ['id' => 0, 'topic_id' => (int)($_GET['topic'] ?? 0), 'question' => '', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
      'correct_answer' => 'A', 'explanation' => '', 'image_path' => '', 'file_type' => 'image', 'licence_code' => 'Code2', 'quiz_set' => 1];
if ($editId) {
    $st = $conn->prepare('SELECT * FROM questions WHERE id = ?');
    $st->execute([$editId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('Location: /admin/questions.php');
        exit;
    }
    $q = array_merge($q, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    foreach (['question', 'option_a', 'option_b', 'option_c', 'option_d', 'explanation'] as $f) {
        $q[$f] = trim((string)($_POST[$f] ?? ''));
    }
    $q['topic_id'] = (int)($_POST['topic_id'] ?? 0);
    $q['correct_answer'] = strtoupper(trim((string)($_POST['correct_answer'] ?? '')));
    $q['quiz_set'] = max(1, (int)($_POST['quiz_set'] ?? 1));
    $oldPath = (string)($q['image_path'] ?? '');

    $topicIds = array_map('intval', array_column($topics, 'id'));
    if (!in_array($q['topic_id'], $topicIds, true)) $errors[] = 'Choose a topic.';
    if ($q['question'] === '') $errors[] = 'Enter the question text.';
    if ($q['option_a'] === '' || $q['option_b'] === '') $errors[] = 'Enter at least options A and B.';
    if (!in_array($q['correct_answer'], ['A', 'B', 'C', 'D'], true)) $errors[] = 'Choose the correct answer.';
    elseif ($q['option_' . strtolower($q['correct_answer'])] === '') $errors[] = 'The correct answer must not be an empty option.';

    // Media: keep, remove or replace
    $newPath = $oldPath;
    $newType = (string)($q['file_type'] ?: 'image');
    $removeMedia = !empty($_POST['remove_media']);
    if ($removeMedia) { $newPath = ''; $newType = 'image'; }
    $upload = $_FILES['media'] ?? null;
    if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'The file is larger than the upload limit (' . ini_get('upload_max_filesize') . '). Raise upload_max_filesize and post_max_size in php.ini or use a smaller file.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $errors[] = 'The file could not be uploaded. Please try again.';
        } else {
            $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            if (!isset(QUESTION_UPLOAD_TYPES[$ext])) {
                $errors[] = 'Only images (jpg, png, gif, webp), videos (mp4, webm) and PDF files are allowed.';
            } elseif (!$errors) {
                $dir = dirname(__DIR__) . '/uploads/questions';
                if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                    $errors[] = 'Could not create the uploads/questions folder.';
                } else {
                    $name = 'q_' . time() . '_' . random_int(100, 999) . '.' . $ext;
                    if (move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) {
                        $newPath = '/uploads/questions/' . $name;
                        $newType = QUESTION_UPLOAD_TYPES[$ext];
                    } else {
                        $errors[] = 'Could not save the uploaded file.';
                    }
                }
            }
        }
    }

    if (!$errors) {
        try {
            $conn->exec('BEGIN IMMEDIATE');
            if ($editId) {
                $conn->prepare('UPDATE questions SET topic_id = ?, question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ?, explanation = ?, image_path = ?, file_type = ?, quiz_set = ? WHERE id = ?')
                     ->execute([$q['topic_id'], $q['question'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['correct_answer'], $q['explanation'], $newPath === '' ? null : $newPath, $newType, $q['quiz_set'], $editId]);
            } else {
                $editId = (int)$conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM questions')->fetchColumn();
                $conn->prepare('INSERT INTO questions (id, topic_id, question, option_a, option_b, option_c, option_d, correct_answer, explanation, image_path, licence_code, file_type, quiz_set) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                     ->execute([$editId, $q['topic_id'], $q['question'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['correct_answer'], $q['explanation'], $newPath === '' ? null : $newPath, $q['licence_code'] ?: 'Code2', $newType, $q['quiz_set']]);
            }
            $conn->exec('COMMIT');
            if ($oldPath !== '' && $oldPath !== $newPath) questionRemoveFile($conn, $oldPath);
            header('Location: /admin/questions.php?msg=saved&topic=' . $q['topic_id']);
            exit;
        } catch (Throwable $e) {
            try { $conn->exec('ROLLBACK'); } catch (Throwable) {}
            if ($newPath !== $oldPath && $newPath !== '') questionRemoveFile($conn, $newPath);
            error_log('[TestMate Add Question] ' . $e->getMessage());
            $errors[] = 'The question could not be saved. Check the PHP terminal for the database error.';
        }
    } elseif ($newPath !== $oldPath && $newPath !== '') {
        questionRemoveFile($conn, $newPath); // discard a file uploaded with an invalid form
    }
}

$media = questionMedia($q['image_path'] ?? '');
$mediaType = strtolower((string)($q['file_type'] ?? 'image'));
if ($media !== '' && preg_match('/\.(mp4|webm|ogg|mov)$/i', $media)) $mediaType = 'video';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editId ? 'Edit Question' : 'Add Question' ?> – TestMate Admin</title>
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
<style>
.form-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:24px;max-width:980px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.field.full{grid-column:1/-1}
.field label{font-size:13px;font-weight:700;color:#44576b}
.field small{color:#7a8794;font-size:12px}
.field input[type=text],.field input[type=number],.field select,.field textarea{padding:10px 12px;border:1px solid #d5dde6;border-radius:8px;font:inherit;font-size:14px;background:#fff;width:100%}
.field textarea{min-height:90px;resize:vertical}
.opt{display:flex;align-items:center;gap:10px}
.opt b{flex:0 0 28px;height:28px;border-radius:50%;background:#eef2f7;display:grid;place-items:center}
.opt.correct b{background:#1e9e5a;color:#fff}
.correct-row{display:flex;gap:8px;flex-wrap:wrap}
.correct-row label{display:flex;align-items:center;gap:6px;padding:9px 14px;border:1px solid #d5dde6;border-radius:8px;cursor:pointer;font-weight:700}
.correct-row label:has(input:checked){background:#e8f8ef;border-color:#1e9e5a;color:#1e7c4a}
.media-box{border:1px dashed #c7d3df;border-radius:10px;padding:14px;background:#fafcff}
.media-box img,.media-box video{max-width:320px;max-height:200px;display:block;border-radius:8px;margin-bottom:10px;background:#fff}
.form-actions{display:flex;gap:10px;margin-top:6px}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}}
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
        <a href="/admin/questions.php">All Questions</a>
        <a href="/admin/add-question.php" class="active">Add Question</a>
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
                <h1><?= $editId ? 'Edit Question #' . (int)$editId : 'Add Question' ?></h1>
                <p><a href="/admin/questions.php" style="color:#3498db;text-decoration:none">&larr; Back to all questions</a></p>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="notice error"><?php foreach ($errors as $e): ?><div><?= questionEsc($e) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form class="form-card" method="post" enctype="multipart/form-data" action="/admin/add-question.php<?= $editId ? '?edit=' . (int)$editId : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= questionEsc($_SESSION['admin_csrf']) ?>">
            <?php if ($editId): ?><input type="hidden" name="id" value="<?= (int)$editId ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="field">
                    <label for="topic_id">Topic</label>
                    <select id="topic_id" name="topic_id" required>
                        <option value="">Choose a topic</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$q['topic_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= questionEsc($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="quiz_set">Question set</label>
                    <input id="quiz_set" type="number" name="quiz_set" min="1" value="<?= (int)$q['quiz_set'] ?>">
                    <small>Leave as 1 unless you group questions into sets.</small>
                </div>
                <div class="field full">
                    <label for="question">Question</label>
                    <textarea id="question" name="question" required><?= questionEsc($q['question']) ?></textarea>
                </div>
                <?php foreach (['A', 'B', 'C', 'D'] as $L): $k = 'option_' . strtolower($L); ?>
                    <div class="field">
                        <label for="<?= $k ?>">Option <?= $L ?><?= $L >= 'C' ? ' (optional)' : '' ?></label>
                        <div class="opt<?= $q['correct_answer'] === $L ? ' correct' : '' ?>" data-letter="<?= $L ?>"><b><?= $L ?></b><input id="<?= $k ?>" type="text" name="<?= $k ?>" value="<?= questionEsc($q[$k]) ?>" <?= $L <= 'B' ? 'required' : '' ?>></div>
                    </div>
                <?php endforeach; ?>
                <div class="field full">
                    <label>Correct answer</label>
                    <div class="correct-row">
                        <?php foreach (['A', 'B', 'C', 'D'] as $L): ?>
                            <label><input type="radio" name="correct_answer" value="<?= $L ?>" <?= $q['correct_answer'] === $L ? 'checked' : '' ?>> <?= $L ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field full">
                    <label for="explanation">Explanation (shown to learners after they answer)</label>
                    <textarea id="explanation" name="explanation"><?= questionEsc($q['explanation']) ?></textarea>
                </div>
                <div class="field full">
                    <label for="media">Picture, video or PDF (optional)</label>
                    <div class="media-box">
                        <?php if ($media !== ''): ?>
                            <?php if ($mediaType === 'video'): ?><video src="<?= questionEsc($media) ?>" controls preload="metadata"></video>
                            <?php elseif ($mediaType === 'pdf'): ?><p><a href="<?= questionEsc($media) ?>" target="_blank" rel="noopener">Open current PDF</a></p>
                            <?php else: ?><img src="<?= questionEsc($media) ?>" alt="Current question picture"><?php endif; ?>
                            <label style="display:flex;gap:6px;align-items:center;font-weight:400;margin-bottom:10px"><input type="checkbox" name="remove_media" value="1"> Remove the current file</label>
                        <?php endif; ?>
                        <input id="media" type="file" name="media" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf">
                        <small style="display:block;margin-top:6px">Upload a new file to <?= $media !== '' ? 'replace the current one' : 'add one' ?>. Maximum size: <?= questionEsc(ini_get('upload_max_filesize')) ?>.</small>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="primary-button" type="submit"><?= $editId ? 'Save Changes' : 'Add Question' ?></button>
                <a class="secondary-button" href="/admin/questions.php">Cancel</a>
            </div>
        </form>
    </main>
</div>
<script>
document.querySelectorAll('input[name="correct_answer"]').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.opt').forEach(function (o) { o.classList.toggle('correct', o.dataset.letter === r.value); });
    });
});
</script>
</body>
</html>