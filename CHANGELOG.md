# Changelog

## 1.x (unreleased)

- The hourly characters per tier as plans in policy.json (omnisense/contracts' `plans`, read at
  the service limits page on 2026-11-10): f0 (the default) 2,000,000, s1 and multi-service
  40,000,000, s2/c2 40,000,000, s3/c3 120,000,000, s4/c4 200,000,000, counted in characters
  translated per key; the `plan` option names the resource's tier. A site that names none is
  held to F0's figure (before: no character limit, since F0's would have capped paid tiers).
- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts: text translation
  v3.0 (translate with textType and category, detect in chunks of 100, languages), synchronous
  document translation (2024-05-01, multipart `document`, on the custom domain). The key by
  reference in Ocp-Apim-Subscription-Key, the region in Ocp-Apim-Subscription-Region. policy.json:
  characters by tier, no request limit, data retention. Tests on MockHttp over answers written from
  the v3.0 and document translation references, each asserting the exact request; PolicyTest,
  BareTest; PHPStan at max, baseline empty.
