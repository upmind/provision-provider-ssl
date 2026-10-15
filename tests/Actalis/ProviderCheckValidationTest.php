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
use ReflectionProperty;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Helper\ActalisApi;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Provider;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateIdentifierParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult;

class ProviderCheckValidationTest extends TestCase
{
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

    public function testReportsThePendingStatusWithoutChangingDcv(): void
    {
        $this->stubRequest(ActalisApi::REQUEST_STATUS_DCV);
        $this->api->expects($this->never())->method('changeDcv');

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('The certificate authority checks domain control validation automatically', $result->getMessage());
        $this->assertSame(CertificateInfoResult::STATUS_PENDING_VALIDATION, $result->status);
        $this->assertSame(CertificateInfoResult::DCV_STATUS_PENDING, $result->dcv_status);
    }

    /**
     * @dataProvider completeProvider
     */
    public function testAlreadyCompleteSucceedsWithTheCurrentStatus(string $requestStatus, string $status): void
    {
        $this->stubRequest($requestStatus);

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('Domain control validation is already complete', $result->getMessage());
        $this->assertSame($status, $result->status);
        $this->assertSame(CertificateInfoResult::DCV_STATUS_COMPLETED, $result->dcv_status);
    }

    /**
     * @return string[][]
     */
    public function completeProvider(): array
    {
        return [
            'vetting' => [ActalisApi::REQUEST_STATUS_TBV, CertificateInfoResult::STATUS_PROCESSING],
            'issued' => [ActalisApi::REQUEST_STATUS_ISS, CertificateInfoResult::STATUS_ISSUED],
        ];
    }

    public function testRejectedRequestSucceedsWithTheCurrentStatus(): void
    {
        $this->stubRequest(ActalisApi::REQUEST_STATUS_REJ);

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('No domain control validation to check: the certificate order is in an error state', $result->getMessage());
        $this->assertSame(CertificateInfoResult::STATUS_ERROR, $result->status);
    }

    public function testRevokedCertificateSucceedsWithTheCurrentStatus(): void
    {
        $this->stubRequest(ActalisApi::REQUEST_STATUS_ISS, '200');
        $this->api->method('certInfo')->willReturn(['certStatus' => ActalisApi::CERT_STATUS_REV]);

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('No domain control validation to check: the certificate order is revoked', $result->getMessage());
        $this->assertSame(CertificateInfoResult::STATUS_REVOKED, $result->status);
    }

    private function stubRequest(string $requestStatus, string $certificateId = ''): void
    {
        $this->api->method('retrieve')->willReturn([
            'certStatus' => $requestStatus,
            'commonName' => 'example.com',
            'domainsMap' => ['example.com' => 'dns-change'],
            'certificateId' => $certificateId,
        ]);
    }

    private function params(): CertificateIdentifierParams
    {
        return CertificateIdentifierParams::create(['order_id' => '1'], false);
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
