<?php

declare(strict_types=1);

use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 4,
    'name' => '004_bank_presentation_fields',
    'up' => static function (SqlExecutorInterface $ex): void {
        if (! $ex->tableExists('mod_btp_banks')) {
            return;
        }

        $database = (string) ($ex->fetchScalar('SELECT DATABASE()') ?? '');
        if ($database === '') {
            return;
        }

        $columns = [
            'invoice_label' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `invoice_label` varchar(255) NOT NULL DEFAULT '' AFTER `display_name`",
            'upi_id' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `upi_id` varchar(255) NOT NULL DEFAULT '' AFTER `invoice_label`",
            'account_name' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `account_name` varchar(255) NOT NULL DEFAULT '' AFTER `upi_id`",
            'account_number' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `account_number` varchar(255) NOT NULL DEFAULT '' AFTER `account_name`",
            'ifsc_code' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `ifsc_code` varchar(100) NOT NULL DEFAULT '' AFTER `account_number`",
        ];

        foreach ($columns as $column => $sql) {
            $exists = (int) ($ex->fetchScalar(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '"
                . addslashes($database)
                . "' AND table_name = 'mod_btp_banks' AND column_name = '"
                . addslashes($column)
                . "'"
            ) ?? 0);

            if ($exists === 0) {
                $ex->execute($sql);
            }
        }
    },
];
