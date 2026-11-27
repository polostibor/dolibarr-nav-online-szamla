-- v3: technical annulment (manageAnnulment) columns. Queue status 4 = annulment requested, 5 = annulled
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_code varchar(32) NULL AFTER date_checked;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_reason text NULL AFTER annul_code;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_transaction_id varchar(64) NULL AFTER annul_reason;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_status varchar(32) NULL AFTER annul_transaction_id;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_messages text NULL AFTER annul_status;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_date_sent datetime NULL AFTER annul_messages;
ALTER TABLE llx_navonline_invoice ADD COLUMN annul_date_checked datetime NULL AFTER annul_date_sent;
