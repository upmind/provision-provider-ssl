<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Helper;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;
use Upmind\ProvisionProviders\SslCertificates\Data\OrganizationParams;

/**
 * Generates private keys and CSRs on behalf of customers, at RSA-2048/SHA-256.
 */
class KeyPairHelper
{
    public const KEY_TYPE_RSA = 'rsa';
    public const KEY_TYPE_ECDSA = 'ecdsa';

    public const KEY_TYPES = [
        self::KEY_TYPE_RSA,
        self::KEY_TYPE_ECDSA,
    ];

    public const DEFAULT_RSA_KEY_SIZE = 2048;

    /**
     * Generate a new private key.
     *
     * @param string|null $keyType One of self::KEY_TYPES; defaults to rsa
     * @param int|null $keySize Key size in bits; defaults to 2048 (RSA) / 256 (ECDSA)
     */
    public static function generatePrivateKey(?string $keyType = null, ?int $keySize = null): PrivateKey
    {
        if ($keyType === self::KEY_TYPE_ECDSA) {
            return EC::createKey($keySize === 384 ? 'secp384r1' : 'secp256r1');
        }

        // phpseclib defaults RSA keys to RSASSA-PSS, which taints both the
        // exported public key's AlgorithmIdentifier (id-RSASSA-PSS) and the CSR
        // signature. Sectigo's legacy trust-provider API only parses PKCS#1 v1.5
        // (rsaEncryption + sha256WithRSAEncryption) and rejects PSS CSRs with a
        // generic error, so pin the key to PKCS#1 v1.5 / SHA-256.
        return RSA::createKey($keySize ?: self::DEFAULT_RSA_KEY_SIZE)
            ->withPadding(RSA::SIGNATURE_PKCS1)
            ->withHash('sha256');
    }

    /**
     * Export a private key as PKCS#8 PEM.
     */
    public static function privateKeyToPem(PrivateKey $privateKey): string
    {
        return $privateKey->toString('PKCS8');
    }

    /**
     * Generate a PEM-encoded CSR for the given key and domain.
     *
     * The common name is also included as a subject alternative name.
     */
    public static function generateCsr(
        PrivateKey $privateKey,
        string $commonName,
        ?OrganizationParams $organization = null
    ): string {
        $x509 = new X509();
        $x509->setPrivateKey($privateKey);

        $x509->setDNProp('id-at-commonName', $commonName);

        if ($organization) {
            $x509->setDNProp('id-at-organizationName', $organization->name);
            $x509->setDNProp('id-at-localityName', $organization->city);
            $x509->setDNProp('id-at-stateOrProvinceName', $organization->state ?: $organization->city);
            $x509->setDNProp('id-at-countryName', strtoupper($organization->country_code));
        }

        // extensions live in the CSR's extensionRequest attribute, which
        // requires signing, re-loading and re-signing the CSR
        $csr = $x509->signCSR();
        $x509->loadCSR($x509->saveCSR($csr));
        $x509->setExtension('id-ce-subjectAltName', [['dNSName' => $commonName]]);

        return $x509->saveCSR($x509->signCSR());
    }
}
