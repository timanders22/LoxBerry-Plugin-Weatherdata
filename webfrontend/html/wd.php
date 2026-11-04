<?php
/**
 * Weatherdata - Endpunkt fuer Loxone. ?status=1 ist offen und liest nur. ?innen= und ?abruf= verlangen das Aktionstoken.
 * Der unangemeldete Endpunkt legt nichts an: Ohne Konfiguration mit Token lehnt er ab.
 * Der Standort kommt in keiner Antwort vor.
 */
require_once __DIR__ . '/wd_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$wd_c = wd_inhalt_oder_null(wd_paths()['config']);
if (!wd_hat_inhalt($wd_c)) {
    header('HTTP/1.1 503 Service Unavailable');
    echo "WD;OK=0;ERR=NICHT_EINGERICHTET\n";
    exit;
}
if (isset($_GET['status'])) {
    $wd_s = wd_json_lesen(wd_tmpdir() . '/zustand.json');
    if (!is_array($wd_s)) { echo "WD;OK=0;ERR=KEIN_LAUF\n"; exit; }
    printf("WD;OK=1;JETZT_OK=%d;PROG_OK=%d;WARN_OK=%d;STUFE=%s;ALTER=%d\n", !empty($wd_s['jetzt_ok']), !empty($wd_s['prog_ok']), !empty($wd_s['warn_ok']),
        !empty($wd_s['warn_ok']) ? (string) (isset($wd_s['lagen'][0]) ? (int) $wd_s['lagen'][0]['stufe'] : 0) : '-', time() - (int) $wd_s['ts']);
    exit;
}
if (isset($_GET['kachel'])) {
    header('Content-Type: application/json; charset=utf-8');
    $wd_s = wd_json_lesen(wd_tmpdir() . '/zustand.json');
    echo json_encode(is_array($wd_s) ? wd_kachel($wd_s) : array('ok' => 0), JSON_UNESCAPED_UNICODE);
    exit;
}
$wd_will = isset($_GET['innen']) || isset($_GET['abruf']);
if (!$wd_will) {
    header('HTTP/1.1 400 Bad Request');
    echo "WD;OK=0;ERR=UNBEKANNT\n";
    exit;
}
$wd_tok = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if ($wd_tok === '' || !hash_equals((string) $wd_c['aktionstoken'], $wd_tok)) {
    header('HTTP/1.1 403 Forbidden');
    echo "WD;OK=0;ERR=TOKEN\n";
    exit;
}
if (isset($_GET['innen'])) {
    $wd_v = is_string($_GET['innen']) ? str_replace(',', '.', trim($_GET['innen'])) : '';
    if (!is_numeric($wd_v) || (float) $wd_v < -20 || (float) $wd_v > 60) {
        header('HTTP/1.1 400 Bad Request');
        echo "WD;OK=0;ERR=WERT\n";
        exit;
    }
    echo wd_json_schreiben(wd_datadir() . '/innen.json', array('c' => round((float) $wd_v, 1), 'ts' => time())) ? "WD;OK=1\n" : "WD;OK=0;ERR=SCHREIBEN\n";
    exit;
}
// Abruf: mit Bremse, hoechstens einmal in 5 Minuten
$wd_f = wd_tmpdir() . '/abruf_letzt';
if (is_file($wd_f) && time() - (int) @file_get_contents($wd_f) < 300) { echo "WD;OK=0;ERR=BREMSE\n"; exit; }
@file_put_contents($wd_f, (string) time());
wd_lauf(true);
echo "WD;OK=1\n";
