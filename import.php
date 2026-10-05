<?php
// c:\Xampp\htdocs\api\import.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/brightdata.php';
require_once __DIR__ . '/shopify.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create sync tracking table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS imported_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(255) NOT NULL UNIQUE,
        bb_url TEXT,
        shopify_product_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}

$brightData = new BrightDataAPI(BRIGHT_DATA_TOKEN, BRIGHT_DATA_DATASET_ID);
$shopify = new ShopifyAPI(SHOPIFY_STORE, SHOPIFY_ADMIN_TOKEN);

function calculateFinalPrice($basePriceUsd) {
    $basePriceUsd = (float)$basePriceUsd;
    $taxFeePercent = TAX_SERVICE_FEE_PERCENT;
    $shippingCostPerKg = SHIPPING_COST_PER_KG;
    $exchangeRateMnt = EXCHANGE_RATE_MNT;
    $weightKg = DEFAULT_WEIGHT_KG; // Could be extracted from JSON if available

    $taxAndServiceFee = $basePriceUsd * ($taxFeePercent / 100);
    $shippingCost = $weightKg * $shippingCostPerKg;
    
    $totalCostUsd = $basePriceUsd + $taxAndServiceFee + $shippingCost;
    $finalPriceMnt = $totalCostUsd * $exchangeRateMnt;
    
    return round($finalPriceMnt);
}

function mapProductToShopifyInput($productJson) {
    // Basic Information
    $title = $productJson['title'] ?? 'Unknown Product';
    $description = $productJson['product_description'] ?? '';
    if (!empty($productJson['features'])) {
        $description .= "<ul>";
        foreach ($productJson['features'] as $feature) {
            $description .= "<li>" . htmlspecialchars($feature) . "</li>";
        }
        $description .= "</ul>";
    }

    $vendor = $productJson['brand'] ?? ($productJson['seller']['name'] ?? 'Best Buy');
    $productType = explode('>', $productJson['product_category'] ?? '')[3] ?? 'Computers & Tablets';
    $sku = $productJson['sku'] ?? null;
    
    $basePriceUsd = str_replace(['$', ','], '', $productJson['final_price'] ?? $productJson['price'] ?? '0.00');
    $comparePriceUsd = str_replace(['$', ','], '', $productJson['initial_price'] ?? '0.00');
    
    $price = calculateFinalPrice($basePriceUsd);
    
    $comparePrice = null;
    if ($comparePriceUsd && $comparePriceUsd > $basePriceUsd) {
        $comparePrice = calculateFinalPrice($comparePriceUsd);
    }

    // Metafields for Specifications
    $metafields = [];
    $allowedSpecs = ['Processor Model', 'System Memory (RAM)', 'Total Storage Capacity', 'Screen Size', 'Graphics', 'Operating System', 'Battery Chemistry'];
    if (!empty($productJson['product_specifications'])) {
        foreach ($productJson['product_specifications'] as $spec) {
            if (in_array($spec['specification_name'], $allowedSpecs)) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => strtolower(str_replace([' ', '(', ')'], '_', $spec['specification_name'])),
                    'value' => $spec['specification_value'],
                    'type' => 'single_line_text_field'
                ];
            }
        }
    }

    // Include Best Buy URL
    if (!empty($productJson['url'])) {
        $metafields[] = [
            'namespace' => 'custom',
            'key' => 'best_buy_url',
            'value' => $productJson['url'],
            'type' => 'url'
        ];
    }

    // Handle Options and Variants (Shopify supports max 3 options)
    $optionsMap = []; // Name => [values]
    $variantsBySku = [];

    if (!empty($productJson['variations'])) {
        foreach ($productJson['variations'] as $v) {
            $vSku = $v['variant_sku'] ?? $sku;
            $optName = $v['variations_name'];
            $optVal = $v['variations_value'];
            
            // Collect unique options
            if (!isset($optionsMap[$optName])) {
                if (count($optionsMap) < 3) { // limit to 3 options
                    $optionsMap[$optName] = [];
                }
            }
            
            if (isset($optionsMap[$optName])) {
                if (!in_array($optVal, $optionsMap[$optName])) {
                    $optionsMap[$optName][] = $optVal;
                }
                $variantsBySku[$vSku][$optName] = $optVal;
            }
        }
    }

    $productOptions = array_keys($optionsMap);
    $shopifyVariants = [];

    if (!empty($variantsBySku)) {
        foreach ($variantsBySku as $vSku => $vOptions) {
            $variantOptionsList = [];
            foreach ($productOptions as $optName) {
                $variantOptionsList[] = $vOptions[$optName] ?? 'Default';
            }
            
            $variant = [
                'sku' => (string)$vSku,
                'price' => (string)$price,
                'options' => $variantOptionsList,
                'inventoryManagement' => 'SHOPIFY',
                'inventoryQuantities' => [
                    ['availableQuantity' => 10, 'locationId' => 'gid://shopify/Location/123456789']
                ]
            ];
            if ($comparePrice) $variant['compareAtPrice'] = (string)$comparePrice;
            $shopifyVariants[] = $variant;
        }
    } else {
        // Default Variant
        $variant = [
            'sku' => (string)$sku,
            'price' => (string)$price,
            'inventoryManagement' => 'SHOPIFY',
            'inventoryQuantities' => [
                ['availableQuantity' => 10, 'locationId' => 'gid://shopify/Location/123456789']
            ]
        ];
        if ($comparePrice) $variant['compareAtPrice'] = (string)$comparePrice;
        $shopifyVariants[] = $variant;
    }

    $input = [
        'title' => $title,
        'descriptionHtml' => $description,
        'vendor' => $vendor,
        'productType' => $productType,
        'tags' => ['Best Buy', 'Imported'],
        'metafields' => $metafields,
        'variants' => $shopifyVariants
    ];
    
    if (!empty($productOptions)) {
        $input['options'] = $productOptions;
    }

    return $input;
}

function processImport($productJson, $shopify, $pdo) {
    if (empty($productJson['sku'])) {
        logMessage("Skipping product with no SKU.", "WARNING");
        return;
    }

    $sku = $productJson['sku'];
    
    try {
        $stmt = $pdo->prepare("SELECT shopify_product_id FROM imported_products WHERE sku = ?");
        $stmt->execute([$sku]);
        $existing = $stmt->fetchColumn();

        $input = mapProductToShopifyInput($productJson);
        $productId = null;

        if ($existing) {
            // Update
            logMessage("Updating existing product SKU: $sku");
            $input['id'] = $existing;
            $productId = $shopify->updateProduct($input);
        } else {
            // Try to find in Shopify directly just in case DB is out of sync
            $shopifyFoundId = $shopify->findProductBySKU($sku);
            if ($shopifyFoundId) {
                logMessage("Found existing product in Shopify (not in DB) SKU: $sku");
                $input['id'] = $shopifyFoundId;
                $productId = $shopify->updateProduct($input);
                
                $stmt = $pdo->prepare("INSERT INTO imported_products (sku, bb_url, shopify_product_id) VALUES (?, ?, ?)");
                $stmt->execute([$sku, $productJson['url'] ?? '', $productId]);
            } else {
                // Create
                logMessage("Creating new product SKU: $sku");
                $productId = $shopify->createProduct($input);
                
                if ($productId) {
                    $stmt = $pdo->prepare("INSERT INTO imported_products (sku, bb_url, shopify_product_id) VALUES (?, ?, ?)");
                    $stmt->execute([$sku, $productJson['url'] ?? '', $productId]);
                }
            }
        }

        // Handle Images separately (avoid duplicates if possible, or clear and set)
        if ($productId && !empty($productJson['images'])) {
            // Pick a few distinct images to avoid overloading
            $imagesToUpload = array_unique(array_slice($productJson['images'], 0, 5));
            $shopify->appendImages($productId, $imagesToUpload);
        }

    } catch (Exception $e) {
        logMessage("Error processing SKU {$sku}: " . $e->getMessage(), "ERROR");
    }
}

// ----------------- MAIN EXECUTION FLOW -----------------

$jsonFile = __DIR__ . '/bestbuy-filtered-products.json';

if (file_exists($jsonFile)) {
    logMessage("Reading products from $jsonFile...");
    $jsonData = file_get_contents($jsonFile);
    $products = json_decode($jsonData, true);

    if ($products && is_array($products)) {
        logMessage("Found " . count($products) . " products to import.");
        foreach ($products as $product) {
            processImport($product, $shopify, $pdo);
        }
        logMessage("Import process completed.");
    } else {
        logMessage("Error: Could not parse JSON data or file is empty.", "ERROR");
    }
} else {
    logMessage("Error: File $jsonFile does not exist.", "ERROR");
}
