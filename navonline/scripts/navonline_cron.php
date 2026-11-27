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
 * \file    htdocs/custom/navonline/scripts/navonline_cron.php
 * \ingroup navonline
 * \brief   CLI runner of the NavOnline scheduled jobs, for the OS scheduler (Windows Task Scheduler / cron).
 *
 * Runs the module's jobs (NavOnlineSendQueue every 5 minutes, NavOnlineMnbRates daily) through the Dolibarr CronJob
 * class (run_jobs + reprogram_jobs), so the result, output and next run date are visible in Setup > Scheduled jobs.
 * Each enabled job runs only when it is due. Unlike scripts/cron/cron_run_jobs.php it needs no security key on the
 * command line (CLI only; access to this machine is already required).
 *
 * Usage:
 *   php navonline_cron.php [userlogin]          run every enabled and due job (default user: first active admin)
 *   php navonline_cron.php --info [--job=LABEL]  show the job state (all jobs, or one)
 *   php navonline_cron.php --enable [--job=LABEL]   enable a job (default: NavOnlineSendQueue; next run: now)
 *   php navonline_cron.php --disable [--job=LABEL]  disable a job (default: NavOnlineSendQueue)
 *   php navonline_cron.php --run --job=LABEL    run one job now, even if not due
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

require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * @var DoliDB $db
 * @var Conf $conf
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'admin', 'cron'));

if (!isModEnabled('navonline') || !isModEnabled('cron')) {
	echo "Modules NavOnline and Scheduled jobs (cron) must be enabled\n";
	exit(1);
}

// Arguments
$cmd = '';
$jobLabel = '';
$userlogin = '';
foreach (array_slice($argv, 1) as $a) {
	if (strpos($a, '--job=') === 0) {
		$jobLabel = substr($a, 6);
	} elseif (strpos($a, '--') === 0) {
		$cmd = $a;
	} else {
		$userlogin = $a;
	}
}

// Jobs of the module
$jobs = array();
$resql = $db->query("SELECT rowid, label FROM ".$db->prefix()."cronjob WHERE module_name = 'navonline' AND entity IN (0, ".((int) $conf->entity).") ORDER BY priority, rowid");
while ($resql && ($o = $db->fetch_object($resql))) {
	$jobs[$o->label] = (int) $o->rowid;
}
if (!$jobs) {
	echo "No NavOnline scheduled job found (re-enable the module)\n";
	exit(1);
}
if ($jobLabel !== '' && !isset($jobs[$jobLabel])) {
	echo "Job '$jobLabel' not found. Jobs: ".implode(', ', array_keys($jobs))."\n";
	exit(1);
}

// User the jobs run as
if ($userlogin === '') {
	$resql = $db->query('SELECT login FROM '.$db->prefix().'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1');
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj) {
		echo "No active admin user found\n";
		exit(1);
	}
	$userlogin = $obj->login;
}
if ($user->fetch(0, $userlogin) <= 0) {
	echo "User not found\n";
	exit(1);
}
$user->loadRights();

$now = dol_now();

switch ($cmd) {
	case '--enable':
	case '--disable':
		$job = new Cronjob($db);
		$job->fetch($jobs[$jobLabel !== '' ? $jobLabel : 'NavOnlineSendQueue'] ?? reset($jobs));
		$job->status = ($cmd === '--enable') ? Cronjob::STATUS_ENABLED : Cronjob::STATUS_DISABLED;
		if ($cmd === '--enable') {
			$job->datenextrun = $now;
		}
		if ($job->update($user) < 0) {
			echo 'Error: '.$job->error."\n";
			exit(1);
		}
		echo 'Job #'.$job->id.' '.$job->label.' '.($cmd === '--enable' ? 'enabled' : 'disabled')."\n";
		exit(0);

	case '--info':
		foreach ($jobs as $label => $id) {
			if ($jobLabel !== '' && $label !== $jobLabel) {
				continue;
			}
			$job = new Cronjob($db);
			$job->fetch($id);
			echo 'Job #'.$job->id.' '.$job->label.': status='.$job->status.', every '.$job->frequency.'x'.$job->unitfrequency.'s';
			echo ', next run '.dol_print_date($job->datenextrun, 'dayhour').', last run '.dol_print_date($job->datelastrun, 'dayhour');
			echo ', last result '.(string) $job->lastresult."\n";
			if ((string) $job->lastoutput !== '') {
				echo "  Last output: ".str_replace("\n", "\n  ", trim($job->lastoutput))."\n";
			}
		}
		exit(0);
}

$force = ($cmd === '--run');
if ($force && $jobLabel === '') {
	echo "--run needs --job=LABEL\n";
	exit(1);
}

$rc = 0;
foreach ($jobs as $label => $id) {
	if ($jobLabel !== '' && $label !== $jobLabel) {
		continue;
	}
	$job = new Cronjob($db);
	$job->fetch($id);
	if (!$force) {
		if ((int) $job->status !== Cronjob::STATUS_ENABLED) {
			echo "$label: disabled\n";
			continue;
		}
		if (!empty($job->datenextrun) && (int) $job->datenextrun > $now) {
			echo "$label: not due yet (next run ".dol_print_date($job->datenextrun, 'dayhour').")\n";
			continue;
		}
	}
	if (!verifCond($job->test)) {
		echo "$label: job condition not met\n";
		continue;
	}

	$result = $job->run_jobs($user->login);
	echo "$label: run_jobs ".($result < 0 ? 'KO '.$job->error : 'OK')."\n";
	if ((string) $job->lastoutput !== '') {
		echo '  '.str_replace("\n", "\n  ", trim($job->lastoutput))."\n";
	}
	if ($job->reprogram_jobs($user->login, $now) < 0) {
		echo "$label: reprogram_jobs KO ".$job->error."\n";
		$rc = 1;
		continue;
	}
	echo "$label: next run ".dol_print_date($job->datenextrun, 'dayhour')."\n";
	if ($result < 0) {
		$rc = 1;
	}
}
exit($rc);
