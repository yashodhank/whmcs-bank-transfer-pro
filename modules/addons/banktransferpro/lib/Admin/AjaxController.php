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
    /** @var array<string, mixed>|null */
    private ?array $requestJson = null;

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
        $this->assertCsrf();

        $action = (string) ($_REQUEST['btp_action'] ?? $_REQUEST['action'] ?? 'list');

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
     * @return array{bank_name: string, branch_name: string, currency_code: string, account_details: string}
     */
    private function validatedPayload(): array
    {
        $input = $this->getRequestJson();
        if ($input === null) {
            $input = $_POST;
        }

        $bankName = trim((string) ($input['bank_name'] ?? ''));
        $branchName = trim((string) ($input['branch_name'] ?? ''));
        $currencyCode = strtoupper(trim((string) ($input['currency_code'] ?? '')));
        $accountDetails = trim((string) ($input['account_details'] ?? ''));

        if ($bankName === '') {
            JsonResponse::error('VALIDATION_ERROR', 'Bank name is required.');
        }

        if ($currencyCode === '' || strlen($currencyCode) !== 3) {
            JsonResponse::error('VALIDATION_ERROR', 'A valid 3-letter currency code is required.');
        }

        if ($accountDetails === '') {
            JsonResponse::error('VALIDATION_ERROR', 'Bank account details are required.');
        }

        if (! $this->currencyExists($currencyCode)) {
            JsonResponse::error('VALIDATION_ERROR', 'Currency code is not configured in WHMCS.');
        }

        return [
            'bank_name' => $bankName,
            'branch_name' => $branchName,
            'currency_code' => $currencyCode,
            'account_details' => $accountDetails,
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
        $input = $this->getRequestJson();
        $confirmed = $_POST['confirm_delete']
            ?? (is_array($input) ? ($input['confirm_delete'] ?? false) : false);

        if (filter_var($confirmed, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        JsonResponse::error('DELETE_CONFIRMATION_REQUIRED', 'Please confirm the delete request and try again.', 400);
    }

    private function assertAdminAccess(): void
    {
        if (! function_exists('checkAdminLogin') || ! checkAdminLogin()) {
            JsonResponse::error('UNAUTHORIZED', 'Admin authentication required.', 401);
        }
    }

    private function assertCsrf(): void
    {
        if (! function_exists('check_token')) {
            return;
        }

        $input = $this->getRequestJson();
        $token = $_POST['token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? (is_array($input) ? ($input['token'] ?? '') : '');

        if ($token !== '' && check_token('WHMCS.admin.default', (string) $token)) {
            return;
        }

        JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
    }

    private function resolveIdFromRequest(): int
    {
        $input = $this->getRequestJson();
        if (is_array($input) && isset($input['id'])) {
            return (int) $input['id'];
        }

        return (int) ($_REQUEST['id'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRequestJson(): ?array
    {
        if ($this->requestJson !== null) {
            return $this->requestJson;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->requestJson = null;

            return null;
        }

        $decoded = json_decode($raw, true);
        $this->requestJson = is_array($decoded) ? $decoded : null;

        return $this->requestJson;
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
