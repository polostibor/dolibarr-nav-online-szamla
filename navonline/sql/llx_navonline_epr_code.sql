-- NavOnline: EPR (kiterjesztett gyártói felelősség) and product fee (termékdíj) codes with their rate.
-- type: E = EPR, T = termékdíj; kt_code: KT / CSK code; rate: fee per unit (HUF), unit: fee base unit (kg);
-- date_start / date_end: validity of the rate (a new rate is a new row from its first day).
CREATE TABLE llx_navonline_epr_code (
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	type           varchar(1) DEFAULT 'E' NOT NULL,
	kt_code        varchar(16) NOT NULL,
	label          varchar(255) NOT NULL,
	rate           double(24,8) DEFAULT 0 NOT NULL,
	unit           varchar(8) DEFAULT 'kg' NOT NULL,
	note           text NULL,
	date_start     date NULL,
	date_end       date NULL,
	active         smallint DEFAULT 1 NOT NULL,
	import_key     varchar(32) NULL,
	datec          datetime NULL,
	tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
