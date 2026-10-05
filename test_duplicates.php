<?php
$data = json_decode(file_get_contents('bestbuy-products.json'), true);
foreach ($data as $item) {
    if (isset($item['title']) && strpos($item['title'], 'iPad A16') !== false) {
        print_r($item['variations']);
        break;
    }
}
