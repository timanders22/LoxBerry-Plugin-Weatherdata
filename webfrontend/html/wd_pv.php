<?php
/**
 * Weatherdata 0.9.10 - PV-Prognose ohne Konto unter haus/pv/... (Regeln/07, Haus-Themen, Eintrag haus/pv).
 *
 * Quelle: Open-Meteo global_tilted_irradiance (Strahlung auf die geneigte Modulflaeche, W/m2, Mittel der VORANGEGANGENEN
 * Stunde) und temperature_2m, stuendlich, heute und morgen, EINE Anfrage je Flaeche und Stunde (bis 4 Flaechen = bis 96
 * Anfragen am Tag). Azimut wie Open-Meteo und azimut.polka-umwelt.de: 0 Sued, -90 Ost, 90 West.
 *
 * Rechnung je Stunde: W = GTI / 1000 x kWp x 1000 x Faktor x Waermebeiwert, Summe ueber die Flaechen, gekappt auf die
 * Hoechstleistung des Wechselrichters (0 = ohne Kappung). Waermebeiwert: Zelltemperatur = Luft + GTI / 800 x 25 K (NOCT 45 C),
 * Leistung -0,4 % je K ueber 25 C, begrenzt auf 0,80 bis 1,10; ohne Lufttemperatur 1. Energie = Summe der Stundenwerte.
 *
 * Ab Werk aus (Haken haus_pv_ein im Reiter Quellen, Abschnitt PV-Prognose); gesendet wird nur mit mqtt_ein, ueber dieselbe
 * kurze TCP-Sitzung wie haus/wetter (wd_haus_sitzung). Nur ein Anbieter je Haus. Werte aelter als 3 Stunden gelten nicht mehr:
 * dann gehen -1 und ok = 0 hinaus, nie ein alter Wert als frischer.
 *
 * Kompatibel mit PHP 7.4 bis 8.5.
 */

/** Hoechstalter eines Abrufs, bis zu dem die Werte gelten (s). Abruf jede Stunde: zwei Fehlschlaege in Folge sind verkraftet. */
function wd_pv_max_alter() { return 3 * 3600; }

/** Die eingetragenen Flaechen mit Leistung > 0, in der Reihenfolge 1 bis 4. */
function wd_pv_flaechen(array $c)
{
    $o = array();
    for ($i = 1; $i <= 4; $i++) {
        $kwp = isset($c['pv' . $i . '_kwp']) && is_numeric($c['pv' . $i . '_kwp']) ? (float) $c['pv' . $i . '_kwp'] : 0.0;
        if ($kwp <= 0) { continue; }
        $o[] = array('nr' => $i, 'name' => trim((string) ($c['pv' . $i . '_name'] ?? '')), 'kwp' => $kwp,
            'neigung' => (int) round((float) ($c['pv' . $i . '_neigung'] ?? 30)), 'az' => (int) round((float) ($c['pv' . $i . '_az'] ?? 0)));
    }
    return $o;
}

/** Kennung einer Flaeche fuer die abgerufenen Reihen: Neigung und Ausrichtung (die Leistung rechnet erst die Rechnung ein). */
function wd_pv_schluessel(array $f) { return (int) $f['neigung'] . '/' . (int) $f['az']; }

/** Fingerabdruck von Standort und Flaechengeometrie. Aendert er sich, gilt der alte Abruf nicht mehr und es wird neu geholt. */
function wd_pv_sig(array $c, array $fl)
{
    $pos = wd_position($c);
    $k = array();
    foreach ($fl as $f) { $k[] = wd_pv_schluessel($f); }
    $k = array_values(array_unique($k));
    sort($k);
    return md5(($pos === null ? '-' : $pos[0] . ',' . $pos[1]) . '|' . implode(';', $k));
}

/** Abrufadresse je Flaeche; mit Open-Meteo-Schluessel ueber customer-api (wie wd_om_url). Unix-Zeit: keine Sommerzeitfalle. */
function wd_pv_url(array $c, array $pos, $neigung, $az)
{
    $key = trim((string) ($c['om_apikey'] ?? ''));
    $u = ($key !== '' ? 'https://customer-api.open-meteo.com' : 'https://api.open-meteo.com') . '/v1/forecast?latitude=' . $pos[0] . '&longitude=' . $pos[1]
       . '&timezone=auto&forecast_days=2&timeformat=unixtime&hourly=global_tilted_irradiance,temperature_2m&tilt=' . (int) $neigung . '&azimuth=' . (int) $az;
    return $key !== '' ? $u . '&apikey=' . rawurlencode($key) : $u;
}

/** Antwort lesen. Rueckgabe Liste array(ts, gti|null, t|null) oder null, wenn sie nicht passt (Inhalt pruefen, nicht HTTP 200 glauben). */
function wd_pv_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['hourly']['time'], $d['hourly']['global_tilted_irradiance']) || !is_array($d['hourly']['time']) || !is_array($d['hourly']['global_tilted_irradiance'])) { return null; }
    $h = $d['hourly'];
    if (count($h['time']) < 24 || count($h['time']) !== count($h['global_tilted_irradiance'])) { return null; }
    $o = array(); $zahlen = 0;
    foreach ($h['time'] as $i => $t) {
        if (!is_int($t) && !(is_string($t) && ctype_digit($t))) { return null; }     // timeformat=unixtime: ganze Sekunden
        $g = $h['global_tilted_irradiance'][$i] ?? null;
        $g = (is_int($g) || is_float($g)) && is_finite((float) $g) && $g >= 0 && $g <= 1500 ? (float) $g : null;
        $tl = $h['temperature_2m'][$i] ?? null;
        $tl = (is_int($tl) || is_float($tl)) && is_finite((float) $tl) && $tl > -80 && $tl < 70 ? (float) $tl : null;
        if ($g !== null) { $zahlen++; }
        $o[] = array((int) $t, $g, $tl);
    }
    return $zahlen > 0 ? $o : null;
}

/**
 * Abruf im Minutenlauf (wd_mehr_holen). Nur mit haus_pv_ein, Open-Meteo an, Standort und mindestens einer Flaeche. Takt 60 min
 * mit der Bremse wd_faellig(); nach einer Aenderung von Flaechen oder Standort und auf Knopfdruck frueher, aber nie oefter als
 * alle 5 Minuten. Alle Flaechen eines Laufs gelingen zusammen oder der alte Abruf bleibt (er verfaellt nach 3 Stunden).
 */
function wd_pv_holen($c, $jetzt, $zwingen)
{
    if (empty($c['haus_pv_ein']) || empty($c['om_ein'])) { return; }
    $fl = wd_pv_flaechen($c);
    $pos = wd_position($c);
    if (!$fl || $pos === null) { return; }
    $m = wd_quelle_lesen('pv');
    $alt = wd_json_lesen(wd_datadir() . '/pv.json');
    $sig = wd_pv_sig($c, $fl);
    $anders = !is_array($alt) || (string) ($alt['sig'] ?? '') !== $sig;
    $frueh = ($anders || $zwingen) && $jetzt - (int) $m['versuch_ts'] >= 300;
    if (!$frueh && !wd_faellig($m, 60, $jetzt)) { return; }
    $m['versuch_ts'] = $jetzt;
    $reihen = array(); $code = 0; $ok = true;
    foreach ($fl as $f) {
        $k = wd_pv_schluessel($f);
        if (isset($reihen[$k])) { continue; }     // zwei Flaechen gleicher Lage: eine Anfrage
        list($code, $b) = wd_http(wd_pv_url($c, $pos, $f['neigung'], $f['az']), 20);
        $r = $code === 200 ? wd_pv_lesen($b) : null;
        if ($r === null) { $ok = false; break; }
        $reihen[$k] = $r;
    }
    if ($ok) {
        wd_json_schreiben(wd_datadir() . '/pv.json', array('ts' => $jetzt, 'sig' => $sig, 'reihen' => $reihen));
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1;
        $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
        wd_log_einmal('epv', 'PV-Prognose: ' . $m['meldung']);
    }
    wd_quelle_merken('pv', $m);
}

/** Waermebeiwert nach NOCT 45 C und -0,4 %/K; ohne Lufttemperatur 1. */
function wd_pv_waerme($gti, $luft)
{
    if ($luft === null || $gti === null) { return 1.0; }
    $zelle = (float) $luft + (float) $gti / 800 * 25;
    return max(0.80, min(1.10, 1 - 0.004 * ($zelle - 25)));
}

/**
 * Die Rechnung, ohne Netz und ohne Dateien (die Proben rufen sie direkt). $daten = Inhalt von pv.json, $fl = wd_pv_flaechen(),
 * $sig = erwarteter Fingerabdruck (null = nicht pruefen). Rueckgabe array mit ok (bool), grund (''|aus|keine|alt|aenderung),
 * stand, stunden (ts Ende der Stunde => W oder null), heute/morgen (array kwh, spitze_w, spitze_zeit oder null), jetzt_w,
 * naechste_w, rest_kwh, kwp. Ein Tag gilt nur vollstaendig (mindestens 23 Stundenwerte, kein Loch), sonst null.
 */
function wd_pv_rechnen($daten, array $fl, $faktor, $wr_max, $jetzt, $sig = null)
{
    $kwp = 0.0;
    foreach ($fl as $f) { $kwp += (float) $f['kwp']; }
    $aus = array('ok' => false, 'grund' => 'keine', 'stand' => null, 'stunden' => array(), 'heute' => null, 'morgen' => null, 'jetzt_w' => null, 'naechste_w' => null, 'rest_kwh' => null, 'kwp' => round($kwp, 2));
    if (!$fl) { $aus['grund'] = 'aus'; return $aus; }
    if (!is_array($daten) || !isset($daten['ts'], $daten['reihen']) || !is_array($daten['reihen'])) { return $aus; }
    if ($sig !== null && (string) ($daten['sig'] ?? '') !== (string) $sig) { $aus['grund'] = 'aenderung'; return $aus; }
    $alter = (int) $jetzt - (int) $daten['ts'];
    if ($alter < -300 || $alter > wd_pv_max_alter()) { $aus['grund'] = 'alt'; return $aus; }
    $faktor = (float) $faktor; $wr_max = (float) $wr_max;
    // Stunden aller Flaechen zusammenlegen; fehlt eine Flaeche oder ihr Wert, ist die Stunde ohne Aussage.
    $summe = array(); $erste = true;
    foreach ($fl as $f) {
        $k = wd_pv_schluessel($f);
        if (!isset($daten['reihen'][$k]) || !is_array($daten['reihen'][$k])) { $aus['grund'] = 'aenderung'; return $aus; }
        $diese = array();
        foreach ($daten['reihen'][$k] as $z) {
            if (!is_array($z) || !isset($z[0]) || !is_numeric($z[0])) { continue; }
            $ts = (int) $z[0];
            $g = (isset($z[1]) && is_numeric($z[1])) ? (float) $z[1] : null;
            $tl = (isset($z[2]) && is_numeric($z[2])) ? (float) $z[2] : null;
            $diese[$ts] = $g === null ? null : $g / 1000 * (float) $f['kwp'] * 1000 * $faktor * wd_pv_waerme($g, $tl);
        }
        if ($erste) { $summe = $diese; $erste = false; continue; }
        foreach ($summe as $ts => $w) { $summe[$ts] = ($w === null || !array_key_exists($ts, $diese) || $diese[$ts] === null) ? null : $w + $diese[$ts]; }
    }
    ksort($summe);
    foreach ($summe as $ts => $w) {
        if ($w === null) { continue; }
        if ($wr_max > 0) { $w = min($w, $wr_max); }
        $summe[$ts] = (int) round(max(0.0, $w));
    }
    $aus['stunden'] = $summe;
    $heute = date('Y-m-d', (int) $jetzt);
    $morgen = date('Y-m-d', mktime(12, 0, 0, (int) date('n', (int) $jetzt), (int) date('j', (int) $jetzt) + 1, (int) date('Y', (int) $jetzt)));
    $tag = function ($d) use ($summe) {
        $n = 0; $wh = 0; $sp = -1; $spz = null; $loch = false;
        foreach ($summe as $ts => $w) {
            if (date('Y-m-d', $ts - 1800) !== $d) { continue; }     // Wert um ts = Mittel der Stunde davor
            $n++;
            if ($w === null) { $loch = true; continue; }
            $wh += $w;
            if ($w > $sp) { $sp = $w; $spz = date('H:i', $ts - 3600); }
        }
        if ($loch || $n < 23) { return null; }
        return array('kwh' => round($wh / 1000, 2), 'spitze_w' => $sp, 'spitze_zeit' => $sp > 0 ? $spz : null);
    };
    $aus['heute'] = $tag($heute);
    $aus['morgen'] = $tag($morgen);
    foreach ($summe as $ts => $w) {
        if ($ts - 3600 <= $jetzt && $jetzt < $ts) {
            $aus['jetzt_w'] = $w;
            $aus['naechste_w'] = array_key_exists($ts + 3600, $summe) ? $summe[$ts + 3600] : null;
        }
    }
    if ($aus['heute'] !== null) {
        $r = 0.0;
        foreach ($summe as $ts => $w) {
            if ($ts <= $jetzt || date('Y-m-d', $ts - 1800) !== $heute) { continue; }
            $r += (float) $w * min(1.0, ($ts - $jetzt) / 3600);
        }
        $aus['rest_kwh'] = round($r / 1000, 2);
    }
    $aus['ok'] = $aus['heute'] !== null || $aus['morgen'] !== null || $aus['jetzt_w'] !== null;     // ein Loch heute nimmt morgen nicht mit
    $aus['grund'] = $aus['ok'] ? '' : 'keine';
    $aus['stand'] = (int) $daten['ts'];
    return $aus;
}

/** Die Rechnung mit der geltenden Einstellung und dem letzten Abruf (fuer Lauf, Oberflaeche und Reiter Test). */
function wd_pv_ergebnis(array $c, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $fl = wd_pv_flaechen($c);
    if (empty($c['haus_pv_ein'])) { $e = wd_pv_rechnen(null, array(), 1, 0, $jetzt); $e['grund'] = 'aus'; return $e; }
    return wd_pv_rechnen(wd_json_lesen(wd_datadir() . '/pv.json'), $fl, $c['pv_faktor'] ?? 0.85, $c['pv_wr_max_w'] ?? 0, $jetzt, wd_pv_sig($c, $fl));
}

/** Die eigenen Themen, Thema => retained. EINE Liste fuer Senden, Abraeumen, Oberflaeche, Reiter Test und Selbstpruefung. */
function wd_pv_themen()
{
    $t = array('haus/pv/datum' => true, 'haus/pv/stand' => true, 'haus/pv/kwp' => true, 'haus/pv/jetzt_w' => true, 'haus/pv/naechste_stunde_w' => true,
        'haus/pv/heute_kwh' => true, 'haus/pv/heute_rest_kwh' => true, 'haus/pv/morgen_kwh' => true,
        'haus/pv/spitze_heute_w' => true, 'haus/pv/spitze_heute_zeit' => true, 'haus/pv/spitze_morgen_w' => true, 'haus/pv/spitze_morgen_zeit' => true);
    $t['haus/pv/ok'] = false;
    $t['haus/pv/zaehler/weatherdata'] = false;
    return $t;
}

function wd_pv_retained() { return array_keys(array_filter(wd_pv_themen())); }

/** Werte aller Themen; ohne Aussage -1 (Zahlen) bzw. "-" (Uhrzeiten). Nie leer, nie ein alter Wert bei ok = 0. */
function wd_pv_werte(array $e, $zaehler, $jetzt)
{
    $w = array();
    foreach (wd_pv_themen() as $t => $r) { $w[$t] = preg_match('#_zeit$#', $t) ? '-' : '-1'; }
    $w['haus/pv/datum'] = date('Ymd', (int) $jetzt);
    $kw = function ($v) { return $v === null ? '-1' : number_format((float) $v, 2, '.', ''); };
    $ganz = function ($v) { return $v === null ? '-1' : (string) (int) $v; };
    if (!empty($e['ok'])) {
        $w['haus/pv/stand'] = (string) (int) $e['stand'];
        $w['haus/pv/kwp'] = $kw($e['kwp']);
        $w['haus/pv/jetzt_w'] = $ganz($e['jetzt_w']);
        $w['haus/pv/naechste_stunde_w'] = $ganz($e['naechste_w']);
        $w['haus/pv/heute_rest_kwh'] = $kw($e['rest_kwh']);
        foreach (array('heute', 'morgen') as $tg) {
            if (!is_array($e[$tg])) { continue; }
            $w['haus/pv/' . $tg . '_kwh'] = $kw($e[$tg]['kwh']);
            $w['haus/pv/spitze_' . $tg . '_w'] = $ganz($e[$tg]['spitze_w']);
            $w['haus/pv/spitze_' . $tg . '_zeit'] = $e[$tg]['spitze_zeit'] === null ? '-' : (string) $e[$tg]['spitze_zeit'];
        }
    } elseif (isset($e['kwp']) && (float) $e['kwp'] > 0) {
        $w['haus/pv/kwp'] = $kw($e['kwp']);
    }
    $w['haus/pv/ok'] = !empty($e['ok']) ? '1' : '0';
    $w['haus/pv/zaehler/weatherdata'] = (string) (((int) $zaehler % 1000 + 1000) % 1000);
    return $w;
}

/** Vollversand (ohne Merker, nach dem Einschalten, alle 30 min) oder nur Geaendertes; ok und zaehler immer. Thema => array(Wert, retain). */
function wd_pv_auswahl(array $werte, $merker, $voll)
{
    $th = wd_pv_themen();
    $aus = array();
    foreach ($werte as $t => $v) {
        if (empty($th[$t])) { continue; }
        if ($voll || !is_array($merker) || !array_key_exists($t, $merker) || (string) $merker[$t] !== (string) $v) { $aus[$t] = array((string) $v, true); }
    }
    foreach ($werte as $t => $v) { if (isset($th[$t]) && !$th[$t]) { $aus[$t] = array((string) $v, false); } }
    return $aus;
}

function wd_pv_merker_datei() { return wd_tmpdir() . '/pv_letzte.json'; }
function wd_pv_sitzung_datei() { return wd_tmpdir() . '/pv_sitzung.json'; }

/** Merker "war Anbieter von haus/pv" (_pv_frueher), mit Zweitschrift; der Unterstrich haelt ihn aus der Sicherung. */
function wd_pv_frueher_setzen($ja)
{
    $c = wd_config();
    if ((int) !empty($c['_pv_frueher']) === ($ja ? 1 : 0)) { return false; }
    if ($ja) { $c['_pv_frueher'] = 1; } else { unset($c['_pv_frueher']); }
    if (!wd_config_speichern($c)) { return false; }
    wd_zweitschrift($c);
    return true;
}

/** Abraeumen der retained haus/pv-Themen mit Ruecklesen (dieselbe Bauart wie haus/wetter). */
function wd_pv_leeren($runden = 3) { return wd_haus_leeren($runden, wd_pv_retained()); }

/** Im Minutenlauf nach haus/wetter. Nur mit haus_pv_ein UND mqtt_ein UND LoxBerry-Wurzel; sonst raeumt eine fruehere Anbieterin ab. */
function wd_pv_lauf(array $c, $zaehler, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $p = wd_paths();
    $mf = wd_pv_merker_datei();
    if (empty($c['haus_pv_ein']) || empty($c['mqtt_ein']) || $p['lbhome'] === '') {
        if (is_file($mf)) { @unlink($mf); }
        if (!empty($c['_pv_frueher']) && $p['lbhome'] !== '') {
            $vf = wd_tmpdir() . '/pv_leeren_versuch';
            clearstatcache(true, $vf);
            $alt = is_file($vf) ? (int) filemtime($vf) : 0;
            if ($alt <= 0 || $alt > time() || time() - $alt >= 600) {
                @touch($vf);
                $e = wd_pv_leeren();
                wd_log('PV-Prognose haus/pv: Abraeumen im Minutenlauf - ' . $e['lage'] . ($e['offen'] ? ' (' . count($e['offen']) . ' Themen offen)' : '') . '.');
                if (in_array($e['lage'], array('geleert', 'nichts'), true)) { wd_pv_frueher_setzen(false); }
            }
        }
        return null;
    }
    $erg = wd_pv_ergebnis($c, $jetzt);
    $werte = wd_pv_werte($erg, $zaehler, $jetzt);
    $vorher = null; $voll_zeit = 0;
    $d = wd_json_lesen($mf);
    if (is_array($d) && isset($d['werte'], $d['voll']) && is_array($d['werte'])) { $vorher = $d['werte']; $voll_zeit = (int) $d['voll']; }
    $voll = ($vorher === null || $voll_zeit <= 0 || $voll_zeit > time() || time() - $voll_zeit > 1800);
    $r = wd_haus_sitzung(wd_pv_auswahl($werte, $vorher, $voll));
    if ($r['lage'] === 'ok') {
        wd_json_schreiben($mf, array('werte' => array_intersect_key($werte, array_flip(wd_pv_retained())), 'voll' => $voll ? time() : $voll_zeit, 'zeit' => time()));
        if (empty($c['_pv_frueher'])) { wd_pv_frueher_setzen(true); }
    }
    wd_json_schreiben(wd_pv_sitzung_datei(), array('zeit' => time(), 'lage' => $r['lage'], 'gesendet' => (int) $r['gesendet'], 'ok' => (int) $werte['haus/pv/ok'], 'grund' => (string) $erg['grund']));
    wd_log_einmal('pv', $r['lage'] === 'ok' ? 'PV-Prognose haus/pv: Sitzung am Broker ok.'
        : 'PV-Prognose haus/pv: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute.');
    return $r;
}

/** Folgen einer Aenderung von haus_pv_ein/mqtt_ein (Abschnitt PV-Prognose, Reiter MQTT, Sicherung). Wie wd_haus_folgen(). */
function wd_pv_folgen(array $alt, array $neu)
{
    $war = !empty($alt['haus_pv_ein']) && !empty($alt['mqtt_ein']);
    $frueher = !empty($alt['_pv_frueher']);
    $ist = !empty($neu['haus_pv_ein']) && !empty($neu['mqtt_ein']);
    @unlink(wd_pv_merker_datei());
    if ($ist) { return array($war ? '' : wd_t('PV.M_EIN'), 'ok', null); }
    if (!$war && !$frueher) { return array('', 'ok', null); }
    $e = wd_pv_leeren();
    wd_log('PV-Prognose haus/pv: Abraeumen ' . $e['lage'] . ($e['offen'] ? ' (' . count($e['offen']) . ' Themen offen)' : '') . '.');
    if ($e['lage'] === 'geleert') { return array(sprintf(wd_t('PV.M_GELEERT'), count(wd_pv_retained())), 'ok', 0); }
    if ($e['lage'] === 'nichts') { return array(wd_t('PV.M_NICHTS'), 'ok', 0); }
    if ($e['lage'] === 'rest') { return array(sprintf(wd_t('PV.M_REST'), count($e['offen'])), 'warn', 1); }
    return array(wd_t('PV.M_KEIN_BROKER'), 'warn', 1);
}

/** Deinstallation und "wd_takt.php --haus-leeren": nur, wenn diese Installation Anbieterin von haus/pv ist oder war. Immer 0. */
function wd_pv_leeren_ausgabe()
{
    $c = wd_config();
    if (empty($c['haus_pv_ein']) && empty($c['_pv_frueher'])) {
        echo "<INFO> haus/pv: diese Installation war nie Anbieter - nichts zu leeren.\n";
        return 0;
    }
    $e = wd_pv_leeren();
    if ($e['lage'] === 'geleert') {
        echo "<OK> haus/pv: geleert (vom Broker bestaetigt, " . count(wd_pv_retained()) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo "<INFO> haus/pv: nichts zu leeren (der Broker haelt keines der Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo "<WARNING> haus/pv: noch zurueckbehalten - bitte von Hand leeren: " . implode(', ', $e['offen']) . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo "<WARNING> haus/pv: der Broker war nicht erreichbar (" . $e['grund'] . ") - bitte von Hand leeren: haus/pv/datum und die weiteren "
            . (count(wd_pv_retained()) - 1) . " Themen aus dem Abschnitt PV-Prognose.\n";
    }
    return 0;
}

/** Zahl fuer Menschen: Komma auf Deutsch, Punkt auf Englisch. */
function wd_pv_zahl($v, $nk = 1)
{
    if ($v === null || !is_numeric($v)) { return '-'; }
    return number_format((float) $v, $nk, wd_sprache() === 'en' ? '.' : ',', wd_sprache() === 'en' ? ',' : '.');
}

/** Zeile fuer den Reiter Test und den Abschnitt PV-Prognose: array(null = aus/wartet, true = in Ordnung, false = Stoerung, Text). */
function wd_pv_testzeile(array $c)
{
    if (empty($c['haus_pv_ein'])) { return array(null, wd_t('PV.T_AUS')); }
    $fl = wd_pv_flaechen($c);
    if (!$fl) { return array(false, wd_t('PV.T_KEINE_FLAECHE')); }
    if (wd_position($c) === null) { return array(false, wd_t('PV.T_STANDORT')); }
    if (empty($c['om_ein'])) { return array(false, wd_t('PV.T_OM_AUS')); }
    $kwp = 0.0; foreach ($fl as $f) { $kwp += $f['kwp']; }
    $kopf = sprintf(wd_t('PV.T_KOPF'), count($fl), wd_pv_zahl($kwp, 2));
    $m = wd_quelle_lesen('pv');
    $e = wd_pv_ergebnis($c);
    if (!$e['ok']) {
        if ((int) $m['versuch_ts'] <= 0 || $e['grund'] === 'aenderung') { return array(null, $kopf . ' ' . wd_t('PV.T_WARTET')); }
        $wann = (int) $m['ok_ts'] > 0 ? date('d.m. H:i', (int) $m['ok_ts']) : wd_t('PV.NIE');
        return array(false, $kopf . ' ' . sprintf(wd_t('PV.T_ALT'), $wann, (string) $m['meldung']));
    }
    $t = $kopf . ' ' . sprintf(wd_t('PV.T_WERTE'), date('H:i', (int) $e['stand']), is_array($e['heute']) ? wd_pv_zahl($e['heute']['kwh']) : '-', is_array($e['morgen']) ? wd_pv_zahl($e['morgen']['kwh']) : '-');
    if (empty($c['mqtt_ein'])) { return array(true, $t . ' ' . wd_t('PV.T_MQTT_AUS')); }
    $sz = wd_json_lesen(wd_pv_sitzung_datei());
    if (!is_array($sz) || !isset($sz['lage'], $sz['zeit'])) { return array(null, $t . ' ' . wd_t('PV.T_NIE')); }
    if ($sz['lage'] !== 'ok' || time() - (int) $sz['zeit'] > 150) {
        return array(false, $t . ' ' . sprintf(wd_t('PV.T_FEHL'), date('H:i:s', (int) $sz['zeit']), wd_t('HAUS.LAGE_' . strtoupper(preg_replace('/[^a-z_]/', '', (string) $sz['lage'])))));
    }
    return array(true, $t . ' ' . sprintf(wd_t('PV.T_GESENDET'), date('H:i:s', (int) $sz['zeit'])));
}

/** Selbstpruefung ohne Netz und Broker: eine feste Suedflaeche an einem festen Tag gegen die Themenliste. Rueckgabe array(ok, Hinweis). */
function wd_pv_vertrag_pruefen()
{
    $tag0 = mktime(0, 0, 0, 6, 1, 2030);
    $reihe = array();
    for ($h = 1; $h <= 48; $h++) {
        $ts = $tag0 + $h * 3600; $std = $h % 24;
        $reihe[] = array($ts, ($std >= 6 && $std <= 20) ? round(1000 * sin(M_PI * ($std - 6) / 14), 1) : 0.0, 0.0);     // Luft 0 C: Spitze 1000 W/m2 x 10 kWp x 0,85 x 0,975 = 8287 W, gekappt 7000
    }
    $fl = array(array('nr' => 1, 'name' => 'Sued', 'kwp' => 10.0, 'neigung' => 30, 'az' => 0));
    $daten = array('ts' => $tag0 + 10 * 3600, 'sig' => 'x', 'reihen' => array('30/0' => $reihe));
    $fehl = array();
    $e = wd_pv_rechnen($daten, $fl, 0.85, 7000, $tag0 + 10 * 3600 + 1800, 'x');
    $w = wd_pv_werte($e, 1234, $tag0 + 10 * 3600 + 1800);
    if (array_keys($w) !== array_keys(wd_pv_themen())) { $fehl[] = 'Themen'; }
    foreach ($w as $t => $v) { if ((string) $v === '' || preg_match('/[\r\n]/', (string) $v)) { $fehl[] = 'leer ' . $t; } }
    if ($w['haus/pv/spitze_heute_w'] !== '7000') { $fehl[] = 'Kappung ' . $w['haus/pv/spitze_heute_w']; }
    if ($w['haus/pv/ok'] !== '1' || $w['haus/pv/zaehler/weatherdata'] !== '234' || $w['haus/pv/datum'] !== '20300601') { $fehl[] = 'ok/zaehler/datum'; }
    $alt = wd_pv_werte(wd_pv_rechnen($daten, $fl, 0.85, 0, $tag0 + 20 * 3600, 'x'), 1, $tag0 + 20 * 3600);
    if ($alt['haus/pv/ok'] !== '0' || $alt['haus/pv/heute_kwh'] !== '-1' || $alt['haus/pv/stand'] !== '-1') { $fehl[] = 'alt'; }
    $n = count(wd_pv_retained());
    return array(!$fehl, $fehl ? implode(', ', array_slice($fehl, 0, 6)) : sprintf(wd_t('PV.VERTRAG_OK'), count(wd_pv_themen()), $n));
}
