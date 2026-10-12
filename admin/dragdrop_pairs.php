<?php
require '../includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /testmate/login.php");
    exit();
}

$message = '';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM dragdrop_pairs WHERE id = $id");
    header("Location: /testmate/admin/dragdrop_pairs.php?msg=deleted");
    exit();
}

// Handle add new pair
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pair'])) {
    $topic_id = (int)$_POST['topic_id'];
    $left_text = trim($_POST['left_text']);
    $right_text = trim($_POST['right_text']);

    if ($topic_id > 0 && $left_text !== '' && $right_text !== '') {
        $stmt = $conn->prepare("INSERT INTO dragdrop_pairs (topic_id, left_text, right_text) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $topic_id, $left_text, $right_text);
        $stmt->execute();
        $stmt->close();
        header("Location: /testmate/admin/dragdrop_pairs.php?msg=added");
        exit();
    } else {
        $message = "All fields are required.";
    }
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_pair'])) {
    $id = (int)$_POST['pair_id'];
    $topic_id = (int)$_POST['topic_id'];
    $left_text = trim($_POST['left_text']);
    $right_text = trim($_POST['right_text']);

    if ($id > 0 && $topic_id > 0 && $left_text !== '' && $right_text !== '') {
        $stmt = $conn->prepare("UPDATE dragdrop_pairs SET topic_id = ?, left_text = ?, right_text = ? WHERE id = ?");
        $stmt->bind_param("issi", $topic_id, $left_text, $right_text, $id);
        $stmt->execute();
        $stmt->close();
        header("Location: /testmate/admin/dragdrop_pairs.php?msg=updated");
        exit();
    } else {
        $message = "All fields are required.";
    }
}

// Get topics for dropdown
$topics = $conn->query("SELECT id, name FROM topics ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Get all pairs with topic names
$pairs = $conn->query("
    SELECT dp.id, dp.topic_id, dp.left_text, dp.right_text, dp.created_at, t.name AS topic_name
    FROM dragdrop_pairs dp
    LEFT JOIN topics t ON dp.topic_id = t.id
    ORDER BY dp.topic_id ASC, dp.id ASC
")->fetch_all(MYSQLI_ASSOC);

$edit_pair = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $res = $conn->query("SELECT * FROM dragdrop_pairs WHERE id = $edit_id");
    if ($res && $res->num_rows > 0) {
        $edit_pair = $res->fetch_assoc();
    }
}

// Group pairs by topic for display
$grouped = [];
foreach ($pairs as $p) {
    $tid = $p['topic_id'] ?? 0;
    $tname = $p['topic_name'] ?? 'Uncategorized';
    if (!isset($grouped[$tid])) {
        $grouped[$tid] = ['name' => $tname, 'pairs' => []];
    }
    $grouped[$tid]['pairs'][] = $p;
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    if ($msg === 'added') $message = "Pair added successfully!";
    elseif ($msg === 'updated') $message = "Pair updated successfully!";
    elseif ($msg === 'deleted') $message = "Pair deleted successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drag & Drop Pairs – TestMate Admin</title>
    <link rel="stylesheet" href="/testmate/css/style.css">
    <style>
        .admin-wrap{display:flex;min-height:calc(100vh - 60px);}
        .sidebar{width:230px;background:#1a252f;color:white;padding:24px 0;flex-shrink:0;position:sticky;top:60px;height:calc(100vh - 60px);overflow-y:auto;}
        .sidebar h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);padding:0 20px;margin-bottom:8px;margin-top:20px;}
        .sidebar h3:first-child{margin-top:0;}
        .sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.8);text-decoration:none;font-size:14px;transition:all .15s;}
        .sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,.1);color:white;}
        .main-content{flex:1;padding:30px;background:#f5f6fa;}
        .card{background:white;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:20px;}
        .form-group{margin-bottom:16px;}
        .form-group label{display:block;font-weight:600;margin-bottom:6px;font-size:14px;color:#374151;}
        .form-group input,.form-group select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:#2563eb;}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .btn{padding:10px 20px;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600;transition:opacity .2s;}
        .btn-primary{background:#2563eb;color:white;}
        .btn-secondary{background:#6b7280;color:white;}
        .btn-danger{background:#dc2626;color:white;}
        .btn:hover{opacity:.9;}
        .alert{background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;}
        .alert-error{background:#fee2e2;color:#991b1b;}
        table{width:100%;border-collapse:collapse;font-size:14px;}
        th,td{padding:12px 16px;text-align:left;border-bottom:1px solid #e5e7eb;}
        th{background:#f9fafb;font-weight:600;color:#4b5563;font-size:12px;text-transform:uppercase;letter-spacing:.05em;}
        tr:hover{background:#f9fafb;}
        .actions{display:flex;gap:8px;}
        .topic-header{background:#eff6ff;color:#1e40af;padding:12px 16px;border-radius:8px 8px 0 0;font-weight:700;font-size:15px;margin-top:20px;}
        .topic-group{background:white;border-radius:0 0 8px 8px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;margin-bottom:24px;}
        .empty{text-align:center;padding:40px;color:#9ca3af;}
        .match-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f3f4f6;}
        .match-row:last-child{border-bottom:none;}
        .match-left,.match-right{flex:1;background:#f9fafb;padding:8px 12px;border-radius:6px;font-size:14px;}
        .match-arrow{color:#9ca3af;font-size:18px;}
    </style>
</head>
<body>
<nav class="navbar">
    <a href="/testmate/admin/index.php" class="brand"><span class="brand-icon">T</span> TestMate Admin</a>
    <div class="nav-links">
        <a href="/testmate/index.php" style="color:rgba(255,255,255,.8);font-size:14px;">← View Site</a>
        <a href="/testmate/logout.php" class="btn-logout">Logout</a>
    </div>
</nav>
<div class="admin-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <h1 style="font-size:22px;margin-bottom:6px;">🧩 Drag & Drop Pairs</h1>
        <p style="color:#888;font-size:14px;margin-bottom:24px;">Create matching pairs for drag-and-drop quiz questions.</p>

        <?php if ($message): ?>
        <div class="alert <?= strpos($message, 'error') !== false || strpos($message, 'required') !== false ? 'alert-error' : '' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Add / Edit Form -->
        <div class="card">
            <h2 style="font-size:16px;font-weight:700;margin-bottom:16px;"><?= $edit_pair ? '✏️ Edit Pair' : '➕ Add New Pair' ?></h2>
            <form method="POST">
                <input type="hidden" name="pair_id" value="<?= $edit_pair['id'] ?? '' ?>">

                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic_id" required>
                        <option value="">Select a topic...</option>
                        <?php foreach ($topics as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($edit_pair && $edit_pair['topic_id'] == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Left Side (Item to Drag)</label>
                        <input type="text" name="left_text" placeholder="e.g. Photosynthesis" 
                               value="<?= htmlspecialchars($edit_pair['left_text'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Right Side (Correct Match)</label>
                        <input type="text" name="right_text" placeholder="e.g. Process by which plants make food" 
                               value="<?= htmlspecialchars($edit_pair['right_text'] ?? '') ?>" required>
                    </div>
                </div>

                <div style="display:flex;gap:10px;">
                    <?php if ($edit_pair): ?>
                    <button type="submit" name="edit_pair" class="btn btn-primary">💾 Update Pair</button>
                    <a href="/testmate/admin/dragdrop_pairs.php" class="btn btn-secondary" style="text-decoration:none;">Cancel</a>
                    <?php else: ?>
                    <button type="submit" name="add_pair" class="btn btn-primary">➕ Add Pair</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Existing Pairs -->
        <h2 style="font-size:16px;font-weight:700;margin-bottom:12px;">Existing Pairs</h2>

        <?php if (empty($grouped)): ?>
        <div class="card empty">
            <p>No drag & drop pairs created yet.</p>
        </div>
        <?php else: ?>
            <?php foreach ($grouped as $tid => $group): ?>
            <div class="topic-header">📚 <?= htmlspecialchars($group['name']) ?></div>
            <div class="topic-group">
                <?php foreach ($group['pairs'] as $p): ?>
                <div class="match-row">
                    <div class="match-left"><?= htmlspecialchars($p['left_text']) ?></div>
                    <div class="match-arrow">↔</div>
                    <div class="match-right"><?= htmlspecialchars($p['right_text']) ?></div>
                    <div class="actions" style="margin-left:auto;">
                        <a href="?edit=<?= $p['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:6px 12px;text-decoration:none;">Edit</a>
                        <a href="?delete=<?= $p['id'] ?>" class="btn btn-danger" style="font-size:12px;padding:6px 12px;text-decoration:none;" 
                           onclick="return confirm('Delete this pair?')">Delete</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Database setup hint -->
        <div class="card" style="margin-top:30px;background:#f8f9fa;">
            <h3 style="font-size:15px;margin-bottom:12px;">🔧 Database Setup</h3>
            <p style="font-size:13px;color:#666;margin-bottom:12px;">If you get a "table not found" error, run this SQL in phpMyAdmin:</p>
            <pre style="background:#1a252f;color:#a8e6cf;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;">CREATE TABLE IF NOT EXISTS dragdrop_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    left_text VARCHAR(255) NOT NULL,
    right_text VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
);</pre>
        </div>
    </div>
</div>
</body>
</html>