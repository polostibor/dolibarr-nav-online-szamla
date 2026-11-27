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
 * \file    htdocs/custom/navonline/core/modules/commande/doc/pdf_dijbekero.modules.php
 * \ingroup navonline
 * \brief   "Díjbekérő" (payment request, not an invoice) PDF of a customer order.
 *
 * Built on the core order template eratosthene: title "Díjbekérő", date label "Kelte", payment due date (order date +
 * payment terms), the transfer reference (order ref) and the notice that the document is not an invoice. It is saved
 * as <ref>_dijbekero.pdf next to the order PDF, which it leaves untouched, and is not the order's main document.
 */

require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/doc/pdf_eratosthene.modules.php';
require_once dirname(__DIR__, 4).'/class/navonlineordertotals.trait.php';
require_once dirname(__DIR__, 4).'/class/navonlineorderpagehead.trait.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Díjbekérő PDF of a customer order
 */
class pdf_dijbekero extends pdf_eratosthene
{
	use NavOnlinePdfTypography;
	use NavOnlineOrderTotalTable;
	use NavOnlineOrderPagehead;

	/** File name suffix of the generated PDF */
	const FILE_SUFFIX = '_dijbekero';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		parent::__construct($db);
		$langs->load('navonline@navonline');
		$this->name = 'dijbekero';
		$this->description = $langs->trans('NavOnlineProformaModelDesc');
		$this->update_main_doc_field = 0;	// the order PDF stays the main document
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Generate the PDF, then move it to <ref>_dijbekero.pdf. An existing order PDF (<ref>.pdf) is kept as it was.
	 *
	 * @param Commande  $object          Order
	 * @param Translate $outputlangs     Output language
	 * @param string    $srctemplatepath Not used
	 * @param int<0,1>  $hidedetails     Hide details of lines
	 * @param int<0,1>  $hidedesc        Hide description
	 * @param int<0,1>  $hideref         Hide ref
	 * @return int<-1,1> 1 = OK, 0 or -1 = KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->load('navonline@navonline');

		$base = getMultidirOutput($object);
		if (empty($base) || !empty($object->specimen)) {
			return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
		}
		$ref = dol_sanitizeFileName($object->ref);
		$main = $base.'/'.$ref.'/'.$ref.'.pdf';
		$target = $base.'/'.$ref.'/'.$ref.self::FILE_SUFFIX.'.pdf';
		$keep = $main.'.navonline-keep';

		// Keep the order PDF aside while the parent writes <ref>.pdf
		if (is_file($main) && !@rename($main, $keep)) {
			$this->error = 'Cannot set aside '.$main;
			return -1;
		}
		// The parent reserves 40 mm for info + totals; the due date, reference and notice need about 18 mm more. The
		// free text height is the only knob of the parent layout: raise it for this document only.
		global $conf;
		$prevFreeText = $conf->global->MAIN_PDF_FREETEXT_HEIGHT ?? null;
		$conf->global->MAIN_PDF_FREETEXT_HEIGHT = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) + 18;
		// One language (the customer's), as the order confirmation, the proposal and the delivery note (user 2026-11-26)
		global $outputlangsbis;
		$prevBis = $conf->global->PDF_USE_ALSO_LANGUAGE_CODE ?? null;
		unset($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
		$prevBisObj = $outputlangsbis;
		$outputlangsbis = null;
		try {
			$navNote = navonlinePdfNoteStyle($object, $outputlangs);	// NavOnline: note smaller, italic
			try {
				$res = parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
			} finally {
				$object->note_public = $navNote;
			}
		} finally {
			if ($prevBis !== null) {
				$conf->global->PDF_USE_ALSO_LANGUAGE_CODE = $prevBis;
			}
			$outputlangsbis = $prevBisObj;
			if ($prevFreeText === null) {
				unset($conf->global->MAIN_PDF_FREETEXT_HEIGHT);
			} else {
				$conf->global->MAIN_PDF_FREETEXT_HEIGHT = $prevFreeText;
			}
		}
		$ok = true;
		if ($res > 0 && is_file($main)) {
			if (is_file($target)) {
				@unlink($target);
			}
			$ok = @rename($main, $target);
			if ($ok) {
				$this->result = array('fullpath' => $target);
			}
		}
		if (is_file($keep)) {
			if (is_file($main)) {
				@unlink($main);
			}
			@rename($keep, $main);
		}
		if (!$ok) {
			$this->error = 'Cannot create '.$target;
			return -1;
		}
		return $res;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Header: title "Díjbekérő", date label "Kelte".
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Commande   $object         Order
	 * @param int<0,1>   $showaddress    Show address
	 * @param Translate  $outputlangs    Output language
	 * @param ?Translate $outputlangsbis Second output language
	 * @param string     $titlekey       Not used (always NavOnlineProformaTitle)
	 * @return array<string,float>
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = 'NavOnlineProformaTitle')
	{
		// phpcs:enable
		$swaps = array(array($outputlangs, 'OrderDate'));
		if (is_object($outputlangsbis)) {
			$outputlangsbis->load('navonline@navonline');
			$swaps[] = array($outputlangsbis, 'DateInvoice');
		}
		$saved = array();
		foreach ($swaps as $i => $s) {
			$saved[$i] = $s[0]->tab_translate[$s[1]] ?? null;
			$s[0]->tab_translate[$s[1]] = $s[0]->transnoentitiesnoconv('NavOnlineProformaDate');
		}
		try {
			return $this->navOrderPagehead($pdf, $object, $showaddress, $outputlangs, $outputlangsbis, 'NavOnlineProformaTitle');
		} finally {
			foreach ($swaps as $i => $s) {
				if ($saved[$i] === null) {
					unset($s[0]->tab_translate[$s[1]]);
				} else {
					$s[0]->tab_translate[$s[1]] = $saved[$i];
				}
			}
		}
	}

	/**
	 * Info block under the lines: payment due date and transfer reference, the standard payment information, then the
	 * "not an invoice" notice.
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param Commande  $object      Order
	 * @param float     $posy        Y position
	 * @param Translate $outputlangs Output language
	 * @return float Y position after the block
	 */
	protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
	{
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		global $outputlangsbis;

		$size = pdf_getPDFFontSize($outputlangs);
		$fs = $size - getDolGlobalInt('PDF_DIFFSIZE_TITLE', 3);
		$bis = (is_object($outputlangsbis) && $outputlangsbis->defaultlang != $outputlangs->defaultlang) ? $outputlangsbis : null;
		if ($bis) {
			$bis->load('navonline@navonline');
		}

		// NavOnline (typography): label / value pairs as on the invoice
		$due = $this->dueDate($object);
		if ($due) {
			$posy = navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, array(array(rtrim($outputlangs->transnoentities('NavOnlineProformaDue'), ':'), dol_print_date($due, 'day', false, $outputlangs, true))));
		}
		$pdf->SetFont('', '', 8.5);
		navonlinePdfColor($pdf, 'text');
		$pdf->MultiCell(100, 0, $outputlangs->transnoentities('NavOnlineProformaReference', $outputlangs->convToOutputCharset($object->ref)), 0, 'L', false, 1, $this->marge_gauche, $posy);
		$posy = $pdf->GetY() + 2.5;
		$rows = array();
		if (!empty($object->delivery_date)) {
			$rows[] = array($outputlangs->transnoentities('DateDeliveryPlanned'), dol_print_date($object->delivery_date, 'day', false, $outputlangs, true));
		} elseif (!empty($object->availability_code) || !empty($object->availability)) {
			$key = 'AvailabilityType'.$object->availability_code;
			$label = ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $outputlangs->convToOutputCharset((string) $object->availability);
			$rows[] = array($outputlangs->transnoentities('AvailabilityPeriod'), str_replace('\n', "\n", $label));
		}
		$rows = array_merge($rows, navonlinePdfTermRows($object, $outputlangs));
		$posy = navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, $rows);
		$posy = navonlinePdfBankForDoc($pdf, $object, $outputlangs, $this->marge_gauche, $posy);

		$pdf->SetFont('', 'B', 9);
		navonlinePdfColor($pdf, 'text');
		$pdf->SetXY($this->marge_gauche, $posy + 1);
		$notice = $outputlangs->transnoentities('NavOnlineProformaNotInvoice');
		if ($bis) {
			$notice .= "\n".$bis->transnoentities('NavOnlineProformaNotInvoice');
		}
		$pdf->MultiCell(100, 4, $notice, 0, 'L');
		$pdf->SetFont('', '', $fs);

		return $pdf->GetY() + 1;
	}

	/**
	 * @param Commande $object Order
	 * @return int Due date (order date + payment terms), 0 without payment terms
	 */
	private function dueDate($object)
	{
		if (empty($object->cond_reglement_id) || empty($object->date)) {
			return 0;
		}
		$tmp = new Facture($this->db);
		$tmp->date = $object->date;
		return (int) $tmp->calculate_date_lim_reglement((int) $object->cond_reglement_id);
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
}
