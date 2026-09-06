<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Admin\JsonResponse;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Repository\ProofRepository;
use BankTransferPro\Repository\SettingsRepository;
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
        if (! $this->settings->isProofUploadEnabled()) {
            JsonResponse::error('FEATURE_DISABLED', 'Payment proof upload is disabled.', 403);
        }

        $this->assertClientLogin();
        $this->assertCsrf();

        $invoiceId = (int) ($_POST['invoiceid'] ?? $_GET['invoiceid'] ?? 0);
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
            $stored = $this->uploader->store($clientId, $_FILES['proof']);
            $bank = $this->resolveBankForInvoice($invoice, $gateway);

            $subject = $this->tickets->renderSubject([
                'invoiceid' => (string) $invoiceId,
                'invoicenum' => (string) ($invoice->invoicenum ?? $invoiceId),
            ]);

            $message = $this->buildTicketMessage($invoice, $bank, $note);

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
            ]);

            JsonResponse::success([
                'proof_id' => $proofId,
                'ticket_id' => $ticket['ticket_id'],
                'ticket_number' => $ticket['ticket_number'],
            ], 'Payment proof uploaded. Support ticket #' . $ticket['ticket_number'] . ' has been opened.');
        } catch (\InvalidArgumentException $e) {
            JsonResponse::error('VALIDATION_ERROR', $e->getMessage());
        } catch (\Throwable $e) {
            JsonResponse::error('UPLOAD_FAILED', $e->getMessage(), 500);
        }
    }

    private function buildTicketMessage(object $invoice, ?array $bank, string $note): string
    {
        $lines = [
            'A client uploaded a payment proof for a bank transfer invoice.',
            '',
            'Invoice ID: ' . $invoice->id,
            'Invoice Number: ' . ($invoice->invoicenum ?? $invoice->id),
            'Amount: ' . ($invoice->total ?? ''),
            'Gateway: ' . ($bank['display_name'] ?? $invoice->paymentmethod),
        ];

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
        $token = $_POST['token'] ?? '';
        if (function_exists('check_token') && ! check_token('WHMCS.default', $token)) {
            JsonResponse::error('CSRF_FAILED', 'Invalid security token.', 403);
        }
    }

    private function isSupportedGateway(string $gateway): bool
    {
        return $gateway === 'banktransferpro' || str_starts_with($gateway, 'banktransferpro_');
    }

    private function resolveBankForInvoice(object $invoice, string $gateway): ?array
    {
        if ($gateway !== 'banktransferpro') {
            return $this->banks->findBySlug($gateway);
        }

        $currencyId = (int) ($invoice->currency ?? 0);
        if ($currencyId <= 0) {
            return null;
        }

        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->first(['code']);
        if ($currency === null) {
            return null;
        }

        return $this->banks->findActiveByCurrencyCode((string) $currency->code);
    }
}
