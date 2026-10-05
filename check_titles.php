<?php
$data = json_decode(file_get_contents('bestbuy-products.json'), true);
$titles = [];
foreach ($data as $item) {
    if (isset($item['title'])) {
        $sku = $item['product_id'] ?? $item['sku'] ?? 'none';
        $titles[$item['title']][] = $sku;
    }
}
foreach ($titles as $title => $skus) {
    if (count($skus) > 1) {
        echo "$title\n  " . implode(', ', $skus) . "\n";
    }
}

