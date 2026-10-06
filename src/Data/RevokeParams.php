<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for revoking an issued certificate.
 *
 * @property-read string|int $order_id Provider order reference
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 * @property-read string|null $reason RFC 5280 revocation reason name
 */
class RevokeParams extends DataSet
{
    /**
     * Revocation reason names callers may pass.
     *
     * RFC 5280 CRLReason names, minus the CA-only reasons and `unspecified` — CA/B Forum SC-063
     * forbids a revocation request from specifying `unspecified`. Each provider maps these onto
     * its own wire representation. An omitted reason defaults per provider.
     */
    public const REASONS = [
        'keyCompromise',
        'affiliationChanged',
        'superseded',
        'cessationOfOperation',
    ];

    public static function rules(): Rules
    {
        return new Rules([
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
            'reason' => ['nullable', 'string', 'in:' . implode(',', self::REASONS)],
        ]);
    }
}
