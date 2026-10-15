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
     * @dataProvider completeProvider
     *
     * @param mixed[] $collect
     */
    public function testAlreadyCompleteSucceedsWithTheCurrentStatus(array $collect, string $status): void
    {
        $this->stubOrder($collect, 'Validated');
        $this->api->expects($this->never())->method('resendDcvEmail');

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('Domain control validation is already complete', $result->getMessage());
        $this->assertSame($status, $result->status);
        $this->assertSame(CertificateInfoResult::DCV_STATUS_COMPLETED, $result->dcv_status);
    }

    /**
     * @return mixed[]
     */
    public function completeProvider(): array
    {
        return [
            'validated' => [['errorCode' => '0', 'dcvStatus' => '1'], CertificateInfoResult::STATUS_PROCESSING],
            'issued' => [['errorCode' => '2'], CertificateInfoResult::STATUS_ISSUED],
        ];
    }

    /**
     * @dataProvider endedProvider
     *
     * @param mixed[] $collect
     */
    public function testEndedOrderSucceedsWithTheCurrentStatus(array $collect, string $status, string $message): void
    {
        $this->stubOrder($collect, 'Validated');
        $this->api->expects($this->never())->method('resendDcvEmail');

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame($message, $result->getMessage());
        $this->assertSame($status, $result->status);
    }

    /**
     * @return mixed[]
     */
    public function endedProvider(): array
    {
        return [
            'cancelled or rejected' => [
                ['errorCode' => '-20', 'dcvStatus' => '1'],
                CertificateInfoResult::STATUS_CANCELLED,
                'No domain control validation to check: the certificate order is cancelled',
            ],
            'revoked' => [
                ['errorCode' => '-21', 'dcvStatus' => '1'],
                CertificateInfoResult::STATUS_REVOKED,
                'No domain control validation to check: the certificate order is revoked',
            ],
        ];
    }

    public function testValidationCompletedDuringTheCallSucceeds(): void
    {
        $this->api->method('collectSsl')->willReturnOnConsecutiveCalls(
            ['errorCode' => '0', 'dcvStatus' => '0', 'fqdn' => 'example.com'],
            ['errorCode' => '2', 'fqdn' => 'example.com']
        );
        $this->api->method('getMdcDomainDetails')->willReturn(['errorCode' => '0']);
        $this->api->method('resendDcvEmail')->willThrowException($this->apiError(-160));

        $result = $this->provider->checkValidation($this->params());
        $result->autoValidation(false);

        $this->assertSame('Domain control validation is already complete', $result->getMessage());
        $this->assertSame(CertificateInfoResult::STATUS_ISSUED, $result->status);
    }

    public function testSectigoErrorFails(): void
    {
        $this->stubOrder(['errorCode' => '0', 'dcvStatus' => '0', 'fqdn' => 'example.com']);
        $this->api->method('resendDcvEmail')->willThrowException($this->apiError(-160));

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

    private function apiError(int $code): ProvisionFunctionError
    {
        return (new ProvisionFunctionError(sprintf('Provider API error %d: message', $code)))
            ->withData(['error_code' => $code]);
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
