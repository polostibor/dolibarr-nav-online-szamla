-- NAV Online Számla: queue / state of customer invoices to report
-- status: 0=pending, 1=sent (waiting for result), 2=done (accepted), 3=error,
--         4=technical annulment requested, 5=annulled (NAV VERIFICATION_DONE), 9=cancelled (not to be sent)

CREATE TABLE llx_navonline_invoice (
	rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity          integer DEFAULT 1 NOT NULL,
	environment     varchar(8) DEFAULT 'test' NOT NULL,
	fk_facture      integer NOT NULL,
	invoice_ref     varchar(128) NOT NULL,
	invoice_type    smallint DEFAULT 0 NOT NULL,
	operation       varchar(8) NULL,
	status          smallint DEFAULT 0 NOT NULL,
	nav_status      varchar(16) NULL,
	transaction_id  varchar(64) NULL,
	request_id      varchar(64) NULL,
	error_message   text NULL,
	nav_messages    text NULL,
	xml_file        varchar(255) NULL,
	date_sent       datetime NULL,
	date_checked    datetime NULL,
	annul_code      varchar(32) NULL,
	annul_reason    text NULL,
	annul_transaction_id varchar(64) NULL,
	annul_status   varchar(32) NULL,
	annul_messages  text NULL,
	annul_date_sent datetime NULL,
	annul_date_checked datetime NULL,
	date_alerted    datetime NULL,
	datec           datetime NOT NULL,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat   integer NULL
) ENGINE=innodb;
