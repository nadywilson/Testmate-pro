<?php
/**
 * TestMate – Administrator: Study Materials overview
 * PHP 8.5 + PDO SQLite
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

function matListEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$topics = [];
$error = '';
$totals = ['questions' => 0, 'materials' => 0, 'videos' => 0, 'sims' => 0];

try {
    $topics = $conn->query("
        SELECT
            t.id,
            t.name,
            t.description,
            (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id) AS question_count,
            (SELECT COUNT(*) FROM materials m WHERE m.topic_id = t.id) AS material_count,
            (SELECT COUNT(*) FROM materials m
               WHERE m.topic_id = t.id
                 AND LOWER(TRIM(m.file_type)) = 'video'
                 AND TRIM(COALESCE(m.file_path, '')) <> '') AS video_count,
            (SELECT COUNT(*) FROM simulations s
               WHERE s.topic_id = t.id AND s.is_active = 1) AS sim_count
        FROM topics t
        ORDER BY t.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($topics as $t) {
        $totals['questions'] += (int)$t['question_count'];
        $totals['materials'] += (int)$t['material_count'];
        $totals['videos']    += (int)$t['video_count'];
        $totals['sims']      += (int)$t['sim_count'];
    }
} catch (PDOException $e) {
    error_log('TestMate admin materials: ' . $e->getMessage());
    $error = 'Unable to load study materials. Check the PHP server terminal.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Materials – TestMate Admin</title>
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
.btn{display:inline-block;border:1px solid transparent;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-primary{background:#3498db;color:#fff!important}
.btn-primary:hover{background:#247bb5}
.btn-outline{background:#fff;color:#2c3e50!important;border-color:#cbd5e1}
.btn-outline:hover{background:#f0f8ff}
.btn-sm{padding:7px 12px;font-size:13px}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:26px}
.stat-card{background:#fff;border:1px solid #edf0f4;border-radius:10px;padding:20px 14px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.stat-card .num{display:block;font-size:2rem;font-weight:800;line-height:1.2}
.stat-card .lbl{display:block;margin-top:6px;font-size:13px;color:#888}
.search{width:100%;max-width:360px;padding:11px 14px;border:1px solid #d9dfe6;border-radius:9px;font-size:14px;margin-bottom:18px;background:#fff}
.topic-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.topic-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,.05);display:flex;flex-direction:column}
.topic-card[hidden]{display:none}
.topic-card h2{font-size:16px;margin:0 0 6px}
.topic-card p{color:#888;font-size:13px;line-height:1.5;margin:0 0 14px;flex:1}
.counts{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:16px}
.counts div{background:#f6f8fb;border-radius:8px;padding:8px 4px;text-align:center}
.counts strong{display:block;font-size:18px}
.counts span{font-size:11px;color:#888}
.empty-flag{display:inline-block;margin-bottom:12px;padding:4px 9px;border-radius:20px;background:#fff4de;color:#a97717;font-size:12px;font-weight:700}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.notice{padding:14px;border-radius:8px;background:#fdecea;color:#a93226;margin-bottom:18px}
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
    <a href="/admin/simulations.php">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php" class="active">Materials</a>
    <a href="/admin/add-material.php">Add Material</a>
</aside>

<main class="main-content">

    <div class="heading-row">
        <div>
            <h1>Study Materials</h1>
            <p>Learning content available for each topic.</p>
        </div>
        <a href="/admin/add-material.php" class="btn btn-primary">Add Material</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice"><?= matListEsc($error) ?></div>
    <?php endif; ?>

    <section class="stat-grid">
        <div class="stat-card"><span class="num" style="color:#2c3e50"><?= count($topics) ?></span><span class="lbl">Topics</span></div>
        <div class="stat-card"><span class="num" style="color:#27ae60"><?= $totals['materials'] ?></span><span class="lbl">Materials</span></div>
        <div class="stat-card"><span class="num" style="color:#e67e22"><?= $totals['videos'] ?></span><span class="lbl">Videos</span></div>
        <div class="stat-card"><span class="num" style="color:#8e44ad"><?= $totals['sims'] ?></span><span class="lbl">Active Simulations</span></div>
        <div class="stat-card"><span class="num" style="color:#3498db"><?= $totals['questions'] ?></span><span class="lbl">Questions</span></div>
    </section>

    <input type="search" id="topicSearch" class="search" placeholder="Search topics..." aria-label="Search topics">

    <div class="topic-grid" id="topicGrid">
        <?php foreach ($topics as $t): $tid = (int)$t['id']; ?>
            <article class="topic-card">
                <h2><?= matListEsc($t['name']) ?></h2>
                <?php if ((int)$t['material_count'] === 0): ?>
                    <span class="empty-flag">No study material yet</span>
                <?php endif; ?>
                <p><?= matListEsc($t['description'] ?? '') ?></p>
                <div class="counts">
                    <div><strong style="color:#27ae60"><?= (int)$t['material_count'] ?></strong><span>Materials</span></div>
                    <div><strong style="color:#e67e22"><?= (int)$t['video_count'] ?></strong><span>Videos</span></div>
                    <div><strong style="color:#8e44ad"><?= (int)$t['sim_count'] ?></strong><span>Sims</span></div>
                    <div><strong style="color:#3498db"><?= (int)$t['question_count'] ?></strong><span>Questions</span></div>
                </div>
                <div class="actions">
                    <a class="btn btn-outline btn-sm" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=read" target="_blank" rel="noopener">View</a>
                    <a class="btn btn-primary btn-sm" href="/admin/add-material.php">Add</a>
                    <a class="btn btn-outline btn-sm" href="/admin/questions.php?topic=<?= $tid ?>">Questions</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

</main>
</div>

<script>
(() => {
  const input = document.getElementById('topicSearch');
  const cards = [...document.querySelectorAll('#topicGrid .topic-card')];
  input?.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    cards.forEach(c => { c.hidden = q !== '' && !c.textContent.toLowerCase().includes(q); });
  });
})();
</script>
</body>
</html>