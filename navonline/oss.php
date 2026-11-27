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
 * \file    htdocs/custom/navonline/oss.php
 * \ingroup navonline
 * \brief   Quarterly OSS summary (amounts in EUR at the ECB rate of the last day of the quarter) + CSV export.
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/class/navonlineoss.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

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

// Default: the last finished quarter
$defTs = dol_time_plus_duree(dol_now(), -3, 'm');
$year = GETPOSTINT('year') ?: (int) dol_print_date($defTs, '%Y');
$quarter = GETPOSTINT('quarter') ?: (int) ceil(((int) dol_print_date($defTs, '%m')) / 3);
$action = GETPOST('action', 'aZ09');

$oss = new NavOnlineOss($db);
$report = $oss->build($year, $quarter);

$fmt = function ($v) {
	return price2num($v, 2) == 0 ? '0.00' : number_format((float) price2num($v, 2), 2, '.', '');
};
$typeLabel = function ($t) use ($langs) {
	return (int) $t === 2 ? $langs->transnoentities('NavOnlineOssCreditNote') : ((int) $t === 3 ? $langs->transnoentities('NavOnlineOssDeposit') : $langs->transnoentities('NavOnlineOssInvoice'));
};

// CSV export (all three parts, amounts with dot decimal separator)
if ($action === 'csv' && $report) {
	$fn = 'OSS_'.$report['period'].'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$fn.'"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	$put = function ($row) use ($out) {
		fputcsv($out, $row, ';', '"', '');
	};
	$put(array($langs->transnoentities('NavOnlineOssTitle'), $report['period'], $report['start'].' - '.$report['end']));
	if ($report['ecbMain']) {
		$put(array($langs->transnoentities('NavOnlineOssEcbRate'), $report['mainCurrency'].'/EUR', $report['ecbMain']['rate'], $report['ecbMain']['date']));
	}
	$put(array());
	$put(array($langs->transnoentities('NavOnlineOssCurrentPart')));
	$put(array($langs->transnoentities('NavOnlineOssMemberState'), $langs->transnoentities('VATRate'), $langs->transnoentities('Code'), $langs->transnoentities('NavOnlineOssNetEur'), $langs->transnoentities('NavOnlineOssVatEur')));
	foreach ($report['current'] as $r) {
		$put(array($r['ms'], $fmt($r['rate']), $r['code'], $fmt($r['net']), $fmt($r['vat'])));
	}
	$put(array());
	$put(array($langs->transnoentities('NavOnlineOssCorrectionsPart')));
	$put(array($langs->transnoentities('NavOnlineOssOriginalPeriod'), $langs->transnoentities('NavOnlineOssMemberState'), $langs->transnoentities('VATRate'), $langs->transnoentities('Code'), $langs->transnoentities('NavOnlineOssNetEur'), $langs->transnoentities('NavOnlineOssVatEur')));
	foreach ($report['corrections'] as $p => $rows) {
		foreach ($rows as $r) {
			$put(array($p, $r['ms'], $fmt($r['rate']), $r['code'], $fmt($r['net']), $fmt($r['vat'])));
		}
	}
	$put(array());
	$put(array($langs->transnoentities('NavOnlineOssDetails')));
	$put(array($langs->transnoentities('Ref'), $langs->transnoentities('Type'), $langs->transnoentities('DateInvoice'), $langs->transnoentities('DatePointOfTax'), $langs->transnoentities('ThirdParty'), $langs->transnoentities('NavOnlineOssMemberState'), $langs->transnoentities('VATRate'), $langs->transnoentities('Code'), $langs->transnoentities('Currency'), $langs->transnoentities('AmountHT'), $langs->transnoentities('AmountVAT'), $langs->transnoentities('NavOnlineOssEcbRate'), $langs->transnoentities('NavOnlineOssEcbDate'), $langs->transnoentities('NavOnlineOssNetEur'), $langs->transnoentities('NavOnlineOssVatEur'), $langs->transnoentities('NavOnlineOssPart'), $langs->transnoentities('NavOnlineOssOriginalPeriod'), $langs->transnoentities('NavOnlineOssSourceInvoice')));
	foreach ($report['details'] as $d) {
		$put(array($d['ref'], $typeLabel($d['type']), dol_print_date($d['date'], 'day'), dol_print_date($d['supply'], 'day'), $d['soc'], $d['ms'], $fmt($d['rate']), $d['code'], $d['currency'], $fmt($d['net']), $fmt($d['vat']),
			$d['ecb'] ? $d['ecb']['rate'] : '1', $d['ecb'] ? $d['ecb']['date'] : '', $fmt($d['netEur']), $fmt($d['vatEur']),
			$d['section'] === 'current' ? $langs->transnoentities('NavOnlineOssCurrentPart') : $langs->transnoentities('NavOnlineOssCorrectionsPart'), $d['origPeriod'], (string) $d['srcref']));
	}
	foreach (array_merge($oss->errors, $oss->warnings) as $w) {
		$put(array('!', $w));
	}
	fclose($out);
	exit;
}

llxHeader('', $langs->trans('NavOnlineOssTitle'));
print load_fiche_titre($langs->trans('NavOnlineOssTitle'), '', 'bill');

// Period selector
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginrightonly">'.$langs->trans('Year').' <input type="number" name="year" class="width75" min="2021" max="2100" value="'.((int) $year).'"></div>';
print '<div class="inline-block marginrightonly">'.$langs->trans('NavOnlineOssQuarter').' <select name="quarter" class="flat">';
for ($q = 1; $q <= 4; $q++) {
	print '<option value="'.$q.'"'.($q === $quarter ? ' selected' : '').'>Q'.$q.'</option>';
}
print '</select></div>';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('NavOnlineOssShow')).'">';
if ($report) {
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?year='.((int) $year).'&quarter='.((int) $quarter).'&action=csv">'.$langs->trans('NavOnlineOssCsv').'</a>';
}
print '</form><br>';

foreach ($oss->errors as $e) {
	print '<div class="error">'.dol_escape_htmltag($e).'</div>';
}
if (!$report) {
	llxFooter();
	$db->close();
	exit;
}

print '<div class="opacitymedium">'.$langs->trans('NavOnlineOssRules').'</div><br>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('NavOnlineOssPeriod').'</td><td>'.$report['period'].' ('.dol_escape_htmltag($report['start'].' – '.$report['end']).')</td></tr>';
print '<tr><td>'.$langs->trans('NavOnlineOssEcbRate').'</td><td>'.($report['ecbMain'] ? '1 EUR = '.dol_escape_htmltag((string) $report['ecbMain']['rate']).' '.$report['mainCurrency'].' ('.dol_escape_htmltag($report['ecbMain']['date']).')' : '-').'</td></tr>';
print '</table><br>';

$table = function ($title, $rows, $withPeriod = false) use ($langs, $fmt) {
	print load_fiche_titre($title, '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
	if ($withPeriod) {
		print '<th>'.$langs->trans('NavOnlineOssOriginalPeriod').'</th>';
	}
	print '<th>'.$langs->trans('NavOnlineOssMemberState').'</th><th class="right">'.$langs->trans('VATRate').'</th><th>'.$langs->trans('Code').'</th>';
	print '<th class="right">'.$langs->trans('NavOnlineOssNetEur').'</th><th class="right">'.$langs->trans('NavOnlineOssVatEur').'</th></tr>';
	$tn = $tv = 0.0;
	foreach ($rows as $r) {
		print '<tr class="oddeven">';
		if ($withPeriod) {
			print '<td>'.dol_escape_htmltag($r['period']).'</td>';
		}
		print '<td>'.dol_escape_htmltag($r['ms']).'</td><td class="right">'.vatrate((string) $r['rate'], true).'</td><td>'.dol_escape_htmltag($r['code']).'</td>';
		print '<td class="right nowraponall">'.$fmt($r['net']).'</td><td class="right nowraponall">'.$fmt($r['vat']).'</td></tr>';
		$tn += $r['net'];
		$tv += $r['vat'];
	}
	if (!$rows) {
		print '<tr><td colspan="'.($withPeriod ? 6 : 5).'"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	} else {
		print '<tr class="liste_total"><td colspan="'.($withPeriod ? 4 : 3).'">'.$langs->trans('Total').'</td><td class="right nowraponall">'.$fmt($tn).'</td><td class="right nowraponall">'.$fmt($tv).'</td></tr>';
	}
	print '</table></div><br>';
};

$table($langs->trans('NavOnlineOssCurrentPart'), $report['current']);
$corrRows = array();
foreach ($report['corrections'] as $p => $rows) {
	foreach ($rows as $r) {
		$corrRows[] = array_merge($r, array('period' => $p));
	}
}
$table($langs->trans('NavOnlineOssCorrectionsPart'), $corrRows, true);

if ($oss->warnings) {
	print '<div class="warning">'.implode('<br>', array_map('dol_escape_htmltag', $oss->warnings)).'</div><br>';
}

// Details
print load_fiche_titre($langs->trans('NavOnlineOssDetails'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
foreach (array('Ref', 'Type', 'DateInvoice', 'DatePointOfTax', 'ThirdParty', 'NavOnlineOssMemberState', 'VATRate', 'Currency', 'AmountHT', 'AmountVAT', 'NavOnlineOssEcbRate', 'NavOnlineOssNetEur', 'NavOnlineOssVatEur', 'NavOnlineOssPart') as $k) {
	print '<th'.(in_array($k, array('VATRate', 'AmountHT', 'AmountVAT', 'NavOnlineOssEcbRate', 'NavOnlineOssNetEur', 'NavOnlineOssVatEur'), true) ? ' class="right"' : '').'>'.$langs->trans($k).'</th>';
}
print '</tr>';
foreach ($report['details'] as $d) {
	print '<tr class="oddeven"><td class="nowraponall">'.dol_escape_htmltag($d['ref']).'</td><td>'.dol_escape_htmltag($typeLabel($d['type'])).($d['srcref'] ? ' ('.dol_escape_htmltag($d['srcref']).')' : '').'</td>';
	print '<td>'.dol_print_date($d['date'], 'day').'</td><td>'.dol_print_date($d['supply'], 'day').'</td><td class="tdoverflowmax200">'.dol_escape_htmltag($d['soc']).'</td>';
	print '<td>'.dol_escape_htmltag($d['ms']).'</td><td class="right">'.vatrate((string) $d['rate'], true).($d['code'] ? ' ('.dol_escape_htmltag($d['code']).')' : '').'</td><td>'.dol_escape_htmltag($d['currency']).'</td>';
	print '<td class="right nowraponall">'.price($d['net']).'</td><td class="right nowraponall">'.price($d['vat']).'</td>';
	print '<td class="right nowraponall">'.($d['ecb'] ? dol_escape_htmltag($d['ecb']['rate'].' ('.$d['ecb']['date'].')') : '-').'</td>';
	print '<td class="right nowraponall">'.$fmt($d['netEur']).'</td><td class="right nowraponall">'.$fmt($d['vatEur']).'</td>';
	print '<td>'.($d['section'] === 'current' ? $langs->trans('NavOnlineOssCurrentPart') : $langs->trans('NavOnlineOssCorrectionOf', $d['origPeriod'])).'</td></tr>';
}
if (!$report['details']) {
	print '<tr><td colspan="14"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
