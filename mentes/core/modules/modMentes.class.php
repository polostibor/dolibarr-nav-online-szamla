<?php
/* Copyright (C) 2026
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    htdocs/custom/mentes/core/modules/modMentes.class.php
 * \ingroup mentes
 * \brief   Backup (database + documents) when a user logs out of Dolibarr.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor
 */
class modMentes extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500121;
		$this->rights_class = 'mentes';
		$this->family = 'technic';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Mentés kilépéskor: a Dolibarrból való kijelentkezéskor elindítja a "Dolibarr mentes" Windows-feladatot (adatbázis + documents mentése).';
		$this->descriptionlong = $this->description;
		$this->editor_name = '';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';
		$this->module_parts = array('triggers' => 1);
		$this->dirs = array();
		$this->config_page_url = array();
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array();
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, 0);
		$this->const = array(
			1 => array('MENTES_TASK_NAME', 'chaine', 'Dolibarr mentes', 'Windows scheduled task started on logout', 0, 'current', 1),
		);
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * @param string $options Options
	 * @return int
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * @param string $options Options
	 * @return int
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
