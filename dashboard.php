
<?php
/**
 * TestMate – Namibia Learner's Licence
 * Redesigned learner dashboard
 * Standalone PHP 8.5 + SQLite
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId < 1) {
    header('Location: /login.php');
    exit;
}

function dashEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* ==========================================
   DASHBOARD ICONS
   ========================================== */

function dashIcon(string $name): string
{
    $paths = [
        'book' =>
            '<path d="M12 6c-3-2-6-2-9-1v14c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1Z"/>
             <path d="M12 6v14"/>',

        'quiz' =>
            '<rect x="5" y="3" width="14" height="18" rx="2"/>
             <path d="M9 3h6M8 10l2 2 4-4M8 16h8"/>',

        'car' =>
            '<path d="M5 16H3v-5l2-5h14l2 5v5h-2M4 11h16"/>
             <circle cx="7" cy="16" r="1.6"/>
             <circle cx="17" cy="16" r="1.6"/>',

        'test' =>
            '<path d="M8 3h8l4 4v14H4V3h4Z"/>
             <path d="M16 3v5h4M8 12h8M8 16h6"/>',

        'chart' =>
            '<path d="M3 20h18M6 17v-5M12 17V8M18 17V4"/>',

        'star' =>
            '<path d="m12 2 3.1 6.3 7 .9-5 4.9 1.2 7-6.3-3.3-6.3 3.3 1.2-7-5-4.9 7-.9Z"/>',

        'arrow' =>
            '<path d="M5 12h14m-6-6 6 6-6 6"/>',

        'play' =>
            '<circle cx="12" cy="12" r="9"/>
             <path d="m10 8 6 4-6 4Z"/>',

        'history' =>
            '<path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2"/>',

        'target' =>
            '<circle cx="12" cy="12" r="9"/>
             <circle cx="12" cy="12" r="5"/>
             <circle cx="12" cy="12" r="1"/>',
    ];

    return '<svg viewBox="0 0 24 24"
                 width="24"
                 height="24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.8"
                 stroke-linecap="round"
                 stroke-linejoin="round"
                 aria-hidden="true">'
        . ($paths[$name] ?? $paths['arrow']) .
        '</svg>';
}

/* ==========================================
   LEARNER INFORMATION
   ========================================== */

$name = trim(
    (string) ($_SESSION['name'] ?? 'Learner')
) ?: 'Learner';

$firstName = preg_split('/\s+/', $name)[0] ?? 'Learner';

$stats = [
    'quizzes' => 0,
    'mocks' => 0,
    'mocks_passed' => 0
];

$overall = 0;
$dashboardError = '';

/* ==========================================
   FETCH SQLITE STATISTICS
   ========================================== */

try {

    $quizStmt = $conn->prepare(
        'SELECT
            COUNT(*) AS attempts,
            ROUND(
                AVG(
                    CASE
                        WHEN total > 0
                        THEN score * 100.0 / total
                    END
                )
            ) AS average_score
         FROM quiz_scores
         WHERE user_id = ?'
    );

    $quizStmt->execute([$userId]);

    $quizStats = $quizStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['quizzes'] = (int) (
        $quizStats['attempts'] ?? 0
    );

    $overall = (int) (
        $quizStats['average_score'] ?? 0
    );

    $mockStmt = $conn->prepare(
        'SELECT
            COUNT(*) AS attempts,
            COALESCE(
                SUM(
                    CASE
                        WHEN passed = 1 THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS passed_count
         FROM mock_scores
         WHERE user_id = ?'
    );

    $mockStmt->execute([$userId]);

    $mockStats = $mockStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['mocks'] = (int) (
        $mockStats['attempts'] ?? 0
    );

    $stats['mocks_passed'] = (int) (
        $mockStats['passed_count'] ?? 0
    );

} catch (PDOException $e) {

    error_log(
        '[TestMate Dashboard] ' . $e->getMessage()
    );

    $dashboardError =
        'Some learning statistics are temporarily unavailable.';
}

$overall = max(0, min(100, $overall));

/* ==========================================
   GREETING AND LOCAL DATE
   ========================================== */

$now = new DateTimeImmutable(
    'now',
    new DateTimeZone('Africa/Windhoek')
);

$hour = (int) $now->format('G');

$greeting = $hour < 12
    ? 'Good morning'
    : ($hour < 17 ? 'Good afternoon' : 'Good evening');

/* ==========================================
   LEARNING RECOMMENDATION
   ========================================== */

if ($stats['quizzes'] === 0) {

    $nextStep =
        'Start with a topic quiz after studying your first lesson.';

} elseif ($overall < 60) {

    $nextStep =
        'Review the topics you find difficult, then retry a quiz.';

} elseif ($overall < 80) {

    $nextStep =
        'Keep practising to strengthen your quiz performance.';

} else {

    $nextStep =
        'Great progress in your quizzes. Try a full mock test next.';
}

/* ==========================================
   QUICK ACCESS CARDS
   ========================================== */

$actions = [
    [
        'title' => 'Study Materials',
        'description' => 'Learn rules, signs and safe driving.',
        'url' => '/study-materials.php',
        'button' => 'Start Studying',
        'icon' => 'book',
        'theme' => 'green'
    ],
    [
        'title' => 'Take a Quiz',
        'description' => 'Test what you have learned.',
        'url' => '/quiz.php',
        'button' => 'Start Quiz',
        'icon' => 'quiz',
        'theme' => 'blue'
    ],
    [
        'title' => 'Simulations',
        'description' => 'Explore real road situations.',
        'url' => '/simulations.php',
        'button' => 'Try Simulation',
        'icon' => 'car',
        'theme' => 'orange'
    ],
    [
        'title' => 'Mock Test',
        'description' => 'Practise the full test experience.',
        'url' => '/mock-test.php',
        'button' => 'Start Mock Test',
        'icon' => 'test',
        'theme' => 'purple'
    ]
];

include __DIR__ . '/includes/header.php';
?>

<style>

/* ==========================================
   TESTMATE DASHBOARD - NEW DESIGN
   Isolated from older global CSS
   ========================================== */

body .tmnd-dashboard {
    max-width: 1370px;
    margin: 0 auto;
    padding: 28px 30px 60px;
    font-family: "Segoe UI", Arial, sans-serif;
    color: #102744;
}

body .tmnd-dashboard *,
body .tmnd-dashboard *::before,
body .tmnd-dashboard *::after {
    box-sizing: border-box;
}

body .tmnd-dashboard a {
    text-decoration: none;
}

body .tmnd-dashboard h1,
body .tmnd-dashboard h2,
body .tmnd-dashboard h3,
body .tmnd-dashboard p {
    margin-top: 0;
}

/* ==========================================
   WELCOME BANNER
   ========================================== */

body .tmnd-welcome {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 25px;

    min-height: 156px;
    padding: 30px 34px;

    border-radius: 18px;
    border: 1px solid #dce9f8;

    background: linear-gradient(
        105deg,
        #e9f4ff 0%,
        #f6faff 54%,
        #c7e4ff 100%
    );

    overflow: hidden;
}

body .tmnd-welcome::after {
    content: "";
    position: absolute;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    right: -85px;
    top: -140px;
    background: rgba(255,255,255,.35);
}

body .tmnd-welcome-copy {
    position: relative;
    z-index: 1;
}

body .tmnd-kicker {
    display: block;
    margin-bottom: 9px;
    color: #2c82c2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
}

body .tmnd-welcome h1 {
    margin-bottom: 10px;
    font-size: clamp(25px, 2.45vw, 36px);
    line-height: 1.17;
    letter-spacing: -.7px;
    font-weight: 800;
    color: #102744;
}

body .tmnd-welcome p {
    margin-bottom: 0;
    font-size: 14px;
    color: #526f8b;
}

/* Date card */

body .tmnd-date-box {
    position: relative;
    z-index: 1;
    flex: 0 0 auto;

    display: flex;
    align-items: center;
    gap: 13px;

    min-width: 185px;
    padding: 14px 16px;
    border-radius: 13px;

    background: rgba(255,255,255,.88);
    border: 1px solid #e0ebf7;

    box-shadow: 0 4px 14px rgba(30,75,110,.04);
}

body .tmnd-date-box span {
    display: block;
    font-size: 11px;
    color: #69819a;
}

body .tmnd-date-box strong {
    display: block;
    color: #173652;
    font-size: 13px;
}

body .tmnd-date-box .tmnd-flag {
    font-size: 27px;
    line-height: 1;
}

/* ==========================================
   LEARNING STATISTICS
   ========================================== */

body .tmnd-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 17px;
    margin: 20px 0 30px;
}

body .tmnd-stat {
    display: flex;
    align-items: center;
    gap: 17px;
    min-width: 0;

    padding: 21px 22px;

    border-radius: 15px;
    background: #fff;
    border: 1px solid #e3ebf3;

    box-shadow: 0 5px 18px rgba(24,54,87,.06);
}

body .tmnd-stat-icon {
    flex: 0 0 54px;
    width: 54px;
    height: 54px;
    border-radius: 15px;

    display: grid;
    place-items: center;
}

body .tmnd-stat-icon svg {
    width: 26px;
    height: 26px;
}

body .tmnd-stat.is-green .tmnd-stat-icon {
    background: #dff7ed;
    color: #0ca76d;
}

body .tmnd-stat.is-blue .tmnd-stat-icon {
    background: #e2edff;
    color: #1f73ee;
}

body .tmnd-stat.is-gold .tmnd-stat-icon {
    background: #fff2d9;
    color: #ee9e10;
}

body .tmnd-stat strong {
    display: block;
    font-size: 29px;
    color: #102744;
    line-height: 1.1;
    font-weight: 800;
}

body .tmnd-stat span.tmnd-stat-label {
    display: block;
    font-size: 12px;
    color: #73869d;
    margin-top: 6px;
}

/* ==========================================
   SECTION HEADING
   ========================================== */

body .tmnd-section-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 16px;
    margin-bottom: 16px;
}

body .tmnd-section-heading h2 {
    margin: 0;
    color: #102744;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -.3px;
}

body .tmnd-section-heading p {
    margin: 4px 0 0;
    color: #74879b;
    font-size: 13px;
}

/* ==========================================
   QUICK ACCESS CARDS
   ========================================== */

body .tmnd-actions {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 16px;
}

body .tmnd-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;

    min-width: 0;
    min-height: 225px;

    padding: 24px 15px 16px;

    border-radius: 15px;
    color: #fff;

    box-shadow: 0 7px 18px rgba(21,57,100,.11);

    transition: transform .2s, box-shadow .2s;
}

body .tmnd-action:hover {
    transform: translateY(-4px);
    box-shadow: 0 11px 25px rgba(21,57,100,.18);
}

body .tmnd-action.is-green {
    background: linear-gradient(135deg,#09ba86,#06a272);
}

body .tmnd-action.is-blue {
    background: linear-gradient(135deg,#308af9,#1265dc);
}

body .tmnd-action.is-orange {
    background: linear-gradient(135deg,#ffab2a,#f48212);
}

body .tmnd-action.is-purple {
    background: linear-gradient(135deg,#9d51e8,#7440cf);
}

body .tmnd-action-icon {
    display: grid;
    place-items: center;

    width: 52px;
    height: 52px;
    margin-bottom: 15px;

    border-radius: 14px;

    color: #fff;
    background: rgba(255,255,255,.18);
}

body .tmnd-action-icon svg {
    width: 29px;
    height: 29px;
}

body .tmnd-action h3 {
    margin-bottom: 7px;
    color: #fff;
    font-size: 17px;
    line-height: 1.3;
    font-weight: 800;
}

body .tmnd-action p {
    color: rgba(255,255,255,.9);
    font-size: 12px;
    margin-bottom: 19px;
    line-height: 1.5;
}

body .tmnd-action a {
    width: 100%;

    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;

    min-height: 39px;
    padding: 10px;
    margin-top: auto;

    border-radius: 8px;

    background: #fff;
    color: #183a60;

    font-size: 12px;
    font-weight: 800;

    transition: background .2s;
}

body .tmnd-action a:hover {
    background: #eef6ff;
}

body .tmnd-action a svg {
    width: 15px;
    height: 15px;
}

/* ==========================================
   LEARNING JOURNEY
   ========================================== */

body .tmnd-journey {
    display: grid;
    grid-template-columns:
        minmax(0, 1.25fr)
        minmax(240px, .75fr);

    align-items: center;
    gap: 15px;
    min-height: 228px;
    margin-top: 26px;

    overflow: hidden;

    border: 1px solid #cfe5fa;
    border-radius: 17px;

    background: linear-gradient(
        110deg,
        #e8f5ff,
        #d5ecff 75%,
        #c1e3ff
    );
}

body .tmnd-journey-copy {
    padding: 28px 5px 28px 30px;
}

body .tmnd-journey h2 {
    font-size: 22px;
    margin-bottom: 7px;
    color: #102744;
    font-weight: 800;
}

body .tmnd-journey p {
    font-size: 13px;
    color: #567591;
    margin-bottom: 16px;
    line-height: 1.65;
    max-width: 550px;
}

body .tmnd-journey .tmnd-progress-caption {
    display: flex;
    justify-content: space-between;
    gap: 12px;

    font-size: 12px;
    color: #214766;
    font-weight: 700;

    max-width: 470px;
    margin: 0 0 8px;
}

body .tmnd-progress-track {
    max-width: 470px;
    height: 9px;

    border-radius: 999px;
    overflow: hidden;

    background: #c4dcef;
}

body .tmnd-progress-fill {
    height: 100%;
    border-radius: inherit;

    background: linear-gradient(
        90deg,
        #12a87a,
        #147df5
    );
}

body .tmnd-journey .tmnd-hint {
    margin-top: 14px;
    margin-bottom: 0;
    font-size: 12px;
    color: #486887;
}

/* Driving illustration */

body .tmnd-driving-art {
    width: 100%;
    max-width: 390px;
    height: auto;

    justify-self: end;
    align-self: end;
}

/* ==========================================
   MORE LEARNING RESOURCES
   ========================================== */

body .tmnd-resources {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;

    margin-top: 27px;
    padding: 18px 21px;

    border: 1px solid #e1eaf4;
    border-radius: 14px;
    background: #fff;
}

body .tmnd-resources strong {
    font-size: 14px;
    margin-right: 10px;
    color: #173452;
}

body .tmnd-resources a {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 9px 11px;

    border: 1px solid #e1eafa;
    border-radius: 8px;

    background: #f8fbff;
    color: #186fbd;

    font-size: 12px;
    font-weight: 700;
}

body .tmnd-resources a:hover {
    background: #eaf4ff;
}

body .tmnd-resources svg {
    width: 16px;
    height: 16px;
}

body .tmnd-alert {
    margin: 0 0 18px;
    padding: 12px 15px;

    border-radius: 10px;
    border: 1px solid #ffdbad;

    background: #fff6e8;
    color: #865b1b;
}

/* ==========================================
   RESPONSIVE DESIGN
   ========================================== */

@media (max-width: 1150px) {

    body .tmnd-actions {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }

    body .tmnd-action {
        min-height: 205px;
    }
}

@media (max-width: 800px) {

    body .tmnd-dashboard {
        padding: 20px 18px 42px;
    }

    body .tmnd-stats {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    body .tmnd-stat {
        padding: 16px;
    }

    body .tmnd-journey {
        grid-template-columns: 1fr;
    }

    body .tmnd-driving-art {
        max-width: 330px;
        margin-top: -25px;
    }
}

@media (max-width: 560px) {

    body .tmnd-dashboard {
        padding: 15px 13px 32px;
    }

    body .tmnd-welcome {
        display: block;
        min-height: 0;
        padding: 24px 20px;
    }

    body .tmnd-date-box {
        margin-top: 20px;
        max-width: 235px;
    }

    body .tmnd-actions {
        grid-template-columns: repeat(2,minmax(0,1fr));
        gap: 10px;
    }

    body .tmnd-action {
        min-height: 215px;
        padding: 19px 10px 12px;
    }

    body .tmnd-action h3 {
        font-size: 14px;
    }

    body .tmnd-action p {
        font-size: 11px;
    }

    body .tmnd-journey-copy {
        padding: 24px 20px 0;
    }

    body .tmnd-resources {
        align-items: stretch;
    }
}

@media (max-width: 370px) {

    body .tmnd-actions {
        grid-template-columns: 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {

    body .tmnd-action {
        transition: none;
    }
}

</style>

<!-- ==========================================
     DASHBOARD MAIN CONTENT
     ========================================== -->

<main class="tmnd-dashboard">

    <!-- WELCOME -->

    <section
        class="tmnd-welcome"
        aria-label="Welcome back"
    >

        <div class="tmnd-welcome-copy">

            <span class="tmnd-kicker">
                Learner Dashboard
            </span>

            <h1>
                <?= dashEscape($greeting) ?>,
                <?= dashEscape($firstName) ?>!
            </h1>

            <p>
                Keep learning. Stay safe.
                Get ready for your learner's licence.
            </p>

        </div>

        <div class="tmnd-date-box">

            <div>

                <span>Today in Namibia</span>

                <strong>
                    <?= dashEscape(
                        $now->format('D, d M Y')
                    ) ?>
                </strong>

            </div>

            <span
                class="tmnd-flag"
                role="img"
                aria-label="Namibian flag"
            >
                🇳🇦
            </span>

        </div>

    </section>

    <?php if ($dashboardError !== ''): ?>

        <div class="tmnd-alert" role="status">
            <?= dashEscape($dashboardError) ?>
        </div>

    <?php endif; ?>

    <!-- STATISTICS -->

    <section
        class="tmnd-stats"
        aria-label="Your learning statistics"
    >

        <div class="tmnd-stat is-green">

            <div class="tmnd-stat-icon">
                <?= dashIcon('chart') ?>
            </div>

            <div>

                <strong>
                    <?= $overall ?>%
                </strong>

                <span class="tmnd-stat-label">
                    Average Quiz Score
                </span>

            </div>

        </div>

        <div class="tmnd-stat is-blue">

            <div class="tmnd-stat-icon">
                <?= dashIcon('quiz') ?>
            </div>

            <div>

                <strong>
                    <?= (int) $stats['quizzes'] ?>
                </strong>

                <span class="tmnd-stat-label">
                    Quizzes Attempted
                </span>

            </div>

        </div>

        <div class="tmnd-stat is-gold">

            <div class="tmnd-stat-icon">
                <?= dashIcon('star') ?>
            </div>

            <div>

                <strong>
                    <?= (int) $stats['mocks_passed'] ?>
                </strong>

                <span class="tmnd-stat-label">
                    Mock Tests Passed
                    (<?= (int) $stats['mocks'] ?> attempted)
                </span>

            </div>

        </div>

    </section>

    <!-- QUICK ACCESS -->

    <div class="tmnd-section-heading">

        <div>

            <h2>Quick Access</h2>

            <p>
                Pick up where you left off,
                or try something new.
            </p>

        </div>

    </div>

    <section
        class="tmnd-actions"
        aria-label="Learning tools"
    >

        <?php foreach ($actions as $action): ?>

            <article
                class="tmnd-action is-<?= dashEscape(
                    $action['theme']
                ) ?>"
            >

                <span class="tmnd-action-icon">
                    <?= dashIcon($action['icon']) ?>
                </span>

                <h3>
                    <?= dashEscape($action['title']) ?>
                </h3>

                <p>
                    <?= dashEscape($action['description']) ?>
                </p>

                <a href="<?= dashEscape($action['url']) ?>">

                    <?= dashEscape($action['button']) ?>

                    <?= dashIcon('arrow') ?>

                </a>

            </article>

        <?php endforeach; ?>

    </section>

    <!-- LEARNING JOURNEY -->

    <section
        class="tmnd-journey"
        aria-labelledby="tmnd-journey-title"
    >

        <div class="tmnd-journey-copy">

            <span class="tmnd-kicker">
                Keep moving forward
            </span>

            <h2 id="tmnd-journey-title">
                Your Learning Journey
            </h2>

            <p>
                Every lesson and practice attempt helps
                you build your knowledge of Namibia's
                road rules.
            </p>

            <div class="tmnd-progress-caption">

                <span>Average quiz performance</span>

                <span><?= $overall ?>%</span>

            </div>

            <div
                class="tmnd-progress-track"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= $overall ?>"
                aria-label="Average quiz performance"
            >

                <div
                    class="tmnd-progress-fill"
                    style="width: <?= $overall ?>%"
                ></div>

            </div>

            <p class="tmnd-hint">
                <?= dashEscape($nextStep) ?>
            </p>

        </div>

        <!-- BUILT-IN DRIVING ILLUSTRATION -->

        <svg
            class="tmnd-driving-art"
            viewBox="0 0 410 230"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >

            <circle
                cx="286" cy="106" r="93"
                fill="#BADFFF" opacity=".75"
            />

            <path
                d="M98 168c57-71 89-78 142-20 23-50 61-77 121-27l49 109H64Z"
                fill="#9CC9EC"
            />

            <path
                d="M134 181c42-45 78-48 117-16 37-25 75-18 118 16v49H112Z"
                fill="#70BCAD"
            />

            <path
                d="M167 230c13-65 76-88 131-113 33-15 52-40 57-70h55v183H167Z"
                fill="#557D9D"
            />

            <path
                d="M235 230c19-47 58-66 91-92 23-18 35-38 40-61"
                stroke="#E9F3FF"
                stroke-width="5"
                stroke-dasharray="16 15"
            />

            <rect
                x="304" y="53"
                width="5" height="71"
                rx="2" fill="#325772"
            />

            <circle
                cx="306" cy="49" r="30"
                fill="white"
                stroke="#E13745"
                stroke-width="9"
            />

            <text
                x="306" y="57"
                text-anchor="middle"
                fill="#1B334D"
                font-size="23"
                font-weight="800"
                font-family="Arial"
            >80</text>

            <g transform="translate(210 147)">

                <ellipse
                    cx="55" cy="67"
                    rx="65" ry="9"
                    fill="#21466D"
                    opacity=".25"
                />

                <path
                    d="M9 35 26 11c4-6 12-9 22-9h41c8 0 14 3 18 10l15 23 7 6v21H2V43Z"
                    fill="#FFFFFF"
                    stroke="#2F648C"
                    stroke-width="4"
                />

                <path
                    d="M37 12h48c5 0 8 2 11 7l9 15H23l11-17c1-3 2-5 3-5Z"
                    fill="#7BC2EC"
                />

                <rect
                    x="7" y="42"
                    width="16" height="9"
                    rx="3" fill="#F0BA3B"
                />

                <rect
                    x="105" y="42"
                    width="18" height="9"
                    rx="3" fill="#F0BA3B"
                />

                <circle
                    cx="28" cy="61" r="13"
                    fill="#243E5E"
                />

                <circle
                    cx="102" cy="61" r="13"
                    fill="#243E5E"
                />

                <circle
                    cx="28" cy="61" r="6"
                    fill="#D8E8F4"
                />

                <circle
                    cx="102" cy="61" r="6"
                    fill="#D8E8F4"
                />

            </g>

        </svg>

    </section>

    <!-- MORE LEARNING RESOURCES -->

    <nav
        class="tmnd-resources"
        aria-label="More learning resources"
    >

        <strong>
            More learning resources
        </strong>

        <a href="/video-demonstrations.php">
            <?= dashIcon('play') ?>
            Video Demonstrations
        </a>

        <a href="/progress.php">
            <?= dashIcon('chart') ?>
            My Progress
        </a>

        <a href="/quiz-history.php">
            <?= dashIcon('history') ?>
            Quiz History
        </a>

        <a href="/licence-info.php">
            <?= dashIcon('book') ?>
            Licence Information
        </a>

    </nav>

</main>

<?php
include __DIR__ . '/includes/footer.php';
?>
