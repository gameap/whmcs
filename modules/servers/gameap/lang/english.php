<?php

/**
 * Strings for the client-area template. WHMCS loads lang/ folders for addon
 * modules only, so gameap_ClientArea() reads this file itself and hands the
 * array to the template as $lang. Every use in clientarea.tpl carries a
 * |default: fallback as well, so the page stays readable without it.
 */

$_LANG['open_panel'] = 'Open the game panel';
$_LANG['open_panel_hint'] = 'Opens GameAP in a new tab, already signed in.';
$_LANG['not_ready'] = 'The server is not ready yet. This page will show its details once setup finishes.';
$_LANG['stale'] = 'Showing the last known state — the panel could not be reached just now.';
$_LANG['address'] = 'Address';
$_LANG['game'] = 'Game';
$_LANG['state'] = 'State';
$_LANG['suspended'] = 'Suspended';
$_LANG['installing'] = 'Installing';
$_LANG['not_installed'] = 'Not installed';
$_LANG['online'] = 'Online';
$_LANG['offline'] = 'Offline';
$_LANG['ram'] = 'RAM';
$_LANG['cpu'] = 'CPU';
$_LANG['panel_login'] = 'Panel login';
