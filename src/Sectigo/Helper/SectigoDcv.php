<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper;

use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;

/**
 * Computes Sectigo file/CNAME DCV data, which derives from hashes of the DER-encoded CSR.
 */
class SectigoDcv
{
    public const DEFAULT_HASH_DOMAIN = 'comodoca.com';

    /**
     * Build the validation file data for HTTP_CSR_HASH validation.
     *
     * @return array{name: string, path: string, content: string}
     */
    public static function fileData(string $csrPem, string $hashDomain, ?string $uniqueValue): array
    {
        $der = Utils::pemToDer($csrPem);
        $name = strtoupper(md5($der)) . '.txt';

        $content = hash('sha256', $der) . "\n" . $hashDomain;
        if ($uniqueValue !== null && $uniqueValue !== '') {
            $content .= "\n" . $uniqueValue;
        }

        return [
            'name' => $name,
            'path' => '/.well-known/pki-validation/' . $name,
            'content' => $content,
        ];
    }

    /**
     * Build the DNS record data for CNAME_CSR_HASH validation.
     *
     * @return array{name: string, type: string, value: string}
     */
    public static function cnameRecord(string $csrPem, string $domain, string $hashDomain, ?string $uniqueValue): array
    {
        $der = Utils::pemToDer($csrPem);
        $sha256 = hash('sha256', $der);

        $domain = ltrim(str_replace('*.', '', strtolower($domain)), '.');

        return [
            'name' => sprintf('_%s.%s', strtolower(md5($der)), $domain),
            'type' => 'CNAME',
            'value' => sprintf(
                '%s.%s.%s%s.',
                substr($sha256, 0, 32),
                substr($sha256, 32),
                ($uniqueValue !== null && $uniqueValue !== '') ? $uniqueValue . '.' : '',
                $hashDomain
            ),
        ];
    }
}
