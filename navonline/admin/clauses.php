<?php
/* Copyright (C) 2026 Alu-Logic Kft.
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
 * \file    htdocs/custom/navonline/admin/clauses.php
 * \ingroup navonline
 * \brief   Invoice texts per payment term: title retention clause, "Paid / Fizetve" stamp (customer invoice PDF)
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/navonline.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'bills', 'navonline@navonline'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Payment terms of the dictionary
$terms = array();
$resql = $db->query('SELECT rowid, code, libelle, nbjour, deposit_percent, active FROM '.$db->prefix().'c_payment_term WHERE entity IN ('.getEntity('c_payment_term').') ORDER BY active DESC, sortorder, rowid');
while ($resql && ($o = $db->fetch_object($resql))) {
	if (trim((string) $o->code) !== '') {
		$terms[strtoupper($o->code)] = $o;
	}
}

// Payment modes (columns of the pairing) and the pairing setting: mode code => allowed term codes, first = default
$modes = array();
$resql = $db->query('SELECT id, code, libelle FROM '.$db->prefix().'c_paiement WHERE active = 1 AND entity IN ('.getEntity('c_paiement').") AND code <> '' ORDER BY id");
while ($resql && ($o = $db->fetch_object($resql))) {
	$modes[strtoupper($o->code)] = $o;
}
$pairing = (array) json_decode(getDolGlobalString('NAVONLINE_PAYMENT_PAIRING', '{}'), true);

/*
 * Actions
 */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// Pairing: the ticked terms of each mode, the chosen default first; a mode without ticks has no rule
	$newPairing = array();
	foreach ($modes as $mcode => $m) {
		$ticked = array();
		foreach ((array) GETPOST('pair_'.$mcode, 'array:aZ09') as $code) {
			$code = strtoupper((string) $code);
			if (isset($terms[$code])) {
				$ticked[] = $code;
			}
		}
		if (!$ticked) {
			continue;
		}
		$def = strtoupper(GETPOST('pairdef_'.$mcode, 'aZ09'));
		if (!in_array($def, $ticked, true)) {
			$def = in_array((string) ($pairing[$mcode][0] ?? ''), $ticked, true) ? $pairing[$mcode][0] : $ticked[0];
		}
		$newPairing[$mcode] = array_values(array_unique(array_merge(array($def), $ticked)));
	}
	dolibarr_set_const($db, 'NAVONLINE_PAYMENT_PAIRING', json_encode($newPairing), 'chaine', 0, 'NavOnline: payment mode => allowed payment terms (first = default)', $conf->entity);
	foreach (NAVONLINE_PT_CLAUSES as $kind => $def) {
		$picked = array();
		foreach ((array) GETPOST('pt_'.$kind, 'array:aZ09') as $code) {
			$code = strtoupper((string) $code);
			if (isset($terms[$code])) {
				$picked[] = $code;
			}
		}
		dolibarr_set_const($db, $def[0], $picked ? implode(',', $picked) : '-', 'chaine', 0, 'NavOnline: payment terms with this invoice text', $conf->entity);
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */
$title = 'NavOnlineSetup';
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-navonline page-admin-clauses');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');
print dol_get_fiche_head(navonlineAdminPrepareHead(), 'clauses', $langs->trans($title), -1, 'bill');

print '<span class="opacitymedium">'.$langs->trans('NavOnlineClausesHelp').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
$modeLabel = function ($m) use ($langs) {
	$k = 'PaymentTypeShort'.$m->code;
	return ($langs->trans($k) !== $k) ? $langs->trans($k) : dol_escape_htmltag($m->libelle);
};
print '<tr class="liste_titre"><th rowspan="2">'.$langs->trans('Code').'</th><th rowspan="2">'.$langs->trans('Label').'</th><th rowspan="2">'.$langs->trans('NavOnlineClausesDue').'</th>';
print '<th class="center" colspan="'.count($modes).'">'.$langs->trans('NavOnlineClausesModes').'</th>';
print '<th class="center" colspan="2">'.$langs->trans('NavOnlineClausesInvoiceTexts').'</th></tr>';
print '<tr class="liste_titre">';
foreach ($modes as $m) {
	print '<th class="center">'.$modeLabel($m).'</th>';
}
print '<th class="center">'.$langs->trans('NavOnlineClauseRetention').'</th><th class="center">'.$langs->trans('NavOnlineClausePaid').'</th></tr>';
$on = array();
foreach (NAVONLINE_PT_CLAUSES as $kind => $def) {
	$on[$kind] = navonlinePaymentTermCodes($kind);
}
foreach ($terms as $code => $t) {
	$k = 'PaymentConditionShort'.$t->code;
	$label = ($langs->trans($k) !== $k) ? $langs->trans($k) : dol_escape_htmltag($t->libelle);
	$label = str_replace(array('%%', '__DEPOSIT_PERCENT__'), array('%', (string) (int) $t->deposit_percent), $label);
	$due = ((int) $t->nbjour > 0) ? $langs->trans('NavOnlineClausesDueDays', (int) $t->nbjour) : $langs->trans('NavOnlineClausesDueNow');
	print '<tr class="oddeven"><td><b>'.dol_escape_htmltag($t->code).'</b>'.(empty($t->active) ? ' <span class="opacitymedium">('.$langs->trans('Disabled').')</span>' : '').'</td>';
	print '<td>'.$label.'</td><td>'.$due.'</td>';
	foreach ($modes as $mcode => $m) {
		$isDef = (($pairing[$mcode][0] ?? '') === $code);
		print '<td class="center"><input type="checkbox" name="pair_'.dol_escape_htmltag($mcode).'[]" value="'.dol_escape_htmltag($code).'"'.(in_array($code, (array) ($pairing[$mcode] ?? array()), true) ? ' checked' : '').'>'.($isDef ? ' <span class="badge badge-status4" title="'.dol_escape_htmltag($langs->trans('NavOnlineClausesDefault')).'">A</span>' : '').'</td>';
	}
	foreach (array_keys(NAVONLINE_PT_CLAUSES) as $kind) {
		print '<td class="center"><input type="checkbox" name="pt_'.$kind.'[]" value="'.dol_escape_htmltag($code).'"'.(in_array($code, $on[$kind], true) ? ' checked' : '').'></td>';
	}
	print '</tr>';
}
print '</table>';
// Default term of each payment mode (among the ticked ones)
print '<br><table class="noborder"><tr class="liste_titre"><th>'.$langs->trans('NavOnlineClausesModes').'</th><th>'.$langs->trans('NavOnlineClausesDefault').'</th></tr>';
foreach ($modes as $mcode => $m) {
	print '<tr class="oddeven"><td>'.$modeLabel($m).'</td><td><select name="pairdef_'.dol_escape_htmltag($mcode).'" class="flat minwidth150"><option value="">'.$langs->trans('NavOnlineClausesNoRule').'</option>';
	foreach ($terms as $code => $t) {
		$k = 'PaymentConditionShort'.$t->code;
		$lab = str_replace(array('%%', '__DEPOSIT_PERCENT__'), array('%', (string) (int) $t->deposit_percent), ($langs->trans($k) !== $k) ? $langs->trans($k) : $t->libelle);
		print '<option value="'.dol_escape_htmltag($code).'"'.((($pairing[$mcode][0] ?? '') === $code) ? ' selected' : '').'>'.dol_escape_htmltag($code.' - '.$lab).'</option>';
	}
	print '</select></td></tr>';
}
print '</table><span class="opacitymedium">'.$langs->trans('NavOnlineClausesPairingHelp').'</span>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br>'.load_fiche_titre($langs->trans('NavOnlineClausesTexts'), '', '');
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Type').'</th><th>'.$langs->trans('NavOnlineClausesWhere').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineClauseRetention').'</td><td>'.$langs->trans('NavOnlineClauseRetentionWhere').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineClausePaid').'</td><td>'.$langs->trans('NavOnlineClausePaidWhere').'</td></tr>';
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
