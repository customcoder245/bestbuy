<?php 


$snapshotId="sd_muo0b71v1pr95czirf";
$BRIGHT_DATA_TOKEN = '46f7eeed-0e8d-412b-b611-12889bd0f003';

echo "\nDownloading product data...\n";

$snapshotUrl =
    'https://api.brightdata.com/datasets/v3/snapshot/' .
    urlencode($snapshotId) .
    '?format=json';

$products = curlRequest(
    $snapshotUrl,
    'GET',
    $BRIGHT_DATA_TOKEN
);



function curlRequest(
    string $url,
    string $method,
    string $token,
    ?array $body = null
): array {

    $ch = curl_init();

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ];

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers
    ];

    if ($method === 'POST') {

        $headers[] = 'Content-Type: application/json';

        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        die("cURL ERROR: {$error}\n");
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {

        die(
            "Bright Data returned invalid JSON.\n" .
            "HTTP: {$httpCode}\n" .
            "Response:\n{$response}\n"
        );
    }

    if ($httpCode >= 400) {

        echo "Bright Data API error:\n";
        print_r($data);

        exit;
    }

    return $data;
}





// ---------------------------------------------------------
// NORMALIZE RESPONSE
//
// Depending on the Bright Data scraper, the result may be:
//   [
//       {...},
//       {...}
//   ]
//
// or:
//   {
//       "data": [...]
//   }
// ---------------------------------------------------------
//print_r($products);
if (isset($products['data']) && is_array($products['data'])) {

    $products = $products['data'];
}

if (!is_array($products)) {

    die("ERROR: Product data is not an array.\n");
}
echo "<pre>";
print_r($products);

echo "</pre>";