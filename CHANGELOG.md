# Changelog

## 1.0.0 (2026-10-05)

- Initial release
- Opt-in tracker injection into the storefront head (off until connected AND
  enabled, double-gated)
- Automatic connect + verification handshake on settings save (5-minute
  throttle, one-time challenge token served from the shop's own domain)
- Challenge endpoint at `/mxcoan/challenge`
- Uninstall notification so ingestion stops on the MetriXs side
- Self-hosted Shopware 6.5 and 6.6 (Shopware Cloud not supported yet)