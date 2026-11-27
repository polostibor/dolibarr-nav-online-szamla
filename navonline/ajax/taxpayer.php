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
 * \file    htdocs/custom/navonline/ajax/taxpayer.php
 * \ingroup navonline
 * \brief   AJAX (POST, with token): NAV taxpayer data of a Hungarian tax number, for the partner card (create / edit).
 *          Answer JSON: {status: ok | notfound | invalid | error, ...data of NavOnlineTaxpayer::lookup()}. One query per
 *          tax number per session (cached).
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}

$res = @include '../../main.inc.php';
if (!$res) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/../class/navonlinetaxpayer.class.php';

/**
 * @var DoliDB $db
 * @var User $user
 */

top_httphead('application/json');

$answer = function ($a) {
	print json_encode($a);
	exit;
};
if (!isModEnabled('navonline') || !NavOnlineTaxpayer::enabled()) {
	$answer(array('status' => 'error', 'message' => 'A NAV-os partneradat-lekérdezés ki van kapcsolva'));
}
if (!$user->hasRight('societe', 'creer') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(403);
	$answer(array('status' => 'error', 'message' => 'Nincs jogosultság'));
}
$id = NavOnlineTaxpayer::taxpayerId(GETPOST('taxnumber', 'alphanohtml'));
if ($id === '') {
	$answer(array('status' => 'error', 'message' => 'Nem magyar adószám (8 vagy 11 számjegy kell)'));
}
$cacheKey = 'navonline_taxpayer_'.NavOnlineTaxpayer::environment().'_'.$id;
if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
	$answer($_SESSION[$cacheKey] + array('cached' => 1));
}
$t = new NavOnlineTaxpayer($db);
$r = $t->lookup($id);
if ($r === null) {
	dol_syslog('NavOnline taxpayer lookup '.$id.' failed: '.implode('; ', $t->errors), LOG_WARNING);
	$answer(array('status' => 'error', 'message' => implode('; ', $t->errors)));
}
dol_syslog('NavOnline taxpayer lookup '.$id.' ('.NavOnlineTaxpayer::environment().'): '.$r['status']);
$r['environment'] = NavOnlineTaxpayer::environment();
$_SESSION[$cacheKey] = $r;
$answer($r);
