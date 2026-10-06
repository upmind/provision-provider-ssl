# Workflow

This document outlines the development guidelines and basic workflow for implementing
new SSL certificate provision providers.

**Ensure you also read the guidelines on [CONTRIBUTING](CONTRIBUTING.md).**

## Resources

Links/resources to familiarize yourself with before you begin.

- [Upmind Provision Provider Base](https://github.com/upmind-automation/provision-provider-base#readme)
  - This is the base library all provisioning code extends from. The project README explains the structure of Upmind provision providers; classes, responsibilities etc
- [Upmind Provision Workbench](https://github.com/upmind-automation/provision-workbench#readme)
  - A local development tool which provides a convenient UI for creating and managing provision configurations, running and inspecting provision functions etc

## Steps

Follow the below steps to create a new provider, using Sectigo as an example:

1. Install the [Upmind Provision Workbench](https://github.com/upmind-automation/provision-workbench#readme)
2. Fork [this repository](https://github.com/upmind/provision-provider-ssl)
3. Clone your fork into the `local/` directory where you have installed the provision workbench and run `composer require upmind/provision-provider-ssl:@dev` - it will install from your fork in local/
4. In your fork of upmind/provision-provider-ssl copy the `src/Example` directory to create `src/Sectigo` and update the namespace on files under `src/Sectigo`
5. Update the sample Configuration class for Sectigo API credentials (e.g. login_name, login_password, sandbox)
6. Bind your new provider to the provision registry in `src/LaravelServiceProvider.php`
7. In the provision workbench terminal re-cache your local provision registry by running `php artisan upmind:provision:cache`
8. In the provision workbench UI (typically http://127.0.0.1:9000) create a Sectigo provision configuration
9. Now you can run provision functions (also known as provision requests) via the workbench UI as you develop them
10. When complete, submit your fork as a pull request (PR) back to the `main` branch on [this repository](https://github.com/upmind/provision-provider-ssl)

## Local development environment

A docker environment is provided so you can develop and run the tooling against any supported PHP
version without installing them locally. Run `make help` to list all commands.

```bash
make setup              # PHP 8.3; or setup-php74 / setup-php81 / setup-php82
make shell              # interactive shell on the container
make static-analysis    # PHPStan
make coding-standards   # PHP-CS-Fixer (or ./fix_formatting.sh outside docker)
make test               # PHPUnit
```

`make setup-phpXX` copies the matching `.docker/Dockerfile.phpXX` into place, creates
`docker-compose.yml` from the example if you don't already have one, rebuilds the container and
reinstalls dependencies from scratch. Both `docker-compose.yml` and `.docker/Dockerfile` are
gitignored, so you can edit them locally without them appearing in a PR.

## Category contract

A provider must follow these rules.

### Scope

- Single-domain and wildcard certificates only. Multi-domain (SAN) certificates are out of scope.
- DV and OV only. EV is out of scope.
- Params and results have no array fields. Each blueprint field maps to exactly one param.
  Fixed-shape nested sets (`organization`) are allowed.

### Functions

| Function | Purpose | Called by |
|---|---|---|
| `create` | Place a new order | Admin/blueprint |
| `getInfo` | Get status, DCV state and metadata. The polling target. | Admin/blueprint |
| `downloadCertificate` | Get the issued certificate and CA chain | Client |
| `restartValidation` | Restart DCV, optionally switching method | Client |
| `reissue` | Re-key with a new CSR | Admin/blueprint |
| `renew` | Place a new order that continues the previous one | Admin/blueprint |
| `revoke` | Revoke an issued certificate | Admin/blueprint |
| `cancel` | Close the order at the CA | Admin/blueprint |

- `order_id` is the provider's order handle. `certificate_id` is an optional second reference.
- `renew` and `reissue` may return a new `order_id`. The system stores the value you return.
- `getInfo` has no side effects. It never returns certificate material or DCV instructions.

### Status

Map every order to one of the `CertificateInfoResult::STATUS_*` values:

| Status | Meaning |
|---|---|
| `pending_validation` | Waiting for domain control validation |
| `processing` | DCV is complete; the CA is vetting or signing |
| `issued` | The certificate is issued and valid |
| `expired` | The certificate has passed `not_after` |
| `revoked` | The certificate is revoked |
| `cancelled` | The order is cancelled at the CA |
| `error` | The CA rejected or failed the order |

- A reissue in progress maps to `pending_validation` or `processing`, not `issued`.
- Always set `provider_status` to the raw platform value(s).
- Set `dcv_status` to `pending`, `completed` or `failed`.

### Domain control validation

- Offer `file` and `dns` only. Default to `dns`.
- Return DCV instructions in the flat `dcv_*` fields of `CertificateOrderResult`:
  - `file`: `dcv_file_path` (under `/.well-known/pki-validation/`) and `dcv_file_content`.
  - `dns`: `dcv_dns_record_name`, `dcv_dns_record_type` (`TXT` or `CNAME`) and
    `dcv_dns_record_value`, describing exactly one record.
- Return instructions from the state-changing calls only: `create`, `renew`, `reissue` and
  `restartValidation`.
- Return the `dcv_*` fields as `null` once `dcv_status` is `completed`, so stale instructions
  clear from the client area.
- `restartValidation` takes an optional `dcv_method` and an optional `csr`. A provider that
  derives DCV values from the CSR needs the `csr` to return instructions for a new method.

### Keys and CSRs

- If `csr` is given, check it with `$this->assertCsrMatches()` before you submit it. Compare it
  with `common_name`, or with the order's domain on `reissue` and `restartValidation`. The check
  fails a CSR that is invalid, names a different domain, or carries extra domains.
- Return neither `csr` nor `private_key` when the customer supplied the CSR.
- If `csr` is omitted, generate the key pair and CSR with `Helper\KeyPairHelper`. Return both.

### Products and validity

- `product_code` is the provider-native product code. If the platform has no single code, the
  provider defines a composite (for example, Actalis `DV:SingleHost`).
- `certificate_type` (`dv` or `ov`) is the validation level. `organization` is present only for
  `ov`.
- The customer contact (`customer_email`, `customer_name`, `customer_phone`) is the applicant and,
  for OV, the organization's authorized representative. Where the CA takes first and last name
  separately, split `customer_name` with `Utils::splitName()`.
- `validity_days` is the purchase term. Map it to the nearest term the platform supports.
  Read `not_before` and `not_after` from the issued certificate.

### Errors

- The CA is the source of truth for its own restrictions (product and wildcard fit, DCV method
  for a wildcard, term limits). Do not duplicate them in the provider. Pass the CA's error code,
  message and field detail through in the error, so a brand admin can see why a call failed.
- Exception: check a restriction in the provider if the CA does not return a clear error for it.
  Examples: a bare error code with no message, an error that names the wrong cause, or an
  accepted order that can never complete.
- Check only what the category owns: CSR integrity and scope, and the state needed to build a
  request.
- Every function can be called in any order state. Return a clear error when the function
  cannot act in the current state.

## Requirements

These are the acceptance criteria for new providers.

### General

- Use the `src/Example/` directory as a basic template for the new provider; do **not** copy + paste an existing Provider class because each provider must be implemented differently
- Implement the Configuration DTO class (used to construct the provider) under the same namespace as the new Provider
- Add a short `README.md` in the provider directory: its configuration fields and how to find `product_code` values. Link it from the main README's Supported Providers list
- Implement PSR-3 debug logging of all API requests + responses. Build the HTTP client on `$this->getGuzzleHandlerStack(true)`: it logs through `Helper\RedactingLogger`, which masks private keys, CSRs and credential fields. If the API carries a credential in a field the logger does not know, add the field to `RedactingLogger`
- Implement all provider functions where possible. E.g., where an operation is not supported by the platform it's fine to throw an error like "Operation not supported"
- Throw (or re-throw) normal/expected errors (e.g., data/state/auth issues) as a ProvisionFunctionError using `$this->errorResult()` - any other exceptions will be considered unexpected and wrapped in a generic error with a benign message which will be unhelpful to end users
- Result messages and error messages must be 'safe' for end users/customers to read (not contain potentially sensitive information such as credentials or references to code/classes/files etc) but should still be reasonably helpful. Do **not** expose the name of the reseller/aggregator platform in result/error messages, since these are re-sold as whitelabelled services — the certificate authority/brand is fine where it is part of the product the customer bought
- Additional information or helpful metadata (e.g. raw API response data) should be returned in successful result debug or error data/debug
- Support PHP 7.4; avoid PHP 8-only syntax
- Any changes to `src/Category.php` or in `src/Data/` must be discussed with Upmind prior to the PR
- Any new 3rd-party dependencies/libraries must be approved by Upmind

### Certificate-specific

- **Never log private keys.** The `private_key` returned by `create()`/`reissue()` is destined for encrypted vault storage. `RedactingLogger` masks HTTP logs only, so keep the key out of result debug data, error data and any message you log yourself. CSRs are not secret; the logger masks them only to keep logs short
- **Honour both key custody modes.** When `csr` is given, check it with `$this->assertCsrMatches()`, then submit it unchanged and return neither `csr` nor `private_key`. When it is omitted, generate the key pair + CSR with `Helper\KeyPairHelper` and return both in the result
- **Always map to the normalized status** (`CertificateInfoResult::STATUS_*`) *and* populate `provider_status` with the raw platform value(s), so no fidelity is lost for support
- **Return DCV completion data** in the flat `dcv_*` result fields — the file or the DNS record, per the selected method. Once validation has completed, return them as `null` so stale instructions clear from the client area
- **Fail gracefully out of state.** Client area actions cannot currently be hidden based on order state, so every function will be called at times when it cannot act. Return a clear, human-readable error (e.g. "Certificate not issued yet — domain validation is still pending") rather than letting a raw API failure through
- **Implement `renew` even without a native renewal API.** Where the platform models renewal, use it and pass the prior order reference — it is what lets validation, remaining days and organisation vetting carry over, so the customer redoes less. Where it does not, `renew` simply calls your own `create()` internally
- **Read validity from the certificate, not the order.** `validity_days` is the purchase term; populate `not_before`/`not_after` from what the CA actually issued, since certificate lifetimes are capped well below common purchase terms
- **Let the CA enforce its own restrictions, unless its API does not return a clear error.** Do not pre-check what the CA already rejects clearly (e.g. a wildcard name on a single-host product); surface the CA's error code, message and field detail instead. Where the CA returns no usable message, or accepts an order that can never complete, check the restriction in the provider and return a clear error
- **Enable every DCV variant the platform accepts at once.** Where the API takes independent flags rather than a single method value, set both variants — HTTP *and* HTTPS file, TXT *and* CNAME — so validation passes whichever the customer completes and however their site redirects. Where the API is single-valued (Sectigo, Actalis), pick one
- **Prefer TXT for the `dns` method**, falling back to CNAME only where the platform has no TXT option. Whichever record types are enabled, `dcv_dns_record_type`/`_name`/`_value` must describe exactly one record — the one you want the customer to create
- **Generate provider mechanics yourself.** Anything the platform needs but a customer cannot meaningfully choose — the CSR unique value/salt, the web server type, cert bundle format — is generated or defaulted in the provider, not taken from params
- **Offer only `file` and `dns` DCV, defaulting to `dns`.** Email validation is not part of the contract; do not build approver addresses or expose approver params
