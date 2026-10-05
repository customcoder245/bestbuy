<?php
require_once __DIR__ . '/config.php';
$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');

function gql($query) {
    global $shopifyStore, $shopifyToken;
    $store = str_replace(['https://','http://'], '', $shopifyStore);
    $ch = curl_init("https://{$store}/admin/api/2025-01/graphql.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $shopifyToken"
        ],
        CURLOPT_POSTFIELDS => json_encode(["query" => $query])
    ]);
    return json_decode(curl_exec($ch), true);
}

$resp = gql('query { productsCount { count } }');
echo "Total products in Shopify: " . $resp['data']['productsCount']['count'] . "\n";

