<?php
/* NAV Online Számla module: VIES check of the community VAT number of an intra-Community customer (KBAET / KBAUK).
 *
 * The VAT number is sent to the public VIES service of the European Commission (REST API), with our own community VAT
 * number as requester, so a valid answer carries a consultation number (requestIdentifier): proof that the buyer's VAT
 * number was valid when the document was issued (Áfa tv. 89. §). Every query is logged in llx_navonline_vies (the row
 * of a refused validation is rolled back with the document; the system log keeps it).
 *
 * Settings (llx_const, all optional):
 *  NAVONLINE_VIES_CHECK          0 = no VIES check (default 1)
 *  NAVONLINE_VIES_CACHE_HOURS    proposals / orders reuse a valid answer this old (default 24); invoices always query
 *  NAVONLINE_VIES_FALLBACK_DAYS  VIES unavailable: allowed (with a warning) if a valid answer is this recent (default 30)
 *  NAVONLINE_VIES_URL            endpoint (tests)
 */

/**
 * VIES client
 */
class NavOnlineVies
{
	const URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Check the community VAT number of a customer for a document.
	 *
	 * @param Societe $soc        Customer (tva_intra already format-checked by navonlineIntraCommunityBuyerError())
	 * @param string  $element    Document element (facture, propal, commande)
	 * @param int     $fkElement  Document id
	 * @param User    $user       User
	 * @return array{result:string,vat:string,error:string,request_identifier:string,last_valid:string}
	 *         result: valid, invalid, unavailable_ok (a recent valid answer), unavailable
	 */
	public function check($soc, $element, $fkElement, $user = null)
	{
		$vat = strtoupper((string) preg_replace('/[\s.\-]+/', '', (string) $soc->tva_intra));
		$out = array('result' => 'unavailable', 'vat' => $vat, 'error' => '', 'request_identifier' => '', 'last_valid' => '');
		if ($element !== 'facture') {
			$recent = $this->lastValid($vat, (int) getDolGlobalString('NAVONLINE_VIES_CACHE_HOURS', '24') * 3600);
			if ($recent) {
				$out['result'] = 'valid';
				$out['request_identifier'] = (string) $recent->request_identifier;
				$out['last_valid'] = (string) $recent->request_date;
				return $out;
			}
		}
		$answer = $this->query($vat);
		$this->log($vat, $answer, $soc, $element, $fkElement, $user);
		$out['error'] = $answer['error'];
		$out['request_identifier'] = $answer['request_identifier'];
		if ($answer['status'] === 'valid' || $answer['status'] === 'invalid') {
			$out['result'] = $answer['status'];
			return $out;
		}
		$recent = $this->lastValid($vat, (int) getDolGlobalString('NAVONLINE_VIES_FALLBACK_DAYS', '30') * 86400);
		if ($recent) {
			$out['result'] = 'unavailable_ok';
			$out['last_valid'] = (string) $recent->request_date;
			$out['request_identifier'] = (string) $recent->request_identifier;
		}
		return $out;
	}

	/**
	 * Query VIES.
	 *
	 * @param string $vat VAT number with its prefix (EL for Greece, XI for Northern Ireland)
	 * @return array{status:string,error:string,request_identifier:string,name:string,address:string,date:string}
	 */
	public function query($vat)
	{
		global $mysoc;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$body = array('countryCode' => substr($vat, 0, 2), 'vatNumber' => substr($vat, 2));
		$own = strtoupper((string) preg_replace('/[\s.\-]+/', '', (string) (is_object($mysoc) ? $mysoc->tva_intra : '')));
		if (preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $own)) {
			$body['requesterMemberStateCode'] = substr($own, 0, 2);	// a consultation number comes only with a requester
			$body['requesterNumber'] = substr($own, 2);
		}
		$out = array('status' => 'unavailable', 'error' => '', 'request_identifier' => '', 'name' => '', 'address' => '', 'date' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'gmt'));
		$res = getURLContent(getDolGlobalString('NAVONLINE_VIES_URL', self::URL), 'POSTALREADYFORMATED', json_encode($body), 1, array('Content-Type: application/json', 'Accept: application/json'), array('https'), 0, -1, 10, 30);
		$json = json_decode((string) ($res['content'] ?? ''), true);
		if (is_array($json) && isset($json['valid']) && is_bool($json['valid'])) {
			$out['status'] = $json['valid'] ? 'valid' : 'invalid';
			$out['request_identifier'] = (string) ($json['requestIdentifier'] ?? '');
			$out['name'] = ($json['name'] ?? '---') !== '---' ? (string) $json['name'] : '';
			$out['address'] = ($json['address'] ?? '---') !== '---' ? (string) $json['address'] : '';
			return $out;
		}
		// VIES or the member state's database is down, too many requests, network error...
		$err = '';
		if (is_array($json) && !empty($json['errorWrappers'][0]['error'])) {
			$err = (string) $json['errorWrappers'][0]['error'];
		} elseif (is_array($json) && !empty($json['userError'])) {
			$err = (string) $json['userError'];
		} elseif (!empty($res['curl_error_msg'])) {
			$err = 'NETWORK';
		} else {
			$err = 'HTTP '.((int) ($res['http_code'] ?? 0));
		}
		$out['error'] = substr($err, 0, 64);
		return $out;
	}

	/**
	 * Last valid answer for a VAT number not older than $seconds.
	 *
	 * @param string $vat     VAT number
	 * @param int    $seconds Age
	 * @return ?object
	 */
	private function lastValid($vat, $seconds)
	{
		$sql = 'SELECT request_identifier, request_date FROM '.$this->db->prefix()."navonline_vies WHERE entity = ".((int) getEntity('societe', 0))." AND vat_number = '".$this->db->escape($vat)."' AND status = 'valid'";
		$sql .= " AND request_date >= '".$this->db->escape(dol_print_date(dol_now() - $seconds, '%Y-%m-%d %H:%M:%S', 'gmt'))."' ORDER BY request_date DESC LIMIT 1";
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ?: null;
	}

	/**
	 * Log a query.
	 *
	 * @param string              $vat       VAT number
	 * @param array<string,string> $answer   Answer of query()
	 * @param Societe             $soc       Customer
	 * @param string              $element   Document element
	 * @param int                 $fkElement Document id
	 * @param ?User               $user      User
	 * @return void
	 */
	private function log($vat, $answer, $soc, $element, $fkElement, $user)
	{
		// also in the system log: a refused validation rolls the row back with the document
		dol_syslog('NavOnline VIES '.$vat.' '.$answer['status'].($answer['request_identifier'] !== '' ? ' '.$answer['request_identifier'] : '').($answer['error'] !== '' ? ' '.$answer['error'] : '').' ('.$element.' '.((int) $fkElement).')', LOG_NOTICE);
		$sql = 'INSERT INTO '.$this->db->prefix().'navonline_vies (entity, vat_number, status, request_identifier, request_date, name, address, error, fk_soc, element, fk_element, fk_user) VALUES (';
		$sql .= ((int) getEntity('societe', 0)).", '".$this->db->escape($vat)."', '".$this->db->escape($answer['status'])."', ";
		$sql .= ($answer['request_identifier'] !== '' ? "'".$this->db->escape($answer['request_identifier'])."'" : 'NULL').", '".$this->db->escape($answer['date'])."', ";
		$sql .= ($answer['name'] !== '' ? "'".$this->db->escape(dol_trunc($answer['name'], 250, 'right', 'UTF-8', 1))."'" : 'NULL').', ';
		$sql .= ($answer['address'] !== '' ? "'".$this->db->escape(dol_trunc($answer['address'], 500, 'right', 'UTF-8', 1))."'" : 'NULL').', ';
		$sql .= ($answer['error'] !== '' ? "'".$this->db->escape($answer['error'])."'" : 'NULL').', ';
		$sql .= ((int) $soc->id).", '".$this->db->escape($element)."', ".((int) $fkElement).', '.(is_object($user) ? (int) $user->id : 'NULL').')';
		$this->db->query($sql);
	}
}
