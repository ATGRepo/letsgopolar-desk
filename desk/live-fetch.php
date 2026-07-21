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
