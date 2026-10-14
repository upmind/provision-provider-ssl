<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Helper;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;

class UtilsTest extends TestCase
{
    /**
     * @dataProvider termProvider
     */
    public function testMatchTerm(int $validityDays, ?int $expected): void
    {
        $this->assertSame($expected, Utils::matchTerm($validityDays, [90, 365]));
    }

    /**
     * @return mixed[]
     */
    public function termProvider(): array
    {
        return [
            'below lower tolerance' => [80, null],
            'lower tolerance' => [81, 90],
            'exact' => [90, 90],
            'upper tolerance' => [99, 90],
            'between terms' => [100, null],
            'second term' => [360, 365],
            'past longest term' => [402, null],
        ];
    }

    /**
     * @dataProvider nameProvider
     *
     * @param string[] $expected
     */
    public function testSplitName(string $name, array $expected): void
    {
        $this->assertSame($expected, Utils::splitName($name));
    }

    /**
     * @return mixed[]
     */
    public function nameProvider(): array
    {
        return [
            'two words' => ['Jane Smith', ['Jane', 'Smith']],
            'multi-word surname' => ['Jan van der Berg', ['Jan', 'van der Berg']],
            'single word' => ['Madonna', ['Madonna', 'Madonna']],
            'extra whitespace' => ["  Jane \t Smith  ", ['Jane', 'Smith']],
        ];
    }
}
