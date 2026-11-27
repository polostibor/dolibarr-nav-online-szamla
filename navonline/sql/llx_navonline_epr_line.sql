-- NavOnline: fee data of a customer invoice line, recorded at validation (Hungarian domestic sales), never updated.
-- base = qty x factor (signed: negative on a credit note), amount = base x rate. A credit note line takes the code,
-- factor and rate of the original invoice line (fk_source = the fee row of the original line).
CREATE TABLE llx_navonline_epr_line (
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	fk_facture     integer NOT NULL,
	fk_facturedet  integer NOT NULL,
	fk_product     integer NOT NULL,
	invoice_type   smallint NOT NULL,
	invoice_date   date NULL,
	delivery_date  date NULL,
	type           varchar(1) NOT NULL,
	kt_code        varchar(16) NOT NULL,
	label          varchar(255) NULL,
	qty            double(24,8) NOT NULL,
	factor         double(24,8) NOT NULL,
	base           double(24,8) NOT NULL,
	unit           varchar(8) NOT NULL,
	rate           double(24,8) NOT NULL,
	amount         double(24,8) NOT NULL,
	fk_source      integer NULL,
	datec          datetime NULL,
	fk_user        integer NULL
) ENGINE=innodb;
