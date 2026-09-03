<?php

declare(strict_types=1);

namespace BankTransferPro\Migration;

interface SqlExecutorInterface
{
    public function execute(string $sql): void;

    public function recordMigrationApplied(int $version, string $migrationName): void;

    /**
     * @return mixed|null
     */
    public function fetchScalar(string $sql);

    public function tableExists(string $table): bool;

    public function nowExpression(): string;
}
