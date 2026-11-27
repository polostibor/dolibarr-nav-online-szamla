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
 * \file    htdocs/custom/navonline/class/navonlinetaxpayer.class.php
 * \ingroup navonline
 * \brief   Taxpayer data of a Hungarian tax number from NAV Online Számla (queryTaxpayer, read-only), for filling in a
 *          new partner: name, full tax number, VAT group id, address of the seat.
 * The environment is NAVONLINE_TAXPAYER_ENVIRONMENT (default prod, allowed by the user on 2026-11-27: read-only query,
 * the test environment returns unreliable data), independent of the environment of invoice reporting.
 * NAVONLINE_TAXPAYER_LOOKUP = 0 turns the lookup off.
 */

require_once __DIR__.'/navonlineclient.class.php';

/**
 * NAV taxpayer query
 */
class NavOnlineTaxpayer
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
	 * @return bool The lookup is on
	 */
	public static function enabled()
	{
		return getDolGlobalString('NAVONLINE_TAXPAYER_LOOKUP', '1') !== '0';
	}

	/**
	 * @return string Environment of the query
	 */
	public static function environment()
	{
		return getDolGlobalString('NAVONLINE_TAXPAYER_ENVIRONMENT', NAVONLINE_ENV_PROD) === NAVONLINE_ENV_TEST ? NAVONLINE_ENV_TEST : NAVONLINE_ENV_PROD;
	}

	/**
	 * @param string $taxNumber Tax number as typed (12345678, 12345678-2-03, 12345678203)
	 * @return string The 8-digit taxpayer id, '' if not a Hungarian tax number
	 */
	public static function taxpayerId($taxNumber)
	{
		$d = preg_replace('/\D/', '', (string) $taxNumber);
		return (strlen($d) === 8 || strlen($d) === 11) ? substr($d, 0, 8) : '';
	}

	/**
	 * Query NAV.
	 *
	 * @param string      $taxNumber Tax number
	 * @param string|null $env       Environment (null: NAVONLINE_TAXPAYER_ENVIRONMENT)
	 * @return array<string,mixed>|null status (ok | notfound | invalid), and with ok: name, short_name, tax_number,
	 *                                  vat_code, group_id, incorporation, address, zip, town, country; null on error
	 */
	public function lookup($taxNumber, $env = null)
	{
		$this->errors = array();
		$id = self::taxpayerId($taxNumber);
		if ($id === '') {
			$this->errors[] = 'Nem magyar adószám (8 vagy 11 számjegy kell)';
			return null;
		}
		$client = new NavOnlineClient($this->db);
		$reporter = $client->getReporter($env === null ? self::environment() : $env);
		if (!$reporter) {
			$this->errors = $client->errors ?: array('A NAV-kapcsolat nem hozható létre');
			return null;
		}
		try {
			$data = $reporter->queryTaxpayer($id);
		} catch (Throwable $e) {
			$this->errors[] = 'NAV: '.$e->getMessage();
			return null;
		}
		if ($data === null) {
			return array('status' => 'notfound', 'taxpayer_id' => $id);
		}
		if ($data === false) {
			return array('status' => 'invalid', 'taxpayer_id' => $id);
		}
		return array('status' => 'ok', 'taxpayer_id' => $id) + self::parse($data);
	}

	/**
	 * Child element by local name, in any namespace.
	 *
	 * @param SimpleXMLElement|null $node Node
	 * @param string                $name Local name
	 * @return SimpleXMLElement|null
	 */
	private static function el($node, $name)
	{
		if (!$node instanceof SimpleXMLElement) {
			return null;
		}
		if (isset($node->$name)) {
			return $node->$name;
		}
		foreach ($node->getNamespaces(true) as $ns) {
			$c = $node->children($ns);
			if (isset($c->$name)) {
				return $c->$name;
			}
		}
		foreach ($node->getDocNamespaces(true, true) as $ns) {
			$c = $node->children($ns);
			if (isset($c->$name)) {
				return $c->$name;
			}
		}
		return null;
	}

	/**
	 * @param SimpleXMLElement|null $node Node
	 * @param string                $name Local name
	 * @return string Trimmed text of the child, '' if missing
	 */
	private static function txt($node, $name)
	{
		$e = self::el($node, $name);
		return $e === null ? '' : trim((string) $e);
	}

	/**
	 * @param SimpleXMLElement $data taxpayerData
	 * @return array<string,mixed>
	 */
	public static function parse($data)
	{
		$detail = self::el($data, 'taxNumberDetail');
		$id = self::txt($detail, 'taxpayerId');
		$vat = self::txt($detail, 'vatCode');
		$county = self::txt($detail, 'countyCode');
		$out = array(
			'name' => self::txt($data, 'taxpayerName'),
			'short_name' => self::txt($data, 'taxpayerShortName'),
			'tax_number' => $id !== '' && $vat !== '' && $county !== '' ? $id.'-'.$vat.'-'.$county : $id,
			'vat_code' => $vat,
			'group_id' => self::txt($data, 'vatGroupMembership'),
			'incorporation' => self::txt($data, 'incorporation'),
			'address' => '', 'zip' => '', 'town' => '', 'country' => '',
		);
		// Address: the seat (HQ), else the first one
		$list = self::el($data, 'taxpayerAddressList');
		$items = array();
		if ($list !== null) {
			$it = self::el($list, 'taxpayerAddressItem');
			if ($it !== null) {
				foreach ($it as $one) {
					$items[] = $one;
				}
			}
		}
		$pick = null;
		foreach ($items as $one) {
			if (self::txt($one, 'taxpayerAddressType') === 'HQ') {
				$pick = $one;
				break;
			}
		}
		if (!$pick && $items) {
			$pick = $items[0];
		}
		$a = $pick ? self::el($pick, 'taxpayerAddress') : null;
		if ($a !== null) {
			$out['zip'] = self::txt($a, 'postalCode');
			$out['town'] = self::nice(self::txt($a, 'city'));
			$out['country'] = self::txt($a, 'countryCode');
			$out['address'] = self::streetLine($a);
		}
		return $out;
	}

	/**
	 * NAV gives the address in capitals: "KECSKEMÉT" -> "Kecskemét", "HOFHERR ALBERT" -> "Hofherr Albert". A text not
	 * all in capitals is kept as it is.
	 *
	 * @param string $v Text
	 * @return string
	 */
	public static function nice($v)
	{
		return ($v !== '' && mb_strtoupper($v, 'UTF-8') === $v) ? mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : $v;
	}

	/**
	 * Street line in the Hungarian order: "Klapka utca 22. A ép. 1. lph. fszt. 2.", or the lot number.
	 *
	 * @param SimpleXMLElement $a taxpayerAddress (detailed address)
	 * @return string
	 */
	private static function streetLine($a)
	{
		$dot = function ($v) {
			return preg_match('/\d$/', $v) ? $v.'.' : $v;
		};
		$parts = array();
		$street = trim(self::nice(self::txt($a, 'streetName')).' '.mb_strtolower(self::txt($a, 'publicPlaceCategory'), 'UTF-8'));
		if ($street !== '') {
			$parts[] = $street;
		}
		if (($n = self::txt($a, 'number')) !== '') {
			$parts[] = $dot($n);
		}
		if (($b = self::txt($a, 'building')) !== '') {
			$parts[] = $b.' ép.';
		}
		if (($s = self::txt($a, 'staircase')) !== '') {
			$parts[] = $dot($s).' lph.';
		}
		if (($f = self::txt($a, 'floor')) !== '') {
			$f = mb_strtolower($f, 'UTF-8');
			$parts[] = preg_match('/^\d+$/', $f) ? $f.'. em.' : rtrim($f, '.').'.';	// "fszt" -> "fszt."
		}
		if (($d = self::txt($a, 'door')) !== '') {
			$parts[] = $dot(mb_strtolower($d, 'UTF-8'));
		}
		if (!$parts && ($l = self::txt($a, 'lotNumber')) !== '') {
			$parts[] = 'hrsz. '.$l;
		}
		return implode(' ', $parts);
	}
}
