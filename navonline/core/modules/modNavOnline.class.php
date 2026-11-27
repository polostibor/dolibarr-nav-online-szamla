<?php
/* Copyright (C) 2026
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup   navonline     Module NavOnline
 * \brief      NAV Online Számla 3.0 integráció (csak tesztkörnyezet).
 *
 * \file       htdocs/custom/navonline/core/modules/modNavOnline.class.php
 * \ingroup    navonline
 * \brief      Module descriptor for NavOnline
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module NavOnline
 */
class modNavOnline extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Development-range id (500000+). Reserve an official id before distribution.
		$this->numero = 500120;
		$this->rights_class = 'navonline';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = 'NAV Online Számla 3.0 adatszolgáltatás (tesztkörnyezet)';
		$this->descriptionlong = $this->description;

		$this->editor_name = '';
		$this->editor_url = '';

		$this->version = 'development';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			// core/triggers/interface_99_modNavOnline_NavOnline.class.php (InterfaceNavOnline)
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 1,		// core/modules/facture/doc/pdf_sponge_hu.modules.php
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('main', 'thirdpartycard', 'invoicecard', 'propalcard', 'ordercard', 'invoicesuppliercard', 'paymentdao', 'pdfgeneration'),	// class/actions_navonline.class.php: hides partner card fields (NAVONLINE_HIDE_THIRDPARTY_FIELDS); main: hides recurring invoices (NAVONLINE_HIDE_RECURRING_INVOICES); pdfgeneration: dates with dots in the PDFs
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/navonline/temp', '/navonline/xml');

		$this->config_page_url = array('setup.php@navonline');

		$this->hidden = getDolGlobalInt('MODULE_NAVONLINE_DISABLED');
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('navonline@navonline');

		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Non-secret constants only. Credentials live in config/credentials.php (filled in manually by the user).
		$this->const = array(
			1 => array('NAVONLINE_SOFTWARE_OPERATION', 'chaine', 'LOCAL_SOFTWARE', 'NAV softwareOperation', 0, 'current', 1),
			2 => array('NAVONLINE_SOFTWARE_DEV_COUNTRY', 'chaine', 'HU', 'NAV softwareDevCountryCode', 0, 'current', 1),
			3 => array('NAVONLINE_SEND_ON_VALIDATE', 'chaine', '1', 'Send to NAV right after validation', 0, 'current', 1),
		);

		if (!isModEnabled('navonline')) {
			$conf->navonline = new stdClass();
			$conf->navonline->enabled = 0;
		}

		// "NAV Online Számla" tab on the customer invoice card
		$this->tabs = array(
			array('data' => 'invoice:+navonline:NavOnlineTab:navonline@navonline:$user->hasRight("navonline", "invoice", "read"):/navonline/invoice_nav.php?id=__ID__'),
			// "EPR" tab on the product card: EPR / product fee codes of the product
			array('data' => 'product:+navonlineepr:NavOnlineEprProductTab:navonline@navonline:$user->hasRight("produit", "lire"):/navonline/epr_product.php?id=__ID__'),
		);
		$this->dictionaries = array();
		$this->boxes = array();
		// Queue processing against the NAV test environment (disabled by default; enable in Setup > Scheduled jobs)
		$this->cronjobs = array(
			0 => array(
				'label' => 'NavOnlineSendQueue',
				'jobtype' => 'method',
				'class' => '/navonline/class/navonlinesender.class.php',
				'objectname' => 'NavOnlineSender',
				'method' => 'doScheduledJob',
				'parameters' => '',
				'comment' => 'Send pending invoices to NAV Online Számla (TEST) and query their status',
				'frequency' => 5,
				'unitfrequency' => 60,
				'status' => 0,
				'test' => 'isModEnabled("navonline")',
				'priority' => 50,
			),
			// Daily MNB exchange rates of all multicurrency currencies (MNB publishes around noon on working days)
			1 => array(
				'label' => 'NavOnlineMnbRates',
				'jobtype' => 'method',
				'class' => '/navonline/class/navonlinemnb.class.php',
				'objectname' => 'NavOnlineMnb',
				'method' => 'syncRates',
				'parameters' => '14',
				'comment' => 'Download the official MNB exchange rates (last 14 days, missing days filled in) into the multicurrency rates',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'datestart' => dol_mktime(12, 15, 0, (int) date('m'), (int) date('d'), (int) date('Y')),
				'test' => 'isModEnabled("navonline") && isModEnabled("multicurrency")',
				'priority' => 40,
			),
			// Inbound (supplier) invoices from NAV: daily list refresh of the invoices processed by NAV since the last run
			// (insDate; first run: the last 10 days), read-only, records nothing
			2 => array(
				'label' => 'NavOnlineInboundSync',
				'jobtype' => 'method',
				'class' => '/navonline/class/navonlineinbound.class.php',
				'objectname' => 'NavOnlineInbound',
				'method' => 'syncScheduled',
				'parameters' => '10',
				'comment' => 'Read the list of inbound invoices processed by NAV Online Számla since the last run (first run: last 10 days) (query only; recording needs confirmation on the NAV inbound invoices page)',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'datestart' => dol_mktime(6, 30, 0, (int) date('m'), (int) date('d'), (int) date('Y')),
				'test' => 'isModEnabled("navonline")',
				'priority' => 45,
			),
		);

		// Permissions
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Read NAV Online Számla data';
		$this->rights[$r][4] = 'invoice';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Send invoices to NAV Online Számla (test environment)';
		$this->rights[$r][4] = 'invoice';
		$this->rights[$r][5] = 'send';
		$r++;

		// Menus: Billing > Customer invoices > OSS summary
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills',
			'type' => 'left',
			'titre' => 'NavOnlineOssTab',
			'prefix' => img_picto('', 'bill', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'navonline_oss',
			'url' => '/navonline/oss.php',
			'langs' => 'navonline@navonline',
			'position' => 1100,
			'enabled' => 'isModEnabled("navonline")',
			'perms' => '$user->hasRight("navonline", "invoice", "read")',
			'target' => '',
			'user' => 0,	// internal users only
		);
		// Billing > Customer invoices > Proof of exit of export invoices (EAM)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills',
			'type' => 'left',
			'titre' => 'NavOnlineExpMenu',
			'prefix' => img_picto('', 'bill', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'navonline_export_proof',
			'url' => '/navonline/export_proof.php',
			'langs' => 'navonline@navonline',
			'position' => 1150,
			'enabled' => 'isModEnabled("navonline")',
			'perms' => '$user->hasRight("navonline", "invoice", "read")',
			'target' => '',
			'user' => 0,
		);
		// Billing > Exchange rates (MNB)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'NavOnlineRatesMenu',
			'prefix' => img_picto('', 'currency', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'navonline_rates',
			'url' => '/navonline/rates.php',
			'langs' => 'navonline@navonline',
			'position' => 1200,
			'enabled' => 'isModEnabled("navonline") && isModEnabled("multicurrency")',
			'perms' => '$user->hasRight("navonline", "invoice", "read")',
			'target' => '',
			'user' => 0,
		);
		// Billing > Supplier invoices > NAV inbound invoices
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=suppliers_bills',
			'type' => 'left',
			'titre' => 'NavOnlineInboundMenu',
			'prefix' => img_picto('', 'supplier_invoice', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'navonline_inbound',
			'url' => '/navonline/inbound.php',
			'langs' => 'navonline@navonline',
			'position' => 1300,
			'enabled' => 'isModEnabled("navonline") && (isModEnabled("supplier_invoice") || isModEnabled("fournisseur"))',
			'perms' => '$user->hasRight("navonline", "invoice", "read")',
			'target' => '',
			'user' => 0,
		);
		// Billing > Customer invoices > Adóhatósági ellenőrzési adatszolgáltatás (23/2014. NGM rendelet 11/A. §, the name is
		// prescribed); administrators only
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills',
			'type' => 'left',
			'titre' => 'NavOnlineX01Title',
			'prefix' => img_picto('', 'bill', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'navonline_x01',
			'url' => '/navonline/x01.php',
			'langs' => 'navonline@navonline',
			'position' => 1290,
			'enabled' => 'isModEnabled("navonline") && isModEnabled("invoice")',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
		// Bank > Bank statement import (camt.053 statement / account history)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=bank',
			'type' => 'left',
			'titre' => 'NavOnlineBankImportMenu',
			'prefix' => img_picto('', 'bank_account', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'bank',
			'leftmenu' => 'navonline_bankimport',
			'url' => '/navonline/bankimport.php',
			'langs' => 'navonline@navonline',
			'position' => 1310,
			'enabled' => 'isModEnabled("navonline") && isModEnabled("bank")',
			'perms' => '$user->hasRight("banque", "lire")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string    $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/navonline/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		// Shipment: pallets, for the gross weight of export shipments (net = product weights, gross = net + pallets)
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->addExtraField('navonline_pallets', 'NavOnlineExtraPallets', 'int', 100, '10', 'expedition', 0, 0, '', '', 1, '', '1', '', '', '0', 'navonline@navonline', 'isModEnabled("navonline")');
		// Customer invoice: proof of exit of an export (EAM): MRN of the export declaration(s), date of exit (export_proof.php)
		$extrafields->addExtraField('navonline_export_mrn', 'NavOnlineExtraExportMrn', 'varchar', 100, '255', 'facture', 0, 0, '', '', 1, '', '1', 'NavOnlineExtraExportMrnHelp', '', '0', 'navonline@navonline', 'isModEnabled("navonline")', 0, 0);
		$extrafields->addExtraField('navonline_exit_date', 'NavOnlineExtraExitDate', 'date', 101, '', 'facture', 0, 0, '', '', 1, '', '1', 'NavOnlineExtraExitDateHelp', '', '0', 'navonline@navonline', 'isModEnabled("navonline")', 0, 0);
		// Third party: domestic organisation that is not a VAT subject and has no tax number (NAV customerVatStatus OTHER)
		$extrafields->addExtraField('navonline_nonvat', 'NavOnlineExtraNonVat', 'boolean', 100, '', 'societe', 0, 0, '', '', 1, '', '1', 'NavOnlineExtraNonVatHelp', '', '0', 'navonline@navonline', 'isModEnabled("navonline")');
		$extrafields->addExtraField('navonline_pallet_kg', 'NavOnlineExtraPalletKg', 'double', 101, '24,4', 'expedition', 0, 0, '', '', 1, '', '1', 'Üresen: '.getDolGlobalString('NAVONLINE_PALLET_WEIGHT_KG', '25').' kg (NAVONLINE_PALLET_WEIGHT_KG)', '', '0', 'navonline@navonline', 'isModEnabled("navonline")');

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string    $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>          1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
