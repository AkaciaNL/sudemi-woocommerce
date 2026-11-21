# Plugin protocol

The protocol between this plugin and sudemi (pairing, Ed25519 request signing, catalogue, heartbeat,
orders, shipping, payouts, statistics) is published on the sudemi website:

**https://sudemi.com/docs/en/plugin-protocol**

The page is available in 12 languages (replace `en` with `nl`, `tr`, `de`, `fr`, `es`, `it`, `pl`,
`ru`, `zh`, `ar` or `he`); the technical reference on every page is in English. It is the single,
current version: this file used to hold a copy, which went out of date.

Section numbers are the same as before, so references in this repository's code such as
"PROTOCOL.md 4d" point to section 4d of that page.

Building a plugin for another shop system? This plugin and its siblings are reference
implementations:

- https://github.com/AkaciaNL/sudemi-woocommerce
- https://github.com/AkaciaNL/sudemi-prestashop
- https://github.com/AkaciaNL/sudemi-opencart

Prefer plain API calls over a plugin? The seller API: https://sudemi.com/developers/shops

## Copy: the shop's own shipping rules (section 4d, from 1.4.7)

A copy of one section of the page above, kept here because this plugin implements it in
`includes/class-sudemi-shipping-rules.php` and `includes/class-sudemi-shipping.php`. The page
above is the current version; where they differ, the page wins.

### The shop's own shipping rules (`POST /integration/v1/shipping/rules`, core 2026-11-21)

Owner decisions 2026-11-21: one shop's items to one country travel in **one parcel**, and the parcel
is charged by the shop's **own rule** as its platform defines it, not "the highest single-item rate".
Each product's `shipping[{country,costMinor}]` stays what ONE item costs to ship on its own (search and
product pages show it); the rule decides what a parcel of several items costs. The plugin sends the rule
for every country it delivers to, after every full sync and whenever the shop's shipping settings change:

```json
{
  "currency": "EUR",
  "rules": [
    {"country": "DE", "extraItemMinor": 150, "freeOverMinor": 5000},
    {"country": "FR", "baseMinor": 695},
    {"country": "NL", "bandBasis": "weight", "aboveLast": "none",
     "bands": [{"upTo": 2000, "priceMinor": 495}, {"upTo": 10000, "priceMinor": 895}, {"upTo": null, "priceMinor": 1495}]},
    {"country": "BE", "bandBasis": "price", "bands": [{"upTo": 4999, "priceMinor": 695}, {"upTo": null, "priceMinor": 0}]}
  ],
  "issues": [
    {"code": "shipping_rule_approximated", "message": "flat_rate cost formula '[fee percent=10]' cannot be copied; highest rate used",
     "details": {"countries": ["AT"], "method": "flat_rate"}}
  ]
}
```

| Field | Notes |
| --- | --- |
| `currency` | optional; must be the shop's currency (as product prices) |
| `rules[]` | at most one per country; **replaces** every rule this plugin sent before. A country without a rule: the parcel pays the highest `costMinor` among its items, once (the old rule) |
| `country` | ISO 3166-1 alpha-2, a country sudemi delivers to (others are ignored) |
| `baseMinor` | the parcel's price, whatever it holds; replaces the products' `costMinor` for parcels. Leave out to use the highest item `costMinor` |
| `extraItemMinor` | added for every item after the first (quantities added up over all lines of the parcel) |
| `freeOverMinor` | the parcel ships free once its goods reach this amount: the items at the shop's own prices, VAT included, sale and quantity prices applied, shipping not counted, in the shop's currency |
| `bandBasis` | `weight` (the parcel's weight in grams: each product's `weightGrams` x quantity) or `price` (the parcel's goods as above) |
| `bands[]` | ascending `{upTo, priceMinor}`; a parcel pays the first band whose `upTo` is at least its weight or goods; `upTo: null` = no upper limit (last band only). The band replaces `baseMinor` and the items' `costMinor` |
| `aboveLast` | `none` (default: not delivered above the last band, the country is not offered for that parcel) or `highest` (charged the highest band) |

All amounts are minor units of the shop's currency, VAT included as buyers pay it, 0 to the price cap.
With weight bands, a product without `weightGrams` counts as the heaviest band (never undercharged).

**What a plugin cannot copy exactly** (a cost formula, a percentage fee, a rate that depends on
shipping classes in a way the fields above cannot say, a table-rate plugin, a carrier module or a
live carrier rate): it sends the shop's **highest** rate for that country (as `baseMinor`, or in the
products' `costMinor`) so the shop is never undercharged, and reports `shipping_rule_approximated`
(or `shipping_rate_calculated` for a live carrier rate) in `issues` without `externalId`, with the
countries in `details.countries`. The shop then sees "set it on sudemi" with a link to its Shipping
page. These issues are resolved when a later `POST /shipping/rules` no longer reports them.

**sudemi's settings win.** What the shop sets on its sudemi Shipping page (portal, seller API,
`PUT /integration/v1/shipping`) overrides the plugin's rule field by field: a sudemi price replaces
`baseMinor` and the bands, a sudemi per-item charge, threshold or bands replace the plugin's.

A product's per-unit handling charge that its platform adds for every unit (PrestaShop's
`additional_shipping_cost`) goes in its shipping entries as `perUnitMinor`:
`"shipping": [{"country": "DE", "costMinor": 795, "perUnitMinor": 200}]`. It is added for every unit of
that product in the parcel on top of the rule's base or band (only when the rule has `baseMinor` or
bands); `costMinor` still includes it once.

The response: `{"status": "ok", "accepted": 3, "ignored": [{"country": "US", "reason": "..."}], "issues": [...]}`.
A broken rule (bands not ascending, negative amount, unknown basis) is listed in `ignored` with the
reason and no rule is kept for that country (the old rule applies); the rest is kept.
