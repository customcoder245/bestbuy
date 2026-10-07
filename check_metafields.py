import urllib.request, json
import sys

with open('.env') as f:
    env = dict(line.strip().split('=', 1) for line in f if '=' in line)

store = env.get('SHOPIFY_STORE', '').replace('https://', '')
token = env.get('SHOPIFY_ADMIN_TOKEN', '')
headers = {'X-Shopify-Access-Token': token, 'Content-Type': 'application/json'}

q = """
{
  metafieldDefinitions(first: 50, ownerType: PRODUCT) {
    edges {
      node {
        namespace
        key
        name
        type { name }
      }
    }
  }
}
"""

req = urllib.request.Request(f'https://{store}/admin/api/2025-01/graphql.json', headers=headers, data=json.dumps({'query': q}).encode('utf-8'))
res = urllib.request.urlopen(req)
print(json.dumps(json.loads(res.read()), indent=2))

