<?php
require 'includes/auth.php';
require 'includes/db_connect.php';

$dashboard_topic_ids = [6, 7, 8, 9];
$topic_id = isset($_GET['topic']) ? (int)$_GET['topic'] : 0;

// Guard: this page is only for the 4 Dashboard-only topics
if (!in_array($topic_id, $dashboard_topic_ids, true)) {
    header("Location: /testmate/dashboard.php");
    exit();
}

$t = $conn->prepare("SELECT * FROM topics WHERE id = ?");
$t->bind_param("i", $topic_id);
$t->execute();
$topic = $t->get_result()->fetch_assoc();

if (!$topic) {
    header("Location: /testmate/dashboard.php");
    exit();
}

// First admin-uploaded image for this topic, if any (takes priority over the built-in book image)
$img_stmt = $conn->prepare("
    SELECT file_path FROM materials
    WHERE topic_id = ? AND file_type = 'image' AND file_path IS NOT NULL AND file_path <> ''
    ORDER BY sort_order ASC, id ASC LIMIT 1
");
$img_stmt->bind_param("i", $topic_id);
$img_stmt->execute();
$img_row = $img_stmt->get_result()->fetch_assoc();
$admin_image = $img_row ? $img_row['file_path'] : null;

// ── Content sourced and paraphrased from the NATIS Namibian Learner's & Driving Licence Manual ──
// Each topic has: a hero image pulled from the actual manual, section text, and an
// optional "Robo Says" callout tip — matching the mascot callouts used throughout the book.
$content = [
    6 => [ // What is a Learner's Licence
        'hero_image' => '/testmate/images/hero_learner.png',
        'sections' => [
            [
                'heading' => 'Purpose of the Learner\'s Licence',
                'text' => 'A learner\'s licence is the first step towards getting a full driving licence in Namibia. It shows you have passed a test on vehicle controls, road signs, and the rules of the road, and it allows you to practise driving on public roads under supervision before you qualify for a full licence.',
            ],
            [
                'heading' => 'Who Needs One',
                'text' => 'Anyone who wants to learn to drive a motor vehicle in Namibia — whether a motorcycle, a light motor vehicle, or a heavy motor vehicle — must first obtain a learner\'s licence for that category before applying for a driving licence.',
            ],
            [
                'heading' => 'How Long It Is Valid',
                'text' => 'A learner\'s licence is valid for 18 months from the date it is issued. You must pass your driving test and obtain your full driving licence within this period, or you will need to reapply for a learner\'s licence.',
                'robo_tip' => 'Learner\'s licence is valid for 18 months.',
            ],
        ],
    ],
    7 => [ // Licence Categories & Codes
        'hero_image' => '/testmate/images/hero_categories.png',
        'sections' => [
            [
                'heading' => 'Learner\'s Licence Codes',
                'text' => 'Code 1 covers motorcycles — engines under 125cc from age 16, or over 125cc from age 17. Code 2 covers motor vehicles with a tare (empty weight) under 3,500kg, from age 17. Code 3 covers motor vehicles with a tare over 3,500kg, from age 18.',
            ],
            [
                'heading' => 'Driving Licence Categories',
                'text' => 'Once you hold a full driving licence, the category (A1, A, B, C1, C, BE, C1E, CE) determines what you may legally drive. A1 and A cover motorcycles (under and over 125cc). B covers light motor vehicles up to 3,500kg tare, including minibuses, buses and goods vehicles under that weight. C1 and C cover progressively heavier motor vehicles. BE, C1E and CE add trailers and articulated combinations to the equivalent B, C1 and C categories.',
            ],
            [
                'heading' => 'Why This Matters',
                'text' => 'The code or category on your licence strictly limits what you\'re legally allowed to drive. Driving a vehicle heavier or in a different class than your licence permits is an offence, even if you hold a valid licence for a different category.',
            ],
            [
                'heading' => 'Full Categories Table',
                'text' => 'The table below, taken directly from the manual, shows every category with its example vehicle, code, class definition, and minimum age.',
                'image' => '/testmate/images/categories_table.png',
            ],
        ],
    ],
    8 => [ // Licence Conditions
        'hero_image' => '/testmate/images/hero_conditions.jpg',
        'sections' => [
            [
                'heading' => 'Accompanied Driving',
                'text' => 'A learner driver must always be accompanied in the vehicle by a person who already holds a valid driving licence for that same class of vehicle.',
            ],
            [
                'heading' => 'The "L" Sign',
                'text' => 'Whenever a learner driver is driving a vehicle (except a motorcycle), a red "L" sign — at least 300mm by 200mm — must be displayed in the rear window on the right-hand side of the vehicle, as shown above.',
            ],
            [
                'heading' => 'Passenger Restrictions',
                'text' => 'A learner driving a motorcycle without a sidecar may not carry any passengers. A learner driver is also never allowed to transport passengers for payment (reward), regardless of vehicle type.',
            ],
            [
                'heading' => 'Carrying Your Licence',
                'text' => 'A learner driver must carry their learner\'s licence with them at all times while driving, and produce it if requested by a traffic officer.',
            ],
            [
                'heading' => 'Adapted Vehicles',
                'text' => 'If your vehicle has been specially adapted for use by a physically challenged person, the specific modifications must be noted on the learner\'s licence itself.',
            ],
            [
                'heading' => 'Professional Authorisation',
                'text' => 'Some driving activities need an extra "Professional Authorisation" endorsed on your licence — not just the licence code itself. You need one if: you hold a C1, C, C1E or CE licence and want to use it; you'."'".'ll transport passengers for reward; you'."'".'ll transport dangerous goods; or you'."'".'ll drive a breakdown vehicle. Transporting dangerous goods also requires a training course approved by the Minister of Transport.',
            ],
            [
                'heading' => 'Age Restrictions for Professional Authorisation',
                'text' => 'A driver transporting passengers for reward must be at least 21 years old. A driver transporting dangerous goods must be at least 25 years old.',
                'image' => '/testmate/images/robo_conditions.png',
            ],
            [
                'heading' => 'Categories of Professional Authorisation',
                'text' => '"P" authorises transporting passengers only. "G" authorises transporting goods only. "D" authorises transporting dangerous goods and goods.',
            ],
        ],
    ],
    9 => [ // What You're Tested On
        'hero_image' => '/testmate/images/hero_test.png',
        'sections' => [
            [
                'heading' => 'The Four Areas of the Test',
                'text' => 'The official learner\'s licence test covers four areas: (1) the controls of the motor vehicle for the class you are applying for, (2) road traffic signs, (3) the rules of the road, and (4) other matters relating to road traffic law — such as documents you must carry and vehicle loading rules.',
            ],
            [
                'heading' => 'Pass Mark',
                'text' => 'You need to score at least 80% to pass the official learner\'s licence test.',
                'robo_tip' => 'The pass mark for the Learner\'s Licence test is 80%, which transforms to a total of 72 points out of 90 questions.',
            ],
            [
                'heading' => 'Eyesight Requirement',
                'text' => 'Before the written test, you\'ll also undergo an eye screening. You need a minimum of 6/12 vision in each eye. If you wear glasses or contact lenses, bring them with you to the testing centre.',
            ],
            [
                'heading' => 'How to Prepare',
                'text' => 'Use the Study Materials and Quizzes sections of TestMate to work through vehicle controls, road signs, and the rules of the road — the same three areas (plus other road traffic law) that the real test is based on.',
            ],
        ],
    ],
];

$page_content = $content[$topic_id] ?? ['sections' => []];
$hero_image = $admin_image ?: ($page_content['hero_image'] ?? null);
?>
<?php include 'includes/header.php'; ?>

<style>
.info-hero {
    background: #fcfbf7;
    border: 1px solid #ddd7c9;
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    align-items: stretch;
    margin-bottom: 24px;
}
.info-hero .thumb {
    width: 260px;
    flex-shrink: 0;
    background-size: cover;
    background-position: center;
    min-height: 170px;
}
.info-hero .thumb.iconfallback {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 64px;
    background: #efeadf;
}
.info-hero .body {
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.info-hero .body h1 { margin: 0 0 8px; font-size: 22px; color: #3b3e3a; }
.info-hero .body p { margin: 0; font-size: 14.5px; color: #8a8477; line-height: 1.6; }

.info-section {
    background: #fcfbf7;
    border: 1px solid #ddd7c9;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 14px;
}
.info-section h3 { margin: 0 0 8px; font-size: 16px; color: #3b3e3a; }
.info-section p { margin: 0; font-size: 14.5px; color: #565a54; line-height: 1.7; }

.robo-tip {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-top: 14px;
    padding: 12px 16px;
    background: #eef2ec;
    border-left: 4px solid #74937f;
    border-radius: 6px;
}
.robo-tip .robo-icon { font-size: 22px; line-height: 1; flex-shrink: 0; }
.robo-tip .robo-text { font-size: 13.5px; color: #45523c; line-height: 1.55; }
.robo-tip .robo-text strong { color: #3b3e3a; }

.section-image {
    display: block;
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin-top: 14px;
    border: 1px solid #ddd7c9;
}

.source-note {
    font-size: 12.5px;
    color: #97907f;
    margin: 20px 0 30px;
    font-style: italic;
}
</style>

<div class="container" style="max-width:820px;">

    <div style="margin: 26px 0 18px;">
        <a href="/testmate/dashboard.php" class="btn btn-outline" style="font-size:13px;padding:7px 16px;">← Back to Dashboard</a>
    </div>

    <div class="info-hero">
        <?php if ($hero_image): ?>
        <div class="thumb" style="background-image:url('<?= htmlspecialchars($hero_image) ?>');"></div>
        <?php else: ?>
        <div class="thumb iconfallback"><?= $topic['icon'] ?></div>
        <?php endif; ?>
        <div class="body">
            <h1><?= htmlspecialchars($topic['name']) ?></h1>
            <p><?= htmlspecialchars($topic['description']) ?></p>
        </div>
    </div>

    <?php foreach ($page_content['sections'] as $section): ?>
    <div class="info-section">
        <h3><?= htmlspecialchars($section['heading']) ?></h3>
        <p><?= htmlspecialchars($section['text']) ?></p>
        <?php if (!empty($section['image'])): ?>
        <img class="section-image" src="<?= htmlspecialchars($section['image']) ?>" alt="<?= htmlspecialchars($section['heading']) ?>">
        <?php endif; ?>
        <?php if (!empty($section['robo_tip'])): ?>
        <div class="robo-tip">
            <span class="robo-icon">🚦</span>
            <span class="robo-text"><strong>Robo says:</strong> <?= htmlspecialchars($section['robo_tip']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <p class="source-note">Based on the NATIS Namibian Learner's &amp; Driving Licence Manual (General Information chapter).</p>

</div>

<?php include 'includes/footer.php'; ?>