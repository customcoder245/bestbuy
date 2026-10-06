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
    print("Missing Shopify credentials in .env")
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

# 1. Get publication ID for Online Store
pub_query = """
query {
  publications(first: 10) {
    edges {
      node {
        id
        name
      }
    }
  }
}
"""
res = run_graphql(pub_query)
pub_id = None
for edge in res.get("data", {}).get("publications", {}).get("edges", []):
    if edge["node"]["name"] == "Online Store":
        pub_id = edge["node"]["id"]
        break

if not pub_id:
    print("Could not find Online Store publication ID.")
    sys.exit(1)

print(f"Online Store Publication ID: {pub_id}")

# 2. Get all unpublished Bestbuy products
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
        publishedAt
      }
    }
  }
}
"""

publish_mut = """
mutation publishablePublish($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    userErrors {
      field
      message
    }
  }
}
"""

has_next = True
cursor = None
published_count = 0

while has_next:
    vars_ = {"cursor": cursor}
    res = run_graphql(get_products_query, vars_)
    products = res.get("data", {}).get("products", {})
    edges = products.get("edges", [])
    
    for edge in edges:
        node = edge["node"]
        if not node.get("publishedAt"):
            title = node.get("title", "")
            print(f"Publishing: {title.encode('ascii', 'ignore').decode('ascii')}")
            run_graphql(publish_mut, {
                "id": node["id"],
                "input": [{"publicationId": pub_id}]
            })
            published_count += 1
            time.sleep(0.3)
            
    page_info = products.get("pageInfo", {})
    has_next = page_info.get("hasNextPage", False)
    cursor = page_info.get("endCursor")

print(f"Done. Published {published_count} products.")

