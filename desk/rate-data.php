<?php
/**
 * Let's Go Polar - Dates and Rates data proxy
 * Place this file in the desk subdomain web root:
 *   /home/uXXXXXXXX/domains/letsgopolar.com/public_html/desk/rate-data.php
 *
 * WHY THIS EXISTS
 * The dashboard runs on desk.letsgopolar.com. The data lives in a Google Apps
 * Script web app (script.google.com) and the trip pages live on
 * www.letsgopolar.com. Both are different origins, so the browser blocks direct
 * calls. This small server-side proxy fetches them for the browser, which keeps
 * everything same-origin and also hides the Apps Script URL from public view.
 *
 * TWO MODES
 *   1) Default (no query)         -> returns the Sheet JSON from Apps Script.
 *   2) ?trip=<full LGP trip URL>  -> returns the HTML of that trip page on
 *                                    www.letsgopolar.com (used later by the live
 *                                    brochure). Only letsgopolar.com URLs are
 *                                    allowed, so this cannot be used as an open proxy.
 *
 * SETUP
 *   - Paste your deployed Apps Script /exec URL into APPS_SCRIPT_URL below.
 *   - Leave CACHE_SECONDS as-is for a short cache, or set to 0 to always read live.
 */

// ------------------------------------------------------------------
// CONFIG - edit these two lines only
// ------------------------------------------------------------------
const APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbybHnKNh44TkrkROsi3tx6NJUxAbT2-aL018GknFjFbUbZqdMiQW2yAZdgv2YlNoMs/exec';
const CACHE_SECONDS   = 300; // 5 minutes. Set to 0 to disable caching.
const OCEANWIDE_FEED  = 'https://oceanwide-expeditions.com/trip-feed/agents?currency=USD';
const OCEANWIDE_LINKS_CSV = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQ5rFt51GX4KqI8uMaGq_cSsLCgW64Mp-UDmHgjN6lq0fYtplGash2RyDuFCO-H2HKEh0dQ3zrxN03I/pub?output=csv';
const OCEANWIDE_LINKS_LOCAL = 'lgp-links.csv'; // if present next to this script, used instead of the Google URL
const QUARK_DETAIL_LOCAL = 'quark-detail.json';  // Quark departure-detail JSON (uploaded via the API page)
const QUARK_LINKS_LOCAL  = 'quark-lgp-links.csv'; // Quark LGP Links CSV (uploaded via the API page)
const AURORA_SERVICES_LOCAL = 'aurora-services.json'; // Aurora services JSON (uploaded via the API page)
const AURORA_LINKS_LOCAL    = 'aurora-lgp-links.csv'; // Aurora LGP Links CSV (uploaded via the API page)
const SWAN_TRIPS_LOCAL = 'swan-trips.json';   // Swan trips JSON (slimmed, uploaded via the API page)
const SWAN_LINKS_LOCAL = 'swan-lgp-links.csv'; // Swan LGP Links CSV (uploaded via the API page)
const QUARK_CMD_LOCAL  = 'quark-cmd.json';    // Quark Closed Market pricing (parsed from uploaded xlsx)
const INCLUDE_ONLY_IN_COMBINATION = false; // exclude legs that are only sold inside a combo
// ------------------------------------------------------------------

// Same-origin dashboard calls this, so we do not need a wildcard CORS header.
// If you ever serve the dashboard from a different host, add it here explicitly.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- Build check: rate-data.php?ping=1 confirms which version is deployed ----
if (isset($_GET['ping'])) {
    echo json_encode([
        'build'            => 'oceanwide-merge-v1',
        'oceanwide_feed'   => OCEANWIDE_FEED,
        'links_csv_set'    => (OCEANWIDE_LINKS_CSV !== ''),
        'curl_available'   => function_exists('curl_init'),
        'allow_url_fopen'  => (bool) ini_get('allow_url_fopen'),
    ]);
    exit;
}

// ---- Mode 4: trip media (gallery photos + description) for the brochure ----
if (isset($_GET['trip_media']) && $_GET['trip_media'] !== '') {
    $tripUrl = $_GET['trip_media'];
    $host = parse_url($tripUrl, PHP_URL_HOST);
    if ($host === null || substr($host, -strlen('letsgopolar.com')) !== 'letsgopolar.com') {
        http_response_code(400);
        echo json_encode(['error' => 'Only letsgopolar.com URLs are allowed.']);
        exit;
    }
    $pageHtml = http_get($tripUrl);
    if ($pageHtml === false) {
        echo json_encode(['gallery' => [], 'description' => '']);
        exit;
    }
    echo json_encode(extract_trip_media($pageHtml));
    exit;
}

// ---- Mode 3: inclusions/exclusions extraction (for the brochure) ----
if (isset($_GET['incl']) && $_GET['incl'] !== '') {
    $tripUrl = $_GET['incl'];
    $host = parse_url($tripUrl, PHP_URL_HOST);
    if ($host === null || substr($host, -strlen('letsgopolar.com')) !== 'letsgopolar.com') {
        http_response_code(400);
        echo json_encode(['error' => 'Only letsgopolar.com URLs are allowed.']);
        exit;
    }
    $pageHtml = http_get($tripUrl);
    if ($pageHtml === false) {
        echo json_encode(['inclusions' => [], 'exclusions' => []]);
        exit;
    }
    echo json_encode(extract_incl_excl($pageHtml));
    exit;
}

// ---- Mode 2: trip page fetch (for the live brochure, added later) ----
if (isset($_GET['trip']) && $_GET['trip'] !== '') {
    $trip = $_GET['trip'];

    // Only allow pages on letsgopolar.com. This prevents the proxy being abused
    // to fetch arbitrary sites.
    $host = parse_url($trip, PHP_URL_HOST);
    if ($host === null || substr($host, -strlen('letsgopolar.com')) !== 'letsgopolar.com') {
        http_response_code(400);
        echo json_encode(['error' => 'Only letsgopolar.com URLs are allowed.']);
        exit;
    }

    $html = http_get($trip);
    if ($html === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not fetch trip page.']);
        exit;
    }
    // Return the raw HTML (the brochure builder parses it client-side).
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ---- Mode 1: sheet data (default) ----

// Optional short cache so we do not hit Apps Script on every page load.
$cacheFile = sys_get_temp_dir() . '/lgp_rate_data_oceanwide-merge-v1.json';
$noCache   = isset($_GET['nocache']);
if (!$noCache && CACHE_SECONDS > 0 && is_readable($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age < CACHE_SECONDS) {
        readfile($cacheFile);
        exit;
    }
}

$data = http_get(APPS_SCRIPT_URL);
if ($data === false) {
    // If the live call fails but we have a stale cache, serve that rather than nothing.
    if (is_readable($cacheFile)) {
        readfile($cacheFile);
        exit;
    }
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach the data source.', 'trips' => []]);
    exit;
}

// Merge in Oceanwide (fetched live + mapped to our trip shape). If Oceanwide is
// unreachable, we still return the sheet data rather than failing.
$merged = merge_oceanwide($data);

// Merge in Quark (from uploaded detail JSON + uploaded Quark LGP Links CSV).
// If the Quark files aren't present, this is a no-op and returns $merged unchanged.
$merged = merge_quark($merged);

// Merge in Aurora (from uploaded services JSON + uploaded Aurora LGP Links CSV).
// No-op if the Aurora files aren't present.
$merged = merge_aurora($merged);

// Merge in Swan Hellenic (from uploaded slim trips JSON + uploaded Swan LGP Links CSV).
// Emits standard trips into the feed, and Flash/Solo deal groupings for the
// Closed Market Deals page. No-op if the Swan files aren't present.
$merged = merge_swan($merged);

// Merge in Quark Closed Market deals (parsed from the uploaded xlsx). Matches each
// departure to the Quark LGP Links by ship + fuzzy itinerary + date +/-1 day.
$merged = merge_quark_cmd($merged);

// Merge in Swan Hellenic Antarctica 2026-27 Closed Market deals (static rates
// supplied by email, enriched from the Swan LGP Links sheet). Self-contained.
$merged = merge_swan_antarctica_cmd($merged);

// Save cache (best effort) and return.
if (CACHE_SECONDS > 0) {
    @file_put_contents($cacheFile, $merged);
}
echo $merged;
exit;

// ------------------------------------------------------------------
// Oceanwide Expeditions live feed -> merged into the sheet payload.
// The sheet payload is {"updated": "...", "trips": [...]}. We append mapped
// Oceanwide trips to that trips array. LGP links are attached by matching the
// Oceanwide trip \"code\" against a code->lgpLink map the sheet may provide
// (sheet key \"oceanwideLinks\": { \"HDS08-26\": \"https://letsgopolar.com/...\" }).
// Trips with no matching code get an empty lgpLink (reported separately for you).
// ------------------------------------------------------------------
function merge_oceanwide($sheetJson) {
    $payload = json_decode($sheetJson, true);
    if (!is_array($payload)) $payload = ['updated' => '', 'trips' => []];
    if (!isset($payload['trips']) || !is_array($payload['trips'])) $payload['trips'] = [];

    // Build the code->lgpLink map by fetching the published Oceanwide links CSV.
    // Columns: operator_links, operator_code, lgp_links, itinerary_name, ship, ...
    $linkMap = ow_fetch_link_map();

    $diag = ['feed_fetched' => false, 'feed_trip_count' => 0, 'link_map_count' => count($linkMap), 'error' => null];
    $raw = http_get(OCEANWIDE_FEED);
    if ($raw === false) {
        $diag['error'] = 'feed_fetch_failed';
        $payload['oceanwideDiag'] = $diag;
        $payload['oceanwideMissing'] = [];
        return json_encode($payload);
    }
    $diag['feed_fetched'] = true;
    $feed = json_decode($raw, true);
    if (!is_array($feed) || !isset($feed['trips']) || !is_array($feed['trips'])) {
        $diag['error'] = 'feed_parse_failed';
        $payload['oceanwideDiag'] = $diag;
        $payload['oceanwideMissing'] = [];
        return json_encode($payload);
    }
    $diag['feed_trip_count'] = count($feed['trips']);

    $missing = [];
    $idx = 0;
    foreach ($feed['trips'] as $t) {
        if (!INCLUDE_ONLY_IN_COMBINATION && !empty($t['only_in_combination'])) continue;
        $mapped = map_oceanwide_trip($t, $idx++, $linkMap, $missing);
        $payload['trips'][] = $mapped;
    }
    // Expose the unmatched list + diagnostics so the API page can surface them.
    $diag['mapped_count'] = $idx;
    $diag['missing_count'] = count($missing);
    if (isset($GLOBALS['OW_CSV_DIAG'])) { $diag['csv'] = $GLOBALS['OW_CSV_DIAG']; }
    $payload['oceanwideMissing'] = $missing;
    $payload['oceanwideDiag'] = $diag;
    return json_encode($payload);
}

// ------------------------------------------------------------------
// Quark Expeditions: departure-detail JSON (uploaded) + Quark LGP Links CSV
// (uploaded) -> mapped trips appended to the feed. Inner join on
// departure_id (detail) == operator_id (links). Detail departures with no
// matching links row are reported in quarkMissing.
// ------------------------------------------------------------------
function merge_quark($mergedJson) {
    $payload = json_decode($mergedJson, true);
    if (!is_array($payload)) return $mergedJson;

    $detailPath = __DIR__ . '/' . QUARK_DETAIL_LOCAL;
    $linksPath  = __DIR__ . '/' . QUARK_LINKS_LOCAL;
    $diag = ['detail_present' => false, 'links_present' => false, 'detail_count' => 0,
             'links_count' => 0, 'mapped_count' => 0, 'missing_count' => 0, 'error' => null];

    if (!is_readable($detailPath)) { $payload['quarkDiag'] = $diag; return json_encode($payload); }
    $diag['detail_present'] = true;
    $dmt = @filemtime($detailPath);
    $diag['published'] = $dmt ? gmdate('c', $dmt) : null;

    $raw = file_get_contents($detailPath);
    $detail = json_decode($raw, true);
    if (!is_array($detail)) { $diag['error'] = 'detail_parse_failed'; $payload['quarkDiag'] = $diag; return json_encode($payload); }
    // Accept list or object-of-departures.
    if (!array_is_list_compat($detail)) $detail = array_values($detail);
    $diag['detail_count'] = count($detail);

    // Build links map keyed by operator_id.
    $linkMap = [];
    if (is_readable($linksPath)) {
        $diag['links_present'] = true;
        $csv = file_get_contents($linksPath);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(array("\r\n", "\r"), "\n", $csv);
        $lines = explode("\n", $csv);
        if ($lines) {
            $header = str_getcsv(array_shift($lines));
            $header = array_map(function($h){ return trim($h); }, $header);
            $ci = array_flip($header);
            foreach ($lines as $line) {
                if ($line === '') continue;
                $cols = str_getcsv($line);
                $oid = isset($ci['operator_id']) && isset($cols[$ci['operator_id']]) ? trim($cols[$ci['operator_id']]) : '';
                if ($oid === '') continue;
                $linkMap[$oid] = [
                    'destinations' => isset($ci['destinations']) ? ($cols[$ci['destinations']] ?? '') : '',
                    'season'       => isset($ci['season'])       ? ($cols[$ci['season']] ?? '')       : '',
                    'region'       => isset($ci['region'])       ? ($cols[$ci['region']] ?? '')       : '',
                    'lgp_link'     => isset($ci['lgp_links'])    ? ($cols[$ci['lgp_links']] ?? '')    : '',
                    'itinerary'    => isset($ci['itinerary_name']) ? ($cols[$ci['itinerary_name']] ?? '') : '',
                ];
            }
        }
        $diag['links_count'] = count($linkMap);
    }

    $missing = [];
    $idx = 0;
    foreach ($detail as $e) {
        $did = isset($e['departure_id']) ? trim((string)$e['departure_id']) : '';
        if ($did === '') continue;
        if (!isset($linkMap[$did])) {
            // Detail departure not present in the Quark links sheet -> report it.
            $missing[] = [
                'departure_id' => $did,
                'ship'         => $e['ship_name'] ?? '',
                'start_date'   => $e['start_date'] ?? '',
                'end_date'     => $e['end_date'] ?? '',
                'api_id'       => isset($e['id']) ? (string)$e['id'] : '',
            ];
            continue;
        }
        $lk = $linkMap[$did];
        $payload['trips'][] = quark_map_trip($e, $lk, 800000 + $idx);
        $idx++;
    }

    $diag['mapped_count']  = $idx;
    $diag['missing_count'] = count($missing);
    $payload['quarkMissing'] = $missing;
    $payload['quarkDiag']    = $diag;
    return json_encode($payload);
}

// PHP < 8.1 compatibility for array_is_list.
function array_is_list_compat($arr) {
    if (function_exists('array_is_list')) return array_is_list($arr);
    if ($arr === []) return true;
    return array_keys($arr) === range(0, count($arr) - 1);
}

function quark_process_cabins($e) {
    $out = [];
    $cabins = isset($e['cabins']) && is_array($e['cabins']) ? $e['cabins'] : [];
    foreach ($cabins as $cid => $cd) {
        $name = $cd['cabin_name'] ?? '';
        $isSolo = (strpos($name, 'Solo') !== false) || (strpos($name, 'Single') !== false);
        $occs = isset($cd['occupancies']) && is_array($cd['occupancies']) ? $cd['occupancies'] : [];
        foreach ($occs as $o) {
            $desc = $o['occupancy_description'] ?? '';
            if (strpos($desc, 'Shared') !== false) continue;                 // skip shared
            if (!$isSolo && strpos($desc, 'Single Room') !== false) continue; // skip single-room on non-solo cabins
            $usd = isset($o['prices']['USD']) && is_array($o['prices']['USD']) ? $o['prices']['USD'] : [];
            $base = $usd['price_per_person'] ?? null;
            $tr   = $usd['mandatory_transfer_price_per_person'] ?? 0;
            $full = ($base !== null) ? ($base + ($tr ?: 0)) : null;
            $disc = $usd['deposit_only_prices']['total_price_per_person'] ?? null;
            $spaces = $o['spaces_available'] ?? 0;
            $out[] = [
                'name'      => $name,
                'full'      => $full,
                'disc'      => ($disc !== null ? $disc : $full),
                'available' => ((int)$spaces) > 0,
            ];
        }
    }
    return $out;
}

function quark_map_trip($e, $lk, $id) {
    $cabins = quark_process_cabins($e);
    // fromPrice/fromFull = min over available cabins (disc preferred).
    $fromPrice = null; $fromFull = null; $availCount = 0;
    foreach ($cabins as $c) {
        if ($c['available'] && $c['disc'] !== null) {
            $availCount++;
            if ($fromPrice === null || $c['disc'] < $fromPrice) { $fromPrice = $c['disc']; $fromFull = $c['full']; }
        }
    }
    $start = $e['start_date'] ?? '';   // "2026-Aug-06" style from Quark
    $end   = $e['end_date'] ?? '';
    $days  = $e['duration_days'] ?? '';
    $rawRegion = $lk['region'] ?: ($lk['destinations'] ?? '');
    $region = (stripos($rawRegion, 'antarc') !== false) ? 'Antarctic' : 'Arctic';
    return [
        'id'           => $id,
        'operator'     => 'Quark Expeditions',
        'ship'         => $e['ship_name'] ?? '',
        'start'        => quark_iso_($start),
        'end'          => quark_iso_($end),
        'startRaw'     => $start,
        'endRaw'       => $end,
        'itinerary'    => $lk['itinerary'] ?: ($e['departure_name'] ?? ''),
        'days'         => $days,
        'nights'       => null,
        'duration'     => $days !== '' ? ($days . ' days') : '',
        'activities'   => quark_activities_($e),
        'operatorLink' => $e['url'] ?? '',
        'lgpLink'      => $lk['lgp_link'] ?? '',
        'destinations' => $lk['destinations'] ?? '',
        'region'       => $region,
        'season'       => $lk['season'] ?? '',
        'startLoc'     => $e['start_location'] ?? '',
        'endLoc'       => $e['end_location'] ?? '',
        'cabins'       => $cabins,
        'fromPrice'    => $fromPrice,
        'fromFull'     => $fromFull,
        'availCount'   => $availCount,
        'cabinCount'   => count($cabins),
    ];
}

// Convert "2026-Aug-06" to "2026-08-06". Falls back to the raw string.
function quark_iso_($s) {
    if (!$s) return '';
    $months = ['Jan'=>'01','Feb'=>'02','Mar'=>'03','Apr'=>'04','May'=>'05','Jun'=>'06',
               'Jul'=>'07','Aug'=>'08','Sep'=>'09','Oct'=>'10','Nov'=>'11','Dec'=>'12'];
    $parts = explode('-', $s);
    if (count($parts) === 3 && isset($months[$parts[1]])) {
        return $parts[0] . '-' . $months[$parts[1]] . '-' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
    }
    return $s;
}

// Quark included/paid options -> the dashboard activities[] shape {text, price}.
function quark_activities_($e) {
    $acts = [];
    $opts = isset($e['options']) && is_array($e['options']) ? $e['options'] : [];
    foreach (($opts['included_options'] ?? []) as $o) {
        $n = $o['option_name'] ?? '';
        if ($n !== '') $acts[] = ['text' => $n . ' (included)', 'price' => 0];
    }
    foreach (($opts['paid_options'] ?? []) as $o) {
        $n = $o['option_name'] ?? '';
        $p = $o['prices']['USD']['price_per_person'] ?? null;
        if ($n !== '') $acts[] = ['text' => $n . ($p !== null ? ' - $' . number_format($p) : ''), 'price' => ($p !== null ? $p : null)];
    }
    return $acts;
}

// ------------------------------------------------------------------
// Aurora Expeditions: services JSON (uploaded) + Aurora LGP Links CSV
// (uploaded) -> mapped trips appended to the feed. Inner join on
// voyageCode (services) == operator_id (links). Services flagged hidden
// are skipped. Services with no matching links row are reported in
// auroraMissing. Itinerary/destinations/season/region come from the CSV.
// ------------------------------------------------------------------
function merge_aurora($mergedJson) {
    $payload = json_decode($mergedJson, true);
    if (!is_array($payload)) return $mergedJson;

    $svcPath   = __DIR__ . '/' . AURORA_SERVICES_LOCAL;
    $linksPath = __DIR__ . '/' . AURORA_LINKS_LOCAL;
    $diag = ['services_present' => false, 'links_present' => false, 'services_count' => 0,
             'visible_count' => 0, 'links_count' => 0, 'mapped_count' => 0, 'missing_count' => 0, 'error' => null];

    if (!is_readable($svcPath)) { $payload['auroraDiag'] = $diag; return json_encode($payload); }
    $diag['services_present'] = true;
    $smt = @filemtime($svcPath);
    $diag['published'] = $smt ? gmdate('c', $smt) : null;

    $raw = file_get_contents($svcPath);
    $services = json_decode($raw, true);
    if (!is_array($services)) { $diag['error'] = 'services_parse_failed'; $payload['auroraDiag'] = $diag; return json_encode($payload); }
    if (!array_is_list_compat($services)) $services = array_values($services);
    $diag['services_count'] = count($services);

    // Build links map keyed by operator_id.
    $linkMap = [];
    if (is_readable($linksPath)) {
        $diag['links_present'] = true;
        $csv = file_get_contents($linksPath);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(array("\r\n", "\r"), "\n", $csv);
        $lines = explode("\n", $csv);
        if ($lines) {
            $header = array_map(function($h){ return trim($h); }, str_getcsv(array_shift($lines)));
            $ci = array_flip($header);
            foreach ($lines as $line) {
                if ($line === '') continue;
                $cols = str_getcsv($line);
                $oid = isset($ci['operator_id']) && isset($cols[$ci['operator_id']]) ? trim($cols[$ci['operator_id']]) : '';
                if ($oid === '') continue;
                $linkMap[$oid] = [
                    'destinations' => isset($ci['destinations']) ? ($cols[$ci['destinations']] ?? '') : '',
                    'season'       => isset($ci['season'])       ? ($cols[$ci['season']] ?? '')       : '',
                    'region'       => isset($ci['region'])       ? ($cols[$ci['region']] ?? '')       : '',
                    'lgp_link'     => isset($ci['lgp_links'])    ? ($cols[$ci['lgp_links']] ?? '')    : '',
                    'end_date'     => isset($ci['end_date'])     ? ($cols[$ci['end_date']] ?? '')     : '',
                    'start_loc'    => isset($ci['start_location']) ? ($cols[$ci['start_location']] ?? '') : '',
                    'end_loc'      => isset($ci['end_location'])   ? ($cols[$ci['end_location']] ?? '')   : '',
                ];
            }
        }
        $diag['links_count'] = count($linkMap);
    }

    $missing = [];
    $idx = 0;
    foreach ($services as $e) {
        $dat = isset($e['data']) && is_array($e['data']) ? $e['data'] : null;
        if ($dat === null) continue;
        if (!empty($dat['hidden'])) continue;            // skip hidden services (matches notebook)
        $diag['visible_count']++;
        $vc = isset($e['voyageCode']) ? trim((string)$e['voyageCode']) : '';
        if ($vc === '') continue;
        if (!isset($linkMap[$vc])) {
            $missing[] = [
                'voyage_code' => $vc,
                'name'        => $dat['ExternalName'] ?? ($dat['Name'] ?? ''),
                'ship'        => $dat['Ship'] ?? '',
                'start_date'  => $dat['StartDate'] ?? '',
            ];
            continue;
        }
        $payload['trips'][] = aurora_map_trip($e, $dat, $linkMap[$vc], 700000 + $idx);
        $idx++;
    }

    $diag['mapped_count']  = $idx;
    $diag['missing_count'] = count($missing);
    $payload['auroraMissing'] = $missing;
    $payload['auroraDiag']    = $diag;
    return json_encode($payload);
}

// Build the dashboard cabins[] from Aurora ServicePricing + CabinInformation.
// Join on price-category NAME (matches the notebook, which is more complete than
// joining on id). full = GrossPrice; disc = GrossPrice when PromotionValue is 0,
// else NetPrice; available = UnitAvailable > 0.
function aurora_cabins_($dat) {
    $pricing = isset($dat['ServicePricing']) && is_array($dat['ServicePricing']) ? $dat['ServicePricing'] : [];
    $availByName = [];
    foreach ((isset($dat['CabinInformation']) && is_array($dat['CabinInformation']) ? $dat['CabinInformation'] : []) as $c) {
        $n = $c['CabinCategory'] ?? '';
        if ($n !== '') $availByName[$n] = $c;
    }
    $out = [];
    foreach ($pricing as $p) {
        $name  = $p['PriceCategoryName'] ?? '';
        $gross = $p['GrossPrice'] ?? null;
        $net   = $p['NetPrice'] ?? null;
        $promo = $p['PromotionValue'] ?? 0;
        $full  = $gross;
        $disc  = (empty($promo)) ? $gross : $net;   // no promo -> disc == full
        $units = isset($availByName[$name]) ? ($availByName[$name]['UnitAvailable'] ?? null) : null;
        $out[] = [
            'name'      => $name,
            'full'      => $full,
            'disc'      => ($disc !== null ? $disc : $full),
            'available' => ((int)($units ?? 0)) > 0,
        ];
    }
    return $out;
}

function aurora_map_trip($e, $dat, $lk, $id) {
    $cabins = aurora_cabins_($dat);
    $fromPrice = null; $fromFull = null; $availCount = 0;
    foreach ($cabins as $c) {
        if ($c['available'] && $c['disc'] !== null) {
            $availCount++;
            if ($fromPrice === null || $c['disc'] < $fromPrice) { $fromPrice = $c['disc']; $fromFull = $c['full']; }
        }
    }
    $start = $dat['StartDate'] ?? '';                 // already ISO yyyy-mm-dd
    $days  = $dat['VoyageDay'] ?? ($dat['PackageNights'] ?? '');
    $nights= $dat['PackageNights'] ?? null;
    // Prefer the CSV end_date (human-entered, e.g. "2026-Jun-7"); fall back to start+days.
    $end = aurora_iso_mon_(($lk['end_date'] ?? ''));
    if ($end === '') $end = aurora_add_days_($start, is_numeric($days) ? (int)$days : null);
    $rawRegion = $lk['region'] ?: ($lk['destinations'] ?? '');
    $region = (stripos($rawRegion, 'antarc') !== false) ? 'Antarctic' : 'Arctic';
    return [
        'id'           => $id,
        'operator'     => 'Aurora Expeditions',
        'ship'         => $dat['Ship'] ?? '',
        'start'        => $start,
        'end'          => $end,
        'startRaw'     => $start,
        'endRaw'       => $end,
        'itinerary'    => $dat['ExternalName'] ?? ($dat['Name'] ?? ''),
        'days'         => $days,
        'nights'       => $nights,
        'duration'     => ($days !== '' ? ($days . ' days') : ''),
        'activities'   => [],
        'operatorLink' => '',
        'lgpLink'      => $lk['lgp_link'] ?? '',
        'destinations' => $lk['destinations'] ?? '',
        'region'       => $region,
        'season'       => $lk['season'] ?? '',
        'startLoc'     => (!empty($lk['start_loc'])) ? $lk['start_loc'] : ($dat['Location_FullLocationName'] ?? ''),
        'endLoc'       => (!empty($lk['end_loc']))   ? $lk['end_loc']   : ($dat['Location_FullLocationName'] ?? ''),
        'cabins'       => $cabins,
        'fromPrice'    => $fromPrice,
        'fromFull'     => $fromFull,
        'availCount'   => $availCount,
        'cabinCount'   => count($cabins),
    ];
}

// Add N days to an ISO yyyy-mm-dd date; returns ISO. Falls back to start on error.
function aurora_add_days_($iso, $n) {
    if (!$iso || $n === null) return $iso;
    $ts = strtotime($iso);
    if ($ts === false) return $iso;
    return gmdate('Y-m-d', $ts + $n * 86400);
}

// Convert "2026-Jun-7" (year-Mon-day) to ISO "2026-06-07". Returns '' if unparseable.
function aurora_iso_mon_($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $months = ['Jan'=>'01','Feb'=>'02','Mar'=>'03','Apr'=>'04','May'=>'05','Jun'=>'06',
               'Jul'=>'07','Aug'=>'08','Sep'=>'09','Oct'=>'10','Nov'=>'11','Dec'=>'12'];
    $parts = explode('-', $s);
    if (count($parts) === 3 && isset($months[$parts[1]])) {
        return $parts[0] . '-' . $months[$parts[1]] . '-' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
    }
    // Already ISO?
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return '';
}

// ------------------------------------------------------------------
// Swan Hellenic: slim trips JSON (uploaded, one entry per departure) +
// Swan LGP Links CSV (uploaded). Join on trip id == links API_id.
// Emits: standard trips (Cruise Plus fare) into the feed; plus swanFlash
// and swanSolo deal groupings for the Closed Market Deals page. Reports any
// unrecognized tariff family in swanNewTariffs.
//
// Slim trip shape (produced in the browser):
//   { "id": 443, "rooms": [ { "name": "Oceanview M4", "avail": 2,
//       "tariffs": { "Cruise Plus": {"full":17500,"disc":17500},
//                    "Arctic Flash Cruise Plus": {"full":15000,"disc":12000} } }, ... ] }
// ------------------------------------------------------------------
function merge_swan($mergedJson) {
    $payload = json_decode($mergedJson, true);
    if (!is_array($payload)) return $mergedJson;

    $tripsPath = __DIR__ . '/' . SWAN_TRIPS_LOCAL;
    $linksPath = __DIR__ . '/' . SWAN_LINKS_LOCAL;
    $diag = ['trips_present' => false, 'links_present' => false, 'trips_count' => 0,
             'links_count' => 0, 'mapped_count' => 0, 'flash_count' => 0, 'solo_count' => 0,
             'missing_count' => 0, 'error' => null];

    if (!is_readable($tripsPath)) { $payload['swanDiag'] = $diag; return json_encode($payload); }
    $diag['trips_present'] = true;
    $tmt = @filemtime($tripsPath);
    $diag['published'] = $tmt ? gmdate('c', $tmt) : null;

    $raw = file_get_contents($tripsPath);
    $trips = json_decode($raw, true);
    if (!is_array($trips)) { $diag['error'] = 'trips_parse_failed'; $payload['swanDiag'] = $diag; return json_encode($payload); }
    if (!array_is_list_compat($trips)) $trips = array_values($trips);
    $diag['trips_count'] = count($trips);

    // Links keyed by API_id.
    $linkMap = [];
    if (is_readable($linksPath)) {
        $diag['links_present'] = true;
        $csv = file_get_contents($linksPath);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(array("\r\n", "\r"), "\n", $csv);
        $lines = explode("\n", $csv);
        if ($lines) {
            $header = array_map(function($h){ return trim($h); }, str_getcsv(array_shift($lines)));
            $ci = array_flip($header);
            foreach ($lines as $line) {
                if ($line === '') continue;
                $cols = str_getcsv($line);
                $aid = isset($ci['API_id']) && isset($cols[$ci['API_id']]) ? trim($cols[$ci['API_id']]) : '';
                if ($aid === '') continue;
                $linkMap[$aid] = [
                    'ship'         => isset($ci['ship']) ? ($cols[$ci['ship']] ?? '') : '',
                    'itinerary'    => isset($ci['itinerary_name']) ? ($cols[$ci['itinerary_name']] ?? '') : '',
                    'start_date'   => isset($ci['start_date']) ? ($cols[$ci['start_date']] ?? '') : '',
                    'end_date'     => isset($ci['end_date']) ? ($cols[$ci['end_date']] ?? '') : '',
                    'destinations' => isset($ci['destinations']) ? ($cols[$ci['destinations']] ?? '') : '',
                    'season'       => isset($ci['season']) ? ($cols[$ci['season']] ?? '') : '',
                    'region'       => isset($ci['region']) ? ($cols[$ci['region']] ?? '') : '',
                    'lgp_link'     => isset($ci['lgp_links']) ? ($cols[$ci['lgp_links']] ?? '') : '',
                    'operator_link'=> isset($ci['operator_links']) ? ($cols[$ci['operator_links']] ?? '') : '',
                    'start_loc'    => isset($ci['start_location']) ? ($cols[$ci['start_location']] ?? '') : '',
                    'end_loc'      => isset($ci['end_location']) ? ($cols[$ci['end_location']] ?? '') : '',
                ];
            }
        }
        $diag['links_count'] = count($linkMap);
    }

    $flash = []; $solo = []; $missing = []; $newTariffs = [];
    $idx = 0; $fidx = 0; $sidx = 0;
    foreach ($trips as $t) {
        $tid = isset($t['id']) ? (string)$t['id'] : '';
        if ($tid === '') continue;
        if (!isset($linkMap[$tid])) { $missing[] = ['id' => $tid]; continue; }
        $lk = $linkMap[$tid];
        $rooms = isset($t['rooms']) && is_array($t['rooms']) ? $t['rooms'] : [];

        // Collect unknown tariff families for the warning.
        foreach ($rooms as $r) {
            foreach (($r['tariffs'] ?? []) as $tname => $_p) {
                if (!swan_tariff_known_($tname)) { $newTariffs[$tname] = true; }
            }
        }

        $stdCabins = swan_cabins_for_($rooms, 'standard');
        if ($stdCabins) { $payload['trips'][] = swan_map_trip($tid, $lk, $stdCabins, 600000 + $idx); $idx++; }
        $flashCabins = swan_cabins_for_($rooms, 'flash');
        if ($flashCabins) { $flash[] = swan_map_trip($tid, $lk, $flashCabins, 610000 + $fidx); $fidx++; }
        $soloCabins = swan_cabins_for_($rooms, 'solo');
        if ($soloCabins) { $solo[] = swan_map_trip($tid, $lk, $soloCabins, 620000 + $sidx); $sidx++; }
    }

    $diag['mapped_count'] = $idx;
    $diag['flash_count']  = $fidx;
    $diag['solo_count']   = $sidx;
    $diag['missing_count']= count($missing);
    $payload['swanFlash']      = $flash;
    $payload['swanSolo']       = $solo;
    $payload['swanMissing']    = array_values($missing);
    $payload['swanNewTariffs'] = array_keys($newTariffs);
    $payload['swanDiag']       = $diag;
    return json_encode($payload);
}

// Known Swan tariff families (ignoring Child/Extra suffixes). Anything else is "new".
function swan_tariff_known_($name) {
    $base = preg_replace('/\s*\((Child|Extra)\)\s*$/', '', trim((string)$name));
    $known = [
        'Cruise Plus', 'CRS Cruise Plus', 'Cruise Only', 'CRS Cruise Only',
        'Arctic Flash Cruise Plus', 'Arctic Flash Cruise Only',
        'Swan Flash Cruise Plus', 'Swan Flash Cruise Only',
        'Swan Solo Cruise Plus', 'Swan Solo Cruise Only',
    ];
    return in_array($base, $known, true);
}

// Build dashboard cabins[] from slim rooms, selecting the tariff by mode:
//  standard -> "Cruise Plus" (fallback "CRS Cruise Plus" / any Cruise Plus)
//  flash    -> any tariff whose name contains "Flash"
//  solo     -> any tariff whose name contains "Solo"
// Child/Extra variants always excluded. available = room avail > 0.
function swan_cabins_for_($rooms, $mode) {
    // Which occupancy to price by: Double (id 2) for standard and Flash boards;
    // Single (id 1) for Solo. Single is per-person sole occupancy (higher); Double
    // is per-person shared (lower). Mixing them was the earlier pricing bug.
    $occ = ($mode === 'solo') ? 'single' : 'double';
    $out = [];
    foreach ($rooms as $r) {
        $name = $r['name'] ?? '';
        // Skip GTY (guarantee) room-classes for now — behaviour not yet defined.
        if (stripos(ltrim($name), 'GTY') === 0) continue;
        $avail = ((int)($r['avail'] ?? 0)) > 0;
        $tariffs = isset($r['tariffs']) && is_array($r['tariffs']) ? $r['tariffs'] : [];
        $chosen = null; $chosenLabel = '';
        foreach ($tariffs as $tname => $p) {
            if (preg_match('/\((Child|Extra)\)\s*$/', $tname)) continue;
            $isFlash = (stripos($tname, 'Flash') !== false);
            $isSolo  = (stripos($tname, 'Solo')  !== false);
            if ($mode === 'standard') {
                if ($isFlash || $isSolo) continue;
                if ($tname === 'Cruise Plus') { $chosen = $p; $chosenLabel = $tname; break; }
                if ($chosen === null && $tname === 'CRS Cruise Plus') { $chosen = $p; $chosenLabel = $tname; }
                elseif ($chosen === null && stripos($tname, 'Cruise Plus') !== false) { $chosen = $p; $chosenLabel = $tname; }
            } elseif ($mode === 'flash') {
                if ($isFlash) { $chosen = $p; $chosenLabel = $tname; break; }
            } elseif ($mode === 'solo') {
                if ($isSolo) { $chosen = $p; $chosenLabel = $tname; break; }
            }
        }
        if ($chosen === null) continue;
        $dealVal = swan_occ_price_($chosen, $occ);
        if ($dealVal === null) continue; // no price for the required occupancy on this room

        if ($mode === 'standard') {
            $out[] = [
                'name'      => $name,
                'full'      => $dealVal,
                'disc'      => $dealVal,
                'available' => $avail,
            ];
        } else {
            // Deal cabin: "was" is the standard Cruise Plus fare on the SAME room and
            // SAME occupancy (only when actually higher); "now" is the deal fare.
            $stdVal = null;
            if (isset($tariffs['Cruise Plus']))      $stdVal = swan_occ_price_($tariffs['Cruise Plus'], $occ);
            if ($stdVal === null && isset($tariffs['CRS Cruise Plus'])) $stdVal = swan_occ_price_($tariffs['CRS Cruise Plus'], $occ);
            $showWas = ($stdVal !== null && $dealVal !== null && $stdVal > $dealVal);
            $out[] = [
                'name'      => $name . ($chosenLabel ? ' — ' . $chosenLabel : ''),
                'full'      => $showWas ? $stdVal : $dealVal,
                'disc'      => $dealVal,
                'available' => $avail,
            ];
        }
    }
    return $out;
}

// Pull the disc (fallback full) price for a given occupancy ('double'/'single')
// from a tariff value. Tolerates the legacy flat {full,disc} shape by treating it
// as the price for any occupancy.
function swan_occ_price_($tariffVal, $occ) {
    if (!is_array($tariffVal)) return null;
    if (isset($tariffVal[$occ]) && is_array($tariffVal[$occ])) {
        $o = $tariffVal[$occ];
        return $o['disc'] ?? ($o['full'] ?? null);
    }
    // Legacy flat shape (pre-occupancy split): use as-is so old cached files don't break.
    if (array_key_exists('disc', $tariffVal) || array_key_exists('full', $tariffVal)) {
        return $tariffVal['disc'] ?? ($tariffVal['full'] ?? null);
    }
    return null;
}

function swan_map_trip($tid, $lk, $cabins, $id) {
    $fromPrice = null; $fromFull = null; $availCount = 0;
    foreach ($cabins as $c) {
        if ($c['available'] && $c['disc'] !== null) {
            $availCount++;
            if ($fromPrice === null || $c['disc'] < $fromPrice) { $fromPrice = $c['disc']; $fromFull = $c['full']; }
        }
    }
    $rawRegion = $lk['region'] ?: ($lk['destinations'] ?? '');
    $region = (stripos($rawRegion, 'antarc') !== false) ? 'Antarctic' : 'Arctic';
    return [
        'id'           => $id,
        'operator'     => 'Swan Hellenic',
        'ship'         => $lk['ship'] ?? '',
        'start'        => aurora_iso_mon_($lk['start_date'] ?? ''),
        'end'          => aurora_iso_mon_($lk['end_date'] ?? ''),
        'startRaw'     => $lk['start_date'] ?? '',
        'endRaw'       => $lk['end_date'] ?? '',
        'itinerary'    => $lk['itinerary'] ?? '',
        'days'         => '',
        'nights'       => null,
        'duration'     => '',
        'activities'   => [],
        'operatorLink' => $lk['operator_link'] ?? '',
        'lgpLink'      => $lk['lgp_link'] ?? '',
        'destinations' => $lk['destinations'] ?? '',
        'region'       => $region,
        'season'       => $lk['season'] ?? '',
        'startLoc'     => $lk['start_loc'] ?? '',
        'endLoc'       => $lk['end_loc'] ?? '',
        'cabins'       => $cabins,
        'fromPrice'    => $fromPrice,
        'fromFull'     => $fromFull,
        'availCount'   => $availCount,
        'cabinCount'   => count($cabins),
    ];
}

// ------------------------------------------------------------------
// Quark Closed Market Deals: parsed pricing (quark-cmd.json, from the uploaded
// xlsx) matched to the Quark LGP Links. Match key is ship + fuzzy itinerary +
// date within +/-1 day (Quark's hotel-night convention can shift the date by a
// day). Matched departures gain the LGP link, destinations, season and region;
// unmatched departures still render from the sheet's own data. Emits quarkCMD
// (card-shaped trips), quarkCmdDate (sheet generated date) and quarkCmdDiag.
// ------------------------------------------------------------------
function merge_quark_cmd($mergedJson) {
    $payload = json_decode($mergedJson, true);
    if (!is_array($payload)) return $mergedJson;

    $cmdPath   = __DIR__ . '/' . QUARK_CMD_LOCAL;
    $linksPath = __DIR__ . '/' . QUARK_LINKS_LOCAL;
    $diag = ['present' => false, 'departures' => 0, 'matched' => 0, 'unmatched' => 0, 'links_count' => 0, 'error' => null];

    if (!is_readable($cmdPath)) { $payload['quarkCmdDiag'] = $diag; return json_encode($payload); }
    $diag['present'] = true;
    $cmt = @filemtime($cmdPath);
    $diag['published'] = $cmt ? gmdate('c', $cmt) : null;

    $raw = file_get_contents($cmdPath);
    $cmd = json_decode($raw, true);
    if (!is_array($cmd) || !isset($cmd['departures'])) { $diag['error'] = 'cmd_parse_failed'; $payload['quarkCmdDiag'] = $diag; return json_encode($payload); }
    $sheetDate = $cmd['generated'] ?? ($diag['published'] ?? null);
    $departures = $cmd['departures'];
    $diag['departures'] = count($departures);

    // Build the Quark links index for matching: rows with ship, start_date(ISO), itinerary, plus payload fields.
    $links = [];
    if (is_readable($linksPath)) {
        $csv = file_get_contents($linksPath);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $csv = str_replace(array("\r\n", "\r"), "\n", $csv);
        $lines = explode("\n", $csv);
        if ($lines) {
            $header = array_map(function($h){ return trim($h); }, str_getcsv(array_shift($lines)));
            $ci = array_flip($header);
            foreach ($lines as $line) {
                if ($line === '') continue;
                $cols = str_getcsv($line);
                $get = function($k) use ($ci, $cols) { return (isset($ci[$k]) && isset($cols[$ci[$k]])) ? trim($cols[$ci[$k]]) : ''; };
                $links[] = [
                    'ship'         => $get('ship'),
                    'itin'         => $get('itinerary_name'),
                    'start_iso'    => quark_iso_($get('start_date')),
                    'end_iso'      => quark_iso_($get('end_date')),
                    'destinations' => $get('destinations'),
                    'season'       => $get('season'),
                    'region'       => $get('region'),
                    'lgp_link'     => $get('lgp_links'),
                    'operator_link'=> $get('operator_links'),
                    'start_loc'    => $get('start_location'),
                    'end_loc'      => $get('end_location'),
                ];
            }
        }
    }
    $diag['links_count'] = count($links);

    $cards = []; $idx = 0; $matched = 0;
    foreach ($departures as $d) {
        $link = qcmd_match_($d, $links);
        if ($link !== null) $matched++;
        $cards[] = qcmd_map_($d, $link, $sheetDate, 630000 + $idx);
        $idx++;
    }
    $diag['matched']   = $matched;
    $diag['unmatched'] = count($departures) - $matched;

    $payload['quarkCMD']     = $cards;
    $payload['quarkCmdDate'] = $sheetDate;
    $payload['quarkCmdDiag'] = $diag;
    return json_encode($payload);
}

// Match a CMD departure to a links row: ship equal (normalized), itinerary prefix
// overlap, and start dates within +/-1 day. Returns the link row or null.
function qcmd_match_($d, $links) {
    $ship = qcmd_norm_($d['ship'] ?? '');
    $itin = qcmd_norm_($d['itinerary'] ?? '');
    $dts  = strtotime(($d['date'] ?? '') ?: '1970-01-01');
    $best = null; $bestScore = 99;
    foreach ($links as $L) {
        if (qcmd_norm_($L['ship']) !== $ship) continue;
        $lts = strtotime(($L['start_iso'] ?? '') ?: '1970-01-01');
        $dayDiff = ($dts && $lts) ? abs((int)round(($lts - $dts) / 86400)) : 99;
        if ($dayDiff > 1) continue;
        $a = $itin; $b = qcmd_norm_($L['itin']);
        $pref = min(strlen($a), strlen($b), 25);
        $itinOk = ($pref > 0 && substr($a, 0, $pref) === substr($b, 0, $pref));
        // score: prefer itinerary match, then closest date
        $score = ($itinOk ? 0 : 5) + $dayDiff;
        if ($score < $bestScore) { $bestScore = $score; $best = $L; }
    }
    return $best;
}

function qcmd_norm_($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
}

// Map a CMD departure (+ optional matched link) into the dashboard card shape.
function qcmd_map_($d, $link, $sheetDate, $id) {
    $cabins = [];
    foreach (($d['cabins'] ?? []) as $c) {
        $full = $c['full'] ?? null; $disc = $c['disc'] ?? null;
        $cabins[] = [
            'name'      => $c['name'] ?? '',
            'full'      => $full,
            'disc'      => ($disc !== null ? $disc : $full),
            'available' => true, // the CMD grid lists offered deal cabins; no availability field
        ];
    }
    $fromPrice = null; $fromFull = null; $availCount = 0;
    foreach ($cabins as $c) {
        if ($c['disc'] !== null) {
            $availCount++;
            if ($fromPrice === null || $c['disc'] < $fromPrice) { $fromPrice = $c['disc']; $fromFull = $c['full']; }
        }
    }
    $dest = $link ? ($link['destinations'] ?? '') : '';
    $rawRegion = $link ? ($link['region'] ?: ($link['destinations'] ?? '')) : '';
    $region = (stripos($rawRegion, 'antarc') !== false) ? 'Antarctic' : 'Arctic';
    // Return date: prefer the matched links end date; otherwise compute from
    // departure + cruise nights (a best-effort fallback for unmatched departures).
    $start = $d['date'] ?? '';
    $end = ($link && !empty($link['end_iso'])) ? $link['end_iso'] : '';
    if ($end === '' && $start !== '' && isset($d['nights']) && is_numeric($d['nights'])) {
        $ts = strtotime($start);
        if ($ts !== false) $end = gmdate('Y-m-d', $ts + ((int)$d['nights']) * 86400);
    }
    return [
        'id'           => $id,
        'operator'     => 'Quark Expeditions',
        'ship'         => $d['ship'] ?? '',
        'start'        => $start,
        'end'          => $end,
        'startRaw'     => $d['date'] ?? '',
        'endRaw'       => $end,
        'itinerary'    => $d['itinerary'] ?? '',
        'days'         => $d['nights'] ?? '',
        'nights'       => $d['nights'] ?? null,
        'duration'     => (isset($d['nights']) && $d['nights'] !== '') ? ($d['nights'] . ' nights') : '',
        'activities'   => [],
        'operatorLink' => $link ? ($link['operator_link'] ?? '') : '',
        'lgpLink'      => $link ? ($link['lgp_link'] ?? '') : '',
        'destinations' => $dest,
        'region'       => $region,
        'season'       => $link ? ($link['season'] ?? '') : '',
        'startLoc'     => $link ? ($link['start_loc'] ?? '') : '',
        'endLoc'       => $link ? ($link['end_loc'] ?? '') : '',
        'cabins'       => $cabins,
        'fromPrice'    => $fromPrice,
        'fromFull'     => $fromFull,
        'availCount'   => $availCount,
        'cabinCount'   => count($cabins),
        'cmdUnmatched' => ($link === null),
    ];
}

function ow_fetch_link_map() {
    global $OW_CSV_DIAG;
    $OW_CSV_DIAG = ['csv_fetched' => false, 'csv_bytes' => 0, 'csv_headers' => null, 'csv_source' => null];
    $map = [];
    $csv = false;
    $localPath = __DIR__ . '/' . OCEANWIDE_LINKS_LOCAL;
    if (OCEANWIDE_LINKS_LOCAL !== '' && is_readable($localPath)) {
        $csv = file_get_contents($localPath);
        $OW_CSV_DIAG['csv_source'] = 'local';
        $mt = @filemtime($localPath);
        $OW_CSV_DIAG['links_updated'] = $mt ? gmdate('c', $mt) : null;
    }
    if ($csv === false || $csv === '') {
        $csv = http_get(OCEANWIDE_LINKS_CSV);
        $OW_CSV_DIAG['csv_source'] = 'url';
    }
    if ($csv === false || $csv === '') { return $map; }
    $OW_CSV_DIAG['csv_fetched'] = true;
    $OW_CSV_DIAG['csv_bytes'] = strlen($csv);
    // Parse CSV. Use str_getcsv line by line to handle quoted itinerary names with commas.
    $csv = str_replace(array("\r\n", "\r"), "\n", $csv);
    $lines = explode("\n", $csv);
    if (!$lines) return $map;
    $header = null; $ci_code = null; $ci_link = null;
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if ($header === null) {
            $header = array_map('trim', $cols);
            $ci_code = array_search('operator_code', $header);
            $ci_link = array_search('lgp_links', $header);
            $ci_dest = array_search('destinations', $header);
            $ci_seas = array_search('season', $header);
            $ci_itin = array_search('itinerary_name', $header);
            $ci_reg  = array_search('region', $header);
            $GLOBALS['OW_CSV_DIAG']['csv_headers'] = $header;
            continue;
        }
        if ($ci_code === false) break; // no join key, cannot map
        $code = isset($cols[$ci_code]) ? ow_norm_code_($cols[$ci_code]) : '';
        if ($code === '') continue;
        $link = ($ci_link !== false && isset($cols[$ci_link])) ? trim($cols[$ci_link]) : '';
        $map[$code] = [
            'link'         => $link,
            'destinations' => ($ci_dest !== false && isset($cols[$ci_dest])) ? trim($cols[$ci_dest]) : '',
            'season'       => ($ci_seas !== false && isset($cols[$ci_seas])) ? trim($cols[$ci_seas]) : '',
            'itinerary'    => ($ci_itin !== false && isset($cols[$ci_itin])) ? trim($cols[$ci_itin]) : '',
            'region'       => ($ci_reg  !== false && isset($cols[$ci_reg]))  ? trim($cols[$ci_reg])  : '',
        ];
    }
    return $map;
}
// Normalize a trip code for matching (uppercase, trim, strip stray spaces).
function ow_norm_code_($c) {
    return strtoupper(trim($c));
}
function ow_region_($r, $destArr) {
    $r = strtolower($r ?? '');
    if (strpos($r, 'antarctic') !== false) return 'Antarctic';
    if (strpos($r, 'arctic') !== false) return 'Arctic';
    $dest = strtolower(implode(' ', $destArr ?? []));
    foreach (['antarctic','south georgia','falkland','weddell','drake','ushuaia','shetland'] as $k) {
        if (strpos($dest, $k) !== false) return 'Antarctic';
    }
    return 'Arctic';
}
function ow_rawdate_($iso) {
    if (!$iso) return '';
    $mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $p = explode('-', $iso);
    if (count($p) !== 3) return '';
    return intval($p[2]) . ' ' . $mon[intval($p[1])-1] . ' ' . $p[0];
}
function ow_season_($region, $iso) {
    if (!$iso) return '';
    $p = explode('-', $iso);
    if (count($p) !== 3) return '';
    $y = intval($p[0]); $m = intval($p[1]);
    if ($region === 'Antarctic') {
        $start = ($m >= 9) ? $y : $y - 1;
    } else {
        $start = $y;
    }
    return substr((string)$start, 2) . '-' . substr((string)($start+1), 2);
}
function ow_avail_($c) {
    return (($c['ber_units'] ?? 0) > 0) || (($c['cab_units'] ?? 0) > 0);
}
function ow_cabin_($c) {
    $price = $c['price'] ?? null;      // net / current
    $old   = $c['old_price'] ?? null;  // full
    $full  = ($old !== null) ? $old : $price;
    return [
        'name'      => $c['title'] ?? 'Cabin',
        'full'      => $full,
        'disc'      => $price,
        'available' => ow_avail_($c),
    ];
}
function map_oceanwide_trip($t, $idx, $linkMap, &$missing) {
    $region = ow_region_($t['region'] ?? '', $t['destinations'] ?? []);
    $start = $t['departure_date'] ?? null;
    $end   = $t['return_date'] ?? null;

    $cabins = [];
    foreach (($t['cabins'] ?? []) as $c) $cabins[] = ow_cabin_($c);
    $availCabins = array_values(array_filter($cabins, function($c){ return $c['available']; }));
    $prices = array_values(array_filter(array_map(function($c){ return $c['disc']; }, $availCabins), function($v){ return $v !== null; }));
    $fulls  = array_values(array_filter(array_map(function($c){ return $c['full']; }, $availCabins), function($v){ return $v !== null; }));
    $fromPrice = $prices ? min($prices) : null;
    $fromFull  = $fulls ? min($fulls) : null;

    $acts = [];
    foreach (($t['extras'] ?? []) as $e) {
        $pr = $e['price'] ?? null; $ti = $e['title'] ?? 'Activity';
        $acts[] = ['text' => is_numeric($pr) ? ($ti . ' - $' . number_format($pr) . ' per person') : $ti, 'price' => $pr];
    }

    $days = $t['days'] ?? null; $nights = $t['nights'] ?? null;
    $dur = ($days && $nights) ? ($days . ' D / ' . $nights . ' N') : ($days ? ($days . ' D') : '');

    $code = $t['code'] ?? '';
    $codeNorm = ow_norm_code_($code);
    $lgp = '';
    $row = ($codeNorm !== '' && isset($linkMap[$codeNorm])) ? $linkMap[$codeNorm] : null;
    // The link map value may be the widened array (current) or a bare string (legacy).
    $rowLink = is_array($row) ? ($row['link'] ?? '') : (is_string($row) ? $row : '');
    if ($row !== null && $rowLink !== '') {
        $lgp = $rowLink;
    } else {
        // No matching row, or matched row has no link yet -> report for follow-up.
        $missing[] = [
            'code'        => $code,
            'name'        => $t['name'] ?? '',
            'ship'        => $t['ship'] ?? '',
            'departure'   => $start,
            'return'      => $end,
            'operatorUrl' => isset($t['url']) ? ('https://oceanwide-expeditions.com' . $t['url']) : '',
        ];
    }

    // Prefer the curated CSV values (itinerary, destinations, season, region) when the
    // row exists; otherwise fall back to the Oceanwide feed values.
    $feedDest = implode(', ', $t['destinations'] ?? []);
    $itinerary   = ($row && !empty($row['itinerary']))    ? $row['itinerary']    : ($t['name'] ?? '');
    $destinations= ($row && isset($row['destinations']) && $row['destinations'] !== '') ? $row['destinations'] : $feedDest;
    $season      = ($row && !empty($row['season']))       ? $row['season']       : ow_season_($region, $start);
    if ($row && !empty($row['region'])) {
        $region = (stripos($row['region'], 'antarc') !== false) ? 'Antarctic' : 'Arctic';
    }

    return [
        'id'           => 900000 + $idx, // high offset so Oceanwide ids never clash with sheet ids
        'operator'     => 'Oceanwide Expeditions',
        'ship'         => $t['ship'] ?? '',
        'start'        => $start,
        'end'          => $end,
        'startRaw'     => ow_rawdate_($start),
        'endRaw'       => ow_rawdate_($end),
        'itinerary'    => $itinerary,
        'days'         => $days,
        'nights'       => $nights,
        'duration'     => $dur,
        'activities'   => $acts,
        'operatorLink' => isset($t['url']) ? ('https://oceanwide-expeditions.com' . $t['url']) : '',
        'lgpLink'      => $lgp,
        'destinations' => $destinations,
        'region'       => $region,
        'season'       => $season,
        'startLoc'     => $t['embark'] ?? '',
        'endLoc'       => $t['disembark'] ?? '',
        'cabins'       => $cabins,
        'fromPrice'    => $fromPrice,
        'fromFull'     => $fromFull,
        'availCount'   => count($availCabins),
        'cabinCount'   => count($cabins),
    ];
}

// ------------------------------------------------------------------
// Helper: pull the INCLUSIONS and EXCLUSIONS lists out of a trip page.
// Works off the visible text, so it does not depend on the theme's CSS classes.
// ------------------------------------------------------------------
function extract_trip_media($html) {
    return [
        'gallery'     => grab_gallery_($html, 3),
        'description' => grab_description_($html),
        'map'         => grab_map_($html),
        'ship'        => grab_ship_($html),
    ];
}

// Voyage map image: lazy-loaded, real URL sits in data-lazy-src inside the "Voyage map" panel.
function grab_map_($html) {
    $i = stripos($html, 'Voyage map');
    if ($i === false) return '';
    $rest = substr($html, $i);
    if (preg_match_all('/<img\\b[^>]*>/i', $rest, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/data-lazy-src="([^"]+)"/i', $tag, $lm)) {
                $u = html_entity_decode($lm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strpos($u, 'data:') !== 0) return $u;
            }
            if (preg_match('/\\ssrc="([^"]+)"/i', $tag, $sm)) {
                $u = html_entity_decode($sm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strpos($u, 'data:') !== 0) return $u;
            }
        }
    }
    return '';
}

// Ship details: description paragraphs + Type / Capacity / Year Built, from the "Ship Details" region.
function grab_ship_($html) {
    $i = stripos($html, 'Ship Details');
    if ($i === false) return ['description' => '', 'spec' => new stdClass()];
    $rest = substr($html, $i + strlen('Ship Details'));
    // Bound the region at the next major section heading.
    $stop = strlen($rest);
    foreach (['>FAQ<', 'Trip Request Form', 'Adventure Options'] as $lab) {
        $p = stripos($rest, $lab);
        if ($p !== false && $p < $stop) $stop = $p;
    }
    $region = substr($rest, 0, $stop);
    // Description = first up-to-two paragraphs.
    $desc = '';
    if (preg_match_all('/<p\\b[^>]*>(.*?)<\\/p>/is', $region, $pm)) {
        $parts = [];
        foreach ($pm[1] as $p) {
            $t = clean_text_($p);
            if ($t !== '') $parts[] = $t;
            if (count($parts) >= 2) break;
        }
        $desc = implode(' ', $parts);
    }
    // Spec fields.
    $spec = [];
    foreach (['Type', 'Capacity', 'Year Built'] as $label) {
        if (preg_match('/>' . preg_quote($label, '/') . '<\\/h2>\\s*<div[^>]*>(.*?)<\\/div>/is', $region, $mm)) {
            $v = clean_text_($mm[1]);
            if ($v !== '') $spec[$label] = $v;
        }
    }
    return ['description' => $desc, 'spec' => (object)$spec];
}

function clean_text_($s) {
    $s = preg_replace('/<[^>]+>/', ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\\s+/', ' ', $s);
    return trim($s);
}

// First N carousel images hosted on letsgopolar.com (hero + gallery).
function grab_gallery_($html, $limit) {
    $out = [];
    if (preg_match_all('/<img\\b[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (stripos($tag, 'swiper-slide-image') === false) continue;
            if (!preg_match('/\\ssrc="([^"]+)"/i', $tag, $sm)) continue;
            $u = $sm[1];
            if (strpos($u, 'data:') === 0) continue;
            if (strpos($u, 'https://letsgopolar.com/') !== 0) continue;
            if (!in_array($u, $out, true)) $out[] = $u;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

// Description = first paragraph text inside the "Voyage map" accordion panel.
function grab_description_($html) {
    $i = stripos($html, 'Voyage map');
    if ($i === false) return '';
    $rest = substr($html, $i);
    if (!preg_match('/<p>(.*?)<\\/p>/is', $rest, $pm)) return '';
    $txt = preg_replace('/<[^>]+>/', ' ', $pm[1]);
    $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace('/\\s+/', ' ', $txt);
    return trim($txt);
}

function extract_incl_excl($html) {
    // Reduce to text while keeping list-item boundaries as newlines.
    $s = $html;
    $s = preg_replace('/<\s*li[^>]*>/i', "\n\x01", $s);   // mark start of each <li>
    $s = preg_replace('/<[^>]+>/', ' ', $s);                 // strip remaining tags
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize whitespace but keep our \x01 list markers and newlines.
    $s = preg_replace('/[ \t\r\f]+/', ' ', $s);

    $inc = grab_list_($s, 'INCLUSIONS', ['EXCLUSIONS', 'Payment terms', 'Rates per person']);
    $exc = grab_list_($s, 'EXCLUSIONS', ['Payment terms', 'Rates per person', 'Cabin Details']);
    return ['inclusions' => $inc, 'exclusions' => $exc];
}

function grab_list_($text, $startLabel, $stopLabels) {
    // Case-sensitive, word-boundary match so the ALL-CAPS label "INCLUSIONS"
    // is found but the mixed-case heading "Inclusions and Exclusions" is not.
    if (!preg_match('/\b' . preg_quote($startLabel, '/') . '\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $rest = substr($text, $m[0][1] + strlen($startLabel));
    // Cut at the first stop label that appears (also word-boundary matched).
    $cut = strlen($rest);
    foreach ($stopLabels as $lab) {
        if (preg_match('/\b' . preg_quote($lab, '/') . '\b/', $rest, $mm, PREG_OFFSET_CAPTURE)) {
            if ($mm[0][1] < $cut) $cut = $mm[0][1];
        }
    }
    $chunk = substr($rest, 0, $cut);
    // Each list item was marked with \x01.
    $items = [];
    foreach (explode("\x01", $chunk) as $piece) {
        $line = trim($piece);
        if ($line === '') continue;
        // Skip stray leftovers that are just punctuation or very short.
        if (mb_strlen($line) < 3) continue;
        $items[] = $line;
        if (count($items) >= 40) break; // safety cap
    }
    return $items;
}

// ------------------------------------------------------------------
// Helper: fetch a URL with cURL, following redirects (Apps Script redirects).
// ------------------------------------------------------------------
function http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,   // Apps Script issues a redirect
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'LGP-RateDesk-Proxy',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return false;
        return $body;
    }
    // Fallback if cURL is unavailable.
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? false : $body;
}


// ------------------------------------------------------------------
// Swan Hellenic Antarctica 2026-27 Closed Market Deals (CMD).
// One-off gross per-person (double occupancy) rates supplied by Swan Hellenic
// by email (no feed/API). Rates are static and baked in here; ship, itinerary,
// destinations and LGP link were matched to the Swan Hellenic LGP Links sheet by
// departure date. Each card carries two cabin rows: the double-occupancy gross
// rate (the "starting from" headline) and a derived single-occupancy figure
// (per-person fare x the quoted single-supplement %). Emits swanAntarcticaCmd.
// Self-contained: never a no-op, no external files required.
// ------------------------------------------------------------------
function merge_swan_antarctica_cmd($mergedJson) {
    $payload = json_decode($mergedJson, true);
    if (!is_array($payload)) $payload = ['updated' => '', 'trips' => []];
    $payload['swanAntarcticaCmd'] = swan_antarctica_cmd_cards();
    return json_encode($payload);
}
function swan_antarctica_cmd_cards() {
    return [
    [
        'id' => 640000, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-10-23', 'end' => '2026-11-12',
        'startRaw' => '2026-Oct-23', 'endRaw' => '2026-Nov-12',
        'itinerary' => 'South Atlantic Cruise: from South Africa to Antarctica',
        'days' => 20, 'nights' => null, 'duration' => '20 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/south-atlantic-cruise-from-south-africa-to-antarctica-2026-10-23',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-23-oct-2026-south-atlantic-cruise-from-south-africa-to-antarctica/',
        'destinations' => 'South Georgia',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Cape Town', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 9998, 'disc' => 9998, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 14997, 'disc' => 14997, 'available' => true],
        ],
        'fromPrice' => 9998, 'fromFull' => 9998,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640001, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Minerva',
        'start' => '2026-11-11', 'end' => '2026-11-20',
        'startRaw' => '2026-Nov-11', 'endRaw' => '2026-Nov-20',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-11-11',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shm-11-nov-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 8999, 'disc' => 8999, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 13498, 'disc' => 13498, 'available' => true],
        ],
        'fromPrice' => 8999, 'fromFull' => 8999,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640002, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-11-12', 'end' => '2026-11-21',
        'startRaw' => '2026-Nov-12', 'endRaw' => '2026-Nov-21',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-11-12',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-12-nov-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 9225, 'disc' => 9225, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 13838, 'disc' => 13838, 'available' => true],
        ],
        'fromPrice' => 9225, 'fromFull' => 9225,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640003, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Minerva',
        'start' => '2026-11-20', 'end' => '2026-12-08',
        'startRaw' => '2026-Nov-20', 'endRaw' => '2026-Dec-08',
        'itinerary' => 'In Shackleton\'s Footsteps: Falklands, South Georgia and the Antarctic Peninsula',
        'days' => 18, 'nights' => null, 'duration' => '18 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/in-shackletons-footsteps-falklands-south-georgia-and-the-antarctic-peninsula-2026-11-20',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shm-20-nov-2026-in-shackletons-footsteps-falklands-south-georgia-and-the-antarctic-peninsula/',
        'destinations' => 'South Georgia',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 17998, 'disc' => 17998, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 26997, 'disc' => 26997, 'available' => true],
        ],
        'fromPrice' => 17998, 'fromFull' => 17998,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640004, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-11-21', 'end' => '2026-11-30',
        'startRaw' => '2026-Nov-21', 'endRaw' => '2026-Nov-30',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-11-21',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-21-nov-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 9225, 'disc' => 9225, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 13838, 'disc' => 13838, 'available' => true],
        ],
        'fromPrice' => 9225, 'fromFull' => 9225,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640005, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Vega',
        'start' => '2026-11-24', 'end' => '2026-12-04',
        'startRaw' => '2026-Nov-24', 'endRaw' => '2026-Dec-04',
        'itinerary' => 'Antarctic Peninsula Odyssey cruise',
        'days' => 10, 'nights' => null, 'duration' => '10 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-peninsula-odyssey-cruise-2026-11-24',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shv-24-nov-2026-antarctic-peninsula-odyssey-cruise/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 10250, 'disc' => 10250, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 15375, 'disc' => 15375, 'available' => true],
        ],
        'fromPrice' => 10250, 'fromFull' => 10250,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640006, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-11-30', 'end' => '2026-12-09',
        'startRaw' => '2026-Nov-30', 'endRaw' => '2026-Dec-09',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-11-30',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-30-nov-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 9225, 'disc' => 9225, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 13838, 'disc' => 13838, 'available' => true],
        ],
        'fromPrice' => 9225, 'fromFull' => 9225,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640007, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Vega',
        'start' => '2026-12-04', 'end' => '2026-12-13',
        'startRaw' => '2026-Dec-04', 'endRaw' => '2026-Dec-13',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-12-04',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shv-04-dec-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 10125, 'disc' => 10125, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 15188, 'disc' => 15188, 'available' => true],
        ],
        'fromPrice' => 10125, 'fromFull' => 10125,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640008, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-12-09', 'end' => '2026-12-19',
        'startRaw' => '2026-Dec-09', 'endRaw' => '2026-Dec-19',
        'itinerary' => 'Antarctic Peninsula Odyssey cruise',
        'days' => 10, 'nights' => null, 'duration' => '10 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-peninsula-odyssey-cruise-2026-12-09',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-09-dec-2026-antarctic-peninsula-odyssey-cruise/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 11499, 'disc' => 11499, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 17248, 'disc' => 17248, 'available' => true],
        ],
        'fromPrice' => 11499, 'fromFull' => 11499,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640009, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Vega',
        'start' => '2026-12-13', 'end' => '2026-12-22',
        'startRaw' => '2026-Dec-13', 'endRaw' => '2026-Dec-22',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2026-12-13',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shv-13-dec-2026-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 10350, 'disc' => 10350, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 15525, 'disc' => 15525, 'available' => true],
        ],
        'fromPrice' => 10350, 'fromFull' => 10350,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640010, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2026-12-19', 'end' => '2026-12-29',
        'startRaw' => '2026-Dec-19', 'endRaw' => '2026-Dec-29',
        'itinerary' => 'Antarctic Peninsula Odyssey cruise',
        'days' => 10, 'nights' => null, 'duration' => '10 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-peninsula-odyssey-cruise-2026-12-19',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-19-dec-2026-antarctic-peninsula-odyssey-cruise/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 11499, 'disc' => 11499, 'available' => true],
            ['name' => 'Single occupancy (150% of per-person fare)', 'full' => 17248, 'disc' => 17248, 'available' => true],
        ],
        'fromPrice' => 11499, 'fromFull' => 11499,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 150,
    ],
    [
        'id' => 640011, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Diana',
        'start' => '2027-02-24', 'end' => '2027-03-09',
        'startRaw' => '2027-Feb-24', 'endRaw' => '2027-Mar-09',
        'itinerary' => 'Cruise to the Antarctic Circle',
        'days' => 13, 'nights' => null, 'duration' => '13 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/cruise-to-the-antarctic-circle-2027-02-24',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shd-24-feb-2027-cruise-to-the-antarctic-circle/',
        'destinations' => 'Circle',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 12999, 'disc' => 12999, 'available' => true],
            ['name' => 'Single occupancy (175% of per-person fare)', 'full' => 22748, 'disc' => 22748, 'available' => true],
        ],
        'fromPrice' => 12999, 'fromFull' => 12999,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 175,
    ],
    [
        'id' => 640012, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Vega',
        'start' => '2027-03-03', 'end' => '2027-03-12',
        'startRaw' => '2027-Mar-03', 'endRaw' => '2027-Mar-12',
        'itinerary' => 'Antarctic Wonders: roundtrip cruise from Ushuaia',
        'days' => 9, 'nights' => null, 'duration' => '9 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/antarctic-wonders-roundtrip-cruise-from-ushuaia-2027-03-03',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shv-03-mar-2027-antarctic-wonders-roundtrip-cruise-from-ushuaia/',
        'destinations' => 'Classic',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 9225, 'disc' => 9225, 'available' => true],
            ['name' => 'Single occupancy (175% of per-person fare)', 'full' => 16144, 'disc' => 16144, 'available' => true],
        ],
        'fromPrice' => 9225, 'fromFull' => 9225,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 175,
    ],
    [
        'id' => 640013, 'operator' => 'Swan Hellenic',
        'ship' => 'SH Vega',
        'start' => '2027-03-12', 'end' => '2027-03-25',
        'startRaw' => '2027-Mar-12', 'endRaw' => '2027-Mar-25',
        'itinerary' => 'Cruise to the Antarctic Circle',
        'days' => 13, 'nights' => null, 'duration' => '13 days',
        'activities' => [],
        'operatorLink' => 'https://www.swanhellenic.com/cruise/cruise-to-the-antarctic-circle-2027-03-12',
        'lgpLink' => 'https://letsgopolar.com/antarctic-trips/shv-12-mar-2027-cruise-to-the-antarctic-circle/',
        'destinations' => 'Circle',
        'region' => 'Antarctic', 'season' => '26-27',
        'startLoc' => 'Ushuaia', 'endLoc' => 'Ushuaia',
        'cabins' => [
            ['name' => 'Per person, double occupancy (gross)', 'full' => 12199, 'disc' => 12199, 'available' => true],
            ['name' => 'Single occupancy (175% of per-person fare)', 'full' => 21348, 'disc' => 21348, 'available' => true],
        ],
        'fromPrice' => 12199, 'fromFull' => 12199,
        'availCount' => 1, 'cabinCount' => 2,
        'singleSupplementPct' => 175,
    ],
    ];
}
