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
 * \file    htdocs/custom/navonline/scripts/navonline_x01_backfill.php
 * \ingroup navonline
 * \brief   Tax authority audit export: snapshots for the customer invoices validated without one (before the function
 *          existed). Made from the data of today, so they are marked "not at issue". Nothing is sent to NAV.
 *
 * Usage:
 *   php navonline_x01_backfill.php --info        count the issued invoices with / without snapshot
 *   php navonline_x01_backfill.php --run [N]     make the missing snapshots (at most N, default all)
 */

$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) == 'cgi' || $sapi_type === 'apache2handler') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$res = @include __DIR__.'/../../../master.inc.php';
if (!$res) {
	$res = @include __DIR__.'/../../../../master.inc.php';
}
if (!$res) {
	echo "Error: cannot include master.inc.php\n";
	exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../class/navonlinex01.class.php';

/**
 * @var DoliDB $db
 * @var Conf $conf
 */

if (!isModEnabled('navonline')) {
	echo "Module NavOnline must be enabled\n";
	exit(1);
}

$cmd = $argv[1] ?? '--info';
$limit = (int) ($argv[2] ?? 0);
$p = $db->prefix();
$count = function ($withSnapshot) use ($db, $p) {
	$sql = 'SELECT COUNT(*) AS n FROM '.$p.'facture f WHERE f.entity IN ('.getEntity('invoice').') AND f.fk_statut > 0 AND f.type <> '.Facture::TYPE_PROFORMA;
	$sql .= ' AND '.($withSnapshot ? '' : 'NOT ').'EXISTS (SELECT 1 FROM '.$p.'navonline_x01_snapshot s WHERE s.fk_facture = f.rowid)';
	return (int) $db->fetch_object($db->query($sql))->n;
};

echo 'Issued customer invoices with snapshot: '.$count(true).', without: '.$count(false)."\n";
if ($cmd !== '--run') {
	exit(0);
}

// The first active administrator makes the snapshots
$res = $db->query('SELECT rowid FROM '.$p.'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1');
$o = $res ? $db->fetch_object($res) : null;
$user = new User($db);
if (!$o || $user->fetch((int) $o->rowid) <= 0) {
	echo "No active administrator\n";
	exit(1);
}
$user->loadRights();

$x = new NavOnlineX01($db);
$db->begin();
$r = $x->backfill($user, $limit);
if ($r['errors']) {
	$db->rollback();
	echo 'Error, nothing saved: '.implode('; ', $x->errors)."\n";
	exit(1);
}
$db->commit();
echo 'Snapshots made: '.$r['done'].' (not at issue), of which incomplete (XML error, shown by the export): '.$r['incomplete']."\n";
exit(0);
