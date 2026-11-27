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
 * \file    htdocs/custom/navonline/core/modules/expedition/doc/pdf_espadon_hu.modules.php
 * \ingroup navonline
 * \brief   Shipment (szállítólevél) PDF: core espadon with the customer's order number on its own header line.
 *
 * Only _pagehead() is overridden: a copy of the core espadon (Dolibarr 24.0) method where the order line no longer
 * carries the customer ref in parentheses and a "Vevő rendelésszáma" line follows the order date. After a Dolibarr
 * upgrade compare the method with the new core file.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/doc/pdf_espadon.modules.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Shipment PDF with the customer order number
 */
class pdf_espadon_hu extends pdf_espadon
{
	use NavOnlinePdfTypography;
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		parent::__construct($db);
		$this->name = 'espadon_hu';
		$this->description = 'Szállítólevél (espadon) a vevő rendelésszámával';
	}

	/** @var string Incoterms text of the document being generated */
	private $navInco = '';

	/** @var string[] Gross / net weight lines (export shipment) */
	private $navWeight = array();

	/** @var Translate|null Output language of the document being generated */
	private $navLangs = null;

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Generate the PDF with Hungarian delivery note wording: shipped quantity column, "Összesen" totals row, one
	 * "Szállítási mód: X – csomagszám: N" line (linked to the carrier's tracking page) and space for the signatures.
	 *
	 * @param Expedition $object          Shipment
	 * @param Translate  $outputlangs     Output language
	 * @param string     $srctemplatepath Not used
	 * @param int<0,1>   $hidedetails     Hide details
	 * @param int<0,1>   $hidedesc        Hide description
	 * @param int<0,1>   $hideref         Hide ref
	 * @return int<-1,1>
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'sendings', 'navonline@navonline'));
		$this->navLangs = $outputlangs;
		$this->navMethod = '';

		$trackLine = '';
		$labels = array('QtyToShip' => 'NavOnlineShipQtyShipped', 'Total' => 'NavOnlineShipTotal', 'WeightVolShort' => 'NavOnlineShipWeightTitle', 'Sender' => 'NavOnlineShipSender');
		$saveTracking = array($object->tracking_number ?? '', $object->shipping_method_id ?? 0, $object->tracking_url ?? '', $object->note_public ?? '');
		if (!empty($object->tracking_number)) {
			// One line "Szállítási mód: GLS – csomagszám : <number or link>" as the first line of the note box (190 mm
			// wide): the core prints the tracking number in a 60 mm cell (it wrapped) plus a second method / link block
			$method = '';
			if ($object->shipping_method_id > 0) {
				$res = $this->db->query('SELECT code, libelle FROM '.MAIN_DB_PREFIX.'c_shipment_mode WHERE rowid = '.((int) $object->shipping_method_id));
				$o = $res ? $this->db->fetch_object($res) : null;
				if ($o) {
					$tr = $outputlangs->transnoentities('SendingMethod'.strtoupper($o->code));
					$method = ($tr !== 'SendingMethod'.strtoupper($o->code)) ? $tr : $o->libelle;
				}
				$object->getUrlTrackingStatus((string) $object->tracking_number);
			}
			$this->navMethod = $method;
			$label = $method !== '' ? $outputlangs->transnoentitiesnoconv('NavOnlineShipMethodParcel', $method) : $outputlangs->transnoentitiesnoconv('NavOnlineShipParcel');
			$number = (!empty($object->tracking_url) && $object->tracking_url !== $object->tracking_number) ? $object->tracking_url : dol_escape_htmltag((string) $object->tracking_number);
			// Data line, not part of the note: bold, not italic (as the tracking line of the goods receipt)
			$trackLine = '<b>'.dol_escape_htmltag($label).': '.$number.'</b>';
			$object->tracking_number = '';
			$object->shipping_method_id = 0;	// skips the core second tracking block
		}
		// One language (the customer's), as the order confirmation and the proposal (user 2026-11-26): the core prints
		// the column titles also in PDF_USE_ALSO_LANGUAGE_CODE
		$prevBis = $conf->global->PDF_USE_ALSO_LANGUAGE_CODE ?? null;
		unset($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
		$saved = array();
		foreach ($labels as $key => $navKey) {
			$saved[$key] = $outputlangs->tab_translate[$key] ?? null;
			$outputlangs->tab_translate[$key] = $outputlangs->transnoentitiesnoconv($navKey, $this->navMethod ?? '');
		}
		// Space for the signature block under the totals: the free text height is the only knob of the core layout
		$prevFreeText = $conf->global->MAIN_PDF_FREETEXT_HEIGHT ?? null;
		$conf->global->MAIN_PDF_FREETEXT_HEIGHT = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) + 22;
		// Incoterms under the lines on the left (_tableau_tot), not in the core box above the lines
		$this->navInco = navonlineIncotermsText($this->db, $object, $outputlangs);
		// Export (EAM order): gross / net weight under it
		$this->navWeight = array();
		$orderId = (in_array((string) ($object->origin_type ?? $object->origin ?? ''), array('commande', 'order'), true)) ? (int) $object->origin_id : 0;
		if ($orderId > 0 && navonlineHasEamLine($this->db, 'commandedet', 'fk_commande', $orderId)) {
			$this->navWeight = navonlineWeightLines(navonlineShipmentWeights($this->db, array((int) $object->id)), null, $outputlangs);
		}
		if ($this->navWeight) {
			$conf->global->MAIN_PDF_FREETEXT_HEIGHT += 4 * count($this->navWeight);
		}
		$savedInco = $object->fk_incoterms ?? null;
		$object->fk_incoterms = 0;
		try {
			$navNote = navonlinePdfNoteStyle($object, $outputlangs, $trackLine);	// NavOnline: note smaller, italic
			try {
				return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
			} finally {
				$object->note_public = $navNote;
			}
		} finally {
			$object->fk_incoterms = $savedInco;
			if ($prevFreeText === null) {
				unset($conf->global->MAIN_PDF_FREETEXT_HEIGHT);
			} else {
				$conf->global->MAIN_PDF_FREETEXT_HEIGHT = $prevFreeText;
			}
			foreach ($saved as $key => $val) {
				if ($val === null) {
					unset($outputlangs->tab_translate[$key]);
				} else {
					$outputlangs->tab_translate[$key] = $val;
				}
			}
			list($object->tracking_number, $object->shipping_method_id, $object->tracking_url, $object->note_public) = $saveTracking;
			if ($prevBis !== null) {
				$conf->global->PDF_USE_ALSO_LANGUAGE_CODE = $prevBis;
			}
		}
	}

	/** @var string Carrier name for the tracking line */
	private $navMethod = '';

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
		return (float) $this->_tableau_tot($pdf, $object, 0, $y, $outputlangs);
	}

	/**
	 * Totals row, then the signature block (handed over by / received by / date of receipt).
	 *
	 * @param TCPDF      $pdf         PDF
	 * @param Expedition $object      Shipment
	 * @param float      $deja_regle  Not used
	 * @param float      $posy        Y
	 * @param Translate  $outputlangs Output language
	 * @return float Y after the block
	 */
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs)
	{
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		$rowY = $posy;
		$posy = parent::_tableau_tot($pdf, $object, $deja_regle, $posy, $outputlangs);

		$size = pdf_getPDFFontSize($outputlangs);
		// NavOnline (typography): Incoterms and the weights as label / value pairs, as on the invoice (the Incoterms value
		// bold, the weights regular)
		$rows = array();
		if (!empty($this->navInco)) {
			list($k, $v) = explode(': ', $this->navInco, 2) + array('', '');
			$rows[] = array(rtrim($k, ':'), $v, true);
		}
		foreach ((array) $this->navWeight as $wl) {
			list($k, $v) = explode(': ', $wl, 2) + array('', '');
			$rows[] = array(rtrim($k, ':'), $v, false);
		}
		if ($rows) {
			$posy = navonlinePdfInfoRows($pdf, $this->marge_gauche, max($posy, $rowY + 6.5), 120, $rows) - 4.5;
		}
		$top = $posy + 6;
		$width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$cols = array('NavOnlineShipHandedOver', 'NavOnlineShipReceivedBy', 'NavOnlineShipReceivedDate');
		$cw = $width / count($cols);
		$pdf->SetFont('', '', $size - 2);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetDrawColor(128, 128, 128);
		foreach ($cols as $i => $key) {
			$x = $this->marge_gauche + $i * $cw;
			$pdf->line($x + 2, $top + 10, $x + $cw - 6, $top + 10);
			$pdf->SetXY($x + 2, $top + 11);
			$pdf->MultiCell($cw - 8, 3, $outputlangs->transnoentities($key), 0, 'C');
		}
		return $top + 16;
	}

	/**
	 * Weight column: decimals with the separator of the output language (core prints "8.7 kg", the total "12,5 kg").
	 *
	 * @param TCPDF  $pdf        PDF
	 * @param float  $curY       Y
	 * @param string $colKey     Column
	 * @param string $columnText Text
	 * @return void
	 */
	public function printStdColumnContent($pdf, &$curY, $colKey, $columnText = '')
	{
		if ($colKey === 'weight' && is_object($this->navLangs)) {
			$dec = $this->navLangs->transnoentitiesnoconv('SeparatorDecimal');
			if ($dec !== '' && $dec !== '.' && $dec !== 'SeparatorDecimal') {
				$columnText = preg_replace('/(\d)\.(\d)/', '$1'.$dec.'$2', (string) $columnText);
			}
		}
		$pdf->SetFont('', '', 9.5);	// typography
		navonlinePdfColor($pdf, 'text');
		parent::printStdColumnContent($pdf, $curY, $colKey, $columnText);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Table frame. With MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT the column titles are repeated on the next pages too: core
	 * espadon always hides them there (hidetop = 1) and starts the lines right at tab_top_newpage, so the title row is
	 * drawn in the free band above it (the page head ends well above marge_haute + 32).
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param float      $tab_top        Top of the table
	 * @param float      $tab_height     Height
	 * @param float      $nexY           Not used
	 * @param Translate  $outputlangs    Langs
	 * @param int        $hidetop        1 = page after the first one
	 * @param int        $hidebottom     Hide bottom
	 * @param string     $currency       Currency
	 * @param ?Translate $outputlangsbis Second language
	 * @return void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
	{
		// phpcs:enable
		$navTop = ($hidetop == 1 && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) ? $tab_top - $this->tabTitleHeight : $tab_top;
		$navH = $this->navTableHeight($pdf, $navTop, $tab_height + $tab_top - $navTop);	// NavOnline: frame ends under the last line
		if ($navH === null) {
			return;
		}
		if ($navH < $tab_height + $tab_top - $navTop) {
			$hidebottom = 0;
		}
		$tab_height = $navH - ($tab_top - $navTop);
		if ($hidetop == 1 && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
			parent::_tableau($pdf, $tab_top - $this->tabTitleHeight, $tab_height + $this->tabTitleHeight, $nexY, $outputlangs, 0, $hidebottom, $currency, $outputlangsbis);
			return;
		}
		parent::_tableau($pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop, $hidebottom, $currency, $outputlangsbis);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *  Show top header of page.
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Expedition	$object     	Object to show
	 *  @param  int<0,1>  	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @return	float|int                   Return topshift value
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf, $mysoc;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		$outputlangs->load("orders");

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		//Prepare next
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 110;

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		$pdf->SetXY($this->marge_gauche, $posy);

		// Logo
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

		$pdf->SetDrawColor(128, 128, 128);

		$posx = $this->page_largeur - $w - $this->marge_droite;
		$posy = $this->marge_haute;

		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines): the number in
		// the title as on the other documents, the order with its date on one line ("Megrendelés ref. : SO… / dátum")
		$draft = ($object->status == $object::STATUS_DRAFT);
		$title = $outputlangs->transnoentities("SendingSheet").' '.$outputlangs->convToOutputCharset($object->ref);
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$navLines = array();
		$shipDate = $object->date_shipping ?: ($object->date_expedition ?? 0) ?: ($object->date_valid ?? 0) ?: $object->date_creation;
		if (!empty($shipDate)) {
			$navLines[] = $outputlangs->transnoentities("NavOnlineShipDate").": ".dol_print_date($shipDate, "day", false, $outputlangs, true);
		}
		if (getDolGlobalInt('PDF_SHOW_PROJECT_TITLE')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$navLines[] = $outputlangs->transnoentities("Project").": ".(empty($object->project->title) ? '' : dol_trunc($object->project->title, 50));
			}
		}
		if (getDolGlobalInt('PDF_SHOW_PROJECT')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$outputlangs->load("projects");
				$navLines[] = $outputlangs->transnoentities("RefProject").": ".$object->project->ref;
			}
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_client);
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerAccountancyCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_compta_client);
		}
		// The order the shipment comes from
		$linkedobject = null;
		$origin = $object->origin;
		$origin_id = $object->origin_id;
		$object->fetch_origin();
		if (isModEnabled($origin)) {     // commonly $origin='commande'
			$outputlangs->load('orders');
			$classname = ucfirst($origin);
			$linkedobject = new $classname($this->db);
			'@phan-var-force Commande|Facture $linkedobject';
			if ($linkedobject->fetch($origin_id) > 0) {
				$navLines[] = $outputlangs->transnoentities("NavOnlineOurSalesOrderRef").": ".$outputlangs->transnoentities((string) $linkedobject->ref).' / '.dol_print_date($linkedobject->date, "day", false, $outputlangs, true);
			} else {
				$linkedobject = null;
			}
		}
		// The customer's order number, from the shipment (prefilled from the order, editable), else from the order
		$refCustomer = trim((string) ($object->ref_customer ?? ''));
		if ($refCustomer === '' && is_object($linkedobject) && !empty($linkedobject->ref_client)) {
			$refCustomer = trim((string) $linkedobject->ref_client);
		}
		if ($refCustomer !== '') {
			$navLines[] = $outputlangs->transnoentities("RefCustomer").": ".$outputlangs->convToOutputCharset(dol_trunc($refCustomer, 65));
		}
		$navBottom = navonlinePdfHeadLines($pdf, $posx, $posy, $w, $navLines, $default_font_size);
		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute);
		// Next pages: the repeated column titles go in the band above tab_top_newpage (marge_haute + 32 + top_shift, see
		// _tableau), up to 10 mm high: the header lines must end 1.5 mm above it
		if (getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
			$top_shift = max($top_shift, $navBottom + 1.5 - ($this->marge_haute + 32 - 10));
		}

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';
			$arrayidcontact = $this->getIdContactOfShipment($object, 'internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$object->fetch_user(reset($arrayidcontact));
				$labelbeforecontactname = ($outputlangs->transnoentities("FromContactName") != 'FromContactName' ? $outputlangs->transnoentities("FromContactName") : $outputlangs->transnoentities("Name"));
				$carac_emetteur .= ($carac_emetteur ? "\n" : '').$labelbeforecontactname.": ".$outputlangs->convToOutputCharset($object->user->getFullName($outputlangs));
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ' (' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && !empty($object->user->office_phone)) ? $object->user->office_phone : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ', ' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT') && !empty($object->user->email)) ? $object->user->email : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ')' : '';
				$carac_emetteur .= "\n";
			}

			$carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

			// Show sender
			$posy = (getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : $this->marge_haute + 32) + $top_shift;	// NavOnline: under the header lines
			$posx = $this->marge_gauche;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;

			// Show sender frame
			if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
				navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
				$pdf->SetFont('', '', 8);
				$pdf->SetXY($posx, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Sender"), 0, $ltrdirection);
				$pdf->SetXY($posx, $posy);
				navonlinePdfThinLine($pdf);	// typography: white box, thin frame
				navonlinePdfThinLine($pdf);
				$pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
				navonlinePdfLineReset($pdf);
				navonlinePdfLineReset($pdf);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->SetFillColor(255, 255, 255);
			}

			// Show sender name
			if (!getDolGlobalString('MAIN_PDF_HIDE_SENDER_NAME')) {
				$pdf->SetXY($posx + 3, $posy + 3);
				navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
				$pdf->SetFont('', 'B', 10.5);
				$pdf->MultiCell($widthrecbox - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
				$posy = $pdf->getY();
			}

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox - 2) - 4, $carac_emetteur, $ltrdirection);	// typography: 9.5 / 8.5 pt


			// If SHIPPING contact defined, we use it
			$usecontact = false;
			$arrayidcontact = $this->getIdContactOfShipment($object, 'external', 'SHIPPING');
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

			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, (!empty($object->contact) ? $object->contact : null), ($usecontact ? 1 : 0), 'targetwithdetails', $object);

			// Show recipient
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = (getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : $this->marge_haute + 32) + $top_shift;	// NavOnline: under the header lines
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			// Show recipient frame
			if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
				navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
				$pdf->SetFont('', '', 8);
				$pdf->SetXY($posx + 2, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Recipient"), 0, $ltrdirection);
				navonlinePdfThinLine($pdf);
				$pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
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
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox) - 4, $carac_client, $ltrdirection);	// typography: 9.5 / 8.5 pt
		}

		$pdf->SetTextColor(0, 0, 0);

		return $top_shift;
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
