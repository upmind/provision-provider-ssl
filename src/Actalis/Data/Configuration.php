<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Actalis\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Actalis Partner API credentials. The API authenticates by mutual TLS; README.md shows how to
 * split the PKCS#12 client certificate into PEM parts.
 *
 * @property-read string $client_certificate PEM-encoded client certificate issued by Actalis
 * @property-read string $client_private_key PEM-encoded private key belonging to the client certificate
 * @property-read string|null $client_private_key_passphrase Passphrase protecting the private key, if it is encrypted
 * @property-read string $partner_name Partner short name, as agreed with Actalis
 * @property-read bool|null $sandbox Make API requests against the Actalis test environment
 * @property-read bool|null $notify_customer Whether Actalis emails the issued certificate to the customer; defaults to true
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'client_certificate' => ['required', 'string', 'certificate_pem'],
            // not certificate_pem: that rule rejects encrypted keys, and the key is instead
            // validated (structure + passphrase) by ActalisApi before any request is attempted
            'client_private_key' => ['required', 'string'],
            'client_private_key_passphrase' => ['nullable', 'string'],
            'partner_name' => ['required', 'string', 'max:50'],
            'sandbox' => ['nullable', 'boolean'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);
    }
}
