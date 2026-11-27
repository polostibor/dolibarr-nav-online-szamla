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
 * \file    htdocs/custom/navonline/class/navonlinemnb.class.php
 * \ingroup navonline
 * \brief   Daily download of the official MNB (Magyar Nemzeti Bank) exchange rates into the Dolibarr multicurrency rates.
 *
 * For every currency of Setup > Multicurrency except the main currency (HUF), the MNB rate of each MNB publication
 * day of the last days is stored with date_sync = that day 12:00 (not 00:00: the core date filter compares with the end of the\n * document day in UTC, i.e. 00:59 of the next day in Budapest, which would pick up the next day's rate), so that with MULTICURRENCY_USE_RATE_ON_DOCUMENT_DATE
 * a document gets the MNB rate valid on its date. Missing days (computer off, weekend) are filled in on the next run;
 * an existing day is updated, never duplicated.
 * Dolibarr rate semantics: rate = currency units per 1 HUF, rate_direct = HUF per 1 currency unit.
 */

/**
 * MNB exchange rates
 */
class NavOnlineMnb
{
	/** @var string MNB web service (official address from its WSDL) */
	const MNB_URL = 'http://www.mnb.hu/arfolyamok.asmx';

	/** @var DoliDB */
	private $db;

	/** @var string Output of the last run (shown in Setup > Scheduled jobs) */
	public $output = '';

	/** @var string */
	public $error = '';

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
	 * Scheduled job entry point.
	 *
	 * @param int $days Number of past days to (re)check, default 14
	 * @return int 0 if OK, <>0 if KO (Dolibarr cron convention)
	 */
	public function syncRates($days = 14)
	{
		global $conf;

		$this->output = '';
		$this->errors = array();
		$main = strtoupper((string) $conf->currency);

		$currencies = array();
		$resql = $this->db->query('SELECT rowid, code FROM '.$this->db->prefix().'multicurrency WHERE entity IN ('.getEntity('multicurrency').')');
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			if (strtoupper($o->code) !== $main) {
				$currencies[strtoupper($o->code)] = (int) $o->rowid;
			}
		}
		if (!$currencies) {
			$this->output = 'Nincs forinttól eltérő pénznem beállítva';
			return 0;
		}
		if ($main !== 'HUF') {
			$this->error = 'Az MNB-árfolyamok forintban vannak, a fő pénznem viszont: '.$main;
			return 1;
		}

		$days = max(1, min(60, (int) $days));
		$rates = $this->fetchMnb(date('Y-m-d', strtotime('-'.$days.' days')), date('Y-m-d'), array_keys($currencies));
		if ($rates === null) {
			$this->error = implode('; ', $this->errors);
			return 1;
		}

		$added = $updated = 0;
		$latest = array();
		foreach ($rates as $day => $list) {
			foreach ($list as $code => $hufPerUnit) {
				if (!isset($currencies[$code]) || $hufPerUnit <= 0) {
					continue;
				}
				$fk = $currencies[$code];
				$dateSync = $this->db->idate(dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)));
				$rate = 1 / $hufPerUnit;
				$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'multicurrency_rate WHERE fk_multicurrency = '.$fk." AND date_sync = '".$dateSync."' AND entity = ".((int) $conf->entity));
				$obj = $res ? $this->db->fetch_object($res) : null;
				if ($obj) {
					$ok = $this->db->query('UPDATE '.$this->db->prefix().'multicurrency_rate SET rate = '.((float) $rate).', rate_direct = '.((float) $hufPerUnit).' WHERE rowid = '.((int) $obj->rowid));
					$updated += $ok ? 1 : 0;
				} else {
					$ok = $this->db->query('INSERT INTO '.$this->db->prefix().'multicurrency_rate (date_sync, rate, rate_direct, fk_multicurrency, entity) VALUES ('."'".$dateSync."', ".((float) $rate).', '.((float) $hufPerUnit).', '.$fk.', '.((int) $conf->entity).')');
					$added += $ok ? 1 : 0;
				}
				if (!$ok) {
					$this->errors[] = $code.' '.$day.': '.$this->db->lasterror();
				}
				$latest[$code] = $day.' '.$hufPerUnit;
			}
		}

		$missing = array_diff(array_keys($currencies), array_keys($latest));
		$this->output = 'MNB: '.$added.' új, '.$updated.' frissített árfolyam. Legutóbbi: ';
		foreach ($latest as $code => $v) {
			$this->output .= '1 '.$code.' = '.str_replace('.', ',', (string) explode(' ', $v)[1]).' Ft ('.explode(' ', $v)[0].'); ';
		}
		if ($missing) {
			$this->output .= ' Az MNB nem közöl árfolyamot: '.implode(', ', $missing);
		}
		if ($this->errors) {
			$this->error = implode('; ', $this->errors);
			return 1;
		}
		return 0;
	}

	/**
	 * Call MNB GetExchangeRates.
	 *
	 * @param string   $from       Y-m-d
	 * @param string   $to         Y-m-d
	 * @param string[] $currencies ISO codes
	 * @return array<string,array<string,float>>|null [day][code] => HUF per 1 unit
	 */
	public function fetchMnb($from, $to, array $currencies)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$body = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
			.'<GetExchangeRates xmlns="http://www.mnb.hu/webservices/"><startDate>'.$from.'</startDate><endDate>'.$to.'</endDate>'
			.'<currencyNames>'.implode(',', array_map(function ($c) {
				return preg_replace('/[^A-Z]/', '', $c);
			}, $currencies)).'</currencyNames></GetExchangeRates></soap:Body></soap:Envelope>';
		$headers = array('Content-Type: text/xml; charset=utf-8', 'SOAPAction: "http://www.mnb.hu/webservices/MNBArfolyamServiceSoap/GetExchangeRates"');
		$res = getURLContent(self::MNB_URL, 'POSTALREADYFORMATED', $body, 1, $headers, array('http', 'https'), 0, -1, 10, 30);
		if (empty($res['content']) || (int) $res['http_code'] !== 200) {
			$this->errors[] = 'Az MNB-letöltés nem sikerült (HTTP '.(int) $res['http_code'].(!empty($res['curl_error_msg']) ? ', '.$res['curl_error_msg'] : '').')';
			return null;
		}

		// The result is an XML document escaped inside the SOAP response
		if (!preg_match('#<GetExchangeRatesResult>(.*)</GetExchangeRatesResult>#s', (string) $res['content'], $m)) {
			$this->errors[] = 'MNB: váratlan válasz';
			return null;
		}
		$inner = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
		$xml = @simplexml_load_string($inner);
		if (!$xml) {
			$this->errors[] = 'MNB: a válasz nem érvényes XML';
			return null;
		}

		$out = array();
		foreach ($xml->Day as $d) {
			$day = (string) $d['date'];
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
				continue;
			}
			foreach ($d->Rate as $r) {
				$unit = max(1, (int) $r['unit']);
				$value = (float) str_replace(array(' ', ','), array('', '.'), (string) $r);
				if ($value > 0) {
					$out[$day][strtoupper((string) $r['curr'])] = $value / $unit;
				}
			}
		}
		ksort($out);
		return $out;
	}
}
