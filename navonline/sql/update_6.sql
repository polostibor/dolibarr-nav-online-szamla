-- v7: inbound invoices - items of a batch modification (batchInvoice) as separate rows, and a flag when the NAV data
-- of an already recorded invoice changed (technical annulment and new report by the supplier)
ALTER TABLE llx_navonline_inbound ADD COLUMN batch_index integer DEFAULT 0 NOT NULL AFTER invoice_number;
ALTER TABLE llx_navonline_inbound ADD COLUMN nav_changed smallint DEFAULT 0 NOT NULL AFTER status;
ALTER TABLE llx_navonline_inbound DROP INDEX uk_navonline_inbound;
ALTER TABLE llx_navonline_inbound ADD UNIQUE INDEX uk_navonline_inbound (entity, environment, supplier_taxid, invoice_number, batch_index);
