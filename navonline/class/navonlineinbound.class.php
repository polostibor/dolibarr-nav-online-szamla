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
 * \file    htdocs/custom/navonline/class/navonlineinbound.class.php
 * \ingroup navonline
 * \brief   Inbound (supplier) invoices from NAV Online Számla: list sync, invoice data, supplier matching and
 *          creation of draft supplier invoices.
 *
 * Only read-only NAV operations are used here (queryInvoiceDigest, queryInvoiceData with direction INBOUND). The
 * environment is NAVONLINE_INBOUND_ENVIRONMENT (default prod, allowed by the user), independent of the environment
 * used for reporting outgoing invoices. Nothing is recorded in Dolibarr automatically: sync only fills
 * llx_navonline_inbound; a supplier and a draft supplier invoice are created when the user confirms on inbound.php.
 */

require_once __DIR__.'/navonlineclient.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Inbound invoices
 */
class NavOnlineInbound
{
	const STATUS_NEW = 0;
	const STATUS_RECORDED = 1;
	const STATUS_IGNORED = 9;

	/** NAV allows at most 35 days per invoiceIssueDate query */
	const MAX_RANGE_DAYS = 35;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string[] Non-blocking notices of the last record() (e.g. amounts differing from NAV) */
	public $warnings = array();

	/** @var string Output of the scheduled job */
	public $output = '';

	/** @var array<string,array<int,object>>|null 8-digit tax id => partners */
	private $taxIndex = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return string Environment used for inbound queries
	 */
	public static function environment()
	{
		return getDolGlobalString('NAVONLINE_INBOUND_ENVIRONMENT', NAVONLINE_ENV_PROD) === NAVONLINE_ENV_TEST ? NAVONLINE_ENV_TEST : NAVONLINE_ENV_PROD;
	}

	/**
	 * @return \NavOnlineInvoice\Reporter|null
	 */
	protected function reporter()
	{
		$c = new NavOnlineClient();
		$r = $c->getReporter(self::environment());
		if (!$r) {
			$this->errors = array_merge($this->errors, $c->errors);
		}
		return $r;
	}

	/**
	 * Scheduled job: refresh the list of the last N days (default 10). Records nothing.
	 *
	 * @param int|string $days Days back
	 * @return int 0 = OK, <0 = error (cron convention)
	 */
	public function syncScheduled($days = 10)
	{
		global $conf;

		// By processing time at NAV (insDate), from the end of the last successful run: an invoice reported late (manual
		// invoice, report refused and sent again weeks later, back-dated issue date) or a run missed for days is never
		// skipped. First run: the last N days. One hour of overlap (the upsert is idempotent).
		$days = max(1, min(90, (int) $days ?: 10));
		$key = self::cursorKey();
		$now = dol_now();
		$last = (int) getDolGlobalString($key);
		$from = $last > 0 ? $last - 3600 : $now - $days * 86400;
		$res = $this->syncByInsDate($from, $now);
		if ($res < 0) {
			$this->output = implode('; ', $this->errors);
			return -1;
		}
		dolibarr_set_const($this->db, $key, (string) $now, 'chaine', 0, 'NavOnline: end of the last inbound sync (insDate)', $conf->entity);
		$this->output = 'NAV bejövő számlák ('.dol_print_date($from, 'dayhour').' óta beérkezett adatszolgáltatások): '.$res['found'].' találat, '.$res['new'].' új'.($res['changed'] ? ', '.$res['changed'].' megváltozott' : '');
		return 0;
	}

	/**
	 * @return string Constant holding the end of the last insDate sync (timestamp) of the inbound environment
	 */
	public static function cursorKey()
	{
		return 'NAVONLINE_INBOUND_SYNC_'.strtoupper(self::environment());
	}

	/**
	 * Read the inbound invoices processed by NAV in a time range (insDate, UTC) into llx_navonline_inbound.
	 *
	 * @param int $from Timestamp
	 * @param int $to   Timestamp
	 * @return array{found:int,new:int,changed:int}|int Counters, -1 on error
	 */
	public function syncByInsDate($from, $to)
	{
		$this->errors = array();
		if ($from <= 0 || $to <= $from) {
			$this->errors[] = 'Érvénytelen időszak';
			return -1;
		}
		$r = $this->reporter();
		if (!$r) {
			return -1;
		}
		$tot = array('found' => 0, 'new' => 0, 'changed' => 0);
		$span = (self::MAX_RANGE_DAYS - 1) * 86400;	// NAV: at most 35 days per query
		for ($start = $from; $start < $to; $start += $span) {
			$end = min($to, $start + $span);
			$q = array('insDate' => array('dateTimeFrom' => gmdate('Y-m-d\TH:i:s', $start).'.000Z', 'dateTimeTo' => gmdate('Y-m-d\TH:i:s', $end).'.000Z'));
			$res = $this->fetchDigest($r, $q);
			if ($res < 0) {
				return -1;
			}
			foreach ($tot as $k => $v) {
				$tot[$k] = $v + $res[$k];
			}
		}
		return $tot;
	}

	/**
	 * All pages of one INBOUND digest query, stored.
	 *
	 * @param \NavOnlineInvoice\Reporter $r         Reporter
	 * @param array<string,mixed>        $mandatory mandatoryQueryParams
	 * @return array{found:int,new:int,changed:int}|int Counters, -1 on error
	 */
	private function fetchDigest($r, $mandatory)
	{
		global $conf;

		$env = self::environment();
		$tot = array('found' => 0, 'new' => 0, 'changed' => 0);
		$page = 1;
		do {
			try {
				$res = $r->queryInvoiceDigest(array('mandatoryQueryParams' => $mandatory), $page, 'INBOUND');
			} catch (Exception $e) {
				$this->errors[] = NavOnlineClient::describeException($e);
				return -1;
			}
			$pages = (int) $res->availablePage;
			foreach ($res->invoiceDigest as $d) {
				$tot['found']++;
				$u = $this->upsertDigest($d, $env, (int) $conf->entity);
				$tot['new'] += $u === 1 ? 1 : 0;
				$tot['changed'] += $u === 2 ? 1 : 0;
			}
			$page++;
		} while ($page <= $pages);
		return $tot;
	}

	/**
	 * Read the inbound invoice list of a date range (issue date) from NAV into llx_navonline_inbound.
	 *
	 * @param string $from Y-m-d
	 * @param string $to   Y-m-d
	 * @return array{found:int,new:int}|int Counters, -1 on error
	 */
	public function sync($from, $to)
	{
		global $conf;

		$this->errors = array();
		$tsFrom = strtotime($from);
		$tsTo = strtotime($to);
		if (!$tsFrom || !$tsTo || $tsFrom > $tsTo) {
			$this->errors[] = 'Érvénytelen időszak';
			return -1;
		}
		$r = $this->reporter();
		if (!$r) {
			return -1;
		}
		$tot = array('found' => 0, 'new' => 0, 'changed' => 0);
		for ($start = $tsFrom; $start <= $tsTo; $start = strtotime('+'.self::MAX_RANGE_DAYS.' days', $start)) {
			$end = min($tsTo, strtotime('+'.(self::MAX_RANGE_DAYS - 1).' days', $start));
			$res = $this->fetchDigest($r, array('invoiceIssueDate' => array('dateFrom' => date('Y-m-d', $start), 'dateTo' => date('Y-m-d', $end))));
			if ($res < 0) {
				return -1;
			}
			foreach ($tot as $k => $v) {
				$tot[$k] = $v + $res[$k];
			}
		}
		return $tot;
	}

	/**
	 * @param SimpleXMLElement $d      invoiceDigest
	 * @param string           $env    Environment
	 * @param int              $entity Entity
	 * @return int 1 if inserted, 0 if updated
	 */
	private function upsertDigest($d, $env, $entity)
	{
		$s = function ($v, $len = 255) {
			$v = trim((string) $v);
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$n = function ($v) {
			return trim((string) $v) === '' ? 'NULL' : (float) $v;
		};
		$taxid = substr(preg_replace('/\D/', '', (string) $d->supplierTaxNumber), 0, 8);
		$number = trim((string) $d->invoiceNumber);
		$batch = (int) $d->batchIndex;	// item of a batch modification (batchInvoice), 0 otherwise
		$where = 'entity = '.$entity." AND environment = '".$this->db->escape($env)."' AND supplier_taxid = '".$this->db->escape($taxid)."' AND invoice_number = '".$this->db->escape($number)."' AND batch_index = ".$batch;
		$fields = array(
			'supplier_name' => $s($d->supplierName),
			'operation' => $s($d->invoiceOperation, 8),
			'category' => $s($d->invoiceCategory, 16),
			'issue_date' => $s($d->invoiceIssueDate, 10),
			'delivery_date' => $s($d->invoiceDeliveryDate, 10),
			'payment_date' => $s($d->paymentDate, 10),
			'payment_method' => $s($d->paymentMethod, 16),
			'currency' => $s($d->currency, 3),
			'net_amount' => $n($d->invoiceNetAmount),
			'vat_amount' => $n($d->invoiceVatAmount),
			'net_amount_huf' => $n($d->invoiceNetAmountHUF),
			'vat_amount_huf' => $n($d->invoiceVatAmountHUF),
			'appearance' => $s($d->invoiceAppearance, 16),
			'completeness' => ((string) $d->completenessIndicator === 'true' ? 1 : 0),
			'transaction_id' => $s($d->transactionId, 32),
			'invoice_index' => $n($d->index) === 'NULL' ? 'NULL' : (int) $d->index,
			'ins_date' => trim((string) $d->insDate) === '' ? 'NULL' : "'".$this->db->idate(strtotime((string) $d->insDate))."'",
			'original_invoice_number' => $s($d->originalInvoiceNumber, 64),
		);
		$res = $this->db->query('SELECT rowid, transaction_id, status FROM '.$this->db->prefix().'navonline_inbound WHERE '.$where);
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o) {
			$set = array();
			foreach ($fields as $k => $v) {
				$set[] = $k.' = '.$v;
			}
			// Another NAV report of the same invoice (the supplier annulled the previous one technically and sent it
			// again): the cached invoice data is out of date; a row already recorded is flagged for checking
			$newTx = trim((string) $d->transactionId);
			$changed = $newTx !== '' && !empty($o->transaction_id) && $newTx !== (string) $o->transaction_id;
			if ($changed) {
				$set[] = 'invoice_xml = NULL';
				if ((int) $o->status === self::STATUS_RECORDED) {
					$set[] = 'nav_changed = 1';
				}
				dol_syslog(__METHOD__.' inbound '.$number.' ('.$taxid.'): new NAV report '.$newTx.' (was '.$o->transaction_id.'), cached data dropped', LOG_WARNING);
			}
			$this->db->query('UPDATE '.$this->db->prefix().'navonline_inbound SET '.implode(', ', $set).' WHERE rowid = '.((int) $o->rowid));
			return $changed ? 2 : 0;
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'navonline_inbound (entity, environment, invoice_number, batch_index, supplier_taxid, '.implode(', ', array_keys($fields)).', status, date_creation)';
		$sql .= ' VALUES ('.$entity.", '".$this->db->escape($env)."', '".$this->db->escape($number)."', ".$batch.", '".$this->db->escape($taxid)."', ".implode(', ', $fields).', 0, \''.$this->db->idate(dol_now()).'\')';
		return $this->db->query($sql) ? 1 : 0;
	}

	/**
	 * @param int $id Row id
	 * @return object|null
	 */
	public function fetchRow($id)
	{
		global $conf;
		$res = $this->db->query('SELECT * FROM '.$this->db->prefix().'navonline_inbound WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * Invoice data of a row (queryInvoiceData INBOUND), cached in invoice_xml.
	 *
	 * @param object $row Row
	 * @return array<string,mixed>|null Parsed invoice (see parse()), null on error
	 */
	public function getInvoiceData($row)
	{
		// Batch modification (one document modifying several invoices, batchInvoice): not recorded by the module
		if ((int) ($row->batch_index ?? 0) > 0) {
			$this->errors[] = 'Kötegelt módosítás ('.$row->invoice_number.', '.((int) $row->batch_index).'. tétel, eredeti számla: '.($row->original_invoice_number ?: '?').'): egy bizonylat több számlát módosít, ezt a modul nem rögzíti automatikusan. Kézzel rögzítsd a módosított számlák alapján, majd jelöld „Kihagyás”-sal.';
			return null;
		}
		if (empty($row->invoice_xml)) {
			$r = $this->reporter();
			if (!$r) {
				return null;
			}
			try {
				$xml = $r->queryInvoiceData(array('invoiceNumber' => $row->invoice_number, 'invoiceDirection' => 'INBOUND', 'supplierTaxNumber' => $row->supplier_taxid), true);
			} catch (Exception $e) {
				$this->errors[] = NavOnlineClient::describeException($e);
				return null;
			}
			if (!$xml) {
				$this->errors[] = 'A NAV nem adott vissza számlaadatot';
				return null;
			}
			$row->invoice_xml = $xml->asXML();
			$this->db->query('UPDATE '.$this->db->prefix()."navonline_inbound SET invoice_xml = '".$this->db->escape($row->invoice_xml)."' WHERE rowid = ".((int) $row->rowid));
		}
		$parsed = self::parse($row->invoice_xml);
		if (!$parsed && strpos((string) $row->invoice_xml, 'batchInvoice') !== false) {
			$this->errors[] = 'Kötegelt módosítás ('.$row->invoice_number.'): egy bizonylat több számlát módosít, ezt a modul nem rögzíti automatikusan. Kézzel rögzítsd, majd jelöld „Kihagyás”-sal.';
		} elseif (!$parsed) {
			$this->errors[] = 'A NAV-tól kapott számlaadat nem dolgozható fel';
		}
		return $parsed;
	}

	/**
	 * Parse the decoded invoice XML (InvoiceData) into a plain array.
	 *
	 * @param string $xmlString XML
	 * @return array<string,mixed>|null
	 */
	public static function parse($xmlString)
	{
		$dom = new DOMDocument();
		if (!@$dom->loadXML((string) $xmlString)) {
			return null;
		}
		$xp = new DOMXPath($dom);
		$q = function ($path, $ctx = null) use ($xp) {
			$expr = implode('/', array_map(function ($p) {
				return $p === '' ? '' : (strpos($p, '*') === 0 ? $p : "*[local-name()='".$p."']");
			}, explode('/', $path)));
			$n = $ctx ? $xp->query($expr, $ctx) : $xp->query('//'.$expr);
			return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
		};
		$node = function ($path, $ctx = null) use ($xp) {
			$expr = implode('/', array_map(function ($p) {
				return "*[local-name()='".$p."']";
			}, explode('/', $path)));
			return $ctx ? $xp->query($expr, $ctx) : $xp->query('//'.$expr);
		};
		$tax = function ($ctx) use ($q) {
			if (!$ctx) {
				return '';
			}
			$id = $q('taxpayerId', $ctx);
			if ($id === '') {
				return '';
			}
			$vat = $q('vatCode', $ctx);
			$county = $q('countyCode', $ctx);
			return $id.($vat !== '' ? '-'.$vat.($county !== '' ? '-'.$county : '') : '');
		};
		$first = function ($list) {
			return ($list && $list->length) ? $list->item(0) : null;
		};

		$sup = $first($node('invoiceMain/invoice/invoiceHead/supplierInfo'));
		$det = $first($node('invoiceMain/invoice/invoiceHead/invoiceDetail'));
		if (!$sup) {
			return null;
		}
		$addr = $first($node('supplierAddress', $sup));
		$address = array('country' => '', 'zip' => '', 'town' => '', 'address' => '');
		if ($addr) {
			$address['country'] = $q('*/*[local-name()=\'countryCode\']', $addr);
			$address['zip'] = $q('*/*[local-name()=\'postalCode\']', $addr);
			$address['town'] = $q('*/*[local-name()=\'city\']', $addr);
			$simple = $q('*/*[local-name()=\'additionalAddressDetail\']', $addr);
			if ($simple !== '') {
				$address['address'] = $simple;
			} else {
				$parts = array();
				foreach (array('streetName', 'publicPlaceCategory', 'number', 'building', 'staircase', 'floor', 'door') as $p) {
					$v = $q('*/*[local-name()=\''.$p.'\']', $addr);
					if ($v !== '') {
						$parts[] = $v.(in_array($p, array('number'), true) && !preg_match('/\.$/', $v) ? '.' : '');
					}
				}
				$address['address'] = implode(' ', $parts);
			}
		}

		$inv = array(
			'invoiceNumber' => $q('invoiceNumber'),
			'issueDate' => $q('invoiceIssueDate'),
			'supplier' => array(
				'name' => $q('supplierName', $sup),
				'taxNumber' => $tax($first($node('supplierTaxNumber', $sup))),
				'groupMemberTaxNumber' => $tax($first($node('groupMemberTaxNumber', $sup))),
				'communityVatNumber' => $q('communityVatNumber', $sup),
				'bankAccount' => $q('supplierBankAccountNumber', $sup),
				'address' => $address,
			),
			'category' => $det ? $q('invoiceCategory', $det) : '',
			'deliveryDate' => $det ? $q('invoiceDeliveryDate', $det) : '',
			'currency' => $det ? ($q('currencyCode', $det) ?: 'HUF') : 'HUF',
			'exchangeRate' => $det ? (float) ($q('exchangeRate', $det) ?: 1) : 1,
			'paymentMethod' => $det ? $q('paymentMethod', $det) : '',
			'paymentDate' => $det ? $q('paymentDate', $det) : '',
			'originalInvoiceNumber' => $q('invoiceMain/invoice/invoiceReference/originalInvoiceNumber'),
			'modificationIndex' => $q('invoiceMain/invoice/invoiceReference/modificationIndex'),
			'netAmount' => (float) $q('invoiceMain/invoice/invoiceSummary/*/invoiceNetAmount'),
			'vatAmount' => (float) $q('invoiceMain/invoice/invoiceSummary/*/invoiceVatAmount'),
			'netAmountHuf' => (float) $q('invoiceMain/invoice/invoiceSummary/*/invoiceNetAmountHUF'),
			'vatAmountHuf' => (float) $q('invoiceMain/invoice/invoiceSummary/*/invoiceVatAmountHUF'),
			'lines' => array(),
			'warnings' => array(),
		);
		if ($inv['netAmount'] == 0 && $inv['vatAmount'] == 0) {
			// Simplified invoice: only gross amounts
			$inv['netAmount'] = (float) $q('invoiceMain/invoice/invoiceSummary/summaryGrossData/invoiceGrossAmount');
		}

		$lines = $node('invoiceMain/invoice/invoiceLines/line');
		foreach ($lines ?: array() as $ln) {
			$code = $q('productCodes/productCode/productCodeOwnValue', $ln) ?: $q('productCodes/productCode/productCodeValue', $ln);
			$qty = (float) $q('quantity', $ln);
			$net = $q('lineAmountsNormal/lineNetAmountData/lineNetAmount', $ln);
			$vatRate = null;
			$vatText = '';
			$vatCode = '';	// NAV case of a 0% line (AAM, TAM, EAM, ATK, HO…; FAD = domestic reverse charge)
			$netHuf = $vat = $vatHuf = null;
			if ($net !== '') {
				$net = (float) $net;
				$netHuf = $q('lineAmountsNormal/lineNetAmountData/lineNetAmountHUF', $ln);
				$vat = $q('lineAmountsNormal/lineVatData/lineVatAmount', $ln);
				$vatHuf = $q('lineAmountsNormal/lineVatData/lineVatAmountHUF', $ln);
				$pct = $q('lineAmountsNormal/lineVatRate/vatPercentage', $ln);
				if ($pct !== '') {
					$vatRate = round((float) $pct * 100, 2);
				} else {
					$vatRate = 0;
					foreach (array('vatExemption' => 'Áfamentes', 'vatOutOfScope' => 'Áfa hatályán kívüli', 'vatDomesticReverseCharge' => 'Fordított adózás', 'marginSchemeIndicator' => 'Különbözeti adózás', 'noVatCharge' => 'Nincs felszámított áfa') as $k => $label) {
						if ($node('lineAmountsNormal/lineVatRate/'.$k, $ln)->length) {
							$reason = $q('lineAmountsNormal/lineVatRate/'.$k.'/reason', $ln);
							$vatText = $label.($reason !== '' ? ': '.$reason : '');
							if ($k === 'vatDomesticReverseCharge') {
								$vatCode = 'FAD';
							} elseif (in_array($k, array('vatExemption', 'vatOutOfScope'), true)) {
								$vatCode = strtoupper(trim($q('lineAmountsNormal/lineVatRate/'.$k.'/case', $ln)));
							}
							break;
						}
					}
				}
			} else {
				// Simplified invoice line: gross amount and VAT content
				$gross = (float) $q('lineAmountsSimplified/lineGrossAmountSimplified', $ln);
				$content = (float) $q('lineAmountsSimplified/lineVatRate/vatContent', $ln);
				$net = round($gross * (1 - $content), 2);
				$vat = $gross - $net;
				$vatRate = 0;
				foreach (array(27, 18, 5) as $r) {
					if (abs($content - $r / (100 + $r)) < 0.001) {
						$vatRate = $r;
					}
				}
			}
			if ($qty == 0) {
				$qty = 1;
			}
			// Amounts as reported (the VAT in HUF is what the supplier declared; Dolibarr would recompute it)
			$vat = ($vat === null || $vat === '') ? round((float) $net * (float) $vatRate / 100, 2) : (float) $vat;
			$netHuf = ($netHuf === null || $netHuf === '') ? round((float) $net * $inv['exchangeRate'], 2) : (float) $netHuf;
			$vatHuf = ($vatHuf === null || $vatHuf === '') ? round($vat * $inv['exchangeRate'], 2) : (float) $vatHuf;
			$desc = $q('lineDescription', $ln);
			if ($vatText !== '') {
				$desc .= "\n(".$vatText.')';
			}
			$inv['lines'][] = array(
				'number' => (int) $q('lineNumber', $ln),
				'description' => $desc,
				'code' => $code,
				'qty' => $qty,
				'unit' => $q('unitOfMeasureOwn', $ln) ?: $q('unitOfMeasure', $ln),
				'net' => (float) $net,
				'unitPrice' => (float) $net / $qty,
				'vatRate' => (float) $vatRate,
				'vatCode' => $vatCode,
				'vat' => $vat,
				'netHuf' => $netHuf,
				'vatHuf' => $vatHuf,
				'nature' => $q('lineNatureIndicator', $ln),
				// Advance: a line of an advance (deposit) invoice, or - with the advance invoice number - the deduction
				// of an advance on the final invoice
				'advance' => $q('advanceData/advanceIndicator', $ln) === 'true',
				'advanceOriginal' => $q('advanceData/advancePaymentData/advanceOriginalInvoice', $ln),
			);
		}
		if (!$inv['lines']) {
			// No lines (e.g. merged items): one line per VAT rate of the summary
			foreach ($node('invoiceMain/invoice/invoiceSummary/summaryNormal/summaryByVatRate') ?: array() as $s) {
				$pct = $q('vatRate/vatPercentage', $s);
				$net = (float) $q('vatRateNetData/vatRateNetAmount', $s);
				$inv['lines'][] = array('number' => 0, 'description' => 'Számla összesen'.($pct !== '' ? ' ('.round((float) $pct * 100).'%)' : ''), 'code' => '', 'qty' => 1, 'unit' => '', 'net' => $net, 'unitPrice' => $net, 'vatRate' => $pct !== '' ? round((float) $pct * 100, 2) : 0,
					'vat' => (float) $q('vatRateVatData/vatRateVatAmount', $s), 'netHuf' => (float) $q('vatRateNetData/vatRateNetAmountHUF', $s), 'vatHuf' => (float) $q('vatRateVatData/vatRateVatAmountHUF', $s), 'nature' => '');
			}
			$inv['warnings'][] = 'A NAV-adatban nincsenek tételsorok: áfakulcsonként egy összesítő sor';
		}
		// VAT of the summary (rounded by the supplier, e.g. to whole forints): the rounding difference goes to the largest line
		if ($inv['lines']) {
			$big = 0;
			foreach ($inv['lines'] as $k => $l) {
				if (abs($l['net']) > abs($inv['lines'][$big]['net'])) {
					$big = $k;
				}
			}
			$limit = max(1, count($inv['lines']));
			foreach (array('vat' => 'vatAmount', 'vatHuf' => 'vatAmountHuf') as $lk => $sk) {
				if ($inv[$sk] == 0 && $sk === 'vatAmountHuf' && $inv['currency'] === 'HUF') {
					$inv[$sk] = $inv['vatAmount'];
				}
				$s = 0;
				foreach ($inv['lines'] as $l) {
					$s += $l[$lk];
				}
				$diff = round($inv[$sk] - $s, 2);
				if ($diff != 0 && abs($diff) <= $limit && $inv[$sk] != 0) {
					$inv['lines'][$big][$lk] = round($inv['lines'][$big][$lk] + $diff, 2);
				}
			}
		}
		$sum = 0;
		foreach ($inv['lines'] as $l) {
			$sum += $l['net'];
		}
		if (abs($sum - $inv['netAmount']) > 1) {
			$inv['warnings'][] = 'A tételek nettó összege ('.price($sum).') eltér a számla nettó összegétől ('.price($inv['netAmount']).')';
		}
		return $inv;
	}

	/**
	 * Partners whose tax number (idprof1 Adószám, idprof3 Csoportazonosító, EU VAT number HU...) starts with the
	 * given 8-digit tax id. Suppliers first.
	 *
	 * @param string $taxid 8 digits
	 * @return array<int,object> rowid, nom, fournisseur
	 */
	public function matchSupplier($taxid)
	{
		$taxid = substr(preg_replace('/\D/', '', (string) $taxid), 0, 8);
		if (strlen($taxid) !== 8) {
			return array();
		}
		if ($this->taxIndex === null) {
			$this->taxIndex = array();
			$sql = 'SELECT rowid, nom, fournisseur, siren, ape, tva_intra FROM '.$this->db->prefix().'societe WHERE entity IN ('.getEntity('societe').') AND status = 1';
			$res = $this->db->query($sql);
			while ($res && ($o = $this->db->fetch_object($res))) {
				foreach (array($o->siren, $o->ape, preg_replace('/^HU/i', '', (string) $o->tva_intra)) as $v) {
					$d = substr(preg_replace('/\D/', '', (string) $v), 0, 8);
					if (strlen($d) === 8) {
						$this->taxIndex[$d][$o->rowid] = $o;
					}
				}
			}
			foreach ($this->taxIndex as &$list) {
				uasort($list, function ($a, $b) {
					return ((int) $b->fournisseur - (int) $a->fournisseur) ?: ((int) $a->rowid - (int) $b->rowid);
				});
			}
			unset($list);
		}
		return array_values($this->taxIndex[$taxid] ?? array());
	}

	/**
	 * Supplier for a parsed invoice: a group member's own tax number first (group VAT ID invoices), then the
	 * supplier tax number.
	 *
	 * @param array<string,mixed> $inv Parsed invoice
	 * @param object              $row Row
	 * @return array<int,object>
	 */
	public function matchForInvoice($inv, $row)
	{
		if ($inv && $inv['supplier']['groupMemberTaxNumber'] !== '') {
			$m = $this->matchSupplier($inv['supplier']['groupMemberTaxNumber']);
			if ($m) {
				return $m;
			}
		}
		return $this->matchSupplier($row->supplier_taxid);
	}

	/**
	 * Create a supplier from the invoice data (confirmed and possibly edited by the user).
	 *
	 * @param array<string,string> $data name, tax_number, eu_vat, country, zip, town, address, bank_account, payment_mode_id
	 * @param User                 $user User
	 * @return int Societe id, <0 on error
	 */
	public function createSupplier($data, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';

		$soc = new Societe($this->db);
		$soc->name = trim($data['name']);
		$soc->client = 0;
		$soc->fournisseur = 1;
		$soc->code_fournisseur = -1;	// automatic
		$soc->status = 1;
		$soc->idprof1 = trim($data['tax_number']);
		$soc->tva_intra = trim($data['eu_vat']);
		$soc->tva_assuj = 1;
		$soc->address = trim($data['address']);
		$soc->zip = trim($data['zip']);
		$soc->town = trim($data['town']);
		$soc->country_id = (int) getCountry(trim($data['country']) ?: 'HU', '3');
		if (!empty($data['payment_mode_id'])) {
			$soc->mode_reglement_supplier_id = (int) $data['payment_mode_id'];
		}
		$soc->note_private = 'Létrehozva a NAV Online Számla bejövő számlájából, '.dol_print_date(dol_now(), '%Y-%m-%d');
		if ($soc->name === '') {
			$this->errors[] = 'A szállító neve üres';
			return -1;
		}
		$id = $soc->create($user);
		if ($id <= 0) {
			$this->errors[] = 'Szállító: '.$soc->error.' '.implode(', ', $soc->errors);
			return -1;
		}
		if (!empty($data['payment_mode_id'])) {
			$this->db->query('UPDATE '.$this->db->prefix().'societe SET mode_reglement_supplier = '.((int) $data['payment_mode_id']).' WHERE rowid = '.((int) $id));
		}
		$acc = trim($data['bank_account']);
		if ($acc !== '') {
			$rib = new CompanyBankAccount($this->db);
			$rib->socid = $id;
			$rib->label = 'Bankszámla (NAV)';
			$rib->number = $acc;
			$rib->proprio = $soc->name;
			$rib->owner_name = $soc->name;
			$rib->default_rib = 1;
			if (preg_match('/^HU\d{26}$/', str_replace(' ', '', $acc))) {
				$rib->iban = str_replace(' ', '', $acc);
				$rib->number = '';
			}
			$rib->country_code = 'HU';
			$rib->type = 'ban';
			if ($rib->create($user) > 0) {
				$rib->update($user);
			}
		}
		$this->taxIndex = null;
		return $id;
	}

	/**
	 * Payment mode id for a NAV payment method.
	 *
	 * @param string $method TRANSFER, CASH, CARD, VOUCHER, OTHER
	 * @return int 0 = none
	 */
	public function paymentModeId($method)
	{
		$code = array('TRANSFER' => 'VIR', 'CASH' => 'LIQ', 'CARD' => 'CB')[strtoupper((string) $method)] ?? '';
		if ($code === '') {
			return 0;
		}
		$res = $this->db->query('SELECT id FROM '.$this->db->prefix()."c_paiement WHERE code = '".$this->db->escape($code)."' AND active = 1 AND entity IN (".getEntity('c_paiement').') LIMIT 1');
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->id : 0;
	}

	/**
	 * Product of the supplier with this supplier product code (Beszerzési árak: supplier ref).
	 *
	 * @param int    $socid Supplier
	 * @param string $code  Product code on the invoice
	 * @return int 0 = none
	 */
	public function productForCode($socid, $code)
	{
		if (trim((string) $code) === '') {
			return 0;
		}
		$res = $this->db->query('SELECT fk_product FROM '.$this->db->prefix().'product_fournisseur_price WHERE fk_soc = '.((int) $socid)." AND ref_fourn = '".$this->db->escape(trim($code))."' AND entity IN (".getEntity('productsupplierprice').') ORDER BY rowid DESC LIMIT 1');
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->fk_product : 0;
	}

	/**
	 * Create the draft supplier invoice of a row. If the supplier already has an invoice with this supplier
	 * reference (entered by hand), the row is linked to it instead.
	 *
	 * Links (optional): receptions and purchase orders of the supplier the invoice belongs to, as the supplier invoice
	 * card links them when an invoice is created from a reception (a reception brings its order too). On validation the
	 * workflow then classifies them billed when the amounts match.
	 *
	 * @param object                                       $row   Row
	 * @param int                                          $socid Supplier
	 * @param User                                         $user  User
	 * @param array{reception?:int[],order_supplier?:int[]} $links Documents to link
	 * @param bool                                         $standalone A reducing modification / storno whose original
	 *                                                                 invoice is not in Dolibarr (issued before it was
	 *                                                                 used) is recorded as a credit note without source
	 * @return int FactureFournisseur id, <0 on error
	 */
	public function record($row, $socid, $user, array $links = array(), $standalone = false)
	{
		$this->warnings = array();
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		if ((int) $row->status === self::STATUS_RECORDED && $row->fk_facture_fourn > 0) {
			$this->errors[] = 'Ez a számla már rögzítve van';
			return -1;
		}
		$inv = $this->getInvoiceData($row);
		if (!$inv) {
			return -1;
		}
		$soc = new Societe($this->db);
		if ($soc->fetch((int) $socid) <= 0) {
			$this->errors[] = 'A szállító nem található';
			return -1;
		}

		// Already entered by hand?
		$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix()."facture_fourn WHERE ref_supplier = '".$this->db->escape($row->invoice_number)."' AND fk_soc = ".((int) $socid).' AND entity IN ('.getEntity('invoice').')');
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o) {
			$diff = $this->compareWithNav((int) $o->rowid, $row);
			$note = 'Már létező szállítói számlához kapcsolva'.($diff !== '' ? ' – ELTÉRÉS: '.$diff : '');
			if ($diff !== '') {
				$this->warnings[] = 'A már rögzített '.$row->invoice_number.' számla összege eltér a NAV-ban szereplőtől: '.$diff;
				$this->db->query('UPDATE '.$this->db->prefix()."facture_fourn SET note_private = CONCAT(COALESCE(note_private, ''), '".$this->db->escape("\nNAV eltérés: ".$diff)."') WHERE rowid = ".((int) $o->rowid));
			}
			$this->linkDocuments((int) $o->rowid, (int) $socid, $links);
			$this->markRecorded($row, (int) $o->rowid, $socid, dol_trunc($note, 250, 'right', 'UTF-8', 1));
			return (int) $o->rowid;
		}

		$isCredit = false;
		$source = 0;
		$kind = self::modificationKind($row, $inv);
		$orig = $inv['originalInvoiceNumber'] ?: (string) $row->original_invoice_number;
		$origId = ($kind !== '') ? $this->findOriginal($orig, (int) $socid) : 0;
		// Modification of data only (delivery date, customer data...: no item and no amount): no supplier invoice, the
		// original is annotated (and its dates follow while it is a draft)
		if ($row->operation === 'MODIFY' && self::isDataOnly($inv)) {
			return $this->recordDataOnly($row, $inv, $origId, (int) $socid, $orig);
		}
		if ($kind === 'credit') {
			if (!$origId && !$standalone) {
				$this->errors[] = 'Ez '.($row->operation === 'STORNO' ? 'sztornó' : 'csökkentő módosító').' számla: előbb az eredeti számlát ('.$orig.') kell rögzíteni, vagy – ha az a Dolibarr előtti időszakból való – jelöld be a „Rögzítés eredeti számla nélkül” lehetőséget';
				return -1;
			}
			$isCredit = true;
			$source = $origId;
		} elseif ($kind === 'increase' && !$origId) {
			$this->warnings[] = 'Növelő módosító számla: az eredeti számla ('.$orig.') nincs a Dolibarrban, ezért nincs hozzá kapcsolva';
		}

		// Előlegszámla: every line is an advance (and none deducts one). Végszámla: deduction lines of advance invoices;
		// a deduction becomes a Dolibarr deposit use (discount on the line) when the advance invoice is in Dolibarr
		$isDeposit = false;
		$deductions = array();	// line index => supplier advance invoice id (0 = not in Dolibarr)
		if (!$isCredit && $kind === '' && !empty($inv['lines'])) {
			$isDeposit = true;
			foreach ($inv['lines'] as $i => $l) {
				if (empty($l['advance']) || $l['advanceOriginal'] !== '') {
					$isDeposit = false;
				}
			}
		}
		foreach ($inv['lines'] as $i => $l) {
			if (!empty($l['advance']) && $l['advanceOriginal'] !== '' && $l['net'] < 0) {
				$depId = $this->findOriginal($l['advanceOriginal'], (int) $socid);
				$deductions[$i] = $depId;
				if (!$depId) {
					$this->warnings[] = 'Előleg levonása: a(z) '.$l['advanceOriginal'].' előlegszámla nincs a Dolibarrban, a levonás egyszerű negatív tételként rögzül';
				}
			}
		}

		$this->db->begin();
		$f = new FactureFournisseur($this->db);
		$f->socid = $soc->id;
		$f->ref_supplier = $row->invoice_number;
		$f->type = $isCredit ? FactureFournisseur::TYPE_CREDIT_NOTE : ($isDeposit ? FactureFournisseur::TYPE_DEPOSIT : FactureFournisseur::TYPE_STANDARD);
		$f->fk_facture_source = $source ?: null;
		$f->date = dol_stringtotime($inv['issueDate'].' 12:00:00', 0);
		$f->date_echeance = $inv['paymentDate'] !== '' ? dol_stringtotime($inv['paymentDate'].' 12:00:00', 0) : $f->date;
		$f->date_pointoftax = $inv['deliveryDate'] !== '' ? dol_stringtotime($inv['deliveryDate'].' 12:00:00', 0) : $f->date;	// teljesítés dátuma
		$f->label = dol_trunc($inv['lines'][0]['description'] ?? '', 250, 'right', 'UTF-8', 1);
		if ($kind !== '' && $orig !== '') {
			$f->label = dol_trunc(($row->operation === 'STORNO' ? 'Sztornó számla' : 'Módosító számla').' - '.$orig, 250, 'right', 'UTF-8', 1);
		} elseif ($isDeposit) {
			$f->label = 'Előlegszámla';
		} elseif ($deductions) {
			$refs = array();
			foreach ($deductions as $i => $depId) {
				$refs[] = $inv['lines'][$i]['advanceOriginal'];
			}
			$f->label = dol_trunc('Végszámla - előleg: '.implode(', ', array_unique($refs)), 250, 'right', 'UTF-8', 1);
		}
		$f->libelle = $f->label;
		$f->cond_reglement_id = $soc->cond_reglement_supplier_id ?: 0;
		$f->mode_reglement_id = $this->paymentModeId($inv['paymentMethod']) ?: ($soc->mode_reglement_supplier_id ?: 0);
		$notes = array('NAV Online Számla: '.$row->invoice_number.' ('.$row->operation.($inv['modificationIndex'] !== '' ? ' #'.$inv['modificationIndex'] : '').')');
		if ($inv['deliveryDate'] !== '') {
			$notes[] = 'Teljesítés dátuma: '.$inv['deliveryDate'];
		}
		if ($inv['originalInvoiceNumber'] !== '') {
			$notes[] = 'Eredeti számla: '.$inv['originalInvoiceNumber'].($kind !== '' && !$origId ? ' (nincs a Dolibarrban'.($isCredit ? ', jóváíró eredeti számla nélkül rögzítve' : '').')' : '');
		}
		foreach ($deductions as $i => $depId) {
			$notes[] = 'Előleg levonása: '.$inv['lines'][$i]['advanceOriginal'].($depId ? '' : ' (az előlegszámla nincs a Dolibarrban)');
		}
		foreach ($inv['warnings'] as $w) {
			$notes[] = 'Figyelem: '.$w;
		}
		$f->note_private = implode("\n", $notes);
		$foreign = strtoupper($inv['currency']) !== strtoupper($conf->currency) && isModEnabled('multicurrency');
		if ($foreign) {
			require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
			list($mcId) = MultiCurrency::getIdAndTxFromCode($this->db, $inv['currency'], $f->date);
			if (!$mcId) {
				$this->db->rollback();
				$this->errors[] = 'A '.$inv['currency'].' pénznem nincs beállítva a Dolibarrban';
				return -1;
			}
			$f->fk_multicurrency = $mcId;
			$f->multicurrency_code = $inv['currency'];
			// Rate of the HUF amounts reported to NAV (some suppliers print a different exchangeRate than the one used)
			$hufPerUnit = ($inv['netAmount'] != 0 && $inv['netAmountHuf'] != 0) ? $inv['netAmountHuf'] / $inv['netAmount'] : $inv['exchangeRate'];
			$invTx = $hufPerUnit > 0 ? 1 / $hufPerUnit : 1;	// as Dolibarr rate (units per HUF)
			$f->multicurrency_tx = $invTx;
		}
		$id = $f->create($user);
		if ($id <= 0) {
			$this->db->rollback();
			$this->errors[] = 'Szállítói számla: '.$f->error.' '.implode(', ', $f->errors);
			return -1;
		}
		if ($foreign) {
			// create() may have replaced the rate by the rate of the day: use the rate printed on the invoice
			$this->db->query('UPDATE '.$this->db->prefix().'facture_fourn SET multicurrency_tx = '.((float) $invTx).' WHERE rowid = '.((int) $id));
			$f->fetch($id);
			$f->multicurrency_tx = $invTx;
		}
		foreach ($inv['lines'] as $i => $l) {
			$fkProduct = $this->productForCode($soc->id, $l['code']);
			$desc = $l['description'].($l['code'] !== '' && !$fkProduct ? "\nCikkszám: ".$l['code'] : '');
			$qty = $l['qty'];
			$pu = $l['unitPrice'];
			if ($isCredit) {
				$qty = abs($qty);
				$pu = abs($pu);
			}
			$puHuf = $foreign ? $pu / $invTx : $pu;
			// 0% line: the NAV case as VAT code of the line when the VAT dictionary has it (as on customer invoices)
			$txtva = $l['vatRate'];
			$vc = (string) ($l['vatCode'] ?? '');
			if ((float) $l['vatRate'] == 0.0 && $vc !== '' && $this->vatCodeExists($vc)) {
				$txtva = '0 ('.$vc.')';
			}
			$r = $f->addline($desc, $puHuf, $txtva, 0, 0, $qty, $fkProduct, 0, 0, 0, 0, 0, 'HT', ($l['nature'] === 'SERVICE' ? 1 : 0), $i + 1, 1, array(), null, 0, $foreign ? $pu : 0, $l['code']);
			if ($r < 0) {
				$this->db->rollback();
				$this->errors[] = 'Tétel '.($i + 1).': '.$f->error.' '.implode(', ', $f->errors);
				return -1;
			}
			// Line totals exactly as reported to NAV (VAT rounded by the supplier, HUF amounts at the supplier's rate).
			// Each line keeps its own NAV sign: a modification may cancel the original line (-) and add the right one (+)
			$sg = ($l['net'] < 0 || ($l['net'] == 0 && $isCredit)) ? -1 : 1;
			$netHuf = $sg * abs($l['netHuf']);
			$vatHuf = $sg * abs($l['vatHuf']);
			$sql = 'UPDATE '.$this->db->prefix().'facture_fourn_det SET total_ht = '.price2num($netHuf).', tva = '.price2num($vatHuf).', total_ttc = '.price2num($netHuf + $vatHuf);
			if ($isCredit && $sg > 0) {
				// Core forces credit note unit prices negative: a positive NAV line gets its positive price back
				$sql .= ', pu_ht = ABS(pu_ht), pu_ttc = ABS(pu_ttc), multicurrency_subprice = ABS(multicurrency_subprice), multicurrency_subprice_ttc = ABS(multicurrency_subprice_ttc)';
			}
			if ($foreign) {
				$sql .= ', multicurrency_total_ht = '.price2num($sg * abs($l['net'])).', multicurrency_total_tva = '.price2num($sg * abs($l['vat'])).', multicurrency_total_ttc = '.price2num($sg * (abs($l['net']) + abs($l['vat'])));
			} else {
				$sql .= ', multicurrency_total_ht = '.price2num($netHuf).', multicurrency_total_tva = '.price2num($vatHuf).', multicurrency_total_ttc = '.price2num($netHuf + $vatHuf);
			}
			if (!$this->db->query($sql.' WHERE rowid = '.((int) $r))) {
				$this->db->rollback();
				$this->errors[] = 'Tétel '.($i + 1).': '.$this->db->lasterror();
				return -1;
			}
			if (!empty($deductions[$i]) && $this->useDeposit((int) $deductions[$i], (int) $r, $soc->id, $l, $txtva, $foreign ? $inv['currency'] : '', $user) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		// Totals from the stored line amounts (the hook changeRoundingMode keeps them on later recalculations too)
		global $hookmanager;
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('invoicesuppliercard'));
		$this->markRecorded($row, $id, $soc->id, '');
		$f->update_price(1);
		$this->linkDocuments($id, (int) $soc->id, $links);
		if ($kind === 'increase' && $origId) {
			// Increasing modification: a standard supplier invoice, linked to the invoice it modifies
			$f->add_object_linked('invoice_supplier', $origId);
		}
		foreach (array_unique(array_filter($deductions)) as $depId) {
			$f->add_object_linked('invoice_supplier', $depId);	// final invoice <-> its advance invoice(s)
		}
		$this->db->commit();
		return $id;
	}

	/**
	 * The deduction line of a final invoice uses the supplier advance invoice as a Dolibarr deposit: a supplier
	 * discount from the advance invoice with the amounts of the NAV line, linked to the line (as "use discount" on the
	 * card would do). So the advance counts as used and is not offered again. Skipped with a warning when the advance
	 * is already used up (e.g. converted and used by hand): no double deduction.
	 *
	 * @param int                 $depId    Supplier advance invoice
	 * @param int                 $lineId   Deduction line (facture_fourn_det)
	 * @param int                 $socid    Supplier
	 * @param array<string,mixed> $l        Parsed NAV line
	 * @param string              $txtva    VAT rate of the line ('27' or '0 (CODE)')
	 * @param string              $currency Foreign currency code, '' for HUF
	 * @param User                $user     User
	 * @return int <0 on error
	 */
	private function useDeposit($depId, $lineId, $socid, $l, $txtva, $currency, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
		$dep = new FactureFournisseur($this->db);
		if ($dep->fetch($depId) <= 0) {
			return 0;
		}
		$ttcHuf = abs((float) $l['netHuf']) + abs((float) $l['vatHuf']);
		$res = $this->db->query('SELECT SUM(amount_ttc) AS s FROM '.$this->db->prefix().'societe_remise_except WHERE fk_invoice_supplier_source = '.((int) $depId));
		$o = $res ? $this->db->fetch_object($res) : null;
		$used = $o ? (float) $o->s : 0;
		if ((int) $dep->type !== FactureFournisseur::TYPE_DEPOSIT || $used + $ttcHuf > abs((float) $dep->total_ttc) + 0.01) {
			$this->warnings[] = 'Előleg levonása: a(z) '.$dep->ref_supplier.' '.((int) $dep->type !== FactureFournisseur::TYPE_DEPOSIT ? 'nem előlegszámlaként van rögzítve' : 'előlegszámla már fel van használva (kedvezménnyé alakítva)').', ezért a levonás egyszerű negatív tétel - ellenőrizd, nincs-e kétszer levonva';
			return 0;
		}
		$d = new DiscountAbsolute($this->db);
		$d->description = '(DEPOSIT)';
		$d->discount_type = 1;	// supplier
		$d->fk_soc = $d->socid = $socid;
		$d->fk_invoice_supplier_source = $depId;
		$d->tva_tx = (float) preg_replace('/\s*\(.*\)/', '', (string) $txtva);
		$d->vat_src_code = preg_match('/\((.+)\)/', (string) $txtva, $m) ? $m[1] : '';
		$d->amount_ht = $d->total_ht = abs((float) $l['netHuf']);
		$d->amount_tva = $d->total_tva = abs((float) $l['vatHuf']);
		$d->amount_ttc = $d->total_ttc = $ttcHuf;
		$mc = $currency !== '' ? array(abs((float) $l['net']), abs((float) $l['vat'])) : array($d->amount_ht, $d->amount_tva);
		$d->multicurrency_amount_ht = $d->multicurrency_total_ht = $mc[0];
		$d->multicurrency_amount_tva = $d->multicurrency_total_tva = $mc[1];
		$d->multicurrency_amount_ttc = $d->multicurrency_total_ttc = $mc[0] + $mc[1];
		if ($currency !== '') {
			$d->multicurrency_code = $currency;
			$d->multicurrency_tx = $dep->multicurrency_tx;
		}
		if ($d->create($user) <= 0 || $d->link_to_invoice($lineId, 0) < 0) {
			$this->errors[] = 'Előleg levonása: '.$d->error;
			return -1;
		}
		if (!$this->db->query('UPDATE '.$this->db->prefix().'facture_fourn_det SET fk_remise_except = '.((int) $d->id).' WHERE rowid = '.((int) $lineId))) {
			$this->errors[] = 'Előleg levonása: '.$this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * @param array<string,mixed> $inv Parsed invoice data
	 * @return bool The document changes data only: no line with an amount and zero totals (NAV allows a modification
	 *              that only corrects e.g. the delivery date or the customer data)
	 */
	public static function isDataOnly($inv)
	{
		if ((float) $inv['netAmount'] != 0 || (float) $inv['vatAmount'] != 0 || (float) $inv['netAmountHuf'] != 0 || (float) $inv['vatAmountHuf'] != 0) {
			return false;
		}
		foreach ((array) $inv['lines'] as $l) {
			if ((float) $l['net'] != 0 || (float) $l['vat'] != 0) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Data-only modification: linked to its original supplier invoice, which gets a note of what changed. A draft
	 * original takes the new teljesítés dátuma / payment due date; a validated one is not changed (the VAT deduction
	 * period may be concerned: a warning for the user).
	 *
	 * @param object              $row    Row
	 * @param array<string,mixed> $inv    Parsed invoice data
	 * @param int                 $origId Original supplier invoice (0 = not in Dolibarr)
	 * @param int                 $socid  Supplier
	 * @param string              $orig   Original invoice number
	 * @return int Original supplier invoice id, <0 on error
	 */
	private function recordDataOnly($row, $inv, $origId, $socid, $orig)
	{
		if ($origId <= 0) {
			$this->errors[] = 'Adatmódosító okirat (tétel és összeg nélkül, pl. teljesítési dátum vagy vevőadat javítása): nincs mit rögzíteni, mert az eredeti számla ('.$orig.') nincs a Dolibarrban. Ha az eredetit később rögzíted, ezt utána rögzítsd; ha a Dolibarr előtti időszakból való, jelöld „Kihagyás”-sal.';
			return -1;
		}
		$p = $this->db->prefix();
		$o = $this->db->fetch_object($this->db->query('SELECT fk_statut, date_pointoftax, date_lim_reglement FROM '.$p.'facture_fourn WHERE rowid = '.((int) $origId)));
		$changes = $set = array();
		foreach (array(array('deliveryDate', 'date_pointoftax', 'teljesítés dátuma'), array('paymentDate', 'date_lim_reglement', 'fizetési határidő')) as $c) {
			$new = (string) $inv[$c[0]];
			$old = !empty($o->{$c[1]}) ? dol_print_date($this->db->jdate($o->{$c[1]}), '%Y-%m-%d') : '';
			if ($new !== '' && $new !== $old) {
				$changes[] = $c[2].': '.($old !== '' ? $old : '-').' → '.$new;
				$set[] = $c[1]." = '".$this->db->idate(dol_stringtotime($new.' 12:00:00', 0))."'";
			}
		}
		$draft = $o && (int) $o->fk_statut === 0;
		$note = 'NAV adatmódosítás '.$row->invoice_number.($inv['modificationIndex'] !== '' ? ' #'.$inv['modificationIndex'] : '').': '
			.($changes ? implode('; ', $changes).($draft ? ' (átvezetve)' : ' (nincs átvezetve, a számla jóvá van hagyva)') : 'tétel és összeg nélkül (pl. vevő- vagy egyéb fejadat javítása)');
		$this->db->begin();
		$sql = 'UPDATE '.$p."facture_fourn SET note_private = CONCAT(COALESCE(note_private, ''), '".$this->db->escape("\n".$note)."')".($draft && $set ? ', '.implode(', ', $set) : '').' WHERE rowid = '.((int) $origId);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$this->markRecorded($row, $origId, $socid, dol_trunc($note, 250, 'right', 'UTF-8', 1));
		$this->db->commit();
		$this->warnings[] = 'Adatmódosító okirat: nem készült új szállítói számla, a(z) '.$orig.' számlához kapcsoltam. '.$note;
		if ($changes && !$draft) {
			$this->warnings[] = 'A(z) '.$orig.' számla már jóvá van hagyva, az adatait nem módosítottam. Ha a teljesítés dátuma változott, ellenőrizd a könyvelővel az ÁFA-levonás időszakát.';
		}
		return $origId;
	}

	/**
	 * Kind of a NAV modification: 'credit' (STORNO or MODIFY with negative net: recorded as a supplier credit note),
	 * 'increase' (MODIFY with net >= 0: recorded as a standard invoice linked to the original), '' for CREATE.
	 *
	 * @param object               $row Row
	 * @param array<string,mixed>  $inv Parsed invoice data
	 * @return string
	 */
	public static function modificationKind($row, $inv)
	{
		if ($row->operation === 'STORNO' || ($row->operation === 'MODIFY' && (float) $inv['netAmount'] < 0)) {
			return 'credit';
		}
		return $row->operation === 'MODIFY' ? 'increase' : '';
	}

	/**
	 * Supplier invoice of the supplier with this supplier reference (the modified original), or 0.
	 *
	 * @param string $orig  Original invoice number
	 * @param int    $socid Supplier
	 * @return int
	 */
	public function findOriginal($orig, $socid)
	{
		if ($orig === '' || $socid <= 0) {
			return 0;
		}
		$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix()."facture_fourn WHERE ref_supplier = '".$this->db->escape($orig)."' AND fk_soc = ".((int) $socid).' AND entity IN ('.getEntity('invoice').')');
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->rowid : 0;
	}

	/**
	 * @param string $code VAT code
	 * @return bool The (0%) code is in the VAT dictionary of the company's country
	 */
	private function vatCodeExists($code)
	{
		global $mysoc;
		static $cache = array();
		if (!isset($cache[$code])) {
			$sql = 'SELECT t.rowid FROM '.$this->db->prefix().'c_tva t JOIN '.$this->db->prefix()."c_country c ON c.rowid = t.fk_pays WHERE c.code = '".$this->db->escape($mysoc->country_code ?: 'HU')."'";
			$sql .= " AND t.code = '".$this->db->escape($code)."' AND t.taux = 0 AND t.active = 1";
			$res = $this->db->query($sql);
			$cache[$code] = ($res && $this->db->num_rows($res) > 0);
		}
		return $cache[$code];
	}

	/**
	 * Net and VAT of an invoice already in Dolibarr against the NAV data of the row (invoice currency when foreign,
	 * else HUF; credit notes compared in absolute value).
	 *
	 * @param int    $facid Supplier invoice
	 * @param object $row   Row
	 * @return string '' when equal (within 0.5), else the differences
	 */
	private function compareWithNav($facid, $row)
	{
		global $conf;
		$res = $this->db->query('SELECT total_ht, total_tva, multicurrency_code, multicurrency_total_ht, multicurrency_total_tva FROM '.$this->db->prefix().'facture_fourn WHERE rowid = '.((int) $facid));
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o) {
			return '';
		}
		$cur = strtoupper((string) $row->currency) ?: $conf->currency;
		$foreign = $cur !== strtoupper($conf->currency);
		if ($foreign && strtoupper((string) $o->multicurrency_code) !== $cur) {
			return 'pénznem: Dolibarr '.($o->multicurrency_code ?: $conf->currency).', NAV '.$cur;
		}
		$pairs = $foreign
			? array('nettó' => array($o->multicurrency_total_ht, $row->net_amount), 'ÁFA' => array($o->multicurrency_total_tva, $row->vat_amount))
			: array('nettó' => array($o->total_ht, $row->net_amount_huf), 'ÁFA' => array($o->total_tva, $row->vat_amount_huf));
		$out = array();
		foreach ($pairs as $label => $p) {
			if (abs(abs((float) $p[0]) - abs((float) $p[1])) > 0.5) {
				$out[] = $label.' Dolibarr '.price(abs((float) $p[0])).', NAV '.price(abs((float) $p[1])).' '.$cur;
			}
		}
		return implode('; ', $out);
	}

	/**
	 * Link a supplier invoice to receptions / purchase orders of the same supplier (a reception also brings its order).
	 *
	 * @param int                                           $facid Supplier invoice
	 * @param int                                           $socid Supplier
	 * @param array{reception?:int[],order_supplier?:int[]} $links Ids
	 * @return int Number of new links
	 */
	public function linkDocuments($facid, $socid, array $links)
	{
		$p = $this->db->prefix();
		$want = array('reception' => array(), 'order_supplier' => array());
		foreach (array_map('intval', $links['reception'] ?? array()) as $rid) {
			$res = $this->db->query('SELECT rowid FROM '.$p.'reception WHERE rowid = '.$rid.' AND fk_soc = '.((int) $socid));
			if ($rid > 0 && $res && $this->db->num_rows($res)) {
				$want['reception'][$rid] = $rid;
				$res = $this->db->query('SELECT fk_source FROM '.$p."element_element WHERE targettype = 'reception' AND fk_target = ".$rid." AND sourcetype IN ('order_supplier', 'supplierorder', 'commandefourn')");
				while ($res && ($o = $this->db->fetch_object($res))) {
					$want['order_supplier'][(int) $o->fk_source] = (int) $o->fk_source;
				}
			}
		}
		foreach (array_map('intval', $links['order_supplier'] ?? array()) as $cid) {
			$res = $this->db->query('SELECT rowid FROM '.$p.'commande_fournisseur WHERE rowid = '.$cid.' AND fk_soc = '.((int) $socid));
			if ($cid > 0 && $res && $this->db->num_rows($res)) {
				$want['order_supplier'][$cid] = $cid;
			}
		}
		$n = 0;
		foreach ($want as $type => $ids) {
			foreach ($ids as $sid) {
				$res = $this->db->query('SELECT rowid FROM '.$p."element_element WHERE sourcetype = '".$type."' AND fk_source = ".$sid." AND targettype = 'invoice_supplier' AND fk_target = ".((int) $facid));
				if ($res && !$this->db->num_rows($res)) {
					$this->db->query('INSERT INTO '.$p."element_element (fk_source, sourcetype, fk_target, targettype) VALUES (".$sid.", '".$type."', ".((int) $facid).", 'invoice_supplier')");
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * Receptions and purchase orders of a supplier that are not billed yet (candidates to link on the record page).
	 *
	 * @param int $socid Supplier
	 * @return array{reception:object[],order_supplier:object[]}
	 */
	public function openDocuments($socid)
	{
		$p = $this->db->prefix();
		$out = array('reception' => array(), 'order_supplier' => array());
		if ($socid <= 0) {
			return $out;
		}
		$sql = 'SELECT r.rowid, r.ref, r.date_reception AS d, r.fk_statut, (SELECT SUM(cd.subprice * rd.qty) FROM '.$p.'receptiondet_batch rd JOIN '.$p.'commande_fournisseurdet cd ON cd.rowid = rd.fk_elementdet WHERE rd.fk_reception = r.rowid) AS total_ht';
		$sql .= ' FROM '.$p.'reception r WHERE r.fk_soc = '.((int) $socid).' AND r.fk_statut IN (1, 2) AND COALESCE(r.billed, 0) = 0 AND r.entity IN ('.getEntity('reception').') ORDER BY r.rowid DESC LIMIT 30';
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out['reception'][] = $o;
		}
		$sql = 'SELECT c.rowid, c.ref, c.date_commande AS d, c.fk_statut, c.total_ht FROM '.$p.'commande_fournisseur c WHERE c.fk_soc = '.((int) $socid);
		$sql .= ' AND c.fk_statut IN (2, 3, 4, 5) AND COALESCE(c.billed, 0) = 0 AND c.entity IN ('.getEntity('supplier_order').') ORDER BY c.rowid DESC LIMIT 30';
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out['order_supplier'][] = $o;
		}
		return $out;
	}

	/**
	 * @param object $row   Row
	 * @param int    $facid Supplier invoice
	 * @param int    $socid Supplier
	 * @param string $note  Note
	 * @return void
	 */
	private function markRecorded($row, $facid, $socid, $note)
	{
		$this->db->query('UPDATE '.$this->db->prefix().'navonline_inbound SET status = '.self::STATUS_RECORDED.', fk_facture_fourn = '.((int) $facid).', fk_soc = '.((int) $socid).', note = '.($note !== '' ? "'".$this->db->escape($note)."'" : 'NULL').' WHERE rowid = '.((int) $row->rowid));
		$row->status = self::STATUS_RECORDED;
		$row->fk_facture_fourn = $facid;
	}

	/**
	 * @param int $id     Row
	 * @param int $status STATUS_NEW or STATUS_IGNORED
	 * @return bool
	 */
	public function setStatus($id, $status)
	{
		global $conf;
		$status = (int) $status === self::STATUS_IGNORED ? self::STATUS_IGNORED : self::STATUS_NEW;
		return (bool) $this->db->query('UPDATE '.$this->db->prefix().'navonline_inbound SET status = '.$status.' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity).' AND status <> '.self::STATUS_RECORDED);
	}
}
