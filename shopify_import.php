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
    $time = "[" . date('Y-m-d H:i:s') . "] ";
    $cleanMsg = htmlspecialchars_decode(htmlspecialchars($msg));
    // If the message starts with a newline, preserve it before the timestamp for visual formatting
    if (str_starts_with($cleanMsg, "\n")) {
        echo "\n" . $time . ltrim($cleanMsg, "\n") . "\n";
    } else {
        echo $time . $cleanMsg . "\n";
    }
    if (ob_get_level() > 0) ob_flush();
    flush();
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
        'compareAtPrice' => $v['compareAtPrice'] ?? null,
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

function getAllPublicationIds() {
    $query = 'query { publications(first: 20) { edges { node { id name } } } }';
    $resp = shopifyGraphQL($query);
    $ids = [];
    if (!empty($resp['data']['publications']['edges'])) {
        foreach ($resp['data']['publications']['edges'] as $edge) {
            $ids[] = $edge['node']['id'];
        }
    }
    return $ids;
}

function publishProductToAll($productId, $publicationIds) {
    if (empty($publicationIds)) return;
    $query = 'mutation publishablePublish($id: ID!, $input: [PublicationInput!]!) {
      publishablePublish(id: $id, input: $input) {
        userErrors { field message }
      }
    }';
    
    $input = [];
    foreach ($publicationIds as $pubId) {
        $input[] = ['publicationId' => $pubId];
    }
    
    $resp = shopifyGraphQL($query, [
        'id' => $productId,
        'input' => $input
    ]);
    if (!empty($resp['data']['publishablePublish']['userErrors'])) {
        logMsg("Publish Error: " . json_encode($resp['data']['publishablePublish']['userErrors']));
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
    
    // Discount filtering (DISABLED per client request to allow all items)
    $originalPrice = (float) str_replace(['$', ','], '', $item['initial_price'] ?? $item['price'] ?? '0');
    $currentPrice  = (float) str_replace(['$', ','], '', $item['final_price'] ?? $item['sale_price'] ?? '0');
    // if ($originalPrice > 0 && $originalPrice <= $currentPrice) {
    //     continue;
    // }
    
    $validProducts[] = $item;
}

$total = count($validProducts);
logMsg("Found $total products.");
if ($isDryRun) logMsg("\n--- DRY RUN MODE ---");

$createdCount  = 0;
$skippedCount  = 0;
$failedCount   = 0;

// Pre-fetch all existing Products from Shopify to map SKUs
logMsg("Fetching existing products from Shopify...");
$existingProducts = []; 
$cursor = null;
do {
    $q = 'query getProd($after: String) { products(first: 250, after: $after) { edges { cursor node { id status variants(first: 10) { edges { node { id sku } } } } } pageInfo { hasNextPage } } }';
    $resp = shopifyGraphQL($q, ['after' => $cursor]);
    $edges = $resp['data']['products']['edges'] ?? [];
    foreach ($edges as $e) {
        $node = $e['node'];
        $productId = $node['id'];
        $status = $node['status'];
        foreach ($node['variants']['edges'] ?? [] as $vEdge) {
            $s = trim((string)$vEdge['node']['sku']);
            if ($s !== '') {
                $existingProducts[$s] = ['productId' => $productId, 'variantId' => $vEdge['node']['id'], 'status' => $status];
            }
        }
    }
    $cursor = !empty($edges) ? end($edges)['cursor'] : null;
    $hasMore = $resp['data']['products']['pageInfo']['hasNextPage'] ?? false;
} while ($hasMore);
logMsg("Found " . count($existingProducts) . " unique SKUs already in Shopify.");

$allPubIds = getAllPublicationIds();
  logMsg("Found " . count($allPubIds) . " sales channels (publications) to publish to.");

  foreach ($validProducts as $index => $product) {
    $current = $index + 1;
    $title   = trim($product['title']);
    $sku     = trim((string)($product['product_id'] ?? $product['sku'] ?? ''));
    $brand   = $product['brand'] ?? 'Best Buy';
    $desc    = $product['description'] ?? '';

    // Price calculation
    $settingsFile  = __DIR__ . '/settings.json';
    $settings      = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    
    $originalUsdNum = (float) str_replace(['$', ','], '', $product['initial_price'] ?? $product['price'] ?? '0');
    $currentUsdNum  = (float) str_replace(['$', ','], '', $product['final_price'] ?? $product['sale_price'] ?? '0');
    
    $taxFeePercent = (float)($settings['tax_fee'] ?? 10);
    $shipping      = (float)($settings['shipping'] ?? 20);
    $exchangeRate  = (float)($settings['exchange_rate'] ?? 3595);
    $compareAtInc  = (float)($settings['compare_at_increment'] ?? 100000); // 100,000 MNT markup
    
    // Calculate Listed Price (What the customer pays = priceMnt)
    // Formula: (BestBuy Sale Price + Tax + Shipping) * Exchange Rate + 100,000
    $currentTaxAmount = $currentUsdNum * ($taxFeePercent / 100);
    $currentTotalUsd  = $currentUsdNum + $currentTaxAmount + $shipping;
    $priceMnt         = round($currentTotalUsd * $exchangeRate) + $compareAtInc;
    
    // Calculate Main Price (The crossed-out original price = compareAtMnt)
    // Formula: (BestBuy Original Price + Tax + Shipping) * Exchange Rate (NO 100,000 added here)
    $originalTaxAmount = $originalUsdNum * ($taxFeePercent / 100);
    $originalTotalUsd  = $originalUsdNum + $originalTaxAmount + $shipping;
    $compareAtMnt      = round($originalTotalUsd * $exchangeRate);
    
    // If the original price isn't higher than our marked-up sale price, remove the discount visual
    if ($compareAtMnt <= $priceMnt) {
        $compareAtMnt = $priceMnt;
    }

    logMsg("\n[$current/$total] Processing: $title");
    logMsg("   => Math (Sale): ( \${$currentUsdNum} + \${$currentTaxAmount} (Tax) + \${$shipping} (Ship) ) * {$exchangeRate} + {$compareAtInc} = {$priceMnt} MNT");
    logMsg("   => Math (Orig): ( \${$originalUsdNum} + \${$originalTaxAmount} (Tax) + \${$shipping} (Ship) ) * {$exchangeRate} = {$compareAtMnt} MNT");

    // Guaranteed Duplicate Check by SKU
    if ($sku !== '' && isset($existingProducts[$sku])) {
        logMsg("   => SKU $sku already exists. Updating price & availability...");
        $exProd = $existingProducts[$sku];
        unset($existingProducts[$sku]);
        
        if (!$isDryRun) {
            $mut = 'mutation updatePrice($productId: ID!, $variants: [ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId: $productId, variants: $variants) { userErrors { message } } }';
            $vars = [
                'productId' => $exProd['productId'],
                'variants' => [['id' => $exProd['variantId'], 'price' => (string)$priceMnt, 'compareAtPrice' => (string)$compareAtMnt]]
            ];
            $r = shopifyGraphQL($mut, $vars);
            if (!empty($r['data']['productVariantsBulkUpdate']['userErrors'])) {
                logMsg("   => Error updating price: " . json_encode($r['data']['productVariantsBulkUpdate']['userErrors']));
            } else {
                logMsg("   => Successfully updated price for SKU $sku.");
                if ($exProd['status'] !== 'ACTIVE') {
                    shopifyGraphQL('mutation pub($input: ProductInput!) { productUpdate(input: $input) { userErrors { message } } }', ['input' => ['id' => $exProd['productId'], 'status' => 'ACTIVE']]);
                }
                // Publish to all channels even if the product already exists
                if (!empty($allPubIds)) {
                    publishProductToAll($exProd['productId'], $allPubIds);
                }
            }
        }
        $skippedCount++; 
        continue;
    }

    // Build description
    if (!empty($product['features']) && is_array($product['features'])) {
        $desc .= "<ul>";
        foreach ($product['features'] as $f) $desc .= "<li>" . htmlspecialchars($f) . "</li>";
        $desc .= "</ul>";
    }


    // ── Collections from breadcrumbs ────────────────────────────────────────
    $collectionGids = [];
    if (!$isDryRun) {
        $bbColId = getOrCreateCollection('Bestbuy-Collection');
        if ($bbColId) $collectionGids[] = $bbColId;
    }
    $tags = ['Захиалгаар'];
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
    $metafields = [
        [
            'namespace' => 'theme',
            'key'       => 'label',
            'value'     => 'Захиалгаар',
            'type'      => 'single_line_text_field',
        ],
        [
            'namespace' => 'theme',
            'key'       => 'label_color',
            'value'     => '#D93A6C',
            'type'      => 'color',
        ]
    ];
    if ($sku) {
        $metafields[] = [
            'namespace' => 'custom',
            'key'       => 'best_buy_sku',
            'value'     => (string) $sku,
            'type'      => 'single_line_text_field',
        ];
        
        $metafields[] = [
            'namespace' => 'custom',
            'key'       => 'bestbuy_original_price_usd',
            'value'     => (string)$originalPrice,
            'type'      => 'single_line_text_field',
        ];
        
        $metafields[] = [
            'namespace' => 'custom',
            'key'       => 'bestbuy_current_price_usd',
            'value'     => (string)$currentPrice,
            'type'      => 'single_line_text_field',
        ];
        
        if (!empty($product['url'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => 'bestbuy_product_url',
                'value'     => (string)$product['url'],
                'type'      => 'url',
            ];
        }
    }
    
    // Add Product Specifications Metafield
    if (!empty($product['product_specifications']) && is_array($product['product_specifications'])) {
        
        $allowedSpecs = [
            'screen size',
            'touch screen',
            'touchscreen',
            'processor model',
            'total storage capacity',
            'system memory (ram)',
            'graphics',
            'battery life (up to)',
            '2-in-1 design',
            'backlit keyboard',
            'color'
        ];

        $filteredSpecs = [];

        foreach ($product['product_specifications'] as $spec) {
            $specName = $spec['specification_name'] ?? '';
            $specValue = $spec['specification_value'] ?? '';
            
            if (empty($specName) || empty($specValue)) continue;
            
            $lowerName = strtolower(trim($specName));
            
            if (in_array($lowerName, $allowedSpecs)) {
                $filteredSpecs[] = $spec;

                $key = preg_replace('/[^a-z0-9_]/', '_', $lowerName);
                $key = trim(preg_replace('/_+/', '_', $key), '_');
                if (strlen($key) > 64) $key = substr($key, 0, 64);
                
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

        if (!empty($filteredSpecs)) {
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => 'product_specifications',
                'value'     => json_encode($filteredSpecs),
                'type'      => 'json',
            ];
        }
    }
    
    // ── Build variants & options ────────────────────────────────────────────
    // Client requested to remove variants entirely (Point 11).
    // We will just create a single default variant for the main product.
    $productOptions = [];
    $shopifyVariants = [
        [
            'sku'            => (string)$sku,
            'price'          => (string)$priceMnt,
            'compareAtPrice' => (string)$compareAtMnt,
            'variantSku'     => (string)$sku
        ]
    ];

    // Log the single variant
    logMsg("   => Single Default Variant: SKU {$sku}");

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
        'productType'     => 'Bestbuy',
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

    if (!empty($allPubIds)) {
        publishProductToAll($productId, $allPubIds);
    }

    // Create the single default variant (this updates the auto-created Default Title variant)
    $variantIdMap = createShopifyVariants($productId, $shopifyVariants, []);

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


logMsg("\nFinished processing JSON products.");
logMsg("Checking for products that dropped out of the feed...");

$toUnpublish = [];
foreach ($existingProducts as $sku => $prod) {
    if ($prod['status'] === 'ACTIVE') {
        $toUnpublish[] = $prod['productId'];
    }
}

if (!empty($toUnpublish)) {
    logMsg("Found " . count($toUnpublish) . " active products no longer in feed. Unpublishing...");
    if (!$isDryRun) {
        $unpubMut = 'mutation productUpdate($input: ProductInput!) { productUpdate(input: $input) { userErrors { message } } }';
        foreach (array_unique($toUnpublish) as $pId) {
            shopifyGraphQL($unpubMut, ['input' => ['id' => $pId, 'status' => 'DRAFT']]);
        }
    }
} else {
    logMsg("No products to unpublish.");
}

logMsg("\nImport complete.");
logMsg("Created: $createdCount");
logMsg("Skipped: $skippedCount");
logMsg("Failed:  $failedCount");

if (!$isCli) echo "</pre>";

file_put_contents(__DIR__ . '/last_sync.txt', date('Y-m-d H:i:s'));

