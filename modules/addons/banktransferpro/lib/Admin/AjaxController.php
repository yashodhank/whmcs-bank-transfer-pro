<?php

declare(strict_types=1);

namespace BankTransferPro\Admin;

use BankTransferPro\Gateway\GatewayActivator;
use BankTransferPro\Gateway\GatewayFileWriter;
use BankTransferPro\Gateway\GatewayPathResolver;
use BankTransferPro\Gateway\SlugGenerator;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Support\RuntimeEnvironment;
use WHMCS\Database\Capsule;

final class AjaxController
{
    public function __construct(
        private readonly BankRepository $bankRepository = new BankRepository(),
        private readonly SlugGenerator $slugGenerator = new SlugGenerator(),
        private readonly GatewayFileWriter $fileWriter = new GatewayFileWriter(),
        private readonly GatewayActivator $activator = new GatewayActivator(),
        private readonly GatewayPathResolver $pathResolver = new GatewayPathResolver()
    ) {
    }

    public function handle(): void
    {
        $this->assertAdminAccess();

        $action = (string) ($_REQUEST['btp_action'] ?? $_REQUEST['action'] ?? 'list');

        if (in_array($action, ['create', 'update', 'delete'], true)) {
            $this->assertCsrf();
        }

        match ($action) {
            'list' => $this->listBanks(),
            'get' => $this->getBank(),
            'create' => $this->createBank(),
            'update' => $this->updateBank(),
            'delete' => $this->deleteBank(),
            default => JsonResponse::error('UNKNOWN_ACTION', 'Unknown action: ' . $action, 404),
        };
    }

    private function listBanks(): never
    {
        JsonResponse::success(['banks' => $this->bankRepository->all()]);
    }

    private function getBank(): never
    {
        $id = $this->resolveIdFromRequest();
        $bank = $this->bankRepository->findById($id);

        if ($bank === null) {
            JsonResponse::error('NOT_FOUND', 'Bank not found.', 404);
        }

        JsonResponse::success(['bank' => $bank]);
    }

    private function createBank(): never
    {
        $payload = $this->validatedPayload();
        $this->assertCurrencySupportedForCurrentRuntime($payload['currency_code']);

        if ($this->bankRepository->duplicateExists(
            $payload['bank_name'],
            $payload['branch_name'],
            $payload['currency_code']
        )) {
            JsonResponse::error(
                'DUPLICATE_BANK',
                'A bank with the same name, branch, and currency already exists.'
            );
        }

        $slug = $this->slugGenerator->generate(
            $payload['bank_name'],
            $payload['branch_name'],
            $payload['currency_code'],
            $this->bankRepository->allSlugs()
        );

        $displayName = BankRepository::buildDisplayName($payload['bank_name'], $payload['branch_name']);
        $gatewayActivated = false;

        try {
            if (RuntimeEnvironment::usesStaticGatewayMode()) {
                $this->activator->activateStaticGateway();
                $gatewayActivated = true;
            } else {
                $this->assertGatewayDirectoryWritable();
                $this->fileWriter->write($slug, $displayName);
                $this->activator->activate($slug, $displayName, $payload['currency_code']);
                $gatewayActivated = true;
            }

            $id = $this->bankRepository->create([
                'gateway_slug' => $slug,
                'bank_name' => $payload['bank_name'],
                'branch_name' => $payload['branch_name'],
                'currency_code' => $payload['currency_code'],
                'account_details' => $payload['account_details'],
                'display_name' => $displayName,
                'invoice_label' => $payload['invoice_label'],
                'upi_id' => $payload['upi_id'],
                'account_name' => $payload['account_name'],
                'account_number' => $payload['account_number'],
                'ifsc_code' => $payload['ifsc_code'],
            ]);
        } catch (\Throwable $e) {
            $this->cleanupFailedCreate($slug, $gatewayActivated);
            JsonResponse::error('CREATE_FAILED', $e->getMessage(), 500);
        }

        $bank = $this->bankRepository->findById($id);
        JsonResponse::success(['bank' => $bank], 'Bank created successfully.');
    }

    private function updateBank(): never
    {
        $id = $this->resolveIdFromRequest();
        $existing = $this->bankRepository->findById($id);

        if ($existing === null) {
            JsonResponse::error('NOT_FOUND', 'Bank not found.', 404);
        }

        $payload = $this->validatedPayload();
        $this->assertCurrencySupportedForCurrentRuntime($payload['currency_code'], $id);

        if ($this->bankRepository->duplicateExists(
            $payload['bank_name'],
            $payload['branch_name'],
            $payload['currency_code'],
            $id
        )) {
            JsonResponse::error(
                'DUPLICATE_BANK',
                'A bank with the same name, branch, and currency already exists.'
            );
        }

        $slug = (string) $existing['gateway_slug'];
        $displayName = BankRepository::buildDisplayName($payload['bank_name'], $payload['branch_name']);

        try {
            if (RuntimeEnvironment::usesStaticGatewayMode()) {
                $this->activator->activateStaticGateway();
            } else {
                $this->assertGatewayDirectoryWritable();
                $this->fileWriter->write($slug, $displayName);
                $this->activator->updateSettings($slug, $displayName, $payload['currency_code']);
            }

            $this->bankRepository->update($id, [
                'bank_name' => $payload['bank_name'],
                'branch_name' => $payload['branch_name'],
                'currency_code' => $payload['currency_code'],
                'account_details' => $payload['account_details'],
                'display_name' => $displayName,
                'invoice_label' => $payload['invoice_label'],
                'upi_id' => $payload['upi_id'],
                'account_name' => $payload['account_name'],
                'account_number' => $payload['account_number'],
                'ifsc_code' => $payload['ifsc_code'],
            ]);
        } catch (\Throwable $e) {
            JsonResponse::error('UPDATE_FAILED', $e->getMessage(), 500);
        }

        JsonResponse::success(['bank' => $this->bankRepository->findById($id)], 'Bank updated successfully.');
    }

    private function deleteBank(): never
    {
        $id = $this->resolveIdFromRequest();
        $existing = $this->bankRepository->findById($id);

        if ($existing === null) {
            JsonResponse::error('NOT_FOUND', 'Bank not found.', 404);
        }

        $slug = (string) $existing['gateway_slug'];
        $this->assertDeleteConfirmed();

        try {
            if (RuntimeEnvironment::usesStaticGatewayMode()) {
                if ($this->bankRepository->countOtherActive($id) === 0) {
                    $this->activator->deactivate('banktransferpro');
                }
            } else {
                $this->activator->deactivate($slug);
                $this->fileWriter->delete($slug);
            }

            $this->bankRepository->delete($id);
        } catch (\Throwable $e) {
            JsonResponse::error('DELETE_FAILED', $e->getMessage(), 500);
        }

        JsonResponse::success(null, 'Bank deleted successfully.');
    }

    /**
     * @return array{
     *   bank_name: string,
     *   branch_name: string,
     *   currency_code: string,
     *   account_details: string,
     *   invoice_label: string,
     *   upi_id: string,
     *   account_name: string,
     *   account_number: string,
     *   ifsc_code: string
     * }
     */
    private function validatedPayload(): array
    {
        $input = $_POST;

        $bankName = trim((string) ($input['bank_name'] ?? ''));
        $branchName = trim((string) ($input['branch_name'] ?? ''));
        $currencyCode = strtoupper(trim((string) ($input['currency_code'] ?? '')));
        $accountDetails = trim((string) ($input['account_details'] ?? ''));
        $invoiceLabel = trim((string) ($input['invoice_label'] ?? ''));
        $upiId = trim((string) ($input['upi_id'] ?? ''));
        $accountName = trim((string) ($input['account_name'] ?? ''));
        $accountNumber = trim((string) ($input['account_number'] ?? ''));
        $ifscCode = strtoupper(trim((string) ($input['ifsc_code'] ?? '')));

        if ($bankName === '') {
            JsonResponse::error('VALIDATION_ERROR', 'Bank name is required.');
        }

        if ($currencyCode === '' || strlen($currencyCode) !== 3) {
            JsonResponse::error('VALIDATION_ERROR', 'A valid 3-letter currency code is required.');
        }

        if ($accountDetails === '' && $upiId === '' && $accountNumber === '') {
            JsonResponse::error(
                'VALIDATION_ERROR',
                'Provide bank account details, a UPI ID, or an account number for the invoice payment block.'
            );
        }

        if (! $this->currencyExists($currencyCode)) {
            JsonResponse::error('VALIDATION_ERROR', 'Currency code is not configured in WHMCS.');
        }

        return [
            'bank_name' => $bankName,
            'branch_name' => $branchName,
            'currency_code' => $currencyCode,
            'account_details' => $accountDetails,
            'invoice_label' => $invoiceLabel,
            'upi_id' => $upiId,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'ifsc_code' => $ifscCode,
        ];
    }

    private function currencyExists(string $currencyCode): bool
    {
        return Capsule::table('tblcurrencies')->where('code', $currencyCode)->exists();
    }

    private function assertCurrencySupportedForCurrentRuntime(string $currencyCode, ?int $excludeId = null): void
    {
        if (! RuntimeEnvironment::usesStaticGatewayMode()) {
            return;
        }

        $existing = $this->bankRepository->findOtherActiveByCurrencyCode($currencyCode, $excludeId);
        if ($existing !== null) {
            JsonResponse::error(
                'CURRENCY_ALREADY_CONFIGURED',
                'Immutable deployments support one active bank per currency. Edit the existing bank for this currency instead.'
            );
        }
    }

    private function assertGatewayDirectoryWritable(): void
    {
        if ($this->pathResolver->isGatewaysDirectoryWritable()) {
            return;
        }

        JsonResponse::error(
            'GATEWAY_DIR_NOT_WRITABLE',
            'The modules/gateways directory is not writable. Check filesystem permissions.'
        );
    }

    private function assertDeleteConfirmed(): void
    {
        $confirmed = $_POST['confirm_delete'] ?? false;

        if (filter_var($confirmed, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        JsonResponse::error('DELETE_CONFIRMATION_REQUIRED', 'Please confirm the delete request and try again.', 400);
    }

    private function assertAdminAccess(): void
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId > 0) {
            return;
        }

        if (function_exists('checkAdminLogin') && checkAdminLogin()) {
            return;
        }

        JsonResponse::error('UNAUTHORIZED', 'Admin authentication required.', 401);
    }

    private function assertCsrf(): void
    {
        if (! function_exists('check_token')) {
            return;
        }

        $token = (string) ($_POST['token'] ?? '');

        try {
            $valid = check_token('WHMCS.admin.default', $token !== '' ? $token : null);
        } catch (\Throwable) {
            JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
        }

        if ($valid) {
            return;
        }

        JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
    }

    private function resolveIdFromRequest(): int
    {
        return (int) ($_POST['id'] ?? $_GET['id'] ?? $_REQUEST['id'] ?? 0);
    }

    private function cleanupFailedCreate(string $slug, bool $gatewayActivated): void
    {
        if (RuntimeEnvironment::usesStaticGatewayMode()) {
            return;
        }

        if ($gatewayActivated) {
            try {
                $this->activator->deactivate($slug);
            } catch (\Throwable) {
                // Preserve the original create failure for the API response.
            }
        }

        try {
            $this->fileWriter->delete($slug);
        } catch (\Throwable) {
            // Preserve the original create failure for the API response.
        }
    }
}
