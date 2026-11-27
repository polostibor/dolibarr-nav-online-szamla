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
 * \file    htdocs/custom/navonline/class/navonlinexmlbuilder.class.php
 * \ingroup navonline
 * \brief   Build NAV Online Számla 3.0 InvoiceData XML from a Dolibarr customer invoice.
 *
 * Supported Dolibarr invoice types:
 *  - 0 standard        -> operation CREATE
 *  - 3 deposit         -> operation CREATE, every line flagged with advanceIndicator
 *  - 2 credit note     -> operation MODIFY (invoiceReference to the source invoice, lines appended with lineOperation CREATE);
 *                         a credit note of a deposit invoice has its lines flagged with advanceIndicator too
 * Not supported (yet): 1 replacement, 4 proforma, 5 situation.
 *
 * Mapping conventions:
 *  - Hungarian tax number (12345678-2-41) is read from idprof1 of the company / thirdparty.
 *  - Third party of type TE_PRIVATE is reported as PRIVATE_PERSON (no name, no address, no tax number).
 *  - 0% VAT lines must carry a NAV code in the VAT dictionary "code" field (vat_src_code):
 *    AAM, TAM, KBAET, KBAUK, EAM, NAM (exemption), ATK, EUFAD37, EUFADE, EUE, HO (out of scope),
 *    DRC / FAD (domestic reverse charge).
 *  - Main currency must be HUF. Foreign currency invoices use the multicurrency amounts + HUF amounts.
 */

require_once __DIR__.'/../lib/navonline.lib.php';
require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * Builder of InvoiceData XML
 */
class NavOnlineXmlBuilder
{
	const NS_DATA = 'http://schemas.nav.gov.hu/OSA/3.0/data';
	const NS_BASE = 'http://schemas.nav.gov.hu/OSA/3.0/base';
	const NS_COMMON = 'http://schemas.nav.gov.hu/NTCA/1.0/common';

	/** @var array<string,string> NAV exemption cases (vatExemption) */
	const VAT_EXEMPTION_CASES = array(
		'AAM' => 'Alanyi adómentesség',
		'TAM' => 'Tárgyi adómentesség',
		'KBAET' => 'Adómentes Közösségen belüli termékértékesítés',
		'KBAUK' => 'Adómentes Közösségen belüli új közlekedési eszköz értékesítés',
		'EAM' => 'Adómentes termékértékesítés a Közösség területén kívülre',
		'NAM' => 'Egyéb nemzetközi ügyletekhez kapcsolódó adómentesség',
	);

	/** @var array<string,string> NAV out-of-scope cases (vatOutOfScope) */
	const VAT_OUT_OF_SCOPE_CASES = array(
		'ATK' => 'Áfa tárgyi hatályán kívül',
		'EUFAD37' => 'Áfa tv. 37. §-a alapján másik tagállamban teljesített, fordítottan adózó ügylet',
		'EUFADE' => 'Másik tagállamban teljesített, nem az Áfa tv. 37. §-a alá tartozó, fordítottan adózó ügylet',
		'EUE' => 'Másik tagállamban teljesített, nem fordítottan adózó ügylet',
		'HO' => 'Harmadik országban teljesített ügylet',
	);

	/** @var array<string,string> Dolibarr c_units.code -> NAV UnitOfMeasureType */
	const UNIT_MAP = array(
		'P' => 'PIECE', 'KG' => 'KILOGRAM', 'T' => 'TON', 'L' => 'LITER', 'M' => 'METER',
		'M3' => 'CUBIC_METER', 'H' => 'HOUR', 'MI' => 'MINUTE', 'D' => 'DAY', 'MO' => 'MONTH',
	);

	/** @var array<string,string> Dolibarr c_paiement.code -> NAV PaymentMethodType */
	const PAYMENT_MAP = array(
		'VIR' => 'TRANSFER', 'PRE' => 'TRANSFER', 'LIQ' => 'CASH', 'CB' => 'CARD', 'CHQ' => 'OTHER',
	);

	/** @var DoliDB */
	private $db;

	/** @var string[] Data errors of the last build */
	public $errors = array();

	/** @var string[] Non-blocking warnings of the last build (stored as 'WARN LOCAL_...' messages) */
	public $warnings = array();

	/** @var string NAV operation for the last built invoice: CREATE or MODIFY */
	public $operation = 'CREATE';

	/** @var bool */
	private $foreignCurrency = false;

	/** @var bool The invoice of the last build is a credit note of a deposit invoice (its lines are advance lines) */
	private $creditOfDeposit = false;

	/** @var string VAT code of the supplier tax number of the last build (1 = subject-exempt, AAM) */
	private $supplierVatCode = '';

	/** @var string VAT code of the Hungarian tax number of the customer of the last build ('' = none) */
	private $customerVatCode = '';

	/** @var string Why the customer of the last build cannot get KBAET / KBAUK ('' = it can) */
	private $customerKbError = '';

	/** @var string Why the invoice of the last build cannot have an EAM line ('' = it can) */
	private $exportError = '';

	/** @var string 8-digit taxpayer id of the supplier of the last build */
	private $supplierTaxpayerId = '';

	/**
	 * @return string 8-digit supplier taxpayer id of the last build
	 */
	public function getSupplierTaxpayerId()
	{
		return $this->supplierTaxpayerId;
	}

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Build the InvoiceData XML.
	 *
	 * @param Facture              $invoice  Invoice with lines and thirdparty loaded
	 * @param Societe|null         $supplier Issuer (defaults to global $mysoc)
	 * @param array<string,mixed>  $options  Optional overrides (mainly for tests):
	 *                                       'reference' => array(originalInvoiceNumber, modifyWithoutMaster, modificationIndex, lineNumberOffset)
	 *                                       'referenceNav' => array(modifyWithoutMaster, modificationIndex, lineNumberOffset) from the NAV
	 *                                       invoice chain, replacing these values of the reference loaded from the database
	 *                                       'advanceData' => array(fk_remise_except => array(advanceOriginalInvoice, advancePaymentDate, advanceExchangeRate))
	 * @return SimpleXMLElement|null       Null if data errors ($this->errors)
	 */
	public function build($invoice, $supplier = null, array $options = array())
	{
		global $conf, $mysoc;

		$this->errors = array();
		$this->warnings = array();
		$supplier = $supplier ? $supplier : $mysoc;
		$type = (int) $invoice->type;

		if (!in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_DEPOSIT, Facture::TYPE_CREDIT_NOTE), true)) {
			$this->errors[] = 'Invoice type '.$type.' is not supported for NAV reporting yet';
			return null;
		}
		if (strtoupper((string) $conf->currency) !== 'HUF') {
			$this->errors[] = 'Main currency must be HUF (current: '.$conf->currency.')';
			return null;
		}
		if (empty($invoice->thirdparty) && method_exists($invoice, 'fetch_thirdparty')) {
			$invoice->fetch_thirdparty();
		}

		$currency = !empty($invoice->multicurrency_code) ? strtoupper($invoice->multicurrency_code) : 'HUF';
		$this->foreignCurrency = ($currency !== 'HUF');
		$this->operation = ($type === Facture::TYPE_CREDIT_NOTE) ? 'MODIFY' : 'CREATE';
		// Credit note of an előlegszámla (advance refunded or reduced): it modifies advance lines, so its lines are
		// advance lines too (advanceIndicator, without advancePaymentData as on the deposit invoice itself)
		$this->creditOfDeposit = false;
		if ($type === Facture::TYPE_CREDIT_NOTE && !empty($invoice->fk_facture_source)) {
			$res = $this->db->query('SELECT type FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $invoice->fk_facture_source));
			$src = $res ? $this->db->fetch_object($res) : null;
			$this->creditOfDeposit = ($src && (int) $src->type === Facture::TYPE_DEPOSIT);
		}

		$root = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InvoiceData xmlns="'.self::NS_DATA.'" xmlns:common="'.self::NS_COMMON.'" xmlns:base="'.self::NS_BASE.'"></InvoiceData>');

		$ref = !empty($invoice->newref) ? $invoice->newref : $invoice->ref;
		if (empty($ref) || preg_match('/^\(?PROV/i', $ref)) {
			$this->errors[] = 'Invoice has no definitive reference';
		}
		$this->add($root, 'invoiceNumber', $ref);
		$this->add($root, 'invoiceIssueDate', $this->date($invoice->date));
		$this->add($root, 'completenessIndicator', 'false');

		$xInvoice = $root->addChild('invoiceMain')->addChild('invoice');

		$lineOffset = 0;
		if ($type === Facture::TYPE_CREDIT_NOTE) {
			$reference = isset($options['reference']) ? $options['reference'] : $this->loadReference($invoice);
			// Values of the invoice chain registered at NAV (NavOnlineSender::navChainReference) replace the local
			// estimate: modifyWithoutMaster, modificationIndex, lineNumberOffset. A STORNO is only the first modification.
			if ($reference && !empty($options['referenceNav']) && is_array($options['referenceNav'])) {
				foreach (array('modifyWithoutMaster', 'modificationIndex', 'lineNumberOffset') as $k) {
					if (array_key_exists($k, $options['referenceNav'])) {
						$reference[$k] = $options['referenceNav'][$k];
					}
				}
				if ((int) $reference['modificationIndex'] !== 1) {
					$reference['fullReversal'] = false;
				}
			}
			if ($reference) {
				$xRef = $xInvoice->addChild('invoiceReference');
				$this->add($xRef, 'originalInvoiceNumber', $reference['originalInvoiceNumber']);
				$this->add($xRef, 'modifyWithoutMaster', $reference['modifyWithoutMaster'] ? 'true' : 'false');
				$this->add($xRef, 'modificationIndex', (int) $reference['modificationIndex']);
				$lineOffset = (int) $reference['lineNumberOffset'];

				// STORNO (invalidating invoice) when the credit note cancels the whole original; MODIFY otherwise
				if (!empty($reference['fullReversal']) && getDolGlobalString('NAVONLINE_STORNO_MODE', 'auto') !== 'never') {
					$this->operation = 'STORNO';
				}
				if (!empty($reference['previousStorno'])) {
					$this->warnings[] = 'WARN LOCAL_ORIGINAL_ALREADY_STORNO: '.$reference['originalInvoiceNumber'].' has already been cancelled by a STORNO credit note';
				}
			}
		}

		// Export only when the goods leave the EU (a credit note follows its invoice)
		$this->exportError = '';
		if ((int) $invoice->type !== 2) {
			$eam = navonlineExportError($this->db, $invoice);
			$this->exportError = $eam[0] === '' ? '' : trim(implode(' ', $eam));
		}

		// Head
		$xHead = $xInvoice->addChild('invoiceHead');
		$this->buildSupplier($xHead->addChild('supplierInfo'), $supplier);
		$this->buildCustomer($xHead->addChild('customerInfo'), $invoice->thirdparty);
		$this->buildDetail($xHead->addChild('invoiceDetail'), $invoice, $currency);

		// Lines + summary
		$xLines = $xInvoice->addChild('invoiceLines');
		$this->add($xLines, 'mergedItemIndicator', 'false');

		$groups = array();
		$lineNumber = 0;
		foreach ((array) $invoice->lines as $line) {
			if ((int) $line->product_type === 9) {
				continue; // title / subtotal lines
			}
			$lineNumber++;
			$this->buildLine($xLines, $line, $lineNumber, $lineOffset, $invoice, $groups, $options);
		}
		if ($lineNumber === 0) {
			$this->errors[] = 'Invoice has no reportable line';
		}

		$this->buildSummary($xInvoice->addChild('invoiceSummary'), $groups);

		return empty($this->errors) ? $root : null;
	}

	/**
	 * @param SimpleXMLElement $x        supplierInfo
	 * @param Societe          $supplier Issuer
	 * @return void
	 */
	private function buildSupplier($x, $supplier)
	{
		$tax = $this->getHuTaxNumber($supplier);
		if (!$tax || empty($tax['vatCode']) || empty($tax['countyCode'])) {
			$this->errors[] = 'Supplier: full Hungarian tax number (12345678-1-23) is required in Prof Id 1 (or in the VAT number field)';
			$tax = array('taxpayerId' => '00000000');
		}
		$this->supplierTaxpayerId = $tax['taxpayerId'];
		$this->supplierVatCode = (string) ($tax['vatCode'] ?? '');
		$this->checkCdv($tax['taxpayerId'], 'Supplier');
		$this->addTaxNumber($x->addChild('supplierTaxNumber'), $tax);

		$vatIntra = strtoupper(str_replace(' ', '', (string) $supplier->tva_intra));
		if (preg_match('/^HU[0-9]{8}$/', $vatIntra)) {
			$this->add($x, 'communityVatNumber', $vatIntra);
		}

		$this->add($x, 'supplierName', $this->text($supplier->name, 512), 'Supplier: name is empty');
		$this->buildAddress($x->addChild('supplierAddress'), $supplier, 'Supplier');
	}

	/**
	 * @param SimpleXMLElement $x   customerInfo
	 * @param Societe|null     $soc Customer
	 * @return void
	 */
	private function buildCustomer($x, $soc)
	{
		if (!$soc) {
			$this->errors[] = 'Customer not loaded';
			return;
		}

		$kb = navonlineIntraCommunityBuyerError($soc);
		$this->customerKbError = $kb[0] === '' ? '' : $kb[0].($kb[1] !== '' ? ' '.$kb[1] : '').($kb[2] !== '' ? ' '.$kb[2] : '');
		if (isset($soc->typent_code) && $soc->typent_code === 'TE_PRIVATE') {
			$this->add($x, 'customerVatStatus', 'PRIVATE_PERSON');
			return;
		}

		$country = strtoupper((string) $soc->country_code);
		if ($country === 'HU') {
			$tax = $this->getHuTaxNumber($soc);
			$this->customerVatCode = (string) ($tax['vatCode'] ?? '');
			if (!$tax) {
				$this->errors[] = 'Customer "'.$soc->name.'": Hungarian tax number (Prof Id 1) is missing or invalid; set the third party type to "Private individual" for a private person';
				return;
			}
			$this->add($x, 'customerVatStatus', 'DOMESTIC');
			$group = $this->getHuGroupTaxNumber($soc, 'Customer "'.$soc->name.'"');
			if ($group) {
				// VAT group member: the purchase is made under the group identification number (Prof Id 3);
				// the member's own tax number (Prof Id 1) goes into groupMemberTaxNumber (child of customerTaxNumber)
				$this->checkCdv($group['taxpayerId'], 'Customer "'.$soc->name.'" group id');
				$xTax = $x->addChild('customerVatData')->addChild('customerTaxNumber');
				$this->addTaxNumber($xTax, $group);
				if (!empty($tax['vatCode']) && $tax['taxpayerId'] !== $group['taxpayerId']) {
					$this->checkCdv($tax['taxpayerId'], 'Customer "'.$soc->name.'"');
					$this->addTaxNumber($xTax->addChild('groupMemberTaxNumber'), $tax);
				}
			} else {
				$this->checkCdv($tax['taxpayerId'], 'Customer "'.$soc->name.'"');
				$this->addTaxNumber($x->addChild('customerVatData')->addChild('customerTaxNumber'), $tax);
			}
		} else {
			$this->add($x, 'customerVatStatus', 'OTHER');
			$vatIntra = strtoupper(str_replace(array(' ', '-', '.'), '', (string) $soc->tva_intra));
			$isEu = function_exists('isInEEC') ? isInEEC($soc) : false;
			if ($isEu && preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $vatIntra)) {
				$this->add($x->addChild('customerVatData'), 'communityVatNumber', $vatIntra);
			} else {
				$thirdStateId = $vatIntra !== '' ? $vatIntra : trim((string) $soc->idprof1);
				if ($thirdStateId !== '') {
					$this->add($x->addChild('customerVatData'), 'thirdStateTaxId', $this->text($thirdStateId, 50));
				}
			}
		}

		$this->add($x, 'customerName', $this->text($soc->name, 512), 'Customer: name is empty');
		$this->buildAddress($x->addChild('customerAddress'), $soc, 'Customer');
	}

	/**
	 * @param SimpleXMLElement $x        invoiceDetail
	 * @param Facture          $invoice  Invoice
	 * @param string           $currency ISO currency code
	 * @return void
	 */
	private function buildDetail($x, $invoice, $currency)
	{
		$this->add($x, 'invoiceCategory', 'NORMAL');
		$delivery = !empty($invoice->date_pointoftax) ? $invoice->date_pointoftax : $invoice->date;
		// A cancellation (STORNO) invoice carries the date of supply of the invoice it cancels
		if ($this->operation === 'STORNO' && ($navOrig = navonlineStornoDeliveryDate($this->db, $invoice)) > 0) {
			$delivery = $navOrig;
		}
		$this->add($x, 'invoiceDeliveryDate', $this->date($delivery));
		if (getDolGlobalInt('NAVONLINE_SMALL_BUSINESS')) {
			$this->add($x, 'smallBusinessIndicator', 'true');
		}
		$this->add($x, 'currencyCode', $currency);
		$this->add($x, 'exchangeRate', $this->exchangeRate($invoice));

		$payCode = strtoupper((string) $invoice->mode_reglement_code);
		if ($payCode !== '') {
			$this->add($x, 'paymentMethod', isset(self::PAYMENT_MAP[$payCode]) ? self::PAYMENT_MAP[$payCode] : 'OTHER');
		}
		// A credit note (negative amount) has no payment due date: not sent, as on its PDF
		if (!empty($invoice->date_lim_reglement) && (int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
			$this->add($x, 'paymentDate', $this->date($invoice->date_lim_reglement));
		}
		if (getDolGlobalInt('NAVONLINE_CASH_ACCOUNTING')) {
			$this->add($x, 'cashAccountingIndicator', 'true');
		}
		$appearance = getDolGlobalString('NAVONLINE_INVOICE_APPEARANCE', 'PAPER');
		$this->add($x, 'invoiceAppearance', in_array($appearance, array('PAPER', 'ELECTRONIC', 'EDI', 'UNKNOWN'), true) ? $appearance : 'PAPER');

		// Customer's own order number ("Vevő rendelésszáma", ref_client) so the buyer sees it in its NAV inbound data
		$refClient = $this->text((string) ($invoice->ref_client ?? $invoice->ref_customer ?? ''), 100);
		if ($refClient !== '') {
			$this->add($x->addChild('conventionalInvoiceInfo')->addChild('orderNumbers'), 'orderNumber', $refClient);
		}
	}

	/**
	 * @param SimpleXMLElement           $xLines     invoiceLines
	 * @param FactureLigne               $line       Line
	 * @param int                        $lineNumber Line number (1..n)
	 * @param int                        $lineOffset Line number offset for modification invoices
	 * @param Facture                    $invoice    Invoice
	 * @param array<string,array<mixed>> $groups     VAT summary accumulator
	 * @param array<string,mixed>        $options    Build options
	 * @return void
	 */
	private function buildLine($xLines, $line, $lineNumber, $lineOffset, $invoice, &$groups, $options)
	{
		$isCreditNote = ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE);
		$label = 'Line '.$lineNumber;

		$x = $xLines->addChild('line');
		$this->add($x, 'lineNumber', $lineNumber);

		if ($isCreditNote) {
			$xMod = $x->addChild('lineModificationReference');
			$this->add($xMod, 'lineNumberReference', $lineOffset + $lineNumber);
			$this->add($xMod, 'lineOperation', 'CREATE');
		}

		// Advance (deposit) data
		$adv = null;
		if ((int) $invoice->type === Facture::TYPE_DEPOSIT || ($this->creditOfDeposit && empty($line->fk_remise_except))) {
			$this->add($x->addChild('advanceData'), 'advanceIndicator', 'true');
		} elseif (!empty($line->fk_remise_except)) {
			$adv = isset($options['advanceData'][$line->fk_remise_except]) ? $options['advanceData'][$line->fk_remise_except] : $this->loadAdvanceData((int) $line->fk_remise_except);
			if ($adv) {
				$xAdv = $x->addChild('advanceData');
				$this->add($xAdv, 'advanceIndicator', 'true');
				$xPay = $xAdv->addChild('advancePaymentData');
				$this->add($xPay, 'advanceOriginalInvoice', $adv['advanceOriginalInvoice']);
				$this->add($xPay, 'advancePaymentDate', $adv['advancePaymentDate']);
				$this->add($xPay, 'advanceExchangeRate', $adv['advanceExchangeRate']);
			}
		}

		$qty = (float) $line->qty;
		$hasQty = ($qty != 0.0);
		$this->add($x, 'lineExpressionIndicator', $hasQty ? 'true' : 'false');
		$nature = 'OTHER';
		if (!empty($line->fk_remise_except)) {
			$nature = 'OTHER';
		} elseif ((int) $line->product_type === 0) {
			$nature = 'PRODUCT';
		} elseif ((int) $line->product_type === 1) {
			$nature = 'SERVICE';
		}
		$this->add($x, 'lineNatureIndicator', $nature);

		$desc = $this->text(!empty($line->product_label) ? $line->product_label : (!empty($line->label) ? $line->label : $line->desc), 512);
		if (!empty($line->product_label) && !empty($line->desc) && trim(dol_string_nohtmltag($line->desc)) !== trim($line->product_label)) {
			$desc = $this->text($line->product_label.' - '.$line->desc, 512);
		}
		// Dolibarr discount lines carry an internal code as description: make it readable
		if (preg_match('/^\((DEPOSIT|CREDIT_NOTE|EXCESS RECEIVED)\)$/', trim((string) $line->desc), $m)) {
			$labels = array('DEPOSIT' => 'Előleg levonása', 'CREDIT_NOTE' => 'Módosító számla beszámítása', 'EXCESS RECEIVED' => 'Túlfizetés beszámítása');
			$desc = $labels[$m[1]].(!empty($adv['advanceOriginalInvoice']) ? ' ('.$adv['advanceOriginalInvoice'].')' : '');
		} elseif (preg_match('/^\(DEPOSIT\)\s*(.+)$/s', trim((string) $line->desc), $m)) {
			// Line of a deposit invoice created from an order/proposal: "(DEPOSIT) (50%) - SO2611-0001"
			$desc = $this->text('Előleg '.trim($m[1]), 512);
		}
		$this->add($x, 'lineDescription', $desc !== '' ? $desc : 'Tétel');

		// Amounts (invoice currency / HUF)
		$net = $this->mc($line, 'total_ht');
		$vat = $this->mc($line, 'total_tva');
		$gross = $this->mc($line, 'total_ttc');
		$netHuf = (float) $line->total_ht;
		$vatHuf = (float) $line->total_tva;
		$grossHuf = (float) $line->total_ttc;

		$unitPrice = $this->foreignCurrency && isset($line->multicurrency_subprice) ? (float) $line->multicurrency_subprice : (float) $line->subprice;
		$unitPriceHuf = (float) $line->subprice;

		if ($hasQty) {
			// Credit note: Dolibarr keeps qty positive and price negative; NAV convention is negative quantity, positive price
			$navQty = $isCreditNote ? -abs($qty) : $qty;
			if ($isCreditNote) {
				$unitPrice = abs($unitPrice);
				$unitPriceHuf = abs($unitPriceHuf);
			}
			$this->add($x, 'quantity', $this->quantity($navQty));
			$unit = $this->unitOfMeasure($line);
			$this->add($x, 'unitOfMeasure', $unit[0]);
			if ($unit[0] === 'OWN') {
				$this->add($x, 'unitOfMeasureOwn', $unit[1]);
			}
			$this->add($x, 'unitPrice', $this->quantity($unitPrice));
			if ($this->foreignCurrency) {
				$this->add($x, 'unitPriceHUF', $this->quantity($unitPriceHuf));
			}

			$remise = (float) $line->remise_percent;
			if ($remise > 0) {
				$discountValue = abs($qty * $unitPrice) - abs($net);
				$xDisc = $x->addChild('lineDiscountData');
				$this->add($xDisc, 'discountDescription', 'Árengedmény');
				$this->add($xDisc, 'discountValue', $this->money(max(0, $discountValue)));
				$this->add($xDisc, 'discountRate', $this->rate($remise / 100));
			}
		}

		$vatRate = $this->vatRate($line, $label);

		$xAmounts = $x->addChild('lineAmountsNormal');
		$xNet = $xAmounts->addChild('lineNetAmountData');
		$this->add($xNet, 'lineNetAmount', $this->money($net));
		$this->add($xNet, 'lineNetAmountHUF', $this->money($netHuf));
		$this->addVatRate($xAmounts->addChild('lineVatRate'), $vatRate);
		if ($vatRate['type'] === 'vatPercentage') {
			$xVat = $xAmounts->addChild('lineVatData');
			$this->add($xVat, 'lineVatAmount', $this->money($vat));
			$this->add($xVat, 'lineVatAmountHUF', $this->money($vatHuf));
		}
		$xGross = $xAmounts->addChild('lineGrossAmountData');
		$this->add($xGross, 'lineGrossAmountNormal', $this->money($gross));
		$this->add($xGross, 'lineGrossAmountNormalHUF', $this->money($grossHuf));

		$key = $vatRate['key'];
		if (!isset($groups[$key])) {
			$groups[$key] = array('rate' => $vatRate, 'net' => 0.0, 'netHuf' => 0.0, 'vat' => 0.0, 'vatHuf' => 0.0, 'gross' => 0.0, 'grossHuf' => 0.0);
		}
		$groups[$key]['net'] += $net;
		$groups[$key]['netHuf'] += $netHuf;
		$groups[$key]['vat'] += $vat;
		$groups[$key]['vatHuf'] += $vatHuf;
		$groups[$key]['gross'] += $gross;
		$groups[$key]['grossHuf'] += $grossHuf;
	}

	/**
	 * @param SimpleXMLElement           $x      invoiceSummary
	 * @param array<string,array<mixed>> $groups VAT groups
	 * @return void
	 */
	private function buildSummary($x, $groups)
	{
		$xNormal = $x->addChild('summaryNormal');
		$totals = array('net' => 0.0, 'netHuf' => 0.0, 'vat' => 0.0, 'vatHuf' => 0.0, 'gross' => 0.0, 'grossHuf' => 0.0);

		ksort($groups);
		foreach ($groups as $g) {
			$xg = $xNormal->addChild('summaryByVatRate');
			$this->addVatRate($xg->addChild('vatRate'), $g['rate']);
			$xn = $xg->addChild('vatRateNetData');
			$this->add($xn, 'vatRateNetAmount', $this->money($g['net']));
			$this->add($xn, 'vatRateNetAmountHUF', $this->money($g['netHuf']));
			$xv = $xg->addChild('vatRateVatData');
			$this->add($xv, 'vatRateVatAmount', $this->money($g['vat']));
			$this->add($xv, 'vatRateVatAmountHUF', $this->money($g['vatHuf']));
			$xgr = $xg->addChild('vatRateGrossData');
			$this->add($xgr, 'vatRateGrossAmount', $this->money($g['gross']));
			$this->add($xgr, 'vatRateGrossAmountHUF', $this->money($g['grossHuf']));
			foreach ($totals as $k => $v) {
				$totals[$k] = $v + $g[$k];
			}
		}

		$this->add($xNormal, 'invoiceNetAmount', $this->money($totals['net']));
		$this->add($xNormal, 'invoiceNetAmountHUF', $this->money($totals['netHuf']));
		$this->add($xNormal, 'invoiceVatAmount', $this->money($totals['vat']));
		$this->add($xNormal, 'invoiceVatAmountHUF', $this->money($totals['vatHuf']));

		$xGross = $x->addChild('summaryGrossData');
		$this->add($xGross, 'invoiceGrossAmount', $this->money($totals['gross']));
		$this->add($xGross, 'invoiceGrossAmountHUF', $this->money($totals['grossHuf']));
	}

	/**
	 * Determine the NAV VAT rate of a line.
	 *
	 * @param FactureLigne $line  Line
	 * @param string       $label Label for error messages
	 * @return array{type:string,key:string,value?:string,case?:string,reason?:string}
	 */
	private function vatRate($line, $label)
	{
		$rate = (float) $line->tva_tx;
		// Subject exemption (AAM) is the status of the supplier: only with VAT code 1, which charges no VAT (the
		// validation trigger refuses these documents already; this keeps such an invoice from being reported)
		if ($rate > 0 && $this->supplierVatCode === '1') {
			$this->errors[] = $label.': VAT rate '.round($rate, 2).'% on an invoice of a subject-exempt supplier (VAT code 1 in the tax number): AAM supplies carry no VAT';
		}
		if ($rate == 0 && strtoupper(trim((string) ($line->vat_src_code ?? ''))) === 'AAM' && $this->supplierVatCode !== '1') {
			$this->errors[] = $label.': AAM (subject exemption) needs a subject-exempt supplier, VAT code 1 in its tax number, got '.($this->supplierVatCode !== '' ? $this->supplierVatCode : 'none');
		}
		if ($rate > 0) {
			// Guard: only Hungarian VAT rates may be reported as vatPercentage. A foreign (OSS) rate such as
			// 23% (PTU-PL) would be reported as a non-existent Hungarian rate, so the invoice is stopped until the
			// NAV reporting rule of OSS supplies is decided.
			$srcCode = strtoupper(trim((string) (isset($line->vat_src_code) ? $line->vat_src_code : '')));
			if (!in_array(round($rate, 2), array(27.0, 18.0, 5.0), true) || preg_match('/-[A-Z]{2}$/', $srcCode)) {
				$this->errors[] = $label.': VAT rate '.round($rate, 2).'%'.($srcCode !== '' ? ' ('.$srcCode.')' : '').' is not a Hungarian VAT rate (OSS / foreign VAT): OSS lines are not reported to NAV (quarterly OSS return), an invoice with them is never sent';
				return array('type' => 'vatPercentage', 'key' => '9_x', 'value' => '0');
			}
			$value = $this->rate($rate / 100);
			return array('type' => 'vatPercentage', 'key' => '9_'.str_pad($value, 8, '0', STR_PAD_LEFT), 'value' => $value);
		}

		$code = strtoupper(trim((string) (isset($line->vat_src_code) ? $line->vat_src_code : '')));
		if (in_array($code, array('EAM', 'KBAET', 'KBAUK'), true) && (int) $line->product_type === 1) {
			$this->errors[] = $label.': '.$code.' is for goods only, the line is a service (use HO, EUFAD37, ... for services)';
		}
		if ($code === 'EAM' && $this->exportError !== '') {
			$this->errors[] = $label.': EAM (export) needs goods leaving the EU ('.$this->exportError.')';
		}
		if (($code === 'KBAET' || $code === 'KBAUK') && $this->customerKbError !== '') {
			// Áfa tv. 89. §: only to a company of another member state with its community VAT number (the validation
			// trigger refuses these documents already)
			$this->errors[] = $label.': '.$code.' needs a customer in another EU member state with a valid community VAT number of that state ('.$this->customerKbError.')';
		}
		if (isset(self::VAT_EXEMPTION_CASES[$code])) {
			return array('type' => 'vatExemption', 'key' => '2_'.$code, 'case' => $code, 'reason' => $this->vatReason($code, self::VAT_EXEMPTION_CASES[$code]));
		}
		if (isset(self::VAT_OUT_OF_SCOPE_CASES[$code])) {
			return array('type' => 'vatOutOfScope', 'key' => '1_'.$code, 'case' => $code, 'reason' => $this->vatReason($code, self::VAT_OUT_OF_SCOPE_CASES[$code]));
		}
		if ($code === 'DRC' || $code === 'FAD') {
			// Áfa tv. 142. § (3): no reverse charge when either party is subject-exempt (VAT code 1; the validation
			// trigger refuses these documents already)
			if ($this->supplierVatCode === '1') {
				$this->errors[] = $label.': domestic reverse charge (FAD) by a subject-exempt supplier (VAT code 1 in the tax number)';
			}
			if ($this->customerVatCode === '1') {
				$this->errors[] = $label.': domestic reverse charge (FAD) to a subject-exempt customer (VAT code 1 in its tax number)';
			}
			return array('type' => 'vatDomesticReverseCharge', 'key' => '3_DRC');
		}

		$this->errors[] = $label.': 0% VAT needs a NAV code in the VAT dictionary "code" field (one of '
			.implode(', ', array_merge(array_keys(self::VAT_EXEMPTION_CASES), array_keys(self::VAT_OUT_OF_SCOPE_CASES), array('DRC'))).')'
			.($code !== '' ? ', got "'.$code.'"' : '');
		return array('type' => 'vatPercentage', 'key' => '9_0', 'value' => '0');
	}

	/** @var array<string,string> VAT dictionary notes by code (cache of vatReason) */
	private $vatNotes;

	/**
	 * Reason of a 0% NAV case: the text the invoice shows (the note of the VAT dictionary entry of the issuer's
	 * country, as the PDF prints it, NAV: "you can use the same text that is shown on the invoice"), else the
	 * built-in text; at most 200 characters (NAV limit).
	 *
	 * @param string $code    NAV case code (KBAET, EAM, ...)
	 * @param string $default Built-in reason
	 * @return string
	 */
	private function vatReason($code, $default)
	{
		global $mysoc;

		if ($this->vatNotes === null) {
			$this->vatNotes = array();
			$sql = 'SELECT t.code, t.note FROM '.$this->db->prefix().'c_tva t';
			$sql .= ' JOIN '.$this->db->prefix().'c_country c ON c.rowid = t.fk_pays';
			$sql .= " WHERE c.code = '".$this->db->escape((string) (is_object($mysoc) && !empty($mysoc->country_code) ? $mysoc->country_code : 'HU'))."'";
			$sql .= ' AND t.taux = 0 AND t.active = 1 AND t.entity IN ('.getEntity('c_tva').')';
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				$note = trim(preg_replace('/\s+/u', ' ', (string) $o->note));
				if ($note !== '' && $o->code !== '') {
					$this->vatNotes[strtoupper($o->code)] = $note;
				}
			}
		}
		$text = $this->vatNotes[strtoupper($code)] ?? $default;
		return dol_trunc($text, 200, 'right', 'UTF-8', 1);
	}

	/**
	 * @param SimpleXMLElement                     $x    vatRate / lineVatRate
	 * @param array<string,string>                 $rate VAT rate descriptor
	 * @return void
	 */
	private function addVatRate($x, $rate)
	{
		switch ($rate['type']) {
			case 'vatPercentage':
				$this->add($x, 'vatPercentage', $rate['value']);
				break;
			case 'vatExemption':
			case 'vatOutOfScope':
				$xr = $x->addChild($rate['type']);
				$this->add($xr, 'case', $rate['case']);
				$this->add($xr, 'reason', $rate['reason']);
				break;
			case 'vatDomesticReverseCharge':
				$this->add($x, 'vatDomesticReverseCharge', 'true');
				break;
		}
	}

	/**
	 * @param SimpleXMLElement $x      Parent
	 * @param Societe          $soc    Company
	 * @param string           $prefix Error label
	 * @return void
	 */
	private function buildAddress($x, $soc, $prefix)
	{
		$xs = $x->addChild('base:simpleAddress', null, self::NS_BASE);
		$country = strtoupper((string) $soc->country_code);
		$this->add($xs, 'base:countryCode', $country, $prefix.': country is empty', self::NS_BASE);
		$zip = strtoupper(trim((string) $soc->zip));
		if (!preg_match('/^[A-Z0-9][A-Z0-9\s\-]{1,8}$/', $zip)) {
			$this->errors[] = $prefix.': postal code "'.$zip.'" is missing or invalid';
		}
		$this->add($xs, 'base:postalCode', $zip, null, self::NS_BASE);
		$this->add($xs, 'base:city', $this->text($soc->town, 255), $prefix.': town is empty', self::NS_BASE);
		$this->add($xs, 'base:additionalAddressDetail', $this->text(str_replace(array("\r\n", "\n", "\r"), ' ', (string) $soc->address), 255), $prefix.': address is empty', self::NS_BASE);
	}

	/**
	 * @param SimpleXMLElement     $x   Parent
	 * @param array<string,string> $tax Parsed tax number
	 * @return void
	 */
	private function addTaxNumber($x, $tax)
	{
		$this->add($x, 'base:taxpayerId', $tax['taxpayerId'], null, self::NS_BASE);
		if (!empty($tax['vatCode'])) {
			$this->add($x, 'base:vatCode', $tax['vatCode'], null, self::NS_BASE);
		}
		if (!empty($tax['countyCode'])) {
			$this->add($x, 'base:countyCode', $tax['countyCode'], null, self::NS_BASE);
		}
	}

	/**
	 * Hungarian tax number of a company: Prof Id 1 first; fallback to the VAT number field
	 * when it holds a full domestic tax number (12345678-1-23), as many HU setups store it there.
	 *
	 * @param Societe $soc Company
	 * @return array{taxpayerId:string,vatCode?:string,countyCode?:string}|null
	 */
	private function getHuTaxNumber($soc)
	{
		$tax = $this->parseHuTaxNumber(isset($soc->idprof1) ? $soc->idprof1 : '');
		if ($tax && !empty($tax['vatCode'])) {
			return $tax;
		}
		$fromVat = preg_match('/^[0-9]{8}-?[1-5]-?[0-9]{2}$/', trim((string) $soc->tva_intra)) ? $this->parseHuTaxNumber($soc->tva_intra) : null;
		return $fromVat ? $fromVat : $tax;
	}

	/**
	 * VAT group identification number (csoportazonosító) of a company, stored in Prof Id 3.
	 * It must be a full tax number with VAT code 5; anything else is a data error (it would be reported wrongly).
	 *
	 * @param Societe $soc Company
	 * @param string  $who Label for messages
	 * @return array{taxpayerId:string,vatCode:string,countyCode:string}|null Null if not a group member
	 */
	private function getHuGroupTaxNumber($soc, $who)
	{
		$raw = trim((string) (isset($soc->idprof3) ? $soc->idprof3 : ''));
		if ($raw === '') {
			return null;
		}
		$group = $this->parseHuTaxNumber($raw);
		if (!$group || empty($group['vatCode']) || $group['vatCode'] !== '5') {
			$this->errors[] = $who.': group identification number (Prof Id 3) "'.$raw.'" must be a full tax number with VAT code 5 (12345678-5-12)';
			return null;
		}
		return $group;
	}

	/**
	 * Hungarian taxpayer id check digit (CDV): digits 1-7 weighted 9,7,3,1,9,7,3;
	 * the 8th digit must be (10 - sum mod 10) mod 10.
	 *
	 * @param string $taxpayerId 8 digits
	 * @return bool
	 */
	public static function isValidHuTaxpayerId($taxpayerId)
	{
		if (!preg_match('/^[0-9]{8}$/', (string) $taxpayerId)) {
			return false;
		}
		$weights = array(9, 7, 3, 1, 9, 7, 3);
		$sum = 0;
		for ($i = 0; $i < 7; $i++) {
			$sum += (int) $taxpayerId[$i] * $weights[$i];
		}
		return (10 - $sum % 10) % 10 === (int) $taxpayerId[7];
	}

	/**
	 * Add a non-blocking warning when the CDV check fails.
	 *
	 * @param string $taxpayerId 8 digits
	 * @param string $who        Label
	 * @return void
	 */
	private function checkCdv($taxpayerId, $who)
	{
		if (!self::isValidHuTaxpayerId($taxpayerId)) {
			$this->warnings[] = 'WARN LOCAL_TAX_NUMBER_CDV: '.$who.': tax number '.$taxpayerId.' fails the Hungarian check digit (CDV) test';
		}
	}

	/**
	 * Parse a Hungarian tax number: 12345678, 12345678-1-23, 12345678123, HU12345678.
	 *
	 * @param string $value Raw value
	 * @return array{taxpayerId:string,vatCode?:string,countyCode?:string}|null
	 */
	public function parseHuTaxNumber($value)
	{
		$digits = preg_replace('/[^0-9]/', '', (string) $value);
		if (strlen($digits) === 11 && preg_match('/^[0-9]{8}[1-5][0-9]{2}$/', $digits)) {
			return array('taxpayerId' => substr($digits, 0, 8), 'vatCode' => substr($digits, 8, 1), 'countyCode' => substr($digits, 9, 2));
		}
		if (strlen($digits) === 8) {
			return array('taxpayerId' => $digits);
		}
		return null;
	}

	/**
	 * Load invoiceReference data for a credit note from the database.
	 *
	 * @param Facture $invoice Credit note
	 * @return array<string,mixed>|null
	 */
	private function loadReference($invoice)
	{
		if (empty($invoice->fk_facture_source)) {
			$this->errors[] = 'Credit note has no source invoice';
			return null;
		}
		$source = new Facture($this->db);
		if ($source->fetch((int) $invoice->fk_facture_source) <= 0) {
			$this->errors[] = 'Source invoice '.$invoice->fk_facture_source.' not found';
			return null;
		}

		// Was the original reported through this module?
		$reported = false;
		$sql = 'SELECT status FROM '.$this->db->prefix().'navonline_invoice WHERE fk_facture = '.((int) $source->id).' AND entity = '.((int) $source->entity).navonlineEnvSql();
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			// sent, done, or annulment requested but not yet approved (the original report is still valid)
			$reported = in_array((int) $obj->status, array(1, 2, 4), true);
		}

		// Modification index and line offset: previous credit notes of the same source (validated, older id)
		$index = 1;
		$offset = $this->countReportableLines((int) $source->id);
		$previousStorno = false;
		$sql = 'SELECT f.rowid, q.operation FROM '.$this->db->prefix().'facture as f';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'navonline_invoice as q ON q.fk_facture = f.rowid'.navonlineEnvSql('q.');
		$sql .= ' WHERE f.fk_facture_source = '.((int) $source->id).' AND f.type = '.Facture::TYPE_CREDIT_NOTE;
		$sql .= ' AND f.fk_statut > 0 AND f.rowid < '.((int) $invoice->id).' ORDER BY f.rowid';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$index++;
				$offset += $this->countReportableLines((int) $obj->rowid);
				if ($obj->operation === 'STORNO') {
					$previousStorno = true;
				}
			}
		}

		return array(
			'originalInvoiceNumber' => $source->ref,
			'modifyWithoutMaster' => !$reported,
			'modificationIndex' => $index,
			'lineNumberOffset' => $offset,
			// First credit note reversing the whole original (net and VAT, in HUF and in the invoice currency)
			'fullReversal' => $index === 1
				&& abs((float) $source->total_ht + (float) $invoice->total_ht) < 0.005
				&& abs((float) $source->total_tva + (float) $invoice->total_tva) < 0.005
				&& abs((float) $source->multicurrency_total_ht + (float) $invoice->multicurrency_total_ht) < 0.005
				&& abs((float) $source->multicurrency_total_tva + (float) $invoice->multicurrency_total_tva) < 0.005,
			'previousStorno' => $previousStorno,
		);
	}

	/**
	 * @param int $fkFacture Invoice id
	 * @return int
	 */
	private function countReportableLines($fkFacture)
	{
		$sql = 'SELECT COUNT(*) as nb FROM '.$this->db->prefix().'facturedet WHERE fk_facture = '.((int) $fkFacture).' AND product_type <> 9';
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * Advance payment data for a deposit deduction line (discount created from a deposit invoice).
	 *
	 * @param int $fkRemiseExcept Discount id
	 * @return array<string,string>|null Null if the discount does not come from a deposit invoice
	 */
	private function loadAdvanceData($fkRemiseExcept)
	{
		// advancePaymentDate = date the advance was paid: first payment of the deposit invoice, else its issue date
		$sql = 'SELECT f.ref, f.datef, f.multicurrency_code, f.multicurrency_tx,';
		$sql .= ' (SELECT MIN(p.datep) FROM '.$this->db->prefix().'paiement_facture as pf INNER JOIN '.$this->db->prefix().'paiement as p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture = f.rowid) as datepaid';
		$sql .= ' FROM '.$this->db->prefix().'societe_remise_except as r';
		$sql .= ' INNER JOIN '.$this->db->prefix().'facture as f ON f.rowid = r.fk_facture_source';
		$sql .= ' WHERE r.rowid = '.((int) $fkRemiseExcept).' AND f.type = '.Facture::TYPE_DEPOSIT;
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return null;
		}
		$tx = (float) $obj->multicurrency_tx;
		$isForeign = !empty($obj->multicurrency_code) && strtoupper($obj->multicurrency_code) !== 'HUF';
		return array(
			'advanceOriginalInvoice' => $obj->ref,
			'advancePaymentDate' => $this->date($this->db->jdate(!empty($obj->datepaid) ? $obj->datepaid : $obj->datef)),
			'advanceExchangeRate' => ($isForeign && $tx > 0) ? $this->formatExchangeRate(1 / $tx) : '1',
		);
	}

	/**
	 * @param FactureLigne $line Line
	 * @return array{0:string,1:string} NAV unit and own label
	 */
	private function unitOfMeasure($line)
	{
		if (empty($line->fk_unit)) {
			return array('PIECE', '');
		}
		$sql = 'SELECT code, short_label, label FROM '.$this->db->prefix().'c_units WHERE rowid = '.((int) $line->fk_unit);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$code = strtoupper((string) $obj->code);
			if (isset(self::UNIT_MAP[$code])) {
				return array(self::UNIT_MAP[$code], '');
			}
			$own = $this->text(!empty($obj->short_label) ? $obj->short_label : $obj->label, 50);
			return array('OWN', $own !== '' ? $own : $code);
		}
		return array('PIECE', '');
	}

	/**
	 * @param Facture $invoice Invoice
	 * @return string
	 */
	private function exchangeRate($invoice)
	{
		if (!$this->foreignCurrency) {
			return '1';
		}
		$tx = (float) $invoice->multicurrency_tx;
		if ($tx <= 0) {
			$this->errors[] = 'Foreign currency invoice without exchange rate';
			return '1';
		}
		// Dolibarr: 1 HUF = tx foreign; NAV: 1 foreign = x HUF
		return $this->formatExchangeRate(1 / $tx);
	}

	/**
	 * @param float $rate HUF per foreign unit
	 * @return string
	 */
	private function formatExchangeRate($rate)
	{
		return rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
	}

	/**
	 * Amount of a line in the invoice currency.
	 *
	 * @param FactureLigne $line  Line
	 * @param string       $field total_ht / total_tva / total_ttc
	 * @return float
	 */
	private function mc($line, $field)
	{
		if ($this->foreignCurrency) {
			$mcField = 'multicurrency_'.$field;
			return (float) (isset($line->$mcField) ? $line->$mcField : 0);
		}
		return (float) $line->$field;
	}

	/**
	 * Add a child with an escaped value.
	 *
	 * @param SimpleXMLElement $parent  Parent
	 * @param string           $name    Element name (may be prefixed, e.g. base:city)
	 * @param string|int       $value   Value
	 * @param string|null      $ifEmpty Error to record when value is empty (element is still added)
	 * @param string|null      $ns      Namespace URI
	 * @return SimpleXMLElement
	 */
	private function add($parent, $name, $value, $ifEmpty = null, $ns = null)
	{
		$value = (string) $value;
		if ($ifEmpty !== null && trim($value) === '') {
			$this->errors[] = $ifEmpty;
		}
		$child = $ns ? $parent->addChild($name, null, $ns) : $parent->addChild($name);
		$child[0] = $value;
		return $child;
	}

	/**
	 * @param int|string $ts Timestamp
	 * @return string YYYY-MM-DD
	 */
	private function date($ts)
	{
		if (empty($ts)) {
			$this->errors[] = 'Missing date';
			return '';
		}
		return dol_print_date($ts, '%Y-%m-%d');
	}

	/**
	 * @param float $v Amount
	 * @return string
	 */
	private function money($v)
	{
		$v = round((float) $v, 2);
		if ($v == 0) {
			$v = 0.0; // avoid "-0.00"
		}
		return number_format($v, 2, '.', '');
	}

	/**
	 * @param float $v Quantity / unit price
	 * @return string
	 */
	private function quantity($v)
	{
		$s = rtrim(rtrim(number_format((float) $v, 10, '.', ''), '0'), '.');
		return ($s === '' || $s === '-0') ? '0' : $s;
	}

	/**
	 * @param float $v Rate between 0 and 1
	 * @return string
	 */
	private function rate($v)
	{
		$s = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
		return $s === '' ? '0' : $s;
	}

	/**
	 * Plain text, trimmed and truncated.
	 *
	 * @param string|null $v   Value
	 * @param int         $max Max length
	 * @return string
	 */
	private function text($v, $max)
	{
		$v = trim(preg_replace('/\s+/u', ' ', dol_string_nohtmltag((string) $v, 1)));
		return dol_trunc($v, $max, 'right', 'UTF-8', 1);
	}
}
