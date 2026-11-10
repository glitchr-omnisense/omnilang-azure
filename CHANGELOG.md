# Changelog

## 1.x (unreleased)

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts: text translation
  v3.0 (translate with textType and category, detect in chunks of 100, languages), synchronous
  document translation (2024-05-01, multipart `document`, on the custom domain). The key by
  reference in Ocp-Apim-Subscription-Key, the region in Ocp-Apim-Subscription-Region. policy.json:
  characters by tier, no request limit, data retention. Tests on MockHttp over answers written from
  the v3.0 and document translation references, each asserting the exact request; PolicyTest,
  BareTest; PHPStan at max, baseline empty.
