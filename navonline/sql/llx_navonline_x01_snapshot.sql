-- NavOnline: invoice data at issue for the tax authority audit export (adóhatósági ellenőrzési adatszolgáltatás,
-- 23/2014. (VI. 30.) NGM rendelet 11/A. §), one row per validation of a customer invoice, never updated.
-- xml: invoiceData 3.0 (with the name and address of a private person customer, OSS lines with their rate).
-- source: validation (made at validation) | backfill (made later from the data of that day: not_at_issue = 1)
CREATE TABLE llx_navonline_x01_snapshot (
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	fk_facture     integer NOT NULL,
	ref            varchar(64) NOT NULL,
	series         varchar(16) NULL,
	seq            bigint NULL,
	invoice_type   smallint NOT NULL,
	issue_date     date NULL,
	delivery_date  date NULL,
	currency       varchar(3) NULL,
	total_ht       double(24,8) NULL,
	total_tva      double(24,8) NULL,
	total_ttc      double(24,8) NULL,
	xml            mediumtext NULL,
	xml_sha256     varchar(64) NULL,
	xsd_ok         smallint DEFAULT 0 NOT NULL,
	errors         text NULL,
	warnings       text NULL,
	source         varchar(16) DEFAULT 'validation' NOT NULL,
	not_at_issue   smallint DEFAULT 0 NOT NULL,
	datec          datetime NULL,
	fk_user        integer NULL
) ENGINE=innodb;
