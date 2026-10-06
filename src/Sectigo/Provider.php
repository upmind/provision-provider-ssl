<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Sectigo;

use GuzzleHttp\Client;
use phpseclib3\File\X509;
use Throwable;
use Upmind\ProvisionBase\Provider\Contract\LogsDebugData;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SslCertificates\Category;
use Upmind\ProvisionProviders\SslCertificates\Data\CancelParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateDownloadResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateIdentifierParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateOrderResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CreateParams;
use Upmind\ProvisionProviders\SslCertificates\Data\DcvMethod;
use Upmind\ProvisionProviders\SslCertificates\Data\EmptyResult;
use Upmind\ProvisionProviders\SslCertificates\Data\OrganizationParams;
use Upmind\ProvisionProviders\SslCertificates\Data\ReissueParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RenewParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RestartValidationParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RevokeParams;
use Upmind\ProvisionProviders\SslCertificates\Helper\KeyPairHelper;
use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoApi;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoDcv;

/**
 * Sectigo reseller platform provider (legacy trust-provider API).
 */
class Provider extends Category implements ProviderInterface, LogsDebugData
{
    protected Configuration $configuration;

    /**
     * @var SectigoApi|null
     */
    protected ?SectigoApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Sectigo')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/sectigo-logo.png')
            ->setDescription('Order and manage Sectigo SSL/TLS certificates via the reseller API');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function create(CreateParams $params): CertificateOrderResult
    {
        $csr = $params->csr;
        $privateKey = null;

        if ($csr) {
            $this->assertCsrMatches($csr, $params->common_name);
        } else {
            $key = KeyPairHelper::generatePrivateKey();
            $privateKey = KeyPairHelper::privateKeyToPem($key);
            $csr = KeyPairHelper::generateCsr($key, $params->common_name, $params->organization);
        }

        $dcvMethod = $params->dcv_method ?: DcvMethod::DNS;
        $uniqueValue = $this->uniqueValueForCsr($csr);

        [$firstName, $lastName] = Utils::splitName($params->customer_name);

        $request = array_merge(array_filter([
            'product' => $params->product_code,
            'days' => $params->validity_days,
            'csr' => $csr,
            'serverSoftware' => -1,
            'isCustomerValidated' => 'Y',
            'dcvMethod' => $this->toSectigoDcvMethod($dcvMethod),
            'uniqueValue' => $uniqueValue,
            'showCertificateID' => 'Y',
            'foreignOrderNumber' => $params->reference,
            'appRepEmailAddress' => $params->customer_email,
            'appRepForename' => $firstName,
            'appRepSurname' => $lastName,
            // only OV vetting calls the customer back
            'appRepTelephone' => $params->organization ? $params->customer_phone : null,
            // 'none' stops Sectigo emailing the issued certificate
            'emailAddress' => $this->configuration->notify_customer === false ? 'none' : null,
        ], function ($value) {
            return $value !== null;
        }), $this->organizationRequestData($params->organization));

        $data = $this->api()->applyOrder($request);

        $orderNumber = $data['orderNumber'] ?? null;
        if (!$orderNumber) {
            $this->errorResult('Provider did not return an order reference', [], ['response_data' => $data]);
        }

        $uniqueValue = (string) ((($data['uniqueValue'] ?? null) ?: $uniqueValue));

        $result = CertificateOrderResult::create(array_merge([
            'order_id' => $orderNumber,
            'certificate_id' => ($data['certificateID'] ?? null) ?: null,
            'status' => CertificateInfoResult::STATUS_PENDING_VALIDATION,
            'provider_status' => (int) $data['errorCode'] === 1 ? 'Applied - awaiting payment' : 'Applied',
            'product_code' => $params->product_code,
            'certificate_type' => $params->certificate_type,
            'common_name' => $params->common_name,
            'dcv_method' => $dcvMethod,
            'dcv_status' => CertificateInfoResult::DCV_STATUS_PENDING,
        ], $this->dcvInstructionData($dcvMethod, $csr, $params->common_name, $uniqueValue)))
            ->setMessage('Certificate order placed');

        if ($privateKey !== null) {
            $result->setCsr($csr)->setPrivateKey($privateKey);
        }

        return $result;
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(CertificateIdentifierParams $params): CertificateInfoResult
    {
        $info = $this->fetchInfoData($params->order_id, $params->certificate_id);

        return CertificateInfoResult::create($info)
            ->setMessage('Certificate order info retrieved');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function downloadCertificate(CertificateIdentifierParams $params): CertificateDownloadResult
    {
        // Reuse the status path for order metadata (status, common name, dates);
        // it back-fills the common name the collect response can omit.
        $info = $this->fetchInfoData($params->order_id, $params->certificate_id);

        // queryType 1 = status + certificate + intermediates/roots. responseType 3
        // returns them individually encoded: the leaf in `certificate` and each CA
        // in a repeated `caCertificate` field, base64-encoded.
        $data = $this->api()->collectSsl($params->order_id, 1, [
            'responseType' => 3,
        ], [-20, -21, -26]);

        if (empty($data['certificate'])) {
            $this->errorNotIssued($info['status']);
        }

        $leaf = $this->normalizeCertificate((string) $data['certificate']);

        $caValues = $data['caCertificate'] ?? [];
        $caValues = is_array($caValues) ? $caValues : [$caValues];
        $caCertificates = [];
        foreach ($caValues as $value) {
            foreach ($this->splitCertificates($this->normalizeCertificate((string) $value)) as $pem) {
                $caCertificates[] = $pem;
            }
        }

        $caBundle = $this->orderCaChain($leaf, $caCertificates);

        return CertificateDownloadResult::create(array_merge($info, [
            'certificate' => $leaf,
            'ca_bundle' => $caBundle !== '' ? $caBundle : null,
        ]))->setMessage('Certificate downloaded');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function restartValidation(RestartValidationParams $params): CertificateOrderResult
    {
        $info = $this->fetchInfoData($params->order_id, $params->certificate_id);

        if ($info['dcv_status'] === CertificateInfoResult::DCV_STATUS_COMPLETED) {
            $this->errorResult('Domain control validation is already complete');
        }

        if ($info['status'] === CertificateInfoResult::STATUS_ISSUED) {
            $this->errorResult('Certificate is already issued');
        }

        $currentMethod = $info['dcv_method'] ?? null;
        $targetMethod = $params->dcv_method ?: $currentMethod;

        if (!$targetMethod) {
            $this->errorResult('Validation state is unavailable — specify a validation method to switch to');
        }

        // DCV values derive from the CSR, so instructions for a new method need it.
        // Check before switching, so a failed request leaves the order unchanged.
        if (!$params->csr && $targetMethod !== $currentMethod) {
            $this->errorResult('Provide the order\'s CSR to get the validation instructions for the new method');
        }

        if ($params->csr && $targetMethod === DcvMethod::DNS && empty($info['common_name'])) {
            $this->errorResult('Unable to determine the certificate common name to build the DNS record');
        }

        if ($params->csr) {
            $this->assertCsrMatches($params->csr, ($info['common_name'] ?? null) ?: null);
        }

        $this->api()->updateDcv($params->order_id, $this->toSectigoDcvMethod($targetMethod));

        $info['dcv_method'] = $targetMethod;
        $info['dcv_status'] = CertificateInfoResult::DCV_STATUS_PENDING;

        if (!$params->csr) {
            return CertificateOrderResult::create($info)
                ->setMessage('Domain validation re-triggered — the existing validation instructions remain valid');
        }

        return CertificateOrderResult::create(array_merge($info, $this->dcvInstructionData(
            $targetMethod,
            $params->csr,
            (string) $info['common_name'],
            $this->uniqueValueForCsr($params->csr)
        )))->setMessage('Domain validation restarted');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reissue(ReissueParams $params): CertificateOrderResult
    {
        $info = $this->fetchInfoData($params->order_id, $params->certificate_id);

        $csr = $params->csr;
        $privateKey = null;

        if ($csr) {
            $this->assertCsrMatches($csr, ($info['common_name'] ?? null) ?: null);
        } else {
            if (empty($info['common_name'])) {
                $this->errorResult(
                    'Unable to determine the certificate common name for this order'
                        . ' — provide a csr to reissue it'
                );
            }

            $key = KeyPairHelper::generatePrivateKey();
            $privateKey = KeyPairHelper::privateKeyToPem($key);
            $csr = KeyPairHelper::generateCsr($key, (string) $info['common_name']);
        }

        // Reissue re-validates the new CSR, so a DCV method is required. Prefer the
        // caller's choice, then the order's existing method; fail explicitly rather
        // than guessing a default when neither can be determined.
        $dcvMethod = $params->dcv_method
            ?: ($info['dcv_method'] ?? $this->getMdcDcvDetail($params->order_id)['method']);

        if (!$dcvMethod) {
            $this->errorResult(
                'Unable to determine the domain validation method for this order'
                    . ' — specify dcv_method to reissue it'
            );
        }

        $uniqueValue = $this->uniqueValueForCsr($csr);

        $data = $this->api()->replaceSsl(array_filter([
            'orderNumber' => $params->order_id,
            'csr' => $csr,
            'serverSoftware' => -1,
            'isCustomerValidated' => 'Y',
            'dcvMethod' => $this->toSectigoDcvMethod($dcvMethod),
            'uniqueValue' => $uniqueValue,
            'showCertificateID' => 'Y',
        ], function ($value) {
            return $value !== null;
        }));

        $certificateId = ($data['certificateID'] ?? null) ?: ($info['certificate_id'] ?? null);
        $uniqueValue = (string) ((($data['uniqueValue'] ?? null) ?: $uniqueValue));

        // Re-read the live order state rather than assuming a pending one: when the
        // same CSR is resubmitted Sectigo reuses the existing validation, so the
        // order can go straight back to processing/issued. The reissued certificate
        // is not signed yet, so its validity window is left for getInfo to report
        // once it issues, instead of echoing the superseded certificate's dates.
        $info = $this->fetchInfoData($params->order_id, $certificateId);
        $info['dcv_method'] = $dcvMethod;
        $info['not_before'] = null;
        $info['not_after'] = null;

        // Only surface validation instructions while DCV is genuinely outstanding.
        if (($info['dcv_status'] ?? null) === CertificateInfoResult::DCV_STATUS_PENDING) {
            $info = array_merge(
                $info,
                $this->dcvInstructionData($dcvMethod, $csr, (string) $info['common_name'], $uniqueValue)
            );
        }

        $result = CertificateOrderResult::create($info)
            ->setMessage('Certificate reissue requested');

        if ($privateKey !== null) {
            $result->setCsr($csr)->setPrivateKey($privateKey);
        }

        return $result;
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): CertificateOrderResult
    {
        // no dedicated renewal operation on this platform; renewal is a fresh order
        return $this->create($params)
            ->setMessage('Certificate renewal order placed');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function revoke(RevokeParams $params): EmptyResult
    {
        $data = $this->api()->revokeSsl(array_filter([
            'orderNumber' => $params->order_id,
            'certificateID' => $params->certificate_id,
            'revocationReason' => $params->reason,
        ], function ($value) {
            return $value !== null;
        }), [-21]);

        if ((int) $data['errorCode'] === -21) {
            return $this->emptyResult('Certificate already revoked');
        }

        return $this->emptyResult('Certificate revoked');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function cancel(CancelParams $params): EmptyResult
    {
        // 11 = "other" refund reason code
        $data = $this->api()->refund($params->order_id, 11, [-20]);

        if ((int) $data['errorCode'] === -20) {
            return $this->emptyResult('Certificate order already cancelled');
        }

        return $this->emptyResult('Certificate order cancelled and refund requested');
    }

    /**
     * Fetch and map order info, enriching DCV detail where the order is still in flight.
     *
     * @return mixed[]
     */
    protected function fetchInfoData($orderId, $certificateId = null): array
    {
        $data = $this->api()->collectSsl($orderId, 0, $this->statusFlags(), [-20, -21, -26]);
        $info = $this->collectInfoData($orderId, $data, $certificateId);

        $inFlight = in_array($info['status'], [
            CertificateInfoResult::STATUS_PENDING_VALIDATION,
            CertificateInfoResult::STATUS_PROCESSING,
        ], true);

        // The status query does not always return the FQDN (some issued orders
        // come back with an empty fqdn), so fall back to GetMDCDomainDetails for
        // the common name whenever it is missing. Record the DCV method whenever
        // it is available (reissue needs it); only trust the live DCV status
        // while the order is still in flight, so a completed order keeps its
        // COMPLETED status from the status query.
        if ($inFlight || empty($info['common_name'])) {
            $dcvDetail = $this->getMdcDcvDetail($orderId);

            if ($dcvDetail['method'] !== null) {
                $info['dcv_method'] = $dcvDetail['method'];
            }
            if ($inFlight && $dcvDetail['status'] !== null) {
                $info['dcv_status'] = $dcvDetail['status'];
            }
            if (empty($info['common_name']) && $dcvDetail['domain'] !== null) {
                $info['common_name'] = $dcvDetail['domain'];
            }
        }

        return $info;
    }

    /**
     * Map a CollectSSL response to CertificateInfoResult data.
     *
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function collectInfoData($orderId, array $data, $certificateId = null): array
    {
        $errorCode = (int) $data['errorCode'];

        $dcvFlag = isset($data['dcvStatus']) && is_numeric($data['dcvStatus']) ? (int) $data['dcvStatus'] : null;
        $dcvStatus = null;
        if ($dcvFlag !== null && $dcvFlag >= 0) {
            $dcvStatus = $dcvFlag === 1
                ? CertificateInfoResult::DCV_STATUS_COMPLETED
                : CertificateInfoResult::DCV_STATUS_PENDING;
        }

        switch (true) {
            case $errorCode === -20:
                $status = CertificateInfoResult::STATUS_CANCELLED;
                break;
            case $errorCode === -21:
                $status = CertificateInfoResult::STATUS_REVOKED;
                break;
            case $errorCode === -26:
                $status = CertificateInfoResult::STATUS_PROCESSING;
                break;
            case $errorCode >= 1:
                $status = CertificateInfoResult::STATUS_ISSUED;
                $dcvStatus = CertificateInfoResult::DCV_STATUS_COMPLETED;
                break;
            default:
                $status = $dcvStatus === CertificateInfoResult::DCV_STATUS_COMPLETED
                    ? CertificateInfoResult::STATUS_PROCESSING
                    : CertificateInfoResult::STATUS_PENDING_VALIDATION;
        }

        $providerStatus = (string) ((($data['certificateStatus'] ?? null) ?: ($data['validationStatus'] ?? '')));

        $info = [
            'order_id' => $orderId,
            // CollectSSL takes no showCertificateID flag, so keep the id the caller holds.
            'certificate_id' => (($data['certificateID'] ?? null) ?: null) ?? $certificateId,
            'status' => $status,
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'common_name' => ($data['fqdn'] ?? '') !== '' ? (string) $data['fqdn'] : null,
            'dcv_status' => $dcvStatus,
            'not_before' => Utils::formatTimestamp($data['notBefore'] ?? null),
            'not_after' => Utils::formatTimestamp($data['notAfter'] ?? null),
        ];

        return $info;
    }

    /**
     * Get the first domain's DCV method/status from GetMDCDomainDetails, tolerating failure.
     *
     * @return array{method: string|null, status: string|null, domain: string|null}
     */
    protected function getMdcDcvDetail($orderId): array
    {
        $detail = ['method' => null, 'status' => null, 'domain' => null];

        try {
            $data = $this->api()->getMdcDomainDetails($orderId);
        } catch (Throwable $e) {
            return $detail;
        }

        foreach ($data as $key => $value) {
            if (preg_match('/^\d+_domainName$/', (string) $key) && $detail['domain'] === null) {
                $detail['domain'] = is_array($value) ? (string) reset($value) : (string) $value;
            }
            if (preg_match('/^\d+_dcvMethod$/', (string) $key) && $detail['method'] === null) {
                $raw = is_array($value) ? (string) reset($value) : (string) $value;
                $detail['method'] = $this->fromSectigoDcvMethod($raw);
            }
            if (preg_match('/^\d+_dcvStatus$/', (string) $key) && $detail['status'] === null) {
                $raw = strtolower(is_array($value) ? (string) reset($value) : (string) $value);
                if ($raw !== '') {
                    $detail['status'] = strpos($raw, 'validated') !== false
                        ? CertificateInfoResult::DCV_STATUS_COMPLETED
                        : CertificateInfoResult::DCV_STATUS_PENDING;
                }
            }
        }

        return $detail;
    }

    /**
     * Build the DCV instruction fields returned to the customer.
     *
     * @return mixed[]
     */
    protected function dcvInstructionData(
        string $dcvMethod,
        string $csr,
        string $commonName,
        ?string $uniqueValue
    ): array {
        $hashDomain = $this->configuration->dcv_hash_domain ?: SectigoDcv::DEFAULT_HASH_DOMAIN;

        if ($dcvMethod === DcvMethod::FILE) {
            $file = SectigoDcv::fileData($csr, $hashDomain, $uniqueValue);

            return [
                'dcv_file_path' => $file['path'],
                'dcv_file_content' => $file['content'],
            ];
        }

        $record = SectigoDcv::cnameRecord($csr, $commonName, $hashDomain, $uniqueValue);

        return [
            'dcv_dns_record_name' => $record['name'],
            'dcv_dns_record_type' => $record['type'],
            'dcv_dns_record_value' => $record['value'],
        ];
    }

    /**
     * @return mixed[] Organization fields for OV orders
     */
    protected function organizationRequestData(?OrganizationParams $organization): array
    {
        if (!$organization) {
            return [];
        }

        return array_filter([
            'organizationName' => $organization->name,
            'streetAddress1' => $organization->address1,
            'localityName' => $organization->city,
            // Sectigo requires a state; many countries have none, so the city stands in
            'stateOrProvinceName' => $organization->state ?: $organization->city,
            'postalCode' => $organization->postcode,
            'countryName' => strtoupper($organization->country_code),
            'companyNumber' => $organization->registration_number,
        ], function ($value) {
            return $value !== null;
        });
    }

    /**
     * Map a category DCV method to the platform value.
     */
    protected function toSectigoDcvMethod(string $dcvMethod): string
    {
        return $dcvMethod === DcvMethod::FILE ? 'HTTP_CSR_HASH' : 'CNAME_CSR_HASH';
    }

    /**
     * Map a platform DCV method to the category value.
     */
    protected function fromSectigoDcvMethod(string $raw): ?string
    {
        $raw = strtoupper($raw);

        if (strpos($raw, 'HTTP') !== false) {
            return DcvMethod::FILE;
        }
        if (strpos($raw, 'CNAME') !== false || strpos($raw, 'DNS') !== false) {
            return DcvMethod::DNS;
        }

        return null;
    }

    /**
     * Wrap an unarmored base64 certificate as PEM.
     */
    protected function pemWrapCertificate(string $certificate): string
    {
        $certificate = trim($certificate);

        if (strpos($certificate, '-----BEGIN') !== false) {
            return $certificate;
        }

        $base64 = (string) preg_replace('/\s+/', '', $certificate);

        return "-----BEGIN CERTIFICATE-----\n"
            . trim(chunk_split($base64, 64, "\n"))
            . "\n-----END CERTIFICATE-----";
    }

    /**
     * Normalize a CollectSSL certificate field to PEM, tolerating already-armored
     * PEM, base64-encoded PEM, and unarmored base64 (DER) input.
     */
    protected function normalizeCertificate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '-----BEGIN') !== false) {
            return $value;
        }

        $compact = (string) preg_replace('/\s+/', '', $value);
        $decoded = base64_decode($compact, true);
        if ($decoded !== false && strpos($decoded, '-----BEGIN CERTIFICATE-----') !== false) {
            return trim($decoded);
        }

        return $this->pemWrapCertificate($value);
    }

    /**
     * Split a PEM blob into individual certificate PEMs.
     *
     * @return string[]
     */
    protected function splitCertificates(string $pem): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches)) {
            return array_map('trim', $matches[0]);
        }

        return $pem === '' ? [] : [$pem];
    }

    /**
     * Order CA certificates into a conventional chain (issuer of the leaf first,
     * up to the root) by following issuer links; falls back to the given order.
     *
     * @param string[] $caCertificates
     */
    protected function orderCaChain(string $leafPem, array $caCertificates): string
    {
        if ($caCertificates === []) {
            return '';
        }

        $dns = function (string $pem): ?array {
            $x509 = new X509();
            if ($x509->loadX509($pem) === false) {
                return null;
            }

            return [
                'subject' => (string) $x509->getSubjectDN(X509::DN_STRING),
                'issuer' => (string) $x509->getIssuerDN(X509::DN_STRING),
            ];
        };

        $leaf = $dns($leafPem);

        $bySubject = [];
        foreach ($caCertificates as $pem) {
            $parsed = $dns($pem);
            if ($parsed !== null) {
                $bySubject[$parsed['subject']] = ['pem' => $pem] + $parsed;
            }
        }

        if ($leaf === null || $bySubject === []) {
            return implode("\n", $caCertificates);
        }

        $ordered = [];
        $used = [];
        $nextIssuer = $leaf['issuer'];
        while (isset($bySubject[$nextIssuer]) && !isset($used[$nextIssuer])) {
            $cert = $bySubject[$nextIssuer];
            $ordered[] = $cert['pem'];
            $used[$nextIssuer] = true;
            if ($cert['subject'] === $cert['issuer']) {
                break; // reached the self-signed root
            }
            $nextIssuer = $cert['issuer'];
        }

        // Append any certs not reachable via issuer links (defensive).
        foreach ($bySubject as $subject => $cert) {
            if (!isset($used[$subject])) {
                $ordered[] = $cert['pem'];
            }
        }

        return implode("\n", $ordered);
    }

    /**
     * Derive the DCV uniqueValue deterministically from the CSR.
     *
     * The CNAME/file DCV token embeds this salt. Deriving it from the CSR keeps
     * the token identical when the same CSR is resubmitted, e.g. a reissue that
     * reuses the customer's key. The validation record placed at order time
     * still matches, so DCV does not restart. A new CSR yields a new token.
     */
    protected function uniqueValueForCsr(string $csrPem): string
    {
        return substr(hash('sha256', 'sectigo-dcv:' . Utils::pemToDer($csrPem)), 0, 20);
    }

    /**
     * @return string[] Standard status-detail flags for CollectSSL
     */
    protected function statusFlags(): array
    {
        return [
            'showValidityPeriod' => 'Y',
            'showFQDN' => 'Y',
            'showExtStatus' => 'Y',
            'showStatusDetails' => 'Y',
            'showMDCDomainDetails' => 'Y',
        ];
    }

    /**
     * Fail with a human-readable message for a certificate which is not issued.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function errorNotIssued(string $status): void
    {
        $messages = [
            CertificateInfoResult::STATUS_PENDING_VALIDATION
                => 'Certificate not issued yet — domain control validation is still pending',
            CertificateInfoResult::STATUS_PROCESSING
                => 'Certificate not issued yet — the certificate authority is processing the order',
            CertificateInfoResult::STATUS_EXPIRED => 'Certificate has expired',
            CertificateInfoResult::STATUS_REVOKED => 'Certificate has been revoked',
            CertificateInfoResult::STATUS_CANCELLED => 'Certificate order has been cancelled',
            CertificateInfoResult::STATUS_ERROR => 'Certificate order is in an error state',
        ];

        $this->errorResult($messages[$status] ?? 'Certificate is not issued');
    }

    protected function api(): SectigoApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => SectigoApi::BASE_URL,
            'headers' => [
                'User-Agent' => SectigoApi::USER_AGENT,
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            // Always attach the PSR-3 request logger; the injected logger only
            // records at the level the admin has configured, so no debug flag is needed.
            'handler' => $this->getGuzzleHandlerStack(true),
        ]);

        return $this->api = new SectigoApi($client, $this->configuration);
    }
}
