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
 * \file    htdocs/custom/navonline/admin/setup.php
 * \ingroup navonline
 * \brief   NavOnline setup page.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
require_once __DIR__.'/../lib/navonline.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'navonline@navonline'));

$hookmanager->initHooks(array('navonlinesetup', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');

if (!$user->admin) {
	accessforbidden();
}

// Setup form: only non-secret software data. Credentials are NOT stored in the database.
$formSetup = new FormSetup($db);

// Environment
$formSetup->newItem('NavOnlineEnvironmentSection')->setAsTitle();
$item = $formSetup->newItem('NAVONLINE_ENVIRONMENT');
$item->setAsSelect(array(
	NAVONLINE_ENV_TEST => $langs->trans('NavOnlineEnvTest'),
	NAVONLINE_ENV_PROD => $langs->trans(navonlineIsProdAvailable() ? 'NavOnlineEnvProd' : 'NavOnlineEnvProdLocked'),
));
$item->defaultFieldValue = NAVONLINE_ENV_TEST;
$item->helpText = $langs->transnoentities('NAVONLINE_ENVIRONMENT_Help');

$item = $formSetup->newItem('NAVONLINE_GOLIVE_DATE');
$item->defaultFieldValue = NAVONLINE_DEFAULT_GOLIVE_DATE;
$item->fieldAttr['placeholder'] = 'YYYY-MM-DD';
$item->fieldAttr['pattern'] = '\d{4}-\d{2}-\d{2}';
$item->helpText = $langs->transnoentities('NAVONLINE_GOLIVE_DATE_Help');

$item = $formSetup->newItem('NAVONLINE_DRY_RUN');
$item->setAsYesNo();
$item->helpText = $langs->transnoentities('NAVONLINE_DRY_RUN_Help');

$item = $formSetup->newItem('NAVONLINE_SEND_ON_VALIDATE');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->transnoentities('NAVONLINE_SEND_ON_VALIDATE_Help');

// Alerts
$formSetup->newItem('NavOnlineAlertSection')->setAsTitle();
$item = $formSetup->newItem('NAVONLINE_ALERT_EMAIL');
$item->fieldAttr['placeholder'] = 'konyveles@example.com, it@example.com';
$item->helpText = $langs->transnoentities('NAVONLINE_ALERT_EMAIL_Help');
$item->cssClass = 'minwidth500';
$item = $formSetup->newItem('NAVONLINE_ALERT_STALE_HOURS');
$item->defaultFieldValue = '12';
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->helpText = $langs->transnoentities('NAVONLINE_ALERT_STALE_HOURS_Help');

$formSetup->newItem('NavOnlineSoftwareSection')->setAsTitle();

$item = $formSetup->newItem('NAVONLINE_SOFTWARE_ID');
$item->fieldParams['isMandatory'] = 1;
$item->fieldAttr['maxlength'] = 18;
$item->fieldAttr['placeholder'] = 'HU12345678ABCDEF01';
$item->helpText = $langs->transnoentities('NAVONLINE_SOFTWARE_ID_Help');

$item = $formSetup->newItem('NAVONLINE_SOFTWARE_NAME');
$item->defaultFieldValue = 'Dolibarr';

$formSetup->newItem('NAVONLINE_SOFTWARE_OPERATION')->setAsSelect(array(
	'LOCAL_SOFTWARE' => 'LOCAL_SOFTWARE',
	'ONLINE_SERVICE' => 'ONLINE_SERVICE',
));

$item = $formSetup->newItem('NAVONLINE_SOFTWARE_MAIN_VERSION');
$item->defaultFieldValue = DOL_VERSION;

$formSetup->newItem('NAVONLINE_SOFTWARE_DEV_NAME');
$formSetup->newItem('NAVONLINE_SOFTWARE_DEV_CONTACT');

$item = $formSetup->newItem('NAVONLINE_SOFTWARE_DEV_COUNTRY');
$item->defaultFieldValue = 'HU';
$item->fieldAttr['maxlength'] = 2;

$formSetup->newItem('NAVONLINE_SOFTWARE_DEV_TAXNUMBER');

$formSetup->newItem('NavOnlineInvoiceSection')->setAsTitle();

$formSetup->newItem('NAVONLINE_INVOICE_APPEARANCE')->setAsSelect(array(
	'PAPER' => 'PAPER',
	'ELECTRONIC' => 'ELECTRONIC',
	'EDI' => 'EDI',
	'UNKNOWN' => 'UNKNOWN',
));
$item = $formSetup->newItem('NAVONLINE_STORNO_MODE');
$item->setAsSelect(array(
	'auto' => $langs->trans('NavOnlineStornoAuto'),
	'never' => $langs->trans('NavOnlineStornoNever'),
));
$item->defaultFieldValue = 'auto';
$item->helpText = $langs->transnoentities('NAVONLINE_STORNO_MODE_Help');
$formSetup->newItem('NAVONLINE_CASH_ACCOUNTING')->setAsYesNo();
$formSetup->newItem('NAVONLINE_SMALL_BUSINESS')->setAsYesNo();

$item = $formSetup->newItem('NAVONLINE_XSD_DIR');
$item->fieldAttr['placeholder'] = NAVONLINE_DEFAULT_XSD_DIR;
$item->helpText = $langs->transnoentities('NAVONLINE_XSD_DIR_Help');
$item->cssClass = 'minwidth500';

/*
 * Actions
 */

$envBefore = navonlineGetEnvironment();
include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

// Switching to production turns dry-run on: the first production invoices are only built and validated
if ($envBefore !== NAVONLINE_ENV_PROD && navonlineGetEnvironment() === NAVONLINE_ENV_PROD && navonlineIsProdAvailable()) {
	dolibarr_set_const($db, 'NAVONLINE_DRY_RUN', '1', 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('NavOnlineProdDryRunOn'), null, 'warnings');
	$formSetup->reloadConfs();
}

// Production stays locked: a saved 'prod' value is reverted immediately
if (getDolGlobalString('NAVONLINE_ENVIRONMENT') === NAVONLINE_ENV_PROD && !navonlineIsProdAvailable()) {
	dolibarr_set_const($db, 'NAVONLINE_ENVIRONMENT', NAVONLINE_ENV_TEST, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('NavOnlineProdLockedError'), null, 'errors');
	$formSetup->reloadConfs(); // show the reverted value, not the posted one
}
// Go-live date must be an existing date in YYYY-MM-DD format
if (getDolGlobalString('NAVONLINE_GOLIVE_DATE') !== '' && !navonlineIsValidDate(getDolGlobalString('NAVONLINE_GOLIVE_DATE'))) {
	dolibarr_set_const($db, 'NAVONLINE_GOLIVE_DATE', NAVONLINE_DEFAULT_GOLIVE_DATE, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('NavOnlineGoLiveDateInvalid', NAVONLINE_DEFAULT_GOLIVE_DATE), null, 'errors');
	$formSetup->reloadConfs();
}

if ($action == 'testalert') {
	require_once __DIR__.'/../class/navonlinesender.class.php';
	$sender = new NavOnlineSender($db);
	if ($sender->sendTestAlert()) {
		setEventMessages($langs->trans('NavOnlineTestAlertSent', implode(', ', navonlineGetAlertRecipients())), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('NavOnlineTestAlertFailed'), $sender->errors, 'errors');
	}
}

if ($action == 'testconnection') {
	require_once __DIR__.'/../class/navonlinesender.class.php';
	$sender = new NavOnlineSender($db);
	if ($sender->testConnection()) {
		setEventMessages($langs->trans('NavOnlineConnectionOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('NavOnlineConnectionFailed'), $sender->errors, 'errors');
	}
}

$action = 'edit';

/*
 * View
 */

$title = 'NavOnlineSetup';

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-navonline page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = navonlineAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'bill');

// Environment info (read-only)
print load_fiche_titre($langs->trans('NavOnlineEnvironment'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
$env = navonlineGetEnvironment();
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineActiveEnvironment').'</td><td>'.navonlineEnvironmentBadge($env).(navonlineIsDryRun() ? ' '.navonlineDryRunBadge() : '').'</td></tr>';
$url = navonlineGetApiUrl($env);
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineApiUrl').'</td><td>'.($url !== '' ? '<code>'.dol_escape_htmltag($url).'</code>' : '<span class="error">'.$langs->trans('NavOnlineProdLocked').'</span>').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineProdStatus').'</td><td>'.(navonlineIsProdAvailable() ? $langs->trans('NavOnlineProdEnabled') : img_picto('', 'lock', 'class="pictofixedwidth"').$langs->trans('NavOnlineProdLocked')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineGoLive').'</td><td>'.dol_escape_htmltag(navonlineGetGoLiveDate()).' <span class="opacitymedium">('.$langs->trans('NavOnlineGoLiveHint').')</span></td></tr>';
foreach (array(NAVONLINE_ENV_TEST, NAVONLINE_ENV_PROD) as $e) {
	print '<tr class="oddeven"><td>'.$langs->trans('NavOnlineCredentialsFile').' - '.navonlineEnvironmentBadge($e).'</td><td><code>config/'.dol_escape_htmltag(basename(navonlineGetCredentialsPath($e))).'</code> ';
	if (navonlineCredentialsFileExists($e)) {
		print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('NavOnlineCredentialsPresent');
	} else {
		print img_warning('', '', 'pictofixedwidth').$langs->trans('NavOnlineCredentialsMissingFor', 'config/credentials.'.$e.'.php.example');
	}
	print '</td></tr>';
}
print '</table>';
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=testalert&token='.newToken().'">'.$langs->trans('NavOnlineTestAlert').'</a>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=testconnection&token='.newToken().'">'.$langs->trans('NavOnlineTestConnection').'</a>';
print '</div>';

print $formSetup->generateOutput(true);

print dol_get_fiche_end();

llxFooter();
$db->close();
