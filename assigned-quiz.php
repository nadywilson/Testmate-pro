<?php
require 'includes/auth.php';
require 'includes/db_connect.php';

$user_id = $_SESSION['user_id'];

// Block admins
if ($_SESSION['role'] === 'admin') {
    header("Location: /testmate/admin/index.php");
    exit();
}

// Get assigned questions
$q = $conn->prepare("
    SELECT aq.id AS assignment_id, q.*
    FROM assigned_quizzes aq
    JOIN questions q ON aq.question_id = q.id
    WHERE aq.user_id = ? AND aq.is_completed = 0
    ORDER BY aq.assigned_at ASC
");
$q->bind_param("i", $user_id);
$q->execute();
$questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($questions)) {
    include 'includes/header.php';
    echo '<div style="max-width:600px;margin:60px auto;text-align:center;">
        <h2 style="margin-bottom:12px;color:#2c3e50;">No Assigned Questions</h2>
        <p style="color:#888;margin-bottom:20px;">Your instructor has not assigned any questions yet.</p>
        <a href="/testmate/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
    </div>';
    include 'includes/footer.php';
    exit();
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignment_ids = $_POST['assignment_ids'];
    $qids           = $_POST['qids'];
    $corrects       = $_POST['correct'];
    $total          = count($qids);
    $score          = 0;
    $results        = [];

    for ($i = 0; $i < $total; $i++) {
        $qid         = $qids[$i];
        $user_answer = strtoupper(trim($_POST['ans_' . $qid] ?? ''));
        $right       = strtoupper(trim($corrects[$i]));
        $is_correct  = ($user_answer === $right);
        if ($is_correct) $score++;

        // Mark assignment as completed
        $upd = $conn->prepare("UPDATE assigned_quizzes SET is_completed=1, completed_at=NOW() WHERE id=?");
        $upd->bind_param("i", $assignment_ids[$i]);
        $upd->execute();

        // Handle failed questions
        if (!$is_correct) {
            $tid = $_POST['topic_ids'][$i];
            $stmt = $conn->prepare("INSERT INTO failed_questions (user_id, question_id, topic_id, times_failed) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE times_failed = times_failed + 1, last_failed = NOW()");
            $stmt->bind_param("iii", $user_id, $qid, $tid);
            $stmt->execute();
        }

        $results[] = [
            'question'    => $_POST['qtexts'][$i],
            'user_answer' => $user_answer,
            'correct'     => $right,
            'is_correct'  => $is_correct,
            'option_a'    => $_POST['opta'][$i],
            'option_b'    => $_POST['optb'][$i],
            'option_c'    => $_POST['optc'][$i],
            'option_d'    => $_POST['optd'][$i],
            'explanation' => $_POST['explanations'][$i],
            'image_path'  => $_POST['images'][$i],
            'file_type'   => $_POST['filetypes'][$i] ?? 'image',
            'topic_id'    => $_POST['topic_ids'][$i] ?? 0,
        ];
    }

    $percentage = round($score / $total * 100);

    // Look up topic names for the "go study this" links (assigned questions can span multiple topics)
    $topic_names = [];
    $tids = array_unique(array_column($results, 'topic_id'));
    if (!empty($tids)) {
        $in = implode(',', array_map('intval', $tids));
        $tr = $conn->query("SELECT id, name FROM topics WHERE id IN ($in)");
        while ($row = $tr->fetch_assoc()) { $topic_names[$row['id']] = $row['name']; }
    }

    include 'includes/header.php';
    ?>
    <div class="container" style="max-width:750px;">
        <div style="border-radius:14px;padding:36px;text-align:center;color:white;margin-bottom:28px;
            background:<?= $percentage >= 80 ? 'linear-gradient(135deg,#27ae60,#2ecc71)' : 'linear-gradient(135deg,#c0392b,#e74c3c)' ?>">
            <div style="font-size:48px;font-weight:800;"><?= $score ?>/<?= $total ?></div>
            <div style="font-size:22px;margin:8px 0;"><?= $percentage ?>%</div>
            <p style="opacity:.9;">Assigned Quiz Completed!</p>
        </div>

        <div style="display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap;">
            <a href="/testmate/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            <a href="/testmate/quiz.php" class="btn btn-outline">Take Another Quiz</a>
        </div>

        <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;">Answer Review</h2>

        <?php foreach ($results as $i => $r):
            $opts = ['A'=>$r['option_a'],'B'=>$r['option_b'],'C'=>$r['option_c'],'D'=>$r['option_d']];
        ?>
        <div class="card" style="margin-bottom:14px;border-left:4px solid <?= $r['is_correct'] ? '#27ae60' : '#e74c3c' ?>;">
            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:#999;">Question <?= $i+1 ?></span>
                <span class="badge <?= $r['is_correct'] ? 'badge-pass' : 'badge-fail' ?>"><?= $r['is_correct'] ? 'Correct' : 'Wrong' ?></span>
            </div>

            <?php if ($r['image_path']):
                $rftype = $r['file_type'] ?? 'image';
            ?>
                <?php if ($rftype === 'pdf'): ?>
                <iframe src="<?= htmlspecialchars($r['image_path']) ?>" style="width:100%;height:300px;border:1px solid #eee;border-radius:8px;margin-bottom:12px;"></iframe>
                <?php elseif ($rftype === 'video'): ?>
                <video src="<?= htmlspecialchars($r['image_path']) ?>" controls style="max-width:100%;max-height:250px;border-radius:8px;margin-bottom:12px;display:block;"></video>
                <?php else: ?>
                <img src="<?= htmlspecialchars($r['image_path']) ?>"
                     style="max-width:100%;max-height:200px;border-radius:8px;margin-bottom:12px;display:block;">
                <?php endif; ?>
            <?php endif; ?>

            <p style="font-weight:500;margin-bottom:12px;"><?= htmlspecialchars($r['question']) ?></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px;">
                <?php foreach ($opts as $key => $val):
                    $is_user    = ($key === $r['user_answer']);
                    $is_correct = ($key === $r['correct']);
                    $bg = '#f8f9fa';
                    if ($is_correct) $bg = '#eafaf1';
                    elseif ($is_user && !$is_correct) $bg = '#fdecea';
                ?>
                <div style="background:<?= $bg ?>;padding:8px 12px;border-radius:6px;font-size:14px;">
                    <strong><?= $key ?>.</strong> <?= htmlspecialchars($val) ?>
                    <?php if ($is_correct): ?> ✓<?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($r['explanation']): ?>
            <div style="background:#f0f8ff;border-left:3px solid #3498db;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;color:#555;">
                <?= htmlspecialchars($r['explanation']) ?>
            </div>
            <?php endif; ?>

            <?php if (!$r['is_correct'] && isset($topic_names[$r['topic_id']])): ?>
            <div style="margin-top:8px;font-size:13px;color:#888;">
                Need help with this?
                <a href="/testmate/study-materials.php?topic=<?= $r['topic_id'] ?>&mode=read" style="color:#3498db;font-weight:600;">Read the <?= htmlspecialchars($topic_names[$r['topic_id']]) ?> notes</a>
                or
                <a href="/testmate/study-materials.php?topic=<?= $r['topic_id'] ?>&mode=video" style="color:#e67e22;font-weight:600;">Watch the video</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    include 'includes/footer.php';
    exit();
}
?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
    <h1>Assigned Quiz</h1>
    <p>Your instructor has assigned these <?= count($questions) ?> question<?= count($questions) > 1 ? 's' : '' ?> for you</p>
</div>

<div class="container" style="max-width:750px;">

    <div style="background:#eaf4ff;border-left:4px solid #3498db;border-radius:10px;padding:14px 20px;margin-bottom:24px;">
        <div style="font-weight:600;color:#2471a3;margin-bottom:2px;">Instructor Assigned Quiz</div>
        <div style="font-size:14px;color:#555;">Complete all questions below. Your results will be saved.</div>
    </div>

    <form method="POST" id="assignedForm">
        <?php foreach ($questions as $i => $q): ?>
        <div class="card" style="margin-bottom:16px;border-left:4px solid #e0e0e0;transition:border-color .2s;" id="card-<?= $q['id'] ?>">
            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:#999;">Question <?= $i+1 ?> of <?= count($questions) ?></span>
            </div>

            <?php if (!empty($q['image_path'])):
                $ftype = $q['file_type'] ?? 'image';
            ?>
                <?php if ($ftype === 'pdf'): ?>
                <iframe src="<?= htmlspecialchars($q['image_path']) ?>" style="width:100%;height:350px;border:1px solid #eee;border-radius:8px;margin-bottom:12px;display:block;"></iframe>
                <?php elseif ($ftype === 'video'): ?>
                <video src="<?= htmlspecialchars($q['image_path']) ?>" controls autoplay muted loop playsinline
                       style="max-width:100%;max-height:300px;border-radius:8px;margin-bottom:12px;display:block;"></video>
                <?php else: ?>
                <img src="<?= htmlspecialchars($q['image_path']) ?>"
                     style="max-width:100%;max-height:250px;border-radius:8px;margin-bottom:12px;display:block;object-fit:contain;">
                <?php endif; ?>
            <?php endif; ?>

            <p style="font-size:16px;font-weight:500;margin-bottom:14px;line-height:1.6;"><?= htmlspecialchars($q['question']) ?></p>

            <input type="hidden" name="assignment_ids[]" value="<?= $q['assignment_id'] ?>">
            <input type="hidden" name="qids[]"           value="<?= $q['id'] ?>">
            <input type="hidden" name="correct[]"        value="<?= $q['correct_answer'] ?>">
            <input type="hidden" name="qtexts[]"         value="<?= htmlspecialchars($q['question']) ?>">
            <input type="hidden" name="opta[]"           value="<?= htmlspecialchars($q['option_a']) ?>">
            <input type="hidden" name="optb[]"           value="<?= htmlspecialchars($q['option_b']) ?>">
            <input type="hidden" name="optc[]"           value="<?= htmlspecialchars($q['option_c']) ?>">
            <input type="hidden" name="optd[]"           value="<?= htmlspecialchars($q['option_d']) ?>">
            <input type="hidden" name="explanations[]"   value="<?= htmlspecialchars($q['explanation'] ?? '') ?>">
            <input type="hidden" name="images[]"         value="<?= htmlspecialchars($q['image_path'] ?? '') ?>">
            <input type="hidden" name="filetypes[]"      value="<?= htmlspecialchars($q['file_type'] ?? 'image') ?>">
            <input type="hidden" name="topic_ids[]"      value="<?= $q['topic_id'] ?>">

            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $key=>$val): ?>
                <label style="display:flex;align-items:center;gap:12px;padding:12px 16px;border:2px solid #e8e8e8;border-radius:8px;cursor:pointer;transition:all .15s;font-size:15px;">
                    <input type="radio" name="ans_<?= $q['id'] ?>" value="<?= $key ?>"
                           onchange="document.getElementById('card-<?= $q['id'] ?>').style.borderLeftColor='#27ae60'"
                           style="accent-color:#3498db;width:18px;height:18px;flex-shrink:0;">
                    <span style="width:26px;height:26px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= $key ?></span>
                    <?= htmlspecialchars($val) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="text-align:center;padding:24px 0;">
            <button type="submit" class="btn btn-primary btn-lg">Submit Assigned Quiz</button>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>