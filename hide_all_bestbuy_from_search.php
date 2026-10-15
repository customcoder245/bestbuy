<?php
// hide_all_bestbuy_from_search.php
// Standalone script to immediately hide all Best Buy products in Shopify from global search & sitemap

require_once __DIR__ . '/config.php';
set_time_limit(0);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) echo "<pre style='background:#1e1e1e;color:#00ff00;padding:20px;font-family:monospace;'>";

function logLine($m) {
    echo "[" . date('H:i:s') . "] " . htmlspecialchars($m) . "\n";
    if (ob_get_level() > 0) ob_flush(); flush();
}

$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = getenv('SHOPIFY_API_VERSION') ?: '2025-01';

if (!$shopifyStore || !$shopifyToken) {
    die("ERROR: SHOPIFY_STORE and SHOPIFY_ADMIN_TOKEN must be set.\n");
}

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
        CURLOPT_POSTFIELDS => json_encode(["query" => $query, "variables" => $vars]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

logLine("Fetching all Best Buy products to hide from global search & sitemap...");

$updatedCount = 0;
$cursor = null;

do {
    $q = 'query getBestBuyProds($after: String) {
        products(first: 50, after: $after, query: "product_type:Bestbuy") {
            edges {
                cursor
                node {
                    id
                    title
                }
            }
            pageInfo {
                hasNextPage
            }
        }
    }';
    $resp = shopifyGQL($q, ['after' => $cursor]);
    $edges = $resp['data']['products']['edges'] ?? [];
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;

    if (empty($edges)) break;
    $cursor = end($edges)['cursor'];

    foreach ($edges as $edge) {
        $id = $edge['node']['id'];
        $title = $edge['node']['title'];

        $updateQ = 'mutation hideFromSearch($input: ProductInput!) {
            productUpdate(input: $input) {
                product { id }
                userErrors { field message }
            }
        }';
        $vars = [
            'input' => [
                'id' => $id,
                'metafields' => [
                    [
                        'namespace' => 'seo',
                        'key'       => 'hidden',
                        'value'     => '1',
                        'type'      => 'number_integer'
                    ]
                ]
            ]
        ];
        $upResp = shopifyGQL($updateQ, $vars);
        $errs = $upResp['data']['productUpdate']['userErrors'] ?? [];
        if (!empty($errs)) {
            logLine("ERROR on $title: " . json_encode($errs));
        } else {
            $updatedCount++;
            logLine("[$updatedCount] Hidden from global search: $title");
        }
        usleep(150000); // rate limit guard
    }

} while ($hasMore);

logLine("\nCompleted! Total Best Buy products hidden from global search & sitemap: $updatedCount");
if (!$isCli) echo "</pre>";

