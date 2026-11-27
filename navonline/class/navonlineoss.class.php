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
 * \file    htdocs/custom/navonline/class/navonlineoss.class.php
 * \ingroup navonline
 * \brief   Quarterly OSS (One-Stop-Shop, EU scheme) summary of the OSS invoice lines.
 *
 * Rules applied:
 *  - OSS line: positive VAT rate that is not a Hungarian rate (27, 18, 5) or a VAT code with a country suffix
 *    (PTU-PL, USt-AT, ...); member state of consumption = code suffix, else the buyer country.
 *  - Period of a supply: teljesítés dátuma (date_pointoftax), else the invoice date.
 *  - Amounts in EUR. EUR invoices: invoice-currency amounts as they are. Other currencies (HUF or foreign):
 *    converted with the ECB reference rate of the last day of the tax period or, if the ECB published
 *    none that day, of the next publication day (VAT Directive art. 366(2)).
 *  - Credit notes (type 2) belong to the return of the quarter in which they are issued. If their source invoice
 *    belongs to the same quarter they reduce that quarter; if it belongs to an earlier quarter they are a
 *    correction of that earlier period (Implementing Regulation 282/2011 art. 61 / Directive art. 369k): reported
 *    in the corrections part, per original period and member state, converted at the rate of the original period.
 *  - Draft invoices and invoices closed as "replaced" (superseded by a replacement invoice) are excluded.
 */

require_once __DIR__.'/../lib/navonline.lib.php';

/**
 * OSS summary
 */
class NavOnlineOss
{
	/** @var string[] Hungarian VAT rates (not OSS) */
	const HU_RATES = array('27', '18', '5');

	/** @var string ECB data API (exchange rates, daily, against EUR) */
	const ECB_URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A?startPeriod=%s&endPeriod=%s&format=csvdata';

	/** @var DoliDB */
	private $db;

	/** @var string[] */
	public $errors = array();

	/** @var string[] */
	public $warnings = array();

	/** @var array<string,array<string,array{rate:float,date:string}>> In-memory rate cache [currency][period end] */
	private $rateCache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * First and last day of a quarter.
	 *
	 * @param int $year    Year
	 * @param int $quarter 1-4
	 * @return array{0:string,1:string} Y-m-d
	 */
	public static function quarterBounds($year, $quarter)
	{
		$m1 = ($quarter - 1) * 3 + 1;
		$start = sprintf('%04d-%02d-01', $year, $m1);
		$end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m1 + 2)));
		return array($start, $end);
	}

	/**
	 * Quarter key (YYYY-Qn) of a timestamp.
	 *
	 * @param int $ts Timestamp
	 * @return string
	 */
	public static function quarterKey($ts)
	{
		return dol_print_date($ts, '%Y', 'tzserver').'-Q'.(int) ceil(((int) dol_print_date($ts, '%m', 'tzserver')) / 3);
	}

	/**
	 * ECB reference rate (units of $currency per 1 EUR) for the last day of a period, or the next publication day.
	 * Cached in DOL_DATA_ROOT/navonline/ecb_rates.json.
	 *
	 * @param string $currency  ISO code
	 * @param string $periodEnd Y-m-d, last day of the tax period
	 * @return array{rate:float,date:string}|null
	 */
	public function ecbRate($currency, $periodEnd)
	{
		$currency = strtoupper($currency);
		if ($currency === 'EUR') {
			return array('rate' => 1.0, 'date' => $periodEnd);
		}
		if (isset($this->rateCache[$currency][$periodEnd])) {
			return $this->rateCache[$currency][$periodEnd];
		}

		$dir = DOL_DATA_ROOT.'/navonline';
		$file = $dir.'/ecb_rates.json';
		$cache = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : array();
		if (isset($cache[$currency][$periodEnd]['rate'])) {
			return $this->rateCache[$currency][$periodEnd] = array('rate' => (float) $cache[$currency][$periodEnd]['rate'], 'date' => (string) $cache[$currency][$periodEnd]['date']);
		}

		if (!preg_match('/^[A-Z]{3}$/', $currency) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
			return null;
		}
		$to = date('Y-m-d', strtotime($periodEnd.' +10 days'));
		if ($periodEnd > date('Y-m-d')) {
			$this->errors[] = 'EKB-árfolyam ('.$currency.'): a bevallási időszak '.$periodEnd.'-án zárul, az árfolyam még nem jelent meg';
			return null;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$res = getURLContent(sprintf(self::ECB_URL, $currency, $periodEnd, $to), 'GET', '', 1, array(), array('https'), 0, -1, 10, 30);	// external URL only
		if (empty($res['content']) || (int) $res['http_code'] !== 200) {
			$this->errors[] = 'EKB-árfolyam ('.$currency.', '.$periodEnd.'): a letöltés nem sikerült (HTTP '.(int) $res['http_code'].')';
			return null;
		}

		$lines = preg_split('/\r?\n/', trim((string) $res['content']));
		$head = str_getcsv((string) array_shift($lines), ',', '"', '');
		$iDate = array_search('TIME_PERIOD', $head, true);
		$iVal = array_search('OBS_VALUE', $head, true);
		$found = null;
		foreach ($lines as $l) {
			$c = str_getcsv($l, ',', '"', '');
			if ($iDate === false || $iVal === false || !isset($c[$iDate], $c[$iVal]) || $c[$iVal] === '') {
				continue;
			}
			if ($c[$iDate] >= $periodEnd && ($found === null || $c[$iDate] < $found['date'])) {
				$found = array('rate' => (float) $c[$iVal], 'date' => $c[$iDate]);
			}
		}
		if (!$found || $found['rate'] <= 0) {
			$this->errors[] = 'EKB-árfolyam ('.$currency.'): '.$periodEnd.' és '.$to.' között nem jelent meg árfolyam';
			return null;
		}

		$cache[$currency][$periodEnd] = $found;
		dol_mkdir($dir);
		file_put_contents($file, json_encode($cache, JSON_PRETTY_PRINT));

		return $this->rateCache[$currency][$periodEnd] = $found;
	}

	/**
	 * SQL condition of an OSS line (alias d = facturedet). Same rule as the NAV module guard.
	 *
	 * @return string
	 */
	public static function ossLineSql()
	{
		return "(d.tva_tx > 0 AND (ROUND(d.tva_tx, 2) NOT IN (".implode(', ', self::HU_RATES).") OR UPPER(COALESCE(d.vat_src_code, '')) REGEXP '-[A-Z]{2}$'))";
	}

	/**
	 * Build the OSS summary of a quarter.
	 *
	 * @param int $year    Year
	 * @param int $quarter 1-4
	 * @return array<string,mixed>|null
	 */
	public function build($year, $quarter)
	{
		global $conf;

		$this->errors = array();
		$this->warnings = array();
		$year = (int) $year;
		$quarter = (int) $quarter;
		if ($year < 2021 || $quarter < 1 || $quarter > 4) {
			$this->errors[] = 'Érvénytelen időszak';
			return null;
		}
		list($start, $end) = self::quarterBounds($year, $quarter);
		$period = $year.'-Q'.$quarter;
		$mainCurrency = strtoupper((string) $conf->currency);

		// Candidate lines: standard/replacement/deposit invoices whose supply date is in the quarter, and credit notes
		// issued in the quarter (their source decides between "this quarter" and "correction of an earlier quarter")
		$sql = 'SELECT f.rowid AS fid, f.ref, f.type, f.datef, f.date_pointoftax, f.fk_facture_source, f.multicurrency_code,';
		$sql .= ' s.nom AS socname, c.code AS soccountry,';
		$sql .= ' d.rowid AS lid, d.tva_tx, d.vat_src_code, d.total_ht, d.total_tva, d.multicurrency_total_ht, d.multicurrency_total_tva,';
		$sql .= ' src.ref AS srcref, src.datef AS srcdatef, src.date_pointoftax AS srcdatepot';
		$sql .= ' FROM '.$this->db->prefix().'facture f';
		$sql .= ' JOIN '.$this->db->prefix().'facturedet d ON d.fk_facture = f.rowid';
		$sql .= ' JOIN '.$this->db->prefix().'societe s ON s.rowid = f.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'c_country c ON c.rowid = s.fk_pays';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'facture src ON src.rowid = f.fk_facture_source';
		$sql .= ' WHERE f.entity IN ('.getEntity('invoice').') AND f.fk_statut > 0';
		$sql .= " AND NOT (f.fk_statut = 3 AND f.close_code = 'replaced')";
		$sql .= ' AND d.product_type <> 9 AND '.self::ossLineSql();
		$sql .= ' AND ((f.type IN (0, 1, 3) AND COALESCE(f.date_pointoftax, f.datef) BETWEEN '."'".$start."' AND '".$end."')";
		$sql .= ' OR (f.type = 2 AND f.datef BETWEEN '."'".$start."' AND '".$end."'))";
		$sql .= ' ORDER BY f.datef, f.ref, d.rang';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = 'SQL: '.$this->db->lasterror();
			return null;
		}

		$current = array();
		$corrections = array();
		$details = array();
		$mixedChecked = array();
		while ($o = $this->db->fetch_object($resql)) {
			$code = trim((string) $o->vat_src_code);
			$ms = preg_match('/-([A-Za-z]{2})$/', $code, $m) ? strtoupper($m[1]) : strtoupper((string) $o->soccountry);
			$rate = (float) $o->tva_tx;
			$invCur = strtoupper(!empty($o->multicurrency_code) ? $o->multicurrency_code : $mainCurrency);

			// Which period does this line belong to?
			$section = 'current';
			$origPeriod = $period;
			if ((int) $o->type === 2) {
				if (empty($o->fk_facture_source)) {
					$this->warnings[] = $o->ref.': jóváíró számla eredeti számla nélkül, a tárgyidőszakban számolva';
				} else {
					$srcDate = $this->db->jdate(!empty($o->srcdatepot) ? $o->srcdatepot : $o->srcdatef);
					$origPeriod = self::quarterKey($srcDate);
					if ($origPeriod < $period) {
						$section = 'correction';
					} elseif ($origPeriod > $period) {
						$this->warnings[] = $o->ref.': a jóváíró számla korábbi, mint az eredeti számla ('.$o->srcref.', '.$origPeriod.'), a tárgyidőszakban számolva';
						$origPeriod = $period;
					}
				}
			}

			// Amount in EUR
			$net = ($invCur === $mainCurrency) ? (float) $o->total_ht : (float) $o->multicurrency_total_ht;
			$vat = ($invCur === $mainCurrency) ? (float) $o->total_tva : (float) $o->multicurrency_total_tva;
			$rateInfo = null;
			if ($invCur !== 'EUR') {
				list(, $origEnd) = self::quarterBounds((int) substr($origPeriod, 0, 4), (int) substr($origPeriod, -1));
				$rateInfo = $this->ecbRate($invCur, $origEnd);
				if (!$rateInfo) {
					$this->warnings[] = $o->ref.': nincs EKB-árfolyam ('.$invCur.', '.$origEnd.'), a tétel kimaradt';
					continue;
				}
			}
			$netEur = $rateInfo ? $net / $rateInfo['rate'] : $net;
			$vatEur = $rateInfo ? $vat / $rateInfo['rate'] : $vat;

			$key = $ms.'|'.$rate.'|'.$code;
			if ($section === 'current') {
				$bucket = &$current;
			} else {
				if (!isset($corrections[$origPeriod])) {
					$corrections[$origPeriod] = array();
				}
				$bucket = &$corrections[$origPeriod];
			}
			if (!isset($bucket[$key])) {
				$bucket[$key] = array('ms' => $ms, 'rate' => $rate, 'code' => $code, 'net' => 0.0, 'vat' => 0.0);
			}
			$bucket[$key]['net'] += $netEur;
			$bucket[$key]['vat'] += $vatEur;
			unset($bucket);

			$details[] = array(
				'ref' => $o->ref, 'type' => (int) $o->type, 'date' => $this->db->jdate($o->datef),
				'supply' => $this->db->jdate(!empty($o->date_pointoftax) ? $o->date_pointoftax : $o->datef),
				'soc' => $o->socname, 'ms' => $ms, 'rate' => $rate, 'code' => $code, 'currency' => $invCur,
				'net' => $net, 'vat' => $vat, 'ecb' => $rateInfo, 'netEur' => $netEur, 'vatEur' => $vatEur,
				'section' => $section, 'origPeriod' => $origPeriod, 'srcref' => $o->srcref,
			);

			// Mixed invoice (OSS + Hungarian lines): reported to NAV / stopped there, worth a look
			if (!isset($mixedChecked[$o->fid])) {
				$mixedChecked[$o->fid] = 1;
				$r2 = $this->db->query('SELECT COUNT(*) AS n FROM '.$this->db->prefix().'facturedet d WHERE d.fk_facture = '.((int) $o->fid).' AND d.product_type <> 9 AND NOT '.self::ossLineSql());
				if ($r2 && (int) $this->db->fetch_object($r2)->n > 0) {
					$this->warnings[] = $o->ref.': vegyes számla (OSS és nem OSS tételek), csak az OSS-tételek szerepelnek; a NAV-hoz nem küldhető be, a számlát szét kell bontani';
				}
			}
		}

		$sort = function ($a, $b) {
			return strcmp($a['ms'], $b['ms']) ?: ($b['rate'] <=> $a['rate']);
		};
		uasort($current, $sort);
		ksort($corrections);
		foreach ($corrections as &$c) {
			uasort($c, $sort);
		}
		unset($c);

		$ecb = null;
		if ($mainCurrency !== 'EUR') {
			$ecb = $this->ecbRate($mainCurrency, $end);
		}

		return array('period' => $period, 'start' => $start, 'end' => $end, 'mainCurrency' => $mainCurrency, 'ecbMain' => $ecb,
			'current' => array_values($current), 'corrections' => $corrections, 'details' => $details);
	}
}
