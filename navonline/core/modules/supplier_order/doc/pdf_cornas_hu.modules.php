<?php
/* Copyright (C) 2026 Alu-Logic Kft.
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    navonline/core/modules/supplier_order/doc/pdf_cornas_hu.modules.php
 * \ingroup navonline
 * \brief   Purchase order PDF (core cornas) with Hungarian wording, delivery address and a header that fits
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/supplier_order/doc/pdf_cornas.modules.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Purchase order (cornas) for Alu-Logic:
 *  - table header as tall as its wrapped titles (core uses a fixed 5 mm and the first line overlaps the titles);
 *  - "Megrendelő" / "Szállító", "Szállító ajánlatszáma", "Kért szállítási határidő";
 *  - "Szállítási cím" (the warehouse) under the address boxes;
 *  - the supplier's article number only when it differs from our own article number;
 *  - one language only (the supplier's): no second language even when PDF_USE_ALSO_LANGUAGE_CODE is set.
 */
class pdf_cornas_hu extends pdf_cornas
{
	use NavOnlinePdfTypography;
	/** @var string Incoterms text of the document being generated */
	private $navInco = '';

	/** Extra height (mm) used by the delivery address line under the address boxes */
	const DELIVERY_LINE_HEIGHT = 7;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		parent::__construct($db);
		$this->name = 'cornas_hu';
		$this->description = 'Szállítói megrendelés (cornas) szállítási címmel, magyar feliratokkal';
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param CommandeFournisseur $object          Purchase order
	 * @param Translate           $outputlangs     Output language
	 * @param string              $srctemplatepath Not used
	 * @param int<0,1>            $hidedetails     Hide details
	 * @param int<0,1>            $hidedesc        Hide description
	 * @param int<0,1>            $hideref         Hide ref
	 * @return int<-1,1>
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs, $outputlangsbis;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// Every domain the core template loads, first: a later load would not overwrite the label swaps below
		$outputlangs->loadLangs(array('main', 'orders', 'companies', 'bills', 'dict', 'products', 'sendings', 'supplier_proposal', 'navonline@navonline'));

		$labels = array(
			'RefSupplier' => 'NavOnlinePoRefSupplier',
			'DateDeliveryPlanned' => 'NavOnlinePoDeliveryDate',
			'BillFrom' => 'NavOnlinePoBuyer',
			'BillTo' => 'NavOnlinePoSupplier',
			'SupplierRef' => 'NavOnlinePoSupplierArticle',
			'SupplierOrder' => 'NavOnlinePoTitle',
		);
		$saved = array();
		foreach ($labels as $key => $navKey) {
			$saved[$key] = $outputlangs->tab_translate[$key] ?? null;
			$outputlangs->tab_translate[$key] = $outputlangs->transnoentitiesnoconv($navKey);
		}

		// Supplier article number equal to our own article number: not repeated ("11210001 (Szállítói cikkszám 11210001)")
		$savedRefs = array();
		foreach ($object->lines as $i => $line) {
			$own = (string) ($line->product_ref ?? ($line->ref ?? ''));
			$sup = (string) (!empty($line->ref_supplier) ? $line->ref_supplier : ($line->ref_fourn ?? ''));
			if ($own !== '' && $sup !== '' && $own === $sup) {
				$savedRefs[$i] = array($line->ref_supplier ?? null, $line->ref_fourn ?? null);
				$line->ref_supplier = '';
				$line->ref_fourn = '';
			}
		}

		// One language: the purchase order is for the supplier, it is not a Hungarian tax document
		$prevBis = $conf->global->PDF_USE_ALSO_LANGUAGE_CODE ?? null;
		unset($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
		$prevBisObj = $outputlangsbis;
		$outputlangsbis = null;

		// Incoterms under the lines (_tableau_info), as on the other documents, not in the core box above the lines
		$this->navInco = navonlineIncotermsText($this->db, $object, $outputlangs);
		$savedInco = $object->fk_incoterms ?? null;
		$object->fk_incoterms = 0;
		// The core totals use price() without a language, i.e. the user's (Hungarian "2 973,40" on an English order):
		// the document language is the global one while the PDF is built
		$prevLangs = $langs;
		$langs = $outputlangs;

		try {
			$navNote = navonlinePdfNoteStyle($object, $outputlangs);	// NavOnline: note smaller, italic
			try {
				return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
			} finally {
				$object->note_public = $navNote;
			}
		} finally {
			$langs = $prevLangs;
			$object->fk_incoterms = $savedInco;
			if ($prevBis !== null) {
				$conf->global->PDF_USE_ALSO_LANGUAGE_CODE = $prevBis;
			}
			$outputlangsbis = $prevBisObj;
			foreach ($savedRefs as $i => $r) {
				$object->lines[$i]->ref_supplier = $r[0];
				$object->lines[$i]->ref_fourn = $r[1];
			}
			foreach ($saved as $key => $val) {
				if ($val === null) {
					unset($outputlangs->tab_translate[$key]);
				} else {
					$outputlangs->tab_translate[$key] = $val;
				}
			}
		}
	}

	/**
	 * Columns as core; the unit price column 24 mm instead of 19 mm: a HUF unit price ("236 155,23") wrapped to two lines.
	 * The description column takes the difference.
	 *
	 * @param CommonObject $object      Object
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
		if (isset($this->cols['subprice'])) {
			$this->cols['subprice']['width'] = 24.0;
		}
	}

	/**
	 * Columns as core, then the title row height from the wrapped column titles (the core leaves it at 5 mm until
	 * the table frame is drawn, after the lines: the first line would be printed over the titles).
	 *
	 * @param CommonObject $object      Object
	 * @param Translate    $outputlangs Langs
	 * @param int          $hidedetails Hide details
	 * @param int          $hidedesc    Hide description
	 * @param int          $hideref     Hide ref
	 * @return void
	 */
	public function prepareArrayColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		parent::prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

		$pdf = pdf_getInstance($this->format);
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', pdf_getPDFFontSize($outputlangs) - 1);
		$h = 5;
		foreach ($this->cols as $key => $col) {
			if (!$this->getColumnStatus($key) || empty($col['width'])) {
				continue;
			}
			$label = !empty($col['title']['label']) ? $col['title']['label'] : $outputlangs->transnoentities($col['title']['textkey'] ?? '');
			if (trim((string) $label) === '') {
				continue;
			}
			$pad = $col['title']['padding'] ?? array(0.5, 0, 0.5, 0);
			$pdf->setCellPaddings($pad[3], $pad[0], $pad[1], $pad[2]);
			$h = max($h, $pdf->getStringHeight($col['width'], $label) + 0.5);
		}
		$this->tabTitleHeight = $h;
	}

	/**
	 * Core header, then "Szállítási cím" under the address boxes; the table moves down by that line.
	 *
	 * @param TCPDF               $pdf         PDF
	 * @param CommandeFournisseur $object      Purchase order
	 * @param int                 $showaddress 1 = first page (address boxes)
	 * @param Translate           $outputlangs Langs
	 * @return float|int Top shift
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		$topShift = $this->navCorePagehead($pdf, $object, $showaddress, $outputlangs);
		if (!$showaddress) {
			return $topShift;
		}
		$address = navonlinePoDeliveryAddress($this->db, $outputlangs);
		if ($address === '') {
			return $topShift;
		}
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$posy = 32 + $topShift + $this->marge_haute + 40 + 2;	// under the 40 mm address boxes of the core header
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetXY($this->marge_gauche, $posy);
		$pdf->SetFont('', 'B', $default_font_size - 1);
		$label = $outputlangs->transnoentities('NavOnlinePoDeliveryAddress').': ';
		$pdf->Cell($pdf->GetStringWidth($label) + 1, 4, $label, 0, 0, 'L');
		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->MultiCell($this->page_largeur - $this->marge_droite - $pdf->GetX(), 4, $address, 0, 'L');
		$pdf->SetTextColor(0, 0, 0);

		return $topShift + self::DELIVERY_LINE_HEIGHT;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Table frame. With MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT the column titles are repeated on the next pages too: core
	 * cornas always hides them there (hidetop = 1) and starts the lines right at tab_top_newpage, so the title row is
	 * drawn in the free band above it (navonlinePdfHeadTopShift() keeps the page head above it). The frame ends under
	 * the last line (navTableHeight()).
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param float     $tab_top     Top of the table
	 * @param float     $tab_height  Height
	 * @param float     $nexY        Not used
	 * @param Translate $outputlangs Langs
	 * @param int       $hidetop     1 = page after the first one
	 * @param int       $hidebottom  Hide bottom
	 * @param string    $currency    Currency
	 * @return void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
	{
		// phpcs:enable
		$navRepeat = $hidetop == 1 && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD');
		$navTop = $navRepeat ? $tab_top - $this->tabTitleHeight : $tab_top;
		$navH = $this->navTableHeight($pdf, $navTop, $tab_height + $tab_top - $navTop);
		if ($navH === null) {
			return;
		}
		if ($navH < $tab_height + $tab_top - $navTop) {
			$hidebottom = 0;
		}
		parent::_tableau($pdf, $navTop, $navH, $nexY, $outputlangs, $navRepeat ? 0 : $hidetop, $hidebottom, $currency);
	}

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
		$a = $this->_tableau_info($pdf, $object, $y, $outputlangs);
		$b = $this->_tableau_tot($pdf, $object, 0, $y, $outputlangs);
		return max((float) $a, (float) $b);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Info block under the lines: Incoterms first (as on the invoice, order confirmation and delivery note), then the
	 * core payment terms and payment mode.
	 *
	 * @param TCPDF               $pdf         PDF
	 * @param CommandeFournisseur $object      Purchase order
	 * @param float               $posy        Y
	 * @param Translate           $outputlangs Langs
	 * @return float
	 */
	protected function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
		// phpcs:enable
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		// NavOnline (typography): label / value pairs as on the invoice, Incoterms first, then the payment terms and
		// mode (the core block shows every mode, a transfer too)
		$rows = array();
		if ($this->navInco !== '') {
			list($k, $v) = explode(': ', $this->navInco, 2) + array('', '');
			$rows[] = array(rtrim($k, ':'), $v);
		}
		$rows = array_merge($rows, navonlinePdfTermRows($object, $outputlangs, true, true, true));
		return navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, $rows);
	}

	/**
	 * Copy of pdf_cornas::_pagehead() (Dolibarr 24.0): diff it with the core after an upgrade. NavOnline change: the
	 * title and the lines under it through navonlinePdfHeadTitle() / navonlinePdfHeadLines(), the same on every document.
	 *
	 * @param TCPDF               $pdf         PDF
	 * @param CommandeFournisseur $object      Purchase order
	 * @param int                 $showaddress 0=no, 1=yes
	 * @param Translate           $outputlangs Langs
	 * @return float|int Top shift
	 */
	private function navCorePagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $langs, $conf, $mysoc;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		// Load translation files required by the page
		$outputlangs->loadLangs(array("main", "orders", "companies", "bills", "sendings"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Do not add the BACKGROUND as this is for suppliers
		//pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);

		//Affiche le filigrane brouillon - Print Draft Watermark
		/*if($object->statut==0 && getDolGlobalString('COMMANDE_DRAFT_WATERMARK'))
		{
			pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',getDolGlobalString('COMMANDE_DRAFT_WATERMARK'));
		}*/
		//Print content

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 110;

		$posx = $this->page_largeur - $this->marge_droite - $w;
		$posy = $this->marge_haute;

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

		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines); the supplier's
		// quotation number as a normal line (core: bold, larger); the order date also before the "Order" step
		// (validation, else creation date); the buyer label in the document's language (core: $langs)
		$title = $outputlangs->transnoentities("SupplierOrder")." ".$outputlangs->convToOutputCharset($object->ref);
		$draft = ($object->status == $object::STATUS_DRAFT);
		if ($draft) {
			$title .= ' - '.$outputlangs->transnoentities("NotValidated");
		}
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$navLines = array();
		if ($object->ref_supplier) {
			$navLines[] = $outputlangs->transnoentities("RefSupplier").": ".$outputlangs->convToOutputCharset($object->ref_supplier);
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT_TITLE')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$navLines[] = $outputlangs->transnoentities("Project").": ".(empty($object->project->title) ? '' : dol_trunc($object->project->title, 50));
			}
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$outputlangs->load("projects");
				$navLines[] = $outputlangs->transnoentities("RefProject").": ".$object->project->ref;
			}
		}
		$navOrderDate = $object->date_commande ?: ($object->date_valid ?: ($object->date_creation ?: $object->date));
		if (!empty($navOrderDate)) {
			$navLines[] = $outputlangs->transnoentities("OrderDate").": ".dol_print_date($navOrderDate, "day", false, $outputlangs, true);
		}
		$usehourmin = getDolGlobalString('SUPPLIER_ORDER_USE_HOUR_FOR_DELIVERY_DATE') ? 'dayhour' : 'day';
		if (!empty($object->delivery_date)) {
			$navLines[] = $outputlangs->transnoentities("DateDeliveryPlanned").": ".dol_print_date($object->delivery_date, $usehourmin, false, $outputlangs, true);
		}
		if ($object->thirdparty->code_fournisseur) {
			$navLines[] = $outputlangs->transnoentities("SupplierCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_fournisseur);
		}
		if (getDolGlobalString('DOC_SHOW_FIRST_SALES_REP')) {
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$usertmp = new User($this->db);
				$usertmp->fetch($arrayidcontact[0]);
				$navLines[] = $outputlangs->transnoentities("BuyerName").": ".$usertmp->getFullName($outputlangs);
			}
		}
		$navLines = array_merge($navLines, navonlineLinkedObjectLines($object, $outputlangs));
		$navBottom = navonlinePdfHeadLines($pdf, $posx, $posy, $w, $navLines, $default_font_size);
		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute);

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';
			// Add internal contact of object if defined
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$object->fetch_user($arrayidcontact[0]);
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
			$posy = 32 + $top_shift + $this->marge_haute;
			$posx = $this->marge_gauche;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}
			$hautcadre = 40;

			// Show sender frame
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($posx, $posy - 5);
			$pdf->MultiCell(80, 5, $outputlangs->transnoentities("BillFrom"), 0, $ltrdirection);
			$pdf->SetXY($posx, $posy);
			navonlinePdfThinLine($pdf);	// typography: white box, thin frame
			$pdf->RoundedRect($posx, $posy, 82, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);
			$pdf->SetTextColor(0, 0, 60);

			// Show sender name
			$pdf->SetXY($posx + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
			$posy = $pdf->getY();

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), (80) - 4, $carac_emetteur, $ltrdirection);	// typography: 9.5 / 8.5 pt



			// If CUSTOMER contact defined on order, we use it. Note: Even if this is a supplier object, the code for external contat that follow order is 'CUSTOMER'
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

			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), ($usecontact ? 1 : 0), 'target', $object);

			// Show recipient
			$widthrecbox = 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = 32 + $top_shift + $this->marge_haute;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			// Show recipient frame
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($posx + 2, $posy - 5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo"), 0, $ltrdirection);
			navonlinePdfThinLine($pdf);
			$pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);

			// Show recipient name
			$pdf->SetXY($posx + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, $ltrdirection);

			$posy = $pdf->getY();

			// Show recipient information
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($posx + 2, $posy);
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox) - 4, $carac_client, $ltrdirection);	// typography: 9.5 / 8.5 pt
		}

		return $top_shift;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Copy of pdf_cornas::_tableau_tot() (Dolibarr 24.0): diff it after an upgrade. NavOnline change: the VAT row labels
	 * go through navonlinePdfVatLabel() ("Total tax (27%)" in English).
	 *
	 * @param TCPDF               $pdf         PDF
	 * @param CommandeFournisseur $object      Purchase order
	 * @param float               $deja_regle  Already paid
	 * @param float               $posy        Y
	 * @param Translate           $outputlangs Langs
	 * @return float Y
	 */
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs)
	{
		// phpcs:enable
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		global $conf, $mysoc, $hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$tab2_top = $posy;
		$tab2_hl = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		// Tableau total
		$col1x = 120;
		$col2x = 170;
		if ($this->page_largeur < 210) { // To work with US executive format
			$col2x -= 20;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;

		// Total HT
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT"), 0, 'L', true);

		$total_ht = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top);
		$pdf->MultiCell($largcol2, $tab2_hl, price($total_ht + (!empty($object->remise) ? $object->remise : 0)), 0, 'R', true);

		// Show VAT by rates and total
		$pdf->SetFillColor(248, 248, 248);

		$this->atleastoneratenotnull = 0;
		foreach ($this->tva as $tvakey => $tvaval) {
			if ($tvakey > 0) {    // On affiche pas taux 0
				$this->atleastoneratenotnull++;

				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

				$tvacompl = '';

				if (preg_match('/\*/', $tvakey)) {
					$tvakey = str_replace('*', '', $tvakey);
					$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
				}

				$totalvat = $outputlangs->transcountrynoentities("TotalVAT", $mysoc->country_code).' ';
				$totalvat .= vatrate($tvakey, true).$tvacompl;
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', true);
			}
		}
		if (!$this->atleastoneratenotnull) { // If no vat at all
			$index++;
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transcountrynoentities("TotalVAT", $mysoc->country_code), 0, 'L', true);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_tva), 0, 'R', true);

			// Total LocalTax1
			if (getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') == 'localtax1on' && $object->total_localtax1 > 0) {
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax1), $useborder, 'R', true);
			}

			// Total LocalTax2
			if (getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') == 'localtax2on' && $object->total_localtax2 > 0) {
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax2), $useborder, 'R', true);
			}
		} else {
			//if (getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX1_OPTION') == 'localtax1on')
			//{
			//Local tax 1
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
						$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).' ';
						$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval, 0, $outputlangs), 0, 'R', true);
					}
				}
			}

			//if (getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') && getDolGlobalString('FACTURE_LOCAL_TAX2_OPTION') == 'localtax2on')
			//{
			//Local tax 2
			foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
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
						$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).' ';
						$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, navonlinePdfVatLabel($totalvat, $outputlangs), 0, 'L', true);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', true);
					}
				}
			}
		}

		// Total TTC (typography: highlighted box, label 10 pt bold, amount 12 pt bold, accent colour)
		$index++;
		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;
		$navH = navonlinePdfTotalBox($pdf, $col1x, $this->page_largeur - $this->marge_droite, $tab2_top + $tab2_hl * $index + 1, $outputlangs->transnoentities("TotalTTC"), price($total_ttc));
		$index += (int) ceil(($navH + 1.5) / $tab2_hl) - 1;
		$pdf->SetFont('', '', $default_font_size - 1);
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
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid"), 0, 'L', false);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle), 0, 'R', false);

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay"), $useborder, 'L', true);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer), $useborder, 'R', true);

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
