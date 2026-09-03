<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testSuccessEnvelopeCanBeEncoded(): void
    {
        $payload = [
            'success' => true,
            'data' => ['banks' => []],
            'message' => 'ok',
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        $this->assertTrue($decoded['success']);
        $this->assertIsArray($decoded['data']['banks']);
        $this->assertSame('ok', $decoded['message']);
    }

    public function testErrorEnvelopeCanBeEncoded(): void
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => 'DUPLICATE_BANK',
                'message' => 'Duplicate bank',
            ],
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        $this->assertFalse($decoded['success']);
        $this->assertSame('DUPLICATE_BANK', $decoded['error']['code']);
    }
}
