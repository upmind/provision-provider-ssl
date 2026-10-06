<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Example\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example provider credentials.
 *
 * @property-read string $api_url API base URL
 * @property-read string $username API username/partner code
 * @property-read string $password API password/token
 * @property-read bool|null $sandbox Whether to use the sandbox/test environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_url' => ['required', 'url'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
