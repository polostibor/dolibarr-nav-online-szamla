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
 * \file    htdocs/custom/navonline/class/navonlinebankstatement.class.php
 * \ingroup navonline
 * \brief   Bank statement import (ISO 20022): parse, store, pair with the Dolibarr bank lines, reconcile.
 *
 * Files (OTP Bank, also any bank with the same standard):
 *  - statement: camt.053 (Document/BkToCstmrStmt/Stmt), monthly, with statement number and opening / closing balance.
 *    OTP writes it in ASCII: accented letters become '?'.
 *  - account history: camt.052-like (BkToCstmrAcctRpt/Rpt, OTP: without namespace and Document), any period, UTF-8.
 * The two files of the same period carry no common bank reference, so an entry is identified by its booking date,
 * signed amount and its rank among the entries of the same day and amount (entry_key): importing both, or a file twice,
 * gives each entry once. A history pairs entries with Dolibarr bank lines; a statement also reconciles the paired bank
 * lines (rappro, num_releve = statement number). Nothing is booked without the user, except the reconciliation of an
 * exact pair: unmatched entries are offered as various payment / transfer on the page (bankimport.php).
 */

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

/**
 * Bank statement import
 */
class NavOnlineBankStatement
{
	const STATUS_OPEN = 0;
	const STATUS_PAIRED = 1;
	const STATUS_RECONCILED = 2;
	const STATUS_IGNORED = 9;

	/** Days around the booking date where a Dolibarr bank line is looked for */
	const MATCH_DAYS = 10;

	/** @var DoliDB */
	public $db;

	/** @var string[] */
	public $errors = array();

	/** @var string[] */
	public $warnings = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Parse a camt.053 statement or a camt.052-like account history.
	 *
	 * @param string $xml File content
	 * @return array<string,mixed>|null type (stmt|hist), account, currency, stmtId, from, to, opening, closing, entries[]
	 */
	public function parse($xml)
	{
		$this->errors = array();
		$prev = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$ok = $dom->loadXML((string) $xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) {
			$this->errors[] = 'A fájl nem érvényes XML';
			return null;
		}
		$body = null;
		foreach (array('Stmt', 'Rpt') as $name) {
			$list = $this->byName($dom->documentElement, $name, true);
			if ($list) {
				$body = $list[0];
				break;
			}
		}
		if (!$body) {
			$this->errors[] = 'Nem bankszámlakivonat (camt.053) vagy számlatörténet (camt.052): nincs Stmt / Rpt elem';
			return null;
		}
		$type = $this->local($body) === 'Stmt' ? 'stmt' : 'hist';
		$acct = $this->text($body, 'Acct/Id/Othr/Id') ?: $this->text($body, 'Acct/Id/IBAN');
		$res = array(
			'type' => $type,
			'account' => $acct,
			'currency' => strtoupper($this->text($body, 'Acct/Ccy')),
			'stmtId' => $type === 'stmt' ? $this->text($body, 'Id') : '',
			'from' => substr($this->text($body, 'FrToDt/FrDtTm'), 0, 10),
			'to' => substr($this->text($body, 'FrToDt/ToDtTm'), 0, 10),
			'opening' => null,
			'closing' => null,
			'entries' => array(),
		);
		foreach ($this->byName($body, 'Bal') as $bal) {
			$code = strtoupper($this->text($bal, 'Tp/CdOrPrtry/Cd'));
			$amt = $this->amount($this->text($bal, 'Amt')) * (strtoupper($this->text($bal, 'CdtDbtInd')) === 'DBIT' ? -1 : 1);
			if (in_array($code, array('OPBD', 'PRCD'), true) && $res['opening'] === null) {
				$res['opening'] = $amt;
			} elseif (in_array($code, array('CLBD'), true)) {
				$res['closing'] = $amt;
			}
		}
		$rank = array();
		foreach ($this->byName($body, 'Ntry') as $n) {
			$sts = strtoupper($this->text($n, 'Sts') ?: $this->text($n, 'Sts/Cd'));
			if ($sts !== '' && $sts !== 'BOOK') {
				continue;	// pending / information entries are not booked
			}
			$credit = strtoupper($this->text($n, 'CdtDbtInd')) === 'CRDT';
			$amount = $this->amount($this->text($n, 'Amt')) * ($credit ? 1 : -1);
			$amtNode = $this->byName($n, 'Amt');
			if ($res['currency'] === '' && $amtNode) {
				$res['currency'] = strtoupper((string) $amtNode[0]->getAttribute('Ccy'));
			}
			$date = substr($this->text($n, 'BookgDt/Dt') ?: $this->text($n, 'BookgDt/DtTm'), 0, 10);
			$tx = $this->byName($n, 'TxDtls', true);
			$tx = $tx ? $tx[0] : $n;
			// counterparty: the creditor of our payments, the debtor of what we receive
			$side = $credit ? 'Dbtr' : 'Cdtr';
			$cpAcct = $this->text($tx, 'RltdPties/'.$side.'Acct/Id/Othr/Id') ?: $this->text($tx, 'RltdPties/'.$side.'Acct/Id/IBAN');
			$ustrd = array();
			foreach ($this->byName($tx, 'Ustrd', true) as $u) {
				$ustrd[] = trim($u->textContent);
			}
			$info = $this->text($tx, 'AddtlTxInf') ?: $this->text($n, 'AddtlNtryInf');
			// OTP history: embedded markup "<extradjel>N</extradjel><ujegy></ujegy> <konyves>FT</konyves> <trnk>640</trnk>
			// <narr>AZONNALI ÁTUTALÁS ...</narr>": the description is the narrative; other markup is dropped, its text kept
			if (preg_match('/<narr>(.*?)<\/narr>/s', $info, $mm)) {
				$info = $mm[1];
			}
			$k = $date.'|'.sprintf('%.2f', $amount);
			$rank[$k] = ($rank[$k] ?? 0) + 1;
			$res['entries'][] = array(
				'key' => $k.'|'.$rank[$k],
				'date' => $date,
				'valueDate' => substr($this->text($n, 'ValDt/Dt') ?: $this->text($n, 'ValDt/DtTm'), 0, 10),
				'amount' => $amount,
				'counterparty' => $this->text($tx, 'RltdPties/'.$side.'/Nm'),
				'counterpartyAccount' => $cpAcct,
				'remittance' => trim(implode(' ', array_filter($ustrd))),
				'txCode' => $this->text($n, 'BkTxCd/Prtry/Cd'),
				'txInfo' => trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]*>/', ' ', $info))),
				'bankRef' => $this->text($n, 'AcctSvcrRef'),
				'endToEnd' => $this->text($tx, 'Refs/EndToEndId'),
			);
		}
		if (!$res['entries'] && $res['opening'] === null) {
			$this->errors[] = 'A fájlban nincs könyvelt tétel';
			return null;
		}
		return $res;
	}

	/**
	 * Statement consistency: opening balance + entries = closing balance.
	 *
	 * @param array<string,mixed> $p Parsed file
	 * @return bool|null Null when the file has no balances (account history)
	 */
	public static function balanceOk($p)
	{
		if ($p['opening'] === null || $p['closing'] === null) {
			return null;
		}
		$sum = 0.0;
		foreach ($p['entries'] as $e) {
			$sum += $e['amount'];
		}
		return abs($p['opening'] + $sum - $p['closing']) < 0.005;
	}

	/**
	 * Dolibarr bank account of the file: by the domestic account number or the IBAN.
	 *
	 * @param string $acct Account id of the file
	 * @return Account|null
	 */
	public function findAccount($acct)
	{
		$norm = function ($s) {
			$d = preg_replace('/\D/', '', (string) $s);
			if (strlen($d) === 26) {	// HU IBAN: 2 check digits + 24-digit account number
				$d = substr($d, 2);
			}
			if (strlen($d) === 24 && substr($d, 16) === '00000000') {
				$d = substr($d, 0, 16);
			}
			return $d;
		};
		$want = $norm(preg_replace('/^[A-Z]{2}/', '', strtoupper((string) $acct)));
		if ($want === '') {
			return null;
		}
		$res = $this->db->query('SELECT rowid, number, iban_prefix FROM '.$this->db->prefix().'bank_account WHERE clos = 0 AND entity IN ('.getEntity('bank_account').')');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$iban = preg_replace('/^[A-Z]{2}/', '', strtoupper(str_replace(' ', '', (string) $o->iban_prefix)));
			if ($norm($o->number) === $want || $norm($iban) === $want) {
				$a = new Account($this->db);
				return $a->fetch((int) $o->rowid) > 0 ? $a : null;
			}
		}
		return null;
	}

	/**
	 * Store the entries of a parsed file (new ones inserted, known ones completed), then pair and reconcile.
	 *
	 * @param array<string,mixed> $p        Parsed file
	 * @param Account             $account  Dolibarr bank account
	 * @param string              $fileName File name (for the record)
	 * @param User                $user     User
	 * @return array<string,int>|int Counters (entries, new, known, paired, reconciled), -1 on error
	 */
	public function import($p, $account, $fileName, $user)
	{
		global $conf;

		$this->errors = $this->warnings = array();
		if ($p['currency'] !== '' && strtoupper((string) $account->currency_code) !== $p['currency']) {
			$this->errors[] = 'A fájl pénzneme ('.$p['currency'].') eltér a bankszámláétól ('.$account->currency_code.')';
			return -1;
		}
		$t = $this->db->prefix().'navonline_bankimport';
		$c = array('entries' => count($p['entries']), 'new' => 0, 'known' => 0, 'paired' => 0, 'reconciled' => 0);
		$s = function ($v, $len = 255) {
			$v = trim((string) $v);
			return $v === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($v, $len, 'right', 'UTF-8', 1))."'";
		};
		$this->db->begin();
		foreach ($p['entries'] as $e) {
			$res = $this->db->query('SELECT rowid, counterparty, remittance, tx_info FROM '.$t.' WHERE entity = '.((int) $conf->entity).' AND fk_account = '.((int) $account->id)." AND entry_key = '".$this->db->escape($e['key'])."'");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				// Known entry (the other file of the period, or the same file again): the statement brings its number;
				// texts are completed only when empty (the statement of OTP loses the accented letters)
				$set = array();
				if ($p['type'] === 'stmt') {
					$set[] = 'stmt_id = '.$s($p['stmtId'], 32);
				}
				foreach (array('counterparty' => 'counterparty', 'remittance' => 'remittance', 'tx_info' => 'txInfo') as $col => $k) {
					if ((string) $o->$col === '' && $e[$k] !== '') {
						$set[] = $col.' = '.$s($e[$k]);
					}
				}
				if ($set && !$this->db->query('UPDATE '.$t.' SET '.implode(', ', $set).' WHERE rowid = '.((int) $o->rowid))) {
					$this->db->rollback();
					$this->errors[] = $this->db->lasterror();
					return -1;
				}
				$c['known']++;
				continue;
			}
			$sql = 'INSERT INTO '.$t.' (entity, fk_account, entry_key, booking_date, value_date, amount, currency, counterparty, counterparty_account, remittance, tx_code, tx_info, bank_ref, end_to_end, stmt_id, source, status, import_file, date_import, fk_user) VALUES (';
			$sql .= ((int) $conf->entity).', '.((int) $account->id).", '".$this->db->escape($e['key'])."', '".$this->db->escape($e['date'])."', ".($e['valueDate'] !== '' ? "'".$this->db->escape($e['valueDate'])."'" : 'NULL').', '.((float) $e['amount']);
			$sql .= ', '.$s($p['currency'] ?: $account->currency_code, 3).', '.$s($e['counterparty']).', '.$s($e['counterpartyAccount'], 64).', '.$s($e['remittance']).', '.$s($e['txCode'], 32).', '.$s($e['txInfo']);
			$sql .= ', '.$s($e['bankRef'], 64).', '.$s($e['endToEnd'], 64).', '.($p['type'] === 'stmt' ? $s($p['stmtId'], 32) : 'NULL').", '".$p['type']."', 0, ".$s($fileName).", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				$this->errors[] = $this->db->lasterror();
				return -1;
			}
			$c['new']++;
		}
		$this->db->commit();
		$m = $this->pairOpen((int) $account->id, $user);
		$c['paired'] = $m['paired'];
		$c['reconciled'] = $m['reconciled'];
		return $c;
	}

	/**
	 * Pair the open entries of an account with Dolibarr bank lines when the match is certain, and reconcile the paired
	 * entries that are on a statement.
	 *
	 * @param int  $accountId Bank account
	 * @param User $user      User
	 * @return array{paired:int,reconciled:int}
	 */
	public function pairOpen($accountId, $user)
	{
		global $conf;

		$out = array('paired' => 0, 'reconciled' => 0);
		$res = $this->db->query('SELECT * FROM '.$this->db->prefix().'navonline_bankimport WHERE entity = '.((int) $conf->entity).' AND fk_account = '.((int) $accountId).' AND status = '.self::STATUS_OPEN.' ORDER BY booking_date, rowid');
		$rows = array();
		while ($res && ($o = $this->db->fetch_object($res))) {
			$rows[] = $o;
		}
		foreach ($rows as $o) {
			$cands = $this->candidates($o);
			$sure = array_values(array_filter($cands, function ($c) {
				return $c['score'] >= 2;
			}));
			$pick = count($cands) === 1 ? $cands[0] : (count($sure) === 1 ? $sure[0] : null);
			if ($pick && $this->pair($o, (int) $pick['id'], $user) > 0) {
				$out['paired']++;
			}
		}
		$out['reconciled'] = $this->reconcilePaired($accountId, $user);
		return $out;
	}

	/**
	 * Dolibarr bank lines that may be this entry: same account and amount, booked within MATCH_DAYS days, not reconciled
	 * and not paired yet. Score: +2 counterparty bank account known for the third party of the line, +2 a reference of a
	 * paid invoice in the remittance information, +1 same day.
	 *
	 * @param object $o Entry row
	 * @return array<int,array{id:int,score:int,label:string,date:int,why:string}>
	 */
	public function candidates($o)
	{
		$p = $this->db->prefix();
		$d = $this->db->jdate($o->booking_date);
		$sql = 'SELECT b.rowid, b.dateo, b.label FROM '.$p.'bank b WHERE b.fk_account = '.((int) $o->fk_account).' AND b.rappro = 0';
		$sql .= ' AND ABS(b.amount - ('.((float) $o->amount).')) < 0.005';
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($d - self::MATCH_DAYS * 86400)."' AND '".$this->db->idate($d + self::MATCH_DAYS * 86400)."'";
		$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$p.'navonline_bankimport i WHERE i.fk_bank = b.rowid)';
		$sql .= ' ORDER BY ABS(DATEDIFF(b.dateo, '."'".$this->db->escape($o->booking_date)."'".')), b.rowid';
		$res = $this->db->query($sql);
		$out = array();
		$cpAcct = preg_replace('/\D/', '', (string) $o->counterparty_account);
		$remit = strtoupper((string) $o->remittance.' '.(string) $o->tx_info);
		while ($res && ($b = $this->db->fetch_object($res))) {
			$score = 0;
			$why = array();
			if (dol_print_date($this->db->jdate($b->dateo), '%Y-%m-%d') === (string) $o->booking_date) {
				$score++;
				$why[] = 'azonos nap';
			}
			foreach ($this->lineLinks((int) $b->rowid) as $l) {
				if ($cpAcct !== '' && $l['type'] === 'company' && $this->companyHasAccount((int) $l['id'], $cpAcct)) {
					$score += 2;
					$why[] = 'partner bankszámlája';
				}
				foreach ($l['refs'] ?? array() as $ref) {
					if ($ref !== '' && strpos($remit, strtoupper($ref)) !== false) {
						$score += 2;
						$why[] = 'számlaszám a közleményben';
						break;
					}
				}
			}
			$out[] = array('id' => (int) $b->rowid, 'score' => $score, 'label' => (string) $b->label, 'date' => $this->db->jdate($b->dateo), 'why' => implode(', ', array_unique($why)));
		}
		return $out;
	}

	/**
	 * Third party and invoice references linked to a bank line (llx_bank_url).
	 *
	 * @param int $lineId Bank line
	 * @return array<int,array{type:string,id:int,refs?:string[]}>
	 */
	private function lineLinks($lineId)
	{
		$p = $this->db->prefix();
		$out = array();
		$res = $this->db->query('SELECT type, url_id FROM '.$p.'bank_url WHERE fk_bank = '.((int) $lineId));
		while ($res && ($u = $this->db->fetch_object($res))) {
			$refs = array();
			if ($u->type === 'payment') {
				$r = $this->db->query('SELECT f.ref FROM '.$p.'paiement_facture pf JOIN '.$p.'facture f ON f.rowid = pf.fk_facture WHERE pf.fk_paiement = '.((int) $u->url_id));
				while ($r && ($x = $this->db->fetch_object($r))) {
					$refs[] = (string) $x->ref;
				}
			} elseif ($u->type === 'payment_supplier') {
				$r = $this->db->query('SELECT f.ref, f.ref_supplier FROM '.$p.'paiementfourn_facturefourn pf JOIN '.$p.'facture_fourn f ON f.rowid = pf.fk_facturefourn WHERE pf.fk_paiementfourn = '.((int) $u->url_id));
				while ($r && ($x = $this->db->fetch_object($r))) {
					$refs[] = (string) $x->ref_supplier;
					$refs[] = (string) $x->ref;
				}
			}
			$out[] = array('type' => (string) $u->type, 'id' => (int) $u->url_id, 'refs' => array_filter($refs, function ($r) {
				return strlen($r) >= 4;
			}));
		}
		return $out;
	}

	/**
	 * @param int    $socid   Third party
	 * @param string $digits  Account number digits from the bank file
	 * @return bool The third party has this bank account (domestic number or IBAN)
	 */
	private function companyHasAccount($socid, $digits)
	{
		$res = $this->db->query('SELECT number, iban_prefix FROM '.$this->db->prefix().'societe_rib WHERE fk_soc = '.((int) $socid)." AND type = 'ban'");
		while ($res && ($o = $this->db->fetch_object($res))) {
			foreach (array((string) $o->number, (string) $o->iban_prefix) as $v) {
				$d = preg_replace('/\D/', '', $v);
				if ($d !== '' && (strpos($d, $digits) !== false || strpos($digits, $d) !== false) && min(strlen($d), strlen($digits)) >= 16) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Pair an entry with a Dolibarr bank line (same account and amount), and reconcile it when it is on a statement.
	 *
	 * @param object $o      Entry row
	 * @param int    $lineId Bank line
	 * @param User   $user   User
	 * @return int 1 OK, <0 error
	 */
	public function pair($o, $lineId, $user)
	{
		$p = $this->db->prefix();
		$b = $this->db->fetch_object($this->db->query('SELECT rowid, fk_account, amount, rappro FROM '.$p.'bank WHERE rowid = '.((int) $lineId)));
		if (!$b || (int) $b->fk_account !== (int) $o->fk_account || abs((float) $b->amount - (float) $o->amount) >= 0.005) {
			$this->errors[] = 'A banki tétel nem ehhez a számlához vagy nem ehhez az összeghez tartozik';
			return -1;
		}
		$busy = $this->db->fetch_object($this->db->query('SELECT rowid FROM '.$p.'navonline_bankimport WHERE fk_bank = '.((int) $lineId).' AND rowid <> '.((int) $o->rowid)));
		if ($busy) {
			$this->errors[] = 'Ez a Dolibarr banki tétel már egy másik kivonattételhez van párosítva';
			return -1;
		}
		if (!$this->db->query('UPDATE '.$p.'navonline_bankimport SET fk_bank = '.((int) $lineId).', status = '.self::STATUS_PAIRED.' WHERE rowid = '.((int) $o->rowid))) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Reconcile the paired entries that are on a statement: llx_bank.rappro = 1, num_releve = statement number.
	 * Only with the bank right "consolidate".
	 *
	 * @param int  $accountId Bank account
	 * @param User $user      User
	 * @return int Number reconciled
	 */
	public function reconcilePaired($accountId, $user)
	{
		global $conf;

		if (!$user->hasRight('banque', 'consolidate')) {
			return 0;
		}
		$p = $this->db->prefix();
		$res = $this->db->query('SELECT rowid, fk_bank, stmt_id FROM '.$p.'navonline_bankimport WHERE entity = '.((int) $conf->entity).' AND fk_account = '.((int) $accountId).' AND status = '.self::STATUS_PAIRED." AND stmt_id IS NOT NULL AND stmt_id <> '' AND fk_bank > 0");
		$n = 0;
		while ($res && ($o = $this->db->fetch_object($res))) {
			$ok = $this->db->query('UPDATE '.$p."bank SET rappro = 1, num_releve = '".$this->db->escape($o->stmt_id)."', fk_user_rappro = ".((int) $user->id).' WHERE rowid = '.((int) $o->fk_bank));
			if ($ok && $this->db->query('UPDATE '.$p.'navonline_bankimport SET status = '.self::STATUS_RECONCILED.' WHERE rowid = '.((int) $o->rowid))) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Remove the pairing of an entry (and the reconciliation done by this import).
	 *
	 * @param object $o Entry row
	 * @return int
	 */
	public function unpair($o)
	{
		$p = $this->db->prefix();
		if ((int) $o->status === self::STATUS_RECONCILED && (int) $o->fk_bank > 0) {
			$this->db->query('UPDATE '.$p."bank SET rappro = 0, num_releve = NULL, fk_user_rappro = NULL WHERE rowid = ".((int) $o->fk_bank)." AND num_releve = '".$this->db->escape((string) $o->stmt_id)."'");
		}
		return $this->db->query('UPDATE '.$p.'navonline_bankimport SET fk_bank = NULL, status = '.self::STATUS_OPEN.' WHERE rowid = '.((int) $o->rowid)) ? 1 : -1;
	}

	/**
	 * Payment mode code suggested for a various payment of an entry: card purchase, bank charge, else transfer.
	 *
	 * @param object $o Entry row
	 * @return string
	 */
	public static function suggestedMode($o)
	{
		$code = strtoupper((string) $o->tx_code);
		$info = strtoupper(dol_string_unaccent((string) $o->tx_info));
		if (strpos($code, '764') === 0 || strpos($info, 'VASARL') !== false || strpos($info, 'V?S?RL') !== false) {
			return 'CB';
		}
		if (strpos($code, 'CAAC') === 0 || preg_match('/JUTALEK|DIJ|KAMAT|SZAMLAVEZET|SZ\?MLAVEZET/', $info)) {
			return 'PRE';
		}
		return 'VIR';
	}

	/**
	 * @param object $o Entry row
	 * @return string Label for a Dolibarr bank line created from the entry
	 */
	public static function label($o)
	{
		$parts = array_filter(array(trim((string) $o->tx_info), trim((string) $o->counterparty), trim((string) $o->remittance)));
		return dol_trunc(implode(' - ', array_unique($parts)) ?: 'Banki tétel', 250, 'right', 'UTF-8', 1);
	}

	/**
	 * Record an unmatched entry as a various payment (bank charge, card purchase...) and pair it.
	 *
	 * @param object $o     Entry row
	 * @param string $mode  Payment mode code
	 * @param string $label Label
	 * @param User   $user  User
	 * @return int Bank line id, <0 on error
	 */
	public function createVarious($o, $mode, $label, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
		$p = $this->db->prefix();
		$typeId = (int) dol_getIdFromCode($this->db, $mode, 'c_paiement', 'code', 'id', 1);
		$pv = new PaymentVarious($this->db);
		$pv->datep = $this->db->jdate($o->booking_date);
		$pv->datev = $o->value_date ? $this->db->jdate($o->value_date) : $pv->datep;
		$pv->sens = (float) $o->amount < 0 ? 0 : 1;
		$pv->amount = abs((float) $o->amount);
		$pv->type_payment = $typeId;
		$pv->label = dol_trunc(trim($label) !== '' ? trim($label) : self::label($o), 250, 'right', 'UTF-8', 1);
		$pv->note = 'Bankkivonat-import: '.$o->booking_date.($o->stmt_id ? ', kivonat '.$o->stmt_id : '');
		$pv->accountid = $pv->fk_account = (int) $o->fk_account;
		$pv->accountancy_code = '';
		$pv->subledger_account = '';
		$this->db->begin();
		$id = $pv->create($user);
		if ($id <= 0) {
			$this->db->rollback();
			$this->errors[] = 'Egyéb kifizetés: '.$pv->error.' '.implode(', ', (array) $pv->errors);
			return -1;
		}
		$b = $this->db->fetch_object($this->db->query('SELECT fk_bank FROM '.$p.'payment_various WHERE rowid = '.((int) $id)));
		if (!$b || (int) $b->fk_bank <= 0 || $this->pair($o, (int) $b->fk_bank, $user) < 0) {
			$this->db->rollback();
			$this->errors[] = 'Egyéb kifizetés: a banki tétel nem jött létre';
			return -1;
		}
		$this->db->commit();
		$this->reconcilePaired((int) $o->fk_account, $user);
		return (int) $b->fk_bank;
	}

	/**
	 * Record an unmatched entry as a transfer between two own accounts (as compta/bank/transfer.php does) and pair it.
	 *
	 * @param object $o           Entry row (on the account of the statement)
	 * @param int    $otherId     The other own account
	 * @param float  $otherAmount Amount on the other account, in its currency (absolute)
	 * @param User   $user        User
	 * @return int Bank line id on the statement account, <0 on error
	 */
	public function createTransfer($o, $otherId, $otherAmount, $user)
	{
		$this->db->begin();
		$acc = new Account($this->db);
		$other = new Account($this->db);
		if ($acc->fetch((int) $o->fk_account) <= 0 || $other->fetch((int) $otherId) <= 0 || (int) $other->id === (int) $acc->id) {
			$this->db->rollback();
			$this->errors[] = 'Átvezetés: érvénytelen bankszámla';
			return -1;
		}
		$amount = (float) $o->amount;	// + received on this account from the other one, - sent to it
		$otherAmount = abs((float) $otherAmount);
		if ($otherAmount <= 0) {
			$this->db->rollback();
			$this->errors[] = 'Átvezetés: add meg az összeget a másik számla pénznemében';
			return -1;
		}
		$type = ((int) $acc->type === Account::TYPE_CASH || (int) $other->type === Account::TYPE_CASH) ? 'LIQ' : 'VIR';
		$label = dol_trunc('Átvezetés '.($amount > 0 ? $other->ref.' → '.$acc->ref : $acc->ref.' → '.$other->ref), 250, 'right', 'UTF-8', 1);
		$date = $this->db->jdate($o->booking_date);
		// A line of a foreign currency account also keeps its amount in the main currency (as compta/bank/transfer.php):
		// the HUF side of the transfer
		global $conf;
		$main = strtoupper((string) $conf->currency);
		$otherSigned = ($amount > 0 ? -1 : 1) * $otherAmount;
		$thisMain = strtoupper((string) $acc->currency_code) !== $main && strtoupper((string) $other->currency_code) === $main ? -$otherSigned : null;
		$otherMain = strtoupper((string) $other->currency_code) !== $main && strtoupper((string) $acc->currency_code) === $main ? -$amount : null;
		$thisLine = $acc->addline($date, $type, $label, $amount, '', 0, $user, '', '', '', null, '', $thisMain);
		$otherLine = $other->addline($date, $type, $label, $otherSigned, '', 0, $user, '', '', '', null, '', $otherMain);
		if ($thisLine <= 0 || $otherLine <= 0
			|| $acc->add_url_line($thisLine, $otherLine, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert') <= 0
			|| $other->add_url_line($otherLine, $thisLine, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert') <= 0
			|| $this->pair($o, (int) $thisLine, $user) < 0) {
			$this->db->rollback();
			$this->errors[] = 'Átvezetés: '.($acc->error ?: $other->error ?: 'a banki tételek nem jöttek létre');
			return -1;
		}
		$this->db->commit();
		$this->reconcilePaired((int) $o->fk_account, $user);
		return (int) $thisLine;
	}

	/**
	 * @param int $id Entry
	 * @return object|null
	 */
	public function fetchRow($id)
	{
		global $conf;
		$res = $this->db->query('SELECT * FROM '.$this->db->prefix().'navonline_bankimport WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity));
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $id     Entry
	 * @param int $status STATUS_OPEN or STATUS_IGNORED
	 * @return bool
	 */
	public function setStatus($id, $status)
	{
		global $conf;
		return (bool) $this->db->query('UPDATE '.$this->db->prefix().'navonline_bankimport SET status = '.((int) $status).' WHERE rowid = '.((int) $id).' AND entity = '.((int) $conf->entity).' AND fk_bank IS NULL');
	}

	/**
	 * @param DOMElement $e Element
	 * @return string Local name
	 */
	private function local($e)
	{
		return (string) ($e->localName ?: $e->nodeName);
	}

	/**
	 * Children (or descendants) with a local name, case-insensitive (OTP writes <cd> in the history).
	 *
	 * @param DOMElement $e    Element
	 * @param string     $name Local name
	 * @param bool       $deep Descendants
	 * @return DOMElement[]
	 */
	private function byName($e, $name, $deep = false)
	{
		$out = array();
		foreach ($deep ? $e->getElementsByTagName('*') : $e->childNodes as $c) {
			if ($c instanceof DOMElement && strcasecmp($this->local($c), $name) === 0) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * @param DOMElement $e    Element
	 * @param string     $path Child path (a/b/c)
	 * @return string Trimmed text, '' when missing
	 */
	private function text($e, $path)
	{
		foreach (explode('/', $path) as $p) {
			$k = $this->byName($e, $p);
			if (!$k) {
				return '';
			}
			$e = $k[0];
		}
		return trim($e->textContent);
	}

	/**
	 * @param string $v Amount text (12345, 12345.0, 12345,50)
	 * @return float
	 */
	private function amount($v)
	{
		return (float) str_replace(array(' ', ','), array('', '.'), (string) $v);
	}
}
