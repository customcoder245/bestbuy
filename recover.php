<?php
$BRIGHT_DATA_TOKEN = '46f7eeed-0e8d-412b-b611-12889bd0f003';
$snapshotId = 'sd_muo0f5at1gwpux';

echo "Restoring from previous snapshot $snapshotId...\n";

$ch = curl_init('https://api.brightdata.com/datasets/v3/snapshot/' . $snapshotId . '?format=json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $BRIGHT_DATA_TOKEN,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("cURL error: " . curl_error($ch));
}
curl_close($ch);

$data = json_decode($response, true);
if (isset($data['data']) && is_array($data['data'])) {
    $data = $data['data'];
}

if (!is_array($data) || empty($data)) {
    die("Error: No data found in snapshot or invalid JSON.\n" . substr($response, 0, 500));
}

file_put_contents(__DIR__ . '/bestbuy-products.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Restored " . count($data) . " products to bestbuy-products.json successfully!\n";
