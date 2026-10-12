<?php
/**
 * TestMate - Learner Video Demonstrations
 * PHP 8.5 + PDO SQLite
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
function videoEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
/**
 * Convert old XAMPP/TestMate file paths into URLs
 * supported by the PHP built-in server.
 */
function videoUrl($path): string
{
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') {
        return '';
    }
    // Preserve externally hosted videos.
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    // Remove an old localhost address if present.
    $path = preg_replace(
        '~^https?://localhost(?::\d+)?/~i',
        '/',
        $path
    );
    // Remove the old /testmate/ directory prefix.
    $path = preg_replace(
        '~^/?testmate/~i',
        '',
        $path
    );
    // Remove current-directory notation.
    $path = preg_replace('~^(\./)+~', '', $path);
    return '/' . ltrim($path, '/');
}
// Optional topic filter. Without ?topic=, show the original complete library.
$selectedVideoTopicId = max(0, (int)($_GET['topic'] ?? 0));
$videos = [];
$sql = "
    SELECT
        m.*,
        t.name AS topic_name
    FROM materials AS m
    INNER JOIN topics AS t
        ON m.topic_id = t.id
    WHERE LOWER(m.file_type) = 'video'
";
if ($selectedVideoTopicId > 0) {
    $sql .= " AND m.topic_id = ?";
}
$sql .= "
    ORDER BY
        t.name ASC,
        COALESCE(m.sort_order, 0) ASC,
        m.id DESC
";
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($selectedVideoTopicId > 0 ? [$selectedVideoTopicId] : []);
    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[TestMate Videos] ' . $e->getMessage());
    $videos = [];
}
// Group videos by their learning topic.
$videosByTopic = [];
foreach ($videos as $video) {
    $topicName = trim(
        (string)($video['topic_name'] ?? 'Other')
    );
    if ($topicName === '') {
        $topicName = 'Other';
    }
    if (!isset($videosByTopic[$topicName])) {
        $videosByTopic[$topicName] = [];
    }
    $videosByTopic[$topicName][] = $video;
}
include __DIR__ . '/includes/header.php';
?>
<style>
/* =====================================================
   TESTMATE VIDEO DEMONSTRATIONS
   ===================================================== */
.video-page {
    min-height: calc(100vh - 76px);
    padding: 30px 0 60px;
    background:
        radial-gradient(
            circle at top right,
            rgba(244,122,31,.12),
            transparent 35%
        ),
        linear-gradient(
            135deg,
            #f4f8fc 0%,
            #f7fafe 48%,
            #f4f8fc 100%
        );
}
.video-container {
    max-width: 1260px;
    margin: 0 auto;
    padding: 0 26px;
}
/* Header */
.video-hero {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 150px;
    margin-bottom: 30px;
    padding: 30px 34px;
    border-radius: 18px;
    background: linear-gradient(
        120deg,
        #153d61 0%,
        #176da5 58%,
        #12a0e9 100%
    );
    box-shadow: 0 12px 28px rgba(19,70,110,.18);
}
.video-hero::before {
    content: "";
    position: absolute;
    width: 300px;
    height: 300px;
    right: -100px;
    top: -130px;
    border-radius: 50%;
    background: rgba(255,255,255,.07);
}
.video-hero::after {
    content: "";
    position: absolute;
    width: 190px;
    height: 190px;
    right: 150px;
    bottom: -140px;
    border-radius: 50%;
    background: rgba(255,255,255,.06);
}
.video-hero-copy {
    position: relative;
    z-index: 3;
    max-width: 660px;
}
.video-kicker {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 8px;
    color: #8fdcff;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .16em;
}
.video-hero h1 {
    margin: 0 0 8px;
    color: #fff;
    font-size: 31px;
    line-height: 1.15;
    font-weight: 900;
}
.video-hero p {
    margin: 0;
    max-width: 650px;
    color: rgba(255,255,255,.84);
    font-size: 14px;
    line-height: 1.7;
}
/* Animated road-sign illustrations */
.video-road-signs {
    position: relative;
    z-index: 3;
    width: 290px;
    height: 100px;
    flex: 0 0 290px;
}
.video-play-sign {
    position: absolute;
    left: 100px;
    top: 8px;
    width: 82px;
    height: 82px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #fff;
    border: 8px solid #ff303a;
    color: #0d65ac;
    font-size: 31px;
    box-shadow: 0 8px 18px rgba(0,0,0,.23);
    animation: videoSignFloat 3s ease-in-out infinite;
}
.video-small-stop {
    position: absolute;
    left: 25px;
    top: 25px;
    width: 51px;
    height: 51px;
    display: flex;
    align-items: center;
    justify-content: center;
    clip-path: polygon(
        30% 0, 70% 0,
        100% 30%, 100% 70%,
        70% 100%, 30% 100%,
        0 70%, 0 30%
    );
    background: #f0202b;
    color: #fff;
    font-size: 11px;
    font-weight: 900;
    animation: videoSignFloat 3.3s ease-in-out infinite;
}
.video-warning-sign {
    position: absolute;
    right: 23px;
    top: 20px;
    width: 0;
    height: 0;
    border-left: 34px solid transparent;
    border-right: 34px solid transparent;
    border-bottom: 61px solid #111;
    animation: videoSignFloat 3.7s ease-in-out infinite;
}
.video-warning-sign::before {
    content: "";
    position: absolute;
    left: -28px;
    top: 9px;
    width: 0;
    height: 0;
    border-left: 28px solid transparent;
    border-right: 28px solid transparent;
    border-bottom: 49px solid #ffd126;
}
.video-warning-sign i {
    position: absolute;
    z-index: 2;
    left: -8px;
    top: 28px;
    color: #111;
    font-size: 18px;
}
@keyframes videoSignFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-7px);
    }
}
/* Toolbar */
.video-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 27px;
}
.video-toolbar-copy h2 {
    margin: 0 0 4px;
    color: #0c3564;
    font-size: 22px;
    font-weight: 900;
}
.video-toolbar-copy p {
    margin: 0;
    color: #718398;
    font-size: 13px;
}
.video-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: 1px solid #dce7f1;
    border-radius: 999px;
    background: #fff;
    color: #155c9e;
    font-size: 12px;
    font-weight: 850;
    white-space: nowrap;
    box-shadow: 0 4px 12px rgba(31,55,82,.06);
}
.video-count i {
    color: #8c50f6;
}
/* Topic headings */
.video-topic-section {
    margin-bottom: 38px;
}
.video-topic-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 15px;
}
.video-topic-heading h2 {
    margin: 0;
    color: #0a3565;
    font-size: 19px;
    font-weight: 900;
}
.video-topic-heading span {
    padding: 6px 11px;
    border-radius: 999px;
    background: #eaf5ff;
    color: #1478c8;
    font-size: 11px;
    font-weight: 850;
}
/* Cards */
.video-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}
.video-card {
    min-width: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dce6ef;
    border-radius: 15px;
    box-shadow: 0 5px 17px rgba(22,48,78,.08);
    transition:
        transform .2s ease,
        box-shadow .2s ease;
}
.video-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 13px 28px rgba(22,48,78,.14);
}
/* Video player */
.video-player-wrap {
    position: relative;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    background: linear-gradient(135deg, #1d405d, #0e8cd4);
}
.video-player-wrap video {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #101820;
}
/* Card content */
.video-card-body {
    padding: 18px;
}
.video-topic-label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 8px;
    color: #738195;
    font-size: 10px;
    font-weight: 850;
    letter-spacing: .07em;
    text-transform: uppercase;
}
.video-topic-label i {
    color: #1597e5;
}
.video-card h3 {
    margin: 0 0 8px;
    color: #062f62;
    font-size: 17px;
    line-height: 1.35;
    font-weight: 900;
}
.video-description {
    margin: 0;
    color: #63778d;
    font-size: 13px;
    line-height: 1.6;
}
.video-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 15px;
    padding-top: 13px;
    border-top: 1px solid #edf1f5;
}
.silent-video-label {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #758ba0;
    font-size: 10px;
    font-weight: 750;
}
.silent-video-label i {
    color: #8b4ff5;
}
.video-study-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #0878cf;
    font-size: 11px;
    font-weight: 850;
    text-decoration: none;
}
.video-study-link:hover {
    text-decoration: underline;
}
/* Playback error */
.video-error {
    display: none;
    margin-top: 12px;
    padding: 10px 12px;
    background: #fdecea;
    border-left: 3px solid #e74c3c;
    border-radius: 0 6px 6px 0;
    color: #c0392b;
    font-size: 12px;
    line-height: 1.6;
}
.video-error a {
    color: #c0392b;
    font-weight: 850;
}
/* Empty state */
.no-videos {
    padding: 65px 25px;
    background: #fff;
    border: 1px dashed #c9d8e6;
    border-radius: 17px;
    text-align: center;
}
.no-videos-icon {
    width: 70px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    border-radius: 50%;
    background: linear-gradient(145deg, #9c61ff, #7136e9);
    color: #fff;
    font-size: 27px;
    box-shadow: 0 8px 20px rgba(113,54,233,.22);
}
.no-videos h2 {
    margin: 0 0 7px;
    color: #0c3564;
    font-size: 20px;
}
.no-videos p {
    max-width: 520px;
    margin: auto;
    color: #718398;
    font-size: 13px;
    line-height: 1.7;
}
/* Responsive */
@media (max-width: 1150px) {
    .video-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .video-road-signs {
        transform: scale(.82);
        transform-origin: right center;
    }
}
@media (max-width: 820px) {
    .video-hero {
        align-items: flex-start;
        flex-direction: column;
    }
    .video-road-signs {
        width: 100%;
        flex-basis: 100px;
        transform-origin: left center;
    }
    .video-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 650px) {
    .video-page {
        padding-top: 18px;
    }
    .video-container {
        padding: 0 15px;
    }
    .video-hero {
        padding: 24px 20px;
    }
    .video-hero h1 {
        font-size: 25px;
    }
    .video-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }
    .video-card-footer {
        flex-wrap: wrap;
    }
}
@media (prefers-reduced-motion: reduce) {
    .video-play-sign,
    .video-small-stop,
    .video-warning-sign {
        animation: none;
    }
}

.video-topic-filter-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
}
.video-topic-filter-nav a {
    display: inline-flex;
    padding: 10px 14px;
    background: white;
    border: 1px solid #dce7f1;
    border-radius: 9px;
    text-decoration: none;
    color: #1169b0;
    font-size: 12px;
    font-weight: 800;
}
.video-topic-filter-nav a:hover { background: #eaf5ff; }
.video-filter-summary {
    padding: 12px 15px;
    margin-bottom: 20px;
    color: #345a79;
    background: #eaf4ff;
    border-left: 3px solid #147df5;
    border-radius: 8px;
    font-size: 13px;
}
</style>
<div class="video-page">
    <main class="video-container">
        <?php if ($selectedVideoTopicId > 0): ?>
            <nav class="video-topic-filter-nav" aria-label="Video topic navigation">
                <a href="/study-materials.php?topic=<?= $selectedVideoTopicId ?>&amp;mode=read#topic-content">Return to Study Materials</a>
                <a href="/video-demonstrations.php">View All Videos</a>
            </nav>
        <?php endif; ?>
        <!-- Page header -->
        <section class="video-hero">
            <div class="video-hero-copy">
                <span class="video-kicker">
                    <i class="fa-solid fa-circle-play"></i>
                    VIDEO LEARNING
                </span>
                <h1>Video Demonstrations</h1>
                <p>
                    Watch visual demonstrations of road rules,
                    traffic situations and correct driver actions.
                    Replay each demonstration as often as you need
                    while preparing for your learner's licence.
                </p>
            </div>
            <div class="video-road-signs" aria-hidden="true">
                <div class="video-small-stop">
                    STOP
                </div>
                <div class="video-play-sign">
                    <i class="fa-solid fa-play"></i>
                </div>
                <div class="video-warning-sign">
                    <i class="fa-solid fa-car"></i>
                </div>
            </div>
        </section>
        <!-- Toolbar -->
        <div class="video-toolbar">
            <div class="video-toolbar-copy">
                <h2>Learn by Watching</h2>
                <p>
                    Select a video below to start learning.
                </p>
            </div>
            <span class="video-count">
                <i class="fa-solid fa-circle-play"></i>
                <?= count($videos) ?>
                <?= count($videos) === 1
                    ? 'video'
                    : 'videos'
                ?>
            </span>
        </div>
        <?php if ($selectedVideoTopicId > 0 && $videos): ?>
            <p class="video-filter-summary">Showing demonstrations for <strong><?= videoEscape((string)($videos[0]['topic_name'] ?? 'selected topic')) ?></strong>.</p>
        <?php endif; ?>
        <!-- Videos grouped by topic -->
        <?php if (!empty($videosByTopic)): ?>
            <?php foreach ($videosByTopic as $topicName => $topicVideos): ?>
                <section class="video-topic-section">
                    <div class="video-topic-heading">
                        <h2>
                            <?= videoEscape($topicName) ?>
                        </h2>
                        <span>
                            <?= count($topicVideos) ?>
                            <?= count($topicVideos) === 1
                                ? 'demonstration'
                                : 'demonstrations'
                            ?>
                        </span>
                    </div>
                    <div class="video-grid">
                        <?php foreach ($topicVideos as $video): ?>
                            <?php
                            $videoPath = videoUrl(
                                $video['file_path'] ?? ''
                            );
                            $pathForExtension = parse_url(
                                $videoPath,
                                PHP_URL_PATH
                            );
                            $extension = strtolower(
                                pathinfo(
                                    (string)$pathForExtension,
                                    PATHINFO_EXTENSION
                                )
                            );
                            $mimeTypes = [
                                'mp4'  => 'video/mp4',
                                'webm' => 'video/webm',
                                'ogv'  => 'video/ogg',
                                'ogg'  => 'video/ogg',
                                'mov'  => 'video/quicktime'
                            ];
                            $mimeType = $mimeTypes[$extension]
                                ?? 'video/mp4';
                            $videoId = 'video-' . (int)$video['id'];
                            $topicId = (int)($video['topic_id'] ?? 0);
                            ?>
                            <article class="video-card">
                                <!-- Video player -->
                                <div class="video-player-wrap">
                                    <?php if ($videoPath !== ''): ?>
                                        <video
                                            id="<?= videoEscape($videoId) ?>"
                                            controls
                                            preload="metadata"
                                        >
                                            <source
                                                src="<?= videoEscape($videoPath) ?>"
                                                type="<?= videoEscape($mimeType) ?>"
                                            >
                                            Your browser does not support
                                            this video format.
                                        </video>
                                    <?php else: ?>
                                        <div style="
                                            display:flex;
                                            align-items:center;
                                            justify-content:center;
                                            height:100%;
                                            padding:20px;
                                            text-align:center;
                                            color:white;
                                        ">
                                            Video file not available
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Video information -->
                                <div class="video-card-body">
                                    <span class="video-topic-label">
                                        <i class="fa-solid fa-road"></i>
                                        <?= videoEscape($topicName) ?>
                                    </span>
                                    <h3>
                                        <?= videoEscape(
                                            $video['title']
                                                ?? 'Video Demonstration'
                                        ) ?>
                                    </h3>
                                    <?php if (!empty($video['content'])): ?>
                                        <p class="video-description">
                                            <?= nl2br(
                                                videoEscape(
                                                    $video['content']
                                                )
                                            ) ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="video-card-footer">
                                        <div class="silent-video-label">
                                            <i class="fa-solid fa-volume-xmark"></i>
                                            Silent demonstration
                                        </div>
                                        <?php if ($topicId > 0): ?>
                                            <a
                                                href="/study-materials.php?topic=<?= $topicId ?>&amp;mode=read"
                                                class="video-study-link"
                                            >
                                                <i class="fa-solid fa-book-open"></i>
                                                Study Topic
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($videoPath !== ''): ?>
                                        <div
                                            id="<?= videoEscape($videoId) ?>-error"
                                            class="video-error"
                                            role="alert"
                                        >
                                            This video cannot be played
                                            by your browser.
                                            <a href="<?= videoEscape($videoPath) ?>"
                                               target="_blank"
                                               rel="noopener noreferrer">
                                                Open video
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- No matching videos uploaded -->
            <div class="no-videos">
                <div class="no-videos-icon">
                    <i class="fa-solid fa-video"></i>
                </div>
                <h2>
                    No video demonstrations available yet
                </h2>
                <p>
                    <?php if ($selectedVideoTopicId > 0): ?>
                        There are no video demonstrations for this selected topic yet.
                        You can return to the reading materials or browse all videos.
                    <?php else: ?>
                        Video demonstrations uploaded by the
                        administrator will automatically appear here.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const players = document.querySelectorAll(
        '.video-player-wrap video'
    );
    players.forEach(function (player) {
        const errorBox = document.getElementById(
            player.id + '-error'
        );
        if (errorBox) {
            player.addEventListener('error', function () {
                errorBox.style.display = 'block';
            }, true);
        }
        // Pause other videos when a learner starts a new one.
        player.addEventListener('play', function () {
            players.forEach(function (other) {
                if (other !== player && !other.paused) {
                    other.pause();
                }
            });
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
