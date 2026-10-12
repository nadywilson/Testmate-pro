
<?php
require_once __DIR__ . '/../includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_id']) ||
    strtolower((string)($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: /login.php');
    exit;
}

function tmEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tmPreview($value, int $length = 80): string {
    $value = (string)($value ?? '');

    // Unicode-aware without requiring mbstring
    if (preg_match_all('/./us', $value, $matches) !== false) {
        $characters = $matches[0];
        return implode('', array_slice($characters, 0, $length))
            . (count($characters) > $length ? '...' : '');
    }

    return substr($value, 0, $length)
        . (strlen($value) > $length ? '...' : '');
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$questions = [];
$topics = [];

// Delete question through POST, not GET
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $deleteId = (int)($_POST['question_id'] ?? 0);

    if ($deleteId > 0) {
        try {
            $stmt = $conn->prepare(
                'DELETE FROM questions WHERE id = ?'
            );
            $stmt->execute([$deleteId]);

            header('Location: /admin/questions.php?msg=deleted');
            exit;
        } catch (PDOException $e) {
            error_log('Question deletion: ' . $e->getMessage());
            $error = 'Unable to delete this question. Other records may reference it.';
        }
    }
}

$topicFilter = max(0, (int)($_GET['topic'] ?? 0));

try {
    $topics = $conn->query(
        'SELECT id, name FROM topics ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($topicFilter > 0) {
        $stmt = $conn->prepare("
            SELECT
                q.*,
                t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE q.topic_id = ?
            ORDER BY q.id DESC
        ");
        $stmt->execute([$topicFilter]);
    } else {
        $stmt = $conn->query("
            SELECT
                q.*,
                t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            ORDER BY q.id DESC
        ");
    }

    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Questions loading: ' . $e->getMessage());
    $error = 'Unable to load questions.';
}
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
.navbar{height:64px;background:#2c3e50;display:flex;justify-content:space-between;align-items:center;padding:0 26px}
.navbar a{color:white;text-decoration:none}
.brand{font-weight:700;font-size:18px;display:flex;align-items:center;gap:10px}
.brand-icon{display:grid;place-items:center;background:#3498db;width:36px;height:36px;border-radius:7px;color:white}
.nav-links{display:flex;align-items:center;gap:25px;font-size:14px}
.btn-logout{background:#e74c3c;padding:11px 17px;border-radius:7px}
.admin-wrap{display:flex;min-height:calc(100vh - 64px)}
.sidebar{width:247px;flex-shrink:0;background:#1a252f;color:white;padding:25px 0}
.sidebar h3{font-size:11px;letter-spacing:1px;color:#8191a0;padding:0 21px;margin:25px 0 10px;text-transform:uppercase}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:block;padding:13px 21px;color:#e4eaf0;text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:#303e49;color:white}
.main-content{flex:1;min-width:0;padding:32px;background:#f5f6fa}
.heading-row{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px}
.heading-row h1{font-size:23px;margin:0}
.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.topic-select{border:1px solid #d9dfe6;background:white;border-radius:9px;padding:12px 15px;min-width:220px;font-size:14px}
.btn{display:inline-block;border:0;border-radius:8px;background:#3498db;color:white!important;padding:12px 17px;text-decoration:none;font-size:14px;font-weight:700;cursor:pointer}
.btn:hover{background:#247bb5}
.table-wrap{background:white;border-radius:12px;overflow-x:auto;box-shadow:0 2px 10px rgba(0,0,0,.05)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;background:#edf2f7;color:#34465e;font-size:12px;letter-spacing:.3px;text-transform:uppercase;padding:17px}
td{padding:15px 17px;border-bottom:1px solid #eef1f4;vertical-align:middle}
tbody tr:hover{background:#fbfcfe}
.badge{display:inline-block;background:#eaf4ff;color:#2471a3;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700}
.notice{padding:14px 18px;border-radius:8px;margin-bottom:16px;font-size:14px}
.notice-success{background:#e5f6eb;color:#207744}
.notice-error{background:#fdecea;color:#a52f23}
.link-edit{color:#3498db;text-decoration:none;font-size:13px;font-weight:600}
.link-delete{color:#e74c3c;border:0;background:none;cursor:pointer;font-size:13px;padding:0;margin-left:12px}
.empty{text-align:center;padding:35px;color:#888}
@media(max-width:760px){
.admin-wrap{display:block}
.sidebar{width:100%;padding:10px 0}
.sidebar a{display:inline-block;padding:10px}
.sidebar h3{margin:12px 15px 5px}
.main-content{padding:20px 14px}
.navbar{height:auto;min-height:64px;flex-wrap:wrap;padding:12px 15px;gap:10px}
}
</style>
</head>
<body>

<nav class="navbar">
    <a href="/admin/index.php" class="brand">
        <span class="brand-icon">T</span> TestMate Admin
    </a>
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
    <a href="/admin/assign-quiz.php">Assign Quiz</a>

    <h3>Content</h3>
    <a href="/admin/simulations.php">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

    <div class="heading-row">
        <h1>All Questions (<?= count($questions) ?>)</h1>

        <div class="actions">
            <select
                class="topic-select"
                aria-label="Filter by topic"
                onchange="window.location.href='/admin/questions.php?topic='+encodeURIComponent(this.value)"
            >
                <option value="0">All Topics</option>
                <?php foreach ($topics as $topic): ?>
                    <option
                        value="<?= (int)$topic['id'] ?>"
                        <?= $topicFilter === (int)$topic['id'] ? 'selected' : '' ?>
                    >
                        <?= tmEscape($topic['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <a href="/admin/add-question.php" class="btn">
                Add Question
            </a>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'deleted'): ?>
        <div class="notice notice-success">
            Question deleted successfully.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice notice-error">
            <?= tmEscape($error) ?>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Topic</th>
                    <th>Question</th>
                    <th>Answer</th>
                    <th>Image</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($questions)): ?>
                <tr>
                    <td colspan="6" class="empty">
                        No questions found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?= (int)$question['id'] ?></td>

                        <td>
                            <?= tmEscape(
                                $question['topic_name'] ?? 'Unknown'
                            ) ?>
                        </td>

                        <td style="max-width:360px;">
                            <?= tmEscape(tmPreview(
                                $question['question'] ?? '',
                                80
                            )) ?>
                        </td>

                        <td>
                            <span class="badge">
                                <?= tmEscape($question['correct_answer'] ?? '') ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($question['image_path'])): ?>
                                <span style="color:#27ae60;">Yes</span>
                            <?php else: ?>
                                <span style="color:#aaa;">No</span>
                            <?php endif; ?>
                        </td>

                        <td style="white-space:nowrap;">
                            <a
                                href="/admin/add-question.php?edit=<?= (int)$question['id'] ?>"
                                class="link-edit"
                            >Edit</a>

                            <form
                                method="POST"
                                action="/admin/questions.php"
                                style="display:inline;"
                                onsubmit="return confirm('Delete this question permanently?');"
                            >
                                <input type="hidden"
                                       name="action" value="delete">
                                <input type="hidden"
                                       name="question_id"
                                       value="<?= (int)$question['id'] ?>">
                                <input type="hidden"
                                       name="csrf"
                                       value="<?= tmEscape($_SESSION['admin_csrf']) ?>">
                                <button type="submit" class="link-delete">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

</div>
</body>
</html>
