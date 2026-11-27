-- NavOnline: precision of the Dolibarr exchange rate columns (NOT run automatically by the module).
--
-- Dolibarr stores multicurrency_tx as "foreign currency units per 1 HUF" with 8 decimals: 1/369.57 = 0.00270585,
-- which gives back 369.5696 instead of the MNB rate 369.57 (HUF amounts and the NAV exchangeRate are computed from
-- it). With 12 decimals the MNB rate is restored exactly (to 6 decimals, as NAV requires).
-- Applied on 2026-11-24 to all 17 tables. Re-apply after a Dolibarr upgrade if a migration resets the columns:
--   SELECT table_name, column_type FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND column_name = 'multicurrency_tx';

ALTER TABLE llx_commande MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_commande_fournisseur MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_expensereport MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_facture MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_facture_fourn MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_facture_fourn_rec MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_facture_rec MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_paiement_facture MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_paiementfourn_facturefourn MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_paymentexpensereport_expensereport MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_product_fournisseur_price MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_product_fournisseur_price_log MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_product_price MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_product_price_by_qty MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_propal MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
ALTER TABLE llx_societe_remise_except MODIFY multicurrency_tx DOUBLE(24,12) NULL;
ALTER TABLE llx_supplier_proposal MODIFY multicurrency_tx DOUBLE(24,12) NULL DEFAULT 1;
