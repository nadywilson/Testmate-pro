
<?php
/**
 * TestMate – Administrator Study Materials
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

function materialEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function materialUrl($path): string
{
    $path = trim((string)($path ?? ''));

    if ($path === '') {
        return '';
    }

    // Existing database records may contain old XAMPP URLs.
    if (str_starts_with($path, '/testmate/')) {
        return substr($path, strlen('/testmate'));
    }

    if (str_starts_with($path, 'testmate/')) {
        return '/' . substr($path, strlen('testmate/'));
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return '/' . $path;
}

/**
 * Delete only uploaded files belonging to the
 * TestMate materials directory.
 */
function removeMaterialFile($path): void
{
    $url = materialUrl($path);

    if (!str_starts_with($url, '/uploads/materials/')) {
        return;
    }

    $filename = basename($url);

    if (
        $filename === '' ||
        $filename === '.' ||
        $filename === '..'
    ) {
        return;
    }

    $uploadDir = dirname(__DIR__)
        . '/uploads/materials/';

    $file = $uploadDir . $filename;

    if (is_file($file)) {
        @unlink($file);
    }
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$msg = '';
$error = '';
$edit_m = null;
$edit_id = max(0, (int)($_GET['edit'] ?? 0));

$topics = [];
$materials = [];

$uploadDir = dirname(__DIR__) . '/uploads/materials/';

$allowedTypes = [
    'jpg'  => 'image',
    'jpeg' => 'image',
    'png'  => 'image',
    'gif'  => 'image',
    'webp' => 'image',
    'pdf'  => 'pdf',
    'mp4'  => 'video',
    'webm' => 'video',
    'mov'  => 'video'
];

$allowedMime = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
    'pdf' => ['application/pdf'],
    'mp4' => ['video/mp4', 'video/quicktime'],
    'webm' => ['video/webm'],
    'mov' => ['video/quicktime', 'video/mp4']
];

// Load the record selected for editing.
try {
    if ($edit_id > 0) {
        $stmt = $conn->prepare("
            SELECT *
            FROM materials
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$edit_id]);
        $edit_m = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$edit_m) {
            $edit_id = 0;
            $error = 'The selected material was not found.';
        }
    }
} catch (PDOException $e) {
    error_log('Material edit lookup: ' . $e->getMessage());
    $error = 'Could not load the selected material.';
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['admin_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $action = (string)($_POST['action'] ?? 'save');

    // Delete material
    if ($action === 'delete') {

        $deleteId = (int)($_POST['material_id'] ?? 0);

        if ($deleteId <= 0) {
            $error = 'Invalid material ID.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT *
                    FROM materials
                    WHERE id = ?
                ");

                $stmt->execute([$deleteId]);
                $toDelete = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($toDelete) {
                    $stmt = $conn->prepare("
                        DELETE FROM materials
                        WHERE id = ?
                    ");

                    $stmt->execute([$deleteId]);

                    // Delete file only when no other
                    // material record uses the same path.
                    if (!empty($toDelete['file_path'])) {
                        $check = $conn->prepare("
                            SELECT COUNT(*)
                            FROM materials
                            WHERE file_path = ?
                        ");

                        $check->execute([
                            $toDelete['file_path']
                        ]);

                        if ((int)$check->fetchColumn() === 0) {
                            removeMaterialFile(
                                $toDelete['file_path']
                            );
                        }
                    }

                    header(
                        'Location: /admin/add-material.php?deleted=1'
                    );
                    exit;
                }

                $error = 'Material not found.';

            } catch (PDOException $e) {
                error_log(
                    'Material delete: ' . $e->getMessage()
                );

                $error = 'Could not delete this material.';
            }
        }
    }

    // Save or update material
    elseif ($action === 'save') {

        $tid = (int)($_POST['topic_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $pid = max(0, (int)($_POST['edit_id'] ?? 0));

        $existing = null;
        $newUploadedPath = null;

        try {
            if ($pid > 0) {
                $stmt = $conn->prepare("
                    SELECT *
                    FROM materials
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$pid]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    throw new RuntimeException(
                        'Material to update was not found.'
                    );
                }
            }

            $file_path = $existing['file_path'] ?? null;
            $file_type = $existing['file_type'] ?? 'image';

            if ($title === '') {
                throw new RuntimeException(
                    'Material title is required.'
                );
            }

            if ($tid <= 0) {
                throw new RuntimeException(
                    'Please select a topic.'
                );
            }

            $checkTopic = $conn->prepare("
                SELECT COUNT(*)
                FROM topics
                WHERE id = ?
            ");

            $checkTopic->execute([$tid]);

            if ((int)$checkTopic->fetchColumn() === 0) {
                throw new RuntimeException(
                    'The selected topic does not exist.'
                );
            }

            $removeFile = !empty($_POST['remove_file']);

            if ($removeFile) {
                $file_path = null;
                $file_type = 'image';
            }

            // Optional uploaded file
            if (
                isset($_FILES['material_file']) &&
                (int)$_FILES['material_file']['error']
                    !== UPLOAD_ERR_NO_FILE
            ) {
                $file = $_FILES['material_file'];

                if (
                    (int)$file['error'] !== UPLOAD_ERR_OK
                ) {
                    throw new RuntimeException(
                        'File upload failed. Check upload size limits.'
                    );
                }

                // Maximum file size: 100 MB
                if (
                    (int)$file['size'] <= 0 ||
                    (int)$file['size'] > 100 * 1024 * 1024
                ) {
                    throw new RuntimeException(
                        'File must be smaller than 100 MB.'
                    );
                }

                $ext = strtolower(pathinfo(
                    (string)$file['name'],
                    PATHINFO_EXTENSION
                ));

                if (!isset($allowedTypes[$ext])) {
                    throw new RuntimeException(
                        'Unsupported file type. Use an image, PDF or video.'
                    );
                }

                if (!is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException(
                        'Invalid uploaded file.'
                    );
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if (
                    !in_array(
                        $mime,
                        $allowedMime[$ext],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'File content does not match the selected file type.'
                    );
                }

                if (!is_dir($uploadDir)) {
                    if (
                        !mkdir(
                            $uploadDir,
                            0755,
                            true
                        ) &&
                        !is_dir($uploadDir)
                    ) {
                        throw new RuntimeException(
                            'Could not create materials upload directory.'
                        );
                    }
                }

                $filename = 'm_'
                    . bin2hex(random_bytes(12))
                    . '.' . $ext;

                $destination = $uploadDir . $filename;

                if (!move_uploaded_file(
                    $file['tmp_name'],
                    $destination
                )) {
                    throw new RuntimeException(
                        'Unable to save uploaded material.'
                    );
                }

                $newUploadedPath =
                    '/uploads/materials/' . $filename;

                $file_path = $newUploadedPath;
                $file_type = $allowedTypes[$ext];
            }

            if ($pid > 0) {
                $stmt = $conn->prepare("
                    UPDATE materials
                    SET
                        topic_id = :topic_id,
                        title = :title,
                        content = :content,
                        file_type = :file_type,
                        file_path = :file_path,
                        sort_order = :sort_order
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':topic_id' => $tid,
                    ':title' => $title,
                    ':content' => $content,
                    ':file_type' => $file_type,
                    ':file_path' => $file_path,
                    ':sort_order' => $sort_order,
                    ':id' => $pid
                ]);

                $msg = 'Material updated successfully.';

                $edit_id = $pid;

                $stmt = $conn->prepare("
                    SELECT *
                    FROM materials
                    WHERE id = ?
                ");

                $stmt->execute([$pid]);
                $edit_m = $stmt->fetch(PDO::FETCH_ASSOC);

            } else {
                $stmt = $conn->prepare("
                    INSERT INTO materials
                    (
                        topic_id,
                        title,
                        content,
                        file_type,
                        file_path,
                        sort_order
                    )
                    VALUES
                    (
                        :topic_id,
                        :title,
                        :content,
                        :file_type,
                        :file_path,
                        :sort_order
                    )
                ");

                $stmt->execute([
                    ':topic_id' => $tid,
                    ':title' => $title,
                    ':content' => $content,
                    ':file_type' => $file_type,
                    ':file_path' => $file_path,
                    ':sort_order' => $sort_order
                ]);

                $msg = 'Material added successfully.';

                $edit_id = 0;
                $edit_m = null;
            }

            // Remove an old uploaded file after the
            // database has been updated successfully.
            if (
                $existing &&
                !empty($existing['file_path']) &&
                $existing['file_path'] !== $file_path
            ) {
                $check = $conn->prepare("
                    SELECT COUNT(*)
                    FROM materials
                    WHERE file_path = ?
                ");

                $check->execute([
                    $existing['file_path']
                ]);

                if ((int)$check->fetchColumn() === 0) {
                    removeMaterialFile(
                        $existing['file_path']
                    );
                }
            }

        } catch (Throwable $e) {
            // Clean up newly uploaded files when
            // saving the database record fails.
            if ($newUploadedPath !== null) {
                removeMaterialFile($newUploadedPath);
            }

            error_log(
                'TestMate material save: ' . $e->getMessage()
            );

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to save material. Check the server terminal.';
        }
    }
}

if (isset($_GET['deleted'])) {
    $msg = 'Material deleted successfully.';
}

// Load available topics and recent materials.
try {
    $topics = $conn->query("
        SELECT *
        FROM topics
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $materials = $conn->query("
        SELECT
            m.*,
            t.name AS topic_name
        FROM materials m
        LEFT JOIN topics t
            ON m.topic_id = t.id
        ORDER BY
            m.topic_id,
            m.sort_order,
            m.id DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log(
        'TestMate materials loading: ' . $e->getMessage()
    );

    $error = 'Unable to load study materials.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    <?= $edit_id ? 'Edit' : 'Add' ?> Material – TestMate Admin
</title>

<link rel="stylesheet" href="/css/style.css">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f6fa;
    color: #2c3e50;
}

.navbar {
    min-height: 64px;
    background: #2c3e50;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 12px 26px;
}

.navbar a {
    color: white;
    text-decoration: none;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
}

.brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 7px;
    background: #3498db;
    color: white;
    display: grid;
    place-items: center;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 24px;
    font-size: 14px;
}

.btn-logout {
    background: #e74c3c;
    padding: 11px 17px;
    border-radius: 7px;
}

.admin-wrap {
    display: flex;
    min-height: calc(100vh - 64px);
}

.sidebar {
    width: 247px;
    background: #1a252f;
    color: white;
    padding: 25px 0;
    flex-shrink: 0;
}

.sidebar h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8191a0;
    padding: 0 21px;
    margin: 25px 0 10px;
}

.sidebar h3:first-child {
    margin-top: 0;
}

.sidebar a {
    display: block;
    padding: 13px 21px;
    color: #e4eaf0;
    text-decoration: none;
    font-size: 14px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #303e49;
    color: white;
}

.main-content {
    flex: 1;
    min-width: 0;
    padding: 32px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 14px;
}

.form-control {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52,152,219,.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 120px;
}

.upload-area {
    position: relative;
    border: 2px dashed #cbd5e1;
    background: #f8fafc;
    padding: 28px 20px;
    border-radius: 10px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
}

.upload-area:hover {
    background: #eff6ff;
    border-color: #3498db;
}

.upload-area input[type="file"] {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.btn {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 11px 17px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.btn-primary {
    background: #3498db;
    color: white;
}

.btn-primary:hover {
    background: #247bb5;
}

.btn-outline {
    background: white;
    color: #2c3e50;
    border: 1px solid #cbd5e1;
}

.alert {
    padding: 14px 18px;
    margin-bottom: 17px;
    border-radius: 8px;
    font-size: 14px;
}

.alert-success {
    background: #e5f6eb;
    color: #207744;
}

.alert-error {
    background: #fdecea;
    color: #a93226;
}

.material-row {
    background: white;
    border-radius: 10px;
    padding: 15px 18px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.material-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.material-actions a {
    text-decoration: none;
    color: #3498db;
    font-size: 13px;
}

.delete-button {
    background: none;
    border: none;
    padding: 0;
    color: #e74c3c;
    font-size: 13px;
    cursor: pointer;
}

.preview-box {
    background: #f0f4f8;
    padding: 14px;
    border-radius: 8px;
    margin-top: 12px;
}

@media (max-width: 760px) {
    .admin-wrap {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 10px;
    }

    .sidebar h3 {
        margin: 12px 15px 5px;
    }

    .sidebar a {
        display: inline-block;
        padding: 10px;
    }

    .main-content {
        padding: 20px 14px;
    }

    .navbar {
        padding: 12px 15px;
        flex-wrap: wrap;
    }

    .material-row {
        align-items: flex-start;
        flex-direction: column;
    }
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
    <a href="/admin/assign-quiz.php">Assign Quiz</a>

    <h3>Content</h3>
    <a href="/admin/simulations.php">Simulations</a>
    <a href="/admin/activities.php">Activities</a>
    <a href="/admin/materials.php">Materials</a>
    <a href="/admin/add-material.php" class="active">
        Add Material
    </a>

</aside>

<main class="main-content">

    <h1 style="font-size:23px;margin:0 0 22px;">
        <?= $edit_id ? 'Edit Material' : 'Add Study Material' ?>
    </h1>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-success">
            <?= materialEsc($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <?= materialEsc($error) ?>
        </div>
    <?php endif; ?>

    <section class="card"
             style="max-width:750px;margin-bottom:30px;">

        <form
            method="POST"
            action="/admin/add-material.php<?= $edit_id ? '?edit=' . $edit_id : '' ?>"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= materialEsc($_SESSION['admin_csrf']) ?>"
            >

            <input type="hidden" name="action" value="save">

            <input
                type="hidden"
                name="edit_id"
                value="<?= (int)$edit_id ?>"
            >

            <div class="form-group">

                <label for="topic_id">Topic</label>

                <select
                    name="topic_id"
                    id="topic_id"
                    class="form-control"
                    required
                >
                    <option value="">Select a topic</option>

                    <?php foreach ($topics as $t): ?>
                        <option
                            value="<?= (int)$t['id'] ?>"
                            <?= (int)($edit_m['topic_id'] ?? 0) ===
                                (int)$t['id'] ? 'selected' : '' ?>
                        >
                            <?= materialEsc($t['icon'] ?? '') ?>
                            <?= materialEsc($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </div>

            <div class="form-group">

                <label for="title">Title</label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    class="form-control"
                    placeholder="e.g. Traffic Light Rules"
                    value="<?= materialEsc($edit_m['title'] ?? '') ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label for="content">
                    Content
                    <small style="color:#888;font-weight:400;">
                        (Reading Mode content, optional when uploading media)
                    </small>
                </label>

                <textarea
                    id="content"
                    name="content"
                    rows="6"
                    class="form-control"
                    placeholder="Write learning material here..."
                ><?= materialEsc($edit_m['content'] ?? '') ?></textarea>

            </div>

            <div class="form-group">

                <label>
                    Upload File
                    <small style="color:#888;font-weight:400;">
                        (Image, PDF or Video)
                    </small>
                </label>

                <div class="upload-area" id="uploadArea">

                    <input
                        type="file"
                        id="material_file"
                        name="material_file"
                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.mp4,.webm,.mov"
                        onchange="previewMaterialFile(this)"
                    >

                    <div style="font-size:30px;margin-bottom:8px;">
                        &#128206;
                    </div>

                    <div style="font-size:14px;color:#666;">
                        <strong style="color:#3498db;">
                            Click to upload
                        </strong>
                        or drag and drop
                    </div>

                    <p style="font-size:12px;color:#999;margin:8px 0 0;">
                        JPG, PNG, GIF, WEBP, PDF, MP4, WEBM, MOV
                    </p>

                </div>

                <div id="filePreview"
                     style="display:none;margin-top:12px;">

                    <p id="previewFileName"
                       style="font-size:13px;color:#2c3e50;">
                    </p>

                    <p style="color:#27ae60;font-size:12px;">
                        File selected
                    </p>
                </div>

                <?php if ($edit_m && !empty($edit_m['file_path'])): ?>

                    <?php
                    $currentFile = materialUrl(
                        $edit_m['file_path']
                    );

                    $currentType = $edit_m['file_type'] ?? 'image';
                    ?>

                    <div class="preview-box">

                        <p style="font-size:13px;color:#666;">
                            Current File:
                            <?= materialEsc($currentType) ?>
                        </p>

                        <?php if ($currentType === 'image'): ?>

                            <img
                                src="<?= materialEsc($currentFile) ?>"
                                alt="Current study material image"
                                style="max-width:220px;width:100%;border-radius:6px;"
                            >

                        <?php elseif ($currentType === 'pdf'): ?>

                            <a
                                href="<?= materialEsc($currentFile) ?>"
                                target="_blank"
                                rel="noopener"
                                style="color:#3498db;"
                            >
                                View Current PDF
                            </a>

                        <?php elseif ($currentType === 'video'): ?>

                            <video
                                src="<?= materialEsc($currentFile) ?>"
                                controls
                                preload="metadata"
                                style="max-width:260px;width:100%;border-radius:6px;"
                            ></video>

                        <?php endif; ?>

                        <label style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            margin-top:14px;
                            font-size:13px;
                            cursor:pointer;
                        ">
                            <input
                                type="checkbox"
                                name="remove_file"
                                value="1"
                            >
                            Remove current file
                        </label>

                    </div>

                <?php endif; ?>

            </div>

            <div class="form-group">

                <label for="sort_order">
                    Sort Order
                    <small style="color:#888;font-weight:400;">
                        (Lower numbers appear first)
                    </small>
                </label>

                <input
                    type="number"
                    name="sort_order"
                    id="sort_order"
                    class="form-control"
                    value="<?= (int)($edit_m['sort_order'] ?? 0) ?>"
                >

            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px;">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <?= $edit_id ? 'Update Material' : 'Add Material' ?>
                </button>

                <?php if ($edit_id > 0): ?>

                    <a
                        href="/admin/add-material.php"
                        class="btn btn-outline"
                    >
                        Cancel Edit
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </section>

    <h2 style="font-size:18px;margin-bottom:15px;">
        Recent Materials
    </h2>

    <?php if (empty($materials)): ?>

        <section class="card" style="text-align:center;color:#888;">
            No study materials added yet.
        </section>

    <?php else: ?>

        <?php foreach ($materials as $m): ?>

            <div class="material-row">

                <div>
                    <div style="
                        font-size:14px;
                        font-weight:700;
                        margin-bottom:6px;
                    ">
                        <?= materialEsc($m['title']) ?>
                    </div>

                    <div style="font-size:12px;color:#888;">
                        <?= materialEsc($m['topic_name'] ?? 'Unknown Topic') ?>
                        ·
                        <?= materialEsc($m['file_type'] ?? 'Text') ?>
                    </div>
                </div>

                <div class="material-actions">

                    <a href="/admin/add-material.php?edit=<?= (int)$m['id'] ?>">
                        Edit
                    </a>

                    <form
                        method="POST"
                        action="/admin/add-material.php"
                        style="display:inline;"
                        onsubmit="return confirm('Permanently delete this study material?');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= materialEsc($_SESSION['admin_csrf']) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="delete"
                        >

                        <input
                            type="hidden"
                            name="material_id"
                            value="<?= (int)$m['id'] ?>"
                        >

                        <button
                            type="submit"
                            class="delete-button"
                        >
                            Delete
                        </button>

                    </form>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</main>
</div>

<script>
function previewMaterialFile(input) {

    const preview = document.getElementById('filePreview');
    const name = document.getElementById('previewFileName');
    const area = document.getElementById('uploadArea');

    if (input.files && input.files.length > 0) {

        const file = input.files[0];

        name.textContent = file.name;

        preview.style.display = 'block';

        area.style.borderColor = '#27ae60';

    } else {

        preview.style.display = 'none';

        area.style.borderColor = '#cbd5e1';

    }
}
</script>

</body>
</html>
