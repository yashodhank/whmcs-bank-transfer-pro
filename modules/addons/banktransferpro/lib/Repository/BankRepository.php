<?php

declare(strict_types=1);

namespace BankTransferPro\Repository;

use BankTransferPro\Support\DuplicateGuard;
use WHMCS\Database\Capsule;

final class BankRepository implements BankLookup
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = Capsule::table('mod_btp_banks')
            ->orderBy('id')
            ->get();

        return array_map(fn ($row) => $this->mapRow($row), $rows->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = Capsule::table('mod_btp_banks')->where('id', $id)->first();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $row = Capsule::table('mod_btp_banks')->where('gateway_slug', $slug)->first();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $row = Capsule::table('mod_btp_banks')
            ->where('gateway_slug', $slug)
            ->where('is_active', 1)
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @return list<string>
     */
    public function allSlugs(): array
    {
        return Capsule::table('mod_btp_banks')->pluck('gateway_slug')->all();
    }

    public function duplicateExists(string $bankName, string $branchName, string $currencyCode, ?int $excludeId = null): bool
    {
        $query = Capsule::table('mod_btp_banks')
            ->where('duplicate_key', DuplicateGuard::buildDuplicateKey($bankName, $branchName, $currencyCode));

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_btp_banks')->insertGetId(array_merge([
            'gateway_slug' => $data['gateway_slug'],
            'duplicate_key' => DuplicateGuard::buildDuplicateKey(
                $data['bank_name'],
                $data['branch_name'],
                $data['currency_code']
            ),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::persistedColumns($data)));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        Capsule::table('mod_btp_banks')->where('id', $id)->update(array_merge([
            'duplicate_key' => DuplicateGuard::buildDuplicateKey(
                $data['bank_name'],
                $data['branch_name'],
                $data['currency_code']
            ),
            'updated_at' => date('Y-m-d H:i:s'),
        ], self::persistedColumns($data)));
    }

    /**
     * Columns shared by insert and update. Legacy upi_id / ifsc_code stay in sync with the
     * identifiers JSON so older readers (and rollbacks) keep working.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function persistedColumns(array $data): array
    {
        $identifiers = is_array($data['identifiers'] ?? null) ? $data['identifiers'] : [];
        $capabilities = is_array($data['capabilities'] ?? null) ? array_values($data['capabilities']) : [];
        $packNotes = is_array($data['pack_notes'] ?? null) ? $data['pack_notes'] : [];

        return [
            'bank_name' => $data['bank_name'],
            'branch_name' => $data['branch_name'],
            'currency_code' => strtoupper($data['currency_code']),
            'country_code' => strtoupper(trim((string) ($data['country_code'] ?? ''))),
            'account_details' => $data['account_details'],
            'display_name' => $data['display_name'],
            'invoice_label' => trim((string) ($data['invoice_label'] ?? '')),
            'upi_id' => trim((string) ($identifiers['upi'] ?? $data['upi_id'] ?? '')),
            'account_name' => trim((string) ($data['account_name'] ?? '')),
            'account_number' => trim((string) ($data['account_number'] ?? '')),
            'ifsc_code' => strtoupper(trim((string) ($identifiers['ifsc'] ?? $data['ifsc_code'] ?? ''))),
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'identifiers' => json_encode($identifiers === [] ? new \stdClass() : $identifiers, JSON_THROW_ON_ERROR),
            'beneficiary_address' => trim((string) ($data['beneficiary_address'] ?? '')),
            'bank_address' => trim((string) ($data['bank_address'] ?? '')),
            'intermediary_bic' => strtoupper(trim((string) ($data['intermediary_bic'] ?? ''))),
            'prefer_charge_code' => strtoupper(trim((string) ($data['prefer_charge_code'] ?? 'OUR'))) ?: 'OUR',
            'accept_fx_receive' => ! empty($data['accept_fx_receive']) ? 1 : 0,
            'wire_purpose_hint' => trim((string) ($data['wire_purpose_hint'] ?? '')),
            'pack_notes' => json_encode($packNotes === [] ? new \stdClass() : $packNotes, JSON_THROW_ON_ERROR),
        ];
    }

    public function delete(int $id): void
    {
        Capsule::table('mod_btp_banks')->where('id', $id)->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCurrencyCode(string $currencyCode): ?array
    {
        $row = Capsule::table('mod_btp_banks')
            ->where('currency_code', strtoupper(trim($currencyCode)))
            ->where('is_active', 1)
            ->orderBy('id')
            ->first();

        return $row ? $this->mapRow($row) : null;
    }

    public function findOtherActiveByCurrencyCode(string $currencyCode, ?int $excludeId = null): ?array
    {
        $query = Capsule::table('mod_btp_banks')
            ->where('currency_code', strtoupper(trim($currencyCode)))
            ->where('is_active', 1)
            ->orderBy('id');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $row = $query->first();

        return $row ? $this->mapRow($row) : null;
    }

    public function countActive(): int
    {
        return (int) Capsule::table('mod_btp_banks')->where('is_active', 1)->count();
    }

    public function countOtherActive(int $excludeId): int
    {
        return (int) Capsule::table('mod_btp_banks')
            ->where('is_active', 1)
            ->where('id', '!=', $excludeId)
            ->count();
    }

    public static function buildDisplayName(string $bankName, string $branchName): string
    {
        $parts = [trim($bankName)];
        $branch = trim($branchName);
        if ($branch !== '') {
            $parts[] = $branch;
        }

        return implode(' — ', $parts);
    }

    public static function buildInvoiceLabel(array $bank): string
    {
        $custom = trim((string) ($bank['invoice_label'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return self::buildDisplayName(
            (string) ($bank['bank_name'] ?? ''),
            (string) ($bank['branch_name'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'gateway_slug' => (string) $row->gateway_slug,
            'bank_name' => (string) $row->bank_name,
            'branch_name' => (string) $row->branch_name,
            'currency_code' => (string) $row->currency_code,
            'account_details' => (string) $row->account_details,
            'display_name' => (string) $row->display_name,
            'invoice_label' => (string) ($row->invoice_label ?? ''),
            'upi_id' => (string) ($row->upi_id ?? ''),
            'account_name' => (string) ($row->account_name ?? ''),
            'account_number' => (string) ($row->account_number ?? ''),
            'ifsc_code' => (string) ($row->ifsc_code ?? ''),
            'country_code' => (string) ($row->country_code ?? ''),
            'capabilities' => self::decodeJson($row->capabilities ?? null, true),
            'identifiers' => self::decodeJson($row->identifiers ?? null, false),
            'beneficiary_address' => (string) ($row->beneficiary_address ?? ''),
            'bank_address' => (string) ($row->bank_address ?? ''),
            'intermediary_bic' => (string) ($row->intermediary_bic ?? ''),
            'prefer_charge_code' => (string) ($row->prefer_charge_code ?? 'OUR'),
            'accept_fx_receive' => (bool) ($row->accept_fx_receive ?? false),
            'wire_purpose_hint' => (string) ($row->wire_purpose_hint ?? ''),
            'pack_notes' => self::decodeJson($row->pack_notes ?? null, false),
            'is_active' => (bool) $row->is_active,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function decodeJson(mixed $value, bool $asList): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $asList ? array_values($decoded) : $decoded;
    }
}
