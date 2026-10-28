<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Instructions-only BTP must not declare gatewayType Bank or persist GATEWAY_BANK,
 * or WHMCS ProcessCreditCardPayments writes false "No Pay Method Available" history.
 */
final class GatewayTypeContractTest extends TestCase
{
    private function read(string $absolute): string
    {
        $contents = file_get_contents($absolute);
        $this->assertIsString($contents);

        return $contents;
    }

    public function testStaticGatewayMetaDataOmitsGatewayTypeBank(): void
    {
        $source = $this->read(dirname(__DIR__) . '/modules/gateways/banktransferpro.php');

        $this->assertStringNotContainsString("'gatewayType' => 'Bank'", $source);
        $this->assertStringNotContainsString('"gatewayType" => "Bank"', $source);
        $this->assertStringContainsString('function banktransferpro_MetaData', $source);
        $this->assertStringContainsString("function banktransferpro_link", $source);
        $this->assertStringNotContainsString('function banktransferpro_capture', $source);
    }

    public function testGatewayStubOmitsGatewayTypeBank(): void
    {
        $source = $this->read(
            dirname(__DIR__) . '/modules/addons/banktransferpro/resources/gateway.stub'
        );

        $this->assertStringNotContainsString("'gatewayType' => 'Bank'", $source);
        $this->assertStringContainsString('function {{SLUG}}_link', $source);
        $this->assertStringNotContainsString('function {{SLUG}}_capture', $source);
    }

    public function testActivatorPersistsThirdPartyTypeNotBank(): void
    {
        $source = $this->read(
            dirname(__DIR__) . '/modules/addons/banktransferpro/lib/Gateway/GatewayActivator.php'
        );

        $this->assertStringContainsString('ensureManualGatewayType', $source);
        $this->assertStringContainsString('GATEWAY_THIRD_PARTY', $source);
        $this->assertStringContainsString("'Invoices'", $source);
        $this->assertStringNotContainsString('GATEWAY_BANK', $source);
    }

    public function testHooksRegisterPreCronJobRepair(): void
    {
        $source = $this->read(dirname(__DIR__) . '/modules/addons/banktransferpro/hooks.php');

        $this->assertStringContainsString("add_hook('PreCronJob'", $source);
        $this->assertStringContainsString('CaptureAttemptRepair', $source);
    }

    public function testAddonActivateRunsCaptureAttemptRepair(): void
    {
        $source = $this->read(
            dirname(__DIR__) . '/modules/addons/banktransferpro/banktransferpro.php'
        );

        $this->assertStringContainsString('CaptureAttemptRepair', $source);
        $this->assertMatchesRegularExpression(
            "/\\(new CaptureAttemptRepair\\(\\)\\)->run\\(\\)/",
            $source
        );
    }
}
