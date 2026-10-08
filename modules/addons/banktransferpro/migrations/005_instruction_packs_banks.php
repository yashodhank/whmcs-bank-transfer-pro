<?php

declare(strict_types=1);

use BankTransferPro\Migration\ColumnEnsurer;
use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 5,
    'name' => '005_instruction_packs_banks',
    'up' => static function (SqlExecutorInterface $ex): void {
        if (! $ex->tableExists('mod_btp_banks')) {
            return;
        }

        ColumnEnsurer::ensure($ex, 'mod_btp_banks', [
            'country_code' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `country_code` char(2) NOT NULL DEFAULT '' AFTER `currency_code`",
            'capabilities' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `capabilities` text NULL',
            'identifiers' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `identifiers` text NULL',
            'beneficiary_address' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `beneficiary_address` text NULL',
            'bank_address' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `bank_address` text NULL',
            'intermediary_bic' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `intermediary_bic` varchar(11) NOT NULL DEFAULT ''",
            'prefer_charge_code' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `prefer_charge_code` varchar(3) NOT NULL DEFAULT 'OUR'",
            'accept_fx_receive' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `accept_fx_receive` tinyint(1) NOT NULL DEFAULT 0',
            'wire_purpose_hint' => "ALTER TABLE `mod_btp_banks` ADD COLUMN `wire_purpose_hint` varchar(255) NOT NULL DEFAULT ''",
            'pack_notes' => 'ALTER TABLE `mod_btp_banks` ADD COLUMN `pack_notes` text NULL',
        ]);

        // Backfill legacy rows. Idempotent: only touches rows that have not been migrated yet.
        // Rows with an IFSC or UPI are Indian receive profiles.
        $ex->execute(
            "UPDATE `mod_btp_banks` SET `country_code` = 'IN'
             WHERE `country_code` = '' AND (`ifsc_code` <> '' OR `upi_id` <> '')"
        );

        // identifiers JSON from legacy columns (JSON_QUOTE keeps this valid on MySQL 5.7+ / MariaDB 10.2+).
        $ex->execute(
            "UPDATE `mod_btp_banks` SET `identifiers` = CONCAT('{', TRIM(BOTH ',' FROM CONCAT(
                IF(`ifsc_code` <> '', CONCAT('\"ifsc\":', JSON_QUOTE(UPPER(`ifsc_code`)), ','), ''),
                IF(`upi_id` <> '', CONCAT('\"upi\":', JSON_QUOTE(`upi_id`)), '')
            )), '}')
             WHERE (`identifiers` IS NULL OR `identifiers` = '') AND (`ifsc_code` <> '' OR `upi_id` <> '')"
        );

        // capabilities inferred from what each row already had.
        $ex->execute(
            "UPDATE `mod_btp_banks` SET `capabilities` = CONCAT('[', TRIM(BOTH ',' FROM CONCAT(
                IF(`ifsc_code` <> '' OR `account_number` <> '', '\"local_transfer\",', ''),
                IF(`upi_id` <> '', '\"instant_alias\"', '')
            )), ']')
             WHERE (`capabilities` IS NULL OR `capabilities` = '')
               AND (`ifsc_code` <> '' OR `account_number` <> '' OR `upi_id` <> '')"
        );
    },
];
