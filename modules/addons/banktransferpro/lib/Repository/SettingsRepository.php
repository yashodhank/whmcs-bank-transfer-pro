<?php

declare(strict_types=1);

namespace BankTransferPro\Repository;

use WHMCS\Database\Capsule;

class SettingsRepository
{
    private const MODULE = 'banktransferpro';

    /** Absolute ceiling for admin-configured proof uploads (MB). */
    public const MAX_UPLOAD_SIZE_MB_HARD_CAP = 10;

    /** Multipart form-field overhead allowance beyond the file size cap (bytes). */
    public const UPLOAD_REQUEST_OVERHEAD_BYTES = 262144;

    public function get(string $key, ?string $default = null): ?string
    {
        $row = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', $key)
            ->first(['value']);

        if ($row === null) {
            return $default;
        }

        return (string) $row->value;
    }

    public function set(string $key, string $value): void
    {
        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => self::MODULE, 'setting' => $key],
            ['value' => $value]
        );
    }

    public function seedDefaults(): void
    {
        $defaults = [
            'ticket_department_id' => '1',
            'ticket_priority' => 'Medium',
            'ticket_subject_template' => 'Payment proof — Invoice #{invoiceid}',
            'enable_proof_upload' => 'on',
            'max_upload_size_mb' => '5',
            'allowed_mime_types' => 'image/jpeg,image/png,application/pdf',
            'upload_cooldown_hours' => '24',
            'auto_select_gateway' => 'on',
        ];

        foreach ($defaults as $key => $value) {
            if ($this->get($key) === null) {
                $this->set($key, $value);
            }
        }
    }

    public function isProofUploadEnabled(): bool
    {
        return $this->get('enable_proof_upload', 'on') === 'on';
    }

    public function maxUploadSizeMb(): int
    {
        $mb = (int) ($this->get('max_upload_size_mb', '5') ?? '5');

        return max(1, min(self::MAX_UPLOAD_SIZE_MB_HARD_CAP, $mb));
    }

    public function maxUploadSizeBytes(): int
    {
        return $this->maxUploadSizeMb() * 1024 * 1024;
    }

    /**
     * Upper bound for Content-Length on proof upload requests (file cap + form overhead).
     */
    public function maxUploadRequestBytes(): int
    {
        return $this->maxUploadSizeBytes() + self::UPLOAD_REQUEST_OVERHEAD_BYTES;
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        $raw = $this->get('allowed_mime_types', 'image/jpeg,image/png,application/pdf') ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function uploadCooldownHours(): int
    {
        return max(0, (int) ($this->get('upload_cooldown_hours', '24') ?? '24'));
    }

    public function ticketDepartmentId(): int
    {
        return max(1, (int) ($this->get('ticket_department_id', '1') ?? '1'));
    }

    public function ticketPriority(): string
    {
        return $this->get('ticket_priority', 'Medium') ?? 'Medium';
    }

    public function ticketSubjectTemplate(): string
    {
        return $this->get('ticket_subject_template', 'Payment proof — Invoice #{invoiceid}')
            ?? 'Payment proof — Invoice #{invoiceid}';
    }

    public function isAutoSelectGatewayEnabled(): bool
    {
        return $this->get('auto_select_gateway', 'on') === 'on';
    }
}
