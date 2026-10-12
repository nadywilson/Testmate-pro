<?php
require 'includes/auth.php';
require 'includes/db_connect.php';

$user_id  = (int)$_SESSION['user_id'];
if (empty($_SESSION['sim_csrf'])) {
    $_SESSION['sim_csrf'] = bin2hex(random_bytes(32));
}

/** Return existing optional columns without requiring a database migration. */
function sim_question_columns(PDO $conn): array {
    $stmt = $conn->query('PRAGMA table_info(simulation_questions)');
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
}

function sim_get_questions(PDO $conn, int $id): array {
    $columns = sim_question_columns($conn);
    $mediaPath = in_array('media_path', $columns, true) ? 'media_path' : 'NULL AS media_path';
    $mediaType = in_array('media_type', $columns, true) ? 'media_type' : 'NULL AS media_type';
    $stmt = $conn->prepare("SELECT id, simulation_id, scenario_index, question, correct_answer, explanation, $mediaPath, $mediaType FROM simulation_questions WHERE simulation_id = ? ORDER BY scenario_index, id");
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sim_legacy_media_url($path): string {
    $path = (string)($path ?? '');
    if (str_starts_with($path, '/testmate/')) return substr($path, strlen('/testmate'));
    if (str_starts_with($path, 'testmate/')) return '/' . substr($path, strlen('testmate/'));
    if (preg_match('~^https?://~i', $path)) return $path;
    return '/' . ltrim($path, '/');
}
$sim_id   = isset($_GET['id'])   ? (int)$_GET['id']   : 0;
$mode     = isset($_GET['mode']) ? $_GET['mode']       : '';

// ─────────────────────────────────────────────────────────────────────────────
// Preview-card icons + page-header artwork.
// All original flat vector art in the Namibian road-sign style (yellow warning
// triangles with black borders, red regulatory discs, blue information discs).
// Drawn as inline SVG and turned into data URIs at runtime — nothing to upload,
// no asset folder, no binary blobs in the file.
// ─────────────────────────────────────────────────────────────────────────────
function sim_svg($inner, $box = '0 0 100 100') {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $box . '">' . $inner . '</svg>';
}
function sim_icon_uri($svg) {
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// Yellow warning triangle (shared base for every "ahead" sign)
$SIM_TRI = '<path d="M50 3 L98 89 H2 Z" fill="#111"/><path d="M50 16 L87 83 H13 Z" fill="#F5C518"/>';

$SIM_ICON_ART = [

    // Sign 192 — Traffic signal ahead
    'traffic_light' => $SIM_TRI .
        '<rect x="41" y="36" width="18" height="40" rx="4" fill="#111"/>' .
        '<circle cx="50" cy="46" r="5" fill="#E03A2F"/>' .
        '<circle cx="50" cy="56" r="5" fill="#F2A31B"/>' .
        '<circle cx="50" cy="66" r="5" fill="#2ECC71"/>',

    // Sign 193 — Traffic control (stop) ahead
    'four_way_stop' => $SIM_TRI .
        '<polygon points="64.8,68.1 56.1,76.8 43.9,76.8 35.2,68.1 35.2,55.9 43.9,47.2 56.1,47.2 64.8,55.9" fill="#D0021B"/>' .
        '<text x="50" y="66" font-family="Arial,Helvetica,sans-serif" font-size="10" font-weight="bold" fill="#fff" text-anchor="middle">STOP</text>',

    // Sign 44 — Speed limit
    'speed' =>
        '<circle cx="50" cy="50" r="47" fill="#fff"/>' .
        '<circle cx="50" cy="50" r="40" fill="none" stroke="#D0021B" stroke-width="12"/>' .
        '<text x="50" y="65" font-family="Arial,Helvetica,sans-serif" font-size="40" font-weight="bold" fill="#111" text-anchor="middle">60</text>',

    // Sign 57 — No parking
    'parking' =>
        '<circle cx="50" cy="50" r="47" fill="#fff"/>' .
        '<circle cx="50" cy="50" r="43" fill="#0A4EA2"/>' .
        '<circle cx="50" cy="50" r="39" fill="none" stroke="#D0021B" stroke-width="9"/>' .
        '<text x="50" y="66" font-family="Arial,Helvetica,sans-serif" font-size="42" font-weight="bold" fill="#fff" text-anchor="middle">P</text>' .
        '<line x1="23" y1="23" x2="77" y2="77" stroke="#D0021B" stroke-width="9" stroke-linecap="round"/>',

    // Sign 55 — No overtaking
    'overtake' =>
        '<circle cx="50" cy="50" r="47" fill="#fff"/>' .
        '<circle cx="50" cy="50" r="40" fill="none" stroke="#D0021B" stroke-width="10"/>' .
        '<g fill="#111"><rect x="26" y="46" width="19" height="17" rx="4"/><rect x="30" y="38" width="11" height="9" rx="3"/></g>' .
        '<g fill="#D0021B"><rect x="55" y="46" width="19" height="17" rx="4"/><rect x="59" y="38" width="11" height="9" rx="3"/></g>',

    // Sign 202 — Wild animals ahead
    'animal_crossing' => $SIM_TRI .
        '<g fill="#111">' .
        '<ellipse cx="47" cy="56" rx="15" ry="7"/>' .
        '<path d="M56 53 L62 41 L66 42 L60 55 Z"/>' .
        '<path d="M60 44 L73 39 L75 43 L63 49 Z"/>' .
        '<rect x="35" y="61" width="3.4" height="15"/><rect x="42" y="61" width="3.4" height="15"/>' .
        '<rect x="51" y="61" width="3.4" height="15"/><rect x="57" y="61" width="3.4" height="15"/>' .
        '<path d="M32 52 L27 46 L29 44 L34 50 Z"/>' .
        '</g>' .
        '<path d="M67 39 C69 32, 73 30, 76 32" stroke="#111" stroke-width="2.6" fill="none" stroke-linecap="round"/>',

    // Sign 196 — Scholar patrol ahead
    'school_zone' => $SIM_TRI .
        '<circle cx="46" cy="34" r="5.5" fill="#111"/>' .
        '<g stroke="#111" stroke-width="4.5" stroke-linecap="round" fill="none">' .
        '<line x1="46" y1="40" x2="46" y2="58"/>' .
        '<line x1="46" y1="58" x2="40" y2="74"/>' .
        '<line x1="46" y1="58" x2="53" y2="74"/>' .
        '<line x1="46" y1="44" x2="37" y2="51"/>' .
        '<line x1="46" y1="44" x2="56" y2="38"/>' .
        '</g>' .
        '<rect x="55" y="30" width="3.2" height="11" fill="#111"/>',

    // Custom — Night driving (no book equivalent)
    'night_driving' => $SIM_TRI .
        '<circle cx="42" cy="53" r="12" fill="#111"/>' .
        '<circle cx="37" cy="49" r="11" fill="#F5C518"/>' .
        '<rect x="52" y="47" width="7" height="14" rx="2" fill="#111"/>' .
        '<path d="M59 49 L80 42 L80 66 L59 59 Z" fill="#111" opacity=".85"/>',

    // Custom — Emergency vehicle (no book equivalent)
    'emergency_vehicle' => $SIM_TRI .
        '<g stroke="#111" stroke-width="3" stroke-linecap="round">' .
        '<line x1="50" y1="46" x2="50" y2="38"/>' .
        '<line x1="35" y1="49" x2="29" y2="43"/>' .
        '<line x1="65" y1="49" x2="71" y2="43"/>' .
        '</g>' .
        '<rect x="33" y="52" width="34" height="13" rx="3" fill="#111"/>' .
        '<rect x="36" y="55" width="12" height="7" rx="2" fill="#D0021B"/>' .
        '<rect x="52" y="55" width="12" height="7" rx="2" fill="#1B63C9"/>' .
        '<rect x="38" y="65" width="24" height="6" rx="2" fill="#111"/>',

    // Custom — Vehicle control check (no book equivalent)
    'vehicle_check' => $SIM_TRI .
        '<path d="M37 54 L43 60 L55 45" stroke="#111" stroke-width="6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' .
        '<path d="M47 75 L58 64" stroke="#111" stroke-width="7" stroke-linecap="round"/>' .
        '<circle cx="63" cy="59" r="8.5" fill="#111"/>' .
        '<circle cx="66" cy="56" r="4.5" fill="#F5C518"/>',
];

// Icons for the newer simulation types (same Namibian road-sign style).
$SIM_ICON_ART += [
    'gravel_road' => $SIM_TRI .
        '<path d="M30 74 Q50 40 70 74" stroke="#7a5230" stroke-width="7" fill="none"/>' .
        '<rect x="42" y="50" width="16" height="11" rx="3" fill="#111"/>' .
        '<circle cx="34" cy="70" r="3" fill="#111"/><circle cx="66" cy="70" r="3" fill="#111"/><circle cx="50" cy="76" r="2.5" fill="#111"/>',
    'truck_overtake' =>
        '<circle cx="50" cy="50" r="46" fill="#fff" stroke="#D62828" stroke-width="9"/>' .
        '<rect x="20" y="40" width="30" height="18" rx="2" fill="#D62828"/><rect x="50" y="44" width="10" height="14" rx="2" fill="#D62828"/>' .
        '<rect x="62" y="44" width="18" height="14" rx="3" fill="#111"/>',
    'roundabout' =>
        '<circle cx="50" cy="50" r="46" fill="#1D5FBF" stroke="#fff" stroke-width="4"/>' .
        '<path d="M30 40 A22 22 0 0 1 70 40 M70 60 A22 22 0 0 1 30 60" stroke="#fff" stroke-width="7" fill="none"/>' .
        '<path d="M74 30 l3 14 l-13 -3Z M26 70 l-3 -14 l13 3Z" fill="#fff"/>',
    'pedestrian_crossing' => $SIM_TRI .
        '<circle cx="51" cy="38" r="5" fill="#111"/><path d="M50 44 l-6 14 l-8 8 M50 44 l5 10 l7 5 M48 56 l8 12" stroke="#111" stroke-width="5" fill="none" stroke-linecap="round"/>' .
        '<path d="M28 76 H72" stroke="#111" stroke-width="4"/>',
    'yield_sign' =>
        '<path d="M6 12 H94 L50 92Z" fill="#fff" stroke="#D62828" stroke-width="10" stroke-linejoin="round"/>',
    'railway_crossing' =>
        '<path d="M14 14 L86 86 M86 14 L14 86" stroke="#fff" stroke-width="20"/>' .
        '<path d="M14 14 L86 86 M86 14 L14 86" stroke="#D62828" stroke-width="12" stroke-dasharray="14 9"/>',
    'following_distance' => $SIM_TRI .
        '<rect x="30" y="56" width="16" height="10" rx="3" fill="#111"/><rect x="56" y="56" width="16" height="10" rx="3" fill="#111"/>' .
        '<path d="M38 40 q3 6 0 8 q-3 -2 0 -8Z M50 34 q3 6 0 8 q-3 -2 0 -8Z M62 40 q3 6 0 8 q-3 -2 0 -8Z" fill="#1D5FBF"/>',
];
$SIM_ICONS = [];
foreach ($SIM_ICON_ART as $sim_key => $sim_art) {
    $SIM_ICONS[$sim_key] = sim_icon_uri(sim_svg($sim_art));
}

// Header artwork: all major road-sign illustrations displayed in a moving strip.
function sim_hero_art() {
    global $SIM_ICON_ART;

    // A smaller, cleaner hero: six signs are released one-by-one by the robot.
    // Each sign has its name underneath and appears with a short pop animation.
    $no_entry =
        '<circle cx="50" cy="50" r="47" fill="#fff"/>' .
        '<circle cx="50" cy="50" r="43" fill="#D0021B"/>' .
        '<rect x="22" y="43" width="56" height="14" rx="2" fill="#fff"/>';

    $warning =
        '<path d="M50 3 L98 89 H2 Z" fill="#111"/>' .
        '<path d="M50 16 L87 83 H13 Z" fill="#F5C518"/>' .
        '<path d="M50 29 L50 58" stroke="#111" stroke-width="7" stroke-linecap="round"/>' .
        '<circle cx="50" cy="70" r="5" fill="#111"/>';

    $signs = [
        ['art' => $SIM_ICON_ART['traffic_light'],  'label' => 'Traffic Light'],
        ['art' => $no_entry,                        'label' => 'No Entry'],
        ['art' => $SIM_ICON_ART['parking'],         'label' => 'Parking'],
        ['art' => $warning,                         'label' => 'Warning Sign'],
        ['art' => $SIM_ICON_ART['speed'],           'label' => 'Speed Limit'],
        ['art' => $SIM_ICON_ART['animal_crossing'], 'label' => 'Wild Animals'],
    ];

    // Cute robot standing at the left and releasing the signs.
    $robot =
        '<ellipse cx="92" cy="121" rx="60" ry="8" fill="#071522" opacity=".45"/>' .
        '<rect x="52" y="62" width="80" height="57" rx="24" fill="#e9f4fb" stroke="#7ea6bd" stroke-width="3"/>' .
        '<rect x="62" y="72" width="60" height="35" rx="15" fill="#142a3a"/>' .
        '<circle cx="82" cy="89" r="7" fill="#27b7ff"/>' .
        '<circle cx="102" cy="89" r="7" fill="#27b7ff"/>' .
        '<circle cx="82" cy="89" r="2.5" fill="#fff"/>' .
        '<circle cx="102" cy="89" r="2.5" fill="#fff"/>' .
        '<path d="M84 99 Q92 105 100 99" fill="none" stroke="#7fe4ff" stroke-width="3" stroke-linecap="round"/>' .
        '<rect x="68" y="115" width="48" height="13" rx="6" fill="#2c91ca"/>' .
        '<circle cx="92" cy="121" r="3" fill="#8eeaff"/>' .
        '<path d="M52 78 L30 96" stroke="#e9f4fb" stroke-width="12" stroke-linecap="round"/>' .
        '<circle cx="27" cy="99" r="8" fill="#2c91ca"/>' .
        '<path d="M132 78 L154 92" stroke="#e9f4fb" stroke-width="12" stroke-linecap="round"/>' .
        '<circle cx="157" cy="94" r="8" fill="#2c91ca"/>' .
        '<path d="M73 128 L67 141" stroke="#e9f4fb" stroke-width="12" stroke-linecap="round"/>' .
        '<path d="M111 128 L117 141" stroke="#e9f4fb" stroke-width="12" stroke-linecap="round"/>' .
        '<circle cx="92" cy="48" r="7" fill="#2c91ca"/>' .
        '<path d="M92 48 L92 34" stroke="#e9f4fb" stroke-width="5"/>' .
        '<circle cx="92" cy="31" r="4" fill="#27b7ff"/>' .
        '<path d="M152 91 Q173 82 190 76" fill="none" stroke="#27b7ff" stroke-width="3" stroke-dasharray="5 5" opacity=".7"/>';

    $out = '<g class="sim-robot">' . $robot . '</g>';

    // Sign positions: six compact signs, each released sequentially from the robot.
    $x_positions = [205, 345, 485, 625, 765, 905];
    foreach ($signs as $i => $item) {
        $delay = number_format($i * 0.75, 2, '.', '');
        $x = $x_positions[$i];
        $out .= '<g transform="translate(' . $x . ',12)"><g class="sim-hero-sign sign-' . ($i + 1) . '" style="--sign-delay:' . $delay . 's">';
        $out .= '<g class="sign-picture" transform="scale(.64)">';
        $out .= $item['art'];
        $out .= '</g>';
        $out .= '<rect class="sign-label-bg" x="0" y="78" width="100" height="22" rx="11" fill="#102b40" opacity=".94"/>';
        $out .= '<text class="sign-label" x="50" y="93" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="9" font-weight="700" fill="#fff">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</text>';
        $out .= '</g></g>';
    }

    return sim_svg($out, '0 0 1035 145');
}

// Sidebar illustration: a learner holding up a licence card. Original flat vector art.
function sim_learner_art() {
    $art =
        // ground shadow
        '<ellipse cx="100" cy="243" rx="50" ry="7" fill="#000" opacity=".28"/>' .
        // legs + shoes
        '<path d="M72 150 H128 L133 236 H111 L100 182 L89 236 H67 Z" fill="#22303f"/>' .
        '<rect x="63" y="230" width="28" height="11" rx="5" fill="#141d26"/>' .
        '<rect x="109" y="230" width="28" height="11" rx="5" fill="#141d26"/>' .
        // torso
        '<path d="M70 84 Q100 76 130 84 L137 156 H63 Z" fill="#3498db"/>' .
        '<path d="M88 79 L100 97 L112 79 L105 76 L100 85 L95 76 Z" fill="#eaf4ff"/>' .
        // arms
        '<path d="M71 86 L59 139 L74 143 L83 100 Z" fill="#2b86c4"/>' .
        '<path d="M129 86 L147 116 L124 133 L112 116 Z" fill="#2b86c4"/>' .
        // licence card (held up in the right hand)
        '<g transform="rotate(-8 134 118)">' .
        '<rect x="104" y="98" width="64" height="41" rx="5" fill="#fff"/>' .
        '<path d="M104 103 a5 5 0 0 1 5 -5 h54 a5 5 0 0 1 5 5 v6 h-64 Z" fill="#1B63C9"/>' .
        '<rect x="110" y="114" width="19" height="20" rx="2" fill="#9fb6c9"/>' .
        '<circle cx="119.5" cy="121" r="4" fill="#7d94a6"/>' .
        '<path d="M113 134 q6.5 -8 13 0 Z" fill="#7d94a6"/>' .
        '<rect x="134" y="115" width="28" height="4" rx="2" fill="#cdd8e0"/>' .
        '<rect x="134" y="123" width="28" height="4" rx="2" fill="#cdd8e0"/>' .
        '<rect x="134" y="131" width="19" height="4" rx="2" fill="#cdd8e0"/>' .
        '</g>' .
        // hands (drawn after the card so the fingers sit in front of it)
        '<circle cx="66" cy="145" r="9" fill="#B5713F"/>' .
        '<circle cx="120" cy="131" r="9" fill="#B5713F"/>' .
        // neck + head
        '<rect x="92" y="64" width="16" height="18" fill="#9a5c32"/>' .
        '<circle cx="100" cy="48" r="26" fill="#B5713F"/>' .
        '<path d="M74 45 Q77 19 100 19 Q123 19 126 45 Q119 31 100 31 Q81 31 74 45 Z" fill="#241c17"/>' .
        '<circle cx="91" cy="47" r="2.7" fill="#241c17"/>' .
        '<circle cx="109" cy="47" r="2.7" fill="#241c17"/>' .
        '<path d="M91 57 Q100 65 109 57" stroke="#241c17" stroke-width="2.6" fill="none" stroke-linecap="round"/>';

    return sim_svg($art, '0 0 200 250');
}

// Handle quiz submission: correct answers are reloaded from SQLite, not the browser.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_simulation'])) {
    if (!hash_equals($_SESSION['sim_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid quiz submission. Reload this page and try again.');
    }
    $sim_id = max(0, (int)($_POST['simulation_id'] ?? 0));
    $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    $s = $conn->prepare('SELECT * FROM simulations WHERE id = ? AND is_active = 1');
    $s->execute([$sim_id]);
    $simulation = $s->fetch(PDO::FETCH_ASSOC);
    if (!$simulation) {
        http_response_code(404);
        exit('Simulation not found.');
    }
    $questions_data = sim_get_questions($conn, $sim_id);
    if (!$questions_data) {
        http_response_code(400);
        exit('This simulation does not have any quiz questions.');
    }
    $scenarios = json_decode((string)($simulation['scenario_data'] ?? '[]'), true);
    if (!is_array($scenarios)) $scenarios = [];
    $score = 0;
    $total = count($questions_data);
    $results = [];
    foreach ($questions_data as $i => $q) {
        $user_answer = strtolower(trim((string)($answers[$i] ?? '')));
        if (!in_array($user_answer, ['correct', 'incorrect'], true)) $user_answer = '';
        $correct_answer = strtolower(trim((string)$q['correct_answer']));
        $is_correct = $user_answer !== '' && hash_equals($correct_answer, $user_answer);
        if ($is_correct) $score++;
        $results[] = [
            'question' => $q['question'],
            'user_answer' => $user_answer,
            'correct' => $correct_answer,
            'is_correct' => $is_correct,
            'explanation' => $q['explanation'] ?? '',
            'scenario_index' => (int)$q['scenario_index'],
        ];
    }
    // SQLite migration: simulation_results.id has no auto-increment, so
    // allocate the next id inside a transaction (same approach as quiz.php).
    try {
        $conn->beginTransaction();
        $nextResultId = (int)$conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM simulation_results')->fetchColumn();
        $ins = $conn->prepare("INSERT INTO simulation_results (id, user_id, simulation_id, mode, score, total, completed, completed_at) VALUES (?, ?, ?, 'quiz', ?, ?, 1, CURRENT_TIMESTAMP)");
        $ins->execute([$nextResultId, $user_id, $sim_id, $score, $total]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('[TestMate Simulation Save] ' . $e->getMessage());
        http_response_code(500);
        exit('Your simulation quiz result could not be saved. Check the PHP server terminal.');
    }

    include 'includes/header.php';
    ?>
    <?php $pct = $total > 0 ? (int)round($score / $total * 100) : 0; ?>
    <header class="sim-head">
        <div>
            <span class="sim-kicker">Simulation Quiz Results</span>
            <h1><?= htmlspecialchars($simulation['title']) ?></h1>
            <p>Review each scenario and the correct driver response.</p>
        </div>
    </header>
    <div class="container">
        <!-- Score Banner -->
        <section class="sim-result <?= $pct >= 80 ? 'is-pass' : 'is-fail' ?>">
            <div class="sim-result-ring" style="--pct:<?= $pct ?>%"><span><?= $pct ?>%</span></div>
            <div>
                <strong><?= $score ?> / <?= $total ?> correct</strong>
                <p><?= $pct >= 80 ? 'Excellent! You understand the rules well.' : 'Keep studying and try again!' ?></p>
            </div>
        </section>

        <nav class="sim-actions">
            <a href="/simulations.php?id=<?= $sim_id ?>&mode=quiz" class="btn btn-primary">Try Again</a>
            <a href="/simulations.php?id=<?= $sim_id ?>&mode=study" class="btn btn-outline">Study Mode</a>
            <a href="/simulations.php" class="btn btn-outline">All Simulations</a>
        </nav>

        <h2 class="sim-section-title">Answer Review</h2>

        <?php foreach ($results as $i => $r):
            $scenario = $scenarios[$r['scenario_index']] ?? [];
        ?>
        <article class="sim-review <?= $r['is_correct'] ? 'is-correct' : 'is-wrong' ?>">
            <div class="sim-review-head">
                <span>Question <?= $i+1 ?></span>
                <span class="sim-tag <?= $r['is_correct'] ? 'is-correct' : 'is-wrong' ?>"><?= $r['is_correct'] ? 'Correct' : 'Wrong' ?></span>
            </div>

            <!-- Mini animation replay -->
            <div class="sim-review-replay">
                <?php if ($simulation['animation_type'] === 'traffic_light' && $scenario): ?>
                <div style="display:inline-flex;align-items:center;gap:16px;font-size:14px;">
                    <span style="font-size:24px;">🚗</span>
                    <span style="font-size:13px;color:#666;">approaches</span>
                    <div style="background:#333;border-radius:6px;padding:4px 8px;display:inline-flex;flex-direction:column;gap:3px;">
                        <div style="width:20px;height:20px;border-radius:50%;background:<?= $scenario['light']==='red'?'#e74c3c':'#333' ?>;margin:0 auto;"></div>
                        <div style="width:20px;height:20px;border-radius:50%;background:<?= $scenario['light']==='amber'?'#f1c40f':'#333' ?>;margin:0 auto;"></div>
                        <div style="width:20px;height:20px;border-radius:50%;background:<?= $scenario['light']==='green'?'#2ecc71':'#333' ?>;margin:0 auto;"></div>
                    </div>
                    <span style="font-size:13px;color:#666;"><?= $scenario['car_passes']?'drives through':'stops' ?></span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php elseif (isset($scenario['oncoming'])): ?>
                <div style="display:inline-flex;align-items:center;gap:14px;font-size:14px;color:#555;">
                    <span style="font-size:24px;">🚗🚙</span>
                    <span><?= $scenario['oncoming'] ? 'Overtook with oncoming traffic close by' : 'Overtook with a clear road' ?></span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php elseif (isset($scenario['animal'])): ?>
                <div style="display:inline-flex;align-items:center;gap:14px;font-size:14px;color:#555;">
                    <span style="font-size:24px;">🚗 <?= htmlspecialchars($scenario['animal']) ?></span>
                    <span>Driver <?= $scenario['slows'] ? 'slowed down' : 'continued at speed' ?></span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php elseif (isset($scenario['vehicle'])): ?>
                <div style="display:inline-flex;align-items:center;gap:14px;font-size:14px;color:#555;">
                    <span style="font-size:24px;"><?= ['ambulance'=>'🚑','fire_truck'=>'🚒','police'=>'🚓'][$scenario['vehicle']] ?? '🚨' ?></span>
                    <span>Sirens <?= htmlspecialchars($scenario['sirens']) ?> — <?= htmlspecialchars(ucfirst(str_replace('_',' ',$scenario['action']))) ?></span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php elseif (isset($scenario['lights'])): ?>
                <div style="display:inline-flex;align-items:center;gap:14px;font-size:14px;color:#555;">
                    <span style="font-size:24px;">🌙🚗</span>
                    <span><?= htmlspecialchars(ucfirst(str_replace('_',' ',$scenario['condition'] ?? ''))) ?> — Lights: <?= htmlspecialchars(ucfirst(str_replace('_',' ',$scenario['lights']))) ?></span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php elseif ($scenario): ?>
                <div style="display:inline-flex;align-items:center;gap:14px;font-size:14px;color:#555;">
                    <span style="font-size:24px;">🚗</span>
                    <span>
                        <?php if (isset($scenario['your_speed'])): ?>
                            School zone: driving <?= (int)$scenario['your_speed'] ?> km/h (limit <?= (int)$scenario['speed'] ?> km/h)
                        <?php elseif (isset($scenario['location'])): ?>
                            Parked: <?= htmlspecialchars(ucfirst(str_replace('_',' ',$scenario['location']))) ?>
                        <?php elseif (isset($scenario['speed'])): ?>
                            Speed: <?= (int)$scenario['speed'] ?> km/h (limit <?= (int)$scenario['limit'] ?> km/h)
                        <?php elseif (isset($scenario['arriving_order'])): ?>
                            Arrived <?= htmlspecialchars($scenario['arriving_order']) ?> — <?= htmlspecialchars($scenario['action']) ?>
                        <?php elseif (isset($scenario['label'])): ?>
                            <?= htmlspecialchars($scenario['label']) ?>
                        <?php else: ?>
                            <?= htmlspecialchars(ucfirst($scenario['sign'] ?? '')) ?> sign
                        <?php endif; ?>
                    </span>
                    <span style="font-size:20px;"><?= $scenario['is_correct']?'✅':'❌' ?></span>
                </div>
                <?php endif; ?>
            </div>

            <p class="sim-review-question"><?= htmlspecialchars($r['question']) ?></p>

            <div class="sim-review-options">
                <?php foreach (['correct'=>'✅ Correct','incorrect'=>'❌ Incorrect'] as $val=>$label):
                    $is_user    = ($r['user_answer'] === $val);
                    $is_correct = ($r['correct'] === $val);
                ?>
                <div class="sim-review-option <?= $is_correct ? 'is-correct' : ($is_user ? 'is-wrong' : '') ?>">
                    <?= $label ?>
                    <?php if ($is_correct): ?> — Correct<?php endif; ?>
                    <?php if ($is_user && !$is_correct): ?> — Your Answer<?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="sim-review-explain">
                <?= htmlspecialchars($r['explanation']) ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php
    echo <<<'TM_REFERENCE_LAYOUT'
<style>
/* =====================================================
   TESTMATE SIMULATIONS - 2026 DESIGN
   Replaces the old "reference layout" patch blocks.
   It no longer resizes the shared header/sidebar, so this
   page now uses the same 74px header and 252px sidebar as
   every other learner page (css/style.css).
   Animation CSS, scene markup and JavaScript are unchanged.
   ===================================================== */
html body.tm-page-reference:not(.tm-sim-embedded) { background: #f4f8fd !important; }
body.tm-page-reference { font-family: "Segoe UI", Arial, sans-serif; color: #102744; }

/* Page width shared by all three simulation screens */
html body.tm-page-reference .sim-head,
html body.tm-page-reference .container {
    box-sizing: border-box;
    width: 100% !important;
    max-width: 1370px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}
html body.tm-page-reference .container { padding: 0 30px 50px !important; }

/* Page banner (matches the dashboard and other pages) */
html body.tm-page-reference .sim-head {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    width: calc(100% - 60px) !important;
    max-width: 1310px !important;
    margin-top: 28px !important;
    margin-bottom: 20px !important;
    padding: 26px 30px;
    border: 1px solid #dce9f8;
    border-radius: 18px;
    background: linear-gradient(105deg, #e9f4ff 0%, #f6faff 54%, #c7e4ff 100%);
}
.sim-head::after {
    content: "";
    position: absolute;
    width: 240px; height: 240px;
    right: -80px; top: -130px;
    border-radius: 50%;
    background: rgba(255,255,255,.4);
    pointer-events: none;
}
.sim-head > * { position: relative; z-index: 1; }
.sim-kicker {
    display: block;
    margin-bottom: 8px;
    color: #2c82c2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
}
.sim-head h1 { margin: 0 0 7px; color: #102744; font-size: clamp(24px, 2.4vw, 32px); line-height: 1.2; font-weight: 800; letter-spacing: -.5px; }
.sim-head p { margin: 0; max-width: 680px; color: #526f8b; font-size: 14px; line-height: 1.6; }
.sim-head-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border: 1px solid #e0ebf7;
    border-radius: 999px;
    background: rgba(255,255,255,.9);
    color: #173652;
    font-size: 13px;
    font-weight: 800;
}
.sim-head-count i { width: 9px; height: 9px; border-radius: 50%; background: #10a77a; }

/* Single-simulation screens use a narrower column; align the banner to it */
html body.tm-page-reference .sim-head.is-narrow { max-width: 940px !important; }

/* Study / Quiz mode switch */
.sim-mode-switch {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    border: 1px solid #dce7f3;
    border-radius: 12px;
    background: #fff;
}
.sim-mode-switch a {
    padding: 9px 16px;
    border-radius: 9px;
    color: #4d6782;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
}
.sim-mode-switch a:hover { background: #f1f7ff; color: #147df5; }
.sim-mode-switch a.is-active { background: #147df5; color: #fff; }
.sim-mode-switch a.is-active.is-quiz { background: #8950dc; }

/* Buttons used on these screens */
html body.tm-page-reference .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 18px;
    border: 1px solid transparent;
    border-radius: 10px !important;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}
html body.tm-page-reference .btn-lg { min-height: 48px; padding: 12px 26px; font-size: 15px; }
html body.tm-page-reference .btn-primary { background: #147df5 !important; border-color: #147df5 !important; color: #fff !important; }
html body.tm-page-reference .btn-primary:hover { background: #0b68d6 !important; }
html body.tm-page-reference .btn-outline { background: #fff !important; border: 1px solid #d6e4f3 !important; color: #1569b8 !important; }
html body.tm-page-reference .btn-outline:hover { background: #eef6ff !important; }

/* ---------- Library: list + player ---------- */
.tm-sim-workspace { display: grid; grid-template-columns: 330px minmax(0, 1fr); gap: 20px; align-items: start; }
.tm-sim-library {
    display: grid;
    gap: 10px;
    max-height: 780px;
    overflow: auto;
    padding: 2px 4px 4px 2px;
}
.tm-sim-library-title { margin: 0 0 2px; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-choice {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) 18px;
    gap: 12px;
    align-items: center;
    width: 100%;
    padding: 12px;
    border: 1px solid #e2eaf3;
    border-radius: 13px;
    background: #fff;
    color: #24425d;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    box-shadow: 0 3px 12px rgba(20,48,76,.04);
    transition: border-color .15s, background .15s, box-shadow .15s;
}
.tm-sim-choice:hover { border-color: #98c5ff; background: #f7fbff; }
.tm-sim-choice.is-active { border-color: #147df5; background: #eef6ff; box-shadow: inset 4px 0 #147df5, 0 6px 16px rgba(20,125,245,.12); }
.tm-sim-thumbnail {
    display: grid;
    place-items: center;
    width: 52px; height: 52px;
    border-radius: 13px;
    background: #f4f8fd;
}
.tm-sim-choice.is-active .tm-sim-thumbnail { background: #fff; }
.tm-sim-thumbnail img { width: 38px; height: 38px; object-fit: contain; }
.tm-sim-choice-copy { min-width: 0; }
.tm-sim-choice strong { display: block; margin-bottom: 3px; color: #102744; font-size: 14px; line-height: 1.35; }
.tm-sim-choice small { display: block; margin-bottom: 3px; color: #10a77a; font-size: 11px; font-weight: 800; }
.tm-sim-choice-copy > span {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    color: #6a7e93;
    font-size: 12px;
    line-height: 1.45;
}
.tm-sim-chevron { color: #9fb2c6; font-size: 20px; line-height: 1; }
.tm-sim-choice.is-active .tm-sim-chevron { color: #147df5; }

.tm-sim-player {
    min-width: 0;
    overflow: hidden;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.tm-sim-player-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #edf1f6;
}
.tm-sim-player-head span { display: block; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-player-head strong { display: block; margin-top: 2px; color: #102744; font-size: 17px; }
.tm-sim-live { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px; background: #e2f8ec; color: #0b8a5f; font-size: 11px; font-weight: 800; }
.tm-sim-live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
html body .tm-sim-player iframe { display: block; width: 100%; height: 560px; border: 0 !important; border-radius: 0 !important; background: #f4f8fd; transition: height .2s ease; }
html body .tm-sim-player-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    padding: 16px 20px;
    border-top: 1px solid #edf1f6;
    background: #f8fbff;
}
html body .tm-sim-player-footer p { margin: 0; color: #647b95; font-size: 13px; }
.tm-sim-player-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.tm-sim-player-actions .btn { min-height: 42px; padding: 10px 18px !important; font-size: 13px !important; white-space: nowrap; }
.tm-sim-player-actions .btn-primary { background: #8950dc !important; border-color: #8950dc !important; }
.tm-sim-player-actions .btn-primary:hover { background: #7440cf !important; }
.tm-sim-choice:focus-visible, .tm-sim-pager button:focus-visible { outline: 3px solid #76b8ff; outline-offset: 2px; }

/* ---------- Study / quiz scenarios ---------- */
.sim-note {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 20px;
    padding: 14px 18px;
    border: 1px solid #cfe3fb;
    border-left: 4px solid #147df5;
    border-radius: 12px;
    background: #eef6ff;
    color: #35536d;
    font-size: 14px;
    line-height: 1.6;
}
.sim-note strong { color: #0f5fb8; white-space: nowrap; }
.sim-note.is-quiz { border-color: #e2d4f8; border-left-color: #8950dc; background: #f6f1ff; }
.sim-note.is-quiz strong { color: #6b38c2; }

.tm-sim-lesson-card,
.quiz-q-card {
    margin-bottom: 20px !important;
    padding: 18px;
    border: 1px solid #e2eaf3 !important;
    border-radius: 16px !important;
    background: #fff !important;
    box-shadow: 0 5px 18px rgba(20,48,76,.06) !important;
}
.tm-sim-lesson-card > h3 { margin: 0 0 12px !important; color: #102744 !important; font-size: 16px !important; font-weight: 800 !important; }
.tm-sim-lesson-card > h3 span { color: #647b95 !important; }

html body.tm-page-reference .sim-container {
    overflow: hidden;
    margin-bottom: 12px !important;
    border: 1px solid #dce8f5 !important;
    border-radius: 13px !important;
    background: #102744 !important;
}
html body.tm-page-reference .sim-screen { border-radius: 0 !important; }
html body.tm-page-reference .sim-info {
    background: #102744 !important;
    color: #e3eef9 !important;
    padding: 13px 18px !important;
    gap: 16px !important;
}
html body.tm-page-reference .sim-info button {
    background: #147df5 !important;
    color: #fff !important;
    border-radius: 9px !important;
    padding: 9px 20px !important;
    font-weight: 800 !important;
}
html body.tm-page-reference .sim-info button:hover { background: #0b68d6 !important; }
[id^="explanation-study-"] { border-radius: 0 12px 12px 0 !important; }

/* Quiz answer choices (selectAnswer() sets inline colours, so these win with !important) */
.quiz-q-card > p { color: #102744 !important; font-size: 15px !important; font-weight: 700 !important; }
.quiz-q-card .answer-btn {
    display: flex !important;
    align-items: center;
    gap: 12px;
    width: 100%;
    margin: 0 0 9px !important;
    padding: 13px 16px !important;
    border: 1px solid #dce7f2 !important;
    border-radius: 11px !important;
    background: #fff !important;
    color: #264766 !important;
    font-family: inherit;
    font-size: 14px !important;
    text-align: left;
    cursor: pointer;
}
.quiz-q-card .answer-btn:hover { border-color: #98c5ff !important; background: #f5faff !important; }
.quiz-q-card .answer-btn.selected { border-color: #147df5 !important; background: #eef6ff !important; color: #0f4d94 !important; font-weight: 700; box-shadow: inset 4px 0 #147df5; }
.quiz-q-card .answer-btn .btn-icon { font-size: 18px !important; }
.sim-submit-box {
    margin: 6px 0 24px;
    padding: 22px;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    text-align: center;
}
.sim-submit-box p { margin: 10px 0 0; color: #7b8fa4; font-size: 13px; }
#quizValidationMsg { padding: 10px 14px; border-radius: 9px; background: #fdecea; color: #b43333 !important; font-weight: 700; }

.sim-cta { margin: 24px 0 30px; padding: 22px; border-radius: 16px; background: #102744; color: #fff; text-align: center; }
.sim-cta p { margin: 0 0 12px; color: #c4d7ec; font-size: 14px; }
.sim-cta .btn-primary { background: #f5c518 !important; border-color: #f5c518 !important; color: #102744 !important; }
.sim-cta .btn-primary:hover { background: #ffd43b !important; }
.sim-bottom-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin: 0 0 40px; }

/* Pager (built by the script below) */
.tm-sim-lesson[hidden] { display: none !important; }
.tm-sim-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 18px 0 26px; }
.tm-sim-pager button {
    min-width: 110px;
    padding: 11px 20px;
    border: 1px solid #d6e4f3;
    border-radius: 10px;
    background: #fff;
    color: #147df5;
    font-family: inherit;
    font-weight: 800;
    cursor: pointer;
}
.tm-sim-pager button:last-child { background: #147df5; border-color: #147df5; color: #fff; }
.tm-sim-pager button:disabled { opacity: .4; cursor: default; }
.tm-sim-pager span { color: #647b95; font-size: 12px; font-weight: 700; }

/* ---------- Quiz results ---------- */
.sim-result {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 18px;
    padding: 26px 28px;
    border-radius: 18px;
    color: #fff;
}
.sim-result.is-pass { background: linear-gradient(120deg, #0b8a5f, #10a77a); }
.sim-result.is-fail { background: linear-gradient(120deg, #b6303e, #e5394a); }
.sim-result-ring {
    display: grid;
    place-items: center;
    flex: 0 0 104px;
    width: 104px; height: 104px;
    border-radius: 50%;
    background: conic-gradient(#fff var(--pct), rgba(255,255,255,.25) 0);
}
.sim-result-ring span { display: grid; place-items: center; width: 84px; height: 84px; border-radius: 50%; background: rgba(0,0,0,.18); font-size: 24px; font-weight: 800; }
.sim-result strong { display: block; font-size: 30px; font-weight: 800; }
.sim-result p { margin: 4px 0 0; color: rgba(255,255,255,.9); font-size: 14px; }
.sim-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
.sim-section-title { margin: 0 0 14px; color: #102744; font-size: 19px; font-weight: 800; }
.sim-review {
    margin-bottom: 16px;
    padding: 20px;
    border: 1px solid #e2eaf3;
    border-left: 5px solid #10a77a;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.sim-review.is-wrong { border-left-color: #e5394a; }
.sim-review-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
.sim-review-head span { color: #647b95; font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.sim-tag { padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 800; }
.sim-tag.is-correct { background: #dff7ed; color: #0b8a5f; }
.sim-tag.is-wrong { background: #fdeaea; color: #b93434; }
.sim-review-replay { margin-bottom: 14px; padding: 14px; border-radius: 11px; background: #f4f8fd; text-align: center; }
.sim-review-question { margin: 0 0 12px; color: #102744; font-size: 15px; font-weight: 700; }
.sim-review-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.sim-review-option { padding: 12px 14px; border: 1px solid #e1eaf3; border-radius: 10px; background: #f7faff; color: #35536d; font-size: 14px; font-weight: 600; text-align: center; }
.sim-review-option.is-correct { border-color: #a6e5c6; background: #e5f8ef; color: #0b7a54; }
.sim-review-option.is-wrong { border-color: #edbfc2; background: #ffeded; color: #b43333; }
.sim-review-explain { padding: 12px 15px; border-left: 4px solid #147df5; border-radius: 0 10px 10px 0; background: #eef6ff; color: #35536d; font-size: 13px; line-height: 1.65; }

/* ---------- Embedded study player (inside the library iframe) ---------- */
html body.tm-page-reference.tm-sim-embedded,
html body.tm-page-reference.has-sidebar.tm-sim-embedded { padding: 0 !important; margin: 0 !important; min-height: 0 !important; background: #f4f8fd !important; }
body.tm-sim-embedded .navbar,
body.tm-sim-embedded .tm-sidebar,
body.tm-sim-embedded .tm-mobile-overlay,
body.tm-sim-embedded .page-header,
body.tm-sim-embedded .sim-head,
body.tm-sim-embedded footer,
body.tm-sim-embedded .footer,
body.tm-sim-embedded .tm-footer { display: none !important; }
html body.tm-page-reference.tm-sim-embedded .container { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 16px !important; }
body.tm-sim-embedded .sim-screen { height: 340px !important; min-height: 0 !important; box-sizing: border-box; }
body.tm-sim-embedded .tm-sim-lesson-card { margin-bottom: 12px !important; box-shadow: none !important; }
body.tm-sim-embedded .tm-sim-lesson > h3 { font-size: 15px !important; }
body.tm-sim-embedded .tm-sim-intro,
body.tm-sim-embedded .tm-sim-end-links { display: none !important; }
body.tm-sim-embedded .tm-sim-pager { margin: 12px 0 4px; }

/* ---------- Responsive ---------- */
@media (max-width: 1150px) {
    .tm-sim-workspace { grid-template-columns: 270px minmax(0, 1fr); gap: 16px; }
    .tm-sim-choice { grid-template-columns: 44px minmax(0, 1fr); }
    .tm-sim-chevron { display: none; }
    .tm-sim-thumbnail { width: 44px; height: 44px; }
    .tm-sim-thumbnail img { width: 32px; height: 32px; }
}
@media (max-width: 760px) {
    html body.tm-page-reference .container { padding: 0 16px 40px !important; }
    html body.tm-page-reference .sim-head { width: calc(100% - 32px) !important; margin-top: 18px !important; padding: 22px 20px; }
    .tm-sim-workspace { display: flex; flex-direction: column; }
    .tm-sim-player { width: 100%; order: 0; }
    .tm-sim-library { width: 100%; order: 1; max-height: 440px; }
    .tm-sim-player iframe { height: 660px; }
    .tm-sim-player-footer { flex-direction: column; align-items: stretch; }
    .tm-sim-player-actions .btn { flex: 1; }
    .sim-result { flex-direction: column; text-align: center; }
    .sim-review-options { grid-template-columns: 1fr; }
    .sim-note { flex-direction: column; gap: 4px; }
    body.tm-sim-embedded .sim-screen { height: 270px !important; }
}
</style><script>document.body.classList.add("tm-page-reference");</script>
<script>
/* Fit the embedded simulation player to its content (same-origin iframe). */
(function () {
    var frame = document.getElementById('tmSimFrame');
    if (!frame) return;
    function fit() {
        try {
            var doc = frame.contentDocument;
            var box = doc && doc.querySelector('.container');
            if (box) frame.style.height = Math.max(380, Math.ceil(box.getBoundingClientRect().height) + 4) + 'px';
        } catch (e) {}
    }
    frame.addEventListener('load', function () {
        fit();
        setTimeout(fit, 300);
        try {
            var win = frame.contentWindow;
            if (win.ResizeObserver) new win.ResizeObserver(fit).observe(frame.contentDocument.body);
        } catch (e) {}
    });
})();
</script>
<script>
(function(){
    const choices=Array.from(document.querySelectorAll('.tm-sim-choice'));
    const frame=document.getElementById('tmSimFrame');
    if(frame){
        choices.forEach(button=>button.addEventListener('click',()=>{
            const id=Number(button.dataset.id);if(!Number.isInteger(id)||id<1)return;
            if(button.classList.contains('is-active'))return;
            choices.forEach(choice=>{const active=choice===button;choice.classList.toggle('is-active',active);choice.setAttribute('aria-pressed',String(active));});
            frame.src='/simulations.php?id='+id+'&mode=study&embedded=1';frame.title=button.dataset.title;
            document.getElementById('tmSimSelectedTitle').textContent=button.dataset.title;
            document.getElementById('tmSimQuizLink').href='/simulations.php?id='+id+'&mode=quiz';
            document.getElementById('tmSimStudyLink').href='/simulations.php?id='+id+'&mode=study';
        }));
    }
    const scenes=Array.from(document.querySelectorAll('.sim-container[id^="sim-study-"]'));
    if(!scenes.length)return;
    const lessons=scenes.map(scene=>scene.parentElement);
    const container=lessons[0].parentElement;
    lessons.forEach(lesson=>lesson.classList.add('tm-sim-lesson'));
    const pager=document.createElement('div');pager.className='tm-sim-pager';pager.innerHTML='<button type="button">Previous</button><span aria-live="polite"></span><button type="button">Next</button>';
    lessons[lessons.length-1].insertAdjacentElement('afterend',pager);
    let current=0;
    function show(index){current=Math.max(0,Math.min(index,lessons.length-1));lessons.forEach((lesson,i)=>lesson.hidden=i!==current);pager.children[0].disabled=current===0;pager.children[2].disabled=current===lessons.length-1;pager.children[1].textContent='Scenario '+(current+1)+' of '+lessons.length;}
    pager.children[0].addEventListener('click',()=>show(current-1));pager.children[2].addEventListener('click',()=>show(current+1));show(0);
    if(new URLSearchParams(window.location.search).get('embedded')==='1'){
        document.body.classList.add('tm-sim-embedded');
        (function isolateEmbeddedPlayer(){
            let node=container;
            while(node&&node.parentElement){
                const parent=node.parentElement;
                Array.from(parent.children).forEach(sibling=>{
                    if(sibling!==node&&!['SCRIPT','STYLE','LINK','META','TEMPLATE'].includes(sibling.tagName)){
                        sibling.style.setProperty('display','none','important');
                    }
                });
                parent.style.setProperty('padding','0','important');
                parent.style.setProperty('margin','0','important');
                parent.style.setProperty('transform','none','important');
                parent.style.setProperty('width','100%','important');
                parent.style.setProperty('max-width','none','important');
                parent.style.setProperty('position','static','important');
                if(parent===document.body)break;
                node=parent;
            }
        })();
        const intro=Array.from(container.children).find(child=>child!==lessons[0]&&child!==pager&&child.querySelector('strong'));
        if(intro)intro.classList.add('tm-sim-intro');
        Array.from(container.children).filter(child=>child.querySelector('a[href*="mode=quiz"]')||child.querySelector('a[href="/simulations.php"]')).forEach(child=>child.classList.add('tm-sim-end-links'));
        document.querySelectorAll('video').forEach(video=>video.preload='metadata');
    }
})();
</script>

TM_REFERENCE_LAYOUT;
include 'includes/footer.php';
    exit();
}

// Get all simulations list
if (!$sim_id) {
    $sims = $conn->query("
        SELECT s.*, t.name AS topic_name,
               COUNT(DISTINCT sq.id) AS question_count
        FROM simulations s
        LEFT JOIN topics t ON s.topic_id = t.id
        LEFT JOIN simulation_questions sq ON s.id = sq.simulation_id
        WHERE s.is_active = 1
        GROUP BY s.id
        ORDER BY s.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    include 'includes/header.php';
    ?>
    <style>
    /* =========================================================
       SIMULATIONS PAGE HEADER
       ========================================================= */
    .sim-hero {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 18px;
        width: 100%;
        min-height: 125px;
        overflow: hidden;
    }

    .sim-hero-copy {
        flex: 0 0 335px;
        min-width: 335px;
        position: relative;
        z-index: 5;
    }

    .sim-hero-copy h1 {
        margin: 0 0 7px;
        font-size: 31px;
    }

    .sim-hero-copy p {
        margin: 0;
        max-width: 340px;
    }

    /* Robot + exactly six signs. */
    .sim-hero-art {
        flex: 1 1 auto;
        min-width: 0;
        height: 145px;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        overflow: hidden;
        position: relative;
        z-index: 2;
    }

    .sim-hero-art svg {
        width: 1035px;
        min-width: 1035px;
        height: 145px;
        display: block;
        overflow: visible;
        filter: drop-shadow(0 5px 8px rgba(0,0,0,.22));
    }

    /* Robot gently bobs while it releases the signs. */
    .sim-robot {
        transform-box: fill-box;
        transform-origin: center bottom;
        animation: robotBob 3s ease-in-out infinite;
    }

    @keyframes robotBob {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-4px); }
    }

    /* Every sign starts near the robot, then pops into its final position. */
    .sim-hero-sign {
        opacity: 0;
        transform-box: fill-box;
        transform-origin: center;
        animation: signRelease 9s ease-in-out infinite;
        animation-delay: var(--sign-delay);
    }

    .sim-hero-sign .sign-picture {
        transform-origin: center;
    }

    .sim-hero-sign .sign-label-bg,
    .sim-hero-sign .sign-label {
        opacity: 0;
        animation: signName 9s ease-in-out infinite;
        animation-delay: var(--sign-delay);
    }

    @keyframes signRelease {
        0% {
            opacity: 0;
            transform: translate(-105px, 20px) scale(.25);
        }
        5% {
            opacity: 1;
            transform: translate(-45px, -3px) scale(.82);
        }
        10% {
            opacity: 1;
            transform: translate(0, 0) scale(1);
        }
        65% {
            opacity: 1;
            transform: translate(0, 0) scale(1);
        }
        76%, 100% {
            opacity: 0;
            transform: translate(0, -3px) scale(.98);
        }
    }

    @keyframes signName {
        0%, 7% { opacity: 0; }
        12%, 65% { opacity: 1; }
        76%, 100% { opacity: 0; }
    }

    @media (prefers-reduced-motion: reduce) {
        .sim-robot,
        .sim-hero-sign,
        .sim-hero-sign .sign-label-bg,
        .sim-hero-sign .sign-label {
            animation: none;
            opacity: 1;
        }
    }

    /* =========================================================
       ADVANCED NAMIBIAN DRIVER'S LICENCE CARD
       ========================================================= */
    .driver-license-card {
        --sim-rail-width: 226px;
        position: fixed;
        left: 10px;
        top: 535px;
        width: calc(var(--sim-rail-width) - 20px);
        height: 126px;
        box-sizing: border-box;
        padding: 9px;
        border-radius: 12px;
        background: linear-gradient(145deg, #f9fdff 0%, #d9edfa 100%);
        border: 2px solid #1597e5;
        box-shadow: 0 8px 22px rgba(0,0,0,.34), 0 0 14px rgba(21,151,229,.28);
        z-index: 100;
        transform-origin: center;
        backface-visibility: hidden;
        animation: driverLicenceFlipFlop 4s ease-in-out infinite;
        overflow: hidden;
        cursor: pointer;
        text-decoration: none;
    }

    .driver-license-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(105deg, transparent 35%, rgba(255,255,255,.82) 48%, transparent 61%);
        transform: translateX(-130%);
        animation: licenceShine 4s ease-in-out infinite;
        pointer-events: none;
        z-index: 5;
    }

    .driver-license-card::after {
        content: "";
        position: absolute;
        width: 90px;
        height: 90px;
        right: -42px;
        top: -42px;
        border-radius: 50%;
        background: rgba(52,152,219,.12);
        border: 1px solid rgba(52,152,219,.22);
        pointer-events: none;
    }

    @keyframes driverLicenceFlipFlop {
        0%, 100% { transform: perspective(800px) rotateY(0deg) rotateX(0deg) translateY(0); }
        20% { transform: perspective(800px) rotateY(11deg) rotateX(2deg) translateY(-2px); }
        40% { transform: perspective(800px) rotateY(0deg) rotateX(0deg) translateY(0); }
        60% { transform: perspective(800px) rotateY(-11deg) rotateX(-2deg) translateY(-2px); }
        80% { transform: perspective(800px) rotateY(0deg) rotateX(0deg) translateY(0); }
    }

    @keyframes licenceShine {
        0%, 55% { transform: translateX(-130%); }
        75%, 100% { transform: translateX(130%); }
    }

    .driver-license-inner {
        position: relative;
        z-index: 4;
        width: 100%;
        height: 100%;
    }

    /* Namibia flag */
    .namibia-flag {
        position: absolute;
        top: 2px;
        left: 2px;
        width: 35px;
        height: 20px;
        border-radius: 2px;
        overflow: hidden;
        border: 1px solid rgba(0,0,0,.2);
        background: linear-gradient(to bottom, #003580 0 33%, #d21034 33% 66%, #009a44 66% 100%);
    }

    .namibia-flag::before {
        content: "";
        position: absolute;
        width: 48px;
        height: 5px;
        left: -7px;
        top: 7px;
        background: #fff;
        transform: rotate(-29deg);
    }

    .namibia-flag::after {
        content: "★";
        position: absolute;
        left: 4px;
        top: 1px;
        color: #ffd700;
        font-size: 9px;
        line-height: 1;
        text-shadow: 0 0 1px #111;
    }

    .license-heading {
        position: absolute;
        left: 42px;
        top: 2px;
        right: 4px;
    }

    .driver-license-country {
        font-size: 6px;
        font-weight: 900;
        letter-spacing: .55px;
        color: #174a73;
        text-transform: uppercase;
        line-height: 1;
    }

    .driver-license-title {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: .2px;
        color: #123b5b;
        line-height: 1.1;
        margin-top: 2px;
    }

    .license-security-mark {
        position: absolute;
        right: 3px;
        top: 2px;
        color: #58a8d7;
        font-size: 10px;
        opacity: .7;
    }

    .driver-license-photo {
        position: absolute;
        left: 2px;
        top: 28px;
        width: 39px;
        height: 52px;
        border-radius: 4px;
        background: linear-gradient(#c9d7e2 0 35%, #879baa 35% 100%);
        border: 1px solid #8aa0b3;
        overflow: hidden;
    }

    .driver-license-photo::before {
        content: "";
        position: absolute;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #9b633e;
        top: 7px;
        left: 11px;
    }

    .driver-license-photo::after {
        content: "";
        position: absolute;
        width: 27px;
        height: 22px;
        border-radius: 15px 15px 4px 4px;
        background: #263849;
        bottom: 4px;
        left: 5px;
    }

    .license-details {
        position: absolute;
        left: 47px;
        top: 29px;
        right: 3px;
    }

    .license-detail-row {
        display: flex;
        gap: 4px;
        font-size: 7px;
        line-height: 1.35;
        color: #34566d;
    }

    .license-detail-row strong {
        color: #123b5b;
    }

    .driver-license-code {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 5px;
        border-radius: 3px;
        background: #1597e5;
        color: #fff;
        font-size: 7px;
        font-weight: 900;
    }

    .license-car {
        position: absolute;
        right: 5px;
        bottom: 30px;
        font-size: 21px;
        filter: grayscale(1);
        opacity: .85;
    }

    /* Barcode */
    .license-barcode {
        position: absolute;
        left: 47px;
        bottom: 7px;
        width: 86px;
        height: 17px;
        display: flex;
        align-items: stretch;
        gap: 1px;
        overflow: hidden;
    }

    .license-barcode span { display: block; background: #142b3c; }
    .license-barcode span:nth-child(1) { width: 2px; }
    .license-barcode span:nth-child(2) { width: 1px; }
    .license-barcode span:nth-child(3) { width: 3px; }
    .license-barcode span:nth-child(4) { width: 1px; }
    .license-barcode span:nth-child(5) { width: 2px; }
    .license-barcode span:nth-child(6) { width: 4px; }
    .license-barcode span:nth-child(7) { width: 1px; }
    .license-barcode span:nth-child(8) { width: 2px; }
    .license-barcode span:nth-child(9) { width: 1px; }
    .license-barcode span:nth-child(10) { width: 4px; }
    .license-barcode span:nth-child(11) { width: 2px; }
    .license-barcode span:nth-child(12) { width: 1px; }
    .license-barcode span:nth-child(13) { width: 3px; }
    .license-barcode span:nth-child(14) { width: 1px; }
    .license-barcode span:nth-child(15) { width: 4px; }
    .license-barcode span:nth-child(16) { width: 2px; }
    .license-barcode span:nth-child(17) { width: 1px; }
    .license-barcode span:nth-child(18) { width: 3px; }
    .license-barcode span:nth-child(19) { width: 2px; }
    .license-barcode span:nth-child(20) { width: 1px; }
    .license-barcode span:nth-child(21) { width: 4px; }
    .license-barcode span:nth-child(22) { width: 1px; }
    .license-barcode span:nth-child(23) { width: 3px; }
    .license-barcode span:nth-child(24) { width: 2px; }

    .license-id-number {
        position: absolute;
        right: 5px;
        bottom: 8px;
        font-size: 6px;
        font-weight: 700;
        color: #557286;
    }

    .driver-license-label {
        position: absolute;
        right: 5px;
        bottom: 25px;
        font-size: 6px;
        font-weight: 900;
        color: #49687d;
    }

    @media (prefers-reduced-motion: reduce) {
        .sim-hero-art svg,
        .driver-license-card,
        .driver-license-card::before {
            animation: none;
        }
    }

    @media (max-width: 1100px) {
        .driver-license-card { display: none; }
    }

    @media (max-width: 900px) {
        .sim-hero {
            flex-direction: column;
            align-items: flex-start;
            gap: 18px;
        }

        .sim-hero-copy {
            flex: none;
            min-width: 0;
            width: 100%;
        }

        .sim-hero-art {
            width: 100%;
            flex-basis: 145px;
        }

        .sim-hero-art svg {
            transform: scale(.82);
            transform-origin: left center;
        }
    }

    /* TestMate Dark Warm Gradient — page background only */
    body.tm-modern,
    body.has-sidebar.tm-modern {
        background:
            radial-gradient(circle at top right,rgba(244,122,31,.22),transparent 35%),
            linear-gradient(135deg,#5A2C18 0%,#3A2117 48%,#2B1A12 100%) !important;
        background-attachment: fixed !important;
    }
    </style>

    <header class="sim-head">
        <div>
            <span class="sim-kicker">Simulations</span>
            <h1>Driving Simulations</h1>
            <p>Learn through interactive, real-life Namibian driving scenarios. Watch what the driver does, then test your judgement.</p>
        </div>
        <span class="sim-head-count"><i></i><?= count($sims) ?> <?= count($sims) === 1 ? 'simulation' : 'simulations' ?></span>
    </header>

    <div class="container">
        <?php if (empty($sims)): ?>
        <div class="sim-submit-box" style="padding:60px 24px;color:#647b95;">
            <h2 style="margin-bottom:12px;color:#102744;">No simulations available yet.</h2>
            <p>Check back soon!</p>
        </div>
        <?php else: ?>
        <div class="tm-sim-workspace">
            <aside class="tm-sim-library" aria-label="Choose a simulation">
                <p class="tm-sim-library-title">Choose a scenario</p>
                <?php foreach ($sims as $index => $sim):
                    $iconKey = isset($SIM_ICONS[$sim['animation_type']]) ? $sim['animation_type'] : 'four_way_stop';
                ?>
                <button type="button" class="tm-sim-choice <?= $index === 0 ? 'is-active' : '' ?>"
                        aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                        data-id="<?= (int)$sim['id'] ?>"
                        data-title="<?= htmlspecialchars($sim['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="tm-sim-thumbnail"><img src="<?= htmlspecialchars($SIM_ICONS[$iconKey], ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
                    <span class="tm-sim-choice-copy">
                        <strong><?= htmlspecialchars($sim['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars($sim['topic_name'] ?? 'Driving practice', ENT_QUOTES, 'UTF-8') ?></small>
                        <span><?= htmlspecialchars($sim['description'], ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <span class="tm-sim-chevron" aria-hidden="true">›</span>
                </button>
                <?php endforeach; ?>
            </aside>
            <section class="tm-sim-player" aria-label="Selected simulation">
                <div class="tm-sim-player-head">
                    <div>
                        <span>Now playing · Study mode</span>
                        <strong id="tmSimSelectedTitle"><?= htmlspecialchars($sims[0]['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <span class="tm-sim-live">Interactive</span>
                </div>
                <iframe id="tmSimFrame" title="<?= htmlspecialchars($sims[0]['title'], ENT_QUOTES, 'UTF-8') ?>"
                        src="/simulations.php?id=<?= (int)$sims[0]['id'] ?>&mode=study&embedded=1"></iframe>
                <div class="tm-sim-player-footer">
                    <p>Watch each scenario, then practise your decision in the quiz.</p>
                    <div class="tm-sim-player-actions">
                        <a id="tmSimStudyLink" class="btn btn-outline" href="/simulations.php?id=<?= (int)$sims[0]['id'] ?>&mode=study">Open Full Study Mode</a>
                        <a id="tmSimQuizLink" class="btn btn-primary" href="/simulations.php?id=<?= (int)$sims[0]['id'] ?>&mode=quiz">Try Quiz</a>
                    </div>
                </div>
            </section>
        </div>

        <?php endif; ?>
    </div>
    <?php
    echo <<<'TM_REFERENCE_LAYOUT'
<style>
/* =====================================================
   TESTMATE SIMULATIONS - 2026 DESIGN
   Replaces the old "reference layout" patch blocks.
   It no longer resizes the shared header/sidebar, so this
   page now uses the same 74px header and 252px sidebar as
   every other learner page (css/style.css).
   Animation CSS, scene markup and JavaScript are unchanged.
   ===================================================== */
html body.tm-page-reference:not(.tm-sim-embedded) { background: #f4f8fd !important; }
body.tm-page-reference { font-family: "Segoe UI", Arial, sans-serif; color: #102744; }

/* Page width shared by all three simulation screens */
html body.tm-page-reference .sim-head,
html body.tm-page-reference .container {
    box-sizing: border-box;
    width: 100% !important;
    max-width: 1370px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}
html body.tm-page-reference .container { padding: 0 30px 50px !important; }

/* Page banner (matches the dashboard and other pages) */
html body.tm-page-reference .sim-head {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    width: calc(100% - 60px) !important;
    max-width: 1310px !important;
    margin-top: 28px !important;
    margin-bottom: 20px !important;
    padding: 26px 30px;
    border: 1px solid #dce9f8;
    border-radius: 18px;
    background: linear-gradient(105deg, #e9f4ff 0%, #f6faff 54%, #c7e4ff 100%);
}
.sim-head::after {
    content: "";
    position: absolute;
    width: 240px; height: 240px;
    right: -80px; top: -130px;
    border-radius: 50%;
    background: rgba(255,255,255,.4);
    pointer-events: none;
}
.sim-head > * { position: relative; z-index: 1; }
.sim-kicker {
    display: block;
    margin-bottom: 8px;
    color: #2c82c2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
}
.sim-head h1 { margin: 0 0 7px; color: #102744; font-size: clamp(24px, 2.4vw, 32px); line-height: 1.2; font-weight: 800; letter-spacing: -.5px; }
.sim-head p { margin: 0; max-width: 680px; color: #526f8b; font-size: 14px; line-height: 1.6; }
.sim-head-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border: 1px solid #e0ebf7;
    border-radius: 999px;
    background: rgba(255,255,255,.9);
    color: #173652;
    font-size: 13px;
    font-weight: 800;
}
.sim-head-count i { width: 9px; height: 9px; border-radius: 50%; background: #10a77a; }

/* Single-simulation screens use a narrower column; align the banner to it */
html body.tm-page-reference .sim-head.is-narrow { max-width: 940px !important; }

/* Study / Quiz mode switch */
.sim-mode-switch {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    border: 1px solid #dce7f3;
    border-radius: 12px;
    background: #fff;
}
.sim-mode-switch a {
    padding: 9px 16px;
    border-radius: 9px;
    color: #4d6782;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
}
.sim-mode-switch a:hover { background: #f1f7ff; color: #147df5; }
.sim-mode-switch a.is-active { background: #147df5; color: #fff; }
.sim-mode-switch a.is-active.is-quiz { background: #8950dc; }

/* Buttons used on these screens */
html body.tm-page-reference .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 18px;
    border: 1px solid transparent;
    border-radius: 10px !important;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}
html body.tm-page-reference .btn-lg { min-height: 48px; padding: 12px 26px; font-size: 15px; }
html body.tm-page-reference .btn-primary { background: #147df5 !important; border-color: #147df5 !important; color: #fff !important; }
html body.tm-page-reference .btn-primary:hover { background: #0b68d6 !important; }
html body.tm-page-reference .btn-outline { background: #fff !important; border: 1px solid #d6e4f3 !important; color: #1569b8 !important; }
html body.tm-page-reference .btn-outline:hover { background: #eef6ff !important; }

/* ---------- Library: list + player ---------- */
.tm-sim-workspace { display: grid; grid-template-columns: 330px minmax(0, 1fr); gap: 20px; align-items: start; }
.tm-sim-library {
    display: grid;
    gap: 10px;
    max-height: 780px;
    overflow: auto;
    padding: 2px 4px 4px 2px;
}
.tm-sim-library-title { margin: 0 0 2px; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-choice {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) 18px;
    gap: 12px;
    align-items: center;
    width: 100%;
    padding: 12px;
    border: 1px solid #e2eaf3;
    border-radius: 13px;
    background: #fff;
    color: #24425d;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    box-shadow: 0 3px 12px rgba(20,48,76,.04);
    transition: border-color .15s, background .15s, box-shadow .15s;
}
.tm-sim-choice:hover { border-color: #98c5ff; background: #f7fbff; }
.tm-sim-choice.is-active { border-color: #147df5; background: #eef6ff; box-shadow: inset 4px 0 #147df5, 0 6px 16px rgba(20,125,245,.12); }
.tm-sim-thumbnail {
    display: grid;
    place-items: center;
    width: 52px; height: 52px;
    border-radius: 13px;
    background: #f4f8fd;
}
.tm-sim-choice.is-active .tm-sim-thumbnail { background: #fff; }
.tm-sim-thumbnail img { width: 38px; height: 38px; object-fit: contain; }
.tm-sim-choice-copy { min-width: 0; }
.tm-sim-choice strong { display: block; margin-bottom: 3px; color: #102744; font-size: 14px; line-height: 1.35; }
.tm-sim-choice small { display: block; margin-bottom: 3px; color: #10a77a; font-size: 11px; font-weight: 800; }
.tm-sim-choice-copy > span {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    color: #6a7e93;
    font-size: 12px;
    line-height: 1.45;
}
.tm-sim-chevron { color: #9fb2c6; font-size: 20px; line-height: 1; }
.tm-sim-choice.is-active .tm-sim-chevron { color: #147df5; }

.tm-sim-player {
    min-width: 0;
    overflow: hidden;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.tm-sim-player-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #edf1f6;
}
.tm-sim-player-head span { display: block; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-player-head strong { display: block; margin-top: 2px; color: #102744; font-size: 17px; }
.tm-sim-live { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px; background: #e2f8ec; color: #0b8a5f; font-size: 11px; font-weight: 800; }
.tm-sim-live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
html body .tm-sim-player iframe { display: block; width: 100%; height: 560px; border: 0 !important; border-radius: 0 !important; background: #f4f8fd; transition: height .2s ease; }
html body .tm-sim-player-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    padding: 16px 20px;
    border-top: 1px solid #edf1f6;
    background: #f8fbff;
}
html body .tm-sim-player-footer p { margin: 0; color: #647b95; font-size: 13px; }
.tm-sim-player-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.tm-sim-player-actions .btn { min-height: 42px; padding: 10px 18px !important; font-size: 13px !important; white-space: nowrap; }
.tm-sim-player-actions .btn-primary { background: #8950dc !important; border-color: #8950dc !important; }
.tm-sim-player-actions .btn-primary:hover { background: #7440cf !important; }
.tm-sim-choice:focus-visible, .tm-sim-pager button:focus-visible { outline: 3px solid #76b8ff; outline-offset: 2px; }

/* ---------- Study / quiz scenarios ---------- */
.sim-note {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 20px;
    padding: 14px 18px;
    border: 1px solid #cfe3fb;
    border-left: 4px solid #147df5;
    border-radius: 12px;
    background: #eef6ff;
    color: #35536d;
    font-size: 14px;
    line-height: 1.6;
}
.sim-note strong { color: #0f5fb8; white-space: nowrap; }
.sim-note.is-quiz { border-color: #e2d4f8; border-left-color: #8950dc; background: #f6f1ff; }
.sim-note.is-quiz strong { color: #6b38c2; }

.tm-sim-lesson-card,
.quiz-q-card {
    margin-bottom: 20px !important;
    padding: 18px;
    border: 1px solid #e2eaf3 !important;
    border-radius: 16px !important;
    background: #fff !important;
    box-shadow: 0 5px 18px rgba(20,48,76,.06) !important;
}
.tm-sim-lesson-card > h3 { margin: 0 0 12px !important; color: #102744 !important; font-size: 16px !important; font-weight: 800 !important; }
.tm-sim-lesson-card > h3 span { color: #647b95 !important; }

html body.tm-page-reference .sim-container {
    overflow: hidden;
    margin-bottom: 12px !important;
    border: 1px solid #dce8f5 !important;
    border-radius: 13px !important;
    background: #102744 !important;
}
html body.tm-page-reference .sim-screen { border-radius: 0 !important; }
html body.tm-page-reference .sim-info {
    background: #102744 !important;
    color: #e3eef9 !important;
    padding: 13px 18px !important;
    gap: 16px !important;
}
html body.tm-page-reference .sim-info button {
    background: #147df5 !important;
    color: #fff !important;
    border-radius: 9px !important;
    padding: 9px 20px !important;
    font-weight: 800 !important;
}
html body.tm-page-reference .sim-info button:hover { background: #0b68d6 !important; }
[id^="explanation-study-"] { border-radius: 0 12px 12px 0 !important; }

/* Quiz answer choices (selectAnswer() sets inline colours, so these win with !important) */
.quiz-q-card > p { color: #102744 !important; font-size: 15px !important; font-weight: 700 !important; }
.quiz-q-card .answer-btn {
    display: flex !important;
    align-items: center;
    gap: 12px;
    width: 100%;
    margin: 0 0 9px !important;
    padding: 13px 16px !important;
    border: 1px solid #dce7f2 !important;
    border-radius: 11px !important;
    background: #fff !important;
    color: #264766 !important;
    font-family: inherit;
    font-size: 14px !important;
    text-align: left;
    cursor: pointer;
}
.quiz-q-card .answer-btn:hover { border-color: #98c5ff !important; background: #f5faff !important; }
.quiz-q-card .answer-btn.selected { border-color: #147df5 !important; background: #eef6ff !important; color: #0f4d94 !important; font-weight: 700; box-shadow: inset 4px 0 #147df5; }
.quiz-q-card .answer-btn .btn-icon { font-size: 18px !important; }
.sim-submit-box {
    margin: 6px 0 24px;
    padding: 22px;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    text-align: center;
}
.sim-submit-box p { margin: 10px 0 0; color: #7b8fa4; font-size: 13px; }
#quizValidationMsg { padding: 10px 14px; border-radius: 9px; background: #fdecea; color: #b43333 !important; font-weight: 700; }

.sim-cta { margin: 24px 0 30px; padding: 22px; border-radius: 16px; background: #102744; color: #fff; text-align: center; }
.sim-cta p { margin: 0 0 12px; color: #c4d7ec; font-size: 14px; }
.sim-cta .btn-primary { background: #f5c518 !important; border-color: #f5c518 !important; color: #102744 !important; }
.sim-cta .btn-primary:hover { background: #ffd43b !important; }
.sim-bottom-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin: 0 0 40px; }

/* Pager (built by the script below) */
.tm-sim-lesson[hidden] { display: none !important; }
.tm-sim-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 18px 0 26px; }
.tm-sim-pager button {
    min-width: 110px;
    padding: 11px 20px;
    border: 1px solid #d6e4f3;
    border-radius: 10px;
    background: #fff;
    color: #147df5;
    font-family: inherit;
    font-weight: 800;
    cursor: pointer;
}
.tm-sim-pager button:last-child { background: #147df5; border-color: #147df5; color: #fff; }
.tm-sim-pager button:disabled { opacity: .4; cursor: default; }
.tm-sim-pager span { color: #647b95; font-size: 12px; font-weight: 700; }

/* ---------- Quiz results ---------- */
.sim-result {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 18px;
    padding: 26px 28px;
    border-radius: 18px;
    color: #fff;
}
.sim-result.is-pass { background: linear-gradient(120deg, #0b8a5f, #10a77a); }
.sim-result.is-fail { background: linear-gradient(120deg, #b6303e, #e5394a); }
.sim-result-ring {
    display: grid;
    place-items: center;
    flex: 0 0 104px;
    width: 104px; height: 104px;
    border-radius: 50%;
    background: conic-gradient(#fff var(--pct), rgba(255,255,255,.25) 0);
}
.sim-result-ring span { display: grid; place-items: center; width: 84px; height: 84px; border-radius: 50%; background: rgba(0,0,0,.18); font-size: 24px; font-weight: 800; }
.sim-result strong { display: block; font-size: 30px; font-weight: 800; }
.sim-result p { margin: 4px 0 0; color: rgba(255,255,255,.9); font-size: 14px; }
.sim-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
.sim-section-title { margin: 0 0 14px; color: #102744; font-size: 19px; font-weight: 800; }
.sim-review {
    margin-bottom: 16px;
    padding: 20px;
    border: 1px solid #e2eaf3;
    border-left: 5px solid #10a77a;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.sim-review.is-wrong { border-left-color: #e5394a; }
.sim-review-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
.sim-review-head span { color: #647b95; font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.sim-tag { padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 800; }
.sim-tag.is-correct { background: #dff7ed; color: #0b8a5f; }
.sim-tag.is-wrong { background: #fdeaea; color: #b93434; }
.sim-review-replay { margin-bottom: 14px; padding: 14px; border-radius: 11px; background: #f4f8fd; text-align: center; }
.sim-review-question { margin: 0 0 12px; color: #102744; font-size: 15px; font-weight: 700; }
.sim-review-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.sim-review-option { padding: 12px 14px; border: 1px solid #e1eaf3; border-radius: 10px; background: #f7faff; color: #35536d; font-size: 14px; font-weight: 600; text-align: center; }
.sim-review-option.is-correct { border-color: #a6e5c6; background: #e5f8ef; color: #0b7a54; }
.sim-review-option.is-wrong { border-color: #edbfc2; background: #ffeded; color: #b43333; }
.sim-review-explain { padding: 12px 15px; border-left: 4px solid #147df5; border-radius: 0 10px 10px 0; background: #eef6ff; color: #35536d; font-size: 13px; line-height: 1.65; }

/* ---------- Embedded study player (inside the library iframe) ---------- */
html body.tm-page-reference.tm-sim-embedded,
html body.tm-page-reference.has-sidebar.tm-sim-embedded { padding: 0 !important; margin: 0 !important; min-height: 0 !important; background: #f4f8fd !important; }
body.tm-sim-embedded .navbar,
body.tm-sim-embedded .tm-sidebar,
body.tm-sim-embedded .tm-mobile-overlay,
body.tm-sim-embedded .page-header,
body.tm-sim-embedded .sim-head,
body.tm-sim-embedded footer,
body.tm-sim-embedded .footer,
body.tm-sim-embedded .tm-footer { display: none !important; }
html body.tm-page-reference.tm-sim-embedded .container { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 16px !important; }
body.tm-sim-embedded .sim-screen { height: 340px !important; min-height: 0 !important; box-sizing: border-box; }
body.tm-sim-embedded .tm-sim-lesson-card { margin-bottom: 12px !important; box-shadow: none !important; }
body.tm-sim-embedded .tm-sim-lesson > h3 { font-size: 15px !important; }
body.tm-sim-embedded .tm-sim-intro,
body.tm-sim-embedded .tm-sim-end-links { display: none !important; }
body.tm-sim-embedded .tm-sim-pager { margin: 12px 0 4px; }

/* ---------- Responsive ---------- */
@media (max-width: 1150px) {
    .tm-sim-workspace { grid-template-columns: 270px minmax(0, 1fr); gap: 16px; }
    .tm-sim-choice { grid-template-columns: 44px minmax(0, 1fr); }
    .tm-sim-chevron { display: none; }
    .tm-sim-thumbnail { width: 44px; height: 44px; }
    .tm-sim-thumbnail img { width: 32px; height: 32px; }
}
@media (max-width: 760px) {
    html body.tm-page-reference .container { padding: 0 16px 40px !important; }
    html body.tm-page-reference .sim-head { width: calc(100% - 32px) !important; margin-top: 18px !important; padding: 22px 20px; }
    .tm-sim-workspace { display: flex; flex-direction: column; }
    .tm-sim-player { width: 100%; order: 0; }
    .tm-sim-library { width: 100%; order: 1; max-height: 440px; }
    .tm-sim-player iframe { height: 660px; }
    .tm-sim-player-footer { flex-direction: column; align-items: stretch; }
    .tm-sim-player-actions .btn { flex: 1; }
    .sim-result { flex-direction: column; text-align: center; }
    .sim-review-options { grid-template-columns: 1fr; }
    .sim-note { flex-direction: column; gap: 4px; }
    body.tm-sim-embedded .sim-screen { height: 270px !important; }
}
</style><script>document.body.classList.add("tm-page-reference");</script>
<script>
/* Fit the embedded simulation player to its content (same-origin iframe). */
(function () {
    var frame = document.getElementById('tmSimFrame');
    if (!frame) return;
    function fit() {
        try {
            var doc = frame.contentDocument;
            var box = doc && doc.querySelector('.container');
            if (box) frame.style.height = Math.max(380, Math.ceil(box.getBoundingClientRect().height) + 4) + 'px';
        } catch (e) {}
    }
    frame.addEventListener('load', function () {
        fit();
        setTimeout(fit, 300);
        try {
            var win = frame.contentWindow;
            if (win.ResizeObserver) new win.ResizeObserver(fit).observe(frame.contentDocument.body);
        } catch (e) {}
    });
})();
</script>
<script>
(function(){
    const choices=Array.from(document.querySelectorAll('.tm-sim-choice'));
    const frame=document.getElementById('tmSimFrame');
    if(frame){
        choices.forEach(button=>button.addEventListener('click',()=>{
            const id=Number(button.dataset.id);if(!Number.isInteger(id)||id<1)return;
            if(button.classList.contains('is-active'))return;
            choices.forEach(choice=>{const active=choice===button;choice.classList.toggle('is-active',active);choice.setAttribute('aria-pressed',String(active));});
            frame.src='/simulations.php?id='+id+'&mode=study&embedded=1';frame.title=button.dataset.title;
            document.getElementById('tmSimSelectedTitle').textContent=button.dataset.title;
            document.getElementById('tmSimQuizLink').href='/simulations.php?id='+id+'&mode=quiz';
            document.getElementById('tmSimStudyLink').href='/simulations.php?id='+id+'&mode=study';
        }));
    }
    const scenes=Array.from(document.querySelectorAll('.sim-container[id^="sim-study-"]'));
    if(!scenes.length)return;
    const lessons=scenes.map(scene=>scene.parentElement);
    const container=lessons[0].parentElement;
    lessons.forEach(lesson=>lesson.classList.add('tm-sim-lesson'));
    const pager=document.createElement('div');pager.className='tm-sim-pager';pager.innerHTML='<button type="button">Previous</button><span aria-live="polite"></span><button type="button">Next</button>';
    lessons[lessons.length-1].insertAdjacentElement('afterend',pager);
    let current=0;
    function show(index){current=Math.max(0,Math.min(index,lessons.length-1));lessons.forEach((lesson,i)=>lesson.hidden=i!==current);pager.children[0].disabled=current===0;pager.children[2].disabled=current===lessons.length-1;pager.children[1].textContent='Scenario '+(current+1)+' of '+lessons.length;}
    pager.children[0].addEventListener('click',()=>show(current-1));pager.children[2].addEventListener('click',()=>show(current+1));show(0);
    if(new URLSearchParams(window.location.search).get('embedded')==='1'){
        document.body.classList.add('tm-sim-embedded');
        (function isolateEmbeddedPlayer(){
            let node=container;
            while(node&&node.parentElement){
                const parent=node.parentElement;
                Array.from(parent.children).forEach(sibling=>{
                    if(sibling!==node&&!['SCRIPT','STYLE','LINK','META','TEMPLATE'].includes(sibling.tagName)){
                        sibling.style.setProperty('display','none','important');
                    }
                });
                parent.style.setProperty('padding','0','important');
                parent.style.setProperty('margin','0','important');
                parent.style.setProperty('transform','none','important');
                parent.style.setProperty('width','100%','important');
                parent.style.setProperty('max-width','none','important');
                parent.style.setProperty('position','static','important');
                if(parent===document.body)break;
                node=parent;
            }
        })();
        const intro=Array.from(container.children).find(child=>child!==lessons[0]&&child!==pager&&child.querySelector('strong'));
        if(intro)intro.classList.add('tm-sim-intro');
        Array.from(container.children).filter(child=>child.querySelector('a[href*="mode=quiz"]')||child.querySelector('a[href="/simulations.php"]')).forEach(child=>child.classList.add('tm-sim-end-links'));
        document.querySelectorAll('video').forEach(video=>video.preload='metadata');
    }
})();
</script>

TM_REFERENCE_LAYOUT;
include 'includes/footer.php';
    exit();
}

// Get specific simulation
$s = $conn->prepare("SELECT s.*, t.name AS topic_name FROM simulations s LEFT JOIN topics t ON s.topic_id = t.id WHERE s.id = ?");
$s->execute([$sim_id]);
$simulation = $s->fetch(PDO::FETCH_ASSOC);

if (!$simulation) {
    header("Location: /simulations.php");
    exit();
}

$scenarios = json_decode($simulation['scenario_data'], true);

// Add two extra emergency-vehicle study examples without changing the database.
// They use the same NATIS-aligned response: clear left, stop, let the emergency vehicle pass.
if ($simulation['animation_type'] === 'emergency_vehicle' && is_array($scenarios)) {
    $extraEmergencyExamples = [
        [
            'vehicle' => 'police',
            'sirens' => 'on',
            'action' => 'pull_over_left_stop',
            'is_correct' => true,
            'explanation' => 'Correct — a police vehicle is approaching with emergency warnings active. Move clearly to the left edge, slow down and stop, keep the path clear, and proceed only after it has passed and it is safe.'
        ],
        [
            'vehicle' => 'ambulance',
            'sirens' => 'on',
            'action' => 'pull_over_left_stop',
            'is_correct' => true,
            'explanation' => 'Correct — an ambulance is approaching from behind with emergency warnings active. Move clearly to the left edge and stop so it can pass on the right. Continue only when the emergency vehicle has passed and it is safe.'
        ]
    ];
    $scenarios = array_merge($scenarios, $extraEmergencyExamples);
}

// NATIS alignment layer.  This changes only the lesson shown on this page;
// it does not write to the database.  The uploaded NATIS manual is the source
// for the road behaviour demonstrated below.
if (is_array($scenarios)) {
    foreach ($scenarios as &$natisScenario) {
        $type = $simulation['animation_type'];

        if ($type === 'emergency_vehicle') {
            if (($natisScenario['sirens'] ?? '') === 'on') {
                // Namibia drives on the left.  Show the learner and emergency
                // vehicle in the SAME travelling lane first; learner then moves
                // safely to the left edge and STOPS so the emergency vehicle can pass.
                $natisScenario['action'] = 'pull_over_left_stop';
                $natisScenario['is_correct'] = true;
                $natisScenario['explanation'] = 'Correct — stop on the approach of an emergency vehicle. Move safely to the left edge, slow down and stop so the emergency vehicle has a clear path. Proceed again only when it has passed and it is safe.';
            } else {
                $natisScenario['action'] = 'proceed_normally';
                $natisScenario['explanation'] = 'No emergency warning is being demonstrated here. Continue with normal safe driving and remain alert.';
            }
        }

        if ($type === 'four_way_stop') {
            // NATIS: every vehicle stops; the vehicle that stopped first and
            // would cross your path must clear the junction first.
            $order = strtolower((string)($natisScenario['arriving_order'] ?? ''));
            if (str_contains($order, 'first') || $order === '1' || str_contains($order, '1st')) {
                $natisScenario['explanation'] = (($natisScenario['action'] ?? '') === 'go')
                    ? 'Correct — stop at the stop line first, check that the junction is safe, then proceed because this vehicle stopped before the conflicting vehicle.'
                    : 'Incorrect — after stopping first and checking that it is safe, this vehicle may proceed before the conflicting vehicle.';
            } else {
                $natisScenario['explanation'] = (($natisScenario['action'] ?? '') === 'go')
                    ? 'Incorrect — another conflicting vehicle stopped first. Stop and allow that vehicle to clear the junction before proceeding.'
                    : 'Correct — stop and wait for the conflicting vehicle that stopped first to clear the junction.';
            }
        }

        if ($type === 'traffic_light') {
            $light = strtolower((string)($natisScenario['light'] ?? ''));
            if ($light === 'red') {
                $natisScenario['explanation'] = !empty($natisScenario['car_passes'])
                    ? 'Incorrect — a steady red signal requires the vehicle to stop behind the stop line and remain stationary until a green signal is displayed and it is safe to proceed.'
                    : 'Correct — stop behind the stop line and remain stationary until the green signal is displayed and it is safe to proceed.';
            } elseif ($light === 'amber' || $light === 'yellow') {
                $natisScenario['explanation'] = !empty($natisScenario['car_passes'])
                    ? 'An amber/yellow signal requires you to stop behind the stop line. You may proceed only if you are already too close to stop safely, and then only with caution.'
                    : 'Correct — stop behind the stop line at an amber/yellow signal when you can do so safely.';
            } elseif ($light === 'green') {
                $natisScenario['explanation'] = !empty($natisScenario['car_passes'])
                    ? 'Correct — a green signal allows you to proceed when it is safe, while yielding to vehicles or pedestrians lawfully in the junction.'
                    : 'A green signal permits movement when it is safe. Check the junction and yield where required before proceeding.';
            }
        }

        if ($type === 'overtake') {
            $natisScenario['explanation'] = !empty($natisScenario['oncoming'])
                ? 'Incorrect — do not overtake when it is unsafe or when your view/available road is not sufficient. Overtaking is normally performed on the right only after signalling and checking that it can be completed safely.'
                : 'Correct — signal before overtaking, pass on the right at a safe distance, and return to the left only when safely clear of the vehicle being passed.';
        }

        if ($type === 'school_zone') {
            // The manual passage used for this audit requires obedience to scholar
            // patrol/traffic signs.  Do not present an invented universal school-zone
            // speed: treat the value shown in the scene as the posted limit.
            $posted = (int)($natisScenario['speed'] ?? 0);
            $actual = (int)($natisScenario['your_speed'] ?? 0);
            if ($posted > 0) {
                $natisScenario['is_correct'] = ($actual <= $posted);
                $natisScenario['explanation'] = ($actual <= $posted)
                    ? 'Correct — obey the displayed speed restriction, watch carefully for children and obey scholar-patrol or other traffic signs.'
                    : 'Incorrect — the vehicle is exceeding the displayed speed restriction. Slow down, watch for children and obey scholar-patrol or other traffic signs.';
            }
        }
    }
    unset($natisScenario);
}

// Get quiz questions for this simulation
$quiz_questions = sim_get_questions($conn, $sim_id);

// Map scenario_index -> media (for Study Mode, which loops over scenario_data JSON, not the questions table)
$media_by_index = [];
foreach ($quiz_questions as $qq) {
    if (!empty($qq['media_path'])) {
        $media_by_index[$qq['scenario_index']] = ['path' => sim_legacy_media_url($qq['media_path']), 'type' => $qq['media_type']];
    }
}

include 'includes/header.php';
?>

<style>
/* Animation Styles */
.sim-container {
    background: #1a252f;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 24px;
    position: relative;
}

.sim-screen {
    min-height: 320px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px;
    position: relative;
    background: linear-gradient(180deg, #87CEEB 0%, #87CEEB 60%, #555 60%, #555 100%);
}

/* Road */
.road {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 40%;
    background: #555;
}
.road-line {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 60px; height: 6px;
    background: white; border-radius: 3px;
}

/* Car */
.car {
    font-size: 48px;
    position: absolute;
    bottom: 30%;
    transition: left 2.8s ease-in-out, bottom 1s ease-in-out;
    transform: scaleX(-1); /* car travels left-to-right, so face it forward */
    z-index: 10;
    filter: drop-shadow(0 4px 6px rgba(0,0,0,.3));
}

/* Overtaking: slower car ahead + oncoming car */
.car-front {
    font-size: 44px;
    position: absolute;
    bottom: 30%;
    z-index: 9;
    filter: drop-shadow(0 4px 6px rgba(0,0,0,.3));
    transform: scaleX(-1); /* slower car faces the same direction as the learner car */
}
.oncoming-car {
    font-size: 42px;
    position: absolute;
    bottom: 55%;
    z-index: 9;
    transition: right 2.8s ease-in-out;
    transform: none; /* oncoming vehicle faces the opposite direction */
    filter: drop-shadow(0 4px 6px rgba(0,0,0,.3));
}

/* Multi-vehicle intersection scenes */
.junction-car {
    position:absolute;
    font-size:42px;
    z-index:9;
    filter:drop-shadow(0 4px 6px rgba(0,0,0,.3));
    transition: left 3.1s cubic-bezier(.22,.61,.36,1), right 3.1s cubic-bezier(.22,.61,.36,1), top 3.1s cubic-bezier(.22,.61,.36,1), bottom 3.1s cubic-bezier(.22,.61,.36,1);
}
.junction-car.from-right { right:8%; bottom:47%; transform:scaleX(-1); }
.junction-car.from-top { left:54%; top:7%; transform:rotate(-90deg); }
.junction-car.from-bottom { left:43%; bottom:7%; transform:rotate(90deg); }
.turn-indicator {
    position:absolute;
    width:9px;height:9px;border-radius:50%;
    background:#ffb000;
    box-shadow:0 0 8px #ffb000;
    opacity:0;
    z-index:15;
}
.turn-indicator.on { opacity:1; animation:indicatorBlink .65s steps(1,end) infinite; }
.car .turn-indicator { right:-2px; bottom:8px; }
.junction-car .turn-indicator { right:-2px; bottom:8px; }
.junction-car.waiting { filter:drop-shadow(0 4px 6px rgba(0,0,0,.3)) drop-shadow(0 0 5px rgba(255,70,50,.55)); }
@keyframes indicatorBlink { 0%,49%{opacity:1} 50%,100%{opacity:.12} }

/* Animal on the road */
.sim-animal {
    font-size: 40px;
    position: absolute;
    bottom: 34%;
    z-index: 11;
    transition: left 2.4s ease-in-out;
}

/* Yellow warning triangle sign (roadside, matches Study Materials look) */
.warn-sign-pole {
    position: absolute;
    right: 25%;
    bottom: 30%;
    z-index: 5;
    text-align: center;
}
.warn-sign-outer {
    width: 0; height: 0;
    border-left: 30px solid transparent;
    border-right: 30px solid transparent;
    border-bottom: 52px solid #1a1a1a;
    margin: 0 auto;
}
.warn-sign-inner {
    width: 0; height: 0;
    border-left: 25px solid transparent;
    border-right: 25px solid transparent;
    border-bottom: 43px solid #f1c40f;
    margin: -49px auto 0;
    position: relative;
}
.warn-sign-symbol {
    position: absolute;
    top: 16px; left: 50%;
    transform: translateX(-50%);
    font-size: 17px;
    line-height: 1;
}
.warn-sign-stem { width:6px; height:48px; background:#666; border-radius:3px; margin:4px auto 0; }

/* Traffic Light */
.traffic-light-pole {
    position: absolute;
    right: 25%;
    bottom: 30%;
    z-index: 5;
}
.traffic-light-box {
    background: #222;
    border-radius: 10px;
    padding: 8px;
    margin-bottom: 4px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    border: 2px solid #444;
}
.light-bulb {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #333;
    transition: background .3s, box-shadow .3s;
}
.light-bulb.red-on    { background: #e74c3c; box-shadow: 0 0 12px #e74c3c; }
.light-bulb.amber-on  { background: #f1c40f; box-shadow: 0 0 12px #f1c40f; }
.light-bulb.green-on  { background: #2ecc71; box-shadow: 0 0 12px #2ecc71; }
.light-pole-stem { width: 6px; height: 60px; background: #666; margin: 0 auto; border-radius: 3px; }

/* Stop sign */
.stop-sign {
    position: absolute;
    right: 25%;
    bottom: 30%;
    z-index: 5;
    text-align: center;
}
.stop-sign-board {
    background: #e74c3c;
    color: white;
    font-size: 12px;
    font-weight: 800;
    width: 50px; height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    clip-path: polygon(30% 0%, 70% 0%, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0% 70%, 0% 30%);
    margin: 0 auto 4px;
}
.stop-sign-pole { width: 6px; height: 60px; background: #666; margin: 0 auto; border-radius: 3px; }

/* Result overlay */
.result-overlay {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    font-size: 80px;
    z-index: 20;
    opacity: 0;
    transition: opacity .5s;
    text-shadow: 0 4px 12px rgba(0,0,0,.3);
}
.result-overlay.show { opacity: 1; }

/* Scenario info bar */
.sim-info {
    background: rgba(0,0,0,.5);
    color: white;
    padding: 12px 20px;
    font-size: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Mode badge */
.mode-badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}
.mode-badge.study { background: #eaf4ff; color: #2471a3; }
.mode-badge.quiz  { background: #fdecea; color: #c0392b; }

/* Question card in quiz mode */
.quiz-q-card {
    background: white;
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 16px;
    border-left: 4px solid #3498db;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

/* Answer buttons */
.answer-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    background: white;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    transition: all .2s;
    width: 100%;
    margin-bottom: 10px;
}
.answer-btn:hover    { border-color: #3498db; background: #f0f8ff; }
.answer-btn.selected { border-color: #3498db; background: #f0f8ff; }
.answer-btn.correct  { border-color: #27ae60; background: #eafaf1; color: #27ae60; }
.answer-btn.wrong    { border-color: #e74c3c; background: #fdecea; color: #e74c3c; }
.answer-btn .btn-icon { font-size: 22px; }

/* New animation types: speed / parking / vehicle_check */
.speed-sign-pole {
    position: absolute; right: 25%; bottom: 30%; z-index: 5; text-align: center;
}
.speed-sign-circle {
    width: 54px; height: 54px; border-radius: 50%; border: 5px solid #e74c3c;
    background: white; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 4px; font-size: 15px; font-weight: 900; color: #333;
}
.speedo-badge {
    position: absolute; top: 12%; left: 12%; background: rgba(0,0,0,.7); color: white;
    padding: 6px 14px; border-radius: 8px; font-size: 14px; font-weight: 700; z-index: 15;
}
.parking-pole { position: absolute; right: 25%; bottom: 30%; z-index: 5; text-align: center; }
.parking-legal-p {
    width: 42px; height: 50px; background: #3498db; border-radius: 6px; display: flex;
    align-items: center; justify-content: center; margin: 0 auto 4px; font-size: 22px;
    font-weight: 900; color: white;
}
.parking-yellow-strip { position: absolute; bottom: 30%; left: 0; right: 0; height: 6px; background: #f1c40f; }
.parking-no-p {
    width: 42px; height: 42px; border-radius: 50%; border: 4px solid #e74c3c; background: white;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 4px;
    font-size: 17px; font-weight: 900; color: #e74c3c; text-decoration: line-through;
}
.vehicle-check-badge {
    position: absolute; top: 15%; left: 50%; transform: translateX(-50%);
    background: rgba(0,0,0,.75); color: white; padding: 10px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 600; z-index: 15; text-align: center; white-space: nowrap;
}

/* ── Night driving ── */
.sim-screen.night-mode {
    background: linear-gradient(180deg, #0b1220 0%, #16233a 60%, #444 60%, #444 100%) !important;
}
.night-stars span {
    position: absolute; width: 2px; height: 2px; background: #fff; border-radius: 50%; opacity: .7;
}
.fog-overlay {
    position: absolute; inset: 0; background: rgba(220,220,230,.55); z-index: 6; pointer-events: none;
}
.headlight-beam {
    position: absolute; bottom: 31%; width: 0; height: 0;
    border-style: solid; opacity: 0; transition: opacity .3s;
    filter: blur(1px);
    z-index: 4;
}
.headlight-beam.on { opacity: .55; }
.headlight-beam.high {
    border-width: 10px 95px 10px 0;
    border-color: transparent rgba(255,250,190,.9) transparent transparent;
}
.headlight-beam.low {
    border-width: 6px 55px 6px 0;
    border-color: transparent rgba(255,250,190,.7) transparent transparent;
}
.headlight-beam.fog {
    border-width: 5px 40px 5px 0;
    border-color: transparent rgba(255,220,140,.6) transparent transparent;
}

/* ── Emergency vehicle ── */
.emergency-vehicle-wrap {
    position: absolute; bottom: 30%; z-index: 9; text-align: center; transition: left 1.6s ease-in-out;
}
.emergency-vehicle-icon { font-size: 42px; filter: drop-shadow(0 4px 6px rgba(0,0,0,.3)); transform: scaleX(-1); transform-origin: center; }
.light-bar { display: flex; gap: 2px; justify-content: center; margin-bottom: 2px; }
.light-bar span { width: 8px; height: 5px; border-radius: 1px; opacity: .3; }
.light-bar .red  { background: #e74c3c; }
.light-bar .blue { background: #2e86de; }
.light-bar.flashing .red  { animation: flashRed 0.6s infinite steps(1); }
.light-bar.flashing .blue { animation: flashBlue 0.6s infinite steps(1); animation-delay: .3s; }
@keyframes flashRed  { 0%,49% { opacity: 1; } 50%,100% { opacity: .2; } }
@keyframes flashBlue { 0%,49% { opacity: .2; } 50%,100% { opacity: 1; } }

/* ── School zone ── */
.school-sign-pole { position: absolute; right: 25%; bottom: 30%; z-index: 5; text-align: center; }
.school-sign {
    width: 58px; height: 58px; border-radius: 8px; background: #f1c40f; border: 3px solid #1a1a1a;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 900; color: #1a1a1a; line-height: 1.1; margin: 0 auto 4px;
}
.school-sign .limit { font-size: 18px; }
.school-kid {
    font-size: 30px; position: absolute; bottom: 33%; z-index: 11; transition: left 2.4s ease-in-out;
}


/* =========================================================
   TESTMATE ROAD SCENE UPGRADE
   Visual upgrade only: keeps the existing PHP/database logic.
   The learner vehicle remains in the left-hand lane used in Namibia.
   ========================================================= */
.sim-screen {
    isolation: isolate;
    overflow: hidden;
    min-height: 360px;
    background:
        radial-gradient(circle at 78% 16%, rgba(255,255,255,.95) 0 4%, rgba(255,255,255,0) 4.4%),
        linear-gradient(180deg,#78c9ee 0 44%,#b8d986 44% 57%,#8eaf61 57% 60%,#4b5055 60% 100%);
    box-shadow: inset 0 -14px 25px rgba(0,0,0,.16);
}

/* distant Namibian roadside landscape */
.sim-screen::before {
    content:"";
    position:absolute;
    left:-3%; right:-3%;
    top:39%;
    height:22%;
    z-index:0;
    pointer-events:none;
    background:
        radial-gradient(ellipse at 8% 100%,#6d8f4e 0 22%,transparent 23%),
        radial-gradient(ellipse at 24% 100%,#7c9c58 0 28%,transparent 29%),
        radial-gradient(ellipse at 71% 100%,#668a4a 0 25%,transparent 26%),
        radial-gradient(ellipse at 91% 100%,#789956 0 30%,transparent 31%),
        linear-gradient(180deg,transparent 0 72%,#a4bb70 73% 100%);
    opacity:.95;
}

/* asphalt texture + road-edge lines */
.sim-screen::after {
    content:"";
    position:absolute;
    left:0; right:0; bottom:0;
    height:40%;
    z-index:1;
    pointer-events:none;
    background:
        linear-gradient(to bottom,
            #d9d5c8 0 3px,
            #f5f2e8 3px 7px,
            transparent 7px calc(100% - 8px),
            #f5f2e8 calc(100% - 8px) calc(100% - 4px),
            #d9d5c8 calc(100% - 4px) 100%),
        repeating-linear-gradient(115deg,rgba(255,255,255,.018) 0 2px,rgba(0,0,0,.025) 2px 4px);
}

/* proper broken centre line across the full carriageway */
.road-line {
    z-index:3 !important;
    top:auto !important;
    bottom:19.3% !important;
    left:0 !important;
    width:100% !important;
    height:5px !important;
    transform:none !important;
    border-radius:0 !important;
    background:repeating-linear-gradient(90deg,#f7f7f2 0 44px,transparent 44px 78px) !important;
    box-shadow:0 1px 1px rgba(0,0,0,.18);
}

/* road users/signs stay above the upgraded road */
.car,.car-front,.oncoming-car,.junction-car,.sim-animal,
.traffic-light-pole,.stop-sign,.warn-sign-pole,.speed-sign-pole,
.parking-pole,.school-sign-pole,.school-kid,.emergency-vehicle-wrap,
.headlight-beam,.vehicle-check-badge,.speedo-badge,.result-overlay {
    z-index:10;
}

/* clearer lane placement: learner uses the left-hand-traffic lane,
   opposing traffic uses the opposite lane. */
.car,.car-front { bottom:10.5% !important; }
.oncoming-car { bottom:28.5% !important; }
.sim-animal,.school-kid { bottom:13% !important; }
.traffic-light-pole,.stop-sign,.warn-sign-pole,.speed-sign-pole,
.parking-pole,.school-sign-pole { bottom:37% !important; }

/* Intersections: add a true crossing road rather than cars floating on grass. */
.sim-screen:has(.junction-car)::after {
    height:100%;
    background:
        linear-gradient(90deg,transparent 0 39%,#f5f2e8 39% 39.6%,#4b5055 39.6% 60.4%,#f5f2e8 60.4% 61%,transparent 61%),
        linear-gradient(180deg,transparent 0 59.2%,#f5f2e8 59.2% 60%,#4b5055 60% 100%),
        repeating-linear-gradient(115deg,rgba(255,255,255,.018) 0 2px,rgba(0,0,0,.025) 2px 4px);
}
.sim-screen:has(.junction-car) .road-line { z-index:4 !important; }
.sim-screen:has(.junction-car) .junction-car.from-right { display:none !important; }
.sim-screen:has(.junction-car) .junction-car.from-top { left:54%; top:4%; }
.sim-screen:has(.junction-car) .junction-car.from-bottom { left:43%; bottom:4%; }

/* Two-vehicle junction teaching layout: one horizontal learner vehicle and
   one vertical vehicle. They never share the same lane. */
.sim-screen:has(.junction-car) .car { bottom:9% !important; }
.sim-screen:has(.junction-car) .junction-car { z-index:12; }

/* stop lines at the junction approaches */
.sim-screen:has(.junction-car) {
    background:
        linear-gradient(90deg,transparent 0 39%,rgba(255,255,255,.9) 39% 39.8%,transparent 39.8% 60.2%,rgba(255,255,255,.9) 60.2% 61%,transparent 61%),
        linear-gradient(180deg,#78c9ee 0 44%,#b8d986 44% 57%,#8eaf61 57% 60%,#4b5055 60% 100%);
}

/* better roadside sign hardware */
.stop-sign-board,.speed-sign-circle,.parking-legal-p,.parking-no-p,.school-sign {
    filter:drop-shadow(0 3px 3px rgba(0,0,0,.28));
}
.stop-sign-pole,.light-pole-stem,.warn-sign-stem,
.speed-sign-pole > div:last-child,.school-sign-pole > div:last-child {
    box-shadow:inset -2px 0 1px rgba(0,0,0,.22);
}

/* traffic signal is easier to read at simulation size */
.traffic-light-box {
    border:3px solid #30363a;
    box-shadow:0 5px 10px rgba(0,0,0,.28);
}
.light-bulb { border:2px solid rgba(255,255,255,.08); }

/* Night scenes keep the improved asphalt while replacing the sky. */
.sim-screen.night-mode {
    background:linear-gradient(180deg,#07111f 0 44%,#15243b 44% 57%,#27303a 57% 60%,#3f4449 60% 100%) !important;
}
.sim-screen.night-mode::before { opacity:.25; filter:brightness(.45); }

@media (max-width:700px) {
    .sim-screen { min-height:320px; padding:18px; }
    .car { font-size:42px; }
    .car-front { font-size:39px; }
    .oncoming-car,.junction-car { font-size:37px; }
}
</style>

<!-- Page Header -->
<header class="sim-head is-narrow">
    <div>
        <span class="sim-kicker">Simulation<?= !empty($simulation['topic_name']) ? ' · ' . htmlspecialchars($simulation['topic_name']) : '' ?></span>
        <h1><?= htmlspecialchars($simulation['title']) ?></h1>
        <p><?= htmlspecialchars($simulation['description']) ?></p>
    </div>
    <nav class="sim-mode-switch" aria-label="Simulation mode">
        <a href="/simulations.php?id=<?= $sim_id ?>&mode=study" class="<?= $mode === 'study' ? 'is-active' : '' ?>">Study Mode</a>
        <a href="/simulations.php?id=<?= $sim_id ?>&mode=quiz" class="is-quiz <?= $mode !== 'study' ? 'is-active' : '' ?>">Quiz Mode</a>
    </nav>
</header>

<div class="container" style="max-width:1000px !important;">

<?php if ($mode === 'study'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- STUDY MODE — Show animation + auto reveal answer       -->
<!-- ═══════════════════════════════════════════════════════ -->

<div class="sim-note">
    <strong>Study Mode:</strong>
    <span> Watch each scenario carefully. The animation will automatically show you whether the driver's action was correct or incorrect so you can learn the rules.</span>
</div>

<?php foreach ($scenarios as $si => $scenario): ?>
<div class="tm-sim-lesson-card" style="margin-bottom:32px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:12px;color:#2c3e50;">
        Scenario <?= $si+1 ?>
        <span style="font-size:13px;font-weight:400;color:#888;margin-left:8px;">
            <?php if ($simulation['animation_type'] === 'traffic_light'): ?>
                <?= ucfirst($scenario['light']) ?> light — Car <?= $scenario['car_passes'] ? 'drives through' : 'stops' ?>
            <?php elseif ($simulation['animation_type'] === 'four_way_stop'): ?>
                Vehicle arrives <?= str_replace('_',' ',$scenario['arriving_order']) ?> — Action: <?= $scenario['action'] ?>
            <?php elseif ($simulation['animation_type'] === 'road_signs'): ?>
                <?= ucfirst($scenario['sign'] ?? '') ?> sign — Driver <?= ($scenario['driver_stops'] ?? false) ? 'stops' : 'does not stop' ?>
            <?php elseif ($simulation['animation_type'] === 'speed'): ?>
                Driving at <?= (int)($scenario['speed'] ?? 0) ?> km/h (limit: <?= (int)($scenario['limit'] ?? 0) ?> km/h)
            <?php elseif ($simulation['animation_type'] === 'parking'): ?>
                <?= ucfirst(str_replace('_',' ',$scenario['location'] ?? '')) ?> parking
            <?php elseif ($simulation['animation_type'] === 'overtake'): ?>
                <?= !empty($scenario['oncoming']) ? 'Overtakes with oncoming traffic' : 'Overtakes with a clear road' ?>
            <?php elseif ($simulation['animation_type'] === 'animal_crossing'): ?>
                Animal on road — Driver <?= !empty($scenario['slows']) ? 'slows down' : 'continues at speed' ?>
            <?php elseif ($simulation['animation_type'] === 'night_driving'): ?>
                <?= ucfirst(str_replace('_',' ',$scenario['condition'] ?? '')) ?> — Lights: <?= ucfirst(str_replace('_',' ',$scenario['lights'] ?? '')) ?>
            <?php elseif ($simulation['animation_type'] === 'emergency_vehicle'): ?>
                <?= ucfirst(str_replace('_',' ',$scenario['vehicle'] ?? '')) ?> — Sirens <?= ucfirst($scenario['sirens'] ?? '') ?>, action: <?= ucfirst(str_replace('_',' ',$scenario['action'] ?? '')) ?>
            <?php elseif ($simulation['animation_type'] === 'school_zone'): ?>
                <?= ucfirst(str_replace('_',' ',$scenario['time'] ?? '')) ?> — Driving <?= (int)($scenario['your_speed'] ?? 0) ?> km/h (limit <?= (int)($scenario['speed'] ?? 0) ?> km/h)
            <?php elseif ($simulation['animation_type'] === 'vehicle_check'): ?>
                <?= htmlspecialchars($scenario['label'] ?? ucfirst(str_replace('_',' ',$scenario['action'] ?? ''))) ?>
            <?php else: ?>
                <?= htmlspecialchars((string)($scenario['label'] ?? '')) ?>
            <?php endif; ?>
        </span>
    </h3>

    <!-- Animation Container -->
    <div class="sim-container" id="sim-study-<?= $si ?>">
        <div class="sim-screen <?= $simulation['animation_type'] === 'night_driving' ? 'night-mode' : '' ?>" id="screen-study-<?= $si ?>">

            <?php if ($simulation['animation_type'] === 'night_driving'): ?>
            <div class="night-stars">
                <?php for ($ns = 0; $ns < 18; $ns++):
                    $nsTop  = rand(4, 45);
                    $nsLeft = rand(2, 96);
                ?>
                <span style="top:<?= $nsTop ?>%;left:<?= $nsLeft ?>%;"></span>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <!-- Road line -->
            <div class="road-line" style="bottom:calc(30% + 10px);left:50%;position:absolute;"></div>

            <?php if ($simulation['animation_type'] === 'traffic_light'): ?>
            <!-- Traffic Light -->
            <div class="traffic-light-pole" id="tl-study-<?= $si ?>">
                <div class="traffic-light-box">
                    <div class="light-bulb <?= $scenario['light']==='red' ? 'red-on' : '' ?>" id="red-study-<?= $si ?>"></div>
                    <div class="light-bulb <?= $scenario['light']==='amber' ? 'amber-on' : '' ?>" id="amber-study-<?= $si ?>"></div>
                    <div class="light-bulb <?= $scenario['light']==='green' ? 'green-on' : '' ?>" id="green-study-<?= $si ?>"></div>
                </div>
                <div class="light-pole-stem"></div>
            </div>

            <?php elseif ($simulation['animation_type'] === 'road_signs'): ?>
            <!-- Road Sign -->
            <div class="stop-sign" id="sign-study-<?= $si ?>">
                <?php if ($scenario['sign'] === 'stop'): ?>
                <div class="stop-sign-board">STOP</div>
                <?php elseif ($scenario['sign'] === 'yield'): ?>
                <div style="width:50px;height:50px;background:#e74c3c;clip-path:polygon(50% 100%,0% 0%,100% 0%);margin:0 auto 4px;display:flex;align-items:center;justify-content:center;">
                    <span style="color:white;font-size:10px;font-weight:800;margin-top:-10px;">YIELD</span>
                </div>
                <?php endif; ?>
                <div class="stop-sign-pole"></div>
            </div>

            <?php elseif ($simulation['animation_type'] === 'four_way_stop'): ?>
            <!-- Four Way Stop Signs at all 4 positions -->
            <?php foreach ([['right:15%','bottom:35%'],['right:15%','bottom:55%'],['left:15%','bottom:35%'],['left:15%','bottom:55%']] as $pos): ?>
            <div style="position:absolute;<?= $pos[0] ?>;<?= $pos[1] ?>;text-align:center;">
                <div style="width:24px;height:24px;background:#e74c3c;clip-path:polygon(30% 0%,70% 0%,100% 30%,100% 70%,70% 100%,30% 100%,0% 70%,0% 30%);margin:0 auto;display:flex;align-items:center;justify-content:center;">
                    <span style="color:white;font-size:5px;font-weight:800;">STOP</span>
                </div>
                <div style="width:3px;height:30px;background:#666;margin:0 auto;"></div>
            </div>
            <?php endforeach; ?>
            <!-- Two-vehicle junction: learner travels horizontally; the other vehicle travels vertically. -->
            <?php $cross_from_bottom = (strpos(strtolower((string)($scenario['arriving_order'] ?? '')), 'same_time_right') !== false); ?>
            <div class="junction-car <?= $cross_from_bottom ? 'from-bottom' : 'from-top' ?>"
                 id="junction-t-study-<?= $si ?>" data-approach="<?= $cross_from_bottom ? 'bottom' : 'top' ?>">🚕<span class="turn-indicator"></span></div>

            <?php elseif ($simulation['animation_type'] === 'speed'): ?>
            <div class="speed-sign-pole">
                <div class="speed-sign-circle"><?= (int)($scenario['limit'] ?? 0) ?></div>
                <div style="width:5px;height:55px;background:#777;border-radius:3px;margin:0 auto;"></div>
            </div>
            <div class="speedo-badge" id="speedo-study-<?= $si ?>" style="display:none;"><?= (int)($scenario['speed'] ?? 0) ?> km/h</div>

            <?php elseif ($simulation['animation_type'] === 'parking'): ?>
            <div class="parking-pole">
                <?php if (($scenario['location'] ?? '') === 'legal'): ?>
                <div class="parking-legal-p">P</div>
                <?php elseif (($scenario['location'] ?? '') === 'yellow'): ?>
                <div class="parking-yellow-strip"></div>
                <div style="background:rgba(0,0,0,.6);color:white;padding:4px 8px;border-radius:6px;font-size:10px;font-weight:700;">YELLOW KERB</div>
                <?php else: ?>
                <div class="parking-no-p">P</div>
                <?php endif; ?>
            </div>

            <?php elseif ($simulation['animation_type'] === 'overtake'): ?>
            <!-- Slower car ahead, same lane -->
            <div class="car-front" style="left:52%;">🚙</div>
            <?php if (!empty($scenario['oncoming'])): ?>
            <!-- Oncoming car in the opposite lane -->
            <div class="oncoming-car" id="oncoming-study-<?= $si ?>" style="right:5%;">🚓</div>
            <?php endif; ?>

            <?php elseif ($simulation['animation_type'] === 'animal_crossing'): ?>
            <!-- Warning sign posted before the crossing zone -->
            <div class="warn-sign-pole">
                <div class="warn-sign-outer"></div>
                <div class="warn-sign-inner">
                    <span class="warn-sign-symbol"><?= $scenario['animal'] ?? '🦌' ?></span>
                </div>
                <div class="warn-sign-stem"></div>
            </div>
            <!-- Animal on the road -->
            <div class="sim-animal" id="animal-study-<?= $si ?>" style="left:58%;"><?= $scenario['animal'] ?? '🦌' ?></div>

            <?php elseif ($simulation['animation_type'] === 'night_driving'): ?>
            <?php
                $ndCond  = $scenario['condition'] ?? '';
                $ndLights = $scenario['lights'] ?? '';
                $ndBeamClass = $ndLights === 'high_beam' ? 'high' : ($ndLights === 'dim' ? 'low' : ($ndLights === 'fog_lights_low' ? 'fog' : ''));
            ?>
            <?php if ($ndCond === 'fog'): ?>
            <div class="fog-overlay"></div>
            <?php endif; ?>
            <?php if ($ndCond === 'oncoming_traffic'): ?>
            <div class="oncoming-car" id="oncoming-study-<?= $si ?>" style="right:8%;bottom:31%;">🚙</div>
            <?php endif; ?>
            <div class="headlight-beam <?= $ndBeamClass ?>" id="beam-study-<?= $si ?>" style="left:16%;"></div>

            <?php elseif ($simulation['animation_type'] === 'emergency_vehicle'): ?>
            <?php
                $evIcon = ['ambulance'=>'🚑','fire_truck'=>'🚒','police'=>'🚓'][$scenario['vehicle'] ?? ''] ?? '🚑';
                $evSirensOn = ($scenario['sirens'] ?? '') === 'on';
            ?>
            <div class="emergency-vehicle-wrap" id="evwrap-study-<?= $si ?>" style="left:8%;bottom:14%;">
                <div class="light-bar <?= $evSirensOn ? 'flashing' : '' ?>">
                    <span class="red"></span><span class="blue"></span><span class="red"></span><span class="blue"></span>
                </div>
                <div class="emergency-vehicle-icon"><?= $evIcon ?></div>
            </div>

            <?php elseif ($simulation['animation_type'] === 'school_zone'): ?>
            <?php $szTime = $scenario['time'] ?? ''; ?>
            <div class="school-sign-pole">
                <div class="school-sign">SCHOOL<span class="limit"><?= (int)($scenario['speed'] ?? 0) ?></span></div>
                <div style="width:5px;height:50px;background:#777;border-radius:3px;margin:0 auto;"></div>
            </div>
            <?php if ($szTime === 'children_present'): ?>
            <div class="school-kid" id="kid-study-<?= $si ?>" style="left:50%;">🧒</div>
            <div class="school-kid" id="kid2-study-<?= $si ?>" style="left:56%;">🧑‍🎓</div>
            <?php endif; ?>
            <div class="speedo-badge" id="speedo-study-<?= $si ?>" style="display:none;"><?= (int)($scenario['your_speed'] ?? 0) ?> km/h</div>

            <?php elseif ($simulation['animation_type'] === 'vehicle_check'): ?>
            <div class="vehicle-check-badge"><?= htmlspecialchars($scenario['label'] ?? ucfirst(str_replace('_',' ',$scenario['action'] ?? ''))) ?></div>
            <?php endif; ?>

            <!-- Car -->
            <div class="car" id="car-study-<?= $si ?>" style="left:<?= $simulation['animation_type'] === 'emergency_vehicle' ? '38%' : '5%' ?>;bottom:<?= $simulation['animation_type'] === 'emergency_vehicle' ? '14%' : '28%' ?>;">🚗<span class="turn-indicator"></span></div>

            <!-- Result Overlay -->
            <div class="result-overlay" id="result-study-<?= $si ?>">
                <?= $scenario['is_correct'] ? '✅' : '❌' ?>
            </div>

        </div>

        <!-- Info bar -->
        <div class="sim-info">
            <span id="status-study-<?= $si ?>" style="font-size:13px;">Click Play to watch the scenario</span>
            <button data-si="<?= $si ?>"
                    data-scenario='<?= htmlspecialchars(json_encode($scenario), ENT_QUOTES, "UTF-8") ?>'
                    onclick="playStudyScenario(this)"
                    id="playBtn-study-<?= $si ?>"
                    style="background:#3498db;color:white;border:none;padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
                Play
            </button>
        </div>
    </div>

    <?php if (isset($media_by_index[$si])):
        $sm = $media_by_index[$si];
    ?>
    <div style="margin-top:12px;">
        <?php if ($sm['type'] === 'video'): ?>
        <video src="<?= htmlspecialchars($sm['path']) ?>" controls style="max-width:100%;max-height:280px;border-radius:8px;display:block;"></video>
        <?php elseif ($sm['type'] === 'pdf'): ?>
        <a href="<?= htmlspecialchars($sm['path']) ?>" target="_blank" class="btn btn-outline" style="font-size:13px;">📄 View attached PDF</a>
        <?php else: ?>
        <img src="<?= htmlspecialchars($sm['path']) ?>" style="max-width:100%;max-height:280px;border-radius:8px;display:block;">
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Explanation (shown after play) -->
    <div id="explanation-study-<?= $si ?>" style="display:none;background:<?= $scenario['is_correct']?'#eafaf1':'#fdecea' ?>;border-left:4px solid <?= $scenario['is_correct']?'#27ae60':'#e74c3c' ?>;border-radius:0 8px 8px 0;padding:14px 18px;font-size:14px;color:#333;line-height:1.6;">
        <strong style="color:<?= $scenario['is_correct']?'#27ae60':'#e74c3c' ?>"><?= $scenario['is_correct']?'Correct Action':'Incorrect Action' ?></strong><br>
        <?= htmlspecialchars($scenario['explanation']) ?>
    </div>
</div>
<?php endforeach; ?>

<div class="sim-cta">
    <p>Finished watching? Check your understanding.</p>
    <a href="/simulations.php?id=<?= $sim_id ?>&mode=quiz" class="btn btn-primary btn-lg">
        Ready? Take the Quiz
    </a>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- QUIZ MODE — Show animation, learner decides answer     -->
<!-- ═══════════════════════════════════════════════════════ -->

<div class="sim-note is-quiz">
    <strong>Quiz Mode:</strong>
    <span> Watch each animation carefully. Decide whether the driver's action was correct or incorrect. Do NOT reveal the answer — make your choice first, then submit all answers.</span>
</div>

<form method="POST" id="simQuizForm">
    <input type="hidden" name="submit_simulation" value="1">
    <input type="hidden" name="simulation_id" value="<?= $sim_id ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['sim_csrf'], ENT_QUOTES, 'UTF-8') ?>">

    <?php foreach ($quiz_questions as $qi => $q):
        $scenario = $scenarios[$q['scenario_index']] ?? [];
    ?>
    <div class="quiz-q-card" style="margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:#999;">Question <?= $qi+1 ?> of <?= count($quiz_questions) ?></span>
            <span style="font-size:12px;background:#fdecea;color:#c0392b;padding:3px 10px;border-radius:20px;font-weight:600;">Quiz Mode — No hints!</span>
        </div>

        <!-- Animation -->
        <div class="sim-container" id="sim-quiz-<?= $qi ?>" style="margin-bottom:14px;">
            <div class="sim-screen <?= $simulation['animation_type'] === 'night_driving' ? 'night-mode' : '' ?>" id="screen-quiz-<?= $qi ?>">

                <?php if ($simulation['animation_type'] === 'night_driving'): ?>
                <div class="night-stars">
                    <?php for ($ns = 0; $ns < 18; $ns++):
                        $nsTop  = rand(4, 45);
                        $nsLeft = rand(2, 96);
                    ?>
                    <span style="top:<?= $nsTop ?>%;left:<?= $nsLeft ?>%;"></span>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <div class="road-line" style="bottom:calc(30% + 10px);left:50%;position:absolute;"></div>

                <?php if ($simulation['animation_type'] === 'traffic_light' && $scenario): ?>
                <div class="traffic-light-pole">
                    <div class="traffic-light-box">
                        <div class="light-bulb <?= $scenario['light']==='red'?'red-on':'' ?>"></div>
                        <div class="light-bulb <?= $scenario['light']==='amber'?'amber-on':'' ?>"></div>
                        <div class="light-bulb <?= $scenario['light']==='green'?'green-on':'' ?>"></div>
                    </div>
                    <div class="light-pole-stem"></div>
                </div>
                <?php elseif ($simulation['animation_type'] === 'road_signs' && $scenario): ?>
                <div class="stop-sign">
                    <?php if (($scenario['sign']??'') === 'stop'): ?>
                    <div class="stop-sign-board">STOP</div>
                    <?php else: ?>
                    <div style="width:50px;height:50px;background:#e74c3c;clip-path:polygon(50% 100%,0% 0%,100% 0%);margin:0 auto 4px;"></div>
                    <?php endif; ?>
                    <div class="stop-sign-pole"></div>
                </div>
                <?php elseif ($simulation['animation_type'] === 'four_way_stop' && $scenario): ?>
                <?php foreach ([['right:15%','bottom:35%'],['right:15%','bottom:55%'],['left:15%','bottom:35%'],['left:15%','bottom:55%']] as $pos): ?>
                <div style="position:absolute;<?= $pos[0] ?>;<?= $pos[1] ?>;text-align:center;">
                    <div style="width:24px;height:24px;background:#e74c3c;clip-path:polygon(30% 0%,70% 0%,100% 30%,100% 70%,70% 100%,30% 100%,0% 70%,0% 30%);margin:0 auto;display:flex;align-items:center;justify-content:center;">
                        <span style="color:white;font-size:5px;font-weight:800;">STOP</span>
                    </div>
                    <div style="width:3px;height:30px;background:#666;margin:0 auto;"></div>
                </div>
                <?php endforeach; ?>
                <?php $cross_from_bottom = (strpos(strtolower((string)($scenario['arriving_order'] ?? '')), 'same_time_right') !== false); ?>
                <div class="junction-car <?= $cross_from_bottom ? 'from-bottom' : 'from-top' ?>"
                     id="junction-t-quiz-<?= $qi ?>" data-approach="<?= $cross_from_bottom ? 'bottom' : 'top' ?>">🚕<span class="turn-indicator"></span></div>
                <?php elseif ($simulation['animation_type'] === 'speed' && $scenario): ?>
                <div class="speed-sign-pole">
                    <div class="speed-sign-circle"><?= (int)($scenario['limit'] ?? 0) ?></div>
                    <div style="width:5px;height:55px;background:#777;border-radius:3px;margin:0 auto;"></div>
                </div>
                <div class="speedo-badge" id="speedo-quiz-<?= $qi ?>" style="display:none;"><?= (int)($scenario['speed'] ?? 0) ?> km/h</div>
                <?php elseif ($simulation['animation_type'] === 'parking' && $scenario): ?>
                <div class="parking-pole">
                    <?php if (($scenario['location'] ?? '') === 'legal'): ?>
                    <div class="parking-legal-p">P</div>
                    <?php elseif (($scenario['location'] ?? '') === 'yellow'): ?>
                    <div class="parking-yellow-strip"></div>
                    <div style="background:rgba(0,0,0,.6);color:white;padding:4px 8px;border-radius:6px;font-size:10px;font-weight:700;">YELLOW KERB</div>
                    <?php else: ?>
                    <div class="parking-no-p">P</div>
                    <?php endif; ?>
                </div>
                <?php elseif ($simulation['animation_type'] === 'overtake' && $scenario): ?>
                <!-- Slower car ahead, same lane -->
                <div class="car-front" style="left:52%;">🚙</div>
                <?php if (!empty($scenario['oncoming'])): ?>
                <div class="oncoming-car" id="oncoming-quiz-<?= $qi ?>" style="right:5%;">🚓</div>
                <?php endif; ?>
                <?php elseif ($simulation['animation_type'] === 'animal_crossing' && $scenario): ?>
                <div class="warn-sign-pole">
                    <div class="warn-sign-outer"></div>
                    <div class="warn-sign-inner">
                        <span class="warn-sign-symbol"><?= $scenario['animal'] ?? '🦌' ?></span>
                    </div>
                    <div class="warn-sign-stem"></div>
                </div>
                <div class="sim-animal" id="animal-quiz-<?= $qi ?>" style="left:58%;"><?= $scenario['animal'] ?? '🦌' ?></div>
                <?php elseif ($simulation['animation_type'] === 'night_driving' && $scenario): ?>
                <?php
                    $ndCondQ  = $scenario['condition'] ?? '';
                    $ndLightsQ = $scenario['lights'] ?? '';
                    $ndBeamClassQ = $ndLightsQ === 'high_beam' ? 'high' : ($ndLightsQ === 'dim' ? 'low' : ($ndLightsQ === 'fog_lights_low' ? 'fog' : ''));
                ?>
                <?php if ($ndCondQ === 'fog'): ?>
                <div class="fog-overlay"></div>
                <?php endif; ?>
                <?php if ($ndCondQ === 'oncoming_traffic'): ?>
                <div class="oncoming-car" id="oncoming-quiz-<?= $qi ?>" style="right:8%;bottom:31%;">🚙</div>
                <?php endif; ?>
                <div class="headlight-beam <?= $ndBeamClassQ ?>" id="beam-quiz-<?= $qi ?>" style="left:16%;"></div>
                <?php elseif ($simulation['animation_type'] === 'emergency_vehicle' && $scenario): ?>
                <?php
                    $evIconQ = ['ambulance'=>'🚑','fire_truck'=>'🚒','police'=>'🚓'][$scenario['vehicle'] ?? ''] ?? '🚑';
                    $evSirensOnQ = ($scenario['sirens'] ?? '') === 'on';
                ?>
                <div class="emergency-vehicle-wrap" id="evwrap-quiz-<?= $qi ?>" style="left:8%;bottom:14%;">
                    <div class="light-bar <?= $evSirensOnQ ? 'flashing' : '' ?>">
                        <span class="red"></span><span class="blue"></span><span class="red"></span><span class="blue"></span>
                    </div>
                    <div class="emergency-vehicle-icon"><?= $evIconQ ?></div>
                </div>
                <?php elseif ($simulation['animation_type'] === 'school_zone' && $scenario): ?>
                <?php $szTimeQ = $scenario['time'] ?? ''; ?>
                <div class="school-sign-pole">
                    <div class="school-sign">SCHOOL<span class="limit"><?= (int)($scenario['speed'] ?? 0) ?></span></div>
                    <div style="width:5px;height:50px;background:#777;border-radius:3px;margin:0 auto;"></div>
                </div>
                <?php if ($szTimeQ === 'children_present'): ?>
                <div class="school-kid" id="kid-quiz-<?= $qi ?>" style="left:50%;">🧒</div>
                <div class="school-kid" id="kid2-quiz-<?= $qi ?>" style="left:56%;">🧑‍🎓</div>
                <?php endif; ?>
                <div class="speedo-badge" id="speedo-quiz-<?= $qi ?>" style="display:none;"><?= (int)($scenario['your_speed'] ?? 0) ?> km/h</div>
                <?php elseif ($simulation['animation_type'] === 'vehicle_check' && $scenario): ?>
                <div class="vehicle-check-badge"><?= htmlspecialchars($scenario['label'] ?? ucfirst(str_replace('_',' ',$scenario['action'] ?? ''))) ?></div>
                <?php endif; ?>

                <div class="car" id="car-quiz-<?= $qi ?>" style="left:<?= $simulation['animation_type'] === 'emergency_vehicle' ? '38%' : '5%' ?>;bottom:<?= $simulation['animation_type'] === 'emergency_vehicle' ? '14%' : '28%' ?>;">🚗<span class="turn-indicator"></span></div>

                <!-- NO result overlay in quiz mode -->
            </div>
            <div class="sim-info">
                <span id="qstatus-<?= $qi ?>" style="font-size:13px;">Click Play to watch — then decide if the action was correct</span>
                <button type="button"
                        data-qi="<?= $qi ?>"
                        data-scenario='<?= htmlspecialchars(json_encode($scenario), ENT_QUOTES, "UTF-8") ?>'
                        onclick="playQuizScenario(this)"
                        id="qplayBtn-<?= $qi ?>"
                        style="background:#e74c3c;color:white;border:none;padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
                    Play
                </button>
            </div>
        </div>

        <!-- Question -->
        <?php if (!empty($q['media_path'])): ?>
        <div style="margin-bottom:14px;">
            <?php if ($q['media_type'] === 'video'): ?>
            <video src="<?= htmlspecialchars(sim_legacy_media_url($q['media_path']), ENT_QUOTES, 'UTF-8') ?>" controls style="max-width:100%;max-height:280px;border-radius:8px;display:block;"></video>
            <?php elseif ($q['media_type'] === 'pdf'): ?>
            <a href="<?= htmlspecialchars(sim_legacy_media_url($q['media_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-outline" style="font-size:13px;">📄 View attached PDF</a>
            <?php else: ?>
            <img src="<?= htmlspecialchars(sim_legacy_media_url($q['media_path']), ENT_QUOTES, 'UTF-8') ?>" style="max-width:100%;max-height:280px;border-radius:8px;display:block;">
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p style="font-weight:600;font-size:15px;margin-bottom:14px;color:#2c3e50;"><?= htmlspecialchars($q['question']) ?></p>

        <!-- Answer Buttons -->
        <button type="button"
                class="answer-btn"
                data-qi="<?= $qi ?>"
                data-val="correct"
                onclick="selectAnswer(<?= $qi ?>, 'correct', this)">
            <span class="btn-icon">✅</span>
            <span>Correct — The driver's action was correct</span>
        </button>
        <button type="button"
                class="answer-btn"
                data-qi="<?= $qi ?>"
                data-val="incorrect"
                onclick="selectAnswer(<?= $qi ?>, 'incorrect', this)">
            <span class="btn-icon">❌</span>
            <span>Incorrect — The driver's action was wrong</span>
        </button>

        <input type="hidden" name="answers[<?= $qi ?>]" id="answer-<?= $qi ?>" value="">
    </div>
    <?php endforeach; ?>

    <div class="sim-submit-box">
        <div id="quizValidationMsg" style="display:none;color:#e74c3c;font-size:14px;margin-bottom:12px;">Please answer all questions before submitting.</div>
        <button type="button" onclick="submitSimQuiz()" class="btn btn-primary btn-lg">Submit Quiz</button>
        <p style="color:#999;font-size:13px;margin-top:10px;">Make sure you have watched all animations and answered every question.</p>
    </div>
</form>

<?php endif; ?>

<div class="sim-bottom-links">
    <a href="/simulations.php" class="btn btn-outline">All Simulations</a>
    <a href="/dashboard.php" class="btn btn-outline">Dashboard</a>
</div>

</div>

<script>
function setTurnIndicator(car, scenario) {
    if (!car) return;
    const lamp = car.querySelector('.turn-indicator');
    if (!lamp) return;
    const action = String(scenario.action || scenario.turn || scenario.direction || '').toLowerCase();
    const turning = action.includes('turn') || action.includes('left') || action.includes('right');
    lamp.classList.toggle('on', turning);
}

function resetJunctionCars(mode, idx) {
    const t = document.getElementById('junction-t-' + mode + '-' + idx);
    if (!t) return;
    t.style.transition = 'none';
    const approach = t.dataset.approach || 'top';
    if (approach === 'bottom') {
        t.style.bottom = '4%';
        t.style.top = 'auto';
    } else {
        t.style.top = '4%';
        t.style.bottom = 'auto';
    }
    void t.offsetWidth;
    t.style.transition = '';
}

function moveCrossVehicleToStop(t) {
    if (!t) return;
    const approach = t.dataset.approach || 'top';
    if (approach === 'bottom') t.style.bottom = '27%';
    else t.style.top = '27%';
}

function moveCrossVehicleThrough(t) {
    if (!t) return;
    const approach = t.dataset.approach || 'top';
    if (approach === 'bottom') t.style.bottom = '82%';
    else t.style.top = '82%';
}

function animateFourWay(mode, idx, scenario, car) {
    const t = document.getElementById('junction-t-' + mode + '-' + idx);
    setTurnIndicator(car, scenario);

    // Keep the lesson visually simple and correct: two vehicles only.
    // Both approach their own stop lines. The vehicle entitled to proceed
    // moves first; the other remains stopped and follows afterwards.
    const order = String(scenario.arriving_order || '').toLowerCase();
    const subjectFirst = order.includes('first') || order === '1' || order.includes('1st');

    moveCrossVehicleToStop(t);
    if (t) t.classList.add('waiting');
    car.style.left = '38%'; // learner stops before entering the junction

    if (subjectFirst) {
        setTimeout(() => {
            if (scenario.action === 'go') car.style.left = '82%';
        }, 1400);
        setTimeout(() => { if (t) t.classList.remove('waiting'); moveCrossVehicleThrough(t); }, 4300);
    } else {
        // Another vehicle arrived first: it clears the junction before the learner proceeds.
        setTimeout(() => { if (t) t.classList.remove('waiting'); moveCrossVehicleThrough(t); }, 1400);
        setTimeout(() => {
            if (scenario.action === 'go') car.style.left = '82%';
        }, 4300);
    }
}

// ── STUDY MODE ANIMATIONS ──
function playStudyScenario(btn) {
    const si       = btn.dataset.si;
    const scenario = JSON.parse(btn.dataset.scenario);

    const car      = document.getElementById('car-study-' + si);
    const result   = document.getElementById('result-study-' + si);
    const status   = document.getElementById('status-study-' + si);
    const expl     = document.getElementById('explanation-study-' + si);
    const speedo   = document.getElementById('speedo-study-' + si);
    const oncoming = document.getElementById('oncoming-study-' + si);
    const animal   = document.getElementById('animal-study-' + si);
    const beam     = document.getElementById('beam-study-' + si);
    const evwrap   = document.getElementById('evwrap-study-' + si);
    const kid      = document.getElementById('kid-study-' + si);
    const kid2     = document.getElementById('kid2-study-' + si);
    resetJunctionCars('study', si);

    if (!car) return;

    btn.disabled   = true;
    btn.textContent = 'Playing...';
    result.classList.remove('show');
    expl.style.display = 'none';
    if (speedo) speedo.style.display = 'none';
    if (oncoming) oncoming.style.right = '5%';
    if (animal) animal.style.left = '58%';
    if (beam) beam.classList.remove('on');
    if (evwrap) { evwrap.style.transition = 'none'; evwrap.style.left = '8%'; evwrap.style.bottom = '14%'; void evwrap.offsetWidth; evwrap.style.transition = ''; }
    if (kid) kid.style.left = '50%';
    if (kid2) kid2.style.left = '56%';

    status.textContent = 'Car approaching...';
    // Reset instantly before replay so the car does not visibly drive backwards.
    car.style.transition = 'none';
    car.style.left = ('vehicle' in scenario) ? '38%' : '5%';
    car.style.bottom = ('vehicle' in scenario) ? '14%' : '28%';
    void car.offsetWidth;
    car.style.transition = '';

    setTimeout(() => {
        status.textContent = 'Action happening...';
        if (speedo) speedo.style.display = 'block';

        if ('location' in scenario) {
            car.style.left = '55%';
        } else if ('oncoming' in scenario) {
            // NATIS: signal before overtaking. Both vehicles start in the same
            // left-hand travelling lane; learner moves right only for the pass.
            setTurnIndicator(car, {action:'turn_right'});
            car.style.bottom = '52%';
            car.style.left = '48%';
            if (scenario.oncoming && oncoming) oncoming.style.right = '55%';
        } else if ('animal' in scenario) {
            if (animal) animal.style.left = '48%';
            car.style.left = scenario.slows ? '32%' : '55%';
        } else if ('vehicle' in scenario) {
            const emergencyActive = (scenario.sirens === 'on');
            if (emergencyActive) {
                // Same direction and SAME travelling lane at approach. The learner clears toward
                // the road edge and stops; the emergency vehicle then continues forward.
                // Learner moves left out of the emergency vehicle's path and stops.
                // Emergency vehicle remains to the learner's right and continues FORWARD.
                car.style.left = '48%';
                // A large sideways move toward the LEFT road edge so the pull-over is unmistakable.
                car.style.bottom = '0%';
                setTurnIndicator(car, {action:'turn_left'});
                if (evwrap) {
                    evwrap.style.bottom = '23%';
                    evwrap.style.left = '88%';
                }
            } else {
                car.style.left = '72%';
                car.style.bottom = '14%';
                if (evwrap) { evwrap.style.bottom = '14%'; evwrap.style.left = '35%'; }
            }
        } else if ('lights' in scenario) {
            if (beam) beam.classList.add('on');
            if (scenario.condition === 'oncoming_traffic' && oncoming) oncoming.style.right = '55%';
            car.style.left = '35%';
        } else if ('your_speed' in scenario) {
            if (kid) kid.style.left = '50%';
            if (kid2) kid2.style.left = '44%';
            car.style.left = scenario.time === 'children_present' ? '30%' : '70%';
        } else if ('label' in scenario) {
            car.style.left = '25%';
        } else if ('speed' in scenario) {
            car.style.left = '80%';
        } else if ('arriving_order' in scenario) {
            animateFourWay('study', si, scenario, car);
        } else if (scenario.car_passes || scenario.driver_stops === false) {
            car.style.left = '90%';
        } else {
            car.style.left = '40%';
        }
    }, 500);

    // Overtake: finish the pass and merge back (or meet the oncoming car)
    if ('oncoming' in scenario) {
        setTimeout(() => {
            car.style.left = scenario.oncoming ? '58%' : '85%';
            if (scenario.oncoming && oncoming) oncoming.style.right = '68%';
        }, 3500);
        setTimeout(() => {
            if (!scenario.oncoming) {
                setTurnIndicator(car, {action:'turn_left'});
                car.style.bottom = ('vehicle' in scenario) ? '14%' : '28%';
                car.style.left = '95%';
                setTimeout(() => setTurnIndicator(car, {}), 1700);
            } else {
                setTurnIndicator(car, {});
            }
        }, 6500);
    }

    setTimeout(() => {
        result.classList.add('show');
        status.textContent = scenario.is_correct ? 'Correct action!' : 'Incorrect action!';
        expl.style.display = 'block';
        btn.disabled   = false;
        btn.textContent = 'Replay';
    }, ('oncoming' in scenario) ? 9800 : (('arriving_order' in scenario) ? 6500 : 3600));
}

// ── QUIZ MODE ANIMATIONS (no result shown) ──
function playQuizScenario(btn) {
    const qi       = btn.dataset.qi;
    const scenario = JSON.parse(btn.dataset.scenario);

    const car      = document.getElementById('car-quiz-' + qi);
    const status   = document.getElementById('qstatus-' + qi);
    const speedo   = document.getElementById('speedo-quiz-' + qi);
    const oncoming = document.getElementById('oncoming-quiz-' + qi);
    const animal   = document.getElementById('animal-quiz-' + qi);
    const beam     = document.getElementById('beam-quiz-' + qi);
    const evwrap   = document.getElementById('evwrap-quiz-' + qi);
    const kid      = document.getElementById('kid-quiz-' + qi);
    const kid2     = document.getElementById('kid2-quiz-' + qi);
    resetJunctionCars('quiz', qi);

    if (!car) return;

    btn.disabled    = true;
    btn.textContent = 'Playing...';
    // Reset instantly before replay so the car does not visibly drive backwards.
    car.style.transition = 'none';
    car.style.left  = ('vehicle' in scenario) ? '38%' : '5%';
    car.style.bottom = ('vehicle' in scenario) ? '14%' : '28%';
    void car.offsetWidth;
    car.style.transition = '';
    status.textContent = 'Watch carefully...';
    if (speedo) speedo.style.display = 'none';
    if (oncoming) oncoming.style.right = '5%';
    if (animal) animal.style.left = '58%';
    if (beam) beam.classList.remove('on');
    if (evwrap) { evwrap.style.transition = 'none'; evwrap.style.left = '8%'; evwrap.style.bottom = '14%'; void evwrap.offsetWidth; evwrap.style.transition = ''; }
    if (kid) kid.style.left = '50%';
    if (kid2) kid2.style.left = '56%';

    setTimeout(() => {
        if (speedo) speedo.style.display = 'block';

        if ('location' in scenario) {
            car.style.left = '55%';
            status.textContent = 'The car parked here — was that correct?';
        } else if ('oncoming' in scenario) {
            setTurnIndicator(car, {action:'turn_right'});
            car.style.bottom = '52%';
            car.style.left = '48%';
            if (scenario.oncoming && oncoming) oncoming.style.right = '55%';
            status.textContent = 'The car is overtaking — was that correct?';
        } else if ('animal' in scenario) {
            if (animal) animal.style.left = '48%';
            car.style.left = scenario.slows ? '32%' : '55%';
            status.textContent = 'Was the driver\'s response to the animal correct?';
        } else if ('vehicle' in scenario) {
            const emergencyActive = (scenario.sirens === 'on');
            if (emergencyActive) {
                // Same direction and SAME travelling lane at approach. The learner clears toward
                // the road edge and stops; the emergency vehicle then continues forward.
                // Learner moves left out of the emergency vehicle's path and stops.
                // Emergency vehicle remains to the learner's right and continues FORWARD.
                car.style.left = '48%';
                // A large sideways move toward the LEFT road edge so the pull-over is unmistakable.
                car.style.bottom = '0%';
                setTurnIndicator(car, {action:'turn_left'});
                if (evwrap) {
                    evwrap.style.bottom = '23%';
                    evwrap.style.left = '88%';
                }
            } else {
                car.style.left = '72%';
                car.style.bottom = '14%';
                if (evwrap) { evwrap.style.bottom = '14%'; evwrap.style.left = '35%'; }
            }
            status.textContent = scenario.sirens === 'on' ? 'Emergency vehicle approaching — move left, slow down and stop.' : 'Emergency vehicle has no active siren — proceed normally and remain alert.';
        } else if ('lights' in scenario) {
            if (beam) beam.classList.add('on');
            if (scenario.condition === 'oncoming_traffic' && oncoming) oncoming.style.right = '55%';
            car.style.left = '35%';
            status.textContent = 'Was this the correct light setting?';
        } else if ('your_speed' in scenario) {
            if (kid) kid.style.left = '50%';
            if (kid2) kid2.style.left = '44%';
            car.style.left = scenario.time === 'children_present' ? '30%' : '70%';
            status.textContent = 'Was that speed correct for this school zone?';
        } else if ('label' in scenario) {
            car.style.left = '25%';
            status.textContent = 'Was this the correct action?';
        } else if ('speed' in scenario) {
            car.style.left = '80%';
            status.textContent = 'Was that speed correct for this sign?';
        } else if ('arriving_order' in scenario) {
            animateFourWay('quiz', qi, scenario, car);
            status.textContent = (scenario.action === 'go') ? 'The car proceeded — was that correct?' : 'The car waited — was that correct?';
        } else if (scenario.car_passes || scenario.driver_stops === false) {
            car.style.left = '90%';
            status.textContent = 'The car drove through — was that correct?';
        } else {
            car.style.left = '40%';
            status.textContent = 'The car stopped — was that correct?';
        }
    }, 500);

    if ('oncoming' in scenario) {
        setTimeout(() => {
            car.style.left = scenario.oncoming ? '58%' : '85%';
            if (scenario.oncoming && oncoming) oncoming.style.right = '68%';
        }, 3500);
        setTimeout(() => {
            if (!scenario.oncoming) {
                car.style.bottom = ('vehicle' in scenario) ? '14%' : '28%';
                car.style.left = '95%';
            }
        }, 6500);
    }

    setTimeout(() => {
        btn.disabled    = false;
        btn.textContent = 'Replay';
        status.textContent = 'Now select your answer below';
    }, ('oncoming' in scenario) ? 9800 : (('arriving_order' in scenario) ? 6500 : 3600));
}

// ── QUIZ ANSWER SELECTION ──
const selectedAnswers = {};

function selectAnswer(qi, val, btn) {
    // Clear previous selection for this question
    document.querySelectorAll('[data-qi="' + qi + '"]').forEach(b => {
        b.classList.remove('selected');
        b.style.borderColor = '#e0e0e0';
        b.style.background  = 'white';
        b.style.color       = '#333';
    });
    btn.classList.add('selected');
    btn.style.borderColor = '#3498db';
    btn.style.background  = '#f0f8ff';
    btn.style.color       = '#2c3e50';
    selectedAnswers[qi]   = val;
    document.getElementById('answer-' + qi).value = val;
}

function submitSimQuiz() {
    const total = <?= count($quiz_questions) ?>;
    if (Object.keys(selectedAnswers).length < total) {
        document.getElementById('quizValidationMsg').style.display = 'block';
        return;
    }
    document.getElementById('simQuizForm').submit();
}
</script>
<!-- TestMate simulation engine: top-down SVG scenes for every scenario type.
     Unknown types keep using the original animation functions above. -->
<script>
window.TM_SIM_TYPE = <?= json_encode((string)$simulation['animation_type']) ?>;
window.TM_SIM_TITLE = <?= json_encode((string)$simulation['title'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script>
/* ===== TestMate simulation engine (built in, no separate file needed) ===== */
/* ==========================================================================
   TestMate Simulation Engine (tm-sim-engine.js)
   Top-down animated driving scenes drawn in SVG.

   - Namibia drives on the LEFT. Scenes are top-down so lane position is
     unambiguous: on the horizontal road, eastbound traffic (moving right)
     uses the UPPER lane, westbound traffic (moving left) the LOWER lane.
     Roundabouts circulate clockwise.
   - The engine only replaces how a scenario is DRAWN and ANIMATED.
     Scenario data, quiz questions, scoring and the PHP NATIS rules in
     simulations.php are untouched. Unknown animation types fall back to
     the original animation code.
   ========================================================================== */
(function () {
'use strict';

const NS = 'http://www.w3.org/2000/svg';
const W = 960, H = 400;

/* Main horizontal road */
const ROAD = { top: 150, bottom: 250, mid: 200, up: 175, down: 225 };
/* Vertical crossing road (junctions) */
const CROSS = { left: 540, right: 640, mid: 590, nb: 565, sb: 615 };

/* ---------- small helpers ---------- */
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const lerp = (a, b, t) => a + (b - a) * t;
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const EASE = {
    linear: t => t,
    in: t => t * t * t,
    out: t => 1 - Math.pow(1 - t, 3),
    inout: t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2,
    soft: t => t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2,
};
const line = (x0, y0, x1, y1) => `M${x0} ${y0} L${x1} ${y1}`;

function svgEl(tag, attrs, html) {
    const el = document.createElementNS(NS, tag);
    if (attrs) for (const k in attrs) el.setAttribute(k, attrs[k]);
    if (html != null) el.innerHTML = html;
    return el;
}

/* ==========================================================================
   STAGE: one SVG scene with fixed layers
   ========================================================================== */
class Stage {
    constructor(host, opts) {
        this.host = host;
        this.opts = opts || {};
        this.svg = svgEl('svg', {
            viewBox: `0 0 ${W} ${H}`,
            class: 'tmx-svg',
            role: 'img',
            'aria-label': this.opts.title || 'Driving scenario animation',
            preserveAspectRatio: 'xMidYMid meet',
        });
        // Every scene gets its own ids. Several scenes share one page, and a
        // url(#id) reference resolves to the FIRST match in the document; if that
        // scene is hidden (pager), its patterns/filters stop rendering elsewhere.
        this.uid = 'u' + (++Stage.count) + '_';
        this.svg.innerHTML = DEFS.replace(/id="tmx/g, `id="${this.uid}tmx`);
        this.layers = {};
        ['ground', 'road', 'marks', 'props', 'dark', 'actors', 'fx', 'hud'].forEach(n => {
            this.layers[n] = svgEl('g', { class: 'tmx-' + n });
            this.svg.appendChild(this.layers[n]);
        });
        this.paths = svgEl('g', { fill: 'none', stroke: 'none', 'pointer-events': 'none' });
        this.svg.appendChild(this.paths);
        host.appendChild(this.svg);
        this.actors = [];
    }
    ref(markup) { return markup == null ? markup : String(markup).replace(/url\(#tmx/g, `url(#${this.uid}tmx`); }
    /* Re-point any url(#tmx...) references inside a node to this scene's ids. */
    fixRefs(root) {
        const nodes = [root, ...root.querySelectorAll('*')];
        for (const n of nodes) for (const a of ['fill', 'stroke', 'filter', 'mask', 'clip-path']) {
            const v = n.getAttribute && n.getAttribute(a);
            if (v && v.indexOf('url(#tmx') === 0) n.setAttribute(a, this.ref(v));
        }
    }
    add(layer, markup, attrs) {
        const g = svgEl('g', attrs || {}, this.ref(markup));
        this.layers[layer].appendChild(g);
        return g;
    }
    /* Actor = a movable group. Art is drawn facing +x (east). */
    actor(markup, x, y, rot, cls) {
        const g = svgEl('g', { class: 'tmx-actor ' + (cls || '') }, this.ref(markup));
        this.layers.actors.appendChild(g);
        const a = new Actor(this, g, x, y, rot || 0);
        this.actors.push(a);
        return a;
    }
    pathEl(d) {
        const p = svgEl('path', { d });
        this.paths.appendChild(p);
        return p;
    }
}

Stage.count = 0;

class Actor {
    constructor(stage, g, x, y, rot) {
        this.stage = stage;
        this.g = g;
        this.home = { x, y, rot };
        this.segs = [];
        this.wobble = null;
        this.set(x, y, rot);
    }
    set(x, y, rot) {
        this.x = x; this.y = y; this.rot = rot;
        this.g.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${rot.toFixed(1)})`);
    }
    /* Follow an SVG path between t0 and t1 (seconds). */
    path(t0, t1, d, ease, opts) {
        const el = this.stage.pathEl(d);
        this.segs.push({ t0, t1, el, len: el.getTotalLength(), ease: EASE[ease || 'inout'] || EASE.inout, keepRot: opts && opts.keepRot, rotOffset: (opts && opts.rotOffset) || 0 });
        this.segs.sort((a, b) => a.t0 - b.t0);
        return this;
    }
    /* Straight move from current end point (or given start) to x,y. */
    to(t0, t1, x, y, ease, opts) {
        const from = this.endPoint();
        return this.path(t0, t1, line(from.x, from.y, x, y), ease, opts);
    }
    endPoint() {
        if (!this.segs.length) return { x: this.home.x, y: this.home.y };
        const s = this.segs[this.segs.length - 1];
        const p = s.el.getPointAtLength(s.len);
        return { x: p.x, y: p.y };
    }
    poseAt(t) {
        let seg = null;
        for (const s of this.segs) if (t >= s.t0) seg = s;
        let x = this.home.x, y = this.home.y, rot = this.home.rot;
        if (!seg && this.segs.length) seg = null;
        if (seg) {
            const f = seg.t1 > seg.t0 ? clamp((t - seg.t0) / (seg.t1 - seg.t0), 0, 1) : 1;
            const L = seg.ease(f) * seg.len;
            const p = seg.el.getPointAtLength(L);
            x = p.x; y = p.y;
            if (seg.keepRot != null && seg.keepRot !== true) {
                rot = seg.keepRot;
            } else if (seg.keepRot === true) {
                rot = this.lastRot != null ? this.lastRot : rot;
            } else if (seg.len > 0.5) {
                const a = seg.el.getPointAtLength(Math.max(0, L - 1));
                const b = seg.el.getPointAtLength(Math.min(seg.len, L + 1));
                rot = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI + seg.rotOffset;
            }
        }
        if (this.wobble) rot += this.wobble(t) || 0;
        this.lastRot = rot;
        return { x, y, rot };
    }
    cls(name, on) { this.g.classList.toggle(name, on !== false); }
}

/* ==========================================================================
   TIMELINE: positions every actor each frame and fires timed events
   ========================================================================== */
class Timeline {
    constructor(stage) {
        this.stage = stage;
        this.events = [];
        this.frames = [];
        this.duration = 0;
    }
    at(t, fn) { this.events.push({ t, fn, done: false }); this.duration = Math.max(this.duration, t); return this; }
    each(fn) { this.frames.push(fn); return this; }
    end(t) { this.duration = Math.max(this.duration, t); return this; }
    seek(t) {
        for (const a of this.stage.actors) { const p = a.poseAt(t); a.set(p.x, p.y, p.rot); }
        for (const f of this.frames) f(t);
    }
    play() {
        for (const a of this.stage.actors) for (const s of a.segs) this.duration = Math.max(this.duration, s.t1);
        this.duration += 0.4;
        const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        return new Promise(resolve => {
            const start = performance.now();
            const step = now => {
                if (this.cancelled) return;
                const t = reduce ? this.duration : (now - start) / 1000;
                for (const e of this.events) if (!e.done && t >= e.t) { e.done = true; try { e.fn(); } catch (err) { console.error(err); } }
                this.seek(Math.min(t, this.duration));
                if (t >= this.duration) resolve(); else requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        });
    }
    cancel() { this.cancelled = true; }
}

/* Expose for the other engine parts (concatenated into one file). */
const TMX = window.TMX = window.TMX || {};
Object.assign(TMX, { NS, W, H, ROAD, CROSS, clamp, lerp, esc, EASE, line, svgEl, Stage, Actor, Timeline });
/* ==========================================================================
   ART LIBRARY - original flat vector art (top-down), no external assets
   Vehicles face +x. The vehicle's LEFT side is -y.
   ========================================================================== */
const DEFS = `
<defs>
  <linearGradient id="tmxSky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e9d9b4"/><stop offset="1" stop-color="#d7bf8c"/></linearGradient>
  <pattern id="tmxSand" width="60" height="60" patternUnits="userSpaceOnUse">
    <rect width="60" height="60" fill="#dcc591"/>
    <circle cx="8" cy="12" r="1.3" fill="#c6ab72"/><circle cx="41" cy="7" r="1" fill="#c9b07a"/>
    <circle cx="28" cy="38" r="1.4" fill="#c4a76d"/><circle cx="52" cy="49" r="1" fill="#cbb27c"/>
    <path d="M14 50 l2 -6 l2 6 M45 26 l1.5 -5 l1.5 5" stroke="#a9a160" stroke-width="1.1" fill="none" stroke-linecap="round"/>
  </pattern>
  <pattern id="tmxGrass" width="60" height="60" patternUnits="userSpaceOnUse">
    <rect width="60" height="60" fill="#b9c27b"/>
    <path d="M10 20 l2 -6 l2 6 M40 45 l2 -6 l2 6 M30 10 l1.5 -5 l1.5 5 M50 18 l1.5 -5 l1.5 5" stroke="#8f9b55" stroke-width="1.2" fill="none" stroke-linecap="round"/>
    <circle cx="20" cy="48" r="1.4" fill="#a3ac64"/>
  </pattern>
  <pattern id="tmxUrban" width="40" height="40" patternUnits="userSpaceOnUse">
    <rect width="40" height="40" fill="#cfd8d3"/><path d="M0 0H40M0 0V40" stroke="#c2ccc7" stroke-width="1.5"/>
  </pattern>
  <pattern id="tmxAsphalt" width="80" height="80" patternUnits="userSpaceOnUse">
    <rect width="80" height="80" fill="#464b51"/>
    <circle cx="12" cy="18" r=".9" fill="#53595f"/><circle cx="50" cy="9" r=".8" fill="#3c4146"/>
    <circle cx="66" cy="44" r="1" fill="#555b61"/><circle cx="27" cy="61" r=".8" fill="#3b4045"/>
    <circle cx="70" cy="72" r=".7" fill="#585e64"/><circle cx="38" cy="33" r=".7" fill="#3d4247"/>
  </pattern>
  <pattern id="tmxGravel" width="50" height="50" patternUnits="userSpaceOnUse">
    <rect width="50" height="50" fill="#c49a68"/>
    <circle cx="7" cy="9" r="1.6" fill="#a97f52"/><circle cx="31" cy="5" r="1.2" fill="#d8b384"/>
    <circle cx="22" cy="27" r="1.8" fill="#a47a4e"/><circle cx="44" cy="31" r="1.3" fill="#d5ae7c"/>
    <circle cx="12" cy="42" r="1.2" fill="#b0865a"/><circle cx="38" cy="46" r="1.6" fill="#9f764b"/>
  </pattern>
  <pattern id="tmxRain" width="34" height="34" patternUnits="userSpaceOnUse">
    <path d="M6 0 l-4 12 M22 14 l-4 12 M30 4 l-3 9 M14 22 l-3 9" stroke="#dff0ff" stroke-width="1.4" stroke-linecap="round" opacity=".75"/>
  </pattern>
  <linearGradient id="tmxBeam" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff6c4" stop-opacity=".95"/><stop offset="1" stop-color="#fff6c4" stop-opacity="0"/></linearGradient>
  <linearGradient id="tmxBeamFog" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ffd88a" stop-opacity=".95"/><stop offset="1" stop-color="#ffd88a" stop-opacity="0"/></linearGradient>
  <radialGradient id="tmxGlow"><stop offset="0" stop-color="#fff" stop-opacity=".95"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
  <radialGradient id="tmxDust"><stop offset="0" stop-color="#e2c79a" stop-opacity=".95"/><stop offset=".7" stop-color="#dcc095" stop-opacity=".55"/><stop offset="1" stop-color="#d9bd90" stop-opacity="0"/></radialGradient>
  <filter id="tmxShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="2" dy="3" stdDeviation="2" flood-color="#000" flood-opacity=".28"/></filter>
</defs>`;

const ART = {};

/* ---------- vehicles ---------- */
function lights(len, wid) {
    const fx = len / 2, rx = -len / 2, hy = wid / 2;
    return `
    <g class="beam beam-high"><path d="M${fx - 2} ${-hy + 6} L${fx + 300} ${-hy - 60} L${fx + 300} ${hy + 60} L${fx - 2} ${hy - 6}Z" fill="url(#tmxBeam)" opacity=".55"/></g>
    <g class="beam beam-low"><path d="M${fx - 2} ${-hy + 6} L${fx + 140} ${-hy - 18} L${fx + 140} ${hy + 18} L${fx - 2} ${hy - 6}Z" fill="url(#tmxBeam)" opacity=".6"/></g>
    <g class="beam beam-fog"><path d="M${fx - 2} ${-hy + 3} L${fx + 80} ${-hy - 34} L${fx + 80} ${hy + 34} L${fx - 2} ${hy - 3}Z" fill="url(#tmxBeamFog)" opacity=".65"/></g>
    <rect class="tail" x="${rx - .5}" y="${-hy + 3}" width="3" height="6" rx="1"/>
    <rect class="tail" x="${rx - .5}" y="${hy - 9}" width="3" height="6" rx="1"/>
    <circle class="ind ind-l" cx="${fx - 3}" cy="${-hy - .5}" r="3"/><circle class="ind ind-l" cx="${rx + 3}" cy="${-hy - .5}" r="3"/>
    <circle class="ind ind-r" cx="${fx - 3}" cy="${hy + .5}" r="3"/><circle class="ind ind-r" cx="${rx + 3}" cy="${hy + .5}" r="3"/>`;
}
function shadow(len, wid) {
    return `<rect x="${-len / 2 + 3}" y="${-wid / 2 + 4}" width="${len}" height="${wid}" rx="${wid / 3}" fill="#000" opacity=".22"/>`;
}

ART.car = (color = '#1e6fd9', extra = '') => {
    const L = 70, Wd = 32;
    return `${shadow(L, Wd)}${lights(L, Wd)}
    <rect x="-35" y="-16" width="70" height="32" rx="10" fill="${color}"/>
    <rect x="-33" y="-14" width="66" height="28" rx="9" fill="#fff" opacity=".08"/>
    <path d="M7 -13 L19 -11 Q24 0 19 11 L7 13 Q10 0 7 -13Z" fill="#20303f"/>
    <path d="M-23 -12 L-15 -12.5 Q-17 0 -15 12.5 L-23 12 Q-26 0 -23 -12Z" fill="#20303f"/>
    <rect x="-15" y="-12" width="22" height="24" rx="4" fill="${color}"/>
    <rect x="-13" y="-10" width="18" height="20" rx="3" fill="#fff" opacity=".12"/>
    <rect x="6" y="-19" width="5" height="3.5" rx="1.5" fill="${color}"/><rect x="6" y="15.5" width="5" height="3.5" rx="1.5" fill="${color}"/>
    <rect x="31.5" y="-13" width="3" height="6" rx="1.2" fill="#fff8d0"/><rect x="31.5" y="7" width="3" height="6" rx="1.2" fill="#fff8d0"/>
    ${extra}`;
};
ART.learner = () => ART.car('#147df5',
    `<rect x="-6" y="-5.5" width="11" height="11" rx="1.5" fill="#fff" stroke="#e11d2a" stroke-width="1.2"/><text x="-.5" y="3.6" font-size="9" font-weight="800" fill="#e11d2a" text-anchor="middle" font-family="Arial">L</text>`);
ART.taxi = () => ART.car('#f2f2f2', `<rect x="-10" y="-5" width="13" height="10" rx="2" fill="#f5c518"/><path d="M-35 -2 H35" stroke="#1d7f3b" stroke-width="2.4"/>`);
ART.sedan = c => ART.car(c || '#c0392b');

ART.bakkie = (color = '#e8e8e8', load = '') => {
    return `${shadow(76, 33)}${lights(76, 33)}
    <rect x="-38" y="-16.5" width="76" height="33" rx="7" fill="${color}"/>
    <rect x="-36" y="-13" width="36" height="26" rx="3" fill="#7b6d5a"/>
    <path d="M-36 -8 H0 M-36 0 H0 M-36 8 H0" stroke="#6a5d4b" stroke-width="1.2"/>
    <path d="M14 -13 L25 -11 Q29 0 25 11 L14 13 Q17 0 14 -13Z" fill="#20303f"/>
    <rect x="2" y="-12" width="13" height="24" rx="3" fill="${color}"/>
    <rect x="35" y="-13" width="3" height="6" rx="1.2" fill="#fff8d0"/><rect x="35" y="7" width="3" height="6" rx="1.2" fill="#fff8d0"/>
    ${load}`;
};

ART.truck = (color = '#d35400') => {
    return `<rect x="-86" y="-15" width="178" height="38" rx="5" fill="#000" opacity=".22"/>${lights(176, 36)}
    <rect x="-88" y="-18" width="128" height="36" rx="3" fill="#eef0f2"/>
    <path d="M-80 -18 V18 M-60 -18 V18 M-40 -18 V18 M-20 -18 V18 M0 -18 V18 M20 -18 V18" stroke="#d3d8dc" stroke-width="1.5"/>
    <rect x="-88" y="-18" width="128" height="36" rx="3" fill="none" stroke="#c8ced3" stroke-width="2"/>
    <text x="-24" y="4" font-size="11" font-weight="800" fill="#9aa4ad" text-anchor="middle" font-family="Arial">FREIGHT</text>
    <rect x="42" y="-17" width="46" height="34" rx="6" fill="${color}"/>
    <path d="M70 -14 L82 -12 Q86 0 82 12 L70 14 Q73 0 70 -14Z" fill="#20303f"/>
    <rect x="86" y="-14" width="3" height="7" rx="1.2" fill="#fff8d0"/><rect x="86" y="7" width="3" height="7" rx="1.2" fill="#fff8d0"/>`;
};

ART.emergency = (kind) => {
    const body = kind === 'fire_truck' ? '#d62828' : '#ffffff';
    const stripe = kind === 'police' ? '#1d4ed8' : (kind === 'fire_truck' ? '#f5c518' : '#e63946');
    const len = kind === 'fire_truck' ? 104 : 80;
    const hl = len / 2;
    let deco = '';
    if (kind === 'ambulance') deco = `<rect x="-14" y="-4" width="14" height="8" fill="#e63946"/><rect x="-11" y="-7" width="8" height="14" fill="#e63946"/>`;
    if (kind === 'fire_truck') deco = `<path d="M-48 -6 H24 M-48 6 H24 M-40 -6 V6 M-28 -6 V6 M-16 -6 V6 M-4 -6 V6 M8 -6 V6 M20 -6 V6" stroke="#cfd4d8" stroke-width="2.2"/>`;
    if (kind === 'police') deco = `<text x="-12" y="4" font-size="10" font-weight="900" fill="#1d4ed8" text-anchor="middle" font-family="Arial">NAMPOL</text>`;
    return `${shadow(len, 34)}${lights(len, 34)}
    <rect x="${-hl}" y="-17" width="${len}" height="34" rx="6" fill="${body}" stroke="#cfd6dc" stroke-width="1"/>
    <path d="M${-hl} -17 H${hl - 8} M${-hl} 17 H${hl - 8}" stroke="${stripe}" stroke-width="4"/>
    ${deco}
    <path d="M${hl - 20} -13 L${hl - 8} -11 Q${hl - 4} 0 ${hl - 8} 11 L${hl - 20} 13 Q${hl - 17} 0 ${hl - 20} -13Z" fill="#20303f"/>
    <g class="siren"><rect x="${hl - 30}" y="-12" width="6" height="11" rx="2" class="siren-r"/><rect x="${hl - 30}" y="1" width="6" height="11" rx="2" class="siren-b"/></g>
    <rect x="${hl - 3}" y="-13" width="3" height="6" rx="1.2" fill="#fff8d0"/><rect x="${hl - 3}" y="7" width="3" height="6" rx="1.2" fill="#fff8d0"/>
    <g class="siren-waves"><circle cx="${hl - 27}" cy="0" r="22" fill="none" stroke="#e63946" stroke-width="2"/><circle cx="${hl - 27}" cy="0" r="22" fill="none" stroke="#2563eb" stroke-width="2"/></g>`;
};

ART.train = (wagons = 4) => {
    let s = '';
    for (let i = 0; i < wagons; i++) {
        const x = -110 - i * 92;
        s += `<rect x="${x - 2}" y="-16" width="88" height="38" rx="4" fill="#000" opacity=".2"/>
              <rect x="${x - 4}" y="-19" width="88" height="38" rx="4" fill="${i % 2 ? '#6f7b85' : '#8a5a3b'}"/>
              <path d="M${x + 6} -19 V19 M${x + 26} -19 V19 M${x + 46} -19 V19 M${x + 66} -19 V19" stroke="#000" stroke-opacity=".18" stroke-width="2"/>`;
    }
    return `${s}
    <rect x="-108" y="-14" width="120" height="38" rx="6" fill="#000" opacity=".22"/>
    <rect x="-110" y="-20" width="122" height="40" rx="7" fill="#f5c518"/>
    <rect x="-110" y="-20" width="122" height="12" rx="6" fill="#1e40af"/>
    <rect x="-80" y="-9" width="60" height="18" rx="3" fill="#2b3a48"/>
    <rect x="-6" y="-15" width="14" height="30" rx="3" fill="#20303f"/>
    <circle cx="10" cy="-12" r="3" fill="#fff8d0"/><circle cx="10" cy="12" r="3" fill="#fff8d0"/>
    <text x="-60" y="18" font-size="7" font-weight="800" fill="#1e3a8a" font-family="Arial">TRANSNAMIB</text>`;
};

/* ---------- people & animals (face +x) ---------- */
ART.person = (shirt = '#e76f51', skin = '#8d5a3b', kid = false) => {
    const k = kid ? .78 : 1;
    return `<g transform="scale(${k})" class="walker">
    <ellipse cx="2" cy="3" rx="9" ry="12" fill="#000" opacity=".18"/>
    <g class="leg-a"><rect x="-2" y="-7" width="10" height="5" rx="2.5" fill="#2d3a4a"/></g>
    <g class="leg-b"><rect x="-2" y="2" width="10" height="5" rx="2.5" fill="#2d3a4a"/></g>
    <ellipse cx="0" cy="0" rx="7" ry="11" fill="${shirt}"/>
    ${kid ? '<rect x="-10" y="-6" width="6" height="12" rx="2" fill="#f5c518"/>' : ''}
    <circle cx="1" cy="0" r="5.4" fill="${skin}"/>
    <path d="M-2 -4 Q1 -6 4 -3" stroke="#1d1d1d" stroke-width="2.2" fill="none"/></g>`;
};

ART.kudu = () => `<g class="animal">
    <ellipse cx="2" cy="4" rx="26" ry="12" fill="#000" opacity=".2"/>
    <ellipse cx="-2" cy="0" rx="25" ry="10" fill="#a07850"/>
    <path d="M-14 -9 V9 M-6 -10 V10 M2 -10 V10 M10 -9 V9" stroke="#f3e7d3" stroke-width="1.6"/>
    <path d="M-27 0 l-7 -2 l1 4Z" fill="#6b4f33"/>
    <ellipse cx="25" cy="0" rx="9" ry="6" fill="#8a6644"/>
    <path d="M27 -4 q8 -8 3 -15 q-4 -6 3 -11 M27 4 q8 8 3 15 q-4 6 3 11" stroke="#4a3626" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <circle cx="32" cy="0" r="2" fill="#2b1d12"/></g>`;
ART.cow = () => `<g class="animal">
    <ellipse cx="2" cy="4" rx="27" ry="13" fill="#000" opacity=".2"/>
    <ellipse cx="-2" cy="0" rx="26" ry="12" fill="#f4efe6"/>
    <path d="M-16 -8 q8 -4 12 3 q-6 8 -14 3Z M6 -2 q8 -6 12 2 q-4 8 -12 5Z" fill="#3a2a20"/>
    <ellipse cx="26" cy="0" rx="9" ry="8" fill="#5b4334"/>
    <path d="M28 -7 l6 -6 M28 7 l6 6" stroke="#e9dfcd" stroke-width="2.4" stroke-linecap="round"/>
    <path d="M-28 0 l-8 3" stroke="#3a2a20" stroke-width="2"/></g>`;
ART.donkey = () => `<g class="animal">
    <ellipse cx="2" cy="4" rx="22" ry="10" fill="#000" opacity=".2"/>
    <ellipse cx="-2" cy="0" rx="21" ry="9" fill="#8f8a84"/>
    <path d="M-20 0 H18 M0 -9 V9" stroke="#4b4743" stroke-width="2.2"/>
    <ellipse cx="22" cy="0" rx="8" ry="6" fill="#7d7872"/>
    <path d="M22 -5 l4 -9 M22 5 l4 9" stroke="#5c5852" stroke-width="3" stroke-linecap="round"/></g>`;
ART.warthog = () => `<g class="animal">
    <ellipse cx="1" cy="3" rx="17" ry="9" fill="#000" opacity=".2"/>
    <ellipse cx="-2" cy="0" rx="16" ry="8" fill="#6d5a4a"/>
    <path d="M-12 -2 H8" stroke="#3d3128" stroke-width="3"/>
    <ellipse cx="15" cy="0" rx="6" ry="5" fill="#5e4c3e"/>
    <path d="M18 -3 q4 -3 3 -6 M18 3 q4 3 3 6" stroke="#f1ebdf" stroke-width="1.6" fill="none"/></g>`;
ART.animal = s => (ART[s] || ART.kudu)();

/* ---------- scenery ---------- */
ART.acacia = (r = 26) => `<g filter="url(#tmxShadow)">
    <ellipse cx="0" cy="0" rx="${r}" ry="${r * .78}" fill="#5f7a35"/>
    <circle cx="${-r * .35}" cy="${-r * .2}" r="${r * .5}" fill="#6f8b3d"/>
    <circle cx="${r * .3}" cy="${r * .15}" r="${r * .45}" fill="#6a8539"/>
    <circle cx="${-r * .1}" cy="${-r * .35}" r="${r * .3}" fill="#88a24e"/></g>`;
ART.bush = (r = 10) => `<circle cx="1.5" cy="2.5" r="${r}" fill="#000" opacity=".16"/><circle r="${r}" fill="#7d8f45"/><circle cx="${-r * .3}" cy="${-r * .3}" r="${r * .45}" fill="#97a95a"/>`;
ART.rock = (r = 9) => `<ellipse cx="2" cy="3" rx="${r}" ry="${r * .7}" fill="#000" opacity=".15"/><ellipse rx="${r}" ry="${r * .72}" fill="#a28e7b"/><ellipse cx="-2" cy="-2" rx="${r * .5}" ry="${r * .3}" fill="#bba895"/>`;
ART.mound = () => `<circle cx="2" cy="3" r="9" fill="#000" opacity=".15"/><circle r="9" fill="#b9733f"/><circle cx="-2" cy="-2" r="4" fill="#cf8a52"/>`;
ART.house = (w = 70, h = 52, roof = '#b5523b') => `<g filter="url(#tmxShadow)">
    <rect x="${-w / 2}" y="${-h / 2}" width="${w}" height="${h}" rx="2" fill="${roof}"/>
    <path d="M${-w / 2} 0 H${w / 2}" stroke="#000" stroke-opacity=".22" stroke-width="2"/>
    <path d="M${-w / 2} ${-h / 2} L${-w / 2 + 10} 0 L${-w / 2} ${h / 2} M${w / 2} ${-h / 2} L${w / 2 - 10} 0 L${w / 2} ${h / 2}" stroke="#000" stroke-opacity=".12" stroke-width="1.5" fill="none"/></g>`;
ART.school = () => `<g filter="url(#tmxShadow)">
    <rect x="-90" y="-34" width="180" height="56" rx="3" fill="#2f6f9f"/>
    <rect x="-90" y="-34" width="180" height="10" fill="#245a82"/>
    <text x="0" y="6" font-size="16" font-weight="900" fill="#fff" text-anchor="middle" font-family="Arial" letter-spacing="3">SCHOOL</text></g>
    <rect x="-70" y="24" width="140" height="12" fill="#9fb3a3"/>`;

/* ---------- road signs (front-facing, standing at the roadside) ---------- */
function post(h = 22) { return `<rect x="-2" y="0" width="4" height="${h}" fill="#7d858c"/><ellipse cx="0" cy="${h}" rx="7" ry="2.5" fill="#000" opacity=".25"/>`; }
ART.signStop = () => `<g filter="url(#tmxShadow)">${post()}<path d="M-9 -22 H9 L21 -10 V8 L9 20 H-9 L-21 8 V-10Z" transform="translate(0 -22) scale(.95)" fill="#d62828" stroke="#fff" stroke-width="2.5"/><text x="0" y="-19" font-size="10" font-weight="900" fill="#fff" text-anchor="middle" font-family="Arial">STOP</text></g>`;
ART.signYield = () => `<g filter="url(#tmxShadow)">${post()}<path d="M-22 -44 H22 L0 -4Z" fill="#fff" stroke="#d62828" stroke-width="6" stroke-linejoin="round"/></g>`;
ART.signSpeed = n => `<g filter="url(#tmxShadow)">${post()}<circle cx="0" cy="-22" r="21" fill="#fff" stroke="#d62828" stroke-width="5"/><text x="0" y="-15.5" font-size="${String(n).length > 2 ? 15 : 18}" font-weight="900" fill="#111" text-anchor="middle" font-family="Arial">${esc(n)}</text></g>`;
ART.signWarn = sym => `<g filter="url(#tmxShadow)">${post()}<path d="M0 -48 L24 -4 H-24Z" fill="#f5c518" stroke="#111" stroke-width="3.5" stroke-linejoin="round"/>${sym}</g>`;
ART.symKudu = `<path d="M-9 -12 q4 -3 9 -2 l5 -6 q1 -3 3 -2 l-2 4 l2 3 l-3 1 l-1 3 q-2 6 -1 8 h-2 l-1 -5 h-6 l-2 5 h-2 q0 -4 1 -7Z" fill="#111"/>`;
ART.symCow = `<path d="M-11 -9 h17 l4 -3 h3 l-1 4 l-3 2 v6 h-2 v-4 h-12 v4 h-2 v-5 l-4 -1Z" fill="#111"/>`;
ART.symKids = `<circle cx="-5" cy="-21" r="2.6" fill="#111"/><circle cx="5" cy="-19" r="2.3" fill="#111"/><path d="M-5 -18 l-3 9 h2 l2 -5 l2 5 h2 l-3 -9Z M5 -16 l-3 8 h2 l2 -4 l2 4 h2 l-3 -8Z" fill="#111"/>`;
ART.symTrain = `<path d="M-11 -10 h18 v-6 h4 v6 h-1 v6 h-21Z" fill="#111"/><circle cx="-6" cy="-3" r="2.4" fill="#111"/><circle cx="4" cy="-3" r="2.4" fill="#111"/>`;
ART.symBend = `<path d="M-6 -6 q0 -8 6 -10 q6 -2 6 -8" stroke="#111" stroke-width="3.6" fill="none"/><path d="M4 -27 l5 4 l-6 2Z" fill="#111"/>`;
ART.symPed = `<circle cx="1" cy="-25" r="2.6" fill="#111"/><path d="M0 -22 l-4 9 l-5 4 M0 -22 l3 6 l4 3 M-1 -15 l5 8" stroke="#111" stroke-width="2.6" fill="none" stroke-linecap="round"/><path d="M-14 -7 H14" stroke="#111" stroke-width="2"/>`;
ART.symRound = `<circle cx="0" cy="-15" r="7" fill="none" stroke="#111" stroke-width="2.8"/><path d="M6 -21 l4 -1 l-1 4 M-6 -9 l-4 1 l1 -4" stroke="#111" stroke-width="2.4" fill="none"/>`;
ART.symSlippery = `<path d="M-8 -12 h14 l3 -4 h-18Z" fill="#111"/><path d="M-9 -8 q4 3 0 6 q-4 3 0 6 M3 -8 q4 3 0 6 q-4 3 0 6" stroke="#111" stroke-width="1.8" fill="none"/>`;
ART.signRoundabout = () => `<g filter="url(#tmxShadow)">${post()}<circle cx="0" cy="-22" r="21" fill="#1d5fbf" stroke="#fff" stroke-width="2.5"/>
    <path d="M-9 -27 A10 10 0 0 1 9 -27 M9 -17 A10 10 0 0 1 -9 -17" stroke="#fff" stroke-width="3.2" fill="none"/>
    <path d="M10 -31 l2 6 l-6 -1Z M-10 -13 l-2 -6 l6 1Z" fill="#fff"/></g>`;
ART.signNoOvertake = () => `<g filter="url(#tmxShadow)">${post()}<circle cx="0" cy="-22" r="21" fill="#fff" stroke="#d62828" stroke-width="5"/>
    <rect x="-13" y="-27" width="11" height="9" rx="2" fill="#d62828"/><rect x="2" y="-27" width="11" height="9" rx="2" fill="#111"/></g>`;
ART.signRail = () => `<g filter="url(#tmxShadow)">${post(26)}<path d="M-20 -40 L20 -12 M20 -40 L-20 -12" stroke="#fff" stroke-width="9"/><path d="M-20 -40 L20 -12 M20 -40 L-20 -12" stroke="#d62828" stroke-width="5" stroke-dasharray="7 5"/></g>`;
ART.railLights = () => `<g filter="url(#tmxShadow)">${post(26)}<rect x="-22" y="-14" width="44" height="18" rx="9" fill="#1f2328"/>
    <circle class="rl rl-a" cx="-11" cy="-5" r="6"/><circle class="rl rl-b" cx="11" cy="-5" r="6"/></g>`;
ART.trafficLight = () => `<g filter="url(#tmxShadow)">${post(26)}<rect x="-12" y="-58" width="24" height="62" rx="7" fill="#1f2328" stroke="#3a4048" stroke-width="2"/>
    <circle class="tl tl-red" cx="0" cy="-45" r="7.5"/><circle class="tl tl-amber" cx="0" cy="-27" r="7.5"/><circle class="tl tl-green" cx="0" cy="-9" r="7.5"/></g>`;

Object.assign(TMX, { DEFS, ART });
/* ==========================================================================
   SCENE TOOLKIT
   ========================================================================== */

/* Deterministic random so a scenario always looks the same. */
function rng(seed) {
    let a = seed >>> 0;
    return () => {
        a |= 0; a = a + 0x6D2B79F5 | 0;
        let t = Math.imul(a ^ a >>> 15, 1 | a);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}
function hash(str) { let h = 2166136261; for (const c of String(str)) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; }

class Scene {
    constructor(stage, opts) {
        this.s = stage;
        this.opts = opts || {};
        this.rand = rng(hash(opts.seed || 'tmx'));
        this.keepOut = [];
    }
    /* ---------- ground ---------- */
    ground(theme) {
        this.theme = theme || 'savanna';
        const fill = { savanna: 'url(#tmxSand)', grass: 'url(#tmxGrass)', urban: 'url(#tmxUrban)' }[this.theme] || 'url(#tmxSand)';
        this.s.add('ground', `<rect width="${W}" height="${H}" fill="${fill}"/>`);
        return this;
    }
    block(x, y, w, h) { this.keepOut.push({ x: x - 14, y: y - 14, w: w + 28, h: h + 28 }); return this; }
    free(x, y, r) { return !this.keepOut.some(k => x + r > k.x && x - r < k.x + k.w && y + r > k.y && y - r < k.y + k.h); }
    scatter(count, kinds) {
        kinds = kinds || (this.theme === 'urban' ? ['bush', 'tree'] : ['acacia', 'bush', 'bush', 'rock', 'mound']);
        let placed = 0, tries = 0;
        while (placed < count && tries < count * 40) {
            tries++;
            const x = this.rand() * W, y = this.rand() * H;
            const kind = kinds[Math.floor(this.rand() * kinds.length)];
            const r = kind === 'acacia' ? 18 + this.rand() * 14 : kind === 'tree' ? 16 + this.rand() * 8 : 7 + this.rand() * 5;
            if (!this.free(x, y, r + 4)) continue;
            const art = kind === 'acacia' || kind === 'tree' ? ART.acacia(r) : kind === 'rock' ? ART.rock(r) : kind === 'mound' ? ART.mound() : ART.bush(r);
            this.s.add('props', art, { transform: `translate(${x.toFixed(0)} ${y.toFixed(0)})` });
            this.keepOut.push({ x: x - r, y: y - r, w: r * 2, h: r * 2 });
            placed++;
        }
        return this;
    }
    houses(list) {
        list.forEach(([x, y, w, h, c]) => {
            this.s.add('props', ART.house(w, h, c), { transform: `translate(${x} ${y})` });
            this.block(x - w / 2, y - h / 2, w, h);
        });
        return this;
    }
    prop(markup, x, y, layer) {
        this.s.add(layer || 'props', markup, { transform: `translate(${x} ${y})` });
        this.block(x - 24, y - 50, 48, 70);
        return this;
    }

    /* ---------- main horizontal road ---------- */
    hRoad(o) {
        o = Object.assign({ surface: 'asphalt', centre: 'broken', edges: 'yellow', kerb: false, from: 0, to: W }, o || {});
        const { top, bottom, mid } = ROAD;
        const w = o.to - o.from;
        this.block(o.from, top - (o.kerb ? 22 : 8), w, bottom - top + (o.kerb ? 44 : 16));
        if (o.kerb) {
            this.s.add('road', `<rect x="${o.from}" y="${top - 22}" width="${w}" height="22" fill="#d9ddd9"/><rect x="${o.from}" y="${bottom}" width="${w}" height="22" fill="#d9ddd9"/>
                <path d="M${o.from} ${top - 1} H${o.to} M${o.from} ${bottom + 1} H${o.to}" stroke="#a9b0b4" stroke-width="3"/>`);
        } else if (o.surface === 'asphalt') {
            this.s.add('road', `<rect x="${o.from}" y="${top - 9}" width="${w}" height="${bottom - top + 18}" fill="#c9a877" opacity=".75"/>`);
        }
        const fill = o.surface === 'gravel' ? 'url(#tmxGravel)' : 'url(#tmxAsphalt)';
        this.s.add('road', `<rect x="${o.from}" y="${top}" width="${w}" height="${bottom - top}" fill="${fill}"/>`);
        if (o.surface === 'gravel') {
            this.s.add('marks', `<path d="M${o.from} ${ROAD.up - 9} H${o.to} M${o.from} ${ROAD.up + 9} H${o.to} M${o.from} ${ROAD.down - 9} H${o.to} M${o.from} ${ROAD.down + 9} H${o.to}" stroke="#9c7347" stroke-width="5" opacity=".45"/>`);
            return this;
        }
        if (o.edges === 'yellow') this.s.add('marks', `<path d="M${o.from} ${top + 4} H${o.to} M${o.from} ${bottom - 4} H${o.to}" stroke="#f2c230" stroke-width="3"/>`);
        if (o.centre === 'broken') this.s.add('marks', `<path d="M${o.from} ${mid} H${o.to}" stroke="#f4f4ef" stroke-width="3.2" stroke-dasharray="30 22"/>`);
        if (o.centre === 'solid') this.s.add('marks', `<path d="M${o.from} ${mid - 3} H${o.to} M${o.from} ${mid + 3} H${o.to}" stroke="#f4f4ef" stroke-width="2.6"/>`);
        return this;
    }
    /* ---------- vertical crossing road ---------- */
    vRoad(o) {
        o = Object.assign({ from: 0, to: H, kerb: false }, o || {});
        const { left, right, mid } = CROSS;
        this.block(left - (o.kerb ? 22 : 8), o.from, right - left + (o.kerb ? 44 : 16), o.to - o.from);
        if (o.kerb) this.s.add('road', `<rect x="${left - 22}" y="${o.from}" width="22" height="${o.to - o.from}" fill="#d9ddd9"/><rect x="${right}" y="${o.from}" width="22" height="${o.to - o.from}" fill="#d9ddd9"/>`);
        this.s.add('road', `<rect x="${left}" y="${o.from}" width="${right - left}" height="${o.to - o.from}" fill="url(#tmxAsphalt)"/>`);
        // Clear the main-road markings inside the junction box.
        this.s.add('marks', `<rect x="${left}" y="${ROAD.top}" width="${right - left}" height="${ROAD.bottom - ROAD.top}" fill="url(#tmxAsphalt)"/>`);
        const segs = [];
        if (o.from < ROAD.top) segs.push(`M${mid} ${o.from} V${ROAD.top - 14}`);
        if (o.to > ROAD.bottom) segs.push(`M${mid} ${ROAD.bottom + 14} V${o.to}`);
        if (segs.length) this.s.add('marks', `<path d="${segs.join(' ')}" stroke="#f4f4ef" stroke-width="3.2" stroke-dasharray="30 22"/>`);
        return this;
    }
    stopLineEast(x) { this.s.add('marks', `<path d="M${x} ${ROAD.top + 2} V${ROAD.mid}" stroke="#fff" stroke-width="6"/>`); return this; }
    stopLineNorth(y) { this.s.add('marks', `<path d="M${CROSS.left} ${y} H${CROSS.mid}" stroke="#fff" stroke-width="6"/>`); return this; }
    stopLineSouth(y) { this.s.add('marks', `<path d="M${CROSS.mid} ${y} H${CROSS.right}" stroke="#fff" stroke-width="6"/>`); return this; }
    yieldLineEast(x) {
        let t = '';
        for (let y = ROAD.top + 6; y < ROAD.mid - 4; y += 12) t += `M${x} ${y} l9 4 l-9 4Z `;
        this.s.add('marks', `<path d="${t}" fill="#fff"/>`); return this;
    }
    yieldLineNorth(y) {
        let t = '';
        for (let x = CROSS.left + 6; x < CROSS.mid - 4; x += 12) t += `M${x} ${y} l4 -9 l4 9Z `;
        this.s.add('marks', `<path d="${t}" fill="#fff"/>`); return this;
    }
    zebra(x, w) {
        w = w || 44;
        let bars = '';
        for (let y = ROAD.top + 6; y < ROAD.bottom - 4; y += 14) bars += `<rect x="${x - w / 2}" y="${y}" width="${w}" height="8" fill="#f6f6f2"/>`;
        this.s.add('marks', `<rect x="${x - w / 2 - 2}" y="${ROAD.top}" width="${w + 4}" height="${ROAD.bottom - ROAD.top}" fill="url(#tmxAsphalt)"/>${bars}`);
        return this;
    }
    /* ---------- roundabout ---------- */
    roundabout(c) {
        const { x, y, rIsland, rOuter } = c;
        this.block(x - rOuter, y - rOuter, rOuter * 2, rOuter * 2);
        // approach arms
        this.block(0, ROAD.top - 8, W, ROAD.bottom - ROAD.top + 16);
        this.block(x - 50 - 8, 0, 116, H);
        this.s.add('road', `<rect x="${x - 50 - 9}" y="0" width="118" height="${H}" fill="#c9a877" opacity=".75"/><rect x="0" y="${ROAD.top - 9}" width="${W}" height="118" fill="#c9a877" opacity=".75"/>
            <rect x="0" y="${ROAD.top}" width="${W}" height="100" fill="url(#tmxAsphalt)"/>
            <rect x="${x - 50}" y="0" width="100" height="${H}" fill="url(#tmxAsphalt)"/>
            <circle cx="${x}" cy="${y}" r="${rOuter + 9}" fill="#c9a877" opacity=".75"/>
            <circle cx="${x}" cy="${y}" r="${rOuter}" fill="url(#tmxAsphalt)"/>`);
        this.s.add('marks', `
            <path d="M0 ${ROAD.mid} H${x - rOuter - 16} M${x + rOuter + 16} ${ROAD.mid} H${W} M${x} 0 V${y - rOuter - 16} M${x} ${y + rOuter + 16} V${H}" stroke="#f4f4ef" stroke-width="3.2" stroke-dasharray="30 22"/>
            <path d="M0 ${ROAD.top + 4} H${x - rOuter - 4} M0 ${ROAD.bottom - 4} H${x - rOuter - 4} M${x + rOuter + 4} ${ROAD.top + 4} H${W} M${x + rOuter + 4} ${ROAD.bottom - 4} H${W}" stroke="#f2c230" stroke-width="3"/>
            <circle cx="${x}" cy="${y}" r="${rIsland + 7}" fill="#d6d1c4"/>
            <circle cx="${x}" cy="${y}" r="${rIsland}" fill="url(#tmxGrass)"/>`);
        this.s.add('props', ART.acacia(20), { transform: `translate(${x} ${y})` });
        return this;
    }
    /* ---------- railway (vertical) ---------- */
    railway(x) {
        this.block(x - 46, 0, 92, H);
        let sleepers = '';
        for (let y = -10; y < H + 10; y += 16) sleepers += `<rect x="${x - 30}" y="${y}" width="60" height="7" rx="1" fill="#6b4e33"/>`;
        this.s.add('props', `<rect x="${x - 42}" y="0" width="84" height="${H}" fill="#9d958a"/>${sleepers}
            <path d="M${x - 15} 0 V${H} M${x + 15} 0 V${H}" stroke="#c8ccd0" stroke-width="4"/>
            <path d="M${x - 15} 0 V${H} M${x + 15} 0 V${H}" stroke="#7d848b" stroke-width="1.5"/>`);
        // crossing surface over the road
        this.s.add('marks', `<rect x="${x - 40}" y="${ROAD.top}" width="80" height="${ROAD.bottom - ROAD.top}" fill="#555b61"/>
            <path d="M${x - 15} ${ROAD.top} V${ROAD.bottom} M${x + 15} ${ROAD.top} V${ROAD.bottom}" stroke="#aeb4ba" stroke-width="4"/>`);
        return this;
    }
}

/* ==========================================================================
   CONTEXT given to each scenario builder
   ========================================================================== */
class Ctx {
    constructor(stage, sc, mode, statusEl, seed) {
        this.stage = stage;
        this.sc = sc || {};
        this.mode = mode;
        this.study = mode === 'study';
        this.statusEl = statusEl;
        this.tl = new Timeline(stage);
        this.scene = new Scene(stage, { seed });
    }
    /* Narration. In quiz mode a neutral wording is used when given. */
    say(t, studyText, quizText) {
        const text = this.study ? studyText : (quizText != null ? quizText : studyText);
        if (!text) return this;
        this.tl.at(t, () => { if (this.statusEl) this.statusEl.textContent = text; });
        return this;
    }
    on(t, fn) { this.tl.at(t, fn); return this; }
    cls(t, actor, name, onOff) { this.tl.at(t, () => actor.cls(name, onOff !== false)); return this; }
    ind(t, actor, side) {
        this.tl.at(t, () => { actor.cls('ind-l-on', side === 'left'); actor.cls('ind-r-on', side === 'right'); actor.cls('hazard-on', side === 'hazard'); });
        return this;
    }
    brake(t, actor, on) { return this.cls(t, actor, 'braking', on !== false); }

    /* Consequence flash (study mode only, so the quiz never gives hints). */
    danger(t, x, y, text) {
        if (!this.study) return this;
        this.tl.at(t, () => {
            const g = this.stage.add('fx', `<circle cx="${x}" cy="${y}" r="46" fill="none" stroke="#e5394a" stroke-width="5" class="tmx-ping"/>
                <g class="tmx-pop"><rect x="${x - text.length * 4.4 - 14}" y="${y - 70}" width="${text.length * 8.8 + 28}" height="28" rx="14" fill="#e5394a"/>
                <text x="${x}" y="${y - 51}" font-size="14" font-weight="800" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial">${esc(text)}</text></g>`);
            setTimeout(() => g.classList.add('tmx-fade'), 2200);
        });
        return this;
    }
    good(t, x, y, text) {
        if (!this.study) return this;
        this.tl.at(t, () => {
            this.stage.add('fx', `<g class="tmx-pop"><rect x="${x - text.length * 4.2 - 14}" y="${y - 70}" width="${text.length * 8.4 + 28}" height="28" rx="14" fill="#10a77a"/>
                <text x="${x}" y="${y - 51}" font-size="14" font-weight="800" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial">${esc(text)}</text></g>`);
        });
        return this;
    }
    /* Small badge that pops above an actor (e.g. arrival order). */
    tag(t, actor, text, color) {
        this.tl.at(t, () => {
            const g = svgEl('g', {}, `<g class="tmx-pop"><rect x="-22" y="-13" width="44" height="22" rx="11" fill="${color || '#102744'}"/><text x="0" y="3" font-size="12" font-weight="800" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial">${esc(text)}</text></g>`);
            this.stage.layers.hud.appendChild(g);
            const follow = () => { g.setAttribute('transform', `translate(${actor.x} ${actor.y - 38})`); };
            follow();
            this.tl.each(follow);
        });
        return this;
    }
    skid(t, d) {
        this.tl.at(t, () => this.stage.add('marks', `<path d="${d}" stroke="#1d2125" stroke-width="5" opacity=".55" fill="none" stroke-linecap="round" class="tmx-skid"/>`));
        return this;
    }
    horn(t, actor) {
        this.tl.at(t, () => {
            const g = svgEl('g', { class: 'tmx-horn' }, `<path d="M0 -18 q14 18 0 36 M10 -28 q22 28 0 56" stroke="#f5c518" stroke-width="3.5" fill="none" stroke-linecap="round"/>`);
            this.stage.layers.fx.appendChild(g);
            const f = () => g.setAttribute('transform', `translate(${actor.x} ${actor.y}) rotate(${actor.rot}) translate(${actor.g.getBBox().width / 2 - 20} 0)`);
            f(); this.tl.each(f);
            setTimeout(() => g.remove(), 1600);
        });
        return this;
    }

    /* ---------- HUD ---------- */
    label(text) {
        if (!text) return this;
        const w = Math.min(560, text.length * 7.4 + 34);
        this.stage.add('hud', `<g class="tmx-label"><rect x="${W / 2 - w / 2}" y="12" width="${w}" height="30" rx="15" fill="#102744" opacity=".88"/>
            <text x="${W / 2}" y="32" font-size="13.5" font-weight="700" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial">${esc(text)}</text></g>`);
        return this;
    }
    /* speedFn(t) -> km/h ; limit optional ; flagOver shows red only in study mode */
    speedo(speedFn, limit, flagOver) {
        const g = this.stage.add('hud', `<g class="tmx-speedo">
            <rect x="14" y="12" width="${limit ? 178 : 132}" height="58" rx="14" fill="#102744" opacity=".9"/>
            <text x="30" y="31" font-size="10" font-weight="800" fill="#8fb6dd" letter-spacing="1.5" font-family="Segoe UI,Arial">SPEED</text>
            <text class="tmx-speed-val" x="30" y="60" font-size="26" font-weight="800" fill="#fff" font-family="Segoe UI,Arial">0</text>
            <text x="${limit ? 92 : 92}" y="60" font-size="11" font-weight="700" fill="#8fb6dd" font-family="Segoe UI,Arial">km/h</text>
            ${limit ? `<circle cx="160" cy="41" r="21" fill="#fff" stroke="#d62828" stroke-width="5"/><text x="160" y="47.5" font-size="${String(limit).length > 2 ? 14 : 17}" font-weight="900" fill="#111" text-anchor="middle" font-family="Arial">${esc(limit)}</text>` : ''}
        </g>`);
        const val = g.querySelector('.tmx-speed-val');
        const upd = t => {
            const v = Math.max(0, Math.round(speedFn(t)));
            val.textContent = v;
            const over = limit && v > limit && flagOver !== false && this.study;
            val.setAttribute('fill', over ? '#ff6b6b' : '#fff');
        };
        upd(0);
        this.tl.each(upd);
        return this;
    }
    meter(title, valueFn, colorFn) {
        const g = this.stage.add('hud', `<g><rect x="${W - 200}" y="12" width="186" height="58" rx="14" fill="#102744" opacity=".9"/>
            <text x="${W - 184}" y="31" font-size="10" font-weight="800" fill="#8fb6dd" letter-spacing="1.5" font-family="Segoe UI,Arial">${esc(title)}</text>
            <text class="v" x="${W - 184}" y="58" font-size="20" font-weight="800" fill="#fff" font-family="Segoe UI,Arial"></text></g>`);
        const v = g.querySelector('.v');
        const upd = t => { v.textContent = valueFn(t); if (colorFn && this.study) v.setAttribute('fill', colorFn(t)); };
        upd(0); this.tl.each(upd);
        return this;
    }
    night() { this.stage.svg.classList.add('tmx-night'); this.stage.add('dark', `<rect width="${W}" height="${H}" fill="#050d1c" opacity=".72"/>`); return this; }
    dusk() { this.stage.add('dark', `<rect width="${W}" height="${H}" fill="#7a3a12" opacity=".22"/><rect width="${W}" height="${H}" fill="#0d1a33" opacity=".22"/>`); return this; }
    rain() {
        this.stage.add('dark', `<rect width="${W}" height="${H}" fill="#24354a" opacity=".28"/>`);
        this.stage.add('marks', `<path d="M0 ${ROAD.up + 8} H${W} M0 ${ROAD.down - 6} H${W}" stroke="#cfe3f7" stroke-width="10" opacity=".14"/>`);
        this.stage.add('fx', `<rect class="tmx-rain" x="-60" y="-60" width="${W + 120}" height="${H + 120}" fill="url(#tmxRain)"/>`);
        return this;
    }
    fog(density) { this.stage.add('fx', `<rect width="${W}" height="${H}" fill="#e8edf1" opacity="${density || .55}" class="tmx-fog"/>`); return this; }
    haze() { this.stage.add('fx', `<rect width="${W}" height="${H}" fill="#d9bb8a" opacity=".5"/>`); return this; }
}

Object.assign(TMX, { Scene, Ctx, rng, hash });
/* ==========================================================================
   SCENARIO BUILDERS
   Each builder draws a scene and scripts a timeline from scenario data.
   ========================================================================== */
const B = {};
const U = ROAD.up, D = ROAD.down;

const learnerCar = (ctx, x, y, rot) => ctx.stage.actor(ART.learner(), x, y, rot || 0, 'learner');
const otherCar = (ctx, color, x, y, rot) => ctx.stage.actor(ART.car(color), x, y, rot || 0);
const isTrue = v => v === true || v === 1 || v === '1' || v === 'true' || v === 'yes';
const str = v => String(v == null ? '' : v).toLowerCase();

/* Live speedometer from the learner car's real on-screen speed. */
function autoSpeedo(ctx, actor, cruiseKmh, cruisePx, limit, flagOver) {
    let last = null, v = 0;
    ctx.speedo(t => {
        if (last && t > last.t) {
            const dt = t - last.t;
            const px = Math.hypot(actor.x - last.x, actor.y - last.y) / dt;
            const kmh = px * cruiseKmh / cruisePx;
            v = v + (kmh - v) * Math.min(1, dt * 6);
        }
        if (t === 0) v = 0;
        last = { t, x: actor.x, y: actor.y };
        return Math.abs(v - cruiseKmh) < cruiseKmh * 0.04 ? cruiseKmh : v;
    }, limit, flagOver);
}
const travel = (kmh, px) => px / (120 + kmh * 3); // seconds to cover px at a given scenario speed

function ruralScene(ctx, seed, opts) {
    ctx.scene.ground(opts && opts.ground || 'savanna');
    ctx.scene.hRoad(Object.assign({ edges: 'yellow' }, opts || {}));
    return ctx.scene;
}
function urbanScene(ctx, opts) {
    ctx.scene.ground('urban');
    ctx.scene.hRoad(Object.assign({ kerb: true, edges: 'none' }, opts || {}));
    return ctx.scene;
}
function urbanBlocks(scene, skipX) {
    const list = [[90, 70, 96, 62, '#b5523b'], [230, 64, 80, 54, '#5d6d7e'], [380, 74, 90, 60, '#a04000'],
        [760, 68, 92, 58, '#7d5a50'], [880, 74, 74, 54, '#b5523b'],
        [110, 330, 90, 60, '#5d6d7e'], [260, 336, 80, 56, '#a04000'], [410, 328, 92, 58, '#b5523b'],
        [770, 334, 90, 60, '#7d5a50'], [890, 328, 78, 58, '#5d6d7e']]
        .filter(h => !skipX || Math.abs(h[0] - skipX) > 120);
    scene.houses(list);
}

/* ---------------------------------------------------------------- traffic_light */
B.traffic_light = ctx => {
    const sc = ctx.sc, light = str(sc.light) || 'red', passes = isTrue(sc.car_passes);
    const s = urbanScene(ctx);
    s.vRoad({ kerb: true });
    urbanBlocks(s, CROSS.mid);
    s.stopLineEast(CROSS.left - 12).stopLineNorth(ROAD.bottom + 12);
    const tl = ctx.stage.add('props', ART.trafficLight(), { transform: `translate(${CROSS.left - 30} ${ROAD.top - 34})`, class: 'tmx-tl state-' + light });
    s.scatter(4);

    const cross = otherCar(ctx, '#c0392b', CROSS.nb, 300, -90);
    const L = learnerCar(ctx, -60, U);
    autoSpeedo(ctx, L, 60, 590, null);
    ctx.say(0.1, 'Approaching a ' + light.toUpperCase() + ' traffic light…', 'Watch what the driver does at the traffic light…');

    if (passes) {
        L.to(0.2, 3.9, 1040, U, 'soft');
        ctx.say(1.8, 'The driver drives through on ' + light.toUpperCase() + '.', 'The driver continues through the junction.');
        if (light === 'red') {
            cross.home.y = 470;
            cross.set(CROSS.nb, 470, -90);
            cross.to(0.6, 2.4, CROSS.nb, 285, 'out');
            ctx.brake(1.9, cross).skid(2.0, `M${CROSS.nb} 330 V292`).horn(2.0, cross);
            ctx.danger(2.1, CROSS.nb, ROAD.mid, 'Near miss!');
            cross.to(4.2, 6.0, CROSS.nb, -80, 'in');
            ctx.brake(4.2, cross, false);
        } else {
            ctx.brake(0, cross);
            if (light === 'amber') ctx.danger(2.2, CROSS.mid, ROAD.mid, 'Should have stopped');
            else ctx.good(2.4, CROSS.mid, ROAD.mid, 'Safe to proceed');
        }
    } else {
        L.to(0.2, 3.0, CROSS.left - 50, U, 'out');
        ctx.brake(1.7, L);
        ctx.say(2.0, 'The driver stops behind the stop line.', 'The driver reaches the junction.');
        if (light === 'red') {
            cross.home.y = 470; cross.set(CROSS.nb, 470, -90);
            cross.to(1.0, 4.6, CROSS.nb, -80, 'soft');
            ctx.good(3.2, 470, ROAD.mid, 'Stopped on red');
        } else {
            ctx.brake(0, cross);
            ctx.good(3.2, 470, ROAD.mid, 'Stopped safely');
        }
    }
};

/* ---------------------------------------------------------------- four_way_stop */
B.four_way_stop = ctx => {
    const sc = ctx.sc, order = str(sc.arriving_order), go = str(sc.action) !== 'wait';
    const s = urbanScene(ctx);
    s.vRoad({ kerb: true });
    urbanBlocks(s, CROSS.mid);
    s.stopLineEast(CROSS.left - 12).stopLineNorth(ROAD.bottom + 12).stopLineSouth(ROAD.top - 12);
    s.prop(ART.signStop(), CROSS.left - 32, ROAD.top - 46, 'props');
    s.prop(ART.signStop(), CROSS.left - 32, ROAD.bottom + 14, 'props');
    s.prop(ART.signStop(), CROSS.right + 30, ROAD.top - 46, 'props');
    s.prop(ART.signStop(), CROSS.right + 30, ROAD.bottom + 14, 'props');

    const fromNorth = order.includes('same_time_right');
    const L = learnerCar(ctx, -60, U);
    const O = fromNorth ? otherCar(ctx, '#e67e22', CROSS.sb, -70, 90) : otherCar(ctx, '#e67e22', CROSS.nb, 470, -90);
    const oStopY = fromNorth ? ROAD.top - 50 : ROAD.bottom + 50;
    const oExitY = fromNorth ? 480 : -80;
    const LStop = CROSS.left - 50;
    autoSpeedo(ctx, L, 50, 800, null);

    const arrive = (A, t0, t1, x, y, label, color) => { A.to(t0, t1, x, y, 'out'); ctx.brake(t1 - 0.8, A); ctx.tag(t1, A, label, color); };
    const leave = (A, t0, t1, x, y) => { ctx.brake(t0, A, false); A.to(t0, t1, x, y, 'in'); };
    ctx.say(0.1, 'Two vehicles approach a four-way stop…', 'Two vehicles approach a four-way stop…');

    if (order.includes('same_time')) {
        arrive(L, 0.2, 2.2, LStop, U, 'Both', '#8950dc');
        arrive(O, 0.2, 2.2, fromNorth ? CROSS.sb : CROSS.nb, oStopY, 'Both', '#8950dc');
        ctx.say(2.3, fromNorth ? 'Both stop together. The learner is on the RIGHT of the other vehicle.' : 'Both stop together. The other vehicle is on the learner’s RIGHT.', 'Both vehicles stop at the same time.');
        if (go) {
            leave(L, 3.0, 5.0, 1040, U);
            if (!fromNorth) {
                leave(O, 3.1, 3.6, CROSS.nb, oStopY - 14); ctx.brake(3.6, O).horn(3.6, O);
                ctx.danger(3.7, CROSS.mid, ROAD.mid, 'Should have given way');
                leave(O, 5.2, 7.0, CROSS.nb, oExitY);
            } else {
                ctx.good(3.6, CROSS.mid, ROAD.mid, 'Vehicle on the right goes first');
                leave(O, 5.0, 6.8, CROSS.sb, oExitY);
            }
            ctx.say(3.1, 'The learner proceeds first.', 'The learner proceeds.');
        } else {
            leave(O, 3.0, 4.8, fromNorth ? CROSS.sb : CROSS.nb, oExitY);
            leave(L, 5.0, 7.0, 1040, U);
        }
    } else if (order.includes('first') || order === '1' || order.includes('1st')) {
        arrive(L, 0.2, 2.0, LStop, U, '1st', '#10a77a');
        arrive(O, 0.9, 2.8, CROSS.nb, oStopY, '2nd', '#647b95');
        if (go) {
            ctx.say(3.2, 'The learner stopped first, checks, and proceeds.', 'The learner proceeds.');
            leave(L, 3.3, 5.3, 1040, U);
            ctx.good(3.6, CROSS.mid, ROAD.mid, 'First to stop, first to go');
            leave(O, 5.4, 7.2, CROSS.nb, oExitY);
        } else {
            ctx.say(3.2, 'The learner stopped first but keeps waiting…', 'The learner waits.');
            ctx.danger(4.2, LStop, U, 'Holding up the junction');
            leave(O, 4.6, 6.4, CROSS.nb, oExitY);
            leave(L, 6.6, 8.6, 1040, U);
        }
    } else {
        arrive(O, 0.1, 1.6, CROSS.nb, oStopY, '1st', '#10a77a');
        arrive(L, 0.2, 2.4, LStop, U, '2nd', '#647b95');
        if (go) {
            ctx.say(2.8, 'The learner arrived second but goes first.', 'The learner proceeds.');
            leave(L, 2.9, 4.9, 1040, U);
            leave(O, 3.0, 3.5, CROSS.nb, oStopY - 14); ctx.brake(3.5, O).horn(3.5, O);
            ctx.danger(3.6, CROSS.mid, ROAD.mid, 'Took the right of way');
            leave(O, 5.2, 7.0, CROSS.nb, oExitY);
        } else {
            leave(O, 3.0, 4.8, CROSS.nb, oExitY);
            leave(L, 5.0, 7.0, 1040, U);
        }
    }
};

/* ---------------------------------------------------------------- speed */
B.speed = ctx => {
    const lim = parseInt(ctx.sc.limit, 10) || 60, spd = parseInt(ctx.sc.speed, 10) || lim;
    if (lim <= 60) { const s = urbanScene(ctx); urbanBlocks(s); s.scatter(5); }
    else ruralScene(ctx).scatter(26);
    ctx.scene.prop(ART.signSpeed(lim), 230, ROAD.top - 46);
    const L = learnerCar(ctx, -60, U);
    const dur = travel(spd, 1100);
    L.to(0.2, 0.2 + dur, 1040, U, 'linear');
    autoSpeedo(ctx, L, spd, 1100 / dur, lim);
    ctx.say(0.1, `Speed limit ${lim} km/h. The driver travels at ${spd} km/h.`, `Speed limit ${lim} km/h. Watch the speedometer.`);
    if (spd > lim) ctx.danger(0.2 + dur * .5, 520, U, `${spd - lim} km/h over the limit`);
    else ctx.good(0.2 + dur * .5, 520, U, 'Within the limit');
    ctx.tl.end(Math.max(3.4, dur + 0.6));
};

/* ---------------------------------------------------------------- school_zone */
B.school_zone = ctx => {
    const sc = ctx.sc, lim = parseInt(sc.speed, 10) || 40, spd = parseInt(sc.your_speed, 10) || lim;
    const time = str(sc.time), kids = time.includes('children');
    const weekend = time.includes('weekend');
    const s = urbanScene(ctx);
    s.zebra(650);
    s.prop(ART.school(), 760, 76);
    s.houses([[120, 70, 90, 58, '#b5523b'], [270, 66, 80, 54, '#5d6d7e'], [140, 330, 90, 60, '#a04000'], [330, 334, 84, 56, '#7d5a50'], [800, 330, 100, 60, '#5d6d7e']]);
    s.prop(ART.signWarn(ART.symKids), 380, ROAD.top - 46);
    s.prop(ART.signSpeed(lim), 440, ROAD.top - 46);
    if (weekend) ctx.stage.add('hud', `<g><rect x="700" y="44" width="120" height="24" rx="12" fill="#647b95"/><text x="760" y="61" font-size="12" font-weight="800" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial">WEEKEND · CLOSED</text></g>`);

    const k1 = ctx.stage.actor(ART.person('#e63946', '#7a4a2c', true), 640, 128, 90);
    const k2 = ctx.stage.actor(ART.person('#2a9d8f', '#5c3a22', true), 662, 124, 90);
    if (weekend) { k1.set(-200, -200, 0); k2.set(-200, -200, 0); }
    const L = learnerCar(ctx, -60, U);
    autoSpeedo(ctx, L, spd, 120 + spd * 3, lim);
    ctx.say(0.1, `School zone. Limit ${lim} km/h, the driver is doing ${spd} km/h.`, 'Watch the driver in the school zone…');

    if (kids && spd <= lim) {
        k1.to(1.2, 4.2, 640, 272, 'linear'); k2.to(1.5, 4.6, 662, 268, 'linear');
        L.to(0.2, 2.6, 560, U, 'out'); ctx.brake(1.6, L);
        ctx.say(2.4, 'Children are crossing. The driver slows down and stops.', 'Children are near the crossing.');
        ctx.good(3.0, 560, U, 'Ready to stop');
        ctx.brake(4.8, L, false); L.to(4.8, 7.0, 1040, U, 'in');
    } else {
        const dur = travel(spd, 1100);
        L.to(0.2, 0.2 + dur, 1040, U, 'linear');
        if (!weekend) { k1.to(0.8, 1.6, 640, 140, 'linear'); }
        if (spd > lim) {
            ctx.danger(0.2 + dur * .55, 640, U, 'Too fast near a school');
            if (!weekend) { k1.to(1.6 + dur * .1, 2.2 + dur * .1, 640, 128, 'out', { keepRot: 90 }); }
        } else ctx.good(0.2 + dur * .55, 640, U, 'Within the limit');
        ctx.tl.end(Math.max(3.6, dur + .8));
    }
};

/* ---------------------------------------------------------------- emergency_vehicle */
B.emergency_vehicle = ctx => {
    const sc = ctx.sc, on = str(sc.sirens) === 'on', kind = str(sc.vehicle) || 'ambulance';
    const s = ruralScene(ctx);
    ctx.stage.add('road', `<rect x="0" y="${ROAD.top - 24}" width="${W}" height="15" fill="#b99666" opacity=".8"/>`);
    s.block(0, ROAD.top - 26, W, 20);
    s.scatter(24);
    const EV = ctx.stage.actor(ART.emergency(kind), -140, U, 0, on ? 'siren-on' : '');
    const L = learnerCar(ctx, 140, U);
    autoSpeedo(ctx, L, 80, 160, null);
    if (on) {
        ctx.say(0.1, 'An emergency vehicle approaches from behind with sirens on…', 'An emergency vehicle approaches from behind…');
        L.to(0.0, 1.2, 330, U, 'linear');
        EV.to(0.0, 2.8, 380, U, 'linear');
        ctx.ind(1.0, L, 'left');
        L.path(1.2, 3.2, `M330 ${U} C 420 ${U}, 440 ${ROAD.top - 4}, 540 ${ROAD.top - 6}`, 'out');
        ctx.brake(2.2, L);
        ctx.say(1.4, 'The driver signals, moves to the LEFT edge and stops.', 'Watch how the driver reacts.');
        EV.to(2.8, 4.8, 1120, U, 'linear');
        ctx.good(3.6, 560, ROAD.top - 6, 'Clear path given');
        ctx.ind(4.9, L, 'right'); ctx.brake(5.0, L, false);
        L.path(5.2, 7.4, `M540 ${ROAD.top - 6} C 640 ${ROAD.top - 6}, 660 ${U}, 760 ${U} L 1040 ${U}`, 'in');
        ctx.ind(6.6, L, null);
    } else {
        ctx.say(0.1, 'A police vehicle follows with NO siren or lights active.', 'A vehicle follows behind…');
        L.to(0.0, 5.6, 1040, U, 'linear');
        EV.to(0.0, 5.6, 760, U, 'linear');
        ctx.say(2.0, 'No emergency signals: the driver proceeds normally and stays alert.', 'The driver continues.');
    }
};

/* ---------------------------------------------------------------- night_driving */
B.night_driving = ctx => {
    const sc = ctx.sc, cond = str(sc.condition), lightsMode = str(sc.lights);
    const s = ruralScene(ctx);
    s.scatter(22);
    ctx.night();
    const beams = lightsMode.includes('high') ? 'lights-high' : 'lights-low';
    const fogLights = lightsMode.includes('fog');
    const L = learnerCar(ctx, -60, U);
    L.cls(beams); if (fogLights) L.cls('lights-fog');
    autoSpeedo(ctx, L, 80, 200, null);
    if (cond === 'fog') {
        ctx.fog(.62);
        L.to(0.2, 6.0, 1040, U, 'linear');
        ctx.say(0.1, 'Driving in fog at night with ' + (lightsMode.includes('high') ? 'HIGH beam' : 'low beam and fog lights') + '.', 'Driving in fog at night…');
        if (lightsMode.includes('high')) {
            ctx.on(1.0, () => L.cls('glare'));
            ctx.danger(2.6, 520, U, 'High beam reflects off fog');
        } else ctx.good(2.6, 520, U, 'Low beam + fog lights');
    } else if (cond.includes('oncoming')) {
        const O = otherCar(ctx, '#c9ced3', 1060, D, 180);
        O.cls('lights-low');
        L.to(0.2, 6.2, 1040, U, 'linear');
        O.to(0.2, 6.2, -80, D, 'linear');
        ctx.say(0.1, 'An oncoming vehicle approaches at night…', 'An oncoming vehicle approaches at night…');
        if (lightsMode.includes('high')) {
            L.cls('lights-high');
            ctx.on(2.0, () => O.cls('dazzled'));
            O.wobble = t => (t > 2.2 && t < 3.6) ? Math.sin(t * 14) * 5 : 0;
            ctx.danger(2.4, 480, D, 'Oncoming driver dazzled');
            ctx.say(2.2, 'The driver keeps HIGH beam on toward oncoming traffic.', 'Watch the headlights.');
        } else {
            L.cls('lights-high'); L.cls('lights-low', false);
            ctx.on(1.3, () => { L.cls('lights-high', false); L.cls('lights-low'); });
            ctx.say(1.3, 'The driver dips to LOW beam for the oncoming vehicle.', 'Watch the headlights.');
            ctx.good(2.4, 480, D, 'Dipped headlights');
            ctx.on(4.4, () => { L.cls('lights-low', false); L.cls('lights-high'); });
        }
    } else {
        const kudu = ctx.stage.actor(ART.kudu(), 860, ROAD.top - 22, 160);
        L.to(0.2, 5.4, 1040, U, 'linear');
        ctx.say(0.1, 'A dark, unlit rural road with ' + (lightsMode.includes('high') ? 'HIGH beam' : 'low beam') + ' on.', 'A dark, unlit rural road…');
        ctx.say(1.4, lightsMode.includes('high') ? 'High beam lights up the kudu far ahead in time.' : 'Low beam only reaches a short distance.', 'Watch the road ahead.');
        if (lightsMode.includes('high')) ctx.good(1.8, 860, ROAD.top - 22, 'Hazard seen early');
    }
};

/* ---------------------------------------------------------------- vehicle_check (driver's view) */
B.vehicle_check = ctx => {
    const sc = ctx.sc, act = str(sc.action);
    const st = ctx.stage;
    st.add('ground', `<rect width="${W}" height="${H}" fill="#9fc6e6"/>
        <path d="M0 140 H${W} V250 H0Z" fill="#cdb88a"/>
        <path d="M410 140 L550 140 L${W - 40} 400 L40 400Z" fill="#4a4f55"/>
        <g class="tmx-road-dash"><path d="M480 150 L480 400" stroke="#f4f4ef" stroke-width="6" stroke-dasharray="26 30"/></g>
        <path d="M410 140 L40 400 M550 140 L${W - 40} 400" stroke="#f2c230" stroke-width="4"/>
        <circle cx="160" cy="138" r="18" fill="#6f8b3d"/><circle cx="820" cy="134" r="22" fill="#5f7a35"/><circle cx="700" cy="138" r="12" fill="#7d8f45"/>`);
    st.add('props', `
        <path d="M0 0 H${W} V36 Q480 70 0 36Z" fill="#20262d"/>
        <rect x="0" y="0" width="34" height="${H}" fill="#20262d"/><rect x="${W - 34}" y="0" width="34" height="${H}" fill="#20262d"/>
        <path d="M0 250 Q480 205 ${W} 250 V${H} H0Z" fill="#2b323a"/>
        <path d="M0 262 Q480 218 ${W} 262" stroke="#3e4751" stroke-width="3" fill="none"/>
        <rect x="190" y="262" width="250" height="96" rx="24" fill="#12161b"/>
        <g transform="translate(255 312)"><circle r="38" fill="#0b0e12" stroke="#4b5560" stroke-width="2"/><g class="tmx-needle"><path d="M0 0 L-28 10" stroke="#ff5a4e" stroke-width="3" stroke-linecap="round"/></g><circle r="4" fill="#cfd6dd"/><text y="26" font-size="9" fill="#8fa0b1" text-anchor="middle" font-family="Arial">km/h</text></g>
        <g transform="translate(375 312)"><circle r="38" fill="#0b0e12" stroke="#4b5560" stroke-width="2"/><g class="tmx-rev"><path d="M0 0 L-26 14" stroke="#ff5a4e" stroke-width="3" stroke-linecap="round"/></g><circle r="4" fill="#cfd6dd"/><text y="26" font-size="9" fill="#8fa0b1" text-anchor="middle" font-family="Arial">RPM</text></g>
        <g class="tmx-warn" transform="translate(206 270)">
          <g class="w w-belt"><circle cx="10" cy="10" r="9"/><path d="M6 4 l8 12 M10 3 v5" stroke="#000" stroke-width="2"/></g>
          <g class="w w-oil" transform="translate(28 0)"><circle cx="10" cy="10" r="9"/><path d="M3 12 h10 l4 -4 M8 9 v-3 h3" stroke="#000" stroke-width="2" fill="none"/></g>
          <g class="w w-bat" transform="translate(56 0)"><circle cx="10" cy="10" r="9"/><rect x="4" y="7" width="12" height="7" fill="none" stroke="#000" stroke-width="2"/></g>
          <g class="w w-haz" transform="translate(194 0)"><path d="M10 2 L19 18 H1Z" stroke="#000" stroke-width="1.5"/></g>
        </g>
        <g class="tmx-wheel" transform="translate(315 368)"><circle r="92" fill="none" stroke="#151a1f" stroke-width="20"/><path d="M-90 0 H90 M0 0 V90" stroke="#151a1f" stroke-width="16"/><circle r="24" fill="#1d2329"/></g>
        <path d="M700 ${H} L760 300 L880 300 L${W - 34} ${H}Z" fill="#3a434d"/>
        <g class="tmx-belt"><path d="M640 268 L820 ${H}" stroke="#2f3a44" stroke-width="22" stroke-linecap="round"/><rect x="805" y="372" width="30" height="16" rx="3" fill="#aeb6be"/></g>
        <g class="tmx-belt-off"><path d="M622 262 L640 330" stroke="#2f3a44" stroke-width="22" stroke-linecap="round"/></g>
        <g class="tmx-phone-hand"><circle cx="520" cy="300" r="26" fill="#8d5a3b"/><rect x="505" y="252" width="34" height="58" rx="6" fill="#111"/><rect x="509" y="258" width="26" height="44" rx="3" fill="#59a5ff"/></g>
        <g class="tmx-phone-mount"><rect x="590" y="226" width="20" height="30" rx="3" fill="#1b1f24"/><rect x="566" y="190" width="68" height="40" rx="6" fill="#111"/><rect x="570" y="194" width="60" height="32" rx="3" fill="#2a9d8f"/><text x="600" y="215" font-size="10" font-weight="800" fill="#fff" text-anchor="middle" font-family="Arial">HANDS-FREE</text></g>`);
    st.svg.classList.add('tmx-cabin');
    ctx.label(sc.label || '');
    const flags = { drive: false };
    const on = (t, c) => ctx.on(t, () => st.svg.classList.add(c));
    const off = (t, c) => ctx.on(t, () => st.svg.classList.remove(c));
    ctx.tl.end(5.2);
    if (act.includes('seatbelt_on')) {
        on(0, 'belt-warn'); ctx.say(0.1, 'The driver sits down and fastens the seatbelt…');
        on(0.8, 'belt-on'); off(1.6, 'belt-warn');
        ctx.say(1.6, 'Seatbelt fastened. Now the engine starts and the car moves off.');
        on(2.2, 'engine'); on(2.8, 'driving');
        ctx.good(2.0, 640, 150, 'Buckled up first');
    } else if (act.includes('seatbelt_off')) {
        on(0, 'belt-off'); on(0.4, 'belt-warn'); on(0.4, 'belt-blink');
        ctx.say(0.4, 'The seatbelt warning flashes, but the driver drives off without it.', 'Watch the warning lights.');
        on(1.4, 'engine'); on(2.0, 'driving');
        ctx.danger(2.6, 640, 150, 'No seatbelt');
    } else if (act.includes('phone_in_hand')) {
        on(0, 'engine'); on(0, 'driving'); on(0, 'belt-on');
        on(1.0, 'phone-hand'); on(1.6, 'drift');
        ctx.say(1.0, 'The driver picks up the phone while driving.', 'Watch the driver.');
        ctx.danger(2.2, 520, 220, 'Phone in hand');
    } else if (act.includes('hands_free')) {
        on(0, 'engine'); on(0, 'driving'); on(0, 'belt-on'); on(0.6, 'phone-mount');
        ctx.say(0.8, 'A call comes in. The phone is in a holder and used hands-free; both hands stay on the wheel.', 'Watch the driver.');
        ctx.good(2.0, 600, 170, 'Hands on the wheel');
    } else if (act.includes('oil')) {
        on(0, 'engine'); on(0, 'driving'); on(0, 'belt-on');
        on(1.0, 'oil-warn');
        ctx.say(1.0, 'The red OIL warning light comes on.', 'A warning light comes on.');
        on(1.8, 'hazards'); on(2.2, 'slowing'); off(3.6, 'driving'); off(3.6, 'slowing'); off(4.2, 'engine');
        ctx.say(2.0, 'The driver signals, pulls over and switches the engine off.', 'Watch what the driver does.');
        ctx.good(3.8, 480, 150, 'Stopped safely');
    } else {
        on(0, 'engine'); on(0.6, 'driving');
        ctx.say(0.2, sc.label || 'Vehicle check');
    }
    return flags;
};

/* ---------------------------------------------------------------- animal_crossing */
B.animal_crossing = ctx => {
    const sc = ctx.sc, species = str(sc.species) || (String(sc.animal || '').match(/🐄|🐂|cow|cattle/i) ? 'cow' : (String(sc.animal || '').match(/🫏|donkey/i) ? 'donkey' : 'kudu'));
    const act = str(sc.driver_action) || (isTrue(sc.slows) ? 'slow' : 'maintain');
    const tod = str(sc.time_of_day);
    const s = ruralScene(ctx);
    s.prop(ART.signWarn(species === 'cow' ? ART.symCow : ART.symKudu), 200, ROAD.top - 46);
    s.scatter(26);
    if (tod === 'night') ctx.night(); else if (tod === 'dusk') ctx.dusk();
    const A = ctx.stage.actor(ART.animal(species), 640, ROAD.top - 24, 90);
    if (species === 'cow') ctx.stage.actor(ART.cow(), 700, ROAD.top - 34, 70);
    const L = learnerCar(ctx, -60, U);
    if (tod === 'night') L.cls('lights-low');
    autoSpeedo(ctx, L, act === 'slow' ? 80 : 100, 330, null);
    const name = { kudu: 'A kudu', cow: 'Cattle', donkey: 'A donkey', warthog: 'A warthog' }[species] || 'An animal';
    ctx.say(0.1, name + ' is next to the road ahead…', name + ' is next to the road ahead…');
    if (act === 'slow' || act === 'stop') {
        L.to(0.2, 2.6, 470, U, 'out'); ctx.brake(1.4, L);
        A.to(1.2, 3.9, 640, 300, 'linear');
        ctx.say(1.5, 'The driver slows down early and is ready to stop.', 'Watch the driver.');
        ctx.good(2.6, 470, U, 'Slowed down in time');
        ctx.brake(4.2, L, false); L.to(4.2, 6.4, 1040, U, 'in');
    } else if (act === 'swerve') {
        const O = ctx.stage.actor(ART.truck('#2471a3'), 1160, D, 180);
        O.to(0.4, 3.0, 760, D, 'linear');
        A.to(1.2, 2.0, 640, U, 'linear');
        L.to(0.2, 1.9, 470, U, 'linear');
        L.path(1.9, 2.6, `M470 ${U} C 520 ${U}, 530 ${D}, 580 ${D}`, 'linear');
        ctx.brake(2.5, O).horn(2.5, O);
        ctx.danger(2.6, 640, D, 'Swerved into oncoming traffic');
        L.path(2.6, 3.5, `M580 ${D} C 620 ${D}, 640 ${U + 4}, 720 ${U}`, 'out');
        ctx.say(2.0, 'The driver swerves into the oncoming lane to miss the animal.', 'Watch the driver.');
        A.to(3.0, 4.0, 640, ROAD.top - 40, 'linear');
        O.to(3.2, 5.0, -200, D, 'in');
        L.to(3.8, 5.4, 1040, U, 'in');
    } else {
        A.to(1.4, 2.3, 640, U, 'linear');
        L.to(0.2, 2.0, 520, U, 'linear');
        L.to(2.0, 2.6, 584, U, 'out');
        ctx.brake(2.0, L).skid(2.1, `M500 ${U - 9} H580 M500 ${U + 9} H580`);
        ctx.say(1.6, 'The driver does not slow down and brakes far too late.', 'Watch the driver.');
        ctx.danger(2.5, 600, U, 'Too fast to stop');
        A.to(2.7, 3.6, 660, 300, 'linear');
        ctx.brake(4.0, L, false); L.to(4.0, 6.0, 1040, U, 'in');
    }
};

/* ---------------------------------------------------------------- gravel_road */
B.gravel_road = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), spd = parseInt(sc.vehicle_speed, 10) || 80, cond = str(sc.condition);
    ctx.label(sc.label || '');
    const st = ctx.stage, s = ctx.scene;
    s.ground('savanna');
    const bend = cond === 'bend';
    if (bend) {
        const rd = 'M -80 300 L 260 300 C 420 300, 470 250, 520 190 S 650 100, 1040 100';
        st.add('road', `<path d="${rd}" stroke="#bf9a6c" stroke-width="112" fill="none" opacity=".55"/><path d="${rd}" stroke="url(#tmxGravel)" stroke-width="96" fill="none"/>`);
        s.block(-80, 240, 400, 120); s.block(260, 140, 320, 220); s.block(500, 40, 540, 120);
        s.prop(ART.signWarn(ART.symBend), 220, 196);
        s.scatter(26);
        const L = learnerCar(ctx, -60, 280);
        L.g.insertAdjacentHTML('afterbegin', DUST_SMALL);
        const lane = 'M -60 280 L 260 280 C 410 280, 455 232, 503 175 S 640 80, 1040 80';
        if (act === 'brake_before_bend') {
            L.path(0.2, 1.7, 'M -60 280 L 250 280', 'out');
            ctx.brake(1.0, L); ctx.brake(1.8, L, false);
            L.path(1.8, 5.2, 'M 250 280 C 410 280, 455 232, 503 175 S 640 80, 1040 80', 'soft');
            autoSpeedo(ctx, L, 60, 260, null);
            ctx.say(0.2, 'A sharp bend on gravel. The driver brakes BEFORE the bend…', 'A sharp bend on gravel…');
            ctx.say(1.9, '…then drives through the bend at a steady, low speed.', 'Watch the driver.');
            ctx.good(3.0, 520, 160, 'Braked before the bend');
        } else {
            L.path(0.2, 1.4, 'M -60 280 L 300 280', 'linear');
            L.path(1.4, 2.6, 'M 300 280 C 400 280, 450 250, 470 230 C 500 210, 560 250, 600 300', 'linear');
            L.wobble = t => (t > 1.6 && t < 3.6) ? Math.sin(t * 9) * 26 * Math.min(1, (t - 1.6)) : (t >= 3.6 ? 35 : 0);
            ctx.brake(1.5, L).skid(1.7, 'M 360 280 C 420 270, 470 240, 520 250 S 580 290, 600 300');
            L.to(2.6, 3.4, 640, 330, 'out');
            autoSpeedo(ctx, L, spd, 330, null);
            ctx.say(0.2, 'The driver enters the bend too fast, then brakes hard and swerves…', 'A sharp bend on gravel…');
            ctx.danger(2.4, 560, 260, 'Lost control on loose gravel');
        }
        return;
    }
    s.hRoad({ surface: 'gravel' });
    s.prop(ART.signWarn(ART.symSlippery), 220, ROAD.top - 46);
    s.scatter(28);
    const dustBig = act === 'tailgate_dust' || act === 'hang_back';
    const L = learnerCar(ctx, -60, U);
    L.g.insertAdjacentHTML('afterbegin', spd >= 100 ? DUST_BIG : DUST_SMALL);
    if (dustBig) {
        const gap = act === 'tailgate_dust' ? 100 : 330;
        const lead = ctx.stage.actor(ART.bakkie('#d9d4c7'), 200, U, 0);
        lead.g.insertAdjacentHTML('afterbegin', DUST_TRAIL);
        L.home.x = 200 - gap; L.set(200 - gap, U, 0);
        lead.to(0.2, 6.2, 1140, U, 'linear');
        L.to(0.2, 6.2, 1140 - gap, U, 'linear');
        autoSpeedo(ctx, L, 80, 940 / 6, null);
        ctx.meter('VISIBILITY', () => act === 'tailgate_dust' ? 'Very poor' : 'Good', () => act === 'tailgate_dust' ? '#ff6b6b' : '#5ee0a8');
        // Stage order puts the dust cloud over a close follower.
        st.layers.actors.appendChild(lead.g);
        if (act === 'tailgate_dust') {
            ctx.say(0.2, 'The driver follows closely inside the dust cloud and cannot see ahead.', 'Following a vehicle on a dusty gravel road…');
            ctx.danger(2.6, 420, U, 'Driving blind in dust');
        } else {
            ctx.say(0.2, 'The driver drops far back until the dust clears and the road is visible.', 'Following a vehicle on a dusty gravel road…');
            ctx.good(2.6, 300, U, 'Safe distance, clear view');
        }
        return;
    }
    const dur = travel(spd, 1100);
    if (act === 'too_fast' || spd > 100) {
        L.to(0.2, 0.2 + dur * .55, 560, U, 'linear');
        L.path(0.2 + dur * .55, 0.2 + dur * .55 + 1.6, `M560 ${U} C 640 ${U}, 680 ${D + 10}, 760 ${ROAD.bottom + 20}`, 'out');
        L.wobble = t => (t > 0.2 + dur * .45) ? Math.sin(t * 11) * 18 + 20 : 0;
        ctx.skid(0.2 + dur * .6, `M600 ${U} C 660 ${U + 10}, 700 ${D}, 760 ${ROAD.bottom + 16}`);
        autoSpeedo(ctx, L, spd, 1100 / dur, null);
        ctx.say(0.2, `${spd} km/h on loose gravel: the car starts to slide.`, 'Driving on a gravel road…');
        ctx.danger(0.2 + dur * .6, 640, U, 'Too fast for gravel');
    } else {
        L.to(0.2, 0.2 + dur, 1040, U, 'linear');
        autoSpeedo(ctx, L, spd, 1100 / dur, null);
        ctx.say(0.2, `Steady ${spd} km/h on gravel, keeping left with smooth steering.`, 'Driving on a gravel road…');
        ctx.good(0.2 + dur * .5, 520, U, 'Steady and in control');
        ctx.tl.end(dur + .6);
    }
};
const DUST_SMALL = `<g class="tmx-dust"><circle cx="-46" cy="0" r="10" fill="url(#tmxDust)"/><circle cx="-60" cy="-6" r="13" fill="url(#tmxDust)"/><circle cx="-62" cy="8" r="12" fill="url(#tmxDust)"/></g>`;
const DUST_BIG = `<g class="tmx-dust"><circle cx="-50" cy="0" r="16" fill="url(#tmxDust)"/><circle cx="-74" cy="-10" r="22" fill="url(#tmxDust)"/><circle cx="-80" cy="12" r="22" fill="url(#tmxDust)"/><circle cx="-104" cy="0" r="26" fill="url(#tmxDust)"/></g>`;
const DUST_TRAIL = `<g class="tmx-dust"><circle cx="-60" cy="0" r="24" fill="url(#tmxDust)"/><circle cx="-100" cy="-14" r="34" fill="url(#tmxDust)"/><circle cx="-110" cy="16" r="34" fill="url(#tmxDust)"/><circle cx="-160" cy="0" r="44" fill="url(#tmxDust)"/><circle cx="-210" cy="-10" r="46" fill="url(#tmxDust)"/><circle cx="-250" cy="12" r="44" fill="url(#tmxDust)"/></g>`;

/* ---------------------------------------------------------------- truck_overtake */
B.truck_overtake = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), solid = str(sc.line) === 'solid';
    const oncoming = isTrue(sc.oncoming_traffic), vis = str(sc.visibility);
    ctx.label(sc.label || '');
    const s = ruralScene(ctx, null, { centre: solid ? 'solid' : 'broken' });
    if (solid) s.prop(ART.signNoOvertake(), 200, ROAD.top - 46);
    s.scatter(24);
    if (vis === 'dust') ctx.haze();
    const T = ctx.stage.actor(ART.truck('#d35400'), 290, U, 0);
    T.to(0.2, 9.2, 1020, U, 'linear');
    const tx = t => 290 + (clamp(t, 0.2, 9.2) - 0.2) * (730 / 9); // truck centre
    const L = learnerCar(ctx, 120, U);
    autoSpeedo(ctx, L, 80, 130, null);
    let O = null;
    if (oncoming || vis === 'dust') { O = otherCar(ctx, '#7f8c8d', 1080, D, 180); O.cls('lights-low'); }
    ctx.say(0.1, 'The learner is behind a slow truck' + (solid ? ' on a SOLID barrier line.' : '.'), 'The learner is behind a slow truck…');

    if (act === 'wait') {
        L.to(0.2, 9.2, 120 + 730, U, 'linear');
        if (O) O.to(0.4, 4.6, -120, D, 'linear');
        ctx.say(1.2, 'The driver stays behind the truck and does not overtake.', 'Watch the driver.');
        ctx.good(2.6, 300, U, 'Waited for a safe place');
        ctx.tl.end(5.4);
        return;
    }
    const t0 = 1.2;
    L.to(0.2, t0, 120 + (t0 - .2) * 81, U, 'linear');
    ctx.ind(t0 - .4, L, 'right');
    const xs = 120 + (t0 - .2) * 81;
    L.path(t0, t0 + 1.0, `M${xs} ${U} C ${xs + 60} ${U}, ${xs + 70} ${D}, ${xs + 140} ${D}`, 'linear');
    ctx.say(t0, 'The driver signals right and pulls out to overtake…', 'The driver pulls out to overtake…');
    if (oncoming || vis === 'dust') {
        O.to(0.6, t0 + 1.9, xs + 330, D, 'linear');
        ctx.brake(t0 + 1.6, O).on(t0 + 1.5, () => O.cls('lights-high')).horn(t0 + 1.6, O);
        L.to(t0 + 1.0, t0 + 1.5, xs + 200, D, 'linear');
        L.path(t0 + 1.5, t0 + 2.3, `M${xs + 200} ${D} C ${xs + 230} ${D}, ${xs + 200} ${U}, ${xs + 150} ${U}`, 'out');
        ctx.brake(t0 + 1.5, L).ind(t0 + 1.5, L, 'left');
        ctx.danger(t0 + 1.8, xs + 260, ROAD.mid, vis === 'dust' ? 'Oncoming car hidden in dust' : 'Head-on danger');
        ctx.say(t0 + 1.6, 'An oncoming car appears; the driver has to brake and cut back in.', 'Watch the driver.');
        O.to(t0 + 2.4, t0 + 5.0, -140, D, 'in');
        ctx.brake(t0 + 2.4, O, false);
        ctx.ind(t0 + 3.0, L, null); ctx.brake(t0 + 3.0, L, false);
        const backX = xs + 150;
        L.to(t0 + 3.0, 9.2, tx(9.2) - 230, U, 'linear');
        return;
    }
    // Pass the truck.
    const pass = act === 'cut_in' ? 4.2 : 4.9;
    L.to(t0 + 1.0, pass, tx(pass) + (act === 'cut_in' ? 92 : 170), D, 'linear');
    ctx.ind(pass - .5, L, 'left');
    const px = tx(pass) + (act === 'cut_in' ? 92 : 170);
    if (act === 'cut_in') {
        L.path(pass, pass + .6, `M${px} ${D} C ${px + 30} ${D}, ${px + 40} ${U}, ${px + 70} ${U}`, 'linear');
        ctx.brake(pass + .4, T).horn(pass + .4, T);
        ctx.danger(pass + .5, px + 20, U, 'Cut in too close');
        ctx.say(pass, 'The driver cuts back in right in front of the truck.', 'The driver returns to the left lane.');
        L.to(pass + .6, 9.2, 1100, U, 'in');
    } else {
        L.path(pass, pass + 1.2, `M${px} ${D} C ${px + 70} ${D}, ${px + 80} ${U}, ${px + 160} ${U}`, 'linear');
        ctx.say(pass, 'The driver signals left and returns once the truck is visible in the mirror.', 'The driver returns to the left lane.');
        if (solid) ctx.danger(2.6, 560, ROAD.mid, 'Crossed a solid line');
        else ctx.good(pass + 1.0, px + 100, U, 'Safe overtake');
        L.to(pass + 1.2, 9.2, 1100, U, 'linear');
    }
    ctx.ind(pass + 1.4, L, null);
};

/* ---------------------------------------------------------------- roundabout */
B.roundabout = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), exit = parseInt(sc.exit, 10) || 2;
    const dir = str(sc.direction) || 'clockwise', signals = sc.signals == null ? true : isTrue(sc.signals);
    const traffic = isTrue(sc.traffic_from_right);
    ctx.label(sc.label || '');
    const C = { x: 480, y: 200, rIsland: 46, rOuter: 112 }, R = 80;
    const s = ctx.scene; s.ground('grass'); s.roundabout(C);
    s.prop(ART.signRoundabout(), 250, ROAD.top - 46);
    s.prop(ART.signYield(), 330, ROAD.top - 46);
    s.yieldLineEast(C.x - C.rOuter - 6);
    s.scatter(16, ['acacia', 'bush', 'bush']);
    const P = deg => { const r = deg * Math.PI / 180; return [C.x + R * Math.cos(r), C.y + R * Math.sin(r)]; };
    const pt = deg => P(deg).map(v => v.toFixed(1)).join(' ');
    const arc = (from, to, cw) => { const [x, y] = P(to); const sweep = Math.abs(to - from); return `A ${R} ${R} 0 ${sweep > 180 ? 1 : 0} ${cw ? 1 : 0} ${x.toFixed(1)} ${y.toFixed(1)}`; };
    const yieldX = C.x - C.rOuter - 44;

    const L = learnerCar(ctx, -60, U);
    autoSpeedo(ctx, L, 40, 220, null);
    const circ = traffic || dir !== 'clockwise' ? otherCar(ctx, '#e67e22', 455, 470, -90) : null;
    if (circ) {
        // Traffic from the right: enters from the south and circulates clockwise past the learner's entry.
        circ.path(0.0, 1.3, `M455 470 L455 ${C.y + C.rOuter + 10}`, 'linear');
        circ.path(1.3, 3.8, `M455 ${C.y + C.rOuter + 10} Q 455 ${P(108)[1]} ${pt(118)} ${arc(118, 245, true)} Q ${pt(252)} 455 ${C.y - C.rOuter - 6}`, 'linear');
        circ.path(3.8, 5.2, `M455 ${C.y - C.rOuter - 6} L455 -90`, 'linear');
    }
    const exitAng = { 1: 252, 2: 342, 3: 432 }[exit] || 342;
    const exitTail = { 1: `Q ${pt(252)} 455 ${C.y - C.rOuter - 6} L 455 -90`, 2: `Q ${pt(342)} ${C.x + C.rOuter + 10} ${U} L 1060 ${U}`, 3: `Q ${pt(72)} 505 ${C.y + C.rOuter + 10} L 505 490` }[exit];
    const ringPath = (startAng) => `M${yieldX} ${U} L ${C.x - C.rOuter - 10} ${U} Q ${pt(198)} ${pt(205)} ${arc(205, exitAng - 7, true)} ${exitTail}`;
    const sigIn = exit === 1 ? 'left' : exit === 3 ? 'right' : null;
    if (signals && sigIn) ctx.ind(0.4, L, sigIn);

    ctx.say(0.1, 'Approaching a roundabout. Traffic circulates CLOCKWISE; give way to traffic from the right.', 'Approaching a roundabout…');
    if (dir !== 'clockwise') {
        L.to(0.2, 2.0, yieldX, U, 'out');
        L.path(2.2, 4.4, `M${yieldX} ${U} L ${C.x - C.rOuter - 10} ${U} Q ${pt(190)} ${pt(170)} ${arc(170, 60, false)}`, 'linear');
        ctx.say(2.3, 'The driver turns RIGHT and drives around the wrong way (anticlockwise).', 'Watch the driver.');
        ctx.brake(3.4, circ).horn(3.4, circ);
        ctx.danger(3.4, C.x - 40, C.y + 70, 'Wrong way!');
        return;
    }
    if (act === 'yield' && traffic) {
        L.to(0.2, 2.2, yieldX, U, 'out'); ctx.brake(1.4, L);
        ctx.say(2.0, 'A vehicle is coming from the RIGHT. The driver stops and gives way.', 'Watch the driver at the yield line.');
        ctx.good(2.6, yieldX, U, 'Gave way to the right');
        ctx.brake(3.4, L, false);
        L.path(3.4, 7.0, ringPath(), 'soft');
    } else if (act === 'no_yield') {
        L.to(0.2, 1.8, yieldX, U, 'linear');
        L.path(1.8, 5.4, ringPath(), 'linear');
        ctx.say(1.9, 'The driver enters without giving way to the vehicle from the right.', 'Watch the driver at the yield line.');
        if (circ) { circ.segs = []; circ.path(0.0, 1.3, `M455 470 L455 ${C.y + C.rOuter + 10}`, 'linear'); circ.path(1.3, 2.4, `M455 ${C.y + C.rOuter + 10} Q 455 ${P(108)[1]} ${pt(118)} ${arc(118, 160, true)}`, 'out'); ctx.brake(2.2, circ).horn(2.3, circ); circ.path(4.6, 7.4, `M${pt(160)} ${arc(160, 245, true)} Q ${pt(252)} 455 ${C.y - C.rOuter - 6} L 455 -90`, 'in'); ctx.brake(4.6, circ, false); }
        ctx.danger(2.4, C.x - 70, C.y + 10, 'Failed to yield');
    } else {
        L.to(0.2, 1.9, yieldX + 4, U, 'out');
        ctx.say(1.2, 'The driver slows, looks RIGHT, sees it is clear and enters.', 'Watch the driver at the yield line.');
        L.path(1.9, 5.6, ringPath(), 'soft');
    }
    const tExit = act === 'yield' ? 6.0 : 4.6;
    if (signals) {
        if (exit === 3) ctx.ind(tExit - .4, L, 'left');
        if (exit === 2) ctx.ind(tExit - .8, L, 'left');
        ctx.ind(tExit + 1, L, null);
        ctx.say(tExit - .6, 'The driver signals LEFT before taking exit ' + exit + '.', null);
        if (act !== 'no_yield') ctx.good(tExit, C.x, C.y - 30, 'Signalled before exiting');
    } else {
        ctx.say(tExit - .6, 'The driver exits without signalling left.', null);
        if (act !== 'no_yield') ctx.danger(tExit, C.x, C.y - 30, 'No exit signal');
    }
};

/* ---------------------------------------------------------------- pedestrian_crossing */
B.pedestrian_crossing = ctx => {
    const sc = ctx.sc, ped = str(sc.pedestrian), act = str(sc.driver_action);
    ctx.label(sc.label || '');
    const s = urbanScene(ctx); urbanBlocks(s, 620);
    const ZX = 620;
    s.zebra(ZX);
    s.stopLineEast(ZX - 40);
    s.prop(ART.signWarn(ART.symPed), 470, ROAD.top - 46);
    s.scatter(4);
    const P1 = ctx.stage.actor(ART.person('#6c5ce7', '#7a4a2c'), ZX - 6, ROAD.top - 16, 90);
    const L = learnerCar(ctx, -60, U);
    autoSpeedo(ctx, L, 50, 300, null);
    const stopX = ZX - 80;
    ctx.say(0.1, ped === 'none' ? 'Approaching a pedestrian crossing…' : 'A pedestrian is at the zebra crossing…', 'Approaching a zebra crossing…');
    if (act === 'overtake_stopped') {
        const V = otherCar(ctx, '#7f8c8d', stopX, U, 0); ctx.brake(0, V);
        L.home.x = -260; L.set(-260, U, 0);
        P1.to(0.8, 2.6, ZX - 6, ROAD.mid + 6, 'linear');
        L.to(0.2, 1.2, 300, U, 'linear');
        ctx.ind(0.9, L, 'right');
        L.path(1.2, 2.4, `M300 ${U} C 360 ${U}, 380 ${D}, 450 ${D} L ${ZX - 52} ${D}`, 'linear');
        P1.to(2.6, 3.0, ZX - 6, D - 2, 'linear');
        ctx.brake(2.4, L).skid(2.4, `M ${ZX - 110} ${D - 9} H ${ZX - 52} M ${ZX - 110} ${D + 9} H ${ZX - 52}`);
        P1.to(3.0, 3.3, ZX - 6, ROAD.mid - 8, 'out', { keepRot: 90 });
        ctx.say(1.3, 'The driver overtakes a car that has stopped at the crossing…', 'The driver passes the stopped car…');
        ctx.danger(2.7, ZX, D, 'Hidden pedestrian!');
        P1.to(3.8, 5.0, ZX - 6, ROAD.bottom + 16, 'linear');
        ctx.brake(5.4, V, false); V.to(5.4, 7.2, 1080, U, 'in');
        ctx.brake(5.4, L, false); ctx.ind(5.4, L, null);
        L.path(5.4, 7.6, `M${ZX - 52} ${D} C ${ZX + 60} ${D}, ${ZX + 80} ${U}, ${ZX + 150} ${U} L 1060 ${U}`, 'in');
        return;
    }
    if (act === 'drive_through') {
        P1.to(0.8, 1.9, ZX - 6, ROAD.top + 20, 'linear');
        L.to(0.2, 3.4, 1040, U, 'linear');
        P1.to(1.9, 2.2, ZX - 6, ROAD.top - 16, 'out', { keepRot: 90 });
        ctx.say(1.6, 'The driver does not stop for the pedestrian.', 'Watch the driver.');
        ctx.danger(2.1, ZX, U, 'Must stop for pedestrians');
        P1.to(3.8, 6.0, ZX - 6, ROAD.bottom + 16, 'linear');
        return;
    }
    // stop / slow and stop
    L.to(0.2, 2.4, stopX, U, 'out'); ctx.brake(1.4, L);
    const startCross = ped === 'waiting' ? 2.6 : 0.8;
    P1.to(startCross, startCross + 3.2, ZX - 6, ROAD.bottom + 16, 'linear');
    ctx.say(1.8, ped === 'waiting' ? 'The pedestrian is waiting; the driver stops and lets them cross.' : 'The driver stops before the crossing and waits.', 'Watch the driver.');
    ctx.good(2.6, stopX, U, 'Gave way to pedestrian');
    const go = startCross + 3.0;
    ctx.brake(go, L, false); L.to(go, go + 2.2, 1040, U, 'in');
};

/* ---------------------------------------------------------------- yield_sign (T-junction) */
B.yield_sign = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), traffic = isTrue(sc.main_road_traffic);
    ctx.label(sc.label || '');
    const s = ruralScene(ctx);
    s.vRoad({ from: ROAD.bottom, to: H });
    s.yieldLineNorth(ROAD.bottom + 10);
    s.prop(ART.signYield(), CROSS.left - 32, ROAD.bottom + 18);
    s.scatter(22);
    const L = learnerCar(ctx, CROSS.nb, 470, -90);
    autoSpeedo(ctx, L, 50, 300, null);
    const M = traffic ? otherCar(ctx, '#c0392b', 1080, D, 180) : null;
    const E = otherCar(ctx, '#16a085', -300, U, 0);
    E.to(0.0, 5.0, 1100, U, 'linear');
    const waitY = ROAD.bottom + 46;
    const turn = t0 => L.path(t0, t0 + 2.6, `M${CROSS.nb} ${waitY} L ${CROSS.nb} ${ROAD.bottom + 10} Q ${CROSS.nb} ${D} ${CROSS.nb - 60} ${D} L -100 ${D}`, 'in');
    ctx.say(0.1, 'Joining the main road at a YIELD sign, turning left…', 'Joining the main road at a YIELD sign…');
    if (traffic && act === 'yield') {
        M.to(0.3, 4.2, -140, D, 'linear');
        L.to(0.2, 2.2, CROSS.nb, waitY, 'out'); ctx.brake(1.4, L);
        ctx.say(2.0, 'A car is approaching from the RIGHT, so the driver stops and gives way.', 'Watch the driver.');
        ctx.good(2.5, CROSS.nb, waitY, 'Gave way');
        ctx.ind(0.8, L, 'left');
        ctx.brake(4.0, L, false); turn(4.0); ctx.ind(6.4, L, null);
    } else if (traffic) {
        M.to(0.3, 2.2, 760, D, 'linear');
        ctx.ind(0.8, L, 'left');
        L.to(0.2, 1.8, CROSS.nb, waitY, 'linear'); turn(1.8);
        M.to(2.2, 2.9, 700, D, 'out'); ctx.brake(2.2, M).horn(2.3, M).skid(2.3, `M790 ${D - 9} H705 M790 ${D + 9} H705`);
        ctx.danger(2.6, 640, D, 'Pulled out in front');
        ctx.say(2.0, 'The driver pulls out without giving way.', 'Watch the driver.');
        ctx.brake(4.0, M, false); M.to(4.0, 7.0, -140, D, 'in');
    } else {
        ctx.ind(0.6, L, 'left');
        L.to(0.2, 2.2, CROSS.nb, waitY + 8, 'out');
        ctx.say(1.4, 'The driver slows, looks right and left, and sees the road is clear.', 'Watch the driver.');
        turn(2.4); ctx.good(2.6, CROSS.nb, waitY, 'Road clear — may proceed');
        ctx.ind(4.8, L, null);
    }
};

/* ---------------------------------------------------------------- railway_crossing */
B.railway_crossing = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), train = str(sc.train) === 'approaching';
    const lightsOn = isTrue(sc.warning_lights);
    ctx.label(sc.label || '');
    const RX = 660;
    const s = ruralScene(ctx);
    s.railway(RX);
    s.stopLineEast(RX - 70);
    s.prop(ART.signRail(), RX - 120, ROAD.top - 48);
    s.prop(ART.signStop(), RX - 175, ROAD.top - 46);
    const rl = ctx.stage.add('props', ART.railLights(), { transform: `translate(${RX - 64} ${ROAD.top - 48})`, class: 'tmx-rl' });
    s.scatter(24);
    const lightsAt = t => ctx.on(t, () => rl.classList.add('on'));
    const lightsOff = t => ctx.on(t, () => rl.classList.remove('on'));
    if (lightsOn) lightsAt(0.1);
    const TR = ctx.stage.actor(ART.train(4), RX, -150, 90);
    const L = learnerCar(ctx, -60, U);
    autoSpeedo(ctx, L, 60, 300, null);
    const stopX = RX - 110;
    ctx.say(0.1, train ? 'A train is approaching the level crossing…' : 'Approaching a railway level crossing…', 'Approaching a railway level crossing…');
    if (act === 'stop_on_tracks') {
        const Q = otherCar(ctx, '#7f8c8d', RX + 120, U, 0); ctx.brake(0, Q);
        L.to(0.2, 2.6, RX, U, 'out'); ctx.brake(1.8, L);
        ctx.say(2.4, 'Traffic ahead is queued, so the driver stops ON the railway line.', 'Watch where the driver stops.');
        lightsAt(2.8);
        TR.to(3.0, 4.6, RX, -10, 'out'); ctx.horn(3.2, TR);
        ctx.danger(3.2, RX, U, 'Stopped on the tracks!');
        ctx.brake(4.0, Q, false); Q.to(4.0, 5.6, 1100, U, 'in');
        ctx.brake(4.3, L, false); L.to(4.3, 6.2, 1040, U, 'in');
        TR.to(5.0, 8.6, RX, 900, 'in');
        lightsOff(8.8);
        return;
    }
    if (act === 'drive_through' && train) {
        L.to(0.2, 2.9, 1040, U, 'linear');
        TR.to(0.4, 2.5, RX, 60, 'linear'); ctx.horn(1.6, TR);
        TR.to(2.5, 6.0, RX, 900, 'linear');
        ctx.say(1.2, 'The driver ignores the warning and races across in front of the train.', 'Watch the driver.');
        ctx.danger(1.9, RX, U, 'Near miss with a train');
        lightsOff(6.4);
        return;
    }
    L.to(0.2, 2.4, stopX, U, 'out'); ctx.brake(1.4, L);
    if (train) {
        TR.to(1.4, 6.4, RX, 900, 'linear'); ctx.horn(2.0, TR);
        ctx.say(2.2, 'The driver stops behind the stop line and waits for the train to pass.', 'The driver reaches the crossing.');
        ctx.good(2.6, stopX, U, 'Stopped and waited');
        lightsOff(6.6);
        ctx.brake(6.8, L, false); L.to(6.8, 8.8, 1040, U, 'in');
    } else {
        TR.set(RX, -600, 90);
        ctx.say(2.2, 'The driver stops, looks both ways along the line, then crosses.', 'The driver reaches the crossing.');
        ctx.good(2.8, stopX, U, 'Stop, look, listen');
        ctx.brake(3.4, L, false); L.to(3.4, 5.6, 1040, U, 'in');
    }
};

/* ---------------------------------------------------------------- following_distance */
B.following_distance = ctx => {
    const sc = ctx.sc, act = str(sc.driver_action), wet = str(sc.weather) === 'rain';
    const gap = parseFloat(sc.gap_seconds) || 2, spd = parseInt(sc.vehicle_speed, 10) || 100;
    ctx.label(sc.label || '');
    const s = ruralScene(ctx); s.scatter(22);
    if (wet) ctx.rain();
    const PX = 62;             // pixels per second of following distance
    const gapPx = gap * PX + 76;
    const leadStart = 120 + gapPx;
    const lead = ctx.stage.actor(ART.bakkie('#c0392b'), leadStart, U, 0);
    const L = learnerCar(ctx, 120, U);
    const v = 150;               // cruise px/s
    autoSpeedo(ctx, L, spd, v, null);
    const tb = 2.6;
    lead.to(0.0, tb, leadStart + v * tb, U, 'linear');
    // 'out' easing starts at 3x its average speed, so size the braking
    // distance to begin at cruise speed (d = v * T / 3).
    const leadStop = leadStart + v * tb + v * 1.1 / 3;
    lead.to(tb, tb + 1.1, leadStop, U, 'out'); ctx.brake(tb, lead);
    const react = tb + .5;                       // learner reaction time
    L.to(0.0, react, 120 + v * react, U, 'linear');
    ctx.say(0.1, `${wet ? 'Wet road in the rain' : 'Dry road'}: following ${gap} second${gap === 1 ? '' : 's'} behind another vehicle.`, `${wet ? 'Wet road in the rain' : 'Dry road'}: following another vehicle…`);
    ctx.say(tb, 'The vehicle ahead brakes suddenly!', 'The vehicle ahead brakes suddenly!');
    const enough = wet ? gap >= 4 : gap >= 2;
    ctx.brake(react, L);
    const lx = 120 + v * react;
    const stopIn = target => Math.max(.6, 3 * (target - lx) / v);
    if (act === 'brake_hard') {
        const tgt = leadStop - 130;
        L.path(react, react + 1.7, `M${lx} ${U} C ${lx + 60} ${U}, ${tgt - 40} ${U + 26}, ${tgt} ${U + 30}`, 'out');
        L.wobble = t => t > react ? Math.min(28, (t - react) * 30) : 0;
        ctx.skid(react + .1, `M${lx} ${U - 9} C ${lx + 60} ${U - 9}, ${tgt - 40} ${U + 16}, ${tgt - 10} ${U + 22}`);
        ctx.danger(react + .8, tgt, U, 'Skidding on a wet road');
        ctx.say(react + .1, 'Too fast for the wet road: hard braking makes the car skid.', null);
    } else if (enough) {
        const tgt = leadStop - 116;
        L.to(react, react + stopIn(tgt), tgt, U, 'out');
        ctx.good(react + 1.2, tgt, U, 'Stopped with space to spare');
    } else {
        const tgt = leadStop - 80;
        L.to(react, react + Math.min(1.0, stopIn(tgt)), tgt, U, 'out');
        ctx.skid(react + .1, `M${lx} ${U - 9} H${tgt} M${lx} ${U + 9} H${tgt}`);
        L.wobble = t => (t > react + .2 && t < react + 1.1) ? Math.sin(t * 22) * 4 : 0;
        ctx.danger(react + .8, tgt + 20, U, 'Far too close!');
    }
    // Following-time ruler between the two vehicles (shown while cruising).
    const ruler = ctx.stage.add('hud', `<g class="tmx-ruler"><path class="r" stroke-width="3" fill="none"/><rect class="b" width="58" height="22" rx="11"/><text class="tx" font-size="12" font-weight="800" fill="#fff" text-anchor="middle" font-family="Segoe UI,Arial"></text></g>`);
    const rp = ruler.querySelector('.r'), rb = ruler.querySelector('.b'), rt = ruler.querySelector('.tx');
    const okColor = enough && act !== 'brake_hard' ? '#10a77a' : '#e5394a';
    const col = ctx.study ? okColor : '#147df5';
    rt.textContent = gap + ' s';
    ctx.tl.each(t => {
        const x1 = L.x + 35, x2 = lead.x - 38, y = U - 30;
        if (t > tb || x2 - x1 < 4) { ruler.style.display = 'none'; return; }
        ruler.style.display = '';
        rp.setAttribute('d', `M${x1} ${y + 6} V${y} H${x2} V${y + 6}`); rp.setAttribute('stroke', col);
        const cx = (x1 + x2) / 2;
        rb.setAttribute('x', cx - 29); rb.setAttribute('y', y - 26); rb.setAttribute('fill', col);
        rt.setAttribute('x', cx); rt.setAttribute('y', y - 11);
    });
};

/* Old "overtake" type (not used by the current database) reuses the truck scene. */
B.overtake = ctx => {
    ctx.sc = Object.assign({}, ctx.sc, { oncoming_traffic: isTrue(ctx.sc.oncoming), driver_action: 'overtake' });
    return B.truck_overtake(ctx);
};

Object.assign(TMX, { B });
/* ==========================================================================
   STYLES (injected once)
   ========================================================================== */
const CSS = `
html body .sim-screen.tmx-on{padding:0!important;min-height:0!important;height:auto!important;display:block!important;background:#1b2733!important;box-shadow:none!important;overflow:hidden}
html body .sim-screen.tmx-on::before,html body .sim-screen.tmx-on::after{display:none!important;content:none!important}
.tmx-svg{display:block;width:100%;height:auto;font-family:"Segoe UI",Arial,sans-serif;user-select:none}
.tmx-pop,.tmx-ping,.tmx-dust circle,.siren-waves circle,.tmx-needle,.tmx-rev,.walker .leg-a,.walker .leg-b{transform-box:fill-box}
.tmx-actor .tail{fill:#7a1515}
.tmx-actor.braking .tail{fill:#ff2b2b;filter:drop-shadow(0 0 4px #ff2b2b)}
.tmx-actor .ind{fill:#ffb000;opacity:0}
.tmx-actor.ind-l-on .ind-l,.tmx-actor.ind-r-on .ind-r,.tmx-actor.hazard-on .ind{animation:tmxBlink .7s steps(1) infinite}
@keyframes tmxBlink{0%{opacity:1}50%{opacity:.06}}
.tmx-actor .beam{display:none}
.tmx-actor.lights-high .beam-high,.tmx-actor.lights-low .beam-low,.tmx-actor.lights-fog .beam-fog{display:inline}
.tmx-actor.glare .beam-high path{opacity:1;filter:blur(6px)}
.tmx-actor.dazzled{filter:drop-shadow(0 0 12px #fff) drop-shadow(0 0 6px #fff)}
.tmx-actor .siren-r{fill:#7a1b1b}.tmx-actor .siren-b{fill:#1b2f7a}
.tmx-actor.siren-on .siren-r{animation:tmxSirR .5s steps(1) infinite}
.tmx-actor.siren-on .siren-b{animation:tmxSirB .5s steps(1) infinite}
@keyframes tmxSirR{0%{fill:#ff2b2b}50%{fill:#7a1b1b}}
@keyframes tmxSirB{0%{fill:#1b2f7a}50%{fill:#2d6bff}}
.tmx-actor .siren-waves{display:none}
.tmx-actor.siren-on .siren-waves{display:inline}
.tmx-actor.siren-on .siren-waves circle{transform-origin:center;animation:tmxWave 1s ease-out infinite;opacity:0}
.tmx-actor.siren-on .siren-waves circle+circle{animation-delay:.5s}
@keyframes tmxWave{0%{transform:scale(.4);opacity:.9}100%{transform:scale(1.8);opacity:0}}
.tmx-actor .walker .leg-a{animation:tmxStepA .45s ease-in-out infinite alternate}
.tmx-actor .walker .leg-b{animation:tmxStepA .45s ease-in-out infinite alternate-reverse}
@keyframes tmxStepA{0%{transform:translateX(-4px)}100%{transform:translateX(4px)}}
.tmx-dust circle{transform-origin:center;animation:tmxDustP 1.1s ease-in-out infinite alternate}
.tmx-dust circle:nth-child(2n){animation-duration:1.5s}
@keyframes tmxDustP{0%{transform:scale(.82);opacity:.7}100%{transform:scale(1.18);opacity:1}}
.tmx-pop{animation:tmxPop .35s cubic-bezier(.2,1.4,.4,1);transform-origin:center}
@keyframes tmxPop{0%{transform:scale(.3);opacity:0}100%{transform:scale(1);opacity:1}}
.tmx-fade{opacity:0;transition:opacity .7s}
.tmx-ping{transform-origin:center;animation:tmxPing 1.1s ease-out infinite}
@keyframes tmxPing{0%{transform:scale(.5);opacity:1}100%{transform:scale(1.5);opacity:0}}
.tmx-horn{animation:tmxHorn .4s ease-out infinite alternate}
@keyframes tmxHorn{0%{opacity:.3}100%{opacity:1}}
.tmx-rain{animation:tmxRain .45s linear infinite}
@keyframes tmxRain{0%{transform:translate(0,0)}100%{transform:translate(0,34px)}}
.tmx-skid{animation:tmxSkid .5s ease-out}
@keyframes tmxSkid{0%{opacity:0}100%{opacity:.55}}
.tmx-tl .tl{fill:#2f3439}
.tmx-tl.state-red .tl-red{fill:#ff3b30;filter:drop-shadow(0 0 6px #ff3b30)}
.tmx-tl.state-amber .tl-amber,.tmx-tl.state-yellow .tl-amber{fill:#ffb000;filter:drop-shadow(0 0 6px #ffb000)}
.tmx-tl.state-green .tl-green{fill:#22d36b;filter:drop-shadow(0 0 6px #22d36b)}
.tmx-rl .rl{fill:#3a1414}
.tmx-rl.on .rl-a{animation:tmxRl 1s steps(1) infinite}
.tmx-rl.on .rl-b{animation:tmxRl 1s steps(1) infinite .5s}
@keyframes tmxRl{0%{fill:#ff2b2b;filter:drop-shadow(0 0 6px #ff2b2b)}50%{fill:#3a1414;filter:none}}
.tmx-night .tmx-actor .beam path{opacity:.7}
/* driver's-view cabin */
.tmx-cabin .tmx-road-dash path{stroke-dashoffset:0}
.tmx-cabin.driving .tmx-road-dash path{animation:tmxDash .55s linear infinite}
.tmx-cabin.driving.slowing .tmx-road-dash path{animation-duration:1.8s}
@keyframes tmxDash{to{stroke-dashoffset:-56}}
.tmx-cabin .w circle,.tmx-cabin .w path{fill:#2b2f34}
.tmx-cabin:not(.engine) .w-bat circle{fill:#e5394a}
.tmx-cabin.belt-warn .w-belt circle{fill:#e5394a}
.tmx-cabin.belt-blink .w-belt,.tmx-cabin.oil-warn .w-oil,.tmx-cabin.hazards .w-haz{animation:tmxBlink .7s steps(1) infinite}
.tmx-cabin.oil-warn .w-oil circle{fill:#e5394a}
.tmx-cabin.hazards .w-haz path{fill:#ffb000}
.tmx-cabin .tmx-needle,.tmx-cabin .tmx-rev{transform-origin:100% 0;transition:transform 1.2s ease}
.tmx-cabin.engine .tmx-rev{transform:rotate(40deg)}
.tmx-cabin.driving .tmx-needle{transform:rotate(85deg)}
.tmx-cabin.driving.slowing .tmx-needle{transform:rotate(15deg);transition-duration:1.4s}
.tmx-cabin .tmx-belt,.tmx-cabin .tmx-phone-hand,.tmx-cabin .tmx-phone-mount{display:none}
.tmx-cabin.belt-on .tmx-belt{display:inline;animation:tmxPop .5s ease-out}
.tmx-cabin.belt-on .tmx-belt-off{display:none}
.tmx-cabin.phone-hand .tmx-phone-hand,.tmx-cabin.phone-mount .tmx-phone-mount{display:inline;animation:tmxPop .4s ease-out}
.tmx-cabin.drift .tmx-ground{animation:tmxSway 1.4s ease-in-out infinite alternate}
@keyframes tmxSway{0%{transform:translateX(-26px)}100%{transform:translateX(26px)}}
@media (prefers-reduced-motion:reduce){.tmx-svg *{animation:none!important;transition:none!important}}
`;
function injectCss() {
    if (document.getElementById('tmx-css')) return;
    const st = document.createElement('style');
    st.id = 'tmx-css';
    st.textContent = CSS;
    document.head.appendChild(st);
}

/* ==========================================================================
   PAGE INTEGRATION
   ========================================================================== */
function render(c) {
    const d = c._tmx;
    if (d.tl) d.tl.cancel();
    d.screen.innerHTML = '';
    const stage = new Stage(d.screen, { title: d.title });
    const ctx = new Ctx(stage, d.sc, d.mode, d.status, d.type + JSON.stringify(d.sc));
    B[d.type](ctx);
    stage.fixRefs(stage.svg);
    d.tl = ctx.tl;
    d.stage = stage;
    ctx.tl.seek(0);
    return ctx;
}

function resultBadge(stage, ok) {
    stage.add('hud', `<g class="tmx-pop"><rect x="${W - 214}" y="${H - 62}" width="200" height="48" rx="24" fill="${ok ? '#10a77a' : '#e5394a'}"/>
        <circle cx="${W - 190}" cy="${H - 38}" r="15" fill="#fff"/>
        <path d="${ok ? `M${W - 197} ${H - 38} l5 5 l9 -10` : `M${W - 196} ${H - 44} l12 12 M${W - 184} ${H - 44} l-12 12`}" stroke="${ok ? '#10a77a' : '#e5394a'}" stroke-width="3.5" fill="none" stroke-linecap="round"/>
        <text x="${W - 166}" y="${H - 33}" font-size="15" font-weight="800" fill="#fff" font-family="Segoe UI,Arial">${ok ? 'Correct action' : 'Incorrect action'}</text></g>`);
}

function play(c) {
    const d = c._tmx;
    const ctx = render(c);
    const btn = d.btn;
    btn.disabled = true;
    btn.textContent = 'Playing...';
    if (d.mode === 'study' && d.expl) d.expl.style.display = 'none';
    const tl = ctx.tl;
    tl.play().then(() => {
        if (d.tl !== tl) return;
        btn.disabled = false;
        btn.textContent = 'Replay';
        if (d.mode === 'study') {
            const ok = !!d.sc.is_correct;
            if (d.status) d.status.textContent = ok ? 'Correct action!' : 'Incorrect action!';
            resultBadge(d.stage, ok);
            if (d.expl) d.expl.style.display = 'block';
        } else if (d.status) {
            d.status.textContent = 'Now select your answer below';
        }
    });
}

function setup() {
    const type = window.TM_SIM_TYPE;
    if (!type || !B[type]) return;           // unknown type: keep the original animations
    injectCss();
    document.querySelectorAll('.sim-container').forEach((c, i) => {
        const btn = c.querySelector('.sim-info button');
        const screen = c.querySelector('.sim-screen');
        if (!btn || !screen) return;
        let sc;
        try { sc = JSON.parse(btn.dataset.scenario || '{}'); } catch (e) { return; }
        const mode = btn.dataset.qi != null ? 'quiz' : 'study';
        c._tmx = {
            sc, type, screen, btn, mode,
            status: c.querySelector('.sim-info span'),
            expl: mode === 'study' ? document.getElementById('explanation-study-' + btn.dataset.si) : null,
            title: (window.TM_SIM_TITLE || 'Driving scenario') + ' — scenario ' + (i + 1),
        };
        screen.classList.add('tmx-on');
        try { render(c); } catch (e) { console.error('[TestMate sim]', e); }
    });
    const oldStudy = window.playStudyScenario, oldQuiz = window.playQuizScenario;
    window.playStudyScenario = function (btn) {
        const c = btn.closest('.sim-container');
        if (c && c._tmx) return play(c);
        return oldStudy && oldStudy(btn);
    };
    window.playQuizScenario = function (btn) {
        const c = btn.closest('.sim-container');
        if (c && c._tmx) return play(c);
        return oldQuiz && oldQuiz(btn);
    };
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
else setup();

})();
</script>

<?php echo <<<'TM_REFERENCE_LAYOUT'
<style>
/* =====================================================
   TESTMATE SIMULATIONS - 2026 DESIGN
   Replaces the old "reference layout" patch blocks.
   It no longer resizes the shared header/sidebar, so this
   page now uses the same 74px header and 252px sidebar as
   every other learner page (css/style.css).
   Animation CSS, scene markup and JavaScript are unchanged.
   ===================================================== */
html body.tm-page-reference:not(.tm-sim-embedded) { background: #f4f8fd !important; }
body.tm-page-reference { font-family: "Segoe UI", Arial, sans-serif; color: #102744; }

/* Page width shared by all three simulation screens */
html body.tm-page-reference .sim-head,
html body.tm-page-reference .container {
    box-sizing: border-box;
    width: 100% !important;
    max-width: 1370px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}
html body.tm-page-reference .container { padding: 0 30px 50px !important; }

/* Page banner (matches the dashboard and other pages) */
html body.tm-page-reference .sim-head {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    width: calc(100% - 60px) !important;
    max-width: 1310px !important;
    margin-top: 28px !important;
    margin-bottom: 20px !important;
    padding: 26px 30px;
    border: 1px solid #dce9f8;
    border-radius: 18px;
    background: linear-gradient(105deg, #e9f4ff 0%, #f6faff 54%, #c7e4ff 100%);
}
.sim-head::after {
    content: "";
    position: absolute;
    width: 240px; height: 240px;
    right: -80px; top: -130px;
    border-radius: 50%;
    background: rgba(255,255,255,.4);
    pointer-events: none;
}
.sim-head > * { position: relative; z-index: 1; }
.sim-kicker {
    display: block;
    margin-bottom: 8px;
    color: #2c82c2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
}
.sim-head h1 { margin: 0 0 7px; color: #102744; font-size: clamp(24px, 2.4vw, 32px); line-height: 1.2; font-weight: 800; letter-spacing: -.5px; }
.sim-head p { margin: 0; max-width: 680px; color: #526f8b; font-size: 14px; line-height: 1.6; }
.sim-head-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border: 1px solid #e0ebf7;
    border-radius: 999px;
    background: rgba(255,255,255,.9);
    color: #173652;
    font-size: 13px;
    font-weight: 800;
}
.sim-head-count i { width: 9px; height: 9px; border-radius: 50%; background: #10a77a; }

/* Single-simulation screens use a narrower column; align the banner to it */
html body.tm-page-reference .sim-head.is-narrow { max-width: 940px !important; }

/* Study / Quiz mode switch */
.sim-mode-switch {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    border: 1px solid #dce7f3;
    border-radius: 12px;
    background: #fff;
}
.sim-mode-switch a {
    padding: 9px 16px;
    border-radius: 9px;
    color: #4d6782;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
}
.sim-mode-switch a:hover { background: #f1f7ff; color: #147df5; }
.sim-mode-switch a.is-active { background: #147df5; color: #fff; }
.sim-mode-switch a.is-active.is-quiz { background: #8950dc; }

/* Buttons used on these screens */
html body.tm-page-reference .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 18px;
    border: 1px solid transparent;
    border-radius: 10px !important;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}
html body.tm-page-reference .btn-lg { min-height: 48px; padding: 12px 26px; font-size: 15px; }
html body.tm-page-reference .btn-primary { background: #147df5 !important; border-color: #147df5 !important; color: #fff !important; }
html body.tm-page-reference .btn-primary:hover { background: #0b68d6 !important; }
html body.tm-page-reference .btn-outline { background: #fff !important; border: 1px solid #d6e4f3 !important; color: #1569b8 !important; }
html body.tm-page-reference .btn-outline:hover { background: #eef6ff !important; }

/* ---------- Library: list + player ---------- */
.tm-sim-workspace { display: grid; grid-template-columns: 330px minmax(0, 1fr); gap: 20px; align-items: start; }
.tm-sim-library {
    display: grid;
    gap: 10px;
    max-height: 780px;
    overflow: auto;
    padding: 2px 4px 4px 2px;
}
.tm-sim-library-title { margin: 0 0 2px; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-choice {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) 18px;
    gap: 12px;
    align-items: center;
    width: 100%;
    padding: 12px;
    border: 1px solid #e2eaf3;
    border-radius: 13px;
    background: #fff;
    color: #24425d;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    box-shadow: 0 3px 12px rgba(20,48,76,.04);
    transition: border-color .15s, background .15s, box-shadow .15s;
}
.tm-sim-choice:hover { border-color: #98c5ff; background: #f7fbff; }
.tm-sim-choice.is-active { border-color: #147df5; background: #eef6ff; box-shadow: inset 4px 0 #147df5, 0 6px 16px rgba(20,125,245,.12); }
.tm-sim-thumbnail {
    display: grid;
    place-items: center;
    width: 52px; height: 52px;
    border-radius: 13px;
    background: #f4f8fd;
}
.tm-sim-choice.is-active .tm-sim-thumbnail { background: #fff; }
.tm-sim-thumbnail img { width: 38px; height: 38px; object-fit: contain; }
.tm-sim-choice-copy { min-width: 0; }
.tm-sim-choice strong { display: block; margin-bottom: 3px; color: #102744; font-size: 14px; line-height: 1.35; }
.tm-sim-choice small { display: block; margin-bottom: 3px; color: #10a77a; font-size: 11px; font-weight: 800; }
.tm-sim-choice-copy > span {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    color: #6a7e93;
    font-size: 12px;
    line-height: 1.45;
}
.tm-sim-chevron { color: #9fb2c6; font-size: 20px; line-height: 1; }
.tm-sim-choice.is-active .tm-sim-chevron { color: #147df5; }

.tm-sim-player {
    min-width: 0;
    overflow: hidden;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.tm-sim-player-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #edf1f6;
}
.tm-sim-player-head span { display: block; color: #647b95; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-sim-player-head strong { display: block; margin-top: 2px; color: #102744; font-size: 17px; }
.tm-sim-live { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px; background: #e2f8ec; color: #0b8a5f; font-size: 11px; font-weight: 800; }
.tm-sim-live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
html body .tm-sim-player iframe { display: block; width: 100%; height: 560px; border: 0 !important; border-radius: 0 !important; background: #f4f8fd; transition: height .2s ease; }
html body .tm-sim-player-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    padding: 16px 20px;
    border-top: 1px solid #edf1f6;
    background: #f8fbff;
}
html body .tm-sim-player-footer p { margin: 0; color: #647b95; font-size: 13px; }
.tm-sim-player-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.tm-sim-player-actions .btn { min-height: 42px; padding: 10px 18px !important; font-size: 13px !important; white-space: nowrap; }
.tm-sim-player-actions .btn-primary { background: #8950dc !important; border-color: #8950dc !important; }
.tm-sim-player-actions .btn-primary:hover { background: #7440cf !important; }
.tm-sim-choice:focus-visible, .tm-sim-pager button:focus-visible { outline: 3px solid #76b8ff; outline-offset: 2px; }

/* ---------- Study / quiz scenarios ---------- */
.sim-note {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 20px;
    padding: 14px 18px;
    border: 1px solid #cfe3fb;
    border-left: 4px solid #147df5;
    border-radius: 12px;
    background: #eef6ff;
    color: #35536d;
    font-size: 14px;
    line-height: 1.6;
}
.sim-note strong { color: #0f5fb8; white-space: nowrap; }
.sim-note.is-quiz { border-color: #e2d4f8; border-left-color: #8950dc; background: #f6f1ff; }
.sim-note.is-quiz strong { color: #6b38c2; }

.tm-sim-lesson-card,
.quiz-q-card {
    margin-bottom: 20px !important;
    padding: 18px;
    border: 1px solid #e2eaf3 !important;
    border-radius: 16px !important;
    background: #fff !important;
    box-shadow: 0 5px 18px rgba(20,48,76,.06) !important;
}
.tm-sim-lesson-card > h3 { margin: 0 0 12px !important; color: #102744 !important; font-size: 16px !important; font-weight: 800 !important; }
.tm-sim-lesson-card > h3 span { color: #647b95 !important; }

html body.tm-page-reference .sim-container {
    overflow: hidden;
    margin-bottom: 12px !important;
    border: 1px solid #dce8f5 !important;
    border-radius: 13px !important;
    background: #102744 !important;
}
html body.tm-page-reference .sim-screen { border-radius: 0 !important; }
html body.tm-page-reference .sim-info {
    background: #102744 !important;
    color: #e3eef9 !important;
    padding: 13px 18px !important;
    gap: 16px !important;
}
html body.tm-page-reference .sim-info button {
    background: #147df5 !important;
    color: #fff !important;
    border-radius: 9px !important;
    padding: 9px 20px !important;
    font-weight: 800 !important;
}
html body.tm-page-reference .sim-info button:hover { background: #0b68d6 !important; }
[id^="explanation-study-"] { border-radius: 0 12px 12px 0 !important; }

/* Quiz answer choices (selectAnswer() sets inline colours, so these win with !important) */
.quiz-q-card > p { color: #102744 !important; font-size: 15px !important; font-weight: 700 !important; }
.quiz-q-card .answer-btn {
    display: flex !important;
    align-items: center;
    gap: 12px;
    width: 100%;
    margin: 0 0 9px !important;
    padding: 13px 16px !important;
    border: 1px solid #dce7f2 !important;
    border-radius: 11px !important;
    background: #fff !important;
    color: #264766 !important;
    font-family: inherit;
    font-size: 14px !important;
    text-align: left;
    cursor: pointer;
}
.quiz-q-card .answer-btn:hover { border-color: #98c5ff !important; background: #f5faff !important; }
.quiz-q-card .answer-btn.selected { border-color: #147df5 !important; background: #eef6ff !important; color: #0f4d94 !important; font-weight: 700; box-shadow: inset 4px 0 #147df5; }
.quiz-q-card .answer-btn .btn-icon { font-size: 18px !important; }
.sim-submit-box {
    margin: 6px 0 24px;
    padding: 22px;
    border: 1px solid #e2eaf3;
    border-radius: 16px;
    background: #fff;
    text-align: center;
}
.sim-submit-box p { margin: 10px 0 0; color: #7b8fa4; font-size: 13px; }
#quizValidationMsg { padding: 10px 14px; border-radius: 9px; background: #fdecea; color: #b43333 !important; font-weight: 700; }

.sim-cta { margin: 24px 0 30px; padding: 22px; border-radius: 16px; background: #102744; color: #fff; text-align: center; }
.sim-cta p { margin: 0 0 12px; color: #c4d7ec; font-size: 14px; }
.sim-cta .btn-primary { background: #f5c518 !important; border-color: #f5c518 !important; color: #102744 !important; }
.sim-cta .btn-primary:hover { background: #ffd43b !important; }
.sim-bottom-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin: 0 0 40px; }

/* Pager (built by the script below) */
.tm-sim-lesson[hidden] { display: none !important; }
.tm-sim-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 18px 0 26px; }
.tm-sim-pager button {
    min-width: 110px;
    padding: 11px 20px;
    border: 1px solid #d6e4f3;
    border-radius: 10px;
    background: #fff;
    color: #147df5;
    font-family: inherit;
    font-weight: 800;
    cursor: pointer;
}
.tm-sim-pager button:last-child { background: #147df5; border-color: #147df5; color: #fff; }
.tm-sim-pager button:disabled { opacity: .4; cursor: default; }
.tm-sim-pager span { color: #647b95; font-size: 12px; font-weight: 700; }

/* ---------- Quiz results ---------- */
.sim-result {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 18px;
    padding: 26px 28px;
    border-radius: 18px;
    color: #fff;
}
.sim-result.is-pass { background: linear-gradient(120deg, #0b8a5f, #10a77a); }
.sim-result.is-fail { background: linear-gradient(120deg, #b6303e, #e5394a); }
.sim-result-ring {
    display: grid;
    place-items: center;
    flex: 0 0 104px;
    width: 104px; height: 104px;
    border-radius: 50%;
    background: conic-gradient(#fff var(--pct), rgba(255,255,255,.25) 0);
}
.sim-result-ring span { display: grid; place-items: center; width: 84px; height: 84px; border-radius: 50%; background: rgba(0,0,0,.18); font-size: 24px; font-weight: 800; }
.sim-result strong { display: block; font-size: 30px; font-weight: 800; }
.sim-result p { margin: 4px 0 0; color: rgba(255,255,255,.9); font-size: 14px; }
.sim-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
.sim-section-title { margin: 0 0 14px; color: #102744; font-size: 19px; font-weight: 800; }
.sim-review {
    margin-bottom: 16px;
    padding: 20px;
    border: 1px solid #e2eaf3;
    border-left: 5px solid #10a77a;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(20,48,76,.06);
}
.sim-review.is-wrong { border-left-color: #e5394a; }
.sim-review-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
.sim-review-head span { color: #647b95; font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.sim-tag { padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 800; }
.sim-tag.is-correct { background: #dff7ed; color: #0b8a5f; }
.sim-tag.is-wrong { background: #fdeaea; color: #b93434; }
.sim-review-replay { margin-bottom: 14px; padding: 14px; border-radius: 11px; background: #f4f8fd; text-align: center; }
.sim-review-question { margin: 0 0 12px; color: #102744; font-size: 15px; font-weight: 700; }
.sim-review-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.sim-review-option { padding: 12px 14px; border: 1px solid #e1eaf3; border-radius: 10px; background: #f7faff; color: #35536d; font-size: 14px; font-weight: 600; text-align: center; }
.sim-review-option.is-correct { border-color: #a6e5c6; background: #e5f8ef; color: #0b7a54; }
.sim-review-option.is-wrong { border-color: #edbfc2; background: #ffeded; color: #b43333; }
.sim-review-explain { padding: 12px 15px; border-left: 4px solid #147df5; border-radius: 0 10px 10px 0; background: #eef6ff; color: #35536d; font-size: 13px; line-height: 1.65; }

/* ---------- Embedded study player (inside the library iframe) ---------- */
html body.tm-page-reference.tm-sim-embedded,
html body.tm-page-reference.has-sidebar.tm-sim-embedded { padding: 0 !important; margin: 0 !important; min-height: 0 !important; background: #f4f8fd !important; }
body.tm-sim-embedded .navbar,
body.tm-sim-embedded .tm-sidebar,
body.tm-sim-embedded .tm-mobile-overlay,
body.tm-sim-embedded .page-header,
body.tm-sim-embedded .sim-head,
body.tm-sim-embedded footer,
body.tm-sim-embedded .footer,
body.tm-sim-embedded .tm-footer { display: none !important; }
html body.tm-page-reference.tm-sim-embedded .container { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 16px !important; }
body.tm-sim-embedded .sim-screen { height: 340px !important; min-height: 0 !important; box-sizing: border-box; }
body.tm-sim-embedded .tm-sim-lesson-card { margin-bottom: 12px !important; box-shadow: none !important; }
body.tm-sim-embedded .tm-sim-lesson > h3 { font-size: 15px !important; }
body.tm-sim-embedded .tm-sim-intro,
body.tm-sim-embedded .tm-sim-end-links { display: none !important; }
body.tm-sim-embedded .tm-sim-pager { margin: 12px 0 4px; }

/* ---------- Responsive ---------- */
@media (max-width: 1150px) {
    .tm-sim-workspace { grid-template-columns: 270px minmax(0, 1fr); gap: 16px; }
    .tm-sim-choice { grid-template-columns: 44px minmax(0, 1fr); }
    .tm-sim-chevron { display: none; }
    .tm-sim-thumbnail { width: 44px; height: 44px; }
    .tm-sim-thumbnail img { width: 32px; height: 32px; }
}
@media (max-width: 760px) {
    html body.tm-page-reference .container { padding: 0 16px 40px !important; }
    html body.tm-page-reference .sim-head { width: calc(100% - 32px) !important; margin-top: 18px !important; padding: 22px 20px; }
    .tm-sim-workspace { display: flex; flex-direction: column; }
    .tm-sim-player { width: 100%; order: 0; }
    .tm-sim-library { width: 100%; order: 1; max-height: 440px; }
    .tm-sim-player iframe { height: 660px; }
    .tm-sim-player-footer { flex-direction: column; align-items: stretch; }
    .tm-sim-player-actions .btn { flex: 1; }
    .sim-result { flex-direction: column; text-align: center; }
    .sim-review-options { grid-template-columns: 1fr; }
    .sim-note { flex-direction: column; gap: 4px; }
    body.tm-sim-embedded .sim-screen { height: 270px !important; }
}
</style><script>document.body.classList.add("tm-page-reference");</script>
<script>
/* Fit the embedded simulation player to its content (same-origin iframe). */
(function () {
    var frame = document.getElementById('tmSimFrame');
    if (!frame) return;
    function fit() {
        try {
            var doc = frame.contentDocument;
            var box = doc && doc.querySelector('.container');
            if (box) frame.style.height = Math.max(380, Math.ceil(box.getBoundingClientRect().height) + 4) + 'px';
        } catch (e) {}
    }
    frame.addEventListener('load', function () {
        fit();
        setTimeout(fit, 300);
        try {
            var win = frame.contentWindow;
            if (win.ResizeObserver) new win.ResizeObserver(fit).observe(frame.contentDocument.body);
        } catch (e) {}
    });
})();
</script>
<script>
(function(){
    const choices=Array.from(document.querySelectorAll('.tm-sim-choice'));
    const frame=document.getElementById('tmSimFrame');
    if(frame){
        choices.forEach(button=>button.addEventListener('click',()=>{
            const id=Number(button.dataset.id);if(!Number.isInteger(id)||id<1)return;
            if(button.classList.contains('is-active'))return;
            choices.forEach(choice=>{const active=choice===button;choice.classList.toggle('is-active',active);choice.setAttribute('aria-pressed',String(active));});
            frame.src='/simulations.php?id='+id+'&mode=study&embedded=1';frame.title=button.dataset.title;
            document.getElementById('tmSimSelectedTitle').textContent=button.dataset.title;
            document.getElementById('tmSimQuizLink').href='/simulations.php?id='+id+'&mode=quiz';
            document.getElementById('tmSimStudyLink').href='/simulations.php?id='+id+'&mode=study';
        }));
    }
    const scenes=Array.from(document.querySelectorAll('.sim-container[id^="sim-study-"]'));
    if(!scenes.length)return;
    const lessons=scenes.map(scene=>scene.parentElement);
    const container=lessons[0].parentElement;
    lessons.forEach(lesson=>lesson.classList.add('tm-sim-lesson'));
    const pager=document.createElement('div');pager.className='tm-sim-pager';pager.innerHTML='<button type="button">Previous</button><span aria-live="polite"></span><button type="button">Next</button>';
    lessons[lessons.length-1].insertAdjacentElement('afterend',pager);
    let current=0;
    function show(index){current=Math.max(0,Math.min(index,lessons.length-1));lessons.forEach((lesson,i)=>lesson.hidden=i!==current);pager.children[0].disabled=current===0;pager.children[2].disabled=current===lessons.length-1;pager.children[1].textContent='Scenario '+(current+1)+' of '+lessons.length;}
    pager.children[0].addEventListener('click',()=>show(current-1));pager.children[2].addEventListener('click',()=>show(current+1));show(0);
    if(new URLSearchParams(window.location.search).get('embedded')==='1'){
        document.body.classList.add('tm-sim-embedded');
        (function isolateEmbeddedPlayer(){
            let node=container;
            while(node&&node.parentElement){
                const parent=node.parentElement;
                Array.from(parent.children).forEach(sibling=>{
                    if(sibling!==node&&!['SCRIPT','STYLE','LINK','META','TEMPLATE'].includes(sibling.tagName)){
                        sibling.style.setProperty('display','none','important');
                    }
                });
                parent.style.setProperty('padding','0','important');
                parent.style.setProperty('margin','0','important');
                parent.style.setProperty('transform','none','important');
                parent.style.setProperty('width','100%','important');
                parent.style.setProperty('max-width','none','important');
                parent.style.setProperty('position','static','important');
                if(parent===document.body)break;
                node=parent;
            }
        })();
        const intro=Array.from(container.children).find(child=>child!==lessons[0]&&child!==pager&&child.querySelector('strong'));
        if(intro)intro.classList.add('tm-sim-intro');
        Array.from(container.children).filter(child=>child.querySelector('a[href*="mode=quiz"]')||child.querySelector('a[href="/simulations.php"]')).forEach(child=>child.classList.add('tm-sim-end-links'));
        document.querySelectorAll('video').forEach(video=>video.preload='metadata');
    }
})();
</script>

TM_REFERENCE_LAYOUT;
include 'includes/footer.php'; ?>