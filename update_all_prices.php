<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);

$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = "2025-01";

function shopifyGraphQL($query, $variables = []) {
    global $shopifyStore, $shopifyToken, $apiVersion;
    $store   = str_replace(['https://', 'http://'], '', $shopifyStore);
    $url     = "https://{$store}/admin/api/{$apiVersion}/graphql.json";
    $payload = ["query" => $query, "variables" => $variables];
    $headers = ["Content-Type: application/json", "X-Shopify-Access-Token: $shopifyToken"];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// 1. Fetch all existing variants and their SKUs
echo "Fetching variants...\n";
$variants = [];
$cursor = null;
do {
    $q = 'query { productVariants(first: 250, after: '.($cursor ? '"'.$cursor.'"' : 'null').') { edges { cursor node { id sku } } pageInfo { hasNextPage } } }';
    $resp = shopifyGraphQL($q);
    $edges = $resp['data']['productVariants']['edges'] ?? [];
    foreach ($edges as $e) {
        $sku = trim((string)$e['node']['sku']);
        if ($sku) {
            $variants[$sku] = $e['node']['id'];
        }
    }
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $resp['data']['productVariants']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);

echo "Found " . count($variants) . " variants.\n";

// 2. Load JSON data
$jsonFile = __DIR__ . '/bestbuy-products.json';
$jsonData = json_decode(file_get_contents($jsonFile), true);

$settingsFile  = __DIR__ . '/settings.json';
$settings      = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$taxFeePercent = (float)($settings['tax_fee'] ?? 10);
$shipping      = (float)($settings['shipping'] ?? 20);
$exchangeRate  = (float)($settings['exchange_rate'] ?? 3595);

$updates = [];

foreach ($jsonData as $product) {
    if (isset($product['error'])) continue;
    
    $sku = trim((string)($product['product_id'] ?? $product['sku'] ?? ''));
    if (empty($sku)) continue;
    
    $originalUsdNum = (float) str_replace(['$', ','], '', $product['initial_price'] ?? $product['price'] ?? '0');
    $currentUsdNum  = (float) str_replace(['$', ','], '', $product['final_price'] ?? $product['sale_price'] ?? '0');
    
    $currentTaxAmount = $currentUsdNum * ($taxFeePercent / 100);
    $currentTotalUsd  = $currentUsdNum + $currentTaxAmount + $shipping;
    $priceMnt         = round($currentTotalUsd * $exchangeRate);
    
    $originalTaxAmount = $originalUsdNum * ($taxFeePercent / 100);
    $originalTotalUsd  = $originalUsdNum + $originalTaxAmount + $shipping;
    $compareAtMnt      = round($originalTotalUsd * $exchangeRate);
    
    if ($compareAtMnt <= $priceMnt) {
        $compareAtMnt = $priceMnt;
    }
    
    // Check main sku
    if (isset($variants[$sku])) {
        $updates[] = [
            'id' => $variants[$sku],
            'price' => (string)$priceMnt,
            'compareAtPrice' => $compareAtMnt > $priceMnt ? (string)$compareAtMnt : null
        ];
    }
    
    // Check variant skus
    if (!empty($product['variations'])) {
        foreach ($product['variations'] as $v) {
            $vSku = $v['variant_sku'] ?? $sku;
            if (isset($variants[$vSku])) {
                $updates[] = [
                    'id' => $variants[$vSku],
                    'price' => (string)$priceMnt,
                    'compareAtPrice' => $compareAtMnt > $priceMnt ? (string)$compareAtMnt : null
                ];
            }
        }
    }
}

// Remove duplicates
$uniqueUpdates = [];
$seen = [];
foreach ($updates as $u) {
    if (!isset($seen[$u['id']])) {
        $seen[$u['id']] = true;
        $uniqueUpdates[] = $u;
    }
}

echo "Updating " . count($uniqueUpdates) . " variants...\n";

// Batch update
$chunks = array_chunk($uniqueUpdates, 20); // Bulk update takes up to 250 but we'll do 20 per call for safety and rate limits
foreach ($chunks as $i => $chunk) {
    // We have to update variants individually or use productVariantsBulkUpdate if we know product ID, but here we only have variant ID.
    // Actually, we can just use productVariantUpdate for each.
    foreach ($chunk as $v) {
        $mut = 'mutation($input: ProductVariantInput!) { productVariantUpdate(input: $input) { userErrors { message } } }';
        shopifyGraphQL($mut, ['input' => $v]);
    }
    echo "Batch " . ($i + 1) . "/" . count($chunks) . " done.\n";
    usleep(500000); // 0.5s pause
}

echo "Done.\n";

