<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for placing a new certificate order.
 *
 * @property-read string $product_code Provider product/SKU code
 * @property-read string $certificate_type Validation level; `dv` or `ov`
 * @property-read int $validity_days Purchase term in days
 * @property-read string|null $csr PEM-encoded CSR; if omitted the provider generates a key pair + CSR
 * @property-read string $common_name Domain the certificate is issued for; may be a wildcard
 * @property-read string|null $dcv_method DCV method; one of the DcvMethod constants
 * @property-read string $customer_email Applicant/notification email; seeded from the billing client profile
 * @property-read string $customer_name Applicant full name; seeded from the billing client profile
 * @property-read string|null $customer_phone Applicant phone in international format; sent to the CA for OV only
 * @property-read OrganizationParams|null $organization Organization identity; required for OV
 * @property-read string|null $reference External order reference, where supported
 */
class CreateParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'product_code' => ['required', 'string'],
            'certificate_type' => [
                'required',
                'in:' . CertificateInfoResult::CERTIFICATE_TYPE_DV . ',' . CertificateInfoResult::CERTIFICATE_TYPE_OV,
            ],
            'validity_days' => ['required', 'integer', 'min:1'],
            'csr' => ['nullable', 'string'],
            'common_name' => ['required', 'string'],
            'dcv_method' => ['nullable', 'in:' . implode(',', DcvMethod::METHODS)],
            'customer_email' => ['required', 'email'],
            'customer_name' => ['required', 'string'],
            'customer_phone' => ['nullable', 'string', 'international_phone'],
            'organization' => [
                'required_if:certificate_type,' . CertificateInfoResult::CERTIFICATE_TYPE_OV,
                OrganizationParams::class,
            ],
            'reference' => ['nullable', 'string'],
        ]);
    }
}
