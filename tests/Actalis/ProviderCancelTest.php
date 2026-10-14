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
use Upmind\ProvisionProviders\SslCertificates\Data\CancelParams;
use Upmind\ProvisionProviders\SslCertificates\Data\EmptyResult;

class ProviderCancelTest extends TestCase
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

    public function testCancelsWithoutALookup(): void
    {
        $this->api->expects($this->once())->method('cancel')->with('1')->willReturn(['errorCode' => 0]);
        $this->api->expects($this->never())->method('retrieve');

        $this->assertSame('Certificate order cancelled', $this->cancel()->getMessage());
    }

    /**
     * @dataProvider finalStateProvider
     *
     * @param mixed[] $retrieve
     */
    public function testRefusedCancelWithNothingToCancel(array $retrieve, ?string $certStatus, string $expected): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_INVALID_STATUS));
        $this->api->method('retrieve')->willReturn($retrieve);
        $this->api->method('certInfo')->willReturn(['certStatus' => $certStatus]);

        $this->assertSame($expected, $this->cancel()->getMessage());
    }

    /**
     * @return mixed[]
     */
    public function finalStateProvider(): array
    {
        $issued = ['certStatus' => ActalisApi::REQUEST_STATUS_ISS, 'certificateId' => '200'];

        return [
            'cancelled' => [
                ['certStatus' => ActalisApi::REQUEST_STATUS_REJ, 'certMsg' => 'Request canceled'],
                null,
                'Certificate order already cancelled',
            ],
            'rejected' => [
                ['certStatus' => ActalisApi::REQUEST_STATUS_REJ, 'certMsg' => 'Vetting failed'],
                null,
                'Certificate order rejected; nothing to cancel',
            ],
            'expired' => [$issued, ActalisApi::CERT_STATUS_EXP, 'Certificate expired; nothing to cancel'],
            'revoked' => [$issued, ActalisApi::CERT_STATUS_REV, 'Certificate revoked; nothing to cancel'],
            'issued' => [
                $issued,
                ActalisApi::CERT_STATUS_PUB,
                'Certificate issued and can no longer be cancelled; nothing to cancel',
            ],
        ];
    }

    public function testDroppedRequestFallsBackToTheCertificate(): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_NOT_FOUND));
        $this->api->method('retrieve')->willThrowException($this->apiError(ActalisApi::ERROR_REQUEST_NOT_FOUND));
        $this->api->expects($this->once())->method('certInfo')->with('200')
            ->willReturn(['certStatus' => ActalisApi::CERT_STATUS_EXP]);

        $this->assertSame('Certificate expired; nothing to cancel', $this->cancel('200')->getMessage());
    }

    public function testInvalidStatusOnAPendingRequestThrows(): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_INVALID_STATUS));
        $this->api->method('retrieve')->willReturn(['certStatus' => ActalisApi::REQUEST_STATUS_DCV]);

        $this->expectCancelError(ActalisApi::ERROR_CANCEL_INVALID_STATUS);
    }

    public function testUnknownRequestWithoutACertificateThrows(): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_NOT_FOUND));
        $this->api->method('retrieve')->willThrowException($this->apiError(ActalisApi::ERROR_REQUEST_NOT_FOUND));
        $this->api->expects($this->never())->method('certInfo');

        $this->expectCancelError(ActalisApi::ERROR_CANCEL_NOT_FOUND);
    }

    public function testUnknownRequestAndCertificateThrows(): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_NOT_FOUND));
        $this->api->method('retrieve')->willThrowException($this->apiError(ActalisApi::ERROR_REQUEST_NOT_FOUND));
        $this->api->method('certInfo')->willThrowException($this->apiError(40));

        $this->expectCancelError(ActalisApi::ERROR_CANCEL_NOT_FOUND, '200');
    }

    public function testFailedLookupThrowsTheCancelError(): void
    {
        $this->api->method('cancel')->willThrowException($this->apiError(ActalisApi::ERROR_CANCEL_INVALID_STATUS));
        $this->api->method('retrieve')->willThrowException(
            ProvisionFunctionError::create('Unable to connect to the provider API')
        );

        $this->expectCancelError(ActalisApi::ERROR_CANCEL_INVALID_STATUS);
    }

    /**
     * @dataProvider otherErrorProvider
     */
    public function testOtherCancelErrorsThrowWithoutALookup(ProvisionFunctionError $error, string $message): void
    {
        $this->api->method('cancel')->willThrowException($error);
        $this->api->expects($this->never())->method('retrieve');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage($message);

        $this->cancel('200');
    }

    /**
     * @return mixed[]
     */
    public function otherErrorProvider(): array
    {
        return [
            'internal error' => [$this->apiError(370), 'error 370'],
            'connection error' => [
                ProvisionFunctionError::create('Unable to connect to the provider API'),
                'Unable to connect',
            ],
        ];
    }

    private function cancel(?string $certificateId = null): EmptyResult
    {
        return $this->provider->cancel(CancelParams::create([
            'order_id' => '1',
            'certificate_id' => $certificateId,
        ], false));
    }

    private function expectCancelError(int $code, ?string $certificateId = null): void
    {
        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage(sprintf('error %d', $code));

        $this->cancel($certificateId);
    }

    private function apiError(int $code): ProvisionFunctionError
    {
        return ProvisionFunctionError::create(sprintf('Provider API error %d: message', $code))
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
