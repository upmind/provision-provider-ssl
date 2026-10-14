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
use Upmind\ProvisionProviders\SslCertificates\Data\CertificateOrderResult;
use Upmind\ProvisionProviders\SslCertificates\Data\CreateParams;
use Upmind\ProvisionProviders\SslCertificates\Data\DcvMethod;
use Upmind\ProvisionProviders\SslCertificates\Data\ReissueParams;
use Upmind\ProvisionProviders\SslCertificates\Data\RestartValidationParams;
use Upmind\ProvisionProviders\SslCertificates\Helper\KeyPairHelper;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoApi;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Helper\SectigoDcv;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Provider;

class ProviderUniqueValueTest extends TestCase
{
    private const HASH_DOMAIN = 'sectigo.com';

    private static string $csr;

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
        $this->api = $this->createMock(SectigoApi::class);

        // DataSet validation needs a booted Laravel app, so these tests disable it
        $this->provider = (new ReflectionClass(Provider::class))->newInstanceWithoutConstructor();
        $this->setProperty('configuration', Configuration::create([
            'login_name' => 'login',
            'login_password' => 'password',
            'dcv_hash_domain' => self::HASH_DOMAIN,
        ], false));
        $this->setProperty('api', $this->api);
    }

    public function testCreateSendsNewUniqueValueForReusedCsr(): void
    {
        $sent = [];
        $this->api->method('applyOrder')->willReturnCallback(function (array $request) use (&$sent) {
            $sent[] = $request['uniqueValue'];
            return ['errorCode' => '0', 'orderNumber' => (string) count($sent)];
        });

        $first = $this->result($this->provider->create($this->createParams(DcvMethod::DNS)));
        $second = $this->result($this->provider->create($this->createParams(DcvMethod::DNS)));

        $this->assertCount(2, $sent);
        $this->assertNotSame($sent[0], $sent[1]);
        foreach ($sent as $value) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{20}$/', $value);
        }

        $this->assertDnsInstructions($first, $sent[0]);
        $this->assertDnsInstructions($second, $sent[1]);
        $this->assertNotSame($first->dcv_dns_record_value, $second->dcv_dns_record_value);
    }

    public function testCreateUsesUniqueValueSectigoReturns(): void
    {
        $this->api->method('applyOrder')->willReturn([
            'errorCode' => '0',
            'orderNumber' => '1',
            'uniqueValue' => 'returned1234',
        ]);

        $result = $this->result($this->provider->create($this->createParams(DcvMethod::FILE)));

        $file = SectigoDcv::fileData(self::$csr, self::HASH_DOMAIN, 'returned1234');
        $this->assertSame($file['path'], $result->dcv_file_path);
        $this->assertSame($file['content'], $result->dcv_file_content);
    }

    public function testHashDomainDefaultsToSectigo(): void
    {
        $this->setProperty('configuration', Configuration::create([
            'login_name' => 'login',
            'login_password' => 'password',
        ], false));
        $this->api->method('applyOrder')->willReturn(['errorCode' => '0', 'orderNumber' => '1']);

        $result = $this->result($this->provider->create($this->createParams(DcvMethod::FILE)));

        // the second line of the validation file is the hash domain
        $this->assertSame('sectigo.com', explode("\n", (string) $result->dcv_file_content)[1]);
    }

    public function testCreateWithoutCsrReturnsGeneratedKeyPair(): void
    {
        $this->api->method('applyOrder')->willReturn(['errorCode' => '0', 'orderNumber' => '1']);

        $result = $this->result($this->provider->create($this->createParams(DcvMethod::DNS, null)));

        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $result->csr);
        $this->assertStringContainsString('PRIVATE KEY', (string) $result->private_key);
    }

    public function testReissueSendsNewUniqueValueForSameCsr(): void
    {
        $this->stubPendingOrder();

        $sent = [];
        $this->api->method('replaceSsl')->willReturnCallback(function (array $request) use (&$sent) {
            $this->assertSame(self::$csr, $request['csr']);
            $sent[] = $request['uniqueValue'];
            return ['errorCode' => '0'];
        });

        $first = $this->result($this->provider->reissue($this->reissueParams()));
        $second = $this->result($this->provider->reissue($this->reissueParams()));

        $this->assertCount(2, $sent);
        $this->assertNotSame($sent[0], $sent[1]);
        $this->assertDnsInstructions($first, $sent[0]);
        $this->assertDnsInstructions($second, $sent[1]);
    }

    public function testRestartValidationWithCsrReplacesWithNewUniqueValue(): void
    {
        $this->stubPendingOrder();
        $this->api->expects($this->never())->method('updateDcv');

        $sent = null;
        $this->api->expects($this->once())->method('replaceSsl')
            ->willReturnCallback(function (array $request) use (&$sent) {
                $this->assertSame('HTTP_CSR_HASH', $request['dcvMethod']);
                $sent = $request['uniqueValue'];
                return ['errorCode' => '0', 'certificateID' => '99'];
            });

        $result = $this->result($this->provider->restartValidation(RestartValidationParams::create([
            'order_id' => '1',
            'dcv_method' => DcvMethod::FILE,
            'csr' => self::$csr,
        ], false)));

        $file = SectigoDcv::fileData(self::$csr, self::HASH_DOMAIN, $sent);
        $this->assertSame(DcvMethod::FILE, $result->dcv_method);
        $this->assertSame('99', $result->certificate_id);
        $this->assertSame($file['path'], $result->dcv_file_path);
        $this->assertSame($file['content'], $result->dcv_file_content);
    }

    public function testRestartValidationWithoutCsrKeepsCurrentMethod(): void
    {
        $this->stubPendingOrder();
        $this->api->expects($this->never())->method('replaceSsl');
        $this->api->expects($this->once())->method('updateDcv')->with('1', 'CNAME_CSR_HASH');

        $result = $this->result($this->provider->restartValidation(RestartValidationParams::create([
            'order_id' => '1',
        ], false)));

        $this->assertSame(DcvMethod::DNS, $result->dcv_method);
        $this->assertNull($result->dcv_dns_record_value);
        $this->assertNull($result->dcv_file_content);
    }

    public function testRestartValidationMethodSwitchWithoutCsrFailsBeforeAnyChange(): void
    {
        $this->stubPendingOrder();
        $this->api->expects($this->never())->method('replaceSsl');
        $this->api->expects($this->never())->method('updateDcv');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('Provide the order\'s CSR');

        $this->provider->restartValidation(RestartValidationParams::create([
            'order_id' => '1',
            'dcv_method' => DcvMethod::FILE,
        ], false));
    }

    private function assertDnsInstructions(CertificateOrderResult $result, string $uniqueValue): void
    {
        $record = SectigoDcv::cnameRecord(self::$csr, 'example.com', self::HASH_DOMAIN, $uniqueValue);

        $this->assertSame($record['name'], $result->dcv_dns_record_name);
        $this->assertSame('CNAME', $result->dcv_dns_record_type);
        $this->assertSame($record['value'], $result->dcv_dns_record_value);
    }

    private function stubPendingOrder(): void
    {
        $this->api->method('collectSsl')->willReturn([
            'errorCode' => '0',
            'dcvStatus' => '0',
            'fqdn' => 'example.com',
            'certificateStatus' => 'Awaiting Validation',
        ]);
        $this->api->method('getMdcDomainDetails')->willReturn([
            'errorCode' => '0',
            '1_domainName' => 'example.com',
            '1_dcvMethod' => 'CNAME_CSR_HASH',
            '1_dcvStatus' => 'Awaiting Validation',
        ]);
    }

    private function createParams(string $dcvMethod, ?string $csr = 'given'): CreateParams
    {
        return CreateParams::create([
            'product_code' => '287',
            'certificate_type' => 'dv',
            'validity_days' => 365,
            'csr' => $csr === 'given' ? self::$csr : $csr,
            'common_name' => 'example.com',
            'dcv_method' => $dcvMethod,
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Example Customer',
        ], false);
    }

    private function reissueParams(): ReissueParams
    {
        return ReissueParams::create([
            'order_id' => '1',
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
