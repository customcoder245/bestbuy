<?php
$j = json_decode(file_get_contents('bestbuy-products.json'), true);
foreach ($j as $p) {
    if (!empty($p['title'])) {
        print_r(array_keys($p));
        break;
    }
}
