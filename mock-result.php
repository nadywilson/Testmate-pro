<?php
/**
 * TestMate – old mock result address.
 * Marking and results now live in mock-test.php; this keeps old links working.
 */
$id = max(0, (int)($_GET['id'] ?? $_GET['result_id'] ?? 0));
header('Location: /mock-test.php' . ($id ? '?result_id=' . $id : ''), true, 303);
exit;