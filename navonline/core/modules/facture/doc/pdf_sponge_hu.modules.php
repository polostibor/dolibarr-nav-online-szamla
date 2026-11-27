<?php
/* Copyright (C) 2004-2024	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2008		Raphael Bertrand		<raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2014	Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2012		Christophe Battarel		<christophe.battarel@altairis.fr>
 * Copyright (C) 2012		Cédric Salvador			<csalvador@gpcsolutions.fr>
 * Copyright (C) 2012-2014	Raphaël Doursenaud		<rdoursenaud@gpcsolutions.fr>
 * Copyright (C) 2015		Marcos García			<marcosgdf@gmail.com>
 * Copyright (C) 2017		Ferran Marcet			<fmarcet@2byte.es>
 * Copyright (C) 2018-2026  Frédéric France			<frederic.france@free.fr>
 * Copyright (C) 2018-2024	Anthony Berton			<anthony.berton@bb2a.fr>
 * Copyright (C) 2022-2025	Alexandre Spangaro		<alexandre@inovea-conseil.com>
 * Copyright (C) 2024-2025	MDW						<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024-2025  Nick Fragoulis
 * Copyright (C) 2024       Franck Moreau
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/* Copy of the Dolibarr sponge invoice template (htdocs/core/modules/facture/doc/pdf_sponge.modules.php, Dolibarr 24.0)
 * adapted for Hungarian invoices by the NavOnline module: Eladó/Vevő box titles, bilingual teljesítés dátuma line.
 * Upgrade-safe: lives in htdocs/custom. Re-sync with the core file after a Dolibarr upgrade if needed.
 */
/**
 *  \file       htdocs/core/modules/facture/doc/pdf_sponge.modules.php
 *  \ingroup    invoice
 *  \brief      File of class to generate customers invoices from sponge model
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';	// NavOnline helpers (cash rounding, credit note / final invoice titles)

/**
 *	Class to manage PDF invoice template sponge
 */
class pdf_sponge_hu extends ModelePDFFactures
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var int     Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'''|'development'|'dolibarr'|'experimental'
	 */
	public $version = 'dolibarr';

	/**
	 * @var int height for info total
	 */
	public $heightforinfotot;

	/**
	 * @var int heightforfreetext
	 */
	public $heightforfreetext;

	/**
	 * @var float heightforfooter
	 */
	public $heightforfooter;

	/**
	 * @var float tab_top
	 */
	public $tab_top;

	/**
	 * @var int|float tab_top_newpage
	 */
	public $tab_top_newpage;

	/** @var float Y where the right-hand header lines end (page head), for the table top of the next pages */
	private $navHeadBottom = 0;

	/** @var float Y under the partner boxes of the first page (0 = not drawn) */
	private $navBoxBottom = 0;

	/** @var string Legal clauses (VAT exemption, origin declaration, retention of title), printed after the payment details */
	private $navClausesHtml = '';

	/** @var string[] The clauses one by one (HTML) */
	private $navClausesParts = array();

	/** @var float Height of the clause block */
	private $navClausesH = 0;

	/**
	 * @var bool Situation invoice type
	 */
	public $situationinvoice;


	/**
	 * @var array<string,array{rank:int,width:float|false,status:bool|int<0,1>,border-left?:bool,title:array{textkey:string,label?:string,align?:string,padding?:array{0:float,1:float,2:float,3:float}},content?:array{align?:string,padding?:array{0:float,1:float,2:float,3:float}}}>	Array of document table columns
	 */
	public $cols;

	/**
	 * @var int Category of operation
	 */
	public $categoryOfOperation = -1; // unknown by default


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		// Translations
		$langs->loadLangs(array("main", "bills"));

		$this->db = $db;
		$this->name = "sponge_hu";
		$langs->load('navonline@navonline');
		$this->description = $langs->trans('NavOnlinePdfSpongeHuDescription');
		$this->update_main_doc_field = 1; // Save the name of generated file as the main doc when generating a doc with this template

		// Dimension page
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->corner_radius = getDolGlobalInt('MAIN_PDF_FRAME_CORNER_RADIUS', 0);
		$this->option_logo = 1; // Display logo
		$this->option_tva = 1; // Manage the vat option FACTURE_TVAOPTION
		$this->option_modereg = 1; // Display payment mode
		$this->option_condreg = 1; // Display payment terms
		$this->option_multilang = 1; // Available in several languages
		$this->option_escompte = 1; // Displays if there has been a discount
		$this->option_credit_note = 1; // Support credit notes
		$this->option_freetext = 1; // Support add of a personalised text
		$this->option_draft_watermark = 1; // Support add of a watermark on drafts
		$this->watermark = '';

		$this->showAmountBeforeDiscount = (getDolGlobalInt('MAIN_HIDE_AMOUNT_BEFORE_DISCOUNT') || getDolGlobalInt('MAIN_HIDE_AMOUNT_BEFORE_DISCOUNT_INVOICE')) ? 0 : 1;
		$this->showDiscountAmount =  (getDolGlobalInt('MAIN_HIDE_AMOUNT_DISCOUNT') || getDolGlobalInt('MAIN_HIDE_AMOUNT_BEFORE_DISCOUNT_INVOICE')) ? 0 : 1;

		if ($mysoc === null) {
			dol_syslog(get_class($this).'::__construct() Global $mysoc should not be null.'. getCallerInfoString(), LOG_ERR);
			return;
		}

		// Get source company
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2); // By default if not defined
		}

		// Define position of columns
		$this->posxdesc = $this->marge_gauche + 1; // used for notes and other stuff


		$this->tabTitleHeight = 5; // default height

		//  Use new system for position of columns, view  $this->defineColumnField()

		$this->tva = array();
		$this->tva_array = array();
		$this->localtax1 = array();
		$this->localtax2 = array();
		$this->atleastoneratenotnull = 0;
		$this->atleastonediscount = 0;
		$this->situationinvoice = false;
	}


	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Function to build pdf onto disk
	 *
	 *  @param		Facture			$object				Object to generate
	 *  @param		Translate		$outputlangs		Lang output object
	 *  @param		string			$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int<0,1>		$hidedetails		Do not show line details
	 *  @param		int<0,1>		$hidedesc			Do not show desc
	 *  @param		int<0,1>		$hideref			Do not show ref
	 *  @return		int<-1,1>							1=OK, <=0=KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $user, $langs, $conf, $mysoc, $hookmanager, $nblines;

		dol_syslog("write_file outputlangs->defaultlang=".(is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (getDolGlobalString('MAIN_USE_FPDF')) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}

		// Load translation files required by the page
		$outputlangs->loadLangs(array("main", "bills", "products", "dict", "companies", "compta", "navonline@navonline"));

		global $outputlangsbis;
		$outputlangsbis = null;
		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
			$outputlangsbis->loadLangs(array("main", "bills", "products", "dict", "companies", "compta", "navonline@navonline"));
		}

		// Show Draft Watermark
		if ($object->status == $object::STATUS_DRAFT && (getDolGlobalString('FACTURE_DRAFT_WATERMARK'))) {
			$this->watermark = getDolGlobalString('FACTURE_DRAFT_WATERMARK');
		}

		$nblines = count($object->lines);

		$hidetop = 0;
		if (getDolGlobalString('MAIN_PDF_DISABLE_COL_HEAD_TITLE')) {
			$hidetop = getDolGlobalString('MAIN_PDF_DISABLE_COL_HEAD_TITLE');
		}

		// Loop on each lines to detect if there is at least one image to show
		$realpatharray = array();
		$this->atleastonephoto = false;
		if (getDolGlobalString('MAIN_GENERATE_INVOICES_WITH_PICTURE')) {
			$objphoto = new Product($this->db);

			for ($i = 0; $i < $nblines; $i++) {
				if (empty($object->lines[$i]->fk_product)) {
					continue;
				}

				$objphoto->fetch($object->lines[$i]->fk_product);
				//var_dump($objphoto->ref);exit;
				$pdir = array();
				if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
					$pdir[0] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product').$objphoto->id."/photos/";
					$pdir[1] = get_exdir(0, 0, 0, 0, $objphoto, 'product').dol_sanitizeFileName($objphoto->ref).'/';
				} else {
					$pdir[0] = get_exdir(0, 0, 0, 0, $objphoto, 'product'); // default
					$pdir[1] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product').$objphoto->id."/photos/"; // alternative
				}

				$arephoto = false;
				$realpath = false;
				foreach ($pdir as $midir) {
					if (!$arephoto) {
						$entity = $objphoto->entity;
						if ($entity !== null && $conf->entity != $entity) {
							$dir = $conf->product->multidir_output[$entity].'/'.$midir; //Check repertories of current entities
						} else {
							$dir = $conf->product->dir_output.'/'.$midir; //Check repertory of the current product
						}

						foreach ($objphoto->liste_photos($dir, 1) as $key => $obj) {
							if (!getDolGlobalInt('CAT_HIGH_QUALITY_IMAGES')) {		// If CAT_HIGH_QUALITY_IMAGES not defined, we use thumb if defined and then original photo
								if ($obj['photo_vignette']) {
									$filename = $obj['photo_vignette'];
								} else {
									$filename = $obj['photo'];
								}
							} else {
								$filename = $obj['photo'];
							}

							$realpath = $dir.$filename;
							$arephoto = true;
							$this->atleastonephoto = true;
						}
					}
				}

				if (!empty($realpath) && $arephoto) {
					$realpatharray[$i] = $realpath;
				}
			}
		}

		//if (count($realpatharray) == 0) $this->posxpicture=$this->posxtva;

		if ($conf->facture->multidir_output[$conf->entity]) {
			$object->fetch_thirdparty();

			$deja_regle = $object->getSommePaiement((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);
			$amount_credit_notes_included = $object->getSumCreditNotesUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);
			$amount_deposits_included = $object->getSumDepositsUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);

			// Definition of $dir and $file
			if ($object->specimen) {
				$dir = $conf->facture->multidir_output[$conf->entity];
				$file = $dir."/SPECIMEN.pdf";
			} else {
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = $conf->facture->multidir_output[$object->entity ?? $conf->entity]."/".$objectref;
				$file = $dir."/".$objectref.".pdf";
			}
			if (!file_exists($dir)) {
				if (dol_mkdir($dir) < 0) {
					$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
					return 0;
				}
			}

			if (file_exists($dir)) {
				// Add pdfgeneration hook
				if (!is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager = new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks

				// Set nblines with the new facture lines content after hook
				$nblines = count($object->lines);
				$nbpayments = count($object->getListOfPayments());
				$navDescSaved = $this->navDepositLineTexts($object, $outputlangs);

				// Create pdf instance
				$pdf = pdf_getInstance($this->format);		// Will also define if we use format 1.7, 1.4/A-1b or 1.7/A-3b (embedded fonts)
				$default_font_size = pdf_getPDFFontSize($outputlangs); // Must be after pdf_getInstance
				$pdf->setAutoPageBreak(true, 0);

				// Compute height for total, free text and footer
				$this->heightforinfotot = 50; // Height reserved to output the info and total part and payment part
				if (!getDolGlobalString('INVOICE_NO_PAYMENT_DETAILS') && $nbpayments > 0) {
					$this->heightforinfotot += (4 * $nbpayments);
				}
				// NavOnline: room for the VAT summary table (Áfa tv. 169. § i)-k)) printed on top of the info area
				$this->heightforinfotot += $this->navVatSummaryHeight($object);
				if (!empty($object->fk_incoterms) && isModEnabled('incoterm')) {
					$this->heightforinfotot += 6;	// Incoterms line under the VAT summary
				}
				$this->navWeight = ((int) $object->type === Facture::TYPE_CREDIT_NOTE) ? array() : $this->navInvoiceWeights($object, $outputlangs, $outputlangsbis);	// NavOnline: weights belong to the export shipment, not to a credit note
				$this->heightforinfotot += 4 * count($this->navWeight);
				if ($this->navPaidTerm($object)) {
					$this->heightforinfotot += 10;	// "Paid / Fizetve" under the totals
				}
				// Height reserved to output the free text on last page (NavOnline: none without a free text)
				$this->heightforfreetext = getDolGlobalString('INVOICE_FREE_TEXT') !== '' ? getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) : 0;
				$this->heightforfooter = $this->marge_basse + (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS') ? 12 : 22); // Height reserved to output the footer (value include bottom margin)

				$heightforqrinvoice = 0;
				if (getDolGlobalString('INVOICE_ADD_SWISS_QR_CODE') == 'bottom') {
					if ($this->getHeightForQRInvoice(1, $object, $langs) > 0) {
						// Shrink infotot to a base 30
						$this->heightforinfotot = 30 + (4 * $nbpayments); // Height reserved to output the info and total part and payment part
					}
				}

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));

				// Set path to the background PDF File
				if (getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
					$logodir = $conf->mycompany->dir_output;
					if (!empty($conf->mycompany->multidir_output[$object->entity ?? $conf->entity])) {
						$logodir = $conf->mycompany->multidir_output[$object->entity ?? $conf->entity];
					}
					$pagecount = $pdf->setSourceFile($logodir.'/' . getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
					$tplidx = $pdf->importPage(1);
				}

				$pdf->Open();
				$pagenb = 0;
				$pdf->SetDrawColor(128, 128, 128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("PdfInvoiceTitle"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$outputlangs->convToOutputCharset($user->getAnonymisableFullName($outputlangs)) : ''));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("PdfInvoiceTitle")." ".$outputlangs->convToOutputCharset($object->thirdparty->name));
				if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
					$pdf->SetCompression(false);
				}

				// Set certificate
				$cert = getDolUserString('CERTIFICATE_CRT', getDolGlobalString('CERTIFICATE_CRT'));
				$certprivate = getDolUserString('CERTIFICATE_CRT_PRIVATE', getDolGlobalString('CERTIFICATE_CRT_PRIVATE'));

				// If a certificate is found
				if ($cert) {
					$info = array(
						'Name' => $this->emetteur->name,
						'Location' => getCountry($this->emetteur->country_code, ''),
						'Reason' => 'INVOICE',
						'ContactInfo' => $this->emetteur->email
					);
					$pdf->setSignature($cert, $certprivate, $this->emetteur->name, '', 2, $info);
				}

				// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite); // Left, Top, Right

				// Set $this->atleastonediscount if you have at least one discount
				// and determine category of operation
				$categoryOfOperation = 0;
				$nbProduct = 0;
				$nbService = 0;
				for ($i = 0; $i < $nblines; $i++) {
					$line = $object->lines[$i];

					if ($line->remise_percent) {
						$this->atleastonediscount++;
					}

					// If DEPOSIT, this line is completely ignored for calculations.
					if ($line->isDepositLine()) {
						continue;
					}

					// determine category of operation
					if ($categoryOfOperation < 2) {
						$lineProductType = $line->product_type;
						if ($lineProductType == Product::TYPE_PRODUCT) {
							$nbProduct++;
						} elseif ($lineProductType == Product::TYPE_SERVICE) {
							$nbService++;
						}
						if ($nbProduct > 0 && $nbService > 0) {
							// mixed products and services
							$categoryOfOperation = 2;
						}
					}
				}
				// determine category of operation
				if ($categoryOfOperation <= 0) {
					// only services
					if ($nbProduct == 0 && $nbService > 0) {
						$categoryOfOperation = 1;
					}
				}
				$this->categoryOfOperation = $categoryOfOperation;

				// Situation invoice handling
				if ($object->situation_cycle_ref) {
					$this->situationinvoice = true;
				}

				// New page
				$pdf->AddPage();
				if (!empty($tplidx)) {
					$pdf->useTemplate($tplidx);
				}
				$pagenb++;

				// Output header (logo, ref and address blocks). This is first call for first page.
				$this->navHeadBottom = 0;
				$this->navBoxBottom = 0;
				$pagehead = $this->_pagehead($pdf, $object, 1, $outputlangs, $outputlangsbis);
				$top_shift = $pagehead['top_shift'];
				$shipp_shift = $pagehead['shipp_shift'];
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, ''); // Set interline to 3
				$pdf->SetTextColor(11, 18, 21);

				// $pdf->GetY() here can't be used. It is bottom of the second address box but first one may be higher

				// $this->tab_top is y where we must continue content (80 = 32 + 48: 32 is height of logo and ref, 48 is address blocks)
				// NavOnline: right under the partner boxes (their height follows the content)
				$this->tab_top = ($this->navBoxBottom > 0 ? $this->navBoxBottom + 7 : $this->marge_haute + 80 + $top_shift) + $shipp_shift;		// top_shift is an addition for linked objects or addons (0 in most cases)
				$this->tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? $this->marge_haute + 32 + $top_shift : $this->marge_haute);

				// You can add more thing under header here, if you increase $extra_under_address_shift too.
				$extra_under_address_shift = 0;
				$qrcodestring = '';
				if (getDolGlobalString('INVOICE_ADD_ZATCA_QR_CODE')) {
					$qrcodestring = $object->buildZATCAQRString();
				} elseif (getDolGlobalString('INVOICE_ADD_SWISS_QR_CODE') == '1' && (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR')) {
					if ($object->fk_account > 0 || $object->fk_bank > 0 || getDolGlobalInt('FACTURE_RIB_NUMBER')) {
						$qrcodestring = $object->buildSwitzerlandQRString();
					}
				} elseif (getDolGlobalString('INVOICE_ADD_EPC_QR_CODE') == '1' && (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR') && $object->getRemainToPay() > 0) {
					// No EPC QR code once the invoice is fully paid: the amount to pay would be 0 (same rule as for INVOICE_ADD_EPC_QR_CODE == 'bottom')
					if ($object->fk_account > 0 || $object->fk_bank > 0 || getDolGlobalInt('FACTURE_RIB_NUMBER')) {
						$qrcodestring = $object->buildEPCQrCodeString();
					}
				}

				if ($qrcodestring) {
					$qrcodecolor = array('25', '25', '25');
					// set style for QR-code
					$styleQr = array(
						'border' => false,
						'padding' => 0,
						'fgcolor' => $qrcodecolor,
						'bgcolor' => false, //array(255,255,255)
						'module_width' => 1, // width of a single module in points
						'module_height' => 1 // height of a single module in points
					);
					$pdf->write2DBarcode($qrcodestring, 'QRCODE,M', $this->marge_gauche, $this->tab_top - 5, 25, 25, $styleQr, 'N');

					if (getDolGlobalString('INVOICE_ADD_EPC_QR_CODE') == '1' && (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR')) {
						if ($object->fk_account > 0 || $object->fk_bank > 0 || getDolGlobalInt('FACTURE_RIB_NUMBER')) {
							$pdf->SetXY($this->marge_gauche + 30, $pdf->GetY() - 15);
							$pdf->SetFont('', '', $default_font_size - 4);
							$pdf->MultiCell(40, 3, $langs->transnoentitiesnoconv("INVOICE_ADD_EPC_QR_CODEPay"), 0, 'L', false);
						}
					}

					$extra_under_address_shift += 25;
				}

				// Call hook printUnderHeaderPDFline
				$parameters = array(
					'object' => $object,
					// 'i' => $i, // we aren't in lines
					'pdf' => &$pdf,
					'outputlangs' => $outputlangs,
					'hidedetails' => $hidedetails
				);
				$reshook = $hookmanager->executeHooks('printUnderHeaderPDFline', $parameters, $this); // Note that $object may have been modified by hook
				if (!empty($hookmanager->resArray['extra_under_address_shift'])) {
					$extra_under_address_shift += $hookmanager->resArray['extra_under_address_shift'];
				}

				$this->tab_top += $extra_under_address_shift;
				$this->tab_top_newpage += 0;


				// Define height of table for lines (for first page)
				$tab_height = $this->page_hauteur - $this->tab_top - $this->heightforfooter - $this->heightforfreetext - $this->getHeightForQRInvoice(1, $object, $langs);

				$nexY = $this->tab_top - 1;

				// Incoterm: NavOnline prints it under the lines on the left (drawInfoTable), not here above the lines
				$height_incoterms = 0;
				if (false && isModEnabled('incoterm')) {
					$desc_incoterms = $object->getIncotermsForPDF();
					if ($desc_incoterms) {
						$this->tab_top -= 2;

						$pdf->SetFont('', '', $default_font_size - 1);
						$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $this->tab_top - 1, dol_htmlentitiesbr($desc_incoterms), 0, 1);
						$nexY = max($pdf->GetY(), $nexY);
						$height_incoterms = $nexY - $this->tab_top;

						// Rect takes a length in 3rd parameter
						$pdf->SetDrawColor(192, 192, 192);
						$pdf->RoundedRect($this->marge_gauche, $this->tab_top - 1, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $height_incoterms + 3, $this->corner_radius, '1234', 'D');

						$this->tab_top = $nexY + 6;
						$height_incoterms += 4;
					}
				}

				// Displays notes. Here we are still on code executed only for the first page.
				$notetoshow = empty($object->note_public) ? '' : $object->note_public;
				if (getDolGlobalString('MAIN_ADD_SALE_REP_SIGNATURE_IN_NOTE')) {
					// Get first sale rep
					if (is_object($object->thirdparty)) {
						$salereparray = $object->thirdparty->getSalesRepresentatives($user);
						$salerepobj = new User($this->db);
						$salerepobj->fetch($salereparray[0]['id']);
						if (!empty($salerepobj->signature)) {
							$notetoshow = dol_concatdesc($notetoshow, $salerepobj->signature);
						}
					}
				}

				// Extrafields in note
				$extranote = $this->getExtrafieldsInHtml($object, $outputlangs);
				if (!empty($extranote)) {
					$notetoshow = dol_concatdesc((string) $notetoshow, $extranote);
				}

				// NavOnline (typography 2026-11-26): the legal clauses (VAT legend, origin declaration, retention of title) are
				// printed after the payment details, 8.5 pt regular, subtitles bold; the public note stays above the lines
				$navEsc = function ($t) {
					return dol_nl2br(dol_escape_htmltag((string) $t, 0, 1));
				};
				$navParts = array();
				$vatlegend = $this->navVatLegend($object, $outputlangs);
				if ($vatlegend !== '') {
					// A clause already in the public note (e.g. typed into the order and copied) is not repeated
					$plainnote = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $notetoshow), ENT_QUOTES, 'UTF-8'));
					$keep = array();
					foreach (explode("\n", $vatlegend) as $vl) {
						if (trim($vl) !== '' && mb_stripos($plainnote, preg_replace('/\s+/u', ' ', trim($vl))) === false) {
							$keep[] = $vl;
						}
					}
					if ($keep) {
						$navParts[] = $navEsc(implode("\n", $keep));
					}
				}
				// Preferential origin declaration (export invoice declaration instead of EUR.1, up to 6000 EUR)
				$navOrigin = $this->navOriginDeclaration($object, $outputlangs, (string) $notetoshow);
				if ($navOrigin !== '') {
					$navParts[] = $navEsc($navOrigin);
				}
				// Retention of title on a deferred-payment invoice, its title bold, an empty line before it
				$navRetention = $this->navRetentionClause($object, $outputlangs, (string) $notetoshow);
				if ($navRetention !== '') {
					$rt = explode("\n", $navRetention, 2);
					$navParts[] = '<b>'.dol_escape_htmltag($rt[0]).'</b>'.(isset($rt[1]) ? '<br>'.$navEsc($rt[1]) : '');
				}
				// The same space (about 2 mm, a short spacer line) before every separate clause
				$this->navClausesParts = $navParts;
				$this->navClausesHtml = implode('<br>', $navParts);
				$this->navClausesH = 0;
				if ($this->navClausesHtml !== '') {
					$pdf->startTransaction();
					$pdf->SetFont('', '', 8.5);
					$this->navClausesH = $this->navDrawClauses($pdf, $this->marge_gauche + 2.5, 40, $this->page_largeur - $this->marge_gauche - $this->marge_droite - 5) - 40 + 4.5;	// frame: 2.5 mm above, 2 mm under the text
					$pdf->rollbackTransaction(true);
				}

				$pagenb = $pdf->getPage();
				if ($notetoshow) {
					$this->tab_top -= 2;

					$tab_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
					$pageposbeforenote = $pagenb;

					$substitutionarray = pdf_getSubstitutionArray($outputlangs, null, $object);
					complete_substitutions_array($substitutionarray, $outputlangs, $object);
					$notetoshow = make_substitutions($notetoshow, $substitutionarray, $outputlangs);
					$notetoshow = convertBackOfficeMediasLinksToPublicLinks($notetoshow);

					$pdf->startTransaction();

					$pdf->SetFont('', '', 8.5);	// NavOnline (typography): public note 8.5 pt regular
					$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $this->tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
					// Description
					$pageposafternote = $pdf->getPage();
					$posyafter = $pdf->GetY();

					if ($pageposafternote > $pageposbeforenote) {
						$pdf->rollbackTransaction(true);

						// prepare pages to receive notes
						while ($pagenb < $pageposafternote) {
							$pdf->AddPage();
							$pagenb++;
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}
							if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
								$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
							}
							$pdf->setTopMargin($this->tab_top_newpage);
							// The only function to edit the bottom margin of current page to set it.
							$pdf->setPageOrientation('', true, $this->heightforfooter + $this->heightforfreetext);
						}

						// back to start
						$pdf->setPage($pageposbeforenote);
						$pdf->setPageOrientation('', true, $this->heightforfooter + $this->heightforfreetext);
						$pdf->SetFont('', '', 8.5);	// NavOnline (typography): public note 8.5 pt regular
						$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $this->tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
						$pageposafternote = $pdf->getPage();

						$posyafter = $pdf->GetY();

						if ($posyafter > ($this->page_hauteur - ($this->heightforfooter + $this->heightforfreetext + 20))) {	// There is no space left for total+free text
							$pdf->AddPage('', '', true);
							$pagenb++;
							$pageposafternote++;
							$pdf->setPage($pageposafternote);
							$pdf->setTopMargin($this->tab_top_newpage);
							// The only function to edit the bottom margin of current page to set it.
							$pdf->setPageOrientation('', true, $this->heightforfooter + $this->heightforfreetext);
							//$posyafter = $this->tab_top_newpage;
						}


						// apply note frame to previous pages
						$i = $pageposbeforenote;
						while ($i < $pageposafternote) {
							$pdf->setPage($i);


							$pdf->SetDrawColor(128, 128, 128);
							// Draw note frame
							if ($i > $pageposbeforenote) {
								$height_note = $this->page_hauteur - ($this->tab_top_newpage + $this->heightforfooter);
								$pdf->RoundedRect($this->marge_gauche, $this->tab_top_newpage - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
							} else {
								$height_note = $this->page_hauteur - ($this->tab_top + $this->heightforfooter);
								$pdf->RoundedRect($this->marge_gauche, $this->tab_top - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
							}

							// Add footer
							$pdf->setPageOrientation('', true, 0); // The only function to edit the bottom margin of current page to set it.
							$this->_pagefoot($pdf, $object, $outputlangs, 1, $this->getHeightForQRInvoice($i, $object, $outputlangs));

							$i++;
						}

						// apply note frame to last page
						$pdf->setPage($pageposafternote);
						if (!empty($tplidx)) {
							$pdf->useTemplate($tplidx);
						}
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
						}
						$height_note = $posyafter - $this->tab_top_newpage;
						$pdf->RoundedRect($this->marge_gauche, $this->tab_top_newpage - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
					} else {
						// No pagebreak
						$pdf->commitTransaction();
						$posyafter = $pdf->GetY();
						$height_note = $posyafter - $this->tab_top;
						$pdf->RoundedRect($this->marge_gauche, $this->tab_top - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');


						if ($posyafter > ($this->page_hauteur - ($this->heightforfooter + $this->heightforfreetext + 20))) {
							// not enough space, need to add page
							$pdf->AddPage('', '', true);
							$pagenb++;
							$pageposafternote++;
							$pdf->setPage($pageposafternote);
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}
							if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
								$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
							}

							$posyafter = $this->tab_top_newpage;
						}
					}

					$tab_height -= $height_note;
					$this->tab_top = $posyafter + 6;
				} else {
					$height_note = 0;
				}

				// Use new auto column system
				$this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

				// Table simulation to know the height of the title line (this set this->tableTitleHeight)
				$pdf->startTransaction();
				$this->pdfTabTitles($pdf, $this->tab_top, $tab_height, $outputlangs, $hidetop);
				$pdf->rollbackTransaction(true);

				if (empty($hidetop) && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
					// NavOnline: as in core eratosthene / cyan (missing from core sponge): the repeated column titles are drawn
					// above tab_top_newpage, so the lines start lower (+2 mm gap). Here, after the simulation above, as
					// tabTitleHeight is only then the real one (bilingual titles: 3 lines, about 13 mm; before: 5 mm).
					$this->tab_top_newpage += $this->tabTitleHeight + 2;
					// ... and at least under the header lines: the "Pénznem" line is drawn 4 mm above the titles
					$this->tab_top_newpage = max($this->tab_top_newpage, $this->navHeadBottom + 5 + $this->tabTitleHeight);
				}

				$nexY = $this->tab_top + $this->tabTitleHeight + 1.6;	// NavOnline: space above the first line

				// Loop on each lines
				$pageposbeforeprintlines = $pdf->getPage();
				$pagenb = $pageposbeforeprintlines;

				$pdf_sub_options = array();
				$pdf_sub_options['titleshowuponpdf'] = 1;
				$pdf_sub_options['titleshowtotalexludingvatonpdf'] = 1;
				$pdf_sub_options_before_title = array();	// Options in use before each title line, by level

				$hidenextline = 0;

				for ($i = 0; $i < $nblines; $i++) {
					$linePosition = $i + 1;
					$curY = $nexY;

					$sub_options = $object->lines[$i]->extraparams["subtotal"] ?? array();

					if ($object->lines[$i]->special_code == SUBTOTALS_SPECIAL_CODE) {
						$level = $object->lines[$i]->qty;
						// Keep the options in use before a title line, to restore them after the subtotal line that closes its block
						if ($level > 0 && !isset($pdf_sub_options_before_title[(int) $level])) {
							$pdf_sub_options_before_title[(int) $level] = $pdf_sub_options;
						}
						if ($sub_options) {
							$hidenextline = 0;
							if (isset($sub_options['titleshowuponpdf'])) {
								$pdf_sub_options['titleshowuponpdf'] = isset($pdf_sub_options['titleshowuponpdf']) && $pdf_sub_options['titleshowuponpdf'] < $level ? $pdf_sub_options['titleshowuponpdf'] : $level;
							} elseif (isset($pdf_sub_options['titleshowuponpdf']) && abs($level) <= $pdf_sub_options['titleshowuponpdf']) {
								unset($pdf_sub_options['titleshowuponpdf']);
							}
							if (isset($sub_options['titleshowtotalexludingvatonpdf'])) {
								$pdf_sub_options['titleshowtotalexludingvatonpdf'] = isset($pdf_sub_options['titleshowtotalexludingvatonpdf']) && $pdf_sub_options['titleshowtotalexludingvatonpdf'] < $level ? $pdf_sub_options['titleshowtotalexludingvatonpdf'] : $level;
							} elseif (isset($pdf_sub_options['titleshowtotalexludingvatonpdf']) && abs($level) <= $pdf_sub_options['titleshowtotalexludingvatonpdf']) {
								unset($pdf_sub_options['titleshowtotalexludingvatonpdf']);
							}
						} else {
							if (isset($pdf_sub_options['titleshowuponpdf']) && abs($level) <= $pdf_sub_options['titleshowuponpdf']) {
								unset($pdf_sub_options['titleshowuponpdf']);
							}
							if (isset($pdf_sub_options['titleshowtotalexludingvatonpdf']) && abs($level) <= $pdf_sub_options['titleshowtotalexludingvatonpdf']) {
								unset($pdf_sub_options['titleshowtotalexludingvatonpdf']);
							}
						}
						// A subtotal line closes the block of the title line of same level: the next lines get back the options in use before this title line
						if ($level < 0 && isset($pdf_sub_options_before_title[(int) abs($level)])) {
							$pdf_sub_options = $pdf_sub_options_before_title[(int) abs($level)];
							foreach (array_keys($pdf_sub_options_before_title) as $tmplevel) {
								if ($tmplevel >= abs($level)) {
									unset($pdf_sub_options_before_title[$tmplevel]);
								}
							}
						}
					}

					if ($object->lines[$i]->special_code == SUBTOTALS_SPECIAL_CODE && isset($sub_options['subtotalshowtotalexludingvatonpdf']) && getDolGlobalString('SUBTOTAL_HIDE_LINES_UNDER_TITLE')) {	// TODO Use $sub_options['titlehidelinesundertitle'] instead of SUBTOTAL_HIDE_LINES_UNDER_TITLE
						$hidenextline = 0;
						$pdf_sub_options = array();
						$pdf_sub_options['titleshowuponpdf'] = 1;
						$pdf_sub_options['titleshowtotalexludingvatonpdf'] = 1;
					}

					if ($hidenextline) {
						$linePosition--;
					} else {
						// NavOnline (typography): the whole line (description, reference line, spacing) must fit on the page, else
						// it starts on the next one (the core checks 6 mm only, the prices then went to the next page)
						$navNeed = 6;
						if ($object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE && $this->getColumnStatus('desc')) {
							$pdf->startTransaction();
							$navP0 = $pdf->getPage();
							$pdf->SetFont('', '', 9.5);
							$navY0 = $curY;
							$this->printColDescContent($pdf, $navY0, 'desc', $object, $i, $outputlangs, 1, $hidedesc);
							if ($pdf->getPage() == $navP0) {
								$navNeed = max(6, $pdf->GetY() - $curY + (!$hideref && !empty($object->lines[$i]->product_ref) ? 4 : 0) + 1.6);
							}
							$pdf = $pdf->rollbackTransaction();
						}
						if (($curY + $navNeed) > ($this->page_hauteur - $this->heightforfooter) || isset($sub_options['titleforcepagebreak']) && !($pdf->getNumPages() == 1 && $curY == $this->tab_top + $this->tabTitleHeight)) {
							$object->lines[$i]->pagebreak = true;
						}

						// in First Check line page break and add page if needed
						if (isset($object->lines[$i]->pagebreak) && $object->lines[$i]->pagebreak) {
							// New page
							$pdf->AddPage();
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}

							$pdf->setPage($pdf->getNumPages());
							$nexY = $curY = $this->tab_top_newpage + 1.6;
						}

						$this->resetAfterColsLinePositionsData($nexY, $pdf->getPage());

						$pdf->SetFont('', '', 9.5); // Into loop to work with multipage (NavOnline: 9.5 pt)
						$pdf->SetTextColor(11, 18, 21);

						// Define size of image if we need it
						$imglinesize = array();
						if (!empty($realpatharray[$i])) {
							$imglinesize = pdf_getSizeForImage($realpatharray[$i]);
						}

						$pdf->setTopMargin($this->tab_top_newpage);
						$pdf->setPageOrientation('', true, $this->heightforfooter);
						$pageposbefore = $pdf->getPage();
						$curYBefore = $curY;

						// Allows data in the first page if description is long enough to break in multiples pages
						$showpricebeforepagebreak = getDolGlobalInt('MAIN_PDF_DATA_ON_FIRST_PAGE');

						if ($this->getColumnStatus('photo')) {
							// We start with Photo of product line
							$imageTopMargin = 1;
							if (isset($imglinesize['width']) && isset($imglinesize['height']) && ($curY + $imageTopMargin + $imglinesize['height']) > ($this->page_hauteur - $this->heightforfooter)) {    // If photo too high, we moved completely on new page
								$pdf->AddPage('', '', true);
								if (!empty($tplidx)) {
									$pdf->useTemplate($tplidx);
								}
								$pdf->setPage($pageposbefore + 1);
								$pdf->setPageOrientation('', true, $this->heightforfooter); // The only function to edit the bottom margin of current page to set it.
								$curY = $this->tab_top_newpage;
								$showpricebeforepagebreak = 0;
							}

							$pdf->setPageOrientation('', false, $this->heightforfooter + $this->heightforfreetext); // The only function to edit the bottom margin of current page to set it.
							// @phan-suppress-next-line PhanTypeMismatchProperty
							if (!empty($this->cols['photo']) && isset($imglinesize['width']) && isset($imglinesize['height'])) {
								$pdf->Image($realpatharray[$i], $this->getColumnContentXStart('photo'), $curY + $imageTopMargin, $imglinesize['width'], $imglinesize['height'], '', '', '', 2, 300); // Use 300 dpi
								// $pdf->Image does not increase value return by getY, so we save it manually
								$posYAfterImage = $curY + $imglinesize['height'];

								$this->setAfterColsLinePositionsData('photo', $posYAfterImage, $pdf->getPage());
							}
						}

						// restore Page orientation for text
						$pdf->setPageOrientation('', true, $this->heightforfooter); // The only function to edit the bottom margin of current page to set it.

						// Description of product line
						if ($this->getColumnStatus('desc')) {
							if ($object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
								// NavOnline: the product reference not before the label but on its own line under the description, 8 pt grey
								$this->printColDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, 1, $hidedesc);
								$navRef = (!$hideref && !empty($object->lines[$i]->product_ref)) ? (string) $object->lines[$i]->product_ref : '';
								if ($navRef !== '') {
									$pdf->SetFont('', '', 8);
									navonlinePdfColor($pdf, 'muted');
									$pdf->MultiCell($this->cols['desc']['width'], 0, $outputlangs->convToOutputCharset($navRef), 0, 'L', false, 1, $this->getColumnContentXStart('desc'), $pdf->GetY() + 0.3);
									navonlinePdfColor($pdf, 'text');
									$pdf->SetFont('', '', 9.5);
								}
								$this->setAfterColsLinePositionsData('desc', $pdf->GetY(), $pdf->getPage());
							} else {
								$bg_color = colorStringToArray(getDolGlobalString("SUBTOTAL_BACK_COLOR_LEVEL_".abs($object->lines[$i]->qty), 'ffffff'));
								pdf_render_subtotals($pdf, $this, $curY, $object, $i, $outputlangs, $hideref, $hidedesc, $bg_color, true, true);
							}
						}


						$afterPosData = $this->getMaxAfterColsLinePositionsData();
						$pdf->setPage($pageposbefore);
						$pdf->setTopMargin($this->marge_haute);
						$curY = $curYBefore;
						$pdf->setPageOrientation('', false, $this->heightforfooter); // The only function to edit the bottom margin of current page to set it.

						// We suppose that a too long description or photo were moved completely on next page
						if ($afterPosData['page'] > $pageposbefore && (empty($showpricebeforepagebreak) || ($curY + 4) > ($this->page_hauteur - $this->heightforfooter))) {
							$pdf->setPage($afterPosData['page']);
							$curY = $this->tab_top_newpage;
						}

						$pdf->SetFont('', '', 9.5); // We reposition the default font (NavOnline: 9.5 pt)

						// Line position
						if ($this->getColumnStatus('position')) {
							$this->printStdColumnContent($pdf, $curY, 'position', strval($linePosition).'.');	// NavOnline: "1."
						}

						// NavOnline: HS code (customs code of the product)
						if ($this->getColumnStatus('hscode') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$this->printStdColumnContent($pdf, $curY, 'hscode', $this->navHsCode($object->lines[$i]));
						}

						// VAT Rate
						if ($this->getColumnStatus('vat') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails);
							// NavOnline: a 0% line shows its NAV case code (AAM, KBAET, ...) instead of a bare "0%"
							if ((float) $object->lines[$i]->tva_tx == 0 && !empty($object->lines[$i]->vat_src_code) && !$hidedetails) {
								$vat_rate = $object->lines[$i]->vat_src_code;
							}
							$this->printStdColumnContent($pdf, $curY, 'vat', $vat_rate);
						}

						// Unit price before discount
						if ($this->getColumnStatus('subprice') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE && isset($pdf_sub_options['titleshowuponpdf'])) {
							$up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails);
							// NavOnline: on a credit note the sign is on the quantity (-1 db x 10 000), as sent to NAV
							$navNegQty = ((int) $object->type === Facture::TYPE_CREDIT_NOTE && (float) $object->lines[$i]->subprice < 0 && !getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE'));
							if ($navNegQty) {
								$up_excl_tax = preg_replace('/^(\s*)-\s*/', '$1', (string) $up_excl_tax);
							}
							$this->printStdColumnContent($pdf, $curY, 'subprice', $up_excl_tax);
						}

						// Quantity
						// Enough for 6 chars
						if ($this->getColumnStatus('qty') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$qty = isset($this->navDeductionLines[$i]) ? '' : pdf_getlineqty($object, $i, $outputlangs, $hidedetails);	// NavOnline: no "1" on the deducted deposit
							if ((int) $object->type === Facture::TYPE_CREDIT_NOTE && (float) $object->lines[$i]->subprice < 0 && !getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE') && (string) $qty !== '') {
								$qty = '-'.$qty;
							}
							$this->printStdColumnContent($pdf, $curY, 'qty', $qty);
						}

						// Situation progress
						if ($this->getColumnStatus('progress') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$progress = pdf_getlineprogress($object, $i, $outputlangs, $hidedetails);
							$this->printStdColumnContent($pdf, $curY, 'progress', $progress);
						}

						// Unit
						if ($this->getColumnStatus('unit') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$unit = pdf_getlineunit($object, $i, $outputlangs, $hidedetails);
							$this->printStdColumnContent($pdf, $curY, 'unit', $unit);
						}

						// Discount on line
						if ($this->getColumnStatus('discount') && $object->lines[$i]->remise_percent && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails);
							$this->printStdColumnContent($pdf, $curY, 'discount', $remise_percent);
						}

						// Total excl tax line (HT)
						if ($this->getColumnStatus('totalexcltax')) {
							if ($object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE && isset($pdf_sub_options['titleshowtotalexludingvatonpdf'])) {
								$total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails);
								$this->printStdColumnContent($pdf, $curY, 'totalexcltax', $total_excl_tax);
							} elseif ($object->lines[$i]->qty < 0 && isset($sub_options['subtotalshowtotalexludingvatonpdf'])) {
								if (isModEnabled('multicurrency') && $object->multicurrency_code != $conf->currency) {
									$total_excl_tax = $object->getSubtotalLineMulticurrencyAmount($object->lines[$i]);
								} else {
									$total_excl_tax = $object->getSubtotalLineAmount($object->lines[$i]);
								}
								$this->printStdColumnContent($pdf, $curY, 'totalexcltax', $total_excl_tax);
							}
						}

						// Total with tax line (TTC)
						if ($this->getColumnStatus('totalincltax') && $object->lines[$i]->special_code != SUBTOTALS_SPECIAL_CODE) {
							$total_incl_tax = pdf_getlinetotalwithtax($object, $i, $outputlangs, $hidedetails);
							$this->printStdColumnContent($pdf, $curY, 'totalincltax', $total_incl_tax);
						}

						// Extrafields
						if (!empty($object->lines[$i]->array_options)) {
							foreach ($object->lines[$i]->array_options as $extrafieldColKey => $extrafieldValue) {
								if ($this->getColumnStatus($extrafieldColKey)) {
									$extrafieldValue = $this->getExtrafieldContent($object->lines[$i], $extrafieldColKey, $outputlangs);
									$this->printStdColumnContent($pdf, $curY, $extrafieldColKey, $extrafieldValue);

									$this->setAfterColsLinePositionsData('options_' . $extrafieldColKey, $pdf->GetY(), $pdf->getPage());
								}
							}
						}

						$afterPosData = $this->getMaxAfterColsLinePositionsData();
						$parameters = array(
						'object' => $object,
						'i' => $i,
						'pdf' => & $pdf,
						'curY' => & $curY,
						'nexY' => & $afterPosData['y'], // for backward module hook compatibility Y will be accessible by $object->getMaxAfterColsLinePositionsData()
						'outputlangs' => $outputlangs,
						'hidedetails' => $hidedetails
						);
						$reshook = $hookmanager->executeHooks('printPDFline', $parameters, $this); // Note that $object may have been modified by hook
					}

					$sign = 1;
					if (isset($object->type) && $object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) {
						$sign = -1;
					}

					// Collect total by value of vat rate into $this->tva_array
					// Lines are already stored as delta (not cumulative) once INVOICE_USE_SITUATION=2, so no ratio must be reapplied here (same guard as CommonObject::update_price())
					$prev_progress = getDolGlobalInt('INVOICE_USE_SITUATION') == 2 ? 0 : $object->lines[$i]->get_prev_progress($object->id);

					if ($prev_progress > 0 && !empty($object->lines[$i]->situation_percent)) { // Compute progress from previous situation
						if (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) {
							$tvaligne = $sign * $object->lines[$i]->multicurrency_total_tva * ($object->lines[$i]->situation_percent - $prev_progress) / $object->lines[$i]->situation_percent;
						} else {
							$tvaligne = $sign * $object->lines[$i]->total_tva * ($object->lines[$i]->situation_percent - $prev_progress) / $object->lines[$i]->situation_percent;
						}
					} else {
						if (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) {
							$tvaligne = $sign * $object->lines[$i]->multicurrency_total_tva;
						} else {
							$tvaligne = $sign * $object->lines[$i]->total_tva;
						}
					}

					$localtax1ligne = $object->lines[$i]->total_localtax1;
					$localtax2ligne = $object->lines[$i]->total_localtax2;
					$localtax1_rate = $object->lines[$i]->localtax1_tx;
					$localtax2_rate = $object->lines[$i]->localtax2_tx;
					$localtax1_type = $object->lines[$i]->localtax1_type;
					$localtax2_type = $object->lines[$i]->localtax2_type;

					$vatrate = (string) $object->lines[$i]->tva_tx;

					// Retrieve type from database for backward compatibility with old records
					if ((!isset($localtax1_type) || $localtax1_type == '' || !isset($localtax2_type) || $localtax2_type == '') // if tax type not defined
						&& (!empty($localtax1_rate) || !empty($localtax2_rate))) { // and there is local tax
						$localtaxtmp_array = getLocalTaxesFromRate($vatrate, 0, $object->thirdparty, $mysoc);
						$localtax1_type = isset($localtaxtmp_array[0]) ? $localtaxtmp_array[0] : '';
						$localtax2_type = isset($localtaxtmp_array[2]) ? $localtaxtmp_array[2] : '';
					}

					// retrieve global local tax
					if ($localtax1_type && $localtax1ligne != 0) {
						if (empty($this->localtax1[$localtax1_type][$localtax1_rate])) {
							$this->localtax1[$localtax1_type][$localtax1_rate] = $localtax1ligne;
						} else {
							$this->localtax1[$localtax1_type][$localtax1_rate] += $localtax1ligne;
						}
					}
					if ($localtax2_type && $localtax2ligne != 0) {
						if (empty($this->localtax2[$localtax2_type][$localtax2_rate])) {
							$this->localtax2[$localtax2_type][$localtax2_rate] = $localtax2ligne;
						} else {
							$this->localtax2[$localtax2_type][$localtax2_rate] += $localtax2ligne;
						}
					}

					if (($object->lines[$i]->info_bits & 0x01) == 0x01) {
						$vatrate .= '*';
					}

					// Fill $this->tva and $this->tva_array
					if (!isset($this->tva[$vatrate])) {
						$this->tva[$vatrate] = 0;
					}
					$this->tva[$vatrate] += $tvaligne;    // ->tva is abandoned, we use now ->tva_array that is more complete
					$vatcode = $object->lines[$i]->vat_src_code;
					if (empty($this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'])) {
						$this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'] = 0;
					}
					if (getDolGlobalInt('PDF_INVOICE_SHOW_VAT_ANALYSIS')) {
						if (empty($this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['tot_ht'])) {
							$this->tva_array[$vatrate . ($vatcode ? ' (' . $vatcode . ')' : '')]['tot_ht'] = 0;
						}
						$this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')] = array('vatrate' => $vatrate, 'vatcode' => $vatcode, 'amount' => $this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'] + $tvaligne, 'tot_ht' => $this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['tot_ht'] + $object->lines[$i]->total_ht);
					} else {
						$this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')] = array('vatrate' => $vatrate, 'vatcode' => $vatcode, 'amount' => $this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'] + $tvaligne);
					}

					if (!$hidenextline) {
						$afterPosData = $this->getMaxAfterColsLinePositionsData();
						$pdf->setPage($afterPosData['page']);
						$nexY = $afterPosData['y'];

						// Add line
						// NavOnline (typography): 1.6 mm space, a thin solid separator (0.35 pt, #D6DCE5), 1.6 mm space
						$nexY += 1.6;
						if ($i < ($nblines - 1) && $nexY < $this->page_hauteur - $this->heightforfooter - 5) {
							navonlinePdfThinLine($pdf);
							$pdf->line($this->marge_gauche, $nexY, $this->page_largeur - $this->marge_droite, $nexY);
							navonlinePdfLineReset($pdf);
						}
						$nexY += 1.6;
					}

					if ($object->lines[$i]->special_code == SUBTOTALS_SPECIAL_CODE && (isset($sub_options['titleshowuponpdf']) || isset($sub_options['titleshowtotalexludingvatonpdf'])) && getDolGlobalString('SUBTOTAL_HIDE_LINES_UNDER_TITLE')) {	// TODO Use $sub_options['titlehidelinesundertitle'] instead of SUBTOTAL_HIDE_LINES_UNDER_TITLE
						$hidenextline = 1;
					}

					$nexY += 0; // Add space between lines
				}

				// NavOnline (typography): real height of the area under the lines (info, VAT summary, totals, payments and the
				// legal clauses), measured by drawing it once in a transaction; the core reserves a fixed estimate
				$pdf->startTransaction();
				$navSimY = 20;
				$navI = $this->drawInfoTable($pdf, $object, $navSimY, $outputlangs, $outputlangsbis);
				$navS = $this->navSummaryEndY;
				$navT = $this->drawTotalTable($pdf, $object, $deja_regle, $navSimY, $outputlangs, $outputlangsbis);
				$navT = $this->drawNavPaidStamp($pdf, $object, max((float) $navT, (float) $navS), $outputlangs, $outputlangsbis);
				$navLp = $object->getListOfPayments('', 0, 1);
				if ((count($navLp) || $amount_credit_notes_included || $amount_deposits_included) && !getDolGlobalString('INVOICE_NO_PAYMENT_DETAILS')) {
					$navT = $this->drawPaymentsTable($pdf, $object, $navT, $outputlangs);
				}
				$navT = $this->navDrawTerms($pdf, $object, (float) $navT + 3, $outputlangs, $outputlangsbis);
				$navAreaH = max((float) $navI, (float) $navT, (float) $navS) - $navSimY;	// exact: the page break follows the real height
				if ($this->navClausesH > 0) {
					$navAreaH += 4 + $this->navClausesH;
				}
				$pdf = $pdf->rollbackTransaction();
				$this->heightforinfotot = $navAreaH;

				// Add last page for document footer if there are not enough size left
				$afterPosData = $this->getMaxAfterColsLinePositionsData();
				$page_bottom_margin = $this->heightforfooter + $this->heightforfreetext + $this->heightforinfotot + $this->getHeightForQRInvoice($pdf->getPage(), $object, $langs);

				// NavOnline: when the totals do not fit under the last line, the table closes under the last line and the
				// totals start at the top of the new page: no empty table frame at the bottom of the one page and the top of the next
				$navTotalsPage = 0;
				$navLastLineY = 0;
				$navLastLinePage = 0;
				// The totals follow the last line (the frame 2 mm under it, the totals 3.5 mm under the frame): a new page only
				// when their measured height does not fit there
				if (isset($afterPosData['y'])) {
					$navLastLineY = (float) $afterPosData['y'];
					$navLastLinePage = (int) $afterPosData['page'];
				}
				if (isset($afterPosData['y']) && $afterPosData['y'] + 5.5 > $this->page_hauteur - $page_bottom_margin) {
					$pdf->AddPage();
					$navTotalsPage = $pdf->getPage();
					if (!empty($tplidx)) {
						$pdf->useTemplate($tplidx);
					}
					$pagenb++;
					$pdf->setPage($pagenb);
				}

				// Draw table frames and columns borders
				$drawTabNumbPage = $pdf->getNumPages();
				for ($i = $pageposbeforeprintlines; $i <= $drawTabNumbPage; $i++) {
					$pdf->setPage($i);
					// reset page orientation each loop to override it if it was changed
					$pdf->setPageOrientation('', false, 0); // The only function to edit the bottom margin of current page to set it.

					$drawTabHideTop = $hidetop;
					$drawTabTop = $this->tab_top_newpage;
					$drawTabBottom = $this->page_hauteur - $this->heightforfooter;
					$hideBottom = 0; // TODO understand why it change to 1 or  0 during process

					if ($i == $pageposbeforeprintlines) {
						// first page need to start after notes
						$drawTabTop = $this->tab_top;
					} elseif (!$drawTabHideTop) {
						if (getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
							$drawTabTop -= $this->tabTitleHeight;
						} else {
							$drawTabHideTop = 1;
						}
					}

					// last page need to include document footer
					if ($i == $pdf->getNumPages()) {
						// remove document footer height to tab bottom position
						$drawTabBottom -= $this->heightforfreetext + $this->heightforinfotot + $this->getHeightForQRInvoice($pdf->getPage(), $object, $outputlangs);
					}

					if ($navLastLinePage && $i == $navLastLinePage) {
						$drawTabBottom = min($drawTabBottom, $navLastLineY + 2);
					}

					$drawTabHeight = $drawTabBottom - $drawTabTop;
					if (!($navTotalsPage && $i == $navTotalsPage)) {
						$this->_tableau($pdf, $drawTabTop, $drawTabHeight, 0, $outputlangs, $drawTabHideTop, $hideBottom, $object, $outputlangsbis);
					}

					$hideFreeText = $i != $pdf->getNumPages() ? 1 : 0; // Display free text only in last page

					$this->_pagefoot($pdf, $object, $outputlangs, $hideFreeText, $this->getHeightForQRInvoice($pdf->getPage(), $object, $outputlangs));

					$pdf->setPage($i); // in case of _pagefoot or _tableau change it

					// reset page orientation each loop to override it if it was changed by _pagefoot or _tableau change it
					$pdf->setPageOrientation('', true, 0); // The only function to edit the bottom margin of current page to set it.

					// Don't print head on first page ($pageposbeforeprintlines) because already added previously
					if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') && $i != $pageposbeforeprintlines) {
						$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);	// NavOnline: core omits the second language here
					}
					if (!empty($tplidx)) {
						$pdf->useTemplate($tplidx);
					}
				}


				// reset text color before print footers
				$pdf->SetTextColor(11, 18, 21);

				$pdf->setPage($pdf->getNumPages());

				$bottomlasttab = $this->page_hauteur - $this->heightforinfotot - $this->heightforfreetext - $this->heightforfooter - $heightforqrinvoice + 1;
				if ($navTotalsPage) {
					// NavOnline: the totals at the top of their own page, where the table would start
					$bottomlasttab = $this->tab_top_newpage - $this->tabTitleHeight;
				} elseif ($navLastLinePage) {
					// NavOnline: the totals 3.5 mm under the frame of the last line
					$bottomlasttab = $navLastLineY + 2 + 3.5;
				}

				// Display infos area
				$navInfoEnd = $posy = $this->drawInfoTable($pdf, $object, $bottomlasttab, $outputlangs, $outputlangsbis);

				// Display total zone
				$posy = $this->drawTotalTable($pdf, $object, $deja_regle, $bottomlasttab, $outputlangs, $outputlangsbis);

				// NavOnline: "Paid / Fizetve" under all the totals (right column, below the HUF VAT / rate lines too)
				$posy = $this->drawNavPaidStamp($pdf, $object, max((float) $posy, (float) $this->navSummaryEndY), $outputlangs, $outputlangsbis);

				// Display payment area
				$listofpayments = $object->getListOfPayments('', 0, 1);
				if ((count($listofpayments) || $amount_credit_notes_included || $amount_deposits_included) && !getDolGlobalString('INVOICE_NO_PAYMENT_DETAILS')) {
					$posy = $this->drawPaymentsTable($pdf, $object, $posy, $outputlangs);
				}

				// NavOnline (typography): delivery and payment terms in the right column under the totals
				$posy = $this->navDrawTerms($pdf, $object, (float) $posy + 3, $outputlangs, $outputlangsbis);

				// NavOnline (typography): legal clauses after the totals and the payment details, framed, 8.5 pt regular
				if ($this->navClausesHtml !== '') {
					$navY = max((float) $posy, (float) $navInfoEnd, (float) $this->navSummaryEndY) + 4;
					if ($navY + $this->navClausesH > $this->page_hauteur - $this->heightforfooter - $this->heightforfreetext) {
						$pdf->AddPage();
						if (!empty($tplidx)) {
							$pdf->useTemplate($tplidx);
						}
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
						}
						$this->_pagefoot($pdf, $object, $outputlangs, 1, 0);
						$navY = $this->tab_top_newpage - $this->tabTitleHeight;
					}
					$navW = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
					$pdf->SetFont('', '', 8.5);
					navonlinePdfColor($pdf, 'text');
					$navBottom = $this->navDrawClauses($pdf, $this->marge_gauche + 2.5, $navY + 2.5, $navW - 5) + 2;
					navonlinePdfThinLine($pdf);
					$pdf->Rect($this->marge_gauche, $navY, $navW, $navBottom - $navY, 'D');
					navonlinePdfLineReset($pdf);
				}

				// Add number of pages in footer
				if (method_exists($pdf, 'AliasNbPages')) {
					$pdf->AliasNbPages();  // @phan-suppress-current-line PhanUndeclaredMethod
				}

				// Add terms to sale
				$termsofsalefilename = getDolGlobalString('MAIN_INFO_INVOICE_TERMSOFSALE');
				if (getDolGlobalInt('MAIN_PDF_ADD_TERMSOFSALE_INVOICE') && $termsofsalefilename) {
					$termsofsale = $conf->invoice->dir_output.'/'.$termsofsalefilename;
					if (!empty($conf->invoice->multidir_output[$object->entity ?? $conf->entity])) {
						$termsofsale = $conf->invoice->multidir_output[$object->entity ?? $conf->entity].'/'.$termsofsalefilename;
					}

					if (file_exists($termsofsale) && is_readable($termsofsale)) {
						$pagecount = $pdf->setSourceFile($termsofsale);
						for ($i = 1; $i <= $pagecount; $i++) {
							$tplIdx = $pdf->importPage($i);
							if ($tplIdx !== false) {
								$s = $pdf->getTemplatesize($tplIdx);
								$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
								$pdf->useTemplate($tplIdx);
							} else {
								setEventMessages(null, array($termsofsale.' cannot be added, probably protected PDF'), 'warnings');
							}
						}
					}
				}

				if (getDolGlobalString('INVOICE_ADD_SWISS_QR_CODE') == 'bottom') {
					$this->addBottomQRInvoice($pdf, $object, $outputlangs);
				}

				$pdf->Close();

				$pdf->Output($file, 'F');

				// Add pdfgeneration hook
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
				$this->warnings = $hookmanager->warnings;
				if ($reshook < 0) {
					$this->error = $hookmanager->error;
					$this->errors = $hookmanager->errors;
					dolChmod($file);
					return -1;
				}

				dolChmod($file);

				$this->result = array('fullpath' => $file);
				foreach ($navDescSaved as $k => $v) {
					$object->lines[$k]->desc = $v;
				}

				return 1; // No error
			} else {
				$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		} else {
			$this->error = $langs->transnoentities("ErrorConstantNotDefined", "FAC_OUTPUTDIR");
			return 0;
		}
	}


	/**
	 *  Show payments table
	 *
	 *  @param	TCPDF		$pdf            Object PDF
	 *  @param  Facture		$object         Object invoice
	 *  @param  float		$posy           Position y in PDF
	 *  @param  Translate	$outputlangs    Object langs for output
	 *  @return int|float          			Return integer <0 if KO, >0 if OK
	 */
	public function drawPaymentsTable(&$pdf, $object, $posy, $outputlangs)
	{
		$sign = 1;
		if ($object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) {
			$sign = -1;
		}

		$tab3_posx = 120;
		$tab3_top = $posy + 8;
		$tab3_width = 80;
		$tab3_height = 4;
		if ($this->page_largeur < 210) { // To work with US executive format
			$tab3_posx -= 15;
		}

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$title = $outputlangs->transnoentities("PaymentsAlreadyDone");
		if ($object->type == 2) {
			$title = $outputlangs->transnoentities("PaymentsBackAlreadyDone");
		}

		$pdf->SetFont('', '', $default_font_size - 3);
		$pdf->SetXY($tab3_posx, $tab3_top - 4);
		$pdf->MultiCell(60, 3, $title, 0, 'L', false);

		$pdf->line($tab3_posx, $tab3_top, $tab3_posx + $tab3_width, $tab3_top);

		$pdf->SetFont('', '', $default_font_size - 4);
		$pdf->SetXY($tab3_posx, $tab3_top);
		$pdf->MultiCell(20, 3, $outputlangs->transnoentities("Payment"), 0, 'L', false);
		$pdf->SetXY($tab3_posx + 21, $tab3_top);
		$pdf->MultiCell(20, 3, $outputlangs->transnoentities("Amount"), 0, 'L', false);
		$pdf->SetXY($tab3_posx + 40, $tab3_top);
		$pdf->MultiCell(20, 3, $outputlangs->transnoentities("Type"), 0, 'L', false);
		$pdf->SetXY($tab3_posx + 58, $tab3_top);
		$pdf->MultiCell(20, 3, $outputlangs->transnoentities("Num"), 0, 'L', false);

		$pdf->line($tab3_posx, $tab3_top - 1 + $tab3_height, $tab3_posx + $tab3_width, $tab3_top - 1 + $tab3_height);

		$y = 0;

		$pdf->SetFont('', '', $default_font_size - 4);


		// Loop on each discount available (deposits and credit notes and excess of payment included)
		$sql = "SELECT re.rowid, re.amount_ht, re.multicurrency_amount_ht, re.amount_tva, re.multicurrency_amount_tva,  re.amount_ttc, re.multicurrency_amount_ttc,";
		$sql .= " re.description, re.fk_facture_source,";
		$sql .= " f.type, f.datef";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe_remise_except as re, ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE re.fk_facture_source = f.rowid AND re.fk_facture = ".((int) $object->id);
		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			$invoice = new Facture($this->db);
			while ($i < $num) {
				$y += 3;
				$obj = $this->db->fetch_object($resql);

				if ($obj->type == 2) {
					$text = $outputlangs->transnoentities("CreditNote");
				} elseif ($obj->type == 3) {
					$text = $outputlangs->transnoentities("Deposit");
				} elseif ($obj->type == 0) {
					$text = $outputlangs->transnoentities("ExcessReceived");
				} else {
					$text = $outputlangs->transnoentities("UnknownType");
				}

				$invoice->fetch($obj->fk_facture_source);

				$pdf->SetXY($tab3_posx, $tab3_top + $y);
				$pdf->MultiCell(20, 3, dol_print_date($this->db->jdate($obj->datef), 'day', false, $outputlangs, true), 0, 'L', false);
				$pdf->SetXY($tab3_posx + 21, $tab3_top + $y);
				$pdf->MultiCell(20, 3, price((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $obj->multicurrency_amount_ttc : $obj->amount_ttc, 0, $outputlangs), 0, 'L', false);
				$pdf->SetXY($tab3_posx + 40, $tab3_top + $y);
				$pdf->MultiCell(20, 3, $text, 0, 'L', false);
				$pdf->SetXY($tab3_posx + 58, $tab3_top + $y);
				$pdf->MultiCell(20, 3, $invoice->ref, 0, 'L', false);

				$pdf->line($tab3_posx, $tab3_top + $y + 3, $tab3_posx + $tab3_width, $tab3_top + $y + 3);

				$i++;
			}
		} else {
			$this->error = $this->db->lasterror();
			return -1;
		}

		// Loop on each payment
		// TODO Call getListOfPayments instead of hard coded sql
		$sql = "SELECT p.datep as date, p.fk_paiement, p.num_paiement as num, pf.amount as amount, pf.multicurrency_amount,";
		$sql .= " cp.code";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf, ".MAIN_DB_PREFIX."paiement as p";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON p.fk_paiement = cp.id";
		$sql .= " WHERE pf.fk_paiement = p.rowid AND pf.fk_facture = ".((int) $object->id);
		//$sql.= " WHERE pf.fk_paiement = p.rowid AND pf.fk_facture = 1";
		$sql .= " ORDER BY p.datep";

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			$y += 3;
			$maxY = $y;
			while ($i < $num) {
				$row = $this->db->fetch_object($resql);
				$pdf->SetXY($tab3_posx, $tab3_top + $y);
				$pdf->MultiCell(20, 3, dol_print_date($this->db->jdate($row->date), 'day', false, $outputlangs, true), 0, 'L', false);
				$pdf->SetXY($tab3_posx + 21, $tab3_top + $y);
				$pdf->MultiCell(20, 3, price($sign * ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $row->multicurrency_amount : $row->amount), 0, $outputlangs), 0, 'L', false);
				$pdf->SetXY($tab3_posx + 40, $tab3_top + $y);
				$oper = $outputlangs->transnoentitiesnoconv("PaymentTypeShort".$row->code);

				$pdf->MultiCell(20, 3, $oper, 0, 'L', false);
				$maxY = max($pdf->GetY() - $tab3_top - 3, $maxY);
				$pdf->SetXY($tab3_posx + 58, $tab3_top + $y);
				$pdf->MultiCell(30, 3, $row->num, 0, 'L', false);
				$y = $maxY = max($pdf->GetY() - 3 - $tab3_top, $maxY);
				$pdf->line($tab3_posx, $tab3_top + $y + 3, $tab3_posx + $tab3_width, $tab3_top + $y + 3);
				$y += 3;
				$i++;
			}

			return $tab3_top + $y + 3;
		} else {
			$this->error = $this->db->lasterror();
			return -1;
		}
	}


	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		Facture		$object			Object to show
	 *   @param		float		$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param  	?Translate	$outputlangsbis	Object lang for output bis
	 *   @return	float						Pos y
	 */
	protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs, $outputlangsbis)
	{
		global $mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetFont('', '', $default_font_size - 1);

		krsort($this->tva_array);

		// Clean data type
		$object->total_tva = (float) $object->total_tva;

		// NavOnline: VAT summary per rate (tax base, VAT, gross + total row), on every invoice
		$posy = $this->drawNavVatSummary($pdf, $object, $posy, $outputlangs, $outputlangsbis);

		// NavOnline (typography): Incoterms, weights and payment terms are in the right column under the totals (navDrawTerms)

		// Show VAT details
		if (false && $object->total_tva != 0 && getDolGlobalInt('PDF_INVOICE_SHOW_VAT_ANALYSIS')) {
			$pdf->SetFillColor(224, 224, 224);

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("VAT");
			$pdf->MultiCell(25, 4, $titre, 0, 'L', true);

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche + 25, $posy);
			$titre = $outputlangs->transnoentities("NetTotal");
			$pdf->MultiCell(25, 4, $titre, 0, 'L', true);

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche + 50, $posy);
			$titre = $outputlangs->transnoentities("VATAmount");
			$pdf->MultiCell(25, 4, $titre, 0, 'L', true);

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche + 75, $posy);
			$titre = $outputlangs->transnoentities("AmountTotal");
			$pdf->MultiCell(25, 4, $titre, 0, 'L', true);

			$posy = $pdf->GetY();
			$tot_ht = 0;
			$tot_tva = 0;
			$tot_ttc = 0;

			foreach ($this->tva_array as $tvakey => $tvaval) {
				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($this->marge_gauche, $posy);
				$titre = round((float) $tvakey, 2) . "%";
				$pdf->MultiCell(25, 4, $titre, 0, 'L');

				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($this->marge_gauche + 25, $posy);
				$titre = price($tvaval['tot_ht']);
				$pdf->MultiCell(25, 4, $titre, 0, 'L');
				$tot_ht += $tvaval['tot_ht'];

				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($this->marge_gauche + 50, $posy);
				$titre = price($tvaval['amount']);
				$pdf->MultiCell(25, 4, $titre, 0, 'L');
				$tot_tva += $tvaval['amount'];

				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($this->marge_gauche + 75, $posy);
				$titre = price($tvaval['tot_ht'] + $tvaval['amount']);
				$pdf->MultiCell(25, 4, $titre, 0, 'L');
				$tot_ttc += ($tvaval['tot_ht'] + $tvaval['amount']);

				$posy = $pdf->GetY();
			}
		}

		// If France, show VAT mention if applicable
		$showvatmention = 0;
		if (in_array($this->emetteur->country_code, array('FR')) && empty($object->total_tva)) {
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			if (empty($mysoc->tva_assuj)) {
				if ($mysoc->forme_juridique_code == 92) {
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoiceAsso"), 0, 'L', false);
				} else {
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoice"), 0, 'L', false);
				}
				$showvatmention++;
			} elseif (getDolGlobalString("INVOICE_VAT_SHOW_REVERSE_CHARGE_MENTION") && $this->emetteur->country_code != $object->thirdparty->country_code && $this->emetteur->isInEEC() && $object->thirdparty->isInEEC()) {
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedReverseChargeProcedure"), 0, 'L', false);
				$showvatmention++;
			}
			$posy = $pdf->GetY();
		}

		$showvatmention += pdfCertifMention($pdf, $outputlangs, $this->emetteur, $default_font_size, $posy, $this);

		if ($showvatmention) {
			$posy += 3;
		}

		$posxval = 52;	// Position of values of properties shown on left side
		$posxend = 110;	// End of x for text on left side
		if ($this->page_largeur < 210) { // To work with US executive format
			$posxend -= 10;
		}

		// Show previous and new balance
		if ($object->status > Facture::STATUS_DRAFT && getDolGlobalInt('PDF_INVOICE_SHOW_BALANCE_SUMMARY')) {
			// All customer previous invoices
			$sql = "SELECT f.rowid, f.datef, f.total_ttc";
			$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
			$sql .= " WHERE f.fk_soc = " . ((int) $object->socid);
			$sql .= " AND f.entity IN (" . getEntity('invoice') . ")";
			$sql .= " AND f.datef <= '" . $this->db->idate($object->date) . "'";
			$sql .= " AND f.rowid < " . ((int) $object->id);
			$sql .= " AND f.fk_statut > 0";
			$sql .= " ORDER BY f.datef ASC";

			$old_balance = 0;
			$invoices = array();
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$invoices[] = $obj;
					$old_balance += $obj->total_ttc;
				}
				$this->db->free($resql);
			}

			// All payments before current date
			$sql_payments = "SELECT p.datep, pf.fk_facture, pf.amount";
			$sql_payments .= " FROM " . MAIN_DB_PREFIX . "paiement_facture as pf";
			$sql_payments .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement as p ON p.rowid = pf.fk_paiement";
			$sql_payments .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = pf.fk_facture";
			$sql_payments .= " WHERE f.fk_soc = " . ((int) $object->socid);
			$sql_payments .= " AND p.datep < '" . $this->db->idate($object->date) . "'";
			$sql_payments .= " ORDER BY p.datep ASC";

			$total_payments = 0;
			$resql_payments = $this->db->query($sql_payments);
			if ($resql_payments) {
				while ($obj_payment = $this->db->fetch_object($resql_payments)) {
					$total_payments += $obj_payment->amount;
				}
				$this->db->free($resql_payments);
			}

			// Payments made on current invoice date (including current invoice)
			$sql_current_date_payments = "SELECT p.datep, pf.fk_facture, pf.amount";
			$sql_current_date_payments .= " FROM " . MAIN_DB_PREFIX . "paiement_facture as pf";
			$sql_current_date_payments .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement as p ON p.rowid = pf.fk_paiement";
			$sql_current_date_payments .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = pf.fk_facture";
			$sql_current_date_payments .= " WHERE f.fk_soc = " . ((int) $object->socid);
			$sql_current_date_payments .= " AND DATE(p.datep) = DATE('" . $this->db->idate($object->date) . "')";

			$current_date_payments = 0;
			$resql_current_date = $this->db->query($sql_current_date_payments);
			if ($resql_current_date) {
				while ($obj_current = $this->db->fetch_object($resql_current_date)) {
					$current_date_payments += $obj_current->amount;
				}
				$this->db->free($resql_current_date);
			}

			// Previous balance
			$old_balance -= $total_payments;

			// New balance
			$new_balance = $old_balance + $object->total_ttc - $current_date_payments;

			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("PreviousBalance").': '.price($old_balance);
			$pdf->MultiCell($posxval - $this->marge_gauche + 8, 4, $titre, 0, 'L', true);

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posxval + 8, $posy);
			$titre = $outputlangs->transnoentities("NewBalance").': '.price($new_balance);
			$pdf->MultiCell($posxend - $posxval - 8, 4, $titre, 0, 'L', true);

			$posy = $pdf->GetY() + 1;
		}

		// NavOnline: final invoice fully covered by the deposits: no payment terms / bank details, but the notice
		if (navonlineFinalInvoiceNoPayment($this->db, $object)) {
			$pdf->SetFont('', 'B', $default_font_size - 1);
			$pdf->SetXY($this->marge_gauche, $posy);
			$notice = $outputlangs->transnoentities('NavOnlinePdfNoPaymentNeeded');
			if (is_object($outputlangsbis)) {
				$outputlangsbis->load('navonline@navonline');
				$notice .= "
".$outputlangsbis->transnoentities('NavOnlinePdfNoPaymentNeeded');
			}
			$pdf->MultiCell($posxend - $this->marge_gauche, 4, $notice, 0, 'L');
			$pdf->SetFont('', '', $default_font_size - 1);
			return $pdf->GetY() + 2;
		}

		// Show category of operations
		if (getDolGlobalInt('INVOICE_CATEGORY_OF_OPERATION') == 2 && $this->categoryOfOperation >= 0) {
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			$categoryOfOperationTitle = $outputlangs->transnoentities("MentionCategoryOfOperations").': ';
			$pdf->MultiCell($posxval - $this->marge_gauche, 4, $categoryOfOperationTitle, 0, 'L');

			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posxval, $posy);
			$categoryOfOperationLabel = $outputlangs->transnoentities("MentionCategoryOfOperations" . $this->categoryOfOperation);
			$pdf->MultiCell($posxend - $posxval, 4, $categoryOfOperationLabel, 0, 'L');

			$posy = $pdf->GetY() + 3; // for 2 lines
		}

		if ($object->type != 2) {
			// Check a payment mode is defined
			if (empty($object->mode_reglement_code)
				&& !getDolGlobalInt('FACTURE_CHQ_NUMBER')
				&& !getDolGlobalInt('FACTURE_RIB_NUMBER')) {
				$this->error = $outputlangs->transnoentities("ErrorNoPaiementModeConfigured");
			} elseif (($object->mode_reglement_code == 'CHQ' && !getDolGlobalInt('FACTURE_CHQ_NUMBER') && empty($object->fk_account) && empty($object->fk_bank))
				|| ($object->mode_reglement_code == 'VIR' && !getDolGlobalInt('FACTURE_RIB_NUMBER') && empty($object->fk_account) && empty($object->fk_bank))) {
				// Avoid having any valid PDF with setup that is not complete
				$outputlangs->load("errors");

				$pdf->SetXY($this->marge_gauche, $posy);
				$pdf->SetTextColor(200, 0, 0);
				$pdf->SetFont('', '', $default_font_size - 2);
				$this->error = $outputlangs->transnoentities("ErrorPaymentModeDefinedToWithoutSetup", $object->mode_reglement_code);
				$pdf->MultiCell($posxend - $this->marge_gauche, 3, $this->error, 0, 'L', false);
				$pdf->SetTextColor(11, 18, 21);

				$posy = $pdf->GetY() + 1;
			}

			// Show payment mode
			if (!empty($object->mode_reglement_code)
				&& $object->mode_reglement_code != 'CHQ'
				&& ($object->mode_reglement_code != 'VIR' || (int) $object->type === Facture::TYPE_DEPOSIT)) {	// NavOnline: the deposit invoice shows how it was paid
				$lib_mode_reg = $outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) != 'PaymentType'.$object->mode_reglement_code ? $outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) : $outputlangs->convToOutputCharset($object->mode_reglement);

				//#21654: add account number used for the debit
				if ($object->mode_reglement_code == "PRE") {
					require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
					$bac = new CompanyBankAccount($this->db);
					// @phan-suppress-next-line PhanPluginSuspiciousParamPosition
					$bac->fetch(0, '', $object->thirdparty->id);
					$iban = $bac->iban.(($bac->iban && $bac->bic) ? ' / ' : '').$bac->bic;
					$lib_mode_reg .= ' '.$outputlangs->trans("PaymentTypePREdetails", dol_trunc($iban, 6, 'right', 'UTF-8', 1));
				}
				// NavOnline: label / value pair, the value bold
				navonlinePdfInfoPair($pdf, $this->marge_gauche, $posy, $posxend - $this->marge_gauche, $outputlangs->transnoentities("PaymentMode").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("PaymentMode") : '').':', $lib_mode_reg, true);

				$posy = $pdf->GetY();
			}

			// Show if Option VAT debit option is on also if transmitter is french
			// Decret n°2099-1299 2022-10-07
			// French legal mention: "Option pour le paiement de la taxe d'apres les debits"
			if ($this->emetteur->country_code == 'FR') {
				if (getDolGlobalInt('TAX_MODE') == 1) {
					$pdf->SetXY($this->marge_gauche, $posy);
					$pdf->writeHTMLCell(80, 5, null, null, $outputlangs->transnoentities("MentionVATDebitOptionIsOn"), 0, 1);

					$posy = $pdf->GetY() + 1;
				}
			}

			// Show online payment link
			if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'CB' || $object->mode_reglement_code == 'VAD') {
				$useonlinepayment = 0;
				if (getDolGlobalString('PDF_SHOW_LINK_TO_ONLINE_PAYMENT')) {
					// Show online payment link
					// The list can be complete by the hook 'doValidatePayment' executed inside getValidOnlinePaymentMethods()
					include_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
					$validpaymentmethod = getValidOnlinePaymentMethods('');
					$useonlinepayment = count($validpaymentmethod);
				}


				if ($object->status != Facture::STATUS_DRAFT && $useonlinepayment) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
					global $langs;

					$langs->loadLangs(array('payment', 'stripe'));
					$servicename = $langs->transnoentities('Online');
					$paiement_url = getOnlinePaymentUrl(0, 'invoice', $object->ref, 0, '', 0);
					$linktopay = $langs->trans("ToOfferALinkForOnlinePayment", $servicename).' <a href="'.$paiement_url.'">'.$outputlangs->transnoentities("ClickHere").'</a>';

					$pdf->SetXY($this->marge_gauche, $posy);
					$pdf->writeHTMLCell($posxend - $this->marge_gauche, 5, null, null, dol_htmlentitiesbr($linktopay), 0, 1);

					$posy = $pdf->GetY() + 1;
				}
			}

			// Show payment mode CHQ
			if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'CHQ') {
				// If payment mode unregulated or payment mode forced to CHQ
				if (getDolGlobalInt('FACTURE_CHQ_NUMBER')) {
					$diffsizetitle = getDolGlobalInt('PDF_DIFFSIZE_TITLE', 3);

					if (getDolGlobalInt('FACTURE_CHQ_NUMBER') > 0) {
						$account = new Account($this->db);
						$account->fetch(getDolGlobalInt('FACTURE_CHQ_NUMBER'));

						$pdf->SetXY($this->marge_gauche, $posy);
						$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
						$pdf->MultiCell($posxend - $this->marge_gauche, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo', $account->owner_name), 0, 'L', false);
						$posy = $pdf->GetY() + 1;

						if (!getDolGlobalString('MAIN_PDF_HIDE_CHQ_ADDRESS')) {
							$pdf->SetXY($this->marge_gauche, $posy);
							$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
							$pdf->MultiCell($posxend - $this->marge_gauche, 3, $outputlangs->convToOutputCharset($account->owner_address), 0, 'L', false);
							$posy = $pdf->GetY() + 2;
						}
					}
					if (getDolGlobalInt('FACTURE_CHQ_NUMBER') == -1) {
						$pdf->SetXY($this->marge_gauche, $posy);
						$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
						$pdf->MultiCell($posxend - $this->marge_gauche, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo', $this->emetteur->name), 0, 'L', false);
						$posy = $pdf->GetY() + 1;

						if (!getDolGlobalString('MAIN_PDF_HIDE_CHQ_ADDRESS')) {
							$pdf->SetXY($this->marge_gauche, $posy);
							$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
							$pdf->MultiCell($posxend - $this->marge_gauche, 3, $outputlangs->convToOutputCharset($this->emetteur->getFullAddress()), 0, 'L', false);
							$posy = $pdf->GetY() + 2;
						}
					}
				}
			}

			// If payment mode not forced or forced to VIR, show payment with BAN
			if ((empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR') && (int) $object->type !== Facture::TYPE_DEPOSIT) {	// NavOnline: no bank details on the deposit invoice (already paid)
				if ($object->fk_account > 0 || $object->fk_bank > 0 || getDolGlobalInt('FACTURE_RIB_NUMBER')) {
					$bankid = ($object->fk_account <= 0 ? getDolGlobalInt('FACTURE_RIB_NUMBER') : (int) $object->fk_account);
					if ($object->fk_bank > 0) {
						$bankid = $object->fk_bank; // For backward compatibility when object->fk_account is forced with object->fk_bank
					}
					$account = new Account($this->db);
					$account->fetch($bankid);

					$curx = $this->marge_gauche;
					$cury = $posy;

					$posy = navonlinePdfBankBlock($pdf, $outputlangs, $curx, $cury, $account, $posxend - $curx);	// NavOnline (typography): framed bank block

					$posy += 2;

					// SHOW EPC QR CODE at bottom, but only if unpaid amount exists
					if ((getDolGlobalString('INVOICE_ADD_EPC_QR_CODE') == 'bottom') && ($object->getRemainToPay() > 0)) {
						$qrPosX = $this->marge_gauche + 5;
						$qrPosY = $posy;
						$qrCodeColor = array('25', '25', '25');
						$styleQr = array(
							'border' => false,
							'padding' => 0,
							'fgcolor' => $qrCodeColor,
							'bgcolor' => false, //array(255,255,255)
							'module_width' => 1, // width of a single module in points
							'module_height' => 1 // height of a single module in points
						);

						$EPCQrCodeString = $object->buildEPCQrCodeString();
						$pdf->write2DBarcode($EPCQrCodeString, 'QRCODE,M', $qrPosX, $qrPosY, 20, 20, $styleQr, 'N');

						$pdf->SetXY($qrPosX + 25, $qrPosY + 5);
						$pdf->SetFont('', '', $default_font_size - 5);
						$pdf->MultiCell(30, 3, $outputlangs->transnoentitiesnoconv("INVOICE_ADD_EPC_QR_CODEPay"), 0, 'L', false);
						$posy = $pdf->GetY() + 2;
					}

					// Show structured communication
					if (getDolGlobalString('INVOICE_PAYMENT_ENABLE_STRUCTURED_COMMUNICATION')) {
						include_once DOL_DOCUMENT_ROOT.'/core/lib/functions_be.lib.php';
						$invoicePaymentKey = dolBECalculateStructuredCommunication($object->ref, $object->type);

						$pdf->MultiCell(100, 3, $outputlangs->transnoentities('StructuredCommunication').": " . $outputlangs->convToOutputCharset($invoicePaymentKey), 0, 'L', false);
					}
				}
			}
		}

		return $posy;
	}


	/**
	 * NavOnline: per VAT rate (and 0% case code) totals of the invoice lines, in the invoice currency.
	 * Deposit deductions reduce the base of their own rate, as in the NAV summaryByVatRate.
	 *
	 * @param	Facture	$object	Invoice
	 * @return	array<string,array{label:string,rate:float,ht:float,tva:float,ttc:float}>
	 */
	protected function navVatSummaryRows($object)
	{
		global $conf;

		$foreign = (isModEnabled('multicurrency') && !empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency);
		$rows = array();
		foreach ($object->lines as $line) {
			if ($line->product_type == 9 || (defined('SUBTOTALS_SPECIAL_CODE') && $line->special_code == SUBTOTALS_SPECIAL_CODE)) {
				continue;
			}
			$ht = (float) ($foreign ? $line->multicurrency_total_ht : $line->total_ht);
			$tva = (float) ($foreign ? $line->multicurrency_total_tva : $line->total_tva);
			$ttc = (float) ($foreign ? $line->multicurrency_total_ttc : $line->total_ttc);
			if ($ht == 0.0 && $tva == 0.0) {
				continue;
			}
			$rate = (float) price2num(preg_replace('/\s*\(.*\)/', '', (string) $line->tva_tx));
			$code = (string) $line->vat_src_code;
			$key = sprintf('%08.3f', $rate).'|'.$code;
			if (!isset($rows[$key])) {
				$rows[$key] = array('label' => vatrate((string) $rate, true).($code !== '' ? ' ('.$code.')' : ''), 'code' => ($rate == 0.0 ? $code : ''), 'rate' => $rate, 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0);
			}
			$rows[$key]['ht'] += $ht;
			$rows[$key]['tva'] += $tva;
			$rows[$key]['ttc'] += $ttc;
		}
		krsort($rows);
		return $rows;
	}

	/** NavOnline: retention of title clause, per invoice language (title + text) */
	const NAV_RETENTION_TEXT = array(
		'hu' => array('Tulajdonjog-fenntartás', 'Az áru tulajdonjoga az Eladót illeti, és nem száll át a Vevőre mindaddig, amíg az áru vételárát teljes egészében meg nem fizették, és az az Eladóhoz be nem érkezett. Attól függetlenül, hogy az áru tulajdonjoga az Eladónál marad, az áruval kapcsolatos kárveszély az áru átadásakor átszáll a Vevőre.'),
		'en' => array('Title retention clause', 'Title to the Goods shall remain vested in the Seller and shall not pass to the Buyer until the purchase price for the Goods has been paid in full and received by the Seller. Irrespective of whether title to the Goods remains vested in the Seller, risk in the Goods shall pass to the Buyer upon delivery.'),
		'de' => array('Eigentumsvorbehalt', 'Das Eigentum an der Ware verbleibt beim Verkäufer und geht erst auf den Käufer über, wenn der Kaufpreis für die Ware vollständig bezahlt und beim Verkäufer eingegangen ist. Unabhängig davon, ob das Eigentum an der Ware beim Verkäufer verbleibt, geht die Gefahr mit der Lieferung auf den Käufer über.'),
	);

	/**
	 * NavOnline: retention of title on a customer invoice whose payment term has it switched on (setup, default 15D,
	 * 30D, 45D, 60D), in
	 * the invoice language only (hu / en / de, else English). Standard invoices only (not deposit or credit notes).
	 * Not repeated when the public note already has it.
	 *
	 * @param Facture   $object      Invoice
	 * @param Translate $outputlangs Langs
	 * @param string    $note        Note shown so far
	 * @return string
	 */
	protected function navRetentionClause($object, $outputlangs, $note)
	{
		if ((int) $object->type !== Facture::TYPE_STANDARD) {
			return '';
		}
		$code = (string) ($object->cond_reglement_code ?? '');
		if ($code === '' && !empty($object->cond_reglement_id)) {
			$res = $this->db->query('SELECT code FROM '.$this->db->prefix().'c_payment_term WHERE rowid = '.((int) $object->cond_reglement_id));
			$o = $res ? $this->db->fetch_object($res) : null;
			$code = $o ? (string) $o->code : '';
		}
		if (!navonlinePaymentTermHas('retention', $code)) {	// NavOnline setup > Számlazáradékok
			return '';
		}
		$plain = mb_strtolower(html_entity_decode(strip_tags($note), ENT_QUOTES, 'UTF-8'));
		foreach (self::NAV_RETENTION_TEXT as $t) {
			if (mb_strpos($plain, mb_strtolower(mb_substr($t[1], 0, 40))) !== false) {
				return '';
			}
		}
		$lang = strtolower(substr((string) $outputlangs->defaultlang, 0, 2));
		$t = self::NAV_RETENTION_TEXT[isset(self::NAV_RETENTION_TEXT[$lang]) ? $lang : 'en'];
		return $t[0]."\n".$t[1];
	}

	/** NavOnline: preferential origin declaration (Annex VI of the EU free trade agreements), per invoice language */
	const NAV_ORIGIN_TEXT = array(
		'hu' => 'A jelen okmányban szereplő termékek exportőre kijelenti, hogy eltérő egyértelmű jelzés hiányában ezek a termékek kedvezményes EU származásúak.',
		'en' => 'The exporter of the products covered by this document declares that, except where otherwise clearly indicated, these products are of European Union preferential origin.',
		'de' => 'Der Ausführer der Waren, auf die sich dieses Handelspapier bezieht, erklärt, dass diese Waren, soweit nicht anders angegeben, präferenzbegünstigte CE Ursprungswaren sind.',
	);

	/** NavOnline: default NAVONLINE_ORIGIN_COUNTRIES: EFTA, Faroe Islands, Euro-Mediterranean partners, Western Balkans,
	 * Georgia, Moldova, Ukraine, United Kingdom (check against Access2Markets when the agreements change) */
	const NAV_ORIGIN_COUNTRIES = 'CH,LI,IS,NO,FO,DZ,EG,IL,JO,LB,MA,PS,TN,AL,BA,ME,MK,RS,XK,GE,MD,UA,GB';

	/**
	 * NavOnline: origin declaration on an export invoice: one with an EAM line (only EAM invoices, by the user's rule
	 * 2026-11-26; not on KBAET invoices) whose total
	 * is at most NAVONLINE_ORIGIN_LIMIT_EUR (default 6000 EUR; above it an EUR.1 certificate is needed). In the
	 * invoice language only, with place and invoice date, a line for the signature and under it the signer's name
	 * (NAVONLINE_ORIGIN_SIGNER). Not repeated when the public note already has a declaration.
	 *
	 * @param Facture   $object      Invoice
	 * @param Translate $outputlangs Langs
	 * @param string    $note        Note shown so far
	 * @return string Plain text, '' when not applicable
	 */
	protected function navOriginDeclaration($object, $outputlangs, $note)
	{
		global $conf, $mysoc;

		if (!in_array((int) $object->type, array(Facture::TYPE_STANDARD), true) || empty($object->id)) {
			return '';
		}
		// Only an export (EAM) invoice (user's rule 2026-11-26; not configurable)
		$res = $this->db->query('SELECT 1 FROM '.$this->db->prefix().'facturedet WHERE fk_facture = '.((int) $object->id)." AND UPPER(vat_src_code) = 'EAM' AND tva_tx = 0 LIMIT 1");
		if (!$res || !$this->db->num_rows($res)) {
			return '';
		}
		// Only to a country with an EU preferential agreement whose origin declaration is this text (user 2026-11-26):
		// NAVONLINE_ORIGIN_COUNTRIES (comma separated ISO codes), default the pan-Euro-Mediterranean partners, the
		// Western Balkans, Georgia, Moldova, Ukraine and the United Kingdom
		$countries = array_filter(array_map('trim', explode(',', strtoupper(getDolGlobalString('NAVONLINE_ORIGIN_COUNTRIES', self::NAV_ORIGIN_COUNTRIES)))));
		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (!is_object($object->thirdparty) || !in_array(strtoupper((string) $object->thirdparty->country_code), $countries, true)) {
			return '';
		}
		// Value in EUR: the EUR total of an EUR invoice, else the HUF total at the EUR rate of the invoice date
		if (strtoupper((string) $object->multicurrency_code) === 'EUR' && isModEnabled('multicurrency')) {
			$eur = abs($this->navGoodsValue($object, true));	// NavOnline: the goods, deposits not deducted
		} else {
			require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
			$rate = MultiCurrency::getIdAndTxFromCode($this->db, 'EUR', $object->date);
			$tx = (float) ($rate[1] ?? 0);	// EUR per 1 HUF
			if ($tx <= 0) {
				return '';
			}
			$eur = abs($this->navGoodsValue($object, false)) * $tx;
		}
		if ($eur > (float) getDolGlobalString('NAVONLINE_ORIGIN_LIMIT_EUR', '6000') + 0.004) {
			return '';
		}
		$plain = mb_strtolower(html_entity_decode(strip_tags($note), ENT_QUOTES, 'UTF-8'));
		foreach (self::NAV_ORIGIN_TEXT as $t) {
			if (mb_strpos($plain, mb_strtolower(mb_substr($t, 0, 40))) !== false) {
				return '';
			}
		}
		$lang = strtolower(substr((string) $outputlangs->defaultlang, 0, 2));
		$text = self::NAV_ORIGIN_TEXT[isset(self::NAV_ORIGIN_TEXT[$lang]) ? $lang : 'en'];
		$place = getDolGlobalString('NAVONLINE_ORIGIN_PLACE', (string) $mysoc->town);
		$date = $object->date;
		if ($lang === 'hu') {
			$when = $place.', '.dol_print_date($date, '%Y.%m.%d.');
		} elseif ($lang === 'de') {
			$when = $place.', den '.dol_print_date($date, '%d.%m.%Y');
		} else {
			$when = $place.', '.dol_print_date($date, '%d.%m.%Y');
		}
		$signer = getDolGlobalString('NAVONLINE_ORIGIN_SIGNER');
		// The declaration is valid only signed: a line for the signature, the signer's name under it
		return $text."\n".$when."\n\n\n".'______________________________'."\n".$signer;
	}

	/** @var string[] Gross / net weight lines of an export invoice */
	private $navWeight = array();

	/**
	 * NavOnline: weights of an export (EAM) invoice, from its shipments: linked to the invoice, else the validated
	 * shipments of its orders; without shipment only the net weight of the invoice lines.
	 *
	 * @param Facture    $object         Invoice
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return string[]
	 */
	protected function navInvoiceWeights($object, $outputlangs, $outputlangsbis = null)
	{
		if (empty($object->id) || !navonlineHasEamLine($this->db, 'facturedet', 'fk_facture', (int) $object->id)) {
			return array();
		}
		$p = $this->db->prefix();
		$ids = array();
		$res = $this->db->query('SELECT fk_source FROM '.$p."element_element WHERE sourcetype = 'shipping' AND targettype = 'facture' AND fk_target = ".((int) $object->id));
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ids[] = (int) $o->fk_source;
		}
		if (!$ids) {
			$sql = 'SELECT ee2.fk_target FROM '.$p.'element_element ee JOIN '.$p."element_element ee2 ON ee2.sourcetype = 'commande' AND ee2.fk_source = ee.fk_source AND ee2.targettype = 'shipping'";
			$sql .= ' JOIN '.$p.'expedition e ON e.rowid = ee2.fk_target AND e.fk_statut > 0';
			$sql .= " WHERE ee.sourcetype = 'commande' AND ee.targettype = 'facture' AND ee.fk_target = ".((int) $object->id);
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				$ids[] = (int) $o->fk_target;
			}
		}
		if ($ids) {
			return navonlineWeightLines(navonlineShipmentWeights($this->db, array_unique($ids)), null, $outputlangs, $outputlangsbis);
		}
		$net = 0.0;
		$res = $this->db->query('SELECT d.qty, pr.weight, pr.weight_units FROM '.$p.'facturedet d JOIN '.$p.'product pr ON pr.rowid = d.fk_product WHERE d.fk_facture = '.((int) $object->id).' AND pr.weight > 0');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$net += (float) $o->qty * navonlineKg((float) $o->weight, (int) $o->weight_units);
		}
		return navonlineWeightLines(null, $net, $outputlangs, $outputlangsbis);
	}

	/**
	 * NavOnline: "Fordított adózás (FAD)" (+ second language), the label of a domestic reverse charge VAT row.
	 *
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return string
	 */
	protected function navReverseChargeLabel($outputlangs, $outputlangsbis = null)
	{
		$t = $outputlangs->transnoentities('NavOnlinePdfReverseCharge');
		if (is_object($outputlangsbis) && $outputlangsbis->transnoentities('NavOnlinePdfReverseCharge') !== $t) {
			$t .= ' / '.$outputlangsbis->transnoentities('NavOnlinePdfReverseCharge');
		}
		return $t.' (FAD)';
	}

	/**
	 * NavOnline: short name of a 0% NAV case (KBAET -> "EU-s termékértékesítés"), in the document language and the
	 * second language; '' when no name is defined.
	 *
	 * @param string     $code           VAT code
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return string
	 */
	protected function navVatCaseName($code, $outputlangs, $outputlangsbis = null)
	{
		$key = 'NavOnlineVatName'.strtoupper($code);
		$out = array();
		foreach (array($outputlangs, $outputlangsbis) as $l) {
			if (is_object($l)) {
				$t = $l->transnoentities($key);
				if ($t !== $key && !in_array($t, $out, true)) {
					$out[] = $t;
				}
			}
		}
		return implode(' / ', $out);
	}

	/**
	 * NavOnline: height (mm) to reserve for the VAT summary table.
	 *
	 * @param	Facture	$object	Invoice
	 * @return	int
	 */
	protected function navVatSummaryHeight($object)
	{
		if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			return 0;
		}
		global $conf;
		$n = count($this->navVatSummaryRows($object));
		$foreign = (isModEnabled('multicurrency') && !empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency);
		return $n ? (8 * ($n + 1) + 18 + ($foreign ? 9 : 0)) : 0;	// rows (a 0% rate + its case name line) + total, header up to 4 lines, gap
	}

	/**
	 * NavOnline: VAT summary table (Áfa tv. 169. § i)-k)): rate | tax base | VAT | gross, plus a total row.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	Facture		$object			Invoice
	 * @param	float		$posy			Y
	 * @param	Translate	$outputlangs	Langs
	 * @param	?Translate	$outputlangsbis	Second language
	 * @return	float						New Y
	 */
	protected function drawNavVatSummary(&$pdf, $object, $posy, $outputlangs, $outputlangsbis)
	{
		$this->navSummaryEndY = $posy;	// reset for this document (the summary may be skipped)
		global $conf;

		if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			return $posy;
		}
		$rows = $this->navVatSummaryRows($object);
		if (empty($rows)) {
			return $posy;
		}
		$sign = (isset($object->type) && $object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) ? -1 : 1;
		$foreign = (isModEnabled('multicurrency') && !empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency);
		$currency = $foreign ? $object->multicurrency_code : $conf->currency;
		$fmt = function ($v) use ($outputlangs, $currency, $sign) {
			return price(price2num($sign * $v, 'MT'), 0, $outputlangs, 1, -1, 'MT', $currency);
		};
		$label = function ($key) use ($outputlangs, $outputlangsbis) {
			return $outputlangs->transnoentities($key).(is_object($outputlangsbis) ? "\n".$outputlangsbis->transnoentities($key) : '');
		};

		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$x0 = $this->marge_gauche;
		$total = ($this->page_largeur < 210 ? 90 : 100);
		$w = array($total - 75, 25, 25, 25);	// 25 + 3 x 25 mm on A4
		$hl = 4;
		navonlinePdfThinLine($pdf);	// NavOnline (typography)
		navonlinePdfColor($pdf, 'head', 'fill');
		navonlinePdfColor($pdf, 'text');
		// One table row; its height follows the cell that wraps most (header, bilingual labels)
		$row = function ($cells, $bold, $fill, $size = 8.5) use (&$pdf, &$posy, $x0, $w, $hl, $default_font_size) {
			$pdf->SetFont('', ($bold ? 'B' : ''), $size);
			$h = $hl;
			foreach ($cells as $k => $t) {
				$h = max($h, $pdf->getStringHeight($w[$k], $t, false, true, '', 1) + 0.5);
			}
			$x = $x0;
			foreach ($cells as $k => $t) {
				$pdf->MultiCell($w[$k], $h, $t, 1, ($k ? 'R' : 'L'), $fill, 0, $x, $posy, true, 0, false, true, 0, 'M');
				$x += $w[$k];
			}
			$posy += $h;
		};

		$row(array($label('NavOnlineVatSumRate'), $label('NavOnlineVatSumBase'), $label('NavOnlineVatSumVat'), $label('NavOnlineVatSumGross')), true, true, 8);
		$sum = array('ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0);
		foreach ($rows as $r) {
			// Domestic reverse charge has no rate: "Fordított adózás (FAD)" instead of "0% (FAD)"
			$isFad = (strtoupper($r['code']) === 'FAD');
			$row(array($isFad ? $this->navReverseChargeLabel($outputlangs, $outputlangsbis) : $r['label'], $fmt($r['ht']), $fmt($r['tva']), $fmt($r['ttc'])), false, false);
			// Name of the 0% case on its own line under the rate, across the table: "(EU-s termékértékesítés)"
			$name = ($r['code'] !== '' && !$isFad) ? $this->navVatCaseName($r['code'], $outputlangs, $outputlangsbis) : '';
			if ($name !== '') {
				$pdf->SetFont('', 'I', 8);
				navonlinePdfColor($pdf, 'muted');
				$hn = max($hl, $pdf->getStringHeight($total, '('.$name.')') + 0.5);
				$pdf->MultiCell($total, $hn, '('.$name.')', 1, 'L', false, 0, $x0, $posy, true, 0, false, true, 0, 'M');
				navonlinePdfColor($pdf, 'text');
				$posy += $hn;
			}
			$sum['ht'] += $r['ht'];
			$sum['tva'] += $r['tva'];
			$sum['ttc'] += $r['ttc'];
		}
		$row(array($label('NavOnlineVatSumTotal'), $fmt($sum['ht']), $fmt($sum['tva']), $fmt($sum['ttc'])), true, true);

		// Foreign-currency invoice: total VAT in HUF (Áfa tv. 80. §), from the HUF amounts stored on the lines (a deposit
		// deduction keeps the HUF VAT of the deposit, as reported to NAV), and under it the exchange rate:
		//   VAT amount / ÁFA tartalom: 30 157,11 HUF
		//   (1 EUR = 372,3100 HUF)
		if ($foreign && (float) $object->multicurrency_tx > 0) {
			$hufTotal = 0.0;
			$perRate = array();
			foreach ($object->lines as $vl) {
				if ($vl->product_type == 9) {
					continue;
				}
				$hufTotal += (float) $vl->total_tva;
				if ((float) $vl->total_tva != 0.0) {
					$rk = vatrate((string) price2num(preg_replace('/\s*\(.*\)/', '', (string) $vl->tva_tx)), true);
					$perRate[$rk] = ($perRate[$rk] ?? 0) + (float) $vl->total_tva;
				}
			}
			$hufFmt = function ($v) use ($outputlangs, $sign) {
				return price(price2num($sign * $v, 'MT'), 0, $outputlangs, 1, -1, 'MT').' HUF';
			};
			$line1 = $outputlangs->transnoentities('NavOnlinePdfVatHufTotal').(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities('NavOnlinePdfVatHufTotal') : '').': '.$hufFmt($hufTotal);
			if (count($perRate) > 1) {	// several rates: the HUF VAT of each too
				$parts = array();
				foreach ($perRate as $rk => $v) {
					$parts[] = $rk.': '.$hufFmt($v);
				}
				$line1 .= ' ('.implode(', ', $parts).')';
			}
			$dec = $outputlangs->transnoentitiesnoconv('SeparatorDecimal');
			$rate = number_format(1 / (float) $object->multicurrency_tx, 4, $dec, $outputlangs->transnoentitiesnoconv('SeparatorThousand'));
			$line2 = '(1 '.$object->multicurrency_code.' = '.$rate.' HUF)';
			$posy += 1.5;
			$pdf->SetFont('', '', 8.5);	// NavOnline (typography): HUF VAT 8.5 pt regular, rate 8 pt grey
			$pdf->SetXY($x0, $posy);
			$pdf->MultiCell($total, 3.8, $line1, 0, 'L');
			$posy = $pdf->GetY();
			$pdf->SetFont('', '', 8);
			navonlinePdfColor($pdf, 'muted');
			$pdf->SetXY($x0, $posy);
			$pdf->MultiCell($total, 3.5, $line2, 0, 'L');
			navonlinePdfColor($pdf, 'text');
			$posy = $pdf->GetY();
		}

		navonlinePdfLineReset($pdf);
		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->SetXY($x0, $posy);
		$this->navSummaryEndY = $posy;
		return $posy + 4;
	}

	/** @var float Y under the VAT summary block (with the HUF VAT and exchange rate lines) */
	private $navSummaryEndY = 0;

	/**
	 * NavOnline: payment terms with the stamp switched on (setup, default RECEP, PT_ORDER, PT_DELIVERY, PT_5050,
	 * DEP30PCTDEL): customer invoices
	 * (not credit notes) get a bold green "Paid / Fizetve" (invoice language + second language) under all the totals.
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Facture    $object         Invoice
	 * @param float      $posy           Y under the totals
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return float Y after it (unchanged when not printed)
	 */
	protected function drawNavPaidStamp(&$pdf, $object, $posy, $outputlangs, $outputlangsbis)
	{
		if (!$this->navPaidTerm($object)) {
			return $posy;
		}
		$outputlangs->load('navonline@navonline');
		$text = $outputlangs->transnoentities('NavOnlinePdfPaid');
		if (is_object($outputlangsbis)) {
			$outputlangsbis->load('navonline@navonline');
			$bis = $outputlangsbis->transnoentities('NavOnlinePdfPaid');
			if ($bis !== $text) {
				$text .= ' / '.$bis;
			}
		}
		$x = is_object($outputlangsbis) ? 112 : 120;
		$w = $this->page_largeur - $this->marge_droite - $x;
		$y = $posy + 2;
		$pdf->SetFont('', 'B', pdf_getPDFFontSize($outputlangs) + 3);
		$pdf->SetTextColor(0, 128, 0);
		$pdf->SetDrawColor(0, 128, 0);
		$pdf->SetLineWidth(0.5);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell($w, 7, $outputlangs->convToOutputCharset($text), 1, 'C', false);
		$pdf->SetLineWidth(0.2);
		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetTextColor(11, 18, 21);
		$pdf->SetFont('', '', pdf_getPDFFontSize($outputlangs) - 1);
		return $y + 7;
	}

	/**
	 * @param Facture $object Invoice
	 * @return bool A customer invoice (not a credit note) with a payment term paid at once
	 */
	protected function navPaidTerm($object)
	{
		if ((int) $object->type === Facture::TYPE_CREDIT_NOTE) {
			return false;
		}
		if ((int) $object->type === Facture::TYPE_DEPOSIT) {	// issued after the advance payment arrived
			return true;
		}
		$code = (string) ($object->cond_reglement_code ?? '');
		if ($code === '' && !empty($object->cond_reglement_id)) {
			$res = $this->db->query('SELECT code FROM '.$this->db->prefix().'c_payment_term WHERE rowid = '.((int) $object->cond_reglement_id));
			$o = $res ? $this->db->fetch_object($res) : null;
			$code = $o ? (string) $o->code : '';
		}
		return navonlinePaymentTermHas('paid', $code);	// NavOnline setup > Számlazáradékok
	}

	/**
	 *  Show total to pay
	 *
	 *  @param	TCPDF		$pdf            Object PDF
	 *	@param  Facture		$object         Object invoice
	 *	@param  float		$deja_regle     Amount already paid (in the currency of invoice)
	 *	@param	float		$posy			Position depart
	 *	@param	Translate	$outputlangs	Object langs
	 *  @param  ?Translate	$outputlangsbis	Object lang for output bis
	 *	@return float						Position pour suite
	 */
	protected function drawTotalTable(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis)
	{
		global $conf, $mysoc, $hookmanager;

		$sign = 1;
		if (isset($object->type) && $object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) {
			$sign = -1;
		}

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$tab2_top = $posy;
		$tab2_hl = 4.4;
		$pdf->SetFont('', '', 9);	// NavOnline (typography): subtotals 9 pt regular

		// Total table
		$col1x = 120;
		$col2x = 170;
		if (is_object($outputlangsbis)) {
			// NavOnline: bilingual labels ("USt. gesamt / ÁFA összesen 0% (KBAET)") need a wider label column, else they wrap
			// and the next total row overlaps them. The left info block ends at 110 mm; 24 mm stays for the amounts.
			$col1x = 111;
			$col2x = 180;	// NavOnline (typography): 9 pt bilingual labels need 69 mm
		}
		if ($this->page_largeur < 210) { // To work with US executive format
			$col1x -= 15;
			$col2x -= 10;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;

		// Add trigger to allow to edit $object
		$parameters = array(
			'object' => &$object,
			'outputlangs' => $outputlangs,
		);
		$hookmanager->executeHooks('beforePercentCalculation', $parameters, $this); // Note that $object may have been modified by hook

		// overall percentage of advancement
		$percent = 0;
		$i = 0;
		foreach ($object->lines as $line) {
			if ($line->product_type != 9) {
				$percent += $line->situation_percent;
				$i++;
			}
		}

		if (!empty($i)) {
			$avancementGlobal = $percent / $i;
		} else {
			$avancementGlobal = 0;
		}

		$object->fetchPreviousNextSituationInvoice();
		$TPreviousIncoice = $object->tab_previous_situation_invoice;

		$total_a_payer = 0;
		$total_a_payer_ttc = 0;
		foreach ($TPreviousIncoice as &$fac) {
			$total_a_payer += $fac->total_ht;
			$total_a_payer_ttc += $fac->total_ttc;
		}
		$total_a_payer += $object->total_ht;
		$total_a_payer_ttc += $object->total_ttc;

		if (!empty($avancementGlobal)) {
			$total_a_payer = $total_a_payer * 100 / $avancementGlobal;
			$total_a_payer_ttc = $total_a_payer_ttc * 100 / $avancementGlobal;
		} else {
			$total_a_payer = 0;
			$total_a_payer_ttc = 0;
		}

		$i = 1;
		if (!empty($TPreviousIncoice)) {
			$pdf->setY($tab2_top);
			$posy = $pdf->GetY();

			foreach ($TPreviousIncoice as &$fac) {
				if ($posy > $this->page_hauteur - 4 - $this->heightforfooter) {
					$this->_pagefoot($pdf, $object, $outputlangs, 1, $this->getHeightForQRInvoice($pdf->getPage(), $object, $outputlangs));
					$pdf->addPage();
					if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
						$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
						$pdf->setY($this->tab_top_newpage);
					} else {
						$pdf->setY($this->marge_haute);
					}
					$posy = $pdf->GetY();
				}

				// Cumulate preceding VAT
				$index++;
				$pdf->SetFillColor(255, 255, 255);
				$pdf->SetXY($col1x, $posy);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("PDFSituationTitle", (string) $fac->situation_counter).' '.$outputlangs->transnoentities("TotalHT"), 0, 'L', true);

				$pdf->SetXY($col2x, $posy);

				$facSign = '';
				if ($i > 1) {
					$facSign = $fac->total_ht >= 0 ? '+' : '';
				}

				$displayAmount = ' '.$facSign.' '.price($fac->total_ht, 0, $outputlangs);

				$pdf->MultiCell($largcol2, $tab2_hl, $displayAmount, 0, 'R', true);

				$i++;
				$posy += $tab2_hl;

				$pdf->setY($posy);
			}

			// Display current total
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $posy);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("PDFSituationTitle", (string) $object->situation_counter).' '.$outputlangs->transnoentities("TotalHT"), 0, 'L', true);

			$pdf->SetXY($col2x, $posy);
			$facSign = '';
			if ($i > 1) {
				$facSign = $object->total_ht >= 0 ? '+' : ''; // management of a particular customer case
			}

			if ($fac->type === Facture::TYPE_CREDIT_NOTE) {
				$facSign = '-';
			}


			$displayAmount = ' '.$facSign.' '.price($object->total_ht, 0, $outputlangs);
			$pdf->MultiCell($largcol2, $tab2_hl, $displayAmount, 0, 'R', true);

			$posy += $tab2_hl;

			// Display all total
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $posy);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("SituationTotalProgress", (string) $avancementGlobal), 0, 'L', true);

			$pdf->SetXY($col2x, $posy);
			$pdf->MultiCell($largcol2, $tab2_hl, price($total_a_payer * $avancementGlobal / 100, 0, $outputlangs), 0, 'R', true);
			$pdf->SetFont('', '', $default_font_size - 2);

			$posy += $tab2_hl;

			if ($posy > $this->page_hauteur - 4 - $this->heightforfooter) {
				$pdf->addPage();
				if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
					$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
					$pdf->setY($this->tab_top_newpage);
				} else {
					$pdf->setY($this->marge_haute);
				}

				$posy = $pdf->GetY();
			}

			$tab2_top = $posy;
			$index = 0;

			$tab2_top += 3;
		}


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

		// Show total discount only if there is some discount on lines
		if ($total_discount_on_lines > 0 && !$object->isSituationInvoice()) {
			// Show discount except on credit note type invoices
			if ($this->showAmountBeforeDiscount && $object->type != 2) {
				$pdf->SetFillColor(255, 255, 255);
				$pdf->SetXY($col1x, $tab2_top);
				$navSubKey = $this->navDeductionLines ? 'NavOnlinePdfSubtotalNet' : 'TotalHTBeforeDiscount';	// NavOnline: no "discount" on a final invoice
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities($navSubKey).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities($navSubKey) : ''), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top);

				$total_before_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? ($object->multicurrency_total_ht + $multicurrency_total_discount_on_lines) : ($object->total_ht + $total_discount_on_lines));
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_before_discount_to_show, 0, $outputlangs), 0, 'R', true);

				$index++;
			}

			// Show total NET before discount except on credit note type invoices
			if ($this->showDiscountAmount && $object->type != 2) {
				$pdf->SetFillColor(255, 255, 255);
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl);
				// NavOnline: the deducted advance payment is no discount
				$navDiscKey = $this->navDeductionLines ? 'NavOnlinePdfDepositDeducted' : 'TotalDiscount';
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities($navDiscKey).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities($navDiscKey) : ''), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl);

				$total_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $multicurrency_total_discount_on_lines : $total_discount_on_lines);
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_discount_to_show, 0, $outputlangs), 0, 'R', true);

				$index++;
			}
		}

		// Total HT
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities(!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT') ? "TotalHT" : "Total").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities(!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT') ? "TotalHT" : "Total") : ''), 0, 'L', true);

		$total_ht = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, price($sign * $total_ht, 0, $outputlangs), 0, 'R', true);

		if (getDolGlobalInt('PDF_INVOICE_SHOW_VAT_ANALYSIS')) {
			$index++;
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalVAT"), 0, 'L', true);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($sign * $object->total_tva), 0, 'R', true);
		}

		// Show VAT by rates and total
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;
		$total_ttc_origin = $object->total_ttc;

		$this->atleastoneratenotnull = 0;


		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = false;
			if (!empty($this->tva_array) && count($this->tva_array) == 1 ) {
				$tva_el = reset($this->tva_array);
				if ($tva_el['vatrate'] == '0.000' && is_float($tva_el['amount'])) $tvaisnull = true;
			}
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// Nothing to do
			} else {
				// Show VAT lines
				// NavOnline: domestic reverse charge rows not as "ÁFA összesen 0% (FAD)" but "Fordított adózás (FAD)"
				$navAllVat = $this->tva_array;
				$navFadVat = array();
				foreach ($this->tva_array as $k => $v) {
					if (strtoupper((string) ($v['vatcode'] ?? '')) === 'FAD') {
						$navFadVat[$k] = $v;
						unset($this->tva_array[$k]);
					}
				}
				$this->navWriteVATArray($this, $index, $pdf, $outputlangs, $outputlangsbis, $object, $col1x, $col2x, $largcol2, $tab2_top, $tab2_hl);	// NavOnline copy
				$this->tva_array = $navAllVat;
				foreach ($navFadVat as $v) {
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $this->navReverseChargeLabel($outputlangs, $outputlangsbis), 0, 'L', true);	// one line: "Fordított adózás (FAD)"
					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price(price2num($v['amount'], 'MT'), 0, $outputlangs), 0, 'R', true);
				}

				// NavOnline: the HUF VAT of a foreign-currency invoice is printed under the VAT summary (drawNavVatSummary)

				// Revenue stamp
				if (price2num($object->revenuestamp, 'MT') != 0) {
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RevenueStamp").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("RevenueStamp", $mysoc->country_code) : ''), $useborder, 'L', true);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($sign * $object->revenuestamp), $useborder, 'R', true);
				}

				// Total TTC (NavOnline typography: a #EDF2FA box with a 0.75 pt blue top line and 3 mm padding, the label
				// 10 pt bold, bilingual on two lines, the amount 12 pt bold, both in the accent colour)
				$index++;
				// NavOnline: foreign-currency invoice (not a credit note): "Fizetendő végösszeg" with the currency code
				$navForeignTotal = (isModEnabled('multicurrency') && !empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency && (int) $object->type !== 2);
				$navTtcKey = ((int) $object->type === Facture::TYPE_CREDIT_NOTE) ? (navonlineCreditNoteOperation($this->db, $object) === 'STORNO' ? 'NavOnlinePdfTotalCancellation' : 'NavOnlinePdfTotalCorrection') : ($navForeignTotal ? 'NavOnlinePdfTotalToPay' : 'TotalTTC');	// NavOnline: no negative "total" on a corrective / cancellation invoice
				if (!isModEnabled("multicurrency") || $object->multicurrency_tx == 1 || getDolGlobalInt('MULTICURRENCY_SHOW_ALSO_MAIN_CURRENCY_ON_PDF') == 0) {
					$navLabel = $outputlangs->transnoentities($navTtcKey).(is_object($outputlangsbis) ? "\n".$outputlangsbis->transnoentities($navTtcKey) : '');
					$navAmount = price($sign * $total_ttc, 0, $outputlangs).($navForeignTotal ? ' '.$object->multicurrency_code : '');
					$navRight = $this->page_largeur - $this->marge_droite;
					$navTop = $tab2_top + $tab2_hl * $index + 1;
					$navCp = $pdf->getCellPaddings();
					$pdf->setCellPaddings(0, 0, 0, 0);
					$pdf->SetFont('', 'B', 12);
					$navAw = $pdf->GetStringWidth($navAmount) + 1;
					$pdf->SetFont('', 'B', 10);
					$navLw = $navRight - $col1x - 3 - $navAw - 2;
					$navH = max($pdf->getStringHeight($navLw, $navLabel), 5.5) + 6;
					$pdf->Rect($col1x, $navTop, $navRight - $col1x, $navH, 'F', array(), NAVONLINE_PDF_COLORS['total']);
					$pdf->SetLineStyle(array('width' => 0.2646, 'dash' => 0, 'color' => NAVONLINE_PDF_COLORS['accent']));
					$pdf->line($col1x, $navTop, $navRight, $navTop);
					navonlinePdfLineReset($pdf);
					navonlinePdfColor($pdf, 'accent');
					$pdf->MultiCell($navLw, 0, $navLabel, 0, 'L', false, 1, $col1x + 1.5, $navTop + 3);
					$pdf->SetFont('', 'B', 12);
					$pdf->MultiCell($navAw, 0, $navAmount, 0, 'R', false, 1, $navRight - 1.5 - $navAw, $navTop + ($navH - 5.3) / 2);
					$pdf->setCellPaddings($navCp['L'], $navCp['T'], $navCp['R'], $navCp['B']);
					$pdf->SetFont('', '', 9);
					navonlinePdfColor($pdf, 'text');
					$index += (int) ceil(($navH + 1.5) / $tab2_hl) - 1;
				} else {
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->SetTextColor(49, 84, 161);
				$pdf->SetFillColor(237, 242, 250);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities($navTtcKey).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities($navTtcKey) : ''), $useborder, 'L', true);

				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				if (false) {
				} else {
					$pdf->MultiCell($largcol2, $tab2_hl, price($sign * $total_ttc, 0, $outputlangs), $useborder, 'R', true);

					//$pdf->writeHTMLCell($largcol2, $tab2_hl, null, null, '<font size="-2">('.price($sign * $object->total_ttc, 0, $outputlangs, 1, -1, 'MT', $mysoc->currency_code).')</font> &nbsp; '.price($sign * $total_ttc, 0, $outputlangs), $useborder, 1, true, true, 'R');
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->SetTextColor(49, 84, 161);
					$pdf->SetFillColor(237, 242, 250);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalTTC") : '').' ('.$outputlangs->getCurrencySymbol($mysoc->currency_code).')', $useborder, 'L', true);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($sign * $total_ttc_origin, 0, $outputlangs, 1, -1, -1, $mysoc->currency_code), $useborder, 'L', true);
				}
				}

				// Retained warranty
				if ($object->displayRetainedWarranty()) {
					$pdf->SetTextColor(40, 40, 40);
					$pdf->SetFillColor(255, 255, 255);

					$retainedWarranty = $object->getRetainedWarrantyAmount('MT');
					$billedWithRetainedWarranty = $object->total_ttc - $retainedWarranty;

					// Billed - retained warranty
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("ToPayOn", dol_print_date($object->date_lim_reglement, 'day')), $useborder, 'L', true);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($billedWithRetainedWarranty), $useborder, 'R', true);

					// retained warranty
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

					$retainedWarrantyToPayOn = $outputlangs->transnoentities("RetainedWarranty").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("RetainedWarranty") : '').' ('.$object->retained_warranty.'%)';
					$retainedWarrantyToPayOn .= !empty($object->retained_warranty_date_limit) ? ' '.$outputlangs->transnoentities("toPayOn", dol_print_date($object->retained_warranty_date_limit, 'day')) : '';

					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $retainedWarrantyToPayOn, $useborder, 'L', true);
					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($retainedWarranty), $useborder, 'R', true);
				}
			}
		}

		$pdf->SetTextColor(11, 18, 21);

		$creditnoteamount = $object->getSumCreditNotesUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0); // Warning, this also include excess received
		$depositsamount = $object->getSumDepositsUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);

		$resteapayer = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!isModEnabled("multicurrency") || $object->multicurrency_tx == 1 || getDolGlobalInt('MULTICURRENCY_SHOW_ALSO_MAIN_CURRENCY_ON_PDF') == 0) {
			// Not used in this case, initialized to avoid CI warnings
			$deja_regle_origin = 0;
			$creditnoteamount_origin = 0;
			$depositsamount_origin = 0;
			$resteapayer_origin = 0;
		} else {
			$deja_regle_origin = $object->getSommePaiement(0);
			$creditnoteamount_origin = $object->getSumCreditNotesUsed(0); // Warning, this also include excess received
			$depositsamount_origin = $object->getSumDepositsUsed(0);
			$resteapayer_origin = price2num($total_ttc_origin - $deja_regle_origin - $creditnoteamount_origin - $depositsamount_origin, 'MT');
		}
		if (!empty($object->paye)) {
			$resteapayer = 0;
			$resteapayer_origin = 0;
		}

		pdfWriteAlreadyPaid($this, $index, $pdf, $outputlangs, $outputlangsbis, $object, $col1x, $col2x, $largcol2, $tab2_top, $tab2_hl, $deja_regle, $creditnoteamount, $depositsamount, $resteapayer, $resteapayer_origin);

		// NavOnline: cash rounding (utánvét): amount payable rounded to 5 Ft, the invoice totals stay exact
		if (function_exists('navonlineCashRoundingApplies') && empty($object->paye) && $resteapayer > 0 && navonlineCashRoundingApplies($object)) {
			$outputlangs->load('navonline@navonline');
			if (is_object($outputlangsbis)) {
				$outputlangsbis->load('navonline@navonline');
			}
			$index++;
			$pdf->SetFont('', 'B', 9);
			$pdf->SetTextColor(49, 84, 161);
			$pdf->SetFillColor(237, 242, 250);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities('NavOnlineCashRoundedToPay').(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities('NavOnlineCashRoundedToPay') : ''), $useborder, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price(navonlineCashRound($resteapayer), 0, $outputlangs), $useborder, 'R', true);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(11, 18, 21);
		}

		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->SetTextColor(11, 18, 21);

		$parameters = array('pdf' => &$pdf, 'object' => &$object, 'outputlangs' => $outputlangs, 'index' => &$index, 'posy' => $posy);

		$reshook = $hookmanager->executeHooks('afterPDFTotalTable', $parameters, $this); // Note that $action and $object may have been modified by some hooks
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
		}

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}

	/** NavOnline: intra-Community notice, printed for KBAET / EUFAD37 lines to an EU buyer with a VAT number */
	const NAV_EU_NOTICE = array(
		'en' => 'Tax-exempt intra-Community supply of goods or intra-Community service. The recipient of the intra-Community supply or the intra-Community service is obliged to report the VAT in the country of destination.',
		'de' => 'Steuerfreie innergemeinschaftliche Warenlieferung und/oder innergemeinschaftliche sonstige Leistungen. Der Erwerber der innergemeinschaftlichen Warenlieferung und/oder innergemeinschaftlichen sonstigen Leistungen ist verpflichtet, die Mehrwertsteuer im Bestimmungsland zu erklären.',
	);

	/**
	 * NavOnline: VAT legend of the invoice: the Hungarian legal reference of every 0% NAV case used on the invoice
	 * (text from the VAT dictionary note; "CODE: " before it only when there are several), plus the
	 * intra-Community notice in the invoice language only (German / English; none on a Hungarian invoice) when a
	 * KBAET / EUFAD37 line is invoiced to a buyer in another EU member state who has a community VAT number.
	 *
	 * @param Facture   $object      Invoice
	 * @param Translate $outputlangs Output language
	 * @return string Plain text (newline separated), '' if nothing to show
	 */
	private function navVatLegend($object, $outputlangs)
	{
		global $mysoc;

		$codes = array();
		foreach ((array) $object->lines as $line) {
			if ((float) $line->tva_tx == 0 && !empty($line->vat_src_code) && (int) $line->special_code !== SUBTOTALS_SPECIAL_CODE) {
				$codes[strtoupper(trim((string) $line->vat_src_code))] = 1;
			}
		}
		if (!$codes) {
			return '';
		}

		$out = array();
		$sql = 'SELECT t.code, t.note FROM '.$this->db->prefix().'c_tva t';
		$sql .= ' JOIN '.$this->db->prefix().'c_country c ON c.rowid = t.fk_pays';
		$sql .= " WHERE c.code = '".$this->db->escape((string) $mysoc->country_code)."' AND t.taux = 0 AND t.active = 1";
		$sql .= " AND t.code IN ('".implode("','", array_map(array($this->db, 'escape'), array_keys($codes)))."')";
		$sql .= ' AND t.entity IN ('.getEntity('c_tva').')';
		$resql = $this->db->query($sql);
		$notes = array();
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$notes[strtoupper($o->code)] = trim((string) $o->note);
		}
		// Hungarian legal reference only (the VAT dictionary note), never translated; "CODE: " only when the invoice has
		// several 0% cases, to tell them apart
		foreach (array_keys($codes) as $code) {
			$text = !empty($notes[$code]) ? $notes[$code] : $code;
			// Reverse charge (Áfa tv. 169. § n): the words "Fordított adózás" must be on the invoice, whatever the note says
			if (in_array($code, array('FAD', 'EUFAD37', 'EUFADE'), true) && mb_stripos($text, 'fordított adózás') === false) {
				$text = 'Fordított adózás - '.$text;
			}
			$out[] = (count($codes) > 1 ? $code.': ' : '').$text;
		}

		$soc = $object->thirdparty;
		$euBuyer = is_object($soc) && trim((string) $soc->tva_intra) !== '' && strtoupper((string) $soc->country_code) !== strtoupper((string) $mysoc->country_code)
			&& function_exists('isInEEC') && isInEEC($soc);
		// The intra-Community notice in the invoice language only (not in the second language); a Hungarian invoice
		// has the Hungarian clause above, so no notice
		$doclang = strtolower(substr((string) $outputlangs->defaultlang, 0, 2));
		if ($euBuyer && $doclang !== 'hu' && (isset($codes['KBAET']) || isset($codes['EUFAD37']))) {
			$out[] = self::NAV_EU_NOTICE[isset(self::NAV_EU_NOTICE[$doclang]) ? $doclang : 'en'];
		}

		return implode("\n", $out);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return list of active generation modules
	 *
	 *  @param  DoliDB  	$db                 Database handler
	 *  @param  int<0,max>	$maxfilenamelength  Max length of value to show
	 *  @return string[]|int<-1,0>				List of templates
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		// phpcs:enable
		return parent::liste_modeles($db, $maxfilenamelength); // TODO: Change the autogenerated stub
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   Show table for lines
	 *
	 *   @param		TCPDF			$pdf     		Object PDF
	 *   @param		float|int		$tab_top		Top position of table
	 *   @param		float|int		$tab_height		Height of table (rectangle)
	 *   @param		int				$nexY			Y (not used)
	 *   @param		Translate		$outputlangs	Langs object
	 *   @param		int<-1,1>		$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int<0,1>		$hidebottom		Hide bottom bar of array
	 *   @param		string|Facture	$object			Invoice object (or currency code - deprecated use)
	 *   @param		?Translate		$outputlangsbis	Langs object bis
	 *   @return	void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $object = '', $outputlangsbis = null)
	{
		// Force to disable hidetop and hidebottom
		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		if ($object instanceOf Facture) {
			$currency = $object->multicurrency_code;
		} else {
			$currency = $object;
		}
		if (empty($currency)) {
			$currency = getDolCurrency();
		}

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(11, 18, 21);
		$pdf->SetFont('', '', $default_font_size - 2);

		if (empty($hidetop)) {
			// Show category of operations
			if (getDolGlobalInt('INVOICE_CATEGORY_OF_OPERATION') == 1 && $this->categoryOfOperation >= 0) {
				$categoryOfOperations = $outputlangs->transnoentities("MentionCategoryOfOperations") . ': ' . $outputlangs->transnoentities("MentionCategoryOfOperations" . $this->categoryOfOperation);
				$pdf->SetXY($this->marge_gauche, $tab_top - 4);
				$pdf->MultiCell(($pdf->GetStringWidth($categoryOfOperations)) + 4, 2, $categoryOfOperations);
			}

			$titre = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
			if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
				// NavOnline: one line, the labels bilingual: "Currency / Pénznem: EUR"
				$outputlangsbis->load('navonline@navonline');
				$titre = $outputlangs->transnoentities('NavOnlinePdfCurrencyLabel').' / '.$outputlangsbis->transnoentities('NavOnlinePdfCurrencyLabel').': '.$currency;
			}
			// NavOnline: no exchange rate here: a foreign-currency invoice shows it once, under the HUF VAT amount of the
			// VAT summary ("(1 EUR = 372,3100 HUF)", drawNavVatSummary)

			$pdf->SetFont('', '', 8);	// NavOnline (typography): 8 pt grey
			navonlinePdfColor($pdf, 'muted');
			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);
			navonlinePdfColor($pdf, 'text');
			// Header background #F2F4F7
			$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, 'F', array(), NAVONLINE_PDF_COLORS['head']);

			// MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
				$pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, $this->corner_radius, '1001', 'F', array(), explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
			}
		}

		navonlinePdfThinLine($pdf);	// NavOnline (typography): 0.35 pt lines, #D6DCE5
		$pdf->SetFont('', '', $default_font_size - 1);

		// Output Rect
		$this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, $hidetop, $hidebottom, 'D'); // Rect takes a length in 3rd parameter and 4th parameter


		$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);

		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight); // line takes a position y in 2nd parameter and 4th parameter
		}
		navonlinePdfLineReset($pdf);
	}

	/**
	 * Copy of CommonDocGenerator::pdfTabTitles() (Dolibarr 24.0): diff it with the core after an upgrade. NavOnline
	 * changes (typography): titles 8.5 pt bold, the second language under them 7.5 pt regular; the column separators
	 * only in the title row (no vertical grid in the body).
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param float     $tab_top     Top of the table
	 * @param float     $tab_height  Height of the table
	 * @param Translate $outputlangs Langs
	 * @param int       $hidetop     1 = no titles
	 * @return float Height of the title row
	 */
	public function pdfTabTitles(&$pdf, $tab_top, $tab_height, $outputlangs, $hidetop = 0)
	{
		global $hookmanager, $outputlangsbis;

		$seps = array();
		foreach ($this->cols as $colKey => $colDef) {
			$parameters = array('colKey' => $colKey, 'pdf' => $pdf, 'outputlangs' => $outputlangs, 'tab_top' => $tab_top, 'tab_height' => $tab_height, 'hidetop' => $hidetop);
			$reshook = $hookmanager->executeHooks('pdfTabTitles', $parameters, $this);
			if ($reshook < 0) {
				setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
			} elseif (empty($reshook)) {
				if (!$this->getColumnStatus($colKey)) {
					continue;
				}
				$label = !empty($colDef['title']['label']) ? $colDef['title']['label'] : $outputlangs->transnoentities($colDef['title']['textkey']);
				if (!empty($colDef['border-left']) && isset($colDef['xStartPos'])) {
					$seps[] = $colDef['xStartPos'];
				}
				if (empty($hidetop)) {
					$cur = $pdf->getCellPaddings();
					$pad = $colDef['title']['padding'];
					$bis = (is_object($outputlangsbis) && trim($label) && $outputlangsbis->transnoentities($colDef['title']['textkey']) !== $label);
					$pdf->setCellPaddings($pad[3], max(1.2, (float) $pad[0]), $pad[1], $bis ? 0.2 : max(1.2, (float) $pad[2]));
					$align = $colDef['title']['align'] ?? '';
					navonlinePdfColor($pdf, 'text');
					$fsT = 8.5;	// a title too wide for its column shrinks (down to 7 pt) instead of touching the frame
					$pdf->SetFont('', 'B', $fsT);
					while ($fsT > 7 && $pdf->GetStringWidth($label) > $colDef['width'] - (float) $pad[1] - (float) $pad[3] - 0.6) {
						$fsT -= 0.25;
						$pdf->SetFont('', 'B', $fsT);
					}
					$pdf->SetXY($colDef['xStartPos'], $tab_top);
					$pdf->MultiCell($colDef['width'], 2, $label, '', $align);
					if ($bis) {
						$pdf->setCellPaddings($pad[3], 0, $pad[1], 1.2);
						$pdf->SetFont('', '', 7.5);
						$pdf->SetXY($colDef['xStartPos'], $pdf->GetY());
						$pdf->MultiCell($colDef['width'], 2, $outputlangsbis->transnoentities($colDef['title']['textkey']), '', $align);
					}
					$this->tabTitleHeight = max($pdf->GetY() - $tab_top, $this->tabTitleHeight);
					$pdf->setCellPaddings($cur['L'], $cur['T'], $cur['R'], $cur['B']);
				}
			}
		}
		if (empty($hidetop)) {
			foreach ($seps as $x) {
				$pdf->line($x, $tab_top, $x, $tab_top + $this->tabTitleHeight);
			}
		}
		$pdf->SetFont('', '', 9.5);
		return $this->tabTitleHeight;
	}

	/**
	 * NavOnline (typography): Incoterms, the weights of an export invoice and the payment terms in the right column
	 * under the totals, as label / value pairs (label 8.5 pt, the important value 9 pt bold, the weights regular).
	 *
	 * @param TCPDF      $pdf            PDF
	 * @param Facture    $object         Invoice
	 * @param float      $posy           Y
	 * @param Translate  $outputlangs    Langs
	 * @param ?Translate $outputlangsbis Second language
	 * @return float Y under them
	 */
	private function navDrawTerms(&$pdf, $object, $posy, $outputlangs, $outputlangsbis)
	{
		$x = is_object($outputlangsbis) ? 111 : 120;
		if ($this->page_largeur < 210) {
			$x -= 15;
		}
		$w = $this->page_largeur - $this->marge_droite - $x;
		$start = $posy;
		$pair = function ($text, $bold) use (&$pdf, &$posy, $x, $w) {
			$k = strpos((string) $text, ': ');
			$posy = navonlinePdfInfoPair($pdf, $x, $posy, $w, $k === false ? '' : substr($text, 0, $k + 1), $k === false ? $text : substr($text, $k + 2), $bold) + 0.6;
		};
		$inco = navonlineIncotermsText($this->db, $object, $outputlangs, $outputlangsbis);
		if ($inco !== '') {
			$pair($inco, true);
		}
		foreach ((array) $this->navWeight as $wl) {
			$pair($wl, false);
		}
		if ($object->type != 2 && $object->type != 3 && $object->cond_reglement_code && !navonlineFinalInvoiceNoPayment($this->db, $object)) {
			$lib = ($outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) != 'PaymentCondition'.$object->cond_reglement_code) ? $outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) : $outputlangs->convToOutputCharset($object->cond_reglement_doc ? $object->cond_reglement_doc : $object->cond_reglement_label);
			$lib = str_replace('\n', "\n", $lib);
			$label = $outputlangs->transnoentities("PaymentConditions").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("PaymentConditions") : '').':';
			if ($posy > $start) {
				$posy += 1;
			}
			$posy = navonlinePdfInfoPair($pdf, $x, $posy, $w, $label, $lib, true) + 0.6;
		}
		return $posy;
	}

	/** @var array<int,int> Lines deducting a deposit invoice (final invoice), by line index */
	private $navDeductionLines = array();

	/** @var array<int,string> HS codes by product id */
	private $navHsCache = array();

	/**
	 * NavOnline (column proposal 2026-11-26, AluLogic_eredeti_tervezet_oszlopjavaslat_jelolve.pdf), every invoice (the
	 * deposit invoice without HS code and unit): # (7 mm) | Description (the rest, 73 mm) | HS code (23 mm) | Qty (14) | Unit (9) | U.P. (net)
	 * (24) | Sales tax (15) | Total (excl. tax) (25).
	 *
	 * @return void
	 */
	private function navColumnLayout($deposit = false)
	{
		$pad = array(0.5, 0.5, 0.5, 0.5);
		$this->cols['position']['status'] = true;
		$this->cols['position']['width'] = 7.0;
		$this->cols['position']['content']['align'] = 'L';
		$this->cols['position']['content']['padding'] = array(1.0, 0.5, 1.0, 1.0);
		$this->cols['desc']['border-left'] = true;
		$this->cols['hscode'] = array(
			'rank' => 0,
			'width' => 23.0,
			'status' => true,
			'title' => array('textkey' => 'NavOnlinePdfHsTitle', 'align' => 'C', 'padding' => $pad),
			'content' => array('align' => 'C', 'padding' => array(1.0, 0.5, 1.0, 0.5)),
			'border-left' => true,
		);
		$ranks = array('position' => 1, 'desc' => 2, 'photo' => 3, 'hscode' => 4, 'qty' => 5, 'unit' => 6, 'progress' => 7, 'subprice' => 8, 'discount' => 9, 'vat' => 10, 'totalexcltax' => 11, 'totalincltax' => 12);
		foreach ($ranks as $k => $r) {
			if (isset($this->cols[$k])) {
				$this->cols[$k]['rank'] = $r;
			}
		}
		foreach (array('qty' => 14.0, 'unit' => 9.0, 'subprice' => 24.0, 'vat' => 15.0, 'totalexcltax' => 25.0) as $k => $wd) {
			if (isset($this->cols[$k])) {
				$this->cols[$k]['width'] = max($wd, $k === 'subprice' ? (float) $this->cols[$k]['width'] : 0);
			}
		}
		if ($deposit) {	// the advance payment line is no product: no HS code, no unit
			$this->cols['hscode']['status'] = false;
			$this->cols['unit']['status'] = false;
		}
		$this->cols['unit']['content']['align'] = 'C';
		if (isset($this->cols['unit']['title'])) {
			$this->cols['unit']['title']['textkey'] = 'NavOnlinePdfUnitTitleShort';	// 9 mm: "Einh." (de)
		}
	}

	/**
	 * Deposit invoice: the advance payment line "(DEPOSIT) (50%) - SO2611-0006" as "Előleg az SO2611-0006 rendelésre
	 * (50%)" / "Advance payment for order SO2611-0006 (50%)" / "Anzahlung auf Auftrag SO2611-0006 (50%)", for the PDF
	 * only (the stored line stays as it is).
	 *
	 * @param Facture   $object      Invoice
	 * @param Translate $outputlangs Langs
	 * @return array<int,string> The original texts, by line index
	 */
	private function navDepositLineTexts($object, $outputlangs)
	{
		$saved = array();
		$this->navDeductionLines = array();
		if ((int) $object->type !== Facture::TYPE_DEPOSIT) {
			// Final invoice: the deducted deposit "(DEPOSIT)" (core: "Előleg beszámítása a(z) ID… előlegszámlából") with
			// the date of the deposit invoice: "Levont előleg, előlegszámla: ID2611-0001 (2026.11.26.)"
			require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
			$outputlangs->load('navonline@navonline');
			foreach ($object->lines as $k => $line) {
				if ((string) $line->desc !== '(DEPOSIT)' || empty($line->fk_remise_except)) {
					continue;
				}
				$discount = new DiscountAbsolute($this->db);
				if ($discount->fetch((int) $line->fk_remise_except) <= 0 || empty($discount->fk_facture_source)) {
					continue;
				}
				$src = new Facture($this->db);
				if ($src->fetch((int) $discount->fk_facture_source) <= 0) {
					continue;
				}
				$saved[$k] = $line->desc;
				$this->navDeductionLines[$k] = 1;
				$object->lines[$k]->desc = $outputlangs->transnoentities('NavOnlinePdfDepositDeductionLine', $outputlangs->convToOutputCharset($src->ref), dol_print_date($src->date, 'day', false, $outputlangs, true));
			}
			return $saved;
		}
		$outputlangs->load('navonline@navonline');
		foreach ($object->lines as $k => $line) {
			if (!empty($line->fk_product) || !preg_match('/^\(DEPOSIT\)\s*\(([^)]*)\)(?:\s*-\s*(\S+))?\s*$/', (string) $line->desc, $m)) {
				continue;
			}
			$ref = $m[2] ?? '';
			$saved[$k] = $line->desc;
			if ($ref !== '') {
				$text = $outputlangs->transnoentities('NavOnlinePdfDepositLine', $ref, $m[1]);
				$text = str_replace('a(z) '.$ref, navonlineHuArticle($ref).' '.$ref, $text);
			} else {
				$text = $outputlangs->transnoentities('NavOnlinePdfDepositLineNoRef', $m[1]);
			}
			$object->lines[$k]->desc = $text;
		}
		return $saved;
	}

	/**
	 * Value of the goods of an invoice (incl. tax) for the 6000 EUR origin declaration limit: the lines without the
	 * deducted deposits / credit notes (fk_remise_except), so a final invoice counts the whole consignment.
	 *
	 * @param Facture $object        Invoice
	 * @param bool    $multicurrency In the invoice currency
	 * @return float
	 */
	private function navGoodsValue($object, $multicurrency)
	{
		$sum = 0.0;
		foreach ((array) $object->lines as $line) {
			if (!empty($line->fk_remise_except) || (defined('SUBTOTALS_SPECIAL_CODE') && $line->special_code == SUBTOTALS_SPECIAL_CODE)) {
				continue;
			}
			$sum += (float) ($multicurrency ? $line->multicurrency_total_ttc : $line->total_ttc);
		}
		return $sum;
	}

	/**
	 * The legal clauses, each a paragraph, the same 2 mm space before every one but the first (8.5 pt).
	 *
	 * @param TCPDF $pdf PDF
	 * @param float $x   X
	 * @param float $y   Y
	 * @param float $w   Width
	 * @return float Y under them
	 */
	private function navDrawClauses($pdf, $x, $y, $w)
	{
		$pdf->SetFont('', '', 8.5);
		navonlinePdfColor($pdf, 'text');
		foreach ($this->navClausesParts as $k => $part) {
			$pdf->writeHTMLCell($w, 0, $x, $y + ($k ? 2 : 0), $part, 0, 1);
			$y = $pdf->GetY();
		}
		return $y;
	}

	/**
	 * HS code of an invoice line: the customs code of its product ('' without one).
	 *
	 * @param FactureLigne $line Line
	 * @return string
	 */
	private function navHsCode($line)
	{
		$id = (int) ($line->fk_product ?? 0);
		if ($id <= 0) {
			return '';
		}
		if (!isset($this->navHsCache[$id])) {
			$res = $this->db->query('SELECT customcode FROM '.$this->db->prefix().'product WHERE rowid = '.$id);
			$o = $res ? $this->db->fetch_object($res) : null;
			$this->navHsCache[$id] = $o ? trim((string) $o->customcode) : '';
		}
		return dol_escape_htmltag($this->navHsCache[$id]);
	}

	/**
	 * NavOnline (typography): text of a partner box: the name 10.5 pt bold, the address lines 9.5 pt, the "Label: value"
	 * lines (phone, e-mail, web, tax numbers) 8.5 pt; an empty line is a 1.5 mm gap.
	 *
	 * @param TCPDF  $pdf  PDF
	 * @param float  $x    X
	 * @param float  $y    Y
	 * @param float  $w    Width
	 * @param string $name Name ('' = none)
	 * @param string $text Address block (pdf_build_address)
	 * @param string $ltr  Alignment
	 * @return float Y under the text
	 */
	private function navPartnerText($pdf, $x, $y, $w, $name, $text, $ltr)
	{
		navonlinePdfColor($pdf, 'text');
		if (trim((string) $name) !== '') {
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell($w, 0, $name, 0, $ltr, false, 1, $x, $y);
			$y = $pdf->GetY() + 0.6;
		}
		foreach (explode("\n", (string) $text) as $l) {
			if (trim($l) === '') {
				$y += 1.5;
				continue;
			}
			$pdf->SetFont('', '', strpos($l, ': ') !== false ? 8.5 : 9.5);
			$pdf->MultiCell($w, 0, $l, 0, $ltr, false, 1, $x, $y);
			$y = $pdf->GetY();
		}
		return $y;
	}

	/**
	 * The customer's order number of the order linked to the invoice: one linked order, else one linked shipment (its
	 * own customer reference, else that of its order); '' when none, several, or not shown on the PDF (as the core:
	 * canDisplayLinkedObjectInPDF, e.g. another third party).
	 *
	 * @param Facture $object Invoice
	 * @return string
	 */
	private function navLinkedOrderCustomerRef($object)
	{
		$object->fetchObjectLinked();
		$orders = $object->linkedObjects['commande'] ?? array();
		if (count($orders) == 1) {
			$order = reset($orders);
			return canDisplayLinkedObjectInPDF($object, $order) ? trim((string) ($order->ref_client ?? '')) : '';
		}
		if (count($orders) > 1) {
			return '';
		}
		$shipments = $object->linkedObjects['shipping'] ?? array();
		if (count($shipments) != 1) {
			return '';
		}
		$shipment = reset($shipments);
		if (!canDisplayLinkedObjectInPDF($object, $shipment)) {
			return '';
		}
		$ref = trim((string) ($shipment->ref_customer ?? ''));
		if ($ref === '' && !empty($shipment->origin_id) && in_array((string) ($shipment->origin_type ?? $shipment->origin ?? ''), array('commande', 'order'), true)) {
			require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
			$order = new Commande($this->db);
			if ($order->fetch((int) $shipment->origin_id) > 0) {
				$ref = trim((string) $order->ref_client);
			}
		}
		return $ref;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *  Show top header of page. This include the logo, ref and address blocks
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Facture		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes (usually set to 1 for first page, and 0 for next pages)
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @param  ?Translate	$outputlangsbis	Object lang for output bis
	 *  @return	array{top_shift:float,shipp_shift:float}	Top shift of linked object lines
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null)
	{
		// phpcs:enable
		global $conf, $langs;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "bills", "propal", "companies"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 110;

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

		$pdf->SetFont('', 'B', $default_font_size + 3);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$subtitle = "";
		$title = $outputlangs->transnoentities("PdfInvoiceTitle");
		// NavOnline: a standard invoice with deposit deductions is a "Végszámla"
		$navFinal = (bool) navonlineFinalInvoiceDeposits($this->db, $object);
		if ($navFinal) {
			$title = $outputlangs->transnoentities('NavOnlinePdfTitleFinal');
		}
		if ($object->type == 1) {
			$title = $outputlangs->transnoentities("InvoiceReplacement");
		}
		// NavOnline: a credit note is a "Módosító számla" (MODIFY) or an "Érvénytelenítő számla" (STORNO), as reported to NAV
		$navCreditOp = ($object->type == 2) ? navonlineCreditNoteOperation($this->db, $object) : '';
		$navCreditKey = ($navCreditOp === 'STORNO') ? 'NavOnlinePdfTitleStorno' : 'NavOnlinePdfTitleModify';
		if ($object->type == 2) {
			$title = $outputlangs->transnoentities($navCreditKey);
		}
		if ($object->type == 3) {
			$title = $outputlangs->transnoentities("NavOnlinePdfTitleDeposit");
		}
		if ($this->situationinvoice) {
			$outputlangs->loadLangs(array("other"));
			$title = $outputlangs->transnoentities("PDFInvoiceSituation") . " " . $outputlangs->transnoentities("NumberingShort") . $object->situation_counter . " -";
			$subtitle = $outputlangs->transnoentities("PDFSituationTitle", (string) $object->situation_counter);
		}
		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
			$title .= ' - ';
			if ($object->type == 0) {
				if ($this->situationinvoice) {
					$title .= $outputlangsbis->transnoentities("PDFInvoiceSituation");
				}
				$title .= $outputlangsbis->transnoentities($navFinal ? 'NavOnlinePdfTitleFinal' : 'PdfInvoiceTitle');
			} elseif ($object->type == 1) {
				$title .= $outputlangsbis->transnoentities("InvoiceReplacement");
			} elseif ($object->type == 2) {
				$title .= $outputlangsbis->transnoentities($navCreditKey);
			} elseif ($object->type == 3) {
				$title .= $outputlangsbis->transnoentities("NavOnlinePdfTitleDeposit");
			} elseif ($object->type == 4) {
				$title .= $outputlangsbis->transnoentities("InvoiceProForma");
			}
		}
		// NavOnline (typography): the number on its own line under the title
		$navNumber = $outputlangs->convToOutputCharset($object->ref);
		if ($object->status == $object::STATUS_DRAFT) {
			$navNumber .= ' - '.$outputlangs->transnoentities("NotValidated");
		}

		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines); on a bilingual
		// invoice every label is bilingual, not only the dates
		$posy = navonlinePdfHeadTitle2($pdf, $posx, $posy, $w, $title, $navNumber, $object->status == $object::STATUS_DRAFT);

		if (!empty($subtitle)) {
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->SetXY($posx, $posy);
			$pdf->MultiCell($w, 6, $subtitle, '', 'R');
			$posy = $pdf->GetY();
		}

		$pdf->SetFont('', '', $default_font_size - 2);
		pdfWriteAdditionnalTitle($pdf, $outputlangs, $this->page_hauteur, $object, $w, $posx, $posy);

		$navBis = (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) ? $outputlangsbis : null;
		$lb = function ($key) use ($outputlangs, $navBis) {
			$label = $outputlangs->transnoentities($key);
			if ($navBis) {
				$bis = $navBis->transnoentities($key);
				if ($bis !== $label) {
					$label .= ' - '.$bis;
				}
			}
			return $label;
		};
		$navLines = array();
		$navEmph = array();
		// The customer's order number on its own line: the invoice's own, else that of the linked order (directly, or
		// through the linked shipment); the linked order line then shows it no more in brackets
		$navRefCustomer = trim((string) ($object->ref_customer ?? ''));
		if ($navRefCustomer === '') {
			$navRefCustomer = $this->navLinkedOrderCustomerRef($object);
		}
		if ($navRefCustomer !== '') {
			$navLines[] = $lb("RefCustomer").": ".dol_trunc($outputlangs->convToOutputCharset($navRefCustomer), 65);
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT_TITLE')) {
			$object->fetchProject();
			if (!empty($object->project->title)) {
				$navLines[] = $lb("Project").": ".dol_trunc($object->project->title, 50);
			}
		}
		if (getDolGlobalString('PDF_SHOW_PROJECT')) {
			$object->fetchProject();
			if (!empty($object->project->ref)) {
				$outputlangs->load("projects");
				$navLines[] = $lb("RefProject").": ".$object->project->ref;
			}
		}
		$objectidnext = $object->getIdReplacingInvoice('validated');
		if ($object->type == 0 && $objectidnext) {
			$objectreplacing = new Facture($this->db);
			$objectreplacing->fetch($objectidnext);
			$navLines[] = $lb("ReplacementByInvoice").': '.$outputlangs->convToOutputCharset($objectreplacing->ref);
		}
		if ($object->type == 1) {
			$objectreplaced = new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);
			$navLines[] = $lb("ReplacementInvoice").': '.$outputlangs->convToOutputCharset($objectreplaced->ref);
		}
		if ($object->type == 2 && !empty($object->fk_facture_source)) {
			$objectreplaced = new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);
			$navLines[] = $lb($navCreditOp === 'STORNO' ? 'NavOnlinePdfOriginalStorno' : 'NavOnlinePdfOriginalModify').': '.$outputlangs->convToOutputCharset($objectreplaced->ref);
			$navLines[] = $lb($navCreditOp === 'STORNO' ? 'NavOnlinePdfOriginalDateStorno' : 'NavOnlinePdfOriginalDateModify').': '.dol_print_date($objectreplaced->date, 'day', false, $outputlangs, true);	// NavOnline: the date on its own line
		}
		$navLines[] = $lb("DateInvoice").": ".dol_print_date($object->date, "day", false, $outputlangs, true);
		if (getDolGlobalString('INVOICE_POINTOFTAX_DATE')) {
			$datepointoftax = !empty($object->date_pointoftax) ? dol_print_date($object->date_pointoftax, "day", false, $outputlangs) : dol_print_date($object->date, "day", false, $outputlangs, true);
			// NavOnline: a cancellation invoice carries the date of supply of the invoice it cancels (as sent to NAV)
			if ($navCreditOp === 'STORNO' && ($navOrig = navonlineStornoDeliveryDate($this->db, $object)) > 0) {
				$datepointoftax = dol_print_date($navOrig, 'day', false, $outputlangs, true);
			}
			$navLines[] = $lb("DatePointOfTax").": ".$datepointoftax;
		}
		if ($object->type != 2 && $object->type != 3 && !navonlineFinalInvoiceNoPayment($this->db, $object)) {	// no due date when nothing is to be paid, nor on the deposit invoice (issued after the payment)
			$navEmph[count($navLines)] = 1;
			$navLines[] = $lb("DateDue").": ".dol_print_date($object->date_lim_reglement, "day", false, $outputlangs, true);
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && $object->thirdparty->code_client) {
			$navLines[] = $lb("CustomerCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_client);
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && $object->thirdparty->code_compta_client) {
			$navLines[] = $lb("CustomerAccountancyCode").": ".$outputlangs->transnoentities((string) $object->thirdparty->code_compta_client);
		}
		if (getDolGlobalString('DOC_SHOW_FIRST_SALES_REP')) {
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$usertmp = new User($this->db);
				$usertmp->fetch($arrayidcontact[0]);
				$navLines[] = $lb("SalesRepresentative").": ".$usertmp->getFullName($langs);
			}
		}
		if (!getDolGlobalString('INVOICE_HIDE_LINKED_OBJECT')) {
			$navLines = array_merge($navLines, navonlineLinkedObjectLines($object, $outputlangs, $navRefCustomer, $navBis));
		}
		$navBottom = navonlinePdfHeadPairs($pdf, $posx, $posy, $w, $navLines, $navEmph);

		$top_shift = navonlinePdfHeadTopShift($navBottom, $this->marge_haute, false);	// next pages: navHeadBottom
		$shipp_shift = 0;
		$this->navHeadBottom = max($this->navHeadBottom, $navBottom);

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';
			// Add internal contact of object if defined
			$arrayidcontact = $object->getIdContact('internal', 'BILLING');
			if (count($arrayidcontact) > 0) {
				$object->fetch_user($arrayidcontact[0]);
				$labelbeforecontactname = ($outputlangs->transnoentities("FromContactName") != 'FromContactName' ? $outputlangs->transnoentities("FromContactName") : $outputlangs->transnoentities("Name"));
				$carac_emetteur .= $labelbeforecontactname." ".$outputlangs->convToOutputCharset($object->user->getFullName($outputlangs));
				$carac_emetteur .= "\n";
			}

			$carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

			// Show sender
			// $posy = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 40 : 42;
			$posy = (getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : $this->marge_haute + 32);
			$posy += $top_shift;
			$posx = $this->marge_gauche;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;

			// NavOnline (typography): white boxes with the same thin frame, 3 mm padding, the height from the content
			$navBoxTop = $posy;
			$navSenderX = $posx;
			$navSenderW = $widthrecbox;
			$pdf->SetFont('', '', 8);
			navonlinePdfColor($pdf, 'muted');
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("NavOnlinePdfSeller").((getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) ? ' - '.$outputlangsbis->transnoentities("NavOnlinePdfSeller") : ''), 0, $ltrdirection, false, 1, $posx, $posy - 5);
			$navSenderEnd = $this->navPartnerText($pdf, $posx + 3, $posy + 3, $widthrecbox - 6, getDolGlobalString('MAIN_PDF_HIDE_SENDER_NAME') ? '' : $outputlangs->convToOutputCharset($this->emetteur->name), $carac_emetteur, $ltrdirection);

			// If BILLING contact defined on invoice, we use it
			$usecontact = false;
			$arrayidcontact = $object->getIdContact('external', 'BILLING');
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

			$carac_client_name = is_object($thirdparty) ? pdfBuildThirdpartyName($thirdparty, $outputlangs) : '';

			$mode = 'target';
			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), ($usecontact ? 1 : 0), $mode, $object);

			// Show recipient
			$widthrecbox = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? $this->marge_haute + 30 : $this->marge_haute + 32;
			$posy += $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			// Show recipient (NavOnline typography: see the sender box)
			$pdf->SetFont('', '', 8);
			navonlinePdfColor($pdf, 'muted');
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("NavOnlinePdfBuyer").((getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) ? ' - '.$outputlangsbis->transnoentities("NavOnlinePdfBuyer") : ''), 0, $ltrdirection, false, 1, $posx, $posy - 5);
			$navRecipEnd = $this->navPartnerText($pdf, $posx + 3, $posy + 3, $widthrecbox - 6, $carac_client_name, $carac_client, $ltrdirection);
			$hautcadre = max(24, max($navSenderEnd, $navRecipEnd) + 3 - $navBoxTop);
			// NavOnline: with a delivery address box (only one that differs from the billing address) each box takes the
			// height of its own content, the delivery box right under the customer box, and the table starts under the
			// lower of the seller and the delivery box; without one both boxes keep the same height
			$navShip = getDolGlobalInt('INVOICE_SHOW_SHIPPING_ADDRESS') && navonlineShippingContact($this->db, $object) !== null;
			$navSenderH = $navShip ? max(24, $navSenderEnd + 3 - $navBoxTop) : $hautcadre;
			$navRecipH = $navShip ? max(24, $navRecipEnd + 3 - $navBoxTop) : $hautcadre;
			navonlinePdfThinLine($pdf);
			if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
				$pdf->RoundedRect($navSenderX, $navBoxTop, $navSenderW, $navSenderH, $this->corner_radius, '1234', 'D');
			}
			if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
				$pdf->RoundedRect($posx, $navBoxTop, $widthrecbox, $navRecipH, $this->corner_radius, '1234', 'D');
			}
			navonlinePdfLineReset($pdf);
			$this->navBoxBottom = $navBoxTop + max($navSenderH, $navRecipH);
			$posy = $navBoxTop;

			// Show shipping/delivery address
			if ($navShip) {
				$navShipH = navonlinePdfShippingAddressBox($pdf, $this->db, $object, $outputlangs, (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) ? $outputlangsbis : null, $this->emetteur, $posx, $navBoxTop + $navRecipH + 6, $widthrecbox);
				$this->navBoxBottom = $navBoxTop + max($navSenderH, $navRecipH + $navShipH);
			}
		}

		$pdf->SetTextColor(11, 18, 21);

		$pagehead = array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift);

		return $pagehead;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   	Show footer of page. Need this->emetteur object
	 *
	 *   	@param	TCPDF		$pdf     			PDF
	 * 		@param	Facture		$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @param	int			$heightforqrinvoice	Height for QR invoices
	 *      @return	int								Return height of bottom margin including footer text
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0, $heightforqrinvoice = 0)
	{
		// phpcs:enable
		$showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
		return navonlinePdfPagefoot($pdf, $outputlangs, 'INVOICE_FREE_TEXT', $this->emetteur, $heightforqrinvoice + $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);	// NavOnline (typography)
	}

	/**
	 *  Define Array Column Field
	 *
	 *  @param	CommonObject	$object    		common object
	 *  @param	Translate		$outputlangs	langs
	 *  @param	int<0,1>		$hidedetails	Do not show line details
	 *  @param	int<0,1>		$hidedesc		Do not show desc
	 *  @param	int<0,1>		$hideref		Do not show ref
	 *  @return	void
	 */
	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $hookmanager;

		// Default field style for content
		$this->defaultContentsFieldsStyle = array(
			'align' => 'R', // R,C,L
			'padding' => array(1, 0.5, 1, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);

		// Default field style for content
		$this->defaultTitlesFieldsStyle = array(
			'align' => 'C', // R,C,L
			'padding' => array(0.5, 0, 0.5, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);

		/*
		 * For example
		 $this->cols['theColKey'] = array(
		 'rank' => $rank, // int : use for ordering columns
		 'width' => 20, // the column width in mm
		 'title' => array(
		 'textkey' => 'yourLangKey', // if there is no label, yourLangKey will be translated to replace label
		 'label' => ' ', // the final label : used fore final generated text
		 'align' => 'L', // text alignment :  R,C,L
		 'padding' => array(0.5,0.5,0.5,0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		 ),
		 'content' => array(
		 'align' => 'L', // text alignment :  R,C,L
		 'padding' => array(0.5,0.5,0.5,0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		 ),
		 );
		 */

		$rank = 0; // do not use negative rank
		$this->cols['position'] = array(
			'rank' => $rank,
			'width' => 10.0,
			'status' => getDolGlobalInt('PDF_SPONGE_ADD_POSITION') ? true : (getDolGlobalInt('PDF_ADD_POSITION') ? true : false),
			'title' => array(
				'textkey' => '#', // use lang key is useful in some case with module
				'align' => 'C',
				// 'textkey' => 'yourLangKey', // if there is no label, yourLangKey will be translated to replace label
				// 'label' => ' ', // the final label
				'padding' => array(0.5, 0.5, 0.5, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
			'content' => array(
				'align' => 'C',
				'padding' => array(1.0, 0.5, 1.0, 1.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
		);

		$rank = 5; // do not use negative rank
		$this->cols['desc'] = array(
			'rank' => $rank,
			'width' => false, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Designation', // use lang key is useful in some case with module
				'align' => 'L',
				// 'textkey' => 'yourLangKey', // if there is no label, yourLangKey will be translated to replace label
				// 'label' => ' ', // the final label
				'padding' => array(0.5, 0.5, 0.5, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
			'content' => array(
				'align' => 'L',
				'padding' => array(1.0, 0.5, 1.0, 1.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
		);

		// Image of product
		$rank += 10;
		$this->cols['photo'] = array(
			'rank' => $rank,
			'width' => (float) getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH', 20), // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'Photo',
				'label' => ' '
			),
			'content' => array(
				'padding' => array(0.0, 0.0, 0.0, 0.0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
			'border-left' => false, // remove left line separator
		);

		if (getDolGlobalString('MAIN_GENERATE_INVOICES_WITH_PICTURE') && !empty($this->atleastonephoto)) {
			$this->cols['photo']['status'] = true;
		}


		$rank += 10;
		$this->cols['vat'] = array(
			'rank' => $rank,
			'status' => false,
			'width' => 16.0, // in mm
			'title' => array(
				'textkey' => 'VAT'
			),
			'border-left' => true, // add left line separator
		);

		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT') && !getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_COLUMN')) {
			$this->cols['vat']['status'] = true;
		}

		$rank += 10;
		$this->cols['subprice'] = array(
			'rank' => $rank,
			'width' => 23.0, // in mm; NavOnline: core 19 mm, a 6-digit HUF price in English format ("123,900.00") wrapped
			'status' => true,
			'title' => array(
				'textkey' => 'PriceUHT'
			),
			'border-left' => true, // add left line separator
		);

		// Adapt dynamically the width of subprice, if text is too long.
		$tmpwidth = 0;
		$nblines = count($object->lines);
		for ($i = 0; $i < $nblines; $i++) {
			$tmpwidth2 = dol_strlen(dol_string_nohtmltag(pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails)));
			$tmpwidth = max($tmpwidth, $tmpwidth2);
		}
		if ($tmpwidth > 10) {
			$this->cols['subprice']['width'] += (2 * ($tmpwidth - 10));
		}

		$rank += 10;
		$this->cols['qty'] = array(
			'rank' => $rank,
			'width' => 16.0, // in mm
			'status' => true,
			'title' => array(
				'textkey' => 'Qty'
			),
			'border-left' => true, // add left line separator
		);

		$rank += 10;
		$this->cols['progress'] = array(
			'rank' => $rank,
			'width' => 19.0, // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'ProgressShort'
			),
			'border-left' => true, // add left line separator
		);

		if ($this->situationinvoice) {
			$this->cols['progress']['status'] = true;
		}

		$rank += 10;
		$this->cols['unit'] = array(
			'rank' => $rank,
			'width' => 11.0, // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'Unit'
			),
			'border-left' => true, // add left line separator
		);
		if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
			$this->cols['unit']['status'] = true;
		}

		$rank += 10;
		$this->cols['discount'] = array(
			'rank' => $rank,
			'width' => 13.0, // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'ReductionShort'
			),
			'border-left' => true, // add left line separator
		);
		if ($this->atleastonediscount) {
			$this->cols['discount']['status'] = true;
		}

		$rank += 1000; // add a big offset to be sure is the last col because default extrafield rank is 100
		$this->cols['totalexcltax'] = array(
			'rank' => $rank,
			'width' => 26.0, // in mm
			'status' => !getDolGlobalBool('PDF_INVOICE_HIDE_PRICE_EXCL_TAX') && !getDolGlobalBool('PDF_PURCHASE_INVOICE_HIDE_PRICE_EXCL_TAX'),
			'title' => array(
				'textkey' => 'TotalHTShort'
			),
			'border-left' => true, // add left line separator
		);

		$rank += 1010; // add a big offset to be sure is the last col because default extrafield rank is 100
		$this->cols['totalincltax'] = array(
			'rank' => $rank,
			'width' => 26.0, // in mm
			'status' => getDolGlobalBool('PDF_INVOICE_SHOW_PRICE_INCL_TAX') && !getDolGlobalBool('PDF_PURCHASE_INVOICE_SHOW_PRICE_INCL_TAX'),
			'title' => array(
				'textkey' => 'TotalTTCShort'
			),
			'border-left' => true, // add left line separator
		);

		// Add extrafields cols
		if (!empty($object->lines)) {
			$line = reset($object->lines);
			$this->defineColumnExtrafield($line, $outputlangs, $hidedetails);
		}

		$parameters = array(
			'object' => $object,
			'outputlangs' => $outputlangs,
			'hidedetails' => $hidedetails,
			'hidedesc' => $hidedesc,
			'hideref' => $hideref
		);

		navonlinePdfUnitColumn($this, $object);	// NavOnline: "ME" title, unit from the product
		$this->navColumnLayout((int) $object->type === Facture::TYPE_DEPOSIT);

		$reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this); // Note that $object may have been modified by hook
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		} elseif (empty($reshook)) {
			// @phan-suppress-next-line PhanPluginSuspiciousParamOrderInternal
			$this->cols = array_replace($this->cols, $hookmanager->resArray); // array_replace is used to preserve keys
		} else {
			$this->cols = $hookmanager->resArray;
		}
	}

	/**
	 * Copy of pdfWriteVATArray() of core/lib/pdf.lib.php (Dolibarr 24.0): diff it after an upgrade. NavOnline change:
	 * the VAT row labels go through navonlinePdfVatLabel() ("Total tax (27%)" in English).
	 *
	 * @param CommonDocGenerator $docgenerator   Model
	 * @param int                $index          Row index (in/out)
	 * @param TCPDF              $pdf            PDF
	 * @param Translate          $outputlangs    Langs
	 * @param ?Translate         $outputlangsbis Second language
	 * @param Facture            $object         Invoice
	 * @param float              $col1x          Label column x
	 * @param float              $col2x          Amount column x
	 * @param float              $largcol2       Amount column width
	 * @param float              $tab2_top       Table top
	 * @param float              $tab2_hl        Row height
	 * @return void
	 */
	private function navWriteVATArray($docgenerator, &$index, $pdf, $outputlangs, $outputlangsbis, $object, $col1x, $col2x, $largcol2, $tab2_top, $tab2_hl)
	{
		global $mysoc;

		$tmpatleastoneratenotnull = 0;

		// Local tax 1 before VAT
		foreach ($docgenerator->localtax1 as $localtax_type => $localtax_rate) {
			if (in_array((string) $localtax_type, array('1', '3', '5'))) {
				continue;
			}

			foreach ($localtax_rate as $tvakey => $tvaval) {
				if ($tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_LOCALTAX1_LINE_IF_ZERO')) {
					//$tmpatleastoneratenotnull++;

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

		// Local tax 2 before VAT
		foreach ($docgenerator->localtax2 as $localtax_type => $localtax_rate) {
			if (in_array((string) $localtax_type, array('1', '3', '5'))) {
				continue;
			}

			foreach ($localtax_rate as $tvakey => $tvaval) {
				if ($tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_LOCALTAX2_LINE_IF_ZERO')) {
					//$tmpatleastoneratenotnull++;

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

		// Situations totals might be wrong on huge amounts with old mode 1
		if (getDolGlobalInt('INVOICE_USE_SITUATION') == 1 && $object->situation_cycle_ref && $object->situation_counter > 1) {
			$sum_pdf_tva = 0;
			foreach ($docgenerator->tva as $tvakey => $tvaval) {
				$sum_pdf_tva += $tvaval; // sum VAT amounts to compare to object
			}

			if ($sum_pdf_tva != $object->total_tva) { // apply coef to recover the VAT object amount (the good one)
				if (!empty($sum_pdf_tva)) {
					$coef_fix_tva = $object->total_tva / $sum_pdf_tva;
				} else {
					$coef_fix_tva = 1;
				}


				foreach ($docgenerator->tva as $tvakey => $tvaval) {
					$docgenerator->tva[$tvakey] = $tvaval * $coef_fix_tva;
				}
				foreach ($docgenerator->tva_array as $tvakey => $tvaval) {
					$docgenerator->tva_array[$tvakey]['amount'] = $tvaval['amount'] * $coef_fix_tva;
				}
			}
		}

		if (!getDolGlobalInt('PDF_INVOICE_SHOW_VAT_ANALYSIS')) {	// by default, we show detail of vat here
			// VAT
			foreach ($docgenerator->tva_array as $tvakey => $tvaval) {
				if ((float) $tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_VAT_LINE_IF_ZERO')) {	// $tvakey can be 'rate (code)', for example '0 (C+S-18)'
					$tmpatleastoneratenotnull++;

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
		}

		// Local tax 1 after VAT
		foreach ($docgenerator->localtax1 as $localtax_type => $localtax_rate) {
			if (in_array((string) $localtax_type, array('2', '4', '6'))) {
				continue;
			}

			foreach ($localtax_rate as $tvakey => $tvaval) {
				if ($tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_LOCALTAX1_LINE_IF_ZERO')) {
					//$tmpatleastoneratenotnull++;

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

		// Local tax 2 after VAT
		foreach ($docgenerator->localtax2 as $localtax_type => $localtax_rate) {
			if (in_array((string) $localtax_type, array('2', '4', '6'))) {
				continue;
			}

			foreach ($localtax_rate as $tvakey => $tvaval) {
				// retrieve global local tax
				if ($tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_LOCALTAX2_LINE_IF_ZERO')) {
					//$tmpatleastoneratenotnull++;

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

					$total_localtax = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', true);
				}
			}
		}

		$docgenerator->atleastoneratenotnull = $tmpatleastoneratenotnull;
	}
}
