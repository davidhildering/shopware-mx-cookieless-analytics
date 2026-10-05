# MX Cookieless Analytics for Shopware 6

European privacy-first, cookieless analytics for Shopware 6 storefronts.
GDPR-native, EU-hosted, no cookie banner required for basic stats.

- **Opt-in**: the tracker never loads until tracking is enabled AND the site
  is connected (auto-connect handshake on config save).
- **Verification**: one-time challenge token served at `/mxcoan/challenge`,
  fetched back by the MetriXs API. No DNS verification.
- **Cache-aware**: flushes Shopware's HTTP cache on connect so the tracker
  appears immediately.
- **Compatibility**: self-hosted Shopware 6.5 and 6.6. Shopware Cloud shops
  use a different extension system ("apps") and are not supported yet.

## Install

1. Download the latest ZIP from
   [GitHub releases](https://github.com/davidhildering/shopware-mx-cookieless-analytics/releases)
2. In the Shopware administration: **Extensions → My extensions → Upload
   extension**
3. Create a free account at [app.metrixs.eu](https://app.metrixs.eu), add your
   shop, create a site API key (**Settings → Sites → API keys**)
4. Paste the key into the plugin settings and save — verification is
   automatic
5. Turn on **Enable tracking**. You're done

Full guide: [docs.metrixs.eu/features/shopware](https://docs.metrixs.eu/features/shopware)

## About MetriXs

[MetriXs](https://metrixs.eu) is cookieless, privacy-first web analytics
hosted in the EU (Germany). No cookies, no personal data, no consent banner.
Free plan, paid from €6/month.

MIT licensed.