<?php
/**
 * Weatherdata - Minutenlauf (cron/cron.01min), nur ueber die PHP-Kommandozeile.
 * Aufruf ohne Argument: ein Lauf. --selbsttest: Selbstpruefung ohne Netz und ohne Geraet.
 * --haus-leeren (0.9.8, Deinstallation): die retained Hausthemen haus/wetter/... (ab 0.9.10 auch haus/pv/...) abraeumen, nur wenn diese Installation Anbieter ist oder war.
 * Der Einstieg nennt keine Variable $cfg oder $p im Globalen: Das SDK ueberschreibt beide (gemessen 01.11.2026).
 */
if (isset($argv[1]) && ($argc > 2 || !in_array($argv[1], array('--selbsttest', '--haus-leeren'), true))) {
    fwrite(STDERR, "Aufruf: wd_takt.php [--selbsttest|--haus-leeren]\n");
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

if (isset($argv[1]) && $argv[1] === '--haus-leeren') {
    $wd_r = wd_haus_leeren_ausgabe();
    $wd_r2 = wd_pv_leeren_ausgabe();     // 0.9.10: haus/pv ebenso, nur wenn diese Installation Anbieterin ist oder war
    exit(max($wd_r, $wd_r2));
}

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
