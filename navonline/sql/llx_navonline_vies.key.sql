ALTER TABLE llx_navonline_vies ADD INDEX idx_navonline_vies_vat (entity, vat_number, status, request_date);
ALTER TABLE llx_navonline_vies ADD INDEX idx_navonline_vies_element (element, fk_element);
