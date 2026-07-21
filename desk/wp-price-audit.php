<?php
/**
 * wp-price-audit.php
 * Receives the WordPress trips export (.csv/.txt, multipart field "file"),
 * fetches the live feed from rate-data.php on this same host, matches trips by
 * ship + departure date, and diffs cabin prices/discounts. Returns a JSON payload
 * the browser turns into a downloadable report.
 *
 * Buckets, so nothing is silently mismatched:
 *   - diffs:      cabins present on BOTH sides where full price, discounted price,
 *                 or discount presence differs.
 *   - wp_only:    cabins in WordPress but not found in the feed for that trip.
 *   - feed_only:  cabins in the feed but not in WordPress for that trip.
 *   - unmatched_trips: WP trips (upcoming) with no feed trip at all.
 * A per-operator cabin-name match rate lets the UI separate high-confidence
 * operators (Oceanwide, Quark) from ones whose names need mapping.
 *
 * Place this file in the SAME folder as rate-data.php (the desk folder).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
@set_time_limit(120);

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST with a multipart file field named "file".', 405);
if (!isset($_FILES['file'])) fail('No file received. The upload field must be named "file".');
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) fail('Upload failed (PHP upload error code ' . $f['error'] . ').');
if ($f['size'] > 60 * 1024 * 1024) fail('File too large (max 60 MB).');

$csvText = file_get_contents($f['tmp_name']);
if ($csvText === false || $csvText === '') fail('Could not read the uploaded file, or it was empty.');
$csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);

// --- Parse the WordPress CSV (quoted, multiline fields, huge Elementor cells) ---
$fh = fopen('php://temp', 'r+');
fwrite($fh, $csvText);
rewind($fh);
$header = fgetcsv($fh);
if (!$header) fail('Could not parse the CSV header.');
$H = array_flip(array_map('trim', $header));
function wcol($H, $row, $name) {
    return (isset($H[$name]) && isset($row[$H[$name]])) ? $row[$H[$name]] : '';
}
$need = ['trip_title','_supplier','_ship_card','start_date','post_status','post_name','cabin-name','original-price','final-price'];
foreach (['start_date','cabin-name','_ship_card'] as $req) {
    if (!isset($H[$req])) { fclose($fh); fail('The file does not look like the WordPress export (missing "' . $req . '" column).'); }
}
$wpTrips = [];
while (($row = fgetcsv($fh)) !== false) {
    $start = trim(wcol($H, $row, 'start_date'));
    if ($start === '') continue;
    $wpTrips[] = [
        'title'   => trim(wcol($H, $row, 'trip_title')),
        'supplier'=> trim(wcol($H, $row, '_supplier')),
        'ship'    => trim(wcol($H, $row, '_ship_card')),
        'start'   => $start,
        'status'  => trim(wcol($H, $row, 'post_status')),
        'slug'    => trim(wcol($H, $row, 'post_name')),
        'cabins'  => wp_cabins_(wcol($H,$row,'cabin-name'), wcol($H,$row,'original-price'), wcol($H,$row,'final-price')),
    ];
}
fclose($fh);

// --- Fetch the live feed from rate-data.php on this same host ---
$feedUrl = wp_self_base_() . '/rate-data.php?nocache=1';
$feedRaw = wp_http_get_($feedUrl);
if ($feedRaw === null) fail('Could not fetch the live feed from rate-data.php on this server.', 502);
$feed = json_decode($feedRaw, true);
if (!is_array($feed)) fail('The live feed did not return valid JSON.', 502);
$feedTrips = isset($feed['trips']) ? $feed['trips'] : (array_is_list($feed) ? $feed : []);

// Load the cabin alias map (WordPress cabin name -> dashboard cabin name) so mapped
// operators (Poseidon, Antarpply, G Adventures, Polar Latitudes) produce real per-cabin
// comparisons instead of landing in the naming buckets. Keyed operatorLower|normShip|normWpCabin.
$aliasMap = [];
$aliasPath = __DIR__ . '/cabin-alias-map.php';
if (is_readable($aliasPath)) {
    $loaded = @include $aliasPath;
    if (is_array($loaded)) $aliasMap = $loaded;
}

// Index feed by ship+start.
$fidx = [];
foreach ($feedTrips as $t) {
    $k = wp_nship_($t['ship'] ?? '') . '|' . ($t['start'] ?? '');
    if (!isset($fidx[$k])) $fidx[$k] = $t;
}

$today = gmdate('Y-m-d');
$diffs = []; $wpOnly = []; $feedOnly = []; $unmatched = [];
$opStat = []; // operator => [cabated, matched]

foreach ($wpTrips as $w) {
    $k = wp_nship_($w['ship']) . '|' . $w['start'];
    $t = $fidx[$k] ?? null;
    if ($t === null) {
        // Only report unmatched trips that are upcoming and published (actionable).
        if ($w['start'] > $today && $w['status'] === 'publish') {
            $unmatched[] = ['title'=>$w['title'],'supplier'=>$w['supplier'],'ship'=>$w['ship'],'start'=>$w['start'],'lgp'=>wp_lgp_($w['slug'])];
        }
        continue;
    }
    $op = $t['operator'] ?? $w['supplier'];
    // Index feed cabins by normalized name.
    $fc = [];
    foreach (($t['cabins'] ?? []) as $c) {
        $fc[wp_ncab_($c['name'] ?? '')] = $c;
    }
    $seen = [];
    foreach ($w['cabins'] as $wc) {
        $nn = wp_ncab_($wc['name']);
        if ($nn === '') continue;
        // Translate the WordPress cabin name to its dashboard equivalent when a mapping
        // exists for this operator + ship. Falls back to the plain normalized name.
        $aliasKey = strtolower($op) . '|' . wp_nship_($w['ship']) . '|' . $nn;
        $lookup = isset($aliasMap[$aliasKey]) ? $aliasMap[$aliasKey] : $nn;
        if (!isset($opStat[$op])) $opStat[$op] = [0,0];
        $opStat[$op][0]++;
        if (!isset($fc[$lookup])) {
            $wpOnly[] = ['op'=>$op,'trip'=>$w['title'],'ship'=>$w['ship'],'start'=>$w['start'],'cabin'=>$wc['name'],'lgp'=>wp_lgp_($w['slug'])];
            continue;
        }
        $opStat[$op][1]++;
        $seen[$lookup] = true;
        $c = $fc[$lookup];
        // Feed prices are numbers; WP prices parsed from "U$ 9,350"/"SOLD OUT".
        $wpFull = $wc['full']; $wpDisc = $wc['disc']; $wpSold = $wc['soldout'];
        $fFull = is_numeric($c['full'] ?? null) ? (float)$c['full'] : null;
        $fDisc = is_numeric($c['disc'] ?? null) ? (float)$c['disc'] : null;
        $fAvail = !empty($c['available']);
        // Discount presence on each side (disc strictly below full).
        $wpHasDisc = ($wpFull !== null && $wpDisc !== null && $wpDisc < $wpFull);
        $fHasDisc  = ($fFull !== null && $fDisc !== null && $fDisc < $fFull);
        $issues = [];
        // Sold-out disagreement.
        if ($wpSold && $fAvail) $issues[] = 'WP shows SOLD OUT but feed shows available';
        if (!$wpSold && !$fAvail && $wpFull !== null) $issues[] = 'feed shows SOLD OUT but WP shows a price';
        // Price differences (only when both sides have the number).
        if ($wpFull !== null && $fFull !== null && abs($wpFull - $fFull) > 1)
            $issues[] = 'full: WP $' . number_format($wpFull) . ' vs feed $' . number_format($fFull);
        // "now" price: WP effective (disc or full) vs feed disc.
        $wpNow = ($wpDisc !== null ? $wpDisc : $wpFull);
        if ($wpNow !== null && $fDisc !== null && abs($wpNow - $fDisc) > 1)
            $issues[] = 'net: WP $' . number_format($wpNow) . ' vs feed $' . number_format($fDisc);
        // Discount-presence mismatch (the Twin Window / Superior case).
        if ($fHasDisc && !$wpHasDisc)
            $issues[] = 'feed has a discount (was $' . number_format($fFull) . ' > now $' . number_format($fDisc) . ') not shown in WP';
        if ($wpHasDisc && !$fHasDisc)
            $issues[] = 'WP shows a discount the feed does not';
        if ($issues) {
            $diffs[] = ['op'=>$op,'trip'=>$w['title'],'ship'=>$w['ship'],'start'=>$w['start'],
                        'cabin'=>$wc['name'],'issue'=>implode('; ', $issues),'lgp'=>wp_lgp_($w['slug'])];
        }
    }
    foreach ($fc as $nn => $c) {
        if (!isset($seen[$nn])) {
            $feedOnly[] = ['op'=>$op,'trip'=>$w['title'],'ship'=>$w['ship'],'start'=>$w['start'],'cabin'=>$c['name'] ?? $nn,'lgp'=>wp_lgp_($w['slug'])];
        }
    }
}

// Per-operator confidence.
$opConfidence = [];
foreach ($opStat as $op => $st) {
    $rate = $st[0] ? round(100 * $st[1] / $st[0]) : 0;
    $opConfidence[$op] = ['cabins'=>$st[0],'matched'=>$st[1],'rate'=>$rate];
}

echo json_encode([
    'ok' => true,
    'generated' => gmdate('c'),
    'summary' => [
        'wp_trips' => count($wpTrips),
        'feed_trips' => count($feedTrips),
        'diffs' => count($diffs),
        'wp_only' => count($wpOnly),
        'feed_only' => count($feedOnly),
        'unmatched_trips' => count($unmatched),
    ],
    'op_confidence' => $opConfidence,
    'diffs' => $diffs,
    'wp_only' => $wpOnly,
    'feed_only' => $feedOnly,
    'unmatched_trips' => $unmatched,
]);

// ---------------- helpers ----------------
function wp_cabins_($names, $origs, $finals) {
    $n = explode('|', (string)$names);
    $o = explode('|', (string)$origs);
    $fp = explode('|', (string)$finals);
    $out = [];
    foreach ($n as $i => $nm) {
        $nm = trim($nm);
        if ($nm === '') continue;
        $origRaw = isset($o[$i]) ? trim($o[$i]) : '';
        $finRaw  = isset($fp[$i]) ? trim($fp[$i]) : '';
        $origVal = wp_money_($origRaw);
        $finVal  = wp_money_($finRaw);
        $sold = (stripos($origRaw, 'sold') !== false || stripos($finRaw, 'sold') !== false)
                && $origVal === null && $finVal === null;
        // WordPress often stores only one price (in final). Treat that as "full".
        $full = ($origVal !== null) ? $origVal : $finVal;
        $disc = ($origVal !== null && $finVal !== null) ? $finVal : null; // disc only meaningful when both present
        $out[] = ['name'=>$nm,'full'=>$full,'disc'=>$disc,'soldout'=>$sold];
    }
    return $out;
}
function wp_money_($s) {
    $s = trim((string)$s);
    if ($s === '' || stripos($s, 'sold') !== false) return null;
    $s = str_replace([' ', ','], '', $s);
    if (preg_match('/([\d]+(?:\.\d+)?)/', $s, $m)) return (float)$m[1];
    return null;
}
function wp_nship_($s) {
    $s = strtolower((string)$s);
    $s = preg_replace('/\b(m\/v|m\/s|mv|ms|ss|sh|the)\b/', '', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function wp_ncab_($s) {
    $s = strtolower((string)$s);
    $s = preg_replace('/\s*—\s*.*$/u', '', $s);        // drop " — Tariff" suffix (Swan)
    $s = preg_replace('/\((single|double|triple|quad|solo)\)/', '', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
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
function wp_http_get_($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $code >= 200 && $code < 400) return $r;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 90], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}
