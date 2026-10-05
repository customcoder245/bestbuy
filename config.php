<?php
// c:\Xampp\htdocs\api\config.php

// Load .env variables if present, otherwise fallback to system environment variables
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

define('BRIGHT_DATA_TOKEN', getenv('BRIGHT_DATA_TOKEN'));
define('BRIGHT_DATA_DATASET_ID', getenv('BRIGHT_DATA_DATASET_ID'));

define('SHOPIFY_STORE', getenv('SHOPIFY_STORE')); // e.g., mystore.myshopify.com
define('SHOPIFY_ADMIN_TOKEN', getenv('SHOPIFY_ADMIN_TOKEN'));

// MySQL Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'shopify_import');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Price Calculation Settings
define('EXCHANGE_RATE_MNT', getenv('EXCHANGE_RATE_MNT') ?: 3595);
define('TAX_SERVICE_FEE_PERCENT', getenv('TAX_SERVICE_FEE_PERCENT') ?: 10);
define('SHIPPING_COST_PER_KG', getenv('SHIPPING_COST_PER_KG') ?: 10);
define('DEFAULT_WEIGHT_KG', getenv('DEFAULT_WEIGHT_KG') ?: 2); // Default weight if not found in data


// Setup basic logging
function logMessage($message, $type = 'INFO') {
    $date = date('Y-m-d H:i:s');
    $logEntry = "[$date] [$type] $message\n";
    file_put_contents(__DIR__ . '/import.log', $logEntry, FILE_APPEND);
    echo $logEntry;
}
