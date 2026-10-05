<?php
require_once __DIR__ . '/config.php';

function shopifyGraphQL($query, $variables = []) {
    $url = "https://" . getenv('SHOPIFY_STORE') . "/admin/api/" . getenv('SHOPIFY_API_VERSION') . "/graphql.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . getenv('SHOPIFY_ADMIN_TOKEN')
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = ['query' => $query];
    if (!empty($variables)) $payload['variables'] = $variables;
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
$q = 'query { products(first: 50) { edges { node { title metafields(namespace: "custom", first: 10) { edges { node { key } } } } } } }';
$res = shopifyGraphQL($q, []);
foreach($res['data']['products']['edges'] as $e) {
    $keys = [];
    foreach($e['node']['metafields']['edges'] as $m) $keys[] = $m['node']['key'];
    echo $e['node']['title'] . ' -> ' . implode(',', $keys) . "\n";
}
