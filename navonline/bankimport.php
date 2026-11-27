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
 * \file    htdocs/custom/navonline/bankimport.php
 * \ingroup navonline
 * \brief   Bank statement import (camt.053 statement, camt.052-like account history): upload, list of the entries,
 *          pairing with the Dolibarr bank lines, reconciliation, various payment / transfer for unmatched entries.
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/class/navonlinebankstatement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'banks', 'compta', 'navonline@navonline'));

if (!isModEnabled('navonline') || !isModEnabled('bank') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
$canWrite = $user->hasRight('banque', 'modifier');
$canReconcile = $user->hasRight('banque', 'consolidate');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$accountId = GETPOSTINT('account');
$status = GETPOSTISSET('status') ? GETPOST('status', 'alpha') : '0';
$bs = new NavOnlineBankStatement($db);
$form = new Form($db);
$self = $_SERVER['PHP_SELF'];
$importDir = DOL_DATA_ROOT.'/navonline/import';
$back = function () use ($self, &$accountId, &$status) {
	header('Location: '.$self.'?account='.((int) $accountId).'&status='.urlencode((string) $status));
	exit;
};
$statusLabels = array(
	NavOnlineBankStatement::STATUS_OPEN => array('Nyitott', 'status1'),
	NavOnlineBankStatement::STATUS_PAIRED => array('Párosítva', 'status4'),
	NavOnlineBankStatement::STATUS_RECONCILED => array('Egyeztetve', 'status6'),
	NavOnlineBankStatement::STATUS_IGNORED => array('Kihagyva', 'status9'),
);

/*
 * Actions
 */
if (in_array($action, array('upload', 'importfile'), true) && $_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
	$path = '';
	$name = '';
	if ($action === 'upload' && !empty($_FILES['bankfile']['tmp_name']) && is_uploaded_file($_FILES['bankfile']['tmp_name'])) {
		$name = dol_sanitizeFileName(basename((string) $_FILES['bankfile']['name']));
		if (!preg_match('/\.xml$/i', $name)) {
			setEventMessages('Csak XML fájl (camt.053 kivonat vagy számlatörténet) tölthető fel', null, 'errors');
			$back();
		}
		dol_mkdir($importDir);
		$path = $importDir.'/'.$name;
		$mv = dol_move_uploaded_file($_FILES['bankfile']['tmp_name'], $path, 1, 0, (int) $_FILES['bankfile']['error'], 1);
		if (!is_numeric($mv) || (int) $mv <= 0) {
			setEventMessages('A fájl feltöltése nem sikerült', null, 'errors');
			$back();
		}
	} elseif ($action === 'importfile') {
		$name = dol_sanitizeFileName(basename(GETPOST('file', 'alphanohtml')));
		$path = $importDir.'/'.$name;
	}
	if ($path === '' || !is_file($path)) {
		setEventMessages('Nincs beolvasható fájl', null, 'errors');
		$back();
	}
	$p = $bs->parse(file_get_contents($path));
	$acc = $p ? $bs->findAccount($p['account']) : null;
	if (!$p) {
		setEventMessages('A fájl nem olvasható be', $bs->errors, 'errors');
	} elseif (!$acc) {
		setEventMessages('A fájl bankszámlája nincs a Dolibarrban (vagy le van zárva): '.dol_escape_htmltag(dol_trunc($p['account'], 34)), null, 'errors');
	} else {
		$accountId = (int) $acc->id;
		$r = $bs->import($p, $acc, $name, $user);
		if (is_array($r)) {
			$msg = ($p['type'] === 'stmt' ? 'Kivonat '.$p['stmtId'] : 'Számlatörténet').' ('.$p['from'].' – '.$p['to'].', '.$acc->ref.'): '.$r['entries'].' tétel, '.$r['new'].' új, '.$r['known'].' már ismert; '.$r['paired'].' párosítva'.($r['reconciled'] ? ', '.$r['reconciled'].' egyeztetve' : '').'.';
			setEventMessages($msg, null, 'mesgs');
			$ok = NavOnlineBankStatement::balanceOk($p);
			if ($ok === false) {
				setEventMessages('A kivonat nyitóegyenlege és a tételek összege nem adja ki a záróegyenleget: a fájl hiányos lehet.', null, 'errors');
			} elseif ($ok === true) {
				setEventMessages('Kivonat egyenlege rendben: nyitó '.price($p['opening']).' + tételek = záró '.price($p['closing']).' '.$acc->currency_code.'.', null, 'mesgs');
			}
			if ($p['type'] === 'stmt' && !$canReconcile) {
				setEventMessages('Nincs jogod banki egyeztetéshez: a párosított tételek nem lettek egyeztetve.', null, 'warnings');
			}
		} else {
			setEventMessages('A beolvasás nem sikerült', $bs->errors, 'errors');
		}
	}
	$status = '0';
	$back();
}

$row = $id > 0 ? $bs->fetchRow($id) : null;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
	$accountId = (int) $row->fk_account;
	$r = 0;
	if ($action === 'pair' && GETPOSTINT('fk_bank') > 0 && (int) $row->status === NavOnlineBankStatement::STATUS_OPEN) {
		$r = $bs->pair($row, GETPOSTINT('fk_bank'), $user);
		if ($r > 0) {
			$bs->reconcilePaired((int) $row->fk_account, $user);
		}
	} elseif ($action === 'various' && (int) $row->status === NavOnlineBankStatement::STATUS_OPEN) {
		$r = $bs->createVarious($row, GETPOST('mode', 'aZ09') ?: NavOnlineBankStatement::suggestedMode($row), GETPOST('label', 'alphanohtml'), $user);
	} elseif ($action === 'transfer' && (int) $row->status === NavOnlineBankStatement::STATUS_OPEN) {
		$r = $bs->createTransfer($row, GETPOSTINT('other'), (float) price2num(GETPOST('other_amount', 'alpha')), $user);
	} elseif ($action === 'unpair' && in_array((int) $row->status, array(NavOnlineBankStatement::STATUS_PAIRED, NavOnlineBankStatement::STATUS_RECONCILED), true)) {
		$r = $bs->unpair($row);
	} elseif (in_array($action, array('ignore', 'restore'), true)) {
		$r = $bs->setStatus($id, $action === 'ignore' ? NavOnlineBankStatement::STATUS_IGNORED : NavOnlineBankStatement::STATUS_OPEN) ? 1 : -1;
	}
	if ($r > 0) {
		setEventMessages('Kész', null, 'mesgs');
	} elseif ($r < 0) {
		setEventMessages('Nem sikerült', $bs->errors, 'errors');
	}
	$back();
}
if ($action === 'repair' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite && $accountId > 0) {
	$m = $bs->pairOpen($accountId, $user);
	setEventMessages('Újrapárosítás: '.$m['paired'].' párosítva'.($m['reconciled'] ? ', '.$m['reconciled'].' egyeztetve' : ''), null, 'mesgs');
	$back();
}

/*
 * View
 */
llxHeader('', 'Bankkivonat importálása');
print load_fiche_titre('Bankkivonat importálása', '', 'bank_account');

$accounts = array();
$res = $db->query('SELECT rowid, ref, label, currency_code FROM '.$db->prefix().'bank_account WHERE clos = 0 AND entity IN ('.getEntity('bank_account').') ORDER BY ref');
while ($res && ($o = $db->fetch_object($res))) {
	$accounts[(int) $o->rowid] = $o;
}
if ($accountId <= 0 || !isset($accounts[$accountId])) {
	$accountId = (int) (getDolGlobalInt('FACTURE_RIB_NUMBER') ?: key($accounts));
}

if ($canWrite) {
	print '<div class="fichecenter"><form method="POST" action="'.$self.'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="upload">';
	print '<input type="file" name="bankfile" accept=".xml"> <input type="submit" class="button smallpaddingimp" value="Feltöltés és beolvasás">';
	print ' <span class="opacitymedium">Havi kivonat (camt.053) vagy számlatörténet (XML). A bankszámlát a fájlból ismeri fel; ugyanaz a tétel nem kerül be kétszer.</span>';
	print '</form>';
	$files = is_dir($importDir) ? dol_dir_list($importDir, 'files', 0, '\.xml$', '', 'date', SORT_DESC) : array();
	if ($files) {
		print '<div class="marginbottomonly margintoponly"><span class="opacitymedium">Fájlok az import mappában:</span> ';
		foreach (array_slice($files, 0, 10) as $f) {
			print '<form method="POST" action="'.$self.'" class="inline-block marginrightonly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="importfile"><input type="hidden" name="file" value="'.dol_escape_htmltag($f['name']).'">';
			print '<input type="submit" class="butAction smallpaddingimp" value="'.dol_escape_htmltag(dol_trunc($f['name'], 48)).'" title="Beolvasás"></form>';
		}
		print '</div>';
	}
	print '</div>';
}

// Account and status filter
print '<form method="GET" action="'.$self.'" class="marginbottomonly">';
print 'Bankszámla: <select name="account" class="flat" onchange="this.form.submit()">';
foreach ($accounts as $aid => $a) {
	print '<option value="'.$aid.'"'.($aid === $accountId ? ' selected' : '').'>'.dol_escape_htmltag($a->ref.' – '.$a->label.' ('.$a->currency_code.')').'</option>';
}
print '</select><input type="hidden" name="status" value="'.dol_escape_htmltag($status).'"></form>';
foreach (array('0' => 'Nyitott', '1' => 'Párosítva', '2' => 'Egyeztetve', '9' => 'Kihagyva', 'all' => 'Mind') as $k => $lbl) {
	$o = $db->fetch_object($db->query('SELECT COUNT(*) AS n FROM '.$db->prefix().'navonline_bankimport WHERE entity = '.((int) $conf->entity).' AND fk_account = '.$accountId.($k === 'all' ? '' : ' AND status = '.((int) $k))));
	print '<a class="'.((string) $status === (string) $k ? 'butAction' : 'butActionRefused').'" style="opacity:1" href="'.$self.'?account='.$accountId.'&status='.$k.'">'.$lbl.' ('.((int) $o->n).')</a>';
}
if ($canWrite) {
	print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="repair"><input type="hidden" name="account" value="'.$accountId.'"><input type="hidden" name="status" value="'.dol_escape_htmltag($status).'">';
	print '<input type="submit" class="butAction" value="Újrapárosítás" title="A nyitott tételek párosítása az azóta rögzített Dolibarr banki tételekkel"></form>';
}

$cur = isset($accounts[$accountId]) ? $accounts[$accountId]->currency_code : '';
$modes = array('VIR' => 'Átutalás', 'PRE' => 'Bankköltség / beszedés', 'CB' => 'Bankkártya', 'LIQ' => 'Készpénz');
$sql = 'SELECT * FROM '.$db->prefix().'navonline_bankimport WHERE entity = '.((int) $conf->entity).' AND fk_account = '.$accountId.($status === 'all' ? '' : ' AND status = '.((int) $status));
$sql .= ' ORDER BY booking_date DESC, rowid DESC';
$resql = $db->query($sql);
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Könyvelés</th><th class="right">Összeg</th><th>Partner</th><th>Közlemény</th><th>Típus</th><th>Kivonat</th><th>Dolibarr banki tétel</th><th>Állapot</th><th></th></tr>';
$num = 0;
while ($resql && ($o = $db->fetch_object($resql))) {
	$num++;
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_escape_htmltag($o->booking_date).'</td>';
	print '<td class="right nowraponall'.((float) $o->amount < 0 ? ' amountremaintopay' : ' amountpaymentcomplete').'">'.price($o->amount, 0, $langs, 1, -1, -1, $o->currency).'</td>';
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag(trim($o->counterparty.' '.$o->counterparty_account)).'">'.dol_escape_htmltag((string) $o->counterparty).($o->counterparty_account ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($o->counterparty_account).'</span>' : '').'</td>';
	print '<td class="tdoverflowmax250" title="'.dol_escape_htmltag((string) $o->remittance).'">'.dol_escape_htmltag((string) $o->remittance).'</td>';
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag((string) $o->tx_info).'">'.dol_escape_htmltag((string) $o->tx_info).'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $o->stmt_id).'</td><td class="tdoverflowmax200">';
	if ($o->fk_bank) {
		print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $o->fk_bank).'">'.img_picto('', 'bank_account', 'class="pictofixedwidth"').'#'.((int) $o->fk_bank).'</a>';
	}
	print '</td><td><span class="badge badge-'.$statusLabels[(int) $o->status][1].'">'.$statusLabels[(int) $o->status][0].'</span></td><td class="nowraponall right">';
	if ($canWrite) {
		$hidden = '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.((int) $o->rowid).'"><input type="hidden" name="status" value="'.dol_escape_htmltag($status).'">';
		if ((int) $o->status === NavOnlineBankStatement::STATUS_OPEN) {
			$cands = $bs->candidates($o);
			if ($cands) {
				print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="pair"><select name="fk_bank" class="flat maxwidth200">';
				foreach ($cands as $c) {
					print '<option value="'.$c['id'].'">'.dol_escape_htmltag(dol_print_date($c['date'], 'day').' '.dol_trunc($c['label'], 30).($c['why'] ? ' ('.$c['why'].')' : '')).'</option>';
				}
				print '</select> <input type="submit" class="butAction smallpaddingimp" value="Párosítás"></form><br>';
			}
			print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="various"><select name="mode" class="flat">';
			$sug = NavOnlineBankStatement::suggestedMode($o);
			foreach ($modes as $mc => $ml) {
				print '<option value="'.$mc.'"'.($mc === $sug ? ' selected' : '').'>'.$ml.'</option>';
			}
			print '</select> <input type="text" name="label" class="flat maxwidth150" value="'.dol_escape_htmltag(NavOnlineBankStatement::label($o)).'">';
			print ' <input type="submit" class="butAction smallpaddingimp" value="Egyéb '.((float) $o->amount < 0 ? 'kifizetés' : 'bevétel').'" title="Rögzítés a Dolibarrban egyéb '.((float) $o->amount < 0 ? 'kifizetésként' : 'bevételként').' (bankköltség, kártyás vásárlás...), párosítva"></form><br>';
			if (count($accounts) > 1) {
				print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="transfer"><select name="other" class="flat">';
				foreach ($accounts as $aid => $a) {
					if ($aid !== (int) $o->fk_account) {
						print '<option value="'.$aid.'">'.dol_escape_htmltag(((float) $o->amount > 0 ? 'innen: ' : 'ide: ').$a->ref).'</option>';
					}
				}
				print '</select> <input type="text" name="other_amount" class="flat width75 right" value="'.price(abs((float) $o->amount), 0, '', 0, -1, -1).'" title="Összeg a másik számla pénznemében (devizaszámlánál az ott könyvelt összeg)">';
				print ' <input type="submit" class="butAction smallpaddingimp" value="Átvezetés" title="Saját számlák közötti átvezetés rögzítése, párosítva"></form><br>';
			}
			print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="ignore"><input type="submit" class="butActionDelete smallpaddingimp" value="Kihagyás"></form>';
		} elseif ((int) $o->status === NavOnlineBankStatement::STATUS_IGNORED) {
			print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="restore"><input type="submit" class="butAction smallpaddingimp" value="Visszaállítás"></form>';
		} else {
			print '<form method="POST" action="'.$self.'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="unpair"><input type="submit" class="butActionDelete smallpaddingimp" value="Párosítás bontása"'.((int) $o->status === NavOnlineBankStatement::STATUS_RECONCILED ? ' title="Az egyeztetést is visszavonja"' : '').'></form>';
		}
	}
	print '</td></tr>';
}
if (!$num) {
	print '<tr><td colspan="9"><span class="opacitymedium">Nincs tétel. Töltsd fel a bank havi kivonatát (camt.053) vagy a számlatörténetet (XML).</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
