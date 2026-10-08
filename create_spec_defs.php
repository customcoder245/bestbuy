<?php
require_once __DIR__ . '/config.php';

$json = json_decode(file_get_contents(__DIR__ . '/bestbuy-products.json'), true);
$specs = [];

foreach ($json as $product) {
    if (!empty($product['product_specifications']) && is_array($product['product_specifications'])) {
        foreach ($product['product_specifications'] as $spec) {
            $name = trim($spec['specification_name'] ?? '');
            if (!$name) continue;
            
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
            $key = trim(preg_replace('/_+/', '_', $key), '_');
            
            if (strlen($key) > 64) $key = substr($key, 0, 64);
            
            // Just assume single_line for definition (Shopify doesn't let you mix types, but single line is standard)
            $specs[$key] = $name;
        }
    }
}

echo "Found " . count($specs) . " unique specifications.\n";

$url = "https://" . getenv('SHOPIFY_STORE') . "/admin/api/" . getenv('SHOPIFY_API_VERSION') . "/graphql.json";
$token = getenv('SHOPIFY_ADMIN_TOKEN');

// Process max 200 (since 250 is the limit, leave room for other custom fields)
$specs = array_slice($specs, 0, 245);

$count = 0;
foreach ($specs as $key => $name) {
    $count++;
    echo "[$count/".count($specs)."] Creating definition for: $name ($key)\n";
    
    $query = 'mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
        metafieldDefinitionCreate(definition: $definition) {
            createdDefinition { id name }
            userErrors { field message }
        }
    }';

    $variables = [
        'definition' => [
            'name' => substr($name, 0, 50),
            'namespace' => 'custom',
            'key' => $key,
            'type' => 'single_line_text_field', // Default to single line for definitions
            'ownerType' => 'PRODUCT',
            'pin' => false
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "X-Shopify-Access-Token: " . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'variables' => $variables]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    usleep(600000); // Wait 0.6 seconds to avoid Shopify API Rate Limits
    
    $res = json_decode($response, true);
    if (!empty($res['data']['metafieldDefinitionCreate']['userErrors'])) {
        $msg = $res['data']['metafieldDefinitionCreate']['userErrors'][0]['message'];
        if (strpos($msg, 'has already been taken') === false) {
             echo "Error: $msg\n";
        }
    }
}
echo "Done!\n";


