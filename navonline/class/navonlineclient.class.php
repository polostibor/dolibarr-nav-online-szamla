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
 * \file    htdocs/custom/navonline/class/navonlineclient.class.php
 * \ingroup navonline
 * \brief   Factory of the pzs NavOnlineInvoice\Reporter for the selected environment (production is locked).
 *
 * Security rules:
 *  - The base URL is the hard-coded test endpoint; anything else is refused.
 *  - config/credentials.php is only include()-d at runtime. Its values are never printed, logged
 *    or stored; error messages mention key names only.
 *  - Never log Reporter::getLastRequestData()['requestBody'] (it contains the login, password hash and signature).
 */

require_once __DIR__.'/../lib/navonline.lib.php';
require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * NAV API client factory
 */
class NavOnlineClient
{
	/** @var string[] */
	public $errors = array();

	/** @var string Environment of the last getReporter() call */
	public $environment = NAVONLINE_ENV_TEST;

	/** @var string Taxpayer id (8 digits) of the technical user, for consistency checks */
	private $userTaxpayerId = '';

	/**
	 * Create a Reporter for the selected NAV environment.
	 *
	 * @param string|null $env Environment; null = the one selected for reporting (NAVONLINE_ENVIRONMENT). The inbound
	 *                         invoice reader passes its own environment (read-only queries, NAVONLINE_INBOUND_ENVIRONMENT).
	 * @return \NavOnlineInvoice\Reporter|null Null on configuration error ($this->errors)
	 */
	public function getReporter($env = null)
	{
		$this->errors = array();

		$this->environment = $env === NAVONLINE_ENV_PROD ? NAVONLINE_ENV_PROD : ($env === null ? navonlineGetEnvironment() : NAVONLINE_ENV_TEST);
		$url = navonlineGetApiUrl($this->environment);
		if ($this->environment === NAVONLINE_ENV_PROD) {
			// Production: only the official endpoint, refused before any network call when locked
			if (!navonlineIsProdAvailable() || $url === '') {
				$this->errors[] = 'Refused: the NAV production environment is not enabled in this installation';
				return null;
			}
			if ($url !== NAVONLINE_PROD_API_URL || parse_url($url, PHP_URL_HOST) !== 'api.onlineszamla.nav.gov.hu') {
				$this->errors[] = 'Refused: unexpected production endpoint';
				return null;
			}
		} elseif (!self::isTestUrl($url)) {
			$this->errors[] = 'Refused: only the NAV test endpoint may be used in the test environment';
			return null;
		}

		$user = $this->loadUser();
		$software = $this->loadSoftware();
		if ($user === null || $software === null) {
			return null;
		}

		try {
			$config = new \NavOnlineInvoice\Config($url, $user, $software);
			$config->useApiSchemaValidation();
			$config->setCurlTimeout(getDolGlobalInt('NAVONLINE_CURL_TIMEOUT', 60));
			return new \NavOnlineInvoice\Reporter($config);
		} catch (Exception $e) {
			$this->errors[] = 'NAV config error: '.$e->getMessage();
			return null;
		}
	}

	/**
	 * @return string 8-digit taxpayer id of the technical user ('' before getReporter())
	 */
	public function getUserTaxpayerId()
	{
		return $this->userTaxpayerId;
	}

	/**
	 * @param string $url URL
	 * @return bool
	 */
	public static function isTestUrl($url)
	{
		return $url === NAVONLINE_TEST_API_URL && parse_url($url, PHP_URL_HOST) === 'api-test.onlineszamla.nav.gov.hu';
	}

	/**
	 * Load technical user data from config/credentials.php.
	 *
	 * @return array<string,string>|null
	 */
	private function loadUser()
	{
		$path = navonlineGetCredentialsPath($this->environment);
		$file = 'config/'.basename($path);
		if (!is_file($path)) {
			$this->errors[] = $file.' is missing (copy the matching .example file and fill it in)';
			return null;
		}

		$data = (static function ($file) {
			return include $file;
		})($path);

		if (!is_array($data)) {
			$this->errors[] = $file.' must return an array';
			return null;
		}

		$missing = array();
		foreach (array('login', 'taxNumber', 'signKey', 'exchangeKey') as $key) {
			if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
				$missing[] = $key;
			}
		}
		if ((!isset($data['password']) || $data['password'] === '') && (!isset($data['passwordHash']) || $data['passwordHash'] === '')) {
			$missing[] = 'password';
		}
		if ($missing) {
			$this->errors[] = $file.': missing value(s) for '.implode(', ', $missing);
			return null;
		}

		$user = array(
			'login' => (string) $data['login'],
			'taxNumber' => substr(preg_replace('/[^0-9]/', '', (string) $data['taxNumber']), 0, 8),
			'signKey' => (string) $data['signKey'],
			'exchangeKey' => (string) $data['exchangeKey'],
		);
		if (!empty($data['passwordHash'])) {
			$user['passwordHash'] = (string) $data['passwordHash'];
		} else {
			$user['password'] = (string) $data['password'];
		}
		if (strlen($user['taxNumber']) !== 8) {
			$this->errors[] = $file.': taxNumber must start with 8 digits';
			return null;
		}

		$this->userTaxpayerId = $user['taxNumber'];
		return $user;
	}

	/**
	 * Software data from module setup.
	 *
	 * @return array<string,string>|null
	 */
	private function loadSoftware()
	{
		$software = array(
			'softwareId' => strtoupper(getDolGlobalString('NAVONLINE_SOFTWARE_ID')),
			'softwareName' => getDolGlobalString('NAVONLINE_SOFTWARE_NAME', 'Dolibarr'),
			'softwareOperation' => getDolGlobalString('NAVONLINE_SOFTWARE_OPERATION', 'LOCAL_SOFTWARE'),
			'softwareMainVersion' => getDolGlobalString('NAVONLINE_SOFTWARE_MAIN_VERSION', DOL_VERSION),
			'softwareDevName' => getDolGlobalString('NAVONLINE_SOFTWARE_DEV_NAME'),
			'softwareDevContact' => getDolGlobalString('NAVONLINE_SOFTWARE_DEV_CONTACT'),
			'softwareDevCountryCode' => strtoupper(getDolGlobalString('NAVONLINE_SOFTWARE_DEV_COUNTRY', 'HU')),
			'softwareDevTaxNumber' => getDolGlobalString('NAVONLINE_SOFTWARE_DEV_TAXNUMBER'),
		);

		$errors = array();
		if (!preg_match('/^[0-9A-Z\-]{18}$/', $software['softwareId'])) {
			$errors[] = 'Software ID must be 18 characters [0-9A-Z-]';
		}
		foreach (array('softwareName', 'softwareMainVersion', 'softwareDevName', 'softwareDevContact') as $key) {
			if (trim($software[$key]) === '') {
				$errors[] = 'Software data "'.$key.'" is empty (module setup)';
			}
		}
		if ($software['softwareDevTaxNumber'] === '') {
			unset($software['softwareDevTaxNumber']);
		}
		if ($errors) {
			$this->errors = array_merge($this->errors, $errors);
			return null;
		}

		return $software;
	}

	/**
	 * Human readable message of a pzs / NAV exception (never contains credentials).
	 *
	 * @param Exception $e Exception
	 * @return string
	 */
	public static function describeException($e)
	{
		if ($e instanceof \NavOnlineInvoice\BaseExceptionResponse) {
			return 'NAV '.$e->getErrorCode().': '.$e->getResultMessage();
		}
		if ($e instanceof \NavOnlineInvoice\CurlError) {
			return 'Network error: '.$e->getMessage();
		}
		if ($e instanceof \NavOnlineInvoice\HttpResponseError) {
			return 'HTTP error: '.dol_trunc($e->getMessage(), 300);
		}
		return get_class($e).': '.dol_trunc($e->getMessage(), 500);
	}
}
