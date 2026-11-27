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
	 * Invoices of an export selection with their latest snapshot, sorted by series and number, and the gaps.
	 *
	 * Mode 'date': issued between $from and $to (Y-m-d, both included). Mode 'number': series $series, invoice numbers
	 * from $from to $to (both included), compared by their rank in the series (not as text).
	 *
	 * @param string $mode   date | number
	 * @param string $from   Start (date or invoice number)
	 * @param string $to     End (date or invoice number)
	 * @param string $series Series (number mode)
	 * @return array{rows:array<int,array<string,mixed>>,gaps:array<string,int[]>,errors:string[]}|null Null when the selection is invalid ($this->errors)
	 */
	public function select($mode, $from, $to, $series = '')
	{
		$this->errors = array();
		$p = $this->db->prefix();
		$sql = 'SELECT f.rowid, f.ref, f.type, f.datef, f.fk_statut, s.nom AS customer FROM '.$p.'facture f LEFT JOIN '.$p.'societe s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE f.entity IN ('.getEntity('invoice').') AND f.fk_statut > 0 AND f.type <> '.Facture::TYPE_PROFORMA;
		$range = null;
		if ($mode === 'date') {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
				$this->errors[] = 'Adj meg érvényes kezdő és záró dátumot (a kezdő nem lehet későbbi a zárónál)';
				return null;
			}
			$sql .= " AND f.datef >= '".$this->db->escape($from)."' AND f.datef <= '".$this->db->escape($to)."'";
		} elseif ($mode === 'number') {
			$series = strtoupper(trim($series));
			list($fs, $fn) = self::seriesOf($from);
			list($ts, $tn) = self::seriesOf($to);
			if ($series === '' || $fn === null || $tn === null || $fs !== $series || $ts !== $series || $fn > $tn) {
				$this->errors[] = 'Adj meg egy sorozatot és ugyanabból a sorozatból egy kezdő és egy záró számlaszámot (a kezdő nem lehet nagyobb a zárónál)';
				return null;
			}
			$sql .= " AND f.ref LIKE '".$this->db->escape($series)."%'";
			$range = array($fn, $tn);
		} else {
			$this->errors[] = 'Ismeretlen kiválasztási mód';
			return null;
		}
		$res = $this->db->query($sql);
		if (!$res) {
			$this->errors[] = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($res)) {
			list($sr, $sq) = self::seriesOf($o->ref);
			if ($mode === 'number' && ($sr !== $series || $sq === null || $sq < $range[0] || $sq > $range[1])) {
				continue;
			}
			$snap = $this->latest((int) $o->rowid);
			$state = !$snap ? 'missing' : ((int) $snap->xsd_ok !== 1 ? 'incomplete' : ((int) $snap->not_at_issue ? 'notatissue' : 'ok'));
			$rows[] = array('id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'series' => $sr, 'seq' => $sq, 'type' => (int) $o->type, 'date' => (string) $o->datef,
				'customer' => (string) $o->customer, 'snapshot' => $snap, 'state' => $state);
		}
		usort($rows, function ($a, $b) {
			return array($a['series'], $a['seq'], $a['ref']) <=> array($b['series'], $b['seq'], $b['ref']);
		});
		// Gaps: numbers of the series missing in the selection range (number mode: the given range; date mode: between
		// the lowest and highest number found per series), i.e. not issued, deleted, or a draft
		$gaps = array();
		$bySeries = array();
		foreach ($rows as $r) {
			if ($r['seq'] !== null) {
				$bySeries[$r['series']][$r['seq']] = true;
			}
		}
		if ($mode === 'number' && !isset($bySeries[$series])) {
			$bySeries[$series] = array();
		}
		foreach ($bySeries as $sr => $seqs) {
			$lo = $mode === 'number' ? $range[0] : min(array_keys($seqs));
			$hi = $mode === 'number' ? $range[1] : max(array_keys($seqs));
			for ($n = $lo; $n <= $hi && count($gaps[$sr] ?? array()) < 1000; $n++) {
				if (!isset($seqs[$n])) {
					$gaps[$sr][] = $n;
				}
			}
		}
		return array('rows' => $rows, 'gaps' => $gaps, 'errors' => array());
	}

	/**
	 * Make the export package (ZIP) of a selection: one invoiceData XML per invoice (the stored snapshot, validated
	 * again against the XSD), jegyzek.xml and jegyzek.csv (selection, time, counts, totals, files with SHA-256),
	 * hibalista.txt with the errors (missing / incomplete data, gaps) and warnings (data not at issue). A package with
	 * an error or a gap is marked HIANYOS (file name and manifest). Logged in llx_navonline_x01_export. Nothing is sent
	 * to NAV.
	 *
	 * @param array<string,mixed> $sel      Result of select()
	 * @param string              $mode     date | number
	 * @param string              $criteria Human readable selection
	 * @param User                $user     User
	 * @return array{path:string,name:string,complete:bool,nb:int,nbOk:int,logId:int}|null
	 */
	public function export($sel, $mode, $criteria, $user)
	{
		global $conf, $mysoc;

		$this->errors = array();
		if (!class_exists('ZipArchive')) {
			$this->errors[] = 'A PHP ZIP-kiterjesztése (ZipArchive) nincs telepítve';
			return null;
		}
		$dir = DOL_DATA_ROOT.'/navonline/x01';
		dol_mkdir($dir);
		$now = dol_now();
		$errors = $warnings = array();
		$files = array();
		$validator = new NavOnlineXsdValidator();
		$tot = array('ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0);
		$used = array();
		foreach ($sel['rows'] as $r) {
			$s = $r['snapshot'];
			if (!$s) {
				$errors[] = $r['ref'].': nincs kiállításkori adat (pillanatkép) a számláról';
				continue;
			}
			if ((int) $s->xsd_ok !== 1 || (string) $s->xml === '') {
				$errors[] = $r['ref'].': a számla adatai hiányosak, XML nem készíthető: '.str_replace("\n", '; ', (string) $s->errors);
				continue;
			}
			if (!$validator->validateInvoiceData((string) $s->xml)) {
				$errors[] = $r['ref'].': XSD-ellenőrzés hibás: '.implode('; ', (array) $validator->errors);
				continue;
			}
			if (hash('sha256', (string) $s->xml) !== (string) $s->xml_sha256) {
				$errors[] = $r['ref'].': a tárolt XML ellenőrzőösszege nem egyezik (sérült adat)';
				continue;
			}
			if ((int) $s->not_at_issue) {
				$warnings[] = $r['ref'].': nem kiállításkori adat: a pillanatkép utólag, '.dol_print_date($this->db->jdate($s->datec), 'dayhour').' adataiból készült';
			}
			$base = dol_sanitizeFileName($r['ref']);
			$name = $base.'.xml';
			for ($i = 2; isset($used[strtolower($name)]); $i++) {
				$name = $base.'_'.$i.'.xml';
			}
			$used[strtolower($name)] = true;
			$files[] = array('row' => $r, 'name' => $name, 'xml' => (string) $s->xml, 'sha' => (string) $s->xml_sha256);
			$tot['ht'] += (float) $s->total_ht;
			$tot['tva'] += (float) $s->total_tva;
			$tot['ttc'] += (float) $s->total_ttc;
		}
		foreach ($sel['gaps'] as $sr => $nums) {
			$errors[] = 'Hézag a(z) '.$sr.' sorozatban: '.count($nums).' hiányzó sorszám ('.implode(', ', array_slice($nums, 0, 50)).(count($nums) > 50 ? ', …' : '').'): nem kiállított, törölt vagy piszkozat számla';
		}
		$complete = !$errors;
		$stamp = dol_print_date($now, '%Y%m%d%H%M%S');
		$zipName = 'adohatosagi_ellenorzesi_adatszolgaltatas_'.$stamp.($complete ? '' : '_HIANYOS').'.zip';
		$zipPath = $dir.'/'.$zipName;

		// Manifest (jegyzék)
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$root = $doc->appendChild($doc->createElement('ellenorzesiAdatszolgaltatas'));
		$add = function ($parent, $name, $value = null, array $attr = array()) use ($doc) {
			$e = $parent->appendChild($doc->createElement($name));
			if ($value !== null) {
				$e->appendChild($doc->createTextNode((string) $value));
			}
			foreach ($attr as $k => $v) {
				$e->setAttribute($k, (string) $v);
			}
			return $e;
		};
		$add($root, 'megnevezes', 'Adóhatósági ellenőrzési adatszolgáltatás (23/2014. (VI. 30.) NGM rendelet 11/A. §)');
		$add($root, 'formatum', 'NAV Online Számla invoiceData 3.0, számlánként külön XML-fájl');
		$add($root, 'kibocsato', (string) $mysoc->name, array('adoszam' => (string) $mysoc->idprof1));
		$add($root, 'keszult', dol_print_date($now, '%Y-%m-%dT%H:%M:%S', 'tzserver'), array('felhasznalo' => (string) $user->login));
		$add($root, 'kivalasztas', $criteria, array('mod' => $mode === 'date' ? 'kibocsatasi_datum' : 'sorszam'));
		$add($root, 'teljes', $complete ? 'igen' : 'nem');
		$sum = $add($root, 'osszesites', null, array('darab' => count($files), 'kivalasztott' => count($sel['rows'])));
		$add($sum, 'netto', price2num($tot['ht'], 'MT'));
		$add($sum, 'afa', price2num($tot['tva'], 'MT'));
		$add($sum, 'brutto', price2num($tot['ttc'], 'MT'));
		$list = $add($root, 'bizonylatok');
		$types = array(0 => 'szamla', 2 => 'jovairas', 3 => 'eloleg', 1 => 'helyettesito', 5 => 'reszszamla');
		$csv = "\xEF\xBB\xBF".implode(';', array('Sorszám', 'Sorozat', 'Kelte', 'Típus', 'Vevő', 'Nettó (Ft)', 'ÁFA (Ft)', 'Bruttó (Ft)', 'Pénznem', 'Fájl', 'SHA-256', 'Megjegyzés'))."\r\n";
		$q = function ($v) {
			return '"'.str_replace('"', '""', (string) $v).'"';
		};
		foreach ($files as $f) {
			$r = $f['row'];
			$s = $r['snapshot'];
			$note = (int) $s->not_at_issue ? 'nem kiállításkori' : '';
			$add($list, 'bizonylat', null, array('sorszam' => $r['ref'], 'sorozat' => $r['series'], 'kelte' => substr($r['date'], 0, 10), 'tipus' => $types[$r['type']] ?? (string) $r['type'],
				'netto' => price2num($s->total_ht, 'MT'), 'afa' => price2num($s->total_tva, 'MT'), 'brutto' => price2num($s->total_ttc, 'MT'), 'penznem' => (string) $s->currency,
				'fajl' => $f['name'], 'sha256' => $f['sha']) + ($note ? array('megjegyzes' => $note) : array()));
			$csv .= implode(';', array($q($r['ref']), $q($r['series']), $q(substr($r['date'], 0, 10)), $q($types[$r['type']] ?? $r['type']), $q($r['customer']),
				$q(price2num($s->total_ht, 'MT')), $q(price2num($s->total_tva, 'MT')), $q(price2num($s->total_ttc, 'MT')), $q($s->currency), $q($f['name']), $q($f['sha']), $q($note)))."\r\n";
		}
		if ($errors || $warnings) {
			$e = $add($root, 'hibak');
			foreach ($errors as $m) {
				$add($e, 'hiba', $m);
			}
			foreach ($warnings as $m) {
				$add($e, 'figyelmeztetes', $m);
			}
		}

		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			$this->errors[] = 'A ZIP-fájl nem hozható létre: '.$zipPath;
			return null;
		}
		foreach ($files as $f) {
			$zip->addFromString($f['name'], $f['xml']);
		}
		$zip->addFromString('jegyzek.xml', $doc->saveXML());
		$zip->addFromString('jegyzek.csv', $csv);
		if ($errors || $warnings) {
			$txt = "Adóhatósági ellenőrzési adatszolgáltatás – ".($complete ? 'TELJES, figyelmeztetésekkel' : 'HIÁNYOS CSOMAG')."\r\n".'Kiválasztás: '.$criteria."\r\n\r\n";
			if ($errors) {
				$txt .= "Hibák (ezek miatt a csomag nem teljes):\r\n- ".implode("\r\n- ", $errors)."\r\n\r\n";
			}
			if ($warnings) {
				$txt .= "Figyelmeztetések:\r\n- ".implode("\r\n- ", $warnings)."\r\n";
			}
			$zip->addFromString('hibalista.txt', "\xEF\xBB\xBF".$txt);
		}
		if (!$zip->close()) {
			$this->errors[] = 'A ZIP-fájl írása nem sikerült';
			return null;
		}
		$sha = hash_file('sha256', $zipPath);
		$s = function ($v) {
			return "'".$this->db->escape(dol_trunc((string) $v, 250, 'right', 'UTF-8', 1))."'";
		};
		$this->db->query('INSERT INTO '.$this->db->prefix().'navonline_x01_export (entity, datec, fk_user, mode, criteria, nb_docs, nb_ok, complete, zip_name, zip_sha256) VALUES ('
			.((int) $conf->entity).", '".$this->db->idate($now)."', ".((int) $user->id).', '.$s($mode).', '.$s($criteria).', '.count($sel['rows']).', '.count($files).', '.($complete ? 1 : 0).', '.$s($zipName).', '.$s($sha).')');
		$logId = (int) $this->db->last_insert_id($this->db->prefix().'navonline_x01_export');
		dol_syslog(__METHOD__.' audit export '.$zipName.' ('.count($files).'/'.count($sel['rows']).', '.($complete ? 'complete' : 'INCOMPLETE').') by '.$user->login, LOG_NOTICE);
		return array('path' => $zipPath, 'name' => $zipName, 'complete' => $complete, 'nb' => count($sel['rows']), 'nbOk' => count($files), 'logId' => $logId, 'errors' => $errors, 'warnings' => $warnings);
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
