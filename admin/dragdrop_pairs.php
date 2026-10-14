<?php
/**
 * TestMate – Administrator: Drag & Drop Pairs
 * PHP 8.5 + PDO SQLite
 *
 * Pairs are used in the matching exercise at the end of each topic quiz:
 * the learner matches each item (value) to its meaning (target).
 * /admin/dragdrop_pairs.php?edit=ID   edit a pair
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

function ddMedia($path): string
{
    $path = trim(str_replace('\\', '/', (string)($path ?? '')));
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    $path = preg_replace('~^/?testmate/~i', '', $path);
    if (in_array('..', explode('/', $path), true)) return '';
    return '/' . ltrim($path, '/');
}
function ddRemoveFile(PDO $conn, string $stored): void
{
    $url = ddMedia($stored);
    if (!str_starts_with($url, '/uploads/dragdrop/')) return;
    $st = $conn->prepare('SELECT COUNT(*) FROM dragdrop_pairs WHERE image_path = ?');
    $st->execute([$stored]);
    if ((int)$st->fetchColumn() > 0) return;
    $file = dirname(__DIR__) . $url;
    if (is_file($file)) @unlink($file);
}
const DD_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

// One-time upgrade: add the image column to dragdrop_pairs if it is missing.
$ddCols = array_column($conn->query('PRAGMA table_info(dragdrop_pairs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('image_path', $ddCols, true)) {
    $conn->exec('ALTER TABLE dragdrop_pairs ADD COLUMN image_path TEXT DEFAULT NULL');
}

$errors = [];
$message = '';
$topics = $conn->query('SELECT id, name FROM topics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$topicIds = array_map('intval', array_column($topics, 'id'));
$topicFilter = max(0, (int)($_GET['topic'] ?? 0));
$editId = max(0, (int)($_GET['edit'] ?? 0));
$form = ['id' => 0, 'topic_id' => $topicFilter, 'value' => '', 'target' => '', 'image_path' => ''];

if ($editId) {
    $st = $conn->prepare('SELECT * FROM dragdrop_pairs WHERE id = ?');
    $st->execute([$editId]);
    $form = $st->fetch(PDO::FETCH_ASSOC) ?: $form;
    if (!(int)$form['id']) $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $id = max(0, (int)($_POST['id'] ?? 0));
    try {
        if ($action === 'delete' && $id) {
            $old = $conn->prepare('SELECT image_path FROM dragdrop_pairs WHERE id = ?');
            $old->execute([$id]);
            $oldImage = (string)$old->fetchColumn();
            $conn->prepare('DELETE FROM dragdrop_pairs WHERE id = ?')->execute([$id]);
            if ($oldImage !== '') ddRemoveFile($conn, $oldImage);
            header('Location: /admin/dragdrop_pairs.php?msg=deleted' . ($topicFilter ? '&topic=' . $topicFilter : ''));
            exit;
        }
        $oldImage = '';
        if ($id) {
            $old = $conn->prepare('SELECT image_path FROM dragdrop_pairs WHERE id = ?');
            $old->execute([$id]);
            $oldImage = (string)$old->fetchColumn();
        }
        $form = ['id' => $id, 'topic_id' => (int)($_POST['topic_id'] ?? 0), 'value' => trim((string)($_POST['value'] ?? '')), 'target' => trim((string)($_POST['target'] ?? '')), 'image_path' => $oldImage];
        $newImage = !empty($_POST['remove_image']) ? '' : $oldImage;
        $upload = $_FILES['image'] ?? null;
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'The picture is larger than the upload limit (' . ini_get('upload_max_filesize') . ').';
            } elseif ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                $errors[] = 'The picture could not be uploaded. Please try again.';
            } elseif (!in_array($ext, DD_IMAGE_TYPES, true)) {
                $errors[] = 'Only pictures (jpg, png, gif, webp, svg) are allowed.';
            } else {
                $dir = dirname(__DIR__) . '/uploads/dragdrop';
                if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                    $errors[] = 'Could not create the uploads/dragdrop folder.';
                } else {
                    $name = 'm_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) $newImage = '/uploads/dragdrop/' . $name;
                    else $errors[] = 'Could not save the uploaded picture.';
                }
            }
        }
        $form['image_path'] = $newImage;
        if (!in_array($form['topic_id'], $topicIds, true)) $errors[] = 'Choose a topic.';
        if ($form['value'] === '' && $newImage === '') $errors[] = 'Enter the item learners drag, or upload a picture for it.';
        if ($form['target'] === '') $errors[] = 'Enter what it matches with.';
        if (!$errors) {
            $conn->exec('BEGIN IMMEDIATE');
            if ($id) {
                $conn->prepare('UPDATE dragdrop_pairs SET topic_id = ?, value = ?, target = ?, image_path = ? WHERE id = ?')->execute([$form['topic_id'], $form['value'], $form['target'], $newImage === '' ? null : $newImage, $id]);
            } else {
                $newId = (int)$conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dragdrop_pairs')->fetchColumn();
                $conn->prepare('INSERT INTO dragdrop_pairs (id, topic_id, value, target, image_path, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')->execute([$newId, $form['topic_id'], $form['value'], $form['target'], $newImage === '' ? null : $newImage]);
            }
            $conn->exec('COMMIT');
            if ($oldImage !== '' && $oldImage !== $newImage) ddRemoveFile($conn, $oldImage);
            header('Location: /admin/dragdrop_pairs.php?msg=saved&topic=' . $form['topic_id']);
            exit;
        }
        if ($newImage !== '' && $newImage !== $oldImage) ddRemoveFile($conn, $newImage); // discard upload from an invalid form
    } catch (Throwable $e) {
        try { $conn->exec('ROLLBACK'); } catch (Throwable) {}
        error_log('[TestMate Drag Drop] ' . $e->getMessage());
        $errors[] = 'The pair could not be saved. Check the PHP terminal for the database error.';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'saved') $message = 'Pair saved successfully.';
elseif ($msg === 'deleted') $message = 'Pair deleted successfully.';

$sql = 'SELECT p.*, t.name AS topic_name FROM dragdrop_pairs p LEFT JOIN topics t ON p.topic_id = t.id';
$params = [];
if ($topicFilter) { $sql .= ' WHERE p.topic_id = ?'; $params[] = $topicFilter; }
$st = $conn->prepare($sql . ' ORDER BY p.topic_id, p.id');
$st->execute($params);
$pairs = $st->fetchAll(PDO::FETCH_ASSOC);

$perTopic = [];
foreach ($conn->query('SELECT topic_id, COUNT(*) AS n FROM dragdrop_pairs GROUP BY topic_id')->fetchAll(PDO::FETCH_ASSOC) as $r) $perTopic[(int)$r['topic_id']] = (int)$r['n'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Drag &amp; Drop Pairs – TestMate Admin</title>
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
.dd-layout{display:grid;grid-template-columns:340px minmax(0,1fr);gap:20px;align-items:start}
.form-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px;position:sticky;top:20px}
.form-card h2{margin:0 0 14px;font-size:17px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.field label{font-size:13px;font-weight:700;color:#44576b}
.field small{color:#7a8794;font-size:12px}
.field input,.field select,.field textarea{padding:10px 12px;border:1px solid #d5dde6;border-radius:8px;font:inherit;font-size:14px;background:#fff;width:100%}
.field textarea{min-height:70px;resize:vertical}
.form-actions{display:flex;gap:10px}
.field input[type=checkbox]{width:auto;padding:0}
.field label.check{flex-direction:row;display:flex;gap:6px;align-items:center;font-weight:400}
.dd-preview{max-width:140px;max-height:110px;object-fit:contain;border:1px solid #e1e8ef;border-radius:8px;background:#fff;padding:4px}
.dd-thumb{width:48px;height:48px;object-fit:contain;vertical-align:middle;margin-right:10px;background:#fff;border:1px solid #e1e8ef;border-radius:6px}
.tip{font-size:13px;color:#6b7c8d;background:#f4f8fd;border-radius:8px;padding:10px 12px;margin-bottom:14px;line-height:1.5}
@media(max-width:1000px){.dd-layout{grid-template-columns:1fr}.form-card{position:static}}
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
        <a href="/admin/add-question.php">Add Question</a>
        <a href="/admin/dragdrop_pairs.php" class="active">Drag &amp; Drop</a>
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
                <h1>Drag &amp; Drop Pairs (<?= count($pairs) ?>)</h1>
                <p>Used in the matching exercise at the end of each topic quiz. A quiz uses up to two pairs from its topic.</p>
            </div>
            <div class="page-actions"><a class="secondary-button" href="/admin/questions.php?tab=dragdrop">View in Questions</a></div>
        </div>

        <?php if ($message !== ''): ?><div class="notice success"><?= questionEsc($message) ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="notice error"><?php foreach ($errors as $e): ?><div><?= questionEsc($e) ?></div><?php endforeach; ?></div><?php endif; ?>

        <div class="dd-layout">
            <form class="form-card" method="post" enctype="multipart/form-data" action="/admin/dragdrop_pairs.php<?= $topicFilter ? '?topic=' . $topicFilter : '' ?>">
                <h2><?= (int)$form['id'] ? 'Edit pair #' . (int)$form['id'] : 'Add a pair' ?></h2>
                <input type="hidden" name="csrf_token" value="<?= questionEsc($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                <div class="tip">Example: item <b>STOP sign (red octagon)</b> matches <b>Stop completely at the line</b>.</div>
                <div class="field">
                    <label for="topic_id">Topic</label>
                    <select id="topic_id" name="topic_id" required>
                        <option value="">Choose a topic</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$form['topic_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= questionEsc($t['name']) ?> (<?= $perTopic[(int)$t['id']] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="image">Picture of the item (optional)</label>
                    <?php $ddImg = ddMedia($form['image_path'] ?? ''); if ($ddImg !== ''): ?>
                        <img class="dd-preview" src="<?= questionEsc($ddImg) ?>" alt="Current picture">
                        <label class="check"><input type="checkbox" name="remove_image" value="1"> Remove this picture</label>
                    <?php endif; ?>
                    <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                    <small>For example a road sign. Learners drag the picture to its meaning.</small>
                </div>
                <div class="field">
                    <label for="value">Item text</label>
                    <textarea id="value" name="value"><?= questionEsc($form['value']) ?></textarea>
                    <small>Optional when a picture is added (it is shown under the picture).</small>
                </div>
                <div class="field">
                    <label for="target">Matches with</label>
                    <textarea id="target" name="target" required><?= questionEsc($form['target']) ?></textarea>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit"><?= (int)$form['id'] ? 'Save Changes' : 'Add Pair' ?></button>
                    <?php if ((int)$form['id']): ?><a class="secondary-button" href="/admin/dragdrop_pairs.php<?= $topicFilter ? '?topic=' . $topicFilter : '' ?>">Cancel</a><?php endif; ?>
                </div>
            </form>

            <div>
                <form class="filters" method="get" action="/admin/dragdrop_pairs.php">
                    <select name="topic" aria-label="Filter by topic" onchange="this.form.submit()">
                        <option value="0">All topics</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $topicFilter === (int)$t['id'] ? 'selected' : '' ?>><?= questionEsc($t['name']) ?> (<?= $perTopic[(int)$t['id']] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Topic</th><th>Item</th><th></th><th>Matches with</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$pairs): ?>
                            <tr><td colspan="6" class="empty">No pairs yet<?= $topicFilter ? ' for this topic' : '' ?>.</td></tr>
                        <?php else: foreach ($pairs as $p): ?>
                            <tr<?= (int)$p['id'] === (int)$form['id'] ? ' style="background:#fffbe8"' : '' ?>>
                                <td class="qid"><?= (int)$p['id'] ?></td>
                                <td class="topic"><?= questionEsc($p['topic_name'] ?? 'Uncategorised') ?></td>
                                <td class="qtext"><?php $pi = ddMedia($p['image_path'] ?? ''); if ($pi !== ''): ?><img class="dd-thumb" src="<?= questionEsc($pi) ?>" alt=""><?php endif; ?><strong><?= questionEsc($p['value']) ?></strong></td>
                                <td class="pair-arrow">&rarr;</td>
                                <td class="qtext"><?= questionEsc($p['target']) ?></td>
                                <td style="white-space:nowrap">
                                    <a class="edit-link" href="/admin/dragdrop_pairs.php?edit=<?= (int)$p['id'] ?><?= $topicFilter ? '&amp;topic=' . $topicFilter : '' ?>">Edit</a>
                                    <form method="POST" action="/admin/dragdrop_pairs.php<?= $topicFilter ? '?topic=' . $topicFilter : '' ?>" style="display:inline" onsubmit="return confirm('Delete this pair?');">
                                        <input type="hidden" name="csrf_token" value="<?= questionEsc($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="delete-button">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>