-- NAV Online Számla module: VIES checks of the community VAT numbers of intra-Community customers (KBAET / KBAUK),
-- one row per query; request_identifier = VIES consultation number (proof of the check, with our VAT number as requester).
-- status: valid, invalid, unavailable

CREATE TABLE llx_navonline_vies (
	rowid               integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity              integer DEFAULT 1 NOT NULL,
	vat_number          varchar(20) NOT NULL,
	status              varchar(12) NOT NULL,
	request_identifier  varchar(32) NULL,
	request_date        datetime NOT NULL,
	name                varchar(255) NULL,
	address             varchar(512) NULL,
	error               varchar(64) NULL,
	fk_soc              integer NULL,
	element             varchar(32) NULL,
	fk_element          integer NULL,
	fk_user             integer NULL
) ENGINE=innodb;
