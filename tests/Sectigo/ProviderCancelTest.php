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
use Upmind\ProvisionProviders\SslCertificates\Data\CancelParams;
use Upmind\ProvisionProviders\SslCertificates\Data\EmptyResult;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoApi;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Provider;

class ProviderCancelTest extends TestCase
{
    private const DAY = 86400;

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

    public function testRefundsWithoutALookup(): void
    {
        $this->api->expects($this->never())->method('collectSsl');
        $this->api->expects($this->once())->method('refund')->with('1', 11, [-20])->willReturn(['errorCode' => '0']);

        $this->assertSame('Certificate order cancelled and refund requested', $this->cancel()->getMessage());
    }

    /**
     * @dataProvider nothingToCancelProvider
     *
     * @param mixed[] $status
     */
    public function testRefusedRefundWithNothingToCancel(array $status, string $expected): void
    {
        $this->api->expects($this->once())->method('refund')->willThrowException($this->apiError(-18));
        $this->api->expects($this->once())->method('collectSsl')->willReturn($status);

        $this->assertSame($expected, $this->cancel()->getMessage());
    }

    /**
     * @return mixed[]
     */
    public function nothingToCancelProvider(): array
    {
        return [
            'cancelled or rejected' => [
                ['errorCode' => '-20'],
                'Certificate order already cancelled or rejected; nothing to cancel',
            ],
            'revoked' => [['errorCode' => '-21'], 'Certificate revoked; nothing to cancel'],
            'expired' => [$this->issued(-400, -35), 'Certificate expired; nothing to cancel'],
            'issued after the refund period' => [
                $this->issued(-60, 305),
                'Certificate issued and no longer refundable; nothing to cancel',
            ],
        ];
    }

    /**
     * @dataProvider refusedRefundProvider
     *
     * @param mixed[] $status
     */
    public function testRefusedRefundOtherwiseThrows(array $status): void
    {
        $this->api->method('refund')->willThrowException($this->apiError(-18));
        $this->api->method('collectSsl')->willReturn($status);

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('error -18');

        $this->cancel();
    }

    /**
     * @return mixed[]
     */
    public function refusedRefundProvider(): array
    {
        return [
            'pending validation' => [['errorCode' => '0']],
            'processing' => [['errorCode' => '-26']],
            'issued within the refund period' => [$this->issued(-10, 355)],
            'issued without dates' => [['errorCode' => '2']],
        ];
    }

    public function testRefusedRefundThrowsWhenTheStateIsUnavailable(): void
    {
        $this->api->method('refund')->willThrowException($this->apiError(-18));
        $this->api->method('collectSsl')->willThrowException($this->apiError(-14));

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('error -18');

        $this->cancel();
    }

    /**
     * @dataProvider otherErrorProvider
     */
    public function testOtherRefundErrorsThrowWithoutALookup(int $errorCode): void
    {
        $this->api->method('refund')->willThrowException($this->apiError($errorCode));
        $this->api->expects($this->never())->method('collectSsl');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage(sprintf('error %d', $errorCode));

        $this->cancel();
    }

    /**
     * @return int[][]
     */
    public function otherErrorProvider(): array
    {
        return [
            'invalid order number' => [-4],
            'unknown error' => [-14],
            'permission denied' => [-16],
        ];
    }

    public function testAlreadyCancelledOrder(): void
    {
        $this->api->method('refund')->willReturn(['errorCode' => '-20']);

        $this->assertSame('Certificate order already cancelled', $this->cancel()->getMessage());
    }

    /**
     * @return mixed[] CollectSSL data for an issued certificate, with dates in days from now
     */
    private function issued(int $notBeforeDays, int $notAfterDays): array
    {
        return [
            'errorCode' => '2',
            'notBefore' => (string) (time() + $notBeforeDays * self::DAY),
            'notAfter' => (string) (time() + $notAfterDays * self::DAY),
        ];
    }

    private function cancel(): EmptyResult
    {
        return $this->provider->cancel(CancelParams::create(['order_id' => '1'], false));
    }

    private function apiError(int $code): ProvisionFunctionError
    {
        return (new ProvisionFunctionError(sprintf('Provider API error %d: message', $code)))
            ->withData(['error_code' => $code]);
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
