<?php
$data = json_decode(file_get_contents('bestbuy-products.json'), true);
foreach ($data as $item) {
    if (isset($item['breadcrumbs'])) {
        print_r($item['breadcrumbs']);
        break;
    }
}
