<?php
/* NavOnline: typography of the PDF templates built on the core ones (AluLogic_szamla_tipografiai_jelolesek.pdf,
 * 2026-11-26): column titles, thin lines, table lines, product reference line, footer. The invoice (pdf_sponge_hu)
 * has the same in its own copy. */

/**
 * Used by pdf_dijbekero, pdf_visszaigazolas, pdf_cyan_hu, pdf_espadon_hu, pdf_cornas_hu, pdf_aurore_hu and
 * pdf_bevetelezes. pdfTabTitles() is a copy of CommonDocGenerator::pdfTabTitles() (Dolibarr 24.0): diff it with the
 * core after an upgrade.
 */
trait NavOnlinePdfTypography
{
	/** @var int PDF object of the previous table line (separator above every line but the first of a page) */
	private $navTypoPdf = 0;

	/** @var int Page of the previous table line */
	private $navTypoPage = 0;

	/** @var float Y of the previous table line */
	private $navTypoY = 0;

	/** @var ?array{0:int,1:int,2:int,3:float,4:int,5:float} Line moved to a new page: pdf, line, pages before, Y, page, previous line Y */
	private $navTypoPending = null;

	/** @var array{0:int,1:int,2:float} PDF object, page and Y under the last table line printed */
	private $navLineEnd = array(0, 0, 0.0);

	/** @var array{0:int,1:int,2:float} PDF object, page and Y of the totals on a page without lines */
	private $navTotalsTop = array(0, 0, 0.0);

	/** @var array{0:int,1:int,2:float} PDF object, page and Y of the info and totals area chosen by navTotalsY() */
	private $navTotalsAt = array(0, 0, 0.0);

	/** @var bool The totals area is being measured (navTotalsY() keeps the Y) */
	private $navMeasuring = false;

	/** @var array<string,bool> Pages with a footer (PDF object-page): the core of the old templates draws the footer of the
	 * last page after the totals, also when navTotalsY() moved them back onto a page that has one */
	private $navFootDone = array();

	/**
	 * Column titles: 8.5 pt bold (a second language under them 7.5 pt regular); the column separators only in the
	 * title row (no vertical grid in the body); thin 0.35 pt lines in #D6DCE5.
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
					$bis = (is_object($outputlangsbis) && trim($label));
					$pdf->setCellPaddings($pad[3], 0.8, $pad[1], $bis ? 0.2 : 0.6);
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
						$pdf->setCellPaddings($pad[3], 0, $pad[1], 0.8);
						$pdf->SetFont('', '', 7.5);
						$pdf->SetXY($colDef['xStartPos'], $pdf->GetY());
						$pdf->MultiCell($colDef['width'], 2, $outputlangsbis->transnoentities($colDef['title']['textkey']), '', $align);
					}
					$this->tabTitleHeight = max($pdf->GetY() - $tab_top, $this->tabTitleHeight);
					$pdf->setCellPaddings($cur['L'], $cur['T'], $cur['R'], $cur['B']);
				}
			}
		}
		navonlinePdfThinLine($pdf);
		if (empty($hidetop)) {
			foreach ($seps as $x) {
				$pdf->line($x, $tab_top, $x, $tab_top + $this->tabTitleHeight);
			}
		}
		$pdf->SetFont('', '', 9.5);
		return $this->tabTitleHeight;
	}

	/**
	 * Table frame with the thin line.
	 *
	 * @param TCPDF  $pdf        PDF
	 * @param float  $x          X
	 * @param float  $y          Y
	 * @param float  $w          Width
	 * @param float  $h          Height
	 * @param float  $r          Corner radius
	 * @param int    $hidetop    Hide top
	 * @param int    $hidebottom Hide bottom
	 * @param string $style      Style
	 * @return void
	 */
	public function printRoundedRect($pdf, $x, $y, $w, $h, $r, $hidetop = 0, $hidebottom = 0, $style = 'D')
	{
		navonlinePdfThinLine($pdf);
		parent::printRoundedRect($pdf, $x, $y, $w, $h, $r, $hidetop, $hidebottom, $style);
	}

	/**
	 * Line values at 9.5 pt.
	 *
	 * @param TCPDF  $pdf        PDF
	 * @param float  $curY       Y
	 * @param string $colKey     Column
	 * @param string $columnText Text
	 * @return int
	 */
	public function printStdColumnContent($pdf, &$curY, $colKey, $columnText = '')
	{
		$pdf->SetFont('', '', 9.5);
		navonlinePdfColor($pdf, 'text');
		return parent::printStdColumnContent($pdf, $curY, $colKey, $columnText);
	}

	/**
	 * Line description at 9.5 pt, the product reference not before the label but on its own line under the
	 * description (8 pt grey), a thin separator above every line but the first of a page.
	 *
	 * @param TCPDF        $pdf            PDF
	 * @param float        $curY           Y
	 * @param string       $colKey         Column
	 * @param CommonObject $object         Document
	 * @param int          $i              Line index
	 * @param Translate    $outputlangs    Langs
	 * @param int          $hideref        Hide the reference
	 * @param int          $hidedesc       Hide the description
	 * @param int          $issupplierline Supplier line
	 * @return void
	 */
	public function printColDescContent($pdf, &$curY, $colKey, $object, $i, $outputlangs, $hideref = 0, $hidedesc = 0, $issupplierline = 0)
	{
		// The whole line (description, reference line, spacing) on one page: when it does not fit under the previous
		// lines, it starts on the next page (the core then prints the values there too). Estimated without a PDF
		// transaction: the core template may be inside one.
		$ref = (!$hideref && !empty($object->lines[$i]->product_ref)) ? (string) $object->lines[$i]->product_ref : '';
		// the totals follow the lines (navTotalsY()): the lines fill the page down to the footer (the old core templates
		// keep the height of the totals free under the lines of every page)
		if (method_exists($this, 'navMeasureTotalsArea') && $pdf->getBreakMargin() > $this->navFootReserve($object) + 0.01) {
			$pdf->setPageOrientation('', true, $this->navFootReserve($object));
		}
		// The core templates print the description in a PDF transaction, roll it back when it ran onto a new page and
		// print it again: a page added here in the first pass is lost and $curY was already moved, so the second pass of
		// the same line restores the Y of the first one.
		if (is_array($this->navTypoPending) && $this->navTypoPending[0] === spl_object_id($pdf) && $this->navTypoPending[1] === $i && $pdf->getNumPages() === $this->navTypoPending[2]) {
			$curY = $this->navTypoPending[3];
			$pdf->setPage($this->navTypoPending[4]);
			$this->navTypoPage = $this->navTypoPending[4];
			$this->navTypoY = $this->navTypoPending[5];
		}
		$this->navTypoPending = null;
		if (isset($this->cols[$colKey]) && spl_object_id($pdf) === $this->navTypoPdf && $pdf->getPage() === $this->navTypoPage) {
			$pad = $this->cols[$colKey]['content']['padding'] ?? array(0, 0, 0, 0);
			$html = pdf_getlinedesc($object, $i, $outputlangs, 1, $hidedesc, $issupplierline);
			$plain = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8'));
			$pdf->SetFont('', '', 9.5);
			$need = $pdf->getStringHeight($this->cols[$colKey]['width'] - (float) $pad[1] - (float) $pad[3], $plain) + (float) $pad[0] + (float) $pad[2] + ($ref !== '' ? 4 : 0) + 1;
			$bottom = $pdf->getPageHeight() - $pdf->getBreakMargin();
			if ($curY + $need > $bottom) {
				$this->navTypoPending = array(spl_object_id($pdf), $i, $pdf->getNumPages(), $curY, $pdf->getPage(), $this->navTypoY);
				$pdf->AddPage();
				$pdf->setPage($pdf->getNumPages());
				$curY = $pdf->getMargins()['top'];
			}
		}
		if (spl_object_id($pdf) === $this->navTypoPdf && $pdf->getPage() === $this->navTypoPage && $curY > $this->navTypoY + 1) {
			navonlinePdfThinLine($pdf);
			$pdf->line($this->marge_gauche, $curY, $this->page_largeur - $this->marge_droite, $curY);
		}
		$this->navTypoPdf = spl_object_id($pdf);
		$this->navTypoPage = $pdf->getPage();
		$this->navTypoY = $curY;
		$pdf->SetFont('', '', 9.5);
		navonlinePdfColor($pdf, 'text');
		parent::printColDescContent($pdf, $curY, $colKey, $object, $i, $outputlangs, 1, $hidedesc, $issupplierline);
		if ($ref !== '' && isset($this->cols[$colKey])) {
			$pad = $this->cols[$colKey]['content']['padding'] ?? array(0, 0, 0, 0);
			$pdf->SetFont('', '', 8);
			navonlinePdfColor($pdf, 'muted');
			$pdf->MultiCell($this->cols[$colKey]['width'] - (float) $pad[3], 0, $outputlangs->convToOutputCharset($ref), 0, 'L', false, 1, $this->cols[$colKey]['xStartPos'] + (float) $pad[3], $pdf->GetY() + 0.3);
			navonlinePdfColor($pdf, 'text');
			$pdf->SetFont('', '', 9.5);
		}
		// the last pass of the last line wins (the core may print a line twice, the first pass rolled back)
		$this->navLineEnd = array(spl_object_id($pdf), $pdf->getPage(), (float) $pdf->GetY());
	}

	/**
	 * Height of the table frame of the current page. When the totals do not fit under the last line the core adds a
	 * page for them and draws the frame to the bottom of the line page and an empty frame on the new one: here the
	 * frame closes 2 mm under the last line and the page without lines gets none (its totals start at its top).
	 *
	 * @param TCPDF $pdf        PDF
	 * @param float $tab_top    Top of the frame
	 * @param float $tab_height Height of the frame
	 * @return ?float Height, null = no frame on this page
	 */
	protected function navTableHeight($pdf, $tab_top, $tab_height)
	{
		$id = spl_object_id($pdf);
		if ($this->navLineEnd[0] !== $id || $this->navLineEnd[1] < 1) {
			return $tab_height;
		}
		$page = $pdf->getPage();
		if ($page > $this->navLineEnd[1]) {
			$this->navTotalsTop = array($id, $page, (float) $tab_top);
			return null;
		}
		// templates with navMeasureTotalsArea(): the totals follow the last line, so the frame closes 2 mm under it on the
		// last page too (the lines may run lower than the frame of the core, which keeps the totals height free)
		if ($page === $this->navLineEnd[1] && method_exists($this, 'navMeasureTotalsArea')) {
			return max(5.0, $this->navLineEnd[2] + 2 - $tab_top);
		}
		if ($page === $this->navLineEnd[1] && $pdf->getNumPages() > $page) {
			return max(5.0, min((float) $tab_height, $this->navLineEnd[2] + 2 - $tab_top));
		}
		return $tab_height;
	}

	/**
	 * Y of the info and totals area. Templates with navMeasureTotalsArea() (given the document): 3.5 mm under the frame
	 * of the last line when the measured height of the area fits there, else at the top of a page of their own (the
	 * core reserves a fixed 40 mm and adds that page itself: a page it added without need is removed, a missing one is
	 * added). Other templates: the top of a page without lines (see navTableHeight()), else the Y of the core.
	 *
	 * @param TCPDF         $pdf         PDF
	 * @param float         $posy        Y the core gives (bottom area of the page)
	 * @param ?CommonObject $object      Document
	 * @param ?Translate    $outputlangs Langs
	 * @return float
	 */
	protected function navTotalsY($pdf, $posy, $object = null, $outputlangs = null)
	{
		$id = spl_object_id($pdf);
		if ($this->navMeasuring) {
			return $posy;
		}
		if ($this->navTotalsAt[0] === $id && $this->navTotalsAt[1] === $pdf->getPage()) {
			return $this->navTotalsAt[2];
		}
		if (is_object($object) && is_object($outputlangs) && method_exists($this, 'navMeasureTotalsArea') && $this->navLineEnd[0] === $id && $this->navLineEnd[1] >= 1) {
			$linePage = $this->navLineEnd[1];
			$y = $this->navLineEnd[2] + 2 + 3.5;
			$limit = $this->page_hauteur - $this->navFootReserve($object);
			$this->navMeasuring = true;
			$pdf->startTransaction();
			$h = (float) $this->navMeasureTotalsArea($pdf, $object, $outputlangs, 20.0) - 20.0;
			$pdf->rollbackTransaction(true);
			$this->navMeasuring = false;
			if ($y + $h <= $limit) {
				for ($p = $pdf->getNumPages(); $p > $linePage; $p--) {
					$pdf->deletePage($p);
				}
				$pdf->setPage($linePage);
			} elseif ($pdf->getNumPages() > $linePage) {
				$pdf->setPage($pdf->getNumPages());
				$y = ($this->navTotalsTop[0] === $id && $this->navTotalsTop[1] === $pdf->getPage()) ? $this->navTotalsTop[2] : (float) $posy;
			} else {
				// the footer of the line page first (the old core templates draw the last one after the totals)
				$this->_pagefoot($pdf, $object, $outputlangs, 1);
				$pdf->AddPage();
				$pdf->setPage($pdf->getNumPages());
				$top_shift = 0;
				if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
					$r = $this->_pagehead($pdf, $object, 0, $outputlangs);
					$top_shift = is_array($r) ? (float) ($r['top_shift'] ?? 0) : (float) $r;
				}
				$this->_pagefoot($pdf, $object, $outputlangs, 0);
				$y = getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? $this->marge_haute : $this->marge_haute + 32 + $top_shift;
			}
			$this->navTotalsAt = array($id, $pdf->getPage(), $y);
			return $y;
		}
		if ($this->navTotalsTop[0] === $id && $this->navTotalsTop[1] === $pdf->getPage()) {
			return $this->navTotalsTop[2];
		}
		return $posy;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Table frame of the core, ending under the last line (see navTableHeight()). Templates with their own _tableau()
	 * call navTableHeight() themselves.
	 *
	 * @param TCPDF     $pdf         PDF
	 * @param float     $tab_top     Top
	 * @param float     $tab_height  Height
	 * @param float     $nexY        Y
	 * @param Translate $outputlangs Langs
	 * @param int       $hidetop     Hide the top
	 * @param int       $hidebottom  Hide the bottom
	 * @param mixed     ...$more     Currency, second language (as the core template takes them)
	 * @return void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, ...$more)
	{
		// phpcs:enable
		$h = $this->navTableHeight($pdf, $tab_top, $tab_height);
		if ($h === null) {
			return;
		}
		if ($h < $tab_height) {
			$hidebottom = 0;
		}
		parent::_tableau($pdf, $tab_top, $h, $nexY, $outputlangs, $hidetop, $hidebottom, ...$more);
	}

	/**
	 * Height kept free above the bottom of the page for the footer (and the free text when there is one).
	 *
	 * @param CommonObject $object Document
	 * @return float
	 */
	protected function navFootReserve($object)
	{
		return $this->marge_basse + (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS') ? 22 : 12) + (getDolGlobalString($this->navFreeTextConst($object)) !== '' ? getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5) : 0);
	}

	/**
	 * Constant of the free text printed above the footer of the last page.
	 *
	 * @param CommonObject $object Document
	 * @return string
	 */
	protected function navFreeTextConst($object)
	{
		$consts = array('commande' => 'ORDER_FREE_TEXT', 'propal' => 'PROPOSAL_FREE_TEXT', 'shipping' => 'SHIPPING_FREE_TEXT',
			'order_supplier' => 'SUPPLIER_ORDER_FREE_TEXT', 'supplier_proposal' => 'SUPPLIER_PROPOSAL_FREE_TEXT', 'reception' => 'RECEPTION_FREE_TEXT');
		return $consts[(string) ($object->element ?? '')] ?? '';
	}

	/**
	 * Footer: company identifiers on one line at the left, 7.5 pt grey under a thin line, the page number right.
	 *
	 * @param TCPDF        $pdf          PDF
	 * @param CommonObject $object       Document
	 * @param Translate    $outputlangs  Langs
	 * @param int          $hidefreetext Hide the free text
	 * @return int
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		$key = spl_object_id($pdf).'-'.$pdf->getPage();
		if (isset($this->navFootDone[$key])) {
			return 0;
		}
		$this->navFootDone[$key] = true;
		$const = $this->navFreeTextConst($object);
		return (int) navonlinePdfPagefoot($pdf, $outputlangs, $const, $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0), $hidefreetext, $this->page_largeur, $this->watermark ?? '');
	}
}
