<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

final class CsrfUxContractTest extends TestCase
{
    public function testSettingsSaveUsesAdminCsrfNamespace(): void
    {
        $source = $this->readModule('lib/Admin/DashboardController.php');

        $this->assertStringContainsString("check_token('WHMCS.admin.default'", $source);
        $this->assertStringNotContainsString("check_token('WHMCS.default'", $source);
    }

    public function testSettingsSaveUsesPostRedirectGet(): void
    {
        $source = $this->readModule('lib/Admin/DashboardController.php');
        $info = $this->readModule('templates/admin/info.tpl');

        $this->assertStringContainsString('saved=1', $source);
        $this->assertStringContainsString('btp-admin-scope', $source);
        $this->assertTrue(
            str_contains($info, 'saved') || str_contains($info, 'settingsSaved') || str_contains($info, 'alert-success'),
            'Info tab must render the PRG success alert inside the addon template.'
        );
    }

    public function testAdminMutationsAreFormEncodedWithPostToken(): void
    {
        $script = $this->readModule('assets/js/admin.js');

        $this->assertStringNotContainsString("'Content-Type': 'application/json'", $script);
        $this->assertTrue(
            str_contains($script, 'application/x-www-form-urlencoded')
            || str_contains($script, 'URLSearchParams')
            || str_contains($script, 'FormData'),
            'Admin mutations must POST form-encoded bodies so $_POST[token] is populated.'
        );
        $this->assertStringContainsString('token', $script);
    }

    public function testListAndGetDoNotRequireCsrf(): void
    {
        $source = $this->readModule('lib/Admin/AjaxController.php');

        $this->assertDoesNotMatchRegularExpression(
            '/assertAdminAccess\(\);\s*\$this->assertCsrf\(\);/s',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/\$action[\s\S]{0,400}assertCsrf\(\)/',
            $source
        );
    }

    public function testAjaxCsrfFailureReturnsJsonNotHtml(): void
    {
        $body = $this->methodBody($this->readModule('lib/Admin/AjaxController.php'), 'assertCsrf');

        $this->assertStringContainsString("JsonResponse::error('CSRF_FAILED'", $body);
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?(?:Throwable|WHMCS\\\\Exception\\\\ProgramExit)/',
            $body
        );
    }

    public function testLangFileIsFlatAddonLangKeys(): void
    {
        $lang = $this->readModule('lang/english.php');

        $this->assertDoesNotMatchRegularExpression(
            '/\$_ADDONLANG\[[^\]]+\]\s*=\s*\[/',
            $lang
        );
        $this->assertMatchesRegularExpression(
            '/\$_ADDONLANG\[\'[a-z0-9_]+\'\]\s*=\s*\'/',
            $lang
        );
    }

    public function testAdminCssHookIsGatedToAddonModulePage(): void
    {
        $hooks = $this->readModule('hooks.php');

        $this->assertMatchesRegularExpression(
            '/AdminAreaHeadOutput[\s\S]{0,800}filename[\s\S]{0,400}addonmodules/',
            $hooks
        );
        $this->assertTrue(
            str_contains($hooks, "\$_GET['module']")
            || str_contains($hooks, "\$_REQUEST['module']"),
            'Admin CSS must also check module=banktransferpro.'
        );
    }

    public function testClientCssHookIsGatedToViewInvoice(): void
    {
        $hooks = $this->readModule('hooks.php');

        $this->assertMatchesRegularExpression(
            '/ClientAreaHeadOutput[\s\S]{0,800}filename[\s\S]{0,400}viewinvoice/',
            $hooks
        );
    }

    public function testProofHtmlIsNotConcatenatedOntoPaymentMethod(): void
    {
        $hooks = $this->readModule('hooks.php');

        $this->assertStringNotContainsString('$existing . $html', $hooks);
        $this->assertStringNotContainsString("'paymentmethod' => \$existing . \$html", $hooks);
    }

    public function testDeleteAjaxHasFailureCatch(): void
    {
        $script = $this->readModule('assets/js/admin.js');
        $deletePos = strpos($script, "classList.contains('btp-delete-btn')");
        $formPos = strpos($script, "getElementById('btp-bank-form')");

        $this->assertNotFalse($deletePos);
        $this->assertNotFalse($formPos);
        $this->assertGreaterThan($deletePos, $formPos);

        $deleteBlock = substr($script, $deletePos, $formPos - $deletePos);
        $this->assertStringContainsString('.catch', $deleteBlock);
    }

    public function testBankModalIncludesPostMethodAndHiddenToken(): void
    {
        $template = $this->readModule('templates/admin/dashboard.tpl');

        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="btp-bank-form"[^>]*method="post"/',
            $template
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="token"/',
            $template
        );
    }

    public function testProofUploadReadsInvoiceIdFromPostOnly(): void
    {
        $source = $this->readModule('lib/Client/UploadController.php');

        $this->assertStringContainsString("\$_POST['invoiceid']", $source);
        $this->assertStringNotContainsString("\$_GET['invoiceid']", $source);
    }

    public function testProofFormParsesResponseTextThenJson(): void
    {
        $hooks = $this->readModule('hooks.php');

        $this->assertStringContainsString('response.text()', $hooks);
        $this->assertStringNotContainsString('return response.json();', $hooks);
    }

    public function testUploadCsrfFailureIsCaughtAsJson(): void
    {
        $body = $this->methodBody($this->readModule('lib/Client/UploadController.php'), 'assertCsrf');

        $this->assertStringContainsString("check_token('WHMCS.default'", $body);
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?(?:Throwable|WHMCS\\\\Exception\\\\ProgramExit)/',
            $body
        );
    }

    private function readModule(string $relativePath): string
    {
        $path = dirname(__DIR__) . '/modules/addons/banktransferpro/' . $relativePath;
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }

    private function methodBody(string $source, string $method): string
    {
        if (! preg_match('/function ' . preg_quote($method, '/') . '\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail('Method ' . $method . ' was not found.');
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $rest = substr($source, $start);
        if (! preg_match('/\n    (?:private|public|protected) function/', $rest, $end, PREG_OFFSET_CAPTURE)) {
            return $rest;
        }

        return substr($rest, 0, (int) $end[0][1]);
    }
}
