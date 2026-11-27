ALTER TABLE llx_navonline_inbound ADD UNIQUE INDEX uk_navonline_inbound (entity, environment, supplier_taxid, invoice_number);
ALTER TABLE llx_navonline_inbound ADD INDEX idx_navonline_inbound_status (entity, status, issue_date);
