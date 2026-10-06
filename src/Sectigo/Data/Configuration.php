<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Sectigo\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Sectigo reseller (trust-provider) API credentials.
 *
 * @property-read string $login_name Reseller account login name
 * @property-read string $login_password Reseller account login password
 * @property-read string|null $dcv_hash_domain Domain suffix in file/CNAME DCV values; defaults to comodoca.com
 * @property-read bool|null $test_mode Whether to place test orders (not billed, not issued)
 * @property-read bool|null $notify_customer Whether Sectigo emails the issued certificate to the customer; defaults to true
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'login_name' => ['required', 'string'],
            'login_password' => ['required', 'string'],
            'dcv_hash_domain' => ['nullable', 'string'],
            'test_mode' => ['nullable', 'boolean'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);
    }
}
