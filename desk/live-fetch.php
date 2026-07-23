<?php
/**
 * Live operator API fetchers for the Let's Go Polar dashboard.
 *
 * Regenerates the same data files the manual upload flow used to receive
 * (quark-detail.json, later aurora-services.json / swan-trips.json), so the
 * existing merges in rate-data.php consume them unchanged.
 *
 * Runs as plain PHP (outside WordPress), so it uses its own curl client, not
 * WordPress HTTP functions. Credentials load from the server-only config that
 * lives outside the web root.
 */

if (!defined('LGP_APP')) define('LGP_APP', 1);

if (!defined('LGP_CONFIG_PATH')) {
    define('LGP_CONFIG_PATH', '/home/u886488648/domains/letsgopolar.com/lgp-private/operator-config.php');
}

/** Load the operator credentials/config once. */
function lgp_cfg() {
    static $c = null;
    if ($c === null) {
        $c = is_readable(LGP_CONFIG_PATH) ? (require LGP_CONFIG_PATH) : [];
    }
    return is_array($c) ? $c : [];
}

/** PHP < 8.1 compatibility for array_is_list. */
function lgp_is_list($arr) {
    if (!is_array($arr)) return false;
    if (function_exists('array_is_list')) return array_is_list($arr);
    if ($arr === []) return true;
    return array_keys($arr) === range(0, count($arr) - 1);
}

/**
 * Minimal curl client. Returns [body|null, status_int, error|null].
 * $opts: method, headers (assoc), body (string|array), timeout.
 */
function lgp_http($url, array $opts = []) {
    if (!function_exists('curl_init')) return [null, 0, 'curl_unavailable'];
    $method  = strtoupper($opts['method'] ?? 'GET');
    $headers = $opts['headers'] ?? [];
    $body    = $opts['body'] ?? null;
    $timeout = (int) ($opts['timeout'] ?? 40);

    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;

    $co = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'LGP-RateDesk-LiveFetch',
        CURLOPT_HTTPHEADER     => $h,
    ];
    if ($method === 'POST') {
        $co[CURLOPT_POST] = true;
        $co[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    } elseif ($method !== 'GET') {
        $co[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) $co[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $co);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = ($resp === false) ? curl_error($ch) : null;
    curl_close($ch);
    return [($resp === false ? null : $resp), $code, $err];
}

/** GET + JSON decode. Returns [decoded|null, status, error|null]. */
function lgp_json($url, array $opts = []) {
    list($body, $code, $err) = lgp_http($url, $opts);
    if ($err !== null) return [null, $code, $err];
    if ($code >= 400) return [null, $code, "http_$code"];
    $j = json_decode((string) $body, true);
    if ($j === null && trim((string) $body) !== 'null') return [null, $code, 'json_parse_failed'];
    return [$j, $code, null];
}

/**
 * Quark refresh: pull the departure index, fetch each departure's detail,
 * write the trimmed array to quark-detail.json in $destDir.
 * Drop-in replacement for the manually uploaded file. Returns a diag array.
 */
function lgp_quark_refresh($destDir) {
    $t0   = microtime(true);
    $cfg  = lgp_cfg();
    $base = $cfg['quark']['base'] ?? '';
    $diag = ['operator' => 'quark', 'ok' => false, 'index_count' => 0,
             'written' => 0, 'fetch_failures' => 0, 'errors' => [], 'secs' => 0];

    if ($base === '') { $diag['errors'][] = 'no_base_url'; return $diag; }

    list($idx, $code, $err) = lgp_json($base . '/departure', ['timeout' => 60]);
    if ($err !== null || !is_array($idx)) { $diag['errors'][] = "index_failed:$code:$err"; return $diag; }
    if (!lgp_is_list($idx)) $idx = array_values($idx);
    $diag['index_count'] = count($idx);

    $keep = ['id', 'departure_id', 'departure_name', 'ship_name', 'start_date', 'end_date',
             'duration_days', 'start_location', 'end_location', 'url', 'cabins', 'options'];

    $recs = [];
    foreach ($idx as $it) {
        $id = $it['id'] ?? null;
        if ($id === null || $id === '') continue;
        list($d, $dc, $de) = lgp_json($base . '/departure/' . rawurlencode($id), ['timeout' => 40]);
        if ($de !== null || !is_array($d)) { $diag['fetch_failures']++; continue; }
        if (lgp_is_list($d)) $d = $d[0] ?? null;
        if (!is_array($d)) { $diag['fetch_failures']++; continue; }
        $rec = [];
        foreach ($keep as $k) if (array_key_exists($k, $d)) $rec[$k] = $d[$k];
        if (isset($rec['departure_id']) && $rec['departure_id'] !== '') $recs[] = $rec;
    }

    if (count($recs) === 0) { $diag['errors'][] = 'no_records_assembled'; return $diag; }

    // Atomic write: temp file then rename, so a partial run never corrupts the live file.
    $target = rtrim($destDir, '/') . '/quark-detail.json';
    $tmp    = $target . '.tmp';
    $json   = json_encode($recs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json) === false) { $diag['errors'][] = 'write_failed'; return $diag; }
    @rename($tmp, $target);

    $diag['written'] = count($recs);
    $diag['ok']      = true;
    $diag['secs']    = round(microtime(true) - $t0, 1);
    return $diag;
}

/** Lowest USD price_per_person across a Quark record's cabins (null if none). */
function lgp_quark_min_price($e) {
    $m = null;
    foreach (($e['cabins'] ?? []) as $c) {
        foreach (($c['occupancies'] ?? []) as $o) {
            $p = $o['prices']['USD']['price_per_person'] ?? null;
            if ($p !== null && ($m === null || $p < $m)) $m = $p;
        }
    }
    return $m;
}

/** Index a Quark record list by departure_id. */
function lgp_quark_index($list) {
    $out = [];
    if (!is_array($list)) return $out;
    if (!lgp_is_list($list)) $list = array_values($list);
    foreach ($list as $e) { if (!empty($e['departure_id'])) $out[$e['departure_id']] = $e; }
    return $out;
}

/** Compare two Quark record lists. Returns removed / added / price_changes. */
function lgp_quark_diff($old, $new) {
    $oi = lgp_quark_index($old);
    $ni = lgp_quark_index($new);
    $removed = $added = $changes = [];
    foreach ($oi as $id => $e) if (!isset($ni[$id])) {
        $removed[] = ['departure_id'=>$id, 'ship'=>$e['ship_name']??'', 'start'=>$e['start_date']??'', 'old_from'=>lgp_quark_min_price($e)];
    }
    foreach ($ni as $id => $e) {
        if (!isset($oi[$id])) {
            $added[] = ['departure_id'=>$id, 'ship'=>$e['ship_name']??'', 'start'=>$e['start_date']??'', 'new_from'=>lgp_quark_min_price($e)];
            continue;
        }
        $po = lgp_quark_min_price($oi[$id]); $pn = lgp_quark_min_price($e);
        if ($po !== null && $pn !== null && $po != $pn) {
            $changes[] = ['departure_id'=>$id, 'ship'=>$e['ship_name']??'', 'start'=>$e['start_date']??'', 'old_from'=>$po, 'new_from'=>$pn, 'delta'=>$pn - $po];
        }
    }
    usort($changes, function($a, $b) { return abs($b['delta']) <=> abs($a['delta']); });
    return ['removed'=>$removed, 'added'=>$added, 'price_changes'=>$changes];
}

/** Path to the Quark refresh meta/report file. */
function lgp_quark_meta_path($dir) { return rtrim($dir, '/') . '/quark-refresh-meta.json'; }

/** Current status: last pull time and last report, without pulling. */
function lgp_quark_status($dir) {
    $p = lgp_quark_meta_path($dir);
    if (is_readable($p)) { $m = json_decode(file_get_contents($p), true); if (is_array($m)) return $m; }
    $f = rtrim($dir, '/') . '/quark-detail.json';
    $mt = is_readable($f) ? @filemtime($f) : null;
    return ['last_pull' => $mt ? gmdate('c', $mt) : null, 'report' => null];
}

/**
 * Manual Quark refresh with a before/after report. Backs up the old file,
 * pulls live, diffs, persists a meta file, and returns the report.
 */
function lgp_quark_refresh_report($dir) {
    $file     = rtrim($dir, '/') . '/quark-detail.json';
    $status   = lgp_quark_status($dir);
    $prevPull = $status['last_pull'] ?? (is_readable($file) ? gmdate('c', @filemtime($file)) : null);
    $old      = is_readable($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($old)) $old = [];

    if (is_readable($file)) @copy($file, $file . '.bak-' . gmdate('Ymd-His'));

    $diag = lgp_quark_refresh($dir);
    if (empty($diag['ok'])) return ['ok' => false, 'operator' => 'quark', 'diag' => $diag];

    $new = json_decode(file_get_contents($file), true);
    if (!is_array($new)) $new = [];
    $diff = lgp_quark_diff($old, $new);

    $report = [
        'ok'             => true,
        'operator'       => 'quark',
        'prev_pull'      => $prevPull,
        'this_pull'      => gmdate('c'),
        'old_count'      => count($old),
        'new_count'      => count($new),
        'removed'        => $diff['removed'],
        'added'          => $diff['added'],
        'price_changes'  => $diff['price_changes'],
        'fetch_failures' => $diag['fetch_failures'] ?? 0,
        'secs'           => $diag['secs'] ?? null,
    ];
    @file_put_contents(
        lgp_quark_meta_path($dir),
        json_encode(['last_pull' => $report['this_pull'], 'prev_pull' => $prevPull, 'report' => $report], JSON_UNESCAPED_SLASHES)
    );
    return $report;
}

/* ===================== Aurora Expeditions ===================== */

/** OAuth2 client-credentials token for Aurora (cached per request). Returns token|null. */
function lgp_aurora_token() {
    static $tok = false; // false = not yet fetched
    if ($tok !== false) return $tok;
    $a = lgp_cfg()['aurora'] ?? [];
    if (empty($a['token_url']) || empty($a['client_id']) || empty($a['client_secret'])) { $tok = null; return null; }
    $basic = base64_encode($a['client_id'] . ':' . $a['client_secret']);
    list($body, $code, $err) = lgp_http($a['token_url'], [
        'method'  => 'POST',
        'headers' => ['Authorization' => 'Basic ' . $basic, 'Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => ['grant_type' => 'client_credentials'],
        'timeout' => 30,
    ]);
    if ($err !== null || $code >= 400) { $tok = null; return null; }
    $j = json_decode((string) $body, true);
    $tok = is_array($j) ? ($j['access_token'] ?? $j['accessToken'] ?? $j['token'] ?? null) : null;
    return $tok;
}

/** Distinct operator_id values from aurora-lgp-links.csv (the LGP sheet). */
function lgp_aurora_oids($dir) {
    $p = rtrim($dir, '/') . '/aurora-lgp-links.csv';
    $out = [];
    if (!is_readable($p)) return $out;
    $c = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($p));
    $c = str_replace(["\r\n", "\r"], "\n", $c);
    $lines = explode("\n", $c);
    $hdr = array_map('trim', str_getcsv(array_shift($lines)));
    $ci = array_flip($hdr);
    if (!isset($ci['operator_id'])) return $out;
    foreach ($lines as $l) { if ($l === '') continue; $cols = str_getcsv($l); $id = trim($cols[$ci['operator_id']] ?? ''); if ($id !== '') $out[$id] = true; }
    return array_keys($out);
}

/** aurora-lgp-links.csv keyed by operator_id, whole row as assoc (for enrichment). */
function lgp_aurora_sheet_rows($dir) {
    $p = rtrim($dir, '/') . '/aurora-lgp-links.csv';
    $out = [];
    if (!is_readable($p)) return $out;
    $c = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($p));
    $c = str_replace(["\r\n", "\r"], "\n", $c);
    $lines = explode("\n", $c);
    $hdr = array_map('trim', str_getcsv(array_shift($lines)));
    foreach ($lines as $l) {
        if ($l === '') continue;
        $cols = str_getcsv($l);
        $row = [];
        foreach ($hdr as $i => $h) $row[$h] = $cols[$i] ?? '';
        $id = trim($row['operator_id'] ?? '');
        if ($id !== '') $out[$id] = $row;
    }
    return $out;
}

/** Lowest GrossPrice across an Aurora service data object's ServicePricing. */
function lgp_aurora_min_price($data) {
    $m = null;
    foreach (($data['ServicePricing'] ?? []) as $p) {
        $g = $p['GrossPrice'] ?? null;
        if ($g !== null && ($m === null || $g < $m)) $m = $g;
    }
    return $m;
}

/**
 * Services refresh (frequent): for each operator_id in the LGP sheet, pull its
 * live rates and write aurora-services.json as [{voyageCode, data}], the exact
 * shape merge_aurora consumes.
 */
function lgp_aurora_services_refresh($dir) {
    $t0 = microtime(true);
    $diag = ['operator' => 'aurora', 'mode' => 'services', 'ok' => false, 'oids' => 0,
             'written' => 0, 'fetch_failures' => 0, 'errors' => [], 'secs' => 0];
    $base = lgp_cfg()['aurora']['api_base'] ?? '';
    if ($base === '') { $diag['errors'][] = 'no_api_base'; return $diag; }
    $tok = lgp_aurora_token();
    if (!$tok) { $diag['errors'][] = 'token_failed'; return $diag; }
    $oids = lgp_aurora_oids($dir);
    $diag['oids'] = count($oids);
    if (!$oids) { $diag['errors'][] = 'no_operator_ids'; return $diag; }

    $entries = [];
    foreach ($oids as $id) {
        list($body, $code, $err) = lgp_json($base . '/service?voyage=' . rawurlencode($id) . '&currency=USD',
            ['timeout' => 40, 'headers' => ['Authorization' => 'Bearer ' . $tok]]);
        if ($err !== null || !is_array($body)) { $diag['fetch_failures']++; continue; }
        $entries[] = ['voyageCode' => $id, 'data' => $body];
    }
    if (!$entries) { $diag['errors'][] = 'no_services'; return $diag; }

    $target = rtrim($dir, '/') . '/aurora-services.json';
    $tmp = $target . '.tmp';
    if (@file_put_contents($tmp, json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) { $diag['errors'][] = 'write_failed'; return $diag; }
    @rename($tmp, $target);
    $diag['written'] = count($entries); $diag['ok'] = true; $diag['secs'] = round(microtime(true) - $t0, 1);
    return $diag;
}

function lgp_aurora_services_status($dir) {
    $p = rtrim($dir, '/') . '/aurora-services-meta.json';
    if (is_readable($p)) { $m = json_decode(file_get_contents($p), true); if (is_array($m)) return $m; }
    $f = rtrim($dir, '/') . '/aurora-services.json';
    $mt = is_readable($f) ? @filemtime($f) : null;
    return ['last_pull' => $mt ? gmdate('c', $mt) : null, 'report' => null];
}

/** Services refresh with a before/after rate report (mirrors the Quark report). */
function lgp_aurora_services_refresh_report($dir) {
    $file = rtrim($dir, '/') . '/aurora-services.json';
    $st = lgp_aurora_services_status($dir);
    $prev = $st['last_pull'] ?? (is_readable($file) ? gmdate('c', @filemtime($file)) : null);
    $old = is_readable($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($old)) $old = [];
    if (is_readable($file)) @copy($file, $file . '.bak-' . gmdate('Ymd-His'));

    $diag = lgp_aurora_services_refresh($dir);
    if (empty($diag['ok'])) return ['ok' => false, 'operator' => 'aurora', 'mode' => 'services', 'diag' => $diag];

    $new = json_decode(file_get_contents($file), true);
    if (!is_array($new)) $new = [];
    $oi = []; foreach ($old as $e) { if (!empty($e['voyageCode'])) $oi[$e['voyageCode']] = $e; }
    $ni = []; foreach ($new as $e) { if (!empty($e['voyageCode'])) $ni[$e['voyageCode']] = $e; }
    $removed = $added = $changes = [];
    foreach ($oi as $id => $e) if (!isset($ni[$id])) {
        $removed[] = ['voyage_code' => $id, 'ship' => $e['data']['Ship'] ?? '', 'start' => $e['data']['StartDate'] ?? '', 'old_from' => lgp_aurora_min_price($e['data'] ?? [])];
    }
    foreach ($ni as $id => $e) {
        $d = $e['data'] ?? [];
        if (!isset($oi[$id])) { $added[] = ['voyage_code' => $id, 'ship' => $d['Ship'] ?? '', 'start' => $d['StartDate'] ?? '', 'new_from' => lgp_aurora_min_price($d)]; continue; }
        $po = lgp_aurora_min_price($oi[$id]['data'] ?? []); $pn = lgp_aurora_min_price($d);
        if ($po !== null && $pn !== null && $po != $pn) $changes[] = ['voyage_code' => $id, 'ship' => $d['Ship'] ?? '', 'start' => $d['StartDate'] ?? '', 'old_from' => $po, 'new_from' => $pn, 'delta' => $pn - $po];
    }
    usort($changes, function ($a, $b) { return abs($b['delta']) <=> abs($a['delta']); });

    $report = ['ok' => true, 'operator' => 'aurora', 'mode' => 'services', 'prev_pull' => $prev, 'this_pull' => gmdate('c'),
               'old_count' => count($old), 'new_count' => count($new), 'oids' => $diag['oids'], 'fetch_failures' => $diag['fetch_failures'],
               'removed' => $removed, 'added' => $added, 'price_changes' => $changes, 'secs' => $diag['secs']];
    @file_put_contents(rtrim($dir, '/') . '/aurora-services-meta.json', json_encode(['last_pull' => $report['this_pull'], 'report' => $report], JSON_UNESCAPED_SLASHES));
    return $report;
}

/**
 * Packages refresh (rare, per season): page through the whole Aurora catalogue
 * (first=20, 1-based offset stepping by 20) and write aurora-packages.json.
 * Each package's Name is the operator_id used by Services and the LGP sheet.
 */
function lgp_aurora_packages_refresh($dir) {
    $t0 = microtime(true);
    $diag = ['operator' => 'aurora', 'mode' => 'packages', 'ok' => false, 'total' => null, 'pages' => 0, 'written' => 0, 'errors' => [], 'secs' => 0];
    $base = lgp_cfg()['aurora']['api_base'] ?? '';
    if ($base === '') { $diag['errors'][] = 'no_api_base'; return $diag; }
    $tok = lgp_aurora_token();
    if (!$tok) { $diag['errors'][] = 'token_failed'; return $diag; }

    $keep = ['Id', 'Name', 'ExternalName', 'TravelDate', 'Length', 'MainRegion', 'SubRegion', 'VoyageType', 'IsActive', 'StopSell', 'PackageStartLocation', 'PackageEndLocation'];
    $all = []; $offset = 1; $first = 20; $total = null; $guard = 0;
    while ($guard++ < 100) {
        list($body, $code, $err) = lgp_json($base . '/packages?first=' . $first . '&offset=' . $offset,
            ['timeout' => 60, 'headers' => ['Authorization' => 'Bearer ' . $tok]]);
        if ($err !== null || !is_array($body)) { $diag['errors'][] = "page_offset_{$offset}_failed:$code"; break; }
        if ($total === null) { $total = $body['TotalPackageCount'] ?? null; $diag['total'] = $total; }
        $pk = $body['data']['Package'] ?? [];
        if (!is_array($pk) || !$pk) break;
        foreach ($pk as $p) { $rec = []; foreach ($keep as $k) if (array_key_exists($k, $p)) $rec[$k] = $p[$k]; if (!empty($rec['Name'])) $all[$rec['Name']] = $rec; }
        $diag['pages']++;
        $offset += $first;
        if ($total !== null && ($offset - 1) >= $total) break;
    }
    $recs = array_values($all);
    if (!$recs) { $diag['errors'][] = 'no_packages'; return $diag; }

    $target = rtrim($dir, '/') . '/aurora-packages.json';
    $tmp = $target . '.tmp';
    if (@file_put_contents($tmp, json_encode($recs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) { $diag['errors'][] = 'write_failed'; return $diag; }
    @rename($tmp, $target);
    $diag['written'] = count($recs); $diag['ok'] = true; $diag['secs'] = round(microtime(true) - $t0, 1);
    return $diag;
}

function lgp_aurora_packages_status($dir) {
    $p = rtrim($dir, '/') . '/aurora-packages-meta.json';
    if (is_readable($p)) { $m = json_decode(file_get_contents($p), true); if (is_array($m)) return $m; }
    $f = rtrim($dir, '/') . '/aurora-packages.json';
    $mt = is_readable($f) ? @filemtime($f) : null;
    return ['last_pull' => $mt ? gmdate('c', $mt) : null, 'report' => null];
}

/**
 * Packages refresh + report: pulls the catalogue, then cross-references package
 * operator_ids against the LGP sheet so you can see which trips are new (need
 * adding to the sheet) and which sheet rows no longer appear in the catalogue.
 */
function lgp_aurora_packages_report($dir) {
    $diag = lgp_aurora_packages_refresh($dir);
    if (empty($diag['ok'])) return ['ok' => false, 'operator' => 'aurora', 'mode' => 'packages', 'diag' => $diag];

    $pkgs = json_decode(file_get_contents(rtrim($dir, '/') . '/aurora-packages.json'), true);
    if (!is_array($pkgs)) $pkgs = [];
    $sheet = array_flip(lgp_aurora_oids($dir));
    $new = [];
    $pkgNames = [];
    foreach ($pkgs as $p) {
        $n = $p['Name'] ?? '';
        if ($n === '') continue;
        $pkgNames[$n] = true;
        if (!isset($sheet[$n])) {
            $new[] = ['operator_id' => $n, 'name' => $p['ExternalName'] ?? '', 'travel_date' => $p['TravelDate'] ?? '',
                      'length' => $p['Length'] ?? '', 'region' => trim(($p['MainRegion'] ?? '') . ' / ' . ($p['SubRegion'] ?? ''), ' /'),
                      'active' => $p['IsActive'] ?? null, 'stop_sell' => $p['StopSell'] ?? null];
        }
    }
    // Enrich stale sheet rows (in the sheet, gone from the catalogue) with
    // itinerary name + departure date, from the pulled services then the sheet.
    $rows = lgp_aurora_sheet_rows($dir);
    $svc = [];
    $svcFile = rtrim($dir, '/') . '/aurora-services.json';
    if (is_readable($svcFile)) {
        $sj = json_decode(file_get_contents($svcFile), true);
        if (is_array($sj)) foreach ($sj as $e) { if (!empty($e['voyageCode'])) $svc[$e['voyageCode']] = $e['data'] ?? []; }
    }
    $stale = [];
    foreach (array_keys($sheet) as $oid) {
        if (isset($pkgNames[$oid])) continue;
        $d = $svc[$oid] ?? [];
        $r = $rows[$oid] ?? [];
        $stale[] = [
            'operator_id' => $oid,
            'name'        => $d['ExternalName'] ?? ($d['Name'] ?? ($r['destinations'] ?? '')),
            'start_date'  => $d['StartDate'] ?? ($r['start_date'] ?? ''),
        ];
    }

    $report = ['ok' => true, 'operator' => 'aurora', 'mode' => 'packages', 'this_pull' => gmdate('c'),
               'total' => $diag['total'], 'written' => $diag['written'], 'pages' => $diag['pages'],
               'in_sheet' => count($sheet), 'new_count' => count($new), 'new' => $new,
               'stale_in_sheet' => $stale, 'secs' => $diag['secs']];
    @file_put_contents(rtrim($dir, '/') . '/aurora-packages-meta.json', json_encode(['last_pull' => $report['this_pull'], 'report' => $report], JSON_UNESCAPED_SLASHES));
    return $report;
}
