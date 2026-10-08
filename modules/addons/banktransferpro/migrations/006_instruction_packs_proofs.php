<?php

declare(strict_types=1);

use BankTransferPro\Migration\ColumnEnsurer;
use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 6,
    'name' => '006_instruction_packs_proofs',
    'up' => static function (SqlExecutorInterface $ex): void {
        ColumnEnsurer::ensure($ex, 'mod_btp_payment_proofs', [
            'payment_reference' => "ALTER TABLE `mod_btp_payment_proofs` ADD COLUMN `payment_reference` varchar(32) NOT NULL DEFAULT ''",
            'pack_id' => "ALTER TABLE `mod_btp_payment_proofs` ADD COLUMN `pack_id` varchar(16) NOT NULL DEFAULT ''",
            'rail_reference' => "ALTER TABLE `mod_btp_payment_proofs` ADD COLUMN `rail_reference` varchar(64) NOT NULL DEFAULT ''",
            'declared_amount' => 'ALTER TABLE `mod_btp_payment_proofs` ADD COLUMN `declared_amount` decimal(16,2) NULL',
            'declared_currency' => "ALTER TABLE `mod_btp_payment_proofs` ADD COLUMN `declared_currency` char(3) NOT NULL DEFAULT ''",
        ]);

        if (! $ex->tableExists('mod_btp_payment_proofs')) {
            return;
        }

        $database = (string) ($ex->fetchScalar('SELECT DATABASE()') ?? '');
        if ($database === '') {
            return;
        }

        $indexExists = (int) ($ex->fetchScalar(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '"
            . addslashes($database)
            . "' AND table_name = 'mod_btp_payment_proofs' AND index_name = 'idx_mod_btp_proofs_reference'"
        ) ?? 0);

        if ($indexExists === 0) {
            $ex->execute('ALTER TABLE `mod_btp_payment_proofs` ADD KEY `idx_mod_btp_proofs_reference` (`payment_reference`)');
        }
    },
];
