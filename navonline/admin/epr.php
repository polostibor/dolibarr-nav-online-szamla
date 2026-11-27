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
 * \file    htdocs/custom/navonline/admin/epr.php
 * \ingroup navonline
 * \brief   EPR / product fee codes (KT code, rate per unit, validity) and the products they are switched on for.
 *          A new rate is a new row from its first day (the old row gets its last day): invoices keep the rate of
 *          their delivery date.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/navonline.lib.php';
require_once __DIR__.'/../class/navonlineepr.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'products', 'navonline@navonline'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$P = $db->prefix();
$form = new Form($db);

if ($action === 'add' || $action === 'update') {
	$type = GETPOST('type', 'aZ09') === 'T' ? 'T' : 'E';
	$kt = trim(GETPOST('kt_code', 'alphanohtml'));
	$label = trim(GETPOST('label', 'alphanohtml'));
	$rate = (float) price2num(GETPOST('rate', 'alphanohtml'));
	$unit = trim(GETPOST('unit', 'alphanohtml')) ?: 'kg';
	$ds = GETPOST('date_startyear', 'int') ? dol_mktime(0, 0, 0, GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear')) : 0;
	$de = GETPOST('date_endyear', 'int') ? dol_mktime(0, 0, 0, GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear')) : 0;
	if ($kt === '' || $label === '' || $rate <= 0) {
		setEventMessages('A KT-kód, a megnevezés és a pozitív díjtétel kötelező', null, 'errors');
	} elseif ($ds && $de && $de < $ds) {
		setEventMessages('Az érvényesség vége nem lehet a kezdete előtt', null, 'errors');
	} else {
		$vals = "type = '".$type."', kt_code = '".$db->escape($kt)."', label = '".$db->escape($label)."', rate = ".price2num($rate).", unit = '".$db->escape($unit)."',";
		$vals .= ' note = '.(GETPOST('note', 'restricthtml') !== '' ? "'".$db->escape(GETPOST('note', 'restricthtml'))."'" : 'NULL').',';
		$vals .= ' date_start = '.($ds ? "'".$db->idate($ds)."'" : 'NULL').', date_end = '.($de ? "'".$db->idate($de)."'" : 'NULL');
		$ok = $action === 'add'
			? $db->query('INSERT INTO '.$P.'navonline_epr_code SET entity = '.((int) $conf->entity).', '.$vals.", active = 1, datec = '".$db->idate(dol_now())."'")
			: $db->query('UPDATE '.$P.'navonline_epr_code SET '.$vals.' WHERE rowid = '.GETPOSTINT('id').' AND entity = '.((int) $conf->entity));
		setEventMessages($ok ? 'Mentve' : $db->lasterror(), null, $ok ? 'mesgs' : 'errors');
	}
	$action = '';
}
if ($action === 'toggle') {
	$db->query('UPDATE '.$P.'navonline_epr_code SET active = 1 - active WHERE rowid = '.GETPOSTINT('id').' AND entity = '.((int) $conf->entity));
	$action = '';
}

$title = 'NavOnlineSetup';
llxHeader('', $langs->trans($title));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');
print dol_get_fiche_head(navonlineAdminPrepareHead(), 'epr', $langs->trans($title), -1, 'bill');

print '<div class="opacitymedium">EPR (kiterjesztett gyártói felelősség) és termékdíj kódjai. A számla jóváhagyásakor a magyar belföldi értékesítés termékeinek soraira rögzítjük a díjat: díjalap = mennyiség × a termék szorzója (díjalap egységenként, pl. kg/db), díj = díjalap × a teljesítés napján érvényes díjtétel. ';
print 'Új díjtétel: új sor az első napjától, a régi sornál add meg az utolsó napot. A jóváíró az eredeti számla díjadataival számol. A termékenkénti bekapcsolás a termékkártya EPR fülén van.</div><br>';

$edit = GETPOST('edit', 'int');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>Típus</th><th>KT-kód</th><th>Megnevezés</th><th class="right">Díjtétel (Ft)</th><th>ME</th><th>Érvényes</th><th class="right">Termékek</th><th class="center">Aktív</th><th></th></tr>';
$res = $db->query('SELECT c.*, (SELECT COUNT(*) FROM '.$P.'navonline_epr_product p WHERE p.entity = c.entity AND p.type = c.type AND p.kt_code = c.kt_code) AS nprod FROM '.$P.'navonline_epr_code c WHERE c.entity = '.((int) $conf->entity).' ORDER BY c.type, c.kt_code, c.date_start');
$codes = 0;
while ($res && ($o = $db->fetch_object($res))) {
	$codes++;
	if ((int) $edit === (int) $o->rowid) {
		print '<tr class="oddeven"><td><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.((int) $o->rowid).'"><select name="type" class="flat">';
		foreach (NavOnlineEpr::TYPES as $k => $l) {
			print '<option value="'.$k.'"'.($o->type === $k ? ' selected' : '').'>'.$l.'</option>';
		}
		print '</select></td><td><input name="kt_code" class="flat width100" value="'.dol_escape_htmltag($o->kt_code).'"></td><td><input name="label" class="flat minwidth200" value="'.dol_escape_htmltag($o->label).'"><br><textarea name="note" class="flat" rows="2" cols="50">'.dol_escape_htmltag((string) $o->note).'</textarea></td>';
		print '<td class="right"><input name="rate" class="flat width75 right" value="'.price2num((float) $o->rate).'"></td><td><input name="unit" class="flat width50" value="'.dol_escape_htmltag($o->unit).'"></td>';
		print '<td class="nowraponall">'.$form->selectDate($o->date_start ? $db->jdate($o->date_start) : -1, 'date_start', 0, 0, 1, '', 1, 0).' – '.$form->selectDate($o->date_end ? $db->jdate($o->date_end) : -1, 'date_end', 0, 0, 1, '', 1, 0).'</td>';
		print '<td class="right">'.((int) $o->nprod).'</td><td></td><td><input type="submit" class="button smallpaddingimp" value="Mentés"> <a href="'.$_SERVER['PHP_SELF'].'">Mégse</a></td></tr>';
		continue;
	}
	print '<tr class="oddeven'.($o->active ? '' : ' opacitymedium').'"><td>'.dol_escape_htmltag(NavOnlineEpr::TYPES[$o->type] ?? $o->type).'</td><td>'.dol_escape_htmltag($o->kt_code).'</td>';
	print '<td>'.dol_escape_htmltag($o->label).($o->note ? '<br><span class="opacitymedium">'.dol_escape_htmltag($o->note).'</span>' : '').'</td><td class="right">'.price((float) $o->rate).'</td><td>'.dol_escape_htmltag($o->unit).'</td>';
	print '<td class="nowraponall">'.($o->date_start ? dol_print_date($db->jdate($o->date_start), 'day') : 'kezdettől').' – '.($o->date_end ? dol_print_date($db->jdate($o->date_end), 'day') : 'visszavonásig').'</td>';
	print '<td class="right">'.((int) $o->nprod).'</td><td class="center"><a href="'.$_SERVER['PHP_SELF'].'?action=toggle&id='.((int) $o->rowid).'&token='.newToken().'">'.($o->active ? img_picto('', 'switch_on') : img_picto('', 'switch_off')).'</a></td>';
	print '<td class="right"><a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?edit='.((int) $o->rowid).'">'.img_edit().'</a></td></tr>';
}
if (!$codes) {
	print '<tr><td colspan="9"><span class="opacitymedium">Még nincs díjkód.</span></td></tr>';
}
print '</table></form><br>';

if (!$edit) {
	print load_fiche_titre('Új díjkód vagy új díjtétel', '', '');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
	print '<table class="noborder centpercent"><tr class="oddeven"><td class="titlefield">Típus</td><td><select name="type" class="flat">';
	foreach (NavOnlineEpr::TYPES as $k => $l) {
		print '<option value="'.$k.'">'.$l.'</option>';
	}
	print '</select></td></tr><tr class="oddeven"><td>KT-kód</td><td><input name="kt_code" class="flat width100"></td></tr><tr class="oddeven"><td>Megnevezés</td><td><input name="label" class="flat minwidth300"></td></tr>';
	print '<tr class="oddeven"><td>Díjtétel (Ft / ME)</td><td><input name="rate" class="flat width75 right"> / <input name="unit" class="flat width50" value="kg"></td></tr>';
	print '<tr class="oddeven"><td>Érvényes</td><td>'.$form->selectDate(-1, 'date_start', 0, 0, 1, '', 1, 0).' – '.$form->selectDate(-1, 'date_end', 0, 0, 1, '', 1, 0).' <span class="opacitymedium">(üresen: kezdettől / visszavonásig)</span></td></tr>';
	print '<tr class="oddeven"><td>Megjegyzés</td><td><textarea name="note" class="flat" rows="2" cols="60"></textarea></td></tr></table>';
	print '<div class="center"><input type="submit" class="button" value="Hozzáadás"></div></form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
