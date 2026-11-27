ALTER TABLE llx_navonline_epr_line ADD INDEX idx_navonline_epr_line_invoice (entity, fk_facture);
ALTER TABLE llx_navonline_epr_line ADD INDEX idx_navonline_epr_line_det (fk_facturedet);
ALTER TABLE llx_navonline_epr_line ADD INDEX idx_navonline_epr_line_date (entity, invoice_date);
