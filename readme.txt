=== sudemi Connector ===
Contributors: akacia
Tags: woocommerce, marketplace, ai, product feed
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.2.6
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

No. It sends your catalogue - products, prices, stock, images - and nothing about your orders or
your customers.

= What if my host does not have the sodium extension? =

The plugin tells you on its settings screen and refuses to connect, rather than failing later.
Ask your host to enable it; it ships with PHP 7.2 and later.

== Changelog ==

= 1.2.6 =
* The full sync's commit carries the catalogue revision the shop had when the sync started. sudemi adopts it, so a product changed or removed while the sync ran is caught by the next heartbeat and synced. The heartbeat right after the commit is still sent.
* Saving a simple product (and a stock change) sends the product's whole listing (title, description, price, stock, categories, images, shipping) instead of price and stock only, with the shop's catalogue revision computed after the save when nothing else is waiting to be sent. sudemi adopts it when every item applied, so a product edit no longer costs a full catalogue sync. Variable products and their variations, a product that is not published and visible, and a push while queued price changes wait still go without the revision (a full sync follows, as before). When the category tree changed since it was last sent, it is sent first; if that fails, the push goes without the revision.
* A newer push for a product drops its older queued price, which could otherwise be sent after it and put the old price back.

= 1.2.5 =
* Order safety: a paid order is never cancelled because sudemi could not be reached. Only a definitive answer changes the WooCommerce order: "already answered, PAID" (an earlier confirmation landed and its reply was lost) keeps the order and moves it to Processing; DECLINED cancels it and restores the stock. No answer, a server error, "slow down" (429) or a refused site (409) leaves the order on hold with its stock reserved, and it is asked about again on the next heartbeat. Before, any of these cancelled the order and restocked it although the buyer could already have been charged.
* Orders still on hold are checked with sudemi on every heartbeat until sudemi says how they ended, so an order whose reply was lost, or whose window closed, no longer stays on hold forever.
* A sudemi order whose WooCommerce order was cancelled is refused on sudemi (the buyer is released), never accepted again.
* Cancelling an order that is accepted but still waiting for the other shops in the buyer's payment now releases the buyer on sudemi; before, sudemi was not told and could take the money later. If sudemi took the money in the meantime, the order is cancelled there with a full refund.
* Shipped and delivered reports (and those cancellations) that do not get through are queued and sent again on every heartbeat until sudemi answers; before, a failure was logged as a success and lost.
* Price changes queued while sudemi was unreachable: a newer price saved while an older one was being sent is no longer dropped, and a batch sudemi refuses for good is not resent forever (a full sync carries it).
* A catalogue sync that sudemi holds back (it would remove many products) is reported as on hold, not as synced.
* After a full catalogue sync the plugin tells sudemi at once which catalogue state the sync described. Before, sudemi adopted whatever the next heartbeat reported, so a product removed in the minutes after a sync could stay listed on sudemi.
* The sudemi screen shows when the last report succeeded, how many order updates are waiting, and how to set up a real cron job (WP-Cron only runs on visits; call wp-cron.php every 5 minutes and set DISABLE_WP_CRON). In all 12 languages.

= 1.2.4 =
* Return requests: after delivery a sudemi buyer may ask to return an order, and the shop answers within 7 days. Open requests now appear on the WooCommerce order screen and under WooCommerce → sudemi (Return requests), with the buyer's reason and the answer-by date. Accept, or reject with a reason (required; the buyer is shown it). Without an answer, sudemi decides. After accepting, refund the buyer once the item is back with a normal WooCommerce refund on the order; it goes through sudemi and closes the request. Each new request is also noted on its order. In all 12 languages.
* A buyer who cancels a paid order on sudemi before it ships (free of charge, refunded at once) now cancels the WooCommerce order too, with a note and the stock restored, so it is not shipped by mistake. The plugin does not send a second cancellation or refund for it.

= 1.2.3 =
* Every signed call now says which site it comes from (the Sudemi-Site header, this WordPress site's own address). A staging copy or a restored backup of the shop holds the same connection, and sudemi now refuses its calls, so a copy can no longer push its catalogue or answer orders as the live shop.
* When sudemi refuses this site because the shop is connected under another address, the sudemi screen says so: if this site is a staging copy or a restored backup, disconnect sudemi there; if the shop moved, connect it again. In all 12 languages.

= 1.2.2 =
* New Support tab on WooCommerce → sudemi (once the shop is connected): open a ticket with sudemi support (category, subject, message, optionally a sudemi order ID), see this shop's tickets with their status, read the conversation and reply. Replying to a closed ticket opens it again. Calls are signed with the shop's key like every other call; the plugin sends no e-mail itself (sudemi e-mails the shop's contact address when support answers, in the admin's language). In all 12 languages.

= 1.2.1 =
* New per-product setting (Product data → sudemi tab): "Right of withdrawal". For products that legally fall under an exception of the EU right of withdrawal (Directive 2011/83/EU, art. 16): sealed for hygiene reasons, made to the buyer's specifications or personalised, perishable, sealed audio/video/software, newspapers and magazines, inseparably mixed after delivery, or alcohol with a market-dependent price delivered after 30+ days. sudemi shows the exception to the buyer before payment. Default: none (the normal right of withdrawal applies). Variations follow their parent product. A change re-sends the catalogue within a minute.

= 1.2.0 =
* Shipping prices are now sent to sudemi, read from your WooCommerce shipping zones (flat rate with a fixed cost, plus the product's shipping-class cost; unconditional free shipping). Without them sudemi buyers could not find or order your products. Countries without a fixed price are left out, not guessed.
* Delivery times: two new settings on the sudemi screen (days until dispatch, days in transit), sent with every product.
* The product barcode (WooCommerce's GTIN/EAN/UPC/ISBN field) and brand are sent, so your offer can appear next to other shops selling the same item.
* Changing a shipping zone or method re-sends the catalogue.
* Fixed: stock was reduced twice for an order received through sudemi.
* Refunds made in WooCommerce (full or partial) and cancelling a paid order are passed to sudemi, which holds the buyer's payment and pays it back; the outcome is noted on the order.
* Fixed: shops that enter prices without tax sold on sudemi without the VAT. Prices are sent as buyers pay them (with VAT), and taxable shipping costs include the destination's shipping tax.
* Variable products: each variation (size, colour...) is sent as its own listing with its own price, stock, SKU and barcode, and an order adds exactly that variation. The variable parent, grouped and external products are no longer sent (they cannot be bought as one item).

= 1.1.0 =
* One payment per cart: when the buyer paid several shops at once, an accepted order stays On hold (not paid, no address) until every shop has answered. It then moves to Processing, or is cancelled with the stock restored if the payment was released.
* Fixed: a failed order answer no longer stops the heartbeat (missing error log level).

= 1.0.0 =
* First release.
