=== sudemi Connector ===
Contributors: akacia
Tags: woocommerce, marketplace, ai, product feed
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

List your WooCommerce catalogue on sudemi, so an AI assistant can find your products and send buyers to you.

== Description ==

sudemi is a marketplace that an AI assistant can search on a shopper's behalf. This plugin keeps
your catalogue in step with it: categories, products, specifications, images, prices and stock.

You remain the merchant of record. sudemi does not take the payment and does not ship anything;
a buyer who finds your product is sent to you.

**What the plugin cannot change.** Your shop address, legal name, country, trader status and VAT
number are confirmed once on sudemi and locked afterwards, because a buyer's rights depend on
them being true.

**Your signing key never leaves your server.** The plugin generates an Ed25519 keypair on your
host and sends sudemi only the public half.

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce → sudemi and press Connect this shop.
3. Open the link shown, sign in to your sudemi account, and confirm the shop is yours.

== Frequently Asked Questions ==

= Does this send my customers' data anywhere? =

No. It sends your catalogue — products, prices, stock, images — and nothing about your orders or
your customers.

= What if my host does not have the sodium extension? =

The plugin tells you on its settings screen and refuses to connect, rather than failing later.
Ask your host to enable it; it ships with PHP 7.2 and later.

== Changelog ==

= 1.2.0 =
* Shipping prices are now sent to sudemi, read from your WooCommerce shipping zones (flat rate with a fixed cost, plus the product's shipping-class cost; unconditional free shipping). Without them sudemi buyers could not find or order your products. Countries without a fixed price are left out, not guessed.
* Delivery times: two new settings on the sudemi screen (days until dispatch, days in transit), sent with every product.
* The product barcode (WooCommerce's GTIN/EAN/UPC/ISBN field) and brand are sent, so your offer can appear next to other shops selling the same item.
* Changing a shipping zone or method re-sends the catalogue.
* Fixed: stock was reduced twice for an order received through sudemi.
* Fixed: shops that enter prices without tax sold on sudemi without the VAT. Prices are sent as buyers pay them (with VAT), and taxable shipping costs include the destination's shipping tax.
* Variable products: each variation (size, colour...) is sent as its own listing with its own price, stock, SKU and barcode, and an order adds exactly that variation. The variable parent, grouped and external products are no longer sent (they cannot be bought as one item).

= 1.1.0 =
* One payment per cart: when the buyer paid several shops at once, an accepted order stays On hold (not paid, no address) until every shop has answered. It then moves to Processing, or is cancelled with the stock restored if the payment was released.
* Fixed: a failed order answer no longer stops the heartbeat (missing error log level).

= 1.0.0 =
* First release.
