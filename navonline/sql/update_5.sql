-- v6: e-mail alert throttling (last alert sent for this row)
ALTER TABLE llx_navonline_invoice ADD COLUMN date_alerted datetime NULL AFTER annul_date_checked;
