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
 * \file    htdocs/custom/navonline/epr_product.php
 * \ingroup navonline
 * \brief   "EPR" tab of the product card: the EPR / product fee codes switched on for the product, with the factor
 *          (fee base per product unit, e.g. kg per piece).
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once __DIR__.'/class/navonlineepr.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('products', 'navonline@navonline'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$product = new Product($db);
if ($id <= 0 || $product->fetch($id) <= 0) {
	accessforbidden();
}
if (!$user->hasRight('produit', 'lire')) {
	accessforbidden();
}
$canEdit = $user->hasRight('produit', 'creer');
$P = $db->prefix();
$entity = (int) $conf->entity;

if ($canEdit && $action === 'add') {
	list($type, $kt) = array_pad(explode('|', GETPOST('code', 'alphanohtml'), 2), 2, '');
	$factor = (float) price2num(GETPOST('factor', 'alphanohtml'));
	if (!in_array($type, array('E', 'T'), true) || $kt === '' || $factor <= 0) {
		setEventMessages('Válassz díjkódot és adj meg pozitív szorzót', null, 'errors');
	} else {
		$ok = $db->query('INSERT INTO '.$P.'navonline_epr_product (entity, fk_product, type, kt_code, factor, datec) VALUES ('.$entity.', '.$product->id.", '".$type."', '".$db->escape($kt)."', ".price2num($factor).", '".$db->idate(dol_now())."')"
			.' ON DUPLICATE KEY UPDATE factor = VALUES(factor)');
		setEventMessages($ok ? 'Mentve' : $db->lasterror(), null, $ok ? 'mesgs' : 'errors');
	}
}
if ($canEdit && $action === 'delete') {
	$db->query('DELETE FROM '.$P.'navonline_epr_product WHERE rowid = '.GETPOSTINT('rowid').' AND fk_product = '.$product->id.' AND entity = '.$entity);
	setEventMessages('Törölve', null, 'mesgs');
}

llxHeader('', $product->ref.' - EPR');
print dol_get_fiche_head(product_prepare_head($product), 'navonlineepr', $langs->trans('Product'), -1, $product->picto);
dol_banner_tab($product, 'ref', '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>', 1, 'ref');
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$e = new NavOnlineEpr($db);
$today = dol_print_date(dol_now(), '%Y-%m-%d');
print '<br><div class="opacitymedium">A számla jóváhagyásakor (magyar belföldi értékesítés) e termék soraira a díjat rögzítjük: díjalap = mennyiség × szorzó, díj = díjalap × a teljesítés napján érvényes díjtétel. A díjkódokat és díjtételeket a NavOnline beállításainak „EPR / termékdíj” lapján kezeled.</div><br>';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>Típus</th><th>KT-kód</th><th>Megnevezés</th><th class="right">Szorzó (díjalap / '.dol_escape_htmltag($product->getLabelOfUnit('short') ?: 'egység').')</th><th class="right">Mai díjtétel</th><th class="right">Díj egységenként</th><th></th></tr>';
$rows = $e->productCodes($product->id);
foreach ($rows as $r) {
	$c = $e->codeOn($r->type, $r->kt_code, $today);
	print '<tr class="oddeven"><td>'.dol_escape_htmltag(NavOnlineEpr::TYPES[$r->type] ?? $r->type).'</td><td>'.dol_escape_htmltag($r->kt_code).'</td><td>'.($c ? dol_escape_htmltag($c->label) : '<span class="warning">nincs ma érvényes díjtétel</span>').'</td>';
	print '<td class="right">'.price2num((float) $r->factor).($c ? ' '.dol_escape_htmltag($c->unit) : '').'</td><td class="right">'.($c ? price((float) $c->rate).' Ft/'.dol_escape_htmltag($c->unit) : '–').'</td>';
	print '<td class="right">'.($c ? price((float) price2num((float) $r->factor * (float) $c->rate, 'MT')).' Ft' : '–').'</td>';
	print '<td class="right">'.($canEdit ? '<a href="'.$_SERVER['PHP_SELF'].'?id='.$product->id.'&action=delete&rowid='.((int) $r->rowid).'&token='.newToken().'">'.img_delete().'</a>' : '').'</td></tr>';
}
if (!$rows) {
	print '<tr><td colspan="7"><span class="opacitymedium">Ehhez a termékhez nincs EPR / termékdíj bekapcsolva.</span></td></tr>';
}
print '</table>';

if ($canEdit) {
	$opts = '';
	$res = $db->query('SELECT DISTINCT type, kt_code, label FROM '.$P.'navonline_epr_code WHERE entity = '.$entity.' AND active = 1 ORDER BY type, kt_code');
	while ($res && ($o = $db->fetch_object($res))) {
		$opts .= '<option value="'.dol_escape_htmltag($o->type.'|'.$o->kt_code).'">'.dol_escape_htmltag((NavOnlineEpr::TYPES[$o->type] ?? $o->type).' '.$o->kt_code.' – '.$o->label).'</option>';
	}
	print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$product->id.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="3">Díjkód bekapcsolása vagy a szorzó módosítása</td></tr><tr class="oddeven"><td>'.($opts ? '<select name="code" class="flat minwidth300">'.$opts.'</select>' : '<span class="opacitymedium">Nincs aktív díjkód.</span>').'</td>';
	print '<td>Szorzó: <input name="factor" class="flat width75 right" value="1"> <span class="opacitymedium">díjalap egységenként</span></td><td class="right"><input type="submit" class="button smallpaddingimp" value="Mentés"'.($opts ? '' : ' disabled').'></td></tr></table></form>';
}

print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
