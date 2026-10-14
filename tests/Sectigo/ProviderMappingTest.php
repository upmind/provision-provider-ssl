<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Sectigo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Provider;

class ProviderMappingTest extends TestCase
{
    /**
     * @dataProvider daysProvider
     */
    public function testMapDays(int $validityDays, int $expected): void
    {
        $this->assertSame($expected, $this->call('mapDays', $validityDays));
    }

    /**
     * @return mixed[]
     */
    public function daysProvider(): array
    {
        return [
            'february month' => [28, 30],
            'one month' => [30, 30],
            'long month' => [31, 30],
            'month upper tolerance' => [33, 30],
            'quarter lower tolerance' => [81, 90],
            'three months' => [90, 90],
            'quarter upper tolerance' => [99, 90],
            'year lower tolerance' => [329, 365],
            'twelve 30-day months' => [360, 365],
            'fifty-two weeks' => [364, 365],
            'one year' => [365, 365],
            'leap year' => [366, 365],
            'year upper tolerance' => [401, 365],
            'two years' => [720, 730],
            'three years' => [1080, 1095],
            'four years' => [1440, 1461],
            'five years' => [1800, 1826],
            'five years upper tolerance' => [2008, 1826],
        ];
    }

    /**
     * @dataProvider unsupportedDaysProvider
     */
    public function testMapDaysRejectsUnsupportedTerm(int $validityDays): void
    {
        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('Unsupported certificate term');

        $this->call('mapDays', $validityDays);
    }

    /**
     * @return mixed[]
     */
    public function unsupportedDaysProvider(): array
    {
        return [
            'one day' => [1],
            'below month tolerance' => [26],
            'month and quarter' => [34],
            'bi-monthly' => [60],
            'below quarter tolerance' => [80],
            'above quarter tolerance' => [100],
            'semi-annual' => [180],
            'below year tolerance' => [328],
            'above year tolerance' => [402],
            'over five years' => [2009],
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
