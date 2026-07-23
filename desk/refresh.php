<?php
/**
 * Manual operator refresh endpoint for the dashboard.
 *
 * Behind the desk password gate. Pulls are deliberately manual (no cron) to stay
 * within operator API rate limits.
 *
 *   GET  refresh.php?op=quark                          -> Quark status (last pull + report)
 *   POST refresh.php  op=quark  action=refresh         -> Quark live pull + report
 *
 *   GET  refresh.php?op=aurora                         -> Aurora status (services + packages)
 *   POST refresh.php  op=aurora action=services        -> Aurora Services rate refresh + report
 *   POST refresh.php  op=aurora action=packages        -> Aurora Packages catalogue refresh + report
 */

define('LGP_APP', 1);
require __DIR__ . '/live-fetch.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$op     = $_GET['op']     ?? $_POST['op']     ?? 'quark';
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$dir    = __DIR__;
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($op === 'quark') {
    if ($isPost && $action === 'refresh') { @set_time_limit(0); echo json_encode(lgp_quark_refresh_report($dir)); exit; }
    echo json_encode(lgp_quark_status($dir));
    exit;
}

if ($op === 'aurora') {
    if ($isPost && $action === 'services') { @set_time_limit(0); echo json_encode(lgp_aurora_services_refresh_report($dir)); exit; }
    if ($isPost && $action === 'packages') { @set_time_limit(0); echo json_encode(lgp_aurora_packages_report($dir)); exit; }
    echo json_encode(['services' => lgp_aurora_services_status($dir), 'packages' => lgp_aurora_packages_status($dir)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_op', 'op' => $op]);
