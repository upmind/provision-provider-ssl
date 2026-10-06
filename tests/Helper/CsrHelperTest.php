<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Helper;

use InvalidArgumentException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\SslCertificates\Helper\CsrHelper;
use Upmind\ProvisionProviders\SslCertificates\Helper\KeyPairHelper;
use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;

class CsrHelperTest extends TestCase
{
    private static PrivateKey $key;

    public static function setUpBeforeClass(): void
    {
        self::$key = KeyPairHelper::generatePrivateKey(KeyPairHelper::KEY_TYPE_ECDSA);
    }

    /**
     * @dataProvider validProvider
     *
     * @param mixed[] $altNames
     */
    public function testAcceptsMatchingCsr(string $csrName, array $altNames, ?string $expected, string $result): void
    {
        $this->assertSame($result, CsrHelper::assertValid(self::csr($csrName, $altNames), $expected));
    }

    /**
     * @return mixed[]
     */
    public function validProvider(): array
    {
        return [
            'exact' => ['example.com', [['dNSName' => 'example.com']], 'example.com', 'example.com'],
            'www pair' => ['example.com', [['dNSName' => 'www.example.com']], 'example.com', 'example.com'],
            'apex pair' => ['www.example.com', [['dNSName' => 'example.com']], 'www.example.com', 'www.example.com'],
            'wildcard apex' => ['*.example.com', [['dNSName' => 'example.com']], '*.example.com', '*.example.com'],
            'case and dot' => ['Example.COM', [], 'example.com.', 'example.com'],
            'no expected name' => ['other.com', [], null, 'other.com'],
        ];
    }

    /**
     * @dataProvider invalidProvider
     *
     * @param mixed[] $altNames
     */
    public function testRejectsMismatchedCsr(?string $csrName, array $altNames, string $expected, string $error): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        CsrHelper::assertValid(self::csr($csrName, $altNames), $expected);
    }

    /**
     * @return mixed[]
     */
    public function invalidProvider(): array
    {
        return [
            'other domain' => ['other.com', [], 'example.com', 'does not match'],
            'wildcard for host' => ['*.example.com', [], 'example.com', 'does not match'],
            'extra domain' => ['example.com', [['dNSName' => 'shop.example.com']], 'example.com', 'additional domains'],
            'wildcard extra' => ['*.example.com', [['dNSName' => 'www.example.com']], '*.example.com', 'additional domains'],
            'ip address' => ['example.com', [['iPAddress' => '192.0.2.1']], 'example.com', 'other than domain names'],
            'no common name' => [null, [['dNSName' => 'example.com']], 'example.com', 'no common name'],
        ];
    }

    public function testRejectsGarbage(): void
    {
        $this->expectExceptionMessage('not a valid PEM-encoded');

        CsrHelper::assertValid("-----BEGIN CERTIFICATE REQUEST-----\nabc\n-----END CERTIFICATE REQUEST-----", null);
    }

    public function testRejectsBadSignature(): void
    {
        $der = Utils::pemToDer(self::csr('example.com', []));
        $der[strlen($der) - 1] = chr(ord($der[strlen($der) - 1]) ^ 0xff);
        $pem = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE REQUEST-----\n";

        $this->expectExceptionMessage('signature does not match');

        CsrHelper::assertValid($pem, 'example.com');
    }

    /**
     * @param mixed[] $altNames
     */
    private static function csr(?string $commonName, array $altNames): string
    {
        $x509 = new X509();
        $x509->setPrivateKey(self::$key);
        $commonName !== null
            ? $x509->setDNProp('id-at-commonName', $commonName)
            : $x509->setDNProp('id-at-organizationName', 'Example');

        if ($altNames) {
            $x509->loadCSR($x509->saveCSR($x509->signCSR()));
            $x509->setExtension('id-ce-subjectAltName', $altNames);
        }

        return $x509->saveCSR($x509->signCSR());
    }
}
