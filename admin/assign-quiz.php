
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

function assignEsc($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function assignPreview($value, int $limit = 100): string {
    $value = (string)($value ?? '');
    if (preg_match_all('/./us', $value, $match) !== false) {
        $chars = $match[0];
        return implode('', array_slice($chars, 0, $limit))
            . (count($chars) > $limit ? '...' : '');
    }
    return substr($value, 0, $limit)
        . (strlen($value) > $limit ? '...' : '');
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$users = [];
$topics = [];
$questions = [];
$history = [];

$topicFilter = max(0, (int)($_GET['topic'] ?? 0));

// Assign selected questions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['admin_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $targetUser = (int)($_POST['user_id'] ?? 0);
    $adminId = (int)$_SESSION['user_id'];

    $selected = $_POST['question_ids'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $questionIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $selected),
                static fn($id) => $id > 0
            )
        )
    );

    if ($targetUser <= 0) {
        $error = 'Please select a learner.';
    } elseif (empty($questionIds)) {
        $error = 'Please select at least one question.';
    } else {
        try {
            $learnerStmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE id = ?
                  AND LOWER(role) IN ('user','learner')
                LIMIT 1
            ");
            $learnerStmt->execute([$targetUser]);

            if (!$learnerStmt->fetchColumn()) {
                throw new RuntimeException(
                    'The selected learner account was not found.'
                );
            }

            $conn->beginTransaction();

            $questionExists = $conn->prepare("
                SELECT id FROM questions WHERE id = ?
            ");

            $check = $conn->prepare("
                SELECT COUNT(*)
                FROM assigned_quizzes
                WHERE user_id = ?
                  AND question_id = ?
                  AND is_completed = 0
            ");

            $insert = $conn->prepare("
                INSERT INTO assigned_quizzes
                    (user_id, admin_id, question_id)
                VALUES (?, ?, ?)
            ");

            $count = 0;

            foreach ($questionIds as $qid) {
                $questionExists->execute([$qid]);

                if (!$questionExists->fetchColumn()) {
                    continue;
                }

                $check->execute([$targetUser, $qid]);

                if ((int)$check->fetchColumn() === 0) {
                    $insert->execute([
                        $targetUser,
                        $adminId,
                        $qid
                    ]);
                    $count++;
                }
            }

            $conn->commit();

            $message = $count . ' question(s) assigned successfully.';

            if ($count === 0) {
                $message = 'No new assignments were created. The questions may already be assigned and pending.';
            }

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log('Assignment error: ' . $e->getMessage());
            $error = 'Unable to assign questions. Check the learner and question records.';
        }
    }
}

// Load learners, topics, questions and history
try {
    $users = $conn->query("
        SELECT id, name, email
        FROM users
        WHERE LOWER(role) IN ('user','learner')
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topics = $conn->query("
        SELECT id, name
        FROM topics
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($topicFilter > 0) {
        $stmt = $conn->prepare("
            SELECT q.*, t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE q.topic_id = ?
            ORDER BY q.id
        ");
        $stmt->execute([$topicFilter]);
    } else {
        $stmt = $conn->query("
            SELECT q.*, t.name AS topic_name
            FROM questions q
            LEFT JOIN topics t ON q.topic_id = t.id
            ORDER BY q.topic_id, q.id
        ");
    }

    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = $conn->query("
        SELECT
            aq.id,
            aq.user_id,
            aq.question_id,
            aq.is_completed,
            aq.assigned_at,
            u.name AS user_name,
            q.question AS question_text
        FROM assigned_quizzes aq
        LEFT JOIN users u ON aq.user_id = u.id
        LEFT JOIN questions q ON aq.question_id = q.id
        ORDER BY aq.assigned_at DESC, aq.id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Assign quiz loading: ' . $e->getMessage());
    $error = 'Unable to load the assignment information.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Quiz – TestMate Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6fa;font-family:Arial,Helvetica,sans-serif;color:#2c3e50}
.navbar{background:#2c3e50;min-height:64px;display:flex;align-items:center;justify-content:space-between;padding:12px 26px;gap:15px}
.navbar a{color:white;text-decoration:none}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px}
.brand-icon{width:36px;height:36px;background:#3498db;border-radius:7px;display:grid;place-items:center}
.nav-links{display:flex;align-items:center;gap:25px;font-size:14px}
.btn-logout{background:#e74c3c;padding:11px 17px;border-radius:7px}
.admin-wrap{display:flex;min-height:calc(100vh - 64px)}
.sidebar{width:247px;flex-shrink:0;background:#1a252f;color:white;padding:25px 0}
.sidebar h3{font-size:11px;color:#8191a0;letter-spacing:1px;text-transform:uppercase;padding:0 21px;margin:25px 0 10px}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:block;padding:13px 21px;color:#e4eaf0;text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:#303e49;color:white}
.main-content{flex:1;min-width:0;padding:32px}
.main-content h1{font-size:23px;margin:0 0 24px}
.form-layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;align-items:start}
.card{background:white;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.card h2{font-size:17px;margin:0 0 18px}
.form-group{margin-bottom:17px}
.form-group label{display:block;font-size:14px;font-weight:700;margin-bottom:8px}
.form-group select{width:100%;padding:12px;border:1px solid #d9dfe6;border-radius:8px;background:white;font-size:14px}
.question-list{max-height:420px;overflow-y:auto;border:1px solid #edf0f4;border-radius:9px;padding:10px}
.q-checkbox{display:flex;align-items:flex-start;gap:10px;border:1px solid #eee;border-radius:8px;padding:12px;margin-bottom:8px;cursor:pointer}
.q-checkbox:hover{background:#f0f8ff;border-color:#3498db}
.q-checkbox input{margin-top:3px;flex-shrink:0;width:17px;height:17px;accent-color:#3498db}
.q-checkbox label{font-size:13px;line-height:1.5;cursor:pointer;flex:1}
.q-topic-tag{display:inline-block;background:#eaf4ff;color:#2471a3;border-radius:20px;padding:3px 9px;font-size:11px;margin-bottom:5px}
.btn{border:0;border-radius:8px;padding:12px 17px;background:#3498db;color:white;font-weight:700;font-size:14px;cursor:pointer}
.btn-full{width:100%;margin-top:18px}
.btn:hover{background:#247bb5}
.text-button{background:none;border:none;color:#3498db;font-size:12px;cursor:pointer;padding:0 12px 0 0}
.history-card{background:white;border-radius:10px;padding:16px;margin-bottom:10px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.history-heading{display:flex;justify-content:space-between;gap:12px;align-items:start}
.badge{border-radius:20px;font-size:12px;padding:5px 10px;display:inline-block;font-weight:700}
.badge-pass{background:#e5f6eb;color:#25844a}
.badge-pending{background:#fff4de;color:#a97717}
.notice{padding:14px 17px;margin-bottom:16px;border-radius:8px;font-size:14px}
.notice-success{background:#e5f6eb;color:#217846}
.notice-error{background:#fdecea;color:#a93226}
@media(max-width:950px){.form-layout{grid-template-columns:1fr}}
@media(max-width:760px){
.admin-wrap{display:block}
.sidebar{width:100%;padding:10px}
.sidebar a{display:inline-block;padding:10px}
.sidebar h3{margin:12px 15px 5px}
.main-content{padding:20px 14px}
.navbar{flex-wrap:wrap}
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
    <a href="/admin/questions.php">All Questions</a>
    <a href="/admin/add-question.php">Add Question</a>
    <a href="/admin/assign-quiz.php" class="active">Assign Quiz</a>

    <h3>Content</h3>
    <a href="/admin/simulations.php">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

    <h1>Assign Questions to a User</h1>

    <?php if ($message !== ''): ?>
        <div class="notice notice-success">
            <?= assignEsc($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice notice-error">
            <?= assignEsc($error) ?>
        </div>
    <?php endif; ?>

    <div class="form-layout">

        <section class="card">
            <h2>New Assignment</h2>

            <form method="POST" action="/admin/assign-quiz.php?topic=<?= $topicFilter ?>">
                <input
                    type="hidden"
                    name="csrf"
                    value="<?= assignEsc($_SESSION['admin_csrf']) ?>"
                >

                <div class="form-group">
                    <label for="user_id">Select User</label>
                    <select name="user_id" id="user_id" required>
                        <option value="">— Choose a learner —</option>
                        <?php foreach ($users as $user): ?>
                            <option
                                value="<?= (int)$user['id'] ?>"
                                <?= (int)($_POST['user_id'] ?? 0) === (int)$user['id']
                                    ? 'selected' : '' ?>
                            >
                                <?= assignEsc($user['name']) ?>
                                (<?= assignEsc($user['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="topic_filter">Filter by Topic</label>
                    <select
                        id="topic_filter"
                        onchange="window.location.href='/admin/assign-quiz.php?topic='+encodeURIComponent(this.value)"
                    >
                        <option value="0">All Topics</option>
                        <?php foreach ($topics as $topic): ?>
                            <option
                                value="<?= (int)$topic['id'] ?>"
                                <?= $topicFilter === (int)$topic['id']
                                    ? 'selected' : '' ?>
                            >
                                <?= assignEsc($topic['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Select Questions (<?= count($questions) ?>)</label>

                    <div style="margin-bottom:10px;">
                        <button type="button"
                                class="text-button"
                                onclick="selectAll(true)">
                            Select All
                        </button>

                        <button type="button"
                                class="text-button"
                                style="color:#888"
                                onclick="selectAll(false)">
                            Clear All
                        </button>
                    </div>

                    <div class="question-list">
                        <?php if (empty($questions)): ?>
                            <p style="color:#888;text-align:center;">
                                No questions available.
                            </p>
                        <?php else: ?>
                            <?php foreach ($questions as $question): ?>
                                <div class="q-checkbox">
                                    <input
                                        type="checkbox"
                                        name="question_ids[]"
                                        value="<?= (int)$question['id'] ?>"
                                        id="q<?= (int)$question['id'] ?>"
                                    >
                                    <label for="q<?= (int)$question['id'] ?>">
                                        <span class="q-topic-tag">
                                            <?= assignEsc(
                                                $question['topic_name'] ?? 'Unknown Topic'
                                            ) ?>
                                        </span>
                                        <div>
                                            <?= assignEsc(assignPreview(
                                                $question['question'] ?? '',
                                                100
                                            )) ?>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-full"
                    <?= empty($users) || empty($questions) ? 'disabled' : '' ?>
                >
                    Assign Selected Questions
                </button>
            </form>
        </section>

        <section>
            <h2 style="font-size:17px;margin:0 0 17px;">
                Recent Assignments
            </h2>

            <?php if (empty($history)): ?>
                <div class="card" style="color:#888;text-align:center;">
                    No assignments yet.
                </div>
            <?php else: ?>
                <?php foreach ($history as $item): ?>
                    <?php
                    $date = strtotime(
                        (string)($item['assigned_at'] ?? '')
                    );
                    $completed = (int)$item['is_completed'] === 1;
                    ?>

                    <div class="history-card">
                        <div class="history-heading">
                            <strong style="font-size:14px;">
                                <?= assignEsc($item['user_name'] ?? 'Unknown User') ?>
                            </strong>

                            <span class="badge <?= $completed
                                ? 'badge-pass'
                                : 'badge-pending' ?>">
                                <?= $completed ? 'Done' : 'Pending' ?>
                            </span>
                        </div>

                        <p style="font-size:13px;color:#666;line-height:1.5;margin:8px 0;">
                            <?= assignEsc(assignPreview(
                                $item['question_text'] ?? 'Question unavailable',
                                80
                            )) ?>
                        </p>

                        <div style="font-size:12px;color:#aaa;">
                            <?= $date !== false
                                ? date('d M Y H:i', $date)
                                : 'Date unavailable' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    </div>
</main>
</div>

<script>
function selectAll(checked) {
    document.querySelectorAll(
        'input[name="question_ids[]"]'
    ).forEach(function(input) {
        input.checked = checked;
    });
}
</script>

</body>
</html>
