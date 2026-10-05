<?php
// c:\Xampp\htdocs\api\shopify_import.php

require_once __DIR__ . '/config.php';
set_time_limit(0);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    echo "<pre style='background: #1e1e1e; color: #00ff00; padding: 20px; font-family: monospace;'>";
}

$options  = $isCli ? getopt("", ["dry-run"]) : [];
$isDryRun = isset($_GET['dry-run']) || isset($options['dry-run']);

$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = getenv('SHOPIFY_API_VERSION') ?: "2025-01";

if (!$shopifyStore || !$shopifyToken) {
    die("ERROR: SHOPIFY_STORE and SHOPIFY_ADMIN_TOKEN must be set in .env\n");
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function logMsg($msg) {
    echo htmlspecialchars_decode(htmlspecialchars($msg)) . "\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
    file_put_contents(__DIR__ . '/shopify_import.log', "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function shopifyGraphQL($query, $variables = []) {
    global $shopifyStore, $shopifyToken, $apiVersion;
    $store   = str_replace(['https://', 'http://'], '', $shopifyStore);
    $url     = "https://{$store}/admin/api/{$apiVersion}/graphql.json";
    $payload = ["query" => $query, "variables" => $variables];
    $headers = ["Content-Type: application/json", "X-Shopify-Access-Token: $shopifyToken"];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) { logMsg("cURL Error: $error"); break; }
        if ($httpCode === 429) { logMsg("Rate limit. Retrying..."); sleep(2 * $attempt); continue; }
        if ($httpCode >= 400) { logMsg("HTTP Error ($httpCode)"); return null; }

        $data = json_decode($response, true);
        if (isset($data['errors'])) logMsg("GraphQL Error: " . json_encode($data['errors']));
        return $data;
    }
    return null;
}

function findExistingProduct($sku) {
    if (empty($sku)) return null;
    $query = 'query findProduct($query: String!) {
      products(first: 1, query: $query) {
        edges { node { id } }
      }
    }';
    $resp = shopifyGraphQL($query, ['query' => "sku:{$sku}"]);
    return $resp['data']['products']['edges'][0]['node']['id'] ?? null;
}

function makeHandle($title, $sku) {
    $base   = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $base   = trim($base, '-');
    $base   = substr($base, 0, 55);
    $unique = substr(bin2hex(random_bytes(3)), 0, 4); // 4-char random hex suffix
    return $base . '-' . $sku . '-' . $unique;
}

function createShopifyProduct($input) {
    $query = 'mutation productCreate($input: ProductInput!) {
      productCreate(input: $input) {
        product { id }
        userErrors { field message }
      }
    }';
    $resp = shopifyGraphQL($query, ['input' => $input]);
    if (!empty($resp['data']['productCreate']['userErrors'])) {
        logMsg("Create Error: " . json_encode($resp['data']['productCreate']['userErrors']));
        return null;
    }
    return $resp['data']['productCreate']['product']['id'] ?? null;
}

function createProductOptions($productId, $optionsMap) {
    if (empty($optionsMap)) return;
    $query = 'mutation productOptionsCreate($productId: ID!, $options: [OptionCreateInput!]!) {
      productOptionsCreate(productId: $productId, options: $options) {
        product { id }
        userErrors { field message }
      }
    }';
    $options = [];
    foreach ($optionsMap as $optName => $optValues) {
        $options[] = ['name' => $optName, 'values' => array_map(fn($v) => ['name' => $v], $optValues)];
    }
    $resp = shopifyGraphQL($query, ['productId' => $productId, 'options' => $options]);
    if (!empty($resp['data']['productOptionsCreate']['userErrors'])) {
        logMsg("Options Error: " . json_encode($resp['data']['productOptionsCreate']['userErrors']));
    }
}

function getDefaultVariantId($productId) {
    $query = 'query getVariants($id: ID!) {
      product(id: $id) { variants(first: 1) { edges { node { id } } } }
    }';
    $resp = shopifyGraphQL($query, ['id' => $productId]);
    return $resp['data']['product']['variants']['edges'][0]['node']['id'] ?? null;
}

function mapVariantInput($v, $productOptions) {
    $mapped = [
        'price'         => $v['price'],
        'inventoryItem' => ['sku' => $v['sku'], 'tracked' => false],
    ];
    if (!empty($v['options']) && !empty($productOptions)) {
        $optionValues = [];
        foreach ($productOptions as $i => $optName) {
            if (isset($v['options'][$i])) {
                $optionValues[] = ['optionName' => $optName, 'name' => $v['options'][$i]];
            }
        }
        if (!empty($optionValues)) $mapped['optionValues'] = $optionValues;
    }
    return $mapped;
}

/**
 * Creates/updates all variants.
 * Returns array of [variantId => options[]] so images can be linked later.
 */
function createShopifyVariants($productId, $variants, $productOptions) {
    if (empty($variants)) return [];

    $variantIdMap = []; // variantId => options list

    // --- Step 1: Update the auto-created "Default Title" variant ---
    $defaultVariantId = getDefaultVariantId($productId);
    if ($defaultVariantId) {
        $firstMapped       = mapVariantInput($variants[0], $productOptions);
        $firstMapped['id'] = $defaultVariantId;

        $updateQuery = 'mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            productVariants { id }
            userErrors { field message }
          }
        }';
        $resp = shopifyGraphQL($updateQuery, ['productId' => $productId, 'variants' => [$firstMapped]]);
        if (!empty($resp['data']['productVariantsBulkUpdate']['userErrors'])) {
            logMsg("Update Default Variant Error: " . json_encode($resp['data']['productVariantsBulkUpdate']['userErrors']));
        }
        $variantIdMap[$defaultVariantId] = $variants[0]['options'] ?? [];
    }

    // --- Step 2: Create remaining variants ---
    $remaining = array_slice($variants, 1);
    if (!empty($remaining)) {
        $createQuery = 'mutation productVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkCreate(productId: $productId, variants: $variants) {
            productVariants { id }
            userErrors { field message }
          }
        }';
        $mappedRemaining = array_map(fn($v) => mapVariantInput($v, $productOptions), $remaining);
        $resp = shopifyGraphQL($createQuery, ['productId' => $productId, 'variants' => $mappedRemaining]);
        if (!empty($resp['data']['productVariantsBulkCreate']['userErrors'])) {
            logMsg("Create Variants Error: " . json_encode($resp['data']['productVariantsBulkCreate']['userErrors']));
        }
        // Map returned variant IDs to their options
        $createdVariants = $resp['data']['productVariantsBulkCreate']['productVariants'] ?? [];
        foreach ($createdVariants as $i => $cv) {
            $variantIdMap[$cv['id']] = $remaining[$i]['options'] ?? [];
        }
    }

    return $variantIdMap;
}

/**
 * Upload images. Returns array of mediaId => url (to match later).
 */
/**
 * Upload images and return the list of Shopify mediaIds created.
 * We wait briefly so Shopify has time to enqueue the images.
 */
function uploadProductImages($productId, $images) {
    if (empty($images)) return [];
    $query = 'mutation productCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
      productCreateMedia(productId: $productId, media: $media) {
        media { id }
        mediaUserErrors { field message }
      }
    }';
    $mediaInput = array_map(fn($url) => [
        'originalSource'   => $url,
        'mediaContentType' => 'IMAGE'
    ], $images);

    $resp = shopifyGraphQL($query, ['productId' => $productId, 'media' => $mediaInput]);
    if (!empty($resp['data']['productCreateMedia']['mediaUserErrors'])) {
        logMsg("Image Upload Error: " . json_encode($resp['data']['productCreateMedia']['mediaUserErrors']));
    }

    return array_column($resp['data']['productCreateMedia']['media'] ?? [], 'id');
}

/**
 * Assign the first uploaded image to every variant so the image
 * updates when a variant is selected in the storefront.
 */
function assignImageToVariants($productId, $mediaIds, $variantIds) {
    if (empty($mediaIds) || empty($variantIds)) return;

    // Use the first available mediaId for all variants
    $firstMediaId = $mediaIds[0];

    $query = 'mutation productVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
      productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
        product { id }
        userErrors { field message }
      }
    }';

    // Batch all variants into one call
    $variantMedia = array_map(fn($vid) => [
        'variantId' => $vid,
        'mediaIds'  => [$firstMediaId]
    ], $variantIds);

    $resp = shopifyGraphQL($query, [
        'productId'    => $productId,
        'variantMedia' => $variantMedia
    ]);
    if (!empty($resp['data']['productVariantAppendMedia']['userErrors'])) {
        logMsg("Assign Image Error: " . json_encode($resp['data']['productVariantAppendMedia']['userErrors']));
    }
}



/**
 * Link a specific image (mediaId) to a variant.
 * This makes the product image update when a color variant is selected.
 */
function linkImageToVariant($productId, $variantId, $mediaId) {
    $query = 'mutation productVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
      productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
        product { id }
        userErrors { field message }
      }
    }';
    $resp = shopifyGraphQL($query, [
        'productId'    => $productId,
        'variantMedia' => [['variantId' => $variantId, 'mediaIds' => [$mediaId]]]
    ]);
    if (!empty($resp['data']['productVariantAppendMedia']['userErrors'])) {
        logMsg("Link Image Error: " . json_encode($resp['data']['productVariantAppendMedia']['userErrors']));
    }
}

$collectionCache = []; // memory cache for collection IDs

function getOrCreateCollection($title) {
    global $collectionCache;
    $title = trim($title);
    if (empty($title)) return null;
    if (isset($collectionCache[$title])) return $collectionCache[$title];
    
    // Search
    $query = 'query searchCol($query: String!) { collections(first: 1, query: $query) { edges { node { id } } } }';
    $resp = shopifyGraphQL($query, ['query' => "title:\"$title\""]);
    $id = $resp['data']['collections']['edges'][0]['node']['id'] ?? null;
    
    if ($id) {
        $collectionCache[$title] = $id;
        return $id;
    }
    
    // Create
    $mut = 'mutation createCol($input: CollectionInput!) { collectionCreate(input: $input) { collection { id } } }';
    $resp = shopifyGraphQL($mut, ['input' => ['title' => $title]]);
    $id = $resp['data']['collectionCreate']['collection']['id'] ?? null;
    if ($id) {
        $collectionCache[$title] = $id;
    }
    return $id;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

logMsg("Starting Shopify import...");

$jsonFile = __DIR__ . '/bestbuy-products.json';
if (!file_exists($jsonFile)) die("ERROR: $jsonFile not found.\n");

$jsonData = json_decode(file_get_contents($jsonFile), true);
if (!$jsonData || !is_array($jsonData)) die("ERROR: Invalid JSON in $jsonFile\n");

// Filter valid products
$validProducts = [];
foreach ($jsonData as $item) {
    if (isset($item['error']) || empty($item['title'])) continue;
    $validProducts[] = $item;
}

$total = count($validProducts);
logMsg("Found $total products.");
if ($isDryRun) logMsg("\n--- DRY RUN MODE ---");

$createdCount  = 0;
$skippedCount  = 0;
$failedCount   = 0;

// Pre-fetch all existing Titles from Shopify to guarantee NO duplicates
logMsg("Fetching existing products from Shopify to prevent duplicates...");
$existingTitles = [];
$cursor = null;
do {
    $q = 'query getProd($after: String) { products(first: 250, after: $after) { edges { cursor node { title } } pageInfo { hasNextPage } } }';
    $resp = shopifyGraphQL($q, ['after' => $cursor]);
    $edges = $resp['data']['products']['edges'] ?? [];
    foreach ($edges as $e) {
        if (!empty($e['node']['title'])) {
            $existingTitles[] = trim((string)$e['node']['title']);
        }
    }
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);
$existingTitles = array_unique($existingTitles);
logMsg("Found " . count($existingTitles) . " unique Titles already in Shopify.");

foreach ($validProducts as $index => $product) {
    $current = $index + 1;
    $title   = trim($product['title']);
    $sku     = $product['product_id'] ?? $product['sku'] ?? null;
    $brand   = $product['brand'] ?? 'Best Buy';
    $desc    = $product['description'] ?? '';

    // ── Guaranteed Duplicate Check by Title ─────────────────────────────────
    if (in_array($title, $existingTitles)) {
        logMsg("\n[$current/$total] Skipping existing product by Title: $title");
        $skippedCount++;
        continue;
    }
    $existingTitles[] = $title; // Add it to memory so we don't duplicate it if JSON has it twice

    // ── Build description ───────────────────────────────────────────────────
    if (!empty($product['features']) && is_array($product['features'])) {
        $desc .= "<ul>";
        foreach ($product['features'] as $f) $desc .= "<li>" . htmlspecialchars($f) . "</li>";
        $desc .= "</ul>";
    }

    // ── Price calculation ───────────────────────────────────────────────────
    $settingsFile  = __DIR__ . '/settings.json';
    $settings      = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    
    $basePrice     = $product['final_price'] ?? $product['price'] ?? 0;
    $baseUsdNum    = (float) str_replace(['$', ','], '', $basePrice);
    
    $taxFeePercent = (float)($settings['tax_fee'] ?? 10);
    $shipping      = (float)($settings['shipping'] ?? 20);
    $exchangeRate  = (float)($settings['exchange_rate'] ?? 3595);
    
    $taxAmount     = $baseUsdNum * ($taxFeePercent / 100);
    $totalUsd      = $baseUsdNum + $taxAmount + $shipping;
    $priceMnt      = round($totalUsd * $exchangeRate);

    logMsg("\n[$current/$total] Processing: $title");
    logMsg("   => Math: \${$baseUsdNum} + \${$taxAmount} (Tax) + \${$shipping} (Ship) = \${$totalUsd} USD -> {$priceMnt} MNT");

    // ── Collections from breadcrumbs ────────────────────────────────────────
    $collectionGids = [];
    $tags = [];
    if (!empty($product['breadcrumbs'])) {
        foreach ($product['breadcrumbs'] as $crumb) {
            $cName = $crumb['name'] ?? '';
            if ($cName === 'Best Buy' || $cName === 'Products') continue; // Skip generic root categories
            if (!empty($cName)) {
                $tags[] = $cName;
                if (!$isDryRun) {
                    $cId = getOrCreateCollection($cName);
                    if ($cId) $collectionGids[] = $cId;
                }
            }
        }
    }
    if (!empty($tags)) {
        logMsg("   => Collections/Tags: " . implode(" > ", $tags));
    }

    // ── Metafields (reset each product) ────────────────────────────────────
    $metafields = [];
    if ($sku) {
        $metafields[] = [
            'namespace' => 'custom',
            'key'       => 'best_buy_sku',
            'value'     => (string) $sku,
            'type'      => 'single_line_text_field',
        ];
    }
    
    // Add Product Specifications Metafield
    if (!empty($product['product_specifications']) && is_array($product['product_specifications'])) {
        $metafields[] = [
            'namespace' => 'custom',
            'key'       => 'product_specifications',
            'value'     => json_encode($product['product_specifications']),
            'type'      => 'json',
        ];

        // Also add each specification as a separate metafield
        foreach ($product['product_specifications'] as $spec) {
            $specName = $spec['specification_name'] ?? '';
            $specValue = $spec['specification_value'] ?? '';
            
            if (empty($specName) || empty($specValue)) continue;

            // Clean the key (must be lowercase alphanumeric and underscores, max 64 chars)
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($specName));
            $key = trim(preg_replace('/_+/', '_', $key), '_');
            
            if (strlen($key) > 64) {
                $key = substr($key, 0, 64);
            }
            
            $specValueStr = (string)$specValue;
            $type = strlen($specValueStr) > 255 ? 'multi_line_text_field' : 'single_line_text_field';
            
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => $key,
                'value'     => $specValueStr,
                'type'      => $type
            ];
        }
    }

    // ── Build variants & options ────────────────────────────────────────────
    $optionsMap    = [];
    $variantsBySku = [];

    // Also track which variant SKU -> Color value (to match with image later)
    $variantColor  = []; // vSku => color string

    if (!empty($product['variations'])) {
        foreach ($product['variations'] as $v) {
            $vSku   = $v['variant_sku'] ?? $sku;
            $optName = $v['variations_name'];
            $optVal  = $v['variations_value'];

            if (!isset($optionsMap[$optName]) && count($optionsMap) < 3) {
                $optionsMap[$optName] = [];
            }
            if (isset($optionsMap[$optName])) {
                if (!in_array($optVal, $optionsMap[$optName])) $optionsMap[$optName][] = $optVal;
                $variantsBySku[$vSku][$optName] = $optVal;
            }

            // Record color mapping
            if (stripos($optName, 'color') !== false || stripos($optName, 'colour') !== false) {
                $variantColor[$vSku] = strtolower($optVal);
            }
        }
    }

    $productOptions = array_keys($optionsMap);
    $shopifyVariants = [];
    $uniqueOptions   = [];

    if (!empty($variantsBySku)) {
        foreach ($variantsBySku as $vSku => $vOptions) {
            $optsList = [];
            foreach ($productOptions as $optName) $optsList[] = $vOptions[$optName] ?? 'Default';
            $optKey = implode("||", $optsList);
            if (!isset($uniqueOptions[$optKey])) {
                $uniqueOptions[$optKey] = true;
                $shopifyVariants[] = ['sku' => (string)$vSku, 'price' => (string)$priceMnt, 'options' => $optsList, 'variantSku' => (string)$vSku];
            }
        }
    } else {
        $shopifyVariants[] = ['sku' => (string)$sku, 'price' => (string)$priceMnt, 'variantSku' => (string)$sku];
    }

    // Log variants
    foreach ($shopifyVariants as $sv) {
        $opts = implode(" / ", $sv['options'] ?? ['Default']);
        logMsg("   => Variant: SKU {$sv['sku']}, Options: {$opts}");
    }

    // ── Images ──────────────────────────────────────────────────────────────
    // Use full-size images only (no prescaled thumbnails)
    $allImages = array_values(array_filter($product['images'] ?? [], fn($u) => strpos($u, 'prescaled') === false));
    $imagesToUpload = array_unique(array_slice($allImages ?: ($product['images'] ?? []), 0, 8));
    logMsg("   => Images: " . count($imagesToUpload) . " to upload");

    if ($isDryRun) continue;

    // ── Create product ──────────────────────────────────────────────────────
    $handle = makeHandle($title, $sku ?: $current);
    $input  = [
        'title'           => $title,
        'handle'          => $handle,
        'vendor'          => $brand,
        'descriptionHtml' => $desc,
        'status'          => 'ACTIVE',
        'templateSuffix'  => 'bestbuy-template',
        'tags'            => implode(',', $tags),
        'metafields'      => $metafields,
    ];
    if (!empty($collectionGids)) {
        $input['collectionsToJoin'] = array_values(array_unique($collectionGids));
    }

    $productId = createShopifyProduct($input);
    if (!$productId) {
        logMsg("   => ERROR: Failed to create $title");
        $failedCount++;
        continue;
    }

    logMsg("   => SUCCESS: Created ($productId)");
    $createdCount++;

    // Create options then variants
    if (!empty($optionsMap)) createProductOptions($productId, $optionsMap);
    $variantIdMap = createShopifyVariants($productId, $shopifyVariants, $productOptions);

    // Upload images and assign to all variants
    if (!empty($imagesToUpload)) {
        $mediaIds = uploadProductImages($productId, array_values($imagesToUpload));
        // Assign first image to every variant so image shows when variant selected
        if (!empty($mediaIds) && !empty($variantIdMap)) {
            assignImageToVariants($productId, $mediaIds, array_keys($variantIdMap));
        }
    }

    usleep(500000); // 0.5s rate limit pause
}

logMsg("\nImport complete.");
logMsg("Created: $createdCount");
logMsg("Skipped: $skippedCount");
logMsg("Failed:  $failedCount");

if (!$isCli) echo "</pre>";