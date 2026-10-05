<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);

$url = "https://" . getenv('SHOPIFY_STORE') . "/admin/api/" . getenv('SHOPIFY_API_VERSION') . "/graphql.json";
$token = getenv('SHOPIFY_ADMIN_TOKEN');

function q($query, $vars = []) {
    global $url, $token;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "X-Shopify-Access-Token: " . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'variables' => $vars]));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

$cursor = null;
$updated = 0;
echo "Fetching and updating products...\n";

do {
    $query = 'query ($after: String) { products(first: 50, after: $after) { edges { cursor node { id } } pageInfo { hasNextPage } } }';
    $res = q($query, ['after' => $cursor]);
    $edges = $res['data']['products']['edges'] ?? [];
    
    foreach ($edges as $e) {
        $id = $e['node']['id'];
        $mut = 'mutation ($input: ProductInput!) { productUpdate(input: $input) { product { id } } }';
        q($mut, ['input' => ['id' => $id, 'templateSuffix' => 'gp-template-bk-default']]);
        $updated++;
        echo "Updated $id\n";
    }
    
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $res['data']['products']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);

echo "Done! Updated $updated products.\n";
