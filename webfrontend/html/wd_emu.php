<?php
/**
 * Weatherdata - Emulator des Loxone-Wetterdienstes. Ab Werk AUS. Stammt nicht von Loxone.
 *
 * Ein Miniserver fragt fest http://weather.loxone.com:6066/forecast/?user=...&coord=<lon>,<lat>&format=1&asl=<m> (Wiki und Quelltext
 * von Weather4Lox, Apache-2.0; das Format ist dort abgelesen, keine Zeile uebernommen). Die Antwort entsteht aus dem Prognose-Bestand
 * des Plugins, im Minutenlauf vorbereitet; der Endpunkt selbst fragt keinen Dienst.
 *
 * Grenzen: Ab Config 16 prueft ein Miniserver Gen. 2 das Zertifikat ueber HTTPS. Das wird hier nicht umgangen, dort geht der Emulator nicht.
 * Der Endpunkt nennt den Standort der Einstellungen nie: Lage, Sonnenzeiten und Hoehe kommen nur aus der Anfrage selbst. Abfragedaten kommen nicht ins Protokoll.
 * Nicht am Miniserver gemessen, nur gegen eine Nachbildung seiner Abfrage.
 */

/** Name der Apache-Datei; bin/wd_emu_apache.sh und uninstall/uninstall bilden ihn genauso. */
function wd_emu_conf_name() { return '001-' . preg_replace('/[^A-Za-z0-9_\-]/', '', wd_paths()['plugin']) . '-emu.conf'; }

/** WMO-Wettercode (Open-Meteo) auf die Bildnummern des Dienstes. Unbekannt: null, dann entscheidet die Menge. */
function wd_emu_bild_wmo($c)
{
    $t = array(0 => 1, 1 => 2, 2 => 7, 3 => 22, 45 => 16, 48 => 16, 51 => 33, 53 => 33, 55 => 23, 56 => 35, 57 => 35, 61 => 33, 63 => 23, 65 => 25, 66 => 35, 67 => 35,
        71 => 24, 73 => 24, 75 => 26, 77 => 24, 80 => 31, 81 => 23, 82 => 25, 85 => 32, 86 => 29, 95 => 28, 96 => 27, 99 => 27);
    return (is_numeric($c) && isset($t[(int) $c])) ? $t[(int) $c] : null;
}

/** Bildnummer: Wettercode der fuehrenden Quelle; ohne ihn aus Regen und Schnee (Codes werden nie gemittelt). */
function wd_emu_bild($code, $regen, $schnee)
{
    $b = wd_emu_bild_wmo($code);
    if ($b !== null) { return $b; }
    if ((float) $schnee > 0) { return 24; }
    if ((float) $regen >= 4) { return 25; }
    if ((float) $regen >= 1) { return 23; }
    return (float) $regen > 0 ? 33 : 7;
}

/** Stunden fuer die Antwort: ab der laufenden Stunde, hoechstens 168. Druck, Windrichtung und Wolken nur aus Open-Meteo, sonst 0. Gefuehlt immer aus derselben eigenen Formel. */
function wd_emu_stunden(array $std, array $om, $jetzt)
{
    $ab = (int) $jetzt - (int) $jetzt % 3600;
    $zo = array();
    foreach ($om as $z) { if (isset($z['ts'])) { $zo[(int) $z['ts'] - (int) $z['ts'] % 3600] = $z; } }
    $aus = array();
    foreach ($std as $z) {
        $ts = (int) $z['ts'];
        if ($ts < $ab || !isset($z['t']) || $z['t'] === null) { continue; }
        $o = isset($zo[$ts]) ? $zo[$ts] : array();
        $r = (float) ($z['regen'] ?? 0); $s = (float) ($z['schnee'] ?? 0);
        list($g) = wd_gefuehlt((float) $z['t'], isset($z['rf']) ? $z['rf'] : null, isset($z['wind']) ? $z['wind'] : null);
        $aus[] = array('ts' => $ts, 't' => round((float) $z['t'], 2), 'g' => round((float) ($g === null ? $z['t'] : $g), 2), 'w' => (int) round((float) ($z['wind'] ?? 0)), 'rt' => (int) round((float) ($o['richt'] ?? 0)),
            'b' => (int) round((float) ($z['boe'] ?? ($z['wind'] ?? 0))), 'w1' => (int) round((float) ($o['wo1'] ?? 0)), 'w2' => (int) round((float) ($o['wo2'] ?? 0)), 'w3' => (int) round((float) ($o['wo3'] ?? 0)),
            'n' => round($r, 1), 'p' => (int) round((float) ($z['regen_w'] ?? 0)), 'sf' => $s <= 0 ? 0.0 : ($r > 0 ? round(min(1.0, $s * 0.7 / $r), 2) : 1.0), 'd' => (int) round((float) ($o['druck'] ?? 0)),
            'f' => (int) round((float) ($z['rf'] ?? 0)), 'l' => wd_emu_bild($o['code'] ?? null, $r, $s), 'st' => round((float) ($z['solar'] ?? 0), 2));
        if (count($aus) >= 168) { break; }
    }
    return $aus;
}

/** Im Minutenlauf: Bestand fuer den Endpunkt ablegen. Aus oder ohne Prognose: Die Datei verschwindet, keine Daten sind besser als alte. */
function wd_emu_schreiben(array $c, array $std, $jetzt)
{
    $f = wd_tmpdir() . '/emu_stunden.json';
    $om = array();
    if (!empty($c['emu_ein'])) { $d = wd_json_lesen(wd_datadir() . '/prog.json'); if (is_array($d) && isset($d['stunden'])) { $om = $d['stunden']; } }
    $z = !empty($c['emu_ein']) ? wd_emu_stunden($std, $om, $jetzt) : array();
    if (!$z) { if (is_file($f)) { @unlink($f); } return false; }
    return wd_json_schreiben($f, array('ts' => (int) $jetzt, 'std' => $z));
}

/** coord=<lon>,<lat> aus der Anfrage. Nur zwei Zahlen im Wertebereich, sonst null. */
function wd_emu_koord($s)
{
    if (!is_string($s) || !preg_match('/^(-?[0-9]{1,3}(?:\.[0-9]{1,8})?),(-?[0-9]{1,2}(?:\.[0-9]{1,8})?)\z/', $s, $m)) { return null; }
    $lo = (float) $m[1]; $la = (float) $m[2];
    return (abs($lo) <= 180 && abs($la) <= 90) ? array(round($lo, 4), round($la, 4)) : null;
}

/** Sonnenzeiten nur fuer die Lage, die die Anfrage selbst nennt; so verraten sie den eingetragenen Standort nicht. */
function wd_emu_sonne($ko, $jetzt)
{
    if ($ko === null) { return array('-', '-'); }
    $s = @date_sun_info((int) $jetzt, $ko[1], $ko[0]);
    $h = function ($k) use ($s) { return (is_array($s) && isset($s[$k]) && is_int($s[$k]) && $s[$k] > 1) ? date('H:i', $s[$k]) : '-'; };
    return array($h('sunrise'), $h('sunset'));
}

/** Rumpf nach Format 1. Lage nur aus der Anfrage, Name und Land bleiben ein Strich. */
function wd_emu_antwort(array $d, array $get, $jetzt)
{
    $ko = wd_emu_koord($get['coord'] ?? null);
    $asl = (isset($get['asl']) && is_string($get['asl']) && preg_match('/^[0-9]{1,4}\z/', $get['asl'])) ? (int) $get['asl'] : 0;
    list($auf, $unter) = wd_emu_sonne($ko, $jetzt);
    $off = (int) date('Z', (int) $jetzt);
    $v = $off < 0 ? '-' : '+'; $b = abs($off) / 3600;
    $o = "<mb_metadata>\n"
       . "id;name;longitude;latitude;height (m.asl.);country;timezone;utc-timedifference;sunrise;sunset;\n"
       . "local date;weekday;local time;temperature(C);feeledTemperature(C);windspeed(km/h);winddirection(degr);wind gust(km/h);low clouds(%);medium clouds(%);high clouds(%);precipitation(mm);probability of Precip(%);snowFraction;sea level pressure(hPa);relative humidity(%);CAPE;picto-code;radiation (W/m2);\n"
       . "</mb_metadata>\n<valid_until>" . ((int) date('Y', (int) $jetzt) + 1) . "-12-31</valid_until>\n<station>\n";
    $o .= ';-;' . ($ko === null ? '0;0' : $ko[0] . ';' . $ko[1]) . ';' . $asl . ';-;' . date('T', (int) $jetzt) . ';UTC' . $v . sprintf('%05.2F', $b) . ';' . $auf . ';' . $unter . ";\n";
    foreach ((array) $d['std'] as $z) {
        $t = (int) $z['ts'];
        $o .= date('d.m.Y', $t) . ";\t" . date('D', $t) . ";\t" . date('H', $t) . ";\t" . sprintf('%.2F', $z['t']) . ";\t" . sprintf('%.2F', $z['g']) . ";\t" . (int) $z['w'] . ";\t" . (int) $z['rt'] . ";\t" . (int) $z['b'] . ";\t"
            . (int) $z['w1'] . ";\t" . (int) $z['w2'] . ";\t" . (int) $z['w3'] . ";\t" . sprintf('%.1F', $z['n']) . ";\t" . (int) $z['p'] . ";\t" . sprintf('%.2F', $z['sf']) . ";\t" . (int) $z['d'] . ";\t" . (int) $z['f'] . ";\t0;\t" . (int) $z['l'] . ";\t" . sprintf('%.2F', $z['st']) . ";\n";
    }
    return $o . "</station>\n";
}

/** Adressen der in LoxBerry eingetragenen Miniserver; ein Rechnername wird aufgeloest. */
function wd_emu_ms_adressen()
{
    $g = wd_paths()['general'];
    $d = $g !== '' ? wd_json_lesen($g) : null;
    $o = array();
    foreach ((is_array($d) && isset($d['Miniserver']) && is_array($d['Miniserver'])) ? $d['Miniserver'] : array() as $m) {
        $a = (is_array($m) && isset($m['Ipaddress']) && is_string($m['Ipaddress'])) ? trim($m['Ipaddress']) : '';
        if ($a === '') { continue; }
        $o[] = filter_var($a, FILTER_VALIDATE_IP) ? $a : gethostbyname($a);
    }
    return $o;
}

/** Wer darf fragen? Ab Werk nur die eingetragenen Miniserver und der LoxBerry selbst. Eine leere Liste sperrt. */
function wd_emu_erlaubt($adr, array $c)
{
    $a = preg_replace('/^::ffff:/i', '', (string) $adr);
    if ($a === '') { return false; }
    if ($a === '127.0.0.1' || $a === '::1') { return true; }
    if (empty($c['emu_nur_ms'])) { return true; }
    return in_array($a, wd_emu_ms_adressen(), true);
}

/** Der Endpunkt: Rueckgabe array(HTTP-Code, Rumpf). Abfragedaten (Lage, Seriennummer) werden nicht gemerkt. */
function wd_emu_endpunkt(array $srv, array $get, $jetzt)
{
    $c = wd_inhalt_oder_null(wd_paths()['config']);
    if (!is_array($c) || empty($c['emu_ein'])) { return array(404, "WD;OK=0;ERR=AUS\n"); }
    $c += wd_vorgaben();
    $me = isset($srv['REQUEST_METHOD']) ? (string) $srv['REQUEST_METHOD'] : 'GET';
    if ($me !== 'GET' && $me !== 'HEAD') { return array(405, "WD;OK=0;ERR=METHODE\n"); }
    if (!wd_emu_erlaubt(isset($srv['REMOTE_ADDR']) ? $srv['REMOTE_ADDR'] : '', $c)) { return array(403, "WD;OK=0;ERR=ZUGANG\n"); }
    $d = wd_json_lesen(wd_tmpdir(false) . '/emu_stunden.json');
    if (!is_array($d) || empty($d['std']) || (int) ($d['ts'] ?? 0) > (int) $jetzt + 60 || (int) $jetzt - (int) ($d['ts'] ?? 0) > 600) {
        wd_log_einmal('emu', 'Emulator: keine frischen Daten, Antwort 503');
        return array(503, "WD;OK=0;ERR=KEINE_DATEN\n");
    }
    wd_log_einmal('emu', 'Emulator: Abfragen werden beantwortet');
    wd_json_schreiben(wd_tmpdir(false) . '/emu_abruf.json', array('ts' => (int) $jetzt));
    return array(200, wd_emu_antwort($d, $get, $jetzt));
}

/**
 * Lage fuer Reiter und Healthcheck, nur lesen. $etc nur fuer den Pruefstand. vhost: unsere Datei ist aktiv. fremd: Eine andere aktive Datei belegt Port 6066.
 * w4l: Ein Plugin dieser Art ist installiert, auch wenn sein Emulator gerade aus ist.
 */
function wd_emu_lage(array $c, $etc = '/etc/apache2')
{
    $p = wd_paths();
    $eigen = wd_emu_conf_name();
    $fremd = array();
    $liste = array_merge(array($etc . '/ports.conf', $etc . '/apache2.conf'), glob($etc . '/sites-enabled/*') ?: array(), glob($etc . '/conf-enabled/*') ?: array());
    foreach ($liste as $f) {
        if (basename($f) === $eigen || !is_file($f)) { continue; }
        if (preg_match('/^\s*(Listen\s+(\S+:)?6066\b|<VirtualHost[^>\n]*:6066\b)/mi', (string) @file_get_contents($f))) { $fremd[] = basename($f); }
    }
    $w4l = array();
    foreach (array('weather4lox', 'wxparser') as $n) { if ($p['lbhome'] !== '' && is_dir($p['lbhome'] . '/webfrontend/html/plugins/' . $n)) { $w4l[] = $n; } }
    $d = wd_json_lesen(wd_tmpdir(false) . '/emu_stunden.json');
    $a = wd_json_lesen(wd_tmpdir(false) . '/emu_abruf.json');
    return array('ein' => !empty($c['emu_ein']), 'vhost' => is_file($etc . '/sites-enabled/' . $eigen), 'fremd' => $fremd, 'w4l' => $w4l,
        'daten_ts' => is_array($d) ? (int) ($d['ts'] ?? 0) : 0, 'stunden' => is_array($d) && isset($d['std']) ? count($d['std']) : 0, 'abruf_ts' => is_array($a) ? (int) ($a['ts'] ?? 0) : 0);
}

/** Fuer wd_gesundheit: null oder array(Status, Schluessel, Wert). */
function wd_emu_gesund(array $c, $jetzt, $etc = '/etc/apache2')
{
    if (empty($c['emu_ein'])) { return null; }
    $e = wd_emu_lage($c, $etc);
    if ($e['fremd']) { return array(4, 'GESUND.EMU_FREMD', implode(', ', $e['fremd'])); }
    if (!$e['vhost']) { return array(4, 'GESUND.EMU_PORT', ''); }
    if ($e['daten_ts'] === 0 || $jetzt - $e['daten_ts'] > 600) { return array(4, 'GESUND.EMU_DATEN', ''); }
    if ($e['abruf_ts'] === 0 || $jetzt - $e['abruf_ts'] > 3 * 3600) { return array(6, 'GESUND.EMU_ABRUF', ''); }
    return null;
}
