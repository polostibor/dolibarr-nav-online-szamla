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
 * \file    htdocs/custom/navonline/core/modules/commande/doc/pdf_visszaigazolas.modules.php
 * \ingroup navonline
 * \brief   "Rendelés-visszaigazolás" (order confirmation) PDF of a customer order.
 *
 * Built on the core order template eratosthene: title "Rendelés-visszaigazolás"; the info block shows both the
 * committed delivery date ("Vállalási határidő") and the lead time ("Szállítási határidő") – the core shows only one
 * of them – with the short date format, and the shipping method.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/doc/pdf_eratosthene.modules.php';
require_once dirname(__DIR__, 4).'/class/navonlineordertotals.trait.php';
require_once dirname(__DIR__, 4).'/class/navonlineorderpagehead.trait.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Order confirmation PDF
 */
class pdf_visszaigazolas extends pdf_eratosthene
{
	use NavOnlinePdfTypography;
	use NavOnlineOrderTotalTable;
	use NavOnlineOrderPagehead;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		parent::__construct($db);
		$langs->load('navonline@navonline');
		$this->name = 'visszaigazolas';
		$this->description = $langs->trans('NavOnlineOrderConfirmModelDesc');
	}

	/** @var string Incoterms text of the document being generated */
	private $navInco = '';

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param Commande  $object          Order
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
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'orders', 'propal', 'sendings', 'navonline@navonline'));

		// The parent reserves 40 mm for info + totals; the deadlines and the shipping method need about 12 mm more
		$prevFreeText = $conf->global->MAIN_PDF_FREETEXT_HEIGHT ?? null;
		$conf->global->MAIN_PDF_FREETEXT_HEIGHT = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) + 12;
		// Incoterms under the lines (drawInfoTable), not in the core box above the lines
		$this->navInco = navonlineIncotermsText($this->db, $object, $outputlangs);
		$savedInco = $object->fk_incoterms ?? null;
		$object->fk_incoterms = 0;
		// One language (the customer's) only: user's decision (2026-11-26), the confirmation is not a tax document. (The
		// bilingual totals overlap of the core is fixed by NavOnlineOrderTotalTable, so this is not needed for layout.)
		global $outputlangsbis;
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
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Header with the title "Rendelés-visszaigazolás".
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Commande   $object         Order
	 * @param int<0,1>   $showaddress    Show address
	 * @param Translate  $outputlangs    Output language
	 * @param ?Translate $outputlangsbis Second output language
	 * @param string     $titlekey       Not used
	 * @return array<string,float>
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = 'NavOnlineOrderConfirmTitle')
	{
		// phpcs:enable
		return $this->navOrderPagehead($pdf, $object, $showaddress, $outputlangs, $outputlangsbis, 'NavOnlineOrderConfirmTitle');
	}

	/**
	 * Info block: committed delivery date, lead time and shipping method, then the standard block (payment terms,
	 * payment mode, bank account) without its own single deadline line.
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param Commande  $object      Order
	 * @param float     $posy        Y
	 * @param Translate $outputlangs Output language
	 * @return float Y after the block
	 */
	protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
	{
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		// NavOnline (typography): label / value pairs as on the invoice, in place of the core block
		$rows = array();
		if (!empty($object->delivery_date)) {
			$rows[] = array($outputlangs->transnoentities('DateDeliveryPlanned'), dol_print_date($object->delivery_date, 'day', false, $outputlangs, true));
		}
		if (!empty($object->availability_code) || !empty($object->availability)) {
			$key = 'AvailabilityType'.$object->availability_code;
			$label = ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $outputlangs->convToOutputCharset((string) $object->availability);
			$rows[] = array($outputlangs->transnoentities('AvailabilityPeriod'), str_replace('\n', "\n", $label));
		}
		if (!empty($object->shipping_method_id)) {
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
		$rows = array_merge($rows, navonlinePdfTermRows($object, $outputlangs));
		$posy = navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, $rows);
		return navonlinePdfBankForDoc($pdf, $object, $outputlangs, $this->marge_gauche, $posy);
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
