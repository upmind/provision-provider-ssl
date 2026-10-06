# Upmind Provision Providers - SSL Certificates

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-ssl.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-ssl)

This provision category contains the common functions used in provisioning flows for SSL/TLS certificates on various certificate authority and reseller platforms.

- [Installation](#installation)
- [Usage](#usage)
- [Functions](#functions)
- [Supported Providers](#supported-providers)
- [Design](#design)
- [Development](#development)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-ssl
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarise yourself with by reading the usage section in the README.

## Functions

| Function | Parameters | Return Data | Description |
|---|---|---|---|
| create() | [_CreateParams_](src/Data/CreateParams.php) | [_CertificateOrderResult_](src/Data/CertificateOrderResult.php) | Place a new certificate order/enrollment |
| getInfo() | [_CertificateIdentifierParams_](src/Data/CertificateIdentifierParams.php) | [_CertificateInfoResult_](src/Data/CertificateInfoResult.php) | Get the current status, metadata and DCV state of an order |
| downloadCertificate() | [_CertificateIdentifierParams_](src/Data/CertificateIdentifierParams.php) | [_CertificateDownloadResult_](src/Data/CertificateDownloadResult.php) | Download the issued certificate + CA chain |
| restartValidation() | [_RestartValidationParams_](src/Data/RestartValidationParams.php) | [_CertificateOrderResult_](src/Data/CertificateOrderResult.php) | Restart DCV, optionally switching method |
| reissue() | [_ReissueParams_](src/Data/ReissueParams.php) | [_CertificateOrderResult_](src/Data/CertificateOrderResult.php) | Reissue (re-key) the certificate with a new CSR |
| renew() | [_RenewParams_](src/Data/RenewParams.php) | [_CertificateOrderResult_](src/Data/CertificateOrderResult.php) | Renew the certificate order |
| revoke() | [_RevokeParams_](src/Data/RevokeParams.php) | [_EmptyResult_](src/Data/EmptyResult.php) | Cryptographically revoke an issued certificate |
| cancel() | [_CancelParams_](src/Data/CancelParams.php) | [_EmptyResult_](src/Data/EmptyResult.php) | Cancel the order at the certificate authority |

## Supported Providers

The following providers are implemented. Each README lists its configuration and product codes.

- [Sectigo](src/Sectigo/README.md) — Sectigo reseller API
- [Actalis](src/Actalis/README.md) — Actalis Partner API

## Design

See [WORKFLOW.md](WORKFLOW.md#category-contract) for the category contract: scope, the normalized status model, the DCV abstraction and the provider rules.

## Development

A docker environment is provided for local development against any supported PHP version:

```bash
make setup              # PHP 8.3; or setup-php74 / setup-php81 / setup-php82
make shell              # interactive shell on the container
make static-analysis    # PHPStan
make coding-standards   # PHP-CS-Fixer
make test               # PHPUnit
make help               # list all commands
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome and credit will be given. Please see [CONTRIBUTING](CONTRIBUTING.md), and
[WORKFLOW](WORKFLOW.md) for the guide to implementing a new provider.

## Credits

- [Harry Lewis](https://github.com/uphlewis)
- [All Contributors](../../contributors)

## License

GPL-3.0-only. Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start).
