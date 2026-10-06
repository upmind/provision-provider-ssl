<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Actalis\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Data\Configuration;

/**
 * Actalis Partner API v2.3.3 client.
 *
 * Every method returns the decoded JSON body of a successful call. The API signals failure in the
 * body rather than the status code — `errorCode` is 0 on success and one of the §6 codes otherwise
 * — so a 200 response carrying a non-zero code is raised here as a provision error.
 */
class ActalisApi
{
    public const BASE_URL_PRODUCTION = 'https://services.actalis.it/partner-api/api/';
    public const BASE_URL_SANDBOX = 'https://extwebra-pte.actalis.it/partner-api/api/';

    /**
     * Certificate encodings accepted by retrieve/certretrieve.
     */
    public const FORMAT_CERTIFICATE = 'CER';
    public const FORMAT_PKCS7 = 'P7B';
    public const FORMAT_CHAIN = 'CHN';
    public const FORMAT_PKCS12 = 'P12';

    /**
     * Request (order) states, per Appendix A of the specification.
     */
    public const REQUEST_STATUS_DCV = 'DCV';
    public const REQUEST_STATUS_TBV = 'TBV';
    public const REQUEST_STATUS_TBA = 'TBA';
    public const REQUEST_STATUS_RED = 'RED';
    public const REQUEST_STATUS_ISS = 'ISS';
    public const REQUEST_STATUS_ERR = 'ERR';
    public const REQUEST_STATUS_REJ = 'REJ';

    /**
     * Certificate states, as reported by certinfo.
     */
    public const CERT_STATUS_AUT = 'AUT';
    public const CERT_STATUS_PEN = 'PEN';
    public const CERT_STATUS_ISS = 'ISS';
    public const CERT_STATUS_PUB = 'PUB';
    public const CERT_STATUS_SUS = 'SUS';
    public const CERT_STATUS_REV = 'REV';
    public const CERT_STATUS_EXP = 'EXP';

    /**
     * RFC 5280 revocation reason names mapped to their numeric CRLReason codes. The provider sends
     * them `R`-prefixed; see Provider::mapRevocationReason().
     *
     * @var array<string,int>
     */
    public const REVOCATION_REASON_CODES = [
        'keyCompromise' => 1,
        'affiliationChanged' => 3,
        'superseded' => 4,
        'cessationOfOperation' => 5,
    ];

    public const REVOCATION_REASON_DEFAULT = 'cessationOfOperation';

    /**
     * Error codes where the API's own message needs context to be actionable.
     *
     * Anything not listed here surfaces the `msg` Actalis returned, which is usually specific
     * enough on its own.
     *
     * @var array<int,string>
     */
    protected const ERROR_HINTS = [
        120 => 'the DCV method does not match the domains on the order',
        130 => 'the DCV method was rejected by the CA',
        131 => 'this DCV method is not permitted for the requested certificate class',
        140 => 'the Partner account is not authorised for this service',
        150 => 'the Partner account has reached its certificate limit',
        190 => 'a CAA record on the domain forbids issuance by the certificate authority',
        230 => 'the CSR was rejected as invalid',
        240 => 'the CSR common name does not match the ordered domain',
        510 => 'an active request already exists for this CSR — generate a new key pair',
        530 => 'the request is not in a state that allows this — e.g. validation cannot be restarted on an already-issued order',
        610 => 'the reissue was rejected by the CA',
        620 => 'a certificate id is required to reissue',
        650 => 'renewal/reissue is not permitted while the certificate is in this state',
        660 => 'this certificate cannot be reissued',
        670 => 'the requested class/policy/duration combination is not available on this account',
        680 => 'the reissue must cover every domain on the original certificate',
    ];

    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var HandlerStack|null
     */
    protected $handlerStack;

    /**
     * @var string|null
     */
    protected $certificateFile;

    /**
     * @var string|null
     */
    protected $privateKeyFile;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function __construct(
        Configuration $configuration,
        ?HandlerStack $handlerStack = null,
        ?Client $client = null
    ) {
        $this->configuration = $configuration;
        $this->handlerStack = $handlerStack;
        $this->client = $client ?: $this->makeClient();
    }

    public function __destruct()
    {
        foreach ([$this->certificateFile, $this->privateKeyFile] as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Check that the interface, the service channel and the client certificate are all working.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function echoTest(string $text = 'Hello'): array
    {
        return $this->get('echo', ['text' => $text]);
    }

    /**
     * Submit a request for a new certificate, or for the renewal/reissue of an existing one.
     *
     * @param array<string,mixed> $params
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function request(array $params): array
    {
        return $this->post('request', $params);
    }

    /**
     * Retrieve the state of a request, and the certificate itself once issued.
     *
     * @param array<string,mixed> $query
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function retrieve(array $query): array
    {
        return $this->get('retrieve', $query);
    }

    /**
     * Change the DCV method of one or more domains on a queued request.
     *
     * Validation restarts from scratch for every domain named, even where the method is unchanged,
     * which is what makes this the way to re-send a validation email.
     *
     * @param array<string,mixed> $params
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changeDcv(array $params): array
    {
        return $this->post('changedcv', $params);
    }

    /**
     * Suspend, reactivate or revoke a certificate.
     *
     * @param array<string,mixed> $query
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changeStatus(array $query): array
    {
        return $this->get('changestatus', $query);
    }

    /**
     * Get the state and metadata of an issued certificate.
     *
     * @param string|int $certificateId
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function certInfo($certificateId): array
    {
        return $this->get('certinfo', ['certificateId' => (string)$certificateId]);
    }

    /**
     * Cancel a request that has not reached a final state, moving it to REJ.
     *
     * @param string|int $requestId
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function cancel($requestId): array
    {
        return $this->get('cancel', ['requestId' => (string)$requestId]);
    }

    /**
     * List the email addresses usable to validate control of the given domain.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDcvEmails(string $fqdn): array
    {
        return $this->get('getDcvEmails', ['fqdn' => $fqdn]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function makeClient(): Client
    {
        $this->assertPrivateKeyUsable();

        $this->certificateFile = $this->writeTempFile(
            'actalis-client-cert-',
            $this->configuration->client_certificate
        );
        $this->privateKeyFile = $this->writeTempFile(
            'actalis-client-key-',
            $this->configuration->client_private_key
        );

        $passphrase = $this->configuration->client_private_key_passphrase;

        $options = [
            'base_uri' => $this->configuration->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION,
            'headers' => [
                'Accept' => 'application/json; charset=utf-8',
            ],
            'cert' => $this->certificateFile,
            'ssl_key' => $passphrase ? [$this->privateKeyFile, $passphrase] : $this->privateKeyFile,
            'timeout' => 60,
            'connect_timeout' => 10,
        ];

        if ($this->handlerStack !== null) {
            $options['handler'] = $this->handlerStack;
        }

        return new Client($options);
    }

    /**
     * Verify the configured private key actually loads with its passphrase before cURL is invoked,
     * so a missing or wrong passphrase (or an unreadable key) fails with a clear, actionable error
     * up-front rather than as an opaque "cURL error 58 … no key found, wrong pass phrase" mid-
     * handshake. The `certificate_pem` config rule cannot cover this: it is a structural regex, it
     * rejects encrypted keys outright, and a passphrase check is inherently cross-field.
     *
     * openssl_pkey_get_private() applies the passphrase, so it returns false only when an encrypted
     * key has no/the wrong passphrase, or the key itself is unreadable; an unencrypted key loads
     * regardless of whether a (then unused) passphrase was configured.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function assertPrivateKeyUsable(): void
    {
        if (!function_exists('openssl_pkey_get_private')) {
            return; // no openssl extension to validate with; let cURL surface any problem
        }

        $passphrase = $this->configuration->client_private_key_passphrase;
        $passphrase = ($passphrase !== null && $passphrase !== '') ? $passphrase : null;

        if (openssl_pkey_get_private($this->configuration->client_private_key, $passphrase) !== false) {
            return;
        }

        $message = $passphrase !== null
            ? 'The client private key could not be decrypted — check client_private_key_passphrase'
            : 'The client private key could not be read — if it is encrypted, set client_private_key_passphrase';

        // keep the most recent OpenSSL error, draining any earlier queued entries as we go
        $opensslError = null;
        while (($error = openssl_error_string()) !== false) {
            $opensslError = $error;
        }

        throw ProvisionFunctionError::create($message)
            ->withData(['configuration' => 'client_private_key'])
            ->withDebug(['openssl_error' => $opensslError]);
    }

    /**
     * cURL takes the client certificate and key as file paths, so the configured PEMs are spilled
     * to owner-only temporary files for the lifetime of this instance and removed on destruction.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function writeTempFile(string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw ProvisionFunctionError::create('Unable to create a temporary file for the client credentials');
        }

        chmod($path, 0600);

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw ProvisionFunctionError::create('Unable to write the client credentials to a temporary file');
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $query
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function get(string $method, array $query): array
    {
        return $this->call('GET', $method, ['query' => $this->filterParams($query)]);
    }

    /**
     * @param array<string,mixed> $body
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function post(string $method, array $body): array
    {
        return $this->call('POST', $method, ['json' => $this->filterParams($body)]);
    }

    /**
     * @param array<string,mixed> $options
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function call(string $httpMethod, string $method, array $options): array
    {
        try {
            $response = $this->client->request($httpMethod, $method, $options);
        } catch (ConnectException $e) {
            throw ProvisionFunctionError::create('Unable to connect to the provider API', $e)
                ->withData(['method' => $method]);
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, $method);
        } catch (Throwable $e) {
            throw ProvisionFunctionError::create('Provider API error: ' . $e->getMessage(), $e)
                ->withData(['method' => $method]);
        }

        return $this->parseResponse($response, $method);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function parseResponse(ResponseInterface $response, string $method): array
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw ProvisionFunctionError::create('Unexpected non-JSON response from the provider API')
                ->withData(['method' => $method, 'status_code' => $response->getStatusCode()])
                ->withDebug(['body' => mb_substr($body, 0, 500)]);
        }

        $error = $this->errorFromData($data, $method, $response->getStatusCode());

        if ($error !== null) {
            throw $error;
        }

        return $data;
    }

    /**
     * Interpret an Actalis error carried in a decoded body, or return null when it carries none.
     *
     * Actalis reports business errors in the body regardless of HTTP status — errorCode 530
     * "Invalid request status." arrives with a 500 — so the body is the authoritative signal.
     * Called from both the success path and the exception path so a buried message is surfaced
     * wherever it lands.
     *
     * @param array<string,mixed> $data
     */
    protected function errorFromData(
        array $data,
        string $method,
        int $statusCode,
        ?Throwable $previous = null
    ): ?ProvisionFunctionError {
        // field-level rejections come back in their own envelope, without an errorCode
        if (isset($data['ValidationErrors'])) {
            $summary = $this->summarizeValidationErrors($data['ValidationErrors']);

            return ProvisionFunctionError::create(
                'The provider rejected the request parameters' . ($summary !== '' ? ': ' . $summary : ''),
                $previous
            )
                ->withData([
                    'method' => $method,
                    'status_code' => $statusCode,
                    'validation_errors' => $data['ValidationErrors'],
                ]);
        }

        $errorCode = (int)($data['errorCode'] ?? 0);

        if ($errorCode !== 0) {
            return ProvisionFunctionError::create($this->errorMessage($errorCode, $data), $previous)
                ->withData([
                    'method' => $method,
                    'status_code' => $statusCode,
                    'error_code' => $errorCode,
                    'error_message' => $this->responseMessage($data),
                ]);
        }

        // an HTTP error status carrying a message but no errorCode still names a real reason
        $message = $this->responseMessage($data);

        if ($statusCode >= 400 && $message !== null && $message !== '') {
            return ProvisionFunctionError::create('Provider API error: ' . $message, $previous)
                ->withData([
                    'method' => $method,
                    'status_code' => $statusCode,
                    'error_message' => $message,
                ]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function errorMessage(int $errorCode, array $data): string
    {
        $message = $this->responseMessage($data) ?: 'Unknown error';
        $hint = self::ERROR_HINTS[$errorCode] ?? null;

        return sprintf('Provider API error %d: %s%s', $errorCode, $message, $hint ? ' — ' . $hint : '');
    }

    /**
     * Flatten a `ValidationErrors` envelope of unknown depth into `field: reason` pairs.
     *
     * @param mixed $errors
     */
    protected function summarizeValidationErrors($errors, string $path = ''): string
    {
        if (!is_array($errors)) {
            return is_scalar($errors) ? ltrim($path . ': ' . $errors, ': ') : '';
        }

        $parts = [];
        foreach ($errors as $key => $value) {
            $parts[] = $this->summarizeValidationErrors($value, is_int($key) ? $path : ltrim($path . '.' . $key, '.'));
        }

        $summary = implode('; ', array_filter($parts));

        return strlen($summary) > 500 ? substr($summary, 0, 497) . '...' : $summary;
    }

    /**
     * Successful calls and most errors report in `msg`; a handful of errors use `message` instead.
     *
     * @param array<string,mixed> $data
     */
    protected function responseMessage(array $data): ?string
    {
        $message = $data['msg'] ?? $data['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    protected function handleRequestException(RequestException $e, string $method): ProvisionFunctionError
    {
        $response = $e->getResponse();

        // A RequestException carrying no response is a transport-level failure — TLS handshake,
        // client-certificate loading, DNS, timeout — not an API rejection, so there is no status
        // code to report. cURL's own message is the useful signal; surface it rather than a
        // misleading "[0] unexpected response".
        if ($response === null) {
            return ProvisionFunctionError::create('Could not complete the provider API request', $e)
                ->withData(['method' => $method])
                ->withDebug(['transport_error' => $e->getMessage()]);
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        // Actalis reports business errors in the body even on a 4xx/5xx (e.g. errorCode 530
        // "Invalid request status." with a 500), so surface the body's own error over the generic
        // HTTP-status explanation wherever the body carries one.
        $data = json_decode($body, true);

        if (is_array($data)) {
            $error = $this->errorFromData($data, $method, $statusCode, $e);

            if ($error !== null) {
                return $error;
            }
        }

        $explanations = [
            403 => 'the client certificate was rejected',
            404 => 'the API method does not exist',
            405 => 'the API method was called with the wrong HTTP verb',
            406 => 'the requested response format is not supported',
            500 => 'the provider encountered an internal error',
            502 => 'the provider API is unavailable',
        ];

        $explanation = $explanations[$statusCode] ?? 'unexpected response';

        return ProvisionFunctionError::create(
            sprintf('Provider API error [%d]: %s', $statusCode, $explanation),
            $e
        )
            ->withData(['method' => $method, 'status_code' => $statusCode])
            ->withDebug(['body' => mb_substr($body, 0, 500)]);
    }

    /**
     * Drop parameters the caller left unset; Actalis treats an empty string as a supplied value
     * and rejects several fields for being present at all.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    protected function filterParams(array $params): array
    {
        return array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
