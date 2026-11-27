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
 * \file    htdocs/custom/navonline/scripts/navonline_send.php
 * \ingroup navonline
 * \brief   CLI: process the NAV queue in the environment selected in the module setup (test / production, dry-run).
 *
 * Usage:
 *   php navonline_send.php --ping          tokenExchange only (checks credentials + software data)
 *   php navonline_send.php --list          show the queue
 *   php navonline_send.php --send          send pending invoices (manageInvoice)
 *   php navonline_send.php --status        query results of sent invoices (queryTransactionStatus)
 *   php navonline_send.php --run           --send then --status
 *   php navonline_send.php --retry <rowid> put an error / annulled row back to pending
 *   php navonline_send.php --annul <rowid> <CODE> <reason>  technical annulment (manageAnnulment) of a DONE row
 *   php navonline_send.php --test-alert    send a test alert e-mail to the configured recipients
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

require_once __DIR__.'/../class/navonlinesender.class.php';

/**
 * @var DoliDB $db
 * @var Conf $conf
 */

if (!isModEnabled('navonline')) {
	echo "Module NavOnline is not enabled\n";
	exit(1);
}

$cmd = isset($argv[1]) ? $argv[1] : '';
$sender = new NavOnlineSender($db);
$rc = 0;

switch ($cmd) {
	case '--ping':
		echo 'Environment: '.navonlineEnvironmentLabel(navonlineGetEnvironment()).(navonlineIsDryRun() ? ' (dry-run active)' : '')."\n";
		echo 'Endpoint: '.(navonlineGetApiUrl() !== '' ? navonlineGetApiUrl() : '(not available - production is locked)')."\n";
		$rc = $sender->testConnection() ? 0 : 1;
		break;

	case '--list':
		$labels = array(0 => 'pending', 1 => 'sent', 2 => 'done', 3 => 'error', 4 => 'annul-req', 5 => 'annulled', 9 => 'cancelled');
		$sql = 'SELECT rowid, environment, invoice_ref, status, nav_status, transaction_id, error_message, nav_messages, annul_status, annul_messages FROM '.$db->prefix().'navonline_invoice';
		$sql .= ' WHERE entity = '.((int) $conf->entity).' ORDER BY rowid';
		echo 'Active environment: '.navonlineEnvironmentLabel(navonlineGetEnvironment()).(navonlineIsDryRun() ? ' (dry-run active)' : '')."\n";
		$resql = $db->query($sql);
		if (!$resql) {
			echo $db->lasterror()."\n";
			exit(1);
		}
		while ($o = $db->fetch_object($resql)) {
			printf("#%-4d %-4s %-20s %-9s %-10s %s\n", $o->rowid, $o->environment, $o->invoice_ref, $labels[(int) $o->status] ?? $o->status, (string) $o->nav_status, (string) $o->transaction_id);
			if (!empty($o->annul_status)) {
				echo '      '.(in_array((int) $o->status, array(4, 5), true) ? 'annulment' : 'previous annulment').': '.$o->annul_status."\n";
			}
			foreach (array_filter(array($o->error_message, $o->nav_messages, $o->annul_messages)) as $m) {
				echo '      '.str_replace("\n", "\n      ", $m)."\n";
			}
		}
		break;

	case '--send':
		$n = $sender->sendPending();
		$rc = $n < 0 ? 1 : 0;
		echo "Sent: ".max(0, $n)."\n";
		break;

	case '--status':
		$n = $sender->checkSent();
		$a = $sender->checkAnnulments();
		$sender->sendAlerts();
		$rc = ($n < 0 || $a < 0) ? 1 : 0;
		$n = max(0, $n) + max(0, $a);
		echo "Finalized: ".max(0, $n)."\n";
		break;

	case '--run':
		$rc = $sender->doScheduledJob();
		break;

	case '--test-alert':
		$rc = $sender->sendTestAlert() ? 0 : 1;
		echo $rc ? "Test alert NOT sent
" : 'Test alert sent to '.implode(', ', navonlineGetAlertRecipients())."\n";
		break;

	case '--annul':
		$rowid = isset($argv[2]) ? (int) $argv[2] : 0;
		$code = isset($argv[3]) ? strtoupper($argv[3]) : '';
		$reason = implode(' ', array_slice($argv, 4));
		if ($rowid <= 0 || $code === '' || trim($reason) === '') {
			echo "Usage: --annul <rowid> <".implode('|', NavOnlineSender::ANNULMENT_CODES)."> <reason>\n";
			exit(1);
		}
		$rc = $sender->requestAnnulment($rowid, $code, $reason) ? 0 : 1;
		break;

	case '--retry':
		$rowid = isset($argv[2]) ? (int) $argv[2] : 0;
		$ok = $rowid > 0 && $sender->retry($rowid);
		echo $ok ? "Row #$rowid re-queued\n" : "Row #$rowid not found or not in error status\n";
		$rc = $ok ? 0 : 1;
		break;

	default:
		echo "Usage: php navonline_send.php --ping | --list | --send | --status | --run | --retry <rowid> | --annul <rowid> <CODE> <reason> | --test-alert\n";
		exit(0);
}

foreach ($sender->log as $l) {
	echo "  $l\n";
}
foreach ($sender->errors as $e) {
	echo "  ERROR: $e\n";
}
exit($rc);
