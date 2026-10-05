# MX Cookieless Analytics for Shopware 6

European privacy-first, cookieless analytics for Shopware 6 storefronts.
GDPR-native, EU-hosted, no cookie banner required for basic stats.

- Opt-in: the tracker never loads until tracking is enabled AND the site is
  connected (auto-connect handshake on config save).
- Verification: one-time challenge token served at `/mxcoan/challenge`,
  fetched back by the MetriXs API. No DNS verification.
- Server-side commerce events planned post-1.0 (`order_completed`).

See the MetriXs docs for the full setup guide.