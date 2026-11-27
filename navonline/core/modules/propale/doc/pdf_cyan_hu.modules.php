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
 * \file    htdocs/custom/navonline/core/modules/propale/doc/pdf_cyan_hu.modules.php
 * \ingroup navonline
 * \brief   Commercial proposal PDF: core cyan in one language, both deadlines, Incoterms under the lines.
 *
 * Built on the core proposal template cyan (like visszaigazolas on eratosthene):
 *  - one language only (the customer's): no second language even when PDF_USE_ALSO_LANGUAGE_CODE is set (user's
 *    decision 2026-11-26; the bilingual core totals also overlapped);
 *  - the info block shows both the committed delivery date and the lead time (core: one of them), the shipping
 *    method and the Incoterms (core: in a box above the lines), then the core payment part;
 *  - "Vevő hivatkozási száma" (the customer's inquiry reference) instead of "Vevő rendelésszáma".
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/propale/doc/pdf_cyan.modules.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Commercial proposal PDF
 */
class pdf_cyan_hu extends pdf_cyan
{
	use NavOnlinePdfTypography;
	/** @var string Incoterms text of the document being generated */
	private $navInco = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		parent::__construct($db);
		$langs->load('navonline@navonline');
		$this->name = 'cyan_hu';
		$this->description = $langs->trans('NavOnlineProposalModelDesc');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param Propal    $object          Proposal
	 * @param Translate $outputlangs     Output language
	 * @param string    $srctemplatepath Not used
	 * @param int<0,1>  $hidedetails     Hide details
	 * @param int<0,1>  $hidedesc        Hide description
	 * @param int<0,1>  $hideref         Hide ref
	 * @return int<-1,1>
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs, $outputlangsbis;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// Every domain the core template loads, first: a later load would not overwrite the label swap below
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'propal', 'products', 'compta', 'sendings', 'navonline@navonline'));
		$savedRef = $outputlangs->tab_translate['RefCustomer'] ?? null;
		$outputlangs->tab_translate['RefCustomer'] = $outputlangs->transnoentitiesnoconv('NavOnlineProposalRefCustomer');

		// Incoterms under the lines (drawInfoTable), not in the core box above the lines
		$this->navInco = navonlineIncotermsText($this->db, $object, $outputlangs);
		$savedInco = $object->fk_incoterms ?? null;
		$object->fk_incoterms = 0;

		// The parent reserves 40 mm for info + totals; both deadlines and the Incoterms need about 10 mm more
		$prevFreeText = $conf->global->MAIN_PDF_FREETEXT_HEIGHT ?? null;
		$conf->global->MAIN_PDF_FREETEXT_HEIGHT = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) + 10;

		// One language (the customer's)
		$prevBis = $conf->global->PDF_USE_ALSO_LANGUAGE_CODE ?? null;
		unset($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
		$prevBisObj = $outputlangsbis;
		$outputlangsbis = null;
		try {
			$navNote = navonlinePdfNoteStyle($object, $outputlangs);	// NavOnline: note smaller, italic
			try {
				return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
			} finally {
				$object->note_public = $navNote;
			}
		} finally {
			if ($prevBis !== null) {
				$conf->global->PDF_USE_ALSO_LANGUAGE_CODE = $prevBis;
			}
			$outputlangsbis = $prevBisObj;
			$object->fk_incoterms = $savedInco;
			if ($prevFreeText === null) {
				unset($conf->global->MAIN_PDF_FREETEXT_HEIGHT);
			} else {
				$conf->global->MAIN_PDF_FREETEXT_HEIGHT = $prevFreeText;
			}
			if ($savedRef === null) {
				unset($outputlangs->tab_translate['RefCustomer']);
			} else {
				$outputlangs->tab_translate['RefCustomer'] = $savedRef;
			}
		}
	}

	/**
	 * Bottom of the info, totals and signature area drawn at a Y (measured in a rollback, see navTotalsY()).
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param Propal    $object      Proposal
	 * @param Translate $outputlangs Langs
	 * @param float     $y           Y
	 * @return float
	 */
	protected function navMeasureTotalsArea($pdf, $object, $outputlangs, $y)
	{
		$a = $this->drawInfoTable($pdf, $object, $y, $outputlangs);
		$b = (float) $this->drawTotalTable($pdf, $object, 0, $y, $outputlangs);
		if (!getDolGlobalString('PROPAL_DISABLE_SIGNATURE')) {
			$b += (float) $this->drawSignatureArea($pdf, $object, $b, $outputlangs);	// the core returns the height of the area
		}
		return max((float) $a, $b);
	}

	/**
	 * Info block: committed delivery date, lead time, shipping method and Incoterms, then the core block (payment
	 * terms, payment mode, bank account) without its own deadline and shipping method lines.
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param Propal    $object      Proposal
	 * @param float     $posy        Y
	 * @param Translate $outputlangs Output language
	 * @return float Y after the block
	 */
	public function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
	{
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		// NavOnline (typography): label / value pairs as on the invoice, in place of the core block
		$outputlangs->load('sendings');
		$rows = array();
		if (!empty($object->delivery_date)) {
			$rows[] = array($outputlangs->transnoentities('DateDeliveryPlanned'), dol_print_date($object->delivery_date, 'day', false, $outputlangs, true));
		}
		if (!empty($object->availability_code) || !empty($object->availability)) {
			$key = 'AvailabilityType'.$object->availability_code;
			$label = ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $outputlangs->convToOutputCharset((string) $object->availability);
			$rows[] = array($outputlangs->transnoentities('AvailabilityPeriod'), str_replace('\n', "\n", $label));
		}
		if (!empty($object->shipping_method_id) && !getDolGlobalString('PROPOSAL_PDF_HIDE_DELIVERYMODE')) {
			$res = $this->db->query('SELECT code, libelle FROM '.MAIN_DB_PREFIX.'c_shipment_mode WHERE rowid = '.((int) $object->shipping_method_id));
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$key = 'SendingMethod'.strtoupper($o->code);
				$rows[] = array($outputlangs->transnoentities('SendingMethod'), ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $o->libelle);
			}
		}
		if (!empty($this->navInco)) {
			list($k, $v) = explode(': ', $this->navInco, 2) + array('', '');
			$rows[] = array(rtrim($k, ':'), $v);
		}
		$rows = array_merge($rows, navonlinePdfTermRows($object, $outputlangs, !getDolGlobalString('PROPOSAL_PDF_HIDE_PAYMENTTERM'), !getDolGlobalString('PROPOSAL_PDF_HIDE_PAYMENTMODE')));
		$posy = navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, $rows);
		if (!getDolGlobalString('PROPOSAL_PDF_HIDE_PAYMENTMODE')) {
			$posy = navonlinePdfBankForDoc($pdf, $object, $outputlangs, $this->marge_gauche, $posy);
		}
		return $posy;
	}

	/**
	 * Columns as the parent; unit column titled "ME" and filled from the product when a line has no unit.
	 *
	 * @param CommonObject $object      Document
	 * @param Translate    $outputlangs Langs
	 * @param int          $hidedetails Hide details
	 * @param int          $hidedesc    Hide description
	 * @param int          $hideref     Hide ref
	 * @return void
	 */
	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		parent::defineColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);
		navonlinePdfUnitColumn($this, $object);
		navonlinePdfTypoColumns($this);	// typography: 1.6 mm above and below the lines
	}

	/**
	 * Copy of pdf_cyan::drawTotalTable() (Dolibarr 24.0): diff it after an upgrade. NavOnline change: the VAT row labels
	 * go through navonlinePdfVatLabel() ("Total tax (27%)" in English).
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Propal     $object         Proposal
	 * @param float      $deja_regle     Already paid
	 * @param float      $posy           Y
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return float Y
	 */
	protected function drawTotalTable(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
	{
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		global $conf, $mysoc, $hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
			$outputlangsbis->loadLangs(array("main", "dict", "companies", "bills", "products", "propal"));
			$default_font_size--;
		}

		$tab2_top = $posy;
		$tab2_hl = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		// Total table
		$col1x = 120;
		$col2x = 170;
		if ($this->page_largeur < 210) { // To work with US executive format
			$col2x -= 20;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;

		// Get Total HT
		$total_ht = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1 ? $object->multicurrency_total_ht : $object->total_ht);

		// Total discount
		$total_discount_on_lines = 0;
		$multicurrency_total_discount_on_lines = 0;
		foreach ($object->lines as $i => $line) {
			$resdiscount = pdfGetLineTotalDiscountAmount($object, $i, $outputlangs, 2);
			$multicurrency_resdiscount = pdfGetLineTotalDiscountAmount($object, $i, $outputlangs, 2, 1);

			$total_discount_on_lines += (is_numeric($resdiscount) ? $resdiscount : 0);
			$multicurrency_total_discount_on_lines += (is_numeric($multicurrency_resdiscount) ? $multicurrency_resdiscount : 0);
			// If line was a negative line, we do not count the discount as a discount
			if ($line->total_ht < 0) {
				$total_discount_on_lines += -$line->total_ht;
				$multicurrency_total_discount_on_lines += -$line->multicurrency_total_ht;
			}
		}

		if ($total_discount_on_lines > 0) {
			if ($this->showAmountBeforeDiscount) {
				// Show total NET before discount
				$pdf->SetFillColor(255, 255, 255);
				$pdf->SetXY($col1x, $tab2_top);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHTBeforeDiscount") . (is_object($outputlangsbis) ? ' / ' . $outputlangsbis->transnoentities("TotalHTBeforeDiscount") : ''), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top);

				$total_before_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? ($object->multicurrency_total_ht + $multicurrency_total_discount_on_lines) : ($object->total_ht + $total_discount_on_lines));
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_before_discount_to_show, 0, $outputlangs), 0, 'R', true);

				$index++;
			}

			if ($this->showDiscountAmount) {
				$pdf->SetFillColor(255, 255, 255);
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalDiscount") . (is_object($outputlangsbis) ? ' / ' . $outputlangsbis->transnoentities("TotalDiscount") : ''), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl);

				$total_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $multicurrency_total_discount_on_lines : $total_discount_on_lines);
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_discount_to_show, 0, $outputlangs), 0, 'R', true);

				$index++;
			}
		}

		// Total HT
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHT") : ''), 0, 'L', true);

		$total_ht = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, price($total_ht + (!empty($object->remise) ? $object->remise : 0), 0, $outputlangs), 0, 'R', true);

		// Show VAT by rates and total
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;
		$total_ttc_origin = $object->total_ttc;

		$this->atleastoneratenotnull = 0;
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = (!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000']));
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// Nothing to do
			} else {
				//Local tax 1 before VAT
				//if (getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') == 'localtax1on')
				//{
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';
							$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}
				//}
				//Local tax 2 before VAT
				//if (getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') == 'localtax2on')
				//{
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';
							$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}
				//}

				// VAT
				foreach ($this->tva as $tvakey => $tvaval) {
					if ($tvakey != 0) {    // On affiche pas taux 0
						$this->atleastoneratenotnull++;

						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

						$tvacompl = '';
						if (preg_match('/\*/', $tvakey)) {
							$tvakey = str_replace('*', '', $tvakey);
							$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
						}
						$totalvat = $outputlangs->transcountrynoentities("TotalVAT", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalVAT", $mysoc->country_code) : '');
						$totalvat .= ' ';
						$totalvat .= vatrate($tvakey, true).$tvacompl;
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval, 0, $outputlangs), 0, 'R', true);
					}
				}

				//Local tax 1 after VAT
				//if (getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') == 'localtax1on')
				//{
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';

							$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}
				//}
				//Local tax 2 after VAT
				//if (getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') == 'localtax2on')
				//{
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						// retrieve global local tax
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX2_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}
				//}

				// Total TTC (typography: highlighted box, label 10 pt bold, amount 12 pt bold, accent colour)
				$index++;
				$navSimple = (!isModEnabled("multicurrency") || $object->multicurrency_tx == 1 || getDolGlobalInt('MULTICURRENCY_SHOW_ALSO_MAIN_CURRENCY_ON_PDF') == 0);
				if (!$navSimple) {
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->SetTextColor(49, 84, 161);
					$pdf->SetFillColor(237, 242, 250);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalTTC") : ''), $useborder, 'L', true);
				}
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				if ($navSimple) {
					$navH = navonlinePdfTotalBox($pdf, $col1x, $this->page_largeur - $this->marge_droite, $tab2_top + $tab2_hl * $index + 1, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? "\n".$outputlangsbis->transnoentities("TotalTTC") : ''), price($total_ttc, 0, $outputlangs));
					$index += (int) ceil(($navH + 1.5) / $tab2_hl) - 1;
				} else {
					$pdf->MultiCell($largcol2, $tab2_hl, price($total_ttc, 0, $outputlangs), $useborder, 'R', true);

					//$pdf->writeHTMLCell($largcol2, $tab2_hl, null, null, '<font size="-2">('.price($sign * $object->total_ttc, 0, $outputlangs, 1, -1, 'MT', $mysoc->currency_code).')</font> &nbsp; '.price($sign * $total_ttc, 0, $outputlangs), $useborder, 1, true, true, 'R');
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->SetTextColor(0, 0, 60);
					$pdf->SetFillColor(224, 224, 224);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalTTC") : '').' ('.$outputlangs->getCurrencySymbol($mysoc->currency_code).')', $useborder, 'L', true);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($total_ttc_origin, 0, $outputlangs, 1, -1, -1, $mysoc->currency_code), $useborder, 'L', true);
				}
			}
		}

		$pdf->SetTextColor(0, 0, 0);

		$resteapayer = 0;
		/*
		$resteapayer = $object->total_ttc - $deja_regle;
		if (!empty($object->paye)) $resteapayer=0;
		*/

		if ($deja_regle > 0) {
			// Already paid + Deposits
			$index++;

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("AlreadyPaid") : ''), 0, 'L', false);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle, 0, $outputlangs), 0, 'R', false);

			/*
			if ($object->close_code == 'discount_vat') {
			$index++;
			$pdf->SetFillColor(255,255,255);

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("EscompteOfferedShort"), $useborder, 'L', 1);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ttc - $deja_regle, 0, $outputlangs), $useborder, 'R', 1);

			$resteapayer=0;
			}
			*/

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("RemainderToPay") : ''), $useborder, 'L', true);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer, 0, $outputlangs), $useborder, 'R', true);

			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(0, 0, 0);
		}

		$parameters = array('pdf' => &$pdf, 'object' => &$object, 'outputlangs' => $outputlangs, 'index' => &$index, 'posy' => $posy);

		$reshook = $hookmanager->executeHooks('afterPDFTotalTable', $parameters, $this); // Note that $action and $object may have been modified by some hooks
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
		}

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Copy of pdf_cyan::_pagehead() (Dolibarr 24.0): diff it with the core after an upgrade. NavOnline change: the
	 * title and the lines under it through navonlinePdfHeadTitle() / navonlinePdfHeadLines(), the same on every
	 * document.
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Propal     $object         Proposal
	 * @param int<0,1>   $showaddress    0=no, 1=yes
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return array{top_shift:float|int,shipp_shift:float|int}
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null)
	{
		global $conf, $langs;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "propal", "companies", "bills"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 100;

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		$pdf->SetXY($this->marge_gauche, $posy);

		// Logo
		if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			if ($this->emetteur->logo) {
				$logodir = $conf->mycompany->dir_output;
				if (!empty($conf->mycompany->multidir_output[$object->entity ?? $conf->entity])) {
					$logodir = $conf->mycompany->multidir_output[$object->entity ?? $conf->entity];
				}
				if (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO')) {
					$logo = $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
				} else {
					$logo = $logodir.'/logos/'.$this->emetteur->logo;
				}
				if (is_readable($logo)) {
					$height = pdf_getHeightForLogo($logo);
					$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height); // width=0 (auto)
				} else {
					$pdf->SetTextColor(200, 0, 0);
					$pdf->SetFont('', 'B', $default_font_size - 2);
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
				}
			} else {
				$text = $this->emetteur->name;
				$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($text), 0, $ltrdirection);
			}
		}

		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines)
		$title = $outputlangs->transnoentities("PdfCommercialProposalTitle").' '.$outputlangs->convToOutputCharset($object->ref);
		$draft = ($object->status == $object::STATUS_DRAFT);
		if ($draft) {
			$title .= ' - '.$outputlangs->transnoentities("NotValidated");
		}
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$displaydate = getDolGlobalString('MAIN_PDF_DATE_TEXT') ? "daytext" : "day";
		$navLines = array();
		$ref_customer = $object->ref_customer ?: $object->ref_client;
		if ($ref_customer) {
			$navLines[] = $outputlangs->transnoentities("RefCustomer").": ".dol_trunc($outputlangs->convToOutputCharset($ref_customer), 65);
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT_TITLE')) {
			$object->fetchProject();
			if (!empty($object->project->title)) {
				$navLines[] = $outputlangs->transnoentities("Project").": ".dol_trunc($object->project->title, 50);
			}
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$outputlangs->load("projects");
				$navLines[] = $outputlangs->transnoentities("RefProject").": ".$object->project->ref;
			}
		}
		$navLines[] = $outputlangs->transnoentities("Date").": ".dol_print_date($object->date, $displaydate, false, $outputlangs, true);
		$navLines[] = $outputlangs->transnoentities("DateEndPropal").": ".dol_print_date($object->fin_validite, $displaydate, false, $outputlangs, true);
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_client);
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerAccountancyCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_compta_client);
		}
		if (getDolGlobalString('DOC_SHOW_FIRST_SALES_REP')) {
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$usertmp = new User($this->db);
				$usertmp->fetch($arrayidcontact[0]);
				$navLines[] = $outputlangs->transnoentities("SalesRepresentative").": ".$usertmp->getFullName($langs);
			}
		}
		$navLines = array_merge($navLines, navonlineLinkedObjectLines($object, $outputlangs, (string) $ref_customer));	// May modify $object->note_public
		$navBottom = navonlinePdfHeadLines($pdf, $posx, $posy, $w, $navLines, $default_font_size);
		$shipp_shift = 0;
		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute);

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';
			// Add internal contact of object if defined
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$object->fetch_user($arrayidcontact[0]);
				$labelbeforecontactname = ($outputlangs->transnoentities("FromContactName") != 'FromContactName' ? $outputlangs->transnoentities("FromContactName") : $outputlangs->transnoentities("Name"));
				$carac_emetteur .= $labelbeforecontactname." ".$outputlangs->convToOutputCharset($object->user->getFullName($outputlangs));
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ' (' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && !empty($object->user->office_phone)) ? $object->user->office_phone : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ', ' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT') && !empty($object->user->email)) ? $object->user->email : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ')' : '';
				$carac_emetteur .= "\n";
			}

			$carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

			// Show sender
			$posy = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 30 : 32;
			$posy += $top_shift + $this->marge_haute;
			$posx = $this->marge_gauche;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;


			$navSX = $posx;	// NavOnline: the frames are drawn under the content (see the shipping address)
			$navSW = $widthrecbox;
			$navBoxTop = $posy;

			// Show sender frame
			if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
				navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
				$pdf->SetFont('', '', 8);
				$pdf->SetXY($posx, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillFrom"), 0, $ltrdirection);
				$pdf->SetXY($posx, $posy);
				navonlinePdfThinLine($pdf);	// typography: white box, thin frame
				navonlinePdfThinLine($pdf);
				navonlinePdfLineReset($pdf);
				navonlinePdfLineReset($pdf);
				$pdf->SetTextColor(0, 0, 60);
			}

			// Show sender name
			if (!getDolGlobalString('MAIN_PDF_HIDE_SENDER_NAME')) {
				$pdf->SetXY($posx + 3, $posy + 3);
				navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
				$pdf->SetFont('', 'B', 10.5);
				$pdf->MultiCell($widthrecbox - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
				$posy = $pdf->getY();
			}

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			$navSEnd = navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox - 2) - 4, $carac_emetteur, $ltrdirection);	// typography: 9.5 / 8.5 pt


			// If CUSTOMER contact defined, we use it
			$usecontact = false;
			$arrayidcontact = $object->getIdContact('external', 'CUSTOMER');
			if (count($arrayidcontact) > 0) {
				$usecontact = true;
				$result = $object->fetch_contact($arrayidcontact[0]);
			}

			// Recipient name
			if ($usecontact && ($object->contact->socid != $object->thirdparty->id && (!isset($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT) || getDolGlobalString('MAIN_USE_COMPANY_NAME_OF_CONTACT')))) {
				$thirdparty = $object->contact;
			} else {
				$thirdparty = $object->thirdparty;
			}

			$carac_client_name = pdfBuildThirdpartyName($thirdparty, $outputlangs);

			$mode = 'target';
			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), ($usecontact ? 1 : 0), $mode, $object);

			// Show recipient
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 30 + $this->marge_haute : 32 + $this->marge_haute;
			$posy += $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			$navRX = $posx;
			$navRW = $widthrecbox;

			// Show recipient frame
			if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
				navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
				$pdf->SetFont('', '', 8);
				$pdf->SetXY($posx + 2, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo"), 0, $ltrdirection);
				navonlinePdfThinLine($pdf);
				navonlinePdfLineReset($pdf);
			}

			// Show recipient name
			$pdf->SetXY($posx + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
			$pdf->MultiCell($widthrecbox, 2, $carac_client_name, 0, $ltrdirection);

			$posy = $pdf->getY();

			// Show recipient information
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($posx + 2, $posy);
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
			$navREnd = navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox) - 4, $carac_client, $ltrdirection);	// typography: 9.5 / 8.5 pt
			// Show shipping/delivery addressAdd commentMore actions
			// NavOnline: with a delivery address box (only one that differs from the billing address) each box takes the
			// height of its own content, the delivery box right under the customer box, and the table starts under the
			// lower of the seller and the delivery box; without one both boxes keep the core height
			$navShip = getDolGlobalInt('PROPOSAL_SHOW_SHIPPING_ADDRESS') && navonlineShippingContact($this->db, $object) !== null;
			$navSH = $navShip ? max(24, $navSEnd + 3 - $navBoxTop) : $hautcadre;
			$navRH = $navShip ? max(24, $navREnd + 3 - $navBoxTop) : $hautcadre;
			navonlinePdfThinLine($pdf);
			if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
				$pdf->RoundedRect($navSX, $navBoxTop, $navSW, $navSH, $this->corner_radius, '1234', 'D');
			}
			if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
				$pdf->RoundedRect($navRX, $navBoxTop, $navRW, $navRH, $this->corner_radius, '1234', 'D');
			}
			navonlinePdfLineReset($pdf);
			if ($navShip) {
				$navShipH = navonlinePdfShippingAddressBox($pdf, $this->db, $object, $outputlangs, null, $this->emetteur, $navRX, $navBoxTop + $navRH + 6, $navRW);
				// the core puts the table at marge_haute + 80 + top_shift + shipp_shift (8 mm under its 40 mm boxes)
				$shipp_shift = $navBoxTop + max($navSH, $navRH + $navShipH) + 8 - ($this->marge_haute + 80 + $top_shift);
			}
		}
		$pdf->SetTextColor(0, 0, 0);

		return array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift);
	}
}
