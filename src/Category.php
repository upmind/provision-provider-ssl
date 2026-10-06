<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Log\LogLevel;
use Upmind\ProvisionBase\Provider\BaseCategory;
use Upmind\ProvisionBase\Provider\Contract\LogsDebugData;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SslCertificates\Data\CancelParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateDownloadResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateIdentifierParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateOrderResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CreateParams;
use Upmind\ProvisionProviders\SslCertificates\Data\EmptyResult;
use Upmind\ProvisionProviders\SslCertificates\Data\ReissueParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RenewParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RestartValidationParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RevokeParams;
use Upmind\ProvisionProviders\SslCertificates\Helper\CsrHelper;
use Upmind\ProvisionProviders\SslCertificates\Helper\RedactingLogger;

/**
 * This provision category contains the common functions used in provisioning
 * flows for SSL/TLS certificates on various certificate authority and
 * reseller platforms.
 */
abstract class Category extends BaseCategory
{
    /**
     * @inheritDoc
     */
    public static function aboutCategory(): AboutData
    {
        return AboutData::create()
            ->setName('SSL Certificates')
            ->setDescription(
                'Order and manage SSL/TLS certificates on common certificate'
                    . ' authority and reseller platforms such as Sectigo and Actalis'
            )
            ->setIcon('lock');
    }

    /**
     * Place a new certificate order, returning the `order_id` used to identify it in
     * subsequent requests, its normalized status and the DCV instructions to satisfy.
     */
    abstract public function create(CreateParams $params): CertificateOrderResult;

    /**
     * Get an order's status, DCV state and metadata.
     *
     * Tracks an order through validation and issuance. Returns status and `dcv_status`, not the
     * DCV instructions — the confirmation value is issued only by the state-changing calls
     * (create/reissue/renew/restartValidation), so a status query cannot reproduce it.
     */
    abstract public function getInfo(CertificateIdentifierParams $params): CertificateInfoResult;

    /**
     * Download the certificate and CA chain of an issued order.
     */
    abstract public function downloadCertificate(CertificateIdentifierParams $params): CertificateDownloadResult;

    /**
     * Restart domain control validation, optionally switching the method.
     *
     * A restart may rotate the confirmation value, so it returns fresh DCV instructions. It never
     * changes key material — that is `reissue`.
     */
    abstract public function restartValidation(RestartValidationParams $params): CertificateOrderResult;

    /**
     * Reissue (re-key) an issued certificate with a new CSR; does not extend validity.
     */
    abstract public function reissue(ReissueParams $params): CertificateOrderResult;

    /**
     * Renew a certificate order, returning a new `order_id`.
     */
    abstract public function renew(RenewParams $params): CertificateOrderResult;

    /**
     * Cryptographically revoke an issued certificate.
     */
    abstract public function revoke(RevokeParams $params): EmptyResult;

    /**
     * Cancel a certificate order at the certificate authority.
     */
    abstract public function cancel(CancelParams $params): EmptyResult;

    /**
     * Build a guzzle handler stack that logs requests/responses through a
     * redacting PSR-3 logger, masking key material and credentials.
     *
     * We assemble the stack directly rather than delegating to the base, whose
     * v4 implementation always attaches its own non-redacting request logger
     * (the $debugLog argument is deprecated upstream); delegating would log
     * every request twice and leak unredacted secrets. The history middleware
     * is registered so getLastGuzzleRequestDebug() keeps working.
     */
    protected function getGuzzleHandlerStack(bool $debugLog = false): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::history($this->guzzleHistory));

        if (!$debugLog || !$this instanceof LogsDebugData) {
            return $stack;
        }

        $rewindMessageBody = function (MessageInterface $message) {
            $message->getBody()->rewind();
            return $message;
        };

        $stack->push(Middleware::mapRequest($rewindMessageBody), 'Rewind-Request-Stream-After-Logging');
        $stack->push(Middleware::mapResponse($rewindMessageBody), 'Rewind-Response-Stream-After-Logging');
        $stack->push(
            Middleware::log(
                new RedactingLogger($this->getLogger()),
                new MessageFormatter(MessageFormatter::DEBUG . PHP_EOL),
                LogLevel::DEBUG
            ),
            'Logger'
        );

        return $stack;
    }

    /**
     * Create an empty result.
     *
     * @param string $message
     * @param mixed[] $data
     * @param mixed[] $debug
     */
    protected function emptyResult($message, $data = [], $debug = []): EmptyResult
    {
        return EmptyResult::create($data)
            ->setMessage($message)
            ->setDebug($debug);
    }

    /**
     * Fail unless a customer-supplied CSR is valid for a single-domain certificate for the common name.
     *
     * @param string|null $commonName Expected common name; null skips the match but keeps the other checks
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function assertCsrMatches(string $csr, ?string $commonName): void
    {
        try {
            CsrHelper::assertValid($csr, $commonName);
        } catch (InvalidArgumentException $e) {
            $this->errorResult($e->getMessage(), ['common_name' => $commonName]);
        }
    }
}
