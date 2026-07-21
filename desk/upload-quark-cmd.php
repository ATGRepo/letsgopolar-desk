<?php
/**
 * upload-quark-cmd.php
 * Receives the Quark Closed Market Pricing Grid (.xlsx, multipart field "file"),
 * parses the "Pricing" worksheet server-side (no external libraries), and saves a
 * normalized JSON as quark-cmd.json next to rate-data.php. rate-data.php then
 * matches each departure to the Quark LGP Links and emits the quarkCMD deal slice.
 *
 * Pricing sheet columns (row with "Ship","Season",...): Ship, Season, Itinerary Name,
 * Departure Date, Cruise Nights, Cabin Category, Currency, List Price,
 * Transfer Package, Discount, Gross Rate, Non Commisionable Amount.
 * We keep USD rows only. "was" = List Price + Transfer Package; "now" = Gross Rate.
 *
 * Place this file in the SAME folder as rate-data.php (the desk folder).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST with a multipart file field named "file".', 405);
if (!isset($_FILES['file'])) fail('No file received. The upload field must be named "file".');
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) fail('Upload failed (PHP upload error code ' . $f['error'] . ').');
if ($f['size'] > 25 * 1024 * 1024) fail('File too large (max 25 MB).');

if (!class_exists('ZipArchive')) fail('Server is missing the Zip extension needed to read .xlsx.', 500);

$tmp = $f['tmp_name'];
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) fail('Could not open the file as an .xlsx (zip) archive.');

// --- Read workbook to find the "Pricing" sheet target ---
$wb = $zip->getFromName('xl/workbook.xml');
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
if ($wb === false || $rels === false) { $zip->close(); fail('Not a valid .xlsx (missing workbook parts).'); }

// sheet name -> r:id
$sheetRid = null;
if (preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"[^>]*\br:id="([^"]*)"/i', $wb, $m, PREG_SET_ORDER)) {
    foreach ($m as $s) {
        if (strcasecmp(trim($s[1]), 'Pricing') === 0) { $sheetRid = $s[2]; break; }
    }
}
if ($sheetRid === null) { $zip->close(); fail('No worksheet named "Pricing" found in the workbook.'); }

// r:id -> target file
$target = null;
if (preg_match_all('/<Relationship\b[^>]*\bId="([^"]*)"[^>]*\bTarget="([^"]*)"/i', $rels, $rm, PREG_SET_ORDER)) {
    foreach ($rm as $r) {
        if ($r[1] === $sheetRid) { $target = $r[2]; break; }
    }
}
if ($target === null) { $zip->close(); fail('Could not resolve the Pricing worksheet target.'); }
$sheetPath = 'xl/' . ltrim($target, '/');

$sheetXml = $zip->getFromName($sheetPath);
if ($sheetXml === false) { $zip->close(); fail('Could not read the Pricing worksheet XML.'); }

// --- Shared strings ---
$shared = [];
$ss = $zip->getFromName('xl/sharedStrings.xml');
if ($ss !== false) {
    // Each <si> may contain multiple <t> (rich text runs); concatenate them.
    if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sm)) {
        foreach ($sm[1] as $si) {
            $t = '';
            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) $t = implode('', $tm[1]);
            $shared[] = xlsx_unescape_($t);
        }
    }
}
$zip->close();

// --- Parse rows into a grid of [rowNum => [colLetter => value]] ---
$rows = [];
if (preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*>(.*?)<\/row>/s', $sheetXml, $rm, PREG_SET_ORDER)) {
    foreach ($rm as $r) {
        $rnum = (int)$r[1];
        $cells = [];
        if (preg_match_all('/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/s', $r[2], $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $attrs = $c[1];
                $inner = isset($c[2]) ? $c[2] : '';
                if (!preg_match('/\br="([A-Z]+)\d+"/', $attrs, $am)) continue;
                $col = $am[1];
                $type = '';
                if (preg_match('/\bt="([^"]*)"/', $attrs, $tm)) $type = $tm[1];
                $val = null;
                if ($type === 'inlineStr') {
                    if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $im)) $val = xlsx_unescape_($im[1]);
                } else {
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                        $raw = $vm[1];
                        if ($type === 's') { $idx = (int)$raw; $val = $shared[$idx] ?? ''; }
                        else { $val = $raw; } // numeric or date-serial or boolean
                    }
                }
                if ($val !== null) $cells[$col] = $val;
            }
        }
        $rows[$rnum] = $cells;
    }
}

// --- Find the header row (contains "Ship" and "Departure Date") and map columns ---
$headerRow = null; $colMap = [];
foreach ($rows as $rnum => $cells) {
    $vals = array_map(function($v){ return strtolower(trim((string)$v)); }, $cells);
    if (in_array('ship', $vals, true) && in_array('departure date', $vals, true)) {
        $headerRow = $rnum;
        foreach ($cells as $col => $v) { $colMap[strtolower(trim((string)$v))] = $col; }
        break;
    }
}
if ($headerRow === null) fail('Could not find the Pricing header row (needs "Ship" and "Departure Date").');

function cmd_need_($colMap, $name) { return $colMap[$name] ?? null; }
$cShip = cmd_need_($colMap, 'ship');
$cSeason = cmd_need_($colMap, 'season');
$cItin = cmd_need_($colMap, 'itinerary name');
$cDate = cmd_need_($colMap, 'departure date');
$cNights = cmd_need_($colMap, 'cruise nights');
$cCabin = cmd_need_($colMap, 'cabin category');
$cCur = cmd_need_($colMap, 'currency');
$cList = cmd_need_($colMap, 'list price');
$cTransfer = cmd_need_($colMap, 'transfer package');
$cDisc = cmd_need_($colMap, 'discount');
$cGross = cmd_need_($colMap, 'gross rate');

// --- Collect USD rows, group into departures keyed by ship|date|itinerary ---
$departures = [];
$rowCount = 0;
foreach ($rows as $rnum => $cells) {
    if ($rnum <= $headerRow) continue;
    $ship = isset($cells[$cShip]) ? trim((string)$cells[$cShip]) : '';
    if ($ship === '') continue;
    $cur = ($cCur && isset($cells[$cCur])) ? strtoupper(trim((string)$cells[$cCur])) : '';
    if ($cur !== 'USD') continue;

    $dateSerial = ($cDate && isset($cells[$cDate])) ? $cells[$cDate] : '';
    $dateIso = xlsx_date_iso_($dateSerial);
    $itin = ($cItin && isset($cells[$cItin])) ? trim((string)$cells[$cItin]) : '';
    $season = ($cSeason && isset($cells[$cSeason])) ? trim((string)$cells[$cSeason]) : '';
    $nights = ($cNights && isset($cells[$cNights])) ? trim((string)$cells[$cNights]) : '';
    $cabin = ($cCabin && isset($cells[$cCabin])) ? trim((string)$cells[$cCabin]) : '';

    $list = xlsx_num_(($cList && isset($cells[$cList])) ? $cells[$cList] : null);
    $transfer = xlsx_num_(($cTransfer && isset($cells[$cTransfer])) ? $cells[$cTransfer] : null);
    $disc = xlsx_num_(($cDisc && isset($cells[$cDisc])) ? $cells[$cDisc] : null);
    $gross = xlsx_num_(($cGross && isset($cells[$cGross])) ? $cells[$cGross] : null);

    $key = $ship . '|' . $dateIso . '|' . $itin;
    if (!isset($departures[$key])) {
        $departures[$key] = [
            'ship' => $ship, 'date' => $dateIso, 'itinerary' => $itin,
            'season' => $season, 'nights' => $nights, 'cabins' => [],
        ];
    }
    // "was" = list + transfer (full fare); "now" = gross rate (deal fare).
    $was = ($list !== null) ? ($list + ($transfer ?? 0)) : null;
    $now = ($gross !== null) ? $gross : $was;
    $departures[$key]['cabins'][] = [
        'name' => $cabin,
        'full' => $was,
        'disc' => $now,
        'discountPct' => ($disc !== null ? round($disc * 100) : null),
    ];
    $rowCount++;
}

$out = ['generated' => gmdate('c'), 'departures' => array_values($departures)];
$json = json_encode($out);

$dest = __DIR__ . '/quark-cmd.json';
if (@file_put_contents($dest, $json) === false) {
    fail('Could not write quark-cmd.json. Check that the desk folder is writable by PHP.', 500);
}

echo json_encode([
    'ok'          => true,
    'saved_as'    => 'quark-cmd.json',
    'usd_rows'    => $rowCount,
    'departures'  => count($departures),
]);

// ---------- helpers ----------
function xlsx_unescape_($s) {
    return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function xlsx_num_($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    return null;
}
// Excel serial date (days since 1899-12-30) -> ISO yyyy-mm-dd. Passes through if already ISO.
function xlsx_date_iso_($v) {
    if ($v === null || $v === '') return '';
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (is_numeric($v)) {
        $serial = (int)floor((float)$v);
        $ts = ($serial - 25569) * 86400; // 25569 = days between 1899-12-30 and 1970-01-01
        return gmdate('Y-m-d', $ts);
    }
    return '';
}
