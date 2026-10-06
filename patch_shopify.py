import sys

with open('shopify_import.php', 'r', encoding='utf-8') as f:
    code = f.read()

import re

code = re.sub(
    r'(logMsg\("Found " \. count\(\$existingProducts\) \. " unique SKUs already in Shopify\."\);\s+)(foreach \(\$validProducts)',
    r'\1$onlineStorePubId = getOnlineStorePublicationId();\n  logMsg("Online Store Publication ID: " . ($onlineStorePubId ?: "Not found"));\n\n  \2',
    code
)

code = re.sub(
    r'(logMsg\("\s+=> SUCCESS: Created \(\$productId\)"\);\s+\$createdCount\+\+;)',
    r'\1\n\n    if ($onlineStorePubId) {\n        publishProduct($productId, $onlineStorePubId);\n    }',
    code
)

with open('shopify_import.php', 'w', encoding='utf-8') as f:
    f.write(code)

print("Patch applied")

