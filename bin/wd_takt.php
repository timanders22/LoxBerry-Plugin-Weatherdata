<?php
/**
 * Weatherdata - Minutenlauf (cron/cron.01min), nur ueber die PHP-Kommandozeile.
 * Aufruf ohne Argument: ein Lauf. --selbsttest: Selbstpruefung ohne Netz und ohne Geraet.
 * Der Einstieg nennt keine Variable $cfg oder $p im Globalen: Das SDK ueberschreibt beide (gemessen 01.11.2026).
 */
if (isset($argv[1]) && ($argc > 2 || $argv[1] !== '--selbsttest')) {
    fwrite(STDERR, "Aufruf: wd_takt.php [--selbsttest]\n");
    exit(2);
}
$wd_html = 'REPLACELBPHTMLDIR';
if (strpos($wd_html, 'REPLACE') === 0 || !is_file($wd_html . '/wd_lib.php')) {
    $wd_html = basename(dirname(__DIR__)) === 'plugins'
        ? dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__)
        : dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($wd_html . '/wd_lib.php')) {
    fwrite(STDERR, "wd_lib.php nicht gefunden\n");
    exit(1);
}
require_once $wd_html . '/wd_lib.php';
date_default_timezone_set('Europe/Berlin');

if (isset($argv[1]) && $argv[1] === '--selbsttest') {
    $wd_z = wd_selbsttest();
    $wd_s = 0;
    foreach ($wd_z as $wd_r) {
        echo ($wd_r['ok'] === null ? '[INFO] ' : ($wd_r['ok'] ? '[OK] ' : '[FEHL] ')) . $wd_r['frage'] . ($wd_r['hinweis'] !== '' ? ' - ' . $wd_r['hinweis'] : '') . "\n";
        if ($wd_r['ok'] === false) { $wd_s++; }
    }
    exit($wd_s ? 1 : 0);
}

wd_lauf(false);
