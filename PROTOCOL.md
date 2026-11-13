<!-- Copy of docs/seller-integration-protocol.md in the sudemi core repository (updated 2026-11-13). The core copy is the source of truth. -->

# Seller Integration Protocol v1

How a shop plugin - WooCommerce, PrestaShop, OpenCart, Shopify - claims a seller account on
sudemi and keeps a catalogue in step with it.

This document is the contract. The plugins are open source and run on servers we do not
control, so everything here is written from the assumption that **the plugin is hostile until
proven otherwise**: its source is public, its host may be compromised, and whoever installed it
may not own the shop they are pointing at.

---

## 1. What the threat model actually is

A distributed, open-source plugin changes three things about the usual integration story.

**There can be no shared secret.** Anyone can read the source, so a key compiled into the plugin
is a key every installation shares and every attacker has. Credentials must be minted per
install, at install time, and must be revocable individually.

**The installer is not necessarily the shop owner.** Someone who can upload a plugin to a
staging copy of a shop must not be able to claim the real shop's identity on sudemi. So the
binding between a sudemi seller account and a shop URL is established by **proving control of
the domain**, not by typing it into a form.

**The shop URL is the seller's identity to a buyer.** A listing says where it came from. If a
seller could edit that after the fact, they could launder a listing through a reputable domain.
It is therefore frozen (§5).

Everything below follows from those three.

---

## 2. Install: the pairing flow

Modelled on the OAuth 2.0 Device Authorization Grant (RFC 8628), because the problem is the
same: a piece of software with no credentials needs to be associated with an account held by a
person using a different screen.

```
plugin                         sudemi                        seller (browser)
  │                               │                                │
  │ keypair = ed25519_generate()  │                                │
  │ POST /integration/v1/pair     │                                │
  │   shop_url, platform, version,│                                │
  │   public_key                  │                                │
  │──────────────────────────────▶│                                │
  │                               │ mints public_code,             │
  │                               │ poll_token (secret, to plugin),│
  │                               │ challenge_token                │
  │◀──────────────────────────────│                                │
  │                               │                                │
  │ serves verification_token at  │                                │
  │ /.well-known/sudemi-challenge │                                │
  │                               │                                │
  │ shows claim_url to the seller │                                │
  │───────────────────────────────┼───────────────────────────────▶│
  │                               │                                │
  │                               │◀── signs in / registers ───────│
  │                               │◀── confirms the claim ─────────│
  │                               │                                │
  │                               │ fetches the challenge path     │
  │                               │ through the FR-36 guard        │
  │                               │────────┐                       │
  │                               │◀───────┘ token matches         │
  │                               │                                │
  │ POST /pair/poll (poll_token)  │                                │
  │──────────────────────────────▶│                                │
  │◀── install_id                 │  (no secret: it already has one)│
```

**Why the challenge file and the browser claim are both required.** The challenge proves the
plugin runs on the shop at `shop_url`. The browser claim proves a person with a sudemi account
wants that shop attached to it. Either alone is insufficient: the challenge alone would let
anyone who can write files to a shop attach it to *their* seller account, and the claim alone
would let anyone type any domain.

The challenge is fetched through the **FR-36 egress guard** - https only, DNS resolved by the
fetcher, resolved IP checked against the private/loopback/link-local/metadata blocklist, checked
again after every redirect, with a redirect cap, a size cap and a timeout. A shop URL that
resolves to a private address is refused, which is also why a plugin on localhost cannot pair
against production.

`poll_token` never appears in a URL, is held only by the plugin instance that started the
pairing, and is the reason a third party who sees the `claim_url` cannot collect the install.
Nothing secret comes back: the plugin already holds the only secret in the protocol, because it
generated it.

### Rate limits

| Thing | Limit |
| --- | --- |
| `POST /pair` per source IP | 10 / hour |
| `POST /pair` per shop host | 5 / hour |
| `POST /pair/poll` per pairing | 1 / 5 s, 100 total |
| Pairing lifetime | 24 hours |

---

## 3. Authenticating every later request

Signatures are **Ed25519**, and the plugin generates the keypair itself.

```
plugin, at install:   keypair = ed25519_generate()
                      → private key stays on the shop's host, forever
                      → public key is sent with POST /pair

every request:
Sudemi-Install:   <install_id>
Sudemi-Timestamp: <unix seconds>
Sudemi-Nonce:     <32 hex chars, unique per install>
Sudemi-Signature: <base64 ed25519_sign(private_key, canonical)>

canonical = method \n path \n timestamp \n nonce \n sha256_hex(body)
```

**Why not an HMAC with a shared per-install secret**, which is what most webhook schemes use?
Because verifying an HMAC requires the server to hold the same secret the plugin holds. Our
database would then contain a working credential for every shop that ever installed the plugin,
and one leaked backup would let someone push a price of zero into thousands of catalogues. With
Ed25519 we store public keys, and a database read yields nothing that can forge anything.

It also removes the worst moment in the flow. There is no secret to transmit, so there is no
"returned exactly once" step to get wrong, nothing sensitive in our logs if a response is
accidentally logged, and nothing for a plugin to leak by writing credentials to a file under the
web root.

Ed25519 rather than RSA: small keys, small signatures, no parameter choices to get wrong, and
deterministic signing - so a plugin on a shared host with a poor entropy source does not leak
its key the way ECDSA can.

Requests are rejected when the timestamp is more than **300 s** from server time, when the nonce
has been seen before for that install, when the signature does not verify, or when the install
is revoked. Every rejection returns the same shape: a verifier that distinguishes "no such
install" from "bad signature" tells an attacker which half of a guess was right.

**Rotation** is a new keypair: the plugin generates one, posts the public half signed with the
old key, and the previous key stays valid for a 10-minute overlap so a shop mid-sync does not
fail every request the moment it rotates. **Revocation** is from the seller portal, and is the
kill switch for a shop that has been compromised.

> **Availability of Ed25519.** PHP has it through the sodium extension, bundled with PHP since
> 7.2 and enabled in nearly every distribution build. Each plugin checks for it at install time
> and refuses to activate with a plain explanation rather than silently falling back to
> something weaker.

### The site a request comes from (`Sudemi-Site`)

A staging copy or a restored backup of a shop carries the same database, so the same install id
and the same signing key: without more, it could push its test catalogue or answer orders as the
live shop. Every signed request therefore also sends **`Sudemi-Site`**: the shop's own base address
as the shop system knows it (WordPress `home_url('/')`, the PrestaShop shop base URL, the OpenCart
catalog URL). It is not part of the signature.

After the signature is verified, sudemi compares the host of `Sudemi-Site` with the paired shop
origin (lower case, without `www.`, default ports ignored; path and scheme do not matter). On a
mismatch the request is refused with **`409 {"error": "site_mismatch"}`**, the refusal is recorded
on the install, and the seller is e-mailed (at most once a day per install, audited). The plugin
shows the refusal in its status screen: disconnect sudemi on the copy, or connect again if the shop
really moved. A request without the header (plugins older than this rule) is let through; an
unreadable header is logged and let through. This is a guard against accidents, not against
someone who holds the private key: such a party can send any header.

## 3a. The heartbeat, and why silence is not agreement

A connected shop has one obligation beyond signing its requests: **it must keep saying it is
there.** Every five minutes by default, it calls

```
POST /integration/v1/heartbeat
{ "catalogueRevision": "<hash>", "productCount": 1284,
  "intervalSeconds": 300, "pluginVersion": "1.0.0" }
```

Two separate failures make this necessary, and only one of them is about liveness.

**The shop went away.** From the platform's side, a shop with nothing to report and a shop whose
server died last Tuesday are *indistinguishable*, and only one of them still has prices worth
quoting. If silence were read as agreement, a dead shop would keep advertising last week's prices
and taking orders at them. So the beat moves a deadline - `trusted_until`, three missed beats
and when it passes, that shop's products stop being listable. Nothing is deleted: the products
come straight back on the next beat.

The multiple is deliberate: the window is **three times the interval the plugin itself promised**,
not a fixed number of minutes. A shop on a host whose cron runs every fifteen minutes cannot
honour a sixty-second contract, and a contract honest shops cannot keep would unlist them while
teaching their owners the integration is broken. The platform clamps the interval it will accept
to 60–900 s and tells the plugin what it is actually being held to, which may not be what it
asked for.

**A change did not reach us.** This one liveness cannot see. The shop is perfectly healthy, the
beats keep arriving, and a price push was lost anyway - the platform was restarting, DNS blipped,
a proxy ate it. So the beat carries a **catalogue revision**: any cheap value the plugin computes
from its own data that changes whenever the catalogue does. Our plugins use
`sha256(product_count | newest_modification_time | local_change_counter)`.

Its only job is to disagree. If the revision the shop reports differs from the one we hold, then
something did not arrive, whatever the reason, and the response carries `full_sync_requested`
rather than waiting for a delta that is already gone. Without it, "no stale prices" would rest on
every single push having succeeded, forever - a bet against ordinary network weather. With it, a
lost push costs one heartbeat interval and a resync.

A disagreement does not unlist on the spot: it means *something* we hold is out of date, not that
all of it is. The catalogue stays listed for a grace period (30 min) and is pulled only if the
resync never arrives - otherwise a plugin that reports a divergence it never resolves would keep
selling at prices it has itself disowned.

### The response is the only channel to a plugin

A plugin behind someone else's firewall never accepts a connection from us, so *everything* we
need a shop to do has to be an answer to a question the shop asked:

```json
{ "status": "ok", "heartbeat_seconds": 300,
  "trusted_until": "2026-11-03T12:15:00Z",
  "catalogue_listed": true, "not_listed_reason": null,
  "full_sync_requested": false,
  "domain_challenge": { "token": "…", "path": "/.well-known/sudemi-challenge" } }
```

### Re-proving the shop URL

Domain ownership is **not a fact established once**. The pairing check proves the domain belonged
to the installer *on that day*; domains are sold, DNS records are repointed, shops move host - and
the listing shows that origin to a buyer as the seller's identity for as long as it is listed.

So it is asked again, every 7 days, through the one channel available: a fresh token goes out in a
heartbeat response, the plugin serves it at the fixed well-known path, and the platform fetches it
back **through the same FR-36 egress guard** as everything else. Three *consecutive* failures pull
the catalogue, not one - a single failed fetch is a shop that was restarting, and a check that
unlists a catalogue for that is worse than no check. An outstanding token is repeated rather than
replaced, because issuing a new one on every beat would race our own fetch and read a healthy shop
as a failing one.

### What the plugin owes on its side

- **A durable retry queue.** A delta push runs while someone is saving a product in the shop's
  admin: one attempt, short timeout, because a sudemi outage must not slow that screen or put an
  error on it. But it must not *drop* the change either - that is a wrong price on a marketplace
  until the next full sync. So a failure is written down and the heartbeat drains it. Keyed by
  product id, so twenty saves during an outage leave one entry carrying the newest price.
- **Count the change before attempting it**, not after it succeeds. The counter is what lets us
  notice a change that never arrived, so it has to move even when the push fails.
- **Report status where the seller can see it.** Products stop being shown when the beats stop,
  and from inside the shop everything looks normal - plugin active, catalogue intact, settings
  right. The usual cause is nothing to do with us: WP-Cron disabled, no crontab entry, an
  outbound firewall. Without a panel saying so, the first sign is a seller wondering where their
  orders went.

## 4. What a plugin may send

All of it is upsert-by-`external_id`, where `external_id` is the id the shop itself uses. The
plugin never invents sudemi ids.

### One company, many shops (owner decision 2026-11-08, migration V57)

A sudemi seller is one legal company: one payout account, one monthly commission invoice and
credit notes, one legal identity, bans and disputes per company. A company can connect several
shops (plugin installs and Shopify stores); each install is one shop, and:

- **Products are keyed per shop.** `external_id` is unique within the install that sent it
  (`products_install_external_id`), not within the company, so two shops of one company can both
  have a product `52` and neither overwrites the other. Feed and seller API products have no
  install and stay keyed per company.
- **Orders belong to exactly one shop.** A cart with items from two shops of one company becomes
  two orders (one per shop, each with its own shipping) in one payment. `orders.integration_install_id`
  is set from the checkout, and the database refuses a checkout line of another shop
  (`checkout_lines_same_shop` trigger).
- **An install sees and acts on its own shop's orders only.** The heartbeat's `pending_orders`,
  `settled_orders` and `open_requests`, `GET /orders` and every `/orders/{id}/...` call are
  scoped by install, never by seller. Another shop's order (same company or not) answers exactly
  like an unknown order: 404 `no_such_order` (`not_found` for the return-request answers). The
  plugin's support tickets are its own shop's too.
- **`POST /shop` describes this shop, not the company.** Name, description, support e-mail and
  returns window are stored on the install. Buyers see the shop's own name (search, product
  details, cart, the payment page line item, mails); the company's legal name stays on legal
  information (trader details, invoices). A shop's push never renames the company; it only fills
  company fields that are still empty.
- **The shop's name is the merchant's (2026-11-09).** The name on the claim screen is the shop's
  name, prefilled from the plugin's proposal; the claim stores it on the pairing
  (`integration_pairing.confirmed_shop_name`, V58) and the install takes it when it is created (the
  plugin's collect, or at once for Shopify). `POST /shop` `displayName` and the Shopify store name
  only fill an EMPTY shop name; they never overwrite a name the merchant confirmed or set. A
  shop is renamed only through support (owner 2026-11-09, "Rename only via ticket"): the seller
  asks in a support ticket, an admin renames it in the admin console, Sellers tab
  (`POST /internal/v1/admin/shops/{installId}/name`, admin only, 1 to 200 characters after
  trimming, audited as `integration.shop_renamed`; a disconnected or unknown install is 404). A
  company created by a claim is named by its legal name; a claim never renames an existing
  company. A rename reindexes the company's products for search (`integration_install_name_reindex`).
  The buyer's order list and order page, MCP `list_orders` and `get_order_status` (`shop`) and the
  order e-mails name each order's own shop.
- **Shipping settings are per shop (2026-11-09).** The settings on sudemi (`seller_shipping`,
  V33) are keyed by the shop's install since V61; rows without an install are the company's
  settings for its products without a shop (feed, seller API). Each product reads its own shop's
  settings (`EffectiveShipping`) in search, product details, cart and checkout. The portal
  (`/internal/v1/seller/shipping?shop=`) and the seller API (`/v1/shipping?shop_id=`) take the
  shop's install id or `none`; left out with several shops: 409 `shop_required`. V61 copied each
  company's settings to each of its live shops, so nothing changed for buyers. The same shop
  reconnecting keeps its settings; a new shop starts with its plugin's prices.
- **A disconnected shop stays disconnected.** Its products go offline with its install and its
  open orders are never handed to the company's other shops. A shop connecting again takes over
  its earlier install only when that is provably the same shop (review 2026-11-09): the install
  that held the same verified shop URL last belongs to the same company AND runs the same platform
  (`IntegrationRepository.adoptEarlierInstallOfSameShop`). The shop URL is scheme and host only, so
  two shops on one host (a multistore, a WordPress multisite) or a platform swap would otherwise
  look alike. Then:
  - its products move, keeping their ids, but stay offline until the shop sends them again in its
    own sync;
  - only closed orders move (`DECLINED`, `CANCELLED`, `REFUNDED`, with no open return request).
    Orders the shop still has to act on (`AWAITING_SELLER`, `ACCEPTED`, `PAID`, `SHIPPED`,
    `DELIVERED`) and open checkouts stay with the revoked install: no plugin is asked about them,
    an unanswered order is released by the sweep at its `respond_by` (the buyer is not charged),
    a paid one is handled through the seller API (`/v1/orders/{id}/...`);
  - its support tickets follow, except a ticket about an order that stayed behind.
  Otherwise nothing moves: the earlier shop's products stay offline with its revoked install.
- **The same product from the API or a feed and a shop (review 2026-11-09).** When a plugin sends
  an `externalId` the company already has as an API or feed product (no install), the shop takes
  that row over (same product id, so its orders stay valid) if no other connected shop of the
  company has a product with that id; otherwise the company row is taken off sale (never moved),
  so the item is not listed twice. A feed import does not list a product whose `external_id` a
  connected shop of the company already sends, and never unlists a shop's products.
- **Shipping** prices and delivery days come from the product's own shop. The company-wide
  shipping settings on the portal (Seller → Shipping), when set, apply to all of its shops.

**Every write is a POST**, including the ones earlier drafts of this document described as `PUT`
and `PATCH`. Three reasons, and the first is the one that matters: the canonical string a plugin
signs includes the HTTP method, so a single method means one shape of that string in four
languages instead of four - and a method mismatch is a signing bug neither side can see. Shared
hosts also still filter `PATCH`, and PHP's stream wrappers handle `POST` most predictably. Reads
are `GET` (signed with their query string, see "Signing a GET"). The one `PUT`,
`/integration/v1/shipping`, also answers `POST`; plugins should send `POST`.

| Endpoint | Carries |
| --- | --- |
| `POST /integration/v1/heartbeat` | Liveness and the catalogue revision; see §3a. With `changesCursor` also the orders changed on the sudemi side (§4d) |
| `GET  /integration/v1/shop` | What this install is, its `locked_fields`, and whether its catalogue is currently listed |
| `POST /integration/v1/shop` | Shop metadata: display name (fills an empty shop name only), description, currency (the shop's currency, see 4c), returns window, support e-mail. `shipsTo` and `languages` are accepted and ignored: where a shop ships and for what comes from each product's `shipping` and from `/integration/v1/shipping` (§4d) |
| `POST /integration/v1/categories` | The shop's own category tree: `externalId`, `parentExternalId`, name, description. With each product's `categoryExternalIds` it is the product's declared category for the blocked category list (owner 2026-11-09): goods our payment provider does not allow (for example weapons or adult products) and goods that need an age check or a licence (alcohol, tobacco, medicines) are not listed. Whole words in English plus the shop's language, category names only (never titles); list and sources in `ai-store-core/src/main/resources/category-filter/blocked-categories.yml` |
| `POST /integration/v1/products` | Products in batches of ≤ 500: identity, title, description, category, brand, GTIN/MPN, **specs** as typed attributes, images as URLs, price, stock, shipping |
| `POST /integration/v1/products/prices` | Price and stock deltas only - the hot path, called from the shop's own save hook |
| `POST /integration/v1/sync` | Opens a full sync and returns its id |
| `POST /integration/v1/sync/{id}/commit` | Ends a full sync and tells the platform which products were *not* in it |
| `POST /integration/v1/orders/{id}/accept` | The shop can fulfil this order. **This captures the buyer's money**; see §4a |
| `POST /integration/v1/orders/{id}/reject` | The shop cannot fulfil it. Releases the buyer's authorisation; optional `{"reason": "..."}` |
| `GET  /integration/v1/orders` | The shop's own order history: approved, declined, shipped, delivered. Optional `?status=` filter and cursor paging; the portal's filters and sorting since core 2026-11-09 (§4d) |
| `GET  /integration/v1/orders/{id}` | One order as the portal's order page shows it (§4d) |
| `GET  /integration/v1/orders/export.csv` | The portal's CSV export of this shop's orders (§4d) |
| `POST /integration/v1/orders/bulk` | Accept, reject, ship or deliver up to 100 orders at once (§4d) |
| `GET  /integration/v1/orders/{id}/fulfilment` | Where to ship it. Only after the order is accepted; see §4a |
| `POST /integration/v1/orders/{id}/shipped` | Dispatched. Optional `{"carrier": "...", "trackingCode": "..."}` |
| `POST /integration/v1/orders/{id}/tracking` | New or corrected tracking on an order already SHIPPED: `{"carrier": "...", "trackingCode": "..."}` (core 2026-11-09, §4d) |
| `POST /integration/v1/orders/{id}/delivered` | Arrived. **Starts the withdrawal period running** |
| `GET/POST /integration/v1/shipping` | This shop's shipping settings on sudemi and what buyers pay per country (§4d) |
| `GET  /integration/v1/payouts` | Read only: the payout account's state and this shop's payouts per currency (§4d) |

A batch is accepted **item by item**. One product with no price is refused by name in a
`rejected` array while the other 499 land - a plugin handed a blanket rejection retries the same
batch forever, and the shop ends up listing nothing because one item was malformed.

A price delta for a product the platform has never seen is **not** inserted: it means the full
sync that should have introduced it was lost, and a row carrying a price with no title would put
an unnameable product in the catalogue. The response sets `full_sync_requested` instead.

### Products the seller API also manages

The seller REST API (`/v1/products`, see `ai-store-web/public/docs/<lang>/seller-api.md`) can
create, replace and delete products too, for shops without a plugin or feed. Each product row has
one `source` (`PLUGIN`, `FEED` or `API`, migration V43), and the two channels never fight:

- **Each channel keeps its own rows (V57).** Products are keyed per shop. A plugin that sends the
  `externalId` of an API or feed product takes it over only when it is the company's only shop
  with that id; with two shops the API/feed row is taken off sale instead (see above). Rows that a
  plugin took over before V57 stay with that plugin.
- **The seller API never guesses a shop.** When two of the company's shops (or a shop and the
  API) have a product with the same `external_id`, create/replace by `external_id` answers 409
  `ambiguous_external_id` and a bulk item names the problem; address the product by its sudemi
  id instead.
- **The API does not overwrite a plugin.** While the install that sent a product is not revoked,
  API create (same `external_id`), replace and delete answer 409 `managed_by_plugin`; only the
  on/off switch, price and stock (`PATCH /v1/products/{id}`, `POST /v1/products/bulk`) are
  accepted, and the plugin's next push overwrites price and stock as before. Products of a
  revoked install can be taken over by the API.
- **A sync commit never delists API products**: they carry no install id, so `unlistMissing`
  cannot reach them, and a feed import that leaves them out does not unlist them either. A feed
  import never unlists a shop's (plugin or Shopify) products: it unlists only the company's own
  feed products (no install) it no longer contains.

### Images

A product carries image **URLs**, not bytes. The platform fetches them, and fetches them through
the same FR-36 guard as everything else, with one extra rule: **an image URL must be on the
verified shop origin or a host the seller has registered as their CDN.** Without that rule a
plugin could make the platform fetch arbitrary internal addresses one image at a time.

Size and type are capped; anything that is not a real image after decoding is rejected rather
than stored.

### Full sync and the empty-catalogue trap

A-17 already says an empty feed must not unlist a catalogue. The same hazard is worse here,
because a plugin crashing halfway through a sync is routine. So a full sync is explicit:

```
POST /integration/v1/sync          → sync_id
POST .../products  (×N, each tagged with sync_id)
POST /integration/v1/sync/{id}/commit
```

Products absent from a **committed** sync are delisted. A sync that is never committed changes
nothing beyond the products it actually sent, and expires after 6 hours. A commit that would
delist more than a configurable share of a live catalogue (default 20 %) is held for the
seller to confirm in the portal rather than applied silently.

---

### Signing a GET

Two things differ from a POST, and both bite silently:

- **The body is empty**, so the body hash is `sha256("")` - not `sha256("{}")`. A client that
  serialises an empty object and signs that will get a 401 it cannot explain.
- **The query string is part of the signed path.** `/integration/v1/orders?status=PAID&limit=10`
  is signed in full, including the `?`. Signing only the path would leave the parameters
  rewritable by anything on the way while the signature still verified, and those parameters
  choose which of a seller's orders come back.

Nothing changes for the POST endpoints: they carry no query string, so the path they already sign
is unchanged.

## 4c. Currency (currency step 2, 2026-11-09)

Owner decisions: the buyer's card is charged in the **shop's currency**; buyers additionally see an
approximate amount in their own currency at the ECB reference rate; a cart with items in several
currencies is one payment per currency; commission invoices and credit notes are always in EUR.

- **One shop, one currency.** `POST /integration/v1/shop` with `currency` (ISO 4217, e.g. `PLN`) sets the
  shop's currency; the answer echoes it as `currency`. Supported shop currencies (owner 2026-11-09,
  "multicurrency for everyone, we will invoice in EUR"): EUR and every currency with an ECB reference rate
  (the EUR commission invoice needs it), except ISK (Stripe wants ISK with two decimals it does not have).
  Anything else is refused (`rejected`, the message names the supported list). A shop that never declares
  one takes the currency of the first product it sends.
- **Every price in the shop's currency.** `POST /products` and `POST /products/prices`: an item whose
  `currency` differs from the shop's currency is rejected item by item ("price currency EUR differs from this
  shop's currency PLN ..."); the rest of the batch goes through. Shipping prices (`shipping[].costMinor`) are in
  the same currency. To change a shop's currency: declare the new one on `POST /shop`, then send a full sync.
- **Minor units are the currency's own.** `priceMinor` is in the currency's minor units as ISO 4217 defines them
  (EUR/PLN/HUF/TRY 2 decimals). Do not multiply by 100 blindly for a currency with other decimals (ISK, JPY are
  not shop currencies today for that reason).
- **Orders** (`pending_orders[]`, `GET /orders`, fulfilment) carry `currency_code` = the shop's currency; book the
  local order in that currency. A plugin should refuse (decline) an order whose `currency_code` is not its
  shop's currency rather than book it in another one.
- **Refunds** (`POST /orders/{id}/refund` `amountMinor`) are in the order's currency. Plugins 1.2.12+ also send `currency`; when given it must be the order's currency, otherwise
  409 `currency_mismatch` (with `orderCurrency`) and nothing is refunded.
- **Payouts.** sudemi's Stripe balance is EUR: a charge in another currency is converted by Stripe, and the
  shop's payout transfer is in EUR, the shop's share of what the charge actually settled for (Stripe's balance
  transaction), tied to the buyer's charge. A refund is made in the order's currency; the shop's transfer is
  reversed in EUR in proportion.

Plugins 1.2.12 (step 3): WooCommerce, PrestaShop and OpenCart send the shop's default currency on `POST /shop` and
on every product, use each currency's own minor units (0 for JPY/ISK, 3 for KWD/BHD, else 2), book an order in its
`currency_code` and decline one in a currency the shop does not sell in, send `currency` with refunds, and show
the currency sudemi sees (with a warning when it is not supported) on their settings page. A shop with several
currencies switched on reports only its default one; orders are only in that one.

## 4a. Confirming orders, and why nothing is charged without the shop

The platform does not hold stock and never will. It carries a figure the plugin pushed, which was
true when it was pushed and may not be now, because the same units are on sale in the shop's own
storefront at the same time. Two buyers can reach the last item and no amount of reserving on the
platform's side changes what is on the shelf.

So the platform does not guess. At checkout the buyer's card is **authorised** - the funds are
held without being taken - and the shop is asked whether it can actually fulfil the order. The
money moves only when the shop says yes.

```
buyer pays        -> card authorised, nothing taken, order is AWAITING_SELLER
shop accepts      -> money captured and split: commission to the platform,
                     the rest to the seller's connected account
shop rejects      -> authorisation voided, hold disappears from the buyer's card
nobody answers    -> same as rejected, once the window closes
```

**Silence is never agreement.** An unanswered order is released, not accepted. A shop that has
stopped answering is usually a shop that is gone, and charging a buyer because nobody objected is
exactly the failure this mechanism exists to prevent. The platform will not auto-approve under any
circumstances.

### How a plugin learns about them

On the heartbeat, because that is the only channel to a plugin (§3a). The response carries a
`pending_orders` array:

```json
{
  "pending_orders": [
    {
      "order_id": "a8c4695b-21ef-4d6c-b745-5412cb1e8bd3",
      "respond_by": "2026-11-05T14:53:45Z",
      "total_minor": 5495,
      "shipping_minor": 495,
      "currency_code": "EUR",
      "destination_country": "NL",
      "lines": [
        { "product_id": "…", "external_id": "appr-1", "title": "A thing", "quantity": 1,
          "unit_price_minor": 5000 }
      ]
    }
  ]
}
```

`external_id` is the shop's own product id, so the plugin can look the item up in its own
catalogue without storing a mapping. At most 25 ride on one beat; the rest arrive on the next.

`shipping_minor` and each line's `unit_price_minor` are what the buyer paid, VAT included, in the
order's currency: `total_minor` is the sum of `unit_price_minor` x `quantity` over the lines plus
`shipping_minor`. **Book the local order at these amounts, not at the shop's current catalogue
price:** the price may have changed since the last sync, and an order built from the catalogue
without the shipping leaves the shop's books short of what was paid. Add the shipping as the
order's shipping line, take any VAT out of the amounts rather than adding it on top, and record
the payment method as sudemi. (Added 2026-11-08; a plugin talking to an older core that does not
send them falls back to the catalogue prices.)

**There is no buyer name, address or contact in this.** A shop deciding whether it has stock does
not need to know who is asking (NFR-38). Those arrive once the order is confirmed.

### The deadline is not ours

`respond_by` is roughly two days out, and that bound comes from the card network rather than from
the platform: an authorisation is good for about seven days - less for some payment methods - and
a capture attempted after it lapses simply fails. A plugin that answers late gets
`409 authorisation_lapsed` and the order is released instead.

### What a plugin owes here

- **Answer from live stock, not from what it last pushed.** What was true at the last sync is
  precisely what cannot be relied on.
- **Create the local order before sending `accept`, not after.** The other order leaves a buyer
  charged for something the shop has no record of. This way a refused acceptance leaves a local
  order with no money behind it, which the plugin can cancel and restock itself.
- **Reduce local stock when the order is created**, so the shop's own storefront cannot sell the
  same unit while the confirmation is in flight - and put it back if the acceptance is refused.
- **Be safe to retry.** A lost response must not produce two local orders or two captures. Key the
  local order by `order_id` and look it up before building another. The platform answers
  `{"status": "already_resolved"}` rather than an error when an order was already answered.
- **Declining is not a failure.** Out of stock is the ordinary case, and a shop that says so
  promptly is behaving exactly as intended. The alternative - taking the money and refunding days
  later - is worse for everyone.

### Responses

| Status | Body | Meaning |
| --- | --- | --- |
| 200 | `{"status": "ok"}` | Applied. On accept, the money has been captured. |
| 200 | `{"status": "already_resolved", "order_status": "..."}` | Answered before, or the window closed. Not an error; stop retrying. `order_status` says how it ended: `PAID` (or `ACCEPTED`, `SHIPPED`, ...) means your earlier accept landed, so keep the local order; `DECLINED` means it was released, so cancel the local order and restock; for a `DECLINED` or `CANCELLED` order `cancelled_by` says who ended it (`buyer`, `shop` or `sudemi`), as in `settled_orders`. Never cancel on `already_resolved` alone. |
| 404 | `{"error": "no_such_order"}` | No such order awaiting an answer *from this shop*. Identical whether it does not exist, belongs to another seller, or belongs to another shop of the same company, so an install cannot probe for other shops' order ids. |
| 409 | `{"error": "authorisation_lapsed"}` | The buyer's authorisation could no longer be charged. The order will be released. |

### After the money is captured

Two more things the shop reports, because nothing on the platform can observe them:

| Call | When | Effect |
| --- | --- | --- |
| `shipped` | the parcel leaves | buyer is e-mailed the carrier and tracking |
| `tracking` | the tracking is added or corrected after `shipped` | buyer is e-mailed the new carrier and tracking (core 2026-11-09, §4d) |
| `delivered` | it arrives | buyer is e-mailed the date they may withdraw by |

`delivered` is the one with legal weight. The right of withdrawal runs from the day the goods
arrive, not the day they were paid for (Directive 2011/83/EU art. 9), so this call is what starts
the clock the consumer's statutory rights depend on. A shop that never sends it leaves every one
of its orders with no computable return deadline.

`shipped` requires the order to be PAID - a shop cannot dispatch what it was not paid for, and
the platform answers `409 not_paid` rather than recording it. `delivered` is accepted from PAID as
well as SHIPPED, so a shop handing something over in person need not invent a dispatch event
first. Both are safe to repeat: a second call answers `{"status": "already_resolved"}` and sends
the buyer nothing twice.

Tracking is optional. No carrier and no code is not an error - the buyer is told the order shipped
with "not given" where the details would be, which is better than withholding the dispatch notice
over a missing field. Whatever is supplied is seller text on its way to an inbox, so it is
stripped of control characters and capped at 100 characters (FR-91).

### Where to ship it

`GET /integration/v1/orders/{id}/fulfilment` returns the buyer's name and postal address, and
**only once the order has been accepted**. Before that it answers `409 not_confirmed_yet`.

That ordering is the point. The question a shop is asked beforehand is whether it has the item,
and answering that needs nobody's address - so none is sent with it. Handing a full postal address
to every shop for every order it might decline would be giving away buyers' addresses to ask about
stock.

An order placed before the platform recorded delivery addresses answers `{"deliverable": false}`
with a message, rather than a label with holes in it.

## 4b. Contract reference (checked against core 2026-11-09, `e2e/integration_contract.py`)

Requests are camelCase JSON; responses are snake_case (one exception: the items of a `rejected`
array are `{"externalId", "reason"}`). Unknown request fields are ignored.

| Endpoint | Body | Answer |
| --- | --- | --- |
| `POST /heartbeat` | `catalogueRevision`, `productCount`, `intervalSeconds` (clamped to 60-900), `pluginVersion`, `changesCursor` (optional: `""` to start, then the last `changes_cursor`); body optional | `status`, `heartbeat_seconds`, `trusted_until`, `catalogue_listed`, `not_listed_reason`, `full_sync_requested`, `domain_challenge{token,path,why}`, `pending_orders[{order_id,respond_by,total_minor,shipping_minor,currency_code,destination_country,lines[{product_id,external_id,title,quantity,unit_price_minor}]}]` + `pending_orders_note`, `settled_orders[{order_id,status,declined_reason,cancelled_by}]`, `open_requests[{request_id,order_id,kind,reason,requested_at,respond_by}]` + `open_requests_note` (each list only when not empty). With `changesCursor` in the body (core 2026-11-09, §4d): `changed_orders[{order_id,status,revision,changed_at,refunded_minor,tracking{carrier,code}\|null,shipped_at,delivered_at,cancelled_by,request{kind,status}\|null}]` (always present then, may be empty), `changes_cursor`, `changes_more`, `changes_cursor_reset` (only when the cursor sent could not be read) |
| `GET /shop` | - | `install_id`, `seller_id`, `shop_origin`, `locked_fields`, `heartbeat_seconds`, `catalogue_listed`, `not_listed_reason`, `full_sync_requested`, `trusted_until` |
| `POST /shop` | `displayName` (fills an empty shop name only), `description`, `supportEmail`, `returnsWindowDays`, `currency` (the shop's currency, §4c; unsupported: 400); `shipsTo` and `languages` accepted and ignored (shipping: §4d); locked fields: 423 | `status`, `currency` (the shop's currency now, or null), `locked_fields` |
| `POST /categories` | `categories[{externalId,parentExternalId,name,description}]`, at least one (the declared category of the products that name them, for the blocked category list) | `status`, `accepted`, `removed` |
| `POST /products` | `syncId` (optional), `catalogueRevision` (optional, change pushes only: the shop's revision after this push), `products[]` (1-500): `externalId`, `title`, `priceMinor` (1 to 10,000,000), `currency`, `quantity` or `inStock`, `description`, `url`, `brand`, `gtin`, `mpn`, `sku`, `weightGrams`, `maxHandlingTimeDays`, `maxTransitTimeDays`, `categoryExternalIds`, `images` (on the shop origin), `specs[{name,values}]`, `shipping[{country,costMinor}]`, `safety{...}`, `withdrawalExclusion` (hygiene, custom, perishable, media, press, mixed, alcohol; unknown = none), `googleProductCategory` (optional, Google product category id or path), `productType` (optional free text, read like a shop category) | `status`, `accepted`, `rejected[]` (item by item), `not_listed[]` (accepted but not listed: the declared category is on the blocked list, with the reason), `catalogue_revision_agreed` |
| `POST /products/prices` | `updates[]` (1-500): `externalId`, `priceMinor` (1 to 10,000,000), `currency`, `quantity` or `inStock`; `catalogueRevision` (optional: the shop's revision after these changes) | `status`, `applied`, `rejected[]`, `full_sync_requested` (true when an id was unknown), `catalogue_revision_agreed` |
| `POST /sync` | - | `sync_id`, `expires_at`, `max_products_per_request` |
| `POST /sync/{id}/commit` | `catalogueRevision` (optional: the shop's revision when this sync started) | `status: committed` (`products_seen`, `delisted`) or `status: held` (`would_delist`, `live_products`, `message`) |
| `POST /orders/{id}/accept` / `reject` | reject: `reason` optional | see §4a; accept adds `payment: captured` or `waiting_for_other_shops` |
| `GET /orders?status=&limit=&after=` | - | `counts`, `orders[{order_id,status,placed_at,total_minor,currency_code,your_net_minor,lines[{title,quantity}],respond_by?,declined_reason?,customer_request?,customer_name?}]`, `next_cursor` (only when there is a next page). Since core 2026-11-09 each row also has `destination_country`, `shipping_minor`, `tracking{carrier,code}\|null`, `shipped_at`, `delivered_at`, `revision`, `refunded_minor`, `payout{state,expected_minor,paid_out_minor,currency_code,due_at,carried_to_owed_minor,owed_paid_off_minor}` |
| `GET /orders?from=&to=&q=&sort=&dir=&offset=&status=&limit=` | any of `from`, `to` (date `YYYY-MM-DD`, `to` inclusive, or ISO instant, exclusive), `q` (order reference or product title words), `sort` (`placed`, `total`, `status`), `dir` (`asc`/`desc`), `offset` switches to the portal's table; `limit` 1-100; `after` ignored | `counts`, `orders[...]` (rows as above), `total`, `offset`, `limit`, `next_offset` (null on the last page); 400 `invalid_date` |
| `GET /orders/{id}` | - | the portal's order page in snake_case: `order_id`, `id`, `status`, `placed_at`, `quantity`, `destination_country`, `lines[{product_id,external_id,title,quantity,unit_price_minor,goods_minor,withdrawal_exclusion}]`, `shop{install_id,name,origin,platform}`, `money{currency_code,goods_minor,shipping_minor,total_minor,commission_minor,your_net_minor,refunded_minor}`, `customer_request?{id,kind,reason,requested_at,respond_by}`, `respond_by?`, `note?`, `captured_at?`, `declined_reason?`, `shipped_at?`, `delivered_at?`, `withdrawal_deadline?`, `tracking?{carrier,code}`, `customer_name?` (after capture, never the address), `allowed_actions[]`, `timeline[{type,at,...}]`, `payout{settled,converted,currency_code,expected_minor,paid_out_minor,state,due_at,carried_to_owed_minor,owed_paid_off_minor,card_fee_minor,card_fee_shop_minor}`, `revision`; 404 `no_such_order` (also another shop's, same company included); 409 `previous_connection` |
| `GET /orders/export.csv?status=&from=&to=&q=&sort=&dir=&lang=&decimal=` | `lang`: en nl tr de fr es it pl ru zh ar he (else en); `decimal=comma`: decimal comma and `;` | `text/csv; charset=UTF-8` with BOM, the portal's columns, at most 10,000 rows; headers `X-Total-Count`, `X-Export-Truncated`; no buyer name or address |
| `POST /orders/bulk` | `action` (`accept`, `reject`, `shipped`, `delivered`), `reason` (reject: required, max 500), `items[{orderId,carrier,trackingCode}]` (1-100) | `action`, `done`, `refused`, `results[{order_id,result: done\|refused,error?,message?,payment?}]` (errors per order: `no_such_order`, `previous_connection`, `already_resolved`, `authorisation_lapsed`, `wrong_state`, `provider_refused`, `failed`); 400 `invalid_request`; 403 `seller_suspended` (accept only) |
| `GET /shipping` | - | `shop_id`, `shop_name`, `mode` (`shop`/`custom`), `currency`, `max_price_minor`, `countries[{country,enabled,price_minor,offered,shop_price_min_minor,shop_price_max_minor,shop_price_products}]` (as the seller API), `effective[{country,offered,source: shop_price\|sudemi_price\|null,min_minor,max_minor}]` |
| `PUT` or `POST /shipping` | `mode`, `currency` (optional, must be the shop's), `countries[{country,enabled,priceMinor}]` (custom mode: every country offered) | as GET; 400 `invalid_request` (unknown country, a country twice, price below 0 or over `max_price_minor`, wrong currency, bad mode) |
| `GET /payouts` | - | `account{connected,charges_enabled,details_submitted,payouts_active,state,action_deadline,disabled_reason}`, `payout_delay_days`, `currencies[{currency_code,orders,paid_out_minor,outstanding_minor,waiting_for_shipment_minor,scheduled_minor,processing_minor,held_minor,next_due_at,card_fee_shop_minor}]`, `owed_to_sudemi[{currency_code,amount_minor,last_change_at}]`, `owed_by_sudemi[{currency_code,amount_minor,last_change_at}]` (a credit sudemi owes the company, paid with its next payout in that currency), `note` |
| `GET /orders/{id}/fulfilment` | - | `deliverable`, `ship_to{recipient_name,line1,line2,postal_code,city,region,country,phone}`, `lines[{product_id,external_id,title,quantity}]`; 409 `not_confirmed_yet`, `payment_pending`, `not_payable` |
| `POST /orders/{id}/shipped` / `delivered` | shipped: `carrier`, `trackingCode` (optional, max 100 chars each) | `status: ok` / `already_resolved`; 409 `not_paid` |
| `POST /orders/{id}/tracking` | `trackingCode` (required; `tracking_code` also accepted), `carrier` (optional: left out or blank keeps the current one); max 100 chars each, control characters stripped. Optional `Idempotency-Key` | `status: ok` (changed, buyer e-mailed) / `already_resolved` (the order has exactly this tracking; nothing changed, nobody e-mailed); 400 `tracking_code_required`; 404 `no_such_order` (also another shop's); 409 `not_shipped` (PAID: send `shipped` with the tracking instead; DELIVERED, CANCELLED, REFUNDED: final) |
| `POST /orders/{id}/refund` | `amountMinor` (omit for the rest), `reason`, optional `currency` (must be the order's, else 409 `currency_mismatch`) | `orderId`, `refundedMinor`, `totalRefundedMinor`, `fullyRefunded`, `providerRefundId`; 409 `refused`; 502 `provider_refused`. Send `Idempotency-Key` (any unique string, max 255): a retry with the same key and body returns the first answer and moves no money (24 h); without it a repeated partial refund refunds again |
| `POST /orders/{id}/cancel` | `reason` (and `Idempotency-Key` as for refund) | as refund, plus `status: CANCELLED`; only a PAID order that has not shipped |
| `POST /orders/{id}/request/accept` / `request/reject` | reject needs `reason` (shown to the buyer) | `status: ok` / `already_resolved`; 400 `invalid_request` (no reason); 404 `not_found` (no open request) |
| `POST /credentials/rotate` | `publicKey` (new Ed25519, base64 X.509) | `status: rotated`, `previous_key_valid_until` (old key valid 10 more minutes) |
| `POST /credentials/revoke` | `reason` optional | `status: revoked`; every later request 401 |
| `GET/POST /support/tickets`, `GET /support/tickets/{id}`, `POST /support/tickets/{id}/messages` | create: `category`, `subject`, `message`, `orderId`, `locale` | `ticket` / `tickets`; another shop's ticket answers 404 |

**Errors** (all `{"error", "message"}`): 400 `invalid_request` (unreadable JSON, an id that is not a
UUID, a missing reason), 401 `not_authenticated` (one shape for every signing failure), 403
`seller_suspended` (a suspended shop may still heartbeat, reject, ship, deliver, refund, cancel and
answer return requests, but not accept orders or send catalogue data), 404 `no_such_order` /
`not_found`, 405 `method_not_allowed`, 409 `site_mismatch`, `previous_connection` and the order-state codes above, 413
`payload_too_large` (over 8 MB), 422 `rejected` (a whole batch refused: empty, more than 500, an
unknown or closed sync), 423 `locked_fields`, 429 `too_many_requests` with `Retry-After` (catalogue
writes, the CSV export and bulk order actions 60 a minute together, everything else 120 a minute, per install), 502 `provider_refused`. Treat 5xx,
429 and timeouts as "not answered": retry later, never as a refusal.

**Catalogue revision** (changed 2026-11-07). A disagreement sets `full_sync_requested`.
- Send `catalogueRevision` on the commit: the revision the shop had **when the sync started**.
  sudemi adopts it, so anything changed during or after the sync (a product switched off) makes the
  next heartbeat disagree and is synced, instead of being hidden. Without it, the next heartbeat's
  revision is adopted (older plugins).
- Send `catalogueRevision` on a change push (`/products` without `syncId`, `/products/prices`) with
  the shop's revision after the change, **only when nothing else is waiting to be sent**. If every
  item applied, sudemi adopts it (`catalogue_revision_agreed: true`) and no full sync follows the edit.
  If anything was rejected or unknown, the revision is not moved.

**Re-paired shops.** An order that belongs to this shop's previous sudemi connection (the shop was
connected again, to another seller account) answers accept/reject with 409 `previous_connection`, not
404. Leave such a local order as it is; it is not released.

**Held syncs.** A commit answered `status: held` waits for the shop owner: Selling > API & webhooks >
Held catalogue updates in the seller portal lists what would be removed, with "Remove these products"
and "Keep my products" (the latter asks the plugin for a fresh full sync).

**Rotation and revocation** are built (the status note in §7 predates them).

## 4d. Managing orders, shipping and payouts from the plugin (core 2026-11-09)

Owner, 2026-11-09: "plugins should be able to manage orders and shippings. the source of truth is
sudemi portal but all these items should go to shops' plugins as well". The sudemi portal stays the
source of truth; a plugin shows and changes the same things through the calls below. Each answer is
built by the portal's own code and renamed to snake_case, so a plugin and the portal never disagree.

**Scope.** Every call is narrowed to the install that signed it. Another company's order and an
order of another shop of the same company both answer exactly like an unknown order (404
`no_such_order`; in a bulk action, `no_such_order` for that item). A `shop` query parameter is
ignored. No answer here carries the buyer's address; `GET /orders/{id}/fulfilment` (after
acceptance) stays the one place a shop gets it. Signed like every call; a GET signs its query
string. Audited like the portal: `orders.bulk` and `shipping.updated` (actor PLUGIN, the install).

**Minimum versions.** Core 2026-11-09 for everything in this section. Plugins up to 1.2.x keep
working unchanged: they send no `changesCursor` (no change feed) and no table parameters (the order
list keeps its old shape; the new row fields are additions). The plugin releases that use this
section are planned as 1.3.0 (WooCommerce, PrestaShop, OpenCart); they should check for the fields
rather than for a core version, and fall back to the 1.2 behaviour when `changed_orders` is missing.

### The change feed (heartbeat `changed_orders`)

Send `"changesCursor": ""` on a heartbeat to start, then always the `changes_cursor` of the last
answer. The answer lists this shop's orders changed since then, oldest change first, at most 200; on
`changes_more: true` send the next heartbeat soon (it may be right away). A change is anything a
plugin would show: status (accepted, paid, declined, shipped, delivered, cancelled, refunded), the
refunded amount, tracking, a payout made or reversed, a buyer's cancellation or return request
opened or answered. Changes made anywhere count (portal, seller API, the plugin itself, sudemi
support, Stripe events, the deadlines that run out).

```json
{"catalogueRevision": "r42", "productCount": 120, "intervalSeconds": 300, "pluginVersion": "1.3.0",
 "changesCursor": "c1.MTc2MjY4NjAwMDAwMDAwMDowMDAwMDAwMC0wMDAwLTAwMDAtMDAwMC0wMDAwMDAwMDAwMDA"}
```

```json
{"status": "ok", "...": "...",
 "changed_orders": [
   {"order_id": "0f6c1c3e-...", "status": "SHIPPED", "revision": 4, "changed_at": "2026-11-09T10:15:02.123456Z",
    "refunded_minor": 0, "tracking": {"carrier": "DHL", "code": "JJD0001"}, "shipped_at": "2026-11-09T10:15:02Z",
    "delivered_at": null, "request": null},
   {"order_id": "7a1d...", "status": "DELIVERED", "revision": 7, "changed_at": "...", "refunded_minor": 300,
    "tracking": null, "shipped_at": "...", "delivered_at": "...", "request": {"kind": "RETURN", "status": "REQUESTED"}}],
 "changes_cursor": "c1....", "changes_more": false}
```

- `cancelled_by` (core 2026-11-09, also on `settled_orders`): for a CANCELLED or DECLINED order who
  ended it: `buyer` (the buyer cancelled), `shop` (this shop declined or cancelled it, on the
  portal, over the seller API or from the plugin) or `sudemi` (no answer in time, the shop was
  removed, or sudemi support refunded it); `null` for every other status. Say this in the shop's
  order note instead of assuming the buyer cancelled.
- Apply an entry only when its `revision` is higher than the one the plugin holds for that order
  (equal: a repeat, ignore it). An entry is a summary; `GET /orders/{id}` has the rest.
- The feed reads 30 seconds behind real time, so a change shows up on the first heartbeat at least
  30 seconds after it was made, and later while a change on sudemi is still being saved (the feed
  never reads past the oldest unfinished save, so no change is ever skipped; the cursor then simply
  stays where it is). A cursor that cannot be read starts over from the last 7 days and
  says `changes_cursor_reset: true`; so does `""`.
- The cursor is opaque. Keep it per install; after a re-pairing start again with `""`.
- Orders waiting for an answer still come as `pending_orders`, return requests to answer as
  `open_requests`; the feed is for keeping the plugin's copy of everything else current. A daily
  `GET /orders?from=<yesterday>` is a cheap safety net.

### Order list and detail

`GET /integration/v1/orders` without table parameters is the 1.2 call. With any of `from`, `to`,
`q`, `sort`, `dir`, `offset` it is the portal's order table:

```
GET /integration/v1/orders?status=PAID&from=2026-11-01&to=2026-11-09&q=kettle&sort=total&dir=desc&limit=50&offset=0
```

```json
{"counts": {"PAID": 3, "SHIPPED": 9}, "total": 3, "offset": 0, "limit": 50, "next_offset": null,
 "orders": [{"order_id": "...", "status": "PAID", "placed_at": "...", "total_minor": 3195, "currency_code": "EUR",
   "your_net_minor": 2800, "lines": [{"title": "Kettle", "quantity": 1}], "customer_name": "Ana Lopez",
   "destination_country": "DE", "shipping_minor": 695, "tracking": null, "shipped_at": null, "delivered_at": null,
   "revision": 2, "refunded_minor": 0,
   "payout": {"state": "waiting_for_shipment", "expected_minor": 2745, "paid_out_minor": 0,
              "currency_code": "EUR", "due_at": null}}]}
```

Payout states: `not_charged` (no money taken, or never), `waiting_for_shipment` (paid, due once
shipped), `scheduled` (due at `due_at`), `processing` (due, the transfer is on its way), `held` (a
chargeback), `partly_paid_out`, `paid_out`, `nothing_due` (refunded in full). `expected_minor` is
in the payout currency, which differs from the order's for a converted payment (§4c).
`carried_to_owed_minor` (core 2026-11-09, V68): what this order could not cover after a refund
(the shop pays Stripe's whole currency conversion fee and the currency difference of a refund) and
was added to the company's owed balance, net of what this order's own payout had paid off (a payout that paid off older debt and was then reversed in full gives that debt back to the balance; it is not counted here as this order's cost); `owed_paid_off_minor`: the part of this order's payout that
paid that balance off instead of being transferred (it counts as paid out for `state`).
`card_fee_shop_minor` (core 2026-11-09, V70): the shop's half of Stripe's card processing fee for
this order, in the payout currency, already taken off `expected_minor` (owner 2026-11-09: sudemi and
the shop share the fee equally; the shop's half is rounded down, sudemi pays the odd cent). Stripe
does not return the fee on a refund, so it stays on a refunded order. 0 for orders from before
9 November 2026 and until the fee is read. In `GET /orders/{id}` the payout also has
`card_fee_minor`: the order's whole part of the payment's fee (null when none applies), split over
the orders of a shared payment in proportion to their totals. Example: 100.00 EUR order, Stripe fee
1.75 EUR: `card_fee_minor` 175, `card_fee_shop_minor` 87, `expected_minor` 9813.

`GET /integration/v1/orders/{id}` is the portal's order page: lines with the shop's own
`external_id`, money with `refunded_minor`, tracking, the `timeline` (`PLACED`, `ACCEPTED`, `PAID`,
`DECLINED`, `SHIPPED`, `DELIVERED`, `REFUND`, `CANCELLED`, `REQUEST`, `REQUEST_DECIDED`), the open
`customer_request`, the `payout`, the `revision` and `allowed_actions`: what the shop can do now,
from `accept`, `reject`, `shipped`, `update_tracking` (`POST /orders/{id}/tracking`, see below),
`delivered`, `refund`, `cancel`, `request_accept`, `request_reject`, each the existing
`/orders/{id}/...` call. The call itself still decides: an
action listed can be refused when the order changed in between.

### Tracking after shipping (`POST /orders/{id}/tracking`)

A shop often marks an order shipped before it has the tracking number, or types a wrong one.
`shipped` only works on a PAID order, so this call carries tracking that comes later:

```json
POST /integration/v1/orders/0f6c1c3e-.../tracking
{"carrier": "DPD", "trackingCode": "09445440012345"}
```

```json
{"status": "ok", "order_id": "0f6c1c3e-..."}
```

- Only while the order is SHIPPED (`update_tracking` in `allowed_actions`). For a PAID order send
  `shipped` with the tracking; a plugin with a single "send tracking" button sends `shipped` when
  the order is PAID and `tracking` when it is SHIPPED. After delivery, cancellation or a full
  refund the answer is 409 `not_shipped` (a partial refund keeps the order SHIPPED).
- The buyer gets an e-mail with the new carrier and code, and their AI assistant shows the new code
  (MCP order status). The order's `revision` goes up, so the change feed lists it with the new
  `tracking`; lines that shipped with the old tracking get the new one too.
- Safe to repeat: the same carrier and code again answer `already_resolved` and e-mail nobody.
- Audited as `order.tracking_updated` (actor PLUGIN, the install; old and new values).

### Export and bulk actions

`GET /integration/v1/orders/export.csv?lang=de&decimal=comma&from=2026-10-01` is the portal's
export of this shop's orders (the same filters, every page, at most 10,000 rows; `X-Export-Truncated:
true` when there were more). Headings and statuses in any of the 12 portal languages.

`POST /integration/v1/orders/bulk` is the portal's "several orders at once":

```json
{"action": "shipped", "items": [
  {"orderId": "0f6c1c3e-...", "carrier": "DHL", "trackingCode": "JJD0001"},
  {"orderId": "7a1d...", "carrier": "PostNL", "trackingCode": "3SABC"}]}
```

```json
{"action": "shipped", "done": 1, "refused": 1, "results": [
  {"order_id": "0f6c1c3e-...", "result": "done"},
  {"order_id": "7a1d...", "result": "refused", "error": "wrong_state",
   "message": "The order is not in a state that allows this."}]}
```

Each order goes through the same service as its own endpoint, in its own transaction; one refusal
never stops the others. `accept` captures each payment like `POST /orders/{id}/accept` (and is
refused as a whole with 403 `seller_suspended` for a suspended shop); `reject` needs one `reason`
for all, shown to the buyers. Refunds and cancellations are not offered in bulk, on purpose: each
moves a different amount of money. Send at most 100 orders per call.

### Shipping

`GET /integration/v1/shipping` returns this shop's settings, as the portal's Shipping page and the
seller API's `GET /v1/shipping?shop_id=` do, plus `effective`: per country what buyers pay.

```json
{"shop_id": "...", "shop_name": "Kettle Shop", "mode": "custom", "currency": "EUR", "max_price_minor": 1000000,
 "countries": [{"country": "DE", "enabled": true, "price_minor": 495, "offered": true,
                "shop_price_min_minor": 695, "shop_price_max_minor": 895, "shop_price_products": 12}, "..."],
 "effective": [{"country": "DE", "offered": true, "source": "sudemi_price", "min_minor": 495, "max_minor": 495},
               {"country": "FR", "offered": true, "source": "shop_price", "min_minor": 795, "max_minor": 995},
               {"country": "AT", "offered": false, "source": null, "min_minor": null, "max_minor": null}]}
```

`source: shop_price` means the price the plugin sends per product (from `min_minor` to `max_minor`
over its products); `sudemi_price` a flat price set on sudemi for every product. One charge per
parcel, never per unit.

`POST` (or `PUT`) `/integration/v1/shipping` replaces the settings, with the portal's checks:

```json
{"mode": "custom", "currency": "EUR", "countries": [
  {"country": "DE", "priceMinor": 495}, {"country": "FR"}, {"country": "AT", "enabled": false}]}
```

`{"mode": "shop"}` clears them (the plugin's own prices apply again). In custom mode only the
listed, enabled countries are offered. Prices are in the shop's currency, 0 to `max_price_minor`.
Other shops of the company keep their own settings.

### Payouts (read only)

`GET /integration/v1/payouts`: the company's payout account (`account.state`: `NOT_STARTED`,
`INCOMPLETE`, `UNDER_REVIEW`, `ACTIVE`, `ACTION_REQUIRED`; managed on the portal only) and this
shop's payouts per currency: `paid_out_minor`, and `outstanding_minor` split into
`waiting_for_shipment_minor`, `scheduled_minor` (with `next_due_at`), `processing_minor` and
`held_minor`; `card_fee_shop_minor` is the shop's half of the card fees on those orders, already
taken off. A shop is paid `payout_delay_days` after delivery (that plus 14 days after shipping
when delivery is never reported), never while a chargeback is open. `owed_to_sudemi` (core
2026-11-09): what the company owes sudemi per currency (currency conversion costs, or its half of
a card fee, that a refund left nothing to take from), for the whole company, not only this shop; it is taken from the company's
next payouts in that currency, never more than one payout, the rest carried. Empty when nothing is
owed.

## 4e. Statistics, the shop's products on sudemi, the shop profile (core 2026-11-13)

Owner 2026-11-13: "Everything should be visible to the shop via plugin ... statistics, sales on
sudemi, how many times his products are listed". All calls are signed like every other plugin call
(GET signing: section "Signing a GET") and see **only this shop** (install), never its sister shops.
Money is in minor units of the shop's currency (`currency`).

### What sudemi counts (counts only, no buyers)

Per product and UTC day (table `product_stats_daily`):
- `impressions`: the product was in the results a buyer's AI assistant (or the buyer API) got back
  from a search. One per search answer, not per buyer.
- `detail_views`: the assistant opened the product's details (price for an address, offers).
- `cart_adds`: the product was put in a buyer's cart.

Orders come from the orders themselves, not from these counters.

### `GET /integration/v1/stats?from=YYYY-MM-DD&to=YYYY-MM-DD`

Both optional, UTC days, inclusive. Default: the last 30 days up to today. At most 366 days;
otherwise `400 {"error":"bad_period"}`.

```json
{
  "install_id": "...", "shop_name": "sudemi Demo Store", "currency": "EUR",
  "from": "2026-10-15", "to": "2026-11-13",
  "totals": {
    "impressions": 1200, "detail_views": 140, "cart_adds": 22,
    "orders": 9, "units": 12,
    "sales_minor": 24550, "refunded_minor": 1200, "commission_minor": 2455, "net_minor": 20895,
    "average_order_minor": 2727, "conversion_bps": 643
  },
  "daily": [ { "day": "2026-10-15", "impressions": 40, "detail_views": 5, "cart_adds": 1, "orders": 0, "sales_minor": 0 } ],
  "top_products": [ { "external_id": "27", "title": "...", "impressions": 300, "detail_views": 40,
                      "cart_adds": 6, "orders": 3, "units": 4, "sales_minor": 13980 } ],
  "orders_by_status": { "PAID": 2, "SHIPPED": 1, "DELIVERED": 5, "REFUNDED": 1 },
  "countries": [ { "country": "DE", "orders": 4, "sales_minor": 11000 } ],
  "listings": { "total": 14, "listed": 13, "not_listed": 1 }
}
```

- `daily` has every day of the period, zeros included (draw it as is).
- `orders`: orders of this shop the buyer paid (authorised) in the period, whatever happened later.
  `sales_minor`: what buyers paid for them (goods + shipping, VAT included). `refunded_minor`,
  `commission_minor`: of those orders. `net_minor` = sales - refunded - commission (a guide; the
  payout figures in `GET /payouts` are what is actually paid). `conversion_bps` = orders x 10000 /
  detail_views (0 when there were no views). `top_products`: up to 20, by sales then impressions.

### `GET /integration/v1/listings?status=all|listed|not_listed&q=&page=0&size=50`

The shop's products as sudemi holds them (deleted ones left out). `size` at most 200; `q` matches
title, SKU or external id.

```json
{ "total": 14, "page": 0, "size": 50, "items": [ {
    "external_id": "27", "title": "Porcelain teapot, 1 litre - Celadon green", "sku": "AD-TEAPOT-GRN",
    "price_minor": 3495, "currency": "EUR", "quantity": 6, "in_stock": true,
    "enabled": true, "listed": true, "not_listed_reasons": [],
    "images": 1, "shipping_countries": 8, "updated_at": "2026-11-12T21:49:49Z",
    "impressions_30d": 120, "detail_views_30d": 14, "orders_30d": 2 } ] }
```

`not_listed_reasons` (zero or more, show them in the shop's language): `disabled_by_shop` (the
switch below), `not_in_shop` (the last full sync no longer contained it: deleted, switched off or
hidden in the shop), `out_of_stock`, `no_shipping` (no shipping price for any country),
`suspended` (taken down by sudemi), `payouts_not_ready` (the company's payout account is not
active), `shop_not_reporting` (this shop's heartbeat stopped or it was disconnected). Unknown
values may be added later: show them as "not listed".

### `POST /integration/v1/listings/{externalId}/enabled` `{"enabled": false}`

The shop's own "show on sudemi" switch for one product. Kept by sudemi: a sync never turns it back
on. Answer: `{"external_id": "27", "enabled": false, "listed": false}`; unknown product `404`.

### Shop profile

`GET /integration/v1/shop` also returns `shop_name`, `shop_description`, `support_email`,
`returns_window_days`, `currency` and `rename_via_support: true`. `POST /integration/v1/shop`
(existing) changes the description, support e-mail and returns window. The name buyers see is not
changed by a plugin (owner 2026-11-09: rename only via a support ticket): show it read-only with a
button that opens a support ticket.

### What the plugin shows (all three, same structure, all 12 languages)

A **sudemi** menu of its own with: Dashboard (statistics: KPI cards, a daily chart, top products,
period 7 / 30 / 90 days), Products on sudemi (the listings list with reasons and the switch),
Orders, Shipping, Payouts, Shop profile, Support, Settings / connection. Charts are drawn in the
page itself (inline SVG), no outside scripts.

## 5. What a plugin may **not** change

These are returned by `GET /integration/v1/shop` in a `locked_fields` array, and every plugin
renders them read-only with the reason shown. A write is refused with `423 Locked`.

| Field | Why it is frozen |
| --- | --- |
| `shop_url` | It is the identity a buyer sees on the listing, and it was proved by domain verification. Changing it means pairing again from the new domain. |
| `legal_name`, `establishment_country`, `trader_status` | Consumer-law identity. A buyer's withdrawal right and the trader/private distinction depend on them, so they are set once against evidence and changed only through the portal. |
| `vat_number`, `trade_register_number` | Same, and they are checked against registries. |
| `listable` | The platform's decision, not the seller's: it depends on payout readiness and on the safety gate. A plugin cannot list itself. |
| `seller_id` | Issued by the platform. |

A plugin **may** propose `legal_name`, `establishment_country`, `trader_status`, `vat_number`
and `trade_register_number` **once**, during the claim, because that is the moment the seller is
filling in a form anyway and retyping it in two places invites a mismatch. After the claim they
are locked.

---

## 6. What the plugins must do on their own side

Non-negotiable for anything carrying our name, and each repo's review checklist repeats them:

- **The private key never leaves the host, and never sits under the web root.** Store it in the
  platform's own configuration store with the tightest permissions available, never log it, and
  never include it in a support export or a debug dump.
- **Never block the shop.** Sync runs in the background, on the host's own scheduler. A sudemi
  outage must not slow a checkout or an admin page. Every call has a timeout and a backoff.
  **A full catalogue sync never runs inside a page request** (admin or storefront, a "send the
  catalogue now" button included): a page may only ask for one (a flag) and send a heartbeat.
  The plugin's cron entry runs it; without cron it runs in small resumable steps (25 products,
  about two seconds) after a page was produced, under a database lock (owner, 2026-11-08).
- **Verify TLS.** No disabling of certificate verification, not even behind an option.
- **Authorise the admin screens.** Capability checks plus the platform's CSRF token on every
  form and every AJAX route.
- **Escape on output, parameterise on query.** No string-built SQL, no unescaped echo.
- **Fail closed.** A signature that does not verify, a response that does not parse, a field
  that is missing - stop and report, do not guess.
- **Clean up on uninstall**, and revoke the install while doing it.
- **Licence per ecosystem**: GPLv2-or-later for WooCommerce and OpenCart, AFL-3.0 for the
  PrestaShop module, so each can be distributed where its users expect to find it.

---

## 7. Status

| Platform | Repository | State |
| --- | --- | --- |
| WooCommerce | `sudemi-woocommerce` | reference implementation |
| PrestaShop | `sudemi-prestashop` | follows the reference |
| OpenCart | `sudemi-opencart` | follows the reference |
| Shopify | inside sudemi-core (`nl.aistore.core.shopify`) | hosted OAuth app, see `docs/operations/shopify-app.md` |

Platform side: pairing, domain verification and re-verification, the heartbeat, freshness gating,
catalogue and price push, and sync commit with the A-17 safety share are implemented. Credential
rotation and portal-side revocation are specified above and not yet built.

Shopify is not a file a seller uploads. It is an OAuth app with a public callback, its own
webhook HMAC scheme and an App Store review, so it shares this protocol's *server* side but
almost none of its install flow. Built 2026-11-07 inside core: the OAuth callback replaces the
challenge file, the claim screen is the same, the install is an ordinary `integration_install`
(platform `shopify`, no signing key, so it can never sign an `/integration/v1` request), sudemi
pulls products instead of the plugin pushing them, and a 5-minute probe of the shop stands in for
the heartbeat. Details: `docs/operations/shopify-app.md`.
