-- NavOnline: Hungarian wording of the Dolibarr core (translation overrides, llx_overwrite_trans, hu_HU).
-- Run by Dolibarr at every activation of the module (data*.sql). A key already overridden is kept as it is
-- (duplicate key: accepted by run_sql), so a text changed later in Setup > Translation is not reset.

-- Vevői számlák: „vevői” a „vásárlói” / „ügyfél számla” helyett, vevői számlánál „kiegyenlítés” a „kifizetés” helyett
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BillsCustomer', 'Vevői számla');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BillsCustomers', 'Vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BillsCustomersUnpaid', 'Kiegyenlítetlen vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BillsCustomersUnpaidForCompany', 'Kiegyenlítetlen vevői számlák – %s');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BlockedLogBillDownload', 'Vevői számla letöltése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BoxLastCustomerBills', 'A legfrissebb vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BoxOldestUnpaidCustomerBills', 'A legrégebbi kiegyenlítetlen vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'BoxTitleOldestUnpaidCustomerBills', 'Vevői számlák: a legrégebbi %s kiegyenlítetlen');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CalcModeLT1', ' <b>%sRE mód a vevői számlákon - szállítói számlák%s</b>');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerAbsoluteDiscountAllUsers', 'Abszolút vevői kedvezmények (minden felhasználó által biztosított)');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerAbsoluteDiscountMy', 'Abszolút vevői kedvezmények (Ön által biztosított)');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerDiscounts', 'Vevői kedvezmények');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerInvoice', 'Vevői számla');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerInvoicePayment', 'Vevői fizetés');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerMargins', 'Vevői árrések');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomerRelativeDiscount', 'Relatív vevői kedvezmény');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomersDraftInvoices', 'Vevői számlatervezetek');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomersInvoices', 'Vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomersInvoicesAndInvoiceLines', 'Vevői számlák és számlaadatok');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomersInvoicesAndPayments', 'Vevői számlák és kiegyenlítések');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CustomersVentilation', 'Vevői számlakötés');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'DeStockOnBill', 'A valós készletek csökkentése a vevői számla/jóváírás érvényesítésével');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Delays_MAIN_DELAY_CUSTOMER_BILLS_UNPAYED', 'Kiegyenlítetlen vevői számla');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'EMailTextInvoicePayed', 'A(z) %s számla kiegyenlítve.');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'ExportDataset_invoice_1', 'Vevői számlák és számla részletei');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'ForCustomersInvoices', 'Vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'ForCustomersOrders', 'Vevői rendelések');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'InvoiceCustomer', 'Vevői számla');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'InvoiceSentByEMail', 'A(z) %s vevői számla e-mailben elküldve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'LastCustomersBills', 'A legutóbbi %s vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'NoUnpaidCustomerBills', 'Nincsenek kiegyenlítetlen vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Notify_BILL_PAYED', 'A vevői számla kiegyenlítve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Notify_BILL_VALIDATE', 'A vevői számla érvényesítve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'PaymentCustomerInvoice', 'Vevői számla kiegyenlítése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'PaymentRegisteredAndInvoiceSetToPaid', 'A befizetés rögzítve, a(z) %s számla kiegyenlítve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Permission1321', 'Vevői számlák, attribútumok és kiegyenlítések exportálása');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Permission16', 'Vevői számlák kiegyenlítésének rögzítése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'PredefinedMailContentSendInvoiceReminder', '__(Helló)__\\n\\nSzeretnénk emlékeztetni, hogy úgy tűnik, hogy a számla __REF__ nem került kiegyenlítésre. A számla másolata emlékeztetőül mellékelve.\\n\\n__ONLINE_PAYMENT_TEXT_AND_URL__\\n\\n__(Tisztelettel)__\\n\\n__SENDEREMAIL_SIGNATURE__');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'RelatedRecurringCustomerInvoices', 'Kapcsolódó ismétlődő vevői számlák');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'SearchACustomerInvoice', 'Keressen egy vevői számlát');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'SearchIntoCustomerPayments', 'Vevői fizetések');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'SearchIntoCustomerShipments', 'Vevői kiszállítások');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'TypeContact_facture_external_BILLING', 'Vevői számla Kapcsolat');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'TypeContact_propal_external_BILLING', 'A vevői számla kapcsolattartója');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'TypeContact_shipping_external_BILLING', 'A vevői számla kapcsolattartója');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logBILL_DELETE', 'A vevői számla logikailag törölve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logBILL_PAYED', 'A vevői számla kiegyenlítve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logBILL_SENTBYMAIL', 'A vevői számla küldése postai úton');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logBILL_UNPAYED', 'A vevői számla kiegyenlítetlenre állítva');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logBILL_VALIDATE', 'A vevői számla érvényesítve');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'logPAYMENT_CUSTOMER', 'Vevői fizetések');

-- Számla nélküli banki mozgások: „Egyéb kifizetések” (az angol „Manual Journal Entries” helyett)
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'MenuVariousPayment', 'Egyéb kifizetések');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'SearchIntoMiscPayments', 'Egyéb kifizetések');

-- Árrés modul: „Árrés részletei”, „Haszonkulcs”, „Árréskulcs” (a „Margórészletek”, „Árrés arány”, „Jelzés arány” helyett)
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'CheckMargins', 'Árrés részletei');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'DisplayMarginRates', 'Haszonkulcs megjelenítése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'DisplayMarkRates', 'Árréskulcs megjelenítése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'MarginRate', 'Haszonkulcs');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'MarkRate', 'Árréskulcs');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'ModifyMarginRates', 'Haszonkulcs módosítása');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'Module59000Name', 'Árrés');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'ShowMarginInfos', 'Árrés-információk megjelenítése');
INSERT INTO llx_overwrite_trans (entity, lang, transkey, transvalue) VALUES (__ENTITY__, 'hu_HU', 'margesSetup', 'Az árréskezelés beállítása');
