# Changelog

All notable changes to the package will be documented in this file.

## Unreleased

- Add `Helper\Utils::matchTerm()`: match a purchase term to a CA term within 10%
- Sectigo: map `validity_days` to the term the API accepts (30, 90, 365, 730, 1095, 1461 or 1826
  days) within 10% of it, and reject any other term
- Actalis: map `validity_days` to the `3m` or `1y` duration within 10% of it, and reject any other
  term. Terms over 120 days no longer fall back to `1y`

## [1.0.0](https://github.com/upmind/provision-provider-ssl/releases/tag/v1.0.0) - 2026-10-06

- Define the `ssl-certificates` provision category: lifecycle functions (`create`, `getInfo`,
  `downloadCertificate`, `restartValidation`, `reissue`, `renew`, `revoke`, `cancel`),
  parameter/result DataSets and the normalized certificate order status model
- Scope: single-domain and wildcard DV and OV certificates, validated by DNS or file
- Add `Helper\KeyPairHelper` to generate key pairs and CSRs on behalf of customers
- Add `Helper\CsrHelper` and `Category::assertCsrMatches()`: reject a customer-supplied CSR
  that is invalid, names a different domain than the order, or carries additional domains
- Add shared provider plumbing: `Helper\Utils` and a redacting debug logger that masks CSRs,
  private keys and credentials in logs
- Add the `Example` provider template
- Add the **Sectigo** provider (legacy reseller API)
- Add the **Actalis** provider (Partner API v2.3.3, over mutual TLS)
