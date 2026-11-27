ALTER TABLE llx_navonline_bankimport ADD UNIQUE INDEX uk_navonline_bankimport (entity, fk_account, entry_key);
ALTER TABLE llx_navonline_bankimport ADD INDEX idx_navonline_bankimport_status (entity, fk_account, status, booking_date);
ALTER TABLE llx_navonline_bankimport ADD INDEX idx_navonline_bankimport_bank (fk_bank);
