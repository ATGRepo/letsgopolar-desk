<?php
/**
 * upload-aurora-services.php
 * Receives the Aurora services JSON (multipart field "file") and saves it
 * next to rate-data.php as aurora-services.json. rate-data.php then processes it,
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
if (empty($list) || !isset($list[0]['voyageCode']) || !isset($list[0]['data'])) {
    fail('JSON parsed but does not look like Aurora services (no voyageCode/data found).');
}
$visible = 0; $withPricing = 0;
foreach ($list as $row) {
    $dat = isset($row['data']) && is_array($row['data']) ? $row['data'] : null;
    if ($dat === null) continue;
    if (!empty($dat['hidden'])) continue;
    $visible++;
    if (isset($dat['ServicePricing']) && is_array($dat['ServicePricing']) && count($dat['ServicePricing'])) $withPricing++;
}

$dest = __DIR__ . '/aurora-services.json';
if (@file_put_contents($dest, $content) === false) {
    fail('Could not write aurora-services.json. Check that the desk folder is writable by PHP.', 500);
}

echo json_encode([
    'ok'            => true,
    'saved_as'      => 'aurora-services.json',
    'services'      => count($list),
    'visible'       => $visible,
    'with_pricing'  => $withPricing,
]);
