<?php
require 'includes/auth.php';
require 'includes/db_connect.php';

$user_id  = $_SESSION['user_id'];
$topic_id = isset($_GET['topic']) ? (int)$_GET['topic'] : 0;

// Handle activity completion via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_activity'])) {
    $act_id   = (int)$_POST['activity_id'];
    $score    = (int)$_POST['score'];
    $max      = (int)$_POST['max_score'];
    $time     = (int)$_POST['time_spent'];

    $check = $conn->prepare("SELECT id FROM activity_results WHERE user_id=? AND activity_id=?");
    $check->bind_param("ii", $user_id, $act_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $upd = $conn->prepare("UPDATE activity_results SET score=?, max_score=?, completed=1, time_spent=?, completed_at=NOW() WHERE user_id=? AND activity_id=?");
        $upd->bind_param("iiiii", $score, $max, $time, $user_id, $act_id);
        $upd->execute();
    } else {
        $ins = $conn->prepare("INSERT INTO activity_results (user_id, activity_id, score, max_score, completed, time_spent, completed_at) VALUES (?,?,?,?,1,?,NOW())");
        $ins->bind_param("iiiii", $user_id, $act_id, $score, $max, $time);
        $ins->execute();
    }
    echo json_encode(['success' => true]);
    exit();
}

// Get activities
if ($topic_id > 0) {
    $a = $conn->prepare("
        SELECT act.*, t.name AS topic_name,
               ar.completed, ar.score, ar.max_score
        FROM activities act
        LEFT JOIN topics t ON act.topic_id = t.id
        LEFT JOIN activity_results ar ON act.id = ar.activity_id AND ar.user_id = ?
        WHERE act.is_active = 1 AND act.topic_id = ?
        ORDER BY act.id
    ");
    $a->bind_param("ii", $user_id, $topic_id);
} else {
    $a = $conn->prepare("
        SELECT act.*, t.name AS topic_name,
               ar.completed, ar.score, ar.max_score
        FROM activities act
        LEFT JOIN topics t ON act.topic_id = t.id
        LEFT JOIN activity_results ar ON act.id = ar.activity_id AND ar.user_id = ?
        WHERE act.is_active = 1
        ORDER BY act.topic_id, act.id
    ");
    $a->bind_param("i", $user_id);
}
$a->execute();
$activities = $a->get_result()->fetch_all(MYSQLI_ASSOC);
$topics     = $conn->query("SELECT * FROM topics ORDER BY id")->fetch_all(MYSQLI_ASSOC);
?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
    <h1>Interactive Activities</h1>
    <p>Learn through drag and drop, map simulations and 3D animations</p>
</div>

<div class="container">

    <!-- Topic Filter -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
        <a href="/testmate/activities.php"
           class="btn <?= $topic_id === 0 ? 'btn-primary' : 'btn-outline' ?>"
           style="font-size:14px;padding:8px 16px;">All Topics</a>
        <?php foreach ($topics as $t): ?>
        <a href="/testmate/activities.php?topic=<?= $t['id'] ?>"
           class="btn <?= $topic_id === $t['id'] ? 'btn-primary' : 'btn-outline' ?>"
           style="font-size:14px;padding:8px 16px;"><?= htmlspecialchars($t['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($activities)): ?>
    <div class="card" style="text-align:center;padding:60px;">
        <h2 style="color:#888;margin-bottom:12px;">No Activities Yet</h2>
        <p style="color:#aaa;margin-bottom:20px;">Activities will appear here when your instructor adds them.</p>
        <a href="/testmate/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
    <?php else: ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">
        <?php foreach ($activities as $act): ?>
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="background:<?= $act['type'] === 'h5p' ? '#2c3e50' : ($act['type'] === 'leaflet' ? '#27ae60' : '#8e44ad') ?>;padding:16px 20px;color:white;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.8;margin-bottom:4px;">
                    <?php if ($act['type'] === 'h5p'): ?>Interactive Activity
                    <?php elseif ($act['type'] === 'leaflet'): ?>Map Simulation
                    <?php else: ?>3D Animation
                    <?php endif; ?>
                </div>
                <h3 style="font-size:16px;font-weight:700;"><?= htmlspecialchars($act['title']) ?></h3>
                <?php if ($act['topic_name']): ?>
                <div style="font-size:12px;opacity:.8;margin-top:4px;"><?= htmlspecialchars($act['topic_name']) ?></div>
                <?php endif; ?>
            </div>
            <div style="padding:16px 20px;">
                <p style="color:#666;font-size:14px;margin-bottom:14px;line-height:1.6;"><?= htmlspecialchars($act['description']) ?></p>

                <?php if ($act['completed']): ?>
                <div style="background:#eafaf1;border-radius:8px;padding:8px 12px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:#27ae60;font-size:13px;font-weight:600;">Completed</span>
                    <span style="color:#27ae60;font-size:13px;"><?= $act['score'] ?>/<?= $act['max_score'] ?> points</span>
                </div>
                <?php else: ?>
                <div style="background:#f8f9fa;border-radius:8px;padding:8px 12px;margin-bottom:14px;">
                    <span style="color:#888;font-size:13px;">Not completed yet</span>
                </div>
                <?php endif; ?>

                <a href="/testmate/activity-view.php?id=<?= $act['id'] ?>"
                   class="btn btn-primary btn-full" style="font-size:14px;">
                    <?= $act['completed'] ? 'Try Again' : 'Start Activity' ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>