<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Support\ModuleFingerprint;
use PHPUnit\Framework\TestCase;

final class InvoiceCurrencyContractTest extends TestCase
{
    public function testModuleDoesNotSelectCurrencyColumnFromTblinvoices(): void
    {
        foreach ($this->invoiceCurrencySources() as $relativePath => $source) {
            $this->assertDoesNotMatchRegularExpression(
                "/table\(\s*['\"]tblinvoices['\"]\s*\)(?:(?!table\().){0,200}first\(\s*\[\s*['\"]currency['\"]/",
                $source,
                $relativePath . ' must not SELECT currency FROM tblinvoices.'
            );
            $this->assertDoesNotMatchRegularExpression(
                "/table\(\s*['\"]tblinvoices['\"]\s*\)(?:(?!table\().){0,200}\[['\"]currency['\"]\]/",
                $source,
                $relativePath . ' must not project tblinvoices.currency.'
            );
            $this->assertStringNotContainsString(
                '$invoice->currency',
                $source,
                $relativePath . ' must not read a currency property from a tblinvoices row.'
            );
        }
    }

    public function testGatewayRendererPrefersThreeLetterParamsCurrency(): void
    {
        $renderer = $this->readModule('lib/Gateway/GatewayRenderer.php');
        $resolver = $this->readModule('lib/Support/InvoiceCurrencyResolver.php');

        $this->assertStringContainsString('codeForGatewayLink', $renderer);
        $this->assertStringContainsString('InvoiceCurrencyResolver', $renderer);
        $this->assertStringContainsString("\$params['currency']", $resolver);
        $this->assertStringNotContainsString("first(['currency'])", $renderer);
        $this->assertStringNotContainsString('WHMCS\\Database\\Capsule', $renderer);
        $this->assertStringContainsString('catch (\Throwable', $renderer);
        $this->assertStringContainsString('temporarily unavailable', $renderer);
    }

    public function testClientCurrencyFallbackUsesInvoiceUserid(): void
    {
        $resolver = $this->readLib('Support/InvoiceCurrencyResolver.php');

        $this->assertStringContainsString("table('tblinvoices')", $resolver);
        $this->assertStringContainsString("first(['userid'])", $resolver);
        $this->assertStringContainsString("table('tblclients')", $resolver);
        $this->assertStringContainsString("table('tblcurrencies')", $resolver);
        $this->assertDoesNotMatchRegularExpression(
            "/table\(\s*['\"]tblinvoices['\"]\s*\)(?:(?!table\().){0,200}first\(\s*\[\s*['\"]currency['\"]/",
            $resolver
        );
    }

    public function testCodeFromHookVarsDoesNotUseAdminUserAsClientId(): void
    {
        $resolver = $this->readLib('Support/InvoiceCurrencyResolver.php');
        $method = $this->methodBody($resolver, 'codeFromHookVars');

        $this->assertStringContainsString("\$vars['userid']", $method);
        $this->assertStringNotContainsString("\$vars['user']", $method);
    }

    public function testUploadControllerResolvesBankFromClientCurrency(): void
    {
        $source = $this->readModule('lib/Client/UploadController.php');

        $this->assertStringContainsString('codeFromInvoiceRecord', $source);
        $this->assertStringContainsString('findActiveBySlug', $source);
        $this->assertStringNotContainsString('$invoice->currency', $source);
    }

    public function testInvoiceCreationHookWritesPaymentMethodViaDbUpdate(): void
    {
        $hooks = $this->readModule('hooks.php');
        $invoiceCreation = $this->hookBody($hooks, 'InvoiceCreation');

        $this->assertStringNotContainsString('$invoice->currency', $invoiceCreation);
        $this->assertDoesNotMatchRegularExpression(
            "/table\(\s*['\"]tblinvoices['\"]\s*\)[\s\S]{0,400}['\"]currency['\"]/",
            $invoiceCreation
        );
        $this->assertStringContainsString('codeFromHookVars', $invoiceCreation);
        $this->assertStringContainsString("table('tblinvoices')", $invoiceCreation);
        $this->assertStringContainsString('update', $invoiceCreation);
        $this->assertStringContainsString("'paymentmethod'", $invoiceCreation);
        $this->assertStringContainsString('logActivity', $invoiceCreation);
        $this->assertStringNotContainsString("\$vars['paymentmethod'] =", $invoiceCreation);
        $this->assertDoesNotMatchRegularExpression('/:\s*array\s*\{/', $invoiceCreation);
    }

    public function testInvoiceCurrencyResolverFileIsPresent(): void
    {
        $path = dirname(__DIR__) . '/modules/addons/banktransferpro/lib/Support/InvoiceCurrencyResolver.php';
        $this->assertFileExists($path);
    }

    public function testModuleFingerprintEncodesCurrencyCapabilityAndVersion(): void
    {
        $this->assertSame('1.1.3', ModuleFingerprint::VERSION);
        $this->assertTrue(ModuleFingerprint::hasCapability(ModuleFingerprint::CAPABILITY_INVOICE_CURRENCY_VIA_CLIENT));
        $this->assertTrue(ModuleFingerprint::isHealthy());
        $this->assertNull(ModuleFingerprint::failureReason());

        $source = $this->readLib('Support/ModuleFingerprint.php');
        $this->assertStringContainsString('invoice_currency_via_client', $source);
        $this->assertStringContainsString("VERSION = '1.1.3'", $source);
    }

    public function testActivateRefusesUnhealthyFingerprint(): void
    {
        $source = $this->readModule('banktransferpro.php');
        $this->assertStringContainsString('ModuleFingerprint::failureReason', $source);
        $this->assertStringContainsString('Activation refused', $source);
    }

    /**
     * @return array<string, string>
     */
    private function invoiceCurrencySources(): array
    {
        $paths = [
            'lib/Gateway/GatewayRenderer.php',
            'lib/Client/UploadController.php',
            'hooks.php',
            'lib/Support/InvoiceCurrencyResolver.php',
            'lib/Support/ModuleFingerprint.php',
        ];

        $sources = [];
        foreach ($paths as $relativePath) {
            $fullPath = dirname(__DIR__) . '/modules/addons/banktransferpro/' . $relativePath;
            if (! is_file($fullPath)) {
                continue;
            }
            $sources[$relativePath] = $this->readModule($relativePath);
        }

        return $sources;
    }

    private function readModule(string $relativePath): string
    {
        $path = dirname(__DIR__) . '/modules/addons/banktransferpro/' . $relativePath;
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }

    private function readLib(string $relativePath): string
    {
        return $this->readModule('lib/' . $relativePath);
    }

    private function hookBody(string $source, string $hookName): string
    {
        if (! preg_match(
            '/add_hook\(\s*\'' . preg_quote($hookName, '/') . '\'[\s\S]*?function\s*\([^)]*\)\s*(?::\s*(?:array|void))?\s*\{/',
            $source,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail('Hook ' . $hookName . ' was not found.');
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $rest = substr($source, $start);
        if (! preg_match('/\n\}\);/', $rest, $end, PREG_OFFSET_CAPTURE)) {
            return $rest;
        }

        return substr($rest, 0, (int) $end[0][1]);
    }

    private function methodBody(string $source, string $methodName): string
    {
        if (! preg_match(
            '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/',
            $source,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail('Method ' . $methodName . ' was not found.');
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $rest = substr($source, $start);
        $depth = 1;
        $length = strlen($rest);
        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($rest, 0, $i);
                }
            }
        }

        return $rest;
    }
}
