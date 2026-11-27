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
 * \file    htdocs/custom/navonline/inbound.php
 * \ingroup navonline
 * \brief   Inbound invoices from NAV Online Számla: list, refresh from NAV, record as draft supplier invoice
 *          (with a new supplier when needed, after confirmation).
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/class/navonlineinbound.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'bills', 'companies', 'suppliers', 'navonline@navonline'));

if (!isModEnabled('navonline') || (!$user->admin && !$user->hasRight('navonline', 'invoice', 'read'))) {
	accessforbidden();
}
$canRecord = isModEnabled('supplier_invoice') || isModEnabled('fournisseur');
$canRecord = $canRecord && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
$canCreateSoc = $user->hasRight('societe', 'creer');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$status = GETPOSTISSET('status') ? GETPOST('status', 'alpha') : '0';
$in = new NavOnlineInbound($db);
$form = new Form($db);
$self = $_SERVER['PHP_SELF'];

$dateTo = dol_mktime(0, 0, 0, GETPOSTINT('tomonth'), GETPOSTINT('today'), GETPOSTINT('toyear')) ?: dol_now();
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('frommonth'), GETPOSTINT('fromday'), GETPOSTINT('fromyear')) ?: dol_time_plus_duree($dateTo, -NavOnlineInbound::MAX_RANGE_DAYS + 1, 'd');

$opLabel = function ($op) {
	return array('CREATE' => 'Számla', 'MODIFY' => 'Módosító', 'STORNO' => 'Sztornó')[(string) $op] ?? (string) $op;
};

/*
 * Actions
 */
if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$r = $in->sync(dol_print_date($dateFrom, '%Y-%m-%d'), dol_print_date($dateTo, '%Y-%m-%d'));
	if (is_array($r)) {
		setEventMessages('NAV-lekérdezés kész: '.$r['found'].' számla, ebből '.$r['new'].' új', null, 'mesgs');
	} else {
		setEventMessages('NAV-lekérdezés sikertelen', $in->errors, 'errors');
	}
	header('Location: '.$self.'?status=0');
	exit;
}
if (in_array($action, array('ignore', 'restore'), true) && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$in->setStatus($id, $action === 'ignore' ? NavOnlineInbound::STATUS_IGNORED : NavOnlineInbound::STATUS_NEW);
	header('Location: '.$self.'?status='.urlencode($status));
	exit;
}
if ($action === 'record' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && $canRecord) {
	$row = $in->fetchRow($id);
	$socid = 0;
	$error = 0;
	if (!$row) {
		accessforbidden();
	}
	$db->begin();
	if (GETPOST('socmode', 'aZ09') === 'new') {
		if (!$canCreateSoc) {
			accessforbidden();
		}
		$socid = $in->createSupplier(array(
			'name' => GETPOST('soc_name', 'alphanohtml'),
			'tax_number' => GETPOST('soc_tax', 'alphanohtml'),
			'eu_vat' => GETPOST('soc_euvat', 'alphanohtml'),
			'country' => GETPOST('soc_country', 'aZ09'),
			'zip' => GETPOST('soc_zip', 'alphanohtml'),
			'town' => GETPOST('soc_town', 'alphanohtml'),
			'address' => GETPOST('soc_address', 'alphanohtml'),
			'bank_account' => GETPOST('soc_bank', 'alphanohtml'),
			'payment_mode_id' => GETPOSTINT('soc_paymode'),
		), $user);
		if ($socid <= 0) {
			$error++;
		}
	} else {
		$socid = GETPOSTINT('socid');
		if ($socid <= 0) {
			$in->errors[] = 'Válassz szállítót';
			$error++;
		} else {
			// The chosen partner becomes a supplier
			$db->query('UPDATE '.$db->prefix().'societe SET fournisseur = 1 WHERE rowid = '.((int) $socid).' AND fournisseur = 0');
		}
	}
	$links = array('reception' => GETPOST('link_reception', 'array:int'), 'order_supplier' => GETPOST('link_order', 'array:int'));
	$facid = $error ? -1 : $in->record($row, $socid, $user, $links, GETPOSTINT('standalone') === 1);
	if ($facid > 0) {
		$db->commit();
		setEventMessages('Szállítói számla rögzítve: '.$row->invoice_number, null, 'mesgs');
		if ($in->warnings) {
			setEventMessages(null, $in->warnings, 'warnings');
		}
		header('Location: '.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$facid);
		exit;
	}
	$db->rollback();
	setEventMessages('A rögzítés nem sikerült', $in->errors, 'errors');
	$action = 'confirm';
}

/*
 * View
 */
llxHeader('', 'NAV bejövő számlák', '', '', 0, 0, '', '', '', 'mod-navonline page-inbound');

// --- Confirmation page of one invoice
if ($action === 'confirm' && $id > 0) {
	$row = $in->fetchRow($id);
	if (!$row) {
		print '<div class="error">Nem található</div>';
		llxFooter();
		exit;
	}
	$inv = $in->getInvoiceData($row);
	print load_fiche_titre('NAV bejövő számla: '.dol_escape_htmltag($row->invoice_number), '<a href="'.$self.'">Vissza a listához</a>', 'bill');
	if (!$inv) {
		print '<div class="error">A számla adatai nem kérhetők le a NAV-tól: '.dol_escape_htmltag(implode('; ', $in->errors)).'</div>';
		llxFooter();
		exit;
	}
	$sup = $inv['supplier'];
	$matches = $in->matchForInvoice($inv, $row);

	print '<form method="POST" action="'.$self.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="record">';
	print '<input type="hidden" name="id" value="'.((int) $row->rowid).'">';

	// Invoice header
	print '<table class="border centpercent tableforfield">';
	$tr = function ($label, $value) {
		print '<tr><td class="titlefield">'.$label.'</td><td>'.$value.'</td></tr>';
	};
	$tr('Szállító a számlán', '<strong>'.dol_escape_htmltag($sup['name']).'</strong> &nbsp; '.dol_escape_htmltag($sup['taxNumber']).($sup['groupMemberTaxNumber'] !== '' ? ' (csoporttag: '.dol_escape_htmltag($sup['groupMemberTaxNumber']).')' : ''));
	$tr('Cím', dol_escape_htmltag(trim($sup['address']['zip'].' '.$sup['address']['town'].', '.$sup['address']['address'], ' ,')));
	$tr('Számla', dol_escape_htmltag($opLabel($row->operation)).($inv['originalInvoiceNumber'] !== '' ? ' &nbsp; eredeti számla: '.dol_escape_htmltag($inv['originalInvoiceNumber']) : ''));
	$tr('Kelte / teljesítés / fizetési határidő', dol_escape_htmltag($inv['issueDate'].' / '.$inv['deliveryDate'].' / '.$inv['paymentDate']));
	$tr('Fizetési mód', dol_escape_htmltag($inv['paymentMethod']));
	$tr('Nettó / áfa', price($inv['netAmount'], 0, $langs, 1, -1, -1, $inv['currency']).' / '.price($inv['vatAmount'], 0, $langs, 1, -1, -1, $inv['currency']).($inv['currency'] !== 'HUF' ? ' &nbsp; (árfolyam: '.price($inv['exchangeRate']).' Ft)' : ''));
	print '</table><br>';
	foreach ($inv['warnings'] as $w) {
		print '<div class="warning">'.dol_escape_htmltag($w).'</div>';
	}

	// Supplier choice
	print load_fiche_titre('Szállító', '', 'company');
	$hasMatch = !empty($matches);
	$mode = GETPOST('socmode', 'aZ09') ?: ($hasMatch ? 'existing' : 'new');
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield"><label><input type="radio" name="socmode" value="existing"'.($mode === 'existing' ? ' checked' : '').'> Meglévő partner</label></td><td>';
	if ($hasMatch) {
		print 'Felismert adószám alapján: ';
		$names = array();
		foreach ($matches as $m) {
			$s = new Societe($db);
			$s->fetch($m->rowid);
			$names[] = $s->getNomUrl(1).(!$m->fournisseur ? ' <span class="opacitymedium">(még nem szállító)</span>' : '');
		}
		print implode(', ', $names).'<br>';
		if (count($matches) > 1) {
			print '<span class="warning">Több partnernek is ez az adószáma, válaszd ki a megfelelőt.</span><br>';
		}
	}
	print $form->select_company(GETPOSTINT('socid') ?: ($hasMatch ? $matches[0]->rowid : 0), 'socid', '', 1, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';
	if ($canCreateSoc) {
		print '<tr><td><label><input type="radio" name="socmode" value="new"'.($mode === 'new' ? ' checked' : '').'> Új szállító felvétele</label></td><td>';
		print '<span class="opacitymedium">A NAV-adatok alapján előre kitöltve, szükség esetén javítsd:</span>';
		print '<table class="noborder">';
		$inp = function ($label, $name, $value, $size = 40) {
			print '<tr><td>'.$label.'</td><td><input type="text" name="'.$name.'" size="'.$size.'" value="'.dol_escape_htmltag(GETPOSTISSET($name) ? GETPOST($name, 'alphanohtml') : $value).'"></td></tr>';
		};
		$inp('Név', 'soc_name', $sup['name'], 60);
		$inp('Adószám', 'soc_tax', $sup['groupMemberTaxNumber'] !== '' ? $sup['groupMemberTaxNumber'] : $sup['taxNumber'], 16);
		$inp('Közösségi adószám', 'soc_euvat', $sup['communityVatNumber'], 16);
		$inp('Ország (kód)', 'soc_country', $sup['address']['country'] ?: 'HU', 3);
		$inp('Irányítószám', 'soc_zip', $sup['address']['zip'], 8);
		$inp('Város', 'soc_town', $sup['address']['town'], 30);
		$inp('Cím', 'soc_address', $sup['address']['address'], 60);
		$inp('Bankszámlaszám', 'soc_bank', $sup['bankAccount'], 30);
		print '<tr><td>Alapértelmezett fizetési mód</td><td>';
		$form->select_types_paiements(GETPOSTISSET('soc_paymode') ? GETPOSTINT('soc_paymode') : $in->paymentModeId($inv['paymentMethod']), 'soc_paymode', '', 0, 1);
		print '</td></tr>';
		if ($sup['groupMemberTaxNumber'] !== '') {
			print '<tr><td colspan="2" class="opacitymedium">Csoportos áfaalany: a számlán a csoportazonosító '.dol_escape_htmltag($sup['taxNumber']).'. A partner adatlapján a Csoportazonosító mezőbe is beírhatod.</td></tr>';
		}
		print '</table></td></tr>';
	}
	print '</table><br>';

	// Receptions / purchase orders of the recognised supplier that are not billed yet: link them to the invoice
	if ($hasMatch) {
		$open = $in->openDocuments((int) $matches[0]->rowid);
		if ($open['reception'] || $open['order_supplier']) {
			$picked = array('reception' => GETPOST('link_reception', 'array:int'), 'order_supplier' => GETPOST('link_order', 'array:int'));
			print load_fiche_titre('Kapcsolódó bevételezés / szállítói megrendelés', '', 'dolly');
			print '<table class="noborder centpercent"><tr class="liste_titre"><th class="width50"></th><th>Bizonylat</th><th>Dátum</th><th class="right">Nettó (Ft)</th></tr>';
			foreach (array('reception' => array('link_reception', 'Bevételezés'), 'order_supplier' => array('link_order', 'Szállítói megrendelés')) as $type => $t) {
				foreach ($open[$type] as $d) {
					$url = DOL_URL_ROOT.($type === 'reception' ? '/reception/card.php?id=' : '/fourn/commande/card.php?id=').((int) $d->rowid);
					$chk = in_array((int) $d->rowid, (array) $picked[$type]) ? ' checked' : '';
					print '<tr class="oddeven"><td class="center"><input type="checkbox" name="'.$t[0].'[]" value="'.((int) $d->rowid).'"'.$chk.'></td>';
					print '<td>'.$t[1].' <a href="'.$url.'" target="_blank">'.dol_escape_htmltag($d->ref).'</a></td>';
					print '<td>'.dol_print_date($db->jdate($d->d), 'day').'</td><td class="right nowraponall">'.price((float) $d->total_ht).'</td></tr>';
				}
			}
			print '</table>';
			print '<div class="opacitymedium">A még ki nem számlázott bizonylatai a felismert szállítónak. A bejelöltek a számlához kapcsolódnak (a bevételezés a megrendelését is hozza); a számla jóváhagyásakor egyező összegnél „Számlázva” állapotba kerülnek.</div><br>';
		}
	}

	// Modification / storno: state of the original invoice; a reducing one without original in Dolibarr (issued
	// before Dolibarr was used) can be recorded as a stand-alone credit note
	$kind = NavOnlineInbound::modificationKind($row, $inv);
	if ($kind !== '') {
		$orig = $inv['originalInvoiceNumber'] ?: (string) $row->original_invoice_number;
		$origId = $hasMatch ? $in->findOriginal($orig, (int) $matches[0]->rowid) : 0;
		print load_fiche_titre($row->operation === 'STORNO' ? 'Sztornó számla' : ($kind === 'credit' ? 'Csökkentő módosító számla' : 'Növelő módosító számla'), '', 'bill');
		print '<div class="info">Eredeti számla: <b>'.dol_escape_htmltag($orig).'</b> – ';
		if ($origId) {
			$fo = new FactureFournisseur($db);
			$fo->fetch($origId);
			print 'a Dolibarrban: '.$fo->getNomUrl(1).'. '.($kind === 'credit' ? 'Szállítói jóváíróként rögzül, ehhez kapcsolva.' : 'Szállítói számlaként rögzül, ehhez kapcsolva.');
		} elseif ($kind === 'credit') {
			print 'nincs a Dolibarrban. Ha a számla a NAV-listában szerepel, előbb azt rögzítsd. Ha a Dolibarr előtti időszakból való (pl. a Naturasoftban könyvelt számla):';
			print '<br><label><input type="checkbox" name="standalone" value="1"'.(GETPOSTINT('standalone') ? ' checked' : '').'> Rögzítés eredeti számla nélkül (önálló szállítói jóváíró)</label>';
		} else {
			print 'nincs a Dolibarrban; szállítói számlaként, kapcsolat nélkül rögzül.';
		}
		print '</div><br>';
	}

	// Advance (előleg) invoice / final invoice deducting advances
	if ($kind === '' && $inv['lines']) {
		$allAdvance = true;
		$ded = array();
		foreach ($inv['lines'] as $l) {
			if (empty($l['advance']) || $l['advanceOriginal'] !== '') {
				$allAdvance = false;
			}
			if (!empty($l['advance']) && $l['advanceOriginal'] !== '' && $l['net'] < 0) {
				$ded[$l['advanceOriginal']] = $hasMatch ? $in->findOriginal($l['advanceOriginal'], (int) $matches[0]->rowid) : 0;
			}
		}
		if ($allAdvance) {
			print '<div class="info">Előlegszámla: szállítói előlegszámlaként rögzül. A végszámla rögzítésekor a levonása ehhez kapcsolódik.</div><br>';
		} elseif ($ded) {
			print '<div class="info">Végszámla, előleg levonásával:';
			foreach ($ded as $ref => $depId) {
				print '<br>- '.dol_escape_htmltag($ref).': ';
				if ($depId) {
					$fd = new FactureFournisseur($db);
					$fd->fetch($depId);
					print $fd->getNomUrl(1).' – a levonás ennek az előlegnek a felhasználásaként rögzül';
				} else {
					print 'nincs a Dolibarrban (pl. a Dolibarr előtti időszakból), a levonás egyszerű negatív tétel lesz';
				}
			}
			print '</div><br>';
		}
	}

	// Lines
	print load_fiche_titre('Tételek', '', 'product');
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>#</th><th>Megnevezés</th><th>Cikkszám</th><th class="right">Mennyiség</th><th>Egység</th><th class="right">Egységár</th><th class="right">Nettó</th><th class="right">Áfa %</th><th>Termék</th></tr>';
	$prodSoc = $hasMatch ? (int) $matches[0]->rowid : 0;
	foreach ($inv['lines'] as $l) {
		$fk = $prodSoc ? $in->productForCode($prodSoc, $l['code']) : 0;
		$pl = '';
		if ($fk) {
			$p = new Product($db);
			$p->fetch($fk);
			$pl = $p->getNomUrl(1);
		}
		print '<tr class="oddeven"><td>'.((int) $l['number']).'</td><td>'.dol_nl2br(dol_escape_htmltag($l['description'])).'</td><td>'.dol_escape_htmltag($l['code']).'</td>';
		print '<td class="right">'.price($l['qty'], 0, $langs, 0, -1, 4).'</td><td>'.dol_escape_htmltag($l['unit']).'</td>';
		print '<td class="right nowraponall">'.price($l['unitPrice'], 0, $langs, 1, -1, 4).'</td><td class="right nowraponall">'.price($l['net'], 0, $langs, 1, -1, 2).'</td><td class="right">'.price($l['vatRate']).(!empty($l['vatCode']) ? ' ('.dol_escape_htmltag($l['vatCode']).')' : '').'</td><td>'.$pl.'</td></tr>';
	}
	print '</table>';
	print '<div class="opacitymedium">Termékhez rendelés: ha a cikkszám egyezik a termék Beszerzési árak fülén az adott szállítónál megadott szállítói cikkszámmal.</div><br>';

	print '<div class="center">';
	if ($canRecord && (int) $row->status !== NavOnlineInbound::STATUS_RECORDED) {
		print '<input type="submit" class="button button-save" value="Piszkozat szállítói számla létrehozása">';
	}
	print ' <a class="button button-cancel" href="'.$self.'">Mégse</a></div>';
	print '</form>';
	llxFooter();
	$db->close();
	exit;
}

// --- List
print load_fiche_titre('NAV bejövő számlák', '', 'bill');

print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="sync">';
print '<div class="inline-block valignmiddle">Lekérdezés a NAV-ból, számla kelte: ';
print $form->selectDate($dateFrom, 'from', 0, 0, 0, '', 1, 0);
print ' – ';
print $form->selectDate($dateTo, 'to', 0, 0, 0, '', 1, 0);
print ' <input type="submit" class="button smallpaddingimp" value="Lekérdezés"></div>';
print '<div class="opacitymedium">Csak olvasás (NAV '.(NavOnlineInbound::environment() === NAVONLINE_ENV_PROD ? 'éles' : 'teszt').' rendszer). Hosszabb időszakot 35 napos részletekben kérdez le. Automatikusan naponta frissül; rögzíteni csak megerősítés után rögzít.</div>';
print '</form><br>';

$tabs = array('0' => 'Új', '1' => 'Rögzített', '9' => 'Kihagyott', 'all' => 'Mind');
print '<div class="tabsAction" style="text-align:left">';
foreach ($tabs as $k => $label) {
	$sql = 'SELECT COUNT(*) AS n FROM '.$db->prefix().'navonline_inbound WHERE entity = '.((int) $conf->entity).($k === 'all' ? '' : ' AND status = '.((int) $k));
	$n = (int) $db->fetch_object($db->query($sql))->n;
	print '<a class="'.((string) $status === (string) $k ? 'butAction' : 'butActionRefused').'" style="opacity:1" href="'.$self.'?status='.$k.'">'.$label.' ('.$n.')</a>';
}
print '</div>';

$sql = 'SELECT * FROM '.$db->prefix().'navonline_inbound WHERE entity = '.((int) $conf->entity).($status === 'all' ? '' : ' AND status = '.((int) $status));
$sql .= ' ORDER BY issue_date DESC, rowid DESC';
$resql = $db->query($sql);

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Kelte</th><th>Szállító (NAV)</th><th>Adószám</th><th>Partner a Dolibarrban</th><th>Számlaszám</th><th>Típus</th><th>Teljesítés</th><th>Fiz. határidő</th><th class="right">Nettó</th><th class="right">Áfa</th><th>Pénznem</th><th>Szállítói számla</th><th></th></tr>';
$num = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$num++;
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_escape_htmltag($o->issue_date).'</td>';
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($o->supplier_name).'">'.dol_escape_htmltag($o->supplier_name).'</td>';
	print '<td>'.dol_escape_htmltag($o->supplier_taxid).'</td><td class="tdoverflowmax200">';
	if ($o->fk_soc) {
		$s = new Societe($db);
		$s->fetch($o->fk_soc);
		print $s->getNomUrl(1);
	} else {
		$m = $in->matchSupplier($o->supplier_taxid);
		if ($m) {
			$s = new Societe($db);
			$s->fetch($m[0]->rowid);
			print $s->getNomUrl(1).(count($m) > 1 ? ' <span class="warning">+'.(count($m) - 1).'</span>' : '');
		} else {
			print '<span class="badge badge-status1">Új szállító</span>';
		}
	}
	print '</td><td class="nowraponall">'.dol_escape_htmltag($o->invoice_number).'</td>';
	print '<td>'.dol_escape_htmltag($opLabel($o->operation)).($o->original_invoice_number ? ' <span class="opacitymedium" title="Eredeti számla">('.dol_escape_htmltag($o->original_invoice_number).')</span>' : '').'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $o->delivery_date).'</td><td class="nowraponall">'.dol_escape_htmltag((string) $o->payment_date).'</td>';
	print '<td class="right nowraponall">'.price($o->net_amount).'</td><td class="right nowraponall">'.price($o->vat_amount).'</td><td>'.dol_escape_htmltag($o->currency).'</td><td class="nowraponall">';
	if ($o->fk_facture_fourn) {
		$ff = new FactureFournisseur($db);
		if ($ff->fetch($o->fk_facture_fourn) > 0) {
			print $ff->getNomUrl(1).' '.$ff->getLibStatut(3);
		}
	}
	print '</td><td class="nowraponall right">';
	if ((int) $o->status === NavOnlineInbound::STATUS_NEW) {
		if ($canRecord) {
			print '<a class="butAction smallpaddingimp" href="'.$self.'?action=confirm&id='.((int) $o->rowid).'">Rögzítés</a>';
		}
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="ignore"><input type="hidden" name="status" value="'.dol_escape_htmltag($status).'"><input type="hidden" name="id" value="'.((int) $o->rowid).'"><input type="submit" class="butActionDelete smallpaddingimp" value="Kihagyás" title="Nem kerül a Dolibarrba (pl. nem nekünk szól)"></form>';
	} elseif ((int) $o->status === NavOnlineInbound::STATUS_IGNORED) {
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="restore"><input type="hidden" name="status" value="'.dol_escape_htmltag($status).'"><input type="hidden" name="id" value="'.((int) $o->rowid).'"><input type="submit" class="butAction smallpaddingimp" value="Visszaállítás"></form>';
	}
	print '</td></tr>';
}
if (!$num) {
	print '<tr><td colspan="13"><span class="opacitymedium">Nincs számla. Kérdezd le a NAV-ból a fenti időszakra.</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
