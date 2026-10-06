<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Order status, DCV state and issued-certificate metadata.
 *
 * The result of getInfo, which tracks an order through validation and issuance. It carries no DCV
 * instructions (file or DNS record) and no generated key material; those are returned only by the
 * state-changing calls, as a CertificateOrderResult.
 *
 * @property-read string|int $order_id Provider order reference
 * @property-read string|int|null $certificate_id Secondary provider certificate reference
 * @property-read string $status Normalized order status; one of the STATUS_* constants
 * @property-read string|null $provider_status Raw provider status string
 * @property-read string|null $certificate_type Validation level; `dv`, `ov` or `ev`
 * @property-read string|null $common_name Domain the certificate is issued for; null when the provider cannot supply it (e.g. a rejected/cancelled order)
 * @property-read string|null $dcv_method DCV method; one of the DcvMethod constants
 * @property-read string|null $dcv_status Validation state; one of the DCV_STATUS_* constants
 * @property-read string|null $not_before Start of validity, format Y-m-d H:i:s (UTC)
 * @property-read string|null $not_after End of validity, format Y-m-d H:i:s (UTC)
 * @property-read string|null $serial_number Serial number of the issued certificate
 */
class CertificateInfoResult extends ResultData
{
    /**
     * Order placed; awaiting domain control validation.
     */
    public const STATUS_PENDING_VALIDATION = 'pending_validation';

    /**
     * Validation complete (or not required); CA is processing/signing.
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * Certificate issued and available for download.
     */
    public const STATUS_ISSUED = 'issued';

    /**
     * Certificate validity has ended.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Certificate has been cryptographically revoked.
     */
    public const STATUS_REVOKED = 'revoked';

    /**
     * Order cancelled/refunded before issuance or terminated at order level.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Order is in an error state requiring intervention e.g., rejected by the CA.
     */
    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_PENDING_VALIDATION,
        self::STATUS_PROCESSING,
        self::STATUS_ISSUED,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
        self::STATUS_CANCELLED,
        self::STATUS_ERROR,
    ];

    public const CERTIFICATE_TYPE_DV = 'dv';
    public const CERTIFICATE_TYPE_OV = 'ov';
    public const CERTIFICATE_TYPE_EV = 'ev';

    public const CERTIFICATE_TYPES = [
        self::CERTIFICATE_TYPE_DV,
        self::CERTIFICATE_TYPE_OV,
        self::CERTIFICATE_TYPE_EV,
    ];

    public const DCV_STATUS_PENDING = 'pending';
    public const DCV_STATUS_COMPLETED = 'completed';
    public const DCV_STATUS_FAILED = 'failed';

    public const DCV_STATUSES = [
        self::DCV_STATUS_PENDING,
        self::DCV_STATUS_COMPLETED,
        self::DCV_STATUS_FAILED,
    ];

    public static function rules(): Rules
    {
        return new Rules([
            'order_id' => ['required'],
            'certificate_id' => ['nullable'],
            'status' => ['required', 'in:' . implode(',', self::STATUSES)],
            'provider_status' => ['nullable', 'string'],
            'certificate_type' => ['nullable', 'in:' . implode(',', self::CERTIFICATE_TYPES)],
            'common_name' => ['nullable', 'string'],
            'dcv_method' => ['nullable', 'in:' . implode(',', DcvMethod::METHODS)],
            'dcv_status' => ['nullable', 'in:' . implode(',', self::DCV_STATUSES)],
            'not_before' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'not_after' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'serial_number' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param string|int $orderId Provider order reference
     */
    public function setOrderId($orderId): self
    {
        $this->setValue('order_id', $orderId);
        return $this;
    }

    /**
     * @param string|int|null $certificateId Secondary provider certificate reference
     */
    public function setCertificateId($certificateId): self
    {
        $this->setValue('certificate_id', $certificateId);
        return $this;
    }

    /**
     * @param string $status One of the STATUS_* constants
     */
    public function setStatus(string $status): self
    {
        $this->setValue('status', $status);
        return $this;
    }

    /**
     * @param string|null $providerStatus Raw provider status string
     */
    public function setProviderStatus(?string $providerStatus): self
    {
        $this->setValue('provider_status', $providerStatus);
        return $this;
    }

    /**
     * @param string|null $certificateType One of the CERTIFICATE_TYPE_* constants
     */
    public function setCertificateType(?string $certificateType): self
    {
        $this->setValue('certificate_type', $certificateType);
        return $this;
    }

    /**
     * @param string|null $commonName Domain of the certificate; null when unavailable
     */
    public function setCommonName(?string $commonName): self
    {
        $this->setValue('common_name', $commonName);
        return $this;
    }

    /**
     * @param string|null $dcvMethod One of the DcvMethod constants
     */
    public function setDcvMethod(?string $dcvMethod): self
    {
        $this->setValue('dcv_method', $dcvMethod);
        return $this;
    }

    /**
     * @param string|null $dcvStatus One of the DCV_STATUS_* constants
     */
    public function setDcvStatus(?string $dcvStatus): self
    {
        $this->setValue('dcv_status', $dcvStatus);
        return $this;
    }

    /**
     * @param string|null $notBefore Validity start, format Y-m-d H:i:s (UTC)
     */
    public function setNotBefore(?string $notBefore): self
    {
        $this->setValue('not_before', $notBefore);
        return $this;
    }

    /**
     * @param string|null $notAfter Validity end, format Y-m-d H:i:s (UTC)
     */
    public function setNotAfter(?string $notAfter): self
    {
        $this->setValue('not_after', $notAfter);
        return $this;
    }

    /**
     * @param string|null $serialNumber Serial number of the issued certificate
     */
    public function setSerialNumber(?string $serialNumber): self
    {
        $this->setValue('serial_number', $serialNumber);
        return $this;
    }
}
