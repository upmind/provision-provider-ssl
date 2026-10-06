<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Data\Configuration;

/**
 * Minimal Sectigo legacy trust-provider API client (form-encoded POST, URL-encoded responses).
 */
class SectigoApi
{
    public const BASE_URL = 'https://secure.trust-provider.com/';
    public const USER_AGENT = 'Upmind/ProvisionProviders/SslCertificates/Sectigo';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Configuration
     */
    protected $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * Place a new certificate order via AutoApplyOrder.
     *
     * @param mixed[] $params
     *
     * @return mixed[]
     */
    public function applyOrder(array $params): array
    {
        if ($this->configuration->test_mode) {
            $params['test'] = 'Y';
        }

        return $this->makeRequest('products/!AutoApplyOrder', $params);
    }

    /**
     * Poll order status and optionally collect the issued certificate.
     *
     * @param mixed[] $params
     * @param int[] $tolerateCodes Negative error codes to return instead of throwing
     *
     * @return mixed[]
     */
    public function collectSsl($orderNumber, int $queryType, array $params = [], array $tolerateCodes = []): array
    {
        return $this->makeRequest('products/download/CollectSSL', array_merge([
            'orderNumber' => $orderNumber,
            'queryType' => $queryType,
        ], $params), $tolerateCodes);
    }

    /**
     * Get per-domain DCV method/status detail for an order.
     *
     * @return mixed[]
     */
    public function getMdcDomainDetails($orderNumber): array
    {
        return $this->makeRequest('products/!GetMDCDomainDetails', ['orderNumber' => $orderNumber]);
    }

    /**
     * Change the DCV method (and/or re-trigger validation) for an order.
     *
     * @return mixed[]
     */
    public function updateDcv($orderNumber, string $newMethod, ?string $newEmailAddress = null): array
    {
        // AutoUpdateDCV has no responseFormat argument and rejects it ("-2 'responseFormat'
        // is an unrecognised argument!"); its native response is already URL-encoded.
        return $this->makeRequest('products/!AutoUpdateDCV', array_filter([
            'orderNumber' => $orderNumber,
            'newMethod' => $newMethod,
            'newDCVEmailAddress' => $newEmailAddress,
        ], function ($value) {
            return $value !== null;
        }), [], false);
    }

    /**
     * Reissue (re-key) an order via AutoReplaceSSL.
     *
     * @param mixed[] $params
     *
     * @return mixed[]
     */
    public function replaceSsl(array $params): array
    {
        return $this->makeRequest('products/!AutoReplaceSSL', $params);
    }

    /**
     * Cryptographically revoke a certificate.
     *
     * @param int[] $tolerateCodes
     *
     * @return mixed[]
     */
    public function revokeSsl(array $params, array $tolerateCodes = []): array
    {
        if ($this->configuration->test_mode) {
            $params['test'] = 'Y';
        }

        return $this->makeRequest('products/!AutoRevokeSSL', $params, $tolerateCodes);
    }

    /**
     * Cancel/refund an order.
     *
     * @param int[] $tolerateCodes
     *
     * @return mixed[]
     */
    public function refund($orderNumber, int $reasonCode, array $tolerateCodes = []): array
    {
        return $this->makeRequest('products/!AutoRefund', [
            'orderNumber' => $orderNumber,
            'refundReasonCode' => $reasonCode,
        ], $tolerateCodes);
    }

    /**
     * Make a request, asserting the response errorCode is non-negative (or tolerated).
     *
     * @param mixed[] $params
     * @param int[] $tolerateCodes Negative error codes to return instead of throwing
     * @param bool $formatResponse Send responseFormat=1; disable for endpoints that reject it
     *
     * @return mixed[]
     *
     * @throws ProvisionFunctionError
     */
    public function makeRequest(
        string $path,
        array $params = [],
        array $tolerateCodes = [],
        bool $formatResponse = true
    ): array {
        $base = [
            'loginName' => $this->configuration->login_name,
            'loginPassword' => $this->configuration->login_password,
        ];

        // Most endpoints return URL-encoded key=value output when responseFormat=1;
        // a few (e.g. AutoUpdateDCV) reject the argument and are already URL-encoded.
        if ($formatResponse) {
            $base['responseFormat'] = 1;
        }

        $params = array_merge($base, $params);

        try {
            $response = $this->client->post($path, [RequestOptions::FORM_PARAMS => $params]);
        } catch (TransferException $e) {
            throw (new ProvisionFunctionError('Provider API request failed', 0, $e))
                ->withDebug(['exception' => get_class($e), 'exception_message' => $e->getMessage()]);
        }

        $body = trim($response->getBody()->__toString());
        $data = self::parseResponse($body);

        if (!isset($data['errorCode']) || !is_numeric($data['errorCode'])) {
            throw (new ProvisionFunctionError('Unexpected provider API response'))
                ->withDebug(['http_code' => $response->getStatusCode(), 'response_body' => substr($body, 0, 1000)]);
        }

        $errorCode = (int) $data['errorCode'];

        if ($errorCode < 0 && !in_array($errorCode, $tolerateCodes, true)) {
            $message = (string) (($data['errorMessage'] ?? null) ?: 'no message');
            $item = (string) ($data['errorItem'] ?? '');

            throw (new ProvisionFunctionError(sprintf(
                'Provider API error %d: %s%s',
                $errorCode,
                $message,
                $item !== '' ? sprintf(' (%s)', $item) : ''
            )))
                ->withData(['error_code' => $errorCode, 'error_message' => $message, 'error_item' => $item ?: null])
                ->withDebug(['response_data' => $data]);
        }

        return $data;
    }

    /**
     * Parse a URL-encoded response body, preserving repeated keys as arrays.
     *
     * @return mixed[]
     */
    public static function parseResponse(string $body): array
    {
        $data = [];

        foreach (explode('&', $body) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');

            if ($key === '') {
                continue;
            }

            if (array_key_exists($key, $data)) {
                $data[$key] = is_array($data[$key]) ? $data[$key] : [$data[$key]];
                $data[$key][] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
