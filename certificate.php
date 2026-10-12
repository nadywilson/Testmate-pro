
<?php
/**
 * TestMate - Certificate of Achievement
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

/**
 * Escape output safely.
 */
function certEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =====================================================
   FETCH THE LEARNER'S NAME
   ===================================================== */

try {
    $userStmt = $conn->prepare("
        SELECT name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->execute([$user_id]);

    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        exit('Learner account not found.');
    }

    $name = (string)$user['name'];

    /* =================================================
       GET THE LEARNER'S BEST PASSED MOCK TEST
       ================================================= */

    $bestStmt = $conn->prepare("
        SELECT
            id,
            score,
            total,
            passed,
            taken_at
        FROM mock_scores
        WHERE user_id = ?
          AND passed = 1
          AND total > 0
          AND (score * 100.0 / total) >= 80
        ORDER BY
            (score * 100.0 / total) DESC,
            taken_at DESC,
            id DESC
        LIMIT 1
    ");

    $bestStmt->execute([$user_id]);

    $best = $bestStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        '[TestMate Certificate] ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Unable to load your certificate. Please try again.'
    );
}

/* =====================================================
   NO CERTIFICATE AVAILABLE
   ===================================================== */

if (!$best) {

    include __DIR__ . '/includes/header.php';
    ?>

    <main style="
        max-width:650px;
        margin:60px auto;
        padding:20px;
    ">

        <div style="
            padding:40px 30px;
            border-radius:15px;
            border:1px solid #e3eaf2;
            background:white;
            text-align:center;
            box-shadow:0 6px 24px rgba(0,0,0,.07);
        ">

            <div style="
                font-size:52px;
                margin-bottom:15px;
            ">
                🏅
            </div>

            <h2 style="
                color:#e74c3c;
                margin-bottom:12px;
            ">
                No Certificate Yet
            </h2>

            <p style="
                color:#666;
                line-height:1.7;
                margin-bottom:22px;
            ">
                You need to pass the Mock Test
                with 80% or more to earn a certificate.
            </p>

            <div style="
                display:flex;
                gap:12px;
                justify-content:center;
                flex-wrap:wrap;
            ">

                <a
                    href="/mock_test.php"
                    class="btn btn-primary"
                >
                    Take the Practice Test
                </a>

                <a
                    href="/progress.php"
                    class="btn btn-outline"
                >
                    View My Progress
                </a>

            </div>

        </div>

    </main>

    <?php

    include __DIR__ . '/includes/footer.php';

    exit;
}

/* =====================================================
   PREPARE CERTIFICATE INFORMATION
   ===================================================== */

$score = (int)$best['score'];
$total = (int)$best['total'];

$percentage = $total > 0
    ? (int)round(($score * 100.0) / $total)
    : 0;

$timestamp = strtotime(
    (string)($best['taken_at'] ?? '')
);

$date = $timestamp !== false
    ? date('d F Y', $timestamp)
    : 'Date unavailable';

/**
 * Consistent certificate number based on
 * the learner ID and best Mock Test attempt.
 */
$cert_number = 'TM-' . strtoupper(
    substr(
        hash(
            'sha256',
            $user_id . ':' . (int)$best['id']
        ),
        0,
        10
    )
);

include __DIR__ . '/includes/header.php';
?>

<style>
/* =====================================================
   TESTMATE CERTIFICATE PAGE
   ===================================================== */

.tm-certificate-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 30px 24px 60px;
}

.tm-certificate-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.tm-certificate-actions button,
.tm-certificate-actions a {
    font-size: 13px;
    font-weight: 700;
}

.tm-certificate {
    border: 12px solid #2c3e50;
    border-radius: 8px;
    padding: 38px;
    text-align: center;
    background: white;
    font-family: Georgia, "Times New Roman", serif;
    box-shadow: 0 8px 30px rgba(0,0,0,.1);
}

.tm-certificate-inner {
    border: 3px solid #f1c40f;
    padding: 35px;
    border-radius: 4px;
}

.tm-certificate-logo {
    width: 60px;
    height: 60px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    border-radius: 12px;
    background: #2c3e50;
    color: white;
    font-size: 28px;
    font-weight: 800;
    font-family: Arial, sans-serif;
}

.tm-certificate-brand {
    margin-bottom: 4px;
    color: #888;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
}

.tm-certificate-platform {
    color: #aaa;
    font-size: 11px;
    letter-spacing: 1px;
}

.tm-certificate-title {
    margin: 30px 0 12px;
    color: #777;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 4px;
    text-transform: uppercase;
}

.tm-certificate-intro {
    margin: 18px 0 10px;
    color: #555;
    font-size: 15px;
}

.tm-certificate-name {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f1c40f;
    color: #2c3e50;
    font-size: 36px;
    font-style: italic;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.tm-certificate-description {
    margin: 20px 0;
    color: #555;
    font-size: 15px;
    line-height: 1.9;
}

.tm-certificate-score {
    display: inline-block;
    min-width: 250px;
    padding: 16px 24px;
    margin: 10px 0;
    border-radius: 8px;
    background: #f8f9fa;
}

.tm-certificate-percent {
    color: #27ae60;
    font-size: 42px;
    font-weight: 800;
}

.tm-certificate-score-label {
    color: #888;
    font-size: 14px;
}

.tm-certificate-date {
    margin-top: 18px;
    color: #888;
    font-size: 14px;
}

.tm-certificate-date strong {
    color: #2c3e50;
}

.tm-certificate-footer {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-top: 35px;
}

.tm-certificate-signature {
    width: 155px;
    padding-top: 7px;
    border-top: 2px solid #2c3e50;
    color: #666;
    font-size: 13px;
}

.tm-certificate-number-label {
    margin-bottom: 6px;
    color: #aaa;
    font-size: 11px;
}

.tm-certificate-number {
    color: #2c3e50;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .5px;
}

.tm-certificate-note {
    margin-top: 18px;
    color: #8191a1;
    font-size: 12px;
    text-align: center;
    line-height: 1.6;
}

/* =====================================================
   RESPONSIVE DESIGN
   ===================================================== */

@media (max-width: 700px) {

    .tm-certificate-page {
        padding: 18px 12px 40px;
    }

    .tm-certificate {
        padding: 15px;
        border-width: 8px;
    }

    .tm-certificate-inner {
        padding: 23px 15px;
    }

    .tm-certificate-name {
        font-size: 27px;
    }

    .tm-certificate-description {
        font-size: 13px;
    }

    .tm-certificate-footer {
        flex-wrap: wrap;
        justify-content: center;
    }

    .tm-certificate-signature {
        width: 130px;
        font-size: 11px;
    }
}

/* =====================================================
   PRINT STYLING
   ===================================================== */

@page {
    size: A4 landscape;
    margin: 12mm;
}

@media print {

    html,
    body {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }

    .navbar,
    .tm-sidebar,
    .sidebar,
    .tm-certificate-actions,
    .tm-certificate-note,
    header,
    footer,
    nav {
        display: none !important;
    }

    body.tm-modern,
    body.has-sidebar,
    body.tm-modern.has-sidebar {
        margin: 0 !important;
        padding: 0 !important;
    }

    .tm-certificate-page {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
    }

    .tm-certificate {
        width: 100%;
        margin: 0;
        padding: 20px;
        border: 10px solid #2c3e50;
        border-radius: 0;
        box-shadow: none;
        break-inside: avoid;
    }

    .tm-certificate-inner {
        padding: 27px;
        border: 3px solid #f1c40f;
    }

    .tm-certificate-name {
        font-size: 34px;
    }

    .tm-certificate-footer {
        margin-top: 28px;
    }

    .tm-certificate {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>

<main class="tm-certificate-page">

    <!-- ACTION BUTTONS -->
    <div class="tm-certificate-actions">

        <button
            type="button"
            onclick="window.print()"
            class="btn btn-primary"
        >
            Print Certificate
        </button>

        <a
            href="/dashboard.php"
            class="btn btn-outline"
        >
            Back to Dashboard
        </a>

    </div>

    <!-- CERTIFICATE -->
    <section
        id="certificate"
        class="tm-certificate"
        aria-label="TestMate Certificate of Achievement"
    >

        <div class="tm-certificate-inner">

            <!-- PLATFORM LOGO -->
            <div style="margin-bottom:24px;">

                <div class="tm-certificate-logo">
                    T
                </div>

                <div class="tm-certificate-brand">
                    TestMate Namibia
                </div>

                <div class="tm-certificate-platform">
                    Learner's Licence Preparation Platform
                </div>

            </div>

            <!-- CERTIFICATE HEADING -->
            <div class="tm-certificate-title">
                Certificate of Achievement
            </div>

            <p class="tm-certificate-intro">
                This is to certify that
            </p>

            <!-- LEARNER NAME -->
            <div class="tm-certificate-name">
                <?= certEscape($name) ?>
            </div>

            <!-- ACHIEVEMENT STATEMENT -->
            <div class="tm-certificate-description">
                has successfully completed the
                TestMate Practice Test
                <br>
                and demonstrated readiness for the
                Namibian Learner's Licence Test
            </div>

            <!-- TEST SCORE -->
            <div class="tm-certificate-score">

                <div class="tm-certificate-percent">
                    <?= $percentage ?>%
                </div>

                <div class="tm-certificate-score-label">
                    Score:
                    <?= $score ?>/<?= $total ?>
                    correct
                </div>

            </div>

            <!-- DATE -->
            <div class="tm-certificate-date">
                Date Achieved:
                <strong>
                    <?= certEscape($date) ?>
                </strong>
            </div>

            <!-- CERTIFICATE FOOTER -->
            <div class="tm-certificate-footer">

                <div class="tm-certificate-signature">
                    TestMate Administrator
                </div>

                <div>

                    <div class="tm-certificate-number-label">
                        Certificate No.
                    </div>

                    <div class="tm-certificate-number">
                        <?= certEscape($cert_number) ?>
                    </div>

                </div>

                <div class="tm-certificate-signature">
                    Date
                </div>

            </div>

        </div>

    </section>

    <p class="tm-certificate-note">
        This certificate recognizes achievement in the
        TestMate practice assessment.
        It is not an official NATIS learner's licence
        or government-issued qualification.
    </p>

</main>

<?php
include __DIR__ . '/includes/footer.php';
?>
