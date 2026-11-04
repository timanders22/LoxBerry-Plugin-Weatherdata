<?php
/**
 * Weatherdata - Oberflaeche. Reiter: Einstellungen | Werte | Quellen | Warnungen | MQTT | Einbindung in Loxone | Test | Logdateien.
 * Alle Variablen tragen wf_: LBWeb::lbheader() setzt Globale des SDK. Handler stehen vor lbheader().
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');
$wf_kand = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/wd_lib.php', dirname(__DIR__) . '/html/wd_lib.php');
foreach ($wf_kand as $wf_c) { if (is_file($wf_c)) { require_once $wf_c; break; } }
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
$wf_x2 = null; $wf_x2neu = null;
$wf_wache = wd_wachposten();
if ($wf_wache !== '') {
    $wf_m = isset($_POST['activetab']) && is_string($_POST['activetab']) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($wf_m !== null) { $_POST['activetab'] = $wf_m; }
    $wf_err = $wf_wache;
}
$wf_muster = '/^tab-(settings|werte|quellen|warnungen|mqtt|loxone|test|log)$/';
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
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $wf_vn . '"');
    echo $wf_vx;
    exit;
}
// ---------- Sichern: Download mit allen Einstellungen, Standort nur als Einstellung ----------
if ($wf_post && isset($_POST['wd_sichern'])) {
    $wf_v = wd_config();
    unset($wf_v['ansage_token_herkunft']);
    if (isset($wf_v['tts']) && is_array($wf_v['tts'])) { $wf_v['tts'] = ansage_sicherung_bereinigen($wf_v['tts']); }
    $wf_alt = wd_rueckspiel_altwerte();
    if ($wf_alt) { $wf_v = array('_warnung' => sprintf(wd_t('SICH.WARNKOPF'), implode(' ', $wf_alt))) + $wf_v; }
    $wf_js = json_encode($wf_v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($wf_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="weatherdata_einstellungen_' . date('Ymd_His') . '.json"');
        echo $wf_js;
        exit;
    }
    $wf_err = wd_t('SICH.SCHREIBFEHLER');
}
// ---------- Zurueckspielen ----------
if ($wf_post && isset($_POST['wd_zurueck'])) {
    if (!isset($_FILES['wd_sicherung']['tmp_name']) || !@is_uploaded_file($_FILES['wd_sicherung']['tmp_name'])) {
        $wf_err = wd_t('SICH.KEINE_DATEI');
    } elseif ((int) $_FILES['wd_sicherung']['size'] > 262144) {
        $wf_err = wd_t('SICH.ZU_GROSS');
    } else {
        $wf_e = wd_sicherung_lesen((string) @file_get_contents($_FILES['wd_sicherung']['tmp_name']), wd_config());
        if ($wf_e[0] === null) {
            $wf_err = wd_t('SICH.ABGELEHNT') . ' ' . implode(' ', $wf_e[1]);
        } elseif (wd_config_speichern($wf_e[0])) {
            wd_zweitschrift($wf_e[0]);
            $wf_ist = wd_inhalt_oder_null($wf_p['config']);
            if ($wf_ist == $wf_e[0]) {
                $wf_note = sprintf(wd_t('SICH.UEBERNOMMEN'), $wf_e[2]) . ' ' . wd_t('SICH.DIENST');
                $wf_hinweise = array_merge($wf_hinweise, $wf_e[3]);
                @unlink(wd_tmpdir() . '/mqtt_sig.txt');
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
    wd_lauf(true);
    $wf_note = wd_t('MELD.ABRUF');
}
if ($wf_post && isset($_POST['zelle_finden'])) {
    $wf_vs = wd_zelle_vorschlag(wd_config());
    if ($wf_vs === null) { $wf_note_art = 'warn'; $wf_note = wd_t('MELD.ZELLE_NICHT'); }
    else { $wf_note = sprintf(wd_t('MELD.ZELLE_VORSCHLAG'), $wf_vs[0], $wf_vs[1]); }
}
if ($wf_post && isset($_POST['clearlog'])) { @file_put_contents($wf_p['log'], ''); $wf_note = wd_t('MELD.LOG_LEER'); }
if ($wf_post && isset($_POST['token_neu'])) {
    $wf_c = wd_config();
    $wf_c['aktionstoken'] = bin2hex(random_bytes(12));
    if (wd_config_speichern($wf_c)) { wd_zweitschrift($wf_c); $wf_note = wd_t('MELD.TOKEN_NEU'); } else { $wf_err = wd_t('MELD.SPEICHERN_FEHL'); }
}
// ---------- Speichern, je Formular ein Teil der Feldtabelle ----------
foreach (array('save', 'quellen_save', 'warn_save', 'mqtt_save') as $wf_form) {
    if (!$wf_post || !isset($_POST[$wf_form])) { continue; }
    $wf_alt = wd_config(); $wf_neu = $wf_alt; $wf_bean = array(); $wf_werte = array();
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
    if ($wf_form === 'warn_save') {
        $wf_tm = array(); $wf_tb = array(); $wf_th = array();
        $wf_neu['tts'] = ansage_formular_lesen($_POST, $wf_alt['tts'], $wf_tm, $wf_tb, array('modi' => wd_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), wd_ansage_k(), $wf_th);
        list($wf_neu['ansage_regeln']) = ansage_regeln_formular($_POST, wd_ansage_regeln($wf_alt), array(), $wf_tm, $wf_tb, wd_ansage_k());
        foreach ($wf_tm as $wf_t) { $wf_fehler[] = $wf_t['text']; }
        foreach ($wf_tb as $wf_t) { $wf_bean[] = $wf_t; }
        foreach ($wf_th as $wf_t) { $wf_hinweise[] = $wf_t['text']; }
    }
    if ($wf_fehler) {
        $wf_x2neu = array('form' => $wf_form, 'werte' => $wf_werte, 'bean' => array_values(array_unique($wf_bean)));
    } elseif (wd_config_speichern($wf_neu)) {
        $wf_saved = true;
        wd_zweitschrift($wf_neu);
        @unlink(wd_tmpdir() . '/mqtt_sig.txt');
    } else {
        $wf_err = wd_t('MELD.SPEICHERN_FEHL');
    }
}
// ---------- POST, Umleitung, GET ----------
if ($wf_post) {
    if (wd_json_schreiben(wf_einmal_datei(), array('zeit' => time(), 'saved' => $wf_saved ? 1 : 0, 'err' => $wf_err, 'note' => $wf_note, 'art' => $wf_note_art, 'fehler' => $wf_fehler, 'hinweise' => $wf_hinweise, 'x2' => $wf_x2neu))) {
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
    } elseif ($f[1] === 'u') {
        $o .= '<label for="' . $k . '">' . $l . '</label><input data-role="none" type="time" id="' . $k . '" name="' . $k . '" value="' . $v . '"' . wf_m($k) . '>';
    } else {
        $o .= '<label for="' . $k . '">' . $l . '</label><input data-role="none" type="text" id="' . $k . '" name="' . $k . '" value="' . $v . '"' . wf_m($k) . '>';
    }
    if (strpos($h, 'HILFE.') !== 0) { $o .= '<div class="sm-hilfe">' . wd_e($h) . '</div>'; }
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
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
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

.sm-wrap .sm-beanstandet { border: 2px solid #e0620d !important; background-color: #fdf4ec !important; }

.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.sm-wrap h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }

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
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_WERT')) ?></th><th><?= wd_e(wd_t('TEXT.T_STAND')) ?></th><th><?= wd_e(wd_t('TEXT.T_HERKUNFT')) ?></th></tr>
<?php foreach ((array) $wf_st['jetzt'] as $wf_k => $wf_v) { ?><tr><td><?= wd_e(wd_t('THEMA.jetzt_' . $wf_k)) ?></td><td><?= wf_wert($wf_v) ?></td><td><?= wd_e($wf_st['herkunft'][$wf_k]) ?></td></tr><?php } ?>
<tr><td><?= wd_e(wd_t('THEMA.gefuehlt_c')) ?></td><td><?= wf_wert($wf_st['gefuehlt']) ?></td><td><?= wd_e($wf_st['gefuehlt_art']) ?></td></tr>
</table></div>
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
<?php foreach ((array) $wf_st['quellen'] as $wf_k => $wf_q) { ?><tr><td><?= wd_e(wd_t('QUELLE.N_' . $wf_k)) ?></td><td><?= wd_e(wd_t(!$wf_q['ein'] ? 'QUELLE.AUS' : ($wf_q['ok'] ? 'QUELLE.OK' : 'QUELLE.NICHT'))) ?></td><td><?= wf_alter($wf_q['alter_s']) ?></td><td class="sm-small"><?= wd_e($wf_q['meldung']) ?></td></tr><?php } ?>
</table></div><?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wd_e(wd_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
<?= wd_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-quellen"><input data-role="none" type="hidden" name="quellen_save" value="1">
<h2><?= wd_e(wd_t('TEXT.H_Q_PROG')) ?></h2>
<?= wf_feld('om_ein') ?><?= wf_feld('om_apikey') ?><?= wf_feld('mosmix_ein') ?><?= wf_feld('met_ein') ?><?= wf_feld('bias_ein') ?><?= wf_feld('fassade_ein') ?><?= wf_feld('fassade_az') ?>
<h2><?= wd_e(wd_t('TEXT.H_Q_WARN')) ?></h2>
<?= wf_feld('dwd_ein') ?><?= wf_feld('bs_ein') ?><?= wf_feld('radar_ein') ?>
<h2><?= wd_e(wd_t('TEXT.H_Q_STATION')) ?></h2>
<?= wf_feld('station_ein') ?><?= wf_feld('station_art') ?><?= wf_feld('station_ordner') ?><?= wf_feld('station_host1') ?><?= wf_feld('station_host2') ?><?= wf_feld('station_gleich_min') ?><?= wf_feld('station_sprung_k') ?>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.EMPFANG_HILFE')) ?></div>
<div class="sm-mono">http://<?= $wf_host ?>/plugins/<?= wd_e($wf_p['plugin']) ?>/wd_empfang.php?token=<?= wd_e($wf_cfg['aktionstoken']) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_Q_UMWELT')) ?></h2>
<?= wf_feld('aq_ein') ?><?= wf_feld('pollen_ein') ?><?= wf_feld('pollen_region') ?>
<h2><?= wd_e(wd_t('TEXT.H_Q_AUS')) ?></h2>
<div class="sm-warnung"><?= wd_e(wd_t('TEXT.AUS_HILFE')) ?></div>
<?= wf_feld('nina_ein') ?><?= wf_feld('nina_ars') ?><?= wf_feld('ma_ein') ?><?= wf_feld('ma_land') ?><?= wf_feld('ma_emma') ?>
<?= wf_feld('ow_ein') ?><?= wf_feld('ow_key') ?><?= wf_feld('wa_ein') ?><?= wf_feld('wa_key') ?><?= wf_feld('vc_ein') ?><?= wf_feld('vc_key') ?><?= wf_feld('tm_ein') ?><?= wf_feld('tm_key') ?><?= wf_feld('mb_ein') ?><?= wf_feld('mb_key') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<h2><?= wd_e(wd_t('TEXT.H_NENNUNG')) ?></h2>
<ul class="sm-schritte">
<li><?= wd_e(wd_t('NENNUNG.OM')) ?> <a href="https://open-meteo.com/" target="_blank" rel="noopener">open-meteo.com</a> (CC BY 4.0)</li>
<li><?= wd_e(wd_t('NENNUNG.DWD')) ?> <a href="https://www.dwd.de/" target="_blank" rel="noopener">dwd.de</a></li>
<li><?= wd_e(wd_t('NENNUNG.BS')) ?> <a href="https://brightsky.dev/" target="_blank" rel="noopener">brightsky.dev</a></li>
<li><?= wd_e(wd_t('NENNUNG.MET')) ?> <a href="https://www.met.no/" target="_blank" rel="noopener">met.no</a></li>
<li><?= wd_e(wd_t('NENNUNG.CAMS')) ?></li>
</ul>
<h2><?= wd_e(wd_t('TEXT.H_BILANZ')) ?></h2>
<div class="sm-hilfe"><?= wd_e(wd_t('TEXT.BILANZ_HILFE')) ?></div>
<?php $wf_bi = wd_bilanz_lage(); if (!$wf_bi) { ?><div class="sm-alert sm-info"><?= wd_e(wd_t('TEXT.BILANZ_LEER')) ?></div><?php } else { ?>
<div class="sm-breit"><table class="sm-tbl"><tr><th><?= wd_e(wd_t('TEXT.T_QUELLE')) ?></th><th>0-6 h</th><th>6-24 h</th><th>24-72 h</th></tr>
<?php foreach ($wf_bi as $wf_q => $wf_b) { ?><tr><td><?= wd_e($wf_q) ?></td><?php foreach (array('f0', 'f6', 'f24') as $wf_f) { ?><td><?= isset($wf_b[$wf_f]) ? wd_e(sprintf(wd_t('TEXT.BILANZ_Z'), round($wf_b[$wf_f][1], 1), (int) $wf_b[$wf_f][0], round(wd_gewicht($wf_bi, $wf_q, $wf_f), 2))) : '&ndash;' ?></td><?php } ?></tr><?php } ?>
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
echo ansage_formular_html($wf_cfg['tts'], $wf_hooks + array('modi' => wd_ansage_modi(), 'ziele' => true, 'ziele_post' => null), wd_ansage_k());
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
<?= wf_feld('mqtt_ein') ?><?= wf_feld('mqtt_topic') ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.SPEICHERN')) ?></button>
</form>
<h2><?= wd_e(wd_t('TEXT.H_GATEWAY')) ?></h2>
<div class="sm-small sm-mono"><?= wd_e(sprintf(wd_t('TEXT.GATEWAY'), (int) $wf_gw)) ?></div>
<?php list($wf_vo, $wf_vt) = wd_v2_lage($wf_cfg); ?><div class="sm-alert <?= $wf_vo === false ? 'sm-warn' : 'sm-info' ?>"><?= wd_e($wf_vt) ?></div>
<h2><?= wd_e(wd_t('TEXT.H_THEMEN')) ?></h2>
<div class="sm-breit"><table class="sm-tbl" id="wd-hakenliste"><tr><th>#</th><th><?= wd_e(wd_t('TEXT.T_THEMA')) ?></th><th><?= wd_e(wd_t('TEXT.T_NAME')) ?></th><th><?= wd_e(wd_t('TEXT.T_RETAIN')) ?></th><th><?= wd_e(wd_t('TEXT.T_NC')) ?></th><th><?= wd_e(wd_t('TEXT.T_PFLICHT')) ?></th><th><?= wd_e(wd_t('TEXT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ($wf_hk as $wf_i => $wf_h) { ?><tr><td><?= (int) $wf_i + 1 ?></td><td><span class="sm-mono"><?= wd_e($wf_h['thema']) ?></span></td><td><span class="sm-mono"><?= wd_e($wf_h['name']) ?></span></td><td><?= wd_e(wd_t($wf_h['retain'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td><?= wd_e(wd_t($wf_h['nc'] ? 'TEXT.JA' : 'TEXT.NEIN')) ?></td><td><?= wd_e(wd_t($wf_h['pflicht'] === 'P' ? 'TEXT.PFLICHT' : 'TEXT.WAHL')) ?></td><td class="sm-small"><?= wd_e($wf_h['text']) ?></td></tr><?php } ?>
</table></div>
<div class="sm-small"><?= wd_e(wd_t('TEXT.MQTT_FUSS')) ?></div>
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
<div class="sm-breit"><table class="sm-tbl" id="wd-musterprojekt"><tr><th>#</th><th><?= wd_e(wd_t('EINB.T_BAUSTEIN')) ?></th><th><?= wd_e(wd_t('EINB.T_NAME')) ?></th><th><?= wd_e(wd_t('EINB.T_EIN')) ?></th><th><?= wd_e(wd_t('TEXT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (wd_bausteinliste($wf_cfg) as $wf_z) { ?><tr><td><?= (int) $wf_z[0] ?></td><td><?= wd_e($wf_z[1]) ?></td><td><span class="sm-mono"><?= wd_e($wf_z[2]) ?></span></td><td><?= wd_e($wf_z[3]) ?></td><td class="sm-small"><?= wd_e($wf_z[4]) ?></td></tr><?php } ?>
</table></div>
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
<div class="sm-knopfreihe"><form action="index.php" method="post"><?= wd_fmt() ?><input data-role="none" type="hidden" name="clearlog" value="1"><input data-role="none" type="hidden" name="activetab" value="tab-log"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= wd_e(wd_t('TEXT.K_LOG')) ?></button></form></div>
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
</script>
<?php
if ($wf_frame) { LBWeb::lbfooter(); }
