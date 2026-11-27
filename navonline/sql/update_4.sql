-- v5: environment of each queue row (test / prod). One row per invoice and environment.
ALTER TABLE llx_navonline_invoice ADD COLUMN environment varchar(8) DEFAULT 'test' NOT NULL AFTER entity;
UPDATE llx_navonline_invoice SET environment = 'test' WHERE environment IS NULL OR environment = '';
ALTER TABLE llx_navonline_invoice DROP INDEX uk_navonline_invoice_fk_facture;
ALTER TABLE llx_navonline_invoice ADD UNIQUE INDEX uk_navonline_invoice_fk_facture_env (fk_facture, entity, environment);
