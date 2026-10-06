import urllib.request, json
with open('.env') as f: env = dict(line.strip().split('=', 1) for line in f if '=' in line)
store = env['SHOPIFY_STORE'].replace('https://', '')
token = env['SHOPIFY_ADMIN_TOKEN']
q = '{ products(first: 10, sortKey: CREATED_AT, reverse: true) { edges { node { id title productType tags publishedAt } } } }'
req = urllib.request.Request(f'https://{store}/admin/api/2025-01/graphql.json', headers={'Content-Type': 'application/json', 'X-Shopify-Access-Token': token}, data=json.dumps({'query': q}).encode('utf-8'))
print(json.dumps(json.loads(urllib.request.urlopen(req).read().decode('utf-8')), indent=2))

