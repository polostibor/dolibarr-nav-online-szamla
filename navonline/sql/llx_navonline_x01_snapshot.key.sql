ALTER TABLE llx_navonline_x01_snapshot ADD INDEX idx_navonline_x01_invoice (entity, fk_facture);
ALTER TABLE llx_navonline_x01_snapshot ADD INDEX idx_navonline_x01_series (entity, series, seq);
ALTER TABLE llx_navonline_x01_snapshot ADD INDEX idx_navonline_x01_date (entity, issue_date);
