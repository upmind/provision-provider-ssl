<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Helper;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\SslCertificates\Helper\Utils;

class UtilsTest extends TestCase
{
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
