<?php
/* Copyright (C) 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/navonline/core/triggers/interface_99_modNavOnline_NavOnline.class.php
 * \ingroup navonline
 * \brief   Triggers of the NavOnline module.
 *
 * The trigger only queues invoices into llx_navonline_invoice. No network call is made here,
 * so a NAV outage can never block or roll back the validation of an invoice. With NAVONLINE_SEND_ON_VALIDATE
 * (default on) the queued invoice is sent at the end of the request, after the validation is committed.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once dirname(__DIR__, 2).'/lib/navonline.lib.php';

/**
 * Class of triggers for NavOnline module
 */
class InterfaceNavOnline extends DolibarrTriggers
{
	const STATUS_PENDING = 0;
	const STATUS_SENT = 1;
	const STATUS_DONE = 2;
	const STATUS_ERROR = 3;
	const STATUS_ANNUL_REQUESTED = 4;
	const STATUS_ANNULLED = 5;
	const STATUS_CANCELLED = 9;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'financial';
		$this->description = 'NAV Online Számla: queue customer invoices for reporting.';
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'bill';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param string       $action Event action code
	 * @param CommonObject $object Object
	 * @param User         $user   Object user
	 * @param Translate    $langs  Object langs
	 * @param Conf         $conf   Object conf
	 * @return int                 <0 if KO, 0 if nothing done, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('navonline')) {
			return 0;
		}

		// Payment mode <-> payment term pairing (NAVONLINE_PAYMENT_PAIRING): partners and customer documents
		if (in_array($action, array('COMPANY_CREATE', 'COMPANY_MODIFY', 'BILL_SUPPLIER_MODIFY'), true) && is_object($object) && !empty($object->element) && $object->element === 'societe') {
			// Shipping method chosen on the new partner form (field added by ActionsNavonline::formObjectOptions)
			if ($action === 'COMPANY_CREATE' && GETPOSTINT('navonline_shipping_method_id') > 0) {
				$sm = GETPOSTINT('navonline_shipping_method_id');
				if ($this->db->query('UPDATE '.$this->db->prefix().'societe SET fk_shipping_method = '.((int) $sm).' WHERE rowid = '.((int) $object->id))) {
					$object->shipping_method_id = $sm;
				}
			}
			return $this->pairPaymentSociete($object);
		}
		if (in_array($action, array('BILL_CREATE', 'BILL_MODIFY', 'BILL_VALIDATE', 'PROPAL_CREATE', 'PROPAL_MODIFY', 'PROPAL_VALIDATE', 'ORDER_CREATE', 'ORDER_MODIFY', 'ORDER_VALIDATE'), true)) {
			$this->pairPaymentDocument($object, in_array($action, array('BILL_VALIDATE', 'PROPAL_VALIDATE', 'ORDER_VALIDATE'), true));
		}

		switch ($action) {
			case 'SHIPPING_CREATE':
				return $this->defaultIncoterms($object);
			case 'BILL_CREATE':
				$this->defaultIncoterms($object);
				$this->creditNoteSourceRate($object);
				$this->bankAccountForCurrency($object, $conf);
				return $this->deliveryDateFromShipment($object);
			case 'PROPAL_CREATE':
			case 'ORDER_CREATE':
				$this->defaultIncoterms($object);
				return $this->bankAccountForCurrency($object, $conf);
			case 'BILL_MODIFY':
			case 'PROPAL_MODIFY':
			case 'ORDER_MODIFY':
				return $this->bankAccountForCurrency($object, $conf);
			case 'PROPAL_VALIDATE':
			case 'ORDER_VALIDATE':
				if ($this->mixedZeroCases($object) < 0 || $this->reverseChargeBuyer($object) < 0 || $this->subjectExemptSeller($object) < 0 || $this->intraCommunityBuyer($object) < 0 || $this->exportAndGoodsOnly($object) < 0 || $this->ossMixed($object) < 0) {
					return -1;
				}
				return $this->bankAccountForCurrency($object, $conf, true);
			case 'BILL_VALIDATE':
				if ($this->unsupportedInvoiceType($object) < 0 || $this->mixedZeroCases($object) < 0 || $this->reverseChargeBuyer($object) < 0 || $this->subjectExemptSeller($object) < 0 || $this->intraCommunityBuyer($object) < 0 || $this->exportAndGoodsOnly($object) < 0 || $this->ossMixed($object) < 0) {
					return -1;	// validation refused (nothing is reported to NAV)
				}
				// Teljesítés dátuma first: the PDF (generated after validation) and the NAV XML must show the same date
				$this->stornoDeliveryDate($object);
				$this->creditNoteDeliveryDate($object);
				$this->deliveryDateDefault($object);
				// The rate may have changed on the draft (setMulticurrencyRate leaves deduction lines alone): before the
				// PDF and the NAV XML are made, the deposit deductions are recalculated at the final invoice rate again
				// Exchange rate of the day the tax liability arises (Áfa tv. 80. §), then the deposit deductions at it
				$rateChanged = $this->isCustomerInvoice($object) && $this->invoiceRateAtTaxPoint($object) > 0;
				if ($this->isCustomerInvoice($object) && ($this->depositDeductionsAtInvoiceRate((int) $object->id) > 0 || $rateChanged)) {
					$this->reloadInvoiceAmounts($object);
				}
				$this->bankAccountForCurrency($object, $conf);
				$ret = $this->billValidate($object, $user, $conf);
				if ($ret >= 0) {
					$this->closeZeroFinalInvoice($object, $user);
					$this->auditSnapshot($object, $user);
					$this->eprRecord($object, $user);
				}
				return $ret;
			case 'BILL_DELETE':
				// A deleted invoice (test invoices before go-live; an issued invoice cannot be deleted in production,
				// INVOICE_CAN_NEVER_BE_REMOVED) leaves no audit export data behind
				if ($this->isCustomerInvoice($object)) {
					$this->db->query('DELETE FROM '.$this->db->prefix().'navonline_x01_snapshot WHERE fk_facture = '.((int) $object->id));
					$this->db->query('DELETE FROM '.$this->db->prefix().'navonline_epr_line WHERE fk_facture = '.((int) $object->id));
				}
				return $this->billWithdraw($action, $object, $conf);
			case 'BILL_UNVALIDATE':
				// Back to draft: the fee data is recorded again at the next validation
				if ($this->isCustomerInvoice($object)) {
					$this->db->query('DELETE FROM '.$this->db->prefix().'navonline_epr_line WHERE fk_facture = '.((int) $object->id));
				}
				return $this->billWithdraw($action, $object, $conf);
			case 'BILL_CANCEL':
				return $this->billWithdraw($action, $object, $conf);
			case 'LINEBILL_INSERT':
				$this->exactExportEurPrice($action, $object);
				$this->depositVatCode($object);
				$this->depositDeductionLineAtInvoiceRate($object);
				return $this->discountLinesLast($object);
			case 'LINEPROPAL_INSERT':
			case 'LINEORDER_INSERT':
				return $this->exactExportEurPrice($action, $object);
			case 'STOCK_MOVEMENT':
				return $this->plainStockMovementLabel($object);
			case 'BILL_SUPPLIER_VALIDATE':
				// Supplier credit note recorded from NAV: reduces the remain to pay of its original invoice
				if (is_object($object) && ($object->element ?? '') === 'invoice_supplier' && !empty($object->id)) {
					$this->applyNavSupplierCredits((int) $object->type === 2 ? (int) $object->fk_facture_source : (int) $object->id, $user);
				}
				return 0;
		}

		return 0;
	}

	/**
	 * Replacement (helyesbítő, TYPE_REPLACEMENT) and situation (részszámla, TYPE_SITUATION) invoices are invoices that
	 * must be reported, but the XML builder supports only standard, deposit and credit note invoices. Validation is
	 * refused, so no invoice exists that cannot be reported. Only for a Hungarian issuer, as billValidate().
	 *
	 * @param Facture $object Invoice being validated
	 * @return int -1 = refused, 0 = OK
	 */
	private function unsupportedInvoiceType($object)
	{
		global $langs, $mysoc;

		if (!$this->isCustomerInvoice($object) || empty($mysoc->country_code) || $mysoc->country_code !== 'HU') {
			return 0;
		}
		$type = (int) $object->type;
		if ($type !== Facture::TYPE_REPLACEMENT && $type !== Facture::TYPE_SITUATION) {
			return 0;
		}
		$langs->load('navonline@navonline');
		$this->errors[] = $langs->trans($type === Facture::TYPE_REPLACEMENT ? 'NavOnlineErrReplacementType' : 'NavOnlineErrSituationType');
		$this->error = end($this->errors);
		return -1;
	}

	/**
	 * Supplier credit notes recorded from NAV (llx_navonline_inbound, STORNO / reducing MODIFY) of a supplier invoice:
	 * once both the credit note and its original are validated, the credit note is used on the original as Dolibarr does
	 * by hand ("Convert into discount", then "use" it on the invoice: fourn/facture/card.php): one discount per VAT rate
	 * from the credit note, which is closed as paid, applied to the original; the original is closed as paid when nothing
	 * is left to pay (STORNO). Called when either of them is validated. Not when the credit is larger than what is left
	 * to pay (the original already paid, partly or fully: the supplier owes a refund) - then a warning. Credit notes
	 * entered by hand are left alone.
	 *
	 * @param int  $sourceId Original supplier invoice
	 * @param User $user     User
	 * @return int Number of credit notes applied
	 */
	private function applyNavSupplierCredits($sourceId, $user)
	{
		global $langs;

		if ($sourceId <= 0) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
		$p = $this->db->prefix();
		$src = new FactureFournisseur($this->db);
		if ($src->fetch($sourceId) <= 0 || (int) $src->status !== FactureFournisseur::STATUS_VALIDATED || !empty($src->paid)) {
			return 0;	// draft (applied when it is validated), already paid or abandoned
		}
		// Validated NAV credit notes of this original, not yet converted into a discount
		$sql = 'SELECT f.rowid FROM '.$p.'facture_fourn f JOIN '.$p.'navonline_inbound n ON n.fk_facture_fourn = f.rowid';
		$sql .= ' WHERE f.fk_facture_source = '.((int) $sourceId).' AND f.type = '.FactureFournisseur::TYPE_CREDIT_NOTE.' AND f.fk_statut = '.FactureFournisseur::STATUS_VALIDATED.' AND f.paye = 0';
		$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$p.'societe_remise_except r WHERE r.fk_invoice_supplier_source = f.rowid)';
		$sql .= ' GROUP BY f.rowid ORDER BY f.rowid';
		$res = $this->db->query($sql);
		if (!$res) {
			return $this->softFail('supplier credit lookup failed: '.$this->db->lasterror());
		}
		$ids = array();
		while ($o = $this->db->fetch_object($res)) {
			$ids[] = (int) $o->rowid;
		}
		$langs->load('navonline@navonline');
		$applied = 0;
		foreach ($ids as $cnId) {
			$cn = new FactureFournisseur($this->db);
			if ($cn->fetch($cnId) <= 0) {
				continue;
			}
			$remain = (float) price2num($src->getRemainToPay(0), 'MT');
			$credit = (float) price2num(abs((float) $cn->total_ttc), 'MT');
			if ($credit <= 0) {
				continue;
			}
			if ($credit > $remain + 0.005) {
				setEventMessages($langs->trans('NavOnlineSupplierCreditNotApplied', $cn->ref_supplier, price($credit), $src->ref_supplier, price($remain)), null, 'warnings');
				dol_syslog(__METHOD__.' credit note '.$cn->ref.' ('.$credit.') not applied to '.$src->ref.': remain to pay '.$remain, LOG_WARNING);
				continue;
			}
			// One discount per VAT rate (a credit note may mix lines of both signs: each rate keeps its sign)
			$sum = array();
			foreach ($cn->lines as $l) {
				if ((int) $l->product_type >= 9 || (float) $l->total_ht == 0) {
					continue;
				}
				$k = $l->tva_tx.($l->vat_src_code ? ' ('.$l->vat_src_code.')' : '');
				foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $f) {
					$sum[$k][$f] = ($sum[$k][$f] ?? 0) + (float) $l->$f;
				}
			}
			$discounts = array();
			foreach ($sum as $k => $a) {
				$d = new DiscountAbsolute($this->db);
				$d->description = '(CREDIT_NOTE)';
				$d->discount_type = 1;	// supplier
				$d->fk_soc = $d->socid = $cn->socid;
				$d->fk_invoice_supplier_source = $cn->id;
				$d->multicurrency_code = $cn->multicurrency_code;
				$d->multicurrency_tx = $cn->multicurrency_tx;
				$d->amount_ht = -$a['total_ht'];
				$d->amount_tva = -$a['total_tva'];
				$d->amount_ttc = -$a['total_ttc'];
				$d->multicurrency_amount_ht = $d->multicurrency_total_ht = -$a['multicurrency_total_ht'];
				$d->multicurrency_amount_tva = $d->multicurrency_total_tva = -$a['multicurrency_total_tva'];
				$d->multicurrency_amount_ttc = $d->multicurrency_total_ttc = -$a['multicurrency_total_ttc'];
				$d->vat_src_code = preg_match('/\((.*)\)/', $k, $m) ? $m[1] : '';
				$d->tva_tx = abs((float) preg_replace('/\s*\(.*\)/', '', $k));
				if ($d->create($user) < 0) {
					return $this->softFail('supplier credit discount failed: '.$d->error);
				}
				$discounts[] = $d;
			}
			if ($cn->setPaid($user) < 0) {
				return $this->softFail('supplier credit note close failed: '.$cn->error);
			}
			foreach ($discounts as $d) {
				if ($d->link_to_invoice(0, $src->id) < 0) {
					return $this->softFail('supplier credit use failed: '.$d->error);
				}
			}
			$applied++;
			$src->fetch($src->id);
			$left = (float) price2num($src->getRemainToPay(0), 'MT');
			if (abs($left) < 0.005) {
				$src->setPaid($user, '', 'NAV: '.$cn->ref_supplier);
			}
			setEventMessages($langs->trans('NavOnlineSupplierCreditApplied', $cn->ref_supplier, price($credit), $src->ref_supplier, price($left)), null, 'mesgs');
			dol_syslog(__METHOD__.' credit note '.$cn->ref.' ('.$credit.') used on '.$src->ref.', left to pay '.$left);
		}
		return $applied;
	}

	/**
	 * Data at issue for the tax authority audit export (adóhatósági ellenőrzési adatszolgáltatás): snapshot of the
	 * invoice at the end of its validation, when everything set here (teljesítés dátuma, rate, deposit deductions) is
	 * in the database. Never blocks the validation: an incomplete snapshot is stored with its errors (shown by the
	 * export), a database error is only logged. Only for a Hungarian issuer.
	 *
	 * @param Facture $object Invoice being validated
	 * @param User    $user   User
	 * @return void
	 */
	private function auditSnapshot($object, $user)
	{
		global $mysoc;

		if (!$this->isCustomerInvoice($object) || empty($mysoc->country_code) || $mysoc->country_code !== 'HU' || (int) $object->type === Facture::TYPE_PROFORMA) {
			return;
		}
		dol_include_once('/navonline/class/navonlinex01.class.php');
		try {
			$x = new NavOnlineX01($this->db);
			if ($x->snapshot((int) $object->id, 'validation', $user) < 0) {
				$this->softFail('audit export snapshot failed: '.implode('; ', $x->errors));
			}
		} catch (Throwable $e) {
			$this->softFail('audit export snapshot failed: '.$e->getMessage());
		}
	}

	/**
	 * EPR / product fee data of the invoice lines (Hungarian domestic sales), recorded at the end of the validation.
	 * Never blocks the validation: a line without valid rate is left out with a warning, a database error is logged.
	 *
	 * @param Facture $object Invoice being validated
	 * @param User    $user   User
	 * @return void
	 */
	private function eprRecord($object, $user)
	{
		if (!$this->isCustomerInvoice($object)) {
			return;
		}
		dol_include_once('/navonline/class/navonlineepr.class.php');
		try {
			$e = new NavOnlineEpr($this->db);
			if ($e->record($object, $user) < 0) {
				$this->softFail('EPR fee data failed: '.implode('; ', $e->errors));
			}
			foreach ($e->warnings as $w) {
				dol_syslog('NavOnline EPR: '.$object->ref.': '.$w, LOG_WARNING);
				if (function_exists('setEventMessages') && !defined('NOREQUIREHTML')) {
					setEventMessages('EPR: '.$w, null, 'warnings');
				}
			}
		} catch (Throwable $e) {
			$this->softFail('EPR fee data failed: '.$e->getMessage());
		}
	}

	/**
	 * Végszámla whose deposits cover the whole amount (total 0): nothing to pay, so it is closed as paid right at
	 * validation (Dolibarr would leave it among the unpaid invoices until "Classify paid" is pressed).
	 *
	 * @param Facture $invoice Invoice being validated
	 * @param User    $user    User
	 * @return void
	 */
	private function closeZeroFinalInvoice($invoice, $user)
	{
		if (!navonlineFinalInvoiceNoPayment($this->db, $invoice) || !empty($invoice->paye)) {
			return;
		}
		if ($invoice->setPaid($user) < 0) {
			dol_syslog('NavOnline: final invoice '.$invoice->ref.' could not be closed as paid: '.$invoice->error, LOG_WARNING);
		} else {
			dol_syslog('NavOnline: final invoice '.$invoice->ref.' has total 0, closed as paid');
		}
	}

	/**
	 * Deposit line created from an order / proposal ("(DEPOSIT) (50%) - SO…", compta/facture/card.php): Dolibarr groups
	 * the source amounts by the numeric VAT rate only, so a 0% line loses its VAT code (EAM, KBAET…), which NAV needs.
	 * When the line is 0% without code and the 0% lines of the linked source document all carry the same code, the
	 * deposit line gets that code. Several different 0% codes are left for the user to choose.
	 *
	 * @param FactureLigne $line New invoice line
	 * @return int 0
	 */
	private function depositVatCode($line)
	{
		$fk = (int) ($line->fk_facture ?? 0);
		if ($fk <= 0 || (float) $line->tva_tx != 0.0 || !empty($line->vat_src_code) || !empty($line->fk_remise_except)
			|| strpos(trim((string) $line->desc), '(DEPOSIT)') !== 0) {
			return 0;
		}
		$p = $this->db->prefix();
		$res = $this->db->query('SELECT type, fk_statut FROM '.$p.'facture WHERE rowid = '.$fk);
		$inv = $res ? $this->db->fetch_object($res) : null;
		if (!$inv || (int) $inv->type !== 3 || (int) $inv->fk_statut !== 0) {	// Facture::TYPE_DEPOSIT, draft
			return 0;
		}
		$codes = array();
		foreach (array('commande' => array('commandedet', 'fk_commande'), 'propal' => array('propaldet', 'fk_propal')) as $type => $t) {
			$sql = 'SELECT DISTINCT d.vat_src_code FROM '.$p.'element_element ee JOIN '.$p.$t[0].' d ON d.'.$t[1].' = ee.fk_source';
			$sql .= " WHERE ee.sourcetype = '".$type."' AND ee.targettype = 'facture' AND ee.fk_target = ".$fk.' AND d.tva_tx = 0 AND d.product_type < 9';
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				$codes[(string) $o->vat_src_code] = true;
			}
		}
		if (count($codes) !== 1 || key($codes) === '') {
			return 0;
		}
		$code = (string) key($codes);
		if ($this->db->query('UPDATE '.$p."facturedet SET vat_src_code = '".$this->db->escape($code)."' WHERE rowid = ".((int) $line->id))) {
			$line->vat_src_code = $code;
			dol_syslog('NavOnline: deposit line '.$line->id.' got VAT code '.$code.' from its source document');
		}
		return 0;
	}

	/**
	 * New deposit deduction line on a draft foreign currency invoice (Facture::insert_discount): HUF amounts at the
	 * rate of this invoice, see depositDeductionsAtInvoiceRate(). insert_discount() sums the lines from the database
	 * (update_price) right after this trigger, so the invoice totals follow.
	 *
	 * @param FactureLigne $line New invoice line
	 * @return int 0
	 */
	private function depositDeductionLineAtInvoiceRate($line)
	{
		$fk = (int) ($line->fk_facture ?? 0);
		if ($fk <= 0 || empty($line->fk_remise_except) || empty($line->id)) {
			return 0;
		}
		$res = $this->db->query('SELECT fk_statut FROM '.$this->db->prefix().'facture WHERE rowid = '.$fk);
		$inv = $res ? $this->db->fetch_object($res) : null;
		if (!$inv || (int) $inv->fk_statut !== 0) {
			return 0;
		}
		if ($this->depositDeductionsAtInvoiceRate($fk, (int) $line->id) > 0) {
			$o = $this->db->fetch_object($this->db->query('SELECT subprice, total_ht, total_tva, total_ttc FROM '.$this->db->prefix().'facturedet WHERE rowid = '.((int) $line->id)));
			if ($o) {
				$line->subprice = (float) $o->subprice;
				$line->total_ht = (float) $o->total_ht;
				$line->total_tva = (float) $o->total_tva;
				$line->total_ttc = (float) $o->total_ttc;
			}
		}
		return 0;
	}

	/**
	 * Deposit deductions of a foreign currency final invoice at the rate of the final invoice.
	 *
	 * NAV (interface specification 3.0, 2.9 "Előlegszámla, végszámla adatszolgáltatása"): the final invoice has a single
	 * exchange rate, the deducted advance is converted at that rate too; the rate of the advance only goes to
	 * advancePaymentData/advanceExchangeRate (accounting information). Dolibarr copies the HUF amounts of the deposit
	 * (its rate) onto the deduction line, which gives a wrong HUF VAT base. The amounts in the invoice currency are
	 * kept (those of the deposit), the HUF amounts are recalculated with the rate reported to NAV (6 decimals); the
	 * difference to the HUF amount of the deposit is an exchange difference in the accounting. The invoice totals are
	 * summed again from the lines when a line was changed.
	 *
	 * @param int $invoiceId Invoice id
	 * @param int $lineId    Only this line (0 = every deposit deduction line of the invoice)
	 * @return int Number of lines changed, <0 on error
	 */
	private function depositDeductionsAtInvoiceRate($invoiceId, $lineId = 0)
	{
		$p = $this->db->prefix();
		$res = $this->db->query('SELECT multicurrency_code, multicurrency_tx FROM '.$p.'facture WHERE rowid = '.((int) $invoiceId));
		$inv = $res ? $this->db->fetch_object($res) : null;
		$code = $inv ? strtoupper((string) $inv->multicurrency_code) : '';
		$tx = $inv ? (float) $inv->multicurrency_tx : 0;
		if ($code === '' || $code === 'HUF' || $tx <= 0) {
			return 0;
		}
		$rate = round(1 / $tx, 6);	// as NavOnlineXmlBuilder::exchangeRate(): 1 foreign = rate HUF

		$sql = 'SELECT d.rowid, d.qty, d.total_ht, d.total_tva, d.multicurrency_total_ht, d.multicurrency_total_tva, s.ref, s.multicurrency_code as src_code';
		$sql .= ' FROM '.$p.'facturedet d JOIN '.$p.'societe_remise_except r ON r.rowid = d.fk_remise_except';
		$sql .= ' JOIN '.$p.'facture s ON s.rowid = r.fk_facture_source AND s.type = '.Facture::TYPE_DEPOSIT;
		$sql .= ' WHERE d.fk_facture = '.((int) $invoiceId).($lineId > 0 ? ' AND d.rowid = '.((int) $lineId) : '');
		$res = $this->db->query($sql);
		if (!$res) {
			return $this->softFail('deposit deduction lookup failed: '.$this->db->lasterror());
		}
		$changed = 0;
		while ($o = $this->db->fetch_object($res)) {
			if (strtoupper((string) $o->src_code) !== $code) {
				dol_syslog('NavOnline: deposit '.$o->ref.' is in '.$o->src_code.', the invoice in '.$code.': deduction line '.$o->rowid.' left as it is', LOG_WARNING);
				continue;
			}
			$ht = (float) price2num((float) $o->multicurrency_total_ht * $rate, 'MT');
			$tva = (float) price2num((float) $o->multicurrency_total_tva * $rate, 'MT');
			if (abs($ht - (float) $o->total_ht) < 0.005 && abs($tva - (float) $o->total_tva) < 0.005) {
				continue;
			}
			$qty = (float) $o->qty != 0.0 ? (float) $o->qty : 1;
			$sqlu = 'UPDATE '.$p.'facturedet SET subprice = '.price2num($ht / $qty, 'MU').', total_ht = '.price2num($ht).', total_tva = '.price2num($tva).', total_ttc = '.price2num($ht + $tva);
			$sqlu .= ' WHERE rowid = '.((int) $o->rowid);
			if (!$this->db->query($sqlu)) {
				return $this->softFail('deposit deduction update failed: '.$this->db->lasterror());
			}
			dol_syslog('NavOnline: deduction of deposit '.$o->ref.' (line '.$o->rowid.') at the invoice rate '.$rate.': HT '.$o->total_ht.' -> '.$ht.' HUF');
			$changed++;
		}
		if ($changed > 0) {
			$sqlt = 'UPDATE '.$p.'facture f JOIN (SELECT fk_facture, SUM(total_ht) ht, SUM(total_tva) tva, SUM(total_ttc) ttc FROM '.$p.'facturedet WHERE fk_facture = '.((int) $invoiceId).' GROUP BY fk_facture) t ON t.fk_facture = f.rowid';
			$sqlt .= ' SET f.total_ht = t.ht, f.total_tva = t.tva, f.total_ttc = t.ttc WHERE f.rowid = '.((int) $invoiceId);
			if (!$this->db->query($sqlt)) {
				return $this->softFail('invoice total update failed: '.$this->db->lasterror());
			}
		}
		return $changed;
	}

	/**
	 * Foreign currency invoice: at validation, the MNB rate valid on the day the tax liability arises (Áfa tv. 80. §).
	 * Dolibarr sets the rate when the draft is created (MULTICURRENCY_USE_RATE_ON_DOCUMENT_DATE) and keeps it when the
	 * invoice date moves to the validation day (FAC_FORCE_DATE_VALIDATION), so a draft left for days, or a supply made
	 * before, has the rate of another day. Day used: the teljesítés dátuma (date_pointoftax, set just before), else the
	 * invoice date; an intra-Community supply (only KBAET / KBAUK lines) uses the invoice date (Áfa tv. 63. §). Rate:
	 * the MNB rate of that day, else the last one published before (weekend, holiday, or not yet published that day),
	 * from the rates synced by NavOnlineMnb, else read from MNB. The amounts in the invoice currency are kept, the HUF
	 * amounts of the lines and of the invoice are recalculated. Not for credit notes (rate of the original invoice,
	 * creditNoteSourceRate()). Off with NAVONLINE_RATE_AT_TAX_POINT = 0.
	 *
	 * @param Facture $object Invoice being validated
	 * @return int 1 = rate changed, 0 = nothing done
	 */
	private function invoiceRateAtTaxPoint($object)
	{
		global $mysoc, $langs;

		if (!getDolGlobalInt('NAVONLINE_RATE_AT_TAX_POINT', 1) || (int) $object->type === Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		$p = $this->db->prefix();
		$id = (int) $object->id;
		$res = $this->db->query('SELECT fk_multicurrency, multicurrency_code, multicurrency_tx FROM '.$p.'facture WHERE rowid = '.$id);
		$inv = $res ? $this->db->fetch_object($res) : null;
		$code = $inv ? strtoupper((string) $inv->multicurrency_code) : '';
		if (!$inv || $code === '' || $code === 'HUF' || (float) $inv->multicurrency_tx <= 0) {
			return 0;
		}
		$res = $this->db->query('SELECT SUM(CASE WHEN UPPER(vat_src_code) IN (\'KBAET\', \'KBAUK\') THEN 1 ELSE 0 END) AS kb, COUNT(*) AS n FROM '.$p.'facturedet WHERE fk_facture = '.$id.' AND product_type <> 9 AND (fk_remise_except IS NULL OR fk_remise_except = 0)');
		$o = $res ? $this->db->fetch_object($res) : null;
		$kbOnly = $o && (int) $o->n > 0 && (int) $o->kb === (int) $o->n;
		$day = (!$kbOnly && !empty($object->date_pointoftax)) ? (int) $object->date_pointoftax : (int) $object->date;
		if ($day <= 0) {
			return 0;
		}
		list($tx, $rateDay) = $this->mnbRateForDay((int) $inv->fk_multicurrency, $code, $day);
		if ($tx <= 0) {
			$langs->load('navonline@navonline');
			setEventMessages($langs->trans('NavOnlineRateNotFound', $code, dol_print_date($day, 'day')), null, 'warnings');
			dol_syslog(__METHOD__.' invoice id='.$id.': no MNB '.$code.' rate for '.dol_print_date($day, 'day').', rate left as it is', LOG_WARNING);
			return 0;
		}
		$old = (float) $inv->multicurrency_tx;
		if (abs(round(1 / $tx, 6) - round(1 / $old, 6)) < 0.0000005) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
		$sql = 'SELECT rowid, qty, remise_percent, tva_tx, localtax1_tx, localtax2_tx, info_bits, product_type, situation_percent, multicurrency_subprice';
		$sql .= ' FROM '.$p.'facturedet WHERE fk_facture = '.$id.' AND product_type <> 9 AND (fk_remise_except IS NULL OR fk_remise_except = 0)';
		$res = $this->db->query($sql);
		if (!$res) {
			return $this->softFail('rate: line lookup failed: '.$this->db->lasterror());
		}
		while ($l = $this->db->fetch_object($res)) {
			$vat = (float) preg_replace('/\s*\(.*\)/', '', (string) $l->tva_tx);
			$t = calcul_price_total($l->qty, 0, (float) $l->remise_percent, $vat, $l->localtax1_tx, $l->localtax2_tx, 0, 'HT', (int) $l->info_bits, (int) $l->product_type, $mysoc, array(), $l->situation_percent !== null ? (float) $l->situation_percent : 100, $tx, (float) $l->multicurrency_subprice);
			$u = 'UPDATE '.$p.'facturedet SET subprice = '.price2num($t[3]).', total_ht = '.price2num($t[0]).', total_tva = '.price2num($t[1]);
			$u .= ', total_localtax1 = '.price2num($t[9]).', total_localtax2 = '.price2num($t[10]).', total_ttc = '.price2num($t[2]);
			$u .= ', multicurrency_total_ht = '.price2num($t[16]).', multicurrency_total_tva = '.price2num($t[17]).', multicurrency_total_ttc = '.price2num($t[18]);
			$u .= ' WHERE rowid = '.((int) $l->rowid);
			if (!$this->db->query($u)) {
				return $this->softFail('rate: line update failed: '.$this->db->lasterror());
			}
		}
		$sqlt = 'UPDATE '.$p.'facture f JOIN (SELECT fk_facture, SUM(total_ht) ht, SUM(total_tva) tva, SUM(total_ttc) ttc, SUM(multicurrency_total_ht) mht, SUM(multicurrency_total_tva) mtva, SUM(multicurrency_total_ttc) mttc';
		$sqlt .= ' FROM '.$p.'facturedet WHERE fk_facture = '.$id.' GROUP BY fk_facture) t ON t.fk_facture = f.rowid';
		$sqlt .= ' SET f.multicurrency_tx = '.((float) $tx).', f.total_ht = t.ht, f.total_tva = t.tva, f.total_ttc = t.ttc';
		$sqlt .= ', f.multicurrency_total_ht = t.mht, f.multicurrency_total_tva = t.mtva, f.multicurrency_total_ttc = t.mttc WHERE f.rowid = '.$id;
		if (!$this->db->query($sqlt)) {
			return $this->softFail('rate: invoice update failed: '.$this->db->lasterror());
		}
		$object->multicurrency_tx = (float) $tx;
		dol_syslog(__METHOD__.' invoice id='.$id.' '.$code.' rate '.round(1 / $old, 6).' -> '.round(1 / $tx, 6).' HUF (MNB '.$rateDay.', tax point '.dol_print_date($day, 'day').')');
		return 1;
	}

	/**
	 * MNB rate valid on a day: the rate of that day, else the last one published before it (at most 10 days back).
	 * From llx_multicurrency_rate (synced by NavOnlineMnb); when missing there, read from MNB.
	 *
	 * @param int    $fkCurrency Multicurrency id
	 * @param string $code       ISO code
	 * @param int    $day        Timestamp of the day
	 * @return array{0:float,1:string} Dolibarr rate (foreign units per 1 HUF, 0 if none) and the MNB day (Y-m-d)
	 */
	private function mnbRateForDay($fkCurrency, $code, $day)
	{
		$y = (int) dol_print_date($day, '%Y');
		$m = (int) dol_print_date($day, '%m');
		$d = (int) dol_print_date($day, '%d');
		$end = dol_mktime(23, 59, 59, $m, $d, $y);
		$from = dol_time_plus_duree($end, -10, 'd');
		$sql = 'SELECT rate, date_sync FROM '.$this->db->prefix().'multicurrency_rate WHERE fk_multicurrency = '.((int) $fkCurrency);
		$sql .= ' AND entity IN ('.getEntity('multicurrency').')';
		$sql .= " AND date_sync <= '".$this->db->idate($end)."' AND date_sync > '".$this->db->idate($from)."' ORDER BY date_sync DESC LIMIT 1";
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o && (float) $o->rate > 0) {
			// a rate synced for an earlier day: MNB may have published the rate of $day meanwhile, ask MNB only then
			$syncDay = dol_print_date($this->db->jdate($o->date_sync), '%Y-%m-%d');
			if ($syncDay === sprintf('%04d-%02d-%02d', $y, $m, $d)) {
				return array((float) $o->rate, $syncDay);
			}
		}
		dol_include_once('/navonline/class/navonlinemnb.class.php');
		$mnb = new NavOnlineMnb($this->db);
		$rates = $mnb->fetchMnb(dol_print_date($from, '%Y-%m-%d'), sprintf('%04d-%02d-%02d', $y, $m, $d), array($code));
		if (is_array($rates) && $rates) {
			krsort($rates);
			foreach ($rates as $mnbDay => $list) {
				if ($mnbDay <= sprintf('%04d-%02d-%02d', $y, $m, $d) && !empty($list[$code]) && (float) $list[$code] > 0) {
					return array(1 / (float) $list[$code], (string) $mnbDay);
				}
			}
		}
		// MNB not reachable: the last synced rate before the day, if any
		if ($o && (float) $o->rate > 0) {
			return array((float) $o->rate, dol_print_date($this->db->jdate($o->date_sync), '%Y-%m-%d'));
		}
		return array(0.0, '');
	}

	/**
	 * Refresh the HUF amounts of the invoice object (lines and totals) from the database.
	 *
	 * @param Facture $invoice Invoice
	 * @return void
	 */
	private function reloadInvoiceAmounts($invoice)
	{
		$o = $this->db->fetch_object($this->db->query('SELECT total_ht, total_tva, total_ttc FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $invoice->id)));
		if ($o) {
			$invoice->total_ht = (float) $o->total_ht;
			$invoice->total_tva = (float) $o->total_tva;
			$invoice->total_ttc = (float) $o->total_ttc;
		}
		if (method_exists($invoice, 'fetch_lines')) {
			$invoice->fetch_lines();
		}
	}

	/**
	 * Deduction lines (deposit / credit note / excess received applied as a line: fk_remise_except) go after the item
	 * lines of a draft invoice. Dolibarr inserts them with rang -1, i.e. first; a final invoice shows the deposit
	 * deduction last ("végszámla"). Renumbers the lines: items in their order, then deductions in theirs.
	 *
	 * @param FactureLigne $line New invoice line
	 * @return int 0
	 */
	private function discountLinesLast($line)
	{
		$fk = (int) ($line->fk_facture ?? 0);
		if ($fk <= 0) {
			return 0;
		}
		$p = $this->db->prefix();
		$res = $this->db->query('SELECT fk_statut FROM '.$p.'facture WHERE rowid = '.$fk);
		$inv = $res ? $this->db->fetch_object($res) : null;
		if (!$inv || (int) $inv->fk_statut !== 0) {
			return 0;
		}
		$res = $this->db->query('SELECT rowid, rang FROM '.$p.'facturedet WHERE fk_facture = '.$fk.' ORDER BY (fk_remise_except IS NOT NULL), rang, rowid');
		if (!$res) {
			return 0;
		}
		$i = 0;
		while ($o = $this->db->fetch_object($res)) {
			$i++;
			if ((int) $o->rang !== $i) {
				$this->db->query('UPDATE '.$p.'facturedet SET rang = '.$i.' WHERE rowid = '.((int) $o->rowid));
				if ((int) $o->rowid === (int) $line->id) {
					$line->rang = $i;
				}
			}
		}
		return 0;
	}

	/**
	 * Fixed export price in EUR (see ActionsNavonline::getSellPrice): the hook can only return a HUF unit price, so the
	 * EUR unit price of the new line is EUR price / rate * rate, which may differ by rounding (339.49999). When the line
	 * is within 0.05 EUR of the product's export_price_eur on a EUR document of an export-level customer, the line is
	 * recalculated from the exact EUR unit price. A manually entered, different price is left alone. The document totals
	 * are recalculated by addline() right after this trigger.
	 *
	 * @param string           $action Trigger code
	 * @param CommonObjectLine $line   New line
	 * @return int 0
	 */
	private function exactExportEurPrice($action, $line)
	{
		global $mysoc;

		$map = array('LINEBILL_INSERT' => array('facture', 'facturedet', 'fk_facture'), 'LINEPROPAL_INSERT' => array('propal', 'propaldet', 'fk_propal'), 'LINEORDER_INSERT' => array('commande', 'commandedet', 'fk_commande'));
		list($parentTable, $lineTable, $fkField) = $map[$action];
		$parentId = (int) ($line->$fkField ?? 0);
		if (empty($line->id) || $parentId <= 0 || empty($line->fk_product) || (int) ($line->special_code ?? 0) !== 0) {
			return 0;
		}
		$sql = 'SELECT d.multicurrency_code, d.multicurrency_tx, s.price_level FROM '.$this->db->prefix().$parentTable.' d';
		$sql .= ' JOIN '.$this->db->prefix().'societe s ON s.rowid = d.fk_soc WHERE d.rowid = '.$parentId;
		$doc = $this->db->fetch_object($this->db->query($sql));
		if (!$doc || strtoupper((string) $doc->multicurrency_code) !== 'EUR' || (float) $doc->multicurrency_tx <= 0 || (int) $doc->price_level !== getDolGlobalInt('NAVONLINE_EXPORT_PRICE_LEVEL', 4)) {
			return 0;
		}
		$res = $this->db->query('SELECT export_price_eur FROM '.$this->db->prefix().'product_extrafields WHERE fk_object = '.((int) $line->fk_product));
		$o = $res ? $this->db->fetch_object($res) : null;
		$eur = $o ? (float) $o->export_price_eur : 0;
		$cur = (float) $line->multicurrency_subprice;
		if ($eur <= 0 || abs($cur - $eur) >= 0.05 || abs($cur - $eur) < 0.000001) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
		$vat = (float) preg_replace('/\s*\(.*\)/', '', (string) $line->tva_tx);
		$t = calcul_price_total($line->qty, 0, (float) $line->remise_percent, $vat, $line->localtax1_tx, $line->localtax2_tx, 0, 'HT', (int) $line->info_bits, (int) $line->product_type, $mysoc, array(), isset($line->situation_percent) ? (float) $line->situation_percent : 100, (float) $doc->multicurrency_tx, $eur);
		$sql = 'UPDATE '.$this->db->prefix().$lineTable.' SET subprice = '.price2num($t[3]).', total_ht = '.price2num($t[0]).', total_tva = '.price2num($t[1]);
		$sql .= ', total_localtax1 = '.price2num($t[9]).', total_localtax2 = '.price2num($t[10]).', total_ttc = '.price2num($t[2]);
		$sql .= ', multicurrency_subprice = '.price2num($t[19]).', multicurrency_total_ht = '.price2num($t[16]).', multicurrency_total_tva = '.price2num($t[17]).', multicurrency_total_ttc = '.price2num($t[18]);
		$sql .= ' WHERE rowid = '.((int) $line->id);
		if ($this->db->query($sql)) {
			$line->subprice = $t[3];
			$line->total_ht = $t[0];
			$line->total_tva = $t[1];
			$line->total_ttc = $t[2];
			$line->multicurrency_subprice = $t[19];
			$line->multicurrency_total_ht = $t[16];
			$line->multicurrency_total_tva = $t[17];
			$line->multicurrency_total_ttc = $t[18];
		}

		return 0;
	}

	/**
	 * Queue a validated customer invoice (or reset it to pending if it was queued before and not yet sent).
	 *
	 * @param Facture $object Invoice
	 * @param User    $user   User
	 * @param Conf    $conf   Conf
	 * @return int
	 */
	private function billValidate($object, User $user, Conf $conf)
	{
		global $mysoc;

		if (!$this->isCustomerInvoice($object)) {
			return 0;
		}

		// Only a Hungarian issuer reports to NAV
		if (empty($mysoc->country_code) || $mysoc->country_code !== 'HU') {
			dol_syslog(__METHOD__.' skipped: issuer company country is not HU', LOG_DEBUG);
			return 0;
		}

		// At BILL_VALIDATE time $object->ref may still be the (PROVxx) ref; the definitive number is in newref
		$ref = !empty($object->newref) ? $object->newref : $object->ref;
		$entity = (int) (!empty($object->entity) ? $object->entity : $conf->entity);

		$existing = $this->fetchQueueRow((int) $object->id, $entity);
		if ($existing === false) {
			return $this->softFail('fetch queue row failed: '.$this->db->lasterror());
		}

		// Documents outside the NAV data reporting, recorded as cancelled with the reason and never sent:
		// - proforma invoice (Facture::TYPE_PROFORMA): not an invoice under the VAT Act, like the díjbekérő of an order;
		// - OSS invoice (only foreign / OSS VAT lines, place of supply in another member state): it goes to the
		//   quarterly OSS return. A mixed invoice (OSS + Hungarian lines) is refused at validation (ossMixed()).
		$msg = '';
		if ((int) $object->type === Facture::TYPE_PROFORMA) {
			$msg = 'Proforma: nem számla, nem tartozik a NAV-adatszolgáltatás alá';
		} elseif ($this->isOssOnlyInvoice((int) $object->id)) {
			$msg = 'OSS-számla: nem tartozik a NAV-adatszolgáltatás alá (negyedéves OSS-bevallásban kell szerepeltetni)';
		}
		if ($msg !== '') {
			if ($existing && in_array((int) $existing->status, array(self::STATUS_SENT, self::STATUS_DONE, self::STATUS_ANNUL_REQUESTED), true)) {
				return 0;
			}
			if ($existing) {
				$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
				$sql .= " invoice_ref = '".$this->db->escape($ref)."', invoice_type = ".((int) $object->type).', status = '.self::STATUS_CANCELLED;
				$sql .= ", error_message = '".$this->db->escape($msg)."' WHERE rowid = ".((int) $existing->rowid);
			} else {
				$sql = 'INSERT INTO '.$this->db->prefix().'navonline_invoice';
				$sql .= ' (entity, environment, fk_facture, invoice_ref, invoice_type, status, error_message, datec, fk_user_creat) VALUES (';
				$sql .= $entity.", '".navonlineGetEnvironment()."', ".((int) $object->id).", '".$this->db->escape($ref)."', ".((int) $object->type);
				$sql .= ', '.self::STATUS_CANCELLED.", '".$this->db->escape($msg)."', '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
			}
			if (!$this->db->query($sql)) {
				return $this->softFail('not-reported queue record failed: '.$this->db->lasterror());
			}
			dol_syslog(__METHOD__.' invoice id='.$object->id.' ref='.$ref.' not reported to NAV: '.$msg, LOG_INFO);
			return 1;
		}

		if ($existing) {
			if (in_array((int) $existing->status, array(self::STATUS_SENT, self::STATUS_DONE, self::STATUS_ANNUL_REQUESTED), true)) {
				// Already reported to NAV: a re-validation needs a modification/annulment flow (later step)
				dol_syslog(__METHOD__.' invoice id='.$object->id.' already reported to NAV (status='.$existing->status.'), not re-queued', LOG_WARNING);
				return 0;
			}

			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
			$sql .= " invoice_ref = '".$this->db->escape($ref)."'";
			$sql .= ', invoice_type = '.((int) $object->type);
			$sql .= ', status = '.self::STATUS_PENDING;
			$sql .= ', error_message = NULL';
			$sql .= ' WHERE rowid = '.((int) $existing->rowid);
		} else {
			$sql = 'INSERT INTO '.$this->db->prefix().'navonline_invoice';
			$sql .= ' (entity, environment, fk_facture, invoice_ref, invoice_type, status, datec, fk_user_creat) VALUES (';
			$sql .= $entity;
			$sql .= ", '".navonlineGetEnvironment()."'";
			$sql .= ', '.((int) $object->id);
			$sql .= ", '".$this->db->escape($ref)."'";
			$sql .= ', '.((int) $object->type);
			$sql .= ', '.self::STATUS_PENDING;
			$sql .= ", '".$this->db->idate(dol_now())."'";
			$sql .= ', '.((int) $user->id);
			$sql .= ')';
		}

		if (!$this->db->query($sql)) {
			return $this->softFail('queue write failed: '.$this->db->lasterror());
		}

		dol_syslog(__METHOD__.' invoice id='.$object->id.' ref='.$ref.' queued for NAV', LOG_INFO);
		self::scheduleImmediateSend((int) $object->id);
		return 1;
	}

	/**
	 * Teljesítés dátuma (date_pointoftax) of an invoice created from shipment(s): the date of the (latest) delivery note.
	 * Delivery note date = planned delivery date (printed on the delivery note), else shipping, validation, creation date.
	 * A date given by the user on the creation form is kept; it stays editable until the invoice is validated.
	 *
	 * @param Facture $object Invoice
	 * @return int
	 */
	private function deliveryDateFromShipment($object)
	{
		if (!$this->isCustomerInvoice($object) || !empty($object->date_pointoftax)) {
			return 0;
		}
		$date = $this->latestShipmentDate((int) $object->id);

		return $date ? $this->setDeliveryDate($object, $date) : 0;
	}

	/**
	 * An intra-Community supply (KBAET, KBAUK) and an export (EAM) cannot be on the same proposal / order / invoice:
	 * validation is refused (the user splits it into two documents).
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function mixedZeroCases($object)
	{
		global $langs;
		$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
		if (!is_object($object) || empty($object->id) || !isset($tables[$object->element ?? ''])) {
			return 0;
		}
		list($table, $fk) = $tables[$object->element];
		$sql = 'SELECT DISTINCT UPPER(vat_src_code) AS c FROM '.$this->db->prefix().$table.' WHERE '.$fk.' = '.((int) $object->id)." AND tva_tx = 0 AND vat_src_code IN ('KBAET', 'KBAUK', 'EAM')";
		$res = $this->db->query($sql);
		$codes = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$codes[] = $o->c;
		}
		if (in_array('EAM', $codes, true) && (in_array('KBAET', $codes, true) || in_array('KBAUK', $codes, true))) {
			$langs->load('navonline@navonline');
			$this->errors[] = $langs->trans('NavOnlineErrMixedEuExport');
			$this->error = end($this->errors);
			return -1;
		}
		return 0;
	}

	/**
	 * Subject exemption (alanyi adómentesség, AAM, Áfa tv. 187. §) is the status of the seller, not of a line: a
	 * proposal / order / invoice with an AAM line is refused unless the VAT code of our tax number is 1, and a line with
	 * VAT is refused when it is 1 (a subject-exempt taxpayer charges no VAT).
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function subjectExemptSeller($object)
	{
		global $langs, $mysoc;
		$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
		if (!is_object($object) || empty($object->id) || !isset($tables[$object->element ?? ''])) {
			return 0;
		}
		list($table, $fk) = $tables[$object->element];
		$sql = "SELECT SUM(CASE WHEN tva_tx = 0 AND UPPER(vat_src_code) = 'AAM' THEN 1 ELSE 0 END) AS aam, MAX(tva_tx) AS maxrate";
		$sql .= ' FROM '.$this->db->prefix().$table.' WHERE '.$fk.' = '.((int) $object->id);
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o) {
			return 0;
		}
		require_once __DIR__.'/../../lib/navonline.lib.php';
		$code = navonlineSellerVatCode($mysoc);
		$langs->load('navonline@navonline');
		if ((int) $o->aam > 0 && $code !== '1') {
			$this->errors[] = $langs->trans('NavOnlineErrAamSeller', $code !== '' ? $code : '?');
		} elseif ($code === '1' && (float) $o->maxrate > 0) {
			$this->errors[] = $langs->trans('NavOnlineErrAamVat', price2num((float) $o->maxrate));
		} else {
			return 0;
		}
		$this->error = end($this->errors);
		return -1;
	}

	/**
	 * Intra-Community exempt supply (KBAET / KBAUK, Áfa tv. 89. §) only to a company in another member state with a
	 * community VAT number of that state (navonlineIntraCommunityBuyerError()): a proposal / order / invoice with such a
	 * line is refused otherwise, as the VAT not charged would be due.
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function intraCommunityBuyer($object)
	{
		global $langs;
		$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
		if (!is_object($object) || empty($object->id) || !isset($tables[$object->element ?? ''])) {
			return 0;
		}
		list($table, $fk) = $tables[$object->element];
		$res = $this->db->query('SELECT COUNT(*) AS n FROM '.$this->db->prefix().$table.' WHERE '.$fk.' = '.((int) $object->id)." AND tva_tx = 0 AND UPPER(vat_src_code) IN ('KBAET', 'KBAUK')");
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o || (int) $o->n === 0) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once __DIR__.'/../../lib/navonline.lib.php';
		$soc = new Societe($this->db);
		if ($soc->fetch((int) $object->socid) <= 0) {
			return 0;
		}
		list($reason, $p1, $p2) = navonlineIntraCommunityBuyerError($soc);
		$langs->load('navonline@navonline');
		if ($reason === '' && getDolGlobalString('NAVONLINE_VIES_CHECK', '1') !== '0') {
			// the format is right: is the number registered? (VIES, logged in llx_navonline_vies with its consultation number)
			global $user;
			require_once __DIR__.'/../../class/navonlinevies.class.php';
			$vies = new NavOnlineVies($this->db);
			$v = $vies->check($soc, (string) $object->element, (int) $object->id, $user);
			if ($v['result'] === 'invalid') {
				$reason = 'NavOnlineErrKbVies';
				$p1 = $v['vat'];
			} elseif ($v['result'] === 'unavailable') {
				$reason = 'NavOnlineErrKbViesDown';
				$p1 = $v['error'];
				$p2 = getDolGlobalString('NAVONLINE_VIES_FALLBACK_DAYS', '30');
			} elseif ($v['result'] === 'unavailable_ok') {
				setEventMessages($langs->trans('NavOnlineWarnViesDown', $v['error'], $v['vat'], dol_print_date(dol_stringtotime($v['last_valid'], 'gmt'), 'day')), null, 'warnings');
			}
		}
		if ($reason === '') {
			return 0;
		}
		$this->errors[] = $langs->trans('NavOnlineErrKb', $soc->name).' '.$langs->trans($reason, $p1, $p2);
		$this->error = end($this->errors);
		return -1;
	}

	/**
	 * Export (EAM, Áfa tv. 98. §) only when the goods leave the EU (navonlineExportError(): the shipping address of the
	 * document, else the customer's); EAM, KBAET and KBAUK only for goods, not for services (those are HO, EUFAD37,
	 * ...). A credit note follows its invoice: only the service rule applies to it.
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function exportAndGoodsOnly($object)
	{
		global $langs;
		$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
		if (!is_object($object) || empty($object->id) || !isset($tables[$object->element ?? ''])) {
			return 0;
		}
		list($table, $fk) = $tables[$object->element];
		$sql = "SELECT SUM(CASE WHEN UPPER(vat_src_code) = 'EAM' THEN 1 ELSE 0 END) AS eam,";
		$sql .= " MAX(CASE WHEN product_type = 1 THEN UPPER(vat_src_code) ELSE '' END) AS service_code";
		$sql .= ' FROM '.$this->db->prefix().$table.' WHERE '.$fk.' = '.((int) $object->id)." AND tva_tx = 0 AND UPPER(vat_src_code) IN ('EAM', 'KBAET', 'KBAUK')";
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o || ((int) $o->eam === 0 && (string) $o->service_code === '')) {
			return 0;
		}
		$langs->load('navonline@navonline');
		if ((string) $o->service_code !== '') {
			$this->errors[] = $langs->trans('NavOnlineErrZeroService', $o->service_code);
			$this->error = end($this->errors);
			return -1;
		}
		if ($object->element === 'facture' && (int) $object->type === 2) {
			return 0;
		}
		require_once __DIR__.'/../../lib/navonline.lib.php';
		list($reason, $p1, $p2) = navonlineExportError($this->db, $object);
		if ($reason === '') {
			return 0;
		}
		// the parameter naming the source of the destination is a lang key
		$params = $reason === 'NavOnlineErrEamHu' ? array($langs->transnoentitiesnoconv($p1), '') : array($p1, $p2 !== '' ? $langs->transnoentitiesnoconv($p2) : '');
		$this->errors[] = $langs->trans('NavOnlineErrEam').' '.$langs->trans($reason, $params[0], $params[1]);
		$this->error = end($this->errors);
		return -1;
	}

	/**
	 * Domestic reverse charge (FAD, Áfa tv. 142. §) only to a Hungarian VAT-registered taxpayer: a proposal / order /
	 * invoice with a FAD line is refused for a foreign buyer, a private person, a buyer without a valid Hungarian tax
	 * number or one that is not subject to VAT on its card.
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function reverseChargeBuyer($object)
	{
		global $langs;
		$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
		if (!is_object($object) || empty($object->id) || !isset($tables[$object->element ?? ''])) {
			return 0;
		}
		list($table, $fk) = $tables[$object->element];
		$res = $this->db->query('SELECT COUNT(*) AS n FROM '.$this->db->prefix().$table.' WHERE '.$fk.' = '.((int) $object->id)." AND UPPER(vat_src_code) = 'FAD'");
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o || (int) $o->n === 0) {
			return 0;
		}
		require_once __DIR__.'/../../lib/navonline.lib.php';
		global $mysoc;
		if (navonlineSellerVatCode($mysoc) === '1') {
			// Áfa tv. 142. § (3): no reverse charge when either party is subject-exempt (AAM)
			$langs->load('navonline@navonline');
			$this->errors[] = $langs->trans('NavOnlineErrFadAamSeller');
			$this->error = end($this->errors);
			return -1;
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		if ($soc->fetch((int) $object->socid) <= 0) {
			return 0;
		}
		$reason = '';
		if (strtoupper((string) $soc->country_code) !== 'HU') {
			$reason = 'NavOnlineErrFadForeign';
		} elseif ((string) $soc->typent_code === 'TE_PRIVATE') {
			$reason = 'NavOnlineErrFadPrivate';
		} elseif (!preg_match('/^\d{8}-?[1-5]-?\d{2}$/', trim((string) $soc->idprof1))) {
			$reason = 'NavOnlineErrFadTaxNumber';
		} elseif (empty($soc->tva_assuj)) {
			$reason = 'NavOnlineErrFadNotVat';
		} elseif (navonlineSellerVatCode($soc) === '1') {
			$reason = 'NavOnlineErrFadAamBuyer';	// Áfa tv. 142. § (3): the buyer is subject-exempt
		}
		if ($reason === '') {
			return 0;
		}
		$langs->load('navonline@navonline');
		$this->errors[] = $langs->trans('NavOnlineErrFad', $soc->name).' '.$langs->trans($reason);
		$this->error = end($this->errors);
		return -1;
	}

	/**
	 * A new proposal / order / shipment / invoice without Incoterms gets those of its source document (order of a
	 * shipment or invoice, proposal of an order), else those of the customer card. Core does it only in the card's
	 * creation form; this covers documents made by workflows, mass actions, API or "create from". Editable afterwards.
	 *
	 * @param CommonObject $object New document
	 * @return int
	 */
	private function defaultIncoterms($object)
	{
		$tables = array('propal' => 'propal', 'commande' => 'commande', 'facture' => 'facture', 'shipping' => 'expedition');
		if (!isModEnabled('incoterm') || !is_object($object) || empty($object->id) || !isset($tables[$object->element ?? '']) || !empty($object->fk_incoterms)) {
			return 0;
		}
		$inco = 0;
		$loc = '';
		$srcTables = array('commande' => 'commande', 'propal' => 'propal', 'shipping' => 'expedition', 'expedition' => 'expedition');
		$origin = (string) ($object->origin_type ?? ($object->origin ?? ''));
		if (isset($srcTables[$origin]) && !empty($object->origin_id)) {
			$res = $this->db->query('SELECT fk_incoterms, location_incoterms FROM '.$this->db->prefix().$srcTables[$origin].' WHERE rowid = '.((int) $object->origin_id));
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o && (int) $o->fk_incoterms > 0) {
				$inco = (int) $o->fk_incoterms;
				$loc = (string) $o->location_incoterms;
			}
		}
		if (!$inco && !empty($object->socid)) {
			$res = $this->db->query('SELECT fk_incoterms, location_incoterms FROM '.$this->db->prefix().'societe WHERE rowid = '.((int) $object->socid));
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o && (int) $o->fk_incoterms > 0) {
				$inco = (int) $o->fk_incoterms;
				$loc = (string) $o->location_incoterms;
			}
		}
		if (!$inco) {
			return 0;
		}
		$sql = 'UPDATE '.$this->db->prefix().$tables[$object->element].' SET fk_incoterms = '.$inco.", location_incoterms = '".$this->db->escape($loc)."' WHERE rowid = ".((int) $object->id).' AND (fk_incoterms IS NULL OR fk_incoterms = 0)';
		if (!$this->db->query($sql)) {
			return $this->softFail('incoterms default failed: '.$this->db->lasterror());
		}
		$object->fk_incoterms = $inco;
		$object->location_incoterms = $loc;
		return 1;
	}

	/**
	 * Core stores stock movement labels made with $langs->trans(), i.e. HTML-encoded ("k&eacute;szlet"): fine on
	 * screen (decoded when shown), unreadable in exports. Stored as plain UTF-8 text instead.
	 *
	 * @param MouvementStock $object Stock movement
	 * @return int
	 */
	private function plainStockMovementLabel($object)
	{
		if (empty($object->id) || strpos((string) $object->label, '&') === false) {
			return 0;
		}
		$plain = html_entity_decode((string) $object->label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($plain === $object->label) {
			return 0;
		}
		if (!$this->db->query('UPDATE '.$this->db->prefix()."stock_mouvement SET label = '".$this->db->escape(dol_trunc($plain, 255, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $object->id))) {
			return $this->softFail('stock movement label update failed: '.$this->db->lasterror());
		}
		$object->label = $plain;
		return 1;
	}

	/**
	 * A credit note in foreign currency is converted at the exchange rate of the invoice it modifies (the HUF VAT of
	 * the modification must match the original), not at the MNB rate of its own date that core takes
	 * (MULTICURRENCY_USE_RATE_ON_DOCUMENT_DATE). Set on BILL_CREATE, before any line is added; the rate can still be
	 * changed by hand on the draft.
	 *
	 * @param Facture $object Invoice
	 * @return int
	 */
	private function creditNoteSourceRate($object)
	{
		if (!$this->isCustomerInvoice($object) || (int) $object->type !== Facture::TYPE_CREDIT_NOTE || empty($object->fk_facture_source)) {
			return 0;
		}
		$res = $this->db->query('SELECT multicurrency_code, multicurrency_tx FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $object->fk_facture_source));
		$src = $res ? $this->db->fetch_object($res) : null;
		if (!$src || (float) $src->multicurrency_tx <= 0 || strtoupper((string) $src->multicurrency_code) !== strtoupper((string) $object->multicurrency_code)) {
			return 0;
		}
		if (abs((float) $src->multicurrency_tx - (float) $object->multicurrency_tx) < 1e-12) {
			return 0;
		}
		$sql = 'UPDATE '.$this->db->prefix().'facture SET multicurrency_tx = '.((float) $src->multicurrency_tx).' WHERE rowid = '.((int) $object->id).' AND fk_statut = 0';
		if (!$this->db->query($sql)) {
			return $this->softFail('credit note rate update failed: '.$this->db->lasterror());
		}
		$object->multicurrency_tx = (float) $src->multicurrency_tx;
		dol_syslog(__METHOD__.' credit note id='.$object->id.' multicurrency_tx='.$object->multicurrency_tx.' (source invoice)', LOG_DEBUG);

		return 1;
	}

	/**
	 * Módosító számla (credit note reported as MODIFY): NAV interface specification 3.0, 2.5.2 point 8 -
	 * invoiceDeliveryDate is the teljesítés dátuma of the original invoice, unless the document modifies it. With a
	 * credit note date in another month NAV warns (UNINTENDED_MODIFICATION_DELIVERY_DATE, active in production since
	 * 2026-01-01; after earlier modifications it compares with the last modifying invoice). So when no teljesítés
	 * dátuma was entered on the draft, the credit note gets the one of the last earlier credit note of the same original,
	 * else that of the original; on the PDF and in the NAV XML alike. A date entered by the user is kept (the credit
	 * note then modifies the date). STORNO: stornoDeliveryDate().
	 *
	 * @param Facture $object Credit note being validated
	 * @return int
	 */
	private function creditNoteDeliveryDate($object)
	{
		if (!$this->isCustomerInvoice($object) || (int) $object->type !== Facture::TYPE_CREDIT_NOTE || empty($object->fk_facture_source) || !empty($object->date_pointoftax)) {
			return 0;
		}
		$src = (int) $object->fk_facture_source;
		$sql = 'SELECT COALESCE(date_pointoftax, datef) AS d FROM '.$this->db->prefix().'facture';
		$sql .= ' WHERE rowid = '.$src.' OR (fk_facture_source = '.$src.' AND type = '.Facture::TYPE_CREDIT_NOTE.' AND fk_statut > 0 AND rowid <> '.((int) $object->id).')';
		$sql .= ' ORDER BY (rowid = '.$src.'), rowid DESC LIMIT 1';	// earlier credit notes (latest first), then the original
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o || empty($o->d)) {
			return 0;
		}
		dol_syslog(__METHOD__.' credit note id='.$object->id.': teljesítés dátuma of the original chain '.$o->d);
		return $this->setDeliveryDate($object, (int) $this->db->jdate($o->d));
	}

	/**
	 * An érvénytelenítő számla (STORNO: exact reversal of the source invoice) carries the teljesítés dátuma of the
	 * invoice it cancels, on the PDF and in the NAV XML, whatever date the draft had.
	 *
	 * @param Facture $object Invoice
	 * @return int
	 */
	private function stornoDeliveryDate($object)
	{
		if (!$this->isCustomerInvoice($object) || (int) $object->type !== Facture::TYPE_CREDIT_NOTE || empty($object->fk_facture_source)) {
			return 0;
		}
		if (navonlineCreditNoteOperation($this->db, $object) !== 'STORNO') {
			return 0;
		}
		$res = $this->db->query('SELECT COALESCE(date_pointoftax, datef) AS d FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $object->fk_facture_source));
		$src = $res ? $this->db->fetch_object($res) : null;
		if (!$src || empty($src->d)) {
			return 0;
		}
		$day = $this->db->jdate($src->d);
		if (!$this->db->query('UPDATE '.$this->db->prefix().'facture SET date_pointoftax = '."'".$this->db->idate($day)."'".' WHERE rowid = '.((int) $object->id))) {
			return $this->softFail('storno date_pointoftax update failed: '.$this->db->lasterror());
		}
		$object->date_pointoftax = $day;

		return 1;
	}

	/**
	 * At validation an empty teljesítés dátuma gets the date of the linked delivery note(s) (the mass action
	 * "create invoices" on the shipment list links the shipments only after BILL_CREATE), else the invoice date
	 * (the NAV module's own fallback). So the date on the PDF and the invoiceDeliveryDate sent to NAV are always
	 * the same stored value.
	 *
	 * @param Facture $object Invoice
	 * @return int
	 */
	private function deliveryDateDefault($object)
	{
		if (!$this->isCustomerInvoice($object) || !empty($object->date_pointoftax)) {
			return 0;
		}
		$date = $this->latestShipmentDate((int) $object->id);
		if (!$date) {
			$date = $object->date;
		}

		return $date ? $this->setDeliveryDate($object, $date) : 0;
	}

	/**
	 * Date of the latest delivery note linked to the invoice, or 0.
	 *
	 * @param int $invoiceId Invoice id
	 * @return int Timestamp
	 */
	private function latestShipmentDate($invoiceId)
	{
		$sql = 'SELECT MAX(COALESCE(e.date_delivery, e.date_expedition, e.date_valid, e.date_creation)) AS d';
		$sql .= ' FROM '.$this->db->prefix().'element_element ee';
		$sql .= ' JOIN '.$this->db->prefix().'expedition e ON e.rowid = ee.fk_source';
		$sql .= " WHERE ee.sourcetype = 'shipping' AND ee.targettype = 'facture' AND ee.fk_target = ".((int) $invoiceId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->softFail('shipment date lookup failed: '.$this->db->lasterror());
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return ($obj && !empty($obj->d)) ? (int) $this->db->jdate($obj->d) : 0;
	}

	/**
	 * @param Facture $object Invoice
	 * @param int     $date   Timestamp
	 * @return int
	 */
	private function setDeliveryDate($object, $date)
	{
		$day = dol_mktime(0, 0, 0, (int) dol_print_date($date, '%m'), (int) dol_print_date($date, '%d'), (int) dol_print_date($date, '%Y'), 'tzserver');
		$sql = 'UPDATE '.$this->db->prefix().'facture SET date_pointoftax = '."'".$this->db->idate($day)."'";
		$sql .= ' WHERE rowid = '.((int) $object->id).' AND date_pointoftax IS NULL';
		if (!$this->db->query($sql)) {
			return $this->softFail('date_pointoftax update failed: '.$this->db->lasterror());
		}
		$object->date_pointoftax = $day;
		dol_syslog(__METHOD__.' invoice id='.$object->id.' date_pointoftax='.dol_print_date($day, 'day'), LOG_DEBUG);

		return 1;
	}

	/**
	 * The bank account printed on the invoice must be in the invoice currency: an EUR invoice gets the EUR account,
	 * a HUF invoice the HUF account. Only changed when the effective account (the invoice's own, else the default
	 * FACTURE_RIB_NUMBER) is in another currency and exactly one open current account exists in the invoice currency.
	 * Direct SQL (no BILL_MODIFY loop); only drafts, a validated invoice is never changed.
	 *
	 * @param Facture $object Invoice
	 * @param Conf    $conf   Conf
	 * @return int
	 */
	private function bankAccountForCurrency($object, Conf $conf, $validating = false)
	{
		// Customer documents that print the bank account: invoice, proposal, sales order
		$tables = array('facture' => 'facture', 'propal' => 'propal', 'commande' => 'commande');
		if (!is_object($object) || empty($object->id) || empty($object->element) || !isset($tables[$object->element]) || !isModEnabled('bank')) {
			return 0;
		}
		// Only drafts (at *_VALIDATE the document is still being validated in this transaction; BILL_VALIDATE sets newref)
		if (!$validating && (int) $object->status !== 0 && empty($object->newref)) {
			return 0;
		}
		$main = strtoupper((string) $conf->currency);
		$currency = strtoupper(!empty($object->multicurrency_code) ? $object->multicurrency_code : $main);

		// Target: the single open current account in the document currency; if the currency has no own account
		// (RON, PLN, CZK, ...): the main currency (HUF) account, i.e. the default FACTURE_RIB_NUMBER when it is in HUF
		$target = $this->singleAccountInCurrency($currency);
		if ($target === 0 && $currency !== $main) {
			$default = getDolGlobalInt('FACTURE_RIB_NUMBER');
			$target = ($default > 0 && $this->accountCurrency($default) === $main) ? $default : $this->singleAccountInCurrency($main);
		}
		if ($target <= 0) {
			dol_syslog(__METHOD__.' '.$object->element.' id='.$object->id.': no bank account for '.$currency.', left unchanged', LOG_WARNING);
			return 0;
		}

		$current = (int) ($object->fk_account > 0 ? $object->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER'));
		if ($current === $target) {
			return 0;
		}
		if ($object->fk_account <= 0 && $currency === $main && $this->accountCurrency($current) === $main) {
			return 0; // empty = default HUF account: already right
		}

		if (!$this->db->query('UPDATE '.$this->db->prefix().$tables[$object->element].' SET fk_account = '.((int) $target).' WHERE rowid = '.((int) $object->id))) {
			return $this->softFail('fk_account update failed: '.$this->db->lasterror());
		}
		$object->fk_account = $target;
		dol_syslog(__METHOD__.' '.$object->element.' id='.$object->id.' '.$currency.' -> bank account id='.$target, LOG_DEBUG);

		return 1;
	}

	/** @var array<string,string[]>|null Pairing rules cache: payment mode code => allowed term codes (first = default) */
	private $pairing = null;

	/**
	 * Allowed term id for a payment mode id: null if the mode has no rule or the term is allowed, else the default term id.
	 *
	 * @param int $modeId Payment mode id (c_paiement)
	 * @param int $termId Payment term id (c_payment_term), 0 if empty
	 * @return int|null Term id to set, null = nothing to change
	 */
	private function pairedTerm($modeId, $termId)
	{
		if ($this->pairing === null) {
			$this->pairing = (array) json_decode(getDolGlobalString('NAVONLINE_PAYMENT_PAIRING', '{}'), true);
		}
		if ($modeId <= 0 || !$this->pairing) {
			return null;
		}
		$res = $this->db->query('SELECT code FROM '.$this->db->prefix().'c_paiement WHERE id = '.((int) $modeId));
		$mode = ($res && ($o = $this->db->fetch_object($res))) ? $o->code : '';
		if (empty($this->pairing[$mode])) {
			return null;
		}
		$allowed = array();
		$res = $this->db->query('SELECT rowid, code FROM '.$this->db->prefix()."c_payment_term WHERE active = 1 AND entity IN (".getEntity('c_payment_term').") AND code IN ('".implode("','", array_map(array($this->db, 'escape'), $this->pairing[$mode]))."')");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$allowed[$o->code] = (int) $o->rowid;
		}
		if (!$allowed || in_array((int) $termId, $allowed, true)) {
			return null;
		}
		foreach ($this->pairing[$mode] as $code) {	// first allowed one = default
			if (isset($allowed[$code])) {
				return $allowed[$code];
			}
		}
		return null;
	}

	/**
	 * @param int $termId Payment term id
	 * @return string Translated label
	 */
	private function termLabel($termId)
	{
		global $langs;
		$res = $this->db->query('SELECT code, libelle FROM '.$this->db->prefix().'c_payment_term WHERE rowid = '.((int) $termId));
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o) {
			return '';
		}
		$langs->load('bills');
		$t = $langs->transnoentitiesnoconv('PaymentConditionShort'.$o->code);
		return $t !== 'PaymentConditionShort'.$o->code ? $t : $o->libelle;
	}

	/**
	 * @param string $msg Warning shown to the user (web only)
	 * @return void
	 */
	private function pairingNotice($msg)
	{
		dol_syslog(__CLASS__.': '.$msg, LOG_INFO);
		if (php_sapi_name() !== 'cli' && function_exists('setEventMessages')) {
			setEventMessages($msg, null, 'warnings');
		}
	}

	/**
	 * Partner: customer pair (mode_reglement / cond_reglement) and supplier pair (*_supplier), read from the database.
	 *
	 * @param Societe $object Third party
	 * @return int
	 */
	private function pairPaymentSociete($object)
	{
		$res = $this->db->query('SELECT mode_reglement, cond_reglement, mode_reglement_supplier, cond_reglement_supplier FROM '.$this->db->prefix().'societe WHERE rowid = '.((int) $object->id));
		$row = $res ? $this->db->fetch_object($res) : null;
		if (!$row) {
			return 0;
		}
		$done = 0;
		foreach (array(array('mode_reglement', 'cond_reglement', 'cond_reglement_id', 'vevői'), array('mode_reglement_supplier', 'cond_reglement_supplier', 'cond_reglement_supplier_id', 'szállítói')) as $p) {
			$new = $this->pairedTerm((int) $row->{$p[0]}, (int) $row->{$p[1]});
			if ($new === null) {
				continue;
			}
			if ($this->db->query('UPDATE '.$this->db->prefix().'societe SET '.$p[1].' = '.((int) $new).' WHERE rowid = '.((int) $object->id))) {
				$object->{$p[2]} = $new;
				$this->pairingNotice('A '.$p[3].' fizetési feltétel a fizetési módhoz igazítva: '.$this->termLabel($new));
				$done++;
			}
		}
		return $done ? 1 : 0;
	}

	/**
	 * Draft invoice / proposal / order (or one being validated): pair the payment term with the payment mode;
	 * an invoice also gets its due date recomputed.
	 *
	 * @param CommonObject $object     Document
	 * @param bool         $validating Called from *_VALIDATE
	 * @return int
	 */
	private function pairPaymentDocument($object, $validating)
	{
		$tables = array('facture' => 'facture', 'propal' => 'propal', 'commande' => 'commande');
		if (!is_object($object) || empty($object->id) || empty($object->element) || !isset($tables[$object->element])) {
			return 0;
		}
		if (!$validating && (int) $object->status !== 0) {
			return 0;
		}
		$res = $this->db->query('SELECT fk_mode_reglement, fk_cond_reglement FROM '.$this->db->prefix().$tables[$object->element].' WHERE rowid = '.((int) $object->id));
		$row = $res ? $this->db->fetch_object($res) : null;
		if (!$row) {
			return 0;
		}
		$new = $this->pairedTerm((int) $row->fk_mode_reglement, (int) $row->fk_cond_reglement);
		if ($new === null) {
			return 0;
		}
		$set = 'fk_cond_reglement = '.((int) $new);
		if ($object->element === 'facture' && method_exists($object, 'calculate_date_lim_reglement')) {
			$object->cond_reglement_id = $new;
			$object->cond_reglement_code = '';
			$lim = $object->calculate_date_lim_reglement($new);
			if ($lim > 0) {
				$object->date_lim_reglement = $lim;
				$set .= ", date_lim_reglement = '".$this->db->idate($lim)."'";
			}
		}
		if (!$this->db->query('UPDATE '.$this->db->prefix().$tables[$object->element].' SET '.$set.' WHERE rowid = '.((int) $object->id))) {
			return $this->softFail('payment term pairing failed: '.$this->db->lasterror());
		}
		$object->cond_reglement_id = $new;
		$this->pairingNotice('A fizetési feltétel a fizetési módhoz igazítva: '.$this->termLabel($new));
		return 1;
	}

	/**
	 * @param string $currency ISO code
	 * @return int Id of the single open current bank account in that currency, 0 if none or several
	 */
	private function singleAccountInCurrency($currency)
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'bank_account';
		$sql .= " WHERE clos = 0 AND courant = 1 AND currency_code = '".$this->db->escape($currency)."'";
		$sql .= ' AND entity IN ('.getEntity('bank_account').')';
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			return 0;
		}
		return (int) $this->db->fetch_object($resql)->rowid;
	}

	/**
	 * @param int $id Bank account id
	 * @return string Currency code ('' if not found)
	 */
	private function accountCurrency($id)
	{
		if ($id <= 0) {
			return '';
		}
		$resql = $this->db->query('SELECT currency_code FROM '.$this->db->prefix().'bank_account WHERE rowid = '.((int) $id));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? strtoupper((string) $obj->currency_code) : '';
	}

	/**
	 * True when every reportable line of the invoice is an OSS / foreign VAT line: a positive rate that is not a
	 * Hungarian rate (27, 18, 5) or a VAT code with a country suffix (PTU-PL, USt-AT, ...). Same rule as the guard
	 * of NavOnlineXmlBuilder::vatRate().
	 *
	 * @param int $invoiceId Invoice id
	 * @return bool
	 */
	private function isOssOnlyInvoice($invoiceId)
	{
		require_once __DIR__.'/../../lib/navonline.lib.php';
		list($n, $noss) = navonlineOssLineCounts($this->db, 'facture', $invoiceId);
		return $n > 0 && $noss === $n;
	}

	/**
	 * OSS lines are not reported to NAV, the other lines of an invoice are: an invoice (and the proposal / order it comes
	 * from) cannot have both, it is refused at validation (to be split into an OSS and a Hungarian invoice).
	 *
	 * @param CommonObject $object Proposal, order or customer invoice
	 * @return int -1 refused, 0 ok
	 */
	private function ossMixed($object)
	{
		global $langs;
		if (!is_object($object) || empty($object->id)) {
			return 0;
		}
		require_once __DIR__.'/../../lib/navonline.lib.php';
		list($n, $noss) = navonlineOssLineCounts($this->db, (string) ($object->element ?? ''), (int) $object->id);
		if ($noss === 0 || $noss === $n) {
			return 0;
		}
		$langs->load('navonline@navonline');
		$this->errors[] = $langs->trans('NavOnlineErrOssMixed');
		$this->error = end($this->errors);
		return -1;
	}

	/** @var int[] Invoices validated in this request, sent at the end of the request */
	private static $immediate = array();

	/**
	 * Send the invoice right after this request instead of waiting for the next cron run.
	 * The send runs at PHP shutdown, i.e. after the validation transaction is committed; nothing is sent
	 * inside the transaction, so a NAV call can never block or roll back the validation.
	 *
	 * @param int $invoiceId Invoice id
	 * @return void
	 */
	private static function scheduleImmediateSend($invoiceId)
	{
		if (!getDolGlobalInt('NAVONLINE_SEND_ON_VALIDATE', 1)) {
			return;
		}
		if (!self::$immediate) {
			register_shutdown_function(static function () {
				$ids = self::$immediate;
				self::$immediate = array();
				require_once dirname(__DIR__, 2).'/class/navonlinesender.class.php';
				NavOnlineSender::sendValidatedNow($ids);
			});
		}
		self::$immediate[] = $invoiceId;
	}

	/**
	 * Handle unvalidate / cancel / delete: drop a not-yet-sent queue entry; warn if already reported.
	 *
	 * @param string  $action Trigger code
	 * @param Facture $object Invoice
	 * @param Conf    $conf   Conf
	 * @return int
	 */
	private function billWithdraw($action, $object, Conf $conf)
	{
		if (!$this->isCustomerInvoice($object)) {
			return 0;
		}

		$entity = (int) (!empty($object->entity) ? $object->entity : $conf->entity);

		$existing = $this->fetchQueueRow((int) $object->id, $entity);
		if ($existing === false) {
			return $this->softFail('fetch queue row failed: '.$this->db->lasterror());
		}
		if (!$existing) {
			return 0;
		}

		if (in_array((int) $existing->status, array(self::STATUS_SENT, self::STATUS_DONE, self::STATUS_ANNUL_REQUESTED), true)) {
			dol_syslog(__METHOD__.' '.$action.' on invoice id='.$object->id.' that was already reported to NAV: annulment/modification required', LOG_WARNING);
			return 0;
		}

		if ($action === 'BILL_DELETE') {
			$sql = 'DELETE FROM '.$this->db->prefix().'navonline_invoice WHERE rowid = '.((int) $existing->rowid);
		} else {
			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET status = '.self::STATUS_CANCELLED;
			$sql .= ' WHERE rowid = '.((int) $existing->rowid);
		}

		if (!$this->db->query($sql)) {
			return $this->softFail('queue withdraw failed: '.$this->db->lasterror());
		}

		return 1;
	}

	/**
	 * @param CommonObject $object Object
	 * @return bool
	 */
	private function isCustomerInvoice($object)
	{
		return is_object($object) && !empty($object->element) && $object->element === 'facture' && !empty($object->id);
	}

	/**
	 * @param int $fkFacture Invoice id
	 * @param int $entity    Entity
	 * @return object|null|false Row, null if none, false on SQL error
	 */
	private function fetchQueueRow($fkFacture, $entity)
	{
		$sql = 'SELECT rowid, status FROM '.$this->db->prefix().'navonline_invoice';
		$sql .= ' WHERE fk_facture = '.((int) $fkFacture).' AND entity = '.((int) $entity).navonlineEnvSql();

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? $obj : null;
	}

	/**
	 * Log an internal error without blocking the business action.
	 *
	 * @param string $message Message
	 * @return int Always 0
	 */
	private function softFail($message)
	{
		dol_syslog(__CLASS__.': '.$message, LOG_ERR);
		return 0;
	}
}
