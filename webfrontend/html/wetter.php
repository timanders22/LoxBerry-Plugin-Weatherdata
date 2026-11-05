<?php
/**
 * Weatherdata - Wetterseite fuer den Webseiten-Baustein (Webpage) der Loxone-App. Liest nur, nennt keinen Standort, keine Anmeldung im Heimnetz.
 * Adresse: wetter.php?mode=auto|light|dark&lang=de|en&theme=<Design>&iconset=<Symbole>. Unbekannte Werte fallen still auf die Vorgabe.
 * Der Kachelwert bleibt unter wd.php?kachel=1.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/wd_lib.php';
date_default_timezone_set('Europe/Berlin');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'");
$wd_me = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($wd_me !== 'GET' && $wd_me !== 'HEAD') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: GET, HEAD');
    exit;
}
$wd_s = wd_json_lesen(wd_tmpdir(false) . '/zustand.json');
echo wd_seite_html(is_array($wd_s) ? $wd_s : null, wd_seite_wahl($_GET), time());
