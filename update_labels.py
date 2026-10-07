import os
import sys
import json
import urllib.request
import time

with open('.env') as f:
    env = dict(line.strip().split('=', 1) for line in f if '=' in line)

SHOPIFY_STORE = env.get('SHOPIFY_STORE', '').replace('https://', '')
SHOPIFY_ACCESS_TOKEN = env.get('SHOPIFY_ADMIN_TOKEN', '')

if not SHOPIFY_STORE or not SHOPIFY_ACCESS_TOKEN:
    print("Missing Shopify credentials")
    sys.exit(1)

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
    try:
        response = urllib.request.urlopen(req)
        return json.loads(response.read().decode('utf-8'))
    except Exception as e:
        print("Error:", e)
        sys.exit(1)

get_products_query = """
query getProducts($cursor: String) {
  products(first: 50, query: "product_type:Bestbuy status:ACTIVE", after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        title
      }
    }
  }
}
"""

update_mut = """
mutation productUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    userErrors {
      field
      message
    }
  }
}
"""

has_next = True
cursor = None
count = 0

while has_next:
    vars_ = {"cursor": cursor}
    res = run_graphql(get_products_query, vars_)
    products = res.get("data", {}).get("products", {})
    edges = products.get("edges", [])
    
    for edge in edges:
        node = edge["node"]
        print(f"Updating labels for: {node['title'].encode('ascii', 'ignore').decode('ascii')}")
        
        # Send update with both color and single_line_text_field types. 
        # Actually I will send them as single_line_text_field, if color fails I will know.
        run_graphql(update_mut, {
            "input": {
                "id": node["id"],
                "metafields": [
                    {
                        "namespace": "theme",
                        "key": "label",
                        "value": "Захиалгаар",
                        "type": "single_line_text_field"
                    },
                    {
                        "namespace": "theme",
                        "key": "label_color",
                        "value": "#D93A6C",
                        "type": "color"
                    }
                ]
            }
        })
        count += 1
        time.sleep(0.3)
            
    page_info = products.get("pageInfo", {})
    has_next = page_info.get("hasNextPage", False)
    cursor = page_info.get("endCursor")

print(f"Done. Updated {count} products.")

