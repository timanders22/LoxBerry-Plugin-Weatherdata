<?php
/**
 * Weatherdata - Grundlage: Pfade, Einstellungen, Protokoll, Sprache, MQTT-Tabelle und Versand, Sicherung.
 *
 * Standort und Warnzelle stehen nur in der Konfiguration dieses Plugins (0600). Sie gehen in die Abfrage des jeweiligen
 * Dienstes, nie ins Protokoll, nie in ein MQTT-Thema. wd_log() streicht sie zusaetzlich aus jeder Zeile.
 *
 * Kompatibel mit PHP 7.4 bis 8.5. Benoetigt weder mbstring noch simplexml noch ZipArchive: Regeln/03 nennt alle drei als
 * nicht ueberall vorhanden. Wer dieses Plugin verstehen will, liest zuerst wd_mqtt_tabelle().
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/sprachausgabe.php';
require_once __DIR__ . '/meldung.php';     // Statusmeldungen an SignalBot (seit 0.9.23)
require_once __DIR__ . '/wd_rechnen.php';
require_once __DIR__ . '/wd_quellen.php';
require_once __DIR__ . '/wd_mehr.php';
require_once __DIR__ . '/wd_emu.php';
require_once __DIR__ . '/wd_seite.php';
require_once __DIR__ . '/wd_eigen.php';
require_once __DIR__ . '/wd_haus.php';
require_once __DIR__ . '/wd_pv.php';
/* 0.9.19: Gemeinsames Sicherungsmodul (Abschrift von Werkzeuge/gemeinsam/sicherung.php, Auftrag des Hausherrn
 * 12.11.2026 und 18.11.2026: "Ja, umbauen"). Liegt neben dieser Datei und schuetzt sich gegen doppeltes Laden; die
 * Beschreibung dieser Linie steht in wd_sicherung_beschreibung(). */
require_once __DIR__ . '/sicherung.php';
require_once __DIR__ . '/haus_bereiche.php';     // gemeinsame Bereiche unter haus/ (seit 0.9.23, Regeln/07)

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins') && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $e = dirname($d);
            if ($e === $d) { break; }
            $d = $e;
        }
        return '';
    }
}

function wd_paths()
{
    static $merk = array();
    $env_home = (string) getenv('LBHOMEDIR');
    $env_ordner = (string) getenv('LBPPLUGINDIR');
    $sl = $env_home . "\n" . $env_ordner;
    if (isset($merk[$sl])) { return $merk[$sl]; }
    $nie = array('', '.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend');
    $env_home = rtrim($env_home, '/');
    $home_ok = ($env_home !== '' && is_dir($env_home . '/config/plugins') && is_dir($env_home . '/data/plugins'));
    $wurzel = $home_ok ? $env_home : lb_wurzel_ermitteln();
    $self = basename(__DIR__);
    $aus = basename(rtrim($env_ordner, '/'));
    $o = '';
    if ($wurzel !== '') {
        $inst = @realpath($wurzel . '/webfrontend/html/plugins/' . $self);
        $hier = @realpath(__DIR__);
        if (!in_array($self, $nie, true) && $inst !== false && $hier !== false && $inst === $hier) {
            $o = $self;
        } elseif ($home_ok && !in_array($aus, $nie, true) && preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $aus)) {
            $o = $aus;
        }
    }
    if ($o !== '') {
        return $merk[$sl] = array(
            'config' => $wurzel . '/config/plugins/' . $o . '/weatherdata.json',
            'backup' => $wurzel . '/config/plugins/' . $o . '.backup.json',
            'log' => $wurzel . '/log/plugins/' . $o . '/weatherdata.log',
            'datadir' => $wurzel . '/data/plugins/' . $o,
            'tmp' => '/tmp/' . preg_replace('/[^A-Za-z0-9_\-]/', '', $o),
            'lbhome' => $wurzel, 'plugin' => $o, 'general' => $wurzel . '/config/system/general.json',
        );
    }
    $a = sys_get_temp_dir() . '/weatherdata-archiv';
    return $merk[$sl] = array(
        'config' => $a . '/config/weatherdata.json', 'backup' => $a . '/config/weatherdata.backup.json',
        'log' => $a . '/log/weatherdata.log', 'datadir' => $a . '/data', 'tmp' => $a . '/tmp',
        'lbhome' => '', 'plugin' => in_array($self, $nie, true) ? 'weatherdata' : $self, 'general' => '',
    );
}

/**
 * Felder der Einstellungen. Name => array(Formular, Art, Vorgabe, Min, Max).
 * Art: t Text, z Zahl, h Haken, u Uhrzeit, g Geheimnis, a Auswahl (Min = Liste).
 * EINE Tabelle fuer Vorgaben, Speichern, Pruefung der Sicherungsdatei und Wirkungsprobe.
 */
function wd_felder()
{
    return array(
        'lat' => array('save', 'pos', '', -90, 90),
        'lon' => array('save', 'pos', '', -180, 180),
        'pos_stellen' => array('save', 'z', 3, 2, 4),
        'warnzelle' => array('save', 'zelle', '', 0, 0),
        'om_takt_min' => array('save', 'z', 30, 15, 180),
        'dwd_takt_min' => array('save', 'z', 5, 2, 60),
        'prog_max_alter_min' => array('save', 'z', 240, 60, 720),
        'warn_max_alter_min' => array('save', 'z', 30, 10, 240),
        'hitze_basis' => array('save', 'a', 'gefuehlt', array('gefuehlt', 'luft'), 0),
        'hitze1_c' => array('save', 'z', 32, 20, 50),
        'hitze2_c' => array('save', 'z', 35, 20, 50),
        'hitze3_c' => array('save', 'z', 38, 20, 55),
        'hysterese_k' => array('save', 'z', 1, 0.2, 5),
        'frost_c' => array('save', 'z', 0, -20, 5),
        'sturm_kmh' => array('save', 'z', 75, 30, 200),
        'regen1h_mm' => array('save', 'z', 15, 1, 100),
        'regen12h_mm' => array('save', 'z', 30, 1, 300),
        'uv_hoch' => array('save', 'z', 6, 1, 15),
        'sturm_vor_min' => array('save', 'z', 120, 10, 720),
        'lueften_delta_k' => array('save', 'z', 1, 0.2, 10),
        'lueften_innen_min_c' => array('save', 'z', 24, 15, 35),
        'wbgt_ein' => array('save', 'h', 0, 0, 0),          // 0.9.14: WBGT-Schaetzung, ab Werk aus
        'wbgt_wind_m' => array('save', 'z', 10, 1, 100),    // 0.9.14: Hoehe des Windmessers ueber Grund (m); Open-Meteo liefert 10 m
        'lueften_letzte' => array('save', 'u', '21:50', 0, 0),
        'morgen_zeit' => array('save', 'u', '07:30', 0, 0),
        'mittag_zeit' => array('save', 'u', '12:30', 0, 0),
        'abend_zeit' => array('save', 'u', '20:00', 0, 0),
        'om_ein' => array('quellen_save', 'h', 1, 0, 0),
        'om_apikey' => array('quellen_save', 'g', '', 0, 0),
        'dwd_ein' => array('quellen_save', 'h', 1, 0, 0),
        'bs_ein' => array('quellen_save', 'h', 1, 0, 0),
        'station_ein' => array('quellen_save', 'h', 1, 0, 0),
        'station_art' => array('quellen_save', 'a', 'weiche', array('weiche', 'direkt', 'empfang'), 0),
        'station_host1' => array('quellen_save', 'host', '', 0, 0),
        'station_host2' => array('quellen_save', 'host', '', 0, 0),
        'mosmix_ein' => array('quellen_save', 'h', 1, 0, 0),
        'met_ein' => array('quellen_save', 'h', 1, 0, 0),
        'wttr_ein' => array('quellen_save', 'h', 0, 0, 0),
        'bias_ein' => array('quellen_save', 'h', 0, 0, 0),
        'fassade_ein' => array('quellen_save', 'h', 0, 0, 0),
        'fassade_az' => array('quellen_save', 'azliste', '0,-90,90,180', 0, 0),
        'aq_ein' => array('quellen_save', 'h', 1, 0, 0),
        'pollen_ein' => array('quellen_save', 'h', 1, 0, 0),
        'pollen_region' => array('quellen_save', 'region', '', 0, 0),
        'radar_ein' => array('quellen_save', 'h', 1, 0, 0),
        'nina_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'nina_ars' => array('zusatz_save', 'ars', '', 0, 0),
        'ma_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'ma_land' => array('zusatz_save', 'land', '', 0, 0),
        'ma_emma' => array('zusatz_save', 'emma', '', 0, 0),
        'ow_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'ow_key' => array('zusatz_save', 'g', '', 0, 0),
        'wa_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'wa_key' => array('zusatz_save', 'g', '', 0, 0),
        'vc_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'vc_key' => array('zusatz_save', 'g', '', 0, 0),
        'tm_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'tm_key' => array('zusatz_save', 'g', '', 0, 0),
        'mb_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'mb_key' => array('zusatz_save', 'g', '', 0, 0),
        'wu_ein' => array('zusatz_save', 'h', 0, 0, 0),
        'wu_station' => array('zusatz_save', 'wustation', '', 0, 0),
        'wu_key' => array('zusatz_save', 'g', '', 0, 0),
        'station_ordner' => array('quellen_save', 'ordner', 'ecowittweiche', 0, 0),
        'station_gleich_min' => array('quellen_save', 'z', 180, 30, 1440),
        'station_sprung_k' => array('quellen_save', 'z', 12, 3, 40),
        'a_warn' => array('warn_save', 'h', 1, 0, 0),
        'a_hitze' => array('warn_save', 'h', 1, 0, 0),
        'a_uv' => array('warn_save', 'h', 1, 0, 0),
        'a_frost' => array('warn_save', 'h', 1, 0, 0),
        'a_starkregen' => array('warn_save', 'h', 1, 0, 0),
        'a_sturm' => array('warn_save', 'h', 1, 0, 0),
        'a_lueften' => array('warn_save', 'h', 1, 0, 0),
        'a_briefing' => array('warn_save', 'h', 1, 0, 0),
        'a_nina' => array('warn_save', 'h', 1, 0, 0),
        'pollen_sperre' => array('warn_save', 'z', 2, 0.5, 3),
        'luft_sperre_aqi' => array('warn_save', 'z', 60, 20, 200),
        'p_hasel' => array('warn_save', 'h', 0, 0, 0), 'p_erle' => array('warn_save', 'h', 0, 0, 0), 'p_esche' => array('warn_save', 'h', 0, 0, 0), 'p_birke' => array('warn_save', 'h', 0, 0, 0),
        'p_graeser' => array('warn_save', 'h', 0, 0, 0), 'p_roggen' => array('warn_save', 'h', 0, 0, 0), 'p_beifuss' => array('warn_save', 'h', 0, 0, 0), 'p_ambrosia' => array('warn_save', 'h', 0, 0, 0),
        's1_wind' => array('warn_save', 'h', 0, 0, 0),
        's1_frost' => array('warn_save', 'h', 0, 0, 0),
        's1_schnee' => array('warn_save', 'h', 0, 0, 0),
        's1_nebel' => array('warn_save', 'h', 0, 0, 0),
        's1_glaette' => array('warn_save', 'h', 0, 0, 0),
        'ansage_sprache' => array('warn_save', 'a', 'de', array('de', 'en'), 0),
        'mqtt_ein' => array('mqtt_save', 'h', 1, 0, 0),
        'mqtt_topic' => array('mqtt_save', 'thema', 'weatherdata', 0, 0),
        'haus_wetter_ein' => array('mqtt_save', 'h', 0, 0, 0),      // 0.9.8: Hausthemen haus/wetter, ab Werk aus (Hausherr 04.11.2026)
        // 0.9.10: PV-Prognose ohne Konto unter haus/pv, ab Werk aus; bis 4 Flaechen (kWp 0 = Flaeche nicht benutzt), Azimut 0 Sued, -90 Ost, 90 West
        'haus_pv_ein' => array('pv_save', 'h', 0, 0, 0),
        'pv1_name' => array('pv_save', 'pvname', '', 0, 0), 'pv1_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv1_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv1_az' => array('pv_save', 'z', 0, -180, 180),
        'pv1_schatten_morgen' => array('pv_save', 'uleer', '', 0, 0), 'pv1_schatten_abend' => array('pv_save', 'uleer', '', 0, 0),     // 0.9.11: oertlicher Schatten, leer = keiner
        'pv2_name' => array('pv_save', 'pvname', '', 0, 0), 'pv2_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv2_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv2_az' => array('pv_save', 'z', 0, -180, 180),
        'pv2_schatten_morgen' => array('pv_save', 'uleer', '', 0, 0), 'pv2_schatten_abend' => array('pv_save', 'uleer', '', 0, 0),     // 0.9.11: oertlicher Schatten, leer = keiner
        'pv3_name' => array('pv_save', 'pvname', '', 0, 0), 'pv3_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv3_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv3_az' => array('pv_save', 'z', 0, -180, 180),
        'pv3_schatten_morgen' => array('pv_save', 'uleer', '', 0, 0), 'pv3_schatten_abend' => array('pv_save', 'uleer', '', 0, 0),     // 0.9.11: oertlicher Schatten, leer = keiner
        'pv4_name' => array('pv_save', 'pvname', '', 0, 0), 'pv4_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv4_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv4_az' => array('pv_save', 'z', 0, -180, 180),
        'pv4_schatten_morgen' => array('pv_save', 'uleer', '', 0, 0), 'pv4_schatten_abend' => array('pv_save', 'uleer', '', 0, 0),     // 0.9.11: oertlicher Schatten, leer = keiner
        'pv_faktor' => array('pv_save', 'z', 0.85, 0.5, 1),
        'pv_temp_beiwert' => array('pv_save', 'z', 0.38, 0.2, 0.5),     // 0.9.11: %/K, Moduldatenblatt "Temperaturkoeffizient Pmax"
        'pv_wr_max_w' => array('pv_save', 'z', 0, 0, 1000000),
        'emu_ein' => array('emu_save', 'h', 0, 0, 0),
        'emu_nur_ms' => array('emu_save', 'h', 1, 0, 0),
        'lox_ein' => array('lox_save', 'h', 0, 0, 0),
        'lox_gueltig_min' => array('lox_save', 'z', 30, 10, 180),
    );
}

function wd_vorgaben()
{
    $v = array('aktionstoken' => '');
    foreach (wd_felder() as $k => $f) { $v[$k] = $f[2]; }
    $v['tts'] = array();
    $v['ansage_regeln'] = ansage_regeln_vorgaben();
    $v['meldung'] = meld_vorgaben(wd_meld_anlaesse(), wd_meld_haken_vorgabe());     // 0.9.23: Meldungen an SignalBot, ab Werk aus
    /* 0.9.19: die Vermerke, die diese Linie selbst in die Konfiguration schreibt (Regeln/05: was ein gemeinsames Modul
     * dort haelt, steht in den Vorgaben). Sie kommen nie aus einer Sicherung und gehen nie in eine
     * (wd_sicherung_vermerke()); fehlen und leer heissen wie bisher "nicht gesetzt". */
    $v['ansage_token_herkunft'] = array();
    $v['_haus_frueher'] = 0;
    $v['_pv_frueher'] = 0;
    return $v;
}

function wd_wert_pruefen($k, $w)
{
    if ($k === 'aktionstoken') {
        return (is_string($w) && preg_match('/^[A-Za-z0-9]{0,64}\z/', $w)) ? array(true, $w, '') : array(false, null, wd_t('MELD.W_TOKEN'));
    }
    $fe = wd_felder();
    if (!isset($fe[$k])) { return array(false, null, 'unbekannt'); }
    $f = $fe[$k];
    switch ($f[1]) {
        case 'host':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[A-Za-z0-9.\-]{1,64}(:[0-9]{1,5})?\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_HOST'));
        case 'azliste':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return preg_match('/^-?[0-9]{1,3}(,-?[0-9]{1,3}){0,3}\z/', $s) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_AZ'));
        case 'pvname':     // 0.9.10: Name einer PV-Flaeche, nur zur Anzeige; Buchstaben (auch Umlaute), Ziffern, Leerzeichen, . - ( ) /
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || (strlen($s) <= 40 && preg_match('#^[\p{L}\p{N} .\-()/]{1,30}\z#u', $s))) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_PVNAME'));
        case 'region':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[0-9]{2,3}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_REGION'));
        case 'ars':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[0-9]{12}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_ARS'));
        case 'land':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[a-z\-]{3,40}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_LAND'));
        case 'wustation':
            $s = is_scalar($w) ? strtoupper(trim((string) $w)) : '';
            return ($s === '' || preg_match('/^[A-Z0-9]{3,32}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_WUSTATION'));
        case 'emma':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[A-Z0-9]{2,12}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_EMMA'));
        case 'h':
            return ($w === 0 || $w === 1 || $w === '0' || $w === '1' || $w === true || $w === false) ? array(true, $w ? 1 : 0, '') : array(false, null, wd_t('MELD.W_HAKEN'));
        case 'z':
            $s = is_scalar($w) ? str_replace(',', '.', trim((string) $w)) : '';
            if (!is_numeric($s) || (float) $s < $f[3] || (float) $s > $f[4]) { return array(false, null, sprintf(wd_t('MELD.W_BEREICH'), $f[3], $f[4])); }
            return array(true, is_int($f[2]) && (float) $s === floor((float) $s) ? (int) $s : (float) $s, '');
        case 'pos':
            $s = is_scalar($w) ? str_replace(',', '.', trim((string) $w)) : '';
            if ($s === '') { return array(true, '', ''); }
            if (!is_numeric($s) || (float) $s < $f[3] || (float) $s > $f[4]) { return array(false, null, sprintf(wd_t('MELD.W_BEREICH'), $f[3], $f[4])); }
            return array(true, $s, '');
        case 'zelle':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^[0-9]{9}\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_ZELLE'));
        case 'u':
            $s = is_scalar($w) ? trim((string) $w) : '';
            return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $s) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_ZEIT'));
        case 'uleer':     // 0.9.11: Uhrzeit HH:MM oder leer (Schatten einer PV-Flaeche)
            $s = is_scalar($w) ? trim((string) $w) : '';
            return ($s === '' || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $s)) ? array(true, $s, '') : array(false, null, wd_t('MELD.W_ZEIT_LEER'));
        case 'a':
            return (is_string($w) && in_array($w, $f[3], true)) ? array(true, $w, '') : array(false, null, wd_t('MELD.W_AUSWAHL'));
        case 'g':
            return (is_string($w) && preg_match('/^[A-Za-z0-9_\-]{0,128}\z/', $w)) ? array(true, $w, '') : array(false, null, wd_t('MELD.W_SCHLUESSEL'));
        case 'ordner':
            return (is_string($w) && preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $w)) ? array(true, $w, '') : array(false, null, wd_t('MELD.W_ORDNER'));
        case 'thema':
            return (is_string($w) && preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\z#', $w) && strlen($w) <= 64) ? array(true, $w, '') : array(false, null, wd_t('MELD.W_THEMA'));
    }
    return array(false, null, 'Art');
}

function wd_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) { return null; }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return (is_array($d) && $d !== array()) ? $d : null;
}

/** Traegt die Datei das Merkwort? Die Selbstheilung entscheidet nach Inhalt, nicht nach Form (Regeln/05). */
function wd_hat_inhalt($c)
{
    return is_array($c) && isset($c['aktionstoken']) && is_string($c['aktionstoken']) && trim($c['aktionstoken']) !== '';
}

function wd_selbstheilung()
{
    $p = wd_paths();
    if (wd_hat_inhalt(wd_inhalt_oder_null($p['config'])) || !wd_hat_inhalt(wd_inhalt_oder_null($p['backup']))) { return false; }
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    $alt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $rest = preg_replace('/\s+/', '', $alt);
    if ($rest !== '' && $rest !== '{}' && $rest !== '[]') { wd_datei_schreiben($p['config'] . '.kaputt', $alt, 0600); }
    $zw = @file_get_contents($p['backup']);
    if (!is_string($zw) || !wd_datei_schreiben($p['config'], $zw, 0600)) { return false; }
    wd_log('Die Konfiguration trug kein Aktionstoken und wurde aus der Zweitschrift wiederhergestellt.');
    return true;
}

/** Eine Zweitschrift mit Token wird nie durch einen Stand ohne Token ersetzt. */
function wd_zweitschrift($stand)
{
    $p = wd_paths();
    $z = wd_inhalt_oder_null($p['backup']);
    if (wd_hat_inhalt($z) && !wd_hat_inhalt($stand)) {
        wd_log('WARNUNG: Die Zweitschrift bleibt unveraendert, der gespeicherte Stand traegt kein Token.');
        return false;
    }
    $roh = @file_get_contents($p['config']);
    return is_string($roh) ? wd_datei_schreiben($p['backup'], $roh, 0600) : false;
}

function wd_config()
{
    $p = wd_paths();
    wd_selbstheilung();
    $c = wd_inhalt_oder_null($p['config']);
    if (!is_array($c)) { $c = array(); }
    $c += wd_vorgaben();
    if (!is_array($c['tts']) || !$c['tts']) { $c['tts'] = ansage_werk_block(); }     // nie gesetzt = ab Werk EIN, ein gespeicherter bleibt
    list($c['tts']) = ansage_vervollstaendigen($c['tts'], 'musicserver');
    return $c;
}

function wd_config_speichern($c)
{
    $p = wd_paths();
    $js = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    return wd_datei_schreiben($p['config'], $js, 0600);
}

/** Fehlt das Token, entsteht es EINMAL; steht es in der Zweitschrift, entsteht keins. */
function wd_token_sichern()
{
    $c = wd_config();
    if (trim((string) $c['aktionstoken']) !== '') { return $c; }
    if (wd_hat_inhalt(wd_inhalt_oder_null(wd_paths()['backup']))) { return $c; }
    $c['aktionstoken'] = bin2hex(random_bytes(12));
    if (wd_config_speichern($c)) { wd_zweitschrift($c); }
    return $c;
}

/** Leere Objekte des Rohtextes ($o, json_decode ohne true) im Feld $a wieder als Objekt fuehren (11.11.2026, Muster
 *  bw_json_leere_objekte() aus Bewaesserung 0.9.56), damit json_encode() sie als {} und nicht als [] schreibt - oben,
 *  verschachtelt und unter fremden Schluesseln. Nur fuer wd_config_vervollstaendigen(); das Speichern im Formular
 *  bleibt, wie es ist. */
function wd_json_leere_objekte($a, $o)
{
    if (!is_array($a)) { return $a; }
    if (is_object($o)) {
        if (!get_object_vars($o) && $a === array()) { return new stdClass(); }
        foreach ($a as $k => $v) {
            $ks = (string) $k;
            if (is_array($v) && property_exists($o, $ks)) { $a[$k] = wd_json_leere_objekte($v, $o->$ks); }
        }
    } elseif (is_array($o)) {
        foreach ($a as $k => $v) {
            if (is_array($v) && array_key_exists($k, $o)) { $a[$k] = wd_json_leere_objekte($v, $o[$k]); }
        }
    }
    return $a;
}

/**
 * Fehlende Einstellungen EINMAL mit ihrer Vorgabe in weatherdata.json schreiben (seit 0.9.16; Regeln/05, Bauform
 * Docker NG 1.3.7; Entscheidung des Hausherrn vom 11.11.2026). Bis 0.9.15 ergaenzte wd_config() fehlende Schluessel
 * nur im Speicher; in die Datei kamen sie erst beim naechsten Speichern im Formular. Aufgerufen vom Minutentakt
 * (bin/wd_takt.php) VOR wd_lauf(), also vor dem ersten Lesen der Konfiguration.
 *
 *  - nur mit LoxBerry-Wurzel und nicht waehrend einer Aktualisierung (Marke data/plugins/<ordner>.upgrade_laeuft,
 *    dieselbe Regel wie wd_gesundheit(): juenger als eine Stunde gilt; ist ihr Alter nicht feststellbar, gilt sie);
 *  - nur an einer Datei mit Merkwort: ein JSON-Objekt mit einem nicht leeren Aktionstoken in der Form, die
 *    wd_wert_pruefen() annimmt; eine fehlende, leere, kaputte oder tokenlose Datei ist Sache von wd_selbstheilung();
 *  - geschrieben werden die WERTE AUS DER DATEI plus die fehlenden Vorgaben, nicht das, was wd_config() daraus macht;
 *    fremde Schluessel bleiben stehen; nur die Schluessel aus wd_vorgaben(), die das Speichern im Formular ebenfalls
 *    schreibt;
 *  - Lese-Regel: ein fehlender oder leerer Block tts gilt in wd_config() als Werkblock ("nie gesetzt = ab Werk EIN").
 *    Eingetragen wird die Vorgabe (leerer Block) - so bleibt er "nie gesetzt", und wd_config() liefert danach
 *    dasselbe wie vorher. Weitere Uebergangsregeln fuer Bestandsdateien hat wd_config() nicht;
 *  - geschrieben wird nur, wenn etwas fehlt; Weg wie beim Speichern (wd_config_speichern(), 0600, danach
 *    wd_zweitschrift()); eine Protokollzeile mit den Namen, nie mit Werten.
 *
 * Rueckgabe: die Namen der eingetragenen Schluessel; leer, wenn nichts geschrieben wurde.
 */
function wd_config_vervollstaendigen()
{
    $p = wd_paths();
    if ($p['lbhome'] === '') { return array(); }
    $m = $p['datadir'] . '.upgrade_laeuft';
    clearstatcache(true, $m);
    if (file_exists($m)) {
        $mt = @filemtime($m);
        if ($mt === false || time() - (int) $mt < 3600) { return array(); }
    }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config']) || !is_readable($p['config'])) { return array(); }
    $roh = @file_get_contents($p['config']);
    if (!is_string($roh) || substr(ltrim($roh), 0, 1) !== '{') { return array(); }
    $datei = json_decode($roh, true);
    if (!wd_hat_inhalt($datei) || !preg_match('/^[A-Za-z0-9]{1,64}\z/', $datei['aktionstoken'])) { return array(); }
    // Ein leeres Objekt der Datei ({}) bliebe nach json_decode(..., true) ein leeres Feld und kaeme beim Schreiben
    // als [] zurueck - die leeren Objekte des Rohtextes bleiben Objekte (11.11.2026).
    $datei = wd_json_leere_objekte($datei, json_decode($roh));
    $fehlend = array();
    foreach (wd_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = (string) $k;
        }
    }
    if (!$fehlend) { return array(); }
    if (!wd_config_speichern($datei)) { return array(); }
    wd_zweitschrift($datei);
    /* Ohne Anzahl: wd_log_sauber() ersetzt jeden Standortwert im Text durch ***, eine Zahl wie "12" koennte einer sein. */
    wd_log('Konfiguration vervollstaendigt, fehlende Einstellungen mit der Vorgabe eingetragen: ' . implode(', ', $fehlend) . '.');
    return $fehlend;
}

function wd_datei_schreiben($pfad, $inhalt, $modus = 0600)
{
    $inhalt = (string) $inhalt;
    $v = dirname($pfad);
    if (!is_dir($v)) { @mkdir($v, 0775, true); }
    $t = $pfad . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($t, '') === false) { return false; }
    if ($modus !== null) { @chmod($t, $modus); }
    if (@file_put_contents($t, $inhalt) !== strlen($inhalt)) { @unlink($t); return false; }
    if (!@rename($t, $pfad)) { @unlink($t); return false; }
    return true;
}

function wd_json_lesen($pfad)
{
    if (!is_file($pfad)) { return null; }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : null;
}

function wd_json_schreiben($pfad, $d, $modus = 0600)
{
    $js = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js === false ? false : wd_datei_schreiben($pfad, $js, $modus);
}

/** $anlegen = false: nur lesen, die unangemeldeten Endpunkte legen nichts an. */
function wd_tmpdir($anlegen = true)
{
    $t = wd_paths()['tmp'];
    if (!$anlegen) {
        if (!is_dir($t)) { return $t; }
        $a = $t . '-' . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'eigen');
        return (!function_exists('posix_geteuid') || @fileowner($t) === posix_geteuid() || !is_dir($a)) ? $t : $a;
    }
    if (!is_dir($t)) { @mkdir($t, 0775, true); }
    clearstatcache(true, $t);
    if (is_dir($t) && is_writable($t) && (!function_exists('posix_geteuid') || @fileowner($t) === posix_geteuid())) { return $t; }
    $a = $t . '-' . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'eigen');
    if (!is_dir($a)) { @mkdir($a, 0700, true); }
    return is_writable($a) ? $a : $t;
}

function wd_datadir()
{
    $d = wd_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

/** Protokoll. Der Standort kommt hier nie hinein: Koordinaten, Warnzelle und Schluessel werden je Zeile gestrichen. */
function wd_log($t)
{
    $p = wd_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        @file_put_contents($f, implode("\n", array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200)) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . wd_log_sauber($t) . "\n", FILE_APPEND);
}

function wd_log_sauber($t)
{
    $t = (string) $t;
    $c = wd_inhalt_oder_null(wd_paths()['config']);
    foreach (is_array($c) ? array('lat', 'lon', 'warnzelle', 'om_apikey', 'ow_key', 'wa_key', 'vc_key', 'tm_key', 'mb_key', 'station_host1', 'station_host2', 'nina_ars', 'pollen_region', 'ma_emma', 'wu_key', 'wu_station') : array() as $k) {
        if (isset($c[$k]) && is_scalar($c[$k]) && (string) $c[$k] !== '') { $t = str_replace((string) $c[$k], '***', $t); }
    }
    $t = preg_replace('/(latitude|longitude|lat|lon|apikey|apiKey|appid|key|warn_cell_id|coord|user|token|stationId)=[^&\s]+/', '$1=***', $t);
    return str_replace(array("\r", "\n"), ' ', $t);
}

function wd_log_einmal($sl, $t)
{
    $f = wd_tmpdir() . '/last_' . preg_replace('/[^a-z0-9_]/', '', $sl) . '.txt';
    if (is_file($f) && (string) @file_get_contents($f) === (string) $t) { return; }
    @file_put_contents($f, (string) $t);
    wd_log($t);
}

function wd_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) { $s = LBSystem::lblanguage(); }
    elseif (getenv('LBLANG')) { $s = getenv('LBLANG'); }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/** Sprachtexte. $sprache setzt der Ansagelauf ausdruecklich, denn dort gibt es keine Oberflaeche. */
function wd_t($sl, $sprache = null)
{
    static $t = array();
    $sp = $sprache === null ? wd_sprache() : $sprache;
    if (!isset($t[$sp])) {
        $fp = wd_paths();
        $o = $fp['lbhome'] !== '' ? $fp['lbhome'] . '/templates/plugins/' . $fp['plugin'] . '/lang' : '';
        if ($o === '' || !is_dir($o)) { $o = dirname(dirname(dirname(__FILE__))) . '/templates/lang'; }
        $x = @parse_ini_file($o . '/language_' . $sp . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($x)) { $x = array(); }
        $r = @parse_ini_file($o . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($r)) { $x = array_replace_recursive($r, $x); }
        foreach ($x as $ab => $pa) {
            if (!is_array($pa)) { continue; }
            foreach ($pa as $s => $w) { $x[$ab][$s] = trim((string) $w, '"'); }
        }
        $t[$sp] = $x;
    }
    list($a, $s) = array_pad(explode('.', $sl, 2), 2, '');
    return isset($t[$sp][$a][$s]) ? $t[$sp][$a][$s] : $sl;
}

function wd_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function wd_ansage_k($cfg = null)
{
    return array('port' => wd_webport(), 'sslport' => ansage_sslport(wd_paths()['general']),
        'kopf' => array('User-Agent: LoxBerry Weatherdata-Plugin'), 'ordner' => wd_datadir(),
        't' => function ($s) { return wd_t($s); }, 'werk' => 'musicserver',
        'schluessel' => array('ART_HINWEIS' => 'TTS.ART_HINWEIS'));
}

function wd_ansage_modi() { return array('aus', 'musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox', 'sonos4lox', 'sonox'); }

function wd_ansage_regeln($c = null)
{
    if ($c === null) { $c = wd_config(); }
    $g = '';
    $r = isset($c['ansage_regeln']) ? ansage_regeln_wert($c['ansage_regeln'], $g) : null;
    return $r === null ? ansage_regeln_vorgaben() : $r;
}

function wd_webport()
{
    $g = wd_paths()['general'];
    if ($g !== '' && is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        $q = (is_array($d) && isset($d['Webserver']['Port']) && is_scalar($d['Webserver']['Port'])) ? (int) $d['Webserver']['Port'] : 0;
        if ($q > 0 && $q <= 65535) { return $q; }
    }
    return 80;
}

function wd_merkwort()
{
    static $w = null;
    if ($w !== null) { return $w; }
    $f = wd_datadir() . '/formmerkwort';
    if (is_readable($f)) {
        $r = trim((string) @file_get_contents($f));
        if (preg_match('/^[0-9a-f]{32,64}$/', $r)) { return $w = $r; }
    }
    $n = bin2hex(random_bytes(24));
    wd_datei_schreiben($f, $n, 0600);
    return $w = $n;
}
function wd_formtoken() { return hash_hmac('sha256', 'formular-v1', wd_merkwort()); }
function wd_fmt() { return '<input data-role="none" type="hidden" name="fmt" value="' . wd_e(wd_formtoken()) . '">'; }
function wd_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { return ''; }
    $ist = isset($_POST['fmt']) ? $_POST['fmt'] : null;
    if (!is_string($ist) || $ist === '') { return wd_t('WACHE.FEHLT'); }
    return hash_equals(wd_formtoken(), $ist) ? '' : wd_t('WACHE.FALSCH');
}

/* ===================================================== MQTT ===================================================== */

/**
 * Die Thementabelle. Thema unter dem Praefix => array(Art, Noncached, Retain, Pflicht, Min, Max, Einheit).
 * Art z = Virtueller Eingang (Zahl oder "-"), t = Virtueller Texteingang.
 * Pflicht P = jede Anlage braucht das Thema fuer die Logik, W = wahlweise Anzeige.
 * Retain nur "fassung": alles andere ist Messwert mit Zeitbezug, wird durch die Uhr falsch oder ist eine Aussage des
 * Dienstes ueber sich selbst (Regeln/07, Entscheidungen 18./19./24.09.2026). Ein leerer Wert geht nie hinaus: keine
 * Aussage ist "-" (Entscheidung 29.09., Nr. 5), die Logik haengt an den Zahlenthemen .../ok.
 * Bedeutung je Thema: Sprachdatei, Abschnitt THEMA, Schluessel = Thema mit "_" statt "/".
 */
function wd_mqtt_tabelle()
{
    $t = array(
        'status/ok' => array('z', 1, 0, 'P', 0, 1, ''), 'status/ts' => array('z', 0, 0, 'W', 0, 4102444800, 's'),
        'status/zaehler' => array('z', 1, 0, 'P', 0, 999, ''),
    );
    foreach (array_keys(wd_quellen_liste()) as $d) {
        $t['quelle/' . $d . '/ok'] = array('z', 1, 0, in_array($d, array('wttr', 'wunderground', 'loxone'), true) ? 'W' : 'P', 0, 1, '');      // ab 0.9.4 ab Werk aus: kein neuer Pflichthaken fuer bestehende Anlagen
        $t['quelle/' . $d . '/alter_s'] = array('z', 0, 0, 'W', 0, 1000000, 's');
    }
    $t += array(
        'jetzt/ok' => array('z', 1, 0, 'P', 0, 1, ''), 'jetzt/ist_ersatz' => array('z', 1, 0, 'P', 0, 1, ''),
        'jetzt/temp_c' => array('z', 0, 0, 'P', -60, 70, 'C'), 'jetzt/rf_pct' => array('z', 0, 0, 'P', 0, 100, '%'),
        'jetzt/taupunkt_c' => array('z', 0, 0, 'W', -70, 50, 'C'), 'jetzt/druck_hpa' => array('z', 0, 0, 'W', 800, 1200, 'hPa'),
        'jetzt/wind_kmh' => array('z', 0, 0, 'P', 0, 300, 'km/h'), 'jetzt/boe_kmh' => array('z', 0, 0, 'W', 0, 400, 'km/h'),
        'jetzt/windrichtung_grad' => array('z', 0, 0, 'W', 0, 360, 'Grad'), 'jetzt/regen_mmh' => array('z', 0, 0, 'P', 0, 500, 'mm/h'),
        'jetzt/regnet' => array('z', 0, 0, 'W', 0, 1, ''), 'jetzt/strahlung_wm2' => array('z', 0, 0, 'P', 0, 2000, 'W/m2'),
        'jetzt/uv' => array('z', 0, 0, 'P', 0, 20, ''),
        'gefuehlt/ok' => array('z', 1, 0, 'P', 0, 1, ''), 'gefuehlt/c' => array('z', 0, 0, 'W', -80, 70, 'C'), 'gefuehlt/art' => array('t', 0, 0, 'W', 0, 0, ''),
        // 0.9.14: WBGT-Schaetzung, nur mit Haken wbgt_ein in Hakenliste, Vorlage und Versand (wd_wbgt_themen()); Messwerte, nicht retained.
        'jetzt/wbgt_ok' => array('z', 1, 0, 'W', 0, 1, ''), 'jetzt/wbgt_c' => array('z', 0, 0, 'W', -60, 60, 'C'),
        'jetzt/wbgt_stufe' => array('z', 0, 0, 'W', 0, 5, ''), 'jetzt/wbgt_wort' => array('t', 0, 0, 'W', 0, 0, ''),
        'jetzt/globe_c' => array('z', 0, 0, 'W', -60, 100, 'C'), 'jetzt/feuchtkugel_c' => array('z', 0, 0, 'W', -60, 60, 'C'),
        'prog/ok' => array('z', 1, 0, 'P', 0, 1, ''),
        'prog/h1/regen_mm' => array('z', 0, 0, 'W', 0, 500, 'mm'), 'prog/h3/regen_mm' => array('z', 0, 0, 'W', 0, 500, 'mm'),
        'prog/h4/solar_wm2' => array('z', 0, 0, 'P', 0, 1500, 'W/m2'),
        'prog/h12/tmin_c' => array('z', 0, 0, 'P', -60, 60, 'C'), 'prog/h12/tmax_c' => array('z', 0, 0, 'P', -60, 60, 'C'),
        'prog/h12/regen_mm' => array('z', 0, 0, 'P', 0, 1000, 'mm'), 'prog/h12/regen_w_pct' => array('z', 0, 0, 'W', 0, 100, '%'),
        'prog/h12/boe_max_kmh' => array('z', 0, 0, 'W', 0, 400, 'km/h'), 'prog/h12/solar_wm2' => array('z', 0, 0, 'P', 0, 1500, 'W/m2'),
        'prog/h24/tmin_c' => array('z', 0, 0, 'W', -60, 60, 'C'), 'prog/h24/tmax_c' => array('z', 0, 0, 'P', -60, 60, 'C'),
        'prog/h24/schnee_cm' => array('z', 0, 0, 'P', 0, 500, 'cm'), 'prog/h24/solar_wm2' => array('z', 0, 0, 'P', 0, 1500, 'W/m2'),
        'prog/h12/regen_max_mm' => array('z', 0, 0, 'W', 0, 1000, 'mm'),
        'prog/h48/tmittel_c' => array('z', 0, 0, 'W', -60, 60, 'C'), 'prog/h48/solar_wm2' => array('z', 0, 0, 'P', 0, 1500, 'W/m2'),
    );
    foreach (array('tag0', 'tag1', 'tag2') as $g) {
        $t['prog/' . $g . '/tmax_c'] = array('z', 0, 0, $g === 'tag2' ? 'W' : 'P', -60, 60, 'C');
        $t['prog/' . $g . '/tmin_c'] = array('z', 0, 0, 'W', -60, 60, 'C');
        $t['prog/' . $g . '/code'] = array('z', 0, 0, 'W', 0, 99, '');
    }
    $t += array(
        'warnung/ok' => array('z', 1, 0, 'P', 0, 1, ''), 'warnung/stufe' => array('z', 1, 0, 'P', 0, 4, ''),
        'warnung/anzahl' => array('z', 0, 0, 'W', 0, 99, ''), 'warnung/zaehler' => array('z', 1, 0, 'W', 0, 999, ''),
        'warnung/beginn_ts' => array('z', 0, 0, 'W', 0, 4102444800, 's'), 'warnung/ende_ts' => array('z', 0, 0, 'W', 0, 4102444800, 's'),
        'warnung/beginn_min' => array('z', 0, 0, 'W', 0, 100000, 'min'),
        'warnung/klasse' => array('t', 0, 0, 'W', 0, 0, ''), 'warnung/art' => array('t', 0, 0, 'W', 0, 0, ''),
        'warnung/text' => array('t', 0, 0, 'P', 0, 0, ''), 'warnung/volltext' => array('t', 0, 0, 'W', 0, 0, ''),
        'warnung/quelle' => array('t', 0, 0, 'W', 0, 0, ''),
        'schwelle/ok' => array('z', 1, 0, 'P', 0, 1, ''), 'schwelle/hitze_32' => array('z', 0, 0, 'P', 0, 1, ''),
        'schwelle/hitze_35' => array('z', 0, 0, 'W', 0, 1, ''), 'schwelle/hitze_38' => array('z', 0, 0, 'W', 0, 1, ''),
        'schwelle/frost' => array('z', 0, 0, 'P', 0, 1, ''), 'schwelle/sturm' => array('z', 0, 0, 'P', 0, 1, ''), 'schwelle/regen' => array('z', 0, 0, 'P', 0, 1, ''),
        'hitze/warnung' => array('z', 0, 0, 'W', 0, 1, ''), 'hitze/gefuehlt' => array('z', 0, 0, 'W', -80, 70, 'C'),
        'kuehlen_moeglich' => array('z', 0, 0, 'W', 0, 1, ''), 'kuehlen/innen_c' => array('z', 0, 0, 'W', -20, 60, 'C'),
        'uv/index' => array('z', 0, 0, 'W', 0, 20, ''), 'uv/hoch' => array('z', 0, 0, 'W', 0, 1, ''),
        'frost/erwartet' => array('z', 0, 0, 'P', 0, 1, ''), 'starkregen/warnung' => array('z', 0, 0, 'P', 0, 1, ''),
        'sturm/vorwarnung' => array('z', 0, 0, 'P', 0, 1, ''),
        'uv/prog' => array('z', 0, 0, 'W', 0, 20, ''),
        'luft/ok' => array('z', 1, 0, 'W', 0, 1, ''), 'luft/index' => array('z', 0, 0, 'W', 0, 500, ''), 'luft/pm10' => array('z', 0, 0, 'W', 0, 2000, 'ug/m3'), 'luft/pm25' => array('z', 0, 0, 'W', 0, 2000, 'ug/m3'), 'luft/ozon' => array('z', 0, 0, 'W', 0, 2000, 'ug/m3'), 'luft/no2' => array('z', 0, 0, 'W', 0, 2000, 'ug/m3'),
        'lueftung_sperren' => array('z', 0, 0, 'W', 0, 1, ''),
        'pollen/ok' => array('z', 1, 0, 'W', 0, 1, ''),
        'radar/ok' => array('z', 1, 0, 'W', 0, 1, ''), 'radar/regen_in_min' => array('z', 0, 0, 'W', 0, 300, 'min'), 'radar/regen_mmh' => array('z', 0, 0, 'W', 0, 500, 'mm/h'),
        'bilanz/fehler_k' => array('z', 0, 0, 'W', 0, 30, 'K'),
        'nina/ok' => array('z', 1, 0, 'W', 0, 1, ''), 'nina/stufe' => array('z', 1, 0, 'W', 0, 4, ''), 'nina/anzahl' => array('z', 0, 0, 'W', 0, 99, ''), 'nina/text' => array('t', 0, 0, 'W', 0, 0, ''),
        'fassung' => array('t', 0, 1, 'W', 0, 0, ''),
    );
    foreach (wd_pollen_arten() as $a) {
        $t['pollen/' . strtolower($a) . '/heute'] = array('z', 0, 0, 'W', 0, 3, '');
        $t['pollen/' . strtolower($a) . '/morgen'] = array('z', 0, 0, 'W', 0, 3, '');
    }
    foreach (array(1, 2, 3, 4) as $f) { $t['prog/fassade' . $f . '/wm2'] = array('z', 0, 0, 'W', 0, 1500, 'W/m2'); }
    foreach (wd_herkunft_werte() as $h) { $t['herkunft/' . $h] = array('t', 0, 0, 'W', 0, 0, ''); }
    // 0.9.8: Pflicht ist genau, was die Musterseiten verdrahten (wd_pflicht_themen()); alles andere wahlweise.
    $pf = array_flip(wd_pflicht_themen());
    foreach ($t as $k => $f) { $t[$k][3] = isset($pf[$k]) ? 'P' : 'W'; }
    return $t;
}

/** 0.9.8: die 25 Themen, die die Musterseiten "Weatherdata" und "Weatherdata Werte und Signale" verdrahten (Baustein-Liste MPL.*,
 *  Quelle wd097_mp_spec.py). Nur sie sind Pflichthaken; die Probe haelt die Liste gegen die Baustein-Liste. */
function wd_pflicht_themen()
{
    return array('status/ok', 'status/zaehler', 'jetzt/ok', 'jetzt/ist_ersatz', 'jetzt/temp_c', 'jetzt/rf_pct', 'jetzt/wind_kmh', 'jetzt/regen_mmh', 'jetzt/strahlung_wm2', 'jetzt/uv', 'prog/ok', 'prog/h4/solar_wm2', 'prog/h12/tmin_c', 'prog/h12/tmax_c', 'prog/h12/regen_mm', 'prog/h24/tmax_c', 'prog/h24/schnee_cm', 'prog/tag1/tmax_c', 'warnung/ok', 'warnung/stufe', 'warnung/text', 'schwelle/hitze_32', 'frost/erwartet', 'starkregen/warnung', 'sturm/vorwarnung');
}

/** 0.9.14: die WBGT-Themen. EINE Liste fuer Versand (wd_werte), Hakenliste, Importvorlage und Selbstpruefung; sie gelten nur mit Haken wbgt_ein. */
function wd_wbgt_themen()
{
    return array('jetzt/wbgt_ok', 'jetzt/wbgt_c', 'jetzt/wbgt_stufe', 'jetzt/wbgt_wort', 'jetzt/globe_c', 'jetzt/feuchtkugel_c');
}

function wd_herkunft_werte()
{
    return array('temp_c', 'rf_pct', 'taupunkt_c', 'druck_hpa', 'wind_kmh', 'boe_kmh', 'windrichtung_grad', 'regen_mmh', 'regnet', 'strahlung_wm2', 'uv', 'prog', 'warnung');
}

function wd_thema_retain($thema)
{
    $t = wd_mqtt_tabelle();
    return isset($t[$thema]) && !empty($t[$thema][2]);
}

function wd_mqtt_thema($s)
{
    return trim((string) preg_replace('#/+#', '/', preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $s)), '/');
}

function wd_mqtt_praefix($c = null)
{
    if ($c === null) { $c = wd_config(); }
    $p = wd_mqtt_thema(isset($c['mqtt_topic']) && is_scalar($c['mqtt_topic']) ? $c['mqtt_topic'] : '');
    return $p !== '' ? $p : 'weatherdata';
}

function wd_gateway_name($praefix, $thema) { return str_replace(array('/', '%'), '_', wd_mqtt_thema($praefix . '/' . $thema)); }

function wd_nutzlast($w)
{
    $s = trim((string) preg_replace('/\s+/', ' ', (string) $w));
    return $s === '' ? '-' : $s;
}

/** UDP-Eingang des Gateways: publish, fuer die Retain-Tabelle retain. Ein leerer Wert ist vorher zu "-" geworden. */
function wd_mqtt_senden(array $werte, $c)
{
    if (empty($c['mqtt_ein'])) { return 0; }
    $p = wd_paths();
    if ($p['general'] === '') { return 0; }
    $g = wd_json_lesen($p['general']);
    $port = is_array($g) && isset($g['Mqtt']['Udpinport']) ? (int) $g['Mqtt']['Udpinport'] : 0;
    if ($port < 1 || $port > 65535) {
        wd_log_einmal('mqtt', 'Kein brauchbarer UDP-Eingangsport in der general.json - ist das MQTT-Gateway eingerichtet?');
        return 0;
    }
    wd_praefix_haus_melden($c);   // 0.9.23: Praefix im gemeinsamen Bereich? melden, nicht anhalten
    $s = @stream_socket_client('udp://127.0.0.1:' . $port, $en, $es, 1);
    if (!is_resource($s)) { wd_log_einmal('mqtt', 'UDP-Ausgang zum MQTT-Gateway liess sich nicht oeffnen.'); return 0; }
    $pf = wd_mqtt_praefix($c);
    $n = 0;
    foreach ($werte as $k => $w) {
        $wort = wd_thema_retain($k) ? 'retain' : 'publish';
        $msg = $wort . ' ' . wd_mqtt_thema($pf . '/' . $k) . ' ' . wd_nutzlast($w);
        if (@fwrite($s, $msg) === strlen($msg)) { $n++; }
        if (count($werte) > 5) { usleep(5000); }
    }
    fclose($s);
    return $n;
}

/** Rueckfall Gateway V1: die Abo-Datei folgt dem Praefix (Regeln/07). Schreibt nur, wenn sie abweicht. */
function wd_abo_nachziehen($c)
{
    $p = wd_paths();
    if (empty($p['lbhome'])) { return false; }
    $f = dirname($p['config']) . '/mqtt_subscriptions.cfg';
    $soll = wd_mqtt_praefix($c) . '/#';
    if (trim((string) @file_get_contents($f)) === $soll) { return false; }
    if (!wd_datei_schreiben($f, $soll . "\n", 0644)) { return false; }
    wd_log('Abo-Datei fuer Gateway V1 auf den Praefix gesetzt.');
    return true;
}

/** Hakenliste: Thema, Gateway-Name, Noncached, Retain, Pflicht, Art. Die Reihenfolge ist die der Tabelle. */
function wd_hakenliste($c = null)
{
    $pf = wd_mqtt_praefix($c);
    $a = array();
    $ohne = empty(($c === null ? wd_config() : $c)['wbgt_ein']) ? array_flip(wd_wbgt_themen()) : array();     // 0.9.14: WBGT nur mit Haken
    foreach (wd_mqtt_tabelle() as $t => $f) {
        if (isset($ohne[$t])) { continue; }
        $a[] =array('thema' => wd_mqtt_thema($pf . '/' . $t), 'name' => wd_gateway_name($pf, $t), 'nc' => $f[1], 'retain' => $f[2], 'pflicht' => $f[3], 'art' => $f[0],
                     'min' => $f[4], 'max' => $f[5], 'einheit' => $f[6], 'kurz' => $t, 'text' => wd_t('THEMA.' . str_replace('/', '_', $t)));
    }
    return $a;
}

function wd_gateway_fassung()
{
    $g = wd_paths()['general'];
    $d = $g !== '' ? wd_json_lesen($g) : null;
    return (is_array($d) && isset($d['Mqtt']['Gatewayversion'])) ? (int) $d['Mqtt']['Gatewayversion'] : 0;
}

/**
 * Fuer bin/healthcheck: array(Status, Text). 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis. Nur lesen, deshalb nicht wd_config():
 * die heilt aus der Zweitschrift. Die Texte nennen weder Standort noch Warnzelle noch Adressen.
 */
function wd_gesundheit($jetzt = null, $etc = '/etc/apache2')
{
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $p = wd_paths();
    if ($p['lbhome'] === '') {
        return array(3, wd_t('GESUND.KEINE_WURZEL'));
    }
    $m = $p['datadir'] . '.upgrade_laeuft';
    if (is_file($m) && $jetzt - (int) @filemtime($m) < 3600) {
        return array(6, wd_t('GESUND.UPGRADE'));
    }
    $c = wd_inhalt_oder_null($p['config']);
    if ($c === null && is_file($p['config']) && trim((string) @file_get_contents($p['config'])) !== '') {
        return array(3, wd_t('GESUND.KONFIG'));
    }
    $c = (is_array($c) ? $c : array()) + wd_vorgaben();
    if ((string) $c['lat'] === '' || (string) $c['lon'] === '' || (string) $c['warnzelle'] === '') {
        return array(6, wd_t('GESUND.STANDORT'));
    }
    $st = wd_json_lesen(wd_tmpdir(false) . '/zustand.json');
    if (!is_array($st) || !isset($st['ts'])) {
        return array(4, wd_t('GESUND.KEIN_LAUF'));
    }
    if ($jetzt - (int) $st['ts'] > 300) {
        return array(3, sprintf(wd_t('GESUND.ALT'), (int) floor(($jetzt - (int) $st['ts']) / 60)));
    }
    $q = isset($st['quellen']) && is_array($st['quellen']) ? $st['quellen'] : array();
    foreach (array('open_meteo' => 'Open-Meteo', 'dwd' => 'DWD') as $k => $n) {
        if (!empty($q[$k]['ein']) && empty($q[$k]['ok'])) {
            return array(4, sprintf(wd_t('GESUND.QUELLE'), $n));
        }
    }
    $em = wd_emu_gesund($c, $jetzt, $etc);
    if ($em !== null) {
        return array($em[0], $em[2] !== '' ? sprintf(wd_t($em[1]), $em[2]) : wd_t($em[1]));
    }
    list($v2) = wd_v2_lage($c);
    if ($v2 === false) {
        return array(4, wd_t('GESUND.HAKEN'));
    }
    return array(5, wd_t('GESUND.OK'));
}

function wd_gateway_autostart()
{
    $g = wd_paths()['general'];
    $d = $g !== '' ? wd_json_lesen($g) : null;
    return (is_array($d) && isset($d['Mqtt'])) ? !empty($d['Mqtt']['Gatewayautostart']) : null;
}

/** Lage der V2-Haken. null = nicht feststellbar. Liest subscriptions.json nur. */
function wd_v2_lage($c)
{
    $f = wd_gateway_fassung();
    $h = wd_paths()['lbhome'];
    if ($f === 0 || $h === '') { return array(null, wd_t('PRUEF.V2_UNKLAR')); }
    if ($f < 2) { return array(null, wd_t('PRUEF.V2_V1')); }
    if (empty($c['mqtt_ein'])) { return array(null, wd_t('PRUEF.V2_AUS')); }
    $d = wd_json_lesen($h . '/config/system/subscriptions.json');
    if ($d === null) { return array(null, wd_t('PRUEF.V2_DATEI')); }
    $abo = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        foreach (isset($d[$s]) && is_array($d[$s]) ? $d[$s] : array() as $e) {
            if (!is_array($e)) { continue; }
            $id = ''; foreach (array('Id', 'id', 'Topic', 'topic') as $k) { if (isset($e[$k]) && is_string($e[$k])) { $id = trim($e[$k]); break; } }
            $nc = false; foreach (array('Noncached', 'noncached') as $k) { if (isset($e[$k]) && is_scalar($e[$k])) { $nc = in_array(strtolower((string) $e[$k]), array('1', 'true', 'yes', 'on'), true) || $e[$k] === true; break; } }
            if ($id !== '') { $abo[$id] = $nc; }
        }
    }
    $fehlt = array(); $ncf = array(); $n = 0; $da = 0;
    foreach (wd_hakenliste($c) as $z) {
        if ($z['pflicht'] !== 'P') { if (isset($abo[$z['thema']]) && $z['nc'] && !$abo[$z['thema']]) { $ncf[] = $z['thema']; } continue; }
        $n++;
        if (!isset($abo[$z['thema']])) { $fehlt[] = $z['thema']; continue; }
        $da++;
        if ($z['nc'] && !$abo[$z['thema']]) { $ncf[] = $z['thema']; }
    }
    if (!$fehlt && !$ncf) { return array(true, sprintf(wd_t('PRUEF.V2_OK'), $n)); }
    // 0.9.8: nur nennen, was wirklich fehlt (bis 0.9.7 stand "Noncached fehlt: ." auch mit leerer Liste); dazu, was das bedeutet.
    $kurz = function ($l) { return implode(', ', array_slice($l, 0, 6)) . (count($l) > 6 ? ' ' . sprintf(wd_t('PRUEF.V2_MEHR'), count($l) - 6) : ''); };
    $tx = sprintf(wd_t('PRUEF.V2_ZAHL'), $da, $n);
    if ($fehlt) { $tx .= ' ' . sprintf(wd_t('PRUEF.V2_FEHLT_L'), $kurz($fehlt)); }
    if ($ncf) { $tx .= ' ' . sprintf(wd_t('PRUEF.V2_NC_L'), $kurz($ncf)); }
    return array(false, $tx . ' ' . wd_t('PRUEF.V2_HILFE'));
}

/** Importvorlage: Kunstgriff VirtualInHttp mit Dummy-Adresse (Regeln/07). Nur Zahlenthemen, Texte brauchen Virtuelle Texteingaenge. */
function wd_vorlage($c = null)
{
    $crlf = "\r\n";
    $vx = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf . '<VirtualInHttp Title="Weatherdata" Comment="' . $vx(wd_t('VORLAGE.KOMMENTAR')) . '" Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach (wd_hakenliste($c) as $z) {
        if ($z['art'] !== 'z') { continue; }
        $an = !($z['min'] === 0 && $z['max'] === 1);
        $o .= "\t" . '<VirtualInHttpCmd Title="' . $vx($z['name']) . '" Comment="' . $vx($z['text']) . '" Check=" " Signed="' . ($z['min'] < 0 ? 'true' : 'false') . '" Analog="' . ($an ? 'true' : 'false')
            . '" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . (int) $z['min'] . '" MaxVal="' . (int) $z['max'] . '" Unit="' . $vx($z['einheit'] !== '' ? '<v.1> ' . $z['einheit'] : '<v.0>') . '" HintText="' . $vx($z['text']) . '"/>' . $crlf;
    }
    return array('VI_weatherdata.xml', $o . '</VirtualInHttp>' . $crlf);
}

/**
 * 0.9.7: Baustein-Liste der Musterseiten "Weatherdata" und "Weatherdata Werte und Signale", wortgleich mit dem Musterprojekt und mit
 * baustein_listen.txt (eine Quelle: wd097_mp_spec.py, Sprachschluessel MPL.*). Rueckgabe array(Seitentitel => array(array(Nr, Typ, Name, Parameter, Eingaenge))).
 * Die Namen der Virtuellen Eingaenge folgen dem Praefix aus dem Reiter MQTT; das Musterprojekt nutzt den Vorgabepraefix weatherdata.
 */
function wd_bausteinliste($c = null)
{
    $pf = wd_mqtt_praefix($c);
    $o = array();
    for ($s = 1; wd_t('MPL.S' . $s) !== 'MPL.S' . $s; $s++) {
        $n = (int) wd_t('MPL.ANZ' . $s); $z = array();
        for ($i = 1; $i <= $n; $i++) {
            $k = 'MPL.S' . $s . '_' . $i . '_';
            $name = wd_t($k . 'N');
            if (strpos($name, 'weatherdata_') === 0) { $name = str_replace('/', '_', $pf) . substr($name, strlen('weatherdata')); }
            $z[] = array($i, wd_t($k . 'T'), $name, wd_t($k . 'P'), wd_t($k . 'E'));
        }
        $o[wd_t('MPL.S' . $s)] = $z;
    }
    return $o;
}

/* ===================================================== Sicherung ===================================================== */

/*
 * 0.9.19: "Einstellungen sichern" und "Einstellungen zurueckspielen" laufen ueber das gemeinsame Sicherungsmodul
 * (sicherung.php, Regeln/05; Auftrag des Hausherrn 18.11.2026 "Ja, umbauen"). Diese Linie uebergibt nur ihre
 * Beschreibung. Bis 0.9.18 standen hier eigene Funktionen (wd_sicherung_stand(), wd_sicherung_schluessel_pflicht(),
 * wd_sicherung_lesen()): Kopf nur _warnung, ein unbekannter Schluessel oben wies die ganze Datei ab, ein fehlender
 * spaeter Schluessel bekam die Vorgabe, der Vermerk ansage_token_herkunft fiel beim Zurueckspielen weg.
 * Seither (Modulregeln, in allen Linien gleich): Kopf mit dem Kennzeichen _plugin; eine fremde Datei und eine Datei ohne
 * Kennzeichen werden als Ganzes abgewiesen, ausser einer Sicherung dieser Linie bis 0.9.18 (wd_sicherung_alt_erkennen());
 * unbekannte Schluessel werden verworfen und mit Namen genannt; ein leerer Wert ueberschreibt keinen bestehenden;
 * spaeter hinzugekommene Schluessel behalten ihren geltenden Wert und werden genannt; eine halb gueltige Datei aendert
 * nichts; Zurueckspielen nur mit Haken.
 */

/**
 * 0.9.19: Die Vermerke dieser Linie - sie gehoeren zur Installation, nicht zur Sicherung: nie gesichert, beim
 * Zurueckspielen bleibt der geltende Wert ('nie_aus_datei'). ansage_token_herkunft: woher die Sprechtoken stammen
 * (Sprachmodul). _haus_frueher / _pv_frueher: diese Installation war Anbieter von haus/wetter bzw. haus/pv (0.9.8,
 * 0.9.10). Bis 0.9.18 hielt index.php die beiden Merker beim Zurueckspielen von Hand fest; den Vermerk der Sprechtoken
 * verlor das Zurueckspielen.
 */
function wd_sicherung_vermerke()
{
    return array('ansage_token_herkunft', '_haus_frueher', '_pv_frueher');
}

/**
 * 0.9.19: Woran eine Sicherung bis 0.9.18 erkannt wird. Keine Fassung bis 0.9.18 schrieb einen Kopf mit _plugin
 * (gemessen 18.11.2026 am Sichern-Knopf aller 19 Fassungen 0.9.0 bis 0.9.18 im Arbeitsordner), jede aber diese
 * Schluessel: das Aktionstoken, die Einstellungen aus wd_felder() von 0.9.0 ohne die Schluessel der Dienste (die
 * 0.9.6 bis 0.9.16 nicht sicherten) und die Bloecke tts und ansage_regeln. Gegen die anderen Linien gemessen:
 * Pruefung-Weatherdata-0.9.19/ohne_kopf/. Ein Schluessel, der hier fehlt, ist beim Zurueckspielen Pflicht; jeder
 * andere Schluessel aus wd_vorgaben() darf in einer aelteren Sicherung fehlen und behaelt seinen geltenden Wert.
 */
function wd_sicherung_alt_merkmal()
{
    return array(
        'aktionstoken', 'lat', 'lon', 'pos_stellen', 'warnzelle', 'om_takt_min', 'dwd_takt_min',
        'prog_max_alter_min', 'warn_max_alter_min', 'hitze_basis', 'hitze1_c', 'hitze2_c', 'hitze3_c', 'hysterese_k',
        'frost_c', 'sturm_kmh', 'regen1h_mm', 'regen12h_mm', 'uv_hoch', 'sturm_vor_min', 'lueften_delta_k',
        'lueften_innen_min_c', 'lueften_letzte', 'morgen_zeit', 'mittag_zeit', 'abend_zeit', 'om_ein', 'dwd_ein',
        'bs_ein', 'station_ein', 'station_art', 'station_host1', 'station_host2', 'mosmix_ein', 'met_ein',
        'bias_ein', 'fassade_ein', 'fassade_az', 'aq_ein', 'pollen_ein', 'pollen_region', 'radar_ein', 'nina_ein',
        'nina_ars', 'ma_ein', 'ma_land', 'ma_emma', 'ow_ein', 'wa_ein', 'vc_ein', 'tm_ein', 'mb_ein',
        'station_ordner', 'station_gleich_min', 'station_sprung_k', 'a_warn', 'a_hitze', 'a_uv', 'a_frost',
        'a_starkregen', 'a_sturm', 'a_lueften', 'a_briefing', 'a_nina', 'pollen_sperre', 'luft_sperre_aqi',
        'p_hasel', 'p_erle', 'p_esche', 'p_birke', 'p_graeser', 'p_roggen', 'p_beifuss', 'p_ambrosia', 's1_wind',
        's1_frost', 's1_schnee', 's1_nebel', 's1_glaette', 'ansage_sprache', 'mqtt_ein', 'mqtt_topic', 'tts',
        'ansage_regeln'
    );
}

/**
 * 0.9.19: Die Kopfschluessel, die eine Sicherung bis 0.9.18 tragen konnte: _warnung (bei einem Wert, den das eigene
 * Zurueckspielen abwiese) und die beiden Merker _haus_frueher (seit 0.9.8) und _pv_frueher (seit 0.9.10), die der
 * Knopf mit der ganzen Konfiguration mitschrieb. Ein anderer Schluessel mit Unterstrich (_hinweis, _fassung, _stand)
 * stammt aus einer Fassung mit Kopf, die immer _plugin schreibt - ohne _plugin ist eine solche Datei keine aeltere
 * eigene.
 */
function wd_sicherung_alt_kopf()
{
    return array('_warnung', '_haus_frueher', '_pv_frueher');
}

/**
 * 0.9.19: Der Uebersetzer fuer das Sicherungsmodul ('uebersetzer'; das Modul ruft ihn nur fuer eine Datei ohne
 * _plugin). Angenommen wird sie nur, wenn jeder Schluessel aus wd_sicherung_alt_merkmal() oben steht und sie keinen
 * anderen Kopfschluessel als die aus wd_sicherung_alt_kopf() traegt; die Meldung sagt dann "aeltere Sicherung erkannt"
 * (Modultext K_ALT_ERKANNT). Sonst null, und das Modul weist die Datei als Ganzes ab. Umgeschrieben wird nichts.
 */
function wd_sicherung_alt_erkennen($d)
{
    if (!is_array($d) || array_key_exists('_plugin', $d)) { return null; }
    foreach (array_keys($d) as $k) {
        if (is_string($k) && $k !== '' && $k[0] === '_' && !in_array($k, wd_sicherung_alt_kopf(), true)) { return null; }
    }
    foreach (wd_sicherung_alt_merkmal() as $k) {
        if (!array_key_exists($k, $d)) { return null; }
    }
    return $d;
}

/**
 * 0.9.19: Die Beschreibung dieser Linie fuer das gemeinsame Sicherungsmodul.
 *  - Inhalt: alle Einstellungen aus wd_vorgaben() mit dem Aktionstoken und den Schluesseln der Dienste (Art 'g' in
 *    wd_felder(), seit 0.9.17; Entscheidung des Hausherrn 12.11.2026). Sprechtoken nie ('sichern'), die Vermerke nie.
 *  - Jeder Wert geht durch wd_wert_pruefen() (dieselbe Pruefung wie das Formular) und wird in dessen Normalform
 *    uebernommen ('wandeln'); der Block tts durch das Sprachmodul (eine Datei mit Sprechtoken wird abgewiesen), der
 *    Block ansage_regeln durch ansage_regeln_wert(). Ohne Aktionstoken wird nichts zurueckgespielt ('quer').
 *  - Pflicht sind die Schluessel aus wd_sicherung_alt_merkmal(); jeder andere darf fehlen und behaelt den geltenden
 *    Wert, ebenso tts.ziele (bis 0.9.18 ansage_sicherung_spaet()).
 */
function wd_sicherung_beschreibung()
{
    $p = wd_paths();
    $tts = array_merge(array_keys(ansage_werk_block()), ansage_geheim());
    $schluessel = array(
        'tts' => sicherung_block($tts, array('ziele' => sicherung_liste(array('art', 'wo', 'laut', 'sprache', 'ein'))
                                                        + array('fehlt' => 'behalten')))
                 + array(
                     'sichern' => 'ansage_sicherung_bereinigen',
                     'pruefen' => function ($w, $k = '', $neu = null, $roh = null) {
                         /* Eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt die Datei eines, wird
                          * sie abgewiesen. Gemessen am Block der DATEI, denn Regel 3 des Moduls setzt ein leeres
                          * Feld vorher auf den geltenden Wert. */
                         if (ansage_sicherung_mangel($roh)) { return sprintf(wd_t('SICH.WERT'), 'tts', wd_t('SICH.TOKEN')); }
                         $g = '';
                         if (!is_array($w) || ansage_wert_pruefen(ansage_sicherung_bereinigen($w), $g, wd_ansage_modi()) === null) {
                             return sprintf(wd_t('SICH.WERT'), 'tts', ansage_kennung_text($g, wd_ansage_k()));
                         }
                         return '';
                     },
                     'wandeln' => function ($w) {
                         $g = '';
                         return ansage_wert_pruefen(ansage_sicherung_bereinigen($w), $g, wd_ansage_modi());
                     }),
        'ansage_regeln' => sicherung_block(array_keys(ansage_regeln_vorgaben()))
                 + array(
                     'pruefen' => function ($w) {
                         $g = '';
                         return ansage_regeln_wert($w, $g) === null ? sprintf(wd_t('SICH.WERT'), 'ansage_regeln', $g) : '';
                     },
                     'wandeln' => function ($w) {
                         $g = '';
                         return ansage_regeln_wert($w, $g);
                     }),
    );
    $schluessel['meldung'] = meld_sicherung_knoten(wd_meld_anlaesse(), wd_meld_k());     // 0.9.23: fehlt -> behalten
    foreach (array_merge(array('aktionstoken'), array_keys(wd_felder())) as $k) {
        $schluessel[$k] = array('wandeln' => function ($w) use ($k) {
            list(, $v) = wd_wert_pruefen($k, $w);
            return $v;
        });
    }
    foreach (array_keys(wd_vorgaben()) as $k) {
        if (in_array($k, wd_sicherung_alt_merkmal(), true) || in_array($k, wd_sicherung_vermerke(), true)) { continue; }
        if (!isset($schluessel[$k])) { $schluessel[$k] = array(); }
        $schluessel[$k]['fehlt'] = 'behalten';
    }
    return array(
        'kennzeichen' => 'LoxBerry-Plugin-Weatherdata',
        'hinweis' => wd_t('SICH.KOPF'),
        /* Die Fassung im Kopf kommt aus der Plugin-Datenbank bzw. plugin.cfg - keine fuenfte Stelle neben den vier,
         * die Werkzeuge/fassung_setzen.py pflegt. */
        'home' => (string) $p['lbhome'], 'ordner' => (string) $p['plugin'],
        'plugin_cfg' => dirname(dirname(__DIR__)) . '/plugin.cfg',
        'vorgaben' => wd_vorgaben(),
        'schluessel' => $schluessel,
        'nie_aus_datei' => wd_sicherung_vermerke(),
        'uebersetzer' => 'wd_sicherung_alt_erkennen',
        'pruefen' => function ($k, $w) {
            if (in_array((string) $k, wd_sicherung_vermerke(), true)) { return ''; }     // geltender Wert, nie aus der Datei
            list($ok, , $g) = wd_wert_pruefen($k, $w);
            /* 0.9.23: kein Praefix auf einem gemeinsamen Bereich unter haus/ (Regeln/07) - wie im Formular. */
            if ($ok && $k === 'mqtt_topic' && is_string($w) && hb_bereich($w) !== '') {
                $ok = false;
                $g = sprintf(wd_t('HAUSBEREICH.SICH_W'), hb_bereich($w));
            }
            return $ok ? '' : sprintf(wd_t('SICH.WERT'), $k, $g);
        },
        'quer' => function ($neu) {
            return (is_array($neu) && isset($neu['aktionstoken']) && trim((string) $neu['aktionstoken']) !== '')
                ? array() : array(array('aktionstoken', wd_t('SICH.TOKEN_LEER')));
        },
        'jetzt' => function () { return wd_config(); },
        't' => 'wd_t',
        'e' => 'wd_e',
    );
}

/**
 * 0.9.19: Die Beschreibung fuer die Meldungen des Knopfes "Einstellungen zurueckspielen": als Klartext ('e'), denn die
 * Oberflaeche maskiert jede Meldung einmal bei der Ausgabe (wd_e()). Regeln/05 "Meldungen als Klartext". Die
 * Haken-Zeile bekommt die unveraenderte Beschreibung und bleibt maskiert.
 */
function wd_sicherung_klartext()
{
    return array('e' => function ($s) { return (string) $s; }) + wd_sicherung_beschreibung();
}

/** 0.9.19: Die Sicherungsdatei als Text - Kopf und Inhalt baut das Modul. '' wenn sie sich nicht bilden laesst. */
function wd_sicherung_bauen()
{
    return sicherung_json(sicherung_bauen(wd_sicherung_beschreibung(), wd_config()));
}

/**
 * 0.9.19: Vor dem Speichern: der Block tts in der Normalform dieser Linie (fehlende Felder mit der Vorgabe, wie
 * wd_config() ihn liest), die geltenden Sprechtoken bleiben (die Sicherung traegt keine).
 */
function wd_sicherung_vorbereiten($neu, $jetzt, $r)
{
    if (isset($neu['tts']) && is_array($neu['tts'])) {
        $jt = (is_array($jetzt) && isset($jetzt['tts']) && is_array($jetzt['tts'])) ? $jetzt['tts'] : array();
        list($tv) = ansage_vervollstaendigen($neu['tts'], 'musicserver');
        $neu['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
    }
    return $neu;
}

/**
 * 0.9.19: Speichern wie im Formular (0600) und die Zweitschrift nachziehen; danach nachlesen, ob der Stand angekommen
 * ist (bis 0.9.18 SICH.NICHT_ANGEKOMMEN) - sonst meldet das Modul den Schreibfehler.
 */
function wd_sicherung_speichern($neu)
{
    if (!wd_config_speichern($neu)) { return false; }
    wd_zweitschrift($neu);
    return wd_inhalt_oder_null(wd_paths()['config']) == $neu;
}

/**
 * 0.9.19: Nach dem Zurueckspielen nachziehen und sagen, was geschah (Regeln/05), dieselben Schritte wie bis 0.9.18:
 * die MQTT-Signatur verwerfen (der naechste Lauf sendet den vollen Satz), das Abo nachziehen, die Hausthemen haus/wetter
 * und haus/pv einschalten oder abraeumen. Einen eigenen Dienst gibt es nicht; der Minutentakt liest die Einstellungen
 * bei seinem naechsten Lauf. Rueckgabe: Saetze (Klartext).
 */
function wd_sicherung_nachziehen($neu, $jetzt, $r)
{
    $h = array(wd_t('SICH.DIENST'));
    $vorher = is_array($jetzt) ? $jetzt : array();
    @unlink(wd_tmpdir() . '/mqtt_sig.txt');
    wd_abo_nachziehen($neu);
    list($hm, , $hf) = wd_haus_folgen($vorher, $neu);
    if ($hf !== null) { wd_haus_frueher_setzen((bool) $hf); }
    if ($hm !== '') { $h[] = $hm; }
    list($pm, , $pf) = wd_pv_folgen($vorher, $neu);
    if ($pf !== null) { wd_pv_frueher_setzen((bool) $pf); }
    if ($pm !== '') { $h[] = $pm; }
    return $h;
}

/**
 * Nimmt die Sicherung wegen eines Werts das eigene Zurueckspielen nicht an? Dann steht es am Knopf. Seit 0.9.19
 * derselbe Satz, den das Modul als _warnung in die Datei schreibt (nur Namen, nie Werte).
 */
function wd_rueckspiel_altwerte()
{
    $d = sicherung_bauen(wd_sicherung_beschreibung(), wd_config());
    return (isset($d['_warnung']) && is_string($d['_warnung']) && $d['_warnung'] !== '') ? array($d['_warnung']) : array();
}

/**
 * 0.9.23 (Entscheidung des Hausherrn 23.11.2026 "Nur gemeinsame Bereiche", Regeln/07): Zeile im Reiter Test -
 * faellt das gespeicherte Themen-Praefix auf einen gemeinsamen Bereich unter haus/? Speichern und Zurueckspielen
 * weisen ein solches Praefix ab; ein schon gespeichertes bleibt in Betrieb und ist hier rot, grau bei ausgeschaltetem MQTT.
 * Liste und Pruefung aus dem gemeinsamen Modul haus_bereiche.php. Rueckgabe array(stand, html).
 */
function wd_pruef_praefix_haus($cfg)
{
    $p = wd_mqtt_praefix($cfg);
    $b = hb_bereich($p);
    if ($b !== '') {
        if (!(!empty($cfg['mqtt_ein']))) {
            return array(-1, sprintf(wd_t('HAUSBEREICH.A_AUS'), strval($p), strval($b)));
        }
        return array(0, sprintf(wd_t('HAUSBEREICH.A_NEIN'), strval($p), strval($b)));
    }
    return array(1, sprintf(wd_t('HAUSBEREICH.A_JA'), strval($p)));
}

/**
 * 0.9.23: einmal je Aenderung eine Protokollzeile, solange das Praefix auf einen gemeinsamen Bereich faellt; der
 * Versand bleibt, wie er ist. Gemerkt in praefix_haus_gemeldet.txt im Datenordner. Rueckgabe der Bereich oder ''.
 */
function wd_praefix_haus_melden($cfg, $merk = null)
{
    $p = wd_mqtt_praefix($cfg);
    if ($merk === null) {
        $merk = wd_paths()['datadir'] . '/praefix_haus_gemeldet.txt';
    }
    $b = hb_melden_faellig($p, $merk);
    if ($b !== '') {
        wd_log(hb_logtext($p, $b));
    }
    return $b;
}

/* ---------------- Statusmeldungen an SignalBot (0.9.23, gemeinsames Modul meldung.php) ----------------
 *
 * Auftrag des Hausherrn 23.11.2026, Entscheidungen 23./24.11.2026: dieselben Anlaesse wie die Ansage, eigener Haken je
 * Anlass, ab Werk aus; Sperre, Bremse und Versand im Modul. Die Erkennung laeuft auch ohne Ausgabeart, wenn eine Meldung
 * eingeschaltet ist (wd_meld_aktiv()), und legt einen Anlass hin, wenn Ansage ODER Meldung ihn will
 * (wd_meld_will()); der Sender trennt die Wege wieder (wd_meld_nur_ansage()).
 */

/** Die meldbaren Anlaesse: Name => Beschriftung (dieselben wie die Ansage). */
function wd_meld_anlaesse()
{
    return array('warn' => wd_t('FELD.a_warn'), 'hitze' => wd_t('FELD.a_hitze'), 'uv' => wd_t('FELD.a_uv'), 'frost' => wd_t('FELD.a_frost'), 'starkregen' => wd_t('FELD.a_starkregen'), 'sturm' => wd_t('FELD.a_sturm'), 'lueften' => wd_t('FELD.a_lueften'), 'briefing' => wd_t('FELD.a_briefing'), 'nina' => wd_t('FELD.a_nina'), 'quelle' => wd_t('QUELLE.L_MELD'));
}

/** Die ab-Werk-Haken der Ansage dieser Linie: so stehen auch die Haken der Meldung, solange der Block nie gespeichert ist. */
function wd_meld_haken_vorgabe()
{
    return array('warn' => 1, 'hitze' => 1, 'uv' => 1, 'frost' => 1, 'starkregen' => 1, 'sturm' => 1, 'lueften' => 1, 'briefing' => 1, 'nina' => 1, 'quelle' => 1);
}

/** Ist der Haken dieses Anlasses fuer die ANSAGE gesetzt? */
function wd_ansage_anlass_sprache($a, $cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    return $a !== 'quelle' && is_array($cfg) && !empty($cfg['a_' . $a]);
}

/** Der wirksame Block 'meldung' fuer den Betrieb (ungueltig gespeichert gilt als aus und wird im Formular genannt). */
function wd_meld_block($cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    list($b) = meld_block(is_array($cfg) && array_key_exists('meldung', $cfg) ? $cfg['meldung'] : null, wd_meld_anlaesse(), wd_meld_haken_vorgabe());
    return $b;
}

/** Die Beanstandungen eines gespeicherten Blocks (leer = gueltig). */
function wd_meld_bean($cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    list(, $f) = meld_block(is_array($cfg) && array_key_exists('meldung', $cfg) ? $cfg['meldung'] : null, wd_meld_anlaesse(), wd_meld_haken_vorgabe());
    return $f;
}

/** Ist irgendein Anlass zur Meldung eingeschaltet? Dann laeuft die Erkennung auch ohne Ausgabeart. */
function wd_meld_aktiv($cfg = null)
{
    return meld_irgendein_an(wd_meld_block($cfg));
}

/** Will die Ansage ODER die Meldung diesen Anlass? */
function wd_meld_will($a, $cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    return wd_ansage_anlass_sprache($a, $cfg) || meld_anlass_an(wd_meld_block($cfg), (string) $a);
}

/** Der Kontext des Moduls: Datenordner (data/plugins/<ordner>, daraus der Absender) und Texte - wie bei der Ansage. */
function wd_meld_k()
{
    return wd_ansage_k();
}

/** Fuer die Ansage nur Eintraege, deren Ansage-Haken gesetzt ist; ein Eintrag ohne bekannten Anlass bleibt wie bisher. */
function wd_meld_nur_ansage(array $l, $cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    $anl = wd_meld_anlaesse();
    $aus = array();
    foreach ($l as $e) {
        if (is_array($e) && array_key_exists(meld_anlass_von($e), $anl) && !wd_ansage_anlass_sprache(meld_anlass_von($e), $cfg)) { continue; }
        $aus[] = $e;
    }
    return $aus;
}

/** Die Eintraege an SignalBot melden. Protokoll: eine Zeile, wenn gesendet oder gescheitert (nie der Text). */
function wd_meld_senden(array $l, $cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    $b = wd_meld_block($cfg);
    if (empty($b['ein']) || !$l) { return null; }
    $a = meld_regel_senden($l, $b, wd_meld_k());
    if ($a['regel'] === '' || in_array($a['regel'], array('BREMSE', 'STAND', 'ORDNER'), true)) {
        wd_log(meld_regel_kurz($a));
    }
    return $a;
}

/** Der Knopf "Testmeldung senden": array(angenommen, Satz). Geht an Haken, Sperre und Bremse vorbei. */
function wd_meld_test()
{
    $r = meld_testmeldung(wd_meld_k());
    wd_log('Testmeldung an SignalBot: ' . ($r[0] ? 'angenommen' : 'nicht angenommen'));
    return $r;
}

/** Der Block im Einstellungsformular (unter den Ansage-Regeln, im selben Formular), mit Lage und Knopf. */
function wd_meld_form_html($cfg = null)
{
    if ($cfg === null) { $cfg = wd_config(); }
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $k = wd_meld_k();
    $o = array();
    /* 0.9.23: unsichtbarer Doppel des Speicherknopfs VOR dem Knopf Testmeldung - die Eingabetaste in einem Textfeld
     * loest den ersten Absendeknopf des Formulars aus; sie soll speichern wie bisher, nicht die Testmeldung senden. */
    $o[] = '<button data-role="none" type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden"></button>';
    $o[] = '<h3>' . $e(meld_t('TITEL', $k)) . '</h3>';
    $o[] = meld_formular_html(wd_meld_block($cfg), wd_meld_anlaesse(), array(), $k);
    list(, $lage) = meld_pruefzeile(wd_meld_block($cfg), wd_meld_bean($cfg), $k);
    $o[] = '<div class="sm-hilfe">' . $e(meld_t('F_TEST', $k) . ' ' . $lage) . '</div>';
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="meld_test" value="1">'
         . $e(meld_t('K_TEST', $k)) . '</button></div>';
    return implode("\n", $o) . "\n";
}

/** Der Anlass eines Eintrags aus dem Ansageplan (Schluessel warn_<klasse>_<stufe>, nina_<id>_<stufe>, hitze_*, uv_*, ...). */
function wd_meld_anlass_von($schluessel)
{
    $s = (string) $schluessel;
    foreach (array('warn', 'nina', 'hitze', 'uv') as $a) {
        if (strpos($s, $a . '_') === 0) { return $a; }
    }
    return $s;
}

/**
 * Ein Eintrag der Quellen-Flanke. Anlassname nach der Regel "Paare einheitlich" (Entscheidung des Hausherrn 24.11.2026):
 * quelle_weg / quelle_wieder - so steht er in der Nachricht (Feld anlass). Der Haken bleibt der eine Haken 'quelle'.
 */
function wd_meld_quelle_eintrag($dienst, $ok, $sp)
{
    $d = (string) $dienst;
    return array('schluessel' => 'quelle|' . $d . '|' . ($ok ? 1 : 0), 'anlass' => $ok ? 'quelle_wieder' : 'quelle_weg',
                 'text' => sprintf(wd_t($ok ? 'QUELLE.MELD_WIEDER' : 'QUELLE.MELD_AUS', $sp), wd_t('QUELLE.N_' . $d, $sp)));
}

/** Zuordnung fuer den Versand: der eine Haken 'quelle' gilt fuer beide Anlassnamen quelle_weg / quelle_wieder. */
function wd_meld_quelle_zuordnung(array $b)
{
    $an = (isset($b['haken']) && is_array($b['haken']) && !empty($b['haken']['quelle'])) ? 1 : 0;
    $b['haken']['quelle_weg'] = $an;
    $b['haken']['quelle_wieder'] = $an;
    return $b;
}

/**
 * Aus dem Minutenlauf (0.9.23, Entscheidung des Hausherrn 24.11.2026, F2): die Anlaesse der Ansage mit den Haken der
 * Meldung (wd_ansage_plan() mit a_* := Haken), eigener Merker meld_stand.json; dazu die Flanke quelle/<dienst>/ok der
 * eingeschalteten Dienste (meld_quellen.json, beim ersten Lauf nur gemerkt). Ab Werk aus: ohne Block nichts, und der
 * Quellenmerker wird verworfen (beim Einschalten keine alte Flanke). Rueckgabe aus, nichts, die Regel des Moduls oder die
 * Lage der Quittung (angenommen, ...).
 */
function wd_meld_lauf(array $st, array $c, $jetzt)
{
    $qf = wd_datadir() . '/meld_quellen.json';
    if (!wd_meld_aktiv($c)) { if (is_file($qf)) { @unlink($qf); } return 'aus'; }
    $b = wd_meld_block($c);
    $sp = (string) $c['ansage_sprache'];
    $cm = $c;
    foreach (array_keys(wd_meld_anlaesse()) as $a) {
        if ($a !== 'quelle') { $cm['a_' . $a] = meld_anlass_an($b, $a) ? 1 : 0; }
    }
    $f = wd_datadir() . '/meld_stand.json';
    $stand = wd_json_lesen($f);
    if (!is_array($stand)) { $stand = array('lagen' => array(), 'tag' => array()); }
    list($tag, $plan) = wd_tag_heute($st, $cm, $stand, wd_ansage_regeln($c), $jetzt, wd_regeln_heute_leser(wd_ansage_k($c), $jetzt));
    $l = array();
    foreach ($plan as $e) {
        $l[] = array('schluessel' => (string) $e['schluessel'], 'anlass' => wd_meld_anlass_von($e['schluessel']),
                     'text' => (string) $e['text'], 'dringend' => !empty($e['dringend']));
    }
    $vor = wd_json_lesen($qf);
    $jq = array();
    foreach ((isset($st['quellen']) && is_array($st['quellen'])) ? $st['quellen'] : array() as $d => $q) {
        if (is_array($q) && !empty($q['ein'])) { $jq[(string) $d] = !empty($q['ok']) ? 1 : 0; }
    }
    if (is_array($vor) && meld_anlass_an($b, 'quelle')) {
        foreach ($jq as $d => $ok) {
            if (!array_key_exists($d, $vor) || (int) $vor[$d] === $ok) { continue; }
            $l[] = wd_meld_quelle_eintrag($d, $ok, $sp);
        }
    }
    wd_json_schreiben($qf, $jq);
    if (!$l) {
        wd_json_schreiben($f, wd_ansage_gesprochen($stand, array(), array(), $tag['heute'], $jetzt));
        return 'nichts';
    }
    // wie wd_meld_senden(), aber mit der Zuordnung des Hakens 'quelle' auf quelle_weg / quelle_wieder
    $a = meld_regel_senden($l, wd_meld_quelle_zuordnung($b), wd_meld_k());
    if ($a['regel'] === '' || in_array($a['regel'], array('BREMSE', 'STAND', 'ORDNER'), true)) {
        wd_log(meld_regel_kurz($a));
    }
    wd_json_schreiben($f, wd_ansage_gesprochen($stand, $plan, is_array($a) ? (array) $a['einzeln'] : array(), $tag['heute'], $jetzt));
    if (!is_array($a)) { return 'aus'; }
    if ($a['regel'] !== '') { return $a['regel']; }
    return (is_array($a['q']) && isset($a['q']['lage'])) ? (string) $a['q']['lage'] : 'gesendet';
}
