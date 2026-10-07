import os, sys, json, urllib.request, time

with open('.env') as f:
    env = dict(line.strip().split('=', 1) for line in f if '=' in line)

SHOPIFY_STORE = env.get('SHOPIFY_STORE', '').replace('https://', '')
SHOPIFY_ACCESS_TOKEN = env.get('SHOPIFY_ADMIN_TOKEN', '')
HEADERS = {
    "X-Shopify-Access-Token": SHOPIFY_ACCESS_TOKEN,
    "Content-Type": "application/json",
    "Accept": "application/json"
}
URL = f"https://{SHOPIFY_STORE}/admin/api/2025-01/graphql.json"

def run_graphql(query, variables=None):
    payload = {"query": query}
    if variables:
        payload["variables"] = variables
    req = urllib.request.Request(URL, headers=HEADERS, data=json.dumps(payload).encode('utf-8'))
    for _ in range(3):
        try:
            res = urllib.request.urlopen(req)
            return json.loads(res.read().decode('utf-8'))
        except Exception as e:
            time.sleep(1)
    return {}

print("Fetching variants...")
variants = {}
has_next = True
cursor = None
while has_next:
    q = '''
    query getVars($cursor: String) {
        productVariants(first: 250, after: $cursor) {
            edges { cursor node { id sku } }
            pageInfo { hasNextPage }
        }
    }
    '''
    res = run_graphql(q, {"cursor": cursor})
    edges = res.get("data", {}).get("productVariants", {}).get("edges", [])
    for e in edges:
        sku = e["node"]["sku"]
        if sku:
            variants[sku] = e["node"]["id"]
    page_info = res.get("data", {}).get("productVariants", {}).get("pageInfo", {})
    has_next = page_info.get("hasNextPage", False)
    if edges:
        cursor = edges[-1]["cursor"]

print(f"Found {len(variants)} variants.")

with open('bestbuy-products.json', 'r', encoding='utf-8') as f:
    jsondata = json.load(f)

try:
    with open('settings.json', 'r') as f:
        settings = json.load(f)
except:
    settings = {}

tax_fee_percent = float(settings.get('tax_fee', 10))
shipping = float(settings.get('shipping', 20))
exchange_rate = float(settings.get('exchange_rate', 3595))

updates = {}

for product in jsondata:
    if 'error' in product: continue
    sku = str(product.get('product_id', product.get('sku', ''))).strip()
    if not sku: continue
    
    orig_str = str(product.get('initial_price', product.get('price', '0'))).replace('$', '').replace(',', '')
    curr_str = str(product.get('final_price', product.get('sale_price', '0'))).replace('$', '').replace(',', '')
    
    try:
        original_usd = float(orig_str)
        current_usd = float(curr_str)
    except:
        continue
        
    current_tax = current_usd * (tax_fee_percent / 100)
    current_total = current_usd + current_tax + shipping
    price_mnt = round(current_total * exchange_rate) + 100000
    
    original_tax = original_usd * (tax_fee_percent / 100)
    original_total = original_usd + original_tax + shipping
    compare_at_mnt = round(original_total * exchange_rate)
    
    if compare_at_mnt <= price_mnt:
        compare_at_mnt = price_mnt
        
    def add_update(s):
        if s in variants:
            updates[variants[s]] = {
                "id": variants[s],
                "price": str(price_mnt),
                "compareAtPrice": str(compare_at_mnt) if compare_at_mnt > price_mnt else None
            }
            
    add_update(sku)
    for v in product.get('variations', []):
        add_update(v.get('variant_sku', sku))

unique_updates = list(updates.values())
print(f"Updating {len(unique_updates)} variants...")

mut = '''
mutation($input: ProductVariantInput!) {
    productVariantUpdate(input: $input) {
        userErrors { message }
    }
}
'''
for i, u in enumerate(unique_updates):
    run_graphql(mut, {"input": u})
    if i > 0 and i % 20 == 0:
        print(f"Batch {i}/{len(unique_updates)} done.")
        time.sleep(0.5)

print("Done.")

