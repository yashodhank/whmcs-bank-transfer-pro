<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\ReceiveProfileValidator;
use BankTransferPro\Support\WhmcsInput;
use PHPUnit\Framework\TestCase;

final class WhmcsInputTest extends TestCase
{
    public function testDecodesEntityEncodedRequestValuesRecursively(): void
    {
        $decoded = WhmcsInput::decode([
            'a' => '{&quot;ifsc&quot;:&quot;X&amp;Y&quot;}',
            'b' => 'O&#039;Brien &lt;b&gt;',
            'nested' => ['c' => 'Tom &amp; Jerry'],
            'n' => 5,
        ]);

        $this->assertSame('{"ifsc":"X&Y"}', $decoded['a']);
        $this->assertSame("O'Brien <b>", $decoded['b']);
        $this->assertSame('Tom & Jerry', $decoded['nested']['c']);
        $this->assertSame(5, $decoded['n']);
    }

    public function testWhmcsEncodedIdentifierJsonValidatesOnceDecoded(): void
    {
        $encoded = [
            'bank_name' => 'IDBI Bank',
            'currency_code' => 'INR',
            'country_code' => 'IN',
            'account_name' => 'SECURIACE TECHNOLOGIES',
            'account_number' => '500102000004909',
            'capabilities' => 'local_transfer',
            'identifiers' => '{&quot;ifsc&quot;:&quot;IBKL0000500&quot;}',
        ];

        $raw = ReceiveProfileValidator::validate($encoded);
        $this->assertContains('IFSC is required for local transfers in India.', $raw['errors']);

        $fixed = ReceiveProfileValidator::validate(WhmcsInput::decode($encoded));
        $this->assertSame([], $fixed['errors']);
        $this->assertSame('IBKL0000500', $fixed['profile']['identifiers']['ifsc']);
    }
}
