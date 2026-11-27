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
 * \file    htdocs/custom/navonline/export_proof.php
 * \ingroup navonline
 * \brief   Proof of exit of export invoices (EAM, Áfa tv. 98. §): the goods must leave the EU within 90 days of the
 *          supply, proven by the exit confirmation of the export declaration (MRN). Lists the validated invoices with an
 *          EAM line, their deadline (date of supply + 90 days), MRN and date of exit (invoice extrafields
 *          navonline_export_mrn, navonline_exit_date), editable here.
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/lib/navonline.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'navonline@navonline'));

if (!isModEnabled('navonline') || (!$user->admin && !$user->hasRight('navonline', 'invoice', 'read'))) {
	accessforbidden();
}
$canEdit = $user->admin || $user->hasRight('facture', 'creer');

$action = GETPOST('action', 'aZ09');
$filter = GETPOST('filter', 'aZ09') ?: 'open';
$days = 90;	// Áfa tv. 98. § (2): the goods leave the EU within 90 days of the supply
$soon = 15;

// Save MRN / date of exit of one invoice
if ($action === 'save' && $canEdit) {
	$id = GETPOSTINT('saveid');
	$inv = new Facture($db);
	if ($id > 0 && $inv->fetch($id) > 0) {
		$inv->fetch_optionals();
		$mrn = strtoupper(trim((string) preg_replace('/[^0-9A-Za-z,; ]+/', '', GETPOST('mrn_'.$id, 'alphanohtml'))));
		$mrn = trim((string) preg_replace('/\s*[,;]\s*|\s+/', ', ', $mrn), ', ');
		$exit = dol_mktime(12, 0, 0, GETPOSTINT('exit'.$id.'month'), GETPOSTINT('exit'.$id.'day'), GETPOSTINT('exit'.$id.'year'));
		$inv->array_options['options_navonline_export_mrn'] = $mrn;
		$inv->array_options['options_navonline_exit_date'] = $exit ?: '';
		if ($inv->insertExtraFields('', $user) < 0) {
			setEventMessages($inv->error, $inv->errors, 'errors');
		} else {
			setEventMessages($langs->trans('NavOnlineExpSaved', $inv->ref), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.urlencode($filter));
	exit;
}

// Validated invoices (not credit notes, not abandoned) with an EAM line
$sql = 'SELECT f.rowid, f.ref, f.datef, f.date_pointoftax, f.fk_statut, f.total_ht, f.multicurrency_code, f.multicurrency_total_ht, s.rowid AS socid, s.nom,';
$sql .= ' ef.navonline_export_mrn AS mrn, ef.navonline_exit_date AS exitdate';
$sql .= ' FROM '.MAIN_DB_PREFIX.'facture f';
$sql .= ' JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = f.fk_soc';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields ef ON ef.fk_object = f.rowid';
$sql .= ' WHERE f.entity IN ('.getEntity('invoice').') AND f.type IN (0, 3) AND f.fk_statut IN (1, 2)';
$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."facturedet d WHERE d.fk_facture = f.rowid AND d.tva_tx = 0 AND UPPER(d.vat_src_code) = 'EAM')";
$sql .= ' ORDER BY COALESCE(f.date_pointoftax, f.datef) ASC, f.ref ASC';
$resql = $db->query($sql);
$rows = array();
$count = array('open' => 0, 'soon' => 0, 'overdue' => 0, 'late' => 0, 'proven' => 0, 'badmrn' => 0);
$today = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
while ($resql && ($o = $db->fetch_object($resql))) {
	$supply = $db->jdate($o->date_pointoftax ?: $o->datef);
	$deadline = dol_time_plus_duree($supply, $days, 'd');
	$exit = $o->exitdate ? $db->jdate($o->exitdate) : 0;
	$mrn = trim((string) $o->mrn);
	$mrnOk = true;
	foreach (array_filter(array_map('trim', explode(',', $mrn))) as $m) {
		// MRN: 2 digits (year), 2 letters (country), 13 characters, 1 check character
		if (!preg_match('/^[0-9]{2}[A-Z]{2}[A-Z0-9]{13}[A-Z0-9]$/', $m)) {
			$mrnOk = false;
		}
	}
	if ($exit && $mrn !== '') {
		$status = $exit > $deadline ? 'late' : 'proven';
	} elseif ($today > $deadline) {
		$status = 'overdue';
	} elseif ((int) round(($deadline - $today) / 86400) <= $soon) {
		$status = 'soon';
	} else {
		$status = 'open';
	}
	$count[$status]++;
	if (!$mrnOk) {
		$count['badmrn']++;
	}
	$inv = new Facture($db);
	$inv->id = (int) $o->rowid;
	$inv->socid = (int) $o->socid;
	$inv->fetch_thirdparty();
	list($dest) = navonlineGoodsDestination($db, $inv);
	$rows[] = array('o' => $o, 'supply' => $supply, 'deadline' => $deadline, 'exit' => $exit, 'status' => $status, 'mrnOk' => $mrnOk, 'dest' => $dest, 'left' => (int) round(($deadline - $today) / 86400));
}

$filters = array(
	'open' => array('soon', 'overdue', 'open'),
	'problem' => array('soon', 'overdue', 'late'),
	'all' => array('open', 'soon', 'overdue', 'late', 'proven'),
);
$show = $filters[$filter] ?? $filters['open'];

llxHeader('', $langs->trans('NavOnlineExpTitle'));
print load_fiche_titre($langs->trans('NavOnlineExpTitle'), '', 'bill');
print '<div class="opacitymedium">'.$langs->trans('NavOnlineExpRules', $days, '<a href="https://taxation-customs.ec.europa.eu/online-services/online-services-and-databases-customs/mrn-follow_en" target="_blank" rel="noopener noreferrer">', '</a>').'</div><br>';

// Summary and filter
print '<div class="inline-block marginrightonly">';
foreach (array('overdue' => 'badgeneutral', 'soon' => 'badge-status1', 'open' => '', 'late' => '', 'proven' => '') as $k => $c) {
	print '<span class="marginrightonly">'.$langs->trans('NavOnlineExpSt'.ucfirst($k)).': <b>'.((int) $count[$k]).'</b></span>';
}
if ($count['badmrn']) {
	print '<span class="marginrightonly error">'.$langs->trans('NavOnlineExpBadMrnCount', (int) $count['badmrn']).'</span>';
}
print '</div><br><br>';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><select name="filter" class="flat" onchange="this.form.submit()">';
foreach (array('open', 'problem', 'all') as $k) {
	print '<option value="'.$k.'"'.($filter === $k ? ' selected' : '').'>'.$langs->trans('NavOnlineExpFilter'.ucfirst($k)).'</option>';
}
print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Refresh')).'"></form><br>';

$colors = array('overdue' => '#C0392B', 'late' => '#C0392B', 'soon' => '#B9770E', 'open' => '', 'proven' => '#1E8449');
$form = new Form($db);
if ($canEdit) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save"><input type="hidden" name="filter" value="'.dol_escape_htmltag($filter).'">';
}
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
foreach (array('Ref', 'ThirdParty', 'NavOnlineExpDest', 'DatePointOfTax', 'NavOnlineExpDeadline', 'NavOnlineExpDaysLeft', 'AmountHT', 'NavOnlineExtraExportMrn', 'NavOnlineExtraExitDate', 'Status') as $k) {
	print '<th'.(in_array($k, array('AmountHT', 'NavOnlineExpDaysLeft'), true) ? ' class="right"' : '').'>'.$langs->trans($k).'</th>';
}
if ($canEdit) {
	print '<th></th>';
}
print '</tr>';
$n = 0;
foreach ($rows as $r) {
	if (!in_array($r['status'], $show, true)) {
		continue;
	}
	$n++;
	$o = $r['o'];
	$inv = new Facture($db);
	$inv->id = (int) $o->rowid;
	$inv->ref = $o->ref;
	$inv->statut = $inv->status = (int) $o->fk_statut;
	$st = $r['status'];
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$inv->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag($o->nom).'</td>';
	print '<td>'.dol_escape_htmltag($r['dest']).'</td>';
	print '<td>'.dol_print_date($r['supply'], 'day').'</td>';
	print '<td>'.dol_print_date($r['deadline'], 'day').'</td>';
	print '<td class="right">'.($r['exit'] ? '' : (int) $r['left']).'</td>';
	print '<td class="right nowraponall">'.($o->multicurrency_code && $o->multicurrency_code !== $conf->currency ? price($o->multicurrency_total_ht, 0, $langs, 1, -1, -1, $o->multicurrency_code) : price($o->total_ht, 0, $langs, 1, -1, -1, $conf->currency)).'</td>';
	if ($canEdit) {
		print '<td><input type="text" name="mrn_'.((int) $o->rowid).'" class="minwidth200" maxlength="255" value="'.dol_escape_htmltag((string) $o->mrn).'" placeholder="26HU0000000000000A1">'.($r['mrnOk'] ? '' : '<br><span class="error small">'.$langs->trans('NavOnlineExpBadMrn').'</span>').'</td>';
		print '<td class="nowraponall">'.$form->selectDate($r['exit'] ?: -1, 'exit'.((int) $o->rowid), 0, 0, 1, '', 1, 0).'</td>';
	} else {
		print '<td>'.dol_escape_htmltag((string) $o->mrn).($r['mrnOk'] ? '' : ' <span class="error small">'.$langs->trans('NavOnlineExpBadMrn').'</span>').'</td>';
		print '<td>'.($r['exit'] ? dol_print_date($r['exit'], 'day') : '').'</td>';
	}
	print '<td class="nowraponall"'.($colors[$st] ? ' style="color:'.$colors[$st].';font-weight:bold"' : '').'>'.$langs->trans('NavOnlineExpSt'.ucfirst($st)).'</td>';
	if ($canEdit) {
		print '<td><button type="submit" name="saveid" value="'.((int) $o->rowid).'" class="button smallpaddingimp">'.dol_escape_htmltag($langs->trans('Save')).'</button></td>';
	}
	print '</tr>';
}
if (!$n) {
	print '<tr><td colspan="'.($canEdit ? 11 : 10).'"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
print '</table></div>';
if ($canEdit) {
	print '</form>';
}

llxFooter();
$db->close();
