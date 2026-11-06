<?php
/**
 * Weatherdata - Oberflaeche. Reiter: Einstellungen | Werte | Quellen | Warnungen | MQTT | Einbindung in Loxone | Wetterseite | Emulator | Test | Logdateien.
 * Alle Variablen tragen wf_: LBWeb::lbheader() setzt Globale des SDK. Handler stehen vor lbheader().
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
// Die Lage entscheidet der eigene Ablageort, nicht die Reihenfolge der Versuche: Aus einem Archiv griffe der erste Kandidat drei Ebenen ueber das Archiv.
$wf_c = basename(dirname(__DIR__)) === 'plugins' ? dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/wd_lib.php' : dirname(__DIR__) . '/html/wd_lib.php';
if (is_file($wf_c)) { require_once $wf_c; }
if (!function_exists('wd_config')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Weatherdata</h2><p><b>Die Programmbibliothek wd_lib.php wurde nicht gefunden.</b> Bitte neu installieren; die Konfiguration bleibt dabei erhalten.</p>';
    exit;
}
date_default_timezone_set('Europe/Berlin');
$wf_p = wd_paths();
if ($wf_p['lbhome'] !== '' && file_exists($wf_p['lbhome'] . '/libs/phplib/loxberry_system.php')) {
    require_once $wf_p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $wf_p['lbhome'] . '/libs/phplib/loxberry_web.php';
}
$wf_vor_heilung = wd_inhalt_oder_null($wf_p['config']);
wd_selbstheilung();
$wf_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$wf_saved = false; $wf_err = ''; $wf_note = ''; $wf_note_art = 'ok'; $wf_fehler = array(); $wf_hinweise = array();
$wf_x2 = null; $wf_x2neu = null; $wf_hilfe = array();
$wf_wache = wd_wachposten();
if ($wf_wache !== '') {
    $wf_m = isset($_POST['activetab']) && is_string($_POST['activetab']) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($wf_m !== null) { $_POST['activetab'] = $wf_m; }
    $wf_err = $wf_wache;
}
$wf_muster = '/^tab-(settings|werte|quellen|warnungen|mqtt|loxone|wetterseite|emulator|test|log)$/';
$wf_tab = preg_match($wf_muster, (string) (isset($_POST['activetab']) && is_string($_POST['activetab']) ? $_POST['activetab'] : '')) ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && is_string($_GET['form']) && preg_match($wf_muster, 'tab-' . preg_replace('/[^a-z]/', '', $_GET['form']))) { $wf_tab = 'tab-' . preg_replace('/[^a-z]/', '', $_GET['form']); }
function wf_aktiv($id) { global $wf_tab; return $wf_tab === $id ? ' sm-active' : ''; }
function wf_einmal_datei() { return wd_datadir() . '/einmalmeldung.json'; }
function wf_einmal_lesen()
{
    $d = wd_json_lesen(wf_einmal_datei());
    if (is_file(wf_einmal_datei())) { @unlink(wf_einmal_datei()); }
    return (is_array($d) && isset($d['zeit']) && abs(time() - (int) $d['zeit']) <= 120) ? $d : null;
}

$wf_cfg = wd_token_sichern();
if (trim((string) $wf_cfg['aktionstoken']) === '') { $wf_hinweise[] = wd_t('WACHE.KEIN_TOKEN'); }

// ---------- Vorlage: Download, vor jedem HTML ----------
if ($wf_post && isset($_POST['vorlage'])) {
    list($wf_vn, $wf_vx) = wd_vorlage($wf_cfg);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $wf_vn . '"');
    echo $wf_vx;
    exit;
}
// ---------- 0.9.7: Vorlage Virtueller Ausgang fuer Messwerte von Loxone ----------
if ($wf_post && isset($_POST['lox_vorlage'])) {
    $wf_lh = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && preg_match('/^[A-Za-z0-9.\-:\[\]]{1,100}\z/', $_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry';
    list($wf_vn, $wf_vx) = wd_lox_vorlage($wf_lh, $wf_p['plugin'], (string) $wf_cfg['aktionstoken']);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $wf_vn . '"');
    echo $wf_vx;
    exit;
}
// ---------- Sichern: Download mit allen Einstellungen, Standort nur als Einstellung ----------
if ($wf_post && isset($_POST['wd_sichern'])) {
    $wf_v = wd_sicherung_stand();
    $wf_alt = wd_rueckspiel_altwerte();
    if ($wf_alt) { $wf_v = array('_warnung' => sprintf(wd_t('SICH.WARNKOPF'), implode(' ', $wf_alt))) + $wf_v; }
    $wf_js = json_encode($wf_v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($wf_js !== false) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="weatherdata_einstellungen_' . date('Ymd_His') . '.json"');
        echo $wf_js;
        exit;
    }
    $wf_err = wd_t('SICH.SCHREIBFEHLER');
}
// ---------- Zurueckspielen ----------
if ($wf_post && isset($_POST['wd_zurueck'])) {
    if (!isset($_FILES['wd_sicherung']['tmp_name']) || !is_string($_FILES['wd_sicherung']['tmp_name']) || !@is_uploaded_file($_FILES['wd_sicherung']['tmp_name'])) {
        $wf_err = wd_t('SICH.KEINE_DATEI');
    } elseif ((int) $_FILES['wd_sicherung']['size'] > 262144) {
        $wf_err = wd_t('SICH.ZU_GROSS');
    } else {
        $wf_halt = wd_config();
        $wf_e = wd_sicherung_lesen((string) @file_get_contents($_FILES['wd_sicherung']['tmp_name']), $wf_halt);
        // 0.9.8: der Merker "war Anbieter von haus/wetter" gehoert zur Installation, nicht zur Sicherung - er bleibt.
        if ($wf_e[0] !== null && !empty($wf_halt['_haus_frueher'])) { $wf_e[0]['_haus_frueher'] = 1; }
        if ($wf_e[0] !== null && !empty($wf_halt['_pv_frueher'])) { $wf_e[0]['_pv_frueher'] = 1; }     // 0.9.10: ebenso fuer haus/pv
        if ($wf_e[0] === null) {
            $wf_err = wd_t('SICH.ABGELEHNT') . ' ' . implode(' ', $wf_e[1]);
        } elseif (wd_config_speichern($wf_e[0])) {
            wd_zweitschrift($wf_e[0]);
            $wf_ist = wd_inhalt_oder_null($wf_p['config']);
            if ($wf_ist == $wf_e[0]) {
                $wf_note = sprintf(wd_t('SICH.UEBERNOMMEN'), $wf_e[2]) . ' ' . wd_t('SICH.DIENST');
                $wf_hinweise = array_merge($wf_hinweise, $wf_e[3]);
                @unlink(wd_tmpdir() . '/mqtt_sig.txt');
                wd_abo_nachziehen($wf_e[0]);
                list($wf_hm, $wf_ha, $wf_hf) = wd_haus_folgen($wf_halt, $wf_e[0]);
                if ($wf_hf !== null) { wd_haus_frueher_setzen((bool) $wf_hf); }
                if ($wf_hm !== '') { $wf_hinweise[] = $wf_hm; }
                list($wf_pm2, $wf_pa2, $wf_pf2) = wd_pv_folgen($wf_halt, $wf_e[0]);
                if ($wf_pf2 !== null) { wd_pv_frueher_setzen((bool) $wf_pf2); }
                if ($wf_pm2 !== '') { $wf_hinweise[] = $wf_pm2; }
            } else {
                $wf_err = wd_t('SICH.NICHT_ANGEKOMMEN');
            }
        } else {
            $wf_err = wd_t('SICH.SCHREIBFEHLER');
        }
    }
}
// ---------- Testansage, Dienstprobe, Warnzelle, Protokoll, Token ----------
if ($wf_post && (isset($_POST['ansage_test']) || isset($_POST['ansage_test_ziel']))) {
    $wf_c = wd_config(); $wf_k = wd_ansage_k();
    $wf_n = isset($_POST['ansage_test_ziel']) ? ansage_test_ziel($_POST) : 0;
    $wf_r = isset($_POST['ansage_test_ziel']) && $wf_n === 0
        ? array('stand' => 0, 'kennung' => 'ZIEL_UNBEKANNT|-', 'art' => '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0)
        : ($wf_n >= 1 ? ansage_testansage($wf_c['tts'], $wf_k, $wf_n) : ansage_testansage($wf_c['tts'], $wf_k));
    wd_log('Testansage: ' . ansage_kurz($wf_r));
    if ($wf_r['stand'] === 1) { $wf_note = wd_t('TTS.M_TEST_OK'); }
    elseif ($wf_r['kennung'] === 'AUS') { $wf_note_art = 'warn'; $wf_note = wd_t('TTS.M_TEST_AUS'); }
    else { $wf_note_art = $wf_r['stand'] === -1 ? 'warn' : 'err'; $wf_note = sprintf(wd_t('TTS.M_TEST_FEHL'), ansage_kennung_text($wf_r['kennung'], $wf_k)); }
    $wf_tab = 'tab-test';
}
if ($wf_post && isset($_POST['abruf'])) {
    if (wd_lauf(true) === null) { $wf_note_art = 'warn'; $wf_note = wd_t('MELD.ABRUF_LAEUFT'); } else { $wf_note = wd_t('MELD.ABRUF'); }
}
if ($wf_post && isset($_POST['zelle_finden'])) {
    $wf_vs = wd_zelle_vorschlag(wd_config());
    if ($wf_vs === null) { $wf_note_art = 'warn'; $wf_note = wd_t('MELD.ZELLE_NICHT'); }
    else { $wf_note = sprintf(wd_t('MELD.ZELLE_VORSCHLAG'), $wf_vs[0], $wf_vs[1]); }
}
// ---------- 0.9.6: Regionalschluessel und Meteoalarm-Region, nur auf Knopfdruck ----------
// 0.9.8: Suche und Uebernehmen stehen als Knoepfe im Formular der Zusatzdienste (kein Formular im Formular). Ein solcher Knopf
// speichert dieses Formular nie: zusatz_save faellt weg, die Eingaben reisen als X-2 zurueck (Schluessel nie, wie beim Speichern).
$wf_zusatz_knopf = $wf_post && (isset($_POST['nina_suchen']) || isset($_POST['nina_setzen']) || isset($_POST['ma_suchen']) || isset($_POST['ma_setzen']));
if ($wf_zusatz_knopf) {
    unset($_POST['zusatz_save']);
    $wf_xw = array();
    foreach (wd_felder() as $wf_k => $wf_f) {
        if ($wf_f[0] !== 'zusatz_save' || $wf_f[1] === 'g') { continue; }
        if ($wf_f[1] === 'h') { $wf_xw[$wf_k] = isset($_POST[$wf_k]) ? '1' : '0'; continue; }
        if (isset($_POST[$wf_k]) && is_string($_POST[$wf_k]) && strlen($_POST[$wf_k]) < 200) { $wf_xw[$wf_k] = $_POST[$wf_k]; }
    }
    $wf_x2neu = array('form' => 'zusatz_save', 'werte' => $wf_xw, 'bean' => array(), 'ziele' => null);
}
if ($wf_post && isset($_POST['nina_suchen'])) {
    $wf_h = wd_ars_suchen(isset($_POST['nina_such']) && is_string($_POST['nina_such']) ? $_POST['nina_such'] : '');
    $wf_hilfe['such'] = $wf_h['such']; $wf_hilfe['ars'] = $wf_h['treffer'];
    if ($wf_h['stand'] === 'ok') { $wf_note = sprintf(wd_t('MELD.ARS_TREFFER'), count($wf_h['treffer']), $wf_h['fassung']) . ($wf_h['mehr'] ? ' ' . wd_t('MELD.ARS_MEHR') : ''); }
    else { $wf_note_art = 'warn'; $wf_note = wd_t($wf_h['stand'] === 'kurz' ? 'MELD.ARS_KURZ' : ($wf_h['stand'] === 'leer' ? 'MELD.ARS_LEER' : 'MELD.ARS_NETZ')); }
}
if ($wf_post && isset($_POST['nina_setzen'])) {
    $wf_v = wd_wert_pruefen('nina_ars', isset($_POST['nina_wert']) ? $_POST['nina_wert'] : null);
    if (!$wf_v[0] || $wf_v[1] === '') {
        $wf_note_art = 'warn'; $wf_note = wd_t('MELD.HILFE_UNGUELTIG');
    } else {
        $wf_c = wd_config(); $wf_c['nina_ars'] = $wf_v[1];
        if (wd_config_speichern($wf_c)) { wd_zweitschrift($wf_c); $wf_note = wd_t('MELD.ARS_UEBERNOMMEN'); if (is_array($wf_x2neu)) { $wf_x2neu['werte']['nina_ars'] = $wf_v[1]; } } else { $wf_err = wd_t('MELD.SPEICHERN_FEHL'); }
    }
}
if ($wf_post && isset($_POST['ma_suchen'])) {
    // 0.9.8: das Land kommt aus dem Feld Land desselben Kastens (bis 0.9.7 eigenes Suchformular ma_such_land).
    $wf_v = wd_wert_pruefen('ma_land', isset($_POST['ma_land']) ? $_POST['ma_land'] : (isset($_POST['ma_such_land']) ? $_POST['ma_such_land'] : null));
    if (!$wf_v[0] || $wf_v[1] === '') {
        $wf_note_art = 'warn'; $wf_note = wd_t('MELD.MA_LAND');
    } else {
        $wf_h = wd_ma_regionen($wf_v[1]);
        $wf_hilfe['ma_land'] = $wf_v[1];
        if ($wf_h === null) { $wf_note_art = 'warn'; $wf_note = wd_t('MELD.MA_NETZ'); }
        elseif (!$wf_h) { $wf_note_art = 'warn'; $wf_note = wd_t('MELD.MA_LEER'); }
        else { $wf_hilfe['ma'] = $wf_h; $wf_note = sprintf(wd_t('MELD.MA_TREFFER'), count($wf_h)); }
    }
}
if ($wf_post && isset($_POST['ma_setzen'])) {
    $wf_v = wd_wert_pruefen('ma_land', isset($_POST['ma_wert_land']) ? $_POST['ma_wert_land'] : null);
    $wf_w2 = wd_wert_pruefen('ma_emma', isset($_POST['ma_wert']) ? $_POST['ma_wert'] : null);
    if (!$wf_v[0] || !$wf_w2[0] || $wf_v[1] === '' || $wf_w2[1] === '') {
        $wf_note_art = 'warn'; $wf_note = wd_t('MELD.HILFE_UNGUELTIG');
    } else {
        $wf_c = wd_config(); $wf_c['ma_land'] = $wf_v[1]; $wf_c['ma_emma'] = $wf_w2[1];
        if (wd_config_speichern($wf_c)) { wd_zweitschrift($wf_c); $wf_note = wd_t('MELD.MA_UEBERNOMMEN'); if (is_array($wf_x2neu)) { $wf_x2neu['werte']['ma_land'] = $wf_v[1]; $wf_x2neu['werte']['ma_emma'] = $wf_w2[1]; } } else { $wf_err = wd_t('MELD.SPEICHERN_FEHL'); }
    }
}
if ($wf_post && isset($_POST['clearlog'])) {
    $wf_ce = dirname($wf_p['log']) . '/cron.err';
    if (empty($_POST['clearlog_ok'])) {
        $wf_note_art = 'warn'; $wf_note = wd_t('MELD.LOG_HAKEN');
    } elseif ((is_file($wf_p['log']) && @file_put_contents($wf_p['log'], '') === false) || (is_file($wf_ce) && @file_put_contents($wf_ce, '') === false)) {
        $wf_err = wd_t('MELD.LOG_FEHL');
    } else {
        $wf_note = wd_t('MELD.LOG_LEER');
    }
}
if ($wf_post && isset($_POST['token_neu'])) {
    $wf_c = wd_config();
    $wf_c['aktionstoken'] = bin2hex(random_bytes(12));
    if (wd_config_speichern($wf_c)) { wd_zweitschrift($wf_c); $wf_note = wd_t('MELD.TOKEN_NEU'); } else { $wf_err = wd_t('MELD.SPEICHERN_FEHL'); }
}
// ---------- Speichern, je Formular ein Teil der Feldtabelle ----------
foreach (array('save', 'quellen_save', 'zusatz_save', 'pv_save', 'warn_save', 'mqtt_save', 'emu_save', 'lox_save') as $wf_form) {
    if (!$wf_post || !isset($_POST[$wf_form])) { continue; }
    $wf_alt = wd_config(); $wf_neu = $wf_alt; $wf_bean = array(); $wf_werte = array(); $wf_ziele_post = null;
    // 0.9.9: beide Zahlen aus der Karte zusammen ins Feld Breitengrad kopiert ("48.13726, 11.57549") - hier aufteilen. Beide mit
    // Dezimalpunkt, sonst wuerde eine einzelne Zahl mit Dezimalkomma ("51,3") still in 51 und 3 zerlegt.
    if ($wf_form === 'save' && isset($_POST['lat']) && is_string($_POST['lat'])
        && preg_match('/^\s*(-?\d{1,3}\.\d+)\s*[,;\/ ]\s*(-?\d{1,3}\.\d+)\s*\z/', $_POST['lat'], $wf_pm)
        && (!isset($_POST['lon']) || !is_string($_POST['lon']) || trim($_POST['lon']) === '' || trim($_POST['lon']) === $wf_pm[2])) {
        $_POST['lat'] = $wf_pm[1]; $_POST['lon'] = $wf_pm[2];
    }
    foreach (wd_felder() as $wf_k => $wf_f) {
        if ($wf_f[0] !== $wf_form) { continue; }
        if ($wf_f[1] === 'h') { $wf_neu[$wf_k] = isset($_POST[$wf_k]) ? 1 : 0; $wf_werte[$wf_k] = $wf_neu[$wf_k] ? '1' : '0'; continue; }
        if ($wf_f[1] === 'g') {
            if (!empty($_POST[$wf_k . '_loeschen'])) { $wf_neu[$wf_k] = ''; continue; }
            if (!isset($_POST[$wf_k]) || !is_string($_POST[$wf_k]) || $_POST[$wf_k] === '') { continue; }
        }
        $wf_w = isset($_POST[$wf_k]) && is_string($_POST[$wf_k]) ? $_POST[$wf_k] : null;
        if ($wf_f[1] !== 'g' && is_string($wf_w) && strlen($wf_w) < 200) { $wf_werte[$wf_k] = $wf_w; }
        list($wf_ok, $wf_v, $wf_g) = wd_wert_pruefen($wf_k, $wf_w);
        if (!$wf_ok) { $wf_fehler[] = wd_t('FELD.' . $wf_k) . ': ' . $wf_g; $wf_bean[] = $wf_k; continue; }
        $wf_neu[$wf_k] = $wf_v;
    }
    if ($wf_form === 'save') {
        if (($wf_neu['lat'] === '') !== ($wf_neu['lon'] === '')) { $wf_fehler[] = wd_t('MELD.W_POS_PAAR'); $wf_bean[] = 'lat'; $wf_bean[] = 'lon'; }
        if (!((float) $wf_neu['hitze1_c'] < (float) $wf_neu['hitze2_c'] && (float) $wf_neu['hitze2_c'] < (float) $wf_neu['hitze3_c'])) { $wf_fehler[] = wd_t('MELD.W_HITZE_FOLGE'); $wf_bean[] = 'hitze1_c'; $wf_bean[] = 'hitze2_c'; $wf_bean[] = 'hitze3_c'; }
    }
    if ($wf_form === 'pv_save') {     // 0.9.11: Schatten morgens muss vor Schatten abends enden
        for ($wf_pi = 1; $wf_pi <= 4; $wf_pi++) {
            $wf_sm = wd_pv_minute($wf_neu['pv' . $wf_pi . '_schatten_morgen'] ?? ''); $wf_sa = wd_pv_minute($wf_neu['pv' . $wf_pi . '_schatten_abend'] ?? '');
            if ($wf_sm !== null && $wf_sa !== null && $wf_sm >= $wf_sa) { $wf_fehler[] = sprintf(wd_t('MELD.W_PV_SCHATTEN'), $wf_pi); $wf_bean[] = 'pv' . $wf_pi . '_schatten_morgen'; $wf_bean[] = 'pv' . $wf_pi . '_schatten_abend'; }
        }
    }
    if ($wf_form === 'warn_save') {
        $wf_tm = array(); $wf_tb = array(); $wf_th = array();
        $wf_neu['tts'] = ansage_formular_lesen($_POST, $wf_alt['tts'], $wf_tm, $wf_tb, array('modi' => wd_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), wd_ansage_k(), $wf_th);
        list($wf_neu['ansage_regeln']) = ansage_regeln_formular($_POST, wd_ansage_regeln($wf_alt), array(), $wf_tm, $wf_tb, wd_ansage_k());
        // 0.9.5: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 0.9.4 ueberschrieb es der naechste Abgleich.
        $wf_neu['ansage_token_herkunft'] = ansage_token_herkunft_formular($_POST, isset($wf_alt['ansage_token_herkunft']) ? $wf_alt['ansage_token_herkunft'] : array());
        foreach ($wf_tm as $wf_t) { $wf_fehler[] = $wf_t['text']; }
        foreach ($wf_tb as $wf_t) { $wf_bean[] = $wf_t; }
        foreach ($wf_th as $wf_t) { $wf_hinweise[] = $wf_t['text']; }
        // X-2: auch Sprachausgabe, Regeln und Ziele reisen zurueck, nie ein Token.
        foreach (array_merge(ansage_x2_felder(), ansage_regeln_x2_felder(), array('ansage_tafel_ein', 'ansage_tafel_namen', 'ansage_homepod_ein', 'ansage_homepod_geraet')) as $wf_xf) {     // 0.9.5: Tafel und HomePod dazu
            if (substr($wf_xf, -6) === '_token') { continue; }
            if (in_array($wf_xf, array('ansage_ruhe_ein', 'ansage_tafel_ein', 'ansage_homepod_ein'), true)) { $wf_werte[$wf_xf] = isset($_POST[$wf_xf]) ? '1' : '0'; continue; }
            if (isset($_POST[$wf_xf]) && is_string($_POST[$wf_xf]) && strlen($_POST[$wf_xf]) <= 4000) { $wf_werte[$wf_xf] = $_POST[$wf_xf]; }
        }
        foreach ($_POST as $wf_xf => $wf_xw) {
            if (strpos((string) $wf_xf, 'tts_ziel_') !== 0 || !is_array($wf_xw)) { continue; }
            foreach ($wf_xw as $wf_xn => $wf_xs) { if (is_string($wf_xs) && strlen($wf_xs) <= 200) { $wf_ziele_post[$wf_xf][(int) $wf_xn] = $wf_xs; } }
        }
    }
    if ($wf_fehler) {
        $wf_x2neu = array('form' => $wf_form, 'werte' => $wf_werte, 'bean' => array_values(array_unique($wf_bean)), 'ziele' => $wf_ziele_post);
    } elseif (wd_config_speichern($wf_neu)) {
        $wf_saved = true;
        wd_zweitschrift($wf_neu);
        @unlink(wd_tmpdir() . '/mqtt_sig.txt');
        if ($wf_form === 'mqtt_save') {
            wd_abo_nachziehen($wf_neu);
            // 0.9.8: Hausthemen haus/wetter - einschalten meldet, ausschalten raeumt im Broker ab (mit Ruecklesen).
            list($wf_hm, $wf_ha, $wf_hf) = wd_haus_folgen($wf_alt, $wf_neu);
            if ($wf_hf !== null) { wd_haus_frueher_setzen((bool) $wf_hf); }
            if ($wf_hm !== '') { $wf_note = $wf_hm; $wf_note_art = $wf_ha; }
        }
        if ($wf_form === 'mqtt_save' || $wf_form === 'pv_save') {
            // 0.9.10: PV-Prognose haus/pv - einschalten meldet, ausschalten (auch MQTT aus) raeumt im Broker ab.
            list($wf_pm2, $wf_pa2, $wf_pf2) = wd_pv_folgen($wf_alt, $wf_neu);
            if ($wf_pf2 !== null) { wd_pv_frueher_setzen((bool) $wf_pf2); }
            if ($wf_pm2 !== '') { if ($wf_note === '') { $wf_note = $wf_pm2; $wf_note_art = $wf_pa2; } else { $wf_hinweise[] = $wf_pm2; } }
            if ($wf_form === 'pv_save' && !empty($wf_neu['haus_pv_ein'])) {
                if (!wd_pv_flaechen($wf_neu)) { $wf_hinweise[] = wd_t('PV.H_KEINE_FLAECHE'); }
                if (wd_position($wf_neu) === null) { $wf_hinweise[] = wd_t('PV.T_STANDORT'); }
                if (empty($wf_neu['om_ein'])) { $wf_hinweise[] = wd_t('PV.T_OM_AUS'); }
            }
        }
    } else {
        $wf_err = wd_t('MELD.SPEICHERN_FEHL');
    }
}
// ---------- POST, Umleitung, GET ----------
if ($wf_post) {
    if (wd_json_schreiben(wf_einmal_datei(), array('zeit' => time(), 'saved' => $wf_saved ? 1 : 0, 'err' => $wf_err, 'note' => $wf_note, 'art' => $wf_note_art, 'fehler' => $wf_fehler, 'hinweise' => $wf_hinweise, 'x2' => $wf_x2neu, 'hilfe' => $wf_hilfe))) {
        header('Location: index.php?form=' . substr($wf_tab, 4), true, 303);
        exit;
    }
    $wf_x2 = $wf_x2neu;
} else {
    $wf_e = wf_einmal_lesen();
    if ($wf_e !== null) {
        $wf_saved = !empty($wf_e['saved']);
        if (is_string($wf_e['err']) && $wf_e['err'] !== '') { $wf_err = $wf_e['err']; }
        if (is_string($wf_e['note']) && $wf_e['note'] !== '') { $wf_note = $wf_e['note']; $wf_note_art = in_array($wf_e['art'], array('ok', 'warn', 'err'), true) ? $wf_e['art'] : 'ok'; }
        $wf_fehler = array_values(array_filter((array) $wf_e['fehler'], 'is_string'));
        $wf_hinweise = array_merge($wf_hinweise, array_values(array_filter((array) $wf_e['hinweise'], 'is_string')));
        $wf_x2 = is_array($wf_e['x2']) ? $wf_e['x2'] : null;
        $wf_hilfe = isset($wf_e['hilfe']) && is_array($wf_e['hilfe']) ? $wf_e['hilfe'] : array();
    }
}
$wf_cfg = wd_config();
$wf_st = wd_json_lesen(wd_tmpdir() . '/zustand.json');
if (!is_array($wf_st)) { $wf_st = array(); }
$wf_frame = class_exists('LBWeb', false);
$wf_bean = is_array($wf_x2) && isset($wf_x2['bean']) ? (array) $wf_x2['bean'] : array();
function wf_w($k)
{
    global $wf_cfg, $wf_x2;
    if (is_array($wf_x2) && isset($wf_x2['werte'][$k])) { return (string) $wf_x2['werte'][$k]; }
    return (string) $wf_cfg[$k];
}
function wf_m($k) { global $wf_bean; return in_array($k, $wf_bean, true) ? ' class="sm-beanstandet"' : ''; }
/** Auswahl aus einer Liste. Ein eingetragener Wert ausserhalb der Liste bleibt als eigene Zeile stehen und wird beim Speichern nicht verloren. */
function wf_auswahl($name, $k, $ist, $zusatz = '')
{
    $li = wd_auswahl_liste($k);
    $o = '<select data-role="none" id="' . wd_e($name) . '" name="' . wd_e($name) . '"' . $zusatz . '>';
    foreach ($li as $w => $t) { $o .= '<option value="' . wd_e((string) $w) . '"' . ((string) $w === (string) $ist ? ' selected' : '') . '>' . wd_e($t) . '</option>'; }
    if ((string) $ist !== '' && !array_key_exists((string) $ist, $li)) { $o .= '<option value="' . wd_e($ist) . '" selected>' . wd_e(sprintf(wd_t('TEXT.LISTE_EIGEN'), $ist)) . '</option>'; }
    return $o . '</select>';
}
function wf_verweis($u, $t) { return '<a href="' . wd_e($u) . '" target="_blank" rel="noopener">' . wd_e($t) . '</a>'; }
/** Ein Feld nach der Feldtabelle; Name und Hilfe aus der Sprachdatei. */
function wf_feld($k)
{
    $f = wd_felder()[$k];
    $v = wd_e(wf_w($k));
    $l = wd_e(wd_t('FELD.' . $k));
    $h = wd_t('HILFE.' . $k);
    $o = '<div class="sm-feld">';
    if ($f[1] === 'h') {
        $o .= '<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;"><input data-role="none" type="checkbox" name="' . $k . '" value="1"' . (wf_w($k) === '1' ? ' checked' : '') . wf_m($k) . '> ' . $l . '</label>';
    } elseif ($f[1] === 'a') {
        $o .= '<label for="' . $k . '">' . $l . '</label><select data-role="none" id="' . $k . '" name="' . $k . '"' . wf_m($k) . '>';
        foreach ($f[3] as $w) { $o .= '<option value="' . wd_e($w) . '"' . (wf_w($k) === $w ? ' selected' : '') . '>' . wd_e(wd_t('WAHL.' . $w)) . '</option>'; }
        $o .= '</select>';
    } elseif ($f[1] === 'g') {
        $o .= '<label for="' . $k . '">' . $l . '</label><input data-role="none" type="password" autocomplete="new-password" id="' . $k . '" name="' . $k . '" value="" placeholder="' . wd_e((string) $GLOBALS['wf_cfg'][$k] !== '' ? wd_t('TEXT.GESETZT') : '') . '"' . wf_m($k) . '>';
        $o .= '<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;"><input data-role="none" type="checkbox" name="' . $k . '_loeschen" value="1"> ' . wd_e(wd_t('TEXT.LOESCHEN')) . '</label>';
    } elseif ($f[1] === 'region' || $f[1] === 'land') {
        $o .= '<label for="' . $k . '">' . $l . '</label>' . wf_auswahl($k, $k, wf_w($k), wf_m($k));
    } elseif ($f[1] === 'u' || $f[1] === 'uleer') {
        $o .= '<label for="' . $k . '">' . $l . '</label><input data-role="none" type="time" id="' . $k . '" name="' . $k . '" value="' . $v . '"' . wf_m($k) . '>';
    } else {
        $o .= '<label for="' . $k . '">' . $l . '</label><input data-role="none" type="text" id="' . $k . '" name="' . $k . '" value="' . $v . '"' . wf_m($k) . '>';
    }
    if (strpos($h, 'HILFE.') !== 0) { $o .= '<div class="sm-hilfe">' . wd_e($h) . '</div>'; }
    $wf_vw = wd_feld_verweise();
    if (isset($wf_vw[$k])) { $wf_vt = array(); foreach ($wf_vw[$k] as $wf_vz) { $wf_vt[] = wf_verweis($wf_vz[0], wd_t($wf_vz[1])); } $o .= '<div class="sm-hilfe">' . implode(' &middot; ', $wf_vt) . '</div>'; }
    return $o . "</div>\n";
}
function wf_wert($w) { return $w === null || $w === '' ? '&ndash;' : wd_e(is_float($w) ? rtrim(rtrim(number_format($w, 1, '.', ''), '0'), '.') : (string) $w); }
function wf_alter($s) { return $s === null ? '&ndash;' : wd_e(sprintf(wd_t('TEXT.VOR'), $s < 120 ? $s . ' s' : round($s / 60) . ' min')); }
$wf_hk = wd_hakenliste($wf_cfg);
$wf_pf = wd_mqtt_praefix($wf_cfg);
$wf_gw = wd_gateway_fassung();
$wf_as = wd_gateway_autostart();
$wf_host = wd_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry');
if ($wf_frame) { LBWeb::lbheader('Weatherdata', 'https://wiki.loxberry.de/plugins/start', 'help.html'); }
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }

.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important;
  display: inline-block; text-decoration: none !important; }
.sm-tab:visited, .sm-tab:hover { text-decoration: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }

.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* 0.9.8: am Handy nie stumm abgeschnitten - sichtbarer Hinweis ueber jeder breiten Tabelle */
@media (max-width: 800px) { .sm-breit::before { content: "<?= str_replace(array('\\', '"'), '', wd_t('TEXT.WISCHEN')) ?>"; display: block; font-size: .85em; color: #666; margin-bottom: 4px; } }
#wd-hakenliste { table-layout: fixed; width: 100%; min-width: 0; }
#wd-hakenliste td, #wd-hakenliste th { overflow-wrap: anywhere; word-break: normal; vertical-align: top; }
.sm-breit.sm-passt::before { content: none; }
/* 0.9.9: Musterseiten- und Thementabelle passen in die Seitenbreite (kein Schieberegler ganz unten); lange Adressen brechen um. */
.sm-tbl.sm-fest { table-layout: fixed; width: 100%; min-width: 0; }
.sm-tbl.sm-fest td, .sm-tbl.sm-fest th { overflow-wrap: anywhere; word-break: normal; vertical-align: top; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }


.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }

.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }


.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }

.sm-tbl tr.sm-abweichung td { background: #fdf4ec; }
#wd-werte-jetzt { table-layout: fixed; width: 100%; min-width: 600px; }
#wd-werte-jetzt td.sm-zahl, #wd-werte-jetzt th.sm-zahl { text-align: right; white-space: nowrap; }
#wd-werte-jetzt td.sm-her { overflow-wrap: anywhere; }
#wd-werte-jetzt td, #wd-werte-jetzt th { overflow: hidden; text-overflow: ellipsis; vertical-align: top; }
#wd-werte-jetzt td.sm-her, #wd-werte-jetzt td:first-child, #wd-werte-jetzt th { white-space: normal; text-overflow: clip; }
.sm-tbl tr.sm-abweichung td:last-child { color: #b84f0a; }
.sm-wrap .sm-beanstandet { border: 2px solid #e0620d !important; background-color: #fdf4ec !important; }

.sm-bild { margin: 12px 0; }
.sm-dienst { border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px; }
.sm-dienst:first-of-type { border-top: 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.sm-wrap h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }

.sm-wahlreihe { display: flex; flex-wrap: wrap; gap: 8px; margin: 4px 0 2px; }
.sm-wrap button.sm-wahl { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border: 2px solid #ccc; border-radius: 8px; background: #fff; color: #333; cursor: pointer;
    font-size: 0.92em; text-shadow: none !important; box-shadow: none !important; }
.sm-wrap button.sm-wahl:hover, .sm-wrap button.sm-wahl:focus { border-color: #6dac20; background: #fff; color: #333; }
.sm-wrap button.sm-wahl[aria-pressed="true"] { border-color: #6dac20; border-width: 3px; padding: 6px 11px; background: #fff; font-weight: 700; }
.sm-farbmuster { display: inline-flex; border-radius: 5px; overflow: hidden; border: 1px solid #bbb; }
.sm-farbmuster i { width: 14px; height: 22px; display: block; }
.sm-wahl img { width: 32px; height: 32px; }
.sm-vorschau { margin: 12px 0; border: 1px solid #ccc; border-radius: 8px; padding: 8px; background: #fafafa; }
.sm-vorschau iframe { display: block; width: 100%; max-width: 100%; height: 760px; border: 1px solid #ddd; border-radius: 6px; background: #fff; margin: 0 auto; }
.sm-adresse { word-break: break-all; display: inline-block; margin: 4px 0; padding: 6px 8px; }
.sm-wrap details summary { cursor: pointer; font-size: 0.9em; color: #4f7d17; margin-top: 6px; }

.sm-wrap button.sm-kopier { font-size: 0.8em; padding: 1px 6px; margin-left: 4px; border: 1px solid #ccc; border-radius: 4px;
    background: #f5f5f5; color: #333; cursor: pointer; text-shadow: none !important; box-shadow: none !important; }

</style>
<div class="sm-wrap">
<?php if ($wf_saved) { ?><div class="sm-alert sm-ok"><b><?= wd_e(wd_t('TEXT.GESPEICHERT')) ?></b></div><?php } ?>
<?php if ($wf_note !== '') { ?><div class="sm-alert <?= $wf_note_art === 'err' ? 'sm-err' : ($wf_note_art === 'warn' ? 'sm-warn' : 'sm-ok') ?>"><?= wd_e($wf_note) ?></div><?php } ?>
<?php if ($wf_err !== '') { ?><div class="sm-alert sm-err"><b><?= wd_e(wd_t('TEXT.FEHLER')) ?></b> <?= wd_e($wf_err) ?></div><?php } ?>
<?php if ($wf_fehler) { ?><div class="sm-alert sm-warn"><b><?= wd_e(wd_t('TEXT.NICHT_UEBERNOMMEN')) ?></b><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($wf_fehler as $wf_t) { ?><li><?= wd_e($wf_t) ?></li><?php } ?></ul></div><?php } ?>
<?php if ($wf_hinweise) { ?><div class="sm-alert sm-warn"><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($wf_hinweise as $wf_t) { ?><li><?= wd_e($wf_t) ?></li><?php } ?></ul></div><?php } ?>

<table class="sm-tbl" style="max-width:620px">
<tr><th><?= wd_e(wd_t('KOPF.EIGENSCHAFT')) ?></th><th><?= wd_e(wd_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= wd_e(wd_t('KOPF.DIENST')) ?></td><td><?= wd_e(wd_t('KOPF.OHNE_DIENST')) ?></td></tr>
<tr><td><?= wd_e(wd_t('KOPF.STANDORT')) ?></td><td><?= wd_e(wd_t(wd_position($wf_cfg) !== null ? 'KOPF.EINGETRAGEN' : 'KOPF.FEHLT')) ?></td></tr>
<tr><td><?= wd_e(wd_t('KOPF.ZELLE')) ?></td><td><?= wd_e(wd_t(trim((string) $wf_cfg['warnzelle']) !== '' ? 'KOPF.EINGETRAGEN' : 'KOPF.FEHLT')) ?></td></tr>
<tr><td><?= wd_e(wd_t('KOPF.WARNSTUFE')) ?></td><td><?php if (empty($wf_st['warn_ok'])) { ?><?= wd_e(wd_t('KOPF.KEINE_AUSSAGE')) ?><?php } else { echo (int) (isset($wf_st['lagen'][0]) ? $wf_st['lagen'][0]['stufe'] : 0); } ?></td></tr>
<tr><td><?= wd_e(wd_t('KOPF.MQTT')) ?></td><td><?= wd_e(wd_t(!empty($wf_cfg['mqtt_ein']) ? 'KOPF.EIN' : 'KOPF.AUS')) ?><div class="sm-small"><?= wd_e(wd_t('KOPF.MQTT_KLEIN')) ?> <?= wd_e(wd_t($wf_as === null ? 'KOPF.UNKLAR' : ($wf_as ? 'KOPF.EIN' : 'KOPF.AUS'))) ?></div></td></tr>
<tr><td><?= wd_e(wd_t('KOPF.LAUF')) ?></td><td><?= !empty($wf_st['ts']) ? wd_e(date('d.m.Y H:i', (int) $wf_st['ts'])) : '&ndash;' ?></td></tr>
</table>

<div class="sm-tabs">
    <a class="sm-tab<?= wf_aktiv('tab-settings') ?>" data-pane="tab-settings" href="index.php?form=settings"><?= wd_e(wd_t('REITER.EINSTELLUNGEN')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-werte') ?>" data-pane="tab-werte" href="index.php?form=werte"><?= wd_e(wd_t('REITER.WERTE')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-quellen') ?>" data-pane="tab-quellen" href="index.php?form=quellen"><?= wd_e(wd_t('REITER.QUELLEN')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-warnungen') ?>" data-pane="tab-warnungen" href="index.php?form=warnungen"><?= wd_e(wd_t('REITER.WARNUNGEN')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-mqtt') ?>" data-pane="tab-mqtt" href="index.php?form=mqtt"><?= wd_e(wd_t('REITER.MQTT')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-loxone') ?>" data-pane="tab-loxone" href="index.php?form=loxone"><?= wd_e(wd_t('REITER.LOXONE')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-wetterseite') ?>" data-pane="tab-wetterseite" href="index.php?form=wetterseite"><?= wd_e(wd_t('REITER.WETTERSEITE')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-emulator') ?>" data-pane="tab-emulator" href="index.php?form=emulator"><?= wd_e(wd_t('REITER.EMULATOR')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-test') ?>" data-pane="tab-test" data-reload="1" href="index.php?form=test"><?= wd_e(wd_t('REITER.TEST')) ?></a>
    <a class="sm-tab<?= wf_aktiv('tab-log') ?>" data-pane="tab-log" href="index.php?form=log"><?= wd_e(wd_t('REITER.LOG')) ?></a>
</div>

<!-- Einstellungen -->
<div class="sm-pane<?= wf_aktiv('tab-settings') ?>" id="tab-settings">
<div class="sm-hinweis"><?= wd_e(wd_t('TEXT.WAS_IST_DAS')) ?></div>
<div class="sm-warnung"><?= wd_e(wd_t('TEXT.PFLICHTHINWEIS')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings"><input data-role="none" type="hidden" name="save" value="1">
<h2><?= wd_e(wd_t('TEXT.H_STANDORT')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.STANDORT_HILFE')) ?></div>
<?= wf_feld('lat') ?><?= wf_feld('lon') ?><?= wf_feld('pos_stellen') ?><?= wf_feld('warnzelle') ?>
<h2><?= wd_e(wd_t('TEXT.H_SCHWELLEN')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.SCHWELLEN_HILFE')) ?></div>
<?= wf_feld('hitze_basis') ?><?= wf_feld('hitze1_c') ?><?= wf_feld('hitze2_c') ?><?= wf_feld('hitze3_c') ?><?= wf_feld('hysterese_k') ?><?= wf_feld('frost_c') ?><?= wf_feld('sturm_kmh') ?><?= wf_feld('regen1h_mm') ?><?= wf_feld('regen12h_mm') ?><?= wf_feld('uv_hoch') ?><?= wf_feld('sturm_vor_min') ?><?= wf_feld('lueften_delta_k') ?><?= wf_feld('lueften_innen_min_c') ?>
<h2><?= wd_e(wd_t('TEXT.H_ZEITEN')) ?></h2>
<?= wf_feld('om_takt_min') ?><?= wf_feld('dwd_takt_min') ?><?= wf_feld('prog_max_alter_min') ?><?= wf_feld('warn_max_alter_min') ?><?= wf_feld('morgen_zeit') ?><?= wf_feld('mittag_zeit') ?><?= wf_feld('abend_zeit') ?><?= wf_feld('lueften_letzte') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings"><input data-role="none" type="hidden" name="zelle_finden" value="1"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_ZELLE')) ?></button></form>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings"><input data-role="none" type="hidden" name="abruf" value="1"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_ABRUF')) ?></button></form>
</div>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.ZELLE_HILFE')) ?></div>
<h2><?= wd_e(wd_t('SICH.H')) ?></h2>
<div class="sm-hinweis"><?= wd_e(wd_t('SICH.ERKLAERUNG')) ?></div>
<div class="sm-warnung"><?= wd_e(wd_t('SICH.WARNUNG')) ?></div>
<?php $wf_altw = wd_rueckspiel_altwerte(); if ($wf_altw) { ?><div class="sm-warnung" id="sicherung-altwerte"><?= wd_e(implode(' ', $wf_altw)) ?></div><?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= wd_e(wd_t('LEGENDE.LESEN')) ?></span><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="wd_sichern" value="1"><?= wd_e(wd_t('SICH.K_SICHERN')) ?></button></form>
  <form action="index.php" method="post" enctype="multipart/form-data"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-settings"><input data-role="none" type="file" name="wd_sicherung" accept=".json"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="wd_zurueck" value="1"><?= wd_e(wd_t('SICH.K_ZURUECK')) ?></button></form>
</div>
</div>

<!-- Werte -->
<div class="sm-pane<?= wf_aktiv('tab-werte') ?>" id="tab-werte">
<?php if (empty($wf_st)) { ?><div class="sm-alert sm-info"><?= wd_e(wd_t('TEXT.NOCH_KEIN_LAUF')) ?></div><?php } else { ?>
<h2><?= wd_e(wd_t('TEXT.H_JETZT')) ?></h2>
<?php $wf_vg = wd_vergleich($wf_st); $wf_dn = wd_herkunft_name($wf_st['dienst_her'] ?? ''); ?>
<div class="sm-breit"><table class="sm-tbl" id="wd-werte-jetzt"><colgroup><col style="width:22%"><col style="width:14%"><col style="width:22%"><col style="width:14%"><col style="width:14%"><col style="width:14%"></colgroup>
<tr><th><?= wd_e(wd_t('TEXT.T_WERT')) ?></th><th class="sm-zahl"><?= wd_e(wd_t('TEXT.T_STAND')) ?></th><th><?= wd_e(wd_t('TEXT.T_HERKUNFT')) ?></th><th class="sm-zahl"><?= wd_e(wd_t('WERTE.T_EIGEN')) ?></th><th class="sm-zahl"><?= wd_e(sprintf(wd_t('WERTE.T_DIENST'), $wf_dn)) ?></th><th class="sm-zahl"><?= wd_e(wd_t('WERTE.T_ABWEICHUNG')) ?></th></tr>
<?php $wf_ez = function ($w, $eh) { return ($w === null || $w === '') ? '&ndash;' : wf_wert($w) . ($eh !== '' ? '&nbsp;' . wd_e($eh) : ''); }; $wf_omk = (array) ($wf_st['dienst_om'] ?? array()); foreach ((array) $wf_st['jetzt'] as $wf_k => $wf_v) { $wf_z = isset($wf_vg[$wf_k]) ? $wf_vg[$wf_k] : array(null, null, null, null); $wf_eh = wd_vergleich_einheit($wf_k); ?><tr<?= $wf_z[3] === true ? ' class="sm-abweichung"' : '' ?>><td><?= wd_e(wd_t('THEMA.jetzt_' . $wf_k)) ?></td><td class="sm-zahl"><?= $wf_ez($wf_v, $wf_eh) ?></td><td class="sm-her"><?= wd_e(wd_herkunft_name($wf_st['herkunft'][$wf_k] ?? '')) ?></td><td class="sm-zahl"><?= $wf_ez($wf_z[0], $wf_eh) ?></td><td class="sm-zahl"><?= $wf_ez($wf_z[1], $wf_eh) ?><?= ($wf_z[1] !== null && in_array($wf_k, $wf_omk, true)) ? '<sup title="' . wd_e(wd_t('WERTE.FUSS_OM')) . '">1</sup>' : '' ?></td><td class="sm-zahl"><?= $wf_z[2] === null ? '&ndash;' : wd_e(($wf_z[2] > 0 && $wf_k !== 'windrichtung_grad' ? '+' : '') . rtrim(rtrim(number_format($wf_z[2], 1, '.', ''), '0'), '.')) . ($wf_eh !== '' && $wf_z[2] !== null ? '&nbsp;' . wd_e($wf_eh) : '') ?><?= $wf_z[3] === true ? '<br><b>' . wd_e(wd_t('WERTE.DEUTLICH')) . '</b>' : '' ?></td></tr><?php } ?>
<tr><td><?= wd_e(wd_t('THEMA.gefuehlt_c')) ?></td><td class="sm-zahl"><?= $wf_ez($wf_st['gefuehlt'], '°C') ?></td><td class="sm-her"><?= wd_e(wd_gefuehlt_herkunft($wf_st)) ?></td><td class="sm-zahl">&ndash;</td><td class="sm-zahl">&ndash;</td><td class="sm-zahl">&ndash;</td></tr>
</table></div>
<div class="sm-small" id="wd-werte-quelle"><?php if (!empty($wf_st['dienst_om'])) { ?><sup>1</sup> <?= wd_e(wd_t('WERTE.FUSS_OM')) ?> <?php } ?><?= wd_e(wd_t('WERTE.QUELLE')) ?></div>
<div class="sm-hilfe" id="wd-werte-vergleich"><?= wd_e(wd_t('WERTE.HILFE')) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_PROGNOSE')) ?></h2>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_WERT')) ?></th><th><?= wd_e(wd_t('TEXT.T_STAND')) ?></th></tr>
<?php foreach ((array) $wf_st['prog'] as $wf_k => $wf_v) { ?><tr><td><?= wd_e(wd_t('THEMA.prog_' . str_replace('/', '_', $wf_k))) ?></td><td><?= wf_wert($wf_v) ?></td></tr><?php } ?>
<?php foreach ((array) $wf_st['tage'] as $wf_i => $wf_t) { ?><tr><td><?= wd_e(sprintf(wd_t('TEXT.T_TAG'), (int) $wf_i)) ?></td><td><?= wf_wert($wf_t['tmin']) ?> / <?= wf_wert($wf_t['tmax']) ?> &deg;C, Code <?= wf_wert($wf_t['code']) ?></td></tr><?php } ?>
</table></div>
<h2><?= wd_e(wd_t('TEXT.H_SIGNALE')) ?></h2>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_WERT')) ?></th><th><?= wd_e(wd_t('TEXT.T_STAND')) ?></th></tr>
<?php foreach ((array) $wf_st['schwelle'] as $wf_k => $wf_v) { ?><tr><td><?= wd_e(wd_t('THEMA.schwelle_' . $wf_k)) ?></td><td><?= wf_wert($wf_v) ?></td></tr><?php } ?>
<tr><td><?= wd_e(wd_t('THEMA.hitze_warnung')) ?></td><td><?= wf_wert($wf_st['hitze_warnung']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.kuehlen_moeglich')) ?></td><td><?= wf_wert($wf_st['kuehlen_moeglich']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.uv_hoch')) ?></td><td><?= wf_wert($wf_st['uv_hoch']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.frost_erwartet')) ?></td><td><?= wf_wert($wf_st['frost_erwartet']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.starkregen_warnung')) ?></td><td><?= wf_wert($wf_st['starkregen']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.sturm_vorwarnung')) ?></td><td><?= wf_wert($wf_st['sturm_vor']) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.lueftung_sperren')) ?></td><td><?= wf_wert($wf_st['lueftung_sperren'] ?? null) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.luft_index')) ?></td><td><?= wf_wert($wf_st['luft']['index'] ?? null) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.radar_regen_in_min')) ?></td><td><?= wf_wert($wf_st['radar']['min'] ?? null) ?></td></tr>
<tr><td><?= wd_e(wd_t('THEMA.bilanz_fehler_k')) ?></td><td><?= wf_wert($wf_st['bilanz_fehler'] ?? null) ?></td></tr>
</table></div>
<?php } ?>
</div>

<!-- Quellen -->
<div class="sm-pane<?= wf_aktiv('tab-quellen') ?>" id="tab-quellen">
<div class="sm-hinweis"><?= wd_e(wd_t('TEXT.QUELLEN_HILFE')) ?></div>
<?php if (!empty($wf_st['quellen'])) { ?>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_QUELLE')) ?></th><th><?= wd_e(wd_t('TEXT.T_LAGE')) ?></th><th><?= wd_e(wd_t('TEXT.T_ALTER')) ?></th><th><?= wd_e(wd_t('TEXT.T_MELDUNG')) ?></th></tr>
<?php foreach ((array) $wf_st['quellen'] as $wf_k => $wf_q) { list($wf_ql, $wf_qm) = wd_quelle_anzeige($wf_k, $wf_q, $wf_cfg, (array) $wf_st['quellen']); ?><tr><td><?= wd_e(wd_t('QUELLE.N_' . $wf_k)) ?></td><td><?= wd_e($wf_ql) ?></td><td><?= wf_alter($wf_q['alter_s']) ?></td><td class="sm-small"><?= wd_e($wf_qm) ?></td></tr><?php } ?>
</table></div><?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= wd_e(wd_t('LEGENDE.LESEN')) ?></span><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-quellen"><input data-role="none" type="hidden" name="quellen_save" value="1">
<h2><?= wd_e(wd_t('TEXT.H_Q_PROG')) ?></h2>
<?= wf_feld('om_ein') ?><?= wf_feld('om_apikey') ?><?= wf_feld('mosmix_ein') ?><?= wf_feld('met_ein') ?><?= wf_feld('wttr_ein') ?><?= wf_feld('bias_ein') ?><?= wf_feld('fassade_ein') ?><?= wf_feld('fassade_az') ?>
<h2><?= wd_e(wd_t('TEXT.H_Q_WARN')) ?></h2>
<?= wf_feld('dwd_ein') ?><?= wf_feld('bs_ein') ?><?= wf_feld('radar_ein') ?>
<h2><?= wd_e(wd_t('TEXT.H_Q_STATION')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.STATION_HILFE')) ?></div>
<?= wf_feld('station_ein') ?><?= wf_feld('station_art') ?><?= wf_feld('station_ordner') ?><?= wf_feld('station_host1') ?><?= wf_feld('station_host2') ?><?= wf_feld('station_gleich_min') ?><?= wf_feld('station_sprung_k') ?>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.EMPFANG_HILFE')) ?></div>
<div class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd_empfang.php?token=<?= wd_e($wf_cfg['aktionstoken']) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_Q_UMWELT')) ?></h2>
<?= wf_feld('aq_ein') ?><?= wf_feld('pollen_ein') ?><?= wf_feld('pollen_region') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<?php /* 0.9.10: PV-Prognose ohne Konto (haus/pv), eigenes Formular pv_save; ab Werk aus. */ ?>
<h2 id="wd-pv"><?= wd_e(wd_t('PV.H')) ?></h2>
<div class="sm-hinweis"><?= wd_e(wd_t('PV.TEXT')) ?></div>
<?php list($wf_po, $wf_pt) = wd_pv_testzeile($wf_cfg); ?><div class="sm-alert <?= $wf_po === false ? 'sm-warn' : ($wf_po ? 'sm-ok' : 'sm-info') ?>" id="wd-pv-lage"><?= wd_e($wf_pt) ?></div>
<?php $wf_pe = wd_pv_ergebnis($wf_cfg); if (!empty($wf_pe['ok'])) { ?>
<div class="sm-breit sm-passt"><table class="sm-tbl sm-fest" id="wd-pv-tage"><colgroup><col style="width:28%"><col style="width:24%"><col style="width:24%"><col style="width:24%"></colgroup><tr><th><?= wd_e(wd_t('PV.T_TAG')) ?></th><th><?= wd_e(wd_t('PV.T_KWH')) ?></th><th><?= wd_e(wd_t('PV.T_SPITZE')) ?></th><th><?= wd_e(wd_t('PV.T_UM')) ?></th></tr>
<?php foreach (array('heute' => 'PV.HEUTE', 'morgen' => 'PV.MORGEN') as $wf_ptg => $wf_ptx) { $wf_pd = $wf_pe[$wf_ptg]; ?><tr><td><?= wd_e(wd_t($wf_ptx)) ?></td><td><?= is_array($wf_pd) ? wd_e(wd_pv_zahl($wf_pd['kwh'])) . '&nbsp;kWh' : '&ndash;' ?></td><td><?= is_array($wf_pd) ? wd_e(wd_pv_zahl($wf_pd['spitze_w'] / 1000, 2)) . '&nbsp;kW' : '&ndash;' ?></td><td><?= (is_array($wf_pd) && $wf_pd['spitze_zeit'] !== null) ? wd_e(sprintf(wd_t('PV.UHR'), $wf_pd['spitze_zeit'])) : '&ndash;' ?></td></tr><?php } ?>
</table></div>
<div class="sm-small"><?= wd_e(sprintf(wd_t('PV.FUSS'), $wf_pe['rest_kwh'] === null ? '-' : wd_pv_zahl($wf_pe['rest_kwh']), $wf_pe['jetzt_w'] === null ? '-' : wd_pv_zahl($wf_pe['jetzt_w'] / 1000, 2))) ?></div>
<?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post" id="wd-pv-form">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-quellen"><input data-role="none" type="hidden" name="pv_save" value="1">
<?= wf_feld('haus_pv_ein') ?>
<div class="sm-hilfe"><?= wd_e(wd_t('PV.FLAECHEN_HILFE')) ?></div>
<?php for ($wf_pi = 1; $wf_pi <= 4; $wf_pi++) { ?><div class="sm-step" id="wd-pv-flaeche<?= $wf_pi ?>">
<h3 class="sm-h3"><?= wd_e(sprintf(wd_t('PV.H_FLAECHE'), $wf_pi)) ?></h3>
<?= wf_feld('pv' . $wf_pi . '_name') ?><?= wf_feld('pv' . $wf_pi . '_kwp') ?><?= wf_feld('pv' . $wf_pi . '_neigung') ?><?= wf_feld('pv' . $wf_pi . '_az') ?>
<?= wf_feld('pv' . $wf_pi . '_schatten_morgen') ?><?= wf_feld('pv' . $wf_pi . '_schatten_abend') ?>
</div>
<?php } ?>
<?= wf_feld('pv_faktor') ?><?= wf_feld('pv_temp_beiwert') ?><?= wf_feld('pv_wr_max_w') ?>
<div class="sm-hilfe"><?= wd_e(wd_t('PV.SCHNEE_HILFE')) ?></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<h3 class="sm-h3"><?= wd_e(wd_t('PV.H_THEMEN')) ?></h3>
<div class="sm-breit sm-passt"><table class="sm-tbl sm-fest" id="wd-pv-themen"><colgroup><col style="width:38%"><col style="width:14%"><col style="width:48%"></colgroup><tr><th><?= wd_e(wd_t('HAUS.T_THEMA')) ?></th><th><?= wd_e(wd_t('HAUS.T_BLEIBT')) ?></th><th><?= wd_e(wd_t('HAUS.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (wd_pv_themen() as $wf_pth => $wf_pr) { ?><tr><td><span class="sm-mono"><?= wd_e($wf_pth) ?></span></td><td><?= wd_e(wd_t($wf_pr ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td class="sm-small"><?= wd_e(wd_t('PV.B_' . strtoupper(str_replace(array('haus/pv/', '/'), array('', '_'), $wf_pth)))) ?></td></tr><?php } ?>
</table></div>
<div class="sm-small"><?= wd_e(wd_t('PV.LEER')) ?></div>
<h2 id="wd-zusatz"><?= wd_e(wd_t('TEXT.H_Q_AUS')) ?></h2>
<?php /* 0.9.8: EIN Formular, je Dienst ein Kasten. Die Such- und Uebernehmen-Knoepfe senden dasselbe Formular mit eigenem name;
   ihr Handler oben speichert es nie (zusatz_save faellt weg). Das Formulartoken steht innerhalb des Formulars. */ ?>
<form action="index.php" method="post" id="wd-zusatz-form">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-quellen"><input data-role="none" type="hidden" name="zusatz_save" value="1">
<div class="sm-step" id="wd-kasten-nina">
<h3 class="sm-h3"><?= wd_e(wd_t('TEXT.H_NINA')) ?></h3>
<div class="sm-hilfe"><?= wd_e(wd_t('Q2.NINA_WOZU')) ?> <?= wd_e(wd_t('Q2.NINA_BED')) ?></div>
<?= wf_feld('nina_ein') ?>
<div class="sm-feld" id="wd-ars-suche"><label for="nina_such"><?= wd_e(wd_t('Q2.ARS_FELD')) ?></label><input data-role="none" type="text" id="nina_such" name="nina_such" maxlength="60" value="<?= wd_e(isset($wf_hilfe['such']) ? $wf_hilfe['such'] : '') ?>">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="nina_suchen" value="1"><?= wd_e(wd_t('TEXT.K_ARS_SUCHEN')) ?></button>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.ARS_HILFE')) ?></div></div>
<?php if (!empty($wf_hilfe['ars'])) { ?><div class="sm-feld" id="wd-ars-treffer"><label for="nina_wert"><?= wd_e(wd_t('TEXT.TREFFER')) ?></label><select data-role="none" id="nina_wert" name="nina_wert"><?php foreach ((array) $wf_hilfe['ars'] as $wf_z) { ?><option value="<?= wd_e($wf_z[0]) ?>"><?= wd_e($wf_z[1] . ' (' . $wf_z[0] . ')') ?></option><?php } ?></select>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="nina_setzen" value="1"><?= wd_e(wd_t('Q2.K_UEBERNEHMEN')) ?></button></div><?php } ?>
<?= wf_feld('nina_ars') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zusatz_speichern" value="1"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</div>
<div class="sm-step" id="wd-kasten-ma">
<h3 class="sm-h3"><?= wd_e(wd_t('TEXT.H_MA')) ?></h3>
<div class="sm-warnung"><?= wd_e(wd_t('TEXT.MA_NUR_AUSSEN')) ?></div>
<div class="sm-hilfe"><?= wd_e(wd_t('Q2.MA_BED')) ?></div>
<?= wf_feld('ma_ein') ?><?= wf_feld('ma_land') ?>
<div class="sm-feld" id="wd-ma-suche"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ma_suchen" value="1"><?= wd_e(wd_t('TEXT.K_MA_SUCHEN')) ?></button>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.MA_HILFE')) ?></div></div>
<?php if (!empty($wf_hilfe['ma'])) { ?><div class="sm-feld" id="wd-ma-treffer"><input data-role="none" type="hidden" name="ma_wert_land" value="<?= wd_e($wf_hilfe['ma_land']) ?>"><label for="ma_wert"><?= wd_e(wd_t('TEXT.TREFFER')) ?></label><select data-role="none" id="ma_wert" name="ma_wert"><?php foreach ((array) $wf_hilfe['ma'] as $wf_a => $wf_b) { ?><option value="<?= wd_e($wf_a) ?>"><?= wd_e($wf_b . ' (' . $wf_a . ')') ?></option><?php } ?></select>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ma_setzen" value="1"><?= wd_e(wd_t('Q2.K_UEBERNEHMEN')) ?></button></div><?php } ?>
<?= wf_feld('ma_emma') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zusatz_speichern" value="1"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</div>
<div class="sm-step" id="wd-kasten-schluessel">
<h3 class="sm-h3"><?= wd_e(wd_t('TEXT.H_SCHLUESSEL')) ?></h3>
<div class="sm-hilfe"><?= wd_e(wd_t('Q2.SCHL_BED')) ?></div>
<?php foreach (array('ow', 'wa', 'vc', 'tm', 'mb') as $wf_sd) { ?><div class="sm-dienst" id="wd-dienst-<?= $wf_sd ?>"><?= wf_feld($wf_sd . '_ein') ?><?= wf_feld($wf_sd . '_key') ?></div>
<?php } ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zusatz_speichern" value="1"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</div>
<div class="sm-step" id="wd-kasten-wu">
<h3 class="sm-h3" id="wd-wu"><?= wd_e(wd_t('WU.H')) ?></h3>
<div class="sm-hinweis"><?= wd_e(wd_t('WU.NUR')) ?> <a href="https://www.wunderground.com/member/api-keys" target="_blank" rel="noopener">www.wunderground.com/member/api-keys</a></div>
<div class="sm-hilfe"><?= wd_e(wd_t('WU.BRAUCHT_NICHT')) ?></div>
<ol class="sm-schritte" id="wd-wu-schritte"><li><?= wd_e(wd_t('WU.S1')) ?></li><li><?= wd_e(wd_t('WU.S2')) ?></li><li><?= wd_e(wd_t('WU.S3')) ?></li><li><?= wd_e(wd_t('WU.S4')) ?></li></ol>
<?= wf_feld('wu_ein') ?><?= wf_feld('wu_station') ?><?= wf_feld('wu_key') ?>
<div class="sm-hilfe"><?= wd_e(wd_t('WU.TAKT')) ?></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="zusatz_speichern" value="1"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= wd_e(wd_t('TEXT.H_NENNUNG')) ?></h2>
<ul class="sm-schritte">
<li><?= wd_e(wd_t('NENNUNG.OM')) ?> <a href="https://open-meteo.com/" target="_blank" rel="noopener">open-meteo.com</a> (CC BY 4.0)</li>
<li><?= wd_e(wd_t('NENNUNG.DWD')) ?> <a href="https://www.dwd.de/" target="_blank" rel="noopener">dwd.de</a></li>
<li><?= wd_e(wd_t('NENNUNG.BS')) ?> <a href="https://brightsky.dev/" target="_blank" rel="noopener">brightsky.dev</a></li>
<li><?= wd_e(wd_t('NENNUNG.MET')) ?> <a href="https://www.met.no/" target="_blank" rel="noopener">met.no</a></li>
<li><?= wd_e(wd_t('NENNUNG.CAMS')) ?></li>
<li><?= wd_e(wd_t('NENNUNG.WTTR')) ?> <a href="https://wttr.in/" target="_blank" rel="noopener">wttr.in</a></li>
<li><?= wd_e(wd_t('NENNUNG.MA')) ?> <a href="https://www.meteoalarm.org/" target="_blank" rel="noopener">www.meteoalarm.org</a><?php $wf_md = wd_ma_dienst((string) $wf_cfg['ma_land']); if ($wf_md !== '') { ?> <span id="wd-ma-dienst"><?= wd_e(sprintf(wd_t('SEITE.Q_MA_DIENST'), $wf_md)) ?></span><?php } ?></li>
<li><?= wd_e(wd_t('NENNUNG.WU')) ?></li>
</ul>
<div class="sm-hilfe"><?= wd_e(wd_t('NENNUNG.WO')) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_BILANZ')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.BILANZ_HILFE')) ?></div>
<?php $wf_bi = wd_bilanz_lage(); if (!$wf_bi) { ?><div class="sm-alert sm-info"><?= wd_e(wd_t('TEXT.BILANZ_LEER')) ?></div><?php } else { ?>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_QUELLE')) ?></th><th>0-6 h</th><th>6-24 h</th><th>24-72 h</th></tr>
<?php foreach ($wf_bi as $wf_q => $wf_b) { ?><tr><td><?= wd_e(wd_t('QUELLE.N_' . $wf_q)) ?></td><?php foreach (array('f0', 'f6', 'f24') as $wf_f) { ?><td><?= isset($wf_b[$wf_f]) ? wd_e(sprintf(wd_t('TEXT.BILANZ_Z'), round($wf_b[$wf_f][1], 1), (int) $wf_b[$wf_f][0], round(wd_gewicht($wf_bi, $wf_q, $wf_f), 2))) : '&ndash;' ?></td><?php } ?></tr><?php } ?>
</table></div><?php } ?>
</div>

<!-- Warnungen -->
<div class="sm-pane<?= wf_aktiv('tab-warnungen') ?>" id="tab-warnungen">
<div class="sm-hinweis"><?= wd_e(wd_t('TEXT.WARN_HILFE')) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_LAGEN')) ?></h2>
<?php if (empty($wf_st['warn_ok'])) { ?><div class="sm-alert sm-warn"><?= wd_e(wd_t('KOPF.KEINE_AUSSAGE')) ?></div>
<?php } elseif (empty($wf_st['lagen'])) { ?><div class="sm-alert sm-ok"><?= wd_e(wd_t('TEXT.KEINE_WARNUNG')) ?></div>
<?php } else { ?><div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_STUFE')) ?></th><th><?= wd_e(wd_t('TEXT.T_SATZ')) ?></th><th><?= wd_e(wd_t('TEXT.T_REGEL')) ?></th></tr>
<?php foreach ((array) $wf_st['lagen'] as $wf_l) { $wf_rg = wd_warn_regel($wf_l); ?><tr><td><?= (int) $wf_l['stufe'] ?></td><td><?= wd_e(wd_warn_satz($wf_l, time(), wd_sprache())) ?></td><td class="sm-small"><?= wd_e($wf_rg[0] . ' / ' . $wf_rg[1]) ?></td></tr><?php } ?>
</table></div><?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-warnungen"><input data-role="none" type="hidden" name="warn_save" value="1">
<h2><?= wd_e(wd_t('TEXT.H_ANLAESSE')) ?></h2>
<?= wf_feld('a_warn') ?><?= wf_feld('a_hitze') ?><?= wf_feld('a_uv') ?><?= wf_feld('a_frost') ?><?= wf_feld('a_starkregen') ?><?= wf_feld('a_sturm') ?><?= wf_feld('a_lueften') ?>
<?= wf_feld('a_briefing') ?><?= wf_feld('a_nina') ?>
<h2><?= wd_e(wd_t('TEXT.H_POLLEN')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.POLLEN_HILFE')) ?></div>
<?= wf_feld('p_hasel') ?><?= wf_feld('p_erle') ?><?= wf_feld('p_esche') ?><?= wf_feld('p_birke') ?><?= wf_feld('p_graeser') ?><?= wf_feld('p_roggen') ?><?= wf_feld('p_beifuss') ?><?= wf_feld('p_ambrosia') ?><?= wf_feld('pollen_sperre') ?><?= wf_feld('luft_sperre_aqi') ?>
<h2><?= wd_e(wd_t('TEXT.H_STUFE1')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.STUFE1_HILFE')) ?></div>
<?= wf_feld('s1_wind') ?><?= wf_feld('s1_frost') ?><?= wf_feld('s1_schnee') ?><?= wf_feld('s1_nebel') ?><?= wf_feld('s1_glaette') ?><?= wf_feld('ansage_sprache') ?>
<h2><?= wd_e(wd_t('TEXT.H_SPRACHE')) ?></h2>
<?php
$wf_hooks = array(
    'w' => function ($n, $g) { global $wf_x2; return (is_array($wf_x2) && ($wf_x2['form'] ?? '') === 'warn_save' && isset($wf_x2['werte'][$n])) ? $wf_x2['werte'][$n] : $g; },
    'm' => function ($n) { return wf_m($n); },
    'c' => function ($n, $g) { global $wf_x2; return (is_array($wf_x2) && ($wf_x2['form'] ?? '') === 'warn_save' && isset($wf_x2['werte'][$n])) ? $wf_x2['werte'][$n] === '1' : (bool) $g; },
);
echo ansage_formular_html($wf_cfg['tts'], $wf_hooks + array('modi' => wd_ansage_modi(), 'ziele' => true, 'ziele_post' => (is_array($wf_x2) && ($wf_x2['form'] ?? '') === 'warn_save' && isset($wf_x2['ziele']['tts_ziel_art']) && is_array($wf_x2['ziele'])) ? $wf_x2['ziele'] : null), wd_ansage_k());
echo ansage_regeln_html(wd_ansage_regeln($wf_cfg), array(), array(), $wf_hooks + array('token_vor_ort' => true), wd_ansage_k());
?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<div class="sm-hilfe" style="margin-top:8px;"><?= wd_e(wd_t('TTS.H_TESTANSAGE')) ?></div>
</div>

<!-- MQTT -->
<div class="sm-pane<?= wf_aktiv('tab-mqtt') ?>" id="tab-mqtt">
<div class="sm-hinweis"><?= wd_e(wd_t('TEXT.MQTT_HILFE')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><input data-role="none" type="hidden" name="mqtt_save" value="1">
<?= wf_feld('mqtt_ein') ?><?= wf_feld('mqtt_topic') ?><?= wf_feld('haus_wetter_ein') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<h2 id="wd-haus"><?= wd_e(wd_t('HAUS.H')) ?></h2>
<div class="sm-hinweis"><?= wd_e(wd_t('HAUS.TEXT')) ?></div>
<?php list($wf_ho, $wf_ht) = wd_haus_testzeile($wf_cfg); ?><div class="sm-alert <?= $wf_ho === false ? 'sm-warn' : ($wf_ho ? 'sm-ok' : 'sm-info') ?>" id="wd-haus-lage"><?= wd_e($wf_ht) ?></div>
<?php $wf_hg = array(); foreach (wd_haus_themen() as $wf_ht2 => $wf_hr) { $wf_hs = explode('/', $wf_ht2); $wf_hk2 = $wf_hs[2]; $wf_hg[$wf_hk2]['r'] = $wf_hr; $wf_hg[$wf_hk2]['t'][] = count($wf_hs) > 3 ? $wf_hs[3] : ''; } ?>
<div class="sm-breit sm-passt"><table class="sm-tbl sm-fest" id="wd-haus-themen"><colgroup><col style="width:34%"><col style="width:14%"><col style="width:52%"></colgroup><tr><th><?= wd_e(wd_t('HAUS.T_THEMA')) ?></th><th><?= wd_e(wd_t('HAUS.T_BLEIBT')) ?></th><th><?= wd_e(wd_t('HAUS.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ($wf_hg as $wf_hk2 => $wf_hz) { $wf_hf2 = array_values(array_filter($wf_hz['t'])); ?><tr><td><span class="sm-mono">haus/wetter/<?= wd_e($wf_hk2) ?><?= $wf_hf2 ? '/' . wd_e(implode(' | ', $wf_hf2)) : '' ?></span></td><td><?= wd_e(wd_t($wf_hz['r'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td class="sm-small"><?= wd_e(wd_t('HAUS.B_' . strtoupper($wf_hk2))) ?></td></tr><?php } ?>
</table></div>
<div class="sm-small"><?= wd_e(wd_t('HAUS.LEER')) ?></div>
<div class="sm-hilfe" id="wd-pv-verweis"><?= wd_e(wd_t('PV.MQTT_VERWEIS')) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_GATEWAY')) ?></h2>
<div class="sm-small sm-mono"><?= wd_e(sprintf(wd_t('TEXT.GATEWAY'), (int) $wf_gw)) ?></div>
<?php list($wf_vo, $wf_vt) = wd_v2_lage($wf_cfg); ?><div class="sm-alert <?= $wf_vo === false ? 'sm-warn' : 'sm-info' ?>"><?= wd_e($wf_vt) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_THEMEN')) ?></h2>
<div class="sm-breit sm-passt"><table class="sm-tbl" id="wd-hakenliste"><colgroup><col style="width:5%"><col style="width:25%"><col style="width:23%"><col style="width:8%"><col style="width:9%"><col style="width:10%"><col style="width:20%"></colgroup><tr><th>#</th><th><?= wd_e(wd_t('TEXT.T_THEMA')) ?></th><th><?= wd_e(wd_t('TEXT.T_NAME')) ?></th><th><?= wd_e(wd_t('TEXT.T_RETAIN')) ?></th><th><?= wd_e(wd_t('TEXT.T_NC')) ?></th><th><?= wd_e(wd_t('TEXT.T_PFLICHT')) ?></th><th><?= wd_e(wd_t('TEXT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ($wf_hk as $wf_i => $wf_h) { ?><tr><td><?= (int) $wf_i + 1 ?></td><td><span class="sm-mono"><?= wd_e($wf_h['thema']) ?></span></td><td><span class="sm-mono"><?= wd_e($wf_h['name']) ?></span></td><td><?= wd_e(wd_t($wf_h['retain'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td><?= wd_e(wd_t($wf_h['nc'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td><?= wd_e(wd_t($wf_h['pflicht'] === 'P' ? 'TEXT.PFLICHT' : 'TEXT.WAHL')) ?></td><td class="sm-small"><?= wd_e($wf_h['text']) ?></td></tr><?php } ?>
</table></div>
<div class="sm-small"><?= wd_e(wd_t('TEXT.MQTT_PFLICHT')) ?> <?= wd_e(wd_t('TEXT.MQTT_FUSS')) ?></div>
</div>

<!-- Einbindung in Loxone -->
<div class="sm-pane<?= wf_aktiv('tab-loxone') ?>" id="tab-loxone">
<div class="sm-hinweis"><?= wd_e(wd_t('EINB.KASTEN')) ?></div>
<div class="sm-step"><b><?= wd_e(wd_t('EINB.S1_T')) ?></b><br><?= wd_e(wd_t('EINB.S1')) ?></div>
<div class="sm-step"><b><?= wd_e(wd_t('EINB.S2_T')) ?></b><br><?= wd_e(wd_t('EINB.S2')) ?></div>
<div class="sm-step"><b><?= wd_e(wd_t('EINB.S3_T')) ?></b><br><?= wd_e(wd_t('EINB.S3')) ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= wd_e(wd_t('LEGENDE.TECHNIK')) ?></span></div>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-loxone"><input data-role="none" type="hidden" name="vorlage" value="1"><button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= wd_e(wd_t('EINB.K_VORLAGE')) ?></button></form>
</div>
<div class="sm-step"><b><?= wd_e(wd_t('EINB.S4_T')) ?></b><br><?= wd_e(wd_t('EINB.S4')) ?></div>
<h2><?= wd_e(wd_t('EINB.H_TABELLE')) ?></h2>
<div class="sm-small"><?= wd_e(wd_t('EINB.TABELLE_HILFE')) ?></div>
<div class="sm-hilfe"><?= wd_e(wd_t('EINB.MUSTERPROJEKT')) ?> <a href="https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt" target="_blank" rel="noopener">LoxBerry-Plugins Musterprojekt</a></div>
<?php $wf_bilder = array('einbindung_weatherdata.png', 'einbindung_weatherdata_werte.png'); $wf_bi = 0; foreach (wd_bausteinliste($wf_cfg) as $wf_st_titel => $wf_zl) { ?><h3 class="sm-h3"><?= wd_e(sprintf(wd_t('EINB.H_SEITE'), $wf_st_titel)) ?></h3>
<div class="sm-breit sm-passt"><table class="sm-tbl sm-fest"><colgroup><col style="width:5%"><col style="width:17%"><col style="width:21%"><col style="width:35%"><col style="width:22%"></colgroup><tr><th><?= wd_e(wd_t('EINB.T_NR')) ?></th><th><?= wd_e(wd_t('EINB.T_BAUSTEIN')) ?></th><th><?= wd_e(wd_t('EINB.T_NAME')) ?></th><th><?= wd_e(wd_t('EINB.T_PARAMETER')) ?></th><th><?= wd_e(wd_t('EINB.T_VERBINDEN')) ?></th></tr>
<?php foreach ($wf_zl as $wf_z) { ?><tr><td><?= (int) $wf_z[0] ?></td><td><?= wd_e($wf_z[1]) ?></td><td><span class="sm-mono"><?= wd_e($wf_z[2]) ?></span></td><td class="sm-small"><?= wd_e($wf_z[3]) ?></td><td class="sm-small"><?= wd_e($wf_z[4]) ?></td></tr><?php } ?>
</table></div>
<?php if (isset($wf_bilder[$wf_bi]) && is_file(__DIR__ . '/' . $wf_bilder[$wf_bi])) { ?><figure class="sm-bild" id="wd-bild-<?= (int) $wf_bi + 1 ?>">
<img src="<?= wd_e($wf_bilder[$wf_bi]) ?>" alt="<?= wd_e(sprintf(wd_t('EINB.BILD_ALT'), $wf_st_titel)) ?>" loading="lazy">
<figcaption><?= wd_e(sprintf(wd_t('EINB.BILD_UNTERSCHRIFT'), $wf_st_titel)) ?></figcaption>
</figure><?php } $wf_bi++; } ?>
<h2><?= wd_e(wd_t('EINB.H_W4L')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('EINB.W4L_HILFE')) ?></div>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('EINB.T_ALT')) ?></th><th><?= wd_e(wd_t('EINB.T_NEU')) ?></th></tr>
<?php foreach (array('T1' => 'jetzt/temp_c', 'T2' => 'jetzt/rf_pct', 'T3' => 'jetzt/wind_kmh', 'T4' => 'jetzt/regen_mmh', 'T5' => 'jetzt/strahlung_wm2', 'T6' => 'status/zaehler', 'T7' => 'jetzt/uv', 'T8' => 'prog/h12/tmin_c', 'T9' => 'prog/h12/regen_mm', 'T10' => 'prog/h12/tmax_c', 'T11' => 'prog/h24/tmax_c', 'T12' => 'prog/h24/schnee_cm', 'T13' => 'prog/h4/solar_wm2', 'T14' => 'prog/h12/solar_wm2', 'T15' => 'prog/h24/solar_wm2', 'T16' => 'prog/h48/solar_wm2', 'T17' => 'prog/tag0/tmax_c', 'T18' => 'prog/tag1/tmax_c', 'T19' => 'prog/tag2/tmax_c') as $wf_a => $wf_b) { ?><tr><td><?= wd_e(wd_t('W4L.' . $wf_a)) ?></td><td><span class="sm-mono"><?= wd_e($wf_pf . '/' . $wf_b) ?></span></td></tr><?php } ?>
</table></div>
<h2><?= wd_e(wd_t('EINB.H_ENDPUNKT')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('EINB.ENDPUNKT_HILFE')) ?></div>
<table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_NAME')) ?></th><th><?= wd_e(wd_t('EINB.T_ADRESSE')) ?></th></tr>
<tr><td><?= wd_e(wd_t('EINB.E_STATUS')) ?></td><td><span class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?status=1</span></td></tr>
<tr><td><?= wd_e(wd_t('EINB.E_INNEN')) ?></td><td><span class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?innen=&lt;v.1&gt;&amp;token=<?= wd_e($wf_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><?= wd_e(wd_t('EINB.E_KACHEL')) ?></td><td><span class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?kachel=1</span></td></tr>
<tr><td><?= wd_e(wd_t('EINB.E_WEBLINK')) ?></td><td><span class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wetter.php</span></td></tr>
<tr><td><?= wd_e(wd_t('EINB.E_ABRUF')) ?></td><td><span class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?abruf=1&amp;token=<?= wd_e($wf_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-loxone"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= wd_e(wd_t('EINB.K_TOKEN')) ?></button></form>
<h2 id="wd-lox-werte"><?= wd_e(wd_t('LOXW.H')) ?></h2>
<div class="sm-hinweis"><?= wd_e(wd_t('LOXW.WAS')) ?></div>
<div class="sm-warnung" id="wd-lox-echo"><b><?= wd_e(wd_t('LOXW.ECHO_T')) ?></b> <?= wd_e(wd_t('LOXW.ECHO')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span><span><i class="sm-punkt sm-b-technik"></i> <?= wd_e(wd_t('LEGENDE.TECHNIK')) ?></span></div>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-loxone"><input data-role="none" type="hidden" name="lox_save" value="1">
<?= wf_feld('lox_ein') ?><?= wf_feld('lox_gueltig_min') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button></form>
<h3 class="sm-h3"><?= wd_e(wd_t('LOXW.H_SCHRITTE')) ?></h3>
<ol class="sm-schritte" id="wd-lox-schritte"><?php foreach (array('S1', 'S2', 'S3', 'S4', 'S5', 'S6') as $wf_k) { ?><li><?= wd_e(wd_t('LOXW.' . $wf_k)) ?><?php if ($wf_k === 'S2') { ?>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-loxone"><input data-role="none" type="hidden" name="lox_vorlage" value="1"><button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= wd_e(wd_t('LOXW.K_VORLAGE')) ?></button></form><?php } ?></li><?php } ?></ol>
<h3 class="sm-h3"><?= wd_e(wd_t('LOXW.H_TABELLE')) ?></h3>
<div class="sm-hilfe"><?= wd_e(wd_t('LOXW.TABELLE_HILFE')) ?></div>
<?php $wf_ll = wd_lox_lage($wf_cfg, time()); $wf_lw = wd_lox_werte(); ?>
<div class="sm-breit"><table class="sm-tbl" id="wd-lox-tabelle"><tr><th><?= wd_e(wd_t('LOXW.T_WERT')) ?></th><th><?= wd_e(wd_t('LOXW.T_BEFEHL')) ?></th><th><?= wd_e(wd_t('LOXW.T_BEREICH')) ?></th><th><?= wd_e(wd_t('LOXW.T_STAND')) ?></th></tr>
<?php foreach ($wf_lw as $wf_k => $wf_f) { $wf_l = $wf_ll[$wf_k]; ?><tr><td><?= wd_e(wd_t('LOXW.T_' . $wf_k)) ?></td><td><span class="sm-mono sm-adresse">/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?lox_<?= wd_e($wf_k) ?>=&lt;v.1&gt;&amp;token=<?= wd_e($wf_cfg['aktionstoken']) ?></span></td><td><?= wd_e($wf_f[1] . ' ' . wd_t('LOXW.BIS') . ' ' . $wf_f[2] . ' ' . $wf_f[3]) ?></td><td class="sm-small"><?= wd_e(sprintf(wd_t('LOXW.L_' . $wf_l[0]), $wf_l[1] === null ? '' : rtrim(rtrim(number_format($wf_l[1], 1, '.', ''), '0'), '.'), $wf_l[2] === null ? 0 : (int) round($wf_l[2] / 60))) ?></td></tr><?php } ?>
<tr><td><?= wd_e(wd_t('LOXW.T_innen')) ?></td><td><span class="sm-mono sm-adresse">/plugins/<?= wd_e($wf_p['plugin']) ?>/wd.php?innen=&lt;v.1&gt;&amp;token=<?= wd_e($wf_cfg['aktionstoken']) ?></span></td><td>-20 <?= wd_e(wd_t('LOXW.BIS')) ?> 60 °C</td><td class="sm-small"><?= wd_e(wd_t('LOXW.L_innen')) ?></td></tr>
</table></div>
<div class="sm-hilfe"><?= wd_e(wd_t('LOXW.RANG')) ?></div>
<h2 id="emulator-verweis"><?= wd_e(wd_t('EMU.H')) ?></h2>
<div class="sm-hinweis"><?= wd_e(wd_t('EINB.EMU_VERWEIS')) ?> <a href="index.php?form=emulator"><?= wd_e(wd_t('EINB.K_EMU')) ?></a></div>
</div>

<!-- Wetterseite -->
<?php
$wf_ws_pfad = '/plugins/' . $wf_p['plugin'] . '/wetter.php';
$wf_ws_basis = 'http://' . (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && preg_match('/^[A-Za-z0-9.\-:\[\]]{1,100}\z/', $_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry') . $wf_ws_pfad;
$wf_ws_v = wd_seite_vorgabe();
$wf_ws_q = '?mode=' . $wf_ws_v['mode'] . '&lang=' . wd_sprache() . '&theme=' . $wf_ws_v['theme'] . '&iconset=' . $wf_ws_v['iconset'];
?>
<div class="sm-pane<?= wf_aktiv('tab-wetterseite') ?>" id="tab-wetterseite">
<div class="sm-hinweis"><?= wd_e(wd_t('WS.WAS')) ?></div>
<h2><?= wd_e(wd_t('WS.H_VORSCHAU')) ?></h2>
<div id="wd-seite-wahl" data-basis="<?= wd_e($wf_ws_basis) ?>" data-pfad="<?= wd_e($wf_ws_pfad) ?>" data-mode="<?= wd_e($wf_ws_v['mode']) ?>" data-lang="<?= wd_e(wd_sprache()) ?>" data-theme="<?= wd_e($wf_ws_v['theme']) ?>" data-iconset="<?= wd_e($wf_ws_v['iconset']) ?>" data-kopiert="<?= wd_e(wd_t('WS.KOPIERT')) ?>" data-nicht="<?= wd_e(wd_t('WS.KOPIEREN_NICHT')) ?>">
<div class="sm-feld"><label><?= wd_e(wd_t('WS.WAHL_DESIGN')) ?></label><div class="sm-wahlreihe">
<?php foreach (wd_seite_themes() as $wf_tid => $wf_tf) { ?><button data-role="none" type="button" class="sm-wahl" data-feld="theme" data-wert="<?= wd_e($wf_tid) ?>" aria-pressed="<?= $wf_tid === $wf_ws_v['theme'] ? 'true' : 'false' ?>"><span class="sm-farbmuster" aria-hidden="true"><i style="background:<?= wd_e($wf_tf['hell']['bg']) ?>"></i><i style="background:<?= wd_e($wf_tf['hell']['akzent']) ?>"></i><i style="background:<?= wd_e($wf_tf['dunkel']['karte']) ?>"></i><i style="background:<?= wd_e($wf_tf['dunkel']['akzent']) ?>"></i></span> <?= wd_e(wd_t('WS.T_' . $wf_tid)) ?><?= $wf_tid === $wf_ws_v['theme'] ? ' (' . wd_e(wd_t('WS.VORGABE')) . ')' : '' ?></button>
<?php } ?></div></div>
<div class="sm-feld"><label><?= wd_e(wd_t('WS.WAHL_SYMBOLE')) ?></label><div class="sm-wahlreihe">
<?php foreach (wd_seite_sets() as $wf_sid => $wf_sf) { ?><button data-role="none" type="button" class="sm-wahl" data-feld="iconset" data-wert="<?= wd_e($wf_sid) ?>" aria-pressed="<?= $wf_sid === $wf_ws_v['iconset'] ? 'true' : 'false' ?>"><?php foreach (array('klar_tag', 'wolkig_tag', 'regen', 'gewitter') as $wf_bn) { ?><img src="/plugins/<?= wd_e($wf_p['plugin']) ?>/symbole/<?= wd_e($wf_sid) ?>/<?= $wf_bn ?>.svg" alt=""><?php } ?> <?= wd_e($wf_sf['name']) ?><?= $wf_sid === $wf_ws_v['iconset'] ? ' (' . wd_e(wd_t('WS.VORGABE')) . ')' : '' ?></button>
<?php } ?></div></div>
<div class="sm-feld"><label><?= wd_e(wd_t('WS.WAHL_HELL')) ?></label><div class="sm-wahlreihe">
<button data-role="none" type="button" class="sm-wahl" data-feld="mode" data-wert="auto" aria-pressed="true"><?= wd_e(wd_t('WS.AUTO')) ?></button><button data-role="none" type="button" class="sm-wahl" data-feld="mode" data-wert="light" aria-pressed="false"><?= wd_e(wd_t('WS.HELL')) ?></button><button data-role="none" type="button" class="sm-wahl" data-feld="mode" data-wert="dark" aria-pressed="false"><?= wd_e(wd_t('WS.DUNKEL')) ?></button>
</div></div>
<div class="sm-feld"><label><?= wd_e(wd_t('WS.WAHL_SPRACHE')) ?></label><div class="sm-wahlreihe">
<button data-role="none" type="button" class="sm-wahl" data-feld="lang" data-wert="de" aria-pressed="<?= wd_sprache() === 'de' ? 'true' : 'false' ?>"><?= wd_e(wd_t('WS.DEUTSCH')) ?></button><button data-role="none" type="button" class="sm-wahl" data-feld="lang" data-wert="en" aria-pressed="<?= wd_sprache() === 'en' ? 'true' : 'false' ?>"><?= wd_e(wd_t('WS.ENGLISCH')) ?></button>
</div></div>
<div class="sm-feld"><label><?= wd_e(wd_t('WS.WAHL_BREITE')) ?></label><div class="sm-wahlreihe">
<button data-role="none" type="button" class="sm-wahl" data-feld="breite" data-wert="voll" aria-pressed="true"><?= wd_e(wd_t('WS.BREITE_VOLL')) ?></button><button data-role="none" type="button" class="sm-wahl" data-feld="breite" data-wert="handy" aria-pressed="false"><?= wd_e(wd_t('WS.BREITE_HANDY')) ?></button>
</div></div>
<div class="sm-hilfe"><?= wd_e(wd_t('WS.WAHL_HILFE')) ?></div>
</div>
<div class="sm-vorschau"><iframe id="wd-seite-vorschau" src="<?= wd_e($wf_ws_pfad . $wf_ws_q) ?>" title="<?= wd_e(wd_t('WS.VORSCHAU_TITEL')) ?>"></iframe></div>
<h2><?= wd_e(wd_t('WS.H_ADRESSE')) ?></h2>
<div><span class="sm-mono sm-adresse" id="wd-seite-adresse"><?= wd_e($wf_ws_basis . $wf_ws_q) ?></span> <button data-role="none" type="button" class="sm-kopier" id="wd-seite-kopieren"><?= wd_e(wd_t('WS.K_KOPIEREN')) ?></button> <span class="sm-small" id="wd-seite-kopiert"></span></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= wd_e(wd_t('WS.LEGENDE_OEFFNEN')) ?></span></div>
<div class="sm-knopfreihe"><a class="sm-btn sm-b-lesen" id="wd-seite-oeffnen" href="<?= wd_e($wf_ws_basis . $wf_ws_q) ?>" target="_blank" rel="noopener"><?= wd_e(wd_t('WS.K_OEFFNEN')) ?></a></div>
<div class="sm-hilfe"><?= wd_e(wd_t('WS.ADRESSE_HILFE')) ?></div>
<h2><?= wd_e(wd_t('WS.H_CONFIG')) ?></h2>
<ol class="sm-schritte"><?php foreach (array('CS1', 'CS2', 'CS3', 'CS4', 'CS5', 'CS6') as $wf_k) { ?><li><?= wd_e(wd_t('WS.' . $wf_k)) ?></li><?php } ?></ol>
<div class="sm-small"><?= wd_e(wd_t('WS.CS_QUELLE')) ?></div>
<div class="sm-warnung"><?= wd_e(wd_t('WS.UNTERWEGS')) ?></div>
<div class="sm-hinweis"><?= wd_e(wd_t('WS.DATEN')) ?></div>
<div class="sm-small"><?= wd_e(wd_t('WS.UNGEMESSEN')) ?></div>
</div>

<!-- Emulator -->
<?php $wf_el = wd_emu_lage($wf_cfg); $wf_es = ($wf_p['lbhome'] !== '' ? $wf_p['lbhome'] : '') . '/bin/plugins/' . $wf_p['plugin'] . '/wd_emu_apache.sh'; ?>
<div class="sm-pane<?= wf_aktiv('tab-emulator') ?>" id="tab-emulator">
<h2 id="emulator"><?= wd_e(wd_t('EMU.H')) ?></h2>
<h3 class="sm-h3"><?= wd_e(wd_t('EMU.H_BRAUCHEN')) ?></h3>
<ol class="sm-schritte" id="wd-emu-wegweiser">
<li><b><?= wd_e(wd_t('EMU.B1_T')) ?></b> <?= wd_e(wd_t('EMU.B1')) ?> <a href="index.php?form=wetterseite"><?= wd_e(wd_t('EMU.K_B1')) ?></a></li>
<li><b><?= wd_e(wd_t('EMU.B2_T')) ?></b> <?= wd_e(wd_t('EMU.B2')) ?> <a href="index.php?form=loxone"><?= wd_e(wd_t('EMU.K_B2')) ?></a></li>
<li><b><?= wd_e(wd_t('EMU.B3_T')) ?></b> <?= wd_e(wd_t('EMU.B3')) ?></li>
</ol>
<div class="sm-breit"><table class="sm-tbl" id="wd-emu-generation"><tr><th><?= wd_e(wd_t('EINB.EMU_T_GEN')) ?></th><th><?= wd_e(wd_t('EINB.EMU_T_GEHT')) ?></th></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_G1')) ?></td><td><?= wd_e(wd_t('EINB.EMU_G1_T')) ?></td></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_G2')) ?></td><td><?= wd_e(wd_t('EINB.EMU_G2_T')) ?></td></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_G3')) ?></td><td><b><?= wd_e(wd_t('EINB.EMU_G3_T')) ?></b></td></tr>
</table></div>
<div class="sm-warnung"><?= wd_e(wd_t('EINB.EMU_WARN')) ?></div>
<div class="sm-warnung" id="wd-emu-abo"><b><?= wd_e(wd_t('EMU.ABO')) ?></b></div>
<div class="sm-hinweis"><?= wd_e(wd_t('EINB.EMU_WAS')) ?></div>
<h3 class="sm-h3"><?= wd_e(wd_t('EINB.EMU_H_SCHRITTE')) ?></h3>
<ol class="sm-schritte" id="wd-emu-schritte">
<li><?= wd_e(wd_t('EINB.EMU_S1')) ?></li>
<li><?= wd_e(wd_t('EINB.EMU_S2')) ?></li>
<li><?= wd_e(wd_t('EINB.EMU_S3')) ?> <a href="https://github.com/timanders22/LoxBerry-Plugin-Weatherdata/releases/latest" target="_blank" rel="noopener"><?= wd_e(wd_t('EMU.RELEASE')) ?></a>
<details><summary><?= wd_e(wd_t('EMU.PROFI')) ?></summary><div class="sm-small sm-mono"><?= wd_e($wf_es) ?> ein</div><div class="sm-small sm-mono"><?= wd_e($wf_es) ?> aus</div></details></li>
<li><?= wd_e(wd_t('EINB.EMU_S4')) ?>
<?php $wf_avm = 'https://fritz.com/' . (wd_sprache() === 'en' ? 'en/apps/knowledge-base/FRITZ-Box-4060/' : 'apps/knowledge-base/FRITZ-Box-4060/'); ?>
<ul id="wd-emu-dns"><li><?= wd_e(wd_t('EMU.S4_DNSMASQ')) ?> <a href="https://wiki.loxberry.de/plugins/dnsmasq/start" target="_blank" rel="noopener"><?= wd_e(wd_t('EMU.K_DNSMASQ')) ?></a></li><li><?= wd_e(wd_t('EMU.S4_EIGEN')) ?></li>
<li><b><?= wd_e(wd_t('EMU.S4_A')) ?></b></li>
<li><?= wd_e(wd_t('EMU.S4_FRITZ')) ?> <a href="<?= wd_e($wf_avm) ?>165" target="_blank" rel="noopener"><?= wd_e(wd_t('EMU.K_AVM_DNS')) ?></a></li>
<li><?= wd_e(wd_t('EMU.S4_REBIND')) ?> <a href="<?= wd_e($wf_avm) ?>3565" target="_blank" rel="noopener"><?= wd_e(wd_t('EMU.K_AVM_REBIND')) ?></a></li>
<li><?= wd_e(wd_t('EMU.S4_NICHT')) ?></li><li class="sm-small"><?= wd_e(wd_t('EMU.S4_UNGEMESSEN')) ?></li></ul></li>
<li><?= wd_e(wd_t('EINB.EMU_S5')) ?></li>
<li><?= wd_e(wd_t('EINB.EMU_S6')) ?></li>
</ol>
<?php if ($wf_el['fremd']) { ?><div class="sm-alert sm-warn"><?= wd_e(sprintf(wd_t('EINB.EMU_FREMD'), implode(', ', $wf_el['fremd']))) ?></div><?php } ?>
<?php if ($wf_el['w4l']) { ?><div class="sm-alert sm-info"><?= wd_e(sprintf(wd_t('EINB.EMU_W4L'), implode(', ', $wf_el['w4l']))) ?></div><?php } ?>
<h3 class="sm-h3"><?= wd_e(wd_t('EMU.H_SELBST')) ?></h3>
<table class="sm-tbl" id="wd-emu-lage"><tr><th><?= wd_e(wd_t('KOPF.EIGENSCHAFT')) ?></th><th><?= wd_e(wd_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= wd_e(wd_t('FELD.emu_ein')) ?></td><td><?= wd_e(wd_t($wf_el['ein'] ? 'KOPF.EIN' : 'KOPF.AUS')) ?></td></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_L_PORT')) ?></td><td><?= wd_e(wd_t($wf_el['vhost'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_L_DATEN')) ?></td><td><?= (int) $wf_el['stunden'] ?></td></tr>
<tr><td><?= wd_e(wd_t('EINB.EMU_L_ABRUF')) ?></td><td><?= $wf_el['abruf_ts'] > 0 ? wd_e(date('d.m.Y H:i', $wf_el['abruf_ts'])) : '&ndash;' ?></td></tr>
</table>
<h3 class="sm-h3"><?= wd_e(wd_t('EMU.H_FEHLER')) ?></h3>
<ul class="sm-schritte" id="wd-emu-fehler"><?php foreach (array('F1', 'F2', 'F3', 'F4', 'F5') as $wf_k) { ?><li><?= wd_e(wd_t('EMU.' . $wf_k)) ?></li><?php } ?></ul>
<div class="sm-hilfe"><?= wd_e(wd_t('EMU.DATENSCHUTZ')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-emulator"><input data-role="none" type="hidden" name="emu_save" value="1">
<?= wf_feld('emu_ein') ?><?= wf_feld('emu_nur_ms') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
</div>

<!-- Test -->
<div class="sm-pane<?= wf_aktiv('tab-test') ?>" id="tab-test">
<h2><?= wd_e(wd_t('REITER.TEST')) ?></h2>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<?php if ($wf_tab === 'tab-test') { $wf_z = wd_selbsttest(); $wf_g = 0; $wf_s = 0; $wf_o = 0; foreach ($wf_z as $wf_r) { if ($wf_r['ok'] === null) { $wf_o++; } elseif ($wf_r['ok']) { $wf_g++; } else { $wf_s++; } } ?>
<div class="sm-alert <?= $wf_s ? 'sm-warn' : 'sm-ok' ?>"><b><?= wd_e(sprintf(wd_t('PRUEF.BILANZ'), $wf_g, $wf_g + $wf_s, $wf_o)) ?></b></div>
<table class="sm-tbl" style="width:100%;"><tr><th style="width:34px;"></th><th><?= wd_e(wd_t('PRUEF.FRAGE')) ?></th><th><?= wd_e(wd_t('PRUEF.BEFUND')) ?></th></tr>
<?php foreach ($wf_z as $wf_r) { ?><tr><td style="text-align:center;font-weight:700;<?= $wf_r['ok'] === null ? 'color:#888;' : ($wf_r['ok'] ? 'color:#6dac20;' : 'color:#e0620d;') ?>"><?= $wf_r['ok'] === null ? '&ndash;' : ($wf_r['ok'] ? '&#10003;' : '&#10007;') ?></td><td><?= wd_e($wf_r['frage']) ?></td><td class="sm-small"><?= wd_e($wf_r['hinweis']) ?></td></tr><?php } ?>
</table>
<?php } ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test"><input data-role="none" type="hidden" name="abruf" value="1"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_ABRUF')) ?></button></form>
<form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-test"><input data-role="none" type="hidden" name="ansage_test" value="1"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_TESTANSAGE')) ?></button>
<?= ansage_testknoepfe_html($wf_cfg['tts'], wd_ansage_k(), array('klasse' => 'sm-btn sm-b-aktion')) ?></form>
</div>
<?php foreach (ansage_regel_lage($wf_cfg['tts'], wd_ansage_regeln($wf_cfg), wd_ansage_k(), true) as $wf_r) { ?><div class="sm-hilfe"><?= wd_e((string) $wf_r[1]) ?></div><?php } ?>
<div class="sm-small" style="margin-top:6px;"><?= wd_e(wd_t('PRUEF.FUSS')) ?></div>
</div>

<!-- Logdateien -->
<div class="sm-pane<?= wf_aktiv('tab-log') ?>" id="tab-log">
<div class="sm-small" style="margin-bottom:8px;"><?= wd_e(wd_t('TEXT.LOG_HILFE')) ?></div>
<?php $wf_ll = is_file($wf_p['log']) ? array_slice(array_reverse(file($wf_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300) : array(); ?>
<?php if ($wf_ll) { ?><div class="sm-log"><?= wd_e(implode("\n", $wf_ll)) ?></div><?php } else { ?><div class="sm-alert sm-info"><?= wd_e(wd_t('TEXT.LOG_LEER')) ?></div><?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<div class="sm-knopfreihe"><form action="index.php" method="post"><?= wd_fmt() ?><label class="sm-small" style="align-self:center;margin-right:10px;"><input data-role="none" type="checkbox" name="clearlog_ok" value="1"> <?= wd_e(wd_t('TEXT.LOG_BESTAETIGEN')) ?></label><input data-role="none" type="hidden" name="clearlog" value="1"><input data-role="none" type="hidden" name="activetab" value="tab-log"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_LOG')) ?></button></form></div>
<?php $wf_ce = dirname($wf_p['log']) . '/cron.err'; if (is_file($wf_ce) && @filesize($wf_ce) > 0) { ?><h3 class="sm-h3">cron.err</h3><div class="sm-log"><?= wd_e(implode("\n", array_slice(file($wf_ce, FILE_IGNORE_NEW_LINES) ?: array(), -100))) ?></div><?php } ?>
<div class="sm-hinweis"><?= wd_e(wd_t('TEXT.LOG_RAMDISK')) ?></div>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
</div>
</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
        document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            if (t.dataset.reload === '1') { return; }
            ev.preventDefault();
            activate(t.dataset.pane);
        });
    });
    activate(<?= json_encode($wf_tab) ?>);
})();
(function () {
    var b = document.getElementById('wd-seite-wahl');
    if (!b) { return; }
    var w = { mode: b.dataset.mode, lang: b.dataset.lang, theme: b.dataset.theme, iconset: b.dataset.iconset };
    var f = document.getElementById('wd-seite-vorschau'), ad = document.getElementById('wd-seite-adresse'), oe = document.getElementById('wd-seite-oeffnen'), ko = document.getElementById('wd-seite-kopiert');
    function q() { return '?mode=' + w.mode + '&lang=' + w.lang + '&theme=' + w.theme + '&iconset=' + w.iconset; }
    function neu() {
        f.src = b.dataset.pfad + q(); ad.textContent = b.dataset.basis + q(); oe.href = b.dataset.basis + q(); ko.textContent = '';
        b.querySelectorAll('.sm-wahl').forEach(function (k) { if (k.dataset.feld !== 'breite') { k.setAttribute('aria-pressed', w[k.dataset.feld] === k.dataset.wert ? 'true' : 'false'); } });
    }
    b.querySelectorAll('.sm-wahl').forEach(function (k) {
        k.addEventListener('click', function () {
            if (k.dataset.feld === 'breite') {
                f.style.width = k.dataset.wert === 'handy' ? '390px' : '100%';
                b.querySelectorAll('.sm-wahl[data-feld="breite"]').forEach(function (x) { x.setAttribute('aria-pressed', x === k ? 'true' : 'false'); });
                return;
            }
            w[k.dataset.feld] = k.dataset.wert; neu();
        });
    });
    document.getElementById('wd-seite-kopieren').addEventListener('click', function () {
        var t = ad.textContent;
        function alt() {
            var r = document.createRange(); r.selectNodeContents(ad); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            var ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            ko.textContent = ok ? b.dataset.kopiert : b.dataset.nicht;
        }
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(function () { ko.textContent = b.dataset.kopiert; }, alt); } else { alt(); }
    });
})();
</script>
<?php
if ($wf_frame) { LBWeb::lbfooter(); }
