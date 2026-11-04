<?php
/**
 * Weatherdata - Empfang fuer Stationen ohne lokale Schnittstelle: Ecowitt-Protokoll (POST) und Wunderground-Protokoll (GET).
 * Nur mit Token (Adresse in der Station: .../wd_empfang.php?token=...) und nur, wenn als Art der Station Empfang eingestellt ist.
 * Nimmt nur bekannte Zahlenfelder; PASSKEY, ID und PASSWORD werden nie gespeichert und nie protokolliert.
 * Gegen eine echte Station nicht gemessen: Ob sie den Token in der Adresse mitschickt, steht im Testplan.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/wd_lib.php';
header('Content-Type: text/plain; charset=utf-8');
$wd_c = wd_inhalt_oder_null(wd_paths()['config']);
if (!wd_hat_inhalt($wd_c) || ($wd_c['station_art'] ?? '') !== 'empfang') {
    header('HTTP/1.1 503 Service Unavailable');
    echo "ERR\n";
    exit;
}
$wd_t = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if ($wd_t === '' || !hash_equals((string) $wd_c['aktionstoken'], $wd_t)) {
    header('HTTP/1.1 403 Forbidden');
    echo "ERR\n";
    exit;
}
$wd_roh = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$wd_felder = array('tempf', 'humidity', 'dewptf', 'baromrelin', 'baromin', 'baromabsin', 'windspeedmph', 'windgustmph', 'winddir', 'rainratein', 'rainin', 'solarradiation', 'uv', 'UV', 'tempinf', 'indoortempf');
$wd_e = array();
foreach ($wd_felder as $wd_k) {
    if (isset($wd_roh[$wd_k]) && is_string($wd_roh[$wd_k]) && is_numeric($wd_roh[$wd_k])) { $wd_e[$wd_k] = (float) $wd_roh[$wd_k]; }
}
if (wd_empfang_lesen($wd_e) === null) {
    header('HTTP/1.1 400 Bad Request');
    echo "ERR\n";
    exit;
}
wd_json_schreiben(wd_tmpdir() . '/empfang.json', array('ts' => time(), 'e' => $wd_e));
echo "OK\n";
