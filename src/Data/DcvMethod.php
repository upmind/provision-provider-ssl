<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

/**
 * Domain control validation (DCV) method constants.
 */
final class DcvMethod
{
    /**
     * Validation file served at /.well-known/pki-validation/.
     */
    public const FILE = 'file';

    /**
     * DNS record validation; the record type is returned in the result.
     */
    public const DNS = 'dns';

    public const METHODS = [
        self::FILE,
        self::DNS,
    ];
}
