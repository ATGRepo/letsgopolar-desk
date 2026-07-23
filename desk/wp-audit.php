<?php
/**
 * wp-audit.php — live WordPress vs Dashboard data audit.
 *
 * Reads the website's antarctic-trips DIRECTLY from the main site database
 * (read-only) and diffs them against the live dashboard feed (rate-data.php on
 * this host). No CSV upload, no export step: the audit always sees current data.
 *
 * Main DB credentials are read at runtime from the main site's wp-config.php
 * (one level up from this desk folder). They are never copied, stored, or logged.
 * The gate credentials used to read the feed come from the server-only
 * operator-config.php (outside the web root, not in the repo).
 *
 * Behind the desk password gate. Manual only.
 *
 *   GET wp-audit.php            -> run the audit, return JSON
 *   GET wp-audit.php?debug=1    -> include timing + connection diagnostics
 *
 * Buckets (nothing is silently dropped):
 *   - price_mismatches:   cabins on BOTH sides with a real price that differ.
 *   - availability_flags: one side has no comparable price (sold out, or the
 *                         cabin is not offered on that sailing). Lower confidence.
 *   - wp_not_in_dashboard: published, upcoming website trips with no feed match.
 *   - dashboard_orphans:   upcoming feed trips with no published website page.
 *   - past_still_published / bad_dates: website hygiene.
 */

define('LGP_APP', 1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(120);

const LGP_CONFIG_PATH  = '/home/u886488648/domains/letsgopolar.com/lgp-private/operator-config.php';
const MAIN_WPCONFIG    = '/home/u886488648/domains/letsgopolar.com/public_html/wp-config.php';

$DEBUG = isset($_GET['debug']);
$t0 = microtime(true);

function out($arr) { echo json_encode($arr); exit; }
function fail($msg, $code = 500) { http_response_code($code); out(['ok' => false, 'error' => $msg]); }

$cfg = is_readable(LGP_CONFIG_PATH) ? (require LGP_CONFIG_PATH) : [];

// ---------------- main site DB (read-only) ----------------
function wpconf_const($key, $src) {
    return preg_match("/define\\(\\s*'" . $key . "'\\s*,\\s*'([^']*)'/", $src, $m) ? $m[1] : null;
}
if (!is_readable(MAIN_WPCONFIG)) fail('Cannot read the website configuration on this server.');
$wc = file_get_contents(MAIN_WPCONFIG);
$dbHost = wpconf_const('DB_HOST', $wc);
$dbName = wpconf_const('DB_NAME', $wc);
$dbUser = wpconf_const('DB_USER', $wc);
$dbPass = wpconf_const('DB_PASSWORD', $wc);
$pre    = preg_match('/\\$table_prefix\\s*=\\s*[\'"]([^\'"]+)/', $wc, $mm) ? $mm[1] : 'wp_';

$db = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (!$db) fail('Could not connect to the website database.');
mysqli_set_charset($db, 'utf8mb4');

// ---------------- pull antarctic-trips + meta ----------------
$posts = [];
$res = mysqli_query($db, "SELECT ID, post_status, post_name, post_title FROM {$pre}posts
                          WHERE post_type='antarctic-trips' AND post_status IN ('publish','private','draft')");
while ($r = mysqli_fetch_assoc($res)) {
    $posts[(int)$r['ID']] = [
        'id' => (int)$r['ID'], 'status' => $r['post_status'], 'slug' => $r['post_name'],
        'title' => $r['post_title'], 'ship' => '', 'supplier' => '', 'start' => '', 'return' => '', 'cabins' => [],
    ];
}
if ($posts) {
    $ids = implode(',', array_map('intval', array_keys($posts)));
    $res = mysqli_query($db, "SELECT post_id, meta_key, meta_value FROM {$pre}postmeta
        WHERE post_id IN ($ids) AND meta_key IN ('_ship_card','_supplier','start_date','return_date','ship-cabins')");
    while ($r = mysqli_fetch_assoc($res)) {
        $id = (int)$r['post_id']; if (!isset($posts[$id])) continue;
        $k = $r['meta_key']; $v = $r['meta_value'];
        if ($k === '_ship_card')  $posts[$id]['ship'] = trim((string)$v);
        elseif ($k === '_supplier') $posts[$id]['supplier'] = trim((string)$v);
        elseif ($k === 'start_date') $posts[$id]['start'] = trim((string)$v);
        elseif ($k === 'return_date') $posts[$id]['return'] = trim((string)$v);
        elseif ($k === 'ship-cabins') {
            $sc = @unserialize($v);
            if (is_array($sc)) foreach ($sc as $it) {
                if (!is_array($it)) continue;
                $nm = trim((string)($it['cabin-name'] ?? ''));
                if ($nm === '') continue;
                $posts[$id]['cabins'][] = [
                    'name' => $nm,
                    'orig' => trim((string)($it['original-price'] ?? '')),
                    'final' => trim((string)($it['final-price'] ?? '')),
                ];
            }
        }
    }
}
mysqli_close($db);
$wp = array_values($posts);

// ---------------- live dashboard feed ----------------
$feedBase = wp_self_base_();
$gate = $cfg['gate'] ?? null;
$feedRaw = wp_http_get_($feedBase . '/rate-data.php?nocache=1', $gate);
if ($feedRaw === null) fail('Could not read the dashboard feed on this server.', 502);
$feed = json_decode($feedRaw, true);
if (!is_array($feed)) fail('The dashboard feed did not return valid JSON.', 502);
$feedTrips = isset($feed['trips']) ? $feed['trips'] : (array_is_list($feed) ? $feed : []);

// ---------------- alias map ----------------
$aliasMap = [];
$aliasPath = __DIR__ . '/cabin-alias-map.php';
if (is_readable($aliasPath)) { $loaded = @include $aliasPath; if (is_array($loaded)) $aliasMap = $loaded; }

// ---------------- indexes ----------------
$feedBySlug = []; $feedByShipStart = [];
foreach ($feedTrips as $t) {
    $sl = slug_of_($t['lgpLink'] ?? '');
    if ($sl !== '' && !isset($feedBySlug[$sl])) $feedBySlug[$sl] = $t;
    $k = nship_($t['ship'] ?? '') . '|' . ($t['start'] ?? '');
    if (!isset($feedByShipStart[$k])) $feedByShipStart[$k] = $t;
}
$wpBySlug = []; $wpByShipStart = [];
foreach ($wp as $w) {
    if ($w['slug'] !== '' && !isset($wpBySlug[$w['slug']])) $wpBySlug[$w['slug']] = $w;
    $k = nship_($w['ship']) . '|' . $w['start'];
    if (!isset($wpByShipStart[$k])) $wpByShipStart[$k] = $w;
}
function find_feed_($w, $feedBySlug, $feedByShipStart) {
    if ($w['slug'] !== '' && isset($feedBySlug[$w['slug']])) return $feedBySlug[$w['slug']];
    return $feedByShipStart[nship_($w['ship']) . '|' . $w['start']] ?? null;
}

$today = gmdate('Y-m-d');
$priceRows = []; $availRows = []; $wpNoFeed = []; $orphans = [];
$pastPub = []; $badDates = []; $noCabins = []; $noPrices = []; $feedNoLink = 0;

// ---------------- CHECK 1: prices ----------------
foreach ($wp as $w) {
    if ($w['status'] !== 'publish' || $w['start'] < $today || $w['start'] === '') continue;
    $t = find_feed_($w, $feedBySlug, $feedByShipStart);
    if (!$t) continue;
    $op = $t['operator'] ?? $w['supplier'];
    $fc = [];
    foreach (($t['cabins'] ?? []) as $c) $fc[ncab_($c['name'] ?? '')] = $c;
    foreach ($w['cabins'] as $c) {
        $nn = ncab_($c['name']); if ($nn === '') continue;
        $akey = strtolower($op) . '|' . nship_($w['ship']) . '|' . $nn;
        $lookup = $aliasMap[$akey] ?? $nn;
        if (!isset($fc[$lookup])) continue;
        $fcab = $fc[$lookup];
        $origv = money_($c['orig']); $finv = money_($c['final']);
        $wpFull = $origv !== null ? $origv : $finv;
        $wpDisc = ($origv !== null && $finv !== null) ? $finv : null;
        $wpSold = ((stripos($c['orig'], 'sold') !== false || stripos($c['final'], 'sold') !== false) && $origv === null && $finv === null);
        $fFull = is_numeric($fcab['full'] ?? null) ? (float)$fcab['full'] : null;
        $fDisc = is_numeric($fcab['disc'] ?? null) ? (float)$fcab['disc'] : null;
        $fAvail = !empty($fcab['available']);
        $fHasPrice = ($fFull !== null || $fDisc !== null);
        $wpHasDisc = ($wpFull !== null && $wpDisc !== null && $wpDisc < $wpFull);
        $fHasDisc = ($fFull !== null && $fDisc !== null && $fDisc < $fFull);
        $row = ['op' => $op, 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'cabin' => $c['name'], 'lgp' => wp_lgp_($w['slug'])];
        if ($wpSold && $fAvail) { $availRows[] = $row + ['issue' => 'website SOLD OUT but dashboard available']; continue; }
        if (!$fHasPrice && $wpFull !== null && !$wpSold) { $availRows[] = $row + ['issue' => 'dashboard sold out or cabin not offered on this sailing, website shows a price']; continue; }
        $issues = [];
        if ($wpFull !== null && $fFull !== null && abs($wpFull - $fFull) > 1)
            $issues[] = 'full: WP $' . number_format($wpFull) . ' vs feed $' . number_format($fFull);
        $wpNow = $wpDisc !== null ? $wpDisc : $wpFull;
        if ($wpNow !== null && $fDisc !== null && abs($wpNow - $fDisc) > 1)
            $issues[] = 'net: WP $' . number_format($wpNow) . ' vs feed $' . number_format($fDisc);
        if ($fHasDisc && !$wpHasDisc)
            $issues[] = 'feed has discount ($' . number_format($fFull) . ' > $' . number_format($fDisc) . ') not on WP';
        if ($wpHasDisc && !$fHasDisc) $issues[] = 'WP shows discount feed does not';
        if ($issues) $priceRows[] = $row + ['issue' => implode('; ', $issues)];
    }
}

// ---------------- CHECK 2: missing / orphan ----------------
foreach ($wp as $w) {
    if ($w['status'] !== 'publish' || $w['start'] < $today || $w['start'] === '') continue;
    if (!find_feed_($w, $feedBySlug, $feedByShipStart))
        $wpNoFeed[] = ['supplier' => $w['supplier'], 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'lgp' => wp_lgp_($w['slug'])];
}
foreach ($feedTrips as $t) {
    if (($t['start'] ?? '') < $today) continue;
    $sl = slug_of_($t['lgpLink'] ?? '');
    if ($sl === '') $feedNoLink++;
    $w = ($sl !== '' && isset($wpBySlug[$sl])) ? $wpBySlug[$sl] : ($wpByShipStart[nship_($t['ship'] ?? '') . '|' . ($t['start'] ?? '')] ?? null);
    if ($w === null)
        $orphans[] = ['op' => $t['operator'] ?? '', 'itinerary' => $t['itinerary'] ?? '', 'ship' => $t['ship'] ?? '', 'start' => $t['start'] ?? '', 'note' => 'no website page found (by link or ship+date)'];
    elseif ($w['status'] !== 'publish')
        $orphans[] = ['op' => $t['operator'] ?? '', 'itinerary' => $t['itinerary'] ?? '', 'ship' => $t['ship'] ?? '', 'start' => $t['start'] ?? '', 'note' => 'website page is ' . strtoupper($w['status']) . ': ' . wp_lgp_($w['slug'])];
}

// ---------------- CHECK 3: stale / broken ----------------
foreach ($wp as $w) {
    $pub = $w['status'] === 'publish';
    if ($pub && $w['start'] !== '' && $w['start'] < $today)
        $pastPub[] = ['supplier' => $w['supplier'], 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'lgp' => wp_lgp_($w['slug'])];
    if (!$pub) continue;
    $upcoming = ($w['start'] === '' || $w['start'] >= $today);
    if ($upcoming) {
        if (count($w['cabins']) === 0)
            $noCabins[] = ['supplier' => $w['supplier'], 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'lgp' => wp_lgp_($w['slug'])];
        else {
            $priced = false;
            foreach ($w['cabins'] as $c) if (money_($c['orig']) !== null || money_($c['final']) !== null) { $priced = true; break; }
            if (!$priced) $noPrices[] = ['supplier' => $w['supplier'], 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'lgp' => wp_lgp_($w['slug'])];
        }
    }
    if ($w['start'] !== '' && $w['return'] !== '' && $w['return'] < $w['start'])
        $badDates[] = ['supplier' => $w['supplier'], 'trip' => $w['title'], 'ship' => $w['ship'], 'start' => $w['start'], 'return' => $w['return'], 'lgp' => wp_lgp_($w['slug'])];
}

usort($priceRows, fn($a, $b) => strcmp($a['op'] . $a['start'], $b['op'] . $b['start']));
usort($pastPub, fn($a, $b) => strcmp($a['start'], $b['start']));

$statusCounts = ['publish' => 0, 'private' => 0, 'draft' => 0];
foreach ($wp as $w) if (isset($statusCounts[$w['status']])) $statusCounts[$w['status']]++;

$report = [
    'ok' => true,
    'generated' => gmdate('c'),
    'summary' => [
        'wp_publish' => $statusCounts['publish'],
        'wp_publish_upcoming' => count(array_filter($wp, fn($w) => $w['status'] === 'publish' && $w['start'] >= $today && $w['start'] !== '')),
        'feed_trips' => count($feedTrips),
        'price_mismatches' => count($priceRows),
        'availability_flags' => count($availRows),
        'wp_not_in_dashboard' => count($wpNoFeed),
        'dashboard_orphans' => count($orphans),
        'past_still_published' => count($pastPub),
        'bad_dates' => count($badDates),
        'no_cabins' => count($noCabins),
        'no_prices' => count($noPrices),
        'feed_no_lgp_link' => $feedNoLink,
    ],
    'price_mismatches' => $priceRows,
    'availability_flags' => $availRows,
    'wp_not_in_dashboard' => $wpNoFeed,
    'dashboard_orphans' => $orphans,
    'past_still_published' => $pastPub,
    'bad_dates' => $badDates,
    'no_cabins' => $noCabins,
    'no_prices' => $noPrices,
];
if ($DEBUG) $report['debug'] = ['secs' => round(microtime(true) - $t0, 2), 'wp_count' => count($wp), 'db' => $dbName, 'gate' => $gate ? 'configured' : 'missing'];
out($report);

// ---------------- helpers ----------------
function nship_($s) {
    $s = strtolower((string)$s);
    $s = preg_replace('/\\b(m\\/v|m\\/s|mv|ms|ss|sh|the)\\b/', '', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function ncab_($s) {
    $s = strtolower((string)$s);
    $s = preg_replace('/\\s*—\\s*.*$/u', '', $s);
    $s = preg_replace('/\\((single|double|triple|quad|solo)\\)/', '', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function money_($s) {
    $s = trim((string)$s);
    if ($s === '' || stripos($s, 'sold') !== false) return null;
    $s = str_replace([' ', ','], '', $s);
    return preg_match('/([\\d]+(?:\\.\\d+)?)/', $s, $m) ? (float)$m[1] : null;
}
function slug_of_($url) {
    return preg_match('#/antarctic-trips/([^/]+)/?#', (string)$url, $m) ? $m[1] : '';
}
function wp_lgp_($slug) {
    $slug = trim((string)$slug);
    return $slug === '' ? '' : ('https://letsgopolar.com/antarctic-trips/' . $slug . '/');
}
function wp_self_base_() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}
function wp_http_get_($url, $gate = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if (is_array($gate) && !empty($gate['user'])) curl_setopt($ch, CURLOPT_USERPWD, $gate['user'] . ':' . ($gate['pass'] ?? ''));
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 400) ? $r : null;
}
