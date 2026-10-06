# MX Cookieless Analytics for Shopware 6

European privacy-first, cookieless analytics for Shopware 6 storefronts.
GDPR-native, EU-hosted, no cookie banner required for basic stats.

- **Opt-in**: the tracker never loads until tracking is enabled AND the site
  is connected (auto-connect handshake on config save).
- **One-click connect**: a "Connect with MetriXs" button on the plugin
  settings page; no API key copying. The manual API-key flow works unchanged.
- **Verification**: one-time challenge token served at `/mxcoan/challenge`,
  fetched back by the MetriXs API. No DNS verification.
- **Revenue**: `order_completed` is sent server-side when a payment
  transaction enters "paid", so redirect payments (iDEAL, Bancontact,
  Carte Bancaire) are included too. Revenue is converted to EUR by MetriXs.
- **Cache-aware**: flushes Shopware's HTTP cache when the connection changes,
  so the tracker appears immediately.
- **Compatibility**: self-hosted Shopware 6.5 and 6.6. Shopware Cloud shops
  use a different extension system ("apps") and are not supported yet.

## Install

1. Download the latest ZIP from
   [GitHub releases](https://github.com/davidhildering/shopware-mx-cookieless-analytics/releases)
2. In the Shopware administration: **Extensions → My extensions → Upload
   extension**
3. Open the plugin settings and click **Connect with MetriXs**. Create or log
   into your MetriXs account, pick the shop's domain, and the plugin stores
   the key, verifies the shop and switches tracking on.

   Prefer to do it by hand? Create an account at
   [app.metrixs.eu](https://app.metrixs.eu), add your shop, create a site API
   key (**Settings → Sites → API keys**), paste it into the plugin settings and
   save. Verification runs automatically; then enable **Enable tracking**.
4. Done. Your storefront head now carries the tracker.

Full guide: [docs.metrixs.eu/features/shopware](https://docs.metrixs.eu/features/shopware)

## Sales revenue

When a payment transaction enters "paid", the plugin sends an `order_completed`
event with the order total, currency, order number, line items and, when
present, the promotion code. Revenue is converted to EUR at ingest.

- The event is sent once per order. An order that could not be reported (for
  example because MetriXs was unreachable) stays eligible and is reported on
  the next "paid" transition.
- Connecting turns the **Commerce** panel on for the site
  (**Settings → Sites → Commerce** if you want to change it later).
- Totals use the order's own tax state, so B2B/net shops report net amounts,
  consistent with the order total in Shopware.

## Multiple shop domains

One plugin connection covers one MetriXs site. If your sales channels run on
separate domains (for example `shop.de` and `shop.nl`), the tracker reports
all of them under the domain that was verified during the connection. Add a
second MetriXs site per domain if you need them separated.

## Uninstall

Uninstalling tells MetriXs to stop collecting for the site and removes the
plugin's API key and connection state from your shop's database. Collected
statistics stay in MetriXs and are deleted according to your plan's retention
setting, so reinstalling and reconnecting continues where you left off.

## About MetriXs

[MetriXs](https://metrixs.eu) is cookieless, privacy-first web analytics
hosted in the EU (Germany). No cookies, no personal data, no consent banner.
Free plan, paid from €6/month.

MIT licensed.
