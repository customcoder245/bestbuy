<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) echo "<pre style='background:#1e1e1e;color:#ff6666;padding:20px;font-family:monospace;'>";

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
        CURLOPT_POSTFIELDS => json_encode(["query" => $query, "variables" => $vars]),
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err)          { echo "cURL Error: $err\n"; return null; }
    if ($httpCode >= 400) { echo "HTTP $httpCode: $body\n"; return null; }
    return json_decode($body, true);
}

function logLine($m) {
    echo htmlspecialchars($m) . "\n";
    if (ob_get_level() > 0) ob_flush(); flush();
}

logLine("Store: " . getenv('SHOPIFY_STORE'));
logLine("Fetching all products to delete...");

$deleted = 0;
$cursor  = null;

do {
    $vars = ['after' => $cursor];
    $resp = shopifyGQL('
      query getProducts($after: String) {
        products(first: 25, after: $after) {
          edges { cursor node { id title } }
          pageInfo { hasNextPage }
        }
      }', $vars);

    if (!$resp) { logLine("ERROR: No response from Shopify API. Check credentials."); break; }

    $edges   = $resp['data']['products']['edges']              ?? [];
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;

    if (isset($resp['errors'])) { logLine("GraphQL errors: " . json_encode($resp['errors'])); break; }

    logLine("Batch: found " . count($edges) . " products.");

    if (empty($edges)) break;

    $cursor = end($edges)['cursor'];

    foreach ($edges as $edge) {
        $id    = $edge['node']['id'];
        $title = $edge['node']['title'];
        $del   = shopifyGQL('
          mutation del($id: ID!) {
            productDelete(input: {id: $id}) {
              deletedProductId
              userErrors { message }
            }
          }', ['id' => $id]);

        $errs = $del['data']['productDelete']['userErrors'] ?? [];
        if (!empty($errs)) {
            logLine("ERROR deleting \"$title\": " . json_encode($errs));
        } else {
            $deleted++;
            logLine("[$deleted] Deleted: $title");
        }
        usleep(250000);
    }

} while ($hasMore);

logLine("\nDone. Total deleted: $deleted products.");
if (!$isCli) echo "</pre>";
