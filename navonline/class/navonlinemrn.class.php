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
 * \file    htdocs/custom/navonline/class/navonlinemrn.class.php
 * \ingroup navonline
 * \brief   Status of an export / transit movement (MRN) from the EU "MRN Follow-up" service of the Commission
 *          (https://ec.europa.eu/taxation_customs/dds2/mrn/). Information only: "The data provided is for information
 *          only and is binding neither for the Commission nor for the National Administrations concerned"; not the
 *          proof of exit of Áfa tv. 98. §. The details are removed about 100 days after the last message of the
 *          movement. Only on request of a user (one MRN per click), no scheduled queries.
 */

/**
 * EU MRN follow-up query
 */
class NavOnlineMrn
{
	const URL = 'https://ec.europa.eu/taxation_customs/dds2/mrn/mrn_list.jsp';

	/** @var string[] */
	public $errors = array();

	/**
	 * @param string $mrn MRN
	 * @return bool Format of an MRN: 2 digits (year), 2 letters (country), 13 characters, 1 check character
	 */
	public static function valid($mrn)
	{
		return (bool) preg_match('/^[0-9]{2}[A-Z]{2}[A-Z0-9]{13}[A-Z0-9]$/', (string) $mrn);
	}

	/**
	 * Query the EU service.
	 *
	 * @param string $mrn MRN
	 * @return array<string,mixed>|null status (found | notfound), rows (type, country, date_utc, time_utc, state, current),
	 *                                  exited (bool), exit_country, exit_date (Y-m-d, Budapest time), exit_utc; null on error
	 */
	public function check($mrn)
	{
		$this->errors = array();
		$mrn = strtoupper(trim((string) $mrn));
		if (!self::valid($mrn)) {
			$this->errors[] = 'Érvénytelen MRN: '.$mrn;
			return null;
		}
		$ch = curl_init(self::URL.'?Lang=en&MRN='.rawurlencode($mrn));
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
			CURLOPT_USERAGENT => 'Dolibarr', CURLOPT_HTTPHEADER => array('Accept: text/html')));
		$html = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		if ($html === false || $code !== 200) {
			$this->errors[] = 'Az EU MRN-szolgáltatás nem érhető el'.($err ? ': '.$err : ' (HTTP '.$code.')');
			return null;
		}
		return self::parse((string) $html);
	}

	/**
	 * Parse the answer of mrn_list.jsp.
	 *
	 * @param string $html Answer
	 * @return array<string,mixed>|null
	 */
	public static function parse($html)
	{
		if (stripos($html, 'No information found') !== false) {
			return array('status' => 'notfound', 'rows' => array(), 'exited' => false, 'exit_country' => '', 'exit_date' => '', 'exit_utc' => '');
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
		libxml_clear_errors();
		$rows = array();
		foreach ($dom->getElementsByTagName('tbody') as $tb) {
			foreach ($tb->getElementsByTagName('tr') as $tr) {
				$cells = array();
				$bold = false;
				foreach ($tr->getElementsByTagName('td') as $td) {
					$cells[] = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $td->textContent)));
					$bold = $bold || $td->getElementsByTagName('b')->length > 0;
				}
				if (count($cells) < 4) {
					continue;
				}
				$utc = '';
				if (preg_match('#^(\d{2})/(\d{2})/(\d{4})\s*-\s*(\d{2}):(\d{2})#', $cells[2], $m)) {
					$utc = $m[3].'-'.$m[2].'-'.$m[1].' '.$m[4].':'.$m[5];
				}
				$rows[] = array('type' => $cells[0], 'country' => $cells[1], 'date' => $cells[2], 'utc' => $utc, 'state' => $cells[3], 'current' => $bold);
			}
		}
		if (!$rows) {
			return null;
		}
		// Exit: a state "Exported" / "Stopped/Exported" (not "Released for Export"); the latest one
		$exit = null;
		foreach ($rows as $r) {
			if (preg_match('/exported/i', $r['state']) && !preg_match('/released\s+for\s+export/i', $r['state']) && $r['utc'] !== '' && (!$exit || $r['utc'] > $exit['utc'])) {
				$exit = $r;
			}
		}
		$local = '';
		if ($exit) {
			$d = new DateTime($exit['utc'], new DateTimeZone('UTC'));
			$d->setTimezone(new DateTimeZone('Europe/Budapest'));
			$local = $d->format('Y-m-d');
		}
		return array('status' => 'found', 'rows' => $rows, 'exited' => (bool) $exit, 'exit_country' => $exit ? $exit['country'] : '', 'exit_date' => $local, 'exit_utc' => $exit ? $exit['utc'] : '');
	}
}
