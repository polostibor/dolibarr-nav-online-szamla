-- v2: NAV processing result columns (applied on module (re)activation; "column already exists" errors are ignored)
ALTER TABLE llx_navonline_invoice ADD COLUMN nav_status varchar(16) NULL AFTER status;
ALTER TABLE llx_navonline_invoice ADD COLUMN nav_messages text NULL AFTER error_message;
ALTER TABLE llx_navonline_invoice ADD COLUMN xml_file varchar(255) NULL AFTER nav_messages;
ALTER TABLE llx_navonline_invoice ADD COLUMN date_checked datetime NULL AFTER date_sent;
