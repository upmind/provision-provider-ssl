<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Sectigo;

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
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateIdentifierParams;
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateInfoResult;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoApi;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Provider;

class ProviderCheckValidationTest extends TestCase
{
    /**
     * @var SectigoApi&MockObject
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
        $this->api = $this->createMock(SectigoApi::class);

        // DataSet validation needs a booted Laravel app, so these tests disable it
        $this->provider = (new ReflectionClass(Provider::class))->newInstanceWithoutConstructor();
        $this->setProperty('configuration', Configuration::create([
            'login_name' => 'login',
            'login_password' => 'password',
        ], false));
        $this->setProperty('api', $this->api);
    }

    public function testAsksSectigoToCheckAPendingOrder(): void
    {
        $this->stubOrder(['errorCode' => '0', 'dcvStatus' => '0', 'fqdn' => 'example.com']);
        $this->api->expects($this->once())->method('resendDcvEmail')->with('1')->willReturn(['errorCode' => '0']);

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('Domain control validation check requested', $result->getMessage());
        $this->assertSame(CertificateInfoResult::STATUS_PENDING_VALIDATION, $result->status);
        $this->assertSame(CertificateInfoResult::DCV_STATUS_PENDING, $result->dcv_status);
    }

    /**
     * @dataProvider notPendingProvider
     *
     * @param mixed[] $collect
     */
    public function testFailsWhenNotAwaitingValidation(array $collect, string $domainStatus, string $message): void
    {
        $this->stubOrder($collect, $domainStatus);
        $this->api->expects($this->never())->method('resendDcvEmail');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage($message);

        $this->provider->checkValidation($this->params());
    }

    /**
     * @return mixed[]
     */
    public function notPendingProvider(): array
    {
        return [
            'issued' => [['errorCode' => '2'], 'Validated', 'already complete'],
            'validated' => [['errorCode' => '0', 'dcvStatus' => '1'], 'Validated', 'already complete'],
            'cancelled or rejected' => [['errorCode' => '-20'], 'Validated', 'not awaiting'],
            'revoked' => [['errorCode' => '-21'], 'Validated', 'not awaiting'],
        ];
    }

    public function testSectigoErrorFails(): void
    {
        $this->stubOrder(['errorCode' => '0', 'dcvStatus' => '0', 'fqdn' => 'example.com']);
        $this->api->method('resendDcvEmail')->willThrowException(
            (new ProvisionFunctionError('Provider API error -160: The attempt to resend DCV emails did not succeed.'))
                ->withData(['error_code' => -160])
        );

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('error -160');

        $this->provider->checkValidation($this->params());
    }

    /**
     * @param mixed[] $collect CollectSSL response
     */
    private function stubOrder(array $collect, string $domainStatus = 'Awaiting Validation'): void
    {
        $this->api->method('collectSsl')->willReturn($collect);
        $this->api->method('getMdcDomainDetails')->willReturn([
            'errorCode' => '0',
            '1_domainName' => 'example.com',
            '1_dcvMethod' => 'CNAME_CSR_HASH',
            '1_dcvStatus' => $domainStatus,
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
