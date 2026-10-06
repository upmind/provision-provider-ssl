<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Helper;

use InvalidArgumentException;
use phpseclib3\File\X509;
use Throwable;

/**
 * Checks a customer-supplied CSR against the certificate it is submitted for.
 */
class CsrHelper
{
    /**
     * Assert the CSR is well-formed, self-signed by its key, and names only the given domain.
     *
     * Allowed subject alternative names are the common name plus its free www/apex pair: the
     * `www.` host of a bare name, or the apex of a `www.` name or a wildcard.
     *
     * @param string|null $commonName Expected common name; null checks the CSR against its own common name
     *
     * @return string The CSR's normalized common name
     *
     * @throws InvalidArgumentException With a customer-facing message
     */
    public static function assertValid(string $csrPem, ?string $commonName): string
    {
        $x509 = new X509();

        try {
            $loaded = $x509->loadCSR($csrPem);
        } catch (Throwable $e) {
            $loaded = false;
        }

        if ($loaded === false) {
            throw new InvalidArgumentException('The CSR is not a valid PEM-encoded certificate signing request');
        }

        try {
            $signed = $x509->validateSignature();
        } catch (Throwable $e) {
            $signed = false;
        }

        if ($signed !== true) {
            throw new InvalidArgumentException('The CSR signature does not match its public key');
        }

        $csrNames = array_values(array_unique(array_map(
            [self::class, 'normalizeName'],
            array_filter((array) $x509->getDNProp('id-at-commonName'), 'is_string')
        )));

        if (count($csrNames) === 0) {
            throw new InvalidArgumentException('The CSR has no common name');
        }

        if (count($csrNames) > 1) {
            throw new InvalidArgumentException('The CSR has more than one common name');
        }

        $csrName = $csrNames[0];

        if ($commonName !== null && $csrName !== self::normalizeName($commonName)) {
            throw new InvalidArgumentException(sprintf(
                'The CSR common name %s does not match the certificate domain %s',
                $csrName,
                self::normalizeName($commonName)
            ));
        }

        $altNames = $x509->getExtension('id-ce-subjectAltName');
        $extra = [];
        foreach (is_array($altNames) ? $altNames : [] as $altName) {
            if (!is_array($altName) || !isset($altName['dNSName']) || !is_string($altName['dNSName'])) {
                throw new InvalidArgumentException('The CSR contains alternative names other than domain names');
            }

            $name = self::normalizeName($altName['dNSName']);
            if (!in_array($name, self::allowedNames($csrName), true)) {
                $extra[] = $name;
            }
        }

        if ($extra) {
            throw new InvalidArgumentException(sprintf(
                'The CSR contains additional domains (%s) — multi-domain certificates are not supported',
                implode(', ', array_unique($extra))
            ));
        }

        return $csrName;
    }

    /**
     * Names a single-domain certificate for the given common name may carry.
     *
     * @return string[]
     */
    protected static function allowedNames(string $commonName): array
    {
        if (strpos($commonName, '*.') === 0) {
            return [$commonName, substr($commonName, 2)];
        }

        if (strpos($commonName, 'www.') === 0) {
            return [$commonName, substr($commonName, 4)];
        }

        return [$commonName, 'www.' . $commonName];
    }

    protected static function normalizeName(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.');
    }
}
