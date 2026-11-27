-- NavOnline: fee codes switched on for a product. factor: fee base per product unit (e.g. kg of packaging per piece);
-- the fee base of a sold line = quantity x factor.
CREATE TABLE llx_navonline_epr_product (
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	fk_product     integer NOT NULL,
	type           varchar(1) DEFAULT 'E' NOT NULL,
	kt_code        varchar(16) NOT NULL,
	factor         double(24,8) DEFAULT 1 NOT NULL,
	import_key     varchar(32) NULL,
	datec          datetime NULL,
	tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
