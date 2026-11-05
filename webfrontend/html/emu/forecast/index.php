<?php
/**
 * Weatherdata - Endpunkt des Emulators: /forecast/ auf Port 6066. Liefert nur Wetterdaten, liest keine Einstellung heraus, fragt keinen Dienst.
 * Ohne eingeschalteten Emulator 404. Siehe wd_emu.php.
 */
ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/wd_lib.php';
date_default_timezone_set('Europe/Berlin');
header_remove('X-Powered-By');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
list($wd_code, $wd_rumpf) = wd_emu_endpunkt($_SERVER, $_GET, time());
http_response_code($wd_code);
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'HEAD') { echo $wd_rumpf; }
