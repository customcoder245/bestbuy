<?php
// c:\Xampp\htdocs\api\shopify.php

class ShopifyAPI {
    private $store;
    private $token;
    private $apiVersion = '2024-01';

    public function __construct($store, $token) {
        $this->store = $store;
        $this->token = $token;
    }

    private function requestWithRetry($query, $variables = [], $maxRetries = 3) {
        $url = "https://{$this->store}/admin/api/{$this->apiVersion}/graphql.json";
        $payload = json_encode(['query' => $query, 'variables' => $variables]);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            $headers = [
                'X-Shopify-Access-Token: ' . $this->token,
                'Content-Type: application/json'
            ];

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                logMessage("Shopify cURL Error: $error", "ERROR");
            } elseif ($httpCode === 429) {
                logMessage("Shopify Rate Limit Hit. Retrying...", "WARNING");
                sleep(2 * $attempt);
                continue;
            } elseif ($httpCode >= 400) {
                logMessage("Shopify HTTP Error ($httpCode): $response", "ERROR");
            } else {
                $data = json_decode($response, true);
                if (isset($data['errors'])) {
                    throw new Exception("Shopify GraphQL Error: " . json_encode($data['errors']));
                }
                return $data['data'] ?? null;
            }
        }
        throw new Exception("Shopify API Request Failed after $maxRetries attempts.");
    }

    public function findProductBySKU($sku) {
        $query = '
        query findProduct($query: String!) {
          products(first: 1, query: $query) {
            edges {
              node {
                id
                title
              }
            }
          }
        }';
        
        $variables = ['query' => "sku:{$sku}"];
        $response = $this->requestWithRetry($query, $variables);
        
        if (!empty($response['products']['edges'])) {
            return $response['products']['edges'][0]['node']['id'];
        }
        return null;
    }

    public function createProduct($input) {
        $query = '
        mutation productCreate($input: ProductInput!) {
          productCreate(input: $input) {
            product {
              id
            }
            userErrors {
              field
              message
            }
          }
        }';

        $response = $this->requestWithRetry($query, ['input' => $input]);
        if (!empty($response['productCreate']['userErrors'])) {
            throw new Exception("Product Create Error: " . json_encode($response['productCreate']['userErrors']));
        }
        return $response['productCreate']['product']['id'] ?? null;
    }

    public function updateProduct($input) {
        $query = '
        mutation productUpdate($input: ProductInput!) {
          productUpdate(input: $input) {
            product {
              id
            }
            userErrors {
              field
              message
            }
          }
        }';

        $response = $this->requestWithRetry($query, ['input' => $input]);
        if (!empty($response['productUpdate']['userErrors'])) {
            throw new Exception("Product Update Error: " . json_encode($response['productUpdate']['userErrors']));
        }
        return $response['productUpdate']['product']['id'] ?? null;
    }

    public function appendImages($productId, $images) {
        if (empty($images)) return;
        
        $query = '
        mutation productAppendImages($input: ProductAppendImagesInput!) {
          productAppendImages(input: $input) {
            newImages {
              id
            }
            userErrors {
              field
              message
            }
          }
        }';

        $mediaInput = [];
        foreach ($images as $imgUrl) {
            $mediaInput[] = ['src' => $imgUrl];
        }

        $input = [
            'id' => $productId,
            'images' => $mediaInput
        ];

        $response = $this->requestWithRetry($query, ['input' => $input]);
        if (!empty($response['productAppendImages']['userErrors'])) {
            logMessage("Image Append Error: " . json_encode($response['productAppendImages']['userErrors']), "WARNING");
        }
    }
}
