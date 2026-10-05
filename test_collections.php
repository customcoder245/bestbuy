<?php
require_once __DIR__ . '/config.php';
$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = getenv('SHOPIFY_API_VERSION') ?: '2025-01';

function shopifyGQL($query, $vars = []) {
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
        CURLOPT_POSTFIELDS => json_encode(["query" => $query, "variables" => empty($vars) ? new stdClass() : $vars]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

// Check if collectionsToJoin works
$test = shopifyGQL('
mutation {
  productCreate(input: {
    title: "Test Collection Product",
    collectionsToJoin: ["gid://shopify/Collection/123456789"]
  }) {
    userErrors { field message }
  }
}
');
print_r($test);
