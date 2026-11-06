<?php
/**
 * Weatherdata 0.9.8 - Anbieter der Hausthemen haus/wetter/... fuer andere Plugins (Regeln/07 Nr. 15; Vertrag
 * Pruefung-Durchgang-2026-09-29/vb_wd_skripte/wd098_themenvertrag.md, nur als Vorschlag). Abnehmer: Sprachsteuerung lokal
 * ab 0.12.13 (Wetterfragen).
 *
 * 0.9.13 (Hausherr 06.11.2026): dazu haus/wetter/datum_text - derselbe Tag wie haus/wetter/datum als Text TT.MM.JJJJ zum
 * Anzeigen in Loxone; retained wie datum, im Satz direkt hinter datum, ohne Aussage "-" genau dann, wenn datum keine hat.
 * haus/wetter/datum behaelt seine Bedeutung (JJJJMMTT). Damit 29 Themen, 27 davon retained.
 *
 * Ab Werk aus (Haken haus_wetter_ein im Reiter MQTT, Entscheidung Hausherr 04.11.2026), wirkt nur mit mqtt_ein. Gesendet per
 * kurzer TCP-Sitzung direkt an den Broker, nicht ueber den UDP-Eingang des Gateways: ein verlorenes Datagramm liesse einen
 * retained Wert stehen, und das Abraeumen braucht das Ruecklesen ohnehin. Bauart wie Ferien und Feiertage 1.2.28
 * (fer_haus_sitzung(), dort eine Abschrift aus AWM-Abfuhr 1.4.26). Kein mosquitto_pub: kein Kennwort auf einer Befehlszeile.
 * Die Linienthemen weatherdata/... bleiben unveraendert; fremde haus-Themen (Fuehler haus/wetter/<name>/...) fasst diese
 * Datei nie an.
 *
 * Kompatibel mit PHP 7.4 bis 8.5.
 */

/** Die eigenen Hausthemen, Thema => retained (true) / fluechtig (false). Reihenfolge = Sendereihenfolge. EINE Liste fuer
 *  Senden, Abraeumen, Reiter MQTT, Reiter Test und Selbstpruefung. */
function wd_haus_themen()
{
    $t = array('haus/wetter/datum' => true, 'haus/wetter/datum_text' => true, 'haus/wetter/zeit' => true, 'haus/wetter/sprache' => true);
    foreach (array('grad', 'gefuehlt_grad', 'feuchte_prozent', 'wind_kmh', 'boeen_kmh', 'regen', 'regen_mm_h') as $k) { $t['haus/wetter/jetzt/' . $k] = true; }
    foreach (array('heute', 'morgen') as $tag) {
        foreach (array('tief_grad', 'hoch_grad', 'regen_mm', 'regen_prozent', 'code', 'wort') as $k) { $t['haus/wetter/' . $tag . '/' . $k] = true; }
    }
    $t['haus/wetter/warnung/stufe'] = true; $t['haus/wetter/warnung/text'] = true;
    $t['haus/wetter/pollen/stufe'] = true; $t['haus/wetter/pollen/art'] = true;
    $t['haus/wetter/ok'] = false;
    $t['haus/wetter/zaehler/weatherdata'] = false;
    return $t;
}

/** Die 27 retained Themen (bis 0.9.12: 26) - abgeraeumt werden nur diese. */
function wd_haus_retained()
{
    return array_keys(array_filter(wd_haus_themen()));
}

/** Ohne Aussage: Temperaturen -999, uebrige Zahlen -1, Texte "-" (wie haus/klima, Entscheidung Nr. 5). */
function wd_haus_leerwert($thema)
{
    if ($thema === 'haus/wetter/datum_text') { return '-'; }
    if (preg_match('#/(sprache|wort|text|art)$#', $thema)) { return '-'; }
    return preg_match('#_grad$#', $thema) ? '-999' : '-1';
}

/** Zahl oder null. Zeichenketten nur, wenn sie ganz Zahl sind; nie NAN/INF. */
function wd_haus_zahl($v)
{
    if (is_int($v) || is_float($v)) { return is_finite((float) $v) ? (float) $v : null; }
    if (is_string($v) && is_numeric(trim($v))) { return (float) trim($v); }
    return null;
}

/** 0.9.13: haus/wetter/datum_text aus dem Wert von haus/wetter/datum - JJJJMMTT wird TT.MM.JJJJ (Tag und Monat
 *  zweistellig, Jahr vierstellig). Hat datum keine Aussage ("-1", "-" oder kein gueltiger Tag), ist es "-". */
function wd_haus_datum_text($datum)
{
    $d = (string) $datum;
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return '-'; }
    return $m[3] . '.' . $m[2] . '.' . $m[1];
}

/** Eine Nachkommastelle, ohne "-0.0". */
function wd_haus_eins($v, $leer)
{
    $v = wd_haus_zahl($v);
    if ($v === null) { return $leer; }
    $r = round($v, 1);
    return number_format($r == 0 ? 0.0 : $r, 1, '.', '');
}

/** Ganze Zahl im Bereich, sonst ohne Aussage. */
function wd_haus_ganz($v, $leer, $min, $max)
{
    $v = wd_haus_zahl($v);
    if ($v === null) { return $leer; }
    $r = (int) round($v);
    return ($r < $min || $r > $max) ? $leer : (string) $r;
}

/** Text ohne Zeilenumbruch und Steuerzeichen, hoechstens $n Byte (UTF-8-sicher); leer wird "-". */
function wd_haus_text($s, $n = 80)
{
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) $s));
    if (strlen($s) > $n) {
        $s = substr($s, 0, $n);
        while ($s !== '' && !preg_match('//u', $s)) { $s = substr($s, 0, -1); }     // nie ein halbes Zeichen am Ende
        $s = rtrim($s);
    }
    return $s === '' ? '-' : $s;
}

/**
 * Die Werte aller 29 Themen aus dem Zustand des Minutenlaufs (wd_zustand(): jetzt, gefuehlt, seite.tage, warn_ok, lagen,
 * pollen). Thema => Zeichenkette, nie leer. $jetzt: Zeit der Rechnung (Vorgabe st.ts).
 */
function wd_haus_werte(array $st, array $c, $zaehler, $jetzt = null)
{
    $jetzt = $jetzt === null ? (int) ($st['ts'] ?? time()) : (int) $jetzt;
    $sp = ((string) ($c['ansage_sprache'] ?? 'de')) === 'en' ? 'en' : 'de';
    $w = array();
    foreach (wd_haus_themen() as $t => $r) { $w[$t] = wd_haus_leerwert($t); }
    $w['haus/wetter/datum'] = date('Ymd', $jetzt);
    $w['haus/wetter/datum_text'] = wd_haus_datum_text($w['haus/wetter/datum']);
    $w['haus/wetter/zeit'] = (string) $jetzt;
    $w['haus/wetter/sprache'] = $sp;
    $j = isset($st['jetzt']) && is_array($st['jetzt']) ? $st['jetzt'] : array();
    $w['haus/wetter/jetzt/grad'] = wd_haus_eins($j['temp_c'] ?? null, '-999');
    $w['haus/wetter/jetzt/gefuehlt_grad'] = wd_haus_eins($st['gefuehlt'] ?? null, '-999');
    $w['haus/wetter/jetzt/feuchte_prozent'] = wd_haus_ganz($j['rf_pct'] ?? null, '-1', 0, 100);
    $w['haus/wetter/jetzt/wind_kmh'] = wd_haus_ganz($j['wind_kmh'] ?? null, '-1', 0, 400);
    $w['haus/wetter/jetzt/boeen_kmh'] = wd_haus_ganz($j['boe_kmh'] ?? null, '-1', 0, 500);
    $rg = wd_haus_zahl($j['regnet'] ?? null);
    $w['haus/wetter/jetzt/regen'] = $rg === null ? '-1' : ($rg > 0 ? '1' : '0');
    $rm = wd_haus_zahl($j['regen_mmh'] ?? null);
    $w['haus/wetter/jetzt/regen_mm_h'] = ($rm === null || $rm < 0) ? '-1' : wd_haus_eins($rm, '-1');
    // Heute und morgen aus den Tagen der Wetterseite (0.9.7): heute = ab der laufenden Stunde, Tief/Hoch bevorzugt der Open-Meteo-Tageswert.
    $tage = array();
    foreach ((isset($st['seite']['tage']) && is_array($st['seite']['tage'])) ? $st['seite']['tage'] : array() as $tg) {
        if (is_array($tg) && isset($tg['datum']) && is_string($tg['datum'])) { $tage[$tg['datum']] = $tg; }
    }
    $morgen = date('Y-m-d', mktime(12, 0, 0, (int) date('n', $jetzt), (int) date('j', $jetzt) + 1, (int) date('Y', $jetzt)));
    foreach (array('heute' => date('Y-m-d', $jetzt), 'morgen' => $morgen) as $tag => $d) {
        if (!isset($tage[$d])) { continue; }
        $tg = $tage[$d];
        $b = 'haus/wetter/' . $tag . '/';
        $w[$b . 'tief_grad'] = wd_haus_eins($tg['tmin'] ?? null, '-999');
        $w[$b . 'hoch_grad'] = wd_haus_eins($tg['tmax'] ?? null, '-999');
        $r = wd_haus_zahl($tg['r'] ?? null);
        $w[$b . 'regen_mm'] = ($r === null || $r < 0) ? '-1' : wd_haus_eins($r, '-1');
        $w[$b . 'regen_prozent'] = wd_haus_ganz($tg['rw'] ?? null, '-1', 0, 100);
        $cd = wd_haus_zahl($tg['c'] ?? null);
        if ($cd !== null && $cd >= 0 && $cd <= 99 && floor($cd) == $cd) {
            $w[$b . 'code'] = (string) (int) $cd;
            $wort = wd_t('SEITE.C_' . (int) $cd, $sp);
            $w[$b . 'wort'] = strpos($wort, 'SEITE.') === 0 ? '-' : wd_haus_text($wort, 60);
        }
    }
    // Warnung: nur Stufe und kurzer Name der hoechsten Lage, nie Volltext, Ort oder Zelle.
    if (!empty($st['warn_ok'])) {
        $l = (isset($st['lagen'][0]) && is_array($st['lagen'][0])) ? $st['lagen'][0] : null;
        $s = $l ? (int) ($l['stufe'] ?? 0) : 0;
        if ($l && $s >= 1 && $s <= 4) {
            $w['haus/wetter/warnung/stufe'] = (string) $s;
            $w['haus/wetter/warnung/text'] = wd_haus_text(wd_warn_name($l, $sp), 80);
        } else {
            $w['haus/wetter/warnung/stufe'] = '0';
            $w['haus/wetter/warnung/text'] = 'keine';
        }
    }
    // Pollen: hoechste Stufe heute ueber alle acht Arten; bei Gleichstand die erste in der festen Folge.
    if (isset($st['pollen']) && is_array($st['pollen'])) {
        $max = null; $art = '';
        foreach (wd_pollen_arten() as $a) {
            $k = strtolower($a);
            $v = wd_haus_zahl($st['pollen'][$k][0] ?? null);
            if ($v === null || $v < 0 || $v > 3) { continue; }
            if ($max === null || $v > $max) { $max = $v; $art = $k; }
        }
        if ($max !== null) {
            $w['haus/wetter/pollen/stufe'] = floor($max) == $max ? (string) (int) $max : number_format($max, 1, '.', '');
            $w['haus/wetter/pollen/art'] = $max > 0 ? $art : 'keine';
        }
    }
    $w['haus/wetter/ok'] = (!empty($st['jetzt_ok']) || !empty($st['prog_ok'])) ? '1' : '0';
    $w['haus/wetter/zaehler/weatherdata'] = (string) (((int) $zaehler % 1000 + 1000) % 1000);
    return $w;
}

/** Was geht in diesem Lauf hinaus? Vollversand (ohne Merker, nach dem Einschalten, alle 30 min) oder nur Geaendertes; ok und
 *  zaehler immer. zeit zaehlt nicht als Aenderung, geht aber mit jedem Versand eines anderen retained Themas mit.
 *  Rueckgabe Thema => array(Wert, retain). */
function wd_haus_auswahl(array $werte, $merker, $voll)
{
    $th = wd_haus_themen();
    $aus = array();
    $geaendert = false;
    foreach ($werte as $t => $v) {
        if (empty($th[$t]) || $t === 'haus/wetter/zeit') { continue; }
        if ($voll || !is_array($merker) || !array_key_exists($t, $merker) || (string) $merker[$t] !== (string) $v) {
            $aus[$t] = array((string) $v, true);
            $geaendert = true;
        }
    }
    if ($geaendert) { $aus = array('haus/wetter/zeit' => array((string) $werte['haus/wetter/zeit'], true)) + $aus; }
    foreach ($werte as $t => $v) { if (isset($th[$t]) && !$th[$t]) { $aus[$t] = array((string) $v, false); } }
    return $aus;
}

/**
 * Eine kurze Sitzung am Broker: CONNECT, PUBLISH der eigenen Hausthemen, SUBSCRIBE der zurueckzulesenden, zurueckbehaltene
 * Werte bis SUBACK + 1 s, DISCONNECT. Bauart fer_haus_sitzung() (Ferien und Feiertage 1.2.28).
 *   $senden  Thema => array(Wert, retain) - eine leere Nutzlast nur mit retain (Abraeumen), ein Thema mit # oder + nie
 *   $rueck   genaue Themen (kein # und kein +), deren zurueckbehaltene Werte gelesen werden
 * Rueckgabe array('lage' => ok | kein_broker | abgelehnt | zeit, 'werte' => Thema => Wert (nur retained PUBLISH),
 * 'gesendet' => n). MQTT 3.1.1, QoS 0, saubere Sitzung, Client-Kennung 'wdhaus<pid>', Keepalive 10 s. Zugang aus general.json;
 * das Kennwort steht nur im CONNECT-Paket, nie in einem Protokoll und nie auf einer Kommandozeile.
 */
function wd_haus_sitzung(array $senden, array $rueck = array())
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'gesendet' => 0);
    $p = wd_paths();
    if ($p['lbhome'] === '' || $p['general'] === '' || !is_file($p['general'])) { return $aus; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross]) && is_scalar($m[$gross])) { return (string) $m[$gross]; }
        return (isset($m[$klein]) && is_scalar($m[$klein])) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host) . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 2);
    $aus['lage'] = 'zeit';

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    $lies = function ($n) use ($s) {
        $d = '';
        $lies_bis = microtime(true) + 5.0;
        while (strlen($d) < $n) {
            if (microtime(true) >= $lies_bis) { return null; }
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    $bereit = function ($bis) use ($s) {
        $rest = $bis - microtime(true);
        if ($rest <= 0) { return false; }
        $r = array($s); $w = null; $x = null;
        $n = @stream_select($r, $w, $x, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        return $n !== false && $n > 0;
    };
    $ende_sitzung = function ($lage) use ($s, &$aus) {
        $aus['lage'] = $lage;
        @fwrite($s, chr(0xE0) . chr(0));
        fclose($s);
        return $aus;
    };

    $flags = 0x02;
    $nutz = $zk('wdhaus' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) === false) {
        fclose($s);
        return $aus;
    }
    $ack = $bereit(microtime(true) + 2.0) ? $paket() : null;
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) { return $ende_sitzung('zeit'); }
    if (ord($ack[1][1]) !== 0) { return $ende_sitzung('abgelehnt'); }

    foreach ($senden as $t => $e) {
        $e = array_values((array) $e);
        $t = (string) $t;
        $w = isset($e[0]) ? (string) $e[0] : '';
        $r = !empty($e[1]);
        if ($t === '' || strpbrk($t, "#+\0") !== false || ($w === '' && !$r)) { continue; }
        $rumpf = $zk($t) . $w;
        if (@fwrite($s, chr(0x30 | ($r ? 1 : 0)) . $laenge(strlen($rumpf)) . $rumpf) === false) {
            return $ende_sitzung('zeit');
        }
        $aus['gesendet']++;
    }

    $soll = array();
    foreach ($rueck as $t) {
        $t = (string) $t;
        if ($t !== '' && strpbrk($t, "#+\0") === false) { $soll[$t] = true; }
    }
    if ($soll) {
        $verarbeiten = function ($pk) use (&$aus, $soll) {
            if (strlen($pk[1]) < 2 || !($pk[0] & 1)) { return; }
            $tl = unpack('n', substr($pk[1], 0, 2));
            $t = substr($pk[1], 2, $tl[1]);
            if (!isset($soll[$t])) { return; }
            $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
            $aus['werte'][$t] = (string) substr($pk[1], $versatz);
        };
        $sub = pack('n', 1);
        foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
        if (@fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub) === false) { return $ende_sitzung('zeit'); }
        $bestaetigt = false;
        $bis = microtime(true) + 3.0;
        while (!$bestaetigt && $bereit($bis)) {
            $pk = $paket();
            if ($pk === null) { break; }
            $art = $pk[0] >> 4;
            if ($art === 9) {
                $rc = (string) substr($pk[1], 2);
                $nr = (strlen($pk[1]) >= 2) ? (int) unpack('n', substr($pk[1], 0, 2))[1] : 0;
                if ($nr !== 1 || strlen($rc) !== count($soll)) { return $ende_sitzung('abgelehnt'); }
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) { return $ende_sitzung('abgelehnt'); }
                }
                $bestaetigt = true;
            } elseif ($art === 3) {
                $verarbeiten($pk);
            }
        }
        if (!$bestaetigt) { return $ende_sitzung('zeit'); }
        $bis = microtime(true) + 1.0;
        while ($bereit($bis)) {
            $pk = $paket();
            if ($pk === null) { break; }
            if (($pk[0] >> 4) === 3) { $verarbeiten($pk); }
        }
    }
    return $ende_sitzung('ok');
}

/** Merker des Anbieters (Thema => zuletzt gesendeter Wert, Zeit des letzten Vollversands) und der letzten Sitzung - im
 *  Zwischenordner (/tmp, verfaellt mit Neustart und Update: dann geht der volle Satz hinaus). */
function wd_haus_merker_datei() { return wd_tmpdir() . '/haus_letzte.json'; }
function wd_haus_sitzung_datei() { return wd_tmpdir() . '/haus_sitzung.json'; }

/** Den Merker "war Anbieter" (_haus_frueher) setzen oder entfernen - mit Zweitschrift. Der Unterstrich haelt ihn aus der
 *  Sicherungsdatei heraus (wd_sicherung_lesen() uebergeht ihn). Rueckgabe true, wenn geschrieben wurde. */
function wd_haus_frueher_setzen($ja)
{
    $c = wd_config();
    if ((int) !empty($c['_haus_frueher']) === ($ja ? 1 : 0)) { return false; }
    if ($ja) { $c['_haus_frueher'] = 1; } else { unset($c['_haus_frueher']); }
    if (!wd_config_speichern($c)) { return false; }
    wd_zweitschrift($c);
    return true;
}

/**
 * Im Minutenlauf (wd_lauf_sperrt() nach dem MQTT-Versand). Nur mit haus_wetter_ein = 1 UND mqtt_ein = 1 UND einer erkannten
 * LoxBerry-Wurzel; sonst verfaellt der Merker, und war diese Installation Anbieter (_haus_frueher), raeumt der Lauf
 * hoechstens alle 10 Minuten die 27 retained Themen ab. Der Merker wird NUR nach einer Sitzung mit lage 'ok' fortgeschrieben.
 */
function wd_haus_lauf(array $st, array $c, $zaehler)
{
    $p = wd_paths();
    $mf = wd_haus_merker_datei();
    if (empty($c['haus_wetter_ein']) || empty($c['mqtt_ein']) || $p['lbhome'] === '') {
        if (is_file($mf)) { @unlink($mf); }
        if (!empty($c['_haus_frueher']) && $p['lbhome'] !== '') {
            $vf = wd_tmpdir() . '/haus_leeren_versuch';
            clearstatcache(true, $vf);
            $alt = is_file($vf) ? (int) filemtime($vf) : 0;
            if ($alt <= 0 || $alt > time() || time() - $alt >= 600) {
                @touch($vf);
                $e = wd_haus_leeren();
                wd_log('Hausthemen haus/wetter: Abraeumen im Minutenlauf - ' . $e['lage'] . ($e['offen'] ? ' (' . count($e['offen']) . ' Themen offen)' : '') . '.');
                if (in_array($e['lage'], array('geleert', 'nichts'), true)) { wd_haus_frueher_setzen(false); }
            }
        }
        return null;
    }
    $werte = wd_haus_werte($st, $c, $zaehler);
    $vorher = null;
    $voll_zeit = 0;
    $d = wd_json_lesen($mf);
    if (is_array($d) && isset($d['werte'], $d['voll']) && is_array($d['werte'])) { $vorher = $d['werte']; $voll_zeit = (int) $d['voll']; }
    $voll = ($vorher === null || $voll_zeit <= 0 || $voll_zeit > time() || time() - $voll_zeit > 1800);
    $r = wd_haus_sitzung(wd_haus_auswahl($werte, $vorher, $voll));
    if ($r['lage'] === 'ok') {
        $merk = array_intersect_key($werte, array_flip(wd_haus_retained()));
        wd_json_schreiben($mf, array('werte' => $merk, 'voll' => $voll ? time() : $voll_zeit, 'zeit' => time()));
        if (empty($c['_haus_frueher'])) { wd_haus_frueher_setzen(true); }
    }
    wd_json_schreiben(wd_haus_sitzung_datei(), array('zeit' => time(), 'lage' => $r['lage'], 'gesendet' => (int) $r['gesendet'], 'voll' => $voll ? 1 : 0, 'ok' => (int) $werte['haus/wetter/ok']));
    wd_log_einmal('haus', $r['lage'] === 'ok' ? 'Hausthemen haus/wetter: Sitzung am Broker ok.'
        : 'Hausthemen haus/wetter: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute.');
    return $r;
}

/**
 * Die 27 retained Themen abraeumen: erst zuruecklesen; ist nichts belegt -> 'nichts'. Sonst PUBLISH mit Retain-Bit und LEERER
 * Nutzlast fuer die belegten Themen und in derselben Sitzung zuruecklesen, bis zu drei Runden. Nur die eigenen Namen aus
 * wd_haus_themen(), nie haus/wetter/#, nie fremde Fuehler. Rueckgabe array('lage' => geleert | nichts | rest | kein_broker,
 * 'offen' => belegte Themen, 'grund' => Lage der gescheiterten Sitzung).
 */
function wd_haus_leeren($runden = 3, $th = null)
{
    $th = is_array($th) ? array_values($th) : wd_haus_retained();     // 0.9.10: auch fuer haus/pv (wd_pv_leeren), nie mit # oder +
    $belegt = function ($w) {
        $o = array();
        foreach ($w as $t => $v) { if ((string) $v !== '') { $o[] = $t; } }
        return $o;
    };
    $r = wd_haus_sitzung(array(), $th);
    if ($r['lage'] !== 'ok') { return array('lage' => 'kein_broker', 'offen' => array(), 'grund' => $r['lage']); }
    $offen = $belegt($r['werte']);
    if (!$offen) { return array('lage' => 'nichts', 'offen' => array(), 'grund' => ''); }
    $lage = 'rest';
    $grund = '';
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) { $senden[$t] = array('', true); }
        $r = wd_haus_sitzung($senden, $th);
        if ($r['lage'] !== 'ok') { $lage = 'kein_broker'; $grund = $r['lage']; continue; }
        $offen = $belegt($r['werte']);
        if (!$offen) { return array('lage' => 'geleert', 'offen' => array(), 'grund' => ''); }
        $lage = 'rest';
    }
    return array('lage' => $lage, 'offen' => $offen, 'grund' => $grund);
}

/**
 * Folgen einer Aenderung von haus_wetter_ein/mqtt_ein (Reiter MQTT, Zurueckspielen einer Sicherung): wird die Installation
 * Anbieter, verfaellt der Merker (der naechste Minutenlauf schickt den vollen Satz); hoert sie auf und war sie es (Haken und
 * MQTT an) oder traegt sie _haus_frueher, raeumt sie die 27 Themen mit Ruecklesen ab.
 * Rueckgabe array(Meldung oder '', 'ok'|'warn', neuer Merker _haus_frueher 0/1 oder null = unveraendert).
 */
function wd_haus_folgen(array $alt, array $neu)
{
    $war = !empty($alt['haus_wetter_ein']) && !empty($alt['mqtt_ein']);
    $frueher = !empty($alt['_haus_frueher']);
    $ist = !empty($neu['haus_wetter_ein']) && !empty($neu['mqtt_ein']);
    @unlink(wd_haus_merker_datei());
    if ($ist) { return array($war ? '' : wd_t('HAUS.M_EIN'), 'ok', null); }
    if (!$war && !$frueher) { return array('', 'ok', null); }
    $e = wd_haus_leeren();
    wd_log('Hausthemen haus/wetter: Abraeumen ' . $e['lage'] . ($e['offen'] ? ' (' . count($e['offen']) . ' Themen offen)' : '') . '.');
    if ($e['lage'] === 'geleert') { return array(sprintf(wd_t('HAUS.M_GELEERT'), count(wd_haus_retained())), 'ok', 0); }
    if ($e['lage'] === 'nichts') { return array(wd_t('HAUS.M_NICHTS'), 'ok', 0); }
    if ($e['lage'] === 'rest') { return array(sprintf(wd_t('HAUS.M_REST'), count($e['offen'])), 'warn', 1); }
    return array(wd_t('HAUS.M_KEIN_BROKER'), 'warn', 1);
}

/** Deinstallation und "wd_takt.php --haus-leeren": nur, wenn diese Installation Anbieter ist oder war. Ausgabe fuer das
 *  Installationsprotokoll; Rueckgabe immer 0 - die Deinstallation laeuft weiter. */
function wd_haus_leeren_ausgabe()
{
    $c = wd_config();
    if (empty($c['haus_wetter_ein']) && empty($c['_haus_frueher'])) {
        echo "<INFO> haus/wetter: diese Installation war nie Anbieter - nichts zu leeren.\n";
        return 0;
    }
    $e = wd_haus_leeren();
    if ($e['lage'] === 'geleert') {
        echo "<OK> haus/wetter: geleert (vom Broker bestaetigt, " . count(wd_haus_retained()) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo "<INFO> haus/wetter: nichts zu leeren (der Broker haelt keines der Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo "<WARNING> haus/wetter: noch zurueckbehalten - bitte von Hand leeren: " . implode(', ', $e['offen']) . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo "<WARNING> haus/wetter: der Broker war nicht erreichbar (" . $e['grund'] . ") - bitte von Hand leeren: haus/wetter/datum und die weiteren "
            . (count(wd_haus_retained()) - 1) . " Themen aus dem Reiter MQTT.\n";
    }
    return 0;
}

/** Die Zeile fuer den Reiter Test: array(null = aus/wartet, true = gesendet, false = gescheitert, Text). Ohne Kennwort. */
function wd_haus_testzeile(array $c)
{
    if (empty($c['haus_wetter_ein'])) { return array(null, wd_t('HAUS.T_AUS')); }
    if (empty($c['mqtt_ein'])) { return array(null, wd_t('HAUS.T_MQTT_AUS')); }
    $sz = wd_json_lesen(wd_haus_sitzung_datei());
    $mz = wd_json_lesen(wd_haus_merker_datei());
    if (!is_array($sz) || !isset($sz['lage'], $sz['zeit'])) { return array(null, wd_t('HAUS.T_NIE')); }
    $w = (is_array($mz) && isset($mz['werte']) && is_array($mz['werte'])) ? $mz['werte'] : array();
    $g = function ($t) use ($w) { $v = isset($w[$t]) ? (string) $w[$t] : '-'; return in_array($v, array('-', '-1', '-999'), true) ? wd_t('HAUS.OHNE') : $v; };
    $frisch = $sz['lage'] === 'ok' && time() - (int) $sz['zeit'] <= 150;
    if (!$frisch) {
        return array(false, sprintf(wd_t('HAUS.T_FEHL'), date('H:i:s', (int) $sz['zeit']), wd_t('HAUS.LAGE_' . strtoupper(preg_replace('/[^a-z_]/', '', (string) $sz['lage'])))));
    }
    return array(true, sprintf(wd_t('HAUS.T_AN'), date('H:i:s', (int) $sz['zeit']), $g('haus/wetter/jetzt/grad'), $g('haus/wetter/heute/hoch_grad'), $g('haus/wetter/warnung/stufe'), $g('haus/wetter/pollen/stufe'),
        !empty($sz['ok']) ? wd_t('TEXT.JA') : wd_t('TEXT.NEIN')));
}

/** Selbstpruefung ohne Broker: Werte zweier fester Zustaende (voll, leer) gegen die Themenliste. Rueckgabe array(ok, Hinweis). */
function wd_haus_vertrag_pruefen()
{
    $j = mktime(10, 0, 0, 6, 1, 2030);
    $voll = array('ts' => $j, 'jetzt_ok' => true, 'prog_ok' => true, 'warn_ok' => true, 'gefuehlt' => 6.04,
        'jetzt' => array('temp_c' => 7.26, 'rf_pct' => 81.4, 'wind_kmh' => 12.6, 'boe_kmh' => 33.0, 'regnet' => 1, 'regen_mmh' => 0.44),
        'seite' => array('tage' => array(array('datum' => '2030-06-01', 'tmin' => 3.0, 'tmax' => 9.46, 'c' => 80, 'r' => 2.35, 'rw' => 70),
                                          array('datum' => '2030-06-02', 'tmin' => -0.04, 'tmax' => 5.0, 'c' => 3, 'r' => 0.0, 'rw' => 10))),
        'lagen' => array(array('stufe' => 2, 'ii' => 52, 'klasse' => 'wind', 'vorab' => 0)),
        'pollen' => array('hasel' => array(0.0, 0.0), 'birke' => array(2.5, 2.0), 'graeser' => array(2.5, 1.0)));
    $leer = array('ts' => $j);
    $th = wd_haus_themen();
    $fehl = array();
    foreach (array('voll' => $voll, 'leer' => $leer) as $n => $st) {
        $w = wd_haus_werte($st, array('ansage_sprache' => 'de'), 1234, $j);
        if (array_keys($w) !== array_keys($th)) { $fehl[] = $n . ': Themen'; }
        foreach ($w as $t => $v) { if ((string) $v === '' || preg_match('/[\r\n]/', (string) $v)) { $fehl[] = $n . ': leer ' . $t; } }
    }
    $wv = wd_haus_werte($voll, array('ansage_sprache' => 'de'), 1234, $j);
    $wl = wd_haus_werte($leer, array('ansage_sprache' => 'de'), 1234, $j);
    $soll = array('haus/wetter/jetzt/grad' => '7.3', 'haus/wetter/jetzt/regen' => '1', 'haus/wetter/heute/regen_mm' => '2.4', 'haus/wetter/heute/code' => '80', 'haus/wetter/morgen/tief_grad' => '0.0',
                  'haus/wetter/warnung/stufe' => '2', 'haus/wetter/pollen/stufe' => '2.5', 'haus/wetter/pollen/art' => 'birke', 'haus/wetter/zaehler/weatherdata' => '234', 'haus/wetter/datum' => '20300601',
                  'haus/wetter/datum_text' => '01.06.2030');
    foreach ($soll as $t => $v) { if (($wv[$t] ?? null) !== $v) { $fehl[] = $t . '=' . ($wv[$t] ?? 'fehlt'); } }
    // 0.9.13: datum_text direkt hinter datum, retained wie datum; ohne Aussage genau dann, wenn datum keine hat.
    $tk = array_keys($th);
    $ip = array_search('haus/wetter/datum', $tk, true);
    if ($ip === false || ($tk[$ip + 1] ?? '') !== 'haus/wetter/datum_text' || $th['haus/wetter/datum_text'] !== $th['haus/wetter/datum']) { $fehl[] = 'datum_text: Stelle/retain'; }
    foreach (array('-1' => '-', '-' => '-', '' => '-', '20301231' => '31.12.2030', '20300231' => '-', '2030061' => '-') as $d => $v) {
        if (wd_haus_datum_text((string) $d) !== $v) { $fehl[] = 'datum_text(' . $d . ')=' . wd_haus_datum_text((string) $d); }
    }
    if (wd_haus_leerwert('haus/wetter/datum_text') !== '-') { $fehl[] = 'datum_text: leerwert'; }
    foreach (array('haus/wetter/jetzt/grad' => '-999', 'haus/wetter/heute/regen_mm' => '-1', 'haus/wetter/warnung/stufe' => '-1', 'haus/wetter/warnung/text' => '-', 'haus/wetter/pollen/art' => '-', 'haus/wetter/ok' => '0') as $t => $v) {
        if (($wl[$t] ?? null) !== $v) { $fehl[] = 'leer ' . $t . '=' . ($wl[$t] ?? 'fehlt'); }
    }
    $n = count(wd_haus_retained());
    if ($n !== 27 ||!empty($th['haus/wetter/ok']) || !empty($th['haus/wetter/zaehler/weatherdata'])) { $fehl[] = 'retain'; }
    return array(!$fehl, $fehl ? implode(', ', array_slice($fehl, 0, 6)) : sprintf(wd_t('HAUS.VERTRAG_OK'), count($th), $n));
}
