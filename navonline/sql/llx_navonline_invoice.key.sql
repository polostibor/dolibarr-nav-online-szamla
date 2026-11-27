ALTER TABLE llx_navonline_invoice ADD INDEX idx_navonline_invoice_status (status);
-- The unique key (fk_facture, entity, environment) is created by update_4.sql (it must run after the environment column exists).
