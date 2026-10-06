<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for renewing a certificate order; all create params plus the prior order.
 *
 * @property-read string|int $order_id Provider order reference of the order being renewed
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 */
class RenewParams extends CreateParams
{
    public static function rules(): Rules
    {
        return new Rules(array_merge(parent::rules()->raw(), [
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
        ]));
    }
}
