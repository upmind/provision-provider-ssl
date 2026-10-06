<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for reissuing (re-keying) a certificate.
 *
 * Reissue keeps the existing product and organization, so it takes only the new key material and
 * the applicant email a re-key request needs.
 *
 * @property-read string|int $order_id Provider order reference
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 * @property-read string|null $csr New PEM-encoded CSR; if omitted the provider generates one
 * @property-read string|null $dcv_method DCV method, if revalidation is required
 * @property-read string $customer_email Applicant/notification email; seeded from the billing client profile
 */
class ReissueParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
            'csr' => ['nullable', 'string'],
            'dcv_method' => ['nullable', 'in:' . implode(',', DcvMethod::METHODS)],
            'customer_email' => ['required', 'email'],
        ]);
    }
}
