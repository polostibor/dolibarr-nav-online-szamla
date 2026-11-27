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
 * \file    htdocs/custom/navonline/class/navonlinex01.class.php
 * \ingroup navonline
 * \brief   Adóhatósági ellenőrzési adatszolgáltatás (23/2014. (VI. 30.) NGM rendelet 11/A. §): invoice data at issue.
 *
 * Every validated customer invoice (standard, deposit, credit note, also OSS; not a draft nor a proforma) gets a
 * snapshot at validation: its invoiceData 3.0 XML (NavOnlineXmlBuilder, option x01: name and address of a private
 * person customer, OSS lines with their foreign rate, no NAV query) validated against the XSD, with its SHA-256, the
 * totals and the errors. The export (phase 2) is made from these snapshots, so it gives the data of the day of issue,
 * not today's third party data. A snapshot row is never changed: a new validation of the same invoice (back to draft
 * and validated again) adds a row, the latest one counts. Invoices validated before this function get a snapshot from
 * the data of the day it is made (backfill, not_at_issue = 1). Nothing is sent to NAV here.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/navonlinexmlbuilder.class.php';
require_once __DIR__.'/navonlinexsdvalidator.class.php';

/**
 * Tax authority audit export: snapshots
 */
class NavOnlineX01
{
	/** @var DoliDB */
	public $db;

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param object $invoice Facture (or a row with type and status)
	 * @return bool The invoice belongs to the audit export: a customer invoice issued (validated or later), not a proforma
	 */
	public static function isConcerned($invoice)
	{
		return is_object($invoice) && (int) ($invoice->status ?? $invoice->fk_statut ?? 0) > 0 && (int) $invoice->type !== Facture::TYPE_PROFORMA;
	}

	/**
	 * Series and rank of an invoice number: the prefix letters and the number after the last dash (the counter of the
	 * numbering module "terre", PREFyymm-nnnn, runs on per prefix); other masks: all the digits, in order.
	 *
	 * @param string $ref Invoice number
	 * @return array{0:string,1:int|null} Series, rank (null when the number has no digit)
	 */
	public static function seriesOf($ref)
	{
		$ref = trim((string) $ref);
		if (preg_match('/^([A-Za-z]+)\d{4}-(\d+)$/', $ref, $m)) {
			return array(strtoupper($m[1]), (int) $m[2]);
		}
		$series = preg_match('/^([A-Za-z]+)/', $ref, $m) ? strtoupper($m[1]) : '';
		$digits = preg_replace('/\D/', '', $ref);
		return array($series, $digits !== '' ? (int) substr($digits, -18) : null);
	}

	/**
	 * Make the snapshot of an invoice now: XML at the data of now, XSD check, store.
	 *
	 * @param int    $invoiceId Invoice
	 * @param string $source    validation | backfill
	 * @param User   $user      User
	 * @return int Snapshot id, 0 when the invoice is not concerned, <0 on database error
	 */
	public function snapshot($invoiceId, $source, $user)
	{
		global $conf, $mysoc;

		$this->errors = array();
		$inv = new Facture($this->db);
		if ($inv->fetch((int) $invoiceId) <= 0) {
			$this->errors[] = 'Invoice '.$invoiceId.' not found';
			return -1;
		}
		if (!self::isConcerned($inv)) {
			return 0;
		}
		$inv->fetch_thirdparty();
		$errors = $warnings = array();
		$xmlString = '';
		$xsdOk = 0;
		try {
			$b = new NavOnlineXmlBuilder($this->db);
			$xml = $b->build($inv, null, array('x01' => true));
			$warnings = $b->warnings;
			if (!$xml) {
				$errors = $b->errors;
			} else {
				$dom = dom_import_simplexml($xml)->ownerDocument;
				$dom->formatOutput = true;
				$xmlString = $dom->saveXML();
				$v = new NavOnlineXsdValidator();
				if ($v->validateInvoiceData($xmlString)) {
					$xsdOk = 1;
				} else {
					$errors = array_merge($errors, array_map(function ($e) {
						return 'XSD: '.$e;
					}, (array) $v->errors));
				}
			}
		} catch (Throwable $e) {
			$errors[] = 'XML: '.$e->getMessage();
		}
		list($series, $seq) = self::seriesOf($inv->ref);
		$s = function ($v) {
			return ($v === null || $v === '') ? 'NULL' : "'".$this->db->escape((string) $v)."'";
		};
		$d = function ($ts) {
			return $ts ? "'".$this->db->idate((int) $ts)."'" : 'NULL';
		};
		$sql = 'INSERT INTO '.$this->db->prefix().'navonline_x01_snapshot (entity, fk_facture, ref, series, seq, invoice_type, issue_date, delivery_date, currency, total_ht, total_tva, total_ttc, xml, xml_sha256, xsd_ok, errors, warnings, source, not_at_issue, datec, fk_user) VALUES (';
		$sql .= ((int) ($inv->entity ?: $conf->entity)).', '.((int) $inv->id).', '.$s($inv->ref).', '.$s($series).', '.($seq === null ? 'NULL' : (int) $seq).', '.((int) $inv->type);
		$sql .= ', '.$d($inv->date).', '.$d($inv->date_pointoftax ?: $inv->date).', '.$s(strtoupper((string) ($inv->multicurrency_code ?: $conf->currency)));
		$sql .= ', '.((float) $inv->total_ht).', '.((float) $inv->total_tva).', '.((float) $inv->total_ttc);
		$sql .= ', '.$s($xmlString).', '.$s($xmlString !== '' ? hash('sha256', $xmlString) : '').', '.$xsdOk.', '.$s(implode("\n", $errors)).', '.$s(implode("\n", $warnings));
		$sql .= ', '.$s($source).', '.($source === 'validation' ? 0 : 1).", '".$this->db->idate(dol_now())."', ".((int) (is_object($user) ? $user->id : 0)).')';
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($this->db->prefix().'navonline_x01_snapshot');
		if ($errors) {
			dol_syslog(__METHOD__.' invoice '.$inv->ref.': audit export data incomplete: '.implode('; ', $errors), LOG_WARNING);
		}
		return $id;
	}

	/**
	 * Snapshots for the invoices issued without one (validated before this function existed): made from the data of
	 * today, marked not_at_issue.
	 *
	 * @param User $user  User
	 * @param int  $limit Max invoices per call (0 = all)
	 * @return array{done:int,incomplete:int,errors:int}
	 */
	public function backfill($user, $limit = 0)
	{
		global $conf;

		$p = $this->db->prefix();
		$sql = 'SELECT f.rowid FROM '.$p.'facture f WHERE f.entity IN ('.getEntity('invoice').') AND f.fk_statut > 0 AND f.type <> '.Facture::TYPE_PROFORMA;
		$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$p.'navonline_x01_snapshot s WHERE s.fk_facture = f.rowid)';
		$sql .= ' ORDER BY f.datef, f.rowid'.($limit > 0 ? ' LIMIT '.((int) $limit) : '');
		$res = $this->db->query($sql);
		$out = array('done' => 0, 'incomplete' => 0, 'errors' => 0);
		$ids = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ids[] = (int) $o->rowid;
		}
		foreach ($ids as $id) {
			$sid = $this->snapshot($id, 'backfill', $user);
			if ($sid < 0) {
				$out['errors']++;
				continue;
			}
			$out['done']++;
			$r = $this->db->fetch_object($this->db->query('SELECT xsd_ok FROM '.$p.'navonline_x01_snapshot WHERE rowid = '.$sid));
			if ($r && (int) $r->xsd_ok !== 1) {
				$out['incomplete']++;
			}
		}
		return $out;
	}

	/**
	 * Latest snapshot of an invoice.
	 *
	 * @param int $invoiceId Invoice
	 * @return object|null
	 */
	public function latest($invoiceId)
	{
		$res = $this->db->query('SELECT * FROM '.$this->db->prefix().'navonline_x01_snapshot WHERE fk_facture = '.((int) $invoiceId).' ORDER BY rowid DESC LIMIT 1');
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}
}
