<?php
require_once __DIR__ . '/config.php';
set_time_limit(0);

echo "Starting Bright Data Scraper...\n";
include __DIR__ . '/bright_data.php';
echo "Scraper Finished.\n\nStarting Shopify Import...\n";
include __DIR__ . '/shopify_import.php';
echo "Sync Complete.\n";

