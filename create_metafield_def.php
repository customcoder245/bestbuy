<?php
require_once __DIR__ . '/config.php';

function createMetafieldDefinition() {
    $query = 'mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
        metafieldDefinitionCreate(definition: $definition) {
            createdDefinition {
                id
                name
            }
            userErrors {
                field
                message
            }
        }
    }';

    $variables = [
        'definition' => [
            'name' => 'Best Buy SKU',
            'namespace' => 'custom',
            'key' => 'best_buy_sku',
            'type' => 'single_line_text_field',
            'ownerType' => 'PRODUCT',
            'description' => 'Original Best Buy SKU',
            'pin' => true
        ]
    ];

    $url = "https://" . getenv('SHOPIFY_STORE') . "/admin/api/" . getenv('SHOPIFY_API_VERSION') . "/graphql.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . getenv('SHOPIFY_ADMIN_TOKEN')
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'variables' => $variables]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    print_r(json_decode($response, true));
}

createMetafieldDefinition();
