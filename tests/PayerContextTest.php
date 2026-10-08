<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\PayerContext;
use PHPUnit\Framework\TestCase;

final class PayerContextTest extends TestCase
{
    public function testReadsCountryFromWhmcsClientDetails(): void
    {
        $this->assertSame('IN', PayerContext::fromClientDetails(['country' => 'in'])->countryCode);
        $this->assertSame('US', PayerContext::fromClientDetails(['country' => 'United States', 'countrycode' => 'US'])->countryCode);
        $this->assertNull(PayerContext::fromClientDetails([])->countryCode);
    }

    public function testDetectsMobileUserAgents(): void
    {
        $this->assertTrue(PayerContext::isMobileUserAgent('Mozilla/5.0 (Linux; Android 14) Mobile Safari'));
        $this->assertTrue(PayerContext::isMobileUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'));
        $this->assertFalse(PayerContext::isMobileUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/120'));
        $this->assertFalse(PayerContext::isMobileUserAgent(null));
    }

    public function testDomesticMatching(): void
    {
        $this->assertTrue((new PayerContext('IN'))->isDomesticTo('IN'));
        $this->assertFalse((new PayerContext('US'))->isDomesticTo('IN'));
        $this->assertTrue((new PayerContext(null))->isDomesticTo('IN'));
        $this->assertTrue((new PayerContext('US'))->isDomesticTo(''));
    }
}
