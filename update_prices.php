<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);
ignore_user_abort(true); // Allow script to finish in background even after browser redirects

$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = getenv('SHOPIFY_API_VERSION') ?: '2025-01';

function shopifyGraphQL($query, $vars = []) {
    global $shopifyStore, $shopifyToken, $apiVersion;
    $store = str_replace(['https://','http://'], '', $shopifyStore);
    $url   = "https://{$store}/admin/api/{$apiVersion}/graphql.json";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $shopifyToken"
        ],
        CURLOPT_POSTFIELDS => json_encode(["query" => $query, "variables" => empty($vars) ? new stdClass() : $vars])
    ]);
    
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

$settingsFile = __DIR__ . '/settings.json';
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$taxFeePercent = (float)($settings['tax_fee'] ?? 10);
$shippingCost  = (float)($settings['shipping'] ?? 20);
$exchangeRate  = (float)($settings['exchange_rate'] ?? 3595);
$compareAtIncrement = (float)($settings['compare_at_increment'] ?? 100000);

$jsonFile = __DIR__ . '/bestbuy-products.json';
if (!file_exists($jsonFile)) die("ERROR: bestbuy-products.json not found.");
$jsonData = json_decode(file_get_contents($jsonFile), true);

// Map SKU -> Base USD Price from JSON
$skuToUsd = [];
foreach ($jsonData as $item) {
    if (isset($item['error']) || empty($item['title'])) continue;
    $sku = $item['product_id'] ?? $item['sku'] ?? null;
    $basePrice = $item['final_price'] ?? $item['price'] ?? 0;
    $baseUsdNum = (float) str_replace(['$', ','], '', $basePrice);
    if ($sku) {
        $skuToUsd[(string)$sku] = $baseUsdNum;
    }
}

// Fetch all products from Shopify
$cursor = null;
$updatedCount = 0;
do {
    $q = 'query getProd($after: String) { products(first: 50, after: $after) { edges { cursor node { id variants(first: 50) { edges { node { id sku } } } } } pageInfo { hasNextPage } } }';
    $resp = shopifyGraphQL($q, ['after' => $cursor]);
    $edges = $resp['data']['products']['edges'] ?? [];
    
    foreach ($edges as $e) {
        $productId = $e['node']['id'];
        $variants = $e['node']['variants']['edges'] ?? [];
        
        $variantsToUpdate = [];
        foreach ($variants as $v) {
            $vId = $v['node']['id'];
            $vSku = (string)$v['node']['sku'];
            
            // If the variant SKU exists in JSON or parent SKU exists
            $baseUsd = $skuToUsd[$vSku] ?? null;
            if ($baseUsd === null) {
                // Try parent product SKU by looking at the first variant (often they share it)
                $firstVarSku = (string)($variants[0]['node']['sku'] ?? '');
                $baseUsd = $skuToUsd[$firstVarSku] ?? null;
            }
            
            if ($baseUsd !== null) {
                // Calculate new price
                $taxAmount = $baseUsd * ($taxFeePercent / 100);
                $totalUsd  = $baseUsd + $taxAmount + $shippingCost;
                $priceMnt  = round($totalUsd * $exchangeRate);
                $compareAtPriceMnt = $priceMnt + $compareAtIncrement;
                
                $variantsToUpdate[] = [
                    'id' => $vId,
                    'price' => (string)$priceMnt,
                    'compareAtPrice' => (string)$compareAtPriceMnt
                ];
            }
        }
        
        if (!empty($variantsToUpdate)) {
            // Update prices via GraphQL
            $mut = 'mutation updatePrices($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                userErrors { message }
              }
            }';
            shopifyGraphQL($mut, ['productId' => $productId, 'variants' => $variantsToUpdate]);
            $updatedCount++;
        }
    }
    
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);

echo "Successfully updated prices for $updatedCount products based on new settings.";
