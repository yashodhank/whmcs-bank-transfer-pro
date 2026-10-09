<?php

declare(strict_types=1);

namespace BankTransferPro\Admin;

use BankTransferPro\Gateway\GatewayActivator;
use BankTransferPro\Gateway\GatewayFileWriter;
use BankTransferPro\Gateway\GatewayPathResolver;
use BankTransferPro\Gateway\SlugGenerator;
use BankTransferPro\Packs\BankProfile;
use BankTransferPro\Packs\CountryList;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PackRenderer;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\ReceiveProfileValidator;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Support\RuntimeEnvironment;
use BankTransferPro\Support\WhmcsInput;
use WHMCS\Database\Capsule;

final class AjaxController
{
    /** @var list<string> */
    private array $warnings = [];

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
            'preview' => $this->previewBank(),
            default => JsonResponse::error('UNKNOWN_ACTION', 'Unknown action: ' . $action, 404),
        };
    }

    private function listBanks(): never
    {
        JsonResponse::success(['banks' => array_map($this->withEffectiveProfile(...), $this->bankRepository->all())]);
    }

    private function getBank(): never
    {
        $id = $this->resolveIdFromRequest();
        $bank = $this->bankRepository->findById($id);

        if ($bank === null) {
            JsonResponse::error('NOT_FOUND', 'Bank not found.', 404);
        }

        JsonResponse::success(['bank' => $this->withEffectiveProfile($bank)]);
    }

    private function createBank(): never
    {
        $payload = $this->validatedPayload(null);
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

            $id = $this->bankRepository->create(array_merge($payload, [
                'gateway_slug' => $slug,
                'display_name' => $displayName,
            ]));
        } catch (\Throwable $e) {
            $this->cleanupFailedCreate($slug, $gatewayActivated);
            JsonResponse::error('CREATE_FAILED', $e->getMessage(), 500);
        }

        $bank = $this->bankRepository->findById($id);
        JsonResponse::success(['bank' => $bank === null ? null : $this->withEffectiveProfile($bank)], 'Bank created successfully.');
    }

    private function updateBank(): never
    {
        $id = $this->resolveIdFromRequest();
        $existing = $this->bankRepository->findById($id);

        if ($existing === null) {
            JsonResponse::error('NOT_FOUND', 'Bank not found.', 404);
        }

        $payload = $this->validatedPayload($existing);
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

            $this->bankRepository->update($id, array_merge($payload, [
                'display_name' => $displayName,
            ]));
        } catch (\Throwable $e) {
            JsonResponse::error('UPDATE_FAILED', $e->getMessage(), 500);
        }

        $updated = $this->bankRepository->findById($id);
        JsonResponse::success(
            ['bank' => $updated === null ? null : $this->withEffectiveProfile($updated), 'warnings' => $this->warnings],
            trim('Bank updated successfully. ' . implode(' ', $this->warnings))
        );
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
     * @return array<string, mixed>
     */
    private function validatedPayload(?array $existing): array
    {
        $result = ReceiveProfileValidator::validate(WhmcsInput::decode($_POST), self::validationContext($existing));
        $profile = $result['profile'];
        $this->warnings = $result['warnings'];

        $errors = $result['errors'];
        if ($errors === [] && ! $this->currencyExists((string) $profile['currency_code'])) {
            $errors[] = 'Currency code is not configured in WHMCS.';
        }

        if ($errors !== []) {
            JsonResponse::error('VALIDATION_ERROR', $errors[0]);
        }

        return $profile;
    }

    /**
     * Rows saved before the wizard never stored an account name; editing them must not be blocked
     * by a field they could never have filled in. New rows and rows that already have a name stay strict.
     *
     * @param array<string, mixed>|null $existing
     * @return array{legacy_missing_payee: bool}
     */
    private static function validationContext(?array $existing): array
    {
        return ['legacy_missing_payee' => $existing !== null && trim((string) ($existing['account_name'] ?? '')) === ''];
    }

    /**
     * Renders the exact invoice experience for three payer contexts from the unsaved draft.
     */
    private function previewBank(): never
    {
        $input = WhmcsInput::decode($_POST);
        $editingId = (int) ($input['id'] ?? 0);
        $existing = $editingId > 0 ? $this->bankRepository->findById($editingId) : null;
        $result = ReceiveProfileValidator::validate($input, self::validationContext($existing));
        $profile = $result['profile'];

        if ($result['errors'] !== []) {
            JsonResponse::error('VALIDATION_ERROR', $result['errors'][0]);
        }

        $bank = array_merge($profile, [
            'display_name' => BankRepository::buildDisplayName($profile['bank_name'], $profile['branch_name']),
        ]);
        $label = BankRepository::buildInvoiceLabel($bank);
        $country = (string) $profile['country_code'];
        $abroad = $country === 'US' ? 'GB' : 'US';
        $invoice = [
            'id' => 10482,
            'number' => '10482',
            'amount' => '1000.00',
            'currency' => (string) $profile['currency_code'],
        ];

        $contexts = [
            ['id' => 'domestic', 'title' => 'Client in ' . CountryList::name($country) . ' (desktop)', 'payer' => PayerContext::fromCountry($country, false)],
            ['id' => 'mobile', 'title' => 'Client in ' . CountryList::name($country) . ' (phone)', 'payer' => PayerContext::fromCountry($country, true)],
            ['id' => 'abroad', 'title' => 'Client abroad (' . CountryList::name($abroad) . ')', 'payer' => PayerContext::fromCountry($abroad, false)],
        ];

        $previews = [];
        foreach ($contexts as $context) {
            $packSet = InstructionPackEngine::build($bank, $context['payer'], $invoice);
            $previews[] = [
                'id' => $context['id'],
                'title' => $context['title'],
                'recommended' => $packSet['recommended'],
                'html' => PackRenderer::render($packSet, $label),
            ];
        }

        JsonResponse::success(['previews' => $previews, 'warnings' => $result['warnings']]);
    }

    /**
     * Resolve legacy columns into the effective profile so the wizard can edit un-migrated rows.
     *
     * @param array<string, mixed> $bank
     * @return array<string, mixed>
     */
    private function withEffectiveProfile(array $bank): array
    {
        $bank['country_code'] = BankProfile::countryCode($bank);
        $bank['capabilities'] = BankProfile::capabilities($bank);
        $bank['identifiers'] = BankProfile::identifiers($bank);
        $bank['pack_notes'] = BankProfile::packNotes($bank);
        $bank['prefer_charge_code'] = BankProfile::chargeCode($bank);

        return $bank;
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
