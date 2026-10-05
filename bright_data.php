<?php

/**
 * Bright Data → Best Buy → PHP
 *
 * Requirements:
 *   PHP 8+
 *
 * Before running:
 *   1. Put your Bright Data API token below.
 *   2. Put your Best Buy scraper Dataset ID below.
 *   3. Set INPUT_MODE to "keyword" or "url"
 *
 * This script:
 *   - starts a Bright Data collection
 *   - waits until the snapshot is ready
 *   - downloads the JSON results
 *   - saves the results to bestbuy-products.json
 *   - filters the requested brands
 */

declare(strict_types=1);
set_time_limit(6000);

// ---------------------------------------------------------
// CONFIGURATION
// ---------------------------------------------------------

$BRIGHT_DATA_TOKEN = '46f7eeed-0e8d-412b-b611-12889bd0f003';

$DATASET_ID = 'gd_ltre1jqe1jfr7cccf';


// Choose:
//   keyword = keyword-based Best Buy scraper
//   url     = URL-based Best Buy scraper
$INPUT_MODE = 'keyword';


// ---------------------------------------------------------
// BRANDS / CATEGORIES YOU WANT
// ---------------------------------------------------------

$keywords = [];

$brands = [
    'Apple',
    'Dell',
    'Lenovo',
    'MSI',
    'ASUS',
    'Acer',
    'HP'
];

$categories = [
    'laptop',
    'tablet'
];


foreach ($brands as $brand) {

    foreach ($categories as $category) {

        $keywords[] = [
            'keywords' => $brand . ' ' . $category
        ];

    }

}






// ---------------------------------------------------------
// URL INPUT
//
// Only use this if your Bright Data scraper expects "url".
// Replace these with actual Best Buy product URLs.
// ---------------------------------------------------------


$urls = [
    [
        'url' => 'https://www.bestbuy.com/site/searchpage.jsp?id=pcat17071&st=Apple+laptops'
    ]
];



// ---------------------------------------------------------
// SELECT SCRAPER INPUT
// ---------------------------------------------------------

if ($INPUT_MODE === 'keyword') {

    $input = $keywords;

} else {

    $input = $urls;

}


// ---------------------------------------------------------
// BASIC VALIDATION
// ---------------------------------------------------------

if (
    empty($BRIGHT_DATA_TOKEN) ||
    $BRIGHT_DATA_TOKEN === 'YOUR_BRIGHT_DATA_API_TOKEN'
) {
    die("ERROR: Add your Bright Data API token.\n");
}

if (
    empty($DATASET_ID) ||
    $DATASET_ID === 'YOUR_BEST_BUY_DATASET_ID'
) {
    die("ERROR: Add your Bright Data Dataset ID.\n");
}

if (empty($input)) {
    die("ERROR: No scraper input configured.\n");
}


// ---------------------------------------------------------
// FUNCTION: CURL REQUEST
// ---------------------------------------------------------

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
// STEP 1: START BRIGHT DATA COLLECTION
// ---------------------------------------------------------

echo "Starting Bright Data collection...\n";


	
$headers = [
    'Authorization: Bearer '.$BRIGHT_DATA_TOKEN,
    'Content-Type: application/json',
];


$data = json_encode([
    'input' => $keywords,
    'limit_per_input' => null
]);	
	
	
	$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'https://api.brightdata.com/datasets/v3/scrape?dataset_id='.$DATASET_ID.'&notify=false&include_errors=true&type=discover_new&discover_by=keywords');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);
$snapshotId ='';
if ($error) {
    echo "Request error: " . $error . PHP_EOL;
} else {
    if ($httpCode >= 200 && $httpCode < 300) {
        $jsonResponse = json_decode($response, true);
      
		$snapshotId =
    $jsonResponse['snapshot_id']
    ?? $jsonResponse['snapshotId']
    ?? null;
    } else {
        echo "Error: HTTP " . $httpCode . PHP_EOL;
        echo $response . PHP_EOL;
    }
}





// ---------------------------------------------------------
// GET SNAPSHOT ID
// ---------------------------------------------------------



if (!$snapshotId) {

    die(
        "ERROR: Bright Data did not return a snapshot ID.\n"
    );
}

echo "\nSnapshot ID: {$snapshotId}\n";


// ---------------------------------------------------------
// STEP 2: WAIT FOR COLLECTION
// ---------------------------------------------------------

echo "\nWaiting for Bright Data collection...\n";

$maxAttempts = 160;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

    sleep(10);

    $progressUrl =
        'https://api.brightdata.com/datasets/v3/progress/' .
        urlencode($snapshotId);

    $progress = curlRequest(
        $progressUrl,
        'GET',
        $BRIGHT_DATA_TOKEN
    );

    $status = $progress['status'] ?? 'unknown';

    echo "Attempt {$attempt}: {$status}\n";

    if ($status === 'ready') {

        echo "Collection is ready.\n";
        break;
    }

    if (
        $status === 'failed' ||
        $status === 'error'
    ) {

        echo "Bright Data collection failed:\n";
        print_r($progress);

        exit;
    }

    if ($attempt === $maxAttempts) {

        /*die(
            "Timed out waiting for Bright Data.\n"
        ); */
    }
}


// ---------------------------------------------------------
// STEP 3: DOWNLOAD RESULTS
// ---------------------------------------------------------

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

if (isset($products['data']) && is_array($products['data'])) {

    $products = $products['data'];
}

if (!is_array($products)) {

    die("ERROR: Product data is not an array.\n");
}


// ---------------------------------------------------------
// SAVE RAW DATA
// ---------------------------------------------------------

file_put_contents(
    __DIR__ . '/bestbuy-products.json',
    json_encode(
        $products,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )
);

echo "\nRaw data saved to:\n";
echo __DIR__ . "/bestbuy-products.json\n";


// ---------------------------------------------------------
// FILTER PRODUCTS
// ---------------------------------------------------------

$allowedBrands = array_map(
    'strtolower',
    $brands
);

$filteredProducts = [];

foreach ($products as $product) {

    if (!is_array($product)) {
        continue;
    }

    // Different Bright Data scrapers can use different
    // field names, so check common possibilities.

    $brand =
        $product['brand']
        ?? $product['manufacturer']
        ?? $product['vendor']
        ?? '';

    $brandLower = strtolower(trim((string)$brand));

    $brandMatched = false;

    foreach ($allowedBrands as $allowedBrand) {

        if (
            $brandLower === $allowedBrand ||
            str_contains($brandLower, $allowedBrand)
        ) {

            $brandMatched = true;
            break;
        }
    }

    if (!$brandMatched) {
        continue;
    }

    $filteredProducts[] = $product;
}


// ---------------------------------------------------------
// REMOVE DUPLICATES
//
// Prefer product ID / SKU when available.
// ---------------------------------------------------------

$uniqueProducts = [];

foreach ($filteredProducts as $product) {

    $productId =
        $product['product_id']
        ?? $product['sku']
        ?? $product['id']
        ?? $product['url']
        ?? md5(json_encode($product));

    $uniqueProducts[(string)$productId] = $product;
}

$filteredProducts = array_values($uniqueProducts);


// ---------------------------------------------------------
// SAVE FILTERED DATA
// ---------------------------------------------------------

file_put_contents(
    __DIR__ . '/bestbuy-filtered-products.json',
    json_encode(
        $filteredProducts,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )
);


// ---------------------------------------------------------
// OUTPUT SUMMARY
// ---------------------------------------------------------

echo "\n========================================\n";
echo "COLLECTION COMPLETE\n";
echo "========================================\n";

echo "Raw products:      " . count($products) . "\n";
echo "Filtered products: " . count($filteredProducts) . "\n";

echo "\nSaved:\n";
echo "bestbuy-products.json\n";
echo "bestbuy-filtered-products.json\n";


// ---------------------------------------------------------
// SHOW FIRST 5 PRODUCTS
// ---------------------------------------------------------

echo "\nFirst products:\n\n";

foreach (array_slice($filteredProducts, 0, 5) as $product) {

    echo "----------------------------------------\n";

    echo "Title: ";
    echo $product['title']
        ?? $product['name']
        ?? 'N/A';

    echo "\n";

    echo "Brand: ";
    echo $product['brand']
        ?? $product['manufacturer']
        ?? 'N/A';

    echo "\n";

    echo "SKU/Product ID: ";
    echo $product['product_id']
        ?? $product['sku']
        ?? 'N/A';

    echo "\n";

    echo "Price: ";
    echo $product['final_price']
        ?? $product['price']
        ?? 'N/A';

    echo "\n";

    echo "URL: ";
    echo $product['url']
        ?? 'N/A';

    echo "\n";
}

echo "\nDone.\n";

