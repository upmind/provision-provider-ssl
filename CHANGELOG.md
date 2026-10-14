# Changelog

All notable changes to the package will be documented in this file.

## Unreleased

- Sectigo: default `dcv_hash_domain` to `sectigo.com`, the suffix Sectigo's current DCV
  documentation uses. Sectigo also accepts `comodoca.com`, so records placed for earlier orders
  still validate

## [1.0.3](https://github.com/upmind/provision-provider-ssl/releases/tag/v1.0.3) - 2026-10-14

- Actalis: `reissue`, `renew` and `revoke` act on the certificate the order holds now, not on a
  stored `certificate_id` that a reissue has superseded
- Actalis: `reissue` returns `not_before`, `not_after` and `serial_number` as `null` until the new
  certificate issues, instead of the superseded certificate's values
- Actalis: when Actalis accepts the existing domain validation and returns no confirmation value,
  report `dcv_status` `completed` and status `processing` instead of `pending_validation`
- Actalis: error 510 now says another request for the certificate or CSR is still in progress. It
  no longer says to generate a new key pair
- Sectigo: `cancel` still requests a refund first. When Sectigo refuses it, `cancel` succeeds if
  the order has nothing left to cancel: it is cancelled, rejected or revoked, or the certificate
  expired or was issued more than 30 days ago. Any other refusal or error still fails
- Actalis: `cancel` still sends the cancel first. When Actalis refuses it, `cancel` succeeds if the
  request has nothing left to cancel: it is rejected or cancelled, or its certificate is issued,
  expired or revoked. Any other refusal or error still fails

## [1.0.2](https://github.com/upmind/provision-provider-ssl/releases/tag/v1.0.2) - 2026-10-14

- Sectigo: generate a new DCV `uniqueValue` for each `create`, `renew`, `reissue` and
  `restartValidation` request, so a reused CSR no longer fails with
  `-55 This Request Token is not unique!`. The CSR, key, CNAME name and file path stay the same.
  The CNAME value and file content change with each request

## [1.0.1](https://github.com/upmind/provision-provider-ssl/releases/tag/v1.0.1) - 2026-10-14

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
