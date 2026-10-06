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
