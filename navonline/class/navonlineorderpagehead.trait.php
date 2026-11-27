<?php
/* NavOnline: page header of the order documents (order confirmation, proforma) */

/**
 * Copy of pdf_eratosthene::_pagehead() (Dolibarr 24.0): diff it with the core after an upgrade. NavOnline changes: the
 * title and the lines under it through navonlinePdfHeadTitle() / navonlinePdfHeadLines() (the same on every
 * document), the linked documents without the repeated customer order number.
 */
trait NavOnlineOrderPagehead
{
	/**
	 * Show top header of page.
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Commande   $object         Order
	 * @param int        $showaddress    0=no, 1=yes
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @param string     $titlekey       Title translation key
	 * @return array{top_shift:float|int,shipp_shift:float|int}
	 */
	protected function navOrderPagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis, $titlekey)
	{
		// phpcs:enable
		if (is_object($outputlangsbis)) {
			$outputlangsbis->load('navonline@navonline');
		}
		// --- copy of pdf_eratosthene::_pagehead() (Dolibarr 24.0), header lines NavOnline (see above) ---
		// phpcs:enable
		global $conf, $langs, $hookmanager, $mysoc;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "bills", "propal", "orders", "companies"));

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
				if (!empty(getMultidirOutput($mysoc, 'mycompany'))) {
					$logodir = getMultidirOutput($mysoc, 'mycompany');
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
				$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($text), 0, 'L');
			}
		}

		$pdf->SetFont('', 'B', $default_font_size + 3);
		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines)
		$title = $outputlangs->transnoentities($titlekey);
		if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
			$title .= ' - '.$outputlangsbis->transnoentities($titlekey);
		}
		$title .= ' '.$outputlangs->convToOutputCharset($object->ref);
		$draft = ($object->status == $object::STATUS_DRAFT);
		if ($draft) {
			$title .= ' - '.$outputlangs->transnoentities("NotValidated");
		}
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$navLines = array();
		if ($object->ref_client) {
			$navLines[] = $outputlangs->transnoentities("RefCustomer").": ".dol_trunc($outputlangs->convToOutputCharset((string) $object->ref_client), 65);
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
		$navLines[] = $outputlangs->transnoentities("OrderDate").": ".dol_print_date($object->date, "day", false, $outputlangs, true);
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_client);
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
			$navLines[] = $outputlangs->transnoentities("CustomerAccountancyCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_compta_client);
		}
		if (getDolGlobalInt('DOC_SHOW_FIRST_SALES_REP')) {
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$usertmp = new User($this->db);
				$usertmp->fetch($arrayidcontact[0]);
				$navLines[] = $outputlangs->transnoentities("SalesRepresentative").": ".$usertmp->getFullName($langs);
			}
		}
		$navLines = array_merge($navLines, navonlineLinkedObjectLines($object, $outputlangs, (string) ($object->ref_client ?? '')));
		$navBottom = navonlinePdfHeadLines($pdf, $posx, $posy, $w, $navLines, $default_font_size);
		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute);
		$shipp_shift = 0;

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';
			// Add internal contact of object if defined
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$object->fetch_user($arrayidcontact[0]);
				$labelbeforecontactname = ($outputlangs->transnoentities("FromContactName") != 'FromContactName' ? $outputlangs->transnoentities("FromContactName") : $outputlangs->transnoentities("Name"));
				$carac_emetteur .= ($carac_emetteur ? "\n" : '').$labelbeforecontactname." ".$outputlangs->convToOutputCharset($object->user->getFullName($outputlangs));
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ' (' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && !empty($object->user->office_phone)) ? $object->user->office_phone : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') && getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ', ' : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT') && !empty($object->user->email)) ? $object->user->email : '';
				$carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') || getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ')' : '';
				$carac_emetteur .= "\n";
			}

			$carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

			// Show sender
			$posy = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : $this->marge_haute + 32;
			$posy += $top_shift;
			$posx = $this->marge_gauche;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
			$widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;


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

			//Recipient name
			if ($usecontact && $object->contact->socid != $object->thirdparty->id && getDolGlobalInt('MAIN_USE_COMPANY_NAME_OF_CONTACT')) {
				$thirdparty = $object->contact;
			} else {
				$thirdparty = $object->thirdparty;
			}

			if (is_object($thirdparty)) {
				$carac_client_name = pdfBuildThirdpartyName($thirdparty, $outputlangs);
			} else {
				$carac_client_name = '';
			}

			$mode =  'target';
			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), ($usecontact ? 1 : 0), $mode, $object);

			// Show recipient
			$widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : 32 + $this->marge_haute;
			$posy += $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
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

			// Show shipping address
			// NavOnline: with a delivery address box (only one that differs from the billing address) each box takes the
			// height of its own content, the delivery box right under the customer box, and the table starts under the
			// lower of the seller and the delivery box; without one both boxes keep the core height
			$navShip = getDolGlobalInt('SALES_ORDER_SHOW_SHIPPING_ADDRESS') && navonlineShippingContact($this->db, $object) !== null;
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

		$pagehead = array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift);

		return $pagehead;
	}
}
