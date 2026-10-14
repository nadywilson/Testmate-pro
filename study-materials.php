<?php
/**
 * TestMate - Study Materials
 * PHP 8.5 / PDO SQLite
 *
 * /study-materials.php                       all topics, grouped by category
 * /study-materials.php?topic=ID&mode=read    lessons for one topic
 * /study-materials.php?topic=ID&mode=video   videos and simulations for one topic
 *
 * Lessons are rows in `materials` (not video). Rows with a negative sort_order
 * are the structured lessons added by tools/import-content.php; their text may
 * use a small markup: "## heading", "- bullet", "! key rule", "? question | answer".
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

function studyEsc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function studyMediaUrl($path): string
{
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    $path = preg_replace('~^/?testmate/~i', '', $path);
    $path = preg_replace('~^(\./)+~', '', $path);
    return '/' . ltrim($path, '/');
}

/** Lesson text → safe HTML (supports the simple lesson markup, plain text still works). */
function studyRenderLesson(string $text): string
{
    $html = '';
    $list = false;
    $close = static function () use (&$html, &$list): void { if ($list) { $html .= '</ul>'; $list = false; } };
    foreach (preg_split('/\R/', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') { $close(); continue; }
        if (str_starts_with($line, '## ')) { $close(); $html .= '<h4>' . studyEsc(substr($line, 3)) . '</h4>'; continue; }
        if (str_starts_with($line, '- ')) { if (!$list) { $html .= '<ul>'; $list = true; } $html .= '<li>' . studyEsc(substr($line, 2)) . '</li>'; continue; }
        $close();
        if (str_starts_with($line, '! ')) {
            $html .= '<div class="sm-key"><span>Key rule</span><p>' . studyEsc(substr($line, 2)) . '</p></div>';
        } elseif (str_starts_with($line, '? ')) {
            [$q, $a] = array_pad(explode('|', substr($line, 2), 2), 2, '');
            $html .= '<details class="sm-check"><summary><span>Quick check</span>' . studyEsc(trim($q)) . '</summary><p>' . studyEsc(trim($a)) . '</p></details>';
        } else {
            $html .= '<p>' . studyEsc($line) . '</p>';
        }
    }
    $close();
    return $html;
}

/** Lower-case text; works even if the mbstring extension is not enabled in php.ini. */
function studyLower($text): string
{
    $text = trim((string)$text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/** First plain paragraph of a lesson, for summaries. */
function studySummary(string $text, int $max = 150): string
{
    foreach (preg_split('/\R/', trim($text)) as $line) {
        $line = trim($line);
        if ($line !== '' && !preg_match('/^(##|-|!|\?) /', $line)) {
            if (strlen($line) <= $max) return $line;
            $cut = function_exists('mb_substr') ? mb_substr($line, 0, $max - 1, 'UTF-8') : substr($line, 0, $max - 1);
            return rtrim($cut) . '…';
        }
    }
    return '';
}

/** Card picture: new drawn picture if installed, otherwise the older images. */
function studyTopicImage(int $id, string $name): string
{
    if (is_file(__DIR__ . '/images/topics/topic-' . $id . '.jpg')) {
        return '/images/topics/topic-' . $id . '.jpg';
    }
    $n = strtolower($name);
    $map = [['traffic rule', 'traffic-rules'], ['speed', 'speed-limits'], ['parking', 'parking-rules'], ['vehicle', 'vehicle-controls'],
        ['warning', 'warning-signs'], ['overtak', 'overtaking-passing'], ['legislation', 'other-road-traffic-legislation'], ['sign', 'road-signs']];
    foreach ($map as [$k, $file]) {
        if (str_contains($n, $k) && is_file(__DIR__ . '/images/' . $file . '.png')) return '/images/' . $file . '.png';
    }
    return '';
}

const STUDY_GROUPS = [
    "Learner's Licence Basics" => ['Licence basics', '#147df5'],
    'Road Traffic Signs' => ['Road signs', '#dc2626'],
    'Vehicle Controls & Road Rules' => ['Rules of the road', '#0d9488'],
    'Road Safety' => ['Road safety', '#d97706'],
    'Road Traffic Law' => ['Road traffic law', '#7c3aed'],
];
/** Categories that are reading-only: no pictures on the card, no videos or quiz buttons. */
const STUDY_READ_ONLY = ["Learner's Licence Basics"];
function studyReadOnly(string $cat): bool { return in_array($cat, STUDY_READ_ONLY, true); }
const STUDY_ICON_LICENCE = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5.5 16c.6-1.6 1.7-2.4 3-2.4s2.4.8 3 2.4M14 10h4.5M14 13h3"/></svg>';

$topics = [];
$topic = null;
$lessons = [];
$extraNotes = [];
$videos = [];
$questions = [];
$simulations = [];
$topicId = max(0, (int)($_GET['topic'] ?? 0));
$mode = (string)($_GET['mode'] ?? 'read');
if (!in_array($mode, ['read', 'video'], true)) {
    $mode = 'read';
}

try {
    $topics = $conn->query("
        SELECT t.*,
            (SELECT CASE WHEN SUM(COALESCE(m.sort_order, 0) < 0) > 0 THEN SUM(COALESCE(m.sort_order, 0) < 0) ELSE COUNT(*) END
               FROM materials m WHERE m.topic_id = t.id AND LOWER(COALESCE(m.file_type, '')) <> 'video') AS lesson_count,
            (SELECT COUNT(*) FROM materials m WHERE m.topic_id = t.id AND LOWER(COALESCE(m.file_type, '')) = 'video' AND TRIM(COALESCE(m.file_path, '')) <> '') AS video_count,
            (SELECT COUNT(*) FROM simulations s WHERE s.topic_id = t.id AND s.is_active = 1) AS sim_count,
            (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id) AS question_count
        FROM topics t
        ORDER BY t.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($topicId > 0) {
        foreach ($topics as $t) {
            if ((int)$t['id'] === $topicId) { $topic = $t; break; }
        }
        if ($topic) {
            $stmt = $conn->prepare('SELECT * FROM materials WHERE topic_id = ? ORDER BY COALESCE(sort_order, 0) ASC, id ASC');
            $stmt->execute([$topicId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $type = strtolower(trim((string)($m['file_type'] ?? '')));
                if ($type === 'video') {
                    if (trim((string)($m['file_path'] ?? '')) !== '') $videos[] = $m;
                } elseif ((int)($m['sort_order'] ?? 0) < 0) {
                    $lessons[] = $m;
                } else {
                    $extraNotes[] = $m;
                }
            }
            // Topics without the new structured lessons show their existing notes as lessons.
            if (!$lessons) { $lessons = $extraNotes; $extraNotes = []; }

            $stmt = $conn->prepare('SELECT * FROM questions WHERE topic_id = ? ORDER BY RANDOM() LIMIT 3');
            $stmt->execute([$topicId]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare('SELECT * FROM simulations WHERE topic_id = ? AND is_active = 1 ORDER BY id ASC');
            $stmt->execute([$topicId]);
            $simulations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log('[TestMate Study Materials] ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load study materials. Check the PHP server terminal.');
}

// Group topics by category for the overview.
$grouped = [];
foreach ($topics as $t) {
    $cat = trim((string)($t['category'] ?? '')) ?: 'Road Traffic Law';
    $grouped[$cat][] = $t;
}
uksort($grouped, static function ($a, $b) {
    $order = array_keys(STUDY_GROUPS);
    $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
    return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
});

// Match each lesson to the video with the same title.
$videoByTitle = [];
foreach ($videos as $v) { $videoByTitle[studyLower($v['title'])] = $v; }
// Use the lesson picture with the same title as the video's cover image.
$posterByTitle = [];
foreach ($lessons as $l) {
    if (strtolower(trim((string)($l['file_type'] ?? ''))) === 'image' && trim((string)($l['file_path'] ?? '')) !== '') {
        $posterByTitle[studyLower($l['title'])] = studyMediaUrl($l['file_path']);
    }
}

include __DIR__ . '/includes/header.php';
?>
<style>
.sm-page{--navy:#102744;--teal:#0b5354;--blue:#147df5;--ink:#173452;--muted:#5b7189;--line:#e2eaf3;padding:28px 32px 56px;background:#f4f8fd;min-height:100%;box-sizing:border-box;color:var(--ink)}
.sm-page *{box-sizing:border-box}
.sm-kicker{display:block;font-size:12px;font-weight:800;letter-spacing:.12em;color:#2c7bd6;text-transform:uppercase;margin-bottom:6px}
.sm-page h1{margin:0 0 6px;font-size:34px;font-weight:800;color:var(--navy);line-height:1.15}
.sm-sub{margin:0 0 22px;color:var(--muted);font-size:15px;max-width:760px}
.sm-tools{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:24px}
.sm-search{display:flex;align-items:center;gap:10px;flex:1 1 300px;max-width:420px;height:48px;padding:0 16px;background:#fff;border:1px solid #dbe6f2;border-radius:12px;color:#7a8ea4}
.sm-search:focus-within{border-color:#81baff;box-shadow:0 0 0 3px rgba(20,125,245,.1)}
.sm-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:14px;color:var(--navy)}
.sm-chips{display:flex;flex-wrap:wrap;gap:6px;padding:5px;background:#fff;border:1px solid #dbe6f2;border-radius:12px}
.sm-chip{height:38px;padding:0 14px;border:0;border-radius:9px;background:transparent;color:#42607d;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.sm-chip:hover{background:#f1f6fc}
.sm-chip[aria-pressed="true"]{background:var(--navy);color:#fff}
.sm-group{margin-bottom:34px}
.sm-group[hidden]{display:none}
.sm-group-head{display:flex;align-items:center;gap:10px;margin:0 0 14px}
.sm-group-head h2{margin:0;font-size:20px;font-weight:800;color:var(--navy)}
.sm-group-dot{width:10px;height:10px;border-radius:50%}
.sm-group-head span.count{font-size:13px;color:var(--muted);font-weight:600}
.sm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.sm-card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 4px 16px rgba(16,39,68,.05);transition:transform .2s,box-shadow .2s}
.sm-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(16,39,68,.1)}
.sm-card[hidden]{display:none}
.sm-card-img{position:relative;display:block;aspect-ratio:16/8;background:#dfe9f5 center/cover no-repeat;border-bottom:4px solid var(--accent,#147df5)}
.sm-card-icon{display:flex;align-items:center;justify-content:center;width:64px;height:64px;margin:22px 20px 0;border-radius:16px;background:#eaf3ff;color:#147df5;text-decoration:none}
.sm-card-plain{border-top:4px solid var(--accent,#147df5)}
.sm-actions-one{grid-template-columns:1fr}
.sm-hero.sm-hero-plain{grid-template-columns:auto minmax(0,1fr);align-items:center}
@media(max-width:820px){.sm-hero.sm-hero-plain{grid-template-columns:1fr}.sm-hero-icon{margin:24px 0 0 20px;width:72px;height:72px}}
.sm-hero-icon{display:flex;align-items:center;justify-content:center;width:96px;height:96px;margin:28px 0 28px 30px;border-radius:22px;background:#eaf3ff;color:#147df5}
.sm-hero-icon svg{width:52px;height:52px}
.sm-card-img .sm-emoji{position:absolute;inset:0;display:grid;place-items:center;font-size:54px}
.sm-card-body{flex:1;display:flex;flex-direction:column;gap:6px;padding:18px 20px 20px}
.sm-card-cat{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--accent,#147df5)}
.sm-card h3{margin:0;font-size:19px;font-weight:800;color:var(--navy)}
.sm-card h3 a{color:inherit;text-decoration:none}
.sm-card p{margin:0;color:var(--muted);font-size:14px;line-height:1.5;flex:1}
.sm-meta{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 4px}
.sm-meta span{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;background:#f1f6fc;color:#42607d;font-size:12px;font-weight:700}
.sm-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}
.sm-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:42px;padding:0 14px;border-radius:10px;border:1px solid transparent;font:inherit;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer;white-space:nowrap}
.sm-btn-primary{background:var(--blue);color:#fff!important}
.sm-btn-primary:hover{background:#0b68d6}
.sm-btn-soft{background:#eaf3ff;color:#0f63c4!important}
.sm-btn-soft:hover{background:#dbeaff}
.sm-btn-ghost{background:#fff;color:var(--navy)!important;border-color:#dbe6f2}
.sm-btn-ghost:hover{background:#f1f6fc}
.sm-empty{padding:30px;text-align:center;color:var(--muted);background:#fff;border:1px dashed #cbd8e6;border-radius:16px}
/* topic page */
.sm-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:16px;color:var(--blue);font-weight:700;font-size:14px;text-decoration:none}
.sm-back:hover{text-decoration:underline}
.sm-hero{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);background:#fff;border:1px solid var(--line);border-radius:22px;overflow:hidden;box-shadow:0 6px 22px rgba(16,39,68,.06);margin-bottom:20px}
.sm-hero-img{min-height:260px;background:#dfe9f5 center/cover no-repeat}
.sm-hero-body{padding:28px 30px;display:flex;flex-direction:column;gap:10px;justify-content:center}
.sm-hero-body h1{font-size:32px;margin:0}
.sm-hero-body p{margin:0;color:var(--muted);font-size:15px;line-height:1.6}
.sm-hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
.sm-tabs{display:inline-flex;gap:6px;padding:5px;margin:4px 0 22px;background:#fff;border:1px solid #dbe6f2;border-radius:12px}
.sm-tab{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border-radius:9px;color:#42607d;font-size:14px;font-weight:700;text-decoration:none}
.sm-tab:hover{background:#f1f6fc}
.sm-tab.active{background:var(--navy);color:#fff}
.sm-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:24px;align-items:start}
.sm-toc{position:sticky;top:96px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px}
.sm-toc h2{margin:0 0 10px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.sm-toc ol{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px;counter-reset:toc}
.sm-toc a{display:flex;gap:10px;align-items:flex-start;padding:9px 10px;border-radius:10px;color:var(--ink);font-size:14px;font-weight:600;text-decoration:none;line-height:1.35}
.sm-toc a:hover{background:#f1f6fc}
.sm-toc a b{flex:0 0 24px;height:24px;border-radius:50%;background:#eaf3ff;color:var(--blue);display:grid;place-items:center;font-size:12px}
.sm-lesson{background:#fff;border:1px solid var(--line);border-radius:20px;overflow:hidden;margin-bottom:22px;box-shadow:0 4px 16px rgba(16,39,68,.04);scroll-margin-top:96px}
.sm-lesson-head{display:flex;align-items:center;gap:14px;padding:20px 24px 0}
.sm-lesson-num{flex:0 0 40px;height:40px;border-radius:12px;background:var(--navy);color:#fff;display:grid;place-items:center;font-weight:800}
.sm-lesson-head span{display:block;font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#2c7bd6}
.sm-lesson-head h2{margin:2px 0 0;font-size:22px;font-weight:800;color:var(--navy)}
.sm-lesson-fig{margin:18px 24px 0;border-radius:14px;overflow:hidden;background:#0d1b2a;aspect-ratio:16/9}
.sm-lesson-fig img,.sm-lesson-fig video{display:block;width:100%;height:100%;object-fit:cover}
.sm-lesson-body{padding:18px 24px 6px;font-size:16px;line-height:1.7;color:#22384f}
.sm-lesson-body p{margin:0 0 12px}
.sm-lesson-body h4{margin:18px 0 8px;font-size:15px;font-weight:800;letter-spacing:.02em;color:var(--navy)}
.sm-lesson-body ul{margin:0 0 12px;padding:0;list-style:none;display:flex;flex-direction:column;gap:7px}
.sm-lesson-body li{position:relative;padding-left:26px}
.sm-lesson-body li::before{content:"";position:absolute;left:4px;top:.6em;width:9px;height:9px;border-radius:50%;background:var(--blue)}
.sm-key{display:flex;gap:12px;align-items:flex-start;margin:14px 0;padding:14px 16px;border-radius:12px;background:#fff8e6;border:1px solid #f6dc9b}
.sm-key span{flex:0 0 auto;padding:3px 9px;border-radius:999px;background:#f59e0b;color:#fff;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-top:2px}
.sm-key p{margin:0;font-weight:700;color:#5a3d00}
.sm-check{margin:14px 0;border:1px solid #cfe3fb;border-radius:12px;background:#f3f8ff}
.sm-check summary{cursor:pointer;padding:13px 16px;font-weight:700;color:var(--navy);list-style:none}
.sm-check summary::-webkit-details-marker{display:none}
.sm-check summary span{display:inline-block;margin-right:10px;padding:3px 9px;border-radius:999px;background:var(--blue);color:#fff;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
.sm-check summary::after{content:"Show answer";float:right;color:var(--blue);font-size:13px}
.sm-check[open] summary::after{content:"Hide"}
.sm-check p{margin:0;padding:0 16px 14px;color:#0b6b4f;font-weight:700}
.sm-lesson-foot{display:flex;flex-wrap:wrap;gap:10px;padding:8px 24px 22px}
.sm-notes{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:22px}
.sm-note{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.sm-note img{display:block;width:100%;max-height:200px;object-fit:contain;background:#f4f8fd}
.sm-note div{padding:12px 14px}
.sm-note h3{margin:0 0 4px;font-size:15px;color:var(--navy)}
.sm-note p{margin:0;font-size:13px;color:var(--muted);line-height:1.5}
.sm-section-title{display:flex;align-items:center;gap:10px;margin:8px 0 14px;font-size:20px;font-weight:800;color:var(--navy)}
.sm-q{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:14px}
.sm-q-label{font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#2c7bd6}
.sm-q-text{margin:6px 0 12px;font-size:16px;font-weight:700;color:var(--navy)}
.sm-q img{display:block;max-width:320px;width:100%;border-radius:10px;margin-bottom:12px}
.practice-options{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.practice-option{display:flex;gap:8px;align-items:flex-start;padding:11px 13px;border:1px solid #dbe6f2;border-radius:10px;cursor:pointer;font-size:14px;background:#f8fbff}
.practice-option:hover{border-color:#81baff}
.practice-option:has(input:checked){border-color:var(--blue);background:#eaf3ff}
.practice-option input{margin-top:3px;accent-color:var(--blue)}
.practice-feedback{margin-top:10px;font-size:14px;font-weight:700;border-radius:10px}
.practice-feedback.correct{padding:10px 12px;background:#e7f8ef;color:#0b6b4f}
.practice-feedback.incorrect{padding:10px 12px;background:#fdecec;color:#b42f2a}
.sm-cta{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding:22px 26px;border-radius:20px;background:linear-gradient(115deg,#102744,#0b5354);color:#fff;margin-top:8px}
.sm-cta h3{margin:0 0 4px;font-size:20px;color:#fff}
.sm-cta p{margin:0;color:rgba(255,255,255,.8);font-size:14px}
.sm-cta .sm-btn-ghost{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff!important}
.sm-video-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-bottom:28px}
.sm-video{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;scroll-margin-top:96px}
.sm-video:target{box-shadow:0 0 0 3px var(--blue)}
.sm-video video{display:block;width:100%;aspect-ratio:16/9;background:#0d1b2a}
.sm-video div{padding:14px 16px 16px}
.sm-video h3{margin:0 0 4px;font-size:16px;color:var(--navy)}
.sm-video p{margin:0;font-size:13px;color:var(--muted);line-height:1.5}
.sm-sim-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.sm-sim{display:flex;flex-direction:column;gap:8px;padding:18px;background:#fff;border:1px solid var(--line);border-radius:16px}
.sm-sim h3{margin:0;font-size:16px;color:var(--navy)}
.sm-sim p{margin:0;font-size:13px;color:var(--muted);line-height:1.5;flex:1}
.sm-sim .row{display:flex;gap:8px}
@media(max-width:1100px){.sm-layout{grid-template-columns:1fr}.sm-toc{position:static}.sm-toc ol{flex-direction:row;flex-wrap:wrap}.sm-toc a{padding:6px 10px;background:#f4f8fd}}
@media(max-width:820px){.sm-hero{grid-template-columns:1fr}.sm-hero-img{min-height:200px}}
@media(max-width:640px){
  .sm-page{padding:20px 16px 40px}.sm-page h1{font-size:28px}
  .sm-chips{width:100%}.sm-chip{flex:1 1 auto}
  .sm-grid{grid-template-columns:1fr}
  .sm-hero-body{padding:22px 20px}.sm-hero-body h1{font-size:26px}
  .sm-tabs{display:flex}.sm-tab{flex:1;justify-content:center;padding:0 10px}
  .sm-lesson-head,.sm-lesson-body,.sm-lesson-foot{padding-left:18px;padding-right:18px}.sm-lesson-fig{margin:16px 18px 0}
  .practice-options{grid-template-columns:1fr}
  .sm-toc a{font-size:13px}
}
</style>

<main class="sm-page">
<?php if (!$topic): ?>

    <span class="sm-kicker">TestMate learning</span>
    <h1>Study Materials</h1>
    <p class="sm-sub">Short lessons with pictures, video demonstrations and practice for every topic in the learner&rsquo;s licence test.</p>

    <?php if ($topicId > 0): ?><div class="sm-empty" style="margin-bottom:20px">That topic was not found. Choose one below.</div><?php endif; ?>

    <div class="sm-tools">
        <label class="sm-search" for="smSearch">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="smSearch" type="search" placeholder="Search study topics..." aria-label="Search study topics">
        </label>
        <div class="sm-chips" role="group" aria-label="Filter by category">
            <button type="button" class="sm-chip" data-group="all" aria-pressed="true">All</button>
            <?php foreach ($grouped as $cat => $_): ?>
                <button type="button" class="sm-chip" data-group="<?= studyEsc($cat) ?>" aria-pressed="false"><?= studyEsc(STUDY_GROUPS[$cat][0] ?? $cat) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($grouped as $cat => $list): $accent = STUDY_GROUPS[$cat][1] ?? '#147df5'; ?>
        <section class="sm-group" data-group="<?= studyEsc($cat) ?>">
            <div class="sm-group-head">
                <span class="sm-group-dot" style="background:<?= $accent ?>"></span>
                <h2><?= studyEsc(STUDY_GROUPS[$cat][0] ?? $cat) ?></h2>
                <span class="count"><?= count($list) ?> topics</span>
            </div>
            <div class="sm-grid">
                <?php foreach ($list as $t): $id = (int)$t['id']; $img = studyTopicImage($id, (string)$t['name']); ?>
                    <?php $ro = studyReadOnly($cat); ?>
                    <article class="sm-card<?= $ro ? ' sm-card-plain' : '' ?>" style="--accent:<?= $accent ?>" data-search="<?= studyEsc(studyLower($t['name'] . ' ' . ($t['description'] ?? ''))) ?>">
                        <?php if ($ro): ?>
                        <a class="sm-card-icon" href="/study-materials.php?topic=<?= $id ?>&amp;mode=read" aria-label="<?= studyEsc($t['name']) ?>"><?= STUDY_ICON_LICENCE ?></a>
                        <?php else: ?>
                        <a class="sm-card-img" href="/study-materials.php?topic=<?= $id ?>&amp;mode=read" <?= $img ? 'style="background-image:url(\'' . studyEsc($img) . '\')"' : '' ?> aria-label="<?= studyEsc($t['name']) ?>">
                            <?php if (!$img): ?><span class="sm-emoji"><?= studyEsc($t['icon'] ?? '📘') ?></span><?php endif; ?>
                        </a>
                        <?php endif; ?>
                        <div class="sm-card-body">
                            <span class="sm-card-cat"><?= studyEsc(STUDY_GROUPS[$cat][0] ?? $cat) ?></span>
                            <h3><a href="/study-materials.php?topic=<?= $id ?>&amp;mode=read"><?= studyEsc($t['name']) ?></a></h3>
                            <p><?= studyEsc($t['description'] ?? '') ?></p>
                            <div class="sm-meta">
                                <span><?= (int)$t['lesson_count'] ?> lessons</span>
                                <?php if (!$ro): ?><span><?= (int)$t['video_count'] ?> videos</span><?php endif; ?>
                                <?php if (!$ro && (int)$t['sim_count']): ?><span><?= (int)$t['sim_count'] ?> simulations</span><?php endif; ?>
                            </div>
                            <?php if ($ro): ?>
                            <div class="sm-actions sm-actions-one">
                                <a class="sm-btn sm-btn-primary" href="/study-materials.php?topic=<?= $id ?>&amp;mode=read">Read</a>
                            </div>
                            <?php else: ?>
                            <div class="sm-actions">
                                <a class="sm-btn sm-btn-ghost" href="/study-materials.php?topic=<?= $id ?>&amp;mode=read">Read</a>
                                <a class="sm-btn sm-btn-primary" href="/study-materials.php?topic=<?= $id ?>&amp;mode=video#topic-content">&#9654; Watch</a>
                                <a class="sm-btn sm-btn-soft" href="/quiz.php?topic=<?= $id ?>">&#10003; Quiz</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <div class="sm-empty" id="smNoResults" hidden>No topics match your search.</div>

<?php else: $tid = (int)$topic['id']; $img = studyTopicImage($tid, (string)$topic['name']); $cat = trim((string)($topic['category'] ?? '')) ?: 'Road Traffic Law';
    $ro = studyReadOnly($cat); if ($ro) { $mode = 'read'; } ?>

    <a class="sm-back" href="/study-materials.php">&larr; All study topics</a>

    <section class="sm-hero<?= $ro ? ' sm-hero-plain' : '' ?>">
        <?php if ($ro): ?><div class="sm-hero-icon"><?= STUDY_ICON_LICENCE ?></div><?php else: ?>
        <div class="sm-hero-img" <?= $img ? 'style="background-image:url(\'' . studyEsc($img) . '\')"' : '' ?>></div><?php endif; ?>
        <div class="sm-hero-body">
            <span class="sm-kicker" style="color:<?= STUDY_GROUPS[$cat][1] ?? '#2c7bd6' ?>"><?= studyEsc(STUDY_GROUPS[$cat][0] ?? $cat) ?></span>
            <h1><?= studyEsc($topic['name']) ?></h1>
            <p><?= studyEsc($topic['description'] ?? '') ?></p>
            <div class="sm-meta">
                <span><?= count($lessons) ?> lessons</span>
                <?php if (!$ro): ?><span><?= count($videos) ?> videos</span><?php endif; ?>
                <?php if (!$ro && $simulations): ?><span><?= count($simulations) ?> simulations</span><?php endif; ?>
                <?php if ((int)$topic['question_count']): ?><span><?= (int)$topic['question_count'] ?> quiz questions</span><?php endif; ?>
            </div>
            <div class="sm-hero-actions">
                <a class="sm-btn sm-btn-primary" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=read#topic-content"><?= $ro ? 'Start reading' : 'Start lesson' ?></a>
                <?php if (!$ro): ?>
                <a class="sm-btn sm-btn-ghost" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=video#topic-content">&#9654; Videos</a>
                <a class="sm-btn sm-btn-soft" href="/quiz.php?topic=<?= $tid ?>">&#10003; Take the quiz</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($ro): ?><div id="topic-content"></div><?php else: ?>
    <nav class="sm-tabs" id="topic-content" aria-label="Topic sections">
        <a class="sm-tab <?= $mode === 'read' ? 'active' : '' ?>" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=read#topic-content">Lesson</a>
        <a class="sm-tab <?= $mode === 'video' ? 'active' : '' ?>" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=video#topic-content">Videos &amp; simulations</a>
    </nav>
    <?php endif; ?>

    <?php if ($mode === 'read'): ?>

        <?php if (!$lessons): ?>
            <div class="sm-empty">No lessons have been added to this topic yet.</div>
        <?php else: ?>
        <div class="sm-layout">
            <aside class="sm-toc">
                <h2>In this topic</h2>
                <ol>
                    <?php foreach ($lessons as $i => $l): ?>
                        <li><a href="#lesson-<?= (int)$l['id'] ?>"><b><?= $i + 1 ?></b><?= studyEsc($l['title']) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </aside>
            <div>
                <?php foreach ($lessons as $i => $l):
                    $file = studyMediaUrl($l['file_path'] ?? '');
                    $type = strtolower(trim((string)($l['file_type'] ?? '')));
                    $vid = $videoByTitle[studyLower($l['title'])] ?? null;
                ?>
                    <article class="sm-lesson" id="lesson-<?= (int)$l['id'] ?>">
                        <header class="sm-lesson-head">
                            <div class="sm-lesson-num"><?= $i + 1 ?></div>
                            <div><span>Lesson <?= $i + 1 ?> of <?= count($lessons) ?></span><h2><?= studyEsc($l['title']) ?></h2></div>
                        </header>
                        <?php if ($file !== '' && $type === 'image' && !$ro): ?>
                            <figure class="sm-lesson-fig"><img src="<?= studyEsc($file) ?>" alt="<?= studyEsc($l['title']) ?>" loading="lazy"></figure>
                        <?php endif; ?>
                        <div class="sm-lesson-body"><?= studyRenderLesson((string)($l['content'] ?? '')) ?></div>
                        <div class="sm-lesson-foot">
                            <?php if ($file !== '' && $type === 'pdf'): ?><a class="sm-btn sm-btn-ghost" href="<?= studyEsc($file) ?>" target="_blank" rel="noopener">Open PDF</a><?php endif; ?>
                            <?php if ($vid && !$ro): ?><a class="sm-btn sm-btn-primary" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=video#video-<?= (int)$vid['id'] ?>">&#9654; Watch this lesson</a><?php endif; ?>
                            <?php if (isset($lessons[$i + 1])): ?><a class="sm-btn sm-btn-ghost" href="#lesson-<?= (int)$lessons[$i + 1]['id'] ?>">Next lesson &rarr;</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if ($extraNotes): ?>
                    <h2 class="sm-section-title">More notes</h2>
                    <div class="sm-notes">
                        <?php foreach ($extraNotes as $n): $nf = studyMediaUrl($n['file_path'] ?? ''); $nt = strtolower(trim((string)($n['file_type'] ?? ''))); ?>
                            <article class="sm-note">
                                <?php if ($nf !== '' && $nt === 'image'): ?><img src="<?= studyEsc($nf) ?>" alt="<?= studyEsc($n['title']) ?>" loading="lazy"><?php endif; ?>
                                <div>
                                    <h3><?= studyEsc($n['title']) ?></h3>
                                    <?php if (trim((string)($n['content'] ?? '')) !== ''): ?><p><?= nl2br(studyEsc($n['content'])) ?></p><?php endif; ?>
                                    <?php if ($nf !== '' && $nt === 'pdf'): ?><p><a href="<?= studyEsc($nf) ?>" target="_blank" rel="noopener">Open PDF</a></p><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($questions): ?>
                    <h2 class="sm-section-title">Test your knowledge</h2>
                    <?php foreach ($questions as $index => $question):
                        $questionId = 'practice-' . (int)$question['id'];
                        $options = ['A' => $question['option_a'] ?? '', 'B' => $question['option_b'] ?? '', 'C' => $question['option_c'] ?? '', 'D' => $question['option_d'] ?? ''];
                        $correctAnswer = strtoupper(trim((string)($question['correct_answer'] ?? '')));
                        $explanation = (string)($question['explanation'] ?? '');
                        $imageUrl = studyMediaUrl($question['image_path'] ?? '');
                        $qType = strtolower((string)($question['file_type'] ?? 'image'));
                    ?>
                        <article class="sm-q">
                            <span class="sm-q-label">Question <?= $index + 1 ?></span>
                            <p class="sm-q-text"><?= studyEsc($question['question']) ?></p>
                            <?php if ($imageUrl !== '' && $qType === 'video'): ?><video src="<?= studyEsc($imageUrl) ?>" controls preload="metadata" style="max-width:420px;width:100%;border-radius:10px;margin-bottom:12px"></video>
                            <?php elseif ($imageUrl !== '' && $qType !== 'pdf'): ?><img src="<?= studyEsc($imageUrl) ?>" alt="Question illustration" loading="lazy"><?php endif; ?>
                            <div class="practice-options" id="<?= studyEsc($questionId) ?>">
                                <?php foreach ($options as $letter => $answer): if (trim((string)$answer) === '') continue; ?>
                                    <label class="practice-option">
                                        <input type="radio" name="<?= studyEsc($questionId) ?>" value="<?= studyEsc($letter) ?>" data-correct="<?= studyEsc($correctAnswer) ?>" data-explanation="<?= studyEsc($explanation) ?>">
                                        <strong><?= studyEsc($letter) ?>.</strong><span><?= studyEsc($answer) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="practice-feedback" id="<?= studyEsc($questionId) ?>-feedback" aria-live="polite"></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($ro): ?>
                <section class="sm-cta">
                    <div><h3>Finished reading?</h3><p>Continue with the other licence basics topics, then move on to road signs.</p></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="sm-btn sm-btn-primary" href="/study-materials.php">All study topics</a></div>
                </section>
                <?php else: ?>
                <section class="sm-cta">
                    <div><h3>Ready to test yourself?</h3><p>Take the full <?= studyEsc($topic['name']) ?> quiz, or watch the videos first.</p></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <a class="sm-btn sm-btn-ghost" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=video#topic-content">&#9654; Videos</a>
                        <a class="sm-btn sm-btn-primary" href="/quiz.php?topic=<?= $tid ?>">Take the quiz</a>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>

        <h2 class="sm-section-title">Video demonstrations</h2>
        <?php if (!$videos): ?>
            <div class="sm-empty" style="margin-bottom:24px">No videos for this topic yet.</div>
        <?php else: ?>
            <div class="sm-video-grid">
                <?php foreach ($videos as $v): $vf = studyMediaUrl($v['file_path']); $vp = $posterByTitle[studyLower($v['title'])] ?? ''; ?>
                    <article class="sm-video" id="video-<?= (int)$v['id'] ?>">
                        <video src="<?= studyEsc($vf) ?>" controls preload="metadata" playsinline<?= $vp !== '' ? ' poster="' . studyEsc($vp) . '"' : '' ?>></video>
                        <div>
                            <h3><?= studyEsc($v['title']) ?></h3>
                            <?php $desc = studySummary((string)($v['content'] ?? ''), 160); if ($desc !== ''): ?><p><?= studyEsc($desc) ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 class="sm-section-title">Interactive simulations</h2>
        <?php if (!$simulations): ?>
            <div class="sm-empty" style="margin-bottom:24px">No simulations for this topic yet. <a href="/simulations.php">Browse all simulations</a>.</div>
        <?php else: ?>
            <div class="sm-sim-grid" style="margin-bottom:24px">
                <?php foreach ($simulations as $s): ?>
                    <article class="sm-sim">
                        <h3><?= studyEsc($s['title']) ?></h3>
                        <p><?= studyEsc($s['description'] ?? '') ?></p>
                        <div class="row">
                            <a class="sm-btn sm-btn-ghost" href="/simulations.php?id=<?= (int)$s['id'] ?>&amp;mode=study">Study mode</a>
                            <a class="sm-btn sm-btn-primary" href="/simulations.php?id=<?= (int)$s['id'] ?>&amp;mode=quiz">Quiz mode</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="sm-cta">
            <div><h3>Done watching?</h3><p>Read the lesson notes, then take the topic quiz.</p></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a class="sm-btn sm-btn-ghost" href="/study-materials.php?topic=<?= $tid ?>&amp;mode=read#topic-content">Read lesson</a>
                <a class="sm-btn sm-btn-primary" href="/quiz.php?topic=<?= $tid ?>">Take the quiz</a>
            </div>
        </section>

    <?php endif; ?>
<?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* topic search + category filter */
    const search = document.getElementById('smSearch');
    const chips = Array.from(document.querySelectorAll('.sm-chip'));
    const groups = Array.from(document.querySelectorAll('.sm-group'));
    const empty = document.getElementById('smNoResults');
    let filter = 'all';
    function apply() {
        const q = search ? search.value.trim().toLowerCase() : '';
        let shown = 0;
        groups.forEach(function (g) {
            const inGroup = filter === 'all' || g.dataset.group === filter;
            let visible = 0;
            g.querySelectorAll('.sm-card').forEach(function (c) {
                const ok = inGroup && (q === '' || c.dataset.search.includes(q));
                c.hidden = !ok; if (ok) visible++;
            });
            g.hidden = visible === 0; shown += visible;
        });
        if (empty) empty.hidden = shown !== 0;
    }
    chips.forEach(function (c) { c.addEventListener('click', function () { filter = c.dataset.group; chips.forEach(function (x) { x.setAttribute('aria-pressed', String(x === c)); }); apply(); }); });
    if (search) search.addEventListener('input', apply);

    /* only one video plays at a time */
    const players = Array.from(document.querySelectorAll('.sm-video video'));
    players.forEach(function (p) { p.addEventListener('play', function () { players.forEach(function (o) { if (o !== p) o.pause(); }); }); });

    /* instant practice question feedback */
    document.querySelectorAll('.practice-options input[type="radio"]').forEach(function (input) {
        input.addEventListener('change', function () {
            const group = input.closest('.practice-options');
            const feedback = group && document.getElementById(group.id + '-feedback');
            if (!feedback) return;
            const selected = String(input.value || '').toUpperCase();
            const correct = String(input.dataset.correct || '').toUpperCase();
            const explanation = input.dataset.explanation || '';
            const passed = selected === correct;
            feedback.classList.remove('correct', 'incorrect');
            feedback.classList.add(passed ? 'correct' : 'incorrect');
            feedback.textContent = passed ? 'Correct! ' + explanation : 'Not quite. Correct answer: ' + correct + '. ' + explanation;
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>