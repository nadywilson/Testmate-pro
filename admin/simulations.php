<?php
/**
 * TestMate – Administrator: Simulations
 * PHP 8.5 + PDO SQLite
 *
 * /admin/simulations.php           list, show/hide, learner results
 * /admin/simulations.php?edit=ID   edit details and quiz questions
 *
 * Scenario animations (scenario_data) are not edited here; they are
 * maintained by tools/migrate-simulations.php so the animations stay valid.
 */

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

function simAdminEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Scenario summary line (same wording learners see). */
function simAdminScenarioLabel(array $s): string
{
    if (isset($s['label']) && $s['label'] !== '') return (string)$s['label'];
    if (isset($s['light'])) return 'Traffic light: ' . ucfirst((string)$s['light']);
    if (isset($s['limit'], $s['speed'])) return 'Speed ' . (int)$s['speed'] . ' km/h, limit ' . (int)$s['limit'] . ' km/h';
    if (isset($s['sign'])) return 'Sign: ' . ucfirst(str_replace('_', ' ', (string)$s['sign']));
    if (isset($s['location'])) return 'Parking: ' . ucfirst(str_replace('_', ' ', (string)$s['location']));
    if (isset($s['arriving_order'])) return 'Arrived ' . $s['arriving_order'] . ', action: ' . ($s['action'] ?? '');
    if (isset($s['action'])) return ucfirst(str_replace('_', ' ', (string)$s['action']));
    return 'Scenario';
}

$editId = max(0, (int)($_GET['edit'] ?? 0));
$message = '';
$error = '';

/* ===================================================== ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $action = (string)($_POST['action'] ?? '');
    $simId = (int)($_POST['simulation_id'] ?? 0);

    try {
        $exists = $conn->prepare('SELECT COUNT(*) FROM simulations WHERE id = ?');
        $exists->execute([$simId]);
        if ($simId <= 0 || (int)$exists->fetchColumn() === 0) {
            throw new RuntimeException('Simulation not found.');
        }

        if ($action === 'toggle') {
            $conn->prepare('UPDATE simulations SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')
                 ->execute([$simId]);
            header('Location: /admin/simulations.php?msg=toggled');
            exit;
        }

        if ($action === 'save') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($title === '') {
                throw new RuntimeException('Title is required.');
            }
            if ($topicId > 0) {
                $t = $conn->prepare('SELECT COUNT(*) FROM topics WHERE id = ?');
                $t->execute([$topicId]);
                if ((int)$t->fetchColumn() === 0) throw new RuntimeException('Selected topic does not exist.');
            }

            $conn->beginTransaction();

            $conn->prepare('UPDATE simulations SET title = ?, description = ?, topic_id = ?, is_active = ? WHERE id = ?')
                 ->execute([$title, $description, $topicId > 0 ? $topicId : null, $active, $simId]);

            // Existing questions
            $updateQ = $conn->prepare('UPDATE simulation_questions SET question = ?, correct_answer = ?, explanation = ? WHERE id = ? AND simulation_id = ?');
            $deleteQ = $conn->prepare('DELETE FROM simulation_questions WHERE id = ? AND simulation_id = ?');
            foreach ((array)($_POST['q'] ?? []) as $qid => $row) {
                $qid = (int)$qid;
                if ($qid <= 0 || !is_array($row)) continue;
                if (!empty($row['delete'])) {
                    $deleteQ->execute([$qid, $simId]);
                    continue;
                }
                $text = trim((string)($row['question'] ?? ''));
                $answer = (string)($row['correct_answer'] ?? '');
                if ($text === '' || !in_array($answer, ['correct', 'incorrect'], true)) {
                    throw new RuntimeException('Every question needs text and an answer (Correct or Incorrect).');
                }
                $updateQ->execute([$text, $answer, trim((string)($row['explanation'] ?? '')), $qid, $simId]);
            }

            // Optional new question
            $newText = trim((string)($_POST['new_question'] ?? ''));
            if ($newText !== '') {
                $newAnswer = (string)($_POST['new_correct_answer'] ?? '');
                $newIndex = (int)($_POST['new_scenario_index'] ?? -1);
                if (!in_array($newAnswer, ['correct', 'incorrect'], true) || $newIndex < 0) {
                    throw new RuntimeException('Choose a scenario and an answer for the new question.');
                }
                // ids are allocated here so this works whether or not the table auto-numbers.
                $nextId = (int)$conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM simulation_questions')->fetchColumn();
                $conn->prepare('INSERT INTO simulation_questions (id, simulation_id, scenario_index, question, correct_answer, explanation) VALUES (?, ?, ?, ?, ?, ?)')
                     ->execute([$nextId, $simId, $newIndex, $newText, $newAnswer, trim((string)($_POST['new_explanation'] ?? ''))]);
            }

            $conn->commit();
            header('Location: /admin/simulations.php?edit=' . $simId . '&msg=saved');
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('TestMate admin simulations: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the changes. Check the PHP server terminal.';
        if ($simId > 0 && ($_POST['action'] ?? '') === 'save') $editId = $simId;
    }
}

switch ($_GET['msg'] ?? '') {
    case 'saved':   $message = 'Simulation saved successfully.'; break;
    case 'toggled': $message = 'Simulation visibility updated.'; break;
}

/* ===================================================== DATA */
$topics = [];
$sims = [];
$sim = null;
$scenarios = [];
$questions = [];

try {
    $topics = $conn->query('SELECT id, name FROM topics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    if ($editId > 0) {
        $st = $conn->prepare('SELECT s.*, t.name AS topic_name FROM simulations s LEFT JOIN topics t ON s.topic_id = t.id WHERE s.id = ?');
        $st->execute([$editId]);
        $sim = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($sim) {
            $decoded = json_decode((string)$sim['scenario_data'], true);
            $scenarios = is_array($decoded) ? array_values($decoded) : [];
            $st = $conn->prepare('SELECT * FROM simulation_questions WHERE simulation_id = ? ORDER BY scenario_index, id');
            $st->execute([$editId]);
            $questions = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error = 'Simulation not found.';
        }
    }

    if (!$sim) {
        $sims = $conn->query("
            SELECT
                s.id, s.title, s.description, s.animation_type, s.is_active, s.scenario_data,
                t.name AS topic_name,
                (SELECT COUNT(*) FROM simulation_questions q WHERE q.simulation_id = s.id) AS question_count,
                (SELECT COUNT(*) FROM simulation_results r WHERE r.simulation_id = s.id AND r.mode = 'quiz') AS attempts,
                (SELECT ROUND(AVG(CASE WHEN r.total > 0 THEN r.score * 100.0 / r.total END))
                   FROM simulation_results r WHERE r.simulation_id = s.id AND r.mode = 'quiz') AS avg_pct
            FROM simulations s
            LEFT JOIN topics t ON s.topic_id = t.id
            ORDER BY s.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('TestMate admin simulations load: ' . $e->getMessage());
    $error = 'Unable to load simulations. Check the PHP server terminal.';
}

$activeCount = 0; $totalAttempts = 0; $noTopic = 0;
foreach ($sims as $s) {
    $activeCount += (int)$s['is_active'] === 1 ? 1 : 0;
    $totalAttempts += (int)$s['attempts'];
    $noTopic += empty($s['topic_name']) ? 1 : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Simulations – TestMate Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f6fa;color:#2c3e50}
.navbar{min-height:64px;background:#2c3e50;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:12px 26px}
.navbar a{color:white;text-decoration:none}
.brand{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}
.brand-icon{display:grid;place-items:center;background:#3498db;width:36px;height:36px;border-radius:7px}
.nav-links{display:flex;align-items:center;gap:25px;font-size:14px}
.btn-logout{background:#e74c3c;padding:11px 17px;border-radius:7px}
.admin-wrap{display:flex;min-height:calc(100vh - 64px)}
.sidebar{width:247px;background:#1a252f;color:white;padding:25px 0;flex-shrink:0}
.sidebar h3{font-size:11px;letter-spacing:1px;color:#8191a0;padding:0 21px;margin:25px 0 10px;text-transform:uppercase}
.sidebar h3:first-child{margin-top:0}
.sidebar a{display:block;padding:13px 21px;color:#e4eaf0;text-decoration:none;font-size:14px}
.sidebar a:hover,.sidebar a.active{background:#303e49;color:white}
.main-content{flex:1;min-width:0;padding:32px;background:#f5f6fa}
.heading-row{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px}
.heading-row h1{font-size:23px;margin:0}
.heading-row p{margin:5px 0 0;color:#888;font-size:14px}
.btn{display:inline-block;border:1px solid transparent;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer;font-family:inherit}
.btn-primary{background:#3498db;color:#fff!important}
.btn-primary:hover{background:#247bb5}
.btn-outline{background:#fff;color:#2c3e50!important;border-color:#cbd5e1}
.btn-outline:hover{background:#f0f8ff}
.btn-sm{padding:7px 12px;font-size:13px}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:26px}
.stat-card{background:#fff;border:1px solid #edf0f4;border-radius:10px;padding:20px 14px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.stat-card .num{display:block;font-size:2rem;font-weight:800;line-height:1.2}
.stat-card .lbl{display:block;margin-top:6px;font-size:13px;color:#888}
.table-wrap{background:#fff;border-radius:10px;overflow-x:auto;box-shadow:0 2px 8px rgba(0,0,0,.05)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;padding:14px;background:#eef2f7;color:#3d5165;white-space:nowrap}
td{padding:13px 14px;border-bottom:1px solid #eef0f4;vertical-align:middle}
tbody tr:hover{background:#fbfcfe}
.muted{color:#888;font-size:12px}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700}
.badge-on{background:#e5f6eb;color:#25844a}
.badge-off{background:#f0f0f0;color:#777}
.badge-warn{background:#fff4de;color:#a97717}
.inline{display:inline}
.link-btn{background:none;border:0;padding:0;color:#3498db;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.link-btn.off{color:#e67e22}
.row-actions{display:flex;gap:12px;align-items:center;white-space:nowrap}
.row-actions a{color:#3498db;font-size:13px;font-weight:700;text-decoration:none}
.notice{padding:14px 17px;margin-bottom:16px;border-radius:8px;font-size:14px}
.notice-success{background:#e5f6eb;color:#217846}
.notice-error{background:#fdecea;color:#a93226}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:22px}
.card h2{font-size:17px;margin:0 0 16px}
.form-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:14px;font-weight:700;margin-bottom:7px}
.form-control{width:100%;padding:11px 13px;border:1px solid #d9dfe6;border-radius:8px;font-size:14px;font-family:inherit;background:#fff}
.form-control:focus{outline:none;border-color:#3498db;box-shadow:0 0 0 3px rgba(52,152,219,.1)}
textarea.form-control{resize:vertical;min-height:70px}
.check{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700}
.scenario{border:1px solid #edf0f4;border-radius:10px;padding:16px;margin-bottom:14px}
.scenario-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.scenario-head strong{font-size:14px}
.q-row{background:#f8fafc;border-radius:8px;padding:14px;margin-top:10px}
.q-grid{display:grid;grid-template-columns:1fr 170px;gap:12px}
.delete-check{display:flex;align-items:center;gap:6px;font-size:12px;color:#c0392b;margin-top:8px}
.save-bar{position:sticky;bottom:0;background:#f5f6fa;padding:14px 0;display:flex;gap:10px;flex-wrap:wrap}
@media(max-width:900px){.form-grid,.q-grid{grid-template-columns:1fr}}
@media(max-width:760px){
.admin-wrap{display:block}
.sidebar{width:100%;padding:10px 0}
.sidebar a{display:inline-block;padding:10px}
.sidebar h3{margin:12px 15px 5px}
.main-content{padding:20px 14px}
.navbar{flex-wrap:wrap}
}
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
    <a href="/admin/assign-quiz.php">Assign Quiz</a>
    <h3>Content</h3>
    <a href="/admin/simulations.php" class="active">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

<?php if ($message !== ''): ?><div class="notice notice-success"><?= simAdminEsc($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error"><?= simAdminEsc($error) ?></div><?php endif; ?>

<?php if ($sim): ?>

    <!-- ================= EDIT ================= -->
    <div class="heading-row">
        <div>
            <h1>Edit Simulation</h1>
            <p><?= simAdminEsc($sim['title']) ?> · <?= count($scenarios) ?> scenarios · <?= count($questions) ?> quiz questions</p>
        </div>
        <div class="row-actions">
            <a class="btn btn-outline btn-sm" href="/simulations.php?id=<?= (int)$sim['id'] ?>&amp;mode=study" target="_blank" rel="noopener">Preview as learner</a>
            <a class="btn btn-outline btn-sm" href="/admin/simulations.php">Back to Simulations</a>
        </div>
    </div>

    <form method="POST" action="/admin/simulations.php?edit=<?= (int)$sim['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= simAdminEsc($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="simulation_id" value="<?= (int)$sim['id'] ?>">

        <section class="card">
            <h2>Details</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input class="form-control" id="title" name="title" required value="<?= simAdminEsc($sim['title']) ?>">
                </div>
                <div class="form-group">
                    <label for="topic_id">Topic</label>
                    <select class="form-control" id="topic_id" name="topic_id">
                        <option value="0">No topic</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$sim['topic_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= simAdminEsc($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= simAdminEsc($sim['description'] ?? '') ?></textarea>
            </div>
            <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int)$sim['is_active'] === 1 ? 'checked' : '' ?>> Visible to learners</label>
            <p class="muted" style="margin:12px 0 0">The topic decides which "Try the simulation" links learners see on quiz results and study pages.</p>
        </section>

        <section class="card">
            <h2>Scenarios and quiz questions</h2>
            <?php foreach ($scenarios as $si => $sc): $sc = is_array($sc) ? $sc : []; ?>
                <div class="scenario">
                    <div class="scenario-head">
                        <strong>Scenario <?= $si + 1 ?>: <?= simAdminEsc(simAdminScenarioLabel($sc)) ?></strong>
                        <span class="badge <?= !empty($sc['is_correct']) ? 'badge-on' : 'badge-warn' ?>"><?= !empty($sc['is_correct']) ? 'Shows a correct action' : 'Shows an incorrect action' ?></span>
                    </div>
                    <?php if (!empty($sc['explanation'])): ?><div class="muted"><?= simAdminEsc($sc['explanation']) ?></div><?php endif; ?>
                    <?php $hasQ = false; foreach ($questions as $q): if ((int)$q['scenario_index'] !== $si) continue; $hasQ = true; $qid = (int)$q['id']; ?>
                        <div class="q-row">
                            <div class="q-grid">
                                <div class="form-group" style="margin:0">
                                    <label for="q<?= $qid ?>">Question</label>
                                    <input class="form-control" id="q<?= $qid ?>" name="q[<?= $qid ?>][question]" value="<?= simAdminEsc($q['question']) ?>">
                                </div>
                                <div class="form-group" style="margin:0">
                                    <label for="a<?= $qid ?>">Right answer</label>
                                    <select class="form-control" id="a<?= $qid ?>" name="q[<?= $qid ?>][correct_answer]">
                                        <option value="correct" <?= $q['correct_answer'] === 'correct' ? 'selected' : '' ?>>Correct</option>
                                        <option value="incorrect" <?= $q['correct_answer'] === 'incorrect' ? 'selected' : '' ?>>Incorrect</option>
                                    </select>
                                </div>
                            </div>
                            <?php $mismatch = in_array($q['correct_answer'], ['correct','incorrect'], true) && (($q['correct_answer'] === 'correct') !== !empty($sc['is_correct'])); ?>
                            <?php if ($mismatch): ?><p class="badge badge-warn" style="margin:8px 0 0">Check: answer does not match what the animation shows</p><?php endif; ?>
                            <div class="form-group" style="margin:10px 0 0">
                                <label for="e<?= $qid ?>">Explanation</label>
                                <textarea class="form-control" id="e<?= $qid ?>" name="q[<?= $qid ?>][explanation]" rows="2"><?= simAdminEsc($q['explanation'] ?? '') ?></textarea>
                            </div>
                            <label class="delete-check"><input type="checkbox" name="q[<?= $qid ?>][delete]" value="1"> Delete this question when saving</label>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hasQ): ?><p class="muted" style="margin:10px 0 0">No quiz question for this scenario yet.</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$scenarios): ?><p class="muted">This simulation has no scenarios.</p><?php endif; ?>
        </section>

        <?php if ($scenarios): ?>
        <section class="card">
            <h2>Add a quiz question (optional)</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="new_question">Question</label>
                    <input class="form-control" id="new_question" name="new_question" placeholder="e.g. The driver stopped at the red light. Was this correct?">
                </div>
                <div class="form-group">
                    <label for="new_scenario_index">Scenario</label>
                    <select class="form-control" id="new_scenario_index" name="new_scenario_index">
                        <?php foreach ($scenarios as $si => $sc): ?>
                            <option value="<?= $si ?>">Scenario <?= $si + 1 ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="new_explanation">Explanation</label>
                    <input class="form-control" id="new_explanation" name="new_explanation">
                </div>
                <div class="form-group">
                    <label for="new_correct_answer">Right answer</label>
                    <select class="form-control" id="new_correct_answer" name="new_correct_answer">
                        <option value="correct">Correct</option>
                        <option value="incorrect">Incorrect</option>
                    </select>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <div class="save-bar">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a class="btn btn-outline" href="/admin/simulations.php">Cancel</a>
        </div>
    </form>

<?php else: ?>

    <!-- ================= LIST ================= -->
    <div class="heading-row">
        <div>
            <h1>Simulations (<?= count($sims) ?>)</h1>
            <p>Animated driving scenarios learners study and get quizzed on.</p>
        </div>
        <a class="btn btn-outline" href="/simulations.php" target="_blank" rel="noopener">Open learner page</a>
    </div>

    <section class="stat-grid">
        <div class="stat-card"><span class="num" style="color:#2c3e50"><?= count($sims) ?></span><span class="lbl">Simulations</span></div>
        <div class="stat-card"><span class="num" style="color:#27ae60"><?= $activeCount ?></span><span class="lbl">Visible to learners</span></div>
        <div class="stat-card"><span class="num" style="color:#3498db"><?= $totalAttempts ?></span><span class="lbl">Quiz attempts</span></div>
        <div class="stat-card"><span class="num" style="color:<?= $noTopic ? '#e67e22' : '#27ae60' ?>"><?= $noTopic ?></span><span class="lbl">Without a topic</span></div>
    </section>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Simulation</th><th>Topic</th><th>Scenarios</th><th>Questions</th><th>Attempts</th><th>Avg score</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$sims): ?>
                <tr><td colspan="9" style="text-align:center;color:#888;padding:30px">No simulations found. Run tools/migrate-simulations.php to add them.</td></tr>
            <?php endif; ?>
            <?php foreach ($sims as $s):
                $sc = json_decode((string)$s['scenario_data'], true);
                $scCount = is_array($sc) ? count($sc) : 0;
                $active = (int)$s['is_active'] === 1;
                $avg = $s['avg_pct'] !== null ? (int)$s['avg_pct'] . '%' : '—';
            ?>
                <tr>
                    <td class="muted"><?= (int)$s['id'] ?></td>
                    <td>
                        <strong><?= simAdminEsc($s['title']) ?></strong>
                        <div class="muted"><?= simAdminEsc(ucwords(str_replace('_', ' ', (string)$s['animation_type']))) ?></div>
                    </td>
                    <td><?= $s['topic_name'] ? simAdminEsc($s['topic_name']) : '<span class="badge badge-warn">No topic</span>' ?></td>
                    <td><?= $scCount ?></td>
                    <td><?= (int)$s['question_count'] === 0 ? '<span class="badge badge-warn">0</span>' : (int)$s['question_count'] ?></td>
                    <td><?= (int)$s['attempts'] ?></td>
                    <td><?= $avg ?></td>
                    <td><span class="badge <?= $active ? 'badge-on' : 'badge-off' ?>"><?= $active ? 'Visible' : 'Hidden' ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a href="/admin/simulations.php?edit=<?= (int)$s['id'] ?>">Edit</a>
                            <a href="/simulations.php?id=<?= (int)$s['id'] ?>&amp;mode=study" target="_blank" rel="noopener">Preview</a>
                            <form method="POST" action="/admin/simulations.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= simAdminEsc($_SESSION['admin_csrf']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="simulation_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="link-btn <?= $active ? 'off' : '' ?>"><?= $active ? 'Hide' : 'Show' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

</main>
</div>
</body>
</html>