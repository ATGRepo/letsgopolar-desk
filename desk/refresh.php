<?php
/**
 * Manual operator refresh endpoint for the dashboard.
 *
 * Lives behind the desk password gate. The pull is deliberately manual (no
 * cron) to stay within operator API rate limits.
 *
 *   GET  refresh.php?op=quark                 -> current status (last pull + last report)
 *   POST refresh.php   (op=quark, action=refresh) -> run a live pull, return before/after report
 */

define('LGP_APP', 1);
require __DIR__ . '/live-fetch.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$op     = $_GET['op']     ?? $_POST['op']     ?? 'quark';
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$dir    = __DIR__;

if ($op !== 'quark') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_op', 'op' => $op]);
    exit;
}

// A live pull must be an explicit POST, so a page load or prefetch never triggers it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'refresh') {
    @set_time_limit(0);
    echo json_encode(lgp_quark_refresh_report($dir));
    exit;
}

// Default: report current status without pulling.
echo json_encode(lgp_quark_status($dir));
