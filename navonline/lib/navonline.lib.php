<?php
/* Copyright (C) 2026
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
 */

/**
 * \file    htdocs/custom/navonline/lib/navonline.lib.php
 * \ingroup navonline
 * \brief   Library functions for NavOnline
 */

/**
 * NAV test endpoint. Intentionally not configurable.
 */
const NAVONLINE_TEST_API_URL = 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3';

/**
 * NAV production endpoint. Allowed by the project rules (CLAUDE.md rule 3).
 * Production is used only when it is selected in the module setup (NAVONLINE_ENVIRONMENT = prod) and
 * config/credentials.prod.php has been filled in by the user. Switching to production turns dry-run on.
 * Set NAVONLINE_PROD_ENABLED to false to lock production again.
 */
const NAVONLINE_PROD_ENABLED = true;
const NAVONLINE_PROD_API_URL = 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3';

/** Environments */
const NAVONLINE_ENV_TEST = 'test';
const NAVONLINE_ENV_PROD = 'prod';

/** Default go-live date of production reporting (invoices issued on or after it) */
const NAVONLINE_DEFAULT_GOLIVE_DATE = '2027-01-01';

/**
 * Selected environment (module setup). Anything but 'prod' means test.
 *
 * @return string NAVONLINE_ENV_TEST|NAVONLINE_ENV_PROD
 */
function navonlineGetEnvironment()
{
	$env = function_exists('getDolGlobalString') ? getDolGlobalString('NAVONLINE_ENVIRONMENT', NAVONLINE_ENV_TEST) : NAVONLINE_ENV_TEST;
	return $env === NAVONLINE_ENV_PROD ? NAVONLINE_ENV_PROD : NAVONLINE_ENV_TEST;
}

/**
 * Whether production can be used at all in this installation.
 *
 * @return bool
 */
function navonlineIsProdAvailable()
{
	return NAVONLINE_PROD_ENABLED === true && NAVONLINE_PROD_API_URL !== '';
}

/**
 * NAV API base URL of the selected environment.
 *
 * @param string|null $env Environment (default: selected)
 * @return string Empty string when the environment is not available (production while disabled)
 */
function navonlineGetApiUrl($env = null)
{
	$env = $env === null ? navonlineGetEnvironment() : $env;
	if ($env === NAVONLINE_ENV_PROD) {
		return navonlineIsProdAvailable() ? NAVONLINE_PROD_API_URL : '';
	}
	return NAVONLINE_TEST_API_URL;
}

/**
 * Go-live date of production reporting as Y-m-d.
 *
 * @return string
 */
function navonlineGetGoLiveDate()
{
	$d = function_exists('getDolGlobalString') ? getDolGlobalString('NAVONLINE_GOLIVE_DATE', NAVONLINE_DEFAULT_GOLIVE_DATE) : NAVONLINE_DEFAULT_GOLIVE_DATE;
	return navonlineIsValidDate($d) ? $d : NAVONLINE_DEFAULT_GOLIVE_DATE;
}

/**
 * Whether a string is an existing calendar date in YYYY-MM-DD format.
 *
 * @param string $d Date
 * @return bool
 */
function navonlineIsValidDate($d)
{
	return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Whether an invoice must be reported in the given environment according to its issue date.
 * Test: always. Production: only invoices issued on or after the go-live date.
 *
 * @param int|string  $invoiceDate Invoice issue date (timestamp)
 * @param string|null $env         Environment (default: selected)
 * @return bool
 */
function navonlineIsReportableDate($invoiceDate, $env = null)
{
	$env = $env === null ? navonlineGetEnvironment() : $env;
	if ($env !== NAVONLINE_ENV_PROD) {
		return true;
	}
	return dol_print_date($invoiceDate, '%Y-%m-%d') >= navonlineGetGoLiveDate();
}

/**
 * Dry-run (trial operation): XML is built, validated and stored, but nothing is sent to NAV
 * and NAV is not called at all (no credentials needed).
 *
 * @return bool
 */
function navonlineIsDryRun()
{
	return function_exists('getDolGlobalInt') && getDolGlobalInt('NAVONLINE_DRY_RUN') === 1;
}

/**
 * Alert recipients (module setup, comma separated).
 *
 * @return string[]
 */
function navonlineGetAlertRecipients()
{
	$raw = function_exists('getDolGlobalString') ? getDolGlobalString('NAVONLINE_ALERT_EMAIL') : '';
	$list = array();
	foreach (preg_split('/[,;\s]+/', $raw) as $addr) {
		if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
			$list[] = $addr;
		}
	}
	return array_values(array_unique($list));
}

/**
 * After how many hours an unfinished queue row (pending / sent / annulment requested) is alerted.
 *
 * @return int
 */
function navonlineGetAlertStaleHours()
{
	$h = function_exists('getDolGlobalInt') ? getDolGlobalInt('NAVONLINE_ALERT_STALE_HOURS', 12) : 12;
	return $h > 0 ? $h : 12;
}

/**
 * HTML badge of an environment: green for test, red for production.
 *
 * @param string $env Environment
 * @return string
 */
function navonlineEnvironmentBadge($env)
{
	$class = $env === NAVONLINE_ENV_PROD ? 'badge-status8' : 'badge-status4';
	return '<span class="badge '.$class.'">'.dol_escape_htmltag(navonlineEnvironmentLabel($env)).'</span>';
}

/**
 * HTML badge shown while dry-run is active.
 *
 * @return string
 */
function navonlineDryRunBadge()
{
	return '<span class="badge badge-status1">PRÓBAÜZEM / DRY-RUN</span>';
}

/**
 * SQL condition restricting llx_navonline_invoice rows to the selected environment.
 *
 * @param string $alias Table alias with trailing dot ('q.') or ''
 * @return string " AND ...environment = '...'"
 */
function navonlineEnvSql($alias = '')
{
	// Value comes from navonlineGetEnvironment() which only returns one of two constants
	return ' AND '.$alias."environment = '".navonlineGetEnvironment()."'";
}

/**
 * Human readable label of an environment (for badges and logs).
 *
 * @param string $env Environment
 * @return string
 */
function navonlineEnvironmentLabel($env)
{
	return $env === NAVONLINE_ENV_PROD ? 'ÉLES / PRODUCTION' : 'TESZT / TEST';
}

/**
 * Default location of the official NAV 3.0 XSD schemas.
 */
const NAVONLINE_DEFAULT_XSD_DIR = 'C:/dev/Online-Invoice/src/schemas/nav/gov/hu/OSA';

/**
 * Directory of the official NAV 3.0 XSD schemas (invoiceData.xsd, invoiceBase.xsd).
 *
 * @return string
 */
function navonlineGetXsdDir()
{
	$dir = function_exists('getDolGlobalString') ? getDolGlobalString('NAVONLINE_XSD_DIR') : '';
	return $dir !== '' ? $dir : NAVONLINE_DEFAULT_XSD_DIR;
}

/**
 * Absolute path of the credentials file of an environment (filled in manually by the user).
 * Test: config/credentials.test.php, or the legacy config/credentials.php if the former does not exist.
 * Production: config/credentials.prod.php only (never falls back to the test file).
 * The module never prints or logs the contents of these files.
 *
 * @param string|null $env Environment (default: selected)
 * @return string
 */
function navonlineGetCredentialsPath($env = null)
{
	$env = $env === null ? navonlineGetEnvironment() : $env;
	$dir = dirname(__DIR__).'/config';
	if ($env === NAVONLINE_ENV_PROD) {
		return $dir.'/credentials.prod.php';
	}
	return is_file($dir.'/credentials.test.php') ? $dir.'/credentials.test.php' : $dir.'/credentials.php';
}

/**
 * Whether the credentials file exists (existence check only, the file is not read here).
 *
 * @param string|null $env Environment (default: selected)
 * @return bool
 */
function navonlineCredentialsFileExists($env = null)
{
	return is_file(navonlineGetCredentialsPath($env));
}

/**
 * Prepare admin pages header
 *
 * @return array<array{0:string,1:string,2:string}>
 */
function navonlineAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('navonline@navonline');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/navonline/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/navonline/admin/clauses.php', 1);
	$head[$h][1] = $langs->trans('NavOnlineClausesTab');
	$head[$h][2] = 'clauses';
	$h++;

	$head[$h][0] = dol_buildpath('/navonline/oss.php', 1);
	$head[$h][1] = $langs->trans('NavOnlineOssTab');
	$head[$h][2] = 'oss';
	$h++;

	$head[$h][0] = dol_buildpath('/navonline/admin/epr.php', 1);
	$head[$h][1] = $langs->trans('NavOnlineEprTab');
	$head[$h][2] = 'epr';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'navonline@navonline');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'navonline@navonline', 'remove');

	return $head;
}

/**
 * Cash rounding (Hungarian rule for cash payments: to 5 Ft; 1-2 down, 3-7 to 5, 8-9 up). Applies to HUF customer
 * invoices whose payment mode is one of NAVONLINE_CASH_ROUNDING_MODES (payment mode codes, default UTV = utánvét).
 * The invoice amounts and the NAV data stay exact; only the amount payable is rounded (PDF), and a payment within the
 * rounding difference closes the invoice (ActionsNavonline::createPayment).
 *
 * @param Facture $invoice Customer invoice
 * @return bool
 */
function navonlineCashRoundingApplies($invoice)
{
	global $conf;
	if (!is_object($invoice) || ($invoice->element ?? '') !== 'facture') {
		return false;
	}
	$cur = strtoupper((string) (!empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : $conf->currency));
	if ($cur !== 'HUF') {
		return false;
	}
	$code = (string) ($invoice->mode_reglement_code ?? '');
	if ($code === '' && !empty($invoice->mode_reglement_id)) {
		$res = $invoice->db->query('SELECT code FROM '.$invoice->db->prefix().'c_paiement WHERE id = '.((int) $invoice->mode_reglement_id));
		$o = $res ? $invoice->db->fetch_object($res) : null;
		$code = $o ? (string) $o->code : '';
	}
	$modes = array_filter(array_map('trim', explode(',', getDolGlobalString('NAVONLINE_CASH_ROUNDING_MODES', 'UTV'))));
	return $code !== '' && in_array($code, $modes, true);
}

/**
 * @param float $amount Amount
 * @return float Amount rounded to NAVONLINE_CASH_ROUNDING_UNIT (default 5)
 */
function navonlineCashRound($amount)
{
	$unit = max(1, getDolGlobalInt('NAVONLINE_CASH_ROUNDING_UNIT', 5));
	$sign = $amount < 0 ? -1 : 1;
	return $sign * floor(abs((float) $amount) / $unit + 0.5) * $unit;
}

/**
 * NAV operation of a credit note (Dolibarr "Jóváírás", Facture::TYPE_CREDIT_NOTE): 'STORNO' (érvénytelenítő számla) or
 * 'MODIFY' (módosító számla); '' for other invoice types. The operation already reported (llx_navonline_invoice) wins;
 * before reporting the rule of NavOnlineXmlBuilder::loadReference() applies: the first validated credit note of the
 * source that reverses its whole net and VAT (in HUF and in the invoice currency) is a STORNO, unless
 * NAVONLINE_STORNO_MODE is 'never'. Keep the two in step.
 *
 * @param DoliDB  $db      Database handler
 * @param Facture $invoice Customer invoice
 * @return string
 */
function navonlineCreditNoteOperation($db, $invoice)
{
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	if (!is_object($invoice) || (int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
		return '';
	}
	if (!empty($invoice->id)) {
		$res = $db->query('SELECT operation FROM '.$db->prefix().'navonline_invoice WHERE fk_facture = '.((int) $invoice->id).' AND entity = '.((int) $invoice->entity).navonlineEnvSql());
		$o = $res ? $db->fetch_object($res) : null;
		if ($o && in_array($o->operation, array('STORNO', 'MODIFY'), true)) {
			return $o->operation;
		}
	}
	if (getDolGlobalString('NAVONLINE_STORNO_MODE', 'auto') === 'never' || empty($invoice->fk_facture_source)) {
		return 'MODIFY';
	}
	$source = new Facture($db);
	if ($source->fetch((int) $invoice->fk_facture_source) <= 0) {
		return 'MODIFY';
	}
	// An earlier validated credit note of the same source makes this one a further modification
	$sql = 'SELECT COUNT(*) as nb FROM '.$db->prefix().'facture WHERE fk_facture_source = '.((int) $source->id).' AND type = '.Facture::TYPE_CREDIT_NOTE;
	$sql .= ' AND fk_statut > 0'.(!empty($invoice->id) ? ' AND rowid < '.((int) $invoice->id) : '');
	$res = $db->query($sql);
	$o = $res ? $db->fetch_object($res) : null;
	if ($o && (int) $o->nb > 0) {
		return 'MODIFY';
	}
	$full = abs((float) $source->total_ht + (float) $invoice->total_ht) < 0.005
		&& abs((float) $source->total_tva + (float) $invoice->total_tva) < 0.005
		&& abs((float) $source->multicurrency_total_ht + (float) $invoice->multicurrency_total_ht) < 0.005
		&& abs((float) $source->multicurrency_total_tva + (float) $invoice->multicurrency_total_tva) < 0.005;
	return $full ? 'STORNO' : 'MODIFY';
}
/**
 * Végszámla (final invoice): a standard customer invoice with at least one deposit deduction line (a line from a
 * discount whose source is a deposit invoice). Returns the deposit invoice refs, empty array for any other invoice.
 *
 * @param DoliDB  $db      Database handler
 * @param Facture $invoice Customer invoice
 * @return string[] Deposit invoice refs (rowid => ref)
 */
function navonlineFinalInvoiceDeposits($db, $invoice)
{
	static $cache = array();
	if (!is_object($invoice) || ($invoice->element ?? '') !== 'facture' || (int) $invoice->type !== 0 || empty($invoice->id)) {
		return array();
	}
	if (!isset($cache[$invoice->id])) {
		$p = $db->prefix();
		$sql = 'SELECT DISTINCT src.rowid, src.ref FROM '.$p.'facturedet d JOIN '.$p.'societe_remise_except re ON re.rowid = d.fk_remise_except';
		$sql .= ' JOIN '.$p.'facture src ON src.rowid = re.fk_facture_source WHERE d.fk_facture = '.((int) $invoice->id).' AND src.type = 3 ORDER BY src.rowid';
		$res = $db->query($sql);
		$refs = array();
		while ($res && ($o = $db->fetch_object($res))) {
			$refs[(int) $o->rowid] = $o->ref;
		}
		$cache[$invoice->id] = $refs;
	}
	return $cache[$invoice->id];
}

/**
 * Final invoice with nothing left to pay (total 0: the deposits cover it): "Pénzügyi teljesítést nem igényel".
 *
 * @param DoliDB  $db      Database handler
 * @param Facture $invoice Customer invoice
 * @return bool
 */
function navonlineFinalInvoiceNoPayment($db, $invoice)
{
	return navonlineFinalInvoiceDeposits($db, $invoice) && abs((float) $invoice->total_ttc) < 0.005 && abs((float) ($invoice->multicurrency_total_ttc ?? 0)) < 0.005;
}

/**
 * Incoterms of a document for its PDF, under the lines on the left: "Szállítási feltétel (Incoterms®): DAP Kristiansand"
 * (+ " / " second language label). '' when the document has no Incoterms (module off or not filled in).
 *
 * @param DoliDB         $db             Database handler
 * @param CommonObject   $object         Proposal, order, shipment or invoice (fk_incoterms, location_incoterms)
 * @param Translate      $outputlangs    Output language
 * @param Translate|null $outputlangsbis Second language
 * @return string
 */
function navonlineIncotermsText($db, $object, $outputlangs, $outputlangsbis = null)
{
	if (!isModEnabled('incoterm') || empty($object->fk_incoterms)) {
		return '';
	}
	$res = $db->query('SELECT code FROM '.$db->prefix().'c_incoterms WHERE rowid = '.((int) $object->fk_incoterms));
	$o = $res ? $db->fetch_object($res) : null;
	if (!$o || trim((string) $o->code) === '') {
		return '';
	}
	$outputlangs->load('navonline@navonline');
	$label = $outputlangs->transnoentities('NavOnlinePdfIncoterms');
	if (is_object($outputlangsbis)) {
		$outputlangsbis->load('navonline@navonline');
		$bis = $outputlangsbis->transnoentities('NavOnlinePdfIncoterms');
		if ($bis !== $label) {
			$label .= ' / '.$bis;
		}
	}
	return $outputlangs->convToOutputCharset($label.': '.trim($o->code.' '.trim((string) $object->location_incoterms)));
}

/**
 * Weight of a product unit in kg (product weight with its unit: 0 kg, -3 g, 3 t, -6 mg, 98 ounce, 99 pound).
 *
 * @param float $weight Weight
 * @param int   $unit   Weight unit (product weight_units)
 * @return float
 */
function navonlineKg($weight, $unit)
{
	$unit = (int) $unit;
	if ($unit === 98) {
		return (float) $weight * 0.0283495;
	}
	if ($unit === 99) {
		return (float) $weight * 0.45359237;
	}
	return (float) $weight * pow(10, $unit);
}

/**
 * Export weights of shipments: net = sum of the shipped quantities x product weight; pallets = "Raklapok száma" x
 * "Egy raklap súlya" of each shipment (empty weight: NAVONLINE_PALLET_WEIGHT_KG, default 25 kg); gross = net + pallets.
 *
 * @param DoliDB $db  Database handler
 * @param int[]  $ids Shipment ids
 * @return array{net:float,pallets:int,pallet_kg:float,gross:float}
 */
function navonlineShipmentWeights($db, array $ids)
{
	$out = array('net' => 0.0, 'pallets' => 0, 'pallet_kg' => 0.0, 'gross' => 0.0);
	$ids = array_filter(array_map('intval', $ids));
	if (!$ids) {
		return $out;
	}
	$in = implode(',', $ids);
	$sql = 'SELECT ed.qty, p.weight, p.weight_units FROM '.$db->prefix().'expeditiondet ed';
	$sql .= ' LEFT JOIN '.$db->prefix().'commandedet cd ON cd.rowid = ed.fk_elementdet';
	$sql .= ' JOIN '.$db->prefix().'product p ON p.rowid = COALESCE(NULLIF(ed.fk_product, 0), cd.fk_product)';
	$sql .= ' WHERE ed.fk_expedition IN ('.$in.') AND p.weight > 0';
	$res = $db->query($sql);
	while ($res && ($o = $db->fetch_object($res))) {
		$out['net'] += (float) $o->qty * navonlineKg((float) $o->weight, (int) $o->weight_units);
	}
	$def = (float) getDolGlobalString('NAVONLINE_PALLET_WEIGHT_KG', '25');
	$res = $db->query('SELECT navonline_pallets, navonline_pallet_kg FROM '.$db->prefix().'expedition_extrafields WHERE fk_object IN ('.$in.')');
	while ($res && ($o = $db->fetch_object($res))) {
		$n = (int) $o->navonline_pallets;
		$kg = ((float) $o->navonline_pallet_kg > 0) ? (float) $o->navonline_pallet_kg : $def;
		$out['pallets'] += $n;
		$out['pallet_kg'] += $n * $kg;
	}
	$out['gross'] = $out['net'] + $out['pallet_kg'];
	return $out;
}

/**
 * @param DoliDB $db       Database handler
 * @param string $table    'commandedet' or 'facturedet'
 * @param string $fk       Parent column
 * @param int    $parentId Order / invoice id
 * @return bool The document has an export (EAM) line
 */
function navonlineHasEamLine($db, $table, $fk, $parentId)
{
	$res = $db->query('SELECT 1 FROM '.$db->prefix().$table.' WHERE '.$fk.' = '.((int) $parentId)." AND UPPER(vat_src_code) = 'EAM' LIMIT 1");
	return $res && $db->num_rows($res) > 0;
}

/**
 * Lines "Bruttó súly: 302,5 kg (2 raklap)" / "Nettó súly: 252,5 kg" for the PDF of an export shipment or invoice.
 * Gross only when known (shipments); '' lines are omitted.
 *
 * @param array{net:float,pallets:int,pallet_kg:float,gross:float}|null $w              Weights (null = none)
 * @param float|null                                                      $netOnly        Net weight when no shipment
 * @param Translate                                                       $outputlangs    Langs
 * @param Translate|null                                                  $outputlangsbis Second language
 * @return string[]
 */
function navonlineWeightLines($w, $netOnly, $outputlangs, $outputlangsbis = null)
{
	$outputlangs->load('navonline@navonline');
	$lbl = function ($key) use ($outputlangs, $outputlangsbis) {
		$t = $outputlangs->transnoentities($key);
		if (is_object($outputlangsbis)) {
			$outputlangsbis->load('navonline@navonline');
			$b = $outputlangsbis->transnoentities($key);
			if ($b !== $t) {
				$t .= ' / '.$b;
			}
		}
		return $t;
	};
	$fmt = function ($kg) use ($outputlangs) {
		$dec = $outputlangs->transnoentitiesnoconv('SeparatorDecimal');
		$s = number_format(round((float) $kg, 2), 2, $dec, $outputlangs->transnoentitiesnoconv('SeparatorThousand'));
		$s = rtrim(rtrim($s, '0'), $dec);
		return $s.' kg';
	};
	$out = array();
	if (is_array($w)) {
		if ($w['net'] <= 0 && $w['gross'] <= 0) {
			return array();
		}
		$out[] = $lbl('NavOnlineWeightGross').': '.$fmt($w['gross']).($w['pallets'] > 0 ? ' ('.$w['pallets'].' '.$outputlangs->transnoentities((int) $w['pallets'] == 1 ? 'NavOnlinePallet' : 'NavOnlinePallets').')' : '');
		$out[] = $lbl('NavOnlineWeightNet').': '.$fmt($w['net']);
	} elseif ($netOnly !== null && $netOnly > 0) {
		$out[] = $lbl('NavOnlineWeightNet').': '.$fmt($netOnly);
	}
	return array_map(array($outputlangs, 'convToOutputCharset'), $out);
}

/** Invoice texts that depend on the payment term: setting => default payment term codes */
const NAVONLINE_PT_CLAUSES = array(
	'retention' => array('NAVONLINE_PT_RETENTION', '15D,30D,45D,60D'),
	'paid' => array('NAVONLINE_PT_PAID', 'RECEP,PT_ORDER,PT_DELIVERY,PT_5050,DEP30PCTDEL'),
);

/**
 * Payment term codes for which an invoice text is switched on (NavOnline setup > Számlazáradékok). A setting never
 * saved gives the defaults; '-' = switched off for every payment term.
 *
 * @param string $kind 'retention' (title retention clause) or 'paid' ("Paid / Fizetve" stamp)
 * @return string[] Upper-case payment term codes
 */
function navonlinePaymentTermCodes($kind)
{
	if (!isset(NAVONLINE_PT_CLAUSES[$kind])) {
		return array();
	}
	list($const, $default) = NAVONLINE_PT_CLAUSES[$kind];
	$val = getDolGlobalString($const, $default);
	if ($val === '-') {
		return array();
	}
	return array_values(array_filter(array_map('trim', explode(',', strtoupper($val)))));
}

/**
 * @param string $kind 'retention' or 'paid'
 * @param string $code Payment term code
 * @return bool The invoice text is switched on for this payment term
 */
function navonlinePaymentTermHas($kind, $code)
{
	return $code !== '' && in_array(strtoupper($code), navonlinePaymentTermCodes($kind), true);
}

/**
 * Delivery address of a purchase document (purchase order, price request): the warehouse
 * NAVONLINE_PO_DELIVERY_WAREHOUSE, else the only open warehouse with an address, with our company name. Country
 * printed for a foreign-language document.
 *
 * @param DoliDB    $db          Database
 * @param Translate $outputlangs Langs
 * @return string
 */
function navonlinePoDeliveryAddress($db, $outputlangs)
{
	global $mysoc;

	$id = getDolGlobalInt('NAVONLINE_PO_DELIVERY_WAREHOUSE');
	$sql = 'SELECT e.address, e.zip, e.town, c.code AS country FROM '.MAIN_DB_PREFIX.'entrepot e LEFT JOIN '.MAIN_DB_PREFIX.'c_country c ON c.rowid = e.fk_pays';
	$sql .= ' WHERE e.entity IN ('.getEntity('stock').')'.($id > 0 ? ' AND e.rowid = '.$id : " AND e.statut = 1 AND e.address IS NOT NULL AND e.address <> ''");
	$res = $db->query($sql);
	if (!$res || ($id <= 0 && $db->num_rows($res) !== 1)) {
		return '';
	}
	$w = $db->fetch_object($res);
	if (!$w || trim((string) $w->address) === '') {
		return '';
	}
	$country = $w->country ?: $mysoc->country_code;
	$parts = array($mysoc->name, trim($w->zip.' '.$w->town).', '.$w->address);
	if (substr((string) $outputlangs->defaultlang, 0, 2) !== 'hu') {
		$parts = array($mysoc->name, $w->address.', '.trim($w->zip.' '.$w->town), $outputlangs->transnoentitiesnoconv('Country'.$country));
	}
	return $outputlangs->convToOutputCharset(implode(' – ', $parts));
}

/**
 * PDF templates: unit column ("ME" / "Unit" / "Einheit", NavOnlinePdfUnitTitle) wide enough for its title, and the
 * unit of lines without one taken from their product (a line added in the UI has it; an imported or copied line may
 * not). Call from defineColumnField() after the columns are defined. The line units are set on the object in memory
 * only (as the UI would have stored them).
 *
 * @param CommonDocGenerator $model  PDF model (with $model->cols)
 * @param CommonObject       $object Document (lines)
 * @return void
 */
function navonlinePdfUnitColumn($model, $object)
{
	foreach (array('unit', 'unit_order') as $col) {	// unit_order: the delivery note (espadon)
		if (isset($model->cols[$col]) && is_array($model->cols[$col])) {
			$model->cols[$col]['title']['textkey'] = 'NavOnlinePdfUnitTitle';
			unset($model->cols[$col]['title']['label']);
			$model->cols[$col]['width'] = max(12.0, (float) ($model->cols[$col]['width'] ?? 0));
		}
	}
	navonlineFillLineUnits($object);
}

/**
 * Lines without a unit (fk_unit) get the unit of their product, in memory (see navonlinePdfUnitColumn).
 *
 * @param CommonObject $object Document (lines)
 * @return void
 */
function navonlineFillLineUnits($object)
{
	global $db;

	if (!getDolGlobalString('PRODUCT_USE_UNITS') || empty($object->lines) || !is_array($object->lines)) {
		return;
	}
	$ids = array();
	foreach ($object->lines as $line) {
		if (empty($line->fk_unit) && !empty($line->fk_product)) {
			$ids[(int) $line->fk_product] = 1;
		}
	}
	if (!$ids) {
		return;
	}
	$units = array();
	$res = $db->query('SELECT rowid, fk_unit FROM '.$db->prefix().'product WHERE rowid IN ('.implode(',', array_keys($ids)).') AND fk_unit > 0');
	while ($res && ($o = $db->fetch_object($res))) {
		$units[(int) $o->rowid] = (int) $o->fk_unit;
	}
	foreach ($object->lines as $line) {
		if (empty($line->fk_unit) && !empty($line->fk_product) && !empty($units[(int) $line->fk_product])) {
			$line->fk_unit = $units[(int) $line->fk_product];
		}
	}
}

/**
 * VAT total row label of the PDF totals: in a language whose NavOnlinePdfVatRateInParens is "yes" (English) the rate goes
 * in brackets: "Total tax 27%" -> "Total tax (27%)", "Total tax 0% (KBAET)" -> "Total tax (0%, KBAET)". Other
 * languages unchanged ("ÁFA összesen 27%").
 *
 * @param string    $label       Label as built by the template (prefix, a space, the rate, optional " (CODE)")
 * @param Translate $outputlangs Langs
 * @return string
 */
function navonlinePdfVatLabel($label, $outputlangs)
{
	if (!is_object($outputlangs)) {
		return $label;
	}
	$outputlangs->load('navonline@navonline');
	if ($outputlangs->transnoentitiesnoconv('NavOnlinePdfVatRateInParens') !== 'yes') {
		return $label;
	}
	if (preg_match('/^(.*\S)\s+(-?[0-9][0-9.,]*\s?%)(?:\s*\(([^()]+)\))?(.*)$/u', (string) $label, $m)) {
		return $m[1].' ('.$m[2].($m[3] !== '' ? ', '.$m[3] : '').')'.$m[4];
	}
	return $label;
}

/**
 * pdf_writeLinkedObjects() of core/lib/pdf.lib.php without repeating the customer's order number: the core shows a
 * linked order as "SO2611-0001 (PO-2026-4471)"; when the document already prints "Vevő rendelésszáma : PO-2026-4471"
 * on its own line, the bracketed number is left out. Same layout as the core function otherwise.
 *
 * @param TCPDF        $pdf               PDF
 * @param CommonObject $object            Document
 * @param Translate    $outputlangs       Langs
 * @param float        $posx              X
 * @param float        $posy              Y
 * @param float        $w                 Width
 * @param float        $h                 Line height
 * @param string       $align             Alignment
 * @param float        $default_font_size Font size
 * @param string       $shownRef          The customer's order number already printed on the document ('' = none)
 * @return float Y after the lines
 */
function navonlineWriteLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, $w, $h, $align, $default_font_size, $shownRef = '')
{
	$linkedobjects = pdf_getLinkedObjects($object, $outputlangs);	// May update $object->note_public
	$shownRef = trim((string) $shownRef);

	if (!empty($linkedobjects)) {
		foreach ($linkedobjects as $linkedobject) {
			$value = (string) $linkedobject["ref_value"];
			if ($shownRef !== '') {
				$value = str_replace(' ('.$shownRef.')', '', $value);
			}
			$reftoshow = $linkedobject["ref_title"].': '.$value;
			if (!empty($linkedobject["date_value"])) {
				$reftoshow .= ' / '.$linkedobject["date_value"];
			}

			$posy += 3;
			$pdf->SetXY($posx, $posy);
			$pdf->SetFont('', '', (float) $default_font_size - 2);
			$pdf->MultiCell($w, $h, $reftoshow, '', $align);
		}
	}

	return $pdf->getY();
}

/**
 * Copy of pdf_bank() of core/lib/pdf.lib.php (Dolibarr 24.0): diff it after an upgrade. NavOnline change: the title
 * ("Fizetés átutalással a következő bankszámlára:") stays bold, every other line (bank, account number, owner, IBAN,
 * BIC) is regular and one size larger than the core (the title size), the same on every document.
 *
 * @param TCPDF     $pdf               PDF
 * @param Translate $outputlangs       Langs
 * @param float     $curx              X
 * @param float     $cury              Y
 * @param Account   $account           Bank account
 * @param int       $onlynumber        1 = only the number
 * @param float     $default_font_size Base font size
 * @return float Y after the block
 */
function navonlinePdfBank($pdf, $outputlangs, $curx, $cury, $account, $onlynumber = 0, $default_font_size = 10)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formbank.class.php';

	$diffsizetitle = getDolGlobalInt('PDF_DIFFSIZE_TITLE', 3);
	$diffsizecontent = getDolGlobalInt('PDF_DIFFSIZE_CONTENT', 4);
	$navContent = $default_font_size - $diffsizetitle;	// NavOnline: the lines one size larger than the core, regular
	$pdf->SetXY($curx, $cury);

	if (empty($onlynumber)) {
		$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByTransferOnThisBankAccount').':', 0, 'L', false);
		$cury += 4;
	}

	$outputlangs->load("banks");

	// Use correct name of bank id according to country
	$bickey = "BICNumber";
	if ($account->getCountryCode() == 'IN') {
		$bickey = "SWIFT";
	}

	// Get format of bank account according to its country
	$usedetailedbban = $account->useDetailedBBAN();

	//$onlynumber=0; $usedetailedbban=1; // For tests
	if ($usedetailedbban) {
		$savcurx = $curx;

		if (empty($onlynumber)) {
			$pdf->SetFont('', '', $navContent);
			$pdf->SetXY($curx, $cury);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("Bank").': '.$outputlangs->convToOutputCharset($account->bank), 0, 'L', false);
			$cury += 3;
		}

		if (!getDolGlobalString('PDF_BANK_HIDE_NUMBER_SHOW_ONLY_BICIBAN')) {    // Note that some countries still need bank number, BIC/IBAN not enough for them
			// Note:
			// bank = code_banque (FR), sort code (GB, IR. Example: 12-34-56)
			// desk = code guichet (FR), used only when $usedetailedbban = 1
			// number = account number
			// key = check control key used only when $usedetailedbban = 1
			if (empty($onlynumber)) {
				$pdf->line($curx + 1, $cury + 1, $curx + 1, $cury + 6);
			}

			$bank_number_length = 0;
			foreach ($account->getFieldsToShow() as $val) {
				$pdf->SetXY($curx, $cury + 4);
				$pdf->SetFont('', '', $navContent);

				if ($val == 'BankCode') {
					// Bank code
					$tmplength = 18;
					$content = $account->code_banque;
				} elseif ($val == 'DeskCode') {
					// Desk
					$tmplength = 18;
					$content = $account->code_guichet;
				} elseif ($val == 'BankAccountNumber') {
					// Number
					$tmplength = 24;
					$content = $account->number;
				} elseif ($val == 'BankAccountNumberKey') {
					// Key
					$tmplength = 15;
					$content = $account->cle_rib;
				} elseif ($val == 'IBAN' || $val == 'BIC') {
					// Key
					$tmplength = 0;
					$content = '';
				} else {
					dol_print_error($account->db, 'Unexpected value for getFieldsToShow: '.$val);
					break;
				}

				if ($content == '') {
					continue;
				}

				$pdf->MultiCell($tmplength, 3, $outputlangs->convToOutputCharset($content), 0, 'C', false);
				$pdf->SetXY($curx, $cury + 1);
				$curx += $tmplength;
				$pdf->SetFont('', '', $navContent);
				$pdf->MultiCell($tmplength, 3, $outputlangs->transnoentities($account->getBankFieldLabel($val)), 0, 'C', false);
				if (empty($onlynumber)) {
					$pdf->line($curx, $cury + 1, $curx, $cury + 7);
				}

				// Only set this variable when table was printed
				$bank_number_length = 8;
			}

			$curx = $savcurx;
			$cury += $bank_number_length;
		}
	} elseif (!empty($account->number)) {
		$pdf->SetFont('', '', $navContent);
		$pdf->SetXY($curx, $cury);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("Bank").': '.$outputlangs->convToOutputCharset($account->bank), 0, 'L', false);
		$cury += 3;

		$pdf->SetFont('', '', $navContent);
		$pdf->SetXY($curx, $cury);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("BankAccountNumber").': '.$outputlangs->convToOutputCharset($account->number), 0, 'L', false);
		$cury += 3;

		if ($diffsizecontent <= 2) {
			$cury += 1;
		}
	}

	$pdf->SetFont('', '', $navContent);

	if (empty($onlynumber) && !empty($account->address)) {
		$pdf->SetXY($curx, $cury);
		$val = $outputlangs->transnoentities("Residence").': '.$outputlangs->convToOutputCharset($account->address);
		$pdf->MultiCell(100, 3, $val, 0, 'L', false);
		//$nboflines=dol_nboflines_bis($val,120);
		//$cury+=($nboflines*3)+2;
		$tmpy = $pdf->getStringHeight(100, $val);
		$cury += $tmpy;
	}

	if (!empty($account->owner_name)) {
		$pdf->SetXY($curx, $cury);
		$val = $outputlangs->transnoentities("BankAccountOwner").': '.$outputlangs->convToOutputCharset($account->owner_name);
		$pdf->MultiCell(100, 3, $val, 0, 'L', false);
		$tmpy = $pdf->getStringHeight(100, $val);
		$cury += $tmpy;
	} elseif (!$usedetailedbban) {
		$cury += 1;
	}

	// Use correct name of bank id according to country
	$ibankey = FormBank::getIBANLabel($account);

	if (!empty($account->iban)) {
		//Remove whitespaces to ensure we are dealing with the format we expect
		$ibanDisplay_temp = str_replace(' ', '', $outputlangs->convToOutputCharset($account->iban));
		$ibanDisplay = "";

		$nbIbanDisplay_temp = dol_strlen($ibanDisplay_temp);
		for ($i = 0; $i < $nbIbanDisplay_temp; $i++) {
			$ibanDisplay .= $ibanDisplay_temp[$i];
			if ($i % 4 == 3 && $i > 0) {
				$ibanDisplay .= " ";
			}
		}

		$pdf->SetFont('', '', $navContent);
		$pdf->SetXY($curx, $cury);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities($ibankey).': '.$ibanDisplay, 0, 'L', false);
		$cury += 3;
	}

	if (!empty($account->bic)) {
		$pdf->SetFont('', '', $navContent);
		$pdf->SetXY($curx, $cury);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities($bickey).': '.$outputlangs->convToOutputCharset($account->bic), 0, 'L', false);
	}

	return $pdf->getY();
}

/**
 * Bank account a document shows (as the core templates: payment mode empty or VIR, the document's account, else
 * FACTURE_RIB_NUMBER), 0 if none.
 *
 * @param CommonObject $object Document
 * @return int
 */
function navonlineDocBankId($object)
{
	if (!empty($object->mode_reglement_code) && $object->mode_reglement_code != 'VIR') {
		return 0;
	}
	if (!empty($object->fk_bank) && $object->fk_bank > 0) {
		return (int) $object->fk_bank;
	}
	if (!empty($object->fk_account) && $object->fk_account > 0) {
		return (int) $object->fk_account;
	}
	return getDolGlobalInt('FACTURE_RIB_NUMBER');
}

/**
 * Core info block without its bank lines: run $call with the document's account and FACTURE_RIB_NUMBER blanked, then
 * print the bank block with navonlinePdfBank(). For the templates that call the core drawInfoTable (eratosthene,
 * cyan).
 *
 * @param callable     $call              Function returning the Y after the core block
 * @param TCPDF        $pdf               PDF
 * @param CommonObject $object            Document
 * @param Translate    $outputlangs       Langs
 * @param float        $marge_gauche      Left margin
 * @return float Y
 */
function navonlineInfoWithBank($call, $pdf, $object, $outputlangs, $marge_gauche)
{
	global $conf, $db;

	$bankid = navonlineDocBankId($object);
	$saved = array($object->fk_account ?? null, $object->fk_bank ?? null, $conf->global->FACTURE_RIB_NUMBER ?? null);
	$object->fk_account = 0;
	$object->fk_bank = 0;
	unset($conf->global->FACTURE_RIB_NUMBER);
	try {
		$posy = $call();
	} finally {
		$object->fk_account = $saved[0];
		$object->fk_bank = $saved[1];
		if ($saved[2] !== null) {
			$conf->global->FACTURE_RIB_NUMBER = $saved[2];
		}
	}
	if ($bankid > 0) {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$account = new Account($db);
		if ($account->fetch($bankid) > 0) {
			$posy = navonlinePdfBankBlock($pdf, $outputlangs, $marge_gauche, $posy + 1, $account, 100) + 2;	// typography: framed bank block
		}
	}
	return $posy;
}

/**
 * Public note of a PDF one size smaller than the core (base - 2) and italic, as on the invoice: the core templates set
 * the note font inside write_file(), so the note is wrapped in an HTML span for the time of the PDF (writeHTMLCell).
 * A plain-text note is turned into HTML first (its line breaks kept). Restore $object->note_public with the returned
 * value after the PDF.
 *
 * @param CommonObject $object      Document
 * @param Translate    $outputlangs Langs
 * @param string       $prefix      HTML of a data line before the note (shipment tracking line): same size, not italic
 * @return ?string The note as it was
 */
function navonlinePdfNoteStyle($object, $outputlangs, $prefix = '')
{
	$saved = $object->note_public ?? null;
	$note = (string) $saved;
	if (trim($note) === '' && $prefix === '') {
		return $saved;
	}
	if (trim($note) !== '' && !dol_textishtml($note)) {
		$note = dol_nl2br(dol_escape_htmltag($note, 0, 1));
	}
	$size = 8.5;	// typography 2026-11-26: 8.5 pt regular
	$body = trim($note) !== '' ? $note : '';
	$object->note_public = '<span style="font-size:'.$size.'pt;">'.$prefix.($prefix !== '' && $body !== '' ? '<br>' : '').$body.'</span>';
	return $saved;
}

/**
 * Header of the NavOnline PDFs (top right), the same on every document (user 2026-11-26): the title with the number
 * bold at base + 3 (shrinks to stay on one line, unless $wrap), colour 0/0/60 (draft: dark red).
 *
 * @param TCPDF  $pdf               PDF
 * @param float  $posx              X
 * @param float  $posy              Y
 * @param float  $w                 Width
 * @param string $title             Title with the number
 * @param float  $default_font_size Base font size
 * @param bool   $draft             Draft (not validated)
 * @param bool   $wrap              Wrap a long title (bilingual invoice) instead of shrinking it
 * @return float Y under the title
 */
function navonlinePdfHeadTitle($pdf, $posx, $posy, $w, $title, $default_font_size, $draft = false, $wrap = false, $number = null)
{
	// Typography (2026-11-26): the number given: title 16 pt / number 12 pt on its own line (navonlinePdfHeadTitle2)
	if ($number !== null && $number !== '' && ($p = strpos($title, ' '.$number)) !== false) {
		return navonlinePdfHeadTitle2($pdf, $posx, $posy, $w, substr($title, 0, $p), substr($title, $p + 1), $draft);
	}
	$fs = $default_font_size + 3;
	$pdf->SetFont('', 'B', $fs);
	if (!$wrap) {
		while ($fs > $default_font_size && $pdf->GetStringWidth($title) > $w - 2) {
			$fs -= 0.5;
			$pdf->SetFont('', 'B', $fs);
		}
	}
	if ($draft) {
		$pdf->SetTextColor(128, 0, 0);
	} else {
		$pdf->SetTextColor(0, 0, 60);
	}
	$pdf->SetXY($posx, $posy);
	$pdf->MultiCell($w, 3, $title, '', 'R');
	$pdf->SetTextColor(0, 0, 60);
	return $pdf->GetY();
}

/**
 * Lines under the header title, the same on every document: regular at base - 2, colour 0/0/60, right-aligned, the
 * first 1.5 mm under the title, 4 mm apart (a wrapped line pushes the next ones down).
 *
 * @param TCPDF    $pdf               PDF
 * @param float    $posx              X
 * @param float    $titleBottom       Y under the title
 * @param float    $w                 Width
 * @param string[] $lines             Lines ("Label : value"), empty ones skipped
 * @param float    $default_font_size Base font size
 * @return float Y under the last line
 */
function navonlinePdfHeadLines($pdf, $posx, $titleBottom, $w, $lines, $default_font_size)
{
	// Typography (2026-11-26): label / value columns
	return navonlinePdfHeadPairs($pdf, $posx, $titleBottom, $w, $lines);
	$pdf->SetFont('', '', $default_font_size - 2);
	$pdf->SetTextColor(0, 0, 60);
	$y = $titleBottom + 1.5;
	$end = $titleBottom;
	foreach ($lines as $line) {
		if ((string) $line === '') {
			continue;
		}
		// Too long for one line: break before the bracketed part ("… : IN2611-0007" / "(dated 11/26/2026)"), not inside it
		$k = strrpos($line, ' (');
		$parts = ($k !== false && $pdf->GetStringWidth($line) > $w - 2) ? array(substr($line, 0, $k), substr($line, $k + 1)) : array($line);
		foreach ($parts as $part) {
			$pdf->SetXY($posx, $y);
			$pdf->MultiCell($w, 3, $part, '', 'R');
			$end = $pdf->GetY();
			$y = max($y + 4, $end + 0.5);
		}
	}
	return $end;
}

/**
 * How far the address boxes must move down so that the header lines end 2 mm above their titles.
 *
 * @param float $bottom      Y under the last header line
 * @param float $marge_haute Top margin
 * @return float
 */
function navonlinePdfHeadTopShift($bottom, $marge_haute, $repeatTitles = true)
{
	$boxtop = (getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 30 : 32) + $marge_haute;
	$shift = max(0, $bottom + 2 - ($boxtop - 5));
	// Next pages with repeated column titles: the titles (about 6 mm) and the currency line (4 mm) above
	// tab_top_newpage (marge_haute + 32 + top_shift) must stay under the header lines
	if ($repeatTitles && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT') && !getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
		$shift = max($shift, $bottom + 1.5 - ($marge_haute + 32 - 10));
	}
	return $shift;
}

/**
 * Header lines of the linked documents (pdf_getLinkedObjects() of the core, which may add to $object->note_public):
 * "Rendelésszámunk: SO2611-0001 / 2026.11.26.", the customer's order number left out when the document already
 * shows it on its own line; with $outputlangsbis the label is bilingual as the other invoice lines. On the customer
 * documents (invoice, shipment, proposal, order) our sales order is "Rendelésszámunk / Our sales order no. / Unsere
 * Auftragsnr." (core: RefOrder "Megrendelés ref."), also in the list the core adds to the note for several orders.
 *
 * @param CommonObject $object         Document
 * @param Translate    $outputlangs    Langs
 * @param string       $shownRef       The customer's order number already printed ('' = none)
 * @param ?Translate   $outputlangsbis Second language (bilingual invoice), null = none
 * @return string[]
 */
function navonlineLinkedObjectLines($object, $outputlangs, $shownRef = '', $outputlangsbis = null)
{
	$customerSide = in_array((string) ($object->element ?? ''), array('facture', 'commande', 'propal', 'shipping'), true);
	$savedRefOrder = $outputlangs->tab_translate['RefOrder'] ?? null;
	$notePrefix = '';
	if ($customerSide) {
		$outputlangs->load('navonline@navonline');
		$outputlangs->tab_translate['RefOrder'] = $outputlangs->transnoentitiesnoconv('NavOnlineOurSalesOrderRef');
		$notePrefix = $outputlangs->tab_translate['RefOrder'];
	}
	try {
		$linkedobjects = pdf_getLinkedObjects($object, $outputlangs);
	} finally {
		if ($customerSide) {
			if ($savedRefOrder === null) {
				unset($outputlangs->tab_translate['RefOrder']);
			} else {
				$outputlangs->tab_translate['RefOrder'] = $savedRefOrder;
			}
		}
	}
	if ($notePrefix !== '' && !empty($object->note_public)) {	// several orders: the core lists them in the note as "RefOrder :"
		$od = $outputlangs->transnoentities('OrderDate');
		$object->note_public = str_replace(array($notePrefix.' :', $od.' : '), array($notePrefix.':', $od.': '), (string) $object->note_public);
	}
	$shownRef = trim((string) $shownRef);
	$keys = $customerSide ? array('NavOnlineOurSalesOrderRef', 'RefSending', 'RefProposal', 'RefContract', 'InterRef') : array('RefOrder', 'RefSending', 'RefProposal', 'RefContract', 'InterRef');
	if (is_object($outputlangsbis)) {
		$outputlangsbis->load('navonline@navonline');
	}
	$lines = array();
	foreach ((array) $linkedobjects as $linkedobject) {
		$value = (string) ($linkedobject['ref_value'] ?? '');
		if ($shownRef !== '') {
			$value = str_replace(' ('.$shownRef.')', '', $value);
		}
		$label = (string) ($linkedobject['ref_title'] ?? '');
		if (is_object($outputlangsbis)) {
			$outputlangsbis->loadLangs(array('main', 'orders', 'sendings', 'propal', 'contracts', 'interventions'));
			$parts = array();
			foreach (explode(' / ', $label) as $part) {
				$bis = '';
				foreach ($keys as $k) {
					if ($outputlangs->transnoentities($k) === $part) {
						$bis = $outputlangsbis->transnoentities($k);
					}
				}
				$parts[] = ($bis !== '' && $bis !== $part) ? $part.' - '.$bis : $part;
			}
			$label = implode(' / ', $parts);
		}
		$line = $label.': '.$value;
		if (!empty($linkedobject['date_value'])) {
			$line .= ' / '.$linkedobject['date_value'];
		}
		$lines[] = $line;
	}
	return $lines;
}

/*
 * NavOnline PDF typography (AluLogic_szamla_tipografiai_jelolesek.pdf, 2026-11-26): FreeSans, main text #0B1215,
 * accent #3154A1 (document title and amount payable), secondary text #5B6472, table header #F2F4F7, total box
 * #EDF2FA, lines #D6DCE5 at 0.35 pt. Sizes are absolute (pt), for A4.
 */
const NAVONLINE_PDF_COLORS = array(
	'text' => array(11, 18, 21),
	'accent' => array(49, 84, 161),
	'muted' => array(91, 100, 114),
	'head' => array(242, 244, 247),
	'total' => array(237, 242, 250),
	'line' => array(214, 220, 229),
);
/** 0.35 pt in mm */
const NAVONLINE_PDF_THIN = 0.1235;

/**
 * Set a NavOnline PDF colour.
 *
 * @param TCPDF  $pdf    PDF
 * @param string $what   text, accent, muted, head, total, line
 * @param string $target text, draw or fill
 * @return void
 */
function navonlinePdfColor($pdf, $what, $target = 'text')
{
	$c = NAVONLINE_PDF_COLORS[$what] ?? NAVONLINE_PDF_COLORS['text'];
	if ($target === 'draw') {
		$pdf->SetDrawColor($c[0], $c[1], $c[2]);
	} elseif ($target === 'fill') {
		$pdf->SetFillColor($c[0], $c[1], $c[2]);
	} else {
		$pdf->SetTextColor($c[0], $c[1], $c[2]);
	}
}

/**
 * Thin (0.35 pt) line in the NavOnline line colour; navonlinePdfLineReset() sets the TCPDF default back.
 *
 * @param TCPDF $pdf PDF
 * @return void
 */
function navonlinePdfThinLine($pdf)
{
	$pdf->SetLineWidth(NAVONLINE_PDF_THIN);
	navonlinePdfColor($pdf, 'line', 'draw');
}

/**
 * @param TCPDF $pdf PDF
 * @return void
 */
function navonlinePdfLineReset($pdf)
{
	$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0, 'color' => array(128, 128, 128)));
}

/**
 * Document title, typography version: the title (e.g. "Invoice - Számla") 16 pt bold accent on one line (it shrinks
 * down to 11 pt when too long), the number under it 12 pt bold accent. Draft: dark red.
 *
 * @param TCPDF  $pdf    PDF
 * @param float  $posx   X
 * @param float  $posy   Y
 * @param float  $w      Width
 * @param string $title  Title without the number
 * @param string $number Number ('' = none)
 * @param bool   $draft  Draft
 * @return float Y under the number
 */
function navonlinePdfHeadTitle2($pdf, $posx, $posy, $w, $title, $number, $draft = false)
{
	if ($draft) {
		$pdf->SetTextColor(128, 0, 0);
	} else {
		navonlinePdfColor($pdf, 'accent');
	}
	$cpad = $pdf->getCellPaddings();
	$pdf->setCellPaddings(0, 0, 0, 0);
	foreach (array(array($title, 16.0), array($number, 12.0)) as $k => $t) {
		if ((string) $t[0] === '') {
			continue;
		}
		$fs = $t[1];
		$pdf->SetFont('', 'B', $fs);
		while ($fs > 11 && $pdf->GetStringWidth($t[0]) > $w - 1) {
			$fs -= 0.5;
			$pdf->SetFont('', 'B', $fs);
		}
		$pdf->MultiCell($w, 0, $t[0], 0, 'R', false, 1, $posx, $k ? $pdf->GetY() + 0.3 : $posy);
	}
	$pdf->setCellPaddings($cpad['L'], $cpad['T'], $cpad['R'], $cpad['B']);
	navonlinePdfColor($pdf, 'text');
	return $pdf->GetY();
}

/**
 * Header lines, typography version: label / value columns, right-aligned as a block. "Label: value" strings are
 * split at the first ": "; label 8 pt secondary colour, value 9 pt bold (9.5 pt for the emphasised ones, e.g. the
 * payment due date). The value column is as wide as the widest value (at most 55 %).
 *
 * @param TCPDF            $pdf         PDF
 * @param float            $posx        X
 * @param float            $titleBottom Y under the title
 * @param float            $w           Width
 * @param string[]         $lines       Lines
 * @param array<int,mixed> $emphasis    Keys of $lines to emphasise
 * @return float Y under the last line
 */
function navonlinePdfHeadPairs($pdf, $posx, $titleBottom, $w, $lines, $emphasis = array())
{
	$rows = array();
	foreach ($lines as $i => $line) {
		$line = (string) $line;
		if (trim($line) === '') {
			continue;
		}
		$k = strpos($line, ': ');
		$rows[] = ($k === false) ? array('', $line, isset($emphasis[$i])) : array(substr($line, 0, $k + 1), substr($line, $k + 2), isset($emphasis[$i]));
	}
	$vw = 0;
	foreach ($rows as $r) {
		$pdf->SetFont('', 'B', $r[2] ? 9.5 : 9);
		$vw = max($vw, $pdf->GetStringWidth($r[1]) + 1.5);
	}
	$vw = min($vw, $w * 0.55);
	$lw = $w - $vw - 1.5;
	$y = $titleBottom + 2;
	$pad = $pdf->getCellPaddings();
	$pdf->setCellPaddings(0, 0, 0, 0);
	$end = $titleBottom;
	foreach ($rows as $r) {
		$fsv = $r[2] ? 9.5 : 9;
		$pdf->SetFont('', '', 8);
		$hl = $pdf->getStringHeight($lw, $r[0]);
		$pdf->SetFont('', 'B', $fsv);
		$hv = $pdf->getStringHeight($vw, $r[1]);
		$pdf->SetFont('', '', 8);
		navonlinePdfColor($pdf, 'muted');
		$pdf->MultiCell($lw, 0, $r[0], 0, 'R', false, 1, $posx, $y + ($fsv - 8) * 0.25);
		$pdf->SetFont('', 'B', $fsv);
		navonlinePdfColor($pdf, 'text');
		$pdf->MultiCell($vw, 0, $r[1], 0, 'L', false, 1, $posx + $lw + 1.5, $y);
		$h = max($hl, $hv);
		$end = $y + $h;
		$y += max(4.3, $h + 0.6);
	}
	$pdf->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
	navonlinePdfColor($pdf, 'text');
	return $end;
}

/**
 * Label / value pair under the lines (delivery and payment terms): label 8.5 pt regular, value 9 pt bold (or 8.5 pt
 * regular), the value kept on one line ("FCA Kecskemét").
 *
 * @param TCPDF  $pdf   PDF
 * @param float  $x     X
 * @param float  $y     Y
 * @param float  $w     Width
 * @param string $label Label (with its colon)
 * @param string $value Value
 * @param bool   $bold  Value in bold
 * @return float Y under it
 */
function navonlinePdfInfoPair($pdf, $x, $y, $w, $label, $value, $bold = true)
{
	$c = sprintf('#%02X%02X%02X', NAVONLINE_PDF_COLORS['text'][0], NAVONLINE_PDF_COLORS['text'][1], NAVONLINE_PDF_COLORS['text'][2]);
	$v = dol_escape_htmltag((string) $value);
	if (mb_strlen((string) $value) <= 40) {
		$v = str_replace(' ', '&nbsp;', $v);
	}
	$html = '<span style="font-size:8.5pt;color:'.$c.';">'.dol_escape_htmltag((string) $label).' </span>'
		.($bold ? '<b><span style="font-size:9pt;color:'.$c.';">'.$v.'</span></b>' : '<span style="font-size:8.5pt;color:'.$c.';">'.$v.'</span>');
	$pdf->SetFont('', '', 8.5);
	$pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L');
	return $pdf->GetY();
}

/**
 * Bank transfer details, typography version: a framed block, the title 8.5 pt bold, label / value columns at 8.5 pt
 * regular, IBAN and BIC/SWIFT values 9 pt bold, about 11.5 pt line pitch. Same data as pdf_bank() / navonlinePdfBank().
 *
 * @param TCPDF     $pdf         PDF
 * @param Translate $outputlangs Langs
 * @param float     $x           X
 * @param float     $y           Y
 * @param Account   $account     Bank account
 * @param float     $w           Width of the block
 * @return float Y under the block
 */
function navonlinePdfBankBlock($pdf, $outputlangs, $x, $y, $account, $w = 100)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formbank.class.php';
	$outputlangs->load('banks');
	$rows = array();
	if (!empty($account->bank)) {
		$rows[] = array($outputlangs->transnoentities('Bank').':', $outputlangs->convToOutputCharset($account->bank), false);
	}
	if (!empty($account->number) && !getDolGlobalString('PDF_BANK_HIDE_NUMBER_SHOW_ONLY_BICIBAN')) {
		$rows[] = array($outputlangs->transnoentities('BankAccountNumber').':', $outputlangs->convToOutputCharset($account->number), false);
	}
	if (!empty($account->owner_name)) {
		$rows[] = array($outputlangs->transnoentities('BankAccountOwner').':', $outputlangs->convToOutputCharset($account->owner_name), false);
	}
	if (!empty($account->iban)) {
		$iban = trim(chunk_split(str_replace(' ', '', $outputlangs->convToOutputCharset($account->iban)), 4, ' '));
		$rows[] = array($outputlangs->transnoentities(FormBank::getIBANLabel($account)).':', $iban, true);
	}
	if (!empty($account->bic)) {
		$rows[] = array($outputlangs->transnoentities($account->getCountryCode() == 'IN' ? 'SWIFT' : 'BICNumber').':', $outputlangs->convToOutputCharset($account->bic), true);
	}
	$pad = 2.5;
	$cpad = $pdf->getCellPaddings();
	$pdf->setCellPaddings(0, 0, 0, 0);
	$pdf->SetFont('', '', 8.5);
	$lw = 0;
	foreach ($rows as $r) {
		$lw = max($lw, $pdf->GetStringWidth($r[0]) + 2.5);
	}
	$lw = min($lw, ($w - 2 * $pad) * 0.45);
	$yy = $y + $pad;
	navonlinePdfColor($pdf, 'text');
	$pdf->SetFont('', 'B', 8.5);
	$pdf->MultiCell($w - 2 * $pad, 0, $outputlangs->transnoentities('PaymentByTransferOnThisBankAccount').':', 0, 'L', false, 1, $x + $pad, $yy);
	$yy = $pdf->GetY() + 1;
	foreach ($rows as $r) {
		$pdf->SetFont('', '', 8.5);
		$pdf->MultiCell($lw, 0, $r[0], 0, 'L', false, 1, $x + $pad, $yy + ($r[2] ? 0.15 : 0));
		$h = $pdf->GetY() - $yy;
		$pdf->SetFont('', $r[2] ? 'B' : '', $r[2] ? 9 : 8.5);
		$pdf->MultiCell($w - 2 * $pad - $lw, 0, $r[1], 0, 'L', false, 1, $x + $pad + $lw, $yy);
		$yy += max(4.05, $h, $pdf->GetY() - $yy);
	}
	$pdf->setCellPaddings($cpad['L'], $cpad['T'], $cpad['R'], $cpad['B']);
	$bottom = $yy + $pad - 0.5;
	navonlinePdfThinLine($pdf);
	$pdf->Rect($x, $y, $w, $bottom - $y, 'D');
	navonlinePdfLineReset($pdf);
	return $bottom;
}

/**
 * Footer, typography version: the core pdf_pagefoot() (free text, page number), its company lines replaced: the
 * company identifiers left-aligned at 7.5 pt in the secondary colour under a 0.35 pt line (#D6DCE5), the page number
 * right on the last line. Same parameters as pdf_pagefoot().
 *
 * @param TCPDF       $pdf           PDF
 * @param Translate   $outputlangs   Langs
 * @param string      $paramfreetext Free text constant
 * @param Societe     $fromcompany   Issuer
 * @param float       $marge_basse   Bottom margin
 * @param float       $marge_gauche  Left margin
 * @param float       $page_hauteur  Page height
 * @param CommonObject $object       Document
 * @param int         $showdetails   Footer details
 * @param int         $hidefreetext  Hide the free text
 * @param float       $page_largeur  Page width
 * @param string      $watermark     Watermark
 * @return float|int Height of the footer
 */
function navonlinePdfPagefoot($pdf, $outputlangs, $paramfreetext, $fromcompany, $marge_basse, $marge_gauche, $page_hauteur, $object, $showdetails = 0, $hidefreetext = 0, $page_largeur = 0, $watermark = '')
{
	global $conf;

	// Company identifiers, as the core footer builds them (lines 3 and 4)
	$line3 = '';
	$line4 = '';
	foreach (array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10) as $n) {
		$prop = 'idprof'.$n;
		if (empty($fromcompany->$prop)) {
			continue;
		}
		$field = $outputlangs->transcountrynoentities('ProfId'.$n, $fromcompany->country_code);
		if (preg_match('/\((.*)\)/i', $field, $reg)) {
			$field = $reg[1];
		}
		$part = $field.': '.$outputlangs->convToOutputCharset($fromcompany->$prop);
		if ($n <= 2) {
			$line3 .= ($line3 ? ' - ' : '').$part;
		} else {
			$line4 .= ($line4 ? ' - ' : '').$part;
		}
	}
	if (!empty($fromcompany->tva_intra)) {
		$line4 .= ($line4 ? ' - ' : '').$outputlangs->transnoentities('VATIntraShort').': '.$outputlangs->convToOutputCharset($fromcompany->tva_intra);
	}
	// One line (user 2026-11-26): tax number - company registration number - community VAT number
	$lines = array_values(array_filter(array(implode(' - ', array_filter(array($line3, $line4))))));

	// The core reserves 3 mm per company line (lines 3 and 4): the page number goes on our last line
	$coreN = count(array_filter(array($line3, $line4)));
	$keys = array('PDF_FOOTER_HIDDEN' => '1', 'PDF_FOOTER_TEXT_COLOR' => implode(', ', NAVONLINE_PDF_COLORS['muted']), 'PDF_FOOTER_PAGE_NUMBER_Y' => (string) (3 * count($lines) - 3 * $coreN - 1 - 3 * max(0, count($lines) - 1)));
	$saved = array();
	foreach ($keys as $k => $v) {
		$saved[$k] = $conf->global->$k ?? null;
		$conf->global->$k = $v;
	}
	try {
		$ret = pdf_pagefoot($pdf, $outputlangs, $paramfreetext, $fromcompany, $marge_basse, $marge_gauche, $page_hauteur, $object, (int) $showdetails, $hidefreetext, $page_largeur, $watermark);
	} finally {
		foreach ($saved as $k => $v) {
			if ($v === null) {
				unset($conf->global->$k);
			} else {
				$conf->global->$k = $v;
			}
		}
	}
	if ($lines && !getDolGlobalInt('PDF_FOOTER_HIDDEN')) {
		$dims = $pdf->getPageDimensions();
		$yLine = $dims['hk'] - ($marge_basse + 3 * count($lines));
		navonlinePdfThinLine($pdf);
		$pdf->line($dims['lm'], $yLine, $dims['wk'] - $dims['rm'], $yLine);
		navonlinePdfLineReset($pdf);
		$pdf->SetFont('', '', 7.5);
		navonlinePdfColor($pdf, 'muted');
		foreach ($lines as $k => $l) {
			$pdf->MultiCell($dims['wk'] - $dims['lm'] - $dims['rm'] - 22, 2, $l, 0, 'L', false, 1, $dims['lm'], $yLine + 1 + 3 * $k);
		}
		navonlinePdfColor($pdf, 'text');
	}
	return $ret;
}

/**
 * Text lines of a partner box (typography): the address lines 9.5 pt, the "Label: value" lines (phone, e-mail, web,
 * tax numbers) 8.5 pt, an empty line is a 1.5 mm gap.
 *
 * @param TCPDF  $pdf  PDF
 * @param float  $x    X
 * @param float  $y    Y
 * @param float  $w    Width
 * @param string $text Address block (pdf_build_address)
 * @param string $ltr  Alignment
 * @return float Y under the text
 */
function navonlinePdfPartnerLines($pdf, $x, $y, $w, $text, $ltr = 'L')
{
	navonlinePdfColor($pdf, 'text');
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
 * Amount payable box (typography): #EDF2FA, a 0.75 pt blue top line, 3 mm padding, the label 10 pt bold (several
 * lines allowed), the amount 12 pt bold, both in the accent colour.
 *
 * @param TCPDF  $pdf    PDF
 * @param float  $x      X
 * @param float  $right  Right edge
 * @param float  $y      Y
 * @param string $label  Label
 * @param string $amount Amount
 * @return float Height of the box
 */
function navonlinePdfTotalBox($pdf, $x, $right, $y, $label, $amount)
{
	$cp = $pdf->getCellPaddings();
	$pdf->setCellPaddings(0, 0, 0, 0);
	$pdf->SetFont('', 'B', 12);
	$aw = $pdf->GetStringWidth($amount) + 1;
	$pdf->SetFont('', 'B', 10);
	$lw = $right - $x - 3 - $aw - 2;
	$h = max($pdf->getStringHeight($lw, $label), 5.5) + 6;
	$pdf->Rect($x, $y, $right - $x, $h, 'F', array(), NAVONLINE_PDF_COLORS['total']);
	$pdf->SetLineStyle(array('width' => 0.2646, 'dash' => 0, 'color' => NAVONLINE_PDF_COLORS['accent']));
	$pdf->line($x, $y, $right, $y);
	navonlinePdfLineReset($pdf);
	navonlinePdfColor($pdf, 'accent');
	$pdf->MultiCell($lw, 0, $label, 0, 'L', false, 1, $x + 1.5, $y + 3);
	$pdf->SetFont('', 'B', 12);
	$pdf->MultiCell($aw, 0, $amount, 0, 'R', false, 1, $right - 1.5 - $aw, $y + ($h - 5.3) / 2);
	$pdf->setCellPaddings($cp['L'], $cp['T'], $cp['R'], $cp['B']);
	navonlinePdfColor($pdf, 'text');
	return $h;
}

/**
 * Line content padding (typography): 1.6 mm above and below every column of the lines.
 *
 * @param CommonDocGenerator $model PDF model
 * @return void
 */
function navonlinePdfTypoColumns($model)
{
	foreach ($model->cols as $k => $c) {
		$p = $c['content']['padding'] ?? array(0.5, 0.5, 0.5, 0.5);
		$model->cols[$k]['content']['padding'] = array(1.6, $p[1] ?? 0.5, 1.6, $p[3] ?? 0.5);
	}
}

/**
 * Info rows under the lines (typography, the same as on the invoice): label / value pairs, the label 8.5 pt, the value
 * 9 pt bold (regular when its third element is false), kept on one line when short.
 *
 * @param TCPDF                          $pdf  PDF
 * @param float                          $x    X
 * @param float                          $posy Y
 * @param float                          $w    Width
 * @param array<int,array{0:string,1:string,2?:bool}> $rows Label (without colon), value, bold
 * @return float Y under them
 */
function navonlinePdfInfoRows($pdf, $x, $posy, $w, $rows)
{
	foreach ($rows as $r) {
		$posy = navonlinePdfInfoPair($pdf, $x, $posy, $w, $r[0].':', (string) $r[1], $r[2] ?? true) + 0.8;
	}
	return $posy + ($rows ? 1.5 : 0);
}

/**
 * Payment terms (with the deposit percentage) and payment mode rows, as the core info blocks print them; the mode
 * of a transfer or cheque is left out (the bank block follows) unless $allModes.
 *
 * @param CommonObject $object      Document
 * @param Translate    $outputlangs Langs
 * @param bool         $withTerms   Payment terms
 * @param bool         $withMode    Payment mode
 * @param bool         $allModes    Also transfer / cheque
 * @return array<int,array{0:string,1:string}>
 */
function navonlinePdfTermRows($object, $outputlangs, $withTerms = true, $withMode = true, $allModes = false)
{
	$rows = array();
	if ($withTerms && (!empty($object->cond_reglement_code) || !empty($object->cond_reglement))) {
		$code = 'PaymentCondition'.$object->cond_reglement_code;
		$lib = ($outputlangs->transnoentities($code) != $code) ? $outputlangs->transnoentities($code) : $outputlangs->convToOutputCharset($object->cond_reglement_doc ? $object->cond_reglement_doc : $object->cond_reglement_label);
		$lib = str_replace('\n', "\n", (string) $lib);
		if (!empty($object->deposit_percent) && $object->deposit_percent > 0) {
			$lib = str_replace('__DEPOSIT_PERCENT__', (string) $object->deposit_percent, $lib);
		}
		$rows[] = array($outputlangs->transnoentities('PaymentConditions'), $lib);
	}
	if ($withMode && !empty($object->mode_reglement_code) && ($allModes || !in_array($object->mode_reglement_code, array('CHQ', 'VIR'), true))) {
		$key = 'PaymentType'.$object->mode_reglement_code;
		$rows[] = array($outputlangs->transnoentities('PaymentMode'), ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $outputlangs->convToOutputCharset((string) $object->mode_reglement));
	}
	return $rows;
}

/**
 * Bank block of a document paid by transfer (navonlineDocBankId), none otherwise.
 *
 * @param TCPDF        $pdf         PDF
 * @param CommonObject $object      Document
 * @param Translate    $outputlangs Langs
 * @param float        $x           X
 * @param float        $posy        Y
 * @return float Y under it
 */
function navonlinePdfBankForDoc($pdf, $object, $outputlangs, $x, $posy)
{
	global $db;

	$bankid = navonlineDocBankId($object);
	if ($bankid > 0) {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$account = new Account($db);
		if ($account->fetch($bankid) > 0) {
			$posy = navonlinePdfBankBlock($pdf, $outputlangs, $x, $posy + 0.5, $account, 100) + 2;
		}
	}
	return $posy;
}

/**
 * Hungarian definite article before a reference read letter by letter or as a number: "az" before a vowel sound
 * ("az SO2611-0006": es-o; "az 1", "az 5"), "a" otherwise ("a PO2611-0001", "a 2").
 *
 * @param string $ref Reference
 * @return string a or az
 */
function navonlineHuArticle($ref)
{
	$c = mb_strtoupper(mb_substr(trim((string) $ref), 0, 1));
	if ($c === '') {
		return 'a(z)';
	}
	if (ctype_digit($c)) {
		return in_array($c, array('1', '5'), true) ? 'az' : 'a';
	}
	return (mb_strpos('AÁEÉIÍOÓÖŐUÚÜŰFLMNRSX', $c) !== false) ? 'az' : 'a';
}

/**
 * Date of supply of the invoice a cancellation (STORNO) credit note cancels: its own date of supply, else its date;
 * 0 without a source invoice. The cancellation invoice carries the date of supply of the original (PDF and NAV).
 *
 * @param DoliDB  $db      Database
 * @param Facture $invoice Credit note
 * @return int Timestamp, 0 if unknown
 */
function navonlineStornoDeliveryDate($db, $invoice)
{
	if (empty($invoice->fk_facture_source)) {
		return 0;
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$src = new Facture($db);
	if ($src->fetch((int) $invoice->fk_facture_source) <= 0) {
		return 0;
	}
	return (int) (!empty($src->date_pointoftax) ? $src->date_pointoftax : $src->date);
}

/**
 * Whether a shipping contact has the billing address: a contact without an own address uses that of its company; else
 * the address, postcode, town and country compared without case and spaces.
 *
 * @param Societe $soc     Customer
 * @param Contact $contact Shipping contact
 * @return bool
 */
function navonlineSameAddress($soc, $contact)
{
	$norm = function ($v) {
		return mb_strtolower(preg_replace('/[\s,.\-]+/u', '', (string) $v));
	};
	if (trim((string) $contact->address) === '' && trim((string) $contact->town) === '') {
		return true;
	}
	return $norm($soc->address) === $norm($contact->address) && $norm($soc->zip) === $norm($contact->zip)
		&& $norm($soc->town) === $norm($contact->town) && (int) $soc->country_id === (int) ($contact->country_id ?: $soc->country_id);
}

/**
 * Shipping contact (role SHIPPING) of a document whose address differs from the billing address, null without one.
 *
 * @param DoliDB       $db     Database
 * @param CommonObject $object Document
 * @return ?Contact
 */
function navonlineShippingContact($db, $object)
{
	$ids = $object->getIdContact('external', 'SHIPPING');
	if (empty($ids) || !is_object($object->thirdparty)) {
		return null;
	}
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	$contact = new Contact($db);
	if ($contact->fetch((int) $ids[0]) <= 0 || navonlineSameAddress($object->thirdparty, $contact)) {
		return null;
	}
	return $contact;
}

/**
 * Shipping address box under the customer box (invoice, proposal, order documents), typography as the partner boxes:
 * only with an explicit shipping contact (role SHIPPING) whose address differs from the billing address, so the same
 * address never appears twice.
 *
 * @param TCPDF        $pdf            PDF
 * @param DoliDB       $db             Database
 * @param CommonObject $object         Document
 * @param Translate    $outputlangs    Langs
 * @param ?Translate   $outputlangsbis Second language (bilingual invoice)
 * @param Societe      $emetteur       Issuer
 * @param float        $x              X
 * @param float        $y              Y (top of the box)
 * @param float        $w              Width
 * @return float Height added under the customer box (box + gap), 0 without a box
 */
function navonlinePdfShippingAddressBox($pdf, $db, $object, $outputlangs, $outputlangsbis, $emetteur, $x, $y, $w)
{
	$contact = navonlineShippingContact($db, $object);
	if ($contact === null) {
		return 0;
	}
	$company = $object->thirdparty;
	if (!empty($contact->socid) && (int) $contact->socid !== (int) $company->id) {
		$company = new Societe($db);
		$company->fetch((int) $contact->socid);
	}
	$outputlangs->load('navonline@navonline');
	$label = $outputlangs->transnoentities('NavOnlinePdfShipTo');
	if (is_object($outputlangsbis)) {
		$outputlangsbis->load('navonline@navonline');
		$label .= ' - '.$outputlangsbis->transnoentities('NavOnlinePdfShipTo');
	}
	$name = pdfBuildThirdpartyName($company, $outputlangs);
	$text = pdf_build_address($outputlangs, $emetteur, $company, $contact, 1, 'target', $object);
	// The tax numbers are in the customer box already: not repeated in the delivery address
	$ids2 = array_filter(array($company->tva_intra ?? '', $company->idprof1 ?? '', $company->idprof2 ?? '', $company->idprof3 ?? '', $company->idprof4 ?? ''), function ($v) {
		return trim((string) $v) !== '';
	});
	$keep = array();
	foreach (explode("\n", (string) $text) as $l) {
		$drop = false;
		foreach ($ids2 as $v) {
			if (strpos($l, ': ') !== false && strpos($l, (string) $v) !== false) {
				$drop = true;
			}
		}
		if (!$drop) {
			$keep[] = $l;
		}
	}
	$text = rtrim(implode("\n", $keep));
	$pdf->SetFont('', '', 8);
	navonlinePdfColor($pdf, 'muted');
	$pdf->MultiCell($w, 5, $label, 0, 'L', false, 1, $x, $y - 5);
	navonlinePdfColor($pdf, 'text');
	$pdf->SetFont('', 'B', 10.5);
	$pdf->MultiCell($w - 6, 0, $name, 0, 'L', false, 1, $x + 3, $y + 3);
	$end = navonlinePdfPartnerLines($pdf, $x + 3, $pdf->GetY() + 0.6, $w - 6, $text, 'L');
	$h = max(14, $end + 3 - $y);
	navonlinePdfThinLine($pdf);
	$pdf->RoundedRect($x, $y, $w, $h, 0, '1234', 'D');
	navonlinePdfLineReset($pdf);
	return $h + 6;
}

/**
 * End of the description of the last table line printed by pdf_writelinedesc() (templates that print their lines
 * without the column system, such as the core aurore): set by the pdf_writelinedesc hook, read by the template.
 *
 * @param ?TCPDF                         $pdf  PDF (null = read)
 * @param ?array{0:int,1:int,2:float}    $info PDF object, page, Y under the description (null = read)
 * @return ?array{0:int,1:int,2:float}
 */
function navonlinePdfLineDescEnd($pdf = null, $info = null)
{
	static $last = null;
	if ($info !== null) {
		$last = $info;
	}
	return $last;
}

/**
 * Bottom margin of the table lines of a PDF printed by pdf_writelinedesc() (templates without the column system, such
 * as the core aurore, keep the height of the totals free on every page): the pdf_writelinedesc hook applies it.
 *
 * @param ?int   $id     PDF object (spl_object_id, 0 = clear, null = read)
 * @param ?float $bottom Bottom margin
 * @return ?array{0:int,1:float}
 */
function navonlinePdfLinesBottom($id = null, $bottom = null)
{
	static $v = null;
	if ($id !== null) {
		$v = $id ? array((int) $id, (float) $bottom) : null;
	}
	return $v;
}

/**
 * VAT code (9th digit) of the Hungarian tax number of a company (Prof Id 1, else a full tax number in the VAT number
 * field, as the NAV XML builder reads it): 1 = subject-exempt (alanyi adómentes), 2 = VAT payer, ...; '' if unknown.
 *
 * @param ?Societe $soc Company
 * @return string
 */
function navonlineSellerVatCode($soc)
{
	if (!is_object($soc)) {
		return '';
	}
	foreach (array((string) ($soc->idprof1 ?? ''), (string) ($soc->tva_intra ?? '')) as $v) {
		if (preg_match('/^[0-9]{8}-?[1-5]-?[0-9]{2}$/', trim($v))) {
			return substr((string) preg_replace('/[^0-9]/', '', $v), 8, 1);
		}
	}
	return '';
}

/**
 * EU member states other than Hungary (Dolibarr country codes).
 */
const NAVONLINE_EU_COUNTRIES = array('AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK');

/**
 * Format of the community VAT numbers (VIES) by prefix, without the prefix.
 */
const NAVONLINE_EU_VAT_FORMATS = array(
	'AT' => 'U[0-9]{8}', 'BE' => '[01][0-9]{9}', 'BG' => '[0-9]{9,10}', 'CY' => '[0-9]{8}[A-Z]', 'CZ' => '[0-9]{8,10}',
	'DE' => '[0-9]{9}', 'DK' => '[0-9]{8}', 'EE' => '[0-9]{9}', 'EL' => '[0-9]{9}', 'ES' => '[0-9A-Z][0-9]{7}[0-9A-Z]',
	'FI' => '[0-9]{8}', 'FR' => '[0-9A-HJ-NP-Z]{2}[0-9]{9}', 'HR' => '[0-9]{11}', 'IE' => '[0-9][0-9A-Z+*][0-9]{5}[A-W][A-I]?',
	'IT' => '[0-9]{11}', 'LT' => '([0-9]{9}|[0-9]{12})', 'LU' => '[0-9]{8}', 'LV' => '[0-9]{11}', 'MT' => '[0-9]{8}',
	'NL' => '[0-9]{9}B[0-9]{2}', 'PL' => '[0-9]{10}', 'PT' => '[0-9]{9}', 'RO' => '[0-9]{2,10}', 'SE' => '[0-9]{12}',
	'SI' => '[0-9]{8}', 'SK' => '[0-9]{10}', 'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
);

/**
 * Why a customer cannot get an intra-Community exempt supply (KBAET / KBAUK, Áfa tv. 89. §): the buyer must be a
 * company in another member state with a community VAT number of that state (Greece: EL; Northern Ireland: XI with a
 * GB address). Only the format is checked, not the registration (VIES).
 *
 * @param Societe $soc Customer
 * @return array{0:string,1:string,2:string} Lang key of the reason ('' = allowed) and its two parameters
 */
function navonlineIntraCommunityBuyerError($soc)
{
	$country = strtoupper((string) ($soc->country_code ?? ''));
	$eu = NAVONLINE_EU_COUNTRIES;
	$vat = strtoupper((string) preg_replace('/[\s.\-]+/', '', (string) ($soc->tva_intra ?? '')));
	if ($country === 'HU') {
		return array('NavOnlineErrKbHu', '', '');
	}
	$ni = ($country === 'GB' && strpos($vat, 'XI') === 0);
	if (!in_array($country, $eu, true) && !$ni) {
		return array('NavOnlineErrKbNotEu', $country, '');
	}
	if ((string) ($soc->typent_code ?? '') === 'TE_PRIVATE') {
		return array('NavOnlineErrKbPrivate', '', '');
	}
	if ($vat === '') {
		return array('NavOnlineErrKbNoVat', '', '');
	}
	$prefix = substr($vat, 0, 2);
	if (!isset(NAVONLINE_EU_VAT_FORMATS[$prefix]) || $prefix === 'HU' || !preg_match('/^'.NAVONLINE_EU_VAT_FORMATS[$prefix].'$/', substr($vat, 2))) {
		return array('NavOnlineErrKbVatFormat', (string) $soc->tva_intra, '');
	}
	$expected = $ni ? 'XI' : ($country === 'GR' ? 'EL' : $country);
	if ($prefix !== $expected) {
		return array('NavOnlineErrKbVatCountry', $prefix, $country);
	}
	return array('', '', '');
}

/**
 * Country the goods of a document go to: the country of its shipping contact (role SHIPPING) when it has one, else
 * that of the customer.
 *
 * @param DoliDB       $db     Database
 * @param CommonObject $object Proposal, order or invoice (thirdparty loaded or loadable)
 * @return array{0:string,1:string} Country code, 'shipping' or 'customer'
 */
function navonlineGoodsDestination($db, $object)
{
	$ids = method_exists($object, 'getIdContact') ? $object->getIdContact('external', 'SHIPPING') : array();
	if (!empty($ids)) {
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$c = new Contact($db);
		if ($c->fetch((int) $ids[0]) > 0 && !empty($c->country_code)) {
			return array(strtoupper((string) $c->country_code), 'shipping');
		}
	}
	if (!is_object($object->thirdparty ?? null) && method_exists($object, 'fetch_thirdparty')) {
		$object->fetch_thirdparty();
	}
	return array(strtoupper((string) ($object->thirdparty->country_code ?? '')), 'customer');
}

/**
 * Why a document cannot have an export exempt line (EAM, Áfa tv. 98. §): the goods must leave the EU. Monaco is in the
 * French VAT territory; a GB customer with an XI (Northern Ireland) VAT number gets intra-Community treatment. Territories
 * outside the EU VAT area with their own country code (Åland AX, French overseas departments, ...) are export.
 *
 * @param DoliDB       $db     Database
 * @param CommonObject $object Proposal, order or invoice
 * @return array{0:string,1:string,2:string} Lang key of the reason ('' = allowed) and its parameters
 */
function navonlineExportError($db, $object)
{
	list($country, $source) = navonlineGoodsDestination($db, $object);
	$where = $source === 'shipping' ? 'NavOnlineEamDestShipping' : 'NavOnlineEamDestCustomer';
	if ($country === 'HU') {
		return array('NavOnlineErrEamHu', $where, '');
	}
	if (in_array($country, NAVONLINE_EU_COUNTRIES, true) || $country === 'MC') {
		return array('NavOnlineErrEamEu', $country, $where);
	}
	$vat = strtoupper((string) preg_replace('/[\s.\-]+/', '', (string) ($object->thirdparty->tva_intra ?? '')));
	if ($country === 'GB' && strpos($vat, 'XI') === 0) {
		return array('NavOnlineErrEamNi', '', '');
	}
	return array('', '', '');
}

/**
 * OSS lines of a document: lines with a foreign VAT rate (a positive rate that is not a Hungarian one: 27, 18, 5) or a
 * VAT code with a country suffix (PTU-PL, USt-AT, ...). OSS invoices (place of supply in another member state) are not
 * reported to NAV and are not part of the e-ÁFA: they go to the quarterly OSS return. Title / subtotal lines and
 * lines without an amount (comments) are not counted.
 *
 * @param DoliDB $db      Database
 * @param string $element facture, propal or commande
 * @param int    $id      Document id
 * @return array{0:int,1:int} Number of lines, number of OSS lines
 */
function navonlineOssLineCounts($db, $element, $id)
{
	$tables = array('propal' => array('propaldet', 'fk_propal'), 'commande' => array('commandedet', 'fk_commande'), 'facture' => array('facturedet', 'fk_facture'));
	if (!isset($tables[$element])) {
		return array(0, 0);
	}
	list($table, $fk) = $tables[$element];
	$oss = "(d.tva_tx > 0 AND (ROUND(d.tva_tx, 2) NOT IN (27, 18, 5) OR UPPER(COALESCE(d.vat_src_code, '')) REGEXP '-[A-Z]{2}$'))";
	$sql = 'SELECT COUNT(*) AS n, SUM(CASE WHEN '.$oss.' THEN 1 ELSE 0 END) AS noss';
	$sql .= ' FROM '.$db->prefix().$table.' d WHERE d.'.$fk.' = '.((int) $id).' AND d.product_type <> 9 AND NOT (d.total_ht = 0 AND d.tva_tx = 0)';
	$res = $db->query($sql);
	$o = $res ? $db->fetch_object($res) : null;
	return $o ? array((int) $o->n, (int) $o->noss) : array(0, 0);
}
