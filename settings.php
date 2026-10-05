<?php
$settingsFile = __DIR__ . '/settings.json';

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'tax_fee' => (float)($_POST['tax_fee'] ?? 10),
        'shipping' => (float)($_POST['shipping'] ?? 20),
        'exchange_rate' => (float)($_POST['exchange_rate'] ?? 3595)
    ];
    file_put_contents($settingsFile, json_encode($newSettings, JSON_PRETTY_PRINT));
    
    // Output success for AJAX
    echo json_encode(["status" => "success"]);
    exit;
}

$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$taxFee = $settings['tax_fee'] ?? 10;
$shipping = $settings['shipping'] ?? 20;
$exchangeRate = $settings['exchange_rate'] ?? 3595;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen font-sans">

    <!-- Header / Navbar -->
    <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="product-list.php" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-arrow-left text-lg"></i>
                </a>
                <h1 class="text-2xl font-semibold text-gray-900">Settings</h1>
            </div>
            <div class="flex items-center gap-4">
                <a href="product-list.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    Cancel
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">Global Pricing Formula</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Adjust the parameters used to calculate the final MNT price for imported products.
                </p>
            </div>
            
            <div class="p-6 space-y-6">
                <!-- Tax & Service Fee -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tax & Service Fee (%)</label>
                    <div class="mt-1 relative rounded-md shadow-sm w-1/2">
                        <input type="number" step="0.1" id="tax_fee" value="<?php echo htmlspecialchars($taxFee); ?>" class="block w-full pr-10 border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 sm:text-sm border p-2">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 sm:text-sm">%</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">Percentage added to the base USD price.</p>
                </div>

                <!-- Shipping -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Shipping Fee (Total USD)</label>
                    <div class="mt-1 relative rounded-md shadow-sm w-1/2">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 sm:text-sm">$</span>
                        </div>
                        <input type="number" step="1" id="shipping" value="<?php echo htmlspecialchars($shipping); ?>" class="block w-full pl-7 border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 sm:text-sm border p-2">
                    </div>
                    <p class="mt-2 text-sm text-gray-500">Flat shipping rate added per product (e.g. 2kg × $10 = $20).</p>
                </div>

                <!-- Exchange Rate -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Exchange Rate (1 USD = ? MNT)</label>
                    <div class="mt-1 relative rounded-md shadow-sm w-1/2">
                        <input type="number" step="1" id="exchange_rate" value="<?php echo htmlspecialchars($exchangeRate); ?>" class="block w-full pr-12 border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 sm:text-sm border p-2">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 sm:text-sm">MNT</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-50 px-6 py-4 flex justify-end">
                <button type="button" onclick="showModal()" class="bg-black hover:bg-gray-800 text-white px-5 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">
                    Save Settings
                </button>
            </div>
        </div>
    </main>

    <!-- Modal overlay -->
    <div id="confirmModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 transition-opacity">
        <div class="bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas fa-info-circle text-blue-600"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Update All Products?</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">
                                Are you sure you want to update these pricing rules? This will automatically recalculate and update the prices for all existing products in your Shopify store.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="confirmBtn" onclick="submitSettings(this)" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm transition-colors">
                    Yes, Update Automatically
                </button>
                <button type="button" onclick="hideModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('confirmModal');
        
        function showModal() {
            modal.classList.remove('hidden');
        }
        
        function hideModal() {
            modal.classList.add('hidden');
        }

        function submitSettings(btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';
            
            const data = new FormData();
            data.append('tax_fee', document.getElementById('tax_fee').value);
            data.append('shipping', document.getElementById('shipping').value);
            data.append('exchange_rate', document.getElementById('exchange_rate').value);

            // 1. Save Settings
            fetch('settings.php', { method: 'POST', body: data })
            .then(res => res.json())
            .then(() => {
                // 2. Trigger price update script in the background
                fetch('update_prices.php');
                
                // 3. Instantly redirect back to product list
                window.location.href = 'product-list.php';
            })
            .catch(err => {
                alert('An error occurred.');
                hideModal();
                btn.disabled = false;
                btn.innerHTML = 'Yes, Update Automatically';
            });
        }
    </script>
</body>
</html>
