<?php
/**
 * TestMate - import the new study content (lessons, lesson pictures, videos, topic pictures).
 *
 * 1. Copy the "content" folder into  C:\projects\testmate\tools\content
 *    (it holds manifest.json and the folders videos, lessons, topics).
 * 2. From C:\projects\testmate run:
 *        C:\php\php.exe tools\import-content.php
 *
 * Options:
 *   --dry-run       show what would happen, change nothing
 *   --update-text   also refresh the text of lessons/videos that were imported before
 *   --source=PATH   use a different content folder
 *
 * What it does:
 *   - backs up the database file first (database.backup-YYYYmmdd-His.sqlite next to it)
 *   - copies videos and lesson pictures to uploads/materials/, topic pictures to images/topics/
 *   - adds one lesson row (file_type 'image', sort_order -100+n) and one video row
 *     (file_type 'video', sort_order n) per unit to the materials table
 *   - skips rows that already exist (same topic, title and type), so it is safe to run again
 *   - never deletes or changes your existing materials
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line: php tools/import-content.php');
}

$root = dirname(__DIR__);
$opts = getopt('', ['dry-run', 'update-text', 'source:']);
$dry = isset($opts['dry-run']);
$updateText = isset($opts['update-text']);
$src = rtrim($opts['source'] ?? (__DIR__ . '/content'), '/\\');

function out(string $msg): void { fwrite(STDOUT, $msg . PHP_EOL); }
function fail(string $msg): never { fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL); exit(1); }

$manifestFile = $src . '/manifest.json';
if (!is_file($manifestFile)) fail("manifest.json not found in $src. Copy the content folder to tools/content first.");
$manifest = json_decode((string)file_get_contents($manifestFile), true);
if (!is_array($manifest['units'] ?? null)) fail('manifest.json is not valid.');

require $root . '/includes/db_connect.php';
if (!isset($conn) || !$conn instanceof PDO) fail('includes/db_connect.php did not create $conn.');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- backup ---------------------------------------------------------------
$dbFile = '';
foreach ($conn->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['name'] === 'main') $dbFile = (string)$row['file'];
}
if ($dbFile === '' || !is_file($dbFile)) fail('Could not find the SQLite database file.');
if (!$dry) {
    $base = preg_replace('/\.(sqlite3?|db)$/i', '', $dbFile) . '.backup-' . date('Ymd-His');
    $backup = $base . '.sqlite';
    for ($i = 2; is_file($backup); $i++) $backup = "$base-$i.sqlite";
    if (!copy($dbFile, $backup)) fail("Could not back up the database to $backup");
    out("Backup:   $backup");
}

// ---- files ----------------------------------------------------------------
$copy = static function (string $from, string $toDir, string $name) use ($dry): bool {
    if (!is_file($from)) return false;
    if (!is_dir($toDir) && !$dry && !mkdir($toDir, 0775, true)) fail("Could not create $toDir");
    $to = $toDir . '/' . $name;
    if (is_file($to) && filesize($to) === filesize($from)) return true;
    if (!$dry && !copy($from, $to)) fail("Could not copy $name");
    return true;
};

$topicIds = array_map('intval', $conn->query('SELECT id FROM topics')->fetchAll(PDO::FETCH_COLUMN));
$posters = 0;
foreach (glob($src . '/topics/topic-*.jpg') ?: [] as $p) {
    if ($copy($p, $root . '/images/topics', basename($p))) $posters++;
}

// ---- rows -----------------------------------------------------------------
// Lessons match only earlier imported lessons (negative sort_order), so older notes with the same title stay separate.
// Videos match any video with the same title, so videos you already uploaded by hand are not added twice.
$find = $conn->prepare('SELECT id FROM materials WHERE topic_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) AND LOWER(COALESCE(file_type, \'\')) = ?
    AND (? = \'video\' OR COALESCE(sort_order, 0) < 0) LIMIT 1');
$nextId = static fn(): int => (int)$conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM materials')->fetchColumn();
$insert = $conn->prepare('INSERT INTO materials (id, topic_id, title, content, file_path, file_type, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
$updateRow = $conn->prepare('UPDATE materials SET content = ? WHERE id = ?');

$firstParagraph = static function (string $notes): string {
    foreach (preg_split('/\R/', $notes) as $line) {
        $line = trim($line);
        if ($line !== '' && !preg_match('/^(##|-|!|\?) /', $line)) return $line;
    }
    return '';
};

$stats = ['lessons' => 0, 'videos' => 0, 'skipped' => 0, 'updated' => 0, 'missing' => 0, 'notopic' => 0];
$conn->beginTransaction();
try {
    foreach ($manifest['units'] as $u) {
        $tid = (int)$u['topic_id'];
        $n = (int)$u['n'];
        $title = trim((string)$u['title']);
        $notes = trim((string)$u['notes']);
        if (!in_array($tid, $topicIds, true)) { $stats['notopic']++; out("  no topic $tid in database - skipped \"$title\""); continue; }

        $rows = [
            ['image', $src . '/lessons/' . $u['lesson'], $u['lesson'], $notes, -100 + $n, 'lessons'],
            ['video', $src . '/videos/' . $u['video'], $u['video'], $firstParagraph($notes), $n, 'videos'],
        ];
        foreach ($rows as [$type, $from, $name, $content, $sort, $key]) {
            $find->execute([$tid, $title, $type, $type]);
            $existing = $find->fetchColumn();
            if ($existing !== false) {
                $copy($from, $root . '/uploads/materials', $name); // refresh the file if a newer one was supplied
                if ($updateText) { if (!$dry) $updateRow->execute([$content, (int)$existing]); $stats['updated']++; }
                else $stats['skipped']++;
                continue;
            }
            if (!$copy($from, $root . '/uploads/materials', $name)) { $stats['missing']++; out("  missing file $name - skipped"); continue; }
            if (!$dry) $insert->execute([$nextId(), $tid, $title, $content, '/uploads/materials/' . $name, $type, $sort]);
            $stats[$key]++;
        }
    }
    $dry ? $conn->rollBack() : $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    fail($e->getMessage() . ' (nothing was changed in the database)');
}

out(($dry ? '[dry run] ' : '') . 'Done.');
out("  lessons added:      {$stats['lessons']}");
out("  videos added:       {$stats['videos']}");
out("  already there:      {$stats['skipped']}" . ($stats['skipped'] ? '  (use --update-text to refresh their text)' : ''));
if ($updateText) out("  text refreshed:     {$stats['updated']}");
out("  topic pictures:     $posters");
if ($stats['missing']) out("  missing files:      {$stats['missing']}");
if ($stats['notopic']) out("  unknown topics:     {$stats['notopic']}");