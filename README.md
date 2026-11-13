# sudemi Connector for WooCommerce

Puts your WooCommerce shop's products in front of people who shop by asking an AI assistant such as ChatGPT or Claude.

[sudemi](https://sudemi.com) is a shopping service for AI assistants. A buyer asks their assistant for, say, a wool scarf delivered to Germany; the assistant searches sudemi, which searches the catalogues of all connected shops at once, and shows the buyer real offers with the shop's own price, stock and delivery cost. This plugin connects your shop to sudemi: it keeps your catalogue there up to date and brings sudemi's orders, statistics and settings into your WordPress admin.

The plugin is free. There is no monthly fee.

## What the plugin does

- **Lists your catalogue on sudemi**: categories, products with their descriptions and specifications, photos, prices, stock and delivery prices. Variable products: every variation is its own listing with its own price, stock and photo.
- **Keeps it current**: a price or stock change is sent the moment you save it, so sudemi and your shop agree within seconds, not after the next import.
- **Checks in every few minutes**, so sudemi only shows your products while your shop is online and its prices are current.
- **Brings sudemi into your WordPress admin**: statistics, the products as sudemi holds them, orders, delivery prices, payouts, your shop profile and support, under the **sudemi** item in the WordPress admin menu.
- **Shows how buyers pay for your products right now** on every sudemi page (see below), with a **Connect Stripe** button when Stripe is not connected yet.

## How buyers pay: two ways

Which way applies is decided automatically from your Stripe account; you never set it by hand. Every sudemi page in the plugin shows the current one at the top.

### Payment through sudemi (Stripe connected)

The buyer pays in a secure payment page without leaving the conversation with their assistant. Payments are processed by Stripe, a licensed payment provider. sudemi never sees or stores card details. Stripe holds the payment and passes your share on to your own Stripe account; sudemi keeps only its commission.

- The order arrives in your shop with the buyer's name and delivery address, so you can ship it.
- The buyer's spending limit, order tracking and sudemi support apply.
- **Cost per order**, both taken from your payout: Stripe's card processing fee (about 1.5% + 0.25 EUR for a card from the European Economic Area, more for other cards; each payment's exact fee as Stripe charged it) and sudemi's **1% commission** on your own price of each item. Delivery carries no commission.
- **Example:** a 50.00 EUR order paid with a card from the European Economic Area. Stripe's fee is 1.00 EUR and sudemi's commission is 0.50 EUR, so you receive 48.50 EUR.
- On a refund the commission on the refunded amount comes back to you; Stripe does not return its own fee. Declined orders cost nothing. Each month you receive a commission invoice.

The plugin shows a green **Connect Stripe is done**.

### Payment on your own website (Stripe not connected)

Your products are listed anyway, for free. The assistant sends the buyer to your own product page and the buyer pays in your own checkout. sudemi is not involved in the payment at all: no commission, and sudemi passes nothing about the buyer (no name, address or e-mail) to your shop. The link carries `utm_source=sudemi`, `utm_medium=ai_assistant` and a click id, so these visitors show up in your own analytics.

The plugin shows a red banner, **Stripe is not connected. Your products are listed on sudemi, Connect Stripe**, with a **Connect Stripe** button that opens the payout page of your sudemi seller account.

If Stripe restricts an account for risk or policy reasons, sudemi pauses the shop while it reviews the case, and the plugin says so with a link to support. How a shop is shown in search never depends on which way it is paid.

## Install

1. Download the plugin (free): https://sudemi.com/downloads/sudemi-woocommerce.zip. The SHA-256 checksum of every download is listed at https://sudemi.com/downloads/manifest.json
2. In WordPress go to **Plugins > Add New > Upload Plugin**.
3. Choose `sudemi-woocommerce.zip` as you downloaded it (do not unpack it) and press **Install Now**, then **Activate**.
4. Open **sudemi > Settings** and press **Connect this shop**.

## Connect your shop

1. You press the connect button. The plugin creates a key pair (Ed25519) on your own server.
2. The private key stays on your server for good. sudemi receives only the public half.
3. The plugin serves a one-time token at `/.well-known/sudemi-challenge` on your shop's domain.
4. You open the link the plugin shows, sign in to **your own** sudemi account and confirm the shop.
5. sudemi fetches the token back from your shop, which proves the request came from this shop's domain.
6. The connection completes and the token stops being served.

The plugin never asks for your sudemi password and could not use one. Your company details (legal name, address, country of establishment, VAT number) are confirmed once in the sudemi seller portal and are locked afterwards, because a buyer's right of withdrawal depends on them being true. The plugin cannot change them.

## The sudemi pages in your WordPress admin

Everything is under the **sudemi** item in the WordPress admin menu. Every page starts with the same header: your shop's name, whether buyers can see your products right now, how buyers pay (see above), and the page tabs.

| Page | What it shows |
|---|---|
| **Dashboard** | Sales, orders, average order, what you receive after refunds and commission, how often AI assistants showed your products in their search results, how often they opened one, how often a buyer put one in the cart, and the conversion. For the last 7, 30 or 90 days, with day-by-day charts, top products, orders by status and the countries your buyers are in. |
| **Products on sudemi** | Every product as sudemi holds it: photo, price, stock, whether buyers can find it and, if not, why (out of stock, no delivery price, switched off, shop not checking in, ...), and how often it was shown, viewed and ordered in the last 30 days. A switch per product hides it on sudemi only; your shop is not changed and a sync never switches it back on. |
| **Orders** | The orders buyers placed through sudemi, with filters and export. Each order shows its items, the money (sale, commission, what you receive, refunds), tracking, history and payout, and the actions sudemi allows at that moment: accept or decline, ship with carrier and tracking, mark delivered, refund in part or in full, cancel, and answer a return request. Every sudemi order is linked to the order it created in your shop. |
| **Shipping** | The delivery prices buyers pay per country. |
| **Payouts** | What Stripe paid out to you, what is expected and when. |
| **Shop profile** | The description, the support e-mail for buyers and your returns window (14 to 365 days), saved on sudemi. Your shop's regular update sends the same values, so an edit is never overwritten. The name buyers see is changed through sudemi support: one button opens a ticket that is already filled in. |
| **Support** | Your tickets with sudemi support, and a new one in a few clicks. |
| **Settings** | The connection, the check-in status, the cron line and the plugin's own options. |

The pages call sudemi once or twice per view, never once per product, and a sudemi outage never blocks your checkout, a product save or an admin page.

## Keep your shop checking in

Your shop tells sudemi every few minutes that it is online and that its prices are current. If it misses three check-ins in a row, its products are taken out of search until it checks in again (nothing is deleted), and you get an e-mail.

WordPress runs its scheduled tasks (WP-Cron) only when somebody visits the shop, so a quiet shop stops checking in. Ask your host for a real cron job every 5 minutes that calls `https://<your shop>/wp-cron.php`, and add `define('DISABLE_WP_CRON', true);` to `wp-config.php`.

## What is sent, and what is not

- **From your shop to sudemi:** catalogue data only: categories, products, prices, stock, photos (as links) and delivery prices, plus the shop details you save on the Shop profile page. Nothing about your own customers is ever sent.
- **From sudemi to your shop:** orders paid through sudemi, with the buyer's name and delivery address so you can ship, and the order's later changes (refunds, returns, cancellations).
- **Statistics** are counted by sudemi and only read by the plugin.

## Requirements

- WordPress 6.2 or later
- WooCommerce 8.0 or later
- PHP 8.0 or later with the **sodium** extension (bundled with PHP since 7.2 and enabled in nearly every build; the plugin checks for it and says so plainly)
- HTTPS on the shop's own domain
- A sudemi seller account (free): https://sudemi.com

## Languages

All texts in 12 languages: English, Arabic, Chinese, Dutch, French, German, Hebrew, Italian, Polish, Russian, Spanish and Turkish (Arabic and Hebrew right to left).

## Security

Every request to sudemi is signed over the method, the path, a timestamp, a single-use nonce and a hash of the body, so a captured request cannot be replayed and a price cannot be changed in transit. TLS verification is always on. One key pair per installation: a signature from one shop never works for another. The private key is not stored under the web root, not logged and not shown in the plugin's screens, so even a breach of sudemi's database could not produce a signature for your shop. Uninstalling removes the key and disconnects the shop.

Details and the review checklist: [SECURITY.md](SECURITY.md). Found a vulnerability? Mail **security@sudemi.com** instead of opening an issue.

## For developers

- [PROTOCOL.md](PROTOCOL.md): the signed protocol between the plugin and sudemi (connection, catalogue, check-in, orders, statistics).
- [readme.txt](readme.txt): the WordPress.org readme, with the changelog.
- Tests are in `tests/`.

## Support

Use the **Support** page inside the plugin, or the seller portal at https://sudemi.com. Guides for sellers: https://sudemi.com/docs/en/sellers

## Licence

GPL-2.0-or-later, as WordPress plugins must be. The full text is in [LICENSE](LICENSE).
