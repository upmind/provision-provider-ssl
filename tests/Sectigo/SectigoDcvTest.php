<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Sectigo;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\SslCertificates\Helper\KeyPairHelper;
use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoDcv;

class SectigoDcvTest extends TestCase
{
    private static string $csr;
    private static string $md5;
    private static string $sha256;

    public static function setUpBeforeClass(): void
    {
        $key = KeyPairHelper::generatePrivateKey(KeyPairHelper::KEY_TYPE_ECDSA);
        self::$csr = KeyPairHelper::generateCsr($key, '*.example.com');
        $der = Utils::pemToDer(self::$csr);
        self::$md5 = md5($der);
        self::$sha256 = hash('sha256', $der);
    }

    public function testFileData(): void
    {
        $file = SectigoDcv::fileData(self::$csr, 'comodoca.com', 'abc123');

        $this->assertSame('/.well-known/pki-validation/' . strtoupper(self::$md5) . '.txt', $file['path']);
        $this->assertSame(self::$sha256 . "\ncomodoca.com\nabc123", $file['content']);
    }

    public function testFileDataWithoutUniqueValue(): void
    {
        $this->assertSame(
            self::$sha256 . "\ncomodoca.com",
            SectigoDcv::fileData(self::$csr, 'comodoca.com', null)['content']
        );
    }

    public function testCnameRecordStripsWildcard(): void
    {
        $record = SectigoDcv::cnameRecord(self::$csr, '*.Example.com', 'sectigo.com', 'abc123');

        $this->assertSame('_' . self::$md5 . '.example.com', $record['name']);
        $this->assertSame('CNAME', $record['type']);
        $this->assertSame(
            substr(self::$sha256, 0, 32) . '.' . substr(self::$sha256, 32) . '.abc123.sectigo.com.',
            $record['value']
        );
    }

    public function testCnameRecordWithoutUniqueValue(): void
    {
        $this->assertSame(
            substr(self::$sha256, 0, 32) . '.' . substr(self::$sha256, 32) . '.comodoca.com.',
            SectigoDcv::cnameRecord(self::$csr, 'example.com', 'comodoca.com', null)['value']
        );
    }
}
