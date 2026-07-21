<?php
/**
 * upload-swan-trips.php
 * Receives the Swan trips JSON (multipart field "file") and saves it
 * next to rate-data.php as swan-trips.json. rate-data.php then processes it,
 * merges with the uploaded Quark LGP Links CSV, and appends the trips to the feed.
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
if ($f['size'] > 50 * 1024 * 1024) {
    fail('File too large (max 50 MB).');
}

$content = file_get_contents($f['tmp_name']);
if ($content === false || $content === '') {
    fail('Could not read the uploaded file, or it was empty.');
}
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

// Validate it parses as JSON and looks like the detail feed (list of departures with cabins).
$data = json_decode($content, true);
if (!is_array($data)) {
    fail('File is not valid JSON, or not the expected departure-detail structure.');
}
// Accept either a list or an object-of-departures.
$list = array_is_list($data) ? $data : array_values($data);
if (empty($list) || !isset($list[0]['id']) || !isset($list[0]['rooms'])) {
    fail('JSON parsed but does not look like slim Swan trips (no id/rooms found).');
}
$withRooms = 0;
foreach ($list as $row) {
    if (isset($row['rooms']) && is_array($row['rooms']) && count($row['rooms'])) $withRooms++;
}

$dest = __DIR__ . '/swan-trips.json';
if (@file_put_contents($dest, $content) === false) {
    fail('Could not write swan-trips.json. Check that the desk folder is writable by PHP.', 500);
}

echo json_encode([
    'ok'            => true,
    'saved_as'      => 'swan-trips.json',
    'trips'         => count($list),
    'with_rooms'    => $withRooms,
]);
