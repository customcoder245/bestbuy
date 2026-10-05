<?php
require_once __DIR__ . '/config.php';

$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ADMIN_TOKEN');
$apiVersion   = getenv('SHOPIFY_API_VERSION') ?: '2025-01';

function fetchShopifyProducts($queryStr = "", $after = "", $before = "") {
    global $shopifyStore, $shopifyToken, $apiVersion;
    $store = str_replace(['https://','http://'], '', $shopifyStore);
    $ch = curl_init("https://{$store}/admin/api/{$apiVersion}/graphql.json");
    
    $direction = $before ? "last: 50" : "first: 50";
    
    $graphqlQuery = '
    query getProducts($query: String, $after: String, $before: String) {
      productsCount(query: $query) { count }
      products(' . $direction . ', sortKey: UPDATED_AT, reverse: true, query: $query, after: $after, before: $before) {
        pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
        edges {
          node {
            id
            title
            status
            vendor
            featuredImage { url }
          }
        }
      }
    }';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $shopifyToken"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "query" => $graphqlQuery, 
            "variables" => [
                "query" => $queryStr ?: null,
                "after" => $after ?: null,
                "before" => $before ?: null
            ]
        ])
    ]);
    
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $products = [];
    $pageInfo = ['hasNextPage' => false, 'hasPreviousPage' => false, 'startCursor' => '', 'endCursor' => ''];
    $totalCount = 0;
    
    if (isset($resp['data'])) {
        $totalCount = $resp['data']['productsCount']['count'] ?? 0;
        if (!empty($resp['data']['products'])) {
            $pageInfo = $resp['data']['products']['pageInfo'];
            if (!empty($resp['data']['products']['edges'])) {
                foreach ($resp['data']['products']['edges'] as $edge) {
                    $products[] = $edge['node'];
                }
            }
        }
    }
    return ['products' => $products, 'pageInfo' => $pageInfo, 'totalCount' => $totalCount];
}

// Handle Bulk Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $ids = $_POST['product_ids'] ?? [];
    foreach ($ids as $id) {
        $store = str_replace(['https://','http://'], '', $shopifyStore);
        $ch = curl_init("https://{$store}/admin/api/{$apiVersion}/graphql.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "X-Shopify-Access-Token: $shopifyToken"],
            CURLOPT_POSTFIELDS => json_encode([
                "query" => 'mutation del($id: ID!) { productDelete(input: {id: $id}) { deletedProductId } }', 
                "variables" => ["id" => $id]
            ])
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    // Refresh page
    header("Location: ?search=" . urlencode($_GET['search'] ?? ''));
    exit;
}

$searchQuery = $_GET['search'] ?? '';
$afterCursor = $_GET['after'] ?? '';
$beforeCursor = $_GET['before'] ?? '';

$fetchResult = fetchShopifyProducts($searchQuery ? "title:*{$searchQuery}*" : "", $afterCursor, $beforeCursor);
$products = $fetchResult['products'];
$pageInfo = $fetchResult['pageInfo'];
$totalCount = $fetchResult['totalCount'];

// Helper to build URLs
function buildUrl($params) {
    $current = $_GET;
    // Remove cursor keys
    unset($current['after'], $current['before']);
    return "?" . http_build_query(array_merge($current, $params));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen font-sans">

    <!-- Header / Navbar -->
    <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Products</h1>
            <div class="flex items-center gap-4">
                <a href="settings.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <button type="button" onclick="syncProducts(this)" class="bg-black hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm flex items-center gap-2">
                    <i class="fas fa-sync-alt" id="sync-icon"></i> <span id="sync-text">Sync Products</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Table Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            
            <!-- Toolbar (Search) -->
            <div class="p-4 border-b border-gray-200 flex bg-gray-50/50">
                
                <!-- Search Form -->
                <form method="GET" action="" class="relative w-full sm:w-96 flex">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input 
                        type="text" 
                        name="search" 
                        value="<?php echo htmlspecialchars($searchQuery); ?>" 
                        class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-all" 
                        placeholder="Search products..."
                    >
                    <?php if ($searchQuery): ?>
                        <a href="?" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Bulk Actions & Table Form -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete">
                
                <!-- Bulk Actions Bar -->
                <div id="bulk-actions" class="hidden px-4 py-2 bg-blue-50/80 border-b border-gray-200 flex justify-between items-center transition-all">
                    <span class="text-sm font-medium text-blue-800"><span id="selected-count">0</span> products selected</span>
                    <button type="submit" onclick="return confirm('Are you sure you want to delete these products from Shopify? This cannot be undone.')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium shadow-sm transition-colors flex items-center gap-2">
                        <i class="far fa-trash-alt"></i> Delete products
                    </button>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left w-12">
                                    <input type="checkbox" id="selectAll" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded cursor-pointer">
                                </th>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-gray-500">Product</th>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-gray-500">Vendor</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                        No products found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <tr class="hover:bg-gray-50 transition-colors group cursor-pointer" onclick="const cb=this.querySelector('.row-check'); if(event.target.tagName !== 'INPUT'){ cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox" name="product_ids[]" value="<?php echo htmlspecialchars($p['id']); ?>" class="row-check h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded cursor-pointer">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 border border-gray-200 rounded-md overflow-hidden bg-gray-100 flex items-center justify-center">
                                                    <?php if (!empty($p['featuredImage']['url'])): ?>
                                                        <img class="h-10 w-10 object-cover" src="<?php echo htmlspecialchars($p['featuredImage']['url']); ?>" alt="">
                                                    <?php else: ?>
                                                        <i class="far fa-image text-gray-400"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 group-hover:text-blue-600 transition-colors">
                                                        <?php echo htmlspecialchars($p['title']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (strtoupper($p['status']) === 'ACTIVE'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                    Active
                                                </span>
                                            <?php elseif (strtoupper($p['status']) === 'DRAFT'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                    Draft
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-200">
                                                    <?php echo htmlspecialchars($p['status']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($p['vendor']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Footer / Pagination -->
            <div class="bg-white px-4 py-3 border-t border-gray-200 flex items-center justify-between sm:px-6">
                <div class="text-sm text-gray-700">
                    Showing <span class="font-medium"><?php echo count($products); ?></span> products out of <span class="font-medium"><?php echo $totalCount; ?></span>
                </div>
                <div class="flex gap-2 text-sm text-gray-500">
                    <?php if ($pageInfo['hasPreviousPage'] && $pageInfo['startCursor']): ?>
                        <a href="<?php echo htmlspecialchars(buildUrl(['before' => $pageInfo['startCursor']])); ?>" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50 text-gray-700">Previous</a>
                    <?php else: ?>
                        <button class="px-3 py-1 border border-gray-300 rounded bg-gray-50 text-gray-400 cursor-not-allowed" disabled>Previous</button>
                    <?php endif; ?>
                    
                    <?php if ($pageInfo['hasNextPage'] && $pageInfo['endCursor']): ?>
                        <a href="<?php echo htmlspecialchars(buildUrl(['after' => $pageInfo['endCursor']])); ?>" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50 text-gray-700">Next</a>
                    <?php else: ?>
                        <button class="px-3 py-1 border border-gray-300 rounded bg-gray-50 text-gray-400 cursor-not-allowed" disabled>Next</button>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <!-- Toast Container -->
        <div id="toast-container" class="fixed bottom-6 right-6 z-50 flex flex-col gap-3"></div>

    </main>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const masterCheck = document.getElementById('selectAll');
            const rowChecks = document.querySelectorAll('.row-check');
            const bulkBar = document.getElementById('bulk-actions');
            const countSpan = document.getElementById('selected-count');

            function updateState() {
                const checked = document.querySelectorAll('.row-check:checked').length;
                countSpan.textContent = checked;
                if (checked > 0) {
                    bulkBar.classList.remove('hidden');
                } else {
                    bulkBar.classList.add('hidden');
                    if (masterCheck) masterCheck.checked = false;
                }
            }

            if (masterCheck) {
                masterCheck.addEventListener('change', (e) => {
                    rowChecks.forEach(cb => cb.checked = e.target.checked);
                    updateState();
                });
            }

            rowChecks.forEach(cb => {
                cb.addEventListener('change', (e) => {
                    e.stopPropagation(); 
                    updateState();
                });
            });
        });

        function showToast(message, duration = 8000) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            // Replicate the exact dark toast style with purple progress bar, no close button
            toast.className = 'bg-[#121212] text-white w-[320px] shadow-2xl overflow-hidden relative transform transition-all duration-300 translate-y-4 opacity-0 flex flex-col rounded border border-gray-800';
            
            toast.innerHTML = `
                <div class="px-5 py-4 flex items-start">
                    <span class="text-[15px] font-medium leading-snug">${message}</span>
                </div>
                <div class="h-[3px] w-full bg-[#121212]">
                    <div class="h-full bg-[#a87ffb] w-full toast-progress" style="transition: width ${duration}ms linear;"></div>
                </div>
            `;
            
            container.appendChild(toast);
            
            // Trigger animations
            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
                const progress = toast.querySelector('.toast-progress');
                requestAnimationFrame(() => {
                    progress.style.width = '0%';
                });
            });
            
            // Auto-remove
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-8');
                setTimeout(() => {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 300);
            }, duration);
        }

        function syncProducts(btn) {
            const icon = document.getElementById('sync-icon');
            const text = document.getElementById('sync-text');
            
            // Visual feedback
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            icon.classList.add('fa-spin');
            text.textContent = 'Syncing...';
            
            // Run import script in background
            fetch('trigger_sync.php');
            
            // Open Bright Data scraper dashboard in a new tab
            window.open('https://brightdata.com/cp/scrapers/gd_ltre1jqe1jfr7cccf/keywords/snapshots?nav_from=my_scrapers&id=hl_c373fc90', '_blank');

            // Use the new toast instead of alert()
            showToast('Sync started! BestBuy scraper is running. Products will appear when the 15-minute process finishes.');
            
            // Reset button immediately
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
            icon.classList.remove('fa-spin');
            text.textContent = 'Sync Products';
        }
    </script>
</body>
</html>
