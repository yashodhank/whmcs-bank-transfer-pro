<?php

declare(strict_types=1);

namespace BankTransferPro\Repository;

use WHMCS\Database\Capsule;

final class ProofRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return (int) Capsule::table('mod_btp_payment_proofs')->insertGetId([
            'invoice_id' => (int) $data['invoice_id'],
            'client_id' => (int) $data['client_id'],
            'gateway_slug' => (string) $data['gateway_slug'],
            'stored_filename' => (string) $data['stored_filename'],
            'original_filename' => (string) $data['original_filename'],
            'mime' => (string) $data['mime'],
            'size' => (int) $data['size'],
            'ticket_id' => isset($data['ticket_id']) ? (int) $data['ticket_id'] : null,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateTicketId(int $proofId, int $ticketId): void
    {
        Capsule::table('mod_btp_payment_proofs')
            ->where('id', $proofId)
            ->update(['ticket_id' => $ticketId]);
    }

    public function hasRecentProof(int $invoiceId, int $cooldownHours): bool
    {
        if ($cooldownHours <= 0) {
            return false;
        }

        $since = date('Y-m-d H:i:s', time() - ($cooldownHours * 3600));

        return Capsule::table('mod_btp_payment_proofs')
            ->where('invoice_id', $invoiceId)
            ->where('uploaded_at', '>=', $since)
            ->exists();
    }
}
