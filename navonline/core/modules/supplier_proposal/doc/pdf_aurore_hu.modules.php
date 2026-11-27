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
 * \file    htdocs/custom/navonline/core/modules/supplier_proposal/doc/pdf_aurore_hu.modules.php
 * \ingroup navonline
 * \brief   Supplier price request PDF (core aurore) with Hungarian wording, date, delivery address and fitting titles
 *
 * Built on the core template aurore:
 *  - column titles on one line (smaller font, unit price column 4 mm wider, wider unit column, the total title over
 *    its real column): the core titles wrapped into the first line;
 *  - title "Árajánlatkérés" (core hu: "Ár kérelem"), the date of the request in the header;
 *  - "Kért szállítási határidő" with the short date (core: "Vállalási határidő", long date, an empty line without a
 *    date);
 *  - "Szállítási cím" (our warehouse) under the address boxes, as on the purchase order (cornas_hu);
 *  - the supplier's article number only when it differs from our own article number.
 * The price and total columns stay (empty: the supplier fills them in). No Incoterms: llx_supplier_proposal has no
 * fk_incoterms. _tableau() and _pagehead() are COPIES of the core (Dolibarr 24.0): diff them after an upgrade.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/supplier_proposal/doc/pdf_aurore.modules.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Supplier price request PDF
 */
class pdf_aurore_hu extends pdf_aurore
{
	use NavOnlinePdfTypography;

	/** @var float Top of the table on a page after the first one */
	private $navNewPageTop = 0.0;

	/** @var array<string,float> Pages that have their head already (PDF object-page => top shift) */
	private $navHeadDone = array();
	/** Extra height (mm) used by the delivery address line under the address boxes */
	const DELIVERY_LINE_HEIGHT = 7;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		parent::__construct($db);
		$langs->load('navonline@navonline');
		$this->name = 'aurore_hu';
		$this->description = $langs->trans('NavOnlineSpModelDesc');
		// One-line column titles: unit price 5 mm wider (from the description), a unit column wide enough for "Egység"
		$this->posxup -= 5;
		$this->posxqty -= 1;
		if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
			$this->posxunit -= 3;
		}
		$this->posxpicture = $this->posxup - getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH', 20);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param SupplierProposal $object          Price request
	 * @param Translate        $outputlangs     Output language
	 * @param string           $srctemplatepath Not used
	 * @param int<0,1>         $hidedetails     Hide details
	 * @param int<0,1>         $hidedesc        Hide description
	 * @param int<0,1>         $hideref         Hide ref
	 * @return int<-1,1>
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// Every domain the core template loads, first: a later load would not overwrite the label swaps below
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'products', 'supplier_proposal', 'sendings', 'orders', 'navonline@navonline'));
		$labels = array(
			'CommercialAsk' => 'NavOnlineSpTitle',
			'DateDeliveryPlanned' => 'NavOnlinePoDeliveryDate',
			'SupplierRef' => 'NavOnlinePoSupplierArticle',
		);
		$saved = array();
		foreach ($labels as $key => $navKey) {
			$saved[$key] = $outputlangs->tab_translate[$key] ?? null;
			$outputlangs->tab_translate[$key] = $outputlangs->transnoentitiesnoconv($navKey);
		}

		// Supplier article number equal to our own article number: not repeated ("11210002 (Szállítói cikkszám 11210002)")
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

		navonlineFillLineUnits($object);	// a line without a unit: the unit of its product

		try {
			$navNote = navonlinePdfNoteStyle($object, $outputlangs);	// NavOnline: note smaller, italic
			try {
				return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
			} finally {
				$object->note_public = $navNote;
				navonlinePdfLinesBottom(0);
			}
		} finally {
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

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Columns of the core, with the typography padding of the lines.
	 *
	 * @param CommonObject $object      Price request
	 * @param Translate    $outputlangs Langs
	 * @param int          $hidedetails Hide details
	 * @param int          $hidedesc    Hide description
	 * @param int          $hideref     Hide reference
	 * @return void
	 */
	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		parent::defineColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);
		navonlinePdfTypoColumns($this);
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
		return (float) $this->_tableau_info($pdf, $object, $y, $outputlangs);
	}

	/**
	 * Info block: "Kért szállítási határidő" with the short date (only when set), then the payment terms. With
	 * SUPPLIER_PROPOSAL_PDF_SHOW_PAYMENTTERMMODE (payment mode, bank) the core block is used as it is.
	 *
	 * @param TCPDF            $pdf         PDF
	 * @param SupplierProposal $object      Price request
	 * @param float            $posy        Y
	 * @param Translate        $outputlangs Langs
	 * @return float
	 */
	protected function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
		// phpcs:enable
		$posy = $this->navTotalsY($pdf, $posy, $object, $outputlangs);	// NavOnline: totals on a page of their own start at its top
		if (getDolGlobalString('SUPPLIER_PROPOSAL_PDF_SHOW_PAYMENTTERMMODE')) {
			return parent::_tableau_info($pdf, $object, $posy, $outputlangs);
		}
		// NavOnline (typography): label / value pairs as on the invoice
		$rows = array();
		if (!empty($object->delivery_date)) {
			$rows[] = array($outputlangs->transnoentities('DateDeliveryPlanned'), dol_print_date($object->delivery_date, 'day', false, $outputlangs, true));
		}
		$rows = array_merge($rows, navonlinePdfTermRows($object, $outputlangs, !getDolGlobalString('SUPPLIER_PROPOSAL_PDF_HIDE_PAYMENTTERMCOND'), false));
		return navonlinePdfInfoRows($pdf, $this->marge_gauche, $posy, 100, $rows);
	}

	/**
	 * Copy of pdf_aurore::_tableau() (Dolibarr 24.0), titles on one line.
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param float     $tab_top     Top
	 * @param float     $tab_height  Height
	 * @param float     $nexY        Not used
	 * @param Translate $outputlangs Langs
	 * @param int       $hidetop     Hide top
	 * @param int       $hidebottom  Hide bottom
	 * @param string    $currency    Currency
	 * @return void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
	{
		global $conf;

		// NavOnline: frame ends under the last line (the core prints the lines without the column system: the end of the
		// last description comes from the pdf_writelinedesc hook; a description running onto the next page is not used)
		$navEnd = navonlinePdfLineDescEnd();
		if (is_array($navEnd) && $navEnd[0] === spl_object_id($pdf) && $navEnd[2] < $this->page_hauteur - 15) {
			$this->navLineEnd = $navEnd;
		} else {
			$this->navLineEnd = array(0, 0, 0.0);
		}
		// Column titles repeated on the next pages (MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT): the core hides them there and
		// starts the lines at tab_top_newpage, so the 5 mm title row goes in the free band above it
		$navRepeat = $hidetop == 1 && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD');
		if ($navRepeat) {
			$tab_top -= 5;
			$tab_height += 5;
			$hidetop = 0;
		}
		$navH = $this->navTableHeight($pdf, $tab_top, $tab_height);
		if ($navH === null) {
			// the core passes the top of the first page here when the totals page follows page 1
			$this->navTotalsTop[2] = $this->navNewPageTop > 0 ? $this->navNewPageTop : (float) $tab_top;
			return;
		}
		$tab_height = $navH;

		// Force to disable hidetop and hidebottom
		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		$currency = !empty($currency) ? $currency : $conf->currency;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 2);

		if (empty($hidetop)) {
			$titre = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);

			//$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
				$pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, 5, $this->corner_radius, '1001', 'F', array(), explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
			}
		}

		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetFont('', '', $default_font_size - 2);	// NavOnline: titles on one line (core: - 1, they wrapped into line 1)

		// Output Rect
		$this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, $hidetop, $hidebottom, 'D'); // Rect takes a length in 3rd parameter and 4th parameter

		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + 5, $this->page_largeur - $this->marge_droite, $tab_top + 5); // line takes a position y in 2nd parameter and 4th parameter

			$pdf->SetXY($this->posxdesc - 1, $tab_top + 1);
			$pdf->MultiCell(108, 2, $outputlangs->transnoentities("Designation"), '', 'L');
		}

		$pdf->line($this->posxup + 1, $tab_top, $this->posxup + 1, $tab_top + $tab_height);
		if (empty($hidetop)) {
			$pdf->SetXY($this->posxup + 1, $tab_top + 1);
			$pdf->MultiCell($this->posxqty - $this->posxup - 2, 2, $outputlangs->transnoentities("PriceUHT"), '', 'C');
		}

		$pdf->line($this->posxqty - 1, $tab_top, $this->posxqty - 1, $tab_top + $tab_height);
		if (empty($hidetop)) {
			$pdf->SetXY($this->posxqty - 1, $tab_top + 1);
			$pdf->MultiCell($this->posxunit - $this->posxqty - 1, 2, $outputlangs->transnoentities("Qty"), '', 'C');
		}

		if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
			$pdf->line($this->posxunit - 1, $tab_top, $this->posxunit - 1, $tab_top + $tab_height);
			if (empty($hidetop)) {
				$pdf->SetXY($this->posxunit - 1, $tab_top + 1);
				$pdf->MultiCell($this->posxdiscount - $this->posxunit - 1, 2, $outputlangs->transnoentities("NavOnlinePdfUnitTitle"), '', 'C');
			}
		}

		$pdf->line($this->posxdiscount - 1, $tab_top, $this->posxdiscount - 1, $tab_top + $tab_height);
		if (empty($hidetop)) {
			if ($this->atleastonediscount) {
				$pdf->SetXY($this->posxdiscount - 1, $tab_top + 1);
				$pdf->MultiCell($this->postotalht - $this->posxdiscount + 1, 2, $outputlangs->transnoentities("ReductionShort"), '', 'C');
			}
		}
		if ($this->atleastonediscount) {
			$pdf->line($this->postotalht, $tab_top, $this->postotalht, $tab_top + $tab_height);
		}
		if (empty($hidetop)) {
			// NavOnline: centred in the real column (core: 30 mm from postotalht, past the right margin); without a
			// discount the empty discount column belongs to it
			$x = $this->atleastonediscount ? $this->postotalht : $this->posxdiscount - 1;
			$pdf->SetXY($x, $tab_top + 1);
			$pdf->MultiCell($this->page_largeur - $this->marge_droite - $x, 2, $outputlangs->transnoentities("TotalHTShort"), '', 'C');
		}
	}

	/**
	 * Copy of pdf_aurore::_pagehead() (Dolibarr 24.0) with the date of the request and the delivery address.
	 *
	 * @param TCPDF            $pdf         PDF
	 * @param SupplierProposal $object      Price request
	 * @param int<0,1>         $showaddress 0=no, 1=yes
	 * @param Translate        $outputlangs Langs
	 * @return float|int Top shift
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf, $langs;
		'@phan-var-force SupplierProposal $object';

		// NavOnline: the core draws the head of the page it adds for the totals twice: once is enough
		$navKey = spl_object_id($pdf).'-'.$pdf->getPage();
		if (isset($this->navHeadDone[$navKey])) {
			return $this->navHeadDone[$navKey];
		}

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "bills", "supplier_proposal", "companies"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - 100;

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
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
				}
			} else {
				$text = $this->emetteur->name;
				$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0);
			}
		}

		$pdf->SetFont('', 'B', $default_font_size + 3);
		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines)
		$title = $outputlangs->transnoentities("CommercialAsk")." ".$outputlangs->convToOutputCharset($object->ref);
		$draft = ($object->status == $object::STATUS_DRAFT);
		if ($draft) {
			$title .= ' - '.$outputlangs->transnoentities("NotValidated");
		}
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, 100, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$navLines = array();
		// Date of the request (validation, else creation)
		$navDate = !empty($object->date_validation) ? $object->date_validation : (!empty($object->date_valid) ? $object->date_valid : ($object->date_creation ?? ($object->datec ?? 0)));
		if (!empty($navDate)) {
			$navLines[] = $outputlangs->transnoentities("Date").": ".dol_print_date($navDate, "day", false, $outputlangs, true);
		}
		if ($object->ref_fourn) {
			$navLines[] = $outputlangs->transnoentities("NavOnlineSpRefSupplier").": ".dol_trunc($outputlangs->convToOutputCharset($object->ref_fourn), 65);
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
		$navBottom = navonlinePdfHeadLines($pdf, $posx, $posy, 100, $navLines, $default_font_size);
		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute);

		if ($showaddress) {
			navonlinePdfLinesBottom(spl_object_id($pdf), $this->navFootReserve($object));	// the lines down to the footer (hook pdf_writelinedesc)
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
			$posy = 32 + $this->marge_haute + $top_shift;
			$posx = $this->marge_gauche;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}
			$hautcadre = 40;

			// Show sender frame
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($posx, $posy - 5);
			$pdf->MultiCell(80, 5, $outputlangs->transnoentities("BillFrom"), 0, 'L');
			$pdf->SetXY($posx, $posy);
			navonlinePdfThinLine($pdf);	// typography: white box, thin frame
			$pdf->RoundedRect($posx, $posy, 82, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);
			$pdf->SetTextColor(0, 0, 60);

			// Show sender name
			$pdf->SetXY($posx + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$posy = $pdf->getY();

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), (80) - 4, $carac_emetteur, 'L');	// typography: 9.5 / 8.5 pt


			// If CUSTOMER contact defined on proposal, we use it. Note: Even if this is a supplier object, the code for external contact that follow order is 'CUSTOMER'
			$usecontact = false;
			if (!getDolGlobalInt('SUPPLIER_PROPOSAL_ADD_BILLING_CONTACT')) {
				$arrayidcontact = $object->getIdContact('external', 'CUSTOMER');
			} else {
				$arrayidcontact = array_merge($object->getIdContact('external', 'CUSTOMER'), $object->getIdContact('external', 'BILLING'));
			}
			if (is_array($arrayidcontact) && count($arrayidcontact) > 0) {
				$usecontact = true;
				$result = $object->fetch_contact($arrayidcontact[0]);
			}

			// Recipient name
			if (!empty($usecontact)) {
				if ($usecontact && ($object->contact->socid != $object->thirdparty->id && (!isset($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT) || getDolGlobalString('MAIN_USE_COMPANY_NAME_OF_CONTACT')))) {
					$socname = $object->contact;
				} else {
					$socname = $object->thirdparty;
				}
			} else {
				$socname = $object->thirdparty;
			}

			$carac_client_name = pdfBuildThirdpartyName($socname, $outputlangs);

			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), ($usecontact ? 1 : 0), 'target', $object);

			// Show recipient
			$widthrecbox = 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = 32 + $this->marge_haute + $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			// Show recipient frame
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($posx + 2, $posy - 5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo"), 0, 'L');
			navonlinePdfThinLine($pdf);
			$pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);

			// Show recipient name
			$pdf->SetXY($posx + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, 'L');

			// Show recipient information
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($posx + 2, $posy + 4 + (dol_nboflines_bis($carac_client_name, 50) * 4));
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($widthrecbox) - 4, $carac_client, 'L');	// typography: 9.5 / 8.5 pt
		}

		// NavOnline: "Szállítási cím" (our warehouse) under the address boxes, as on the purchase order; the table moves
		// down by that line
		if ($showaddress) {
			$address = navonlinePoDeliveryAddress($this->db, $outputlangs);
			if ($address !== '') {
				$pdf->SetTextColor(0, 0, 60);
				$pdf->SetXY($this->marge_gauche, 32 + $top_shift + $this->marge_haute + 40 + 2);
				$pdf->SetFont('', 'B', $default_font_size - 1);
				$label = $outputlangs->transnoentities('NavOnlinePoDeliveryAddress').': ';
				$pdf->Cell($pdf->GetStringWidth($label) + 1, 4, $label, 0, 0, 'L');
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell($this->page_largeur - $this->marge_droite - $pdf->GetX(), 4, $address, 0, 'L');
				$top_shift += self::DELIVERY_LINE_HEIGHT;
			}
		}

		$pdf->SetTextColor(0, 0, 0);
		$this->navHeadDone[$navKey] = $top_shift;
		if (!$showaddress) {
			// top of the table on a next page (core: tab_top_newpage)
			$this->navNewPageTop = !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 32 + $this->marge_haute + $top_shift : $this->marge_haute;
		}
		return $top_shift;
	}
}
