<?php
/* Copyright (C) 2026 Alu-Logic Kft.
 * Based on core pdf_squille (Copyright (C) 2018 Quentin Vial-Gouteyron and Dolibarr contributors)
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
 * \file    navonline/core/modules/reception/doc/pdf_bevetelezes.modules.php
 * \ingroup navonline
 * \brief   Bevételezési bizonylat (goods receipt note) PDF
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/reception/doc/pdf_squille.modules.php';
require_once dirname(__DIR__, 4).'/lib/navonline.lib.php';
require_once dirname(__DIR__, 4).'/class/navonlinepdftypo.trait.php';

/**
 * Goods receipt note, built on core squille (fixed-position layout), with:
 *  - title "Bevételezési bizonylat", number, date of receipt, the supplier's delivery note number, the purchase order;
 *  - "Szállító" (left) / "Átvevő" (right: our company at the address of the receiving warehouse);
 *  - columns Megnevezés | Egység | Megrendelt | Átvett | Hátralék (title row as tall as its wrapped titles);
 *  - totals row and a signature block (handed over by / received by / date).
 * Weight / volume are not printed.
 */
class pdf_bevetelezes extends pdf_squille
{
	use NavOnlinePdfTypography;
	/** @var float x of the unit column */
	private $navX = array();

	/** @var float Height of the column title row */
	private $navTitleH = 5;

	/** @var array<int,float> Remaining (not yet received) quantity per line index */
	private $navRemain = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		parent::__construct($db);
		$this->name = 'bevetelezes';
		$this->description = 'Bevételezési bizonylat (szállítólevélszám, hátralék, aláírás)';

		$right = $this->page_largeur - $this->marge_droite;
		$this->navX = array(
			'unit' => $right - 81,
			'ordered' => $right - 66,
			'received' => $right - 44,
			'remain' => $right - 22,
			'end' => $right,
		);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param Reception $object          Reception
	 * @param Translate $outputlangs     Langs
	 * @param string    $srctemplatepath Not used
	 * @param int<0,1>  $hidedetails     Hide details
	 * @param int<0,1>  $hidedesc        Hide description
	 * @param int<0,1>  $hideref         Hide ref
	 * @return int<-1,1>
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $user, $conf, $langs, $hookmanager;

		$object->fetch_thirdparty();
		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'products', 'propal', 'orders', 'receptions', 'productbatch', 'sendings', 'navonline@navonline'));

		if ($object->status == $object::STATUS_DRAFT && getDolGlobalString('RECEPTION_DRAFT_WATERMARK')) {
			$this->watermark = getDolGlobalString('RECEPTION_DRAFT_WATERMARK');
		}
		if (empty($conf->reception->dir_output)) {
			$this->error = $langs->transnoentities('ErrorConstantNotDefined', 'RECEPTION_OUTPUTDIR');
			return 0;
		}
		$ref = dol_sanitizeFileName($object->ref);
		$dir = $object->specimen ? $conf->reception->dir_output : $conf->reception->dir_output.'/'.$ref;
		$file = $dir.'/'.($object->specimen ? 'SPECIMEN' : $ref).'.pdf';
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('pdfgeneration'));
		$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
		global $action;
		$hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);
		$nblines = count($object->lines);
		$this->navRemain = $this->navRemaining($object);

		$pdf = pdf_getInstance($this->format);
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$heightforinfotot = 28;	// totals row + signature block (see navTotalsAndSignatures())
		$heightforfreetext = getDolGlobalString('RECEPTION_FREE_TEXT') !== '' ? getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) : 0;
		$heightforfooter = $this->marge_basse + 8;
		$pdf->setAutoPageBreak(true, 0);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		if (!getDolGlobalString('MAIN_DISABLE_FPDI') && getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
			$pdf->setSourceFile($conf->mycompany->dir_output.'/'.getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
			$tplidx = $pdf->importPage(1);
		}
		$pdf->Open();
		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
		$pdf->SetSubject($outputlangs->transnoentities('NavOnlineRcpTitle'));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getAnonymisableFullName($outputlangs)));
		$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref).' '.$outputlangs->transnoentities('NavOnlineRcpTitle'));
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

		$pdf->AddPage();
		if (!empty($tplidx)) {
			$pdf->useTemplate($tplidx);
		}
		$pagenb = 1;
		$this->_pagehead($pdf, $object, 1, $outputlangs);
		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->MultiCell(0, 3, '');
		$pdf->SetTextColor(0, 0, 0);

		$tab_top = 80 + $this->marge_haute;
		$tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 32 + $this->marge_haute : $this->marge_haute);

		// Tracking number and public note
		if (!empty($object->note_public) || !empty($object->tracking_number)) {
			$y = $tab_top;
			if (!empty($object->tracking_number)) {
				$pdf->SetFont('', 'B', $default_font_size - 2);
				$pdf->writeHTMLCell(190, 4, $this->posxdesc - 1, $tab_top - 1, $outputlangs->transnoentities('TrackingNumber').': '.dol_escape_htmltag($object->tracking_number), 0, 1, false, true, 'L');
				$y = $pdf->GetY();
			}
			if (!empty($object->note_public)) {
				$pdf->SetFont('', '', 8.5);	// typography: notes 8.5 pt regular
				$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $y, dol_htmlentitiesbr((string) $object->note_public), 0, 1);
			}
			$nexY = $pdf->GetY();
			$pdf->SetDrawColor(192, 192, 192);
			$pdf->RoundedRect($this->marge_gauche, $tab_top - 1, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $nexY - $tab_top + 2, $this->corner_radius, '1234', 'D');
			$tab_top = $nexY + 6;
		}

		$this->navTitleH = $this->navTitleHeight($pdf, $object, $outputlangs);
		$nexY = $tab_top + $this->navTitleH + 1.6;	// typography: 1.6 mm above the lines
		$totals = array('ordered' => 0, 'received' => 0, 'remain' => 0);
		$seen = array();
		$descW = $this->navX['unit'] - $this->posxdesc;

		for ($i = 0; $i < $nblines; $i++) {
			$line = $object->lines[$i];
			$curY = $nexY;
			$pdf->SetFont('', '', 9.5);	// typography: lines 9.5 pt
			navonlinePdfColor($pdf, 'text');
			$pdf->setTopMargin($tab_top_newpage);
			$pdf->setPageOrientation('', true, $heightforfooter + $heightforfreetext);
			$pageposbefore = $pdf->getPage();

			$curX = $this->posxdesc - 1;
			$pdf->startTransaction();
			pdf_writelinedesc($pdf, $object, $i, $outputlangs, $descW, 3, $curX, $curY, 1, $hidedesc);	// typography: the reference on its own line
			$pageposafter = $pdf->getPage();
			if ($pageposafter > $pageposbefore) {
				// Description does not fit: whole line on the next page
				$pdf->rollbackTransaction(true);
				$pdf->AddPage('', '', true);
				if (!empty($tplidx)) {
					$pdf->useTemplate($tplidx);
				}
				if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
					$this->_pagehead($pdf, $object, 0, $outputlangs);
				}
				$pdf->setPage($pageposbefore + 1);
				$curY = $tab_top_newpage + $this->navTitleH + 1.6;
				$pdf->setTopMargin($tab_top_newpage);
				$pdf->setPageOrientation('', true, $heightforfooter + $heightforfreetext);
				$pdf->SetFont('', '', 9.5);	// _pagehead left its own (larger) font
				navonlinePdfColor($pdf, 'text');
				pdf_writelinedesc($pdf, $object, $i, $outputlangs, $descW, 3, $curX, $curY, 1, $hidedesc);	// typography: the reference on its own line
				$pageposafter = $pdf->getPage();
			} else {
				$pdf->commitTransaction();
			}
			// typography: product reference on its own line, 8 pt grey
			$navRef = !empty($line->product_ref) ? (string) $line->product_ref : ((is_object($line->product) && !empty($line->product->ref)) ? (string) $line->product->ref : '');
			if (!$hideref && $navRef !== '') {
				$pdf->SetFont('', '', 8);
				navonlinePdfColor($pdf, 'muted');
				$pdf->MultiCell($descW, 0, $outputlangs->convToOutputCharset($navRef), 0, 'L', false, 1, $curX + 1, $pdf->GetY() + 0.3);
				navonlinePdfColor($pdf, 'text');
			}
			$nexY = $pdf->GetY();
			$pdf->setTopMargin($this->marge_haute);
			$pdf->setPageOrientation('', true, 0);

			$pdf->SetFont('', '', 9.5);
			$unit = (is_object($line->product) && method_exists($line->product, 'getLabelOfUnit')) ? $line->product->getLabelOfUnit('short', $outputlangs) : '';
			$pdf->SetXY($this->navX['unit'], $curY);
			$pdf->MultiCell($this->navX['ordered'] - $this->navX['unit'], 3, $outputlangs->convToOutputCharset((string) $unit), 0, 'C');
			$first = !isset($seen[(int) $line->fk_commandefourndet]);	// an order line split on several lines (warehouses, lots): ordered once
			$seen[(int) $line->fk_commandefourndet] = 1;
			if ($first) {
				$pdf->SetXY($this->navX['ordered'], $curY);
				$pdf->MultiCell($this->navX['received'] - $this->navX['ordered'] - 1, 3, $this->navQty($line->qty_asked, $outputlangs), 0, 'R');
				$totals['ordered'] += (float) $line->qty_asked;
				$pdf->SetXY($this->navX['remain'], $curY);
				$pdf->MultiCell($this->navX['end'] - $this->navX['remain'] - 1, 3, $this->navQty($this->navRemain[$i] ?? 0, $outputlangs), 0, 'R');
				$totals['remain'] += (float) ($this->navRemain[$i] ?? 0);
			}
			$pdf->SetXY($this->navX['received'], $curY);
			$pdf->MultiCell($this->navX['remain'] - $this->navX['received'] - 1, 3, $this->navQty($line->qty, $outputlangs), 0, 'R');
			$totals['received'] += (float) $line->qty;

			$nexY = max($nexY, $pdf->GetY()) + 3.2;	// typography: 1.6 mm, a thin separator, 1.6 mm
			if ($i < $nblines - 1) {
				$pdf->setPage($pageposafter);
				navonlinePdfThinLine($pdf);
				$pdf->line($this->marge_gauche, $nexY - 1.6, $this->page_largeur - $this->marge_droite, $nexY - 1.6);
				navonlinePdfLineReset($pdf);
			}
			while ($pagenb < $pageposafter) {
				$pdf->setPage($pagenb);
				// setPage() restores the page's bottom margin of the lines: without this the footer auto-breaks onto the
				// top of the next page
				$pdf->setPageOrientation('', false, 0);
				if ($pagenb == 1) {
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1, $object);
				} else {
					$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1, $object);
				}
				$this->_pagefoot($pdf, $object, $outputlangs, 1);
				$pagenb++;
				$pdf->setPage($pagenb);
				$pdf->setPageOrientation('', true, 0);
				if (!empty($tplidx)) {
					$pdf->useTemplate($tplidx);
				}
			}
		}

		// The table closes 1.6 mm under the last line (as the separators), the totals 3.5 mm under it; on a new page when
		// they do not fit there
		$top = ($pagenb == 1) ? $tab_top : $tab_top_newpage;
		$navFrameBottom = $nexY - 1.6;
		$this->_tableau($pdf, $top, max(5, $navFrameBottom - $top), 0, $outputlangs, ($pagenb == 1 ? 0 : 1), 0, $object);
		$bottomlasttab = $navFrameBottom + 3.5;
		if ($bottomlasttab + $heightforinfotot > $this->page_hauteur - $heightforfooter - $heightforfreetext) {
			$this->_pagefoot($pdf, $object, $outputlangs, 1);
			$pdf->AddPage();
			if (!empty($tplidx)) {
				$pdf->useTemplate($tplidx);
			}
			$pagenb++;
			if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
				$this->_pagehead($pdf, $object, 0, $outputlangs);
			}
			$bottomlasttab = $tab_top_newpage;
		}
		$this->navTotalsAndSignatures($pdf, $bottomlasttab, $outputlangs, $totals);

		$this->_pagefoot($pdf, $object, $outputlangs);
		if (method_exists($pdf, 'AliasNbPages')) {
			$pdf->AliasNbPages();  // @phan-suppress-current-line PhanUndeclaredMethod
		}
		$pdf->Close();
		$pdf->Output($file, 'F');

		$hookmanager->initHooks(array('pdfgeneration'));
		$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
		$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);
		$this->warnings = $hookmanager->warnings;
		dolChmod($file);
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		}
		$this->result = array('fullpath' => $file);
		return 1;
	}

	/**
	 * Remaining quantity of each order line after this reception: ordered - received on this reception and on the
	 * earlier validated receptions of the order (a later reception does not change the note when it is regenerated).
	 *
	 * @param Reception $object Reception
	 * @return array<int,float>
	 */
	private function navRemaining($object)
	{
		$ids = array();
		foreach ($object->lines as $l) {
			$ids[] = (int) $l->fk_commandefourndet;
		}
		$done = array();
		if ($ids) {
			$sql = 'SELECT b.fk_elementdet, SUM(b.qty) AS q FROM '.MAIN_DB_PREFIX.'receptiondet_batch b JOIN '.MAIN_DB_PREFIX.'reception r ON r.rowid = b.fk_reception';
			$sql .= ' WHERE b.fk_elementdet IN ('.implode(',', array_unique($ids)).') AND r.rowid <= '.((int) $object->id).' AND (r.fk_statut > 0 OR r.rowid = '.((int) $object->id).')';	// as at this reception: a later one does not change a printed note
			$sql .= ' GROUP BY b.fk_elementdet';
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				$done[(int) $o->fk_elementdet] = (float) $o->q;
			}
		}
		$out = array();
		foreach ($object->lines as $i => $l) {
			$out[$i] = max(0, (float) $l->qty_asked - ($done[(int) $l->fk_commandefourndet] ?? 0));
		}
		return $out;
	}

	/**
	 * @param float|string $q           Quantity
	 * @param Translate    $outputlangs Langs
	 * @return string
	 */
	private function navQty($q, $outputlangs)
	{
		$q = (float) $q;
		return (abs($q - round($q)) < 0.00001) ? price(round($q), 0, $outputlangs, 0, 0, 0) : price($q, 0, $outputlangs, 0, 0, -1);
	}

	/**
	 * @return array<string,string> Column key => title
	 */
	private function navTitles($object, $outputlangs)
	{
		$draft = ($object && $object->status < Reception::STATUS_VALIDATED);
		return array(
			'desc' => $outputlangs->transnoentities('Designation'),	// "Megnevezés", as on the other documents
			'unit' => $outputlangs->transnoentities('NavOnlinePdfUnitTitle'),
			'ordered' => $outputlangs->transnoentities('NavOnlineRcpOrdered'),
			'received' => $outputlangs->transnoentities($draft ? 'NavOnlineRcpToReceive' : 'NavOnlineRcpReceived'),
			'remain' => $outputlangs->transnoentities('NavOnlineRcpRemaining'),
		);
	}

	/**
	 * @return float Height of the title row
	 */
	private function navTitleHeight($pdf, $object, $outputlangs)
	{
		$pdf->SetFont('', '', pdf_getPDFFontSize($outputlangs) - 1);
		$h = 5;
		foreach ($this->navTitles($object, $outputlangs) as $k => $t) {
			$w = $this->navColWidth($k);
			$h = max($h, $pdf->getStringHeight($w, $t) + 1.5);
		}
		return $h;
	}

	/**
	 * @param string $k Column key
	 * @return float
	 */
	private function navColWidth($k)
	{
		$x = $this->navX;
		$map = array('desc' => array($this->marge_gauche, $x['unit']), 'unit' => array($x['unit'], $x['ordered']), 'ordered' => array($x['ordered'], $x['received']), 'received' => array($x['received'], $x['remain']), 'remain' => array($x['remain'], $x['end']));
		return $map[$k][1] - $map[$k][0];
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Table frame with the column titles.
	 *
	 * @param TCPDF          $pdf         PDF
	 * @param float|int      $tab_top     Top
	 * @param float|int      $tab_height  Height
	 * @param int            $nexY        Not used
	 * @param Translate      $outputlangs Langs
	 * @param int            $hidetop     Hide title row
	 * @param int            $hidebottom  Not used
	 * @param Reception|null $object      Reception
	 * @return void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $object = null)
	{
		// Column titles on every page with MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT (write_file already starts the lines of
		// a new page under the title row: tab_top_newpage + navTitleH + 2)
		$hidetop = ($hidetop && !getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) ? -1 : 0;
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		if (empty($hidetop)) {	// typography: title row background #F2F4F7
			$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $this->navTitleH, 'F', array(), NAVONLINE_PDF_COLORS['head']);
		}
		$this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, $hidetop, 0, 'D');
		$pdf->SetFont('', 'B', 8.5);	// typography: titles 8.5 pt bold
		navonlinePdfColor($pdf, 'text');
		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + $this->navTitleH, $this->page_largeur - $this->marge_droite, $tab_top + $this->navTitleH);
		}
		$x = array('desc' => $this->marge_gauche) + $this->navX;
		foreach ($this->navTitles($object, $outputlangs) as $k => $t) {
			if ($k !== 'desc' && empty($hidetop)) {	// typography: separators only in the title row
				$pdf->line($x[$k], $tab_top, $x[$k], $tab_top + $this->navTitleH);
			}
			if (empty($hidetop)) {
				$pdf->SetXY($x[$k], $tab_top + 0.5);
				$pdf->MultiCell($this->navColWidth($k), 2, $t, 0, $k === 'desc' ? 'L' : 'C');
			}
		}
	}

	/**
	 * Totals row (ordered / received / remaining) and the signature block.
	 *
	 * @param TCPDF                $pdf         PDF
	 * @param float                $posy        Y under the table
	 * @param Translate            $outputlangs Langs
	 * @param array<string,float>  $totals      Totals
	 * @return void
	 */
	private function navTotalsAndSignatures(&$pdf, $posy, $outputlangs, $totals)
	{
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$pdf->SetFont('', 'B', $default_font_size - 1);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetXY($this->navX['unit'] - 40, $posy);
		$pdf->MultiCell(40 + ($this->navX['ordered'] - $this->navX['unit']), 4, $outputlangs->transnoentities('NavOnlineShipTotal'), 0, 'L');
		foreach (array('ordered' => 'received', 'received' => 'remain', 'remain' => 'end') as $k => $next) {
			$pdf->SetXY($this->navX[$k], $posy);
			$pdf->MultiCell($this->navX[$next] - $this->navX[$k] - 1, 4, $this->navQty($totals[$k], $outputlangs), 0, 'R');
		}

		// Signatures
		$y = $posy + 12;
		$w = ($this->page_largeur - $this->marge_gauche - $this->marge_droite) / 3;
		$pdf->SetFont('', '', $default_font_size - 1);
		foreach (array('NavOnlineRcpSignSupplier', 'NavOnlineRcpSignReceiver', 'NavOnlineRcpSignDate') as $n => $key) {
			$x = $this->marge_gauche + $n * $w;
			$pdf->line($x + 4, $y + 10, $x + $w - 4, $y + 10);
			$pdf->SetXY($x, $y + 11);
			$pdf->MultiCell($w, 4, $outputlangs->transnoentities($key), 0, 'C');
		}
	}

	/**
	 * Header: title, number, date of receipt, the supplier's delivery note, the purchase order; address boxes
	 * "Szállító" (supplier) and "Átvevő" (our company at the receiving warehouse).
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param Reception $object      Reception
	 * @param int       $showaddress 1 = first page
	 * @param Translate $outputlangs Langs
	 * @return int
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf;

		$default_font_size = pdf_getPDFFontSize($outputlangs);
		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);
		$w = 110;
		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		// Logo
		$pdf->SetXY($this->marge_gauche, $posy);
		if ($this->emetteur->logo) {
			$logodir = !empty($conf->mycompany->multidir_output[$object->entity ?? $conf->entity]) ? $conf->mycompany->multidir_output[$object->entity ?? $conf->entity] : $conf->mycompany->dir_output;
			$logo = $logodir.(getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? '/logos/'.$this->emetteur->logo : '/logos/thumbs/'.$this->emetteur->logo_small);
			if (is_readable($logo)) {
				$pdf->Image($logo, $this->marge_gauche, $posy, 0, pdf_getHeightForLogo($logo));
			}
		} else {
			$pdf->SetFont('', 'B', $default_font_size + 3);
			$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
		}

		// NavOnline: header the same on every document (navonlinePdfHeadTitle / navonlinePdfHeadLines)
		$title = $outputlangs->transnoentities('NavOnlineRcpTitle').' '.$outputlangs->convToOutputCharset($object->ref);
		$draft = ($object->status == $object::STATUS_DRAFT);
		if ($draft) {
			$title .= ' - '.$outputlangs->transnoentities('NotValidated');
		}
		$posy = navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft, false, $outputlangs->convToOutputCharset($object->ref));

		$rows = array();
		$date = !empty($object->date_reception) ? $object->date_reception : (!empty($object->date_delivery) ? $object->date_delivery : 0);
		if ($date) {
			$rows[] = $outputlangs->transnoentities('NavOnlineRcpDate').': '.dol_print_date($date, 'day', false, $outputlangs, true);
		}
		if (!empty($object->ref_supplier)) {
			$rows[] = $outputlangs->transnoentities('NavOnlineRcpDeliveryNote').': '.$outputlangs->convToOutputCharset($object->ref_supplier);
		}
		if (!empty($object->origin_id) && isModEnabled('supplier_order')) {
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
			$order = new CommandeFournisseur($this->db);
			if ($order->fetch((int) $object->origin_id) > 0) {
				$rows[] = $outputlangs->transnoentities('NavOnlineRcpOrder').': '.$outputlangs->convToOutputCharset($order->ref).' / '.dol_print_date($order->date, 'day', false, $outputlangs, true);
			}
		}
		navonlinePdfHeadLines($pdf, $posx, $posy, $w, $rows, $default_font_size);

		if ($showaddress) {
			$posy = 32 + $this->marge_haute;
			$hautcadre = 40;
			// Supplier (left, grey)
			$wl = 82;
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($this->marge_gauche, $posy - 5);
			$pdf->MultiCell($wl, 5, $outputlangs->transnoentities('NavOnlineRcpSupplier'), 0, 'L');
			navonlinePdfThinLine($pdf);	// typography: white box, thin frame
			$pdf->RoundedRect($this->marge_gauche, $posy, $wl, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetXY($this->marge_gauche + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell($wl - 2, 4, pdfBuildThirdpartyName($object->thirdparty, $outputlangs), 0, 'L');
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($this->marge_gauche + 2, $pdf->GetY());
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($wl - 2) - 4, pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'targetwithdetails', $object), 'L');	// typography: 9.5 / 8.5 pt

			// Receiver (right): our company at the receiving warehouse
			$wr = ($this->page_largeur < 210) ? 84 : 100;
			$x = $this->page_largeur - $this->marge_droite - $wr;
			navonlinePdfColor($pdf, 'muted');	// typography: box title 8 pt grey
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($x + 2, $posy - 5);
			$pdf->MultiCell($wr, 5, $outputlangs->transnoentities('NavOnlineRcpReceiver'), 0, 'L');
			navonlinePdfThinLine($pdf);
			$pdf->RoundedRect($x, $posy, $wr, $hautcadre, $this->corner_radius, '1234', 'D');
			navonlinePdfLineReset($pdf);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetXY($x + 3, $posy + 3);
			navonlinePdfColor($pdf, 'text');	// typography: name 10.5 pt bold
			$pdf->SetFont('', 'B', 10.5);
			$pdf->MultiCell($wr - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($x + 2, $pdf->GetY());
			navonlinePdfPartnerLines($pdf, $pdf->GetX() + 1, $pdf->GetY(), ($wr - 2) - 4, $this->navReceiverAddress($object, $outputlangs), 'L');	// typography: 9.5 / 8.5 pt
		}
		$pdf->SetTextColor(0, 0, 0);
		return 0;
	}

	/**
	 * Address of the receiving warehouse (of the first line), our phone / e-mail; the registered office address
	 * when the warehouse has none.
	 *
	 * @param Reception $object      Reception
	 * @param Translate $outputlangs Langs
	 * @return string
	 */
	private function navReceiverAddress($object, $outputlangs)
	{
		$wh = 0;
		foreach ($object->lines as $l) {
			if (!empty($l->fk_entrepot)) {
				$wh = (int) $l->fk_entrepot;
				break;
			}
		}
		if (!$wh) {
			$wh = getDolGlobalInt('NAVONLINE_PO_DELIVERY_WAREHOUSE');
		}
		$lines = array();
		if ($wh) {
			$res = $this->db->query('SELECT e.ref, e.address, e.zip, e.town FROM '.MAIN_DB_PREFIX.'entrepot e WHERE e.rowid = '.$wh);
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o && trim((string) $o->address) !== '') {
				$lines[] = $o->address;
				$lines[] = trim($o->zip.' '.$o->town);
			}
		}
		if (!$lines) {
			return pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);
		}
		if (!empty($this->emetteur->phone)) {
			$lines[] = '';
			$lines[] = $outputlangs->transnoentities('PhoneShort').': '.$this->emetteur->phone;
		}
		if (!empty($this->emetteur->email)) {
			$lines[] = $outputlangs->transnoentities('Email').': '.$this->emetteur->email;
		}
		return $outputlangs->convToOutputCharset(implode("\n", $lines));
	}
}
