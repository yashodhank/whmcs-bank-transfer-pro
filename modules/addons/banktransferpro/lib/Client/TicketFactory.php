<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Repository\SettingsRepository;

final class TicketFactory
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository()
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{ticket_id: int, ticket_number: string}
     */
    public function openPaymentProofTicket(
        int $clientId,
        string $subject,
        string $message,
        string $attachmentPath,
        string $attachmentName
    ): array {
        if (! function_exists('localAPI')) {
            require_once ROOTDIR . '/includes/api.php';
        }

        $response = localAPI('OpenTicket', [
            'clientid' => $clientId,
            'deptid' => $this->settings->ticketDepartmentId(),
            'subject' => $subject,
            'message' => $message,
            'priority' => $this->settings->ticketPriority(),
            'attachment' => base64_encode((string) file_get_contents($attachmentPath)),
            'attachmentname' => $attachmentName,
        ]);

        if (! is_array($response) || ($response['result'] ?? '') !== 'success') {
            $error = is_array($response) ? (string) ($response['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException('Failed to open support ticket: ' . $error);
        }

        return [
            'ticket_id' => (int) ($response['id'] ?? 0),
            'ticket_number' => (string) ($response['tid'] ?? ''),
        ];
    }

    public function renderSubject(array $context): string
    {
        return $this->renderTemplate($this->settings->ticketSubjectTemplate(), $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function renderTemplate(string $template, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
