<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Gateway\SlugGenerator;
use PHPUnit\Framework\TestCase;

final class SlugGeneratorTest extends TestCase
{
    public function testGeneratesPrefixedSlug(): void
    {
        $generator = new SlugGenerator();
        $slug = $generator->generate('Ascension Holdings', 'Madrid Branch', 'EUR');

        $this->assertStringStartsWith('banktransferpro_', $slug);
        $this->assertTrue(SlugGenerator::isValidGatewayName($slug));
        $this->assertStringContainsString('eur', $slug);
    }

    public function testSuffixesOnCollision(): void
    {
        $generator = new SlugGenerator();
        $first = $generator->generate('Acme Bank', 'Main', 'USD');
        $second = $generator->generate('Acme Bank', 'Main', 'USD', [$first]);

        $this->assertNotSame($first, $second);
        $this->assertTrue(SlugGenerator::isValidGatewayName($second));
    }

    public function testRejectsInvalidGatewayNames(): void
    {
        $this->assertFalse(SlugGenerator::isValidGatewayName('bad slug!'));
        $this->assertFalse(SlugGenerator::isValidGatewayName(''));
    }
}
