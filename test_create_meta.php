<?php
require_once __DIR__ . '/config.php';
function q($query, $vars = []) {
    $url = "https://" . getenv('SHOPIFY_STORE') . "/admin/api/" . getenv('SHOPIFY_API_VERSION') . "/graphql.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "X-Shopify-Access-Token: " . getenv('SHOPIFY_ADMIN_TOKEN')]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = ['query' => $query];
    if (!empty($vars)) $payload['variables'] = $vars;
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
$pid = "gid://shopify/Product/15384165515577"; // Wait I will query a product ID
$products = q('{ products(first:1) { edges { node { id } } } }');
$pid = $products['data']['products']['edges'][0]['node']['id'];

$mut = 'mutation { productUpdate(input: { id: "'.$pid.'", metafields: [ { namespace: "custom", key: "test_spec", value: "{\"test\":\"ok\"}", type: "json" } ] }) { userErrors { message field } } }';
print_r(q($mut));

