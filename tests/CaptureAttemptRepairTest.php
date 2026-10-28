<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Gateway\CaptureAttemptRepair;
use BankTransferPro\Gateway\GatewayActivator;
use PHPUnit\Framework\TestCase;

final class CaptureAttemptRepairTest extends TestCase
{
    public function testFalseCaptureHistoryRowRequiresBtpGatewayEmptyTransIdAndExactDescription(): void
    {
        $this->assertTrue(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => null,
            'completed' => 0,
        ]));

        $this->assertTrue(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro_acme_usd',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => 'N/A',
        ]));

        $this->assertTrue(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => '',
        ]));
    }

    public function testFalseCaptureHistoryRowRejectsOtherGatewaysAmountsAndCompletedAttempts(): void
    {
        $this->assertFalse(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'stripe',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => null,
        ]));

        $this->assertFalse(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferproxy',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => null,
        ]));

        $this->assertFalse(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro',
            'description' => 'Automatic Payment Attempt',
            'transaction_id' => null,
        ]));

        $this->assertFalse(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => 'txn_123',
        ]));

        $this->assertFalse(CaptureAttemptRepair::isFalseCaptureHistoryRow([
            'gateway' => 'banktransferpro',
            'description' => CaptureAttemptRepair::FALSE_CAPTURE_DESCRIPTION,
            'transaction_id' => null,
            'completed' => 1,
        ]));
    }

    public function testBtpGatewaySlugMatcher(): void
    {
        $this->assertTrue(CaptureAttemptRepair::isBtpGatewaySlug('banktransferpro'));
        $this->assertTrue(CaptureAttemptRepair::isBtpGatewaySlug('banktransferpro_acme_inr'));
        $this->assertFalse(CaptureAttemptRepair::isBtpGatewaySlug('banktransfer'));
        $this->assertFalse(CaptureAttemptRepair::isBtpGatewaySlug('banktransferproxy'));
    }

    public function testManualGatewayTypeFallsBackToInvoicesWithoutWhmcsConstant(): void
    {
        $this->assertSame('Invoices', GatewayActivator::manualGatewayType());
    }
}
