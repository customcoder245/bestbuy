<?php
$BRIGHT_DATA_TOKEN = '46f7eeed-0e8d-412b-b611-12889bd0f003';

$DATASET_ID = 'gd_ltre1jqe1jfr7cccf';
$headers = [
    'Authorization: Bearer '.$BRIGHT_DATA_TOKEN,
    'Content-Type: application/json',
];

$data = json_encode([
    'input' => [
        [
            'keywords' => 'Apple laptop'
        ],
        [
            'keywords' => 'Apple tablet'
        ]
    ],
    'limit_per_input' => null
]);

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'https://api.brightdata.com/datasets/v3/scrape?dataset_id=gd_ltre1jqe1jfr7cccf&notify=false&include_errors=true&type=discover_new&discover_by=keywords');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

if ($error) {
    echo "Request error: " . $error . PHP_EOL;
} else {
    if ($httpCode >= 200 && $httpCode < 300) {
        $jsonResponse = json_decode($response, true);
        print_r($jsonResponse);
    } else {
        echo "Error: HTTP " . $httpCode . PHP_EOL;
        echo $response . PHP_EOL;
    }
}

?>