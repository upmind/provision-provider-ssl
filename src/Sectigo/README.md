# Sectigo

Orders certificates through the Sectigo reseller API (legacy "trust-provider" API).

Sectigo's other APIs are out of scope: SCM REST is enterprise-only, and CaaS is a
domain-subscription and ACME model rather than per-certificate resale.

## Configuration

| Field | Required | Description |
|---|---|---|
| `login_name` | Yes | Reseller account login name |
| `login_password` | Yes | Reseller account login password |
| `test_mode` | No | Place test orders: not billed, not issued |
| `dcv_hash_domain` | No | Domain suffix in file/CNAME DCV values; defaults to `comodoca.com` |
| `notify_customer` | No | Set `false` to stop Sectigo emailing the issued certificate to the customer; defaults to `true` |

## Product codes

`product_code` is the numeric Sectigo product id. The API cannot list products, so take the ids
from your reseller account's price list. These ids are stable across reseller accounts:

| Code | Product |
|---|---|
| `287` | PositiveSSL (DV) |
| `289` | PositiveSSL Wildcard (DV) |

An invalid id fails on the first `create()` with Sectigo's error. Enable `test_mode` so trial
orders are not billed.

## Terms

The API accepts these `days` values: 30, 90 and 365, and 730, 1095, 1461 and 1826 for two- to
five-year bundles. The provider maps `validity_days` to the value within 10% of it. For example,
360 maps to 365 and 1800 to 1826. A term more than 10% from every value fails with an error. It
never rounds up to a longer bundle, so a misconfigured term such as 400 days cannot place a
costlier two-year order. Each account and product accepts a subset of these values. Sectigo
rejects a term the product does not offer.

## Domain control validation

The file and CNAME values derive from the CSR and a `uniqueValue` that the provider generates for
each order, reissue and validation restart. Sectigo rejects a CSR and `uniqueValue` pair it has
already seen, so a CSR can be reused on any number of orders and reissues. Each request gets new
DCV values, even with the same key. The record name and file path stay the same; the CNAME value
and file content change.

No API returns an order's `uniqueValue`, so `restartValidation` cannot rebuild the current
instructions:

- Without `csr`, it calls `AutoUpdateDCV` with the current method. The `uniqueValue` and the
  existing instructions stay the same. A method switch without `csr` fails.
- With `csr`, it replaces the certificate request with a new `uniqueValue` and returns new
  instructions. The customer must replace any record or file they placed before.
