<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for restarting domain control validation, optionally changing the method.
 *
 * A restart may rotate the confirmation value, so it returns fresh DCV instructions. To re-check
 * existing proof without disturbing it, use getInfo.
 *
 * @property-read string|int $order_id Provider order reference
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 * @property-read string|null $dcv_method New DCV method; omit to keep the current one
 * @property-read string|null $csr The order's current PEM-encoded CSR; providers that derive DCV values from the CSR need it to return instructions
 */
class RestartValidationParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
            'dcv_method' => ['nullable', 'in:' . implode(',', DcvMethod::METHODS)],
            'csr' => ['nullable', 'string'],
        ]);
    }
}
