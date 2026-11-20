# Changelog

## 1.3.0 (2026-11-20)

- Shopware 6.7 compatibility: the plugin now declares `shopware/core ~6.7.0`
  and installs on 6.7 (verified live on 6.7.16.1: install/activate, storefront
  tracker injection, challenge endpoint, admin connect, order-paid event).
  Shopware refused to install 1.2.0 on 6.7 with the previous constraint.

## 1.2.0 (2026-10-06)

- Revenue is visible out of the box: connecting now turns the MetriXs
  commerce panel on for your site (same behaviour as the WordPress plugin).
  Before this version, orders were collected but the dashboard kept the
  revenue panel hidden until you enabled commerce by hand.
- Privacy: order events no longer carry the order's deep link code. The
  recorded page is `/checkout/finish` without a token, so nobody reading your
  analytics can use a stored URL to open a customer's order.
- Reliable revenue: an unreachable MetriXs API no longer drops that order for
  good. The plugin marks an order as reported only after a successful send,
  so the next "paid" transition reports it.
- One click on "Connect with MetriXs" now performs one connection attempt
  instead of up to three, and re-verifying an unchanged connection no longer
  flushes your shop's page cache.
- Settings page and connect button are now translated into German.
- The connect start endpoint requires the "change plugin settings" permission;
  a restricted admin role gets a clear message instead of a generic error.
- Faster payment return: the order event call times out in 5 seconds instead
  of 15, so a slow MetriXs API cannot add long pauses to the checkout return.
- Uninstalling removes the plugin's API key and connection state from your
  shop's database.

## 1.1.0 (2026-10-05)

- One-click connect: a "Connect with MetriXs" button on the plugin config
  page opens the MetriXs dashboard, where you create (or open) your account;
  the shop is added, connected, verified and tracking is enabled
  automatically. No API key copying. The manual API-key flow works unchanged.

## 1.0.1 (2026-10-05)

- Server-side `order_completed` commerce events: sent when a payment
  transaction enters "paid" (redirect payments like iDEAL captured), exactly
  once per order, with total, currency, order id, items and discount code.
  Revenue is normalized to EUR by the MetriXs API.
- Tracker cache-bust version follows TRACKER_VERSION (1.2.0).
- Body-shape fix for the /api/event ingest schema (n/u/d/p).

## 1.0.0 (2026-10-05)

- Initial release
- Opt-in tracker injection into the storefront head (off until connected AND
  enabled, double-gated)
- Automatic connect + verification handshake on settings save (5-minute
  throttle, one-time challenge token served from the shop's own domain)
- Challenge endpoint at `/mxcoan/challenge`
- Uninstall notification so ingestion stops on the MetriXs side
- Self-hosted Shopware 6.5 and 6.6 (Shopware Cloud not supported yet)
