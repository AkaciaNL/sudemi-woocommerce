# sudemi Connector for WooCommerce

Lists a WooCommerce shop's catalogue on [sudemi](https://sudemi.com), so an AI assistant can
find the products and send buyers to the shop. The shop stays the merchant of record: sudemi
never takes the money and never ships anything.

## What it sends

Shop metadata, the category tree, products with their descriptions and specifications, image
URLs, prices and stock. Prices and stock go again as they change, from WooCommerce's own save
hook, so the two agree within seconds rather than within an import cycle.

## What it cannot do

The shop address, the legal name, the country of establishment, the trader status and the VAT
number are **confirmed once on sudemi and locked afterwards**. A buyer's withdrawal rights turn
on those being true, and a listing tells a buyer which shop it came from - so they are not
editable from inside a plugin that anyone can install.

The plugin also cannot list the shop. Products stay unlisted until the payment rail reports that
the seller can actually receive money.

## How the connection is made

1. You press **Connect this shop**. The plugin generates an Ed25519 keypair on your server.
2. The private key stays on your server, permanently. sudemi is sent the public half only.
3. The plugin serves a one-time token at `/.well-known/sudemi-challenge`.
4. You open the link it shows, sign in to **your own** sudemi account, and confirm the shop.
5. sudemi fetches that token back from your shop to confirm the request came from here.
6. The connection completes and the token stops being served.

The plugin never asks for a sudemi password, and could not use one if it had it.

## Security

The private key never leaves the server, is never logged, and is not shown in this plugin's own
screens. A breach of sudemi's database cannot produce a signature for your shop, because sudemi
stores only public keys.

Every request carries a timestamp, a nonce and a signature over the method, the path and a hash
of the body, so a captured request cannot be replayed and a price cannot be altered in transit.

Found a vulnerability? Mail **security@sudemi.com** rather than opening an issue.

## Requirements

WordPress 6.2+, WooCommerce 8.0+, PHP 8.0+ with the **sodium** extension, which is bundled with
PHP 7.2 and later and enabled in nearly every distribution build. The plugin checks for it and
says so plainly rather than failing later.

## Licence

GPL-2.0-or-later, as WordPress plugins must be.
