
<?php
/**
 * TestMate - My Profile
 * PHP 8.5 + PDO SQLite
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id < 1) {
    header('Location: /login.php');
    exit;
}

function profileEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function profileImageUrl($path): string
{
    $path = trim(str_replace('\\', '/', (string)$path));

    if ($path === '') {
        return '';
    }

    // Accept only local profile image paths.
    $path = preg_replace('~^/?testmate/~i', '', $path);
    $path = ltrim($path, '/');

    if (
        str_contains($path, '..') ||
        !str_starts_with($path, 'uploads/profiles/')
    ) {
        return '';
    }

    return '/' . $path;
}

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$msg = '';
$error = '';

/* =====================================================
   FETCH ACCOUNT DETAILS
   ===================================================== */

$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit('Account not found.');
}

/* =====================================================
   HANDLE PROFILE FORMS
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['profile_csrf'], $token)) {

        $error = 'Your session expired. Refresh the page and try again.';

    } elseif ($action === 'update_profile') {

        $name = trim((string)($_POST['name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $lcode = (string)($_POST['licence_code'] ?? 'Code2');

        $allowedCodes = ['Code1', 'Code2', 'Code3'];

        if ($name === '') {

            $error = 'Please enter your full name.';

        } elseif (strlen($name) > 150) {

            $error = 'Your name is too long.';

        } elseif (strlen($bio) > 2000) {

            $error = 'Your biography is too long.';

        } elseif (!in_array($lcode, $allowedCodes, true)) {

            $error = 'Please select a valid licence code.';

        } else {

            $pic = (string)($user['profile_pic'] ?? '');
            $newImagePath = null;

            /* Profile picture upload */
            if (
                isset($_FILES['profile_pic']) &&
                is_array($_FILES['profile_pic']) &&
                (int)($_FILES['profile_pic']['error'] ?? UPLOAD_ERR_NO_FILE)
                    !== UPLOAD_ERR_NO_FILE
            ) {

                $file = $_FILES['profile_pic'];

                if (
                    (int)$file['error'] !== UPLOAD_ERR_OK
                ) {

                    $error = 'The profile picture could not be uploaded.';

                } elseif (
                    (int)$file['size'] > 5 * 1024 * 1024
                ) {

                    $error = 'Profile pictures must be smaller than 5 MB.';

                } elseif (
                    !is_uploaded_file(
                        (string)$file['tmp_name']
                    )
                ) {

                    $error = 'Invalid profile picture upload.';

                } else {

                    // Validate the actual image data,
                    // not only the filename extension.
                    $imageInfo = @getimagesize(
                        (string)$file['tmp_name']
                    );

                    $mime = $imageInfo['mime'] ?? '';

                    $extensions = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($extensions[$mime])) {

                        $error = 'Use a JPG, PNG, GIF or WEBP image.';

                    } else {

                        $extension = $extensions[$mime];

                        // The webroot is C:\projects\testmate.
                        $uploadDir =
                            __DIR__ .
                            DIRECTORY_SEPARATOR .
                            'uploads' .
                            DIRECTORY_SEPARATOR .
                            'profiles';

                        if (
                            !is_dir($uploadDir) &&
                            !mkdir($uploadDir, 0755, true) &&
                            !is_dir($uploadDir)
                        ) {

                            $error = 'Unable to create the profile image folder.';

                        } else {

                            $filename =
                                'profile_' .
                                $user_id . '_' .
                                bin2hex(random_bytes(8)) .
                                '.' . $extension;

                            $destination =
                                $uploadDir .
                                DIRECTORY_SEPARATOR .
                                $filename;

                            if (
                                move_uploaded_file(
                                    (string)$file['tmp_name'],
                                    $destination
                                )
                            ) {

                                $newImagePath = $destination;

                                $pic =
                                    '/uploads/profiles/' .
                                    $filename;

                            } else {

                                $error = 'Unable to save the profile picture.';
                            }
                        }
                    }
                }
            }

            /* Update learner information */
            if ($error === '') {

                try {

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET
                            name = ?,
                            bio = ?,
                            licence_code = ?,
                            profile_pic = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $name,
                        $bio,
                        $lcode,
                        $pic,
                        $user_id
                    ]);

                    $_SESSION['name'] = $name;

                    $msg = 'Profile updated successfully!';

                    // Retrieve updated information.
                    $stmt = $conn->prepare("
                        SELECT *
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$user_id]);

                    $user = $stmt->fetch(PDO::FETCH_ASSOC)
                        ?: $user;

                } catch (PDOException $e) {

                    error_log(
                        'TestMate profile update: ' .
                        $e->getMessage()
                    );

                    // Do not leave newly uploaded images
                    // behind when the database update fails.
                    if (
                        $newImagePath !== null &&
                        is_file($newImagePath)
                    ) {
                        @unlink($newImagePath);
                    }

                    $error = 'Unable to update your profile.';
                }
            }
        }

    } elseif ($action === 'change_password') {

        $current = (string)(
            $_POST['current_password'] ?? ''
        );

        $new = (string)(
            $_POST['new_password'] ?? ''
        );

        $confirm = (string)(
            $_POST['confirm_password'] ?? ''
        );

        if (
            !password_verify(
                $current,
                (string)$user['password']
            )
        ) {

            $error = 'Current password is incorrect.';

        } elseif (strlen($new) < 6) {

            $error = 'New password must be at least 6 characters.';

        } elseif ($new !== $confirm) {

            $error = 'New passwords do not match.';

        } else {

            try {

                $hashed = password_hash(
                    $new,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $hashed,
                    $user_id
                ]);

                $msg = 'Password changed successfully!';

            } catch (PDOException $e) {

                error_log(
                    'TestMate password change: ' .
                    $e->getMessage()
                );

                $error = 'Unable to change your password.';
            }
        }
    }
}

/* =====================================================
   LOAD LEARNER STATISTICS
   ===================================================== */

$quiz_count = 0;

$mock_data = [
    'c' => 0,
    'p' => 0
];

try {

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM quiz_scores
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);

    $quiz_count = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS c,
            COALESCE(SUM(passed), 0) AS p
        FROM mock_scores
        WHERE user_id = ?
    ");

    $stmt->execute([$user_id]);

    $mock_data = $stmt->fetch(PDO::FETCH_ASSOC)
        ?: ['c' => 0, 'p' => 0];

} catch (PDOException $e) {

    error_log(
        'TestMate profile statistics: ' .
        $e->getMessage()
    );
}

$profilePic = profileImageUrl(
    $user['profile_pic'] ?? ''
);

$createdAt = strtotime(
    (string)($user['created_at'] ?? '')
);

$memberSince = $createdAt === false
    ? 'Not available'
    : date('d M Y', $createdAt);

include __DIR__ . '/includes/header.php';
?>

<style>
.tm-profile-page {
    max-width: 950px;
    margin: 0 auto;
    padding: 28px 24px 55px;
}

.tm-profile-heading {
    margin-bottom: 25px;
}

.tm-profile-heading h1 {
    margin: 0 0 6px;
    color: #173452;
    font-size: 27px;
}

.tm-profile-heading p {
    margin: 0;
    color: #71859a;
    font-size: 14px;
}

.tm-profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    align-items: start;
}

.tm-profile-card {
    padding: 24px;
    border: 1px solid #e1e9f2;
    border-radius: 13px;
    background: white;
    box-shadow: 0 4px 15px rgba(15,44,81,.05);
}

.tm-profile-card h2 {
    margin: 0 0 19px;
    color: #173452;
    font-size: 18px;
}

.tm-profile-avatar {
    margin-bottom: 20px;
    text-align: center;
}

.tm-profile-avatar img,
.tm-profile-initial {
    width: 100px;
    height: 100px;
    border: 3px solid #2c3e50;
    border-radius: 50%;
    object-fit: cover;
}

.tm-profile-initial {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #2c3e50;
    color: white;
    font-size: 36px;
    font-weight: 800;
}

.tm-profile-field {
    margin-bottom: 17px;
}

.tm-profile-field label {
    display: block;
    margin-bottom: 7px;
    color: #314d67;
    font-size: 13px;
    font-weight: 750;
}

.tm-profile-field input,
.tm-profile-field select,
.tm-profile-field textarea {
    width: 100%;
    padding: 11px 13px;
    border: 1.5px solid #dbe4ed;
    border-radius: 8px;
    outline: none;
    background: white;
    color: #173452;
    font-family: inherit;
    font-size: 14px;
}

.tm-profile-field input:focus,
.tm-profile-field select:focus,
.tm-profile-field textarea:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52,152,219,.12);
}

.tm-profile-field input:disabled {
    background: #f8f9fa;
    color: #888;
}

.tm-profile-field textarea {
    resize: vertical;
}

.tm-profile-button {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 8px;
    background: #075bf2;
    color: white;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
}

.tm-profile-button:hover {
    background: #064bd0;
}

.tm-profile-right {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.tm-profile-stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.tm-profile-stat {
    padding: 16px;
    border-radius: 8px;
    background: #f8f9fa;
    text-align: center;
}

.tm-profile-stat strong {
    display: block;
    font-size: 29px;
    font-weight: 800;
}

.tm-profile-stat small {
    display: block;
    margin-top: 4px;
    color: #888;
    font-size: 12px;
}

.tm-profile-meta {
    margin-top: 15px;
    color: #718398;
    font-size: 13px;
    line-height: 1.9;
}

.tm-profile-meta strong {
    color: #173452;
}

.tm-profile-alert {
    padding: 13px 16px;
    margin-bottom: 18px;
    border-radius: 8px;
    font-size: 13px;
}

.tm-profile-alert.success {
    border: 1px solid #bce8cb;
    background: #eaf8ef;
    color: #187545;
}

.tm-profile-alert.error {
    border: 1px solid #f4c7c7;
    background: #fdecea;
    color: #a93232;
}

.tm-certificate-card {
    text-align: center;
}

.tm-certificate-card h3 {
    margin: 0 0 9px;
    color: #173452;
    font-size: 17px;
}

.tm-certificate-card p {
    margin: 0 0 16px;
    color: #888;
    font-size: 13px;
    line-height: 1.7;
}

@media (max-width: 760px) {
    .tm-profile-grid {
        grid-template-columns: 1fr;
    }

    .tm-profile-page {
        padding: 20px 15px 45px;
    }
}
</style>

<main class="tm-profile-page">

    <header class="tm-profile-heading">

        <h1>My Profile</h1>

        <p>Manage your account and preferences</p>

    </header>

    <?php if ($msg !== ''): ?>

        <div
            class="tm-profile-alert success"
            role="status"
        >
            <?= profileEsc($msg) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div
            class="tm-profile-alert error"
            role="alert"
        >
            <?= profileEsc($error) ?>
        </div>

    <?php endif; ?>

    <div class="tm-profile-grid">

        <!-- LEFT: PROFILE INFORMATION -->
        <section class="tm-profile-card">

            <h2>Profile Information</h2>

            <div class="tm-profile-avatar">

                <?php if ($profilePic !== ''): ?>

                    <img
                        src="<?= profileEsc($profilePic) ?>"
                        alt="Profile picture"
                    >

                <?php else: ?>

                    <div class="tm-profile-initial">
                        <?= profileEsc(
                            strtoupper(
                                substr(
                                    (string)$user['name'],
                                    0,
                                    1
                                )
                            )
                        ) ?>
                    </div>

                <?php endif; ?>

            </div>

            <form
                method="POST"
                enctype="multipart/form-data"
                action="/profile.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="update_profile"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= profileEsc(
                        $_SESSION['profile_csrf']
                    ) ?>"
                >

                <div class="tm-profile-field">

                    <label for="profilePicture">
                        Profile Picture
                    </label>

                    <input
                        id="profilePicture"
                        type="file"
                        name="profile_pic"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                    >

                </div>

                <div class="tm-profile-field">

                    <label for="profileName">
                        Full Name
                    </label>

                    <input
                        id="profileName"
                        type="text"
                        name="name"
                        value="<?= profileEsc($user['name']) ?>"
                        maxlength="150"
                        required
                    >

                </div>

                <div class="tm-profile-field">

                    <label for="profileEmail">
                        Email (cannot be changed)
                    </label>

                    <input
                        id="profileEmail"
                        type="email"
                        value="<?= profileEsc($user['email']) ?>"
                        disabled
                    >

                </div>

                <div class="tm-profile-field">

                    <label for="licenceCode">
                        Licence Code
                    </label>

                    <select
                        id="licenceCode"
                        name="licence_code"
                    >

                        <option
                            value="Code1"
                            <?= ($user['licence_code'] ?? '') === 'Code1'
                                ? 'selected'
                                : '' ?>
                        >
                            Code 1 — Motorcycle
                        </option>

                        <option
                            value="Code2"
                            <?= ($user['licence_code'] ?? '') === 'Code2'
                                ? 'selected'
                                : '' ?>
                        >
                            Code 2 — Car / Light Vehicle
                        </option>

                        <option
                            value="Code3"
                            <?= ($user['licence_code'] ?? '') === 'Code3'
                                ? 'selected'
                                : '' ?>
                        >
                            Code 3 — Heavy Vehicle / Truck
                        </option>

                    </select>

                </div>

                <div class="tm-profile-field">

                    <label for="profileBio">
                        About Me (optional)
                    </label>

                    <textarea
                        id="profileBio"
                        name="bio"
                        rows="3"
                        maxlength="2000"
                        placeholder="Tell us about yourself..."
                    ><?= profileEsc($user['bio'] ?? '') ?></textarea>

                </div>

                <button
                    type="submit"
                    class="tm-profile-button"
                >
                    Save Changes
                </button>

            </form>

        </section>

        <!-- RIGHT COLUMN -->
        <div class="tm-profile-right">

            <!-- PERSONAL STATISTICS -->
            <section class="tm-profile-card">

                <h2>My Stats</h2>

                <div class="tm-profile-stat-grid">

                    <div class="tm-profile-stat">

                        <strong style="color:#3498db;">
                            <?= $quiz_count ?>
                        </strong>

                        <small>Quizzes Taken</small>

                    </div>

                    <div class="tm-profile-stat">

                        <strong style="color:#2c3e50;">
                            <?= (int)$mock_data['c'] ?>
                        </strong>

                        <small>Mock Tests</small>

                    </div>

                    <div class="tm-profile-stat">

                        <strong style="color:#27ae60;">
                            <?= (int)$mock_data['p'] ?>
                        </strong>

                        <small>Tests Passed</small>

                    </div>

                    <div class="tm-profile-stat">

                        <strong style="color:#f1c40f;">
                            <?= (int)($user['streak'] ?? 0) ?>
                        </strong>

                        <small>Day Streak</small>

                    </div>

                </div>

                <div class="tm-profile-meta">

                    <div>
                        Member since:
                        <strong>
                            <?= profileEsc($memberSince) ?>
                        </strong>
                    </div>

                    <div>
                        Licence Code:
                        <strong>
                            <?= profileEsc(
                                $user['licence_code'] ?? 'Not selected'
                            ) ?>
                        </strong>
                    </div>

                </div>

            </section>

            <!-- CHANGE PASSWORD -->
            <section class="tm-profile-card">

                <h2>Change Password</h2>

                <form
                    method="POST"
                    action="/profile.php"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="change_password"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= profileEsc(
                            $_SESSION['profile_csrf']
                        ) ?>"
                    >

                    <div class="tm-profile-field">

                        <label for="currentPassword">
                            Current Password
                        </label>

                        <input
                            id="currentPassword"
                            type="password"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >

                    </div>

                    <div class="tm-profile-field">

                        <label for="newPassword">
                            New Password
                        </label>

                        <input
                            id="newPassword"
                            type="password"
                            name="new_password"
                            minlength="6"
                            autocomplete="new-password"
                            placeholder="At least 6 characters"
                            required
                        >

                    </div>

                    <div class="tm-profile-field">

                        <label for="confirmPassword">
                            Confirm New Password
                        </label>

                        <input
                            id="confirmPassword"
                            type="password"
                            name="confirm_password"
                            minlength="6"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <button
                        type="submit"
                        class="tm-profile-button"
                    >
                        Change Password
                    </button>

                </form>

            </section>

            <!-- ACHIEVEMENT CERTIFICATE -->
            <section class="tm-profile-card tm-certificate-card">

                <h3>Achievement Certificate</h3>

                <p>
                    Pass the Mock Test with 80% or more
                    to earn your certificate.
                </p>

                <a
                    href="/certificate.php"
                    class="btn btn-primary"
                >
                    View Certificate
                </a>

            </section>

        </div>

    </div>

</main>

<?php
include __DIR__ . '/includes/footer.php';
?>
