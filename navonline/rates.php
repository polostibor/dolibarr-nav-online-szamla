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
 * \file    htdocs/custom/navonline/rates.php
 * \ingroup navonline
 * \brief   MNB exchange rates stored in the multicurrency rates, shown the Hungarian way (1 EUR = 369,57 Ft),
 *          with the state of the daily download job and a "download now" action.
 */

$res = @include '../main.inc.php';
if (!$res) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
require_once __DIR__.'/class/navonlinemnb.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'cron', 'multicurrency', 'navonline@navonline'));

if (!isModEnabled('navonline') || !isModEnabled('multicurrency') || (!$user->admin && !$user->hasRight('navonline', 'invoice', 'read'))) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('frommonth'), GETPOSTINT('fromday'), GETPOSTINT('fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('tomonth'), GETPOSTINT('today'), GETPOSTINT('toyear'));
if (empty($dateTo)) {
	$dateTo = dol_mktime(23, 59, 59, (int) date('m'), (int) date('d'), (int) date('Y'));
}
if (empty($dateFrom)) {
	$dateFrom = dol_time_plus_duree($dateTo, -30, 'd') + 1;
}

// Download now (same as the scheduled job)
if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$mnb = new NavOnlineMnb($db);
	if ($mnb->syncRates(14) === 0) {
		setEventMessages($mnb->output, null, 'mesgs');
	} else {
		setEventMessages($mnb->error ?: implode('; ', $mnb->errors), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$main = strtoupper((string) $conf->currency);

// Currencies (main currency excluded)
$currencies = array();
$resql = $db->query('SELECT rowid, code, name FROM '.$db->prefix().'multicurrency WHERE entity IN ('.getEntity('multicurrency').") AND code <> '".$db->escape($main)."' ORDER BY code");
while ($resql && ($o = $db->fetch_object($resql))) {
	$currencies[(int) $o->rowid] = $o->code;
}

// Rates in the period, by day: [Y-m-d][code] => HUF per unit (+ one earlier day per currency for the first change)
$rates = array();
if ($currencies) {
	$sql = 'SELECT r.fk_multicurrency, r.date_sync, r.rate, r.rate_direct FROM '.$db->prefix().'multicurrency_rate r';
	$sql .= ' WHERE r.fk_multicurrency IN ('.implode(',', array_keys($currencies)).') AND r.entity IN ('.getEntity('multicurrency').')';
	$sql .= " AND r.date_sync >= '".$db->idate(dol_time_plus_duree($dateFrom, -10, 'd'))."' AND r.date_sync <= '".$db->idate($dateTo)."'";
	$sql .= ' ORDER BY r.date_sync';
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$huf = ((float) $o->rate_direct > 0) ? (float) $o->rate_direct : (((float) $o->rate > 0) ? 1 / (float) $o->rate : 0);
		if ($huf > 0) {
			$rates[dol_print_date($db->jdate($o->date_sync), '%Y-%m-%d')][$currencies[(int) $o->fk_multicurrency]] = $huf;
		}
	}
}
ksort($rates);

$fmtRate = function ($v) use ($langs) {
	$dec = $langs->transnoentitiesnoconv('SeparatorDecimal');
	$t = number_format((float) $v, 5, $dec, $langs->transnoentitiesnoconv('SeparatorThousand'));
	$t = preg_replace('/0{1,3}$/', '', $t);	// keep at least 2 decimals
	return $t;
};

llxHeader('', $langs->trans('NavOnlineRatesTitle'));
print load_fiche_titre($langs->trans('NavOnlineRatesTitle'), '', 'currency');

// Job state + download now
$job = new Cronjob($db);
$jobFound = $job->fetch(0, 'NavOnlineMnb', 'syncRates') > 0;
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('NavOnlineRatesSource').'</td><td>'.$langs->trans('NavOnlineRatesSourceText').'</td></tr>';
if ($jobFound) {
	print '<tr><td>'.$langs->trans('NavOnlineRatesJob').'</td><td>'.($job->status == Cronjob::STATUS_ENABLED ? $langs->trans('Enabled') : '<span class="error">'.$langs->trans('Disabled').'</span>');
	print ' &nbsp; | &nbsp; '.$langs->trans('LastExecutedScheduledJob').': '.($job->datelastrun ? dol_print_date($job->datelastrun, 'dayhour') : '-');
	print ' &nbsp; | &nbsp; '.$langs->trans('NextScheduledJobExecute').': '.($job->datenextrun ? dol_print_date($job->datenextrun, 'dayhour') : '-').'</td></tr>';
	if ((string) $job->lastoutput !== '') {
		print '<tr><td>'.$langs->trans('NavOnlineRatesLastResult').'</td><td class="small">'.dol_escape_htmltag(trim($job->lastoutput)).'</td></tr>';
	}
}
print '</table>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="right"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="sync">';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('NavOnlineRatesSyncNow')).'"></form><br>';

// Period filter
$form = new Form($db);
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print $langs->trans('DateStart').' '.$form->selectDate($dateFrom, 'from', 0, 0, 0, '', 1, 0).' &nbsp; ';
print $langs->trans('DateEnd').' '.$form->selectDate($dateTo, 'to', 0, 0, 0, '', 1, 0).' ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Refresh')).'"></form><br>';

if (!$currencies) {
	print '<div class="opacitymedium">'.$langs->trans('NavOnlineRatesNoCurrency').'</div>';
	llxFooter();
	$db->close();
	exit;
}

// Table: one row per day (newest first), one column per currency: "1 EUR = 369,57 Ft" + change vs previous day
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Date').'</th>';
foreach ($currencies as $code) {
	print '<th class="right">1 '.dol_escape_htmltag($code).' = … '.$langs->getCurrencySymbol($main).'</th>';
}
print '</tr>';

$days = array();
for ($t = $dateTo; $t >= $dateFrom; $t = dol_time_plus_duree($t, -1, 'd')) {
	$days[] = dol_print_date($t, '%Y-%m-%d');
}
// previous known rate for each day/currency (for the change and for days without publication)
$allDays = array_keys($rates);
$prevOf = function ($day, $code) use ($rates, $allDays) {
	$prev = null;
	foreach ($allDays as $d) {
		if ($d >= $day) {
			break;
		}
		if (isset($rates[$d][$code])) {
			$prev = $rates[$d][$code];
		}
	}
	return $prev;
};

foreach ($days as $day) {
	$ts = dol_stringtotime($day.' 12:00:00');
	$published = isset($rates[$day]);
	print '<tr class="oddeven'.($published ? '' : ' opacitymedium').'"><td class="nowraponall">'.dol_print_date($ts, 'day').' '.dol_print_date($ts, '%a').'</td>';
	foreach ($currencies as $code) {
		print '<td class="right nowraponall">';
		if (isset($rates[$day][$code])) {
			$v = $rates[$day][$code];
			$p = $prevOf($day, $code);
			print '<strong>'.$fmtRate($v).'</strong>';
			if ($p) {
				$diff = $v - $p;
				$cls = $diff > 0 ? 'error' : ($diff < 0 ? 'ok' : 'opacitymedium');
				print ' <span class="small '.$cls.'">('.($diff > 0 ? '+' : '').$fmtRate($diff).')</span>';
			}
		} else {
			$p = $prevOf($day, $code);
			print $p ? '<span title="'.dol_escape_htmltag($langs->trans('NavOnlineRatesNoPublication')).'">'.$fmtRate($p).' *</span>' : '-';
		}
		print '</td>';
	}
	print '</tr>';
}
print '</table></div>';
print '<div class="opacitymedium small">'.$langs->trans('NavOnlineRatesFootnote').'</div>';

llxFooter();
$db->close();
