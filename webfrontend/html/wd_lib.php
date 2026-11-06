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
require_once __DIR__ . '/wd_rechnen.php';
require_once __DIR__ . '/wd_quellen.php';
require_once __DIR__ . '/wd_mehr.php';
require_once __DIR__ . '/wd_emu.php';
require_once __DIR__ . '/wd_seite.php';
require_once __DIR__ . '/wd_eigen.php';
require_once __DIR__ . '/wd_haus.php';
require_once __DIR__ . '/wd_pv.php';

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
        'pv2_name' => array('pv_save', 'pvname', '', 0, 0), 'pv2_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv2_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv2_az' => array('pv_save', 'z', 0, -180, 180),
        'pv3_name' => array('pv_save', 'pvname', '', 0, 0), 'pv3_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv3_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv3_az' => array('pv_save', 'z', 0, -180, 180),
        'pv4_name' => array('pv_save', 'pvname', '', 0, 0), 'pv4_kwp' => array('pv_save', 'z', 0, 0, 100), 'pv4_neigung' => array('pv_save', 'z', 30, 0, 90), 'pv4_az' => array('pv_save', 'z', 0, -180, 180),
        'pv_faktor' => array('pv_save', 'z', 0.85, 0.5, 1),
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
    foreach (wd_mqtt_tabelle() as $t => $f) {
        $a[] = array('thema' => wd_mqtt_thema($pf . '/' . $t), 'name' => wd_gateway_name($pf, $t), 'nc' => $f[1], 'retain' => $f[2], 'pflicht' => $f[3], 'art' => $f[0],
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

/** Was in die Sicherungsdatei kommt: Dienstschluessel nie, Sprechtoken nie. Das Aktionstoken bleibt, es gehoert zur Einrichtung (Hinweis am Knopf). */
function wd_sicherung_stand()
{
    $v = wd_config();
    unset($v['ansage_token_herkunft']);
    foreach (wd_felder() as $k => $f) { if ($f[1] === 'g') { unset($v[$k]); } }
    if (isset($v['tts']) && is_array($v['tts'])) { $v['tts'] = ansage_sicherung_bereinigen($v['tts']); }
    return $v;
}

/** Liest eine Sicherungsdatei. Rueckgabe: array(neuer Stand oder null, Mangel, Anzahl, Hinweise). Eine halb gueltige Datei aendert nichts. */
function wd_sicherung_lesen($roh, $geltend = null)
{
    $mangel = array(); $hin = array();
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || ($d !== array() && array_keys($d) === range(0, count($d) - 1))) { return array(null, array(wd_t('SICH.KEIN_JSON')), 0, array()); }
    $neu = wd_vorgaben();
    // Fehlt ein Dienstschluessel in der Datei (ab 0.9.6 immer), bleibt der geltende. Eine aeltere Datei mit Schluessel wird weiter angenommen.
    foreach (wd_felder() as $k => $f) { if ($f[1] === 'g' && is_array($geltend) && isset($geltend[$k]) && is_string($geltend[$k])) { $neu[$k] = $geltend[$k]; } }
    $n = 0;
    foreach ($d as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }
        if ($k === 'ansage_token_herkunft') { continue; }
        if ($k === 'ansage_regeln') {
            $g = ''; $r = ansage_regeln_wert($w, $g);
            if ($r === null) { $mangel[] = sprintf(wd_t('SICH.WERT'), $k, $g); } else { $neu[$k] = $r; }
            continue;
        }
        if ($k === 'tts') {
            if (!is_array($w)) { $mangel[] = sprintf(wd_t('SICH.WERT'), $k, 'Block'); continue; }
            $tm = ansage_sicherung_mangel($w);
            foreach ($tm as $g) { $mangel[] = sprintf(wd_t('SICH.WERT'), $g, wd_t('SICH.TOKEN')); }
            if ($tm) { continue; }
            $g = '';
            $w = ansage_wert_pruefen(ansage_sicherung_bereinigen($w), $g, wd_ansage_modi());
            if ($w === null) { $mangel[] = sprintf(wd_t('SICH.WERT'), 'tts', ansage_kennung_text($g, wd_ansage_k())); continue; }
            list($b) = ansage_vervollstaendigen($w, 'musicserver');
            $neu['tts'] = $b;
            if (function_exists('ansage_sicherung_spaet')) { list($neu['tts']) = ansage_sicherung_spaet($neu['tts'], (is_array($geltend) && isset($geltend['tts'])) ? $geltend['tts'] : null); }
            $neu['tts'] = ansage_sicherung_tokens_behalten($neu['tts'], (is_array($geltend) && isset($geltend['tts'])) ? $geltend['tts'] : null);
            $n++;
            continue;
        }
        if (!array_key_exists($k, $neu)) { $mangel[] = sprintf(wd_t('SICH.FREMD'), $k); continue; }
        list($ok, $wert, $gr) = wd_wert_pruefen($k, $w);
        if (!$ok) { $mangel[] = sprintf(wd_t('SICH.WERT'), $k, $gr); continue; }
        $neu[$k] = $wert;
        $n++;
    }
    if ($n === 0) { $mangel[] = wd_t('SICH.LEER'); }
    $fehlt = array();
    // Felder, die eine Sicherung von vor 0.9.4 nicht kennen kann, gelten mit ihrer Vorgabe; alles andere muss da sein.
    $wf_felder = wd_felder();
    foreach (array_keys(wd_vorgaben()) as $k) { if (!array_key_exists($k, $d) && !in_array($k, array('wttr_ein', 'emu_ein', 'emu_nur_ms', 'wu_ein', 'wu_station', 'lox_ein', 'lox_gueltig_min', 'haus_wetter_ein'), true) && !preg_match('/^(haus_pv_ein|pv[1-4]?_[a-z_]+)\z/', $k) && !(isset($wf_felder[$k]) && $wf_felder[$k][1] === 'g')) { $fehlt[] = $k; } }
    if ($fehlt) { $mangel[] = sprintf(wd_t('SICH.FEHLEND'), count($fehlt), implode(', ', $fehlt)); }
    if (trim((string) $neu['aktionstoken']) === '') { $mangel[] = wd_t('SICH.TOKEN_LEER'); }
    return array($mangel ? null : $neu, $mangel, $n, $hin);
}

/** Nimmt die Sicherung wegen eines Altwerts das eigene Zurueckspielen nicht an? Dann steht es am Knopf. */
function wd_rueckspiel_altwerte()
{
    $v = wd_sicherung_stand();
    if (trim((string) $v['aktionstoken']) === '') { return array(); }
    $e = wd_sicherung_lesen((string) json_encode($v), wd_config());
    return $e[0] === null ? $e[1] : array();
}
