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
 * \file    htdocs/custom/navonline/invoice_nav.php
 * \ingroup navonline
 * \brief   "NAV Online Számla" tab of the customer invoice card.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once __DIR__.'/class/navonlinesender.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'navonline@navonline'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

$object = new Facture($db);
if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
}
if ($object->id > 0) {
	$id = $object->id;
}

// Security
$result = restrictedArea($user, 'facture', $id, '');
if (!$user->hasRight('navonline', 'invoice', 'read')) {
	accessforbidden();
}
$canSend = $user->hasRight('navonline', 'invoice', 'send');

$hookmanager->initHooks(array('invoicenavonline'));

$sender = new NavOnlineSender($db);
$previewXml = '';
$previewErrors = array();
$previewWarnings = array();
$previewOperation = '';

/*
 * Actions
 */

if ($object->id > 0) {
	$row = $sender->fetchRowByInvoice($object->id);

	if ($action === 'downloadxml' && $row && !empty($row->xml_file)) {
		$rel = (string) $row->xml_file;
		$path = DOL_DATA_ROOT.'/'.$rel;
		if (strpos($rel, 'navonline/xml/') !== 0 || strpos($rel, '..') !== false || !is_file($path)) {
			accessforbidden('Bad file');
		}
		header('Content-Type: application/xml; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.basename($path).'"');
		header('Content-Length: '.filesize($path));
		readfile($path);
		exit;
	}

	if ($canSend && $action === 'enqueue') {
		if ($sender->enqueue($object, $user) > 0) {
			setEventMessages($langs->trans('NavOnlineQueued'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('Error'), $sender->errors, 'errors');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}

	if ($canSend && $action === 'send' && $row) {
		$n = $sender->sendPending(1, (int) $row->rowid);
		setEventMessages($n > 0 ? $langs->trans('NavOnlineSent') : $langs->trans('NavOnlineNotSent'), array_merge($sender->log, $sender->errors), $n > 0 ? 'mesgs' : 'warnings');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}

	if ($canSend && $action === 'checkstatus' && $row) {
		$n = $sender->checkSent(1, (int) $row->rowid);
		$sender->sendAlerts();	// a refusal or a NAV warning found now is alerted too (the row is final, the cron will not see it)
		setEventMessages($langs->trans('NavOnlineStatusChecked'), array_merge($sender->log, $sender->errors), $n < 0 ? 'errors' : 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}

	if ($canSend && $action === 'annul' && $row && $_SERVER['REQUEST_METHOD'] === 'POST') {
		$annulCode = GETPOST('annul_code', 'aZ09');
		$annulReason = GETPOST('annul_reason', 'alphanohtml');
		if ($sender->requestAnnulment((int) $row->rowid, $annulCode, $annulReason)) {
			setEventMessages($langs->trans('NavOnlineAnnulSent'), $sender->log, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($langs->trans('NavOnlineAnnulFailed'), $sender->errors, 'errors');
		$action = 'annulform';
	}

	if ($canSend && $action === 'checkannul' && $row) {
		$n = $sender->checkAnnulments(1, (int) $row->rowid);
		setEventMessages($langs->trans('NavOnlineStatusChecked'), array_merge($sender->log, $sender->errors), $n < 0 ? 'errors' : 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}

	if ($canSend && $action === 'retry' && $row) {
		if ($sender->retry((int) $row->rowid)) {
			setEventMessages($langs->trans('NavOnlineQueued'), null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}

	if ($action === 'preview') {
		$object->fetch_thirdparty();
		$builder = new NavOnlineXmlBuilder($db);
		$xml = $builder->build($object);
		$previewWarnings = $builder->warnings;
		$previewOperation = $builder->operation;
		if ($xml) {
			$dom = dom_import_simplexml($xml)->ownerDocument;
			$dom->formatOutput = true;
			$previewXml = $dom->saveXML();
			$validator = new NavOnlineXsdValidator();
			if (!$validator->validateInvoiceData($previewXml)) {
				$previewErrors = $validator->errors;
			}
		} else {
			$previewErrors = $builder->errors;
		}
	}
}

/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('NavOnlineTab').' - '.$object->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-navonline page-invoice-nav');

if (empty($object->id)) {
	$langs->load('errors');
	print '<div class="error">'.$langs->trans('ErrorRecordNotFound').'</div>';
	llxFooter();
	exit;
}

$object->fetch_thirdparty();
$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'navonline', $langs->trans('InvoiceCustomer'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.$object->thirdparty->getNomUrl(1, 'customer').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '');

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$row = $sender->fetchRowByInvoice($object->id);

$statusLabels = array(
	0 => array('NavOnlineStatusPending', 'status1'),
	1 => array('NavOnlineStatusSent', 'status3'),
	2 => array('NavOnlineStatusDone', 'status4'),
	3 => array('NavOnlineStatusError', 'status8'),
	4 => array('NavOnlineStatusAnnulRequested', 'status3'),
	5 => array('NavOnlineStatusAnnulled', 'status6'),
	9 => array('NavOnlineStatusCancelled', 'status9'),
);

print '<table class="border tableforfield centpercent">';
$rowEnv = ($row && !empty($row->environment)) ? $row->environment : navonlineGetEnvironment();
print '<tr><td class="titlefield">'.$langs->trans('NavOnlineEnvironment').'</td><td>'.navonlineEnvironmentBadge($rowEnv).(navonlineIsDryRun() ? ' '.navonlineDryRunBadge() : '');
$envUrl = navonlineGetApiUrl($rowEnv);
print ' '.($envUrl !== '' ? '<code>'.dol_escape_htmltag($envUrl).'</code>' : '<span class="opacitymedium">'.$langs->trans('NavOnlineProdLocked').'</span>').'</td></tr>';

if (!$row) {
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.dolGetStatus($langs->trans('NavOnlineNotQueued'), '', '', 'status0', 1).'</td></tr>';
} else {
	$st = isset($statusLabels[(int) $row->status]) ? $statusLabels[(int) $row->status] : array('Unknown', 'status0');
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.dolGetStatus($langs->trans($st[0]), '', '', $st[1], 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineNavStatus').'</td><td>'.dol_escape_htmltag((string) $row->nav_status).'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineOperation').'</td><td>'.(!empty($row->operation) ? dol_escape_htmltag($row->operation) : ((int) $row->invoice_type === Facture::TYPE_CREDIT_NOTE ? 'MODIFY' : 'CREATE')).'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineTransactionId').'</td><td>'.dol_escape_htmltag((string) $row->transaction_id).'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineRequestId').'</td><td>'.dol_escape_htmltag((string) $row->request_id).'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineDateQueued').'</td><td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineDateSent').'</td><td>'.dol_print_date($db->jdate($row->date_sent), 'dayhour').'</td></tr>';
	print '<tr><td>'.$langs->trans('NavOnlineDateChecked').'</td><td>'.dol_print_date($db->jdate($row->date_checked), 'dayhour').'</td></tr>';

	if (!empty($row->error_message)) {
		print '<tr><td>'.$langs->trans('Error').'</td><td><span class="error">'.dol_nl2br(dol_escape_htmltag($row->error_message, 0, 1)).'</span></td></tr>';
	}

	print '<tr><td class="tdtop">'.$langs->trans('NavOnlineMessages').'</td><td>';
	if (empty($row->nav_messages)) {
		print '<span class="opacitymedium">'.$langs->trans('None').'</span>';
	} else {
		foreach (explode("\n", $row->nav_messages) as $msg) {
			$picto = 'info';
			$class = '';
			if (strpos($msg, 'ERROR') === 0) {
				$picto = 'error';
				$class = 'error';
			} elseif (strpos($msg, 'WARN') === 0) {
				$picto = 'warning';
				$class = 'warning';
			}
			print '<div class="'.$class.'">'.img_picto('', $picto, 'class="pictofixedwidth"').dol_escape_htmltag($msg).'</div>';
		}
	}
	print '</td></tr>';

	if (!empty($row->annul_code)) {
		// After a re-send the annulment belongs to the previous data report
		$annulCurrent = in_array((int) $row->status, array(NavOnlineSender::STATUS_ANNUL_REQUESTED, NavOnlineSender::STATUS_ANNULLED), true);
		print '<tr class="liste_titre"><td colspan="2">'.$langs->trans($annulCurrent ? 'NavOnlineAnnulment' : 'NavOnlineAnnulmentPrevious').'</td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineAnnulCode').'</td><td>'.dol_escape_htmltag($langs->trans('NavOnlineAnnulCode_'.$row->annul_code)).' <span class="opacitymedium">('.dol_escape_htmltag($row->annul_code).')</span></td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineAnnulReason').'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $row->annul_reason, 0, 1)).'</td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineAnnulStatus').'</td><td>'.dol_escape_htmltag((string) $row->annul_status);
		if ($annulCurrent && ($row->annul_status === 'VERIFICATION_PENDING' || $row->annul_status === 'RECEIVED')) {
			print ' <span class="opacitymedium">- '.$langs->trans('NavOnlineAnnulApproveHint').'</span>';
		}
		print '</td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineTransactionId').'</td><td>'.dol_escape_htmltag((string) $row->annul_transaction_id).'</td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineDateSent').'</td><td>'.dol_print_date($db->jdate($row->annul_date_sent), 'dayhour').'</td></tr>';
		print '<tr><td>'.$langs->trans('NavOnlineDateChecked').'</td><td>'.dol_print_date($db->jdate($row->annul_date_checked), 'dayhour').'</td></tr>';
		if (!empty($row->annul_messages)) {
			print '<tr><td class="tdtop">'.$langs->trans('NavOnlineMessages').'</td><td>';
			foreach (explode("\n", $row->annul_messages) as $msg) {
				print '<div>'.dol_escape_htmltag($msg).'</div>';
			}
			print '</td></tr>';
		}
	}

	if (!empty($row->xml_file)) {
		print '<tr><td>'.$langs->trans('NavOnlineXmlFile').'</td><td><a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=downloadxml&token='.newToken().'">'.img_picto('', 'download', 'class="pictofixedwidth"').dol_escape_htmltag(basename($row->xml_file)).'</a></td></tr>';
	}
}
print '</table>';
print '</div>';

print dol_get_fiche_end();

// Buttons
print '<div class="tabsAction">';
$url = $_SERVER['PHP_SELF'].'?id='.$object->id.'&token='.newToken().'&action=';
print dolGetButtonAction('', $langs->trans('NavOnlinePreviewXml'), 'default', $url.'preview', '', true);
if (!$row) {
	print dolGetButtonAction('', $langs->trans('NavOnlineEnqueue'), 'default', $url.'enqueue', '', $canSend && (int) $object->status !== Facture::STATUS_DRAFT);
} else {
	$st = (int) $row->status;
	if ($st === NavOnlineSender::STATUS_PENDING) {
		print dolGetButtonAction('', $langs->trans(navonlineIsDryRun() ? 'NavOnlineDryRunNow' : 'NavOnlineSendNow'), 'default', $url.'send', '', $canSend);
	}
	if ($st === NavOnlineSender::STATUS_SENT || $st === NavOnlineSender::STATUS_DONE) {
		print dolGetButtonAction('', $langs->trans('NavOnlineCheckStatus'), 'default', $url.'checkstatus', '', $canSend && $st === NavOnlineSender::STATUS_SENT);
	}
	if ($st === NavOnlineSender::STATUS_ERROR) {
		print dolGetButtonAction('', $langs->trans('NavOnlineRetry'), 'default', $url.'retry', '', $canSend);
	}
	if ($st === NavOnlineSender::STATUS_DONE && !navonlineIsDryRun()) {
		print dolGetButtonAction('', $langs->trans('NavOnlineAnnulButton'), 'delete', $url.'annulform', '', $canSend);
	}
	if ($st === NavOnlineSender::STATUS_ANNUL_REQUESTED) {
		print dolGetButtonAction('', $langs->trans('NavOnlineAnnulCheck'), 'default', $url.'checkannul', '', $canSend);
	}
	if ($st === NavOnlineSender::STATUS_ANNULLED) {
		print dolGetButtonAction('', $langs->trans('NavOnlineResend'), 'default', $url.'retry', '', $canSend);
	}
}
print '</div>';

// Technical annulment form
if ($canSend && $action === 'annulform' && $row && (int) $row->status === NavOnlineSender::STATUS_DONE) {
	print load_fiche_titre($langs->trans('NavOnlineAnnulButton'), '', '');
	print '<div class="warning">'.$langs->trans('NavOnlineAnnulWarning').'</div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="annul">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired"><label for="annul_code">'.$langs->trans('NavOnlineAnnulCode').'</label></td><td><select id="annul_code" name="annul_code" class="minwidth300">';
	foreach (NavOnlineSender::ANNULMENT_CODES as $code) {
		print '<option value="'.$code.'"'.(GETPOST('annul_code', 'aZ09') === $code ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('NavOnlineAnnulCode_'.$code)).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired tdtop"><label for="annul_reason">'.$langs->trans('NavOnlineAnnulReason').'</label></td><td><textarea id="annul_reason" name="annul_reason" rows="3" class="quatrevingtpercent" maxlength="1024" required>'.dol_escape_htmltag(GETPOST('annul_reason', 'alphanohtml')).'</textarea></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-delete" value="'.dol_escape_htmltag($langs->trans('NavOnlineAnnulSubmit')).'"> ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
}

// XML preview
if ($action === 'preview') {
	print load_fiche_titre($langs->trans('NavOnlinePreviewXml'), '', '');
	if ($previewErrors) {
		print '<div class="error">'.implode('<br>', array_map('dol_escape_htmltag', $previewErrors)).'</div>';
	} else {
		print '<div class="ok">'.img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('NavOnlineXsdValid').' - '.$langs->trans('NavOnlineOperation').': <strong>'.dol_escape_htmltag($previewOperation).'</strong></div>';
	}
	foreach ($previewWarnings as $w) {
		print '<div class="warning">'.img_picto('', 'warning', 'class="pictofixedwidth"').dol_escape_htmltag($w).'</div>';
	}
	if ($previewXml !== '') {
		print '<div style="max-width: 100%; overflow: auto; max-height: 500px;"><pre class="small" style="margin: 0; white-space: pre;">'.htmlspecialchars($previewXml, ENT_QUOTES, 'UTF-8').'</pre></div>';
	}
}

llxFooter();
$db->close();
