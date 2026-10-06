<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for cancelling a certificate order.
 *
 * @property-read string|int $order_id Provider order reference
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 */
class CancelParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
        ]);
    }
}
