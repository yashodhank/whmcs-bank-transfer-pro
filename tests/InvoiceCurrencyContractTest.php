<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

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
        $this->assertStringContainsString("\$params['currency']", $resolver);
        $this->assertStringNotContainsString("first(['currency'])", $renderer);
        $this->assertStringNotContainsString('WHMCS\\Database\\Capsule', $renderer);
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

    public function testUploadControllerResolvesBankFromClientCurrency(): void
    {
        $source = $this->readModule('lib/Client/UploadController.php');

        $this->assertStringContainsString('codeFromInvoiceRecord', $source);
        $this->assertStringNotContainsString('$invoice->currency', $source);
    }

    public function testInvoiceCreationHookDoesNotReadTblinvoicesCurrency(): void
    {
        $hooks = $this->readModule('hooks.php');
        $invoiceCreation = $this->hookBody($hooks, 'InvoiceCreation');

        $this->assertStringNotContainsString('$invoice->currency', $invoiceCreation);
        $this->assertDoesNotMatchRegularExpression(
            "/table\(\s*['\"]tblinvoices['\"]\s*\)[\s\S]{0,400}['\"]currency['\"]/",
            $invoiceCreation
        );
        $this->assertStringContainsString('codeFromHookVars', $invoiceCreation);
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
            '/add_hook\(\s*\'' . preg_quote($hookName, '/') . '\'[\s\S]*?function\s*\([^)]*\)\s*:\s*array\s*\{/',
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
}
