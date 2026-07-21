<?php
/**
 * upload-quark-links.php
 * Receives an LGP Links CSV (multipart field "file") and saves it next to
 * rate-data.php as quark-lgp-links.csv, so the proxy reads links from a local file
 * instead of fetching the Google published CSV.
 *
 * Place this file in the SAME folder as rate-data.php (the desk folder).
 * It only accepts CSVs whose header contains operator_id and lgp_links.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Use POST with a multipart file field named "file".', 405);
}
if (!isset($_FILES['file'])) {
    fail('No file received. The upload field must be named "file".');
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    fail('Upload failed (PHP upload error code ' . $f['error'] . ').');
}
// Cap at 2 MB; the links CSV is a few KB.
if ($f['size'] > 2 * 1024 * 1024) {
    fail('File too large (max 2 MB).');
}

$content = file_get_contents($f['tmp_name']);
if ($content === false || $content === '') {
    fail('Could not read the uploaded file, or it was empty.');
}

// Strip a UTF-8 BOM if present.
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

// Validate the header so we do not overwrite the good file with the wrong shape.
$firstLine = strtok($content, "\r\n");
if ($firstLine === false) {
    fail('The file has no header row.');
}
$header = array_map(function ($h) { return strtolower(trim($h)); }, str_getcsv($firstLine));
if (!in_array('operator_id', $header, true) || !in_array('lgp_links', $header, true)) {
    fail('Header must include both "operator_id" and "lgp_links". Found: ' . implode(', ', $header));
}

// Count how many rows carry a non-empty link, for a helpful confirmation.
$ci_code = array_search('operator_id', $header, true);
$ci_link = array_search('lgp_links', $header, true);
$rows = preg_split('/\r\n|\n|\r/', $content);
$withLinks = 0; $totalRows = 0;
foreach ($rows as $i => $line) {
    if ($i === 0 || $line === '') continue;
    $cols = str_getcsv($line);
    $totalRows++;
    if (isset($cols[$ci_link]) && trim($cols[$ci_link]) !== '') $withLinks++;
}

$dest = __DIR__ . '/quark-lgp-links.csv';
if (@file_put_contents($dest, $content) === false) {
    fail('Could not write quark-lgp-links.csv. Check that the desk folder is writable by PHP.', 500);
}

echo json_encode([
    'ok'          => true,
    'saved_as'    => 'quark-lgp-links.csv',
    'rows'        => $totalRows,
    'with_links'  => $withLinks,
]);
