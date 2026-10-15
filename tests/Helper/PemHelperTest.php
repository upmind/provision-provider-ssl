<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Helper;

use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\SslCertificates\Helper\PemHelper;

class PemHelperTest extends TestCase
{
    public function testSpaceMangledKeyRoundTrips(): void
    {
        $key = EC::createKey('secp256r1');
        $mangled = $this->collapse($key->toString('PKCS8'));

        $normalized = PemHelper::normalize($mangled);

        $this->assertStringStartsWith("-----BEGIN PRIVATE KEY-----\n", $normalized);
        $this->assertSameKey($key->toString('PKCS8'), $normalized);
    }

    public function testCrlfKeyNormalizes(): void
    {
        $pem = $this->opensslKey();

        $this->assertSame($pem, PemHelper::normalize(str_replace("\n", "\r\n", $pem)));
    }

    public function testKeyWithoutWhitespaceNormalizes(): void
    {
        $pem = $this->opensslKey();

        // every line break removed: the boundaries run straight into the body
        $this->assertSame($pem, PemHelper::normalize(str_replace("\n", '', $pem)));
    }

    public function testEncryptedPkcs8KeyRoundTrips(): void
    {
        $key = EC::createKey('secp256r1')->withPassword('secret');
        $normalized = PemHelper::normalize($this->collapse($key->toString('PKCS8')));

        $this->assertStringStartsWith("-----BEGIN ENCRYPTED PRIVATE KEY-----\n", $normalized);
        $this->assertNotFalse(openssl_pkey_get_private($normalized, 'secret'));
    }

    public function testCertificateChainKeepsEveryBlockInOrder(): void
    {
        $chain = $this->selfSignedCertificate('leaf.example.com') . $this->selfSignedCertificate('ca.example.com');

        $normalized = PemHelper::normalize($this->collapse($chain));

        $this->assertSame($chain, $normalized);
        $this->assertSame(2, substr_count($normalized, '-----BEGIN CERTIFICATE-----'));
    }

    public function testWellFormedValueIsUnchanged(): void
    {
        $pem = $this->opensslKey();

        $this->assertSame($pem, PemHelper::normalize($pem));
        $this->assertSame($pem, PemHelper::normalize(PemHelper::normalize($pem)));
    }

    public function testWellFormedLegacyEncryptedKeyIsKept(): void
    {
        $pem = EC::createKey('secp256r1')->withPassword('secret')->toString('PKCS1');

        $normalized = PemHelper::normalize($pem);

        $this->assertStringContainsString("Proc-Type: 4,ENCRYPTED\n", $normalized);
        $this->assertNotFalse(openssl_pkey_get_private($normalized, 'secret'));
    }

    public function testMangledLegacyEncryptedKeyFails(): void
    {
        $pem = EC::createKey('secp256r1')->withPassword('secret')->toString('PKCS1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PKCS#8');

        PemHelper::normalize($this->collapse($pem));
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidValueFails(string $pem, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PemHelper::normalize($pem);
    }

    /**
     * @return string[][]
     */
    public function invalidProvider(): array
    {
        return [
            'no block' => ['MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA', 'No PEM block found'],
            'unsupported type' => [
                "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n",
                'Unsupported PEM block type: PUBLIC KEY',
            ],
            'invalid base64' => [
                "-----BEGIN PRIVATE KEY----- not*base64 -----END PRIVATE KEY-----",
                'not valid base64',
            ],
        ];
    }

    /**
     * Collapse every line break to a space, as a single-line form field does.
     */
    private function collapse(string $pem): string
    {
        return trim((string) preg_replace('/\r?\n/', ' ', $pem));
    }

    private function assertSameKey(string $expected, string $actual): void
    {
        $expectedKey = openssl_pkey_get_private($expected);
        $actualKey = openssl_pkey_get_private($actual);

        $this->assertNotFalse($expectedKey);
        $this->assertNotFalse($actualKey);
        $this->assertSame(openssl_pkey_get_details($expectedKey), openssl_pkey_get_details($actualKey));
    }

    private function opensslKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private function selfSignedCertificate(string $commonName): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']), $pem);

        return $pem;
    }
}
