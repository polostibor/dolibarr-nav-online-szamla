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
 * \file    htdocs/custom/navonline/scripts/navonline_xml.php
 * \ingroup navonline
 * \brief   CLI: build and XSD-validate NAV InvoiceData XML. Never contacts NAV.
 *
 * Usage:
 *   php navonline_xml.php --selftest            Build synthetic invoices (no DB write) and validate them
 *   php navonline_xml.php --samples             Validate the official NAV sample XMLs (checks the XSD setup)
 *   php navonline_xml.php <invoice_id> [--save] Build XML for a real invoice; --save writes it to documents/navonline/xml
 */

$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) == 'cgi' || $sapi_type === 'apache2handler') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$res = @include __DIR__.'/../../../master.inc.php';
if (!$res) {
	$res = @include __DIR__.'/../../../../master.inc.php';
}
if (!$res) {
	echo "Error: cannot include master.inc.php\n";
	exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/../class/navonlinexmlbuilder.class.php';
require_once __DIR__.'/../class/navonlinexsdvalidator.class.php';

/**
 * @var DoliDB $db
 * @var Conf $conf
 * @var Societe $mysoc
 */

$arg = isset($argv[1]) ? $argv[1] : '';
$save = in_array('--save', $argv, true);

if ($arg === '' || $arg === '--help') {
	echo "Usage: php navonline_xml.php --selftest | --samples | <invoice_id> [--save]\n";
	exit(0);
}

$validator = new NavOnlineXsdValidator();

if ($arg === '--samples') {
	// .../Online-Invoice/src/schemas/nav/gov/hu/OSA -> .../Online-Invoice
	$dir = dirname(navonlineGetXsdDir(), 6).'/sample/Data sample';
	$files = glob($dir.'/*.xml');
	if (!$files) {
		echo "No sample found in $dir\n";
		exit(1);
	}
	$bad = 0;
	foreach ($files as $f) {
		$ok = $validator->validateInvoiceData(file_get_contents($f));
		echo ($ok ? 'OK      ' : 'INVALID ').basename($f)."\n";
		if (!$ok) {
			$bad++;
			echo '        '.implode("\n        ", $validator->errors)."\n";
		}
	}
	echo count($files).' samples, '.$bad." invalid\n";
	exit($bad ? 1 : 0);
}

/**
 * Build + validate (official XSD + pzs InvoiceOperations) and print the result.
 *
 * @param string                $label     Case label
 * @param Facture               $invoice   Invoice
 * @param Societe|null          $supplier  Supplier
 * @param array<string,mixed>   $options   Builder options
 * @param NavOnlineXsdValidator $validator Validator
 * @param bool                  $print     Print the XML
 * @return string|null XML if valid
 */
function navonlineCheckCase($label, $invoice, $supplier, $options, $validator, $print = false)
{
	global $db;

	$builder = new NavOnlineXmlBuilder($db);
	$xml = $builder->build($invoice, $supplier, $options);
	if (!$xml) {
		echo "FAIL  $label: data errors\n      - ".implode("\n      - ", $builder->errors)."\n";
		return null;
	}

	$dom = dom_import_simplexml($xml)->ownerDocument;
	$dom->formatOutput = true;
	$xmlString = $dom->saveXML();

	if (!$validator->validateInvoiceData($xmlString)) {
		echo "FAIL  $label: XSD\n      ".implode("\n      ", $validator->errors)."\n";
		if ($print) {
			echo $xmlString;
		}
		return null;
	}

	// Cross-check with the pzs package (it validates against its own copy of the data XSD as well)
	try {
		$ops = new \NavOnlineInvoice\InvoiceOperations();
		$ops->useDataSchemaValidation();
		$ops->add($xml, $builder->operation);
	} catch (Exception $e) {
		echo "FAIL  $label: pzs InvoiceOperations: ".trim($e->getMessage())."\n";
		return null;
	}

	echo "OK    $label (operation ".$builder->operation.")\n";
	foreach ($builder->warnings as $w) {
		echo "      $w\n";
	}
	if ($print) {
		echo $xmlString;
	}
	return $xmlString;
}

if ($arg === '--selftest') {
	$conf->currency = 'HUF';

	$supplier = new Societe($db);
	$supplier->name = 'Teszt Értékesítő Kft. & Társa';
	$supplier->idprof1 = '99999999-2-41';
	$supplier->tva_intra = 'HU99999999';
	$supplier->country_code = 'HU';
	$supplier->zip = '1234';
	$supplier->town = 'Budapest';
	$supplier->address = "Hármas utca 1.\n2. emelet";

	$mkSoc = function ($name, $country, $zip, $town, $address, $idprof1 = '', $tvaIntra = '', $typent = '') use ($db) {
		$s = new Societe($db);
		$s->name = $name;
		$s->country_code = $country;
		$s->zip = $zip;
		$s->town = $town;
		$s->address = $address;
		$s->idprof1 = $idprof1;
		$s->tva_intra = $tvaIntra;
		$s->typent_code = $typent;
		return $s;
	};

	$mkLine = function ($type, $label, $qty, $pu, $vatRate, $remise = 0, $vatCode = '', $mcTx = 0) {
		$l = new FactureLigne(null);
		$l->product_type = $type;
		$l->product_label = $label;
		$l->desc = '';
		$l->qty = $qty;
		$l->remise_percent = $remise;
		$l->tva_tx = $vatRate;
		$l->vat_src_code = $vatCode;
		$net = round($qty * $pu * (1 - $remise / 100), 2);
		$vat = round($net * $vatRate / 100, 2);
		if ($mcTx > 0) {
			// $pu is in foreign currency, HUF = foreign / tx
			$l->multicurrency_subprice = $pu;
			$l->multicurrency_total_ht = $net;
			$l->multicurrency_total_tva = $vat;
			$l->multicurrency_total_ttc = $net + $vat;
			$l->subprice = round($pu / $mcTx, 2);
			$l->total_ht = round($net / $mcTx, 2);
			$l->total_tva = round($vat / $mcTx, 2);
			$l->total_ttc = $l->total_ht + $l->total_tva;
		} else {
			$l->subprice = $pu;
			$l->total_ht = $net;
			$l->total_tva = $vat;
			$l->total_ttc = $net + $vat;
		}
		return $l;
	};

	$mkInvoice = function ($ref, $type, $soc, $lines, $currency = 'HUF', $tx = 1) use ($db) {
		$f = new Facture($db);
		$f->id = 0;
		$f->ref = $ref;
		$f->type = $type;
		$f->date = dol_mktime(12, 0, 0, 11, 20, 2026);
		$f->date_pointoftax = dol_mktime(12, 0, 0, 11, 18, 2026);
		$f->date_lim_reglement = dol_mktime(12, 0, 0, 12, 5, 2026);
		$f->mode_reglement_code = 'VIR';
		$f->multicurrency_code = $currency;
		$f->multicurrency_tx = $tx;
		$f->thirdparty = $soc;
		$f->lines = $lines;
		return $f;
	};

	$domestic = $mkSoc('Beszerző Kft.', 'HU', '7600', 'Pécs', 'Északi sugárút 123.', '99887764-2-02');
	$private = $mkSoc('Kiss János', 'HU', '1111', 'Budapest', 'Fő utca 1.', '', '', 'TE_PRIVATE');
	$german = $mkSoc('Käufer GmbH', 'DE', '10115', 'Berlin', 'Hauptstraße 5', '', 'DE888888888');
	$german->country_id = 5;
	$us = $mkSoc('Buyer Inc.', 'US', '10001', 'New York', '5th Avenue 1', '12-3456789');
	$noTax = $mkSoc('Adószám Nélküli Kft.', 'HU', '1000', 'Budapest', 'Valahol 1.');

	$fail = 0;
	$cases = array(
		array('Domestic B2B, 27% + 5% + 18%, line discount', $mkInvoice('TST-2026-0001', 0, $domestic, array(
			$mkLine(1, 'Helyszíni szaktanácsadás', 12, 70000, 27),
			$mkLine(0, 'Mezőgazdasági szakkönyv', 100, 2000, 5, 10),
			$mkLine(0, 'Ízesített tej', 2000, 80, 18),
		)), array()),
		array('Private person, 27%', $mkInvoice('TST-2026-0002', 0, $private, array(
			$mkLine(0, 'Termék', 2, 12500, 27),
		)), array()),
		array('EU customer, EUR, KBAET exemption', $mkInvoice('TST-2026-0003', 0, $german, array(
			$mkLine(0, 'Export product', 10, 150.5, 0, 0, 'KBAET', 0.0025),
		), 'EUR', 0.0025), array()),
		array('Third country, ATK + DRC + TAM', $mkInvoice('TST-2026-0004', 0, $us, array(
			$mkLine(1, 'Consulting outside scope', 1, 500000, 0, 0, 'ATK'),
			$mkLine(0, 'Reverse charge item', 3, 1000, 0, 0, 'DRC'),
			$mkLine(1, 'Oktatás', 10, 8000, 0, 0, 'TAM'),
		)), array()),
		array('Deposit invoice', $mkInvoice('TST-2026-0005', 3, $domestic, array(
			$mkLine(1, 'Előleg', 1, 100000, 27),
		)), array()),
		array('Credit note (MODIFY)', $mkInvoice('TST-2026-0006', 2, $domestic, array(
			$mkLine(1, 'Helyszíni szaktanácsadás', 2, -70000, 27),
		)), array('reference' => array('originalInvoiceNumber' => 'TST-2026-0001', 'modifyWithoutMaster' => false, 'modificationIndex' => 1, 'lineNumberOffset' => 3))),
	);
	// Same invoice with the real company setup as issuer (checks Home > Setup > Company data)
	$cases[] = array('Real company setup as supplier', $mkInvoice('TST-2026-0007', 0, $domestic, array(
		$mkLine(1, 'Szolgáltatás', 1, 10000, 27),
	)), array(), $mysoc);

	foreach ($cases as $c) {
		if (!navonlineCheckCase($c[0], $c[1], isset($c[3]) ? $c[3] : $supplier, $c[2], $validator, in_array('--print', $argv, true))) {
			$fail++;
		}
	}

	// STORNO: first credit note fully reversing the original -> STORNO (auto), MODIFY when mode = never
	$stornoInvoice = function () use ($mkInvoice, $mkLine, $domestic) {
		return $mkInvoice('TST-2026-0301', 2, $domestic, array(
			$mkLine(1, 'Helyszíni szaktanácsadás', 12, -70000, 27),
		));
	};
	$stornoRef = array('reference' => array('originalInvoiceNumber' => 'TST-2026-0001', 'modifyWithoutMaster' => false, 'modificationIndex' => 1, 'lineNumberOffset' => 3, 'fullReversal' => true));
	foreach (array('auto' => 'STORNO', 'never' => 'MODIFY') as $mode => $expectedOp) {
		$conf->global->NAVONLINE_STORNO_MODE = $mode;
		$b = new NavOnlineXmlBuilder($db);
		$x = $b->build($stornoInvoice(), $supplier, $stornoRef);
		$valid = $x && $validator->validateInvoiceData($x->asXML());
		$pzsOk = false;
		if ($valid) {
			try {
				$ops = new \NavOnlineInvoice\InvoiceOperations();
				$ops->useDataSchemaValidation();
				$ops->add($x, $b->operation);
				$pzsOk = true;
			} catch (Exception $e) {
				echo '      pzs: '.$e->getMessage()."\n";
			}
		}
		$ok = $valid && $pzsOk && $b->operation === $expectedOp;
		echo ($ok ? 'OK    ' : 'FAIL  ').'Full reversal credit note, mode '.$mode.' -> '.$b->operation."\n";
		if (!$ok) {
			$fail++;
		}
	}
	unset($conf->global->NAVONLINE_STORNO_MODE);

	// CDV (check digit) of Hungarian tax numbers
	foreach (array('99887764' => true, '12345676' => true, '12345678' => false, '99999999' => true, '24099917' => false, '1234567' => false) as $id => $expected) {
		$got = NavOnlineXmlBuilder::isValidHuTaxpayerId((string) $id);
		echo ($got === $expected ? 'OK    ' : 'FAIL  ').'CDV '.$id.' -> '.($got ? 'valid' : 'invalid')."\n";
		if ($got !== $expected) {
			$fail++;
		}
	}

	// Bad CDV: XML is still produced (NAV only warns), but a local warning must be raised
	$badCdv = $mkSoc('Rossz CDV Kft.', 'HU', '1000', 'Budapest', 'Teszt utca 1.', '12345678-1-02');
	$b = new NavOnlineXmlBuilder($db);
	$okXml = $b->build($mkInvoice('TST-2026-0201', 0, $badCdv, array($mkLine(0, 'X', 1, 100, 27))), $supplier);
	$hasWarn = (bool) preg_grep('/LOCAL_TAX_NUMBER_CDV: Customer/', $b->warnings);
	echo ($okXml && $hasWarn ? 'OK    ' : 'FAIL  ').'bad customer CDV -> XML built, warning raised'."\n";
	if (!($okXml && $hasWarn)) {
		$fail++;
	}

	// Expected data errors (must be rejected before XML is produced)
	$negative = array(
		array('HU company without tax number', $mkInvoice('TST-2026-0101', 0, $noTax, array($mkLine(0, 'X', 1, 100, 27)))),
		array('0% line without NAV code', $mkInvoice('TST-2026-0102', 0, $domestic, array($mkLine(0, 'X', 1, 100, 0)))),
		array('Replacement invoice (unsupported)', $mkInvoice('TST-2026-0103', 1, $domestic, array($mkLine(0, 'X', 1, 100, 27)))),
	);
	foreach ($negative as $c) {
		$builder = new NavOnlineXmlBuilder($db);
		if ($builder->build($c[1], $supplier)) {
			echo "FAIL  expected rejection: ".$c[0]."\n";
			$fail++;
		} else {
			echo "OK    rejected: ".$c[0]." -> ".$builder->errors[0]."\n";
		}
	}

	echo $fail ? "$fail case(s) failed\n" : "All cases passed\n";
	exit($fail ? 1 : 0);
}

// Real invoice
$invoice = new Facture($db);
if ($invoice->fetch((int) $arg) <= 0) {
	echo "Invoice $arg not found\n";
	exit(1);
}
$invoice->fetch_thirdparty();

$xmlString = navonlineCheckCase('Invoice '.$invoice->ref, $invoice, null, array(), $validator, !$save);
if ($xmlString === null) {
	exit(1);
}
if ($save) {
	$dir = DOL_DATA_ROOT.'/navonline/xml';
	dol_mkdir($dir);
	$file = $dir.'/'.dol_sanitizeFileName($invoice->ref).'.xml';
	file_put_contents($file, $xmlString);
	echo "Saved: $file\n";
}
exit(0);
