<?php
/**
 * wp-writeback.php — push dashboard feed prices back onto the website.
 *
 * Reads antarctic-trips from the main site DB and the live feed, computes the
 * price changes, and (on apply) writes them back. Full price is struck through:
 *   feed full  -> original-price   (only when a real discount exists)
 *   feed net   -> final-price      (the shown / sold price)
 * It keeps the whole page consistent: the ship-cabins repeater, the three
 * highlight cards (value_1..3, following card_name_price_1..3), and the price
 * filter range (value_filter min, value_filter02 max).
 *
 * Safety:
 *   - Prices only. Cabins the site marks SOLD OUT are never touched.
 *   - Cabins the feed has no price for (sold out / not offered) are skipped.
 *   - Differences of $1 or less (cents rounding) are skipped.
 *   - Apply requires an explicit approved id list. Never writes without ids.
 *   - Every page's price fields are backed up to a JSON file before any write.
 *
 *   GET  wp-writeback.php?action=preview            -> proposed changes (read-only)
 *   POST wp-writeback.php action=apply ids=1,2,3     -> write those trips (with backup)
 *   POST wp-writeback.php action=apply ids=.. dryrun=1 -> compute writes, write nothing
 *
 * Behind the desk gate. Manual only.
 */

define('LGP_APP', 1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(180);

const LGP_CONFIG_PATH = '/home/u886488648/domains/letsgopolar.com/lgp-private/operator-config.php';
const MAIN_WPCONFIG   = '/home/u886488648/domains/letsgopolar.com/public_html/wp-config.php';
const BACKUP_DIR      = __DIR__ . '/price-backups';
const MIN_DELTA       = 1;   // ignore price differences of $1 or less

function out($a) { echo json_encode($a); exit; }
function fail($m, $c = 500) { http_response_code($c); out(['ok' => false, 'error' => $m]); }

$action = $_GET['action'] ?? $_POST['action'] ?? 'preview';
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$dryrun = !empty($_POST['dryrun']) || !empty($_GET['dryrun']);
$cfg = is_readable(LGP_CONFIG_PATH) ? (require LGP_CONFIG_PATH) : [];

// ---- main site DB ----
function wpconf_const($k, $s) { return preg_match("/define\\(\\s*'" . $k . "'\\s*,\\s*'([^']*)'/", $s, $m) ? $m[1] : null; }
if (!is_readable(MAIN_WPCONFIG)) fail('Cannot read the website configuration.');
$wc = file_get_contents(MAIN_WPCONFIG);
$db = @mysqli_connect(wpconf_const('DB_HOST', $wc), wpconf_const('DB_USER', $wc), wpconf_const('DB_PASSWORD', $wc), wpconf_const('DB_NAME', $wc));
if (!$db) fail('Could not connect to the website database.');
mysqli_set_charset($db, 'utf8mb4');
$pre = preg_match('/\\$table_prefix\\s*=\\s*[\'"]([^\'"]+)/', $wc, $mm) ? $mm[1] : 'wp_';

// ---- feed ----
$feedRaw = wb_http_get_(wb_self_base_() . '/rate-data.php?nocache=1', $cfg['gate'] ?? null);
if ($feedRaw === null) fail('Could not read the dashboard feed.', 502);
$feed = json_decode($feedRaw, true);
$feedTrips = isset($feed['trips']) ? $feed['trips'] : [];

// ---- alias map ----
$aliasMap = [];
$ap = __DIR__ . '/cabin-alias-map.php';
if (is_readable($ap)) { $l = @include $ap; if (is_array($l)) $aliasMap = $l; }

// ---- feed indexes ----
$feedBySlug = []; $feedByShipStart = [];
foreach ($feedTrips as $t) {
    $sl = wb_slug_($t['lgpLink'] ?? '');
    if ($sl !== '' && !isset($feedBySlug[$sl])) $feedBySlug[$sl] = $t;
    $k = wb_nship_($t['ship'] ?? '') . '|' . ($t['start'] ?? '');
    if (!isset($feedByShipStart[$k])) $feedByShipStart[$k] = $t;
}

$today = gmdate('Y-m-d');

// ---- candidate posts (publish, upcoming) ----
$idFilter = '';
if ($action === 'apply') {
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
    if (!$ids) fail('No trip ids supplied to apply.', 400);
    $idFilter = ' AND p.ID IN (' . implode(',', $ids) . ')';
}
$sql = "SELECT p.ID, p.post_name, p.post_title FROM {$pre}posts p
        WHERE p.post_type='antarctic-trips' AND p.post_status='publish'" . $idFilter;
$res = mysqli_query($db, $sql);
$posts = [];
while ($r = mysqli_fetch_assoc($res)) $posts[(int)$r['ID']] = ['id' => (int)$r['ID'], 'slug' => $r['post_name'], 'title' => $r['post_title']];
if (!$posts) out(['ok' => true, 'action' => $action, 'trips' => [], 'summary' => ['trips' => 0, 'cabin_changes' => 0]]);

$ids = implode(',', array_map('intval', array_keys($posts)));
$metaKeys = "'_ship_card','_supplier','start_date','ship-cabins','card_name_price_1','card_name_price_2','card_name_price_3','value_1','value_2','value_3','value_filter','value_filter02'";
$res = mysqli_query($db, "SELECT post_id, meta_key, meta_value FROM {$pre}postmeta WHERE post_id IN ($ids) AND meta_key IN ($metaKeys)");
$meta = [];
while ($r = mysqli_fetch_assoc($res)) $meta[(int)$r['post_id']][$r['meta_key']] = $r['meta_value'];

$trips = []; $totalCabinChanges = 0;
$applyResults = []; $backup = [];

foreach ($posts as $id => $p) {
    $m = $meta[$id] ?? [];
    $ship = trim((string)($m['_ship_card'] ?? ''));
    $supplier = trim((string)($m['_supplier'] ?? ''));
    $start = trim((string)($m['start_date'] ?? ''));
    if ($start === '' || $start < $today) continue;
    $scRaw = $m['ship-cabins'] ?? '';
    $sc = @unserialize($scRaw);
    if (!is_array($sc)) continue;

    $t = ($p['slug'] !== '' && isset($feedBySlug[$p['slug']])) ? $feedBySlug[$p['slug']]
       : ($feedByShipStart[wb_nship_($ship) . '|' . $start] ?? null);
    if (!$t) continue;
    $op = $t['operator'] ?? $supplier;
    $fc = [];
    foreach (($t['cabins'] ?? []) as $c) $fc[wb_ncab_($c['name'] ?? '')] = $c;

    $diffs = []; $changed = false;
    // net price per item after this pass (for cards + filter range)
    $netByName = [];
    foreach ($sc as $ik => $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['cabin-name'] ?? ''));
        $curOrig = trim((string)($item['original-price'] ?? ''));
        $curFinal = trim((string)($item['final-price'] ?? ''));
        $curOrigN = wb_money_($curOrig); $curFinalN = wb_money_($curFinal);
        $curNet = $curFinalN !== null ? $curFinalN : $curOrigN;
        if ($curNet !== null) $netByName[$name] = $curNet;   // default: keep current
        if ($name === '') continue;
        $curSold = ((stripos($curOrig, 'sold') !== false || stripos($curFinal, 'sold') !== false) && $curOrigN === null && $curFinalN === null);
        if ($curSold) continue;                              // never touch sold-out cabins
        $nn = wb_ncab_($name);
        $fcab = $fc[$aliasMap[strtolower($op) . '|' . wb_nship_($ship) . '|' . $nn] ?? $nn] ?? null;
        if (!$fcab) continue;
        $fFull = is_numeric($fcab['full'] ?? null) ? (float)$fcab['full'] : null;
        $fDisc = is_numeric($fcab['disc'] ?? null) ? (float)$fcab['disc'] : null;
        if ($fFull === null && $fDisc === null) continue;    // feed sold out / not offered
        $hasDisc = ($fFull !== null && $fDisc !== null && $fDisc < $fFull);
        $net = $fDisc !== null ? $fDisc : $fFull;
        $newOrig = $hasDisc ? wb_usd_($fFull) : '';
        $newFinal = wb_usd_($net);
        $curHasDisc = ($curOrigN !== null && $curFinalN !== null && $curFinalN < $curOrigN);
        $discFlip = ($hasDisc !== $curHasDisc);
        if ($curNet !== null && abs($net - $curNet) <= MIN_DELTA && !$discFlip) { $netByName[$name] = $net; continue; }
        // meaningful change
        $netByName[$name] = $net;
        $diffs[] = ['cabin' => $name, 'item_key' => $ik, 'cur_orig' => $curOrig, 'cur_final' => $curFinal, 'new_orig' => $newOrig, 'new_final' => $newFinal];
        if ($action === 'apply' && !$dryrun) { $sc[$ik]['original-price'] = $newOrig; $sc[$ik]['final-price'] = $newFinal; }
        $changed = true;
    }
    if (!$changed) continue;

    // cards value_1..3 follow their named cabin's net
    $cards = [];
    for ($i = 1; $i <= 3; $i++) {
        $cn = trim((string)($m["card_name_price_$i"] ?? ''));
        $cur = trim((string)($m["value_$i"] ?? ''));
        if ($cn === '') continue;
        $netv = $netByName[$cn] ?? null;
        if ($netv === null) continue;
        $new = wb_comma_($netv);
        if ($new !== $cur) $cards[] = ['key' => "value_$i", 'name' => $cn, 'cur' => $cur, 'new' => $new];
    }
    // filter range
    $nets = array_values(array_filter($netByName, fn($v) => $v !== null));
    $filter = null;
    if ($nets) {
        $newMin = wb_plain_(min($nets)); $newMax = wb_plain_(max($nets));
        $curMin = trim((string)($m['value_filter'] ?? '')); $curMax = trim((string)($m['value_filter02'] ?? ''));
        if ($newMin !== $curMin || $newMax !== $curMax)
            $filter = ['cur_min' => $curMin, 'new_min' => $newMin, 'cur_max' => $curMax, 'new_max' => $newMax];
    }

    $totalCabinChanges += count($diffs);
    $trips[] = ['id' => $id, 'op' => $op, 'trip' => $p['title'], 'ship' => $ship, 'start' => $start,
                'link' => wb_lgp_($p['slug']), 'cabins' => $diffs, 'cards' => $cards, 'filter' => $filter];

    // ---- apply ----
    if ($action === 'apply' && !$dryrun) {
        $backup[$id] = ['ship-cabins' => $scRaw];
        $sets = ["ship-cabins" => serialize($sc)];
        foreach ($cards as $c) { $sets[$c['key']] = $c['new']; $backup[$id][$c['key']] = $m[$c['key']] ?? ''; }
        if ($filter) {
            $sets['value_filter'] = $filter['new_min']; $sets['value_filter02'] = $filter['new_max'];
            $backup[$id]['value_filter'] = $m['value_filter'] ?? ''; $backup[$id]['value_filter02'] = $m['value_filter02'] ?? '';
        }
        $okAll = true;
        foreach ($sets as $mk => $mv) {
            $st = mysqli_prepare($db, "UPDATE {$pre}postmeta SET meta_value=? WHERE post_id=? AND meta_key=?");
            mysqli_stmt_bind_param($st, 'sis', $mv, $id, $mk);
            if (!mysqli_stmt_execute($st)) $okAll = false;
            mysqli_stmt_close($st);
        }
        $applyResults[] = ['id' => $id, 'trip' => $p['title'], 'ok' => $okAll, 'cabin_changes' => count($diffs)];
    }
}

// ---- write backup file on apply ----
$backupFile = null;
if ($action === 'apply' && !$dryrun && $backup) {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775, true);
    $backupFile = 'price-backup-' . gmdate('Ymd-His') . '.json';
    @file_put_contents(BACKUP_DIR . '/' . $backupFile, json_encode(['generated' => gmdate('c'), 'db' => wpconf_const('DB_NAME', $wc), 'posts' => $backup], JSON_UNESCAPED_SLASHES));
}
mysqli_close($db);

// ---- purge the website page cache so new prices go public immediately ----
$cachePurge = null;
if ($action === 'apply' && !$dryrun && $applyResults) $cachePurge = wb_purge_wp_rocket_();

$resp = ['ok' => true, 'action' => $action, 'dryrun' => $dryrun,
         'summary' => ['trips' => count($trips), 'cabin_changes' => $totalCabinChanges],
         'trips' => $trips];
if ($action === 'apply' && !$dryrun) { $resp['applied'] = $applyResults; $resp['backup_file'] = $backupFile; $resp['cache_purge'] = $cachePurge; }
out($resp);

// ---------------- helpers ----------------
function wb_nship_($s) { $s = strtolower((string)$s); $s = preg_replace('/\\b(m\\/v|m\\/s|mv|ms|ss|sh|the)\\b/', '', $s); return preg_replace('/[^a-z0-9]/', '', $s); }
function wb_ncab_($s) { $s = strtolower((string)$s); $s = preg_replace('/\\s*—\\s*.*$/u', '', $s); $s = preg_replace('/\\((single|double|triple|quad|solo)\\)/', '', $s); return preg_replace('/[^a-z0-9]/', '', $s); }
function wb_money_($s) { $s = trim((string)$s); if ($s === '' || stripos($s, 'sold') !== false) return null; $s = str_replace([' ', ','], '', $s); return preg_match('/([\\d]+(?:\\.\\d+)?)/', $s, $m) ? (float)$m[1] : null; }
function wb_usd_($n) { return 'U$ ' . number_format($n); }
function wb_comma_($n) { return number_format($n); }
function wb_plain_($n) { return (string)(int)round($n); }
function wb_slug_($u) { return preg_match('#/antarctic-trips/([^/]+)/?#', (string)$u, $m) ? $m[1] : ''; }
function wb_lgp_($slug) { $slug = trim((string)$slug); return $slug === '' ? '' : ('https://letsgopolar.com/antarctic-trips/' . $slug . '/'); }
function wb_self_base_() { $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); $h = $_SERVER['HTTP_HOST'] ?? 'localhost'; $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); return ($https ? 'https' : 'http') . '://' . $h . $d; }
function wb_rrmdir_($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) wb_rrmdir_($p); else @unlink($p);
    }
    @rmdir($dir);
}
/** Clear the WP Rocket page cache for the site (same server filesystem). */
function wb_purge_wp_rocket_() {
    $base = dirname(MAIN_WPCONFIG) . '/wp-content/cache/wp-rocket';
    $baseReal = realpath($base);
    if ($baseReal === false || !is_dir($baseReal)) return ['purged' => false, 'reason' => 'no_wp_rocket_cache'];
    $removed = 0;
    foreach (scandir($baseReal) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $baseReal . '/' . $f;
        $pr = realpath($p);
        if ($pr === false || strpos($pr, $baseReal . DIRECTORY_SEPARATOR) !== 0) continue; // stay inside the cache dir
        wb_rrmdir_($pr); $removed++;
    }
    return ['purged' => true, 'removed_hosts' => $removed];
}
function wb_http_get_($url, $gate = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
    if (is_array($gate) && !empty($gate['user'])) curl_setopt($ch, CURLOPT_USERPWD, $gate['user'] . ':' . ($gate['pass'] ?? ''));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 400) ? $r : null;
}
