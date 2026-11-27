-- v4: NAV operation actually used when sending (CREATE / MODIFY / STORNO)
ALTER TABLE llx_navonline_invoice ADD COLUMN operation varchar(8) NULL AFTER invoice_type;
