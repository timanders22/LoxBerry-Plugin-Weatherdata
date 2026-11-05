<?php
/**
 * Weatherdata - Stufen 2 bis 5: weitere Prognosequellen, Mischung und Fehlerbilanz, Fassade, Pollen, Luft, NINA, Radar,
 * Empfang der Station, Schluesseldienste, Meteoalarm.
 *
 * Ab Werk AUS: alles mit Schluessel (OpenWeather, WeatherAPI, Visual Crossing, Tomorrow.io, Meteoblue), NINA (Nutzungsbedingungen
 * nicht geklaert) und Meteoalarm (Bedingungen nicht gelesen). Die Adapter der Schluesseldienste sind nach der Dienstdoku gebaut und
 * mangels Schluessel nie gegen eine echte Antwort gefahren; das steht auch im Reiter Quellen und im README.
 */

/** Prognosequellen: Name => array(Schalter, Hat einen Schluessel). open_meteo ist die fuehrende Quelle fuer Strahlung und Wettercode. */
function wd_prog_quellen()
{
    return array('open_meteo' => array('om_ein', 'om_apikey'), 'mosmix' => array('mosmix_ein', ''), 'met' => array('met_ein', ''), 'wttr' => array('wttr_ein', ''),
        'openweather' => array('ow_ein', 'ow_key'), 'weatherapi' => array('wa_ein', 'wa_key'), 'visualcrossing' => array('vc_ein', 'vc_key'), 'tomorrow' => array('tm_ein', 'tm_key'), 'meteoblue' => array('mb_ein', 'mb_key'));
}

function wd_quellen_liste()
{
    $q = array('open_meteo' => 'om_ein', 'dwd' => 'dwd_ein', 'brightsky' => 'bs_ein', 'station' => 'station_ein', 'mosmix' => 'mosmix_ein', 'met' => 'met_ein', 'wttr' => 'wttr_ein', 'luft' => 'aq_ein', 'pollen' => 'pollen_ein', 'nina' => 'nina_ein', 'radar' => 'radar_ein', 'meteoalarm' => 'ma_ein');
    foreach (wd_prog_quellen() as $n => $f) { if ($f[1] !== '' && $n !== 'open_meteo') { $q[$n] = $f[0]; } }
    return $q;
}

function wd_ts_iso($s) { $t = strtotime((string) $s); return $t === false ? 0 : $t; }
function wd_num($w) { return is_numeric($w) ? (float) $w : null; }

/** Bright Sky MOSMIX: /weather liefert stuendlich. Solar in kWh/m2 je Stunde, daher mal 1000. */
function wd_mosmix_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['weather']) || !is_array($d['weather']) || count($d['weather']) < 12) { return null; }
    $s = array();
    foreach ($d['weather'] as $w) {
        if (!is_array($w)) { continue; }
        $sol = wd_num($w['solar'] ?? null);
        $s[] = array('ts' => wd_ts_iso($w['timestamp'] ?? ''), 't' => wd_num($w['temperature'] ?? null), 'rf' => wd_num($w['relative_humidity'] ?? null), 'regen' => wd_num($w['precipitation'] ?? null), 'regen_w' => wd_num($w['precipitation_probability'] ?? null),
            'wind' => wd_num($w['wind_speed'] ?? null), 'boe' => wd_num($w['wind_gust_speed'] ?? null), 'solar' => $sol === null ? null : $sol * 1000);
    }
    return array('stunden' => $s);
}

/** MET Norway: complete; Wind in m/s; Regen aus next_1_hours. Nur Stunden mit Stundenwerten (die ersten rund 60 h), danach 6-Stunden-Schritte ohne Regen je Stunde. */
function wd_met_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['properties']['timeseries']) || !is_array($d['properties']['timeseries'])) { return null; }
    $s = array();
    foreach ($d['properties']['timeseries'] as $e) {
        $i = $e['data']['instant']['details'] ?? null;
        if (!is_array($i)) { continue; }
        $n1 = $e['data']['next_1_hours']['details'] ?? null;
        if (!is_array($n1)) { continue; }
        $w = wd_num($i['wind_speed'] ?? null); $g = wd_num($i['wind_speed_of_gust'] ?? null);
        $s[] = array('ts' => wd_ts_iso($e['time'] ?? ''), 't' => wd_num($i['air_temperature'] ?? null), 'rf' => wd_num($i['relative_humidity'] ?? null), 'regen' => wd_num($n1['precipitation_amount'] ?? null),
            'regen_w' => wd_num($n1['probability_of_precipitation'] ?? null), 'wind' => $w === null ? null : $w * 3.6, 'boe' => $g === null ? null : $g * 3.6, 'uv' => wd_num($i['ultraviolet_index_clear_sky'] ?? null));
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}

/** wttr.in ist ein Einzelbetrieb; der Betreiber meldete Tage mit erschoepfter Kapazitaet. Die Daten kommen in 3-Stunden-Schritten: oefter als deren Schrittweite bringt nichts. */
function wd_wttr_takt_min() { return 180; }

/** Ort fuer wttr.in: immer genau 2 Nachkommastellen, Punkt, Komma ohne Leerzeichen; unabhaengig von der Einstellung pos_stellen. */
function wd_wttr_ort($la, $lo) { return number_format((float) $la, 2, '.', '') . ',' . number_format((float) $lo, 2, '.', ''); }

/**
 * wttr.in format=j1: je Tag acht Schritte in Ortszeit, Zeit als 0, 300 ... 2100. Wird zu Stundenwerten: Temperatur, Feuchte und Wind linear, Regen je Schritt gleich verteilt.
 * Die Antwort muss zum Standort passen: Bei einem unbekannten Ort liefert der Dienst Wetter von anderswo, bei Kapazitaetsende Text.
 */
function wd_wttr_lesen($roh, $la, $lo)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['weather']) || !is_array($d['weather']) || !$d['weather']) { return null; }
    $na = $d['nearest_area'][0] ?? null;
    if (!is_array($na) || !is_numeric($na['latitude'] ?? null) || !is_numeric($na['longitude'] ?? null) || abs((float) $na['latitude'] - (float) $la) > 1.5 || abs((float) $na['longitude'] - (float) $lo) > 1.5) { return null; }
    $sl = array();
    foreach ($d['weather'] as $t) {
        if (!is_array($t) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', (string) ($t['date'] ?? '')) || !isset($t['hourly']) || !is_array($t['hourly'])) { continue; }
        foreach ($t['hourly'] as $h) {
            if (!is_array($h) || !is_numeric($h['time'] ?? null) || !is_numeric($h['tempC'] ?? null)) { continue; }
            $ts = strtotime($t['date'] . sprintf(' %02d:00:00', (int) floor((int) $h['time'] / 100)));
            if ($ts === false) { continue; }
            $sl[$ts] = array('t' => (float) $h['tempC'], 'rf' => wd_num($h['humidity'] ?? null), 'wind' => wd_num($h['windspeedKmph'] ?? null), 'boe' => wd_num($h['WindGustKmph'] ?? null), 'regen' => wd_num($h['precipMM'] ?? null), 'regen_w' => wd_num($h['chanceofrain'] ?? null), 'uv' => wd_num($h['uvIndex'] ?? null));
        }
    }
    ksort($sl);
    $ts = array_keys($sl); $s = array(); $n = 3;
    $zw = function ($a, $b, $f) { return ($a === null || $b === null) ? $a : $a + ($b - $a) * $f; };
    foreach ($ts as $i => $t0) {
        $a = $sl[$t0]; $b = isset($ts[$i + 1]) ? $sl[$ts[$i + 1]] : $a;
        if (isset($ts[$i + 1])) { $n = max(1, min(6, (int) round(($ts[$i + 1] - $t0) / 3600))); }
        for ($k = 0; $k < $n; $k++) {
            $f = $k / $n;
            $s[] = array('ts' => $t0 + $k * 3600, 't' => round($zw($a['t'], $b['t'], $f), 2), 'rf' => $zw($a['rf'], $b['rf'], $f), 'wind' => $zw($a['wind'], $b['wind'], $f), 'boe' => $a['boe'],
                'regen' => $a['regen'] === null ? null : round($a['regen'] / $n, 3), 'regen_w' => $a['regen_w'], 'uv' => $a['uv']);
        }
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}

/* Adapter mit Schluessel: nach Dienstdoku, nie gegen eine echte Antwort gefahren. */
function wd_ow_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['hourly']) || !is_array($d['hourly'])) { return null; }
    $s = array();
    foreach ($d['hourly'] as $h) {
        $w = wd_num($h['wind_speed'] ?? null); $g = wd_num($h['wind_gust'] ?? null); $p = wd_num($h['pop'] ?? null);
        $s[] = array('ts' => (int) ($h['dt'] ?? 0), 't' => wd_num($h['temp'] ?? null), 'rf' => wd_num($h['humidity'] ?? null), 'regen' => (float) ($h['rain']['1h'] ?? 0) + (float) ($h['snow']['1h'] ?? 0), 'regen_w' => $p === null ? null : $p * 100,
            'wind' => $w === null ? null : $w * 3.6, 'boe' => $g === null ? null : $g * 3.6, 'uv' => wd_num($h['uvi'] ?? null));
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}
function wd_wa_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['forecast']['forecastday'])) { return null; }
    $s = array();
    foreach ((array) $d['forecast']['forecastday'] as $t) {
        foreach ((array) ($t['hour'] ?? array()) as $h) {
            $s[] = array('ts' => (int) ($h['time_epoch'] ?? 0), 't' => wd_num($h['temp_c'] ?? null), 'rf' => wd_num($h['humidity'] ?? null), 'regen' => wd_num($h['precip_mm'] ?? null), 'regen_w' => wd_num($h['chance_of_rain'] ?? null), 'wind' => wd_num($h['wind_kph'] ?? null), 'boe' => wd_num($h['gust_kph'] ?? null), 'uv' => wd_num($h['uv'] ?? null));
        }
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}
function wd_vc_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['days']) || !is_array($d['days'])) { return null; }
    $s = array();
    foreach ($d['days'] as $t) {
        foreach ((array) ($t['hours'] ?? array()) as $h) {
            $s[] = array('ts' => (int) ($h['datetimeEpoch'] ?? 0), 't' => wd_num($h['temp'] ?? null), 'rf' => wd_num($h['humidity'] ?? null), 'regen' => wd_num($h['precip'] ?? null), 'regen_w' => wd_num($h['precipprob'] ?? null), 'wind' => wd_num($h['windspeed'] ?? null), 'boe' => wd_num($h['windgust'] ?? null), 'solar' => wd_num($h['solarradiation'] ?? null), 'uv' => wd_num($h['uvindex'] ?? null));
        }
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}
function wd_tm_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['timelines']['hourly'])) { return null; }
    $s = array();
    foreach ((array) $d['timelines']['hourly'] as $h) {
        $v = (array) ($h['values'] ?? array()); $w = wd_num($v['windSpeed'] ?? null); $g = wd_num($v['windGust'] ?? null);
        $s[] = array('ts' => wd_ts_iso($h['time'] ?? ''), 't' => wd_num($v['temperature'] ?? null), 'rf' => wd_num($v['humidity'] ?? null), 'regen' => wd_num($v['rainIntensity'] ?? null), 'regen_w' => wd_num($v['precipitationProbability'] ?? null), 'wind' => $w === null ? null : $w * 3.6, 'boe' => $g === null ? null : $g * 3.6, 'uv' => wd_num($v['uvIndex'] ?? null));
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}
function wd_mb_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['data_1h']['time'])) { return null; }
    $h = $d['data_1h']; $s = array();
    foreach ((array) $h['time'] as $i => $t) {
        $w = wd_num($h['windspeed'][$i] ?? null);
        $s[] = array('ts' => wd_ts_iso((string) $t . ' UTC'), 't' => wd_num($h['temperature'][$i] ?? null), 'rf' => wd_num($h['relativehumidity'][$i] ?? null), 'regen' => wd_num($h['precipitation'][$i] ?? null), 'regen_w' => wd_num($h['precipitation_probability'][$i] ?? null), 'wind' => $w === null ? null : $w * 3.6);
    }
    return count($s) >= 12 ? array('stunden' => $s) : null;
}

/** Adresse und Leser je Quelle. Der Standort ist hoechstens vier Stellen genau (MET verlangt das ohnehin). */
function wd_prog_abruf($q, $c, $jetzt)
{
    $pos = wd_position($c);
    if ($pos === null) { return null; }
    $la = $pos[0]; $lo = $pos[1];
    switch ($q) {
        case 'mosmix': return array('https://api.brightsky.dev/weather?lat=' . $la . '&lon=' . $lo . '&date=' . gmdate('Y-m-d', $jetzt) . '&last_date=' . gmdate('Y-m-d', $jetzt + 3 * 86400) . '&tz=UTC', 'wd_mosmix_lesen');
        case 'met': return array('https://api.met.no/weatherapi/locationforecast/2.0/complete?lat=' . $la . '&lon=' . $lo, 'wd_met_lesen');
        case 'wttr': return array('https://wttr.in/' . wd_wttr_ort($la, $lo) . '?format=j1', function ($r) use ($la, $lo) { return wd_wttr_lesen($r, $la, $lo); });
        case 'openweather': return array('https://api.openweathermap.org/data/3.0/onecall?lat=' . $la . '&lon=' . $lo . '&units=metric&exclude=minutely,daily,alerts,current&appid=' . rawurlencode((string) $c['ow_key']), 'wd_ow_lesen');
        case 'weatherapi': return array('https://api.weatherapi.com/v1/forecast.json?key=' . rawurlencode((string) $c['wa_key']) . '&q=' . $la . ',' . $lo . '&days=3&aqi=no&alerts=no', 'wd_wa_lesen');
        case 'visualcrossing': return array('https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/' . $la . ',' . $lo . '/next3days?unitGroup=metric&include=hours&key=' . rawurlencode((string) $c['vc_key']), 'wd_vc_lesen');
        case 'tomorrow': return array('https://api.tomorrow.io/v4/weather/forecast?location=' . $la . ',' . $lo . '&timesteps=1h&units=metric&apikey=' . rawurlencode((string) $c['tm_key']), 'wd_tm_lesen');
        case 'meteoblue': return array('https://my.meteoblue.com/packages/basic-1h?lat=' . $la . '&lon=' . $lo . '&format=json&apikey=' . rawurlencode((string) $c['mb_key']), 'wd_mb_lesen');
    }
    return null;
}

/** Die uebrigen Prognosequellen holen; je eine eigene Bremse. Schluesseldienste hoechstens stuendlich, damit kein freies Kontingent reisst. MET: nie vor Expires.
 *  wttr.in: fester Abstand, ein Fehlschlag verkuerzt ihn nicht; auch der Knopf hoechstens alle 15 Minuten (der Dienst haelt seine Antwort ohnehin 10 Minuten). */
function wd_prog_holen($c, $jetzt, $zwingen)
{
    foreach (wd_prog_quellen() as $q => $f) {
        if ($q === 'open_meteo' || empty($c[$f[0]]) || ($f[1] !== '' && trim((string) $c[$f[1]]) === '')) { continue; }
        $m = wd_quelle_lesen($q);
        $takt = $f[1] !== '' ? max(60, (int) $c['om_takt_min']) : max(30, (int) $c['om_takt_min']);
        if ($q === 'wttr') {
            if ($jetzt - (int) $m['versuch_ts'] < ($zwingen ? 15 : wd_wttr_takt_min()) * 60 - 5) { continue; }
        } elseif (!$zwingen && !wd_faellig($m, $takt, $jetzt)) { continue; }
        if (!$zwingen && $q === 'met' && isset($m['expires']) && $jetzt < (int) $m['expires']) { continue; }
        $a = wd_prog_abruf($q, $c, $jetzt);
        if ($a === null) { continue; }
        $m['versuch_ts'] = $jetzt;
        list($code, $b, $hk) = wd_http($a[0], 25);
        $d = $code === 200 ? call_user_func($a[1], $b) : null;
        if ($d !== null) {
            wd_json_schreiben(wd_datadir() . '/prog_' . $q . '.json', $d);
            wd_bilanz_merken($q, $d['stunden'], $jetzt);
            $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
            if ($q === 'met' && preg_match('/^Expires:\s*(.+)$/mi', (string) $hk, $t)) { $m['expires'] = wd_ts_iso(trim($t[1])); }
        } else {
            $m['fehl'] = (int) $m['fehl'] + 1; $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
            wd_log_einmal('p' . $q, $q . ': ' . $m['meldung']);
        }
        wd_quelle_merken($q, $m);
    }
}

/* ------------------------------------ Bilanz gegen die eigene Station ------------------------------------ */

function wd_frist($h) { return $h < 6 ? 'f0' : ($h < 24 ? 'f6' : 'f24'); }

/** Der Vorlauf, fuer den eine Frist steht: ihre Mitte, nicht der letzte Abruf vor ihrem Ende (das maß bei f0 Minuten statt Stunden). */
function wd_frist_ziel($fr) { return $fr === 'f0' ? 3 : ($fr === 'f6' ? 12 : 48); }

/** Die volle Stunde wird beim ersten Lauf mit Station in ihren ersten 15 Minuten abgerechnet; wd_bilanz_rechnen() streicht sie danach. Spaeter passt die Stationstemperatur nicht mehr zur Stunde. */
function wd_bilanz_faellig($jetzt) { return $jetzt % 3600 < 900; }

/** Merkt je Quelle und kuenftiger Stunde die Temperatur je Frist (0-6, 6-24, 24-72 h): bis zum Ziel der Frist die neueste Prognose, danach bleibt sie stehen. */
function wd_bilanz_merken($q, array $std, $jetzt)
{
    $f = wd_datadir() . '/bilanz_vorrat.json';
    $v = wd_json_lesen($f); if (!is_array($v)) { $v = array(); }
    foreach ($std as $z) {
        $d = ((int) $z['ts'] - $jetzt) / 3600;
        if ($d < 0 || $d >= 72 || !isset($z['t']) || $z['t'] === null) { continue; }
        $fr = wd_frist($d);
        if (!isset($v[(string) (int) $z['ts']][$q][$fr]) || $d >= wd_frist_ziel($fr)) { $v[(string) (int) $z['ts']][$q][$fr] = round((float) $z['t'], 1); }
    }
    foreach (array_keys($v) as $k) { if ((int) $k < $jetzt - 7200) { unset($v[$k]); } }
    wd_json_schreiben($f, $v);
}

/** Vergleicht die abgelaufene Stunde mit der Station. Tagessummen, 30 Tage. */
function wd_bilanz_rechnen($t_station, $jetzt)
{
    if ($t_station === null) { return; }
    $stunde = $jetzt - $jetzt % 3600;
    $vf = wd_datadir() . '/bilanz_vorrat.json'; $v = wd_json_lesen($vf);
    if (!is_array($v) || !isset($v[(string) $stunde])) { return; }
    $f = wd_datadir() . '/bilanz.json'; $b = wd_json_lesen($f); if (!is_array($b)) { $b = array(); }
    $tag = date('Ymd', $jetzt);
    foreach ($v[(string) $stunde] as $q => $fr) {
        foreach ($fr as $k => $t) {
            $e = (float) $t - (float) $t_station;
            if (!isset($b[$q][$k][$tag])) { $b[$q][$k][$tag] = array(0, 0.0, 0.0); }
            $b[$q][$k][$tag][0]++; $b[$q][$k][$tag][1] += $e * $e; $b[$q][$k][$tag][2] += $e;
        }
    }
    $grenze = date('Ymd', $jetzt - 30 * 86400);
    foreach ($b as $q => $fr) { foreach ($fr as $k => $tg) { foreach (array_keys($tg) as $d) { if ((string) $d < $grenze) { unset($b[$q][$k][$d]); } } } }
    unset($v[(string) $stunde]);
    wd_json_schreiben($vf, $v); wd_json_schreiben($f, $b);
}

/** Bilanz je Quelle und Frist: array(Tage, mittlerer quadratischer Fehler, Bias). */
function wd_bilanz_lage()
{
    $b = wd_json_lesen(wd_datadir() . '/bilanz.json'); $o = array();
    foreach (is_array($b) ? $b : array() as $q => $fr) {
        foreach ($fr as $k => $tg) {
            $n = 0; $s = 0.0; $bi = 0.0;
            foreach ($tg as $w) { $n += (int) $w[0]; $s += (float) $w[1]; $bi += (float) $w[2]; }
            if ($n > 0) { $o[$q][$k] = array(count($tg), sqrt($s / $n), $bi / $n); }
        }
    }
    return $o;
}

/** Gewicht = 1/Fehler^2, unter 14 Tagen Daten gleich (Vorlage 4.1). Grenzen, damit eine Quelle nie alles traegt. */
function wd_gewicht(array $lage, $q, $k)
{
    if (!isset($lage[$q][$k]) || $lage[$q][$k][0] < 14) { return 1.0; }
    return 1.0 / max(0.09, $lage[$q][$k][1] * $lage[$q][$k][1]);
}

/** Faktor je Quelle wandert in 30 Minuten zwischen 0 und 1, damit ein Ausfall nicht als Sprung in Schwellen faehrt (Vorlage 4.2). */
function wd_faktoren(array $an, $jetzt)
{
    $f = wd_datadir() . '/mix_faktoren.json'; $d = wd_json_lesen($f); if (!is_array($d)) { $d = array('ts' => $jetzt, 'q' => array()); }
    $sch = min(1.0, max(0.0, ($jetzt - (int) $d['ts']) / 1800));
    foreach ($an as $q => $ok) {
        $alt = isset($d['q'][$q]) ? (float) $d['q'][$q] : ($ok ? 1.0 : 0.0);
        $d['q'][$q] = $ok ? min(1.0, $alt + $sch) : max(0.0, $alt - $sch);
    }
    $d['ts'] = $jetzt;
    wd_json_schreiben($f, $d);
    return $d['q'];
}

/** Mischung. Regeln Vorlage 4: Temperatur gewichtet, Tmin kleinster, Tmax groesster, Boe groesste, Regen Median plus regen_max, Wahrscheinlichkeit aus der ersten Quelle, die sie hat. */
function wd_mischen(array $je_quelle, array $faktor, array $lage, $bias, $jetzt)
{
    $idx = array();
    foreach ($je_quelle as $q => $std) { foreach ($std as $z) { if ((int) $z['ts'] > 0) { $idx[(int) $z['ts'] - (int) $z['ts'] % 3600][$q] = $z; } } }
    ksort($idx);
    $aus = array();
    foreach ($idx as $ts => $qs) {
        $fr = wd_frist(max(0, ($ts - $jetzt) / 3600));
        $m = array('ts' => $ts); $ts2 = array(); $sg = 0.0; $st = 0.0; $srf = 0.0; $grf = 0.0; $sw = 0.0; $gw = 0.0;
        $regen = array(); $schnee = array(); $bo = null; $uv = null; $rw = null; $sol = null; $n = 0;
        foreach ($qs as $q => $z) {
            $g = wd_gewicht($lage, $q, $fr) * max(0.0, (float) ($faktor[$q] ?? 1));
            if (isset($z['t']) && $z['t'] !== null) {
                $t = (float) $z['t'] - (($bias && isset($lage[$q][$fr]) && $lage[$q][$fr][0] >= 14) ? (float) $lage[$q][$fr][2] : 0.0);
                $ts2[] = $t;
                if ($g > 0) { $st += $g * $t; $sg += $g; $n++; }
            }
            if (isset($z['rf']) && $z['rf'] !== null && $g > 0) { $srf += $g * (float) $z['rf']; $grf += $g; }
            if (isset($z['wind']) && $z['wind'] !== null && $g > 0) { $sw += $g * (float) $z['wind']; $gw += $g; }
            if (isset($z['regen']) && $z['regen'] !== null) { $regen[] = (float) $z['regen']; }
            if (isset($z['schnee']) && $z['schnee'] !== null) { $schnee[] = (float) $z['schnee']; }
            if (isset($z['boe']) && $z['boe'] !== null) { $bo = max((float) $bo, (float) $z['boe']); }
            if (isset($z['uv']) && $z['uv'] !== null) { $uv = max((float) $uv, (float) $z['uv']); }
            if ($rw === null && isset($z['regen_w']) && $z['regen_w'] !== null) { $rw = (float) $z['regen_w']; }
        }
        if (isset($qs['open_meteo']['solar'])) { $sol = $qs['open_meteo']['solar']; } else { foreach ($qs as $z) { if (isset($z['solar']) && $z['solar'] !== null) { $sol = $z['solar']; break; } } }
        sort($regen);
        $med = function ($l) { $n = count($l); if (!$n) { return null; } return $n % 2 ? $l[(int) ($n / 2)] : ($l[$n / 2 - 1] + $l[$n / 2]) / 2; };
        $m['t'] = $sg > 0 ? $st / $sg : ($ts2 ? array_sum($ts2) / count($ts2) : null);
        $m['t_min'] = $ts2 ? min($ts2) : null; $m['t_max'] = $ts2 ? max($ts2) : null;
        $m['rf'] = $grf > 0 ? $srf / $grf : null; $m['wind'] = $gw > 0 ? $sw / $gw : null; $m['regen'] = $med($regen); $m['regen_max'] = $regen ? max($regen) : null;
        $m['regen_w'] = $rw; $m['schnee'] = $schnee ? $med($schnee) : null; $m['boe'] = $bo; $m['uv'] = $uv; $m['solar'] = $sol;
        $aus[] = $m;
    }
    return $aus;
}

/** Alle lebenden Prognosen laden und mischen. Rueckgabe array(stunden, tage, jetzt, Herkunft 'mix:n' oder Quellenname) oder null. */
function wd_prog_gesamt(array $c, array $q, $jetzt)
{
    $je = array(); $an = array(); $om = null;
    foreach (wd_prog_quellen() as $n => $f) {
        $ok = !empty($q[$n]['ok']);
        $an[$n] = $ok;
        $d = wd_json_lesen(wd_datadir() . ($n === 'open_meteo' ? '/prog.json' : '/prog_' . $n . '.json'));
        if (!is_array($d) || !isset($d['stunden'])) { continue; }
        if ($ok || (int) ($q[$n]['alter_s'] ?? 999999) <= (int) $c['prog_max_alter_min'] * 60) { $je[$n] = $d['stunden']; }
        if ($n === 'open_meteo' && $ok) { $om = $d; }
    }
    $leben = array_keys(array_filter($an));
    if (!$leben || !$je) { return null; }
    $faktor = wd_faktoren($an, $jetzt);
    $std = wd_mischen($je, $faktor, wd_bilanz_lage(), !empty($c['bias_ein']), $jetzt);
    return array('stunden' => $std, 'tage' => $om ? $om['tage'] : array(), 'jetzt' => $om ? $om['jetzt'] : wd_prog_jetzt($std, $jetzt), 'herkunft' => count($leben) === 1 ? str_replace('_', '-', $leben[0]) : 'mix:' . count($leben));
}

/** Ohne Open-Meteo: der Mischwert der laufenden Stunde als Wert fuer jetzt. Tagescode entfaellt: Codes werden nie gemittelt. */
function wd_prog_jetzt(array $std, $jetzt)
{
    foreach ($std as $z) {
        if (abs((int) $z['ts'] - $jetzt) <= 3600) {
            $r = $z['regen'];
            return array('temp_c' => $z['t'] === null ? null : round($z['t'], 1), 'rf_pct' => $z['rf'] === null ? null : round($z['rf']), 'taupunkt_c' => null, 'druck_hpa' => null, 'wind_kmh' => $z['wind'] === null ? null : round($z['wind'], 1), 'boe_kmh' => $z['boe'], 'windrichtung_grad' => null, 'regen_mmh' => $r, 'strahlung_wm2' => $z['solar'], 'uv' => $z['uv'], 'regnet' => $r === null ? null : ($r > 0 ? 1 : 0));
        }
    }
    return array();
}

/* ------------------------------------ Fassade: Strahlung auf senkrechter Flaeche, Open-Meteo ------------------------------------ */

/** Hoechstens vier Ausrichtungen in Grad (0 Sued, -90 Ost, 90 West, Open-Meteo-Zaehlung). Einmal je Stunde. */
function wd_fassade_holen($c, $jetzt, $zwingen)
{
    if (empty($c['fassade_ein']) || empty($c['om_ein'])) { return; }
    $m = wd_quelle_lesen('fassade');
    if (!$zwingen && !wd_faellig($m, 60, $jetzt)) { return; }
    $pos = wd_position($c); if ($pos === null) { return; }
    $m['versuch_ts'] = $jetzt; $aus = array(); $ok = true;
    foreach (array_slice(explode(',', (string) $c['fassade_az']), 0, 4) as $i => $az) {
        list($code, $b) = wd_http('https://api.open-meteo.com/v1/forecast?latitude=' . $pos[0] . '&longitude=' . $pos[1] . '&timezone=auto&forecast_days=2&hourly=global_tilted_irradiance&tilt=90&azimuth=' . (int) $az, 20);
        $d = $code === 200 ? json_decode($b, true) : null;
        if (!is_array($d) || !isset($d['hourly']['global_tilted_irradiance'], $d['hourly']['time'])) { $ok = false; continue; }
        $off = (int) ($d['utc_offset_seconds'] ?? 0); $max = null;
        foreach ($d['hourly']['time'] as $k => $t) {
            $ts = wd_ts_iso($t . ':00 UTC') - $off;
            if ($ts >= $jetzt && $ts < $jetzt + 12 * 3600 && is_numeric($d['hourly']['global_tilted_irradiance'][$k])) { $max = max((float) $max, (float) $d['hourly']['global_tilted_irradiance'][$k]); }
        }
        $aus[$i + 1] = $max;
    }
    if ($ok) { wd_json_schreiben(wd_datadir() . '/fassade.json', array('ts' => $jetzt, 'w' => $aus)); $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200'; }
    else { $m['fehl'] = (int) $m['fehl'] + 1; $m['meldung'] = wd_t('QUELLE.INHALT'); }
    wd_quelle_merken('fassade', $m);
}

/* ------------------------------------ Umwelt: Luft (CAMS ueber Open-Meteo), Pollen (DWD) ------------------------------------ */

function wd_luft_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['current']) || !is_array($d['current'])) { return null; }
    $c = $d['current'];
    return array('index' => wd_num($c['european_aqi'] ?? null), 'pm10' => wd_num($c['pm10'] ?? null), 'pm25' => wd_num($c['pm2_5'] ?? null), 'ozon' => wd_num($c['ozone'] ?? null), 'no2' => wd_num($c['nitrogen_dioxide'] ?? null));
}

function wd_pollen_arten() { return array('Hasel', 'Erle', 'Esche', 'Birke', 'Graeser', 'Roggen', 'Beifuss', 'Ambrosia'); }

/** DWD s31fg: Stufen als Text 0, 0-1, 1 ... 3, -1 fehlt. In eine Zahl von 0 bis 3 in Halbschritten. Rueckgabe je Art array(heute, morgen). */
function wd_pollen_stufe($s)
{
    $s = trim((string) $s);
    if (preg_match('/^(\d)-(\d)\z/', $s, $m)) { return ((int) $m[1] + (int) $m[2]) / 2; }
    return preg_match('/^[0-3]\z/', $s) ? (float) $s : null;
}
function wd_pollen_lesen($roh, $teil)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['content']) || !is_array($d['content'])) { return null; }
    foreach ($d['content'] as $e) {
        if (!is_array($e) || (int) ($e['partregion_id'] ?? 0) !== (int) $teil || !isset($e['Pollen'])) {
            if (!is_array($e) || (int) ($e['partregion_id'] ?? 0) !== -1 || (int) ($e['region_id'] ?? 0) !== (int) $teil) { continue; }
        }
        $o = array();
        foreach (wd_pollen_arten() as $a) {
            $o[strtolower($a)] = array(wd_pollen_stufe($e['Pollen'][$a]['today'] ?? ''), wd_pollen_stufe($e['Pollen'][$a]['tomorrow'] ?? ''));
        }
        return $o;
    }
    return null;
}

/* ------------------------------------ NINA, nur Nicht-Wetter: Der Strom dwd wird nie ausgewertet ------------------------------------ */

function wd_nina_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return null; }
    $o = array();
    foreach ($d as $e) {
        if (!is_array($e)) { continue; }
        $id = (string) ($e['id'] ?? '');
        if (stripos($id, 'dwd') === 0) { continue; }
        $st = strtolower((string) preg_replace('/\..*$/', '', $id));
        $st = preg_replace('/^([a-z]+).*/', '$1', $st);
        $sev = (string) ($e['payload']['data']['severity'] ?? '');
        $titel = (string) ($e['i18nTitle']['de'] ?? ($e['payload']['data']['headline'] ?? ''));
        $o[] = array('id' => $id, 'status' => 'actual', 'msg' => (string) ($e['payload']['data']['msgType'] ?? 'alert'), 'ii' => 0, 'gruppe' => 'NINA', 'art' => $titel, 'stufe' => wd_stufe_aus_schwere($sev), 'vorab' => 0,
            'beginn' => wd_ts_iso($e['startDate'] ?? ($e['sent'] ?? '')), 'ende' => wd_ts_iso($e['expiresDate'] ?? ''), 'titel' => $titel, 'volltext' => $titel, 'quelle' => 'nina', 'strom' => $st, 'verweise' => array());
    }
    return $o;
}

/* ------------------------------------ Radar ueber Bright Sky: verdichtet auf einen Punkt ------------------------------------ */

/** Antwort /radar mit format=plain: Liste von Schritten, je timestamp und precipitation_5 (Raster, Zehntelmillimeter je 5 Minuten).
 *  Rueckgabe array(Minuten bis Regen oder null, mm/h jetzt). Der Hoechstwert im kleinen Raster zaehlt. */
function wd_radar_lesen($roh, $jetzt)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['radar']) || !is_array($d['radar']) || !$d['radar']) { return null; }
    $bis = null; $jetzt_wert = 0.0;
    foreach ($d['radar'] as $s) {
        $t = wd_ts_iso($s['timestamp'] ?? ''); $g = $s['precipitation_5'] ?? null;
        if (!is_array($g) || $t <= 0) { continue; }
        $mx = 0;
        foreach ($g as $z) { foreach ((array) $z as $w) { if (is_numeric($w) && (float) $w > $mx) { $mx = (float) $w; } } }
        if ($t + 300 >= $jetzt && $t <= $jetzt + 300) { $jetzt_wert = max($jetzt_wert, $mx / 100 * 12); }
        if ($t >= $jetzt - 300 && $mx > 0 && $bis === null) { $bis = max(0, (int) round(($t - $jetzt) / 60)); }
    }
    return array($bis, round($jetzt_wert, 1));
}

/* ------------------------------------ Meteoalarm: nur fuer Nutzer ausserhalb Deutschlands, ab Werk aus ------------------------------------ */

function wd_ma_lesen($roh, $emma)
{
    if (strpos((string) $roh, '<feed') === false) { return null; }
    $o = array();
    if (preg_match_all('#<entry>(.*?)</entry>#s', (string) $roh, $m)) {
        foreach ($m[1] as $e) {
            if ($emma !== '' && strpos($e, '<value>' . $emma . '</value>') === false) { continue; }
            $ein = function ($tag) use ($e) { return preg_match('#<cap:' . $tag . '>(.*?)</cap:' . $tag . '>#s', $e, $t) ? wd_xml_wert($t[1]) : ''; };
            $art = $ein('event');
            // 0.9.6: Bedingungen von MeteoAlarm (Stand 15.03.2024), Ziffer 5: Ausgabezeit, Quelle und Verweis stehen bei jeder Warnung.
            $ma_stelle = preg_match('#<author>\s*<name>(.*?)</name>#s', $e, $ma_t) ? wd_xml_wert($ma_t[1]) : '';
            $ma_zeit = wd_ts_iso($ein('sent') ?: $ein('effective'));
            $ma_satz = sprintf(wd_t('QUELLE.MA_NENNUNG'), is_int($ma_zeit) ? date('d.m.Y H:i', $ma_zeit) : (string) $ma_zeit, $ma_stelle !== '' && stripos($ma_stelle, 'meteoalarm') === false ? $ma_stelle . ', ' : '');
            $o[] = array('id' => $ein('identifier'), 'status' => $ein('status') ?: 'Actual', 'msg' => $ein('message_type') ?: 'Alert', 'ii' => 0, 'gruppe' => '', 'art' => $art, 'stufe' => wd_stufe_aus_schwere($ein('severity')), 'vorab' => 0,
                'beginn' => wd_ts_iso($ein('onset') ?: $ein('effective')), 'ende' => wd_ts_iso($ein('expires')), 'titel' => $art, 'volltext' => trim($art . ' ' . $ma_satz), 'quelle' => 'meteoalarm', 'verweise' => array());
        }
    }
    return $o;
}

/** Eine einfache Quelle mit Abruf, Leser und Datei. */
function wd_einfach_holen($q, $url, $leser, $datei, $takt, $jetzt, $zwingen, array $kopf = array())
{
    $m = wd_quelle_lesen($q);
    if (!$zwingen && !wd_faellig($m, $takt, $jetzt)) { return; }
    $m['versuch_ts'] = $jetzt;
    list($code, $b) = wd_http($url, 25, $kopf);
    $d = $code === 200 ? call_user_func($leser, $b) : null;
    if ($d !== null) { wd_json_schreiben(wd_datadir() . '/' . $datei, $d); $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200'; }
    else { $m['fehl'] = (int) $m['fehl'] + 1; $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code; wd_log_einmal('e' . $q, $q . ': ' . $m['meldung']); }
    wd_quelle_merken($q, $m);
}

/** Alle Quellen der Stufen 2 bis 5 in einem Lauf; jede fuer sich, ein Fehlschlag stoppt die anderen nicht. */
function wd_mehr_holen($c, $jetzt, $zwingen)
{
    wd_prog_holen($c, $jetzt, $zwingen);
    wd_fassade_holen($c, $jetzt, $zwingen);
    $pos = wd_position($c);
    if (!empty($c['aq_ein']) && $pos !== null) {
        wd_einfach_holen('luft', 'https://air-quality-api.open-meteo.com/v1/air-quality?latitude=' . $pos[0] . '&longitude=' . $pos[1] . '&timezone=auto&current=european_aqi,pm10,pm2_5,ozone,nitrogen_dioxide', 'wd_luft_lesen', 'luft.json', 60, $jetzt, $zwingen);
    }
    if (!empty($c['pollen_ein']) && trim((string) $c['pollen_region']) !== '') {
        $teil = (int) $c['pollen_region'];
        wd_einfach_holen('pollen', 'https://opendata.dwd.de/climate_environment/health/alerts/s31fg.json', function ($r) use ($teil) { return wd_pollen_lesen($r, $teil); }, 'pollen.json', 180, $jetzt, $zwingen);
    }
    if (!empty($c['nina_ein']) && preg_match('/^[0-9]{12}\z/', (string) $c['nina_ars'])) {
        wd_einfach_holen('nina', 'https://warnung.bund.de/api31/dashboard/' . substr((string) $c['nina_ars'], 0, 5) . '0000000.json', 'wd_nina_lesen', 'nina.json', 5, $jetzt, $zwingen);
    }
    if (!empty($c['ma_ein']) && preg_match('/^[a-z\-]{3,40}\z/', (string) $c['ma_land'])) {
        $em = (string) $c['ma_emma'];
        wd_einfach_holen('meteoalarm', 'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-' . $c['ma_land'], function ($r) use ($em) { return wd_ma_lesen($r, $em); }, 'meteoalarm.json', 5, $jetzt, $zwingen);     // 0.9.6: alle 5 Minuten, die Bedingungen verlangen hoechstens 10 Minuten Verzug
    }
    if (!empty($c['radar_ein']) && $pos !== null) {
        wd_einfach_holen('radar', 'https://api.brightsky.dev/radar?lat=' . $pos[0] . '&lon=' . $pos[1] . '&distance=5000&format=plain', function ($r) use ($jetzt) { $x = wd_radar_lesen($r, $jetzt); return $x === null ? null : array('min' => $x[0], 'mmh' => $x[1]); }, 'radar.json', 5, $jetzt, $zwingen, array('Accept-Encoding: gzip'));
    }
}

/* ------------------------------------ Station: direkt und Empfang ------------------------------------ */

/** Direktabruf bei der Station (get_livedata_info) ueber zwei Adressen in den Einstellungen. Rueckgabe array(HTTP, Rumpf). */
function wd_st_direkt($c)
{
    $code = 0; $b = '';
    foreach (array('station_host1', 'station_host2') as $k) {
        $h = trim((string) $c[$k]);
        if ($h === '') { continue; }
        list($code, $b) = wd_http('http://' . $h . '/get_livedata_info', 8);
        if ($code === 200 && wd_st_lesen($b) !== null) { return array($code, $b); }
    }
    return array($code, $b);
}

/** Empfang: Die Station meldet nach dem Ecowitt- oder WU-Protokoll an wd_empfang.php. Felder in der eigenen Form. */
function wd_empfang_lesen(array $e)
{
    $n = function ($k) use ($e) { return (isset($e[$k]) && is_numeric($e[$k])) ? (float) $e[$k] : null; };
    $f = $n('tempf'); $rf = $n('humidity');
    if ($f === null || $rf === null || $rf <= 0 || $rf > 100) { return null; }
    $ms = function ($v) { return $v === null ? null : round($v * 1.609344, 1); };
    $rr = $n('rainratein') ?? $n('rainin'); $bar = $n('baromrelin') ?? $n('baromin') ?? $n('baromabsin'); $in = $n('tempinf') ?? $n('indoortempf');
    $dp = $n('dewptf');
    return array('temp_c' => round(($f - 32) * 5 / 9, 1), 'rf_pct' => round($rf), 'taupunkt_c' => $dp === null ? null : round(($dp - 32) * 5 / 9, 1), 'druck_hpa' => $bar === null ? null : round($bar * 33.8639, 1),
        'wind_kmh' => $ms($n('windspeedmph')), 'boe_kmh' => $ms($n('windgustmph')), 'windrichtung_grad' => $n('winddir'), 'regen_mmh' => $rr === null ? null : round($rr * 25.4, 1), 'regnet' => $rr === null ? null : ($rr > 0 ? 1 : 0),
        'strahlung_wm2' => $n('solarradiation'), 'uv' => $n('uv') ?? $n('UV'), 'innen_c' => $in === null ? null : round(($in - 32) * 5 / 9, 1));
}

/* ------------------------------------ Fuer Oberflaeche und Lauf ------------------------------------ */

/** Umwelt in den Zustand. Pollen zaehlen nur fuer angehakte Arten; Signal lueftung_sperren ist nur ein Signal. */
function wd_umwelt(array &$st, array $c, $jetzt)
{
    $q = $st['quellen'];
    $st['luft'] = (!empty($q['luft']['ok'])) ? wd_json_lesen(wd_datadir() . '/luft.json') : null;
    $st['pollen'] = (!empty($q['pollen']['ok'])) ? wd_json_lesen(wd_datadir() . '/pollen.json') : null;
    $r = (!empty($q['radar']['ok'])) ? wd_json_lesen(wd_datadir() . '/radar.json') : null;
    $st['radar'] = $r;
    $hoch = 0; $wahl = array();
    if (is_array($st['pollen'])) {
        foreach (wd_pollen_arten() as $a) {
            $k = strtolower($a);
            if (!empty($c['p_' . $k])) {
                $v = $st['pollen'][$k][0] ?? null;
                if ($v !== null && $v >= (float) $c['pollen_sperre']) { $hoch = 1; $wahl[] = $a; }
            }
        }
    }
    $st['pollen_hoch_arten'] = $wahl;
    $luft = is_array($st['luft']) && isset($st['luft']['index']) ? (float) $st['luft']['index'] : null;
    $st['luft_hoch'] = $luft === null ? 0 : ($luft >= (float) $c['luft_sperre_aqi'] ? 1 : 0);
    $st['lueftung_sperren'] = ($st['luft'] === null && $st['pollen'] === null) ? null : (($hoch || $st['luft_hoch']) ? 1 : 0);
    // Bilanz fuer die Kachel: Wie gut war die Prognose heute?
    $lage = wd_bilanz_lage();
    $best = null;
    foreach ($lage as $qn => $fr) { if (isset($fr['f0']) && ($best === null || $fr['f0'][1] < $best)) { $best = $fr['f0'][1]; } }
    $st['bilanz'] = $lage; $st['bilanz_fehler'] = $best === null ? null : round($best, 1);
    $st['fassade'] = !empty($c['fassade_ein']) ? wd_json_lesen(wd_datadir() . '/fassade.json') : null;
    // NINA: immer eigene Lage, anderer Absender
    $n = (!empty($q['nina']['ok'])) ? wd_json_lesen(wd_datadir() . '/nina.json') : null;
    $st['nina'] = is_array($n) ? wd_lagen_ordnen($n, $jetzt) : null;
}

/** Briefing am Morgen: nur die angehakten Pollenarten und die Luft. */
function wd_briefing(array $st, array $c, $sp)
{
    $t = array();
    if (is_array($st['pollen'] ?? null)) {
        foreach (wd_pollen_arten() as $a) {
            $k = strtolower($a);
            if (!empty($c['p_' . $k]) && ($st['pollen'][$k][0] ?? 0) >= 1) { $t[] = sprintf(wd_t('SATZ.POLLEN', $sp), wd_t('POLLEN.' . $k, $sp), (string) $st['pollen'][$k][0]); }
        }
    }
    if (!empty($st['luft_hoch'])) { $t[] = wd_t('SATZ.LUFT', $sp); }
    return implode(' ', $t);
}

/** Satz fuer die Weblink-Seite und den Kachelwert. */
function wd_kachel(array $st)
{
    return array('ok' => !empty($st['jetzt_ok']) ? 1 : 0, 'temp_c' => $st['jetzt']['temp_c'] ?? null, 'gefuehlt_c' => $st['gefuehlt'] ?? null, 'herkunft' => $st['herkunft']['temp_c'] ?? 'keine',
        'stufe' => !empty($st['warn_ok']) ? (int) ($st['lagen'][0]['stufe'] ?? 0) : null, 'warnung' => isset($st['lagen'][0]) ? wd_warn_name($st['lagen'][0], wd_sprache()) : '', 'tmax' => $st['tage'][0]['tmax'] ?? null, 'tmin' => $st['tage'][0]['tmin'] ?? null, 'verlaesslich_k' => $st['bilanz_fehler'] ?? null, 'ts' => (int) ($st['ts'] ?? 0));
}

/* ------------------------------------ Bedienung 0.9.6: Listen, Klartext der Quellen, Suchen nur auf Knopfdruck ------------------------------------ */

/** Teilregionen des Pollenflugs. Nummern und Namen wortgleich aus der DWD-Datei s31fg.json, gelesen am 05.11.2026. Nummer = partregion_id, bei einer Region ohne Teil (partregion_id -1) region_id: so liest es wd_pollen_lesen. */
function wd_pollen_liste()
{
    return array(
        11 => array('Schleswig-Holstein und Hamburg', 'Inseln und Marschen'), 12 => array('Schleswig-Holstein und Hamburg', 'Geest,Schleswig-Holstein und Hamburg'),
        20 => array('Mecklenburg-Vorpommern', ''),
        31 => array('Niedersachsen und Bremen', 'Westl. Niedersachsen/Bremen'), 32 => array('Niedersachsen und Bremen', 'Östl. Niedersachsen'),
        41 => array('Nordrhein-Westfalen', 'Rhein.-Westfäl. Tiefland'), 42 => array('Nordrhein-Westfalen', 'Ostwestfalen'), 43 => array('Nordrhein-Westfalen', 'Mittelgebirge NRW'),
        50 => array('Brandenburg und Berlin', ''),
        61 => array('Sachsen-Anhalt', 'Tiefland Sachsen-Anhalt'), 62 => array('Sachsen-Anhalt', 'Harz'),
        71 => array('Thüringen', 'Tiefland Thüringen'), 72 => array('Thüringen', 'Mittelgebirge Thüringen'),
        81 => array('Sachsen', 'Tiefland Sachsen'), 82 => array('Sachsen', 'Mittelgebirge Sachsen'),
        91 => array('Hessen', 'Nordhessen und hess. Mittelgebirge'), 92 => array('Hessen', 'Rhein-Main'),
        101 => array('Rheinland-Pfalz und Saarland', 'Rhein, Pfalz, Nahe und Mosel'), 102 => array('Rheinland-Pfalz und Saarland', 'Mittelgebirgsbereich Rheinland-Pfalz'), 103 => array('Rheinland-Pfalz und Saarland', 'Saarland'),
        111 => array('Baden-Württemberg', 'Oberrhein und unteres Neckartal'), 112 => array('Baden-Württemberg', 'Hohenlohe/mittlerer Neckar/Oberschwaben'), 113 => array('Baden-Württemberg', 'Mittelgebirge Baden-Württemberg'),
        121 => array('Bayern', 'Allgäu/Oberbayern/Bay. Wald'), 122 => array('Bayern', 'Donauniederungen'), 123 => array('Bayern', 'Bayern nördl. der Donau, o. Bayr. Wald, o. Mainfranken'), 124 => array('Bayern', 'Mainfranken'),
    );
}

/** Feednamen von feeds.meteoalarm.org, gelesen am 05.11.2026, deutscher und englischer Name. Deutschland fehlt mit Absicht: Dort gelten die DWD-Warnungen; ein alter Eintrag bleibt gueltig. */
function wd_ma_laender()
{
    return array(
        'andorra' => array('Andorra', 'Andorra'), 'belgium' => array('Belgien', 'Belgium'), 'bosnia-herzegovina' => array('Bosnien und Herzegowina', 'Bosnia and Herzegovina'), 'bulgaria' => array('Bulgarien', 'Bulgaria'),
        'denmark' => array('Dänemark', 'Denmark'), 'estonia' => array('Estland', 'Estonia'), 'finland' => array('Finnland', 'Finland'), 'france' => array('Frankreich', 'France'), 'greece' => array('Griechenland', 'Greece'),
        'ireland' => array('Irland', 'Ireland'), 'iceland' => array('Island', 'Iceland'), 'israel' => array('Israel', 'Israel'), 'italy' => array('Italien', 'Italy'), 'croatia' => array('Kroatien', 'Croatia'),
        'latvia' => array('Lettland', 'Latvia'), 'lithuania' => array('Litauen', 'Lithuania'), 'luxembourg' => array('Luxemburg', 'Luxembourg'), 'malta' => array('Malta', 'Malta'), 'moldova' => array('Moldau', 'Moldova'),
        'montenegro' => array('Montenegro', 'Montenegro'), 'netherlands' => array('Niederlande', 'Netherlands'), 'republic-of-north-macedonia' => array('Nordmazedonien', 'North Macedonia'), 'norway' => array('Norwegen', 'Norway'),
        'austria' => array('Österreich', 'Austria'), 'poland' => array('Polen', 'Poland'), 'portugal' => array('Portugal', 'Portugal'), 'romania' => array('Rumänien', 'Romania'), 'sweden' => array('Schweden', 'Sweden'),
        'switzerland' => array('Schweiz', 'Switzerland'), 'serbia' => array('Serbien', 'Serbia'), 'slovakia' => array('Slowakei', 'Slovakia'), 'slovenia' => array('Slowenien', 'Slovenia'), 'spain' => array('Spanien', 'Spain'),
        'czechia' => array('Tschechien', 'Czech Republic'), 'ukraine' => array('Ukraine', 'Ukraine'), 'hungary' => array('Ungarn', 'Hungary'), 'united-kingdom' => array('Vereinigtes Königreich', 'United Kingdom'), 'cyprus' => array('Zypern', 'Cyprus'),
    );
}

/** Auswahl fuer ein Feld: Wert => Anzeige, erster Eintrag leer. Die Eingabenpruefung bleibt die alte, ein Wert ausserhalb der Liste bleibt also gueltig. */
function wd_auswahl_liste($k)
{
    $o = array('' => wd_t('TEXT.BITTE_WAEHLEN'));
    if ($k === 'pollen_region') {
        foreach (wd_pollen_liste() as $n => $e) { $o[(string) $n] = $e[0] . ($e[1] !== '' ? ': ' . $e[1] : '') . ' (' . $n . ')'; }
    } elseif ($k === 'ma_land') {
        $en = wd_sprache() === 'en';
        $l = array();
        foreach (wd_ma_laender() as $n => $e) { $l[$n] = $en ? $e[1] : $e[0]; }
        if ($en) { asort($l); }
        $o += $l;
    }
    return $o;
}

/** Fundstellen je Feld: array(Adresse, Sprachschluessel). Jede Adresse am 05.11.2026 geoeffnet. */
function wd_feld_verweise()
{
    return array(
        'om_apikey' => array(array('https://open-meteo.com/en/pricing', 'VERWEIS.OM')),
        'pollen_region' => array(array('https://opendata.dwd.de/climate_environment/health/alerts/s31fg.json', 'VERWEIS.POLLEN')),
        'nina_ars' => array(array('https://www.xrepository.de/details/urn:de:bund:destatis:bevoelkerungsstatistik:schluessel:rs', 'VERWEIS.ARS'), array('https://nina.api.bund.dev/', 'VERWEIS.NINA_API')),
        'ma_land' => array(array('https://feeds.meteoalarm.org/', 'VERWEIS.MA_FEEDS')),
        'ma_emma' => array(array('https://meteoalarm.org/', 'VERWEIS.MA_SEITE')),
        'ow_key' => array(array('https://home.openweathermap.org/users/sign_up', 'VERWEIS.OW'), array('https://openweathermap.org/api/one-call-3', 'VERWEIS.OW_PREIS')),
        'wa_key' => array(array('https://www.weatherapi.com/signup', 'VERWEIS.WA'), array('https://www.weatherapi.com/pricing', 'VERWEIS.WA_PREIS')),
        'vc_key' => array(array('https://www.visualcrossing.com/sign-up/', 'VERWEIS.VC'), array('https://www.visualcrossing.com/weather-data-pricing/', 'VERWEIS.VC_PREIS')),
        'tm_key' => array(array('https://app.tomorrow.io/signup', 'VERWEIS.TM'), array('https://www.tomorrow.io/weather-api/', 'VERWEIS.TM_PREIS')),
        'mb_key' => array(array('https://business.meteoblue.com/products/weather-apis/free-weather-api', 'VERWEIS.MB')),
    );
}

/** Technische Meldung in einen Satz. Station und Dienste getrennt: 403 heisst bei der Weiche etwas anderes als bei einem fremden Dienst. */
function wd_quelle_klartext($n, $meldung, array $c)
{
    $m = (string) $meldung;
    if ($m === '') { return wd_t('QUELLE.NOCH_NICHT'); }
    if (!preg_match('/^HTTP (\d+)\z/', $m, $t)) { return $m; }
    $h = (int) $t[1];
    if ($n === 'station') {
        $art = (string) ($c['station_art'] ?? 'weiche');
        if ($art === 'empfang') { return wd_t('QUELLE.ST_EMPFANG'); }
        if ($art === 'direkt') { return $h === 0 ? wd_t('QUELLE.ST_DIREKT_0') : sprintf(wd_t('QUELLE.K_HTTP'), $h); }
        $k = array(0 => 'ST_0', 403 => 'ST_403', 404 => 'ST_404', 503 => 'ST_503');
        return isset($k[$h]) ? wd_t('QUELLE.' . $k[$h]) : sprintf(wd_t('QUELLE.K_HTTP'), $h);
    }
    $k = array(0 => 'K_0', 401 => 'K_401', 403 => 'K_403', 429 => 'K_429');
    return isset($k[$h]) ? wd_t('QUELLE.' . $k[$h]) : sprintf(wd_t('QUELLE.K_HTTP'), $h);
}

/** Eine Zeile der Quellenuebersicht: array(Lage, Meldung). Fehlt eine Eintragung oder wird die Quelle gar nicht gefragt, sagt sie das statt "keine gueltige Antwort". */
function wd_quelle_anzeige($n, array $q, array $c, array $alle = array())
{
    $li = wd_quellen_liste();
    $ein = isset($li[$n]) ? !empty($c[$li[$n]]) : !empty($q['ein']);
    if (!$ein) { return array(wd_t('QUELLE.AUS'), ''); }
    $leer = function ($k) use ($c) { return trim((string) ($c[$k] ?? '')) === ''; };
    $pq = wd_prog_quellen();
    $grund = '';
    if ($n === 'pollen' && $leer('pollen_region')) { $grund = 'L_TEILREGION'; }
    elseif ($n === 'nina' && $leer('nina_ars')) { $grund = 'L_ARS'; }
    elseif ($n === 'meteoalarm' && $leer('ma_land')) { $grund = 'L_LAND'; }
    elseif (in_array($n, array('dwd', 'brightsky'), true) && $leer('warnzelle')) { $grund = 'L_ZELLE'; }
    elseif (isset($pq[$n]) && $pq[$n][1] !== '' && $n !== 'open_meteo' && $leer($pq[$n][1])) { $grund = 'L_SCHLUESSEL'; }
    elseif ((isset($pq[$n]) || in_array($n, array('luft', 'radar'), true)) && ($leer('lat') || $leer('lon'))) { $grund = 'L_STANDORT'; }
    elseif ($n === 'station') {
        $art = (string) ($c['station_art'] ?? 'weiche');
        if ($art === 'weiche' && !wd_st_vorhanden($c)) { $grund = 'L_WEICHE'; }
        elseif ($art === 'direkt' && $leer('station_host1') && $leer('station_host2')) { $grund = 'L_ADRESSE'; }
    }
    if ($grund !== '') { return array(wd_t('QUELLE.' . $grund), ''); }
    if (!empty($q['ok'])) { return array(wd_t('QUELLE.OK'), (string) ($q['meldung'] ?? '')); }
    if ($n === 'brightsky' && !empty($alle['dwd']['ok'])) { return array(wd_t('QUELLE.L_RUECKFALL'), wd_t('QUELLE.RUECKFALL_T')); }
    $me = (string) ($q['meldung'] ?? '');
    return array(wd_t($me === '' ? 'QUELLE.L_NOCH' : 'QUELLE.NICHT'), wd_quelle_klartext($n, $me, $c));
}

/** Fuer den Reiter Test: Ist das Token der Weiche lesbar? null = keine Weiche im Spiel. Das Token selbst geht nie hinaus. */
function wd_st_token_lage($c)
{
    if (empty($c['station_ein']) || (string) $c['station_art'] !== 'weiche' || !wd_st_vorhanden($c)) { return null; }
    return is_array(wd_json_lesen(wd_st_token_datei($c)));
}

/** Vergleichsform fuer die Gemeindesuche, ohne mbstring: Umlaute und Eszett ausgeschrieben, Kleinbuchstaben. */
function wd_such_form($s)
{
    return strtolower(strtr((string) $s, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss')));
}

/**
 * Amtliche Liste der Regionalschluessel (Statistisches Bundesamt, xRepository). Nur auf Knopfdruck, nie im Takt: erst die gueltige Fassung erfragen, dann die Datei.
 * Alte Fassungen verschwinden dort (die 2021er Adresse aus der NINA-Beschreibung antwortet am 05.11.2026 mit 404), deshalb keine feste Adresse.
 * Zeilen mit Hinweis (Stadt-/Ortsteile der Stadtstaaten) entfallen: Ihre ersten 5 Stellen sind kein Kreis. Rueckgabe array(Fassung, Zeilen) oder null.
 */
function wd_ars_liste()
{
    $f = wd_tmpdir() . '/ars_liste.json';
    $d = wd_json_lesen($f);
    if (is_array($d) && isset($d['ts'], $d['fassung'], $d['z']) && time() - (int) $d['ts'] < 86400) { return array((string) $d['fassung'], (array) $d['z']); }
    $u = 'https://www.xrepository.de/api/';
    $urn = 'urn:de:bund:destatis:bevoelkerungsstatistik:schluessel:rs';
    list($code, $b) = wd_http($u . 'codeliste/' . $urn . '/gueltigeVersion', 20);
    if ($code !== 200 || !preg_match('#<dat:version>(\d{4}-\d{2}-\d{2})</dat:version>#', (string) $b, $t)) { return null; }
    list($code, $b) = wd_http($u . 'xrepository/' . $urn . '_' . $t[1] . '/download/Regionalschl_ssel_' . $t[1] . '.json', 40);
    $j = $code === 200 ? json_decode((string) $b, true) : null;
    if (!is_array($j) || !isset($j['daten']) || !is_array($j['daten'])) { return null; }
    $z = array();
    foreach ($j['daten'] as $e) {
        if (!is_array($e) || !isset($e[0], $e[1]) || !is_string($e[0]) || !is_string($e[1]) || !preg_match('/^[0-9]{12}\z/', $e[0]) || !empty($e[2])) { continue; }
        $z[] = array($e[0], $e[1]);
    }
    if (count($z) < 1000) { return null; }     // Inhalt pruefen, nicht HTTP 200 glauben
    wd_json_schreiben($f, array('ts' => time(), 'fassung' => $t[1], 'z' => $z));
    return array($t[1], $z);
}

/** Gemeindesuche. Der Suchtext bleibt auf dem Geraet und in keinem Protokoll. Rueckgabe array('stand','such','treffer','mehr','fassung'). */
function wd_ars_suchen($such)
{
    $such = trim(substr(trim((string) $such), 0, 60));
    $o = array('stand' => 'ok', 'such' => $such, 'treffer' => array(), 'mehr' => 0, 'fassung' => '');
    $nf = wd_such_form($such);
    if (strlen($nf) < 2) { $o['stand'] = 'kurz'; return $o; }
    $l = wd_ars_liste();
    if ($l === null) { $o['stand'] = 'netz'; return $o; }
    $o['fassung'] = $l[0];
    $anf = array(); $rest = array();
    foreach ($l[1] as $e) {
        $p = strpos(wd_such_form($e[1]), $nf);
        if ($p === false) { continue; }
        if ($p === 0) { $anf[] = $e; } else { $rest[] = $e; }
    }
    $alle = array_merge($anf, $rest);
    $o['mehr'] = count($alle) > 40 ? 1 : 0;
    $o['treffer'] = array_slice($alle, 0, 40);
    if (!$alle) { $o['stand'] = 'leer'; }
    return $o;
}

/** EMMA-Kennungen aus dem Atom-Feed eines Landes. Der Feed nennt nur Regionen, fuer die gerade eine Warnung gilt. Rueckgabe Kennung => Name, oder null, wenn der Feed nicht zu lesen war. Nur auf Knopfdruck. */
function wd_ma_regionen($land)
{
    if (!preg_match('/^[a-z\-]{3,40}\z/', (string) $land)) { return null; }
    list($code, $b) = wd_http('https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-' . $land, 25);
    if ($code !== 200 || strpos((string) $b, '<feed') === false) { return null; }
    $o = array();
    if (preg_match_all('#<entry>(.*?)</entry>#s', (string) $b, $m)) {
        foreach ($m[1] as $e) {
            if (!preg_match('#<valueName>EMMA_ID</valueName>\s*<value>([A-Z0-9]{2,12})</value>#', $e, $t)) { continue; }
            $o[$t[1]] = preg_match('#<cap:areaDesc>(.*?)</cap:areaDesc>#s', $e, $a) ? wd_xml_wert($a[1]) : '';
        }
    }
    asort($o);
    return $o;
}
