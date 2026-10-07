import urllib.request, json
env = dict(line.strip().split('=', 1) for line in open('.env') if '=' in line)
req = urllib.request.Request(
    f'https://{env.get("SHOPIFY_STORE", "").replace("https://", "")}/admin/api/2025-01/graphql.json',
    headers={'X-Shopify-Access-Token': env.get('SHOPIFY_ADMIN_TOKEN', ''), 'Content-Type': 'application/json'},
    data=json.dumps({'query': '{ publications(first: 10) { edges { node { id name } } } }'}).encode('utf-8')
)
res = urllib.request.urlopen(req)
print(json.dumps(json.loads(res.read()), indent=2))

