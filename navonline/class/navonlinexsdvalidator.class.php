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
 * \file    htdocs/custom/navonline/class/navonlinexsdvalidator.class.php
 * \ingroup navonline
 * \brief   Validate NAV 3.0 InvoiceData XML against the official XSD schemas (C:\dev\Online-Invoice).
 *
 * The official schemas import their dependencies by namespace only (no schemaLocation, they are meant to be
 * resolved through catalog.xml). libxml has no catalog support for XSD imports, so we build a small wrapper
 * schema that imports every namespace with an explicit absolute location, in dependency order:
 *   common (NTCA 1.0)  -> from the pzs/nav-online-invoice package (not shipped in the Online-Invoice repo)
 *   base   (OSA 3.0)   -> C:\dev\Online-Invoice\...\invoiceBase.xsd
 *   data   (OSA 3.0)   -> C:\dev\Online-Invoice\...\invoiceData.xsd
 * Validation itself is done by NavOnlineInvoice\Xsd::validate() of the pzs package.
 */

require_once __DIR__.'/../lib/navonline.lib.php';
require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * XSD validator for NAV Online Számla 3.0 data XML
 */
class NavOnlineXsdValidator
{
	const NS_COMMON = 'http://schemas.nav.gov.hu/NTCA/1.0/common';
	const NS_BASE = 'http://schemas.nav.gov.hu/OSA/3.0/base';
	const NS_DATA = 'http://schemas.nav.gov.hu/OSA/3.0/data';
	const NS_ANNUL = 'http://schemas.nav.gov.hu/OSA/3.0/annul';

	/** @var string Directory holding invoiceData.xsd and invoiceBase.xsd */
	private $schemaDir;

	/** @var string[] Last validation errors */
	public $errors = array();

	/**
	 * @param string|null $schemaDir Override of the official schema directory
	 */
	public function __construct($schemaDir = null)
	{
		$this->schemaDir = rtrim(str_replace('\\', '/', $schemaDir ? $schemaDir : navonlineGetXsdDir()), '/');
	}

	/**
	 * Validate an InvoiceData XML string.
	 *
	 * @param string $xml XML document
	 * @return bool       True if valid; errors are in $this->errors otherwise
	 */
	public function validateInvoiceData($xml)
	{
		return $this->validate($xml, 'InvoiceData', self::NS_DATA);
	}

	/**
	 * Validate an InvoiceAnnulment (technical annulment) XML string.
	 *
	 * @param string $xml XML document
	 * @return bool
	 */
	public function validateAnnulment($xml)
	{
		return $this->validate($xml, 'InvoiceAnnulment', self::NS_ANNUL);
	}

	/**
	 * @param string $xml      XML document
	 * @param string $rootName Expected root element
	 * @param string $rootNs   Expected root namespace
	 * @return bool
	 */
	private function validate($xml, $rootName, $rootNs)
	{
		$this->errors = array();

		$wrapper = $this->getWrapperPath();
		if ($wrapper === '') {
			return false;
		}

		$prev = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML($xml);
		libxml_use_internal_errors($prev);
		if (!$loaded) {
			$this->errors[] = 'XML is not well-formed';
			return false;
		}
		if ($doc->documentElement->localName !== $rootName || $doc->documentElement->namespaceURI !== $rootNs) {
			$this->errors[] = 'Unexpected root element, expected {'.$rootNs.'}'.$rootName;
			return false;
		}

		try {
			\NavOnlineInvoice\Xsd::validate($xml, $wrapper);
		} catch (\NavOnlineInvoice\XsdValidationError $e) {
			$this->errors[] = trim($e->getMessage());
			return false;
		}

		return true;
	}

	/**
	 * Return (and create if needed) the wrapper schema file path.
	 *
	 * @return string Empty string on error
	 */
	public function getWrapperPath()
	{
		$data = $this->schemaDir.'/invoiceData.xsd';
		$annul = $this->schemaDir.'/invoiceAnnulment.xsd';
		$base = $this->schemaDir.'/invoiceBase.xsd';
		$common = str_replace('\\', '/', dirname(__DIR__)).'/vendor/pzs/nav-online-invoice/src/NavOnlineInvoice/xsd/common.xsd';

		foreach (array($data, $annul, $base, $common) as $f) {
			if (!is_file($f)) {
				$this->errors[] = 'XSD not found: '.$f;
				return '';
			}
		}

		$content = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$content .= '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" elementFormDefault="qualified">'."\n";
		$content .= '	<xs:import namespace="'.self::NS_COMMON.'" schemaLocation="'.self::toFileUri($common).'"/>'."\n";
		$content .= '	<xs:import namespace="'.self::NS_BASE.'" schemaLocation="'.self::toFileUri($base).'"/>'."\n";
		$content .= '	<xs:import namespace="'.self::NS_DATA.'" schemaLocation="'.self::toFileUri($data).'"/>'."\n";
		$content .= '	<xs:import namespace="'.self::NS_ANNUL.'" schemaLocation="'.self::toFileUri($annul).'"/>'."\n";
		$content .= '</xs:schema>'."\n";

		$dir = self::getTempDir();
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
			$this->errors[] = 'Cannot create temp dir '.$dir;
			return '';
		}

		$path = $dir.'/nav30.wrapper.'.substr(md5($content), 0, 12).'.xsd';
		if (!is_file($path) && file_put_contents($path, $content) === false) {
			$this->errors[] = 'Cannot write '.$path;
			return '';
		}

		return $path;
	}

	/**
	 * @return string
	 */
	private static function getTempDir()
	{
		if (defined('DOL_DATA_ROOT')) {
			return DOL_DATA_ROOT.'/navonline/temp';
		}
		return sys_get_temp_dir().'/navonline';
	}

	/**
	 * @param string $path Absolute path
	 * @return string file:/// URI
	 */
	private static function toFileUri($path)
	{
		$path = str_replace('\\', '/', $path);
		$segments = array_map('rawurlencode', explode('/', $path));
		// keep the drive letter colon (C:) readable
		$segments = array_map(function ($s) {
			return str_replace('%3A', ':', $s);
		}, $segments);
		$uri = implode('/', $segments);
		return 'file:///'.ltrim($uri, '/');
	}
}
