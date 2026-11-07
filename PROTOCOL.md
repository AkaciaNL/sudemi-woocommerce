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

## 4. What a plugin may send

All of it is upsert-by-`external_id`, where `external_id` is the id the shop itself uses. The
plugin never invents sudemi ids.

| Endpoint | Carries |
| --- | --- |
| `PUT /integration/v1/shop` | Shop metadata: display name, description, languages, currency, dispatch country, shipping destinations, returns window, support e-mail |
| `PUT /integration/v1/categories` | The shop's own category tree: `external_id`, `parent_external_id`, names per language |
| `POST /integration/v1/products` | Products in batches of ≤ 500: identity, titles and descriptions per language, category, brand, GTIN/MPN, **specs** as typed attributes, images as URLs, price, stock, shipping, the GPSR safety block FR-34 requires, and an optional `withdrawalExclusion` (`hygiene`, `custom`, `perishable`, `media`, `press`, `mixed`, `alcohol`: the Directive 2011/83/EU art. 16 exception the shop marked the product with; omitted when the normal right of withdrawal applies) |
| `PATCH /integration/v1/products/prices` | Price and stock deltas only - the hot path, called on a shop's own `product_updated` hook |
| `POST /integration/v1/sync/{id}/commit` | Ends a full sync and tells the platform which products were *not* in it |

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

### Orders: answering, and one payment for several shops

The heartbeat answer lists `pending_orders`: orders whose buyer's card is authorised but not
charged. The plugin answers each with `POST /integration/v1/orders/{order_id}/accept` or
`/reject` (`{"reason": "..."}`). Rejecting, or not answering by `respond_by`, releases the
money; nothing is ever charged on silence.

A buyer pays **once for a whole cart**, which can hold several shops' items. A card
authorisation can be captured only once, so the money is taken when **every** shop in that
payment has answered, and only for the orders that were accepted. The accept answer says which
case applies:

| `payment` in the accept answer | Meaning | What the plugin does |
|---|---|---|
| `"captured"` (or absent, from older servers) | The money is taken. | Fetch the address (`GET /orders/{id}/fulfilment`), mark the local order paid, and ship. |
| `"waiting_for_other_shops"` | Accepted, but another shop in the same payment has not answered. The order is `ACCEPTED`, and the address is withheld (`/fulfilment` answers 409 `payment_pending`). | Keep the local order on hold and unpaid. **Do not ship.** |

Both answers carry `"status": "ok"`. Later heartbeats carry `settled_orders` for orders this
shop accepted that are no longer waiting. Each entry is repeated for 7 days, so apply them
idempotently:

```json
"settled_orders": [
  {"order_id": "…", "status": "PAID"},
  {"order_id": "…", "status": "DECLINED", "declined_reason": "…"}
]
```

- `PAID` (or `SHIPPED`/`DELIVERED`): the money is taken. Fetch the address, mark the local order paid, and ship.
- `DECLINED`: released without charge (the capture failed or the authorisation lapsed). Cancel the local order and restore its stock.
- Only a local order still on hold is touched; anything else was settled before.

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
| Shopify | `sudemi-shopify` | hosted app; design agreed separately before implementation |

Shopify is not a file a seller uploads. It is an OAuth app with a public callback, its own
webhook HMAC scheme and an App Store review, so it shares this protocol's *server* side but
almost none of its install flow.
