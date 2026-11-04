<?php
/**
 * Weatherdata - reine Rechnungen, ohne Netz und ohne Dateien: gefuehlte Temperatur, Schwellen, Prognosefenster,
 * Warnregeln nach Tabelle 6.4 der Entscheidungsvorlage, fertige Saetze.
 *
 * Formeln selbst geschrieben nach den oeffentlichen Angaben des US National Weather Service:
 * Windchill-Formel 2001 (weather.gov/safety/cold-wind-chill-chart), Hitzeindex nach Rothfusz mit den zwei Korrekturen
 * und der vereinfachten Vorpruefung (wpc.ncep.noaa.gov/html/heatindex_equation.shtml). Das ist NICHT die Gefuehlte
 * Temperatur des DWD; darum heisst jede Meldung "Hitzeschwelle (eigene Rechnung)", nie "Hitzewarnung".
 */

/** Gefuehlte Temperatur. Rueckgabe array(Wert in C, 'windchill'|'hitzeindex'|'luft') oder array(null,'-'). */
function wd_gefuehlt($t_c, $rf, $wind_kmh)
{
    if ($t_c === null) { return array(null, '-'); }
    $t = (float) $t_c;
    $f = $t * 9 / 5 + 32;
    if ($wind_kmh !== null && $t <= 10.0 && (float) $wind_kmh * 0.621371 > 3.0) {
        $v = pow((float) $wind_kmh * 0.621371, 0.16);
        $wc = 35.74 + 0.6215 * $f - 35.75 * $v + 0.4275 * $f * $v;
        return array(round(($wc - 32) * 5 / 9, 1), 'windchill');
    }
    if ($rf !== null) {
        $r = (float) $rf;
        $einfach = 0.5 * ($f + 61.0 + ($f - 68.0) * 1.2 + $r * 0.094);
        if (($einfach + $f) / 2 >= 80.0) {
            $h = -42.379 + 2.04901523 * $f + 10.14333127 * $r - 0.22475541 * $f * $r - 0.00683783 * $f * $f
               - 0.05481717 * $r * $r + 0.00122874 * $f * $f * $r + 0.00085282 * $f * $r * $r - 0.00000199 * $f * $f * $r * $r;
            if ($r < 13 && $f >= 80 && $f <= 112) { $h -= ((13 - $r) / 4) * sqrt(max(0, (17 - abs($f - 95)) / 17)); }
            if ($r > 85 && $f >= 80 && $f <= 87) { $h += (($r - 85) / 10) * ((87 - $f) / 5); }
            return array(round(($h - 32) * 5 / 9, 1), 'hitzeindex');
        }
    }
    return array(round($t, 1), 'luft');
}

/** Taupunkt nach Magnus, nur als Rueckfall, wenn die Quelle keinen liefert. */
function wd_taupunkt($t, $rf)
{
    if ($t === null || $rf === null || (float) $rf <= 0) { return null; }
    $a = log((float) $rf / 100) + 17.625 * (float) $t / (243.04 + (float) $t);
    return round(243.04 * $a / (17.625 - $a), 1);
}

/** Schwelle mit Hysterese: an ab S, aus unter S-h, dazwischen bleibt der Stand. */
function wd_hysterese($wert, $schwelle, $h, $alt)
{
    if ($wert === null) { return null; }
    if ($wert >= $schwelle) { return 1; }
    if ($wert < $schwelle - $h) { return 0; }
    return $alt ? 1 : 0;
}

/**
 * Prognosefenster aus der stuendlichen Liste (Zeitpunkt, Wert je Groesse). $d = wd_prog_stunden().
 * Liefert die W4L-Ersatzwerte. Eine Groesse ohne Stunden im Fenster bleibt null und wird zu "-".
 */
function wd_prog_fenster(array $std, $jetzt)
{
    $aus = array();
    $fenster = function ($h) use ($std, $jetzt) {
        $l = array();
        foreach ($std as $z) { if ($z['ts'] >= $jetzt - 1800 && $z['ts'] < $jetzt + $h * 3600 - 1800) { $l[] = $z; } }
        return $l;
    };
    $sp = function ($l, $k) { $o = array(); foreach ($l as $z) { if (isset($z[$k]) && $z[$k] !== null) { $o[] = (float) $z[$k]; } } return $o; };
    $mi = function ($o) { return $o ? round(min($o), 1) : null; };
    $ma = function ($o) { return $o ? round(max($o), 1) : null; };
    $su = function ($o) { return $o ? round(array_sum($o), 1) : null; };
    $mw = function ($o) { return $o ? round(array_sum($o) / count($o), 1) : null; };
    foreach (array(1, 3) as $h) { $aus['h' . $h . '/regen_mm'] = $su($sp($fenster($h), 'regen')); }
    $aus['h4/solar_wm2'] = $mw($sp($fenster(4), 'solar'));
    $l = $fenster(12);
    $aus['h12/tmin_c'] = $mi($sp($l, 't_min') ?: $sp($l, 't')); $aus['h12/tmax_c'] = $ma($sp($l, 't_max') ?: $sp($l, 't')); $aus['h12/regen_mm'] = $su($sp($l, 'regen')); $aus['h12/regen_max_mm'] = $su($sp($l, 'regen_max'));
    $aus['h12/regen_w_pct'] = $ma($sp($l, 'regen_w')); $aus['h12/boe_max_kmh'] = $ma($sp($l, 'boe')); $aus['h12/solar_wm2'] = $mw($sp($l, 'solar'));
    $l = $fenster(24);
    $aus['h24/tmin_c'] = $mi($sp($l, 't_min') ?: $sp($l, 't')); $aus['h24/tmax_c'] = $ma($sp($l, 't_max') ?: $sp($l, 't')); $aus['h24/schnee_cm'] = $su($sp($l, 'schnee')); $aus['h24/solar_wm2'] = $mw($sp($l, 'solar'));
    $l = $fenster(48);
    $aus['h48/tmittel_c'] = $mw($sp($l, 't')); $aus['h48/solar_wm2'] = $mw($sp($l, 'solar'));
    return $aus;
}

/** Hoechste gefuehlte Temperatur der restlichen Stunden bis Mitternacht. Rueckgabe array(Wert, UV-Hoechstwert). */
function wd_prog_heute_rest(array $std, $jetzt)
{
    $ende = strtotime(date('Y-m-d', $jetzt) . ' 23:59:59');
    $mg = null; $ml = null; $uv = null;
    foreach ($std as $z) {
        if ($z['ts'] < $jetzt - 1800 || $z['ts'] > $ende) { continue; }
        list($g) = wd_gefuehlt($z['t_max'] ?? ($z['t'] ?? null), $z['rf'] ?? null, $z['wind'] ?? null);
        if ($g !== null && ($mg === null || $g > $mg)) { $mg = $g; }
        if (isset($z['t']) && $z['t'] !== null && ($ml === null || $z['t'] > $ml)) { $ml = (float) $z['t']; }
        if (isset($z['uv']) && $z['uv'] !== null && ($uv === null || $z['uv'] > $uv)) { $uv = (float) $z['uv']; }
    }
    return array($mg, $ml, $uv);
}

/** Wird es in der Nacht (bis 7 Uhr des Folgetags) draussen kaelter als $grenze? */
function wd_nacht_kuehler(array $std, $jetzt, $grenze)
{
    $ende = strtotime(date('Y-m-d', $jetzt + 86400) . ' 07:00:00');
    foreach ($std as $z) {
        if ($z['ts'] > $jetzt && $z['ts'] <= $ende && isset($z['t']) && $z['t'] !== null && (float) $z['t'] <= $grenze) { return true; }
    }
    return false;
}

/**
 * DWD-Warncodes (Ereigniscode II) => array(Klasse, Weckrecht in Stufe 3). Quelle: DWD-Warncodes (opendata).
 * Die Stufe kommt NICHT aus dieser Tabelle, sondern aus der amtlichen Schwere (Minor bis Extreme).
 * Falle: Hitze 248 hat technisch Stufe 3, UV 246 und Hitze 247 Stufe 1; deshalb entscheidet die Klasse mit.
 */
function wd_dwd_codes()
{
    return array(
        22 => array('frost', 0), 24 => array('glaette', 0), 31 => array('gewitter', 1), 33 => array('gewitter', 1), 34 => array('gewitter', 1),
        36 => array('gewitter', 1), 38 => array('gewitter', 1), 40 => array('gewitter', 1), 41 => array('gewitter', 1), 42 => array('gewitter', 1),
        44 => array('gewitter', 1), 45 => array('gewitter', 1), 46 => array('gewitter', 1), 48 => array('gewitter', 1), 49 => array('gewitter', 1),
        51 => array('wind', 0), 52 => array('wind', 0), 53 => array('wind', 0), 54 => array('wind', 1), 55 => array('wind', 1), 56 => array('wind', 1),
        57 => array('wind', 0), 58 => array('wind', 0), 59 => array('nebel', 0),
        61 => array('regen', 0), 62 => array('regen', 1), 63 => array('regen', 0), 64 => array('regen', 0), 65 => array('regen', 0), 66 => array('regen', 1),
        70 => array('schnee', 0), 71 => array('schnee', 0), 72 => array('schnee', 0), 73 => array('schnee', 0), 74 => array('schnee', 0), 75 => array('schnee', 0),
        76 => array('schnee', 0), 77 => array('schnee', 0), 78 => array('schnee', 0), 82 => array('frost', 0), 84 => array('glaette', 0), 85 => array('glaette', 0),
        87 => array('glaette', 0), 88 => array('tauwetter', 0), 89 => array('tauwetter', 0), 90 => array('gewitter', 1), 91 => array('gewitter', 1), 92 => array('gewitter', 1),
        93 => array('gewitter', 1), 95 => array('gewitter', 1), 96 => array('gewitter', 1), 246 => array('uv', 0), 247 => array('hitze', 0), 248 => array('hitze', 0),
    );
}

/** Klasse fuer einen unbekannten Code aus der CAP-Gruppe; so faellt eine neue Nummer nicht durch. */
function wd_klasse_aus_gruppe($gruppe)
{
    $t = array('FROST' => 'frost', 'WIND' => 'wind', 'THUNDERSTORM' => 'gewitter', 'RAIN' => 'regen', 'SNOWFALL' => 'schnee', 'ICE' => 'glaette', 'GLAZE' => 'glaette',
               'FOG' => 'nebel', 'HEAT' => 'hitze', 'UV' => 'uv', 'THAW' => 'tauwetter');
    $g = strtoupper(trim((string) $gruppe));
    return isset($t[$g]) ? $t[$g] : 'sonst';
}

function wd_stufe_aus_schwere($s)
{
    $t = array('minor' => 1, 'moderate' => 2, 'severe' => 3, 'extreme' => 4);
    $s = strtolower(trim((string) $s));
    return isset($t[$s]) ? $t[$s] : 0;
}

/** Filter und Zusammenfuehrung (Vorlage 6.3). Eingang: Liste von Lagen; Ausgang: je Klasse und ueberlappendem Zeitfenster die hoechste. */
function wd_lagen_ordnen(array $roh, $jetzt)
{
    $ersetzt = array();
    foreach ($roh as $l) { foreach ((array) ($l['verweise'] ?? array()) as $v) { $ersetzt[$v] = true; } }
    $o = array();
    foreach ($roh as $l) {
        if (strtolower((string) ($l['status'] ?? 'actual')) !== 'actual') { continue; }
        if (strtolower((string) ($l['msg'] ?? 'alert')) === 'cancel') { continue; }
        if (!empty($l['id']) && isset($ersetzt[$l['id']])) { continue; }
        $ii = (int) ($l['ii'] ?? 0);
        if ($ii === 98 || $ii === 99 || $ii === 79) { continue; }
        if (preg_match('/LEITERSEIL/i', (string) ($l['art'] ?? ''))) { continue; }
        if ((int) ($l['ende'] ?? 0) > 0 && (int) $l['ende'] <= $jetzt) { continue; }
        $codes = wd_dwd_codes();
        $l['klasse'] = isset($codes[$ii]) ? $codes[$ii][0] : wd_klasse_aus_gruppe($l['gruppe'] ?? '');
        $l['weckrecht'] = isset($codes[$ii]) ? (int) $codes[$ii][1] : 0;
        $o[] = $l;
    }
    usort($o, function ($a, $b) { return ($b['stufe'] <=> $a['stufe']) ?: ((int) $a['beginn'] <=> (int) $b['beginn']); });
    $aus = array();
    foreach ($o as $l) {
        $dopp = false;
        foreach ($aus as $x) {
            if ($x['klasse'] === $l['klasse'] && (int) $l['beginn'] <= (int) ($x['ende'] ?: PHP_INT_MAX) && (int) $x['beginn'] <= (int) ($l['ende'] ?: PHP_INT_MAX)) { $dopp = true; break; }
        }
        if (!$dopp) { $aus[] = $l; }
    }
    return $aus;
}

/**
 * Regel je Lage nach Tabelle 6.4. Rueckgabe array(tag, nacht):
 * tag: anzeige | einmal | dringend | tagesplan; nacht: still | morgens | morgens_dringend | weckt | nie.
 */
function wd_warn_regel(array $l)
{
    if (($l['quelle'] ?? '') === 'nina') {
        $mo = in_array((string) ($l['strom'] ?? ''), array('mow', 'mowas', 'kat', 'katwarn'), true) && (int) $l['stufe'] >= 3;
        return $mo ? array('dringend', 'weckt') : array('einmal', (int) $l['stufe'] >= 3 ? 'weckt' : 'still');
    }
    if ($l['klasse'] === 'hitze' || $l['klasse'] === 'uv') { return array('tagesplan', 'nie'); }
    $s = (int) $l['stufe'];
    if ($s >= 4) { return array('dringend', 'weckt'); }
    if ($s === 3) { return !empty($l['weckrecht']) ? array('dringend', 'weckt') : array('einmal', 'morgens_dringend'); }
    if (!empty($l['vorab'])) { return array('einmal', 'still'); }
    if ($s === 2) { return array('einmal', 'morgens'); }
    return array('anzeige', 'still');
}

/** Zeitraum als gesprochener Halbsatz: Tag, Beginn, Ende. Immer ganze Stunden und Minuten nur wenn noetig. */
function wd_zeitraum($beginn, $ende, $jetzt, $sp)
{
    $uhr = function ($ts) use ($sp) {
        $m = (int) date('i', $ts);
        return (int) date('G', $ts) . ($sp === 'de' ? ' Uhr' : ':00') . ($m > 0 ? ($sp === 'de' ? ' ' . $m : '') : '');
    };
    $tag = function ($ts) use ($jetzt, $sp) {
        $d = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d', $jetzt))) / 86400 + 0.5);
        if ($d === 0) { return wd_t('SATZ.HEUTE', $sp); }
        if ($d === 1) { return wd_t('SATZ.MORGEN', $sp); }
        return wd_t('SATZ.AM', $sp) . ' ' . date('d.m.', $ts);
    };
    $b = (int) $beginn; $e = (int) $ende;
    if ($b > $jetzt + 300) {
        $t = $tag($b) . ' ' . wd_t('SATZ.AB', $sp) . ' ' . $uhr($b);
        if ($e > 0) { $t .= (date('Ymd', $e) === date('Ymd', $b) ? ' ' : ' ' . wd_t('SATZ.BIS_TAG', $sp) . ' ' . $tag($e) . ' ') . wd_t('SATZ.BIS', $sp) . ' ' . $uhr($e); }
        return $t;
    }
    if ($e > 0) { return (date('Ymd', $e) === date('Ymd', $jetzt) ? wd_t('SATZ.HEUTE', $sp) . ' ' : $tag($e) . ' ') . wd_t('SATZ.BIS', $sp) . ' ' . $uhr($e); }
    return wd_t('SATZ.JETZT', $sp);
}

/** Name der Warnung fuer Ansage und Anzeige: aus der Sprachdatei je Code, sonst die Klasse. Der CAP-Wortlaut geht in den Texteingang. */
function wd_warn_name(array $l, $sp)
{
    $n = wd_t('WARN.II_' . (int) ($l['ii'] ?? 0), $sp);
    if (strpos($n, 'WARN.') === 0) { $n = wd_t('WARN.KL_' . strtoupper($l['klasse']), $sp); }
    return $n;
}

/** Fertiger Satz. Kurz, Klasse, Zeitraum, Quelle (Vorlage 6.4). */
function wd_warn_satz(array $l, $jetzt, $sp)
{
    $q = ($l['quelle'] ?? 'dwd') === 'brightsky' ? wd_t('SATZ.QUELLE_BS', $sp) : wd_t('SATZ.QUELLE_DWD', $sp);
    $v = !empty($l['vorab']) ? wd_t('SATZ.VORAB', $sp) . ' ' : '';
    return $v . sprintf(wd_t('SATZ.WARNUNG', $sp), wd_warn_name($l, $sp), wd_zeitraum($l['beginn'], $l['ende'], $jetzt, $sp), $q);
}

/**
 * Ansageplan. Entscheidet NUR, welche Saetze jetzt faellig sind; ob gesprochen wird, entscheiden die Regeln im Stamm
 * (Nachtruhe, Sperre, Schalter "Ansagen aus"). Reine Funktion: $stand wird neu zurueckgegeben, sobald der Aufrufer weiss, was
 * der Stamm getan hat (wd_ansage_gesprochen()).
 * $tag = array('nacht'=>bool, 'morgen'=>bool (Fenster), 'mittag'=>bool, 'abend'=>bool, 'heute'=>Ymd)
 */
function wd_ansage_plan(array $st, array $c, array $stand, array $tag, $jetzt)
{
    $sp = (string) $c['ansage_sprache'];
    $plan = array();
    $lagen = isset($st['lagen']) && is_array($st['lagen']) ? $st['lagen'] : array();
    if (!empty($c['a_warn'])) {
        foreach ($lagen as $l) {
            list($am_tag, $nachts) = wd_warn_regel($l);
            if ($am_tag === 'tagesplan') { continue; }
            $sl = 'warn_' . $l['klasse'] . '_' . (int) $l['stufe'];
            $gesagt = 0;
            foreach ((array) ($stand['lagen'] ?? array()) as $m) {
                if ($m['klasse'] === $l['klasse'] && (int) $l['beginn'] <= (int) ($m['ende'] ?: PHP_INT_MAX) && (int) $m['beginn'] <= (int) ($l['ende'] ?: PHP_INT_MAX)) { $gesagt = max($gesagt, (int) $m['stufe']); }
            }
            if ($gesagt >= (int) $l['stufe']) { continue; }
            if ($am_tag === 'anzeige') {
                $h = 's1_' . $l['klasse'];
                if (empty($c[$h]) || !empty($tag['nacht'])) { continue; }
                $d = false;
            } elseif (!empty($tag['nacht'])) {
                if ($nachts !== 'weckt') { continue; }
                $d = true;
            } else {
                $d = $am_tag === 'dringend' || (!empty($tag['frueh']) && $nachts === 'morgens_dringend');
            }
            $plan[] = array('schluessel' => $sl, 'text' => wd_warn_satz($l, $jetzt, $sp), 'dringend' => $d, 'lage' => $l);
        }
    }
    // NINA: Wortlaut unveraendert, eigene Lage. Nachts nur, was weckt. Eigener Haken.
    if (!empty($c['a_nina'])) {
        foreach ((array) ($st['nina'] ?? array()) as $l) {
            list($am_tag, $nachts) = wd_warn_regel($l);
            $sl = 'nina_' . substr(md5((string) $l['id']), 0, 8) . '_' . (int) $l['stufe'];
            if (in_array($sl, (array) ($stand['nina'] ?? array()), true)) { continue; }
            if (!empty($tag['nacht']) && $nachts !== 'weckt') { continue; }
            $plan[] = array('schluessel' => $sl, 'text' => (string) $l['titel'], 'dringend' => $am_tag === 'dringend' || !empty($tag['nacht']), 'nina' => $sl);
        }
    }
    $heute = (string) $tag['heute'];
    $plan_tag = function ($sl, $text) use (&$plan, $stand, $heute, $tag) {
        foreach (array('morgen', 'mittag') as $f) {
            if (!empty($tag[$f]) && (($stand['tag'][$sl . '_' . $f] ?? '') !== $heute)) {
                $plan[] = array('schluessel' => $sl, 'text' => $text, 'dringend' => false, 'marke' => $sl . '_' . $f);
                return;
            }
        }
    };
    if (!empty($tag['nacht'])) { return $plan; }
    // amtliche Hitze und UV, eigene Hitzeschwellen: morgens und einmal am Mittag
    if (!empty($c['a_hitze'])) {
        foreach ($lagen as $l) {
            if ($l['klasse'] === 'hitze') { $plan_tag('hitze_amtlich', wd_warn_satz($l, $jetzt, $sp)); break; }
        }
        $sw = (int) ($st['hitze_stufe'] ?? 0);
        if ($sw > 0) {
            $g = $st['hitze_max'] ?? null;
            $wert = (int) $c['hitze' . $sw . '_c'];
            $plan_tag('hitze_eigen', sprintf(wd_t('SATZ.HITZE', $sp), $wert, $g === null ? '-' : round($g)));
        }
    }
    if (!empty($c['a_uv'])) {
        foreach ($lagen as $l) {
            if ($l['klasse'] === 'uv') { $plan_tag('uv_amtlich', wd_warn_satz($l, $jetzt, $sp)); break; }
        }
        if (!empty($st['uv_hoch'])) { $plan_tag('uv_eigen', sprintf(wd_t('SATZ.UV', $sp), round((float) ($st['uv_max'] ?? 0)))); }
    }
    // Briefing: nur Pollen der Haken und Luft, morgens.
    if (!empty($c['a_briefing']) && !empty($tag['morgen']) && (($stand['tag']['briefing'] ?? '') !== $heute)) {
        $b = function_exists('wd_briefing') ? wd_briefing($st, $c, $sp) : '';
        if ($b !== '') { $plan[] = array('schluessel' => 'briefing', 'text' => $b, 'dringend' => false, 'marke' => 'briefing'); }
    }
    // Frost: abends. Starkregen und Sturm eigene Schwellen: einmal am Tag, sobald erreicht.
    if (!empty($c['a_frost']) && !empty($st['frost_erwartet']) && !empty($tag['abend']) && (($stand['tag']['frost'] ?? '') !== $heute)) {
        $plan[] = array('schluessel' => 'frost', 'text' => sprintf(wd_t('SATZ.FROST', $sp), (string) ($st['frost_wert'] ?? '-')), 'dringend' => false, 'marke' => 'frost');
    }
    if (!empty($c['a_starkregen']) && !empty($st['schwelle_regen']) && (($stand['tag']['starkregen'] ?? '') !== $heute)) {
        $plan[] = array('schluessel' => 'starkregen', 'text' => wd_t('SATZ.STARKREGEN', $sp), 'dringend' => false, 'marke' => 'starkregen');
    }
    if (!empty($c['a_sturm']) && !empty($st['schwelle_sturm']) && (($stand['tag']['sturm'] ?? '') !== $heute)) {
        $plan[] = array('schluessel' => 'sturm', 'text' => wd_t('SATZ.STURM', $sp), 'dringend' => false, 'marke' => 'sturm');
    }
    // Lueften: an einem Hitzetag, sobald aussen kuehler; spaetestens kurz vor der Nachtruhe als Hinweis, wenn es erst in der Nacht kuehler wird.
    if (!empty($c['a_lueften']) && !empty($st['hitze_heute']) && (($stand['tag']['lueften'] ?? '') !== $heute)) {
        if (!empty($st['kuehlen_moeglich'])) {
            $plan[] = array('schluessel' => 'lueften', 'text' => wd_t('SATZ.LUEFTEN', $sp), 'dringend' => false, 'marke' => 'lueften');
        } elseif (!empty($tag['letzte']) && !empty($st['nacht_kuehler'])) {
            $plan[] = array('schluessel' => 'lueften', 'text' => wd_t('SATZ.LUEFTEN_NACHT', $sp), 'dringend' => false, 'marke' => 'lueften');
        }
    }
    return $plan;
}

/** Nach dem Stamm: Was gesprochen wurde, merken. $einzeln = Ergebnis je Schluessel ('' = gesprochen). Abgelaufene Lagen fallen weg. */
function wd_ansage_gesprochen(array $stand, array $plan, array $einzeln, $heute, $jetzt)
{
    $stand += array('lagen' => array(), 'tag' => array());
    foreach ($plan as $e) {
        $g = isset($einzeln[$e['schluessel']]) ? $einzeln[$e['schluessel']] : 'X';
        if ($g !== '') { continue; }
        if (isset($e['lage'])) {
            $stand['lagen'][] = array('klasse' => $e['lage']['klasse'], 'stufe' => (int) $e['lage']['stufe'], 'beginn' => (int) $e['lage']['beginn'], 'ende' => (int) $e['lage']['ende']);
        }
        if (isset($e['marke'])) { $stand['tag'][$e['marke']] = (string) $heute; }
        if (isset($e['nina'])) { $stand['nina'][] = $e['nina']; $stand['nina'] = array_slice($stand['nina'], -50); }
    }
    $rest = array();
    foreach ($stand['lagen'] as $m) { if ((int) $m['ende'] === 0 ? (int) $m['beginn'] > $jetzt - 172800 : (int) $m['ende'] > $jetzt) { $rest[] = $m; } }
    $stand['lagen'] = $rest;
    return $stand;
}
