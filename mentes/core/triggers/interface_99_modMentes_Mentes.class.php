<?php
/* Copyright (C) 2026
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    htdocs/custom/mentes/core/triggers/interface_99_modMentes_Mentes.class.php
 * \ingroup mentes
 * \brief   On USER_LOGOUT start the "Dolibarr mentes" Windows scheduled task (backup).
 *
 * The task runs outside the web server, so the logout is not slowed down and the backup is not
 * killed when the PHP process ends. The trigger never blocks the logout.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of the Mentes module
 */
class InterfaceMentes extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'technic';
		$this->description = 'Mentés kilépéskor (Windows-feladat indítása).';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'technic';
	}

	/**
	 * @param string       $action Event code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Langs
	 * @param Conf         $conf   Conf
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'USER_LOGOUT' || !isModEnabled('mentes')) {
			return 0;
		}
		if (stripos(PHP_OS_FAMILY, 'Windows') === false || !function_exists('exec')) {
			dol_syslog(__CLASS__.' backup on logout needs Windows and exec()', LOG_WARNING);
			return 0;
		}

		$task = getDolGlobalString('MENTES_TASK_NAME', 'Dolibarr mentes');
		if (!preg_match('/^[\w .\-]+$/u', $task)) {
			dol_syslog(__CLASS__.' invalid MENTES_TASK_NAME', LOG_ERR);
			return 0;
		}

		$out = array();
		$rc = 0;
		exec('schtasks /Run /TN '.escapeshellarg($task).' 2>&1', $out, $rc);
		dol_syslog(__CLASS__.' logout of '.$user->login.': backup task "'.$task.'" '.($rc === 0 ? 'started' : 'NOT started (rc='.$rc.')'), $rc === 0 ? LOG_INFO : LOG_ERR);

		return 0; // never block the logout
	}
}
