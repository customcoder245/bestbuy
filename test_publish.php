<?php
require_once __DIR__ . '/config.php';
$store = str_replace(['https://', 'http://'], '', getenv("SHOPIFY_STORE"));
$token = getenv("SHOPIFY_ADMIN_TOKEN");

// Get the first product to test
$q = 'query { products(first: 1) { edges { node { id title } } } }';
$url = "https://{$store}/admin/api/2025-01/graphql.json";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "X-Shopify-Access-Token: $token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["query" => $q]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
$prodId = $data['data']['products']['edges'][0]['node']['id'] ?? '';
echo "Testing product ID: $prodId\n";

if (preg_match('/Product\/(\d+)/', $prodId, $m)) {
    $numericId = $m[1];
    $url = "https://{$store}/admin/api/2025-01/products/{$numericId}.json";
    
    $payload = json_encode(["product" => ["id" => $numericId, "published" => true]]);
    echo "Payload: $payload\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "X-Shopify-Access-Token: $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP CODE: $code\n";
    echo "RESPONSE: $res\n";
}

