
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

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function questionEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$error = '';
$message = '';
$questions = [];
$topics = [];

// Delete a question securely
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $deleteId = filter_var(
        $_POST['question_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$deleteId) {
        $error = 'Invalid question ID.';
    } else {
        try {
            $stmt = $conn->prepare(
                "DELETE FROM questions WHERE id = :id"
            );

            $stmt->execute([':id' => $deleteId]);

            header('Location: /admin/questions.php?msg=deleted');
            exit;

        } catch (PDOException $e) {
            error_log('Question deletion error: ' . $e->getMessage());
            $error = 'Could not delete the question. It may be referenced by existing learner records.';
        }
    }
}

$topic_filter = filter_var(
    $_GET['topic'] ?? 0,
    FILTER_VALIDATE_INT
);

$topic_filter = $topic_filter && $topic_filter > 0
    ? $topic_filter
    : 0;

try {
    if ($topic_filter > 0) {
        $stmt = $conn->prepare("
            SELECT q.*, t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE q.topic_id = :topic_id
            ORDER BY q.id DESC
        ");

        $stmt->execute([':topic_id' => $topic_filter]);
    } else {
        $stmt = $conn->query("
            SELECT q.*, t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            ORDER BY q.id DESC
        ");
    }

    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $topics = $conn->query("
        SELECT id, name
        FROM topics
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Questions page error: ' . $e->getMessage());
    $error = 'Unable to load questions from the database.';
}

if (($_GET['msg'] ?? '') === 'deleted') {
    $message = 'Question deleted successfully.';
} elseif (($_GET['msg'] ?? '') === 'saved') {
    $message = 'Question saved successfully.';
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
* { box-sizing:border-box; }

body {
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:#f5f6fa;
    color:#2c3e50;
}

.navbar {
    min-height:60px;
    background:#2c3e50;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:12px 24px;
    gap:15px;
}

.brand {
    display:flex;
    align-items:center;
    gap:10px;
    color:#fff;
    text-decoration:none;
    font-size:18px;
    font-weight:700;
}

.brand-icon {
    display:inline-grid;
    place-items:center;
    width:34px;
    height:34px;
    border-radius:8px;
    background:#3498db;
    color:#fff;
}

.nav-links {
    display:flex;
    gap:20px;
    align-items:center;
}

.nav-links a {
    color:#fff;
    text-decoration:none;
    font-size:14px;
}

.btn-logout {
    padding:9px 15px;
    background:#e74c3c;
    border-radius:6px;
}

.admin-wrap {
    display:flex;
    min-height:calc(100vh - 60px);
}

.sidebar {
    width:230px;
    background:#1a252f;
    color:white;
    padding:24px 0;
    flex-shrink:0;
    position:sticky;
    top:0;
    height:calc(100vh - 60px);
    overflow-y:auto;
}

.sidebar h3 {
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:rgba(255,255,255,.4);
    padding:0 20px;
    margin:20px 0 8px;
}

.sidebar h3:first-child { margin-top:0; }

.sidebar a {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 20px;
    color:rgba(255,255,255,.8);
    text-decoration:none;
    font-size:14px;
    transition:all .15s;
}

.sidebar a:hover,
.sidebar a.active {
    background:rgba(255,255,255,.1);
    color:#fff;
}

.main-content {
    flex:1;
    min-width:0;
    padding:30px;
    background:#f5f6fa;
}

.page-heading {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    flex-wrap:wrap;
    gap:12px;
}

.page-heading h1 {
    font-size:22px;
    margin:0;
}

.page-actions {
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}

.topic-select {
    padding:9px 12px;
    border:1px solid #ddd;
    border-radius:8px;
    font-size:14px;
    background:white;
}

.primary-button {
    display:inline-block;
    background:#3498db;
    color:#fff;
    text-decoration:none;
    padding:10px 16px;
    border-radius:7px;
    font-size:14px;
    font-weight:700;
}

.primary-button:hover { background:#247db7; }

.table-wrap {
    background:white;
    border-radius:10px;
    overflow-x:auto;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
}

table {
    width:100%;
    border-collapse:collapse;
    font-size:14px;
}

th {
    background:#eef2f7;
    padding:14px;
    text-align:left;
    color:#44576b;
}

td {
    padding:13px 14px;
    border-bottom:1px solid #edf0f4;
    vertical-align:middle;
}

tr:last-child td { border-bottom:none; }

.badge {
    display:inline-block;
    padding:5px 10px;
    border-radius:6px;
    background:#eaf4ff;
    color:#2471a3;
    font-size:13px;
    font-weight:700;
}

.edit-link {
    color:#3498db;
    font-size:13px;
    text-decoration:none;
    margin-right:10px;
}

.delete-button {
    border:none;
    padding:0;
    background:none;
    color:#e74c3c;
    font-size:13px;
    cursor:pointer;
}

.notice {
    padding:13px 16px;
    border-radius:8px;
    margin-bottom:16px;
    font-size:14px;
}

.notice.success {
    background:#eafaf1;
    color:#216e43;
    border:1px solid #b8e4ca;
}

.notice.error {
    background:#fdecea;
    color:#a93226;
    border:1px solid #efc7c2;
}

@media(max-width:750px) {
    .admin-wrap { flex-direction:column; }

    .sidebar {
        position:static;
        width:100%;
        height:auto;
        padding:12px 0;
    }

    .main-content { padding:20px 14px; }
    .navbar { padding:12px 14px; flex-wrap:wrap; }
}
</style>
</head>

<body>

<nav class="navbar">
    <a href="/admin/index.php" class="brand">
        <span class="brand-icon">T</span>
        TestMate Admin
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
        <a href="/admin/materials.php">Materials</a>
        <a href="/admin/add-material.php">Add Material</a>
    </aside>

    <main class="main-content">

        <div class="page-heading">
            <h1>All Questions (<?= count($questions) ?>)</h1>

            <div class="page-actions">
                <select
                    class="topic-select"
                    aria-label="Filter questions by topic"
                    onchange="location.href='/admin/questions.php?topic='+encodeURIComponent(this.value)"
                >
                    <option value="0">All Topics</option>

                    <?php foreach ($topics as $t): ?>
                        <option
                            value="<?= (int)$t['id'] ?>"
                            <?= $topic_filter === (int)$t['id']
                                ? 'selected' : '' ?>
                        >
                            <?= questionEsc($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <a href="/admin/add-question.php" class="primary-button">
                    Add Question
                </a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="notice success">
                <?= questionEsc($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice error">
                <?= questionEsc($error) ?>
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
                        <td colspan="6"
                            style="text-align:center;color:#888;padding:28px;">
                            No questions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($questions as $q): ?>
                        <?php
                        $questionText = (string)($q['question'] ?? '');
                        $preview = mb_substr(
                            $questionText,
                            0,
                            80,
                            'UTF-8'
                        );

                        if (mb_strlen($questionText, 'UTF-8') > 80) {
                            $preview .= '...';
                        }
                        ?>

                        <tr>
                            <td style="color:#888;font-size:13px;">
                                <?= (int)$q['id'] ?>
                            </td>

                            <td style="font-size:13px;">
                                <?= questionEsc(
                                    $q['topic_name'] ?? 'Uncategorised'
                                ) ?>
                            </td>

                            <td style="font-size:14px;max-width:300px;">
                                <?= questionEsc($preview) ?>
                            </td>

                            <td>
                                <span class="badge">
                                    <?= questionEsc($q['correct_answer'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($q['image_path'])): ?>
                                    <span style="color:#27ae60;font-size:13px;">
                                        Yes
                                    </span>
                                <?php else: ?>
                                    <span style="color:#aaa;font-size:13px;">
                                        No
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td style="white-space:nowrap;">
                                <a
                                    class="edit-link"
                                    href="/admin/add-question.php?edit=<?= (int)$q['id'] ?>"
                                >
                                    Edit
                                </a>

                                <form
                                    method="POST"
                                    action="/admin/questions.php"
                                    style="display:inline;"
                                    onsubmit="return confirm('Permanently delete this question? Existing learning records may reference it.');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= questionEsc($_SESSION['admin_csrf']) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >
                                    <input
                                        type="hidden"
                                        name="question_id"
                                        value="<?= (int)$q['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="delete-button"
                                    >
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
