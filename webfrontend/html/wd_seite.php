<?php
/**
 * Weatherdata 0.9.7 - Wetterseite fuer den Webseiten-Baustein (Webpage) der Loxone-App. Reines PHP, HTML und CSS, kein Skript, nichts von aussen.
 * Liest nur den Zustand des Minutenlaufs. Nennt keinen Standort. Fehlende Werte sind ein Strich, nichts wird erfunden.
 * Designs sind eigene CSS-Variablen; Symbolsets liegen unter symbole/<set>/ mit Lizenz und Quelle (README, Quellen und Lizenzen).
 */

/** Die 21 Bildnamen, die jedes Set liefern muss. */
function wd_seite_bildnamen()
{
    return array('klar_tag', 'klar_nacht', 'heiter_tag', 'heiter_nacht', 'wolkig_tag', 'wolkig_nacht', 'bedeckt', 'nebel', 'niesel', 'regen', 'starkregen',
        'gefrierend', 'schnee', 'starkschnee', 'schauer_tag', 'schauer_nacht', 'schneeschauer_tag', 'schneeschauer_nacht', 'gewitter', 'gewitter_hagel', 'unbekannt');
}

/** Symbolsets: Ordner = Schluessel. Name, Lizenz, Quelle fuer Fusszeile und Reiter. bunt: das Set bringt eigene Farben mit. */
function wd_seite_sets()
{
    return array(
        'meteocons' => array('name' => 'Meteocons', 'lizenz' => 'MIT', 'url' => 'https://github.com/basmilius/meteocons', 'bunt' => true),
        'klar' => array('name' => 'Klar', 'lizenz' => 'MIT', 'url' => '', 'bunt' => true),
        'phosphor' => array('name' => 'Phosphor', 'lizenz' => 'MIT', 'url' => 'https://github.com/phosphor-icons/core', 'bunt' => false),
        'bootstrap' => array('name' => 'Bootstrap Icons', 'lizenz' => 'MIT', 'url' => 'https://github.com/twbs/icons', 'bunt' => false),
    );
}

/**
 * Designs: je ein Satz Farben fuer hell und dunkel. Reihenfolge: Hintergrund, Karte, Schrift, leise Schrift, Linie, Akzent, Symbolfarbe, Kurve, Regenbalken, Kopf oben, Kopf unten.
 * Jeder Satz ist im Pruefstand gegen WCAG gerechnet: Schrift und leise Schrift auf Hintergrund, Karte und Kopf mindestens 4,5:1.
 */
function wd_seite_themes()
{
    $s = function ($bg, $ka, $tx, $le, $li, $ak, $sy, $ku, $ba, $k1, $k2) {
        return array('bg' => $bg, 'karte' => $ka, 'text' => $tx, 'leise' => $le, 'linie' => $li, 'akzent' => $ak, 'sym' => $sy, 'kurve' => $ku, 'balken' => $ba, 'kopf1' => $k1, 'kopf2' => $k2);
    };
    return array(
        'frisch' => array('hell' => $s('#eef3f7', '#ffffff', '#1c2733', '#4f5d6b', '#d8e1ea', '#1667b0', '#2b6cb0', '#c2410c', '#3b82c4', '#d6e7fb', '#f4f8fc'),
                          'dunkel' => $s('#0e1621', '#17222f', '#e8eef5', '#a9b6c4', '#2a3a4c', '#6cb4ff', '#9cc9ff', '#ffa94d', '#5aa9f0', '#1d3a5c', '#17222f')),
        'garten' => array('hell' => $s('#f1f6ea', '#ffffff', '#1f2a17', '#4e5a44', '#d6e3c6', '#3f6a10', '#4f7d17', '#c2410c', '#2f80c0', '#e1efcf', '#f8fbf3'),
                          'dunkel' => $s('#111a0c', '#1a2613', '#eaf2e1', '#b0bfa2', '#2f4224', '#9ccc65', '#a8d672', '#ffb066', '#63a8e6', '#28401c', '#1a2613')),
        'sand' => array('hell' => $s('#faf5ec', '#fffdf8', '#2d2418', '#66563f', '#eadfcb', '#9a4a07', '#b45309', '#c2410c', '#2f74b5', '#fbe6c6', '#fffaf2'),
                        'dunkel' => $s('#1b150d', '#261e14', '#f6eee1', '#c8b79c', '#3d3122', '#f2b366', '#f2c48a', '#ffb066', '#6aaee8', '#3b2a14', '#261e14')),
        'schiefer' => array('hell' => $s('#f4f4f5', '#ffffff', '#18181b', '#52525b', '#e4e4e7', '#3f3f46', '#52525b', '#18181b', '#4b7bb5', '#ffffff', '#ffffff'),
                            'dunkel' => $s('#09090b', '#18181b', '#fafafa', '#a1a1aa', '#27272a', '#d4d4d8', '#d4d4d8', '#fafafa', '#7aa6d8', '#18181b', '#18181b')),
        'abend' => array('hell' => $s('#f3f1fa', '#ffffff', '#221d33', '#575170', '#e1ddef', '#5b3fb5', '#5b3fb5', '#c2410c', '#3b7fc4', '#e6defb', '#f8f6fd'),
                         'dunkel' => $s('#120f1f', '#1d1830', '#efecf8', '#b5aecd', '#332b4d', '#b39dff', '#c2b1ff', '#ffb066', '#6aaee8', '#2d2152', '#1d1830')),
    );
}

function wd_seite_vorgabe() { return array('mode' => 'auto', 'theme' => 'frisch', 'iconset' => 'meteocons'); }

/** Eingaben der Adresse. Nur Zeichenketten aus der Liste; alles andere faellt still auf die Vorgabe. Nichts davon wird zurueckgegeben, was nicht in der Liste steht. */
function wd_seite_wahl(array $get)
{
    $v = wd_seite_vorgabe();
    $w = array('mode' => $v['mode'], 'lang' => wd_sprache(), 'theme' => $v['theme'], 'iconset' => $v['iconset']);
    $erlaubt = array('mode' => array('auto', 'light', 'dark'), 'lang' => array('de', 'en'), 'theme' => array_keys(wd_seite_themes()), 'iconset' => array_keys(wd_seite_sets()));
    foreach ($erlaubt as $k => $li) {
        if (isset($get[$k]) && is_string($get[$k]) && strlen($get[$k]) <= 20 && in_array($get[$k], $li, true)) { $w[$k] = $get[$k]; }
    }
    return $w;
}

/** WMO-Wettercode (Open-Meteo, wie im Emulator) auf Bildname ohne Tageszeit. */
function wd_seite_wmo()
{
    return array(0 => 'klar', 1 => 'heiter', 2 => 'wolkig', 3 => 'bedeckt', 45 => 'nebel', 48 => 'nebel', 51 => 'niesel', 53 => 'niesel', 55 => 'niesel', 56 => 'gefrierend', 57 => 'gefrierend',
        61 => 'regen', 63 => 'regen', 65 => 'starkregen', 66 => 'gefrierend', 67 => 'gefrierend', 71 => 'schnee', 73 => 'schnee', 75 => 'starkschnee', 77 => 'schnee',
        80 => 'schauer', 81 => 'schauer', 82 => 'starkregen', 85 => 'schneeschauer', 86 => 'schneeschauer', 95 => 'gewitter', 96 => 'gewitter_hagel', 99 => 'gewitter_hagel');
}

/**
 * Bild und Text fuer einen Wettercode. Rueckgabe array(Bildname, Sprachschluessel).
 * Unbekannter oder fehlender Code: aus Regen und Schnee, wie im Emulator; ohne beides das Bild "unbekannt", nie "klar".
 */
function wd_seite_symbol($code, $regen = null, $schnee = null, $nacht = false)
{
    $t = wd_seite_wmo();
    $c = (is_int($code) || is_float($code) || (is_string($code) && preg_match('/^[0-9]{1,3}(\.0+)?\z/', $code))) ? (float) $code : null;
    if ($c !== null && is_finite($c) && $c >= 0 && $c < 1000 && floor($c) == $c && isset($t[(int) $c])) {
        $b = $t[(int) $c]; $txt = 'SEITE.C_' . (int) $c;
    } else {
        $r = is_numeric($regen) ? (float) $regen : null; $s = is_numeric($schnee) ? (float) $schnee : null;
        if ($s !== null && $s > 0) { $b = 'schnee'; }
        elseif ($r !== null && $r >= 4) { $b = 'starkregen'; }
        elseif ($r !== null && $r >= 1) { $b = 'regen'; }
        elseif ($r !== null && $r > 0) { $b = 'niesel'; }
        else { $b = 'unbekannt'; }
        $txt = 'SEITE.F_' . strtoupper($b);
    }
    if (in_array($b, array('klar', 'heiter', 'wolkig', 'schauer', 'schneeschauer'), true)) { $b .= $nacht ? '_nacht' : '_tag'; }
    return array($b, $txt);
}

/** Zahl oder null; Zeichenketten aus dem Zustand nur, wenn sie ganz Zahl sind. */
function wd_seite_n($v)
{
    if (is_int($v) || is_float($v)) { return is_finite((float) $v) ? (float) $v : null; }
    if (is_string($v) && is_numeric($v)) { return (float) $v; }
    return null;
}

/**
 * Im Minutenlauf: Stunden und Tage fuer die Seite, ohne Standort. Codes nur von Open-Meteo, solange es lebt (Codes werden nie gemittelt).
 * std: hoechstens 48 Stunden ab der laufenden. tage: hoechstens 7 ab heute; Summen und Spitzen aus den Stunden desselben Tags.
 */
function wd_seite_daten(array $std, array $om_std, array $om_tage, $jetzt)
{
    $ab = (int) $jetzt - (int) $jetzt % 3600;
    $code = array();
    foreach ($om_std as $z) { if (is_array($z) && isset($z['ts'])) { $code[(int) $z['ts'] - (int) $z['ts'] % 3600] = $z['code'] ?? null; } }
    $r1 = function ($v, $n = 1) { $v = wd_seite_n($v); return $v === null ? null : round($v, $n); };
    $aus = array(); $tag = array();
    $heute = date('Y-m-d', (int) $jetzt);
    foreach ($std as $z) {
        if (!is_array($z) || !isset($z['ts'])) { continue; }
        $ts = (int) $z['ts'];
        $d = date('Y-m-d', $ts);
        if ($ts >= $ab && $d >= $heute) {
            $tg = &$tag[$d];
            if (!is_array($tg)) { $tg = array('tmin' => null, 'tmax' => null, 'r' => null, 's' => null, 'rw' => null, 'b' => null, 'uv' => null); }
            $t = wd_seite_n($z['t'] ?? null);
            if ($t !== null) { $tg['tmin'] = $tg['tmin'] === null ? $t : min($tg['tmin'], $t); $tg['tmax'] = $tg['tmax'] === null ? $t : max($tg['tmax'], $t); }
            foreach (array('r' => 'regen', 's' => 'schnee') as $k => $q) { $v = wd_seite_n($z[$q] ?? null); if ($v !== null) { $tg[$k] = (float) $tg[$k] + $v; } }
            foreach (array('rw' => 'regen_w', 'b' => 'boe', 'uv' => 'uv') as $k => $q) { $v = wd_seite_n($z[$q] ?? null); if ($v !== null) { $tg[$k] = $tg[$k] === null ? $v : max($tg[$k], $v); } }
            unset($tg);
        }
        if ($ts < $ab || count($aus) >= 48 || wd_seite_n($z['t'] ?? null) === null) { continue; }
        $aus[] = array('ts' => $ts, 't' => $r1($z['t'] ?? null), 'r' => $r1($z['regen'] ?? null), 'rw' => $r1($z['regen_w'] ?? null, 0), 's' => $r1($z['schnee'] ?? null),
            'w' => $r1($z['wind'] ?? null, 0), 'b' => $r1($z['boe'] ?? null, 0), 'sol' => $r1($z['solar'] ?? null, 0), 'uv' => $r1($z['uv'] ?? null), 'c' => isset($code[$ts]) ? wd_seite_n($code[$ts]) : null);
    }
    $omt = array();
    foreach ($om_tage as $t) { if (is_array($t) && isset($t['datum']) && is_string($t['datum'])) { $omt[$t['datum']] = $t; } }
    $tage = array();
    foreach ($tag as $d => $tg) {
        if (count($tage) >= 7) { break; }
        $o = $omt[$d] ?? array();
        $tage[] = array('datum' => $d, 'tmin' => wd_seite_n($o['tmin'] ?? null) ?? ($tg['tmin'] === null ? null : round($tg['tmin'], 1)), 'tmax' => wd_seite_n($o['tmax'] ?? null) ?? ($tg['tmax'] === null ? null : round($tg['tmax'], 1)),
            'c' => wd_seite_n($o['code'] ?? null), 'r' => $tg['r'] === null ? null : round($tg['r'], 1), 's' => $tg['s'] === null ? null : round($tg['s'], 1), 'rw' => $tg['rw'], 'b' => $tg['b'], 'uv' => $tg['uv']);
    }
    return array('std' => $aus, 'tage' => $tage);
}

/** Zahl fuer die Anzeige. null: Strich. de mit Komma. */
function wd_seite_zahl($v, $nk, $sp, $einheit = '')
{
    $v = wd_seite_n($v);
    if ($v === null) { return '&ndash;'; }
    $s = number_format($v, $nk, $sp === 'de' ? ',' : '.', $sp === 'de' ? '.' : ',');
    if ($nk > 0 && preg_match('/^-0[,.]0+\z/', $s)) { $s = substr($s, 1); }
    if ($nk === 0 && $s === '-0') { $s = '0'; }
    return wd_e($s) . ($einheit !== '' ? '&nbsp;' . $einheit : '');
}

/** CSS-Block der Variablen fuer das gewaehlte Design und den Modus. */
function wd_seite_css_vars($theme, $mode)
{
    $th = wd_seite_themes(); $t = isset($th[$theme]) ? $th[$theme] : $th['frisch'];
    $satz = function ($v) { $o = ''; foreach ($v as $k => $w) { $o .= '--' . $k . ':' . $w . ';'; } return $o; };
    $hell = $satz($t['hell']); $dunkel = $satz($t['dunkel']);
    if ($mode === 'light') { return ':root{' . $hell . 'color-scheme:light}'; }
    if ($mode === 'dark') { return ':root{' . $dunkel . 'color-scheme:dark}'; }
    return ':root{' . $hell . 'color-scheme:light dark}@media (prefers-color-scheme: dark){:root{' . $dunkel . '}}';
}

/** Bildersammlung fuer die benutzten Bilder. Jedes Bild steht einmal als <symbol>, die Seite zeigt es mit <use>. Fehlt eine Datei, bleibt die Stelle leer. */
function wd_seite_sprite($set, array $namen, $ordner = null)
{
    $ordner = $ordner === null ? __DIR__ . '/symbole' : $ordner;
    if (!isset(wd_seite_sets()[$set])) { return ''; }
    $o = '';
    foreach (array_unique($namen) as $n) {
        if (!in_array($n, wd_seite_bildnamen(), true)) { continue; }
        $roh = @file_get_contents($ordner . '/' . $set . '/' . $n . '.svg');
        if (!is_string($roh) || !preg_match('#^\s*<svg([^>]*)>(.*)</svg>\s*\z#s', $roh, $m)) { continue; }
        $vb = preg_match('/viewBox="([0-9. -]+)"/', $m[1], $x) ? $x[1] : '0 0 64 64';
        $fi = preg_match('/\sfill="([a-zA-Z#0-9]+)"/', $m[1], $x) ? ' fill="' . $x[1] . '"' : '';
        $o .= '<symbol id="s-' . $n . '" viewBox="' . $vb . '"' . $fi . '>' . $m[2] . '</symbol>';
    }
    return $o === '' ? '' : '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">' . $o . '</svg>';
}

/** Himmelsrichtung aus Grad, 8 Teile. */
function wd_seite_richtung($g, $sp)
{
    $g = wd_seite_n($g);
    if ($g === null) { return ''; }
    $de = array('N', 'NO', 'O', 'SO', 'S', 'SW', 'W', 'NW'); $en = array('N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW');
    $i = (int) floor(fmod(fmod($g, 360) + 360 + 22.5, 360) / 45) % 8;
    return $sp === 'de' ? $de[$i] : $en[$i];
}

/** Europaeischer Luftindex (EAQI) in die sechs Stufen der EEA, so wie Open-Meteo sie nennt. */
function wd_seite_luft_stufe($i)
{
    $i = wd_seite_n($i);
    if ($i === null) { return null; }
    foreach (array(20 => 1, 40 => 2, 60 => 3, 80 => 4, 100 => 5) as $g => $s) { if ($i <= $g) { return $s; } }
    return 6;
}

/** UV-Stufe nach WHO: 1 niedrig (0-2), 2 maessig (3-5), 3 hoch (6-7), 4 sehr hoch (8-10), 5 extrem (11+). */
function wd_seite_uv_stufe($u)
{
    $u = wd_seite_n($u);
    if ($u === null) { return null; }
    $u = round($u);
    return $u <= 2 ? 1 : ($u <= 5 ? 2 : ($u <= 7 ? 3 : ($u <= 10 ? 4 : 5)));
}

/** Zeitraum einer Warnung fuer das Auge: ab/bis mit Tag und Uhrzeit HH:MM. Die gesprochene Form (wd_zeitraum) bleibt der Ansage. */
function wd_seite_zeitraum($beginn, $ende, $jetzt, $sp)
{
    $tag = function ($ts) use ($jetzt, $sp) {
        $d = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d', $jetzt))) / 86400 + 0.5);
        $u = date('H:i', $ts);
        if ($d === 0) { return wd_t('SEITE.HEUTE_K', $sp) . ' ' . $u; }
        if ($d === 1) { return wd_t('SEITE.MORGEN_K', $sp) . ' ' . $u; }
        return date($sp === 'de' ? 'd.m.' : 'm/d', $ts) . ' ' . $u;
    };
    $b = (int) $beginn; $e = (int) $ende; $o = array();
    if ($b > $jetzt + 300) { $o[] = sprintf(wd_t('SEITE.AB', $sp), $tag($b)); }
    if ($e > 0) { $o[] = sprintf(wd_t('SEITE.BIS', $sp), $tag($e)); }
    return $o ? implode(' ', $o) : wd_t('SEITE.JETZT', $sp);
}

/** Eine Warnlage fuer die Anzeige vorbereiten: nur die Felder, die die Satzbauer brauchen, mit festem Typ. */
function wd_seite_lage($l)
{
    if (!is_array($l)) { return null; }
    $k = isset($l['klasse']) && is_string($l['klasse']) && preg_match('/^[a-z_]{1,20}\z/', $l['klasse']) ? $l['klasse'] : 'sonst';
    $q = isset($l['quelle']) && is_string($l['quelle']) ? $l['quelle'] : 'dwd';
    return array('klasse' => $k, 'ii' => (int) wd_seite_n($l['ii'] ?? 0), 'vorab' => !empty($l['vorab']) ? 1 : 0, 'stufe' => max(0, min(4, (int) wd_seite_n($l['stufe'] ?? 0))),
        'beginn' => (int) wd_seite_n($l['beginn'] ?? 0), 'ende' => (int) wd_seite_n($l['ende'] ?? 0), 'quelle' => $q,
        'art' => isset($l['art']) && is_string($l['art']) ? $l['art'] : '', 'volltext' => isset($l['volltext']) && is_string($l['volltext']) ? $l['volltext'] : '');
}

/** Die ganze Seite. $st: Zustand oder null. $w: wd_seite_wahl(). $ordner: Symbolordner (Pruefstand). */
function wd_seite_html($st, array $w, $jetzt, $ordner = null)
{
    $sp = $w['lang'];
    $T = function ($k) use ($sp) { return wd_e(wd_t('SEITE.' . $k, $sp)); };
    $g = function ($a, $k) { return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : null; };
    $st = is_array($st) ? $st : null;
    $sets = wd_seite_sets(); $set = $w['iconset'];
    $benutzt = array();
    $bild = function ($name, $text, $klasse = 'ic') use (&$benutzt) {
        $benutzt[] = $name;
        return '<svg class="' . $klasse . '" role="img" aria-label="' . wd_e($text) . '"><title>' . wd_e($text) . '</title><use href="#s-' . $name . '" xlink:href="#s-' . $name . '"/></svg>';
    };
    $o = '';

    // ---------- Kopf: jetzt ----------
    $jw = $g($st, 'jetzt'); $seite = $g($st, 'seite');
    $std = is_array($g($seite, 'std')) ? array_values(array_filter($g($seite, 'std'), 'is_array')) : array();
    $tage = is_array($g($seite, 'tage')) ? array_values(array_filter($g($seite, 'tage'), 'is_array')) : array();
    $ts = (int) wd_seite_n($g($st, 'ts'));
    $sol = wd_seite_n($g($jw, 'strahlung_wm2'));
    $c0 = isset($std[0]) ? $std[0] : array();
    list($b0, $t0) = wd_seite_symbol($g($c0, 'c'), $g($jw, 'regen_mmh') ?? $g($c0, 'r'), $g($c0, 's'), $sol !== null && $sol <= 0);
    $t0txt = wd_t($t0, $sp);
    $tt = isset($tage[0]) ? $tage[0] : array();
    $herk = $g($g($st, 'herkunft'), 'temp_c');
    $herk = $herk === 'station' ? $T('H_STATION') : ($herk === 'wunderground' ? $T('H_WU') : ($herk === 'loxone' ? $T('H_LOXONE') : ($st !== null && $herk !== null && $herk !== 'keine' ? $T('H_PROGNOSE') : '&ndash;')));
    $o .= '<header class="kopf karte">';
    $o .= '<div class="kopf-l">' . $bild($b0, $st === null ? wd_t('SEITE.F_UNBEKANNT', $sp) : $t0txt, 'ic gross') . '<div><div class="temp">' . wd_seite_zahl($g($jw, 'temp_c'), 1, $sp, '&deg;C') . '</div>'
        . '<div class="was">' . ($st === null || $b0 === 'unbekannt' && wd_seite_n($g($jw, 'temp_c')) === null ? '&ndash;' : wd_e($t0txt)) . '</div></div></div>';
    $o .= '<div class="kopf-r"><div>' . $T('GEFUEHLT') . ' <b>' . wd_seite_zahl($g($st, 'gefuehlt'), 1, $sp, '&deg;') . '</b></div>'
        . '<div>' . $T('TIEF') . ' <b>' . wd_seite_zahl($g($tt, 'tmin'), 0, $sp, '&deg;') . '</b> &middot; ' . $T('HOCH') . ' <b>' . wd_seite_zahl($g($tt, 'tmax'), 0, $sp, '&deg;') . '</b></div>'
        . '<div class="leise">' . $T('STAND') . ' ' . ($ts > 0 ? wd_e(date('H:i', $ts)) : '&ndash;') . ' &middot; ' . $herk . '</div></div>';
    $o .= '</header>';
    if ($st === null) {
        $o .= '<div class="hinweis">' . $T('LEER') . '</div>';
    } elseif ($ts > 0 && $jetzt - $ts > 900) {
        $o .= '<div class="hinweis">' . wd_e(sprintf(wd_t('SEITE.ALT', $sp), date('d.m. H:i', $ts))) . '</div>';
    }

    // ---------- Warnungen ----------
    $o .= '<section class="karte"><h2>' . $T('H_WARN') . '</h2>';
    $lagen = is_array($g($st, 'lagen')) ? $g($st, 'lagen') : array();
    if (empty($g($st, 'warn_ok'))) {
        $o .= '<p class="leise">' . $T('WARN_KEINE_AUSSAGE') . '</p>';
    } elseif (!$lagen) {
        $o .= '<p class="ok">' . $T('WARN_KEINE') . '</p>';
    } else {
        foreach ($lagen as $l) {
            $l = wd_seite_lage($l);
            if ($l === null) { continue; }
            $n = $l['quelle'] === 'meteoalarm' && $l['art'] !== '' ? $l['art'] : wd_warn_name($l, $sp);
            $o .= '<div class="warn w' . $l['stufe'] . '"><div class="warn-k"><span class="dreieck" aria-hidden="true">&#9650;</span> ' . wd_e(sprintf(wd_t('SEITE.STUFE', $sp), $l['stufe'])) . ' &middot; ' . $T('STUFE_' . $l['stufe']) . '</div>'
                . '<div class="warn-n">' . wd_e($n) . '</div><div class="leise">' . wd_e(wd_seite_zeitraum($l['beginn'], $l['ende'], $jetzt, $sp)) . '</div>'
                . ($l['quelle'] === 'meteoalarm' && $l['volltext'] !== '' ? '<div class="leise klein">' . wd_e($l['volltext']) . '</div>' : '') . '</div>';
        }
    }
    $nina = $g($st, 'nina');
    if (is_array($nina) && $nina) {
        $o .= '<h3>' . $T('H_NINA') . '</h3>';
        foreach ($nina as $l) { $l = wd_seite_lage($l); if ($l === null) { continue; } $o .= '<div class="warn w' . $l['stufe'] . '"><div class="warn-n">' . wd_e($l['art']) . '</div><div class="leise">' . wd_e(wd_seite_zeitraum($l['beginn'], $l['ende'], $jetzt, $sp)) . '</div></div>'; }
    }
    $o .= '</section>';

    // ---------- Jetzt im Einzelnen ----------
    $kach = function ($titel, $wert, $zusatz = '') { return '<div class="kachel"><div class="leise">' . $titel . '</div><div class="wert">' . $wert . '</div>' . ($zusatz !== '' ? '<div class="leise klein">' . $zusatz . '</div>' : '') . '</div>'; };
    $ri = wd_seite_richtung($g($jw, 'windrichtung_grad'), $sp);
    $uvs = wd_seite_uv_stufe($g($jw, 'uv'));
    $ra = $g($st, 'radar');
    $rmin = wd_seite_n($g($ra, 'min'));
    $radar = !is_array($ra) ? '&ndash;' : ($rmin === null ? $T('RADAR_KEIN') : ($rmin <= 0 ? $T('RADAR_JETZT') : wd_e(sprintf(wd_t('SEITE.RADAR_IN', $sp), (int) $rmin))));
    $o .= '<section class="karte"><h2>' . $T('H_JETZT') . '</h2><div class="kacheln">'
        . $kach($T('K_RF'), wd_seite_zahl($g($jw, 'rf_pct'), 0, $sp, '%'))
        . $kach($T('K_TAU'), wd_seite_zahl($g($jw, 'taupunkt_c'), 1, $sp, '&deg;C'))
        . $kach($T('K_DRUCK'), wd_seite_zahl($g($jw, 'druck_hpa'), 0, $sp, 'hPa'))
        . $kach($T('K_WIND'), wd_seite_zahl($g($jw, 'wind_kmh'), 0, $sp, 'km/h'), $ri !== '' ? wd_e(sprintf(wd_t('SEITE.AUS_RICHTUNG', $sp), $ri)) : '')
        . $kach($T('K_BOE'), wd_seite_zahl($g($jw, 'boe_kmh'), 0, $sp, 'km/h'))
        . $kach($T('K_REGEN'), wd_seite_zahl($g($jw, 'regen_mmh'), 1, $sp, 'mm/h'))
        . $kach($T('K_UV'), wd_seite_zahl($g($jw, 'uv'), 0, $sp), $uvs === null ? '' : $T('UV_' . $uvs))
        . $kach($T('K_SONNE'), wd_seite_zahl($sol, 0, $sp, 'W/m&sup2;'))
        . $kach($T('K_RADAR'), $radar, is_array($ra) && wd_seite_n($g($ra, 'mmh')) ? wd_seite_zahl($g($ra, 'mmh'), 1, $sp, 'mm/h') : '')
        . '</div></section>';

    // ---------- Stundenverlauf ----------
    $o .= '<section class="karte"><h2>' . $T('H_STUNDEN') . '</h2>';
    if (!$std) {
        $o .= '<p class="leise">&ndash; ' . $T('KEINE_PROGNOSE') . '</p>';
    } else {
        $n = count($std); $bw = 56;
        $tv = array(); $rv = array();
        foreach ($std as $z) { $t = wd_seite_n($g($z, 't')); if ($t !== null) { $tv[] = $t; } $r = wd_seite_n($g($z, 'r')); if ($r !== null) { $rv[] = $r; } }
        $tmin = $tv ? min($tv) : 0; $tmax = $tv ? max($tv) : 1; if ($tmax - $tmin < 2) { $tmax += 1; $tmin -= 1; }
        $rmax = max(1.0, $rv ? max($rv) : 0);
        $zeit = ''; $sym = ''; $temp = ''; $regen = ''; $pkt = array(); $kreise = ''; $balken = '';
        foreach ($std as $i => $z) {
            $zts = (int) wd_seite_n($g($z, 'ts'));
            $h = (int) date('G', $zts);
            $zeit .= '<div class="' . ($h === 0 ? 'mitternacht' : '') . '">' . ($h === 0 ? wd_e(wd_t('SEITE.WT_' . date('N', $zts), $sp)) : wd_e(date('H', $zts)) . '<span class="leise">:00</span>') . '</div>';
            $zs = wd_seite_n($g($z, 'sol'));
            list($bb, $bt) = wd_seite_symbol($g($z, 'c'), $g($z, 'r'), $g($z, 's'), $zs !== null && $zs <= 0);
            $sym .= '<div>' . $bild($bb, wd_t($bt, $sp)) . '</div>';
            $temp .= '<div><b>' . wd_seite_zahl($g($z, 't'), 0, $sp, '&deg;') . '</b></div>';
            $r = wd_seite_n($g($z, 'r')); $rw = wd_seite_n($g($z, 'rw'));
            $regen .= '<div class="leise klein">' . ($r !== null && $r >= 0.1 ? wd_seite_zahl($r, 1, $sp) : '') . ($rw !== null && $rw >= 10 ? '<br>' . wd_seite_zahl($rw, 0, $sp, '%') : '') . '</div>';
            $t = wd_seite_n($g($z, 't'));
            $x = $i * $bw + $bw / 2;
            if ($t !== null) {
                $y = 6 + (1 - ($t - $tmin) / ($tmax - $tmin)) * 44;
                $pkt[] = round($x, 1) . ',' . round($y, 1);
                $kreise .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4"><title>' . wd_e(date('H:i', $zts)) . ' ' . strip_tags(html_entity_decode(wd_seite_zahl($t, 1, $sp, '°C'))) . '</title></circle>';
            }
            if ($r !== null && $r > 0) {
                $hh = max(3, min(34, $r / $rmax * 34));
                $balken .= '<rect x="' . round($x - 10, 1) . '" y="' . round(36 - $hh, 1) . '" width="20" height="' . round($hh + 4, 1) . '" rx="4"><title>' . wd_e(date('H:i', $zts)) . ' ' . strip_tags(html_entity_decode(wd_seite_zahl($r, 1, $sp, 'mm'))) . '</title></rect>';
            }
        }
        $breite = $n * $bw;
        $sp_style = ' style="grid-template-columns:repeat(' . $n . ',' . $bw . 'px);width:' . $breite . 'px"';
        $o .= '<div class="std" tabindex="0" aria-label="' . $T('H_STUNDEN') . '"><div class="std-in" style="width:' . $breite . 'px">'
            . '<div class="std-z"' . $sp_style . '>' . $zeit . '</div><div class="std-z"' . $sp_style . '>' . $sym . '</div><div class="std-z"' . $sp_style . '>' . $temp . '</div>'
            . '<svg class="kurve" width="' . $breite . '" height="56" viewBox="0 0 ' . $breite . ' 56" role="img" aria-label="' . $T('KURVE') . '"><polyline points="' . implode(' ', $pkt) . '" fill="none" stroke-width="2" stroke-linejoin="round"/>' . $kreise . '</svg>'
            . '<svg class="balken" width="' . $breite . '" height="40" viewBox="0 0 ' . $breite . ' 40" role="img" aria-label="' . $T('REGEN_MM') . '"><line x1="0" y1="38" x2="' . $breite . '" y2="38" stroke-width="1"/>' . $balken . '</svg>'
            . '<div class="std-z"' . $sp_style . '>' . $regen . '</div>'
            . '</div></div><p class="leise klein">' . $T('STUNDEN_HILFE') . '</p>';
    }
    $o .= '</section>';

    // ---------- Tage ----------
    $o .= '<section class="karte"><h2>' . $T('H_TAGE') . '</h2>';
    if (!$tage) {
        $o .= '<p class="leise">&ndash; ' . $T('KEINE_PROGNOSE') . '</p>';
    } else {
        $alle = array();
        foreach ($tage as $t) { foreach (array('tmin', 'tmax') as $k) { $v = wd_seite_n($g($t, $k)); if ($v !== null) { $alle[] = $v; } } }
        $lo = $alle ? min($alle) : 0; $hi = $alle ? max($alle) : 1; if ($hi - $lo < 1) { $hi = $lo + 1; }
        $o .= '<div class="tage">';
        foreach ($tage as $i => $t) {
            $d = strtotime((string) $g($t, 'datum') . ' 12:00:00');
            $name = $i === 0 ? wd_t('SEITE.HEUTE', $sp) : ($i === 1 ? wd_t('SEITE.MORGEN', $sp) : ($d ? wd_t('SEITE.WT_' . date('N', $d), $sp) : '-'));
            list($bb, $bt) = wd_seite_symbol($g($t, 'c'), $g($t, 'r'), $g($t, 's'), false);
            $a = wd_seite_n($g($t, 'tmin')); $b = wd_seite_n($g($t, 'tmax'));
            $bar = ($a !== null && $b !== null) ? '<span class="spanne"><i style="left:' . round(($a - $lo) / ($hi - $lo) * 100, 1) . '%;width:' . max(2, round(($b - $a) / ($hi - $lo) * 100, 1)) . '%"></i></span>' : '<span class="spanne"></span>';
            $r = wd_seite_n($g($t, 'r')); $rw = wd_seite_n($g($t, 'rw'));
            $o .= '<div class="tag"><div class="tag-n"><b>' . wd_e($name) . '</b><span class="leise klein">' . ($d ? wd_e(date($sp === 'de' ? 'd.m.' : 'm/d', $d)) : '') . '</span></div>'
                . '<div>' . $bild($bb, wd_t($bt, $sp)) . '</div><div class="tag-t leise">' . wd_e(wd_t($bt, $sp)) . '</div>'
                . '<div class="tag-min">' . wd_seite_zahl($a, 0, $sp, '&deg;') . '</div>' . $bar . '<div class="tag-max"><b>' . wd_seite_zahl($b, 0, $sp, '&deg;') . '</b></div>'
                . '<div class="tag-r leise klein">' . wd_seite_zahl($r, 1, $sp, 'mm') . ($rw !== null ? ' &middot; ' . wd_seite_zahl($rw, 0, $sp, '%') : '') . '</div></div>';
        }
        $o .= '</div><p class="leise klein">' . $T('TAGE_HILFE') . '</p>';
    }
    $o .= '</section>';

    // ---------- Pollen und Luft ----------
    $po = $g($st, 'pollen'); $lu = $g($st, 'luft');
    $o .= '<div class="zwei"><section class="karte"><h2>' . $T('H_POLLEN') . '</h2>';
    if (!is_array($po)) {
        $o .= '<p class="leise">&ndash; ' . $T('POLLEN_KEINE') . '</p>';
    } else {
        $stufe = function ($v) use ($sp) {
            $v = wd_seite_n($v);
            if ($v === null) { return '<span class="leise">&ndash;</span>'; }
            $v = max(0, min(3, round($v * 2) / 2));
            $p = ''; for ($k = 1; $k <= 3; $k++) { $p .= '<i class="' . ($v >= $k ? 'voll' : ($v >= $k - 0.5 ? 'halb' : '')) . '"></i>'; }
            return '<span class="punkte" role="img" aria-label="' . wd_e(wd_t('SEITE.P_' . str_replace('.', '_', (string) $v), $sp)) . '">' . $p . '</span> <span class="klein">' . wd_e(wd_t('SEITE.P_' . str_replace('.', '_', (string) $v), $sp)) . '</span>';
        };
        $o .= '<table class="tab"><tr><th></th><th>' . $T('HEUTE') . '</th><th>' . $T('MORGEN') . '</th></tr>';
        foreach (wd_pollen_arten() as $a) {
            $k = strtolower($a); $v = $g($po, $k);
            $o .= '<tr><td>' . wd_e(wd_t('POLLEN.' . $k, $sp)) . '</td><td>' . $stufe($g($v, 0)) . '</td><td>' . $stufe($g($v, 1)) . '</td></tr>';
        }
        $o .= '</table>';
    }
    $o .= '</section><section class="karte"><h2>' . $T('H_LUFT') . '</h2>';
    if (!is_array($lu)) {
        $o .= '<p class="leise">&ndash; ' . $T('LUFT_KEINE') . '</p>';
    } else {
        $ls = wd_seite_luft_stufe($g($lu, 'index'));
        $o .= '<div class="luft"><div class="wert">' . wd_seite_zahl($g($lu, 'index'), 0, $sp) . '</div><div>' . ($ls === null ? '&ndash;' : $T('LUFT_' . $ls)) . '<div class="leise klein">' . $T('LUFT_INDEX') . '</div></div></div>'
            . '<table class="tab"><tr><td>' . $T('PM10') . '</td><td>' . wd_seite_zahl($g($lu, 'pm10'), 0, $sp, '&micro;g/m&sup3;') . '</td></tr><tr><td>' . $T('PM25') . '</td><td>' . wd_seite_zahl($g($lu, 'pm25'), 0, $sp, '&micro;g/m&sup3;') . '</td></tr>'
            . '<tr><td>' . $T('OZON') . '</td><td>' . wd_seite_zahl($g($lu, 'ozon'), 0, $sp, '&micro;g/m&sup3;') . '</td></tr><tr><td>' . $T('NO2') . '</td><td>' . wd_seite_zahl($g($lu, 'no2'), 0, $sp, '&micro;g/m&sup3;') . '</td></tr></table>'
            . '<p class="leise klein">' . $T('LUFT_MODELL') . '</p>';
    }
    $o .= '</section></div>';

    // ---------- Quellen ----------
    $q = is_array($g($st, 'quellen')) ? $g($st, 'quellen') : array();
    $ok = function ($n) use ($q, $g) { return !empty($g($g($q, $n), 'ok')); };
    $a = function ($u, $t) { return '<a href="' . wd_e($u) . '" target="_blank" rel="noopener">' . wd_e($t) . '</a>'; };
    $li = array();
    if ($ok('open_meteo')) { $li[] = wd_e(wd_t('NENNUNG.OM', $sp)) . ' ' . $a('https://open-meteo.com/', 'open-meteo.com') . ' (CC BY 4.0)'; }
    if ($ok('mosmix') || $ok('brightsky') || $ok('radar')) { $li[] = wd_e(wd_t('NENNUNG.BS', $sp)) . ' ' . $a('https://brightsky.dev/', 'brightsky.dev'); }
    if ($ok('met')) { $li[] = wd_e(wd_t('NENNUNG.MET', $sp)) . ' ' . $a('https://www.met.no/', 'met.no'); }
    if ($ok('wttr')) { $li[] = wd_e(wd_t('NENNUNG.WTTR', $sp)) . ' ' . $a('https://wttr.in/', 'wttr.in'); }
    if ($ok('dwd') || $ok('pollen') || $ok('mosmix')) { $li[] = wd_e(wd_t('SEITE.Q_DWD', $sp)) . ' ' . $a('https://www.dwd.de/', 'dwd.de'); }
    if ($ok('luft')) { $li[] = wd_e(wd_t('NENNUNG.CAMS', $sp)); }
    if ($ok('meteoalarm') || $g($st, 'warn_quelle') === 'meteoalarm') { $li[] = wd_e(wd_t('NENNUNG.MA', $sp)) . ' ' . $a('https://www.meteoalarm.org/', 'www.meteoalarm.org') . (is_string($g($st, 'ma_dienst')) && $g($st, 'ma_dienst') !== '' ? ' ' . wd_e(sprintf(wd_t('SEITE.Q_MA_DIENST', $sp), $g($st, 'ma_dienst'))) : ''); }
    if ($ok('nina') && is_array($nina) && $nina) { $li[] = wd_e(wd_t('SEITE.Q_NINA', $sp)); }
    if ($ok('station')) { $li[] = $T('Q_STATION'); }
    if ($ok('wunderground')) { $li[] = $T('Q_WU'); }
    if ($ok('loxone')) { $li[] = $T('Q_LOXONE'); }
    $si = $sets[$set];
    $li[] = wd_e(sprintf(wd_t('SEITE.Q_SYMBOLE', $sp), $si['name'], $si['lizenz'])) . ($si['url'] !== '' ? ' ' . $a($si['url'], preg_replace('#^https://#', '', $si['url'])) : '');
    $o .= '<footer class="karte fuss"><h2>' . $T('H_QUELLEN') . '</h2><ul><li>' . implode('</li><li>', $li) . '</li></ul><p class="leise klein">' . $T('PFLICHT') . '</p></footer>';

    $kopf = '<!doctype html><html lang="' . $sp . '" data-mode="' . $w['mode'] . '" data-theme="' . $w['theme'] . '" data-iconset="' . $set . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta http-equiv="refresh" content="300">'
        . '<meta name="color-scheme" content="' . ($w['mode'] === 'light' ? 'light' : ($w['mode'] === 'dark' ? 'dark' : 'light dark')) . '"><meta name="referrer" content="no-referrer">'
        . '<title>' . $T('TITEL') . '</title><style>' . wd_seite_css_vars($w['theme'], $w['mode']) . wd_seite_css(!empty($si['bunt'])) . '</style></head><body><main class="w">';
    return $kopf . $o . '</main>' . wd_seite_sprite($set, $benutzt, $ordner) . '</body></html>';
}

/** Feste Regeln, nur aus den Variablen. Breite fuer Handy und iPad; die Stundenleiste rollt seitlich. */
function wd_seite_css($bunt)
{
    return 'html{-webkit-text-size-adjust:100%}*{box-sizing:border-box}'
        . 'body{margin:0;background:var(--bg);color:var(--text);font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}'
        . '.w{max-width:1100px;margin:0 auto;padding:12px max(12px,env(safe-area-inset-right)) 24px max(12px,env(safe-area-inset-left))}'
        . '.karte{background:var(--karte);border:1px solid var(--linie);border-radius:14px;padding:14px 16px;margin:0 0 12px}'
        . 'h2{font-size:1.02em;margin:0 0 10px;color:var(--akzent)}h3{font-size:.95em;margin:12px 0 6px}'
        . '.leise{color:var(--leise)}.klein{font-size:.82em}.ok{margin:0}'
        . 'a{color:var(--akzent)}'
        . '.ic{width:40px;height:40px;display:inline-block;vertical-align:middle;color:var(--sym)}.ic.gross{width:96px;height:96px;flex:0 0 auto}'
        . ($bunt ? '' : '.ic{padding:4px}.ic.gross{padding:10px}')
        . '.kopf{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;justify-content:space-between;background:linear-gradient(160deg,var(--kopf1),var(--kopf2))}'
        . '.kopf-l{display:flex;align-items:center;gap:10px}.temp{font-size:3em;font-weight:700;line-height:1.05;letter-spacing:-.02em}.was{font-size:1.1em}'
        . '.kopf-r{display:grid;gap:4px;font-size:.98em}'
        . '.hinweis{border:1px solid var(--linie);border-left:4px solid var(--akzent);background:var(--karte);border-radius:10px;padding:10px 12px;margin:0 0 12px}'
        . '.warn{border:1px solid var(--linie);border-left:6px solid var(--leise);border-radius:10px;padding:8px 12px;margin:0 0 8px}'
        . '.warn.w1{border-left-color:#e6b800}.warn.w2{border-left-color:#f08a00}.warn.w3{border-left-color:#d7261e}.warn.w4{border-left-color:#8e24aa}'
        . '.warn-k{font-size:.85em;font-weight:600}.warn-n{font-weight:700;margin:2px 0}.dreieck{font-size:.8em}'
        . '.w1 .dreieck{color:#b38f00}.w2 .dreieck{color:#d97a00}.w3 .dreieck{color:#d7261e}.w4 .dreieck{color:#8e24aa}'
        . '.kacheln{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}'
        . '.kachel{border:1px solid var(--linie);border-radius:10px;padding:8px 10px}.kachel .wert{font-size:1.25em;font-weight:600;margin-top:2px}'
        . '.std{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -4px;padding:0 4px 6px}.std-in{display:block}'
        . '.std-z{display:grid;text-align:center;align-items:center}.std-z>div{padding:2px 0}.mitternacht{font-weight:700;color:var(--akzent)}'
        . '.kurve,.balken{display:block}.kurve polyline{stroke:var(--kurve)}.kurve circle{fill:var(--kurve);stroke:var(--karte);stroke-width:2}'
        . '.balken rect{fill:var(--balken)}.balken line{stroke:var(--linie)}'
        . '.tage{display:grid;gap:2px}.tag{display:grid;grid-template-columns:5.2em 44px 1fr 2.6em minmax(60px,1.2fr) 2.6em 7.5em;gap:8px;align-items:center;padding:4px 0;border-top:1px solid var(--linie)}'
        . '.tag:first-child{border-top:0}.tag-n{display:flex;flex-direction:column}.tag-min{text-align:right;color:var(--leise)}.tag-r{text-align:right}'
        . '.spanne{position:relative;display:block;height:8px;border-radius:4px;background:var(--linie)}.spanne i{position:absolute;top:0;bottom:0;border-radius:4px;background:var(--akzent)}'
        . '.zwei{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:0 12px}'
        . '.tab{border-collapse:collapse;width:100%}.tab td,.tab th{padding:4px 6px;border-top:1px solid var(--linie);text-align:left;font-weight:normal}.tab th{color:var(--leise);border-top:0;font-size:.85em}'
        . '.punkte{display:inline-flex;gap:3px;vertical-align:middle}.punkte i{width:10px;height:10px;border-radius:50%;border:1.5px solid var(--akzent)}'
        . '.punkte i.voll{background:var(--akzent)}.punkte i.halb{background:linear-gradient(90deg,var(--akzent) 50%,transparent 50%)}'
        . '.luft{display:flex;gap:14px;align-items:center;margin-bottom:6px}.luft .wert{font-size:2em;font-weight:700}'
        . '.fuss ul{margin:0;padding-left:18px}.fuss li{margin:3px 0;font-size:.9em}'
        . '@media (max-width:600px){.temp{font-size:2.5em}.ic.gross{width:76px;height:76px}.tag{grid-template-columns:4.4em 40px 2.2em minmax(40px,1fr) 2.4em;row-gap:0}.tag-t{display:none}.tag-r{grid-column:2/-1;text-align:left}.kacheln{grid-template-columns:repeat(2,1fr)}}';
}
