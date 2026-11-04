# Security

## Reporting

Mail **security@sudemi.com**. Please do not open a public issue for a vulnerability.

## What this plugin guarantees

- **The private signing key never leaves the server it was generated on.** It is not sent to
  sudemi, not written under the web root, not logged, and not displayed in the plugin's own
  screens. sudemi stores only the public half, so a breach of their database cannot produce a
  signature for your shop.
- **Every request is signed** over the method, the path, a timestamp, a nonce and a hash of the
  body. A captured request cannot be replayed, and a price cannot be altered in transit.
- **TLS verification is always on** and is deliberately not configurable.
- **No shop can speak for another.** One keypair per installation; a signature from one shop
  does not verify for any other.
- **The shop address is proved, not typed.** sudemi fetches a one-time token from this shop
  before any connection exists.
- **Nothing about your customers is sent.** The plugin sends catalogue data: products, prices,
  stock, categories, images. Not orders, not buyers, not addresses.

## Review checklist

Anything carrying this name has to satisfy all of these, and a reviewer should be able to check
each one by reading:

- [ ] No credential in a file under the web root; none in any log or support export.
- [ ] Every admin action checks a capability **and** a CSRF token before doing anything.
- [ ] Everything printed is escaped; every query is parameterised.
- [ ] TLS verification on, with a timeout on every call.
- [ ] A sudemi outage never blocks a checkout, a product save or an admin page.
- [ ] Fail closed: a response that does not parse is not treated as success.
- [ ] Uninstall removes the key and revokes the install.
