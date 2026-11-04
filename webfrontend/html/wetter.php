<?php
/**
 * Weatherdata - kleine Seite fuer den Webseiten-Baustein der Loxone-App (Ersatz der W4L-Webseite). Liest nur, nennt keinen Standort, keine Themen-Sammlung.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/wd_lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$wd_s = wd_json_lesen(wd_tmpdir(false) . '/zustand.json');
$wd_k = is_array($wd_s) ? wd_kachel($wd_s) : array('ok' => 0);
$wd_v = function ($w, $e = '') { return $w === null || $w === '' ? '&ndash;' : wd_e($w) . $e; };
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="300"><title>Weatherdata</title>
<style>body{font-family:-apple-system,'Segoe UI',Roboto,sans-serif;margin:16px;background:#fff;color:#333}h1{font-size:2.4em;margin:0;color:#4f7d17}.k{color:#666;font-size:.9em}.w{margin-top:10px;padding:8px 10px;border-radius:6px;background:#fdf4ec;border:1px solid #f0c9a0}</style></head><body>
<?php if (empty($wd_k['ok'])) { ?><p><?= wd_e(wd_t('KOPF.KEINE_AUSSAGE')) ?></p><?php } else { ?>
<h1><?= $wd_v($wd_k['temp_c'], ' &deg;C') ?></h1>
<div class="k"><?= wd_e(wd_t('THEMA.gefuehlt_c')) ?>: <?= $wd_v($wd_k['gefuehlt_c'], ' &deg;C') ?> &middot; <?= wd_e(wd_t('TEXT.T_HERKUNFT')) ?>: <?= wd_e($wd_k['herkunft']) ?></div>
<div class="k"><?= $wd_v($wd_k['tmin'], ' &deg;C') ?> / <?= $wd_v($wd_k['tmax'], ' &deg;C') ?></div>
<?php if ($wd_k['warnung'] !== '') { ?><div class="w"><b><?= wd_e(wd_t('TEXT.T_STUFE')) ?> <?= (int) $wd_k['stufe'] ?>:</b> <?= wd_e($wd_k['warnung']) ?></div><?php } ?>
<?php } ?>
</body></html>
