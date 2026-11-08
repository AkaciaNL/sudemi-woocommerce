<!-- Copy of docs/seller-integration-protocol.md in the sudemi core repository (updated 2026-11-08). The core copy is the source of truth. -->

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
| Pairing lifetime | 30 minutes |

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

**Every call is a POST**, including the ones earlier drafts of this document described as `PUT`
and `PATCH`. Three reasons, and the first is the one that matters: the canonical string a plugin
signs includes the HTTP method, so a single method means one shape of that string in four
languages instead of four - and a method mismatch is a signing bug neither side can see. Shared
hosts also still filter `PATCH`, and PHP's stream wrappers handle `POST` most predictably.

| Endpoint | Carries |
| --- | --- |
| `POST /integration/v1/heartbeat` | Liveness and the catalogue revision; see §3a |
| `GET  /integration/v1/shop` | What this install is, its `locked_fields`, and whether its catalogue is currently listed |
| `POST /integration/v1/shop` | Shop metadata: display name, description, currency, shipping destinations, returns window, support e-mail |
| `POST /integration/v1/categories` | The shop's own category tree: `externalId`, `parentExternalId`, name, description |
| `POST /integration/v1/products` | Products in batches of ≤ 500: identity, title, description, category, brand, GTIN/MPN, **specs** as typed attributes, images as URLs, price, stock, shipping |
| `POST /integration/v1/products/prices` | Price and stock deltas only - the hot path, called from the shop's own save hook |
| `POST /integration/v1/sync` | Opens a full sync and returns its id |
| `POST /integration/v1/sync/{id}/commit` | Ends a full sync and tells the platform which products were *not* in it |
| `POST /integration/v1/orders/{id}/accept` | The shop can fulfil this order. **This captures the buyer's money**; see §4a |
| `POST /integration/v1/orders/{id}/reject` | The shop cannot fulfil it. Releases the buyer's authorisation; optional `{"reason": "..."}` |
| `GET  /integration/v1/orders` | The shop's own order history: approved, declined, shipped, delivered. Optional `?status=` filter and cursor paging |
| `GET  /integration/v1/orders/{id}/fulfilment` | Where to ship it. Only after the order is accepted; see §4a |
| `POST /integration/v1/orders/{id}/shipped` | Dispatched. Optional `{"carrier": "...", "trackingCode": "..."}` |
| `POST /integration/v1/orders/{id}/delivered` | Arrived. **Starts the withdrawal period running** |

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

- **The plugin (or feed) wins.** A product this plugin sends takes over a row with the same
  `externalId` that the API created or archived: `source` becomes `PLUGIN`, the row is linked to
  this install, and the next full sync governs it like any other product.
- **The API does not overwrite a plugin.** While the install that sent a product is not revoked,
  API create (same `external_id`), replace and delete answer 409 `managed_by_plugin`; only the
  on/off switch, price and stock (`PATCH /v1/products/{id}`, `POST /v1/products/bulk`) are
  accepted, and the plugin's next push overwrites price and stock as before. Products of a
  revoked install can be taken over by the API.
- **A sync commit never delists API products**: they carry no install id, so `unlistMissing`
  cannot reach them, and a feed import that leaves them out does not unlist them either.

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
| 200 | `{"status": "already_resolved", "order_status": "..."}` | Answered before, or the window closed. Not an error; stop retrying. `order_status` says how it ended: `PAID` (or `ACCEPTED`, `SHIPPED`, ...) means your earlier accept landed, so keep the local order; `DECLINED` means it was released, so cancel the local order and restock. Never cancel on `already_resolved` alone. |
| 404 | `{"error": "no_such_order"}` | No such order awaiting an answer *from this shop*. Identical whether it does not exist or belongs to another seller, so an install cannot probe for other shops' order ids. |
| 409 | `{"error": "authorisation_lapsed"}` | The buyer's authorisation could no longer be charged. The order will be released. |

### After the money is captured

Two more things the shop reports, because nothing on the platform can observe them:

| Call | When | Effect |
| --- | --- | --- |
| `shipped` | the parcel leaves | buyer is e-mailed the carrier and tracking |
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

## 4b. Contract reference (checked against core 2026-11-07, `e2e/integration_contract.py`)

Requests are camelCase JSON; responses are snake_case (one exception: the items of a `rejected`
array are `{"externalId", "reason"}`). Unknown request fields are ignored.

| Endpoint | Body | Answer |
| --- | --- | --- |
| `POST /heartbeat` | `catalogueRevision`, `productCount`, `intervalSeconds` (clamped to 60-900), `pluginVersion`; body optional | `status`, `heartbeat_seconds`, `trusted_until`, `catalogue_listed`, `not_listed_reason`, `full_sync_requested`, `domain_challenge{token,path,why}`, `pending_orders[{order_id,respond_by,total_minor,shipping_minor,currency_code,destination_country,lines[{product_id,external_id,title,quantity,unit_price_minor}]}]` + `pending_orders_note`, `settled_orders[{order_id,status,declined_reason}]`, `open_requests[{request_id,order_id,kind,reason,requested_at,respond_by}]` + `open_requests_note` (each list only when not empty) |
| `GET /shop` | - | `install_id`, `seller_id`, `shop_origin`, `locked_fields`, `heartbeat_seconds`, `catalogue_listed`, `not_listed_reason`, `full_sync_requested`, `trusted_until` |
| `POST /shop` | `displayName`, `description`, `supportEmail`, `returnsWindowDays` (locked fields: 423) | `status`, `locked_fields` |
| `POST /categories` | `categories[{externalId,parentExternalId,name,description}]`, at least one | `status`, `accepted`, `removed` |
| `POST /products` | `syncId` (optional), `catalogueRevision` (optional, change pushes only: the shop's revision after this push), `products[]` (1-500): `externalId`, `title`, `priceMinor` (1 to 10,000,000), `currency`, `quantity` or `inStock`, `description`, `url`, `brand`, `gtin`, `mpn`, `sku`, `weightGrams`, `maxHandlingTimeDays`, `maxTransitTimeDays`, `categoryExternalIds`, `images` (on the shop origin), `specs[{name,values}]`, `shipping[{country,costMinor}]`, `safety{...}`, `withdrawalExclusion` (hygiene, custom, perishable, media, press, mixed, alcohol; unknown = none) | `status`, `accepted`, `rejected[]` (item by item), `catalogue_revision_agreed` |
| `POST /products/prices` | `updates[]` (1-500): `externalId`, `priceMinor` (1 to 10,000,000), `currency`, `quantity` or `inStock`; `catalogueRevision` (optional: the shop's revision after these changes) | `status`, `applied`, `rejected[]`, `full_sync_requested` (true when an id was unknown), `catalogue_revision_agreed` |
| `POST /sync` | - | `sync_id`, `expires_at`, `max_products_per_request` |
| `POST /sync/{id}/commit` | `catalogueRevision` (optional: the shop's revision when this sync started) | `status: committed` (`products_seen`, `delisted`) or `status: held` (`would_delist`, `live_products`, `message`) |
| `POST /orders/{id}/accept` / `reject` | reject: `reason` optional | see §4a; accept adds `payment: captured` or `waiting_for_other_shops` |
| `GET /orders?status=&limit=&after=` | - | `counts`, `orders[{order_id,status,placed_at,total_minor,currency_code,your_net_minor,lines[{title,quantity}],respond_by?,declined_reason?,customer_request?,customer_name?}]`, `next_cursor` (only when there is a next page) |
| `GET /orders/{id}/fulfilment` | - | `deliverable`, `ship_to{recipient_name,line1,line2,postal_code,city,region,country,phone}`, `lines[{product_id,external_id,title,quantity}]`; 409 `not_confirmed_yet`, `payment_pending`, `not_payable` |
| `POST /orders/{id}/shipped` / `delivered` | shipped: `carrier`, `trackingCode` (optional, max 100 chars each) | `status: ok` / `already_resolved`; 409 `not_paid` |
| `POST /orders/{id}/refund` | `amountMinor` (omit for the rest), `reason` | `orderId`, `refundedMinor`, `totalRefundedMinor`, `fullyRefunded`, `providerRefundId`; 409 `refused`; 502 `provider_refused`. Send `Idempotency-Key` (any unique string, max 255): a retry with the same key and body returns the first answer and moves no money (24 h); without it a repeated partial refund refunds again |
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
writes 60 a minute, everything else 120 a minute, per install), 502 `provider_refused`. Treat 5xx,
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
