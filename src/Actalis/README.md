# Actalis

Orders certificates directly through the Actalis Partner API v2.3.3.

## Configuration

| Field | Required | Description |
|---|---|---|
| `client_certificate` | Yes | PEM client certificate issued by Actalis |
| `client_private_key` | Yes | PEM private key of the client certificate |
| `client_private_key_passphrase` | No | Passphrase, if the private key is encrypted |
| `partner_name` | Yes | Short partner name agreed with Actalis |
| `sandbox` | No | Use the Actalis test environment |
| `notify_customer` | No | Set `false` to stop Actalis emailing the issued certificate to the customer; defaults to `true` |

The API authenticates by mutual TLS, not by username and password. Actalis issues the client
certificate as a PKCS#12 file. Split it into PEM parts:

```bash
openssl pkcs12 -in partner.p12 -clcerts -nokeys -out client.crt   # client_certificate
openssl pkcs12 -in partner.p12 -nocerts -nodes  -out client.key   # client_private_key
```

The provider accepts PEM values whose line breaks were lost or collapsed to spaces, as some
configuration forms do, and rebuilds them. A legacy encrypted key (`Proc-Type: 4,ENCRYPTED`) needs
its original line breaks; otherwise convert it to PKCS#8 with `openssl pkcs8 -topk8`. Before any
request, the provider checks that the certificate and key parse and that they match.

## Product codes

Actalis selects a product by certificate class and policy, so `product_code` is the pair:
`DV:SingleHost`, `DV:Wildcard`, `OV:SingleHost` or `OV:Wildcard`. Either half may be omitted. A
missing class comes from `certificate_type`; a missing policy comes from whether the common name
is a wildcard.

## Terms

Actalis sells SSL Server certificates on `3m` and `1y` durations only. The provider maps
`validity_days` to the duration within 10% of it: 81–99 days to `3m` and 329–401 days to `1y`.
Any other term, such as monthly, semi-annual or multi-year, fails with an error.

## Check validation

Actalis has no call to check DCV now. It checks a pending file or DNS record on its own schedule.
`changedcv` restarts DCV, but it rejects the request's existing confirmation value with error
17013, so it always changes the value. So `checkValidation` returns the current status without a
change.

## Cancel

`cancel` always sends the cancel to Actalis. If Actalis refuses it because the request is in a
final status (350) or no longer exists (360), `cancel` checks the request and succeeds when there is
nothing left to cancel: the request is rejected or cancelled, or its certificate is issued, expired
or revoked. If Actalis no longer holds the request, it checks the certificate from `certificate_id`
instead. Any other refusal or error fails.
