<?php
/**
 * TestMate – one-time database repair: automatic record IDs
 *
 * Run from PowerShell in C:\projects\testmate :
 *     C:\php\php.exe tools\fix-table-ids.php
 *
 * Why: when the database was converted from MySQL, every table kept
 * "id INTEGER NOT NULL" but lost AUTO_INCREMENT. Any page that inserts a
 * row without choosing an id (Add Material, Add User, Assign Quiz,
 * registration, ...) then fails with "NOT NULL constraint failed: id".
 *
 * What it does:
 *   1. Backs up the SQLite database file next to the original.
 *   2. For each table whose "id" column is not yet a primary key, rebuilds
 *      the table with  "id" INTEGER PRIMARY KEY  (SQLite then numbers new
 *      rows automatically). Every column, default, row and index is kept,
 *      and every existing id stays exactly the same.
 *   3. Skips any table with duplicate or empty ids and reports it.
 *
 * Safe to run more than once: already-fixed tables are skipped.
 * Pages that already pick their own id (quiz, mock test, simulations)
 * keep working unchanged.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line only.');
}

require __DIR__ . '/../includes/db_connect.php';

/** @var PDO $conn */
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function out(string $msg): void { fwrite(STDOUT, $msg . PHP_EOL); }
function q(string $name): string { return '"' . str_replace('"', '""', $name) . '"'; }

/* ---------------------------------------------------------------- backup */
$dbFile = '';
foreach ($conn->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $db) {
    if (($db['name'] ?? '') === 'main') { $dbFile = (string)($db['file'] ?? ''); }
}
if ($dbFile === '' || !is_file($dbFile)) {
    exit('Could not locate the database file. Nothing was changed.' . PHP_EOL);
}
$backup = preg_replace('/\.sqlite$/i', '', $dbFile) . '-backup-' . date('Ymd-His') . '.sqlite';
if (!copy($dbFile, $backup)) {
    exit("Could not create a backup at $backup. Nothing was changed." . PHP_EOL);
}
out("Backup created: $backup");

/* --------------------------------------------------------------- tables */
$tables = $conn->query("
    SELECT name, sql FROM sqlite_master
    WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$fixed = 0; $skipped = 0; $already = 0;

$conn->exec('PRAGMA foreign_keys = OFF');

foreach ($tables as $t) {
    $table = (string)$t['name'];
    $sql   = (string)$t['sql'];

    $cols = $conn->query('PRAGMA table_info(' . q($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    $idCol = null;
    foreach ($cols as $c) { if (strtolower((string)$c['name']) === 'id') { $idCol = $c; } }

    if ($idCol === null) { continue; }                 // no id column (e.g. token tables)
    if ((int)$idCol['pk'] > 0) { $already++; continue; } // already a primary key
    if (strtoupper((string)$idCol['type']) !== 'INTEGER') {
        out("  skip  $table  (id column is not INTEGER)"); $skipped++; continue;
    }

    $check = $conn->query('SELECT COUNT(*) AS n, COUNT(id) AS nn, COUNT(DISTINCT id) AS d FROM ' . q($table))->fetch(PDO::FETCH_ASSOC);
    if ((int)$check['n'] !== (int)$check['nn'] || (int)$check['nn'] !== (int)$check['d']) {
        out("  skip  $table  (has empty or duplicate ids – fix those rows first)"); $skipped++; continue;
    }

    // Rewrite only the id column definition and the table name.
    $newSql = preg_replace('/("id"|`id`|\bid\b)\s+INTEGER\s+NOT\s+NULL/i', '"id" INTEGER PRIMARY KEY', $sql, 1, $count);
    if ($count !== 1 || stripos($newSql, 'PRIMARY KEY') === false || preg_match_all('/PRIMARY\s+KEY/i', $newSql) !== 1) {
        out("  skip  $table  (unexpected table definition)"); $skipped++; continue;
    }
    $tmp = $table . '__tm_new';
    $newSql = preg_replace('/^CREATE\s+TABLE\s+("[^"]+"|`[^`]+`|\S+)/i', 'CREATE TABLE ' . q($tmp), $newSql, 1);

    $indexes = $conn->prepare("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL");
    $indexes->execute([$table]);
    $indexSql = $indexes->fetchAll(PDO::FETCH_COLUMN);

    $colList = implode(', ', array_map(static fn($c) => q((string)$c['name']), $cols));

    try {
        $conn->beginTransaction();
        $conn->exec('DROP TABLE IF EXISTS ' . q($tmp));
        $conn->exec($newSql);
        $conn->exec('INSERT INTO ' . q($tmp) . " ($colList) SELECT $colList FROM " . q($table));
        $before = (int)$conn->query('SELECT COUNT(*) FROM ' . q($table))->fetchColumn();
        $after  = (int)$conn->query('SELECT COUNT(*) FROM ' . q($tmp))->fetchColumn();
        if ($before !== $after) { throw new RuntimeException("row count changed ($before -> $after)"); }
        $conn->exec('DROP TABLE ' . q($table));
        $conn->exec('ALTER TABLE ' . q($tmp) . ' RENAME TO ' . q($table));
        foreach ($indexSql as $ix) { $conn->exec((string)$ix); }
        $conn->commit();
        out("  fixed $table  ($after rows)");
        $fixed++;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        out("  FAILED $table: " . $e->getMessage() . '  (table left unchanged)');
        $skipped++;
    }
}

$conn->exec('PRAGMA foreign_keys = ON');
$integrity = (string)$conn->query('PRAGMA integrity_check')->fetchColumn();

out('');
out("Done. Fixed: $fixed   Already fine: $already   Skipped: $skipped");
out("Database integrity check: $integrity");
out("If anything looks wrong, restore the backup: $backup");