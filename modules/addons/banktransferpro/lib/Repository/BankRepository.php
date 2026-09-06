<?php

declare(strict_types=1);

namespace BankTransferPro\Repository;

use BankTransferPro\Support\DuplicateGuard;
use WHMCS\Database\Capsule;

final class BankRepository
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
     * @param array{bank_name: string, branch_name: string, currency_code: string, account_details: string, display_name: string, gateway_slug: string} $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_btp_banks')->insertGetId([
            'gateway_slug' => $data['gateway_slug'],
            'bank_name' => $data['bank_name'],
            'branch_name' => $data['branch_name'],
            'currency_code' => strtoupper($data['currency_code']),
            'account_details' => $data['account_details'],
            'display_name' => $data['display_name'],
            'duplicate_key' => DuplicateGuard::buildDuplicateKey(
                $data['bank_name'],
                $data['branch_name'],
                $data['currency_code']
            ),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array{bank_name: string, branch_name: string, currency_code: string, account_details: string, display_name: string} $data
     */
    public function update(int $id, array $data): void
    {
        Capsule::table('mod_btp_banks')->where('id', $id)->update([
            'bank_name' => $data['bank_name'],
            'branch_name' => $data['branch_name'],
            'currency_code' => strtoupper($data['currency_code']),
            'account_details' => $data['account_details'],
            'display_name' => $data['display_name'],
            'duplicate_key' => DuplicateGuard::buildDuplicateKey(
                $data['bank_name'],
                $data['branch_name'],
                $data['currency_code']
            ),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
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

    public static function buildDisplayName(string $bankName, string $branchName): string
    {
        $parts = ['Bank Transfer Pro', trim($bankName)];
        $branch = trim($branchName);
        if ($branch !== '') {
            $parts[] = $branch;
        }

        return implode(' — ', $parts);
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
            'is_active' => (bool) $row->is_active,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
