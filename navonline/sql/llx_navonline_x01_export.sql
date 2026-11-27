-- NavOnline: log of the tax authority audit exports (adóhatósági ellenőrzési adatszolgáltatás): who, when, which
-- selection, how many documents, complete or not, and the SHA-256 of the ZIP kept in documents/navonline/x01
CREATE TABLE llx_navonline_x01_export (
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	datec          datetime NOT NULL,
	fk_user        integer NULL,
	mode           varchar(8) NOT NULL,
	criteria       varchar(255) NOT NULL,
	nb_docs        integer DEFAULT 0 NOT NULL,
	nb_ok          integer DEFAULT 0 NOT NULL,
	complete       smallint DEFAULT 0 NOT NULL,
	zip_name       varchar(255) NULL,
	zip_sha256     varchar(64) NULL
) ENGINE=innodb;
