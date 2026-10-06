<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Actalis;

use Carbon\Carbon;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\LogsDebugData;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Helper\ActalisApi;
use Upmind\ProvisionProviders\SslCertificates\Category;
use Upmind\ProvisionProviders\SslCertificates\Data\CancelParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateDownloadResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateIdentifierParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateOrderResult;
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

/**
 * Actalis Partner API provider.
 *
 * The category's `order_id` is the Actalis `requestId` and `certificate_id` is its `certificateId`.
 * The two are distinct entities: a request is the queue entry that produces a certificate, and
 * renewal and reissue each create a *new* request against an existing certificate, hence a new
 * `order_id`.
 */
class Provider extends Category implements ProviderInterface, LogsDebugData
{
    /**
     * The only certificate family this category deals in.
     */
    private const CERT_FAMILY_SSL = 'SSLSV';

    public const CERT_CLASS_DV = 'DV';
    public const CERT_CLASS_OV = 'OV';
    public const CERT_CLASS_EV = 'EV';
    public const CERT_CLASS_QW = 'QW';

    public const CERT_CLASSES = [
        self::CERT_CLASS_DV,
        self::CERT_CLASS_OV,
        self::CERT_CLASS_EV,
        self::CERT_CLASS_QW,
    ];

    public const POLICY_SINGLE_HOST = 'SingleHost';
    public const POLICY_WILDCARD = 'Wildcard';
    public const POLICY_MULTI_SAN = 'MultiSAN';

    public const POLICIES = [
        self::POLICY_SINGLE_HOST,
        self::POLICY_WILDCARD,
        self::POLICY_MULTI_SAN,
    ];

    /**
     * Actalis DCV method names.
     */
    private const DCV_WEBSITE_CHANGE = 'website-change';
    private const DCV_DNS_CHANGE = 'dns-change';

    /**
     * Where the website-change method expects the validation file, and what it is called.
     */
    private const DCV_FILE_NAME = 'actalis.txt';
    private const DCV_FILE_PATH = '/.well-known/pki-validation/' . self::DCV_FILE_NAME;

    /**
     * Prefix of the TXT record value the dns-change method looks for.
     */
    private const DCV_DNS_VALUE_PREFIX = 'actalis-dcv=';

    /**
     * SSL Server certificates are only sold on three-month and one-year terms.
     */
    private const DURATION_THREE_MONTHS = '3m';
    private const DURATION_ONE_YEAR = '1y';

    /**
     * Longest term, in days, still fulfilled as a three-month certificate.
     */
    private const THREE_MONTH_DAYS_LIMIT = 120;

    protected Configuration $configuration;

    /**
     * @var ActalisApi|null
     */
    protected ?ActalisApi $api = null;

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
            ->setName('Actalis')
            ->setDescription('Order and manage SSL/TLS certificates directly with the Actalis CA')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/actalis-logo.png');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function create(CreateParams $params): CertificateOrderResult
    {
        try {
            [$certClass, $policy] = $this->parseProductCode(
                $params->product_code,
                $params->common_name,
                $params->certificate_type
            );

            $keyPair = $this->resolveKeyPair($params->csr, $params->common_name, $params->organization);
            $dcvMethods = $this->buildDcvMethods($params->dcv_method);

            $response = $this->api()->request(array_merge(
                $this->orderParams($params),
                [
                    'certFamily' => self::CERT_FAMILY_SSL,
                    'certClass' => $certClass,
                    'policy' => $policy,
                    'commonName' => $params->common_name,
                    'duration' => $this->mapDuration($params->validity_days),
                    'csr' => $this->formatCsr($keyPair['csr']),
                    'domainNames' => $params->common_name,
                    'dcvMethods' => $dcvMethods,
                ]
            ));

            return $this->orderResult(
                $this->requireRequestId($response),
                array_merge(
                    [
                        'product_code' => $params->product_code,
                        'certificate_type' => $this->certificateTypeFromClass($certClass),
                        'csr' => $keyPair['generated'] ? $keyPair['csr'] : null,
                        'private_key' => $keyPair['private_key'],
                    ],
                    $this->dcvInstructions(
                        $params->dcv_method,
                        $params->common_name,
                        $this->firstConfirmationValue($response)
                    )
                )
            )->setMessage('Certificate order created');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(CertificateIdentifierParams $params): CertificateInfoResult
    {
        try {
            return $this->infoResult((string)$params->order_id)
                ->setMessage('Certificate order info obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function downloadCertificate(CertificateIdentifierParams $params): CertificateDownloadResult
    {
        try {
            $requestId = (string)$params->order_id;

            $retrieve = $this->api()->retrieve([
                'requestId' => $requestId,
                'certificateType' => ActalisApi::FORMAT_CHAIN,
            ]);

            $chain = $retrieve['certificate'] ?? null;

            if (!is_string($chain) || $chain === '') {
                $this->errorResult('Certificate is not available for download yet', [
                    'order_id' => $requestId,
                    'provider_status' => $retrieve['certStatus'] ?? null,
                    'provider_message' => $retrieve['certMsg'] ?? null,
                ]);
            }

            [$certificate, $caBundle] = $this->splitChain($chain);

            return CertificateDownloadResult::create(array_merge(
                $this->infoValues($requestId, $retrieve),
                [
                    'certificate' => $certificate,
                    'ca_bundle' => $caBundle,
                ]
            ))->setMessage('Certificate obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function restartValidation(RestartValidationParams $params): CertificateOrderResult
    {
        try {
            $requestId = (string)$params->order_id;
            $retrieve = $this->api()->retrieve(['requestId' => $requestId]);

            $commonName = $this->requireCommonName($retrieve, $requestId);
            $dcvMethod = $params->dcv_method ?: $this->currentDcvMethod($retrieve, $commonName);

            $response = $this->api()->changeDcv([
                'requestId' => $requestId,
                'domainNames' => $commonName,
                'dcvMethods' => $this->buildDcvMethods($dcvMethod),
            ]);

            return $this->orderResult(
                $requestId,
                $this->dcvInstructions(
                    $dcvMethod,
                    $commonName,
                    $this->firstConfirmationValue($response)
                )
            )->setMessage('Domain control validation restarted');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reissue(ReissueParams $params): CertificateOrderResult
    {
        try {
            $retrieve = $this->api()->retrieve(['requestId' => (string)$params->order_id]);
            $certificateId = $params->certificate_id ?: ($retrieve['certificateId'] ?? null);

            if (!$certificateId) {
                $this->errorResult('Certificate cannot be reissued before it has been issued', [
                    'order_id' => $params->order_id,
                    'provider_status' => $retrieve['certStatus'] ?? null,
                ]);
            }

            $commonName = $this->requireCommonName($retrieve, (string)$params->order_id);
            $domainNames = $this->domainNames($retrieve, $commonName);
            $dcvMethod = $params->dcv_method ?: $this->currentDcvMethod($retrieve, $commonName);

            $keyPair = $this->resolveKeyPair($params->csr, $commonName, null);

            $response = $this->api()->request([
                'certificateId' => (string)$certificateId,
                'isRekeying' => 'true',
                'csr' => $this->formatCsr($keyPair['csr']),
                'domainNames' => $domainNames,
                'dcvMethods' => $this->buildDcvMethods($dcvMethod, substr_count($domainNames, ',') > 0),
                'requestorEmail' => $params->customer_email,
                'partnerName' => $this->configuration->partner_name,
            ] + $this->notifyParams());

            return $this->orderResult(
                $this->requireRequestId($response),
                array_merge(
                    [
                        'csr' => $keyPair['generated'] ? $keyPair['csr'] : null,
                        'private_key' => $keyPair['private_key'],
                    ],
                    $this->dcvInstructions(
                        $dcvMethod,
                        $commonName,
                        $this->firstConfirmationValue($response)
                    )
                )
            )->setMessage('Certificate reissue requested');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): CertificateOrderResult
    {
        try {
            [$certClass, $policy] = $this->parseProductCode(
                $params->product_code,
                $params->common_name,
                $params->certificate_type
            );

            $certificateId = $params->certificate_id
                ?: ($this->api()->retrieve(['requestId' => (string)$params->order_id])['certificateId'] ?? null);

            if (!$certificateId) {
                $this->errorResult('Certificate cannot be renewed before it has been issued', [
                    'order_id' => $params->order_id,
                ]);
            }

            $keyPair = $this->resolveKeyPair($params->csr, $params->common_name, $params->organization);
            $dcvMethod = $params->dcv_method;

            $response = $this->api()->request(array_merge(
                $this->orderParams($params),
                [
                    'certificateId' => (string)$certificateId,
                    'certFamily' => self::CERT_FAMILY_SSL,
                    'certClass' => $certClass,
                    'policy' => $policy,
                    'commonName' => $params->common_name,
                    'duration' => $this->mapDuration($params->validity_days),
                    'csr' => $this->formatCsr($keyPair['csr']),
                    'domainNames' => $params->common_name,
                    'dcvMethods' => $this->buildDcvMethods($dcvMethod),
                ]
            ));

            return $this->orderResult(
                $this->requireRequestId($response),
                array_merge(
                    [
                        'product_code' => $params->product_code,
                        'certificate_type' => $this->certificateTypeFromClass($certClass),
                        'csr' => $keyPair['generated'] ? $keyPair['csr'] : null,
                        'private_key' => $keyPair['private_key'],
                    ],
                    $this->dcvInstructions(
                        $dcvMethod,
                        $params->common_name,
                        $this->firstConfirmationValue($response)
                    )
                )
            )->setMessage('Certificate renewal ordered');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function revoke(RevokeParams $params): EmptyResult
    {
        try {
            $certificateId = $params->certificate_id
                ?: ($this->api()->retrieve(['requestId' => (string)$params->order_id])['certificateId'] ?? null);

            if (!$certificateId) {
                $this->errorResult('Certificate cannot be revoked before it has been issued', [
                    'order_id' => $params->order_id,
                ]);
            }

            $this->api()->changeStatus([
                'certificateId' => (string)$certificateId,
                'operation' => 'REV',
                'reason' => $this->mapRevocationReason($params->reason),
            ]);

            return $this->emptyResult('Certificate revoked');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function cancel(CancelParams $params): EmptyResult
    {
        try {
            $this->api()->cancel((string)$params->order_id);

            return $this->emptyResult('Certificate order cancelled');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Order params shared by create and renew.
     *
     * @return array<string,mixed>
     */
    protected function orderParams(CreateParams $params): array
    {
        // The customer is both requestor and approver. The API layer drops nulls; Actalis rejects
        // an order that lacks what its class needs.
        [$firstName, $lastName] = Utils::splitName($params->customer_name);
        // only OV vetting calls the customer back
        $phone = $params->organization ? $params->customer_phone : null;

        $orderParams = array_merge([
            'partnerName' => $this->configuration->partner_name,
            'requestorEmail' => $params->customer_email,
            'requestorName' => $firstName,
            'requestorSurname' => $lastName,
            'requestorPhone' => $phone,
            'approverEmail' => $params->customer_email,
            'approverName' => $firstName,
            'approverSurname' => $lastName,
            'approverPhone' => $phone,
            'externalOrderNumber' => $params->reference,
        ], $this->notifyParams());

        $organization = $params->organization;

        if ($organization) {
            // Nested params arrive as a stdClass, so an unset field is undefined, not null; ?? reads
            // it defensively.
            $orderParams = array_merge($orderParams, [
                'orgName' => $organization->name ?? null,
                'orgCountry' => $organization->country_code ?? null,
                'orgRegistrationNumber' => $organization->registration_number ?? null,
            ]);
        }

        return $orderParams;
    }

    /**
     * `notifyUser=0` stops Actalis emailing the issued certificate; omitted, it sends it.
     *
     * @return array<string,string>
     */
    protected function notifyParams(): array
    {
        return $this->configuration->notify_customer === false ? ['notifyUser' => '0'] : [];
    }

    /**
     * Use the customer's CSR where they supplied one and it matches, otherwise generate a key pair and CSR.
     *
     * @return array{csr:string,private_key:string|null,generated:bool}
     */
    protected function resolveKeyPair(?string $csr, string $commonName, ?OrganizationParams $organization): array
    {
        if ($csr) {
            $this->assertCsrMatches($csr, $commonName);

            return ['csr' => $csr, 'private_key' => null, 'generated' => false];
        }

        $privateKey = KeyPairHelper::generatePrivateKey();

        return [
            'csr' => KeyPairHelper::generateCsr($privateKey, $commonName, $organization),
            'private_key' => KeyPairHelper::privateKeyToPem($privateKey),
            'generated' => true,
        ];
    }

    /**
     * Actalis wants the CSR as bare base64: no PEM markers, no line breaks.
     */
    protected function formatCsr(string $csr): string
    {
        return (string)preg_replace('/\s+/', '', (string)preg_replace('/-----[^-]+-----/', '', $csr));
    }

    /**
     * Split a `product_code` into an Actalis certificate class and policy.
     *
     * The canonical form is `<CLASS>:<POLICY>`, e.g. `DV:SingleHost` or `OV:Wildcard`. Either half
     * may be omitted: a missing class is taken from `certificate_type` and a missing policy from
     * whether the common name is a wildcard. The CA rejects a policy that does not fit the common name.
     *
     * @return array{0:string,1:string}
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function parseProductCode(string $productCode, string $commonName, ?string $certificateType): array
    {
        $class = null;
        $policy = null;

        foreach (preg_split('#[:/]#', trim($productCode)) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if ($matchedClass = $this->matchConstant($part, self::CERT_CLASSES)) {
                $class = $matchedClass;
                continue;
            }

            if ($matchedPolicy = $this->matchConstant($part, self::POLICIES)) {
                $policy = $matchedPolicy;
                continue;
            }

            $this->errorResult('Unrecognised product code', [
                'product_code' => $productCode,
                'unrecognised' => $part,
                'expected' => 'a <class>:<policy> pair, e.g. DV:SingleHost',
                'classes' => self::CERT_CLASSES,
                'policies' => self::POLICIES,
            ]);
        }

        $class = $class ?: $this->certClassFromType($certificateType);
        $policy = $policy ?: ($this->isWildcard($commonName) ? self::POLICY_WILDCARD : self::POLICY_SINGLE_HOST);

        return [$class, $policy];
    }

    /**
     * @param string[] $candidates
     */
    protected function matchConstant(string $value, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (strcasecmp($value, $candidate) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    protected function certClassFromType(?string $certificateType): string
    {
        $classes = [
            CertificateInfoResult::CERTIFICATE_TYPE_DV => self::CERT_CLASS_DV,
            CertificateInfoResult::CERTIFICATE_TYPE_OV => self::CERT_CLASS_OV,
            CertificateInfoResult::CERTIFICATE_TYPE_EV => self::CERT_CLASS_EV,
        ];

        return $classes[(string)$certificateType] ?? self::CERT_CLASS_DV;
    }

    protected function certificateTypeFromClass(?string $certClass): ?string
    {
        $types = [
            self::CERT_CLASS_DV => CertificateInfoResult::CERTIFICATE_TYPE_DV,
            self::CERT_CLASS_OV => CertificateInfoResult::CERTIFICATE_TYPE_OV,
            self::CERT_CLASS_EV => CertificateInfoResult::CERTIFICATE_TYPE_EV,
            // a QWAC is an EV certificate carrying an extra eIDAS statement
            self::CERT_CLASS_QW => CertificateInfoResult::CERTIFICATE_TYPE_EV,
        ];

        return $types[(string)$certClass] ?? null;
    }

    /**
     * SSL Server certificates come in three-month and one-year terms only, so anything longer is
     * fulfilled a year at a time.
     */
    protected function mapDuration(int $validityDays): string
    {
        return $validityDays <= self::THREE_MONTH_DAYS_LIMIT
            ? self::DURATION_THREE_MONTHS
            : self::DURATION_ONE_YEAR;
    }

    /**
     * Build the `dcvMethods` value for a single domain.
     */
    protected function buildDcvMethods(
        ?string $dcvMethod,
        bool $applyToAllDomains = false
    ): string {
        $prefix = $applyToAllDomains ? 'all-' : '';

        switch ($dcvMethod ?: DcvMethod::DNS) {
            case DcvMethod::FILE:
                return $prefix . self::DCV_WEBSITE_CHANGE;
            case DcvMethod::DNS:
            default:
                return $prefix . self::DCV_DNS_CHANGE;
        }
    }

    /**
     * The completion data a customer needs in order to pass validation.
     *
     * Only `request` and `changedcv` return the confirmation value, so full instructions come
     * from create/renew/reissue/restartValidation. getInfo cannot read it back — it is a server random,
     * not derived from the CSR — so a status query reports status only, not these fields.
     *
     * @return array<string,mixed>
     */
    protected function dcvInstructions(
        ?string $dcvMethod,
        string $commonName,
        ?string $confirmationValue
    ): array {
        $dcvMethod = $dcvMethod ?: DcvMethod::DNS;
        $domain = $this->dcvDomain($commonName);

        $instructions = ['dcv_method' => $dcvMethod];

        // a null confirmation value means Actalis considers the domain already validated
        if ($confirmationValue === null) {
            return $instructions;
        }

        if ($dcvMethod === DcvMethod::FILE) {
            return array_merge($instructions, [
                'dcv_file_path' => self::DCV_FILE_PATH,
                'dcv_file_content' => $confirmationValue,
            ]);
        }

        return array_merge($instructions, [
            'dcv_dns_record_name' => $domain,
            'dcv_dns_record_type' => 'TXT',
            'dcv_dns_record_value' => self::DCV_DNS_VALUE_PREFIX . $confirmationValue,
        ]);
    }

    /**
     * Map the DCV method Actalis reports for a domain back onto a category method.
     *
     * @param array<string,mixed> $retrieve
     */
    protected function currentDcvMethod(array $retrieve, string $commonName): ?string
    {
        $domainsMap = $retrieve['domainsMap'] ?? null;

        if (!is_array($domainsMap)) {
            return null;
        }

        $actalisMethod = $domainsMap[$commonName] ?? reset($domainsMap);

        $methods = [
            self::DCV_WEBSITE_CHANGE => DcvMethod::FILE,
            self::DCV_DNS_CHANGE => DcvMethod::DNS,
        ];

        return $methods[(string)$actalisMethod] ?? null;
    }

    /**
     * Every domain on an existing request; a reissue must cover all of them.
     *
     * @param array<string,mixed> $retrieve
     */
    protected function domainNames(array $retrieve, string $commonName): string
    {
        $domainsMap = $retrieve['domainsMap'] ?? null;

        if (is_array($domainsMap) && $domainsMap !== []) {
            return implode(',', array_keys($domainsMap));
        }

        return $commonName;
    }

    /**
     * @param array<string,mixed> $retrieve
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function requireCommonName(array $retrieve, string $requestId): string
    {
        $commonName = (string)($retrieve['commonName'] ?? '');

        if ($commonName === '') {
            $this->errorResult('The provider did not report a common name for this order', [
                'order_id' => $requestId,
            ]);
        }

        return $commonName;
    }

    /**
     * The domain control is proven over; for a wildcard that is the domain itself.
     */
    protected function dcvDomain(string $commonName): string
    {
        return $this->isWildcard($commonName) ? substr($commonName, 2) : $commonName;
    }

    protected function isWildcard(string $commonName): bool
    {
        return strpos($commonName, '*.') === 0;
    }

    /**
     * Confirmation values come back positionally, one per requested domain.
     *
     * @param array<string,mixed> $response
     */
    protected function firstConfirmationValue(array $response): ?string
    {
        $values = $response['confirmationValue'] ?? null;

        if (!is_array($values) || $values === []) {
            return null;
        }

        $value = reset($values);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Pull the request reference from a request/reissue/renew response, failing clearly if the API
     * reported success without one rather than tripping over an undefined array key downstream.
     *
     * @param array<string,mixed> $response
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function requireRequestId(array $response): string
    {
        $requestId = $response['requestId'] ?? null;

        if ($requestId === null || $requestId === '') {
            $this->errorResult('The provider did not return a request reference', [], ['response' => $response]);
        }

        return (string)$requestId;
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function infoResult(string $requestId, array $overrides = []): CertificateInfoResult
    {
        return CertificateInfoResult::create(array_merge($this->infoValues($requestId), $overrides));
    }

    /**
     * The richer result the state-changing calls return: order status plus the DCV instructions
     * and any generated key material.
     *
     * @param array<string,mixed> $overrides
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function orderResult(string $requestId, array $overrides = []): CertificateOrderResult
    {
        return CertificateOrderResult::create(array_merge($this->infoValues($requestId), $overrides));
    }

    /**
     * Assemble the category's view of an order.
     *
     * `retrieve` reports how far the request has got; `certinfo` is what knows the certificate has
     * since been revoked or expired, and carries the validity dates and serial number, so both are
     * consulted once a certificate exists.
     *
     * @param array<string,mixed>|null $retrieve
     *
     * @return array<string,mixed>
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function infoValues(string $requestId, ?array $retrieve = null): array
    {
        $retrieve = $retrieve ?: $this->api()->retrieve(['requestId' => $requestId]);

        $requestStatus = isset($retrieve['certStatus']) ? (string)$retrieve['certStatus'] : null;
        // Actalis returns an empty string until the certificate exists
        $certificateId = ($retrieve['certificateId'] ?? null) ?: null;
        $commonName = (string)($retrieve['commonName'] ?? '');

        $certInfo = $certificateId ? $this->api()->certInfo($certificateId) : [];
        $certStatus = isset($certInfo['certStatus']) ? (string)$certInfo['certStatus'] : null;

        $dcvMethod = $this->currentDcvMethod($retrieve, $commonName);
        $dcvStatus = $this->mapDcvStatus($requestStatus);

        $values = [
            'order_id' => $requestId,
            'certificate_id' => $certificateId,
            'status' => $this->mapStatus($requestStatus, $certStatus, $retrieve['certMsg'] ?? null),
            'provider_status' => $this->providerStatus($requestStatus, $certStatus),
            'certificate_type' => $this->certificateTypeFromClass($certInfo['certClass'] ?? null),
            'common_name' => $commonName,
            'dcv_method' => $dcvMethod,
            'dcv_status' => $dcvStatus,
            'not_before' => $this->formatDate($certInfo['notBefore'] ?? null),
            'not_after' => $this->formatDate($certInfo['notAfter'] ?? null),
            'serial_number' => $certInfo['serialNumber'] ?? null,
        ];

        return $values;
    }

    /**
     * Map the Actalis request and certificate states onto the category's status enum.
     *
     * The certificate's own state wins where it reports something terminal, since a request sits at
     * ISS for good and knows nothing about a later revocation or expiry.
     */
    protected function mapStatus(?string $requestStatus, ?string $certStatus, ?string $certMsg): string
    {
        switch ($certStatus) {
            case ActalisApi::CERT_STATUS_REV:
                return CertificateInfoResult::STATUS_REVOKED;
            case ActalisApi::CERT_STATUS_EXP:
                return CertificateInfoResult::STATUS_EXPIRED;
            case ActalisApi::CERT_STATUS_SUS:
                // suspension is not permitted for SSL Server certificates, so this needs a human
                return CertificateInfoResult::STATUS_ERROR;
        }

        switch ($requestStatus) {
            case ActalisApi::REQUEST_STATUS_DCV:
                return CertificateInfoResult::STATUS_PENDING_VALIDATION;
            case ActalisApi::REQUEST_STATUS_TBV:
            case ActalisApi::REQUEST_STATUS_TBA:
            case ActalisApi::REQUEST_STATUS_RED:
                return CertificateInfoResult::STATUS_PROCESSING;
            case ActalisApi::REQUEST_STATUS_ISS:
                return CertificateInfoResult::STATUS_ISSUED;
            case ActalisApi::REQUEST_STATUS_ERR:
                return CertificateInfoResult::STATUS_ERROR;
            case ActalisApi::REQUEST_STATUS_REJ:
                // REJ covers both our own cancellation and a rejection by the CA's vetting staff;
                // only the message distinguishes them
                return $this->wasCancelled($certMsg)
                    ? CertificateInfoResult::STATUS_CANCELLED
                    : CertificateInfoResult::STATUS_ERROR;
        }

        return CertificateInfoResult::STATUS_PROCESSING;
    }

    protected function wasCancelled(?string $certMsg): bool
    {
        return is_string($certMsg) && stripos($certMsg, 'cancel') !== false;
    }

    protected function mapDcvStatus(?string $requestStatus): ?string
    {
        switch ($requestStatus) {
            case ActalisApi::REQUEST_STATUS_DCV:
                return CertificateInfoResult::DCV_STATUS_PENDING;
            case ActalisApi::REQUEST_STATUS_TBV:
            case ActalisApi::REQUEST_STATUS_TBA:
            case ActalisApi::REQUEST_STATUS_RED:
            case ActalisApi::REQUEST_STATUS_ISS:
                return CertificateInfoResult::DCV_STATUS_COMPLETED;
            case ActalisApi::REQUEST_STATUS_ERR:
            case ActalisApi::REQUEST_STATUS_REJ:
                return CertificateInfoResult::DCV_STATUS_FAILED;
        }

        return null;
    }

    protected function providerStatus(?string $requestStatus, ?string $certStatus): ?string
    {
        $statuses = array_filter([$requestStatus, $certStatus]);

        return $statuses ? implode('/', $statuses) : null;
    }

    /**
     * @param mixed $date
     */
    protected function formatDate($date): ?string
    {
        if (!is_string($date) || $date === '') {
            return null;
        }

        return Carbon::parse($date)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Map an RFC 5280 revocation reason name onto the `R`-prefixed CRLReason token changestatus
     * takes (e.g. `cessationOfOperation` -> `R5`).
     *
     * Actalis accepts only R0/R1/R3/R4/R5 (Partner API §5.9) and rejects an omitted reason with
     * errorCode 320. R0 (`unspecified`) is forbidden in a revocation request by CA/B Forum SC-063.
     * An empty or unrecognised reason therefore defaults to `cessationOfOperation` (R5), the
     * administrative "no longer needed".
     */
    protected function mapRevocationReason(?string $reason): string
    {
        $codes = ActalisApi::REVOCATION_REASON_CODES;

        return 'R' . ($codes[$reason] ?? $codes[ActalisApi::REVOCATION_REASON_DEFAULT]);
    }

    /**
     * Split a PEM chain into the end-entity certificate and the intermediates below it.
     *
     * @return array{0:string,1:string|null}
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function splitChain(string $chain): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $chain, $matches);

        $certificates = $matches[0];

        if ($certificates === []) {
            $this->errorResult('The provider returned a certificate chain that could not be parsed');
        }

        $certificate = array_shift($certificates);

        return [$certificate, $certificates ? implode("\n", $certificates) : null];
    }

    protected function api(): ActalisApi
    {
        // Always attach the redacting request logger; it records through the platform
        // logger at the level the admin has configured, so no separate debug flag is
        // needed (matching the Sectigo provider).
        return $this->api ?: $this->api = new ActalisApi(
            $this->configuration,
            $this->getGuzzleHandlerStack(true)
        );
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        $this->errorResult('Unexpected provider error: ' . $e->getMessage(), [], [], $e);
    }
}
