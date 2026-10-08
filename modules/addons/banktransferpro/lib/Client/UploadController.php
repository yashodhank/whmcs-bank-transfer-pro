<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Admin\JsonResponse;
use BankTransferPro\Packs\BankProfile;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\ProofDetails;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Repository\ProofRepository;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\InvoiceCurrencyResolver;
use WHMCS\Database\Capsule;

final class UploadController
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly PaymentProofUploader $uploader = new PaymentProofUploader(),
        private readonly TicketFactory $tickets = new TicketFactory(),
        private readonly ProofRepository $proofs = new ProofRepository(),
        private readonly BankRepository $banks = new BankRepository()
    ) {
    }

    public function handle(): void
    {
        $stored = null;

        if (! $this->settings->isProofUploadEnabled()) {
            JsonResponse::error('FEATURE_DISABLED', 'Payment proof upload is disabled.', 403);
        }

        $this->assertClientLogin();
        $this->assertCsrf();

        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            JsonResponse::error('NOT_FOUND', 'Invoice not found.', 404);
        }

        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ((int) $invoice->userid !== $clientId) {
            JsonResponse::error('FORBIDDEN', 'You do not have access to this invoice.', 403);
        }

        $status = (string) $invoice->status;
        if (! in_array($status, ['Unpaid', 'Payment Pending'], true)) {
            JsonResponse::error('INVALID_STATUS', 'Payment proof can only be uploaded for unpaid invoices.');
        }

        $gateway = (string) $invoice->paymentmethod;
        if (! $this->isSupportedGateway($gateway)) {
            JsonResponse::error('INVALID_GATEWAY', 'This invoice is not using a Bank Transfer Pro gateway.');
        }

        if ($this->uploader->hasRecentProof($invoiceId)) {
            JsonResponse::error(
                'COOLDOWN_ACTIVE',
                'A payment proof was recently submitted for this invoice. Please wait before uploading again.'
            );
        }

        if (! isset($_FILES['proof']) || ! is_array($_FILES['proof'])) {
            JsonResponse::error('VALIDATION_ERROR', 'Please choose a file to upload.');
        }

        try {
            $bank = $this->resolveBankForInvoice($invoice, $gateway);
            $invoiceCurrency = (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice);
            $packSet = $this->buildPackSet($invoice, $bank, $invoiceCurrency, $clientId);

            // Validate pack-aware proof fields before touching the filesystem.
            $details = ProofDetails::fromRequest(
                $_POST,
                $packSet,
                $invoiceId,
                $invoiceCurrency,
                $bank !== null && BankProfile::acceptsFx($bank)
            );

            $stored = $this->uploader->store($clientId, $_FILES['proof']);

            $subject = $this->tickets->renderSubject([
                'invoiceid' => (string) $invoiceId,
                'invoicenum' => (string) ($invoice->invoicenum ?? '') !== '' ? (string) $invoice->invoicenum : (string) $invoiceId,
                'paymentreference' => $details['payment_reference'],
            ]);

            $message = $this->buildTicketMessage($invoice, $bank, $note, $details, $packSet);

            $ticket = $this->tickets->openPaymentProofTicket(
                $clientId,
                $subject,
                $message,
                $stored['path'],
                $stored['original_filename']
            );

            $proofId = $this->proofs->create([
                'invoice_id' => $invoiceId,
                'client_id' => $clientId,
                'gateway_slug' => $bank['gateway_slug'] ?? $gateway,
                'stored_filename' => $stored['stored_filename'],
                'original_filename' => $stored['original_filename'],
                'mime' => $stored['mime'],
                'size' => $stored['size'],
                'ticket_id' => $ticket['ticket_id'],
                'payment_reference' => $details['payment_reference'],
                'pack_id' => $details['pack_id'],
                'rail_reference' => $details['rail_reference'],
                'declared_amount' => $details['declared_amount'],
                'declared_currency' => $details['declared_currency'],
            ]);

            JsonResponse::success([
                'proof_id' => $proofId,
                'ticket_id' => $ticket['ticket_id'],
                'ticket_number' => $ticket['ticket_number'],
                'payment_reference' => $details['payment_reference'],
            ], 'Payment proof uploaded. Support ticket #' . $ticket['ticket_number'] . ' has been opened.');
        } catch (\InvalidArgumentException $e) {
            JsonResponse::error('VALIDATION_ERROR', $e->getMessage());
        } catch (\Throwable $e) {
            if (is_array($stored)) {
                $this->cleanupStoredProof($stored);
            }
            JsonResponse::error('UPLOAD_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * @param array{path?: mixed} $stored
     */
    private function cleanupStoredProof(array $stored): void
    {
        $path = is_string($stored['path'] ?? null) ? $stored['path'] : '';
        if ($path === '') {
            return;
        }

        try {
            $this->uploader->deleteStoredFile($path);
        } catch (\Throwable) {
            // Preserve the original upload or ticket error for the client response.
        }
    }

    /**
     * @param array<string, mixed>|null $bank
     * @return array<string, mixed>
     */
    private function buildPackSet(object $invoice, ?array $bank, ?string $invoiceCurrency, int $clientId): array
    {
        $invoiceId = (int) $invoice->id;
        if ($bank === null) {
            return ['packs' => [], 'recommended' => null, 'amount' => (string) ($invoice->total ?? ''), 'currency' => $invoiceCurrency];
        }

        $country = Capsule::table('tblclients')->where('id', $clientId)->value('country');

        return InstructionPackEngine::build(
            $bank,
            PayerContext::fromCountry($country, false),
            [
                'id' => $invoiceId,
                'number' => (string) ($invoice->invoicenum ?? '') !== '' ? (string) $invoice->invoicenum : (string) $invoiceId,
                'amount' => (string) ($invoice->total ?? ''),
                'currency' => $invoiceCurrency,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $bank
     * @param array{payment_reference: string, pack_id: string, rail_reference: string, declared_amount: ?string, declared_currency: string} $details
     * @param array<string, mixed> $packSet
     */
    private function buildTicketMessage(object $invoice, ?array $bank, string $note, array $details, array $packSet): string
    {
        $lines = [
            'A client uploaded a payment proof for a bank transfer invoice.',
            '',
            'Invoice ID: ' . $invoice->id,
            'Invoice Number: ' . ((string) ($invoice->invoicenum ?? '') !== '' ? $invoice->invoicenum : $invoice->id),
            'Amount: ' . ($invoice->total ?? ''),
            'Gateway: ' . ($bank['display_name'] ?? $invoice->paymentmethod),
            '',
        ];

        $lines = array_merge($lines, ProofDetails::ticketLines($details, $packSet));

        if ($note !== '') {
            $lines[] = '';
            $lines[] = 'Client note:';
            $lines[] = $note;
        }

        return implode("\n", $lines);
    }

    private function assertClientLogin(): void
    {
        if (empty($_SESSION['uid'])) {
            JsonResponse::error('UNAUTHORIZED', 'Please log in to upload payment proof.', 401);
        }
    }

    private function assertCsrf(): void
    {
        if (! function_exists('check_token')) {
            return;
        }

        $token = $_POST['token'] ?? '';

        try {
            $valid = check_token('WHMCS.default', $token);
        } catch (\Throwable) {
            JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
        }

        if ($valid) {
            return;
        }

        JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
    }

    private function isSupportedGateway(string $gateway): bool
    {
        return $gateway === 'banktransferpro' || str_starts_with($gateway, 'banktransferpro_');
    }

    private function resolveBankForInvoice(object $invoice, string $gateway): ?array
    {
        if ($gateway !== 'banktransferpro') {
            return $this->banks->findActiveBySlug($gateway);
        }

        $code = (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice);
        if ($code === null) {
            return null;
        }

        return $this->banks->findActiveByCurrencyCode($code);
    }
}
