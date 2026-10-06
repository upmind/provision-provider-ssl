<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Actalis;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Helper\ActalisApi;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Provider;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult as Info;

class ProviderMappingTest extends TestCase
{
    /**
     * @dataProvider productCodeProvider
     *
     * @param string[] $expected
     */
    public function testParseProductCode(string $code, string $commonName, ?string $type, array $expected): void
    {
        $this->assertSame($expected, $this->call('parseProductCode', $code, $commonName, $type));
    }

    /**
     * @return mixed[]
     */
    public function productCodeProvider(): array
    {
        return [
            'full pair' => ['OV:Wildcard', '*.example.com', null, ['OV', 'Wildcard']],
            'case insensitive' => ['dv:singlehost', 'example.com', null, ['DV', 'SingleHost']],
            'slash separator' => ['EV/SingleHost', 'example.com', null, ['EV', 'SingleHost']],
            'class from type' => ['Wildcard', '*.example.com', 'ov', ['OV', 'Wildcard']],
            'policy from name' => ['DV', '*.example.com', null, ['DV', 'Wildcard']],
            'class defaults to DV' => ['', 'example.com', null, ['DV', 'SingleHost']],
            'policy sent as given' => ['DV:SingleHost', '*.example.com', null, ['DV', 'SingleHost']],
        ];
    }

    public function testParseProductCodeRejectsUnknownPart(): void
    {
        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('Unrecognised product code');

        $this->call('parseProductCode', 'DV:Gold', 'example.com', null);
    }

    /**
     * @dataProvider statusProvider
     */
    public function testMapStatus(?string $request, ?string $cert, ?string $msg, string $expected): void
    {
        $this->assertSame($expected, $this->call('mapStatus', $request, $cert, $msg));
    }

    /**
     * @return mixed[]
     */
    public function statusProvider(): array
    {
        return [
            'dcv' => [ActalisApi::REQUEST_STATUS_DCV, null, null, Info::STATUS_PENDING_VALIDATION],
            'vetting' => [ActalisApi::REQUEST_STATUS_TBV, null, null, Info::STATUS_PROCESSING],
            'issued' => [ActalisApi::REQUEST_STATUS_ISS, ActalisApi::CERT_STATUS_ISS, null, Info::STATUS_ISSUED],
            'revoked wins' => [ActalisApi::REQUEST_STATUS_ISS, ActalisApi::CERT_STATUS_REV, null, Info::STATUS_REVOKED],
            'expired wins' => [ActalisApi::REQUEST_STATUS_ISS, ActalisApi::CERT_STATUS_EXP, null, Info::STATUS_EXPIRED],
            'suspended' => [ActalisApi::REQUEST_STATUS_ISS, ActalisApi::CERT_STATUS_SUS, null, Info::STATUS_ERROR],
            'error' => [ActalisApi::REQUEST_STATUS_ERR, null, null, Info::STATUS_ERROR],
            'cancelled' => [ActalisApi::REQUEST_STATUS_REJ, null, 'Request canceled by RAO operator', Info::STATUS_CANCELLED],
            'rejected' => [ActalisApi::REQUEST_STATUS_REJ, null, 'Vetting failed', Info::STATUS_ERROR],
            'unknown' => [null, null, null, Info::STATUS_PROCESSING],
        ];
    }

    /**
     * @dataProvider dcvStatusProvider
     */
    public function testMapDcvStatus(?string $request, ?string $expected): void
    {
        $this->assertSame($expected, $this->call('mapDcvStatus', $request));
    }

    /**
     * @return mixed[]
     */
    public function dcvStatusProvider(): array
    {
        return [
            'pending' => [ActalisApi::REQUEST_STATUS_DCV, Info::DCV_STATUS_PENDING],
            'completed' => [ActalisApi::REQUEST_STATUS_TBA, Info::DCV_STATUS_COMPLETED],
            'failed' => [ActalisApi::REQUEST_STATUS_REJ, Info::DCV_STATUS_FAILED],
            'unknown' => [null, null],
        ];
    }

    /**
     * @dataProvider revocationReasonProvider
     */
    public function testMapRevocationReason(?string $reason, string $expected): void
    {
        $this->assertSame($expected, $this->call('mapRevocationReason', $reason));
    }

    /**
     * @return mixed[]
     */
    public function revocationReasonProvider(): array
    {
        return [
            ['keyCompromise', 'R1'],
            ['affiliationChanged', 'R3'],
            ['superseded', 'R4'],
            ['cessationOfOperation', 'R5'],
            'default' => [null, 'R5'],
        ];
    }

    /**
     * @param mixed ...$args
     *
     * @return mixed
     */
    private function call(string $method, ...$args)
    {
        // the mapping helpers use no configuration; a DataSet needs a booted Laravel app
        $provider = (new ReflectionClass(Provider::class))->newInstanceWithoutConstructor();

        $reflection = new ReflectionMethod($provider, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($provider, ...$args);
    }
}
