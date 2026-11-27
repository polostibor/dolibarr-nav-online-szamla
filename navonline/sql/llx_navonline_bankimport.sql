-- NavOnline: bank statement entries imported from the bank (camt.053 statement, camt.052-like account history), one
-- row per entry. status: 0 = open (no Dolibarr bank line), 1 = paired with a Dolibarr bank line (fk_bank),
-- 2 = reconciled (paired, and the entry is on an imported statement: the bank line is reconciled with stmt_id), 9 = ignored
CREATE TABLE llx_navonline_bankimport (
	rowid                integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity               integer DEFAULT 1 NOT NULL,
	fk_account           integer NOT NULL,
	entry_key            varchar(64) NOT NULL,
	booking_date         date NOT NULL,
	value_date           date NULL,
	amount               double(24,8) NOT NULL,
	currency             varchar(3) NULL,
	counterparty         varchar(255) NULL,
	counterparty_account varchar(64) NULL,
	remittance           varchar(255) NULL,
	tx_code              varchar(32) NULL,
	tx_info              varchar(255) NULL,
	bank_ref             varchar(64) NULL,
	end_to_end           varchar(64) NULL,
	stmt_id              varchar(32) NULL,
	source               varchar(8) NULL,
	fk_bank              integer NULL,
	status               smallint DEFAULT 0 NOT NULL,
	note                 varchar(255) NULL,
	import_file          varchar(255) NULL,
	date_import          datetime NULL,
	fk_user              integer NULL,
	tms                  timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
