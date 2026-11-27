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
 * \file    htdocs/custom/navonline/class/navonlineordertotals.trait.php
 * \ingroup navonline
 * \brief   Totals block of the order PDFs (díjbekérő, visszaigazolás) with a wider label column on bilingual documents.
 *
 * Copy of pdf_eratosthene::drawTotalTable() (Dolibarr 24.0). The only change: with a second language the labels
 * ("Total (excl. tax) / Összesen (nettó)") do not fit the 50 mm column, wrap, and the next row overlaps them; the label
 * column is then 112-176 mm, as on the sponge_hu invoice. After a Dolibarr upgrade compare it with the core function.
 */

/**
 * Wider totals label column for bilingual order PDFs
 */
require_once dirname(__DIR__).'/lib/navonline.lib.php';

trait NavOnlineOrderTotalTable
{
	/**
	 * Bottom of the info and totals area drawn at a Y (measured in a rollback, see navTotalsY()).
	 *
	 * @param TCPDF        $pdf         PDF
	 * @param CommonObject $object      Document
	 * @param Translate    $outputlangs Langs
	 * @param float        $y           Y
	 * @return float
	 */
	protected function navMeasureTotalsArea($pdf, $object, $outputlangs, $y)
	{
		$a = $this->drawInfoTable($pdf, $object, $y, $outputlangs);
		$b = $this->drawTotalTable($pdf, $object, 0, $y, $outputlangs);
		return max((float) $a, (float) $b);
	}

	/**
	 *	Show total to pay
	 *
	 *	@param	TCPDF		$pdf            Object PDF
	 *	@param  Commande	$object         Object to show
	 *	@param  float		$deja_regle     Montant deja regle
	 *	@param	float		$posy			Position depart
	 *	@param	Translate	$outputlangs	Object langs
	 *  @param  ?Translate	$outputlangsbis	Object lang for output bis
	 *	@return float						Position pour suite
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
		if (is_object($outputlangsbis)) {
			// NavOnline: bilingual labels need a wider label column, else they wrap and the next row overlaps them. The
			// left info block ends at 110 mm; 24 mm stays for the amounts (as on the sponge_hu invoice).
			$col1x = 112;
			$col2x = 176;
		}
		if ($this->page_largeur < 210) { // To work with US executive format
			if (is_object($outputlangsbis)) {
				$col1x -= 15;
				$col2x -= 10;
			} else {
				$col2x -= 20;
			}
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

				$total_discount_to_show = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $multicurrency_total_discount_on_lines : $total_discount_on_lines);
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_discount_to_show, 0, $outputlangs), 0, 'R', true);

				$index++;
			}
		}

		// Total HT
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHT") : ''), 0, 'L', true);
		$total_ht = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
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
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

							$total_localtax = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}

				//Local tax 2 before VAT
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
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
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

				// VAT
				foreach ($this->tva_array as $tvakey => $tvaval) {
					if ((float) $tvakey != 0) {    // On affiche pas taux 0, $tvakey can be 'rate (code)'
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
						if (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'rateonly') {
							$totalvat .= vatrate((string) $tvaval['vatrate'], true).$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'codeonly') {
							$totalvat .= $tvaval['vatcode'].$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
							$totalvat .= $tvacompl;
						} else {
							$totalvat .= vatrate((string) $tvaval['vatrate'], true).($tvaval['vatcode'] ? ' ('.$tvaval['vatcode'].')' : '').$tvacompl;
						}
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price(price2num($tvaval['amount'], 'MT'), 0, $outputlangs), 0, 'R', true);
					}
				}

				//Local tax 1 after VAT
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
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
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

				//Local tax 2 after VAT
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
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
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
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalTTC", $mysoc->country_code) : ''), $useborder, 'L', true);
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

		$creditnoteamount = 0;
		$depositsamount = 0;
		//$creditnoteamount=$object->getSumCreditNotesUsed();
		//$depositsamount=$object->getSumDepositsUsed();
		//print "x".$creditnoteamount."-".$depositsamount;exit;
		$resteapayer = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!empty($object->paye)) {
			$resteapayer = 0;
		}

		if ($deja_regle > 0) {
			// Already paid + Deposits
			$index++;

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("AlreadyPaid") : ''), 0, 'L', false);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle, 0, $outputlangs), 0, 'R', false);

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
}
