<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Actalis;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Helper\ActalisApi;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Provider;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult as Info;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateOrderResult;
use Upmind\ProvisionProviders\SslCertificates\Data\ReissueParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RevokeParams;
use Upmind\ProvisionProviders\SslCertificates\Helper\KeyPairHelper;

class ProviderReissueTest extends TestCase
{
    private const SUPERSEDED_CERTIFICATE_ID = '100';
    private const LIVE_CERTIFICATE_ID = '200';

    private static string $csr;

    /**
     * @var ActalisApi&MockObject
     */
    private $api;

    private Provider $provider;

    public static function setUpBeforeClass(): void
    {
        // DataSets parse their rules through the Validator facade; without a booted app, bind one
        $app = new Container();
        $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
        Facade::setFacadeApplication($app);

        $key = KeyPairHelper::generatePrivateKey(KeyPairHelper::KEY_TYPE_ECDSA);
        self::$csr = KeyPairHelper::generateCsr($key, 'example.com');
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    protected function setUp(): void
    {
        $this->api = $this->createMock(ActalisApi::class);

        // DataSet validation needs a booted Laravel app, so these tests disable it
        $this->provider = (new ReflectionClass(Provider::class))->newInstanceWithoutConstructor();
        $this->setProperty('configuration', Configuration::create(['partner_name' => 'partner'], false));
        $this->setProperty('api', $this->api);
    }

    public function testReissueRekeysTheOrdersLiveCertificate(): void
    {
        $this->stubRequests(ActalisApi::REQUEST_STATUS_DCV);

        $this->api->expects($this->once())->method('request')
            ->willReturnCallback(function (array $params) {
                $this->assertSame(self::LIVE_CERTIFICATE_ID, $params['certificateId']);
                $this->assertSame('true', $params['isRekeying']);
                return ['requestId' => '2', 'confirmationValue' => ['token']];
            });

        $this->provider->reissue($this->reissueParams());
    }

    public function testReissueReturnsInstructionsForANewConfirmationValue(): void
    {
        $this->stubRequests(ActalisApi::REQUEST_STATUS_DCV);
        $this->api->method('request')->willReturn(['requestId' => '2', 'confirmationValue' => ['token']]);

        $result = $this->result($this->provider->reissue($this->reissueParams()));

        $this->assertSame('2', $result->order_id);
        $this->assertSame(Info::STATUS_PENDING_VALIDATION, $result->status);
        $this->assertSame(Info::DCV_STATUS_PENDING, $result->dcv_status);
        $this->assertSame('example.com', $result->dcv_dns_record_name);
        $this->assertSame('TXT', $result->dcv_dns_record_type);
        $this->assertSame('actalis-dcv=token', $result->dcv_dns_record_value);
    }

    public function testReissueWithoutConfirmationValueReportsValidationComplete(): void
    {
        // Actalis accepts the existing validation but still reports DCV on the new request at first
        $this->stubRequests(ActalisApi::REQUEST_STATUS_DCV);
        $this->api->method('request')->willReturn(['requestId' => '2']);

        $result = $this->result($this->provider->reissue($this->reissueParams()));

        $this->assertSame(Info::STATUS_PROCESSING, $result->status);
        $this->assertSame(Info::DCV_STATUS_COMPLETED, $result->dcv_status);
        $this->assertNull($result->dcv_dns_record_value);
        $this->assertNull($result->dcv_file_content);
    }

    public function testReissueResultOmitsTheSupersededCertificatesDetails(): void
    {
        $this->stubRequests(ActalisApi::REQUEST_STATUS_DCV);
        $this->api->method('request')->willReturn(['requestId' => '2', 'confirmationValue' => ['token']]);

        $result = $this->result($this->provider->reissue($this->reissueParams()));

        $this->assertNull($result->not_before);
        $this->assertNull($result->not_after);
        $this->assertNull($result->serial_number);
    }

    public function testRevokeUsesTheOrdersLiveCertificate(): void
    {
        $this->stubRequests(ActalisApi::REQUEST_STATUS_ISS);
        $this->api->expects($this->once())->method('changeStatus')
            ->with($this->callback(function (array $query) {
                return $query['certificateId'] === self::LIVE_CERTIFICATE_ID && $query['operation'] === 'REV';
            }))
            ->willReturn([]);

        $this->provider->revoke(RevokeParams::create([
            'order_id' => '1',
            'certificate_id' => self::SUPERSEDED_CERTIFICATE_ID,
        ], false));
    }

    /**
     * @dataProvider currentCertificateIdProvider
     *
     * @param mixed[] $retrieve
     * @param string|int|null $storedId
     */
    public function testCurrentCertificateId(array $retrieve, $storedId, ?string $expected): void
    {
        $method = new ReflectionMethod($this->provider, 'currentCertificateId');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->provider, $retrieve, $storedId));
    }

    /**
     * @return mixed[]
     */
    public function currentCertificateIdProvider(): array
    {
        return [
            'live id wins over stored id' => [['certificateId' => '200'], '100', '200'],
            'stored id when not issued yet' => [['certificateId' => ''], '100', '100'],
            'integer stored id' => [[], 100, '100'],
            'neither' => [['certificateId' => ''], null, null],
        ];
    }

    public function testPendingRequestErrorHint(): void
    {
        $api = (new ReflectionClass(ActalisApi::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($api, 'errorMessage');
        $method->setAccessible(true);

        $message = $method->invoke($api, 510, ['msg' => 'Certificate ID or CSR found in another pending request.']);

        $this->assertStringContainsString('still in progress', $message);
        $this->assertStringNotContainsString('new key pair', $message);
    }

    /**
     * Request 1 holds the live certificate; request 2 is the reissue, in the given state.
     */
    private function stubRequests(string $reissueStatus): void
    {
        $this->api->method('retrieve')->willReturnCallback(function (array $query) use ($reissueStatus) {
            $request = [
                'commonName' => 'example.com',
                'domainsMap' => ['example.com' => 'dns-change'],
                'certificateId' => self::LIVE_CERTIFICATE_ID,
            ];

            return $request + [
                'certStatus' => $query['requestId'] === '2' ? $reissueStatus : ActalisApi::REQUEST_STATUS_ISS,
            ];
        });
        $this->api->method('certInfo')->willReturn([
            'certStatus' => ActalisApi::CERT_STATUS_ISS,
            'certClass' => 'DV',
            'notBefore' => '2026-01-01T00:00:00Z',
            'notAfter' => '2026-07-01T00:00:00Z',
            'serialNumber' => 'abc123',
        ]);
    }

    private function reissueParams(): ReissueParams
    {
        return ReissueParams::create([
            'order_id' => '1',
            'certificate_id' => self::SUPERSEDED_CERTIFICATE_ID,
            'csr' => self::$csr,
            'customer_email' => 'customer@example.com',
        ], false);
    }

    private function result(CertificateOrderResult $result): CertificateOrderResult
    {
        $result->autoValidation(false);

        return $result;
    }

    /**
     * @param mixed $value
     */
    private function setProperty(string $name, $value): void
    {
        $property = new ReflectionProperty(Provider::class, $name);
        $property->setAccessible(true);
        $property->setValue($this->provider, $value);
    }
}
