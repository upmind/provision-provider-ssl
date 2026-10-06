<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * An issued certificate and its CA chain, plus the order status and metadata.
 *
 * @property-read string $certificate PEM-encoded leaf/end-entity certificate
 * @property-read string|null $ca_bundle PEM-encoded CA chain (intermediates, concatenated)
 */
class CertificateDownloadResult extends CertificateInfoResult
{
    public static function rules(): Rules
    {
        return new Rules(array_merge(parent::rules()->raw(), [
            'certificate' => ['required', 'certificate_pem'],
            'ca_bundle' => ['nullable', 'string'],
        ]));
    }

    /**
     * @param string $certificate PEM-encoded leaf certificate
     */
    public function setCertificate(string $certificate): self
    {
        $this->setValue('certificate', $certificate);
        return $this;
    }

    /**
     * @param string|null $caBundle PEM-encoded CA chain
     */
    public function setCaBundle(?string $caBundle): self
    {
        $this->setValue('ca_bundle', $caBundle);
        return $this;
    }
}
