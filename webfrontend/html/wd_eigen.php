<?php
/**
 * Weatherdata 0.9.7 - eigene Messwerte: von Loxone (wd.php?lox_...=) und von der eigenen Station ueber Wunderground.
 *
 * Rangfolge fuer die Werte "jetzt": Station (Weiche, direkt, Empfang) > Wunderground (eigene Station ueber die Cloud) > Loxone > Prognose.
 * Begruendung: Station und Wunderground liefern einen zusammengehoerigen Satz einer Wetterstation, Loxone einzelne Fuehler; ist ein Wert
 * bei der hoeheren Stufe leer, greift je Wert die naechste. Die Vorhersage wird von keiner dieser Quellen beruehrt.
 * Beides ist ab Werk aus. Der Wunderground-Schluessel geht in keine Sicherung, kein Protokoll, keine Ausgabe.
 */

/* ------------------------------------ Loxone: Werte vom Miniserver ------------------------------------ */

/**
 * Werte, die ein Miniserver wirklich haben kann. Parameter (ohne "lox_") => array(Ziel in "jetzt", Min, Max, Einheit, Faktor, Gleichtakt-Toleranz).
 * Eigene Namen, die mit keinem MQTT-Thema von Weatherdata uebereinstimmen: Ein Wert, den Weatherdata ueber MQTT an Loxone gibt, traegt dort
 * den Namen <praefix>_jetzt_..., nie lox_... (Rueckkopplungssperre, wie Weather4Lox mit eigenem Praefix).
 * hell: Helligkeit in Lux, wird wie bei der Station (klux * 7,8926) in W/m2 umgerechnet und zaehlt nur, wenn keine Strahlung kommt.
 */
function wd_lox_werte()
{
    return array(
        'temp' => array('temp_c', -50, 60, '°C', 1.0, 0.051),
        'rf' => array('rf_pct', 1, 100, '%', 1.0, 0.51),
        'wind' => array('wind_kmh', 0, 250, 'km/h', 1.0, 0.051),
        'boe' => array('boe_kmh', 0, 300, 'km/h', 1.0, 0.051),
        'regen' => array('regen_mmh', 0, 300, 'mm/h', 1.0, 0.051),
        'regnet' => array('regnet', 0, 1, '0/1', 1.0, 0.01),
        'strahlung' => array('strahlung_wm2', 0, 1500, 'W/m²', 1.0, 0.51),
        'hell' => array('strahlung_wm2', 0, 200000, 'lx', 0.0078926, 0.0),
    );
}

function wd_lox_datei($k) { return wd_datadir() . '/lox_' . preg_replace('/[^a-z]/', '', (string) $k) . '.json'; }

/**
 * Ein Wert von Loxone. Rueckgabe array(HTTP-Code, Antwortzeile). Prueft Bereich, Sprung und Abstand zur Prognose (Temperatur, wie die Station)
 * und den Gleichtakt: Kommt genau der Wert zurueck, den Weatherdata gerade aus einer anderen Quelle veroeffentlicht, wird er nicht angenommen.
 * Er aendert dann ohnehin nichts; drei solche Eingaenge hintereinander mit mindestens zwei verschiedenen Werten melden "Rueckkopplung".
 */
function wd_lox_annehmen(array $c, $k, $roh, $jetzt, $zustand = null)
{
    $tab = wd_lox_werte();
    if (!isset($tab[$k])) { return array(400, 'ERR=UNBEKANNT'); }
    $f = $tab[$k];
    $s = is_string($roh) ? str_replace(',', '.', trim($roh)) : '';
    if (!is_numeric($s) || !is_finite((float) $s) || (float) $s < $f[1] || (float) $s > $f[2]) { return array(400, 'ERR=WERT'); }
    $w = (float) $s;
    if ($k === 'regnet' && $w !== 0.0 && $w !== 1.0) { return array(400, 'ERR=WERT'); }
    $wert = round($w * $f[4], 1);
    $alt = wd_json_lesen(wd_lox_datei($k));
    if (!is_array($alt)) { $alt = array('w' => null, 'ts' => 0, 'gleich' => 0, 'gleich_w' => array(), 'echo' => 0); }
    $grund = '';
    if ($k === 'temp') {
        if ($alt['w'] !== null && $jetzt - (int) $alt['ts'] <= 900 && abs($wert - (float) $alt['w']) > (float) $c['station_sprung_k']) { $grund = 'SPRUNG'; }
        $pj = wd_json_lesen(wd_datadir() . '/prog.json');
        if (is_array($pj) && isset($pj['jetzt']['temp_c']) && is_numeric($pj['jetzt']['temp_c']) && abs($wert - (float) $pj['jetzt']['temp_c']) > 20.0) { $grund = 'SPRUNG'; }
    }
    // Gleichtakt: Vergleich mit dem zuletzt veroeffentlichten Wert, solange dieser NICHT von Loxone stammt.
    if ($zustand === null) { $zustand = wd_json_lesen(wd_tmpdir(false) . '/zustand.json'); }
    $ziel = $f[0];
    $pub = is_array($zustand) && isset($zustand['jetzt'][$ziel]) && is_numeric($zustand['jetzt'][$ziel]) ? (float) $zustand['jetzt'][$ziel] : null;
    $her = is_array($zustand) && isset($zustand['herkunft'][$ziel]) ? (string) $zustand['herkunft'][$ziel] : '';
    if ($grund === '' && $f[5] > 0 && $pub !== null && $her !== 'loxone' && $her !== 'keine' && $her !== '' && abs($wert - $pub) <= $f[5]) {
        $alt['gleich'] = (int) $alt['gleich'] + 1;
        $gw = array_slice(array_values(array_unique(array_merge((array) $alt['gleich_w'], array((string) $wert)))), -5);
        $alt['gleich_w'] = $gw;
        if ($alt['gleich'] >= 3 && count($gw) >= 2 && $k !== 'regnet') {
            if (empty($alt['echo'])) { wd_log('Loxone: Der Wert ' . $k . ' kommt im Gleichtakt mit dem eigenen Wert zurueck (Rueckkopplung?). Er wird nicht angenommen.'); }
            $alt['echo'] = 1;
        }
        wd_json_schreiben(wd_lox_datei($k), $alt);
        return array(409, 'ERR=ECHO');
    }
    if ($grund !== '') {
        wd_log_einmal('lox' . $k, 'Loxone: Wert ' . $k . ' nicht plausibel (' . $grund . ').');
        return array(422, 'ERR=' . $grund);
    }
    $neu = array('w' => $wert, 'ts' => $jetzt, 'gleich' => 0, 'gleich_w' => array(), 'echo' => 0);
    if (!wd_json_schreiben(wd_lox_datei($k), $neu)) { return array(500, 'ERR=SCHREIBEN'); }
    $m = wd_quelle_lesen('loxone'); $m['ok_ts'] = $jetzt; $m['versuch_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    wd_quelle_merken('loxone', $m);
    return array(200, 'OK=1');
}

/** Gueltige Loxone-Werte, in der Form von "jetzt". Leeres Feld, wenn aus oder nichts gueltig. Jeder Wert hat seine eigene Uhr. */
function wd_lox_aktuell(array $c, $jetzt)
{
    if (empty($c['lox_ein'])) { return array(); }
    $grenze = max(10, min(180, (int) $c['lox_gueltig_min'])) * 60;
    $o = array();
    foreach (wd_lox_werte() as $k => $f) {
        $d = wd_json_lesen(wd_lox_datei($k));
        if (!is_array($d) || !isset($d['w']) || !is_numeric($d['w']) || $jetzt - (int) $d['ts'] > $grenze || (int) $d['ts'] > $jetzt + 60) { continue; }
        if ($k === 'hell' && isset($o['strahlung_wm2'])) { continue; }
        $o[$f[0]] = (float) $d['w'];
    }
    if (!isset($o['regnet']) && isset($o['regen_mmh'])) { $o['regnet'] = $o['regen_mmh'] > 0 ? 1 : 0; }
    if (isset($o['regnet'])) { $o['regnet'] = (int) $o['regnet']; }
    return $o;
}

/** Fuer den Reiter: je Wert array(Stand: aus|leer|gueltig|alt|echo, Wert, Alter in s). */
function wd_lox_lage(array $c, $jetzt)
{
    $grenze = max(10, min(180, (int) $c['lox_gueltig_min'])) * 60; $o = array();
    foreach (array_keys(wd_lox_werte()) as $k) {
        $d = wd_json_lesen(wd_lox_datei($k));
        if (empty($c['lox_ein'])) { $o[$k] = array('aus', null, null); continue; }
        if (!is_array($d)) { $o[$k] = array('leer', null, null); continue; }
        if (!empty($d['echo'])) { $o[$k] = array('echo', null, null); continue; }
        if (!isset($d['w']) || !is_numeric($d['w'])) { $o[$k] = array('leer', null, null); continue; }
        $a = $jetzt - (int) $d['ts'];
        $o[$k] = array($a <= $grenze ? 'gueltig' : 'alt', (float) $d['w'], $a);
    }
    return $o;
}

/**
 * Vorlage fuer einen Virtuellen Ausgang (Importdatei VQ_, Bauform wie bb_vorlage der Befehlsbruecke, Regeln/07): je Wert ein analoger
 * Befehl mit fertiger Adresse. $host = Name oder Adresse des LoxBerry wie im Browser (mit Port, wenn noetig), $token das Aktionstoken.
 */
function wd_lox_vorlage($host, $plugin, $token)
{
    $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $z = array('<?xml version="1.0" encoding="utf-8"?>');
    $z[] = '<VirtualOut HintText="" Title="' . $x(wd_t('LOXW.V_TITEL')) . '" Comment="' . $x(wd_t('LOXW.V_KOMMENTAR')) . '" Address="' . $x('http://' . $host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">';
    $z[] = "\t" . '<Info templateType="3" minVersion="17010727"/>';
    $befehle = array();
    foreach (wd_lox_werte() as $k => $f) { $befehle[] = array(wd_t('LOXW.T_' . $k), wd_t('LOXW.B_' . $k), 'lox_' . $k); }
    $befehle[] = array(wd_t('LOXW.T_innen'), wd_t('LOXW.B_innen'), 'innen');
    foreach ($befehle as $b) {
        $cmd = '/plugins/' . $plugin . '/wd.php?' . $b[2] . '=<v.1>&token=' . $token;
        $z[] = "\t" . '<VirtualOutCmd Title="' . $x($b[0]) . '" Comment="' . $x($b[1]) . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . $x($cmd) . '" CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="true" Repeat="0" RepeatRate="0" SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" HintText=""/>';
    }
    $z[] = '</VirtualOut>';
    return array('VQ_weatherdata_messwerte.xml', implode("\r\n", $z) . "\r\n");
}

/* ------------------------------------ Wunderground: eigene Station ueber die PWS-Schnittstelle ------------------------------------ */

/**
 * Adresse nach "PWS Observations - Current Conditions - v2" (The Weather Company, gelesen am 05.11.2026): stationId, format=json, units=m
 * (metrisch: Grad Celsius, km/h, mb, mm), numericPrecision=decimal, apiKey. Der Schluessel steht nur hier und geht nie ins Protokoll.
 */
function wd_wu_url(array $c)
{
    return 'https://api.weather.com/v2/pws/observations/current?stationId=' . rawurlencode(strtoupper(trim((string) $c['wu_station']))) . '&format=json&units=m&numericPrecision=decimal&apiKey=' . rawurlencode((string) $c['wu_key']);
}

/**
 * Antwort in die Form von "jetzt". Null, wenn sie nicht passt: andere Station, kein metric-Block, Feuchte ausserhalb 1..100, Temperatur fehlt,
 * qcStatus 0 (laut Doku "possibly incorrect"). Die Doku nennt alle Felder ausser stationID und qcStatus als moeglich leer.
 */
function wd_wu_lesen($roh, $station)
{
    $d = json_decode((string) $roh, true);
    $o = is_array($d) && isset($d['observations'][0]) && is_array($d['observations'][0]) ? $d['observations'][0] : null;
    if ($o === null || !isset($o['metric']) || !is_array($o['metric'])) { return null; }
    if (strcasecmp((string) ($o['stationID'] ?? ''), trim((string) $station)) !== 0) { return null; }
    if (isset($o['qcStatus']) && (int) $o['qcStatus'] === 0) { return null; }
    $m = $o['metric'];
    $n = function ($a, $k) { return (isset($a[$k]) && is_numeric($a[$k])) ? (float) $a[$k] : null; };
    $t = $n($m, 'temp'); $rf = $n($o, 'humidity');
    if ($t === null || $t < -60 || $t > 70 || $rf === null || $rf <= 0 || $rf > 100) { return null; }
    $rd = function ($x, $s = 1) { return $x === null ? null : round($x, $s); };
    $pr = $n($m, 'precipRate');
    return array('temp_c' => $rd($t), 'rf_pct' => $rd($rf, 0), 'taupunkt_c' => $rd($n($m, 'dewpt')), 'druck_hpa' => $rd($n($m, 'pressure')), 'wind_kmh' => $rd($n($m, 'windSpeed')),
        'boe_kmh' => $rd($n($m, 'windGust')), 'windrichtung_grad' => $rd($n($o, 'winddir'), 0), 'regen_mmh' => $rd($pr), 'regnet' => $pr === null ? null : ($pr > 0 ? 1 : 0),
        'strahlung_wm2' => $rd($n($o, 'solarRadiation')), 'uv' => $rd($n($o, 'uv')), 'ts' => (int) ($n($o, 'epoch') ?? 0));
}

/** Abstand zwischen zwei Abrufen: nie unter 10 Minuten, auch nicht auf Knopfdruck; nach Fehlschlaegen laenger, hoechstens 60 Minuten. */
function wd_wu_abstand($fehl) { return (int) min(3600, 600 * pow(2, max(0, min(3, (int) $fehl - 1)))); }

/** Holt die aktuelle Beobachtung und prueft sie wie die Station (Sprung, Abstand zur Prognose). Merker unter quelle_wunderground.json. */
function wd_wu_holen(array $c, $jetzt)
{
    if (empty($c['wu_ein']) || trim((string) $c['wu_station']) === '' || trim((string) $c['wu_key']) === '') { return; }
    $m = wd_quelle_lesen('wunderground');
    if ($jetzt - (int) $m['versuch_ts'] < wd_wu_abstand($m['fehl']) - 5) { return; }
    $m['versuch_ts'] = $jetzt;
    list($code, $b) = wd_http(wd_wu_url($c), 20);
    $w = $code === 200 ? wd_wu_lesen($b, $c['wu_station']) : null;
    $grund = $w === null ? ($code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code) : '';
    if ($w !== null) {
        $alt = wd_json_lesen(wd_datadir() . '/wu.json');
        if ($w['ts'] > 0 && $jetzt - $w['ts'] > 1800) { $grund = wd_t('QUELLE.WU_ALT'); }
        elseif (is_array($alt) && isset($alt['temp_c'], $alt['ts']) && $jetzt - (int) $alt['ts'] <= 1800 && abs((float) $w['temp_c'] - (float) $alt['temp_c']) > (float) $c['station_sprung_k']) { $grund = wd_t('QUELLE.SPRUNG'); }
        $pj = wd_json_lesen(wd_datadir() . '/prog.json');
        if ($grund === '' && is_array($pj) && isset($pj['jetzt']['temp_c']) && is_numeric($pj['jetzt']['temp_c']) && abs((float) $w['temp_c'] - (float) $pj['jetzt']['temp_c']) > 20.0) { $grund = wd_t('QUELLE.SPRUNG'); }
    }
    if ($grund === '') {
        if ($w['ts'] <= 0) { $w['ts'] = $jetzt; }
        wd_json_schreiben(wd_datadir() . '/wu.json', $w);
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1; $m['meldung'] = $grund;
        wd_log_einmal('wu', 'Wunderground: ' . $grund);
    }
    wd_quelle_merken('wunderground', $m);
}

/** Gueltige Wunderground-Werte (Beobachtung hoechstens 30 Minuten alt) oder null. */
function wd_wu_aktuell(array $c, $jetzt)
{
    if (empty($c['wu_ein'])) { return null; }
    $w = wd_json_lesen(wd_datadir() . '/wu.json');
    if (!is_array($w) || !isset($w['ts']) || $jetzt - (int) $w['ts'] > 1800 || !isset($w['temp_c'])) { return null; }
    $m = wd_quelle_lesen('wunderground');
    if ((int) $m['fehl'] > 0 && $m['meldung'] === wd_t('QUELLE.SPRUNG')) { return null; }
    unset($w['ts']);
    return $w;
}

/* ------------------------------------ Reiter Werte: eigene Messung gegen Wetterdienst (nur Anzeige) ------------------------------------ */

/** Herkunft in Worten, wie die Namen im Reiter Quellen. Unbekanntes bleibt wie es ist, leer und "keine" werden zum Strich. */
function wd_herkunft_name($h)
{
    $h = (string) $h;
    if ($h === '' || $h === 'keine' || $h === '-') { return '–'; }
    if (preg_match('/^mix:(\d+)\z/', $h, $m)) { return sprintf(wd_t('WERTE.H_MIX'), (int) $m[1]); }
    if ($h === 'rechnung') { return wd_t('WERTE.H_RECHNUNG'); }
    $n = wd_t('QUELLE.N_' . str_replace('-', '_', $h));
    return strpos($n, 'QUELLE.') === 0 ? $h : $n;
}

/** Herkunft der gefuehlten Temperatur: Rechnung aus der Herkunft der Lufttemperatur, mit der Art in Worten (nicht "luft"). */
function wd_gefuehlt_herkunft(array $st)
{
    $art = (string) ($st['gefuehlt_art'] ?? '');
    $a = wd_t('WERTE.ART_' . $art);
    if (strpos($a, 'WERTE.') === 0) { return '–'; }
    return sprintf(wd_t('WERTE.GEFUEHLT_HER'), wd_herkunft_name($st['herkunft']['temp_c'] ?? ''), $a);
}

/**
 * Schwellen fuer "deutlich anders" je Groesse. null = nicht markieren: Der Druck der Station ist meist auf Meereshoehe umgerechnet,
 * der von Open-Meteo gilt in Ortshoehe (surface_pressure); Windrichtung und "regnet" sind keine Betraege.
 */
function wd_vergleich_schwellen()
{
    return array('temp_c' => 3.0, 'rf_pct' => 15.0, 'taupunkt_c' => 3.0, 'druck_hpa' => null, 'wind_kmh' => 15.0, 'boe_kmh' => 25.0, 'windrichtung_grad' => null,
        'regen_mmh' => 2.0, 'regnet' => null, 'strahlung_wm2' => 300.0, 'uv' => 2.0);
}

/** Je Wert array(eigen, dienst, abweichung, deutlich): eigen nur aus Station, Wunderground oder Loxone; fehlt eins, null und keine Markierung. */
function wd_vergleich(array $st)
{
    $sw = wd_vergleich_schwellen(); $o = array();
    $jw = isset($st['jetzt']) && is_array($st['jetzt']) ? $st['jetzt'] : array();
    $dn = isset($st['dienst']) && is_array($st['dienst']) ? $st['dienst'] : array();
    foreach ($jw as $k => $v) {
        $her = (string) ($st['herkunft'][$k] ?? '');
        $e = (in_array($her, array('station', 'wunderground', 'loxone'), true) && is_numeric($v)) ? (float) $v : null;
        $d = (isset($dn[$k]) && is_numeric($dn[$k])) ? (float) $dn[$k] : null;
        $ab = ($e === null || $d === null) ? null : $e - $d;
        if ($ab !== null && $k === 'windrichtung_grad') { $ab = fmod($ab + 540.0, 360.0) - 180.0; }
        $deut = ($ab === null || !array_key_exists($k, $sw) || $sw[$k] === null) ? null : (abs($ab) >= $sw[$k]);
        $o[$k] = array($e, $d, $ab === null ? null : round($ab, 1), $deut);
    }
    return $o;
}
