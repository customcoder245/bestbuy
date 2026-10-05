<?php
require_once __DIR__ . '/config.php';
$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');

function gql($query, $vars = []) {
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
        CURLOPT_POSTFIELDS => json_encode(["query" => $query, "variables" => empty($vars) ? new stdClass() : $vars])
    ]);
    return json_decode(curl_exec($ch), true);
}

$resp = gql('query { products(first: 5, query: "sku:6668952") { edges { node { id title variants(first:5){edges{node{sku}}} } } } }');
print_r($resp);

$resp2 = gql('query { products(first: 5, query: "-sku:6668952") { edges { node { id title variants(first:5){edges{node{sku}}} } } } }');
// let's just fetch ANY product to see what SKUs they have
$resp3 = gql('query { products(first: 1) { edges { node { id title variants(first:5){edges{node{sku}}} } } } }');
print_r($resp3);


