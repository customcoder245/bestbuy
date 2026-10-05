<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);

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

echo "Fetching all products to deduplicate...\n";

$cursor = null;
$products = [];
do {
    $resp = gql('query getProd($after: String) { products(first: 250, after: $after) { edges { cursor node { id title } } pageInfo { hasNextPage } } }', ['after' => $cursor]);
    $edges = $resp['data']['products']['edges'] ?? [];
    foreach ($edges as $e) {
        $products[] = $e['node'];
    }
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);

echo "Found " . count($products) . " total products.\n";

$titles = [];
$toDelete = [];

foreach ($products as $p) {
    if (!isset($titles[$p['title']])) {
        $titles[$p['title']] = $p['id'];
    } else {
        $toDelete[] = $p['id'];
    }
}

echo "Found " . count($toDelete) . " duplicate products to delete.\n";

$deleted = 0;
foreach ($toDelete as $id) {
    $resp = gql('mutation del($id: ID!) { productDelete(input: {id: $id}) { deletedProductId userErrors { message } } }', ['id' => $id]);
    $errs = $resp['data']['productDelete']['userErrors'] ?? [];
    if (!empty($errs)) {
        echo "Error deleting: " . json_encode($errs) . "\n";
    } else {
        $deleted++;
        echo "Deleted duplicate: $id\n";
    }
    usleep(250000);
}

echo "Deduplication complete. Deleted $deleted products.\n";

