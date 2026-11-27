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
 * \file    htdocs/custom/navonline/x01.php
 * \ingroup navonline
 * \brief   Adóhatósági ellenőrzési adatszolgáltatás (23/2014. (VI. 30.) NGM rendelet 11/A. §): selection by issue date or
 *          by invoice number range, preview, ZIP of invoiceData 3.0 XML files with manifest, log. Administrators only.
 *          Nothing is sent to NAV.
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/class/navonlinex01.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'bills', 'navonline@navonline'));

if (!isModEnabled('navonline') || empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$mode = GETPOST('mode', 'aZ09') === 'number' ? 'number' : 'date';
$dFrom = dol_mktime(0, 0, 0, GETPOSTINT('frommonth'), GETPOSTINT('fromday'), GETPOSTINT('fromyear'));
$dTo = dol_mktime(0, 0, 0, GETPOSTINT('tomonth'), GETPOSTINT('today'), GETPOSTINT('toyear'));
if (!$dFrom) {
	$dFrom = dol_mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'));
}
if (!$dTo) {
	$dTo = dol_now();
}
$series = strtoupper(GETPOST('series', 'aZ09'));
$refFrom = trim(GETPOST('ref_from', 'alphanohtml'));
$refTo = trim(GETPOST('ref_to', 'alphanohtml'));
$x = new NavOnlineX01($db);
$self = $_SERVER['PHP_SELF'];
$args = function () use ($mode, $dFrom, $dTo, $series, $refFrom, $refTo) {
	return $mode === 'date' ? array(dol_print_date($dFrom, '%Y-%m-%d'), dol_print_date($dTo, '%Y-%m-%d'), '') : array($refFrom, $refTo, $series);
};
$criteria = function () use ($mode, $dFrom, $dTo, $series, $refFrom, $refTo) {
	return $mode === 'date' ? 'kibocsátás dátuma: '.dol_print_date($dFrom, '%Y-%m-%d').' – '.dol_print_date($dTo, '%Y-%m-%d').' (mindkét nap beleértve)'
		: 'sorozat '.$series.', számlaszám: '.$refFrom.' – '.$refTo.' (mindkét szám beleértve)';
};
$stream = function ($path, $name) {
	if (!is_file($path)) {
		return false;
	}
	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="'.$name.'"');
	header('Content-Length: '.filesize($path));
	header('Cache-Control: private, no-store');
	readfile($path);
	exit;
};

/*
 * Actions
 */
if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	list($a1, $a2, $a3) = $args();
	$sel = $x->select($mode, $a1, $a2, $a3);
	$r = $sel ? $x->export($sel, $mode, $criteria(), $user) : null;
	if ($r) {
		$stream($r['path'], $r['name']);
	}
	setEventMessages('Az export nem készült el', $x->errors, 'errors');
	$action = 'preview';
}
if ($action === 'download' && GETPOSTINT('id') > 0) {
	$o = $db->fetch_object($db->query('SELECT zip_name, zip_sha256 FROM '.$db->prefix().'navonline_x01_export WHERE rowid = '.GETPOSTINT('id').' AND entity = '.((int) $conf->entity)));
	$path = $o ? DOL_DATA_ROOT.'/navonline/x01/'.basename((string) $o->zip_name) : '';
	if ($o && is_file($path) && hash_file('sha256', $path) === (string) $o->zip_sha256) {
		$stream($path, basename((string) $o->zip_name));
	}
	setEventMessages('A korábbi export fájlja nem található vagy megváltozott', null, 'errors');
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('NavOnlineX01Title'));
print load_fiche_titre($langs->trans('NavOnlineX01Title'), '', 'bill');
print '<div class="opacitymedium marginbottomonly">23/2014. (VI. 30.) NGM rendelet 11/A. §. A kiválasztott, kiállított számlák (jóváírással, előleggel, devizás és OSS-számlákkal, fizetési és NAV-beküldési állapottól függetlenül) kiállításkori adatai, NAV Online Számla invoiceData 3.0 formátumban, számlánként külön XML-fájlban, egy ZIP-ben, jegyzékkel. Az export nem küld adatot a NAV-nak.</div>';

print '<form method="GET" action="'.$self.'">';
print '<input type="hidden" name="action" value="preview">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Kiválasztás</td></tr>';
print '<tr class="oddeven"><td class="titlefield"><label><input type="radio" name="mode" value="date"'.($mode === 'date' ? ' checked' : '').'> Kibocsátás dátuma szerint</label></td><td>';
print $form->selectDate($dFrom, 'from', 0, 0, 0, '', 1, 0).' – '.$form->selectDate($dTo, 'to', 0, 0, 0, '', 1, 0).' <span class="opacitymedium">(a kezdő és a záró nap is beletartozik)</span></td></tr>';
$seriesList = array();
$res = $db->query('SELECT DISTINCT ref FROM '.$db->prefix().'facture WHERE entity IN ('.getEntity('invoice').') AND fk_statut > 0 AND type <> '.Facture::TYPE_PROFORMA);
while ($res && ($o = $db->fetch_object($res))) {
	$sr = NavOnlineX01::seriesOf($o->ref)[0];
	if ($sr !== '') {
		$seriesList[$sr] = $sr;
	}
}
ksort($seriesList);
print '<tr class="oddeven"><td><label><input type="radio" name="mode" value="number"'.($mode === 'number' ? ' checked' : '').'> Számlasorszám szerint</label></td><td>';
print 'Sorozat: <select name="series" class="flat">';
foreach ($seriesList ?: array('' => '') as $sr) {
	print '<option value="'.dol_escape_htmltag($sr).'"'.($sr === $series ? ' selected' : '').'>'.dol_escape_htmltag($sr).'</option>';
}
print '</select> kezdő: <input type="text" name="ref_from" class="flat width150" value="'.dol_escape_htmltag($refFrom).'" placeholder="pl. IN2701-0001">';
print ' záró: <input type="text" name="ref_to" class="flat width150" value="'.dol_escape_htmltag($refTo).'"> <span class="opacitymedium">(mindkét szám beletartozik; a sorrend a sorozaton belüli szám szerinti)</span></td></tr>';
print '</table><div class="center margintoponly"><input type="submit" class="button" value="Előnézet"></div></form>';

if ($action === 'preview') {
	list($a1, $a2, $a3) = $args();
	$sel = $x->select($mode, $a1, $a2, $a3);
	if (!$sel) {
		print '<div class="error">'.dol_escape_htmltag(implode('; ', $x->errors)).'</div>';
	} else {
		$cnt = array('ok' => 0, 'notatissue' => 0, 'incomplete' => 0, 'missing' => 0);
		$tot = 0.0;
		foreach ($sel['rows'] as $r) {
			$cnt[$r['state']]++;
			$tot += $r['snapshot'] ? (float) $r['snapshot']->total_ttc : 0;
		}
		$gapsN = array_sum(array_map('count', $sel['gaps']));
		$complete = !$cnt['incomplete'] && !$cnt['missing'] && !$gapsN;
		print '<br>'.load_fiche_titre('Előnézet: '.dol_escape_htmltag($criteria()), '', '');
		print '<div class="'.($complete ? 'ok' : 'warning').'">'.count($sel['rows']).' bizonylat, ebből exportálható '.($cnt['ok'] + $cnt['notatissue']).'; bruttó összesen '.price($tot, 0, $langs, 1, -1, -1, $conf->currency).'. ';
		print $complete ? 'A csomag teljes lesz.' : '<b>A csomag HIÁNYOS lesz</b>: '.implode(', ', array_filter(array($cnt['missing'] ? $cnt['missing'].' számlának nincs kiállításkori adata' : '', $cnt['incomplete'] ? $cnt['incomplete'].' számla adatai hiányosak' : '', $gapsN ? $gapsN.' hiányzó sorszám' : ''))).'.';
		print $cnt['notatissue'] ? ' '.$cnt['notatissue'].' számla adata nem kiállításkori (utólag készült).' : '';
		print '</div>';
		foreach ($sel['gaps'] as $sr => $nums) {
			print '<div class="warning">Hézag a(z) '.dol_escape_htmltag($sr).' sorozatban: '.dol_escape_htmltag(implode(', ', array_slice($nums, 0, 50)).(count($nums) > 50 ? ', …' : '')).'</div>';
		}
		$labels = array('ok' => array('Rendben', 'status4'), 'notatissue' => array('Nem kiállításkori', 'status1'), 'incomplete' => array('Hiányos adat', 'status8'), 'missing' => array('Nincs adat', 'status8'));
		$types = array(0 => 'Számla', 2 => 'Jóváírás', 3 => 'Előleg', 1 => 'Helyettesítő', 5 => 'Részszámla');
		print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><th>Sorszám</th><th>Kelte</th><th>Típus</th><th>Vevő</th><th class="right">Nettó</th><th class="right">ÁFA</th><th class="right">Bruttó</th><th>Pénznem</th><th>Állapot</th></tr>';
		foreach ($sel['rows'] as $r) {
			$s = $r['snapshot'];
			print '<tr class="oddeven"><td class="nowraponall"><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$r['id'].'">'.dol_escape_htmltag($r['ref']).'</a></td><td class="nowraponall">'.dol_escape_htmltag(substr($r['date'], 0, 10)).'</td>';
			print '<td>'.($types[$r['type']] ?? $r['type']).'</td><td class="tdoverflowmax200">'.dol_escape_htmltag($r['customer']).'</td>';
			foreach (array('total_ht', 'total_tva', 'total_ttc') as $k) {
				print '<td class="right nowraponall">'.($s ? price($s->$k) : '').'</td>';
			}
			print '<td>'.($s ? dol_escape_htmltag($s->currency) : '').'</td><td><span class="badge badge-'.$labels[$r['state']][1].'"'.($s && $s->errors ? ' title="'.dol_escape_htmltag($s->errors).'"' : '').'>'.$labels[$r['state']][0].'</span></td></tr>';
		}
		if (!$sel['rows']) {
			print '<tr><td colspan="9"><span class="opacitymedium">Nincs kiállított számla a kiválasztásban.</span></td></tr>';
		}
		print '</table></div>';
		if ($sel['rows']) {
			print '<form method="POST" action="'.$self.'" class="center margintoponly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="export"><input type="hidden" name="mode" value="'.$mode.'">';
			foreach (array('fromday' => dol_print_date($dFrom, '%d'), 'frommonth' => dol_print_date($dFrom, '%m'), 'fromyear' => dol_print_date($dFrom, '%Y'), 'today' => dol_print_date($dTo, '%d'), 'tomonth' => dol_print_date($dTo, '%m'), 'toyear' => dol_print_date($dTo, '%Y'), 'series' => $series, 'ref_from' => $refFrom, 'ref_to' => $refTo) as $k => $v) {
				print '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag($v).'">';
			}
			print '<input type="submit" class="button" value="Adatszolgáltatás letöltése (ZIP)"></form>';
		}
	}
}

// Log of the exports
print '<br>'.load_fiche_titre('Korábbi exportok', '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><th>Időpont</th><th>Felhasználó</th><th>Kiválasztás</th><th class="right">Bizonylat</th><th>Csomag</th><th>Fájl</th></tr>';
$res = $db->query('SELECT e.*, u.login FROM '.$db->prefix().'navonline_x01_export e LEFT JOIN '.$db->prefix().'user u ON u.rowid = e.fk_user WHERE e.entity = '.((int) $conf->entity).' ORDER BY e.rowid DESC LIMIT 20');
$n = 0;
while ($res && ($o = $db->fetch_object($res))) {
	$n++;
	print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($o->datec), 'dayhour').'</td><td>'.dol_escape_htmltag((string) $o->login).'</td><td class="tdoverflowmax300">'.dol_escape_htmltag((string) $o->criteria).'</td>';
	print '<td class="right">'.((int) $o->nb_ok).' / '.((int) $o->nb_docs).'</td><td>'.((int) $o->complete ? '<span class="badge badge-status4">Teljes</span>' : '<span class="badge badge-status8">Hiányos</span>').'</td>';
	print '<td><a href="'.$self.'?action=download&id='.((int) $o->rowid).'" title="SHA-256: '.dol_escape_htmltag((string) $o->zip_sha256).'">'.dol_escape_htmltag((string) $o->zip_name).'</a></td></tr>';
}
if (!$n) {
	print '<tr><td colspan="6"><span class="opacitymedium">Még nem készült export.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
