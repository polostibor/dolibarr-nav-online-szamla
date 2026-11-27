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
 * \file    htdocs/custom/navonline/class/actions_navonline.class.php
 * \ingroup navonline
 * \brief   Hooks of the NavOnline module.
 *
 * printCommonFooter (context thirdpartycard): hides partner card fields that have no Dolibarr switch, in view, edit
 * and create mode. The data stays in the database. Fields are listed in NAVONLINE_HIDE_THIRDPARTY_FIELDS
 * (comma separated): legalform, birth, capital.
 */

/**
 * Hooks of NavOnline
 */
class ActionsNavonline
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/** Field => array(id of the input in edit/create mode, translation key of the label in view mode) */
	const THIRDPARTY_FIELDS = array(
		'legalform' => array('forme_juridique_code', 'JuridicalStatus'),
		'birth' => array('birth', 'CompanyBirthDate'),
		'capital' => array('capital', 'Capital'),
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** @var bool Re-entry guard of getSellPrice (it calls the standard price calculation) */
	private static $inSellPrice = false;

	/** @var array<int, array<string, ?string>> Date formats swapped for the PDF, per output language object */
	private static $pdfDateFormats = array();

	/**
	 * PDF (every template, context pdfgeneration): the currency line as "Pénznem: EUR" (user 2026-11-26), and dates
	 * separated by dots, never by slashes (user 2026-11-26), only for
	 * the time of the PDF: en_US 11/26/2026 -> 26.11.2026 (day first, 24 h), other languages "/" -> "."; hu and de have
	 * dots already. The UI and the date inputs keep their format.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters (outputlangs)
	 * @param CommonObject         $object      Document
	 * @param string               $action      Action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int 0
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$ol = $parameters['outputlangs'] ?? null;
		if (!is_object($ol) || isset(self::$pdfDateFormats[spl_object_id($ol)])) {
			return 0;
		}
		$saved = array();
		foreach (array('FormatDateShort', 'FormatDateHourShort', 'FormatDateHourSecShort') as $key) {
			$fmt = $ol->transnoentitiesnoconv($key);
			if (strpos($fmt, '/') === false) {
				continue;
			}
			$saved[$key] = $ol->tab_translate[$key] ?? null;
			$new = str_replace(array('%m/%d/%Y', '%m/%d/%y'), array('%d.%m.%Y', '%d.%m.%y'), $fmt);
			$new = str_replace(array('%I:%M:%S %p', '%I:%M %p'), array('%H:%M:%S', '%H:%M'), $new);
			$ol->tab_translate[$key] = str_replace('/', '.', $new);
		}
		// Currency line above the lines: "Pénznem: EUR" / "Currency: EUR" / "Währung: EUR" (core: "All amounts in euros")
		$ol->load('navonline@navonline');
		$codes = array(getDolGlobalString('MAIN_MONNAIE', 'HUF'));
		$res = $this->db->query('SELECT code FROM '.$this->db->prefix().'multicurrency');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$codes[] = (string) $o->code;
		}
		$cur = array('AmountInCurrency' => $ol->transnoentitiesnoconv('NavOnlinePdfCurrencyLabel').': %s');
		foreach (array_unique($codes) as $c) {
			if (preg_match('/^[A-Z]{3}$/', $c)) {
				$cur['Currency'.$c] = $c;
			}
		}
		foreach ($cur as $key => $val) {
			if (!array_key_exists($key, $saved)) {
				$saved[$key] = $ol->tab_translate[$key] ?? null;
			}
			$ol->tab_translate[$key] = $val;
		}
		self::$pdfDateFormats[spl_object_id($ol)] = $saved;
		return 0;
	}

	/**
	 * PDF line description (hook pdf_writelinedesc, before the core prints it): where the description of this line will
	 * end (estimated with the current font), for the table frame of templates without the column system. Prints nothing.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Document
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function pdf_writelinedesc($parameters, &$object, &$action, $hookmanager)
	{
		$pdf = $parameters['pdf'] ?? null;
		if (!is_object($pdf) || !method_exists($pdf, 'getStringHeight') || !isset($parameters['i'], $parameters['w'], $parameters['posy']) || !is_object($parameters['outputlangs'] ?? null)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once __DIR__.'/../lib/navonline.lib.php';
		$navBottom = navonlinePdfLinesBottom();
		if (is_array($navBottom) && $navBottom[0] === spl_object_id($pdf) && $pdf->getBreakMargin() > $navBottom[1] + 0.01) {
			$pdf->setPageOrientation('', true, $navBottom[1]);	// the lines down to the footer, the totals follow them
		}
		$html = pdf_getlinedesc($object, (int) $parameters['i'], $parameters['outputlangs'], (int) ($parameters['hideref'] ?? 0), (int) ($parameters['hidedesc'] ?? 0), (int) ($parameters['issupplierline'] ?? 0));
		$plain = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8'));
		$h = $pdf->getStringHeight((float) $parameters['w'], $plain);
		navonlinePdfLineDescEnd($pdf, array(spl_object_id($pdf), (int) $pdf->getPage(), (float) $parameters['posy'] + $h));
		return 0;
	}

	/**
	 * PDF done: the date formats of beforePDFCreation() back.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters (outputlangs)
	 * @param CommonDocGenerator   $object      Template
	 * @param string               $action      Action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int 0
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$ol = $parameters['outputlangs'] ?? null;
		if (!is_object($ol) || !isset(self::$pdfDateFormats[spl_object_id($ol)])) {
			return 0;
		}
		foreach (self::$pdfDateFormats[spl_object_id($ol)] as $key => $val) {
			if ($val === null) {
				unset($ol->tab_translate[$key]);
			} else {
				$ol->tab_translate[$key] = $val;
			}
		}
		unset(self::$pdfDateFormats[spl_object_id($ol)]);
		return 0;
	}

	/**
	 * Fixed export price in EUR: on a EUR proposal / order / invoice of a customer on the export price level
	 * (NAVONLINE_EXPORT_PRICE_LEVEL, default 4), a product with "Export értékesítési ár (EUR)" (extrafield
	 * export_price_eur) gets exactly that EUR net unit price. Dolibarr computes line prices in the main currency and
	 * converts them with the document rate, so the HUF unit price returned here is EUR price / document rate.
	 *
	 * @param array<string,mixed> $parameters  thirdparty_seller, thirdparty_buyer, pqp
	 * @param Product             $object      Product
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 1 = price replaced (resArray), 0 = standard calculation
	 */
	public function getSellPrice($parameters, &$object, &$action, $hookmanager)
	{
		if (self::$inSellPrice || !is_object($object) || empty($object->id)) {
			return 0;
		}
		$doc = $GLOBALS['object'] ?? null;	// the document being edited (card.php global)
		if (!is_object($doc) || !in_array($doc->element ?? '', array('facture', 'propal', 'commande'), true)) {
			return 0;
		}
		$tx = (float) ($doc->multicurrency_tx ?? 0);
		if (strtoupper((string) ($doc->multicurrency_code ?? '')) !== 'EUR' || $tx <= 0) {
			return 0;
		}
		$buyer = $parameters['thirdparty_buyer'] ?? null;
		if (!is_object($buyer) || (int) $buyer->price_level !== getDolGlobalInt('NAVONLINE_EXPORT_PRICE_LEVEL', 4)) {
			return 0;
		}
		if (empty($object->array_options) && method_exists($object, 'fetch_optionals')) {
			$object->fetch_optionals();
		}
		$eur = (float) ($object->array_options['options_export_price_eur'] ?? 0);
		if ($eur <= 0) {
			return 0;
		}

		self::$inSellPrice = true;
		try {
			$res = $object->getSellPrice($parameters['thirdparty_seller'], $buyer, $parameters['pqp'] ?? 0);
		} finally {
			self::$inSellPrice = false;
		}
		$vat = (float) preg_replace('/\s*\(.*\)/', '', (string) ($res['tva_tx'] ?? $object->tva_tx));
		$res['pu_ht'] = $eur / $tx;
		$res['pu_ttc'] = $res['pu_ht'] * (1 + $vat / 100);
		$res['price_base_type'] = 'HT';
		// HookManager builds resArray from ->results (and resets it after the call): never write resArray directly
		$this->results = $res;

		return 1;
	}

	/**
	 * Cash rounding (utánvét, see navonlineCashRoundingApplies()): a customer payment that leaves at most half of the
	 * rounding unit unpaid or overpaid (e.g. 12 343 Ft invoice paid with 12 345 Ft) closes the invoice as paid.
	 * Context paymentdao, called by Paiement::create() when an invoice still has a remainder after the payment.
	 *
	 * @param array<string,mixed> $parameters  facid, invoice, remaintopay
	 * @param Paiement            $object      Payment
	 * @param string              $action      CLOSEPAIDINVOICE
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 1 = invoice closed here, 0 = standard
	 */
	public function createPayment($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		$invoice = $parameters['invoice'] ?? null;
		$remain = (float) ($parameters['remaintopay'] ?? 0);
		if ($action !== 'CLOSEPAIDINVOICE' || !is_object($invoice) || $remain == 0) {
			return 0;
		}
		require_once dirname(__DIR__).'/lib/navonline.lib.php';
		$unit = max(1, getDolGlobalInt('NAVONLINE_CASH_ROUNDING_UNIT', 5));
		if (abs($remain) > $unit / 2 || !navonlineCashRoundingApplies($invoice)) {
			return 0;
		}
		if ($invoice->setPaid($user, '', 'Kerekítés ('.$unit.' Ft): '.price(-$remain).' Ft') < 0) {
			$this->error = $invoice->error;
			return -1;
		}
		dol_syslog('NavOnline cash rounding: invoice '.$invoice->ref.' closed as paid, rounding difference '.(-$remain));
		return 1;
	}

	/**
	 * Supplier invoices recorded from NAV (llx_navonline_inbound) keep the line amounts reported by the supplier (VAT
	 * rounded by the supplier, HUF amounts at the supplier's rate). update_price() would "fix" such lines by
	 * recomputing them from the unit price; returning 1 skips that check for these invoices only. Lines the user
	 * edits are still recalculated by updateline().
	 *
	 * @param array<string,mixed> $parameters  fk_element = line id
	 * @param CommonObject        $object      Document
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 1 = keep the stored line amounts, 0 = standard
	 */
	public function changeRoundingMode($parameters, &$object, &$action, $hookmanager)
	{
		static $cache = array();

		if (!is_object($object) || ($object->element ?? '') !== 'invoice_supplier' || empty($object->id)) {
			return 0;
		}
		if (empty($cache[$object->id])) {	// only positive results are cached (the link is created right after the lines)
			$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'navonline_inbound WHERE fk_facture_fourn = '.((int) $object->id).' LIMIT 1');
			$cache[$object->id] = ($res && $this->db->num_rows($res) > 0);
		}
		return $cache[$object->id] ? 1 : 0;
	}

	/**
	 * Left menu: the "Payments" entry under the customer invoices is named "Kiegyenlítések" (a customer invoice is
	 * settled, not paid out). The core uses the same "Payments" key for supplier invoices, salaries, taxes..., which
	 * keep it; so only this entry (leftmenu customers_bills_payment) is renamed here, without a core change.
	 *
	 * @param array<string,mixed>            $parameters  mainmenu
	 * @param array<int,array<string,mixed>> $object      Menu entries (eldy left menu)
	 * @param string                         $action      Action
	 * @param HookManager                    $hookmanager Hook manager
	 * @return int 1 = entries replaced by $this->results, 0 = nothing changed
	 */
	public function menuLeftMenuItems($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!is_array($object)) {
			return 0;
		}
		$changed = false;
		foreach ($object as $k => $it) {
			if (is_array($it) && ($it['leftmenu'] ?? '') === 'customers_bills_payment' && (int) ($it['level'] ?? -1) === 1) {
				$langs->load('navonline@navonline');
				$object[$k]['titre'] = $langs->trans('NavOnlineCustomerPaymentsMenu');
				$changed = true;
			}
		}
		if (!$changed) {
			return 0;
		}
		$this->results = $object;	// HookManager: ->results becomes ->resArray, which replaces the menu (return 1)
		return 1;
	}

	/**
	 * New partner form (societe/card.php, action create): a "Shipping method" select, saved by the COMPANY_CREATE
	 * trigger (field navonline_shipping_method_id). After creation it is edited on the customer tab as usual.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $form;

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('thirdpartycard', $contexts, true) || !in_array($action, array('create', 'add'), true) || !(isModEnabled('shipping') || isModEnabled('expedition'))) {
			return 0;
		}
		if (!is_object($form)) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
			$form = new Form($this->db);
		}
		$langs->loadLangs(array('sendings', 'deliveries'));
		$selected = GETPOSTISSET('navonline_shipping_method_id') ? GETPOSTINT('navonline_shipping_method_id') : getDolGlobalInt('NAVONLINE_DEFAULT_SHIPPING_METHOD');
		ob_start();
		$ret = $form->selectShippingMethod($selected, 'navonline_shipping_method_id', '', 1, '', 1);
		$select = ob_get_clean().(is_string($ret) ? $ret : '');
		$this->resprints = '<tr><td>'.$langs->trans('SendingMethod').'</td><td colspan="3" class="maxwidthonsmartphone">'.img_picto('', 'dolly', 'class="pictofixedwidth"').$select.'</td></tr>';

		return 0;
	}

	/**
	 * PDF templates (invoice, order, proposal…): the unit column (PRODUCT_USE_UNITS) is 11 mm wide in the core
	 * templates, too narrow for its title "Egység" (wraps to "Egysé/g"). Widen it to 13 mm; the description column
	 * takes the rest as usual.
	 *
	 * @param array<string,mixed> $parameters  object, outputlangs…
	 * @param CommonDocGenerator  $object      PDF model
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function defineColumnField($parameters, &$object, &$action, $hookmanager)
	{
		// Never active until 2026-11-26 (no pdfgeneration context): the NavOnline templates set their own unit column
		// (navonlinePdfUnitColumn), the context is registered now only for the PDF date format, so this stays off
		return 0;
		// phpcs:ignore
		if (is_object($object) && !empty($object->cols['unit']['status']) && (float) ($object->cols['unit']['width'] ?? 0) < 13) {
			$col = $object->cols['unit'];
			$col['width'] = 13;
			$this->results = array('unit' => $col);
		}
		return 0;
	}

	/**
	 * Hide partner card rows (view, edit and create mode of societe/card.php).
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null   $object     Object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 (other hooks and the standard footer still run)
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));

		// Recurring customer and supplier invoices are not used (NAVONLINE_HIDE_RECURRING_INVOICES): hide the template list
		// menu entries and the "Convert into template invoice" buttons. The jobs RecurringInvoicesJob and
		// RecurringSupplierInvoicesJob are disabled as well.
		if (getDolGlobalInt('NAVONLINE_HIDE_RECURRING_INVOICES') && empty($GLOBALS['navonline_recurring_hidden'])) {
			$GLOBALS['navonline_recurring_hidden'] = 1;
			$sel = 'a[href*="/compta/facture/invoicetemplate_list.php"], a[href*="/compta/facture/card-rec.php"], a[href*="/fourn/facture/list-rec.php"], a[href*="/fourn/facture/card-rec.php"]';
			print "\n<!-- NavOnline: recurring invoices hidden -->\n<style>".$sel." { display: none !important; }</style>\n";
			print "<script>\njQuery(function ($) { $(".json_encode($sel).").each(function () { var w = $(this).closest('div.menu_contenu, div.divButAction'); (w.length ? w : $(this)).hide(); }); });\n</script>\n";
		}

		// Customer / supplier payment form: payment mode "Készpénz" (LIQ) -> the cash account (NAVONLINE_CASH_BANK_ACCOUNT),
		// another mode -> back to a bank account. Dolibarr accepts only LIQ lines on a cash account.
		$cashAcc = getDolGlobalInt('NAVONLINE_CASH_BANK_ACCOUNT');
		if ($cashAcc > 0 && empty($GLOBALS['navonline_cash_js']) && preg_match('#/(compta/paiement|fourn/facture/paiement)\.php$#', (string) ($_SERVER['PHP_SELF'] ?? ''))) {
			$GLOBALS['navonline_cash_js'] = 1;
			$liq = array('LIQ', (string) dol_getIdFromCode($this->db, 'LIQ', 'c_paiement', 'code', 'id', 1));
			print "\n<!-- NavOnline: cash payments to the cash account -->\n<script>\njQuery(function ($) {\n";
			print "\tvar cash = ".json_encode((string) $cashAcc).", liq = ".json_encode($liq).";\n";
			print "\tvar mode = $('#selectpaiementcode, #selectpaiementid').first(), acc = $('#selectaccountid');\n";
			print "\tif (!mode.length || !acc.length) { return; }\n";
			print "\tvar other = (acc.val() && acc.val() !== cash && acc.val() > 0) ? acc.val() : '';\n";
			print "\tfunction firstBank() { var v = ''; acc.find('option').each(function () { if (!v && this.value > 0 && this.value !== cash) { v = this.value; } }); return v; }\n";
			print "\tfunction apply() {\n";
			print "\t\tif (liq.indexOf(String(mode.val())) !== -1) { if (acc.val() !== cash) { if (acc.val() > 0) { other = acc.val(); } acc.val(cash).trigger('change'); } }\n";
			print "\t\telse if (acc.val() === cash) { acc.val(other || firstBank()).trigger('change'); }\n";
			print "\t}\n\tapply();\n\tmode.on('change', apply);\n});\n</script>\n";
		}

		// New customer invoice: the invoice date is the day of validation (FAC_FORCE_DATE_VALIDATION), so the date field
		// of the creation form is read-only (today) with a hint; the draft card has no date edit with that option
		if (getDolGlobalString('FAC_FORCE_DATE_VALIDATION') && empty($GLOBALS['navonline_invdate_js']) && preg_match('#/compta/facture/card\.php$#', (string) ($_SERVER['PHP_SELF'] ?? '')) && $action === 'create') {
			$GLOBALS['navonline_invdate_js'] = 1;
			$langs->load('navonline@navonline');
			$js = <<<'JS'
<!-- NavOnline: invoice date = validation date -->
<script>
jQuery(function ($) {
	var d = $('#re');
	if (!d.length) { return; }
	var td = d.closest('td');
	try { d.datepicker('destroy'); } catch (e) {}
	d.prop('readonly', true).css('background-color', '#eee').attr('tabindex', '-1');
	td.find('button, img.ui-datepicker-trigger, a[id^="reButton"]').hide();
	td.append(' <span class="opacitymedium">' + NAVHINT + '</span>');
});
</script>
JS;
			print "\n".str_replace('NAVHINT', json_encode($langs->trans('NavOnlineInvoiceDateIsValidation')), $js)."\n";
		}

		if (!in_array('thirdpartycard', $contexts, true)) {
			return 0;
		}
		$wanted =array_filter(array_map('trim', explode(',', getDolGlobalString('NAVONLINE_HIDE_THIRDPARTY_FIELDS'))));
		$ids = $labels = array();
		$langs->loadLangs(array('companies'));
		foreach ($wanted as $f) {
			if (isset(self::THIRDPARTY_FIELDS[$f])) {
				$ids[] = '#'.self::THIRDPARTY_FIELDS[$f][0];
				$labels[] = $langs->transnoentitiesnoconv(self::THIRDPARTY_FIELDS[$f][1]);
			}
		}
		if (!$ids) {
			return 0;
		}

		print "\n<!-- NavOnline: hidden partner fields -->\n<script>\njQuery(function ($) {\n";
		// edit / create mode: the row of the input
		print "\t$(".json_encode(implode(',', $ids)).").closest('tr').hide();\n";
		// view mode: rows whose label cell is exactly one of the labels
		print "\tvar labels = ".json_encode(array_values($labels)).";\n";
		print "\t$('table tr').each(function () { var td = $(this).children('td').first(); if (td.length && labels.indexOf($.trim(td.text())) !== -1) { $(this).hide(); } });\n";
		print "});\n</script>\n";

		return 0;
	}
	/**
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonInvoice       $object     Invoice
	 * @return bool The credit note card (customer or supplier) where the returned-goods action applies
	 */
	private function navReturnApplies($parameters, $object)
	{
		global $user;
		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!array_intersect(array('invoicecard', 'invoicesuppliercard'), $contexts)) {
			return false;
		}
		dol_include_once('/navonline/class/navonlinereturn.class.php');
		return NavOnlineReturn::isCandidate($object) && $user->hasRight('stock', 'mouvement', 'creer');
	}

	/**
	 * Visszáru: make the stock movements of a credit note (after the confirmation dialog).
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonInvoice       $object      Invoice
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		$this->dijbekeroReminder($parameters, $object, $action);
		if ($action !== 'confirm_navvisszaru' || GETPOST('confirm', 'alpha') !== 'yes' || !$this->navReturnApplies($parameters, $object)) {
			return 0;
		}
		$ret = new NavOnlineReturn($this->db);
		$n = $ret->run($object, GETPOSTINT('navwarehouse') ?: $ret->defaultWarehouse(), $user);
		if ($n > 0) {
			setEventMessages((NavOnlineReturn::isIncoming($object) ? 'Visszáru bevételezve' : 'Visszáru kiadva').': '.$n.' tétel', null, 'mesgs');
		} else {
			setEventMessages('A visszáru készletmozgása nem készült el', $ret->errors, 'errors');
		}
		$action = '';
		return 0;
	}

	/**
	 * Order card (view): a díjbekérő (pdf_dijbekero, <ref>_dijbekero.pdf) was made for the validated order, but no
	 * validated standard or deposit invoice is linked to it yet. The díjbekérő is not an invoice and is not reported
	 * to NAV; once the customer pays, an advance invoice (előlegszámla) has to be issued. Dolibarr orders have no
	 * payments of their own, so the reminder stays until such an invoice exists.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Order
	 * @param string              $action     Action
	 * @return void
	 */
	private function dijbekeroReminder($parameters, $object, $action)
	{
		global $langs, $user;

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if ($action !== '' || !in_array('ordercard', $contexts, true) || !is_object($object) || ($object->element ?? '') !== 'commande'
			|| empty($object->id) || (int) $object->status < 1 || !preg_match('#/commande/card\.php$#', (string) ($_SERVER['PHP_SELF'] ?? ''))) {
			return;
		}
		$base = getMultidirOutput($object);
		$ref = dol_sanitizeFileName($object->ref);
		if (empty($base) || !is_file($base.'/'.$ref.'/'.$ref.'_dijbekero.pdf')) {
			return;
		}
		// Linked customer invoice in either direction of element_element, validated (or later), standard or deposit
		$p = $this->db->prefix();
		$id = (int) $object->id;
		$sql = 'SELECT f.rowid FROM '.$p.'element_element ee JOIN '.$p.'facture f ON';
		$sql .= " (ee.sourcetype = 'commande' AND ee.fk_source = ".$id." AND ee.targettype = 'facture' AND f.rowid = ee.fk_target)";
		$sql .= " OR (ee.targettype = 'commande' AND ee.fk_target = ".$id." AND ee.sourcetype = 'facture' AND f.rowid = ee.fk_source)";
		$sql .= ' WHERE f.fk_statut > 0 AND f.type IN (0, 3) LIMIT 1';
		$res = $this->db->query($sql);
		if (!$res || $this->db->num_rows($res) > 0) {
			return;
		}
		$langs->load('navonline@navonline');
		$msg = $langs->trans('NavOnlineDijbekeroNoInvoice');
		if (isModEnabled('invoice') && $user->hasRight('facture', 'creer')) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?action=create&origin=commande&originid='.$id.'&socid='.((int) $object->socid);
			$msg .= ' <a href="'.dol_escape_htmltag($url).'">'.$langs->trans('NavOnlineDijbekeroCreateInvoice').'</a>';
		}
		setEventMessages($msg, null, 'warnings');
	}

	/**
	 * Confirmation dialog of the returned-goods action (warehouse choice when there is more than one).
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonInvoice       $object      Invoice
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $form;
		if ($action !== 'navvisszaru' || !$this->navReturnApplies($parameters, $object)) {
			return 0;
		}
		if (!is_object($form)) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
			$form = new Form($this->db);
		}
		$ret = new NavOnlineReturn($this->db);
		$lines = $ret->lines($object);
		$wh = $ret->warehouses();
		$in = NavOnlineReturn::isIncoming($object);
		$items = array();
		foreach ($lines as $l) {
			$items[] = dol_escape_htmltag($l['ref'] ?: ('#'.$l['fk_product'])).': '.price($l['qty'], 0, '', 0, 0, -1);
		}
		$question = ($in ? 'A jóváíró termék-tételei visszakerülnek a raktárba (készletnövelés):' : 'A szállítói jóváíró termék-tételei kikerülnek a raktárból (készletcsökkentés):').'<br>'.implode('<br>', $items).'<br><br>Csak akkor hagyd jóvá, ha az áru ténylegesen '.($in ? 'visszaérkezett' : 'visszament a szállítóhoz').'.';
		$formq = array();
		if (count($wh) > 1) {
			$formq[] = array('type' => 'select', 'name' => 'navwarehouse', 'label' => 'Raktár', 'values' => $wh, 'default' => $ret->defaultWarehouse());
		} else {
			$formq[] = array('type' => 'hidden', 'name' => 'navwarehouse', 'value' => (string) $ret->defaultWarehouse());
		}
		$url = $_SERVER['PHP_SELF'].'?facid='.((int) $object->id);
		$this->resprints = $form->formconfirm($url, $in ? 'Visszáru bevételezése' : 'Visszáru kiadása', $question, 'confirm_navvisszaru', $formq, 'yes', 1, 0, 500);
		return 0;
	}

	/**
	 * "Visszáru bevételezése" / "Visszáru kiadása" button on a validated credit note with product lines; once done,
	 * a disabled button with the date.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonInvoice       $object      Invoice
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->navReturnApplies($parameters, $object)) {
			return 0;
		}
		$ret = new NavOnlineReturn($this->db);
		if (!$ret->lines($object)) {
			return 0;
		}
		$in = NavOnlineReturn::isIncoming($object);
		$label = $in ? 'Visszáru bevételezése' : 'Visszáru kiadása';
		$done = $ret->done($object);
		if ($done) {
			print '<a class="butActionRefused classfortooltip" href="#" title="'.dol_escape_htmltag(($in ? 'Bevételezve: ' : 'Kiadva: ').dol_print_date($this->db->jdate($done->datem), 'dayhour')).'">'.$label.' ✔</a>';
		} else {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?facid='.((int) $object->id).'&action=navvisszaru&token='.newToken().'" title="'.dol_escape_htmltag($in ? 'Az áru visszaérkezett: a tételek visszakerülnek a raktárba' : 'Az áru visszament a szállítóhoz: a tételek kikerülnek a raktárból').'">'.$label.'</a>';
		}
		return 0;
	}
}
