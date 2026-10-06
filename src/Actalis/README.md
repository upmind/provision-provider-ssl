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

## Product codes

Actalis selects a product by certificate class and policy, so `product_code` is the pair:
`DV:SingleHost`, `DV:Wildcard`, `OV:SingleHost` or `OV:Wildcard`. Either half may be omitted. A
missing class comes from `certificate_type`; a missing policy comes from whether the common name
is a wildcard.
