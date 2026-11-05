<?php
/**
 * Weatherdata - Quellen und Lauf: Netzabruf, Open-Meteo, DWD-CAP (ZIP und XML ohne Erweiterungen), Bright Sky,
 * Station ueber die Ecowitt-Weiche, Zusammenfuehrung, Versand, Ansage.
 *
 * Quellen und Lizenzen stehen im Reiter Quellen und im README. Unwetterzentrale ist bewusst nicht eingebaut.
 * Prueffaelle ersetzen den Netzweg ueber $GLOBALS['wd_http_attrappe'], nie ueber eine Einstellung.
 */

define('WD_UA', 'LoxBerry-Plugin-Weatherdata (github.com/timanders22/LoxBerry-Plugin-Weatherdata)');

/** GET. Rueckgabe array(HTTP-Code, Rumpf, Kopfzeilen-Text). Code 0 = keine Antwort. Die Adresse kommt nie ins Protokoll. */
function wd_http($url, $tmo = 20, array $kopf = array())
{
    if (isset($GLOBALS['wd_http_attrappe']) && is_callable($GLOBALS['wd_http_attrappe'])) { return call_user_func($GLOBALS['wd_http_attrappe'], $url, $kopf); }
    $gz = in_array('Accept-Encoding: gzip', $kopf, true);     // Bright Sky /radar verlangt gzip
    $kz = array_merge(array('User-Agent: ' . WD_UA), $gz ? array() : array('Accept-Encoding: identity'), $kopf);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hk = '';
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $tmo,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $kz, CURLOPT_HEADERFUNCTION => function ($c, $z) use (&$hk) { $hk .= $z; return strlen($z); }));
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return array($b === false ? 0 : $code, $b === false ? '' : wd_entpacken((string) $b, $hk), $hk);
    }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3, 'header' => implode("\r\n", $kz) . "\r\n")));
    $fh = @fopen($url, 'r', false, $ctx);
    if ($fh === false) { return array(0, '', ''); }
    $meta = stream_get_meta_data($fh);
    $b = (string) stream_get_contents($fh);
    fclose($fh);
    $hk = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? implode("\n", $meta['wrapper_data']) : '';
    preg_match_all('#HTTP/\S+\s+(\d{3})#', $hk, $m);
    return array($m[1] ? (int) end($m[1]) : 0, wd_entpacken($b, $hk), $hk);
}

function wd_entpacken($b, $hk)
{
    if (preg_match('/^Content-Encoding:\s*gzip/mi', (string) $hk) && function_exists('gzdecode')) { $x = @gzdecode($b); if ($x !== false) { return $x; } }
    return $b;
}

/** Merker je Quelle: letzter Erfolg, Versuch, Meldung. Datei liegt im Datenordner. */
function wd_quelle_lesen($q)
{
    $d = wd_json_lesen(wd_datadir() . '/quelle_' . $q . '.json');
    return is_array($d) ? $d + array('ok_ts' => 0, 'versuch_ts' => 0, 'meldung' => '', 'fehl' => 0, 'etag' => '') : array('ok_ts' => 0, 'versuch_ts' => 0, 'meldung' => '', 'fehl' => 0, 'etag' => '');
}

function wd_quelle_merken($q, array $m) { wd_json_schreiben(wd_datadir() . '/quelle_' . $q . '.json', $m); }

/** Bremse: Nach Fehlschlaegen wird der Abstand laenger, hoechstens 60 Minuten. */
function wd_faellig(array $m, $takt_min, $jetzt)
{
    $abst = (int) $takt_min * 60;
    if ((int) $m['fehl'] > 0) { $abst = min(3600, max(120, 120 * (int) pow(2, min(5, (int) $m['fehl'] - 1)))); }
    return $jetzt - (int) $m['versuch_ts'] >= $abst - 5;
}

function wd_position($c)
{
    $la = trim((string) $c['lat']); $lo = trim((string) $c['lon']);
    if ($la === '' || $lo === '') { return null; }
    $n = max(2, min(4, (int) $c['pos_stellen']));
    return array(round((float) $la, $n), round((float) $lo, $n));
}

/* ---------------------------------- Open-Meteo ---------------------------------- */

function wd_om_url($c)
{
    $pos = wd_position($c);
    if ($pos === null) { return ''; }
    $key = trim((string) $c['om_apikey']);
    $u = ($key !== '' ? 'https://customer-api.open-meteo.com' : 'https://api.open-meteo.com') . '/v1/forecast?latitude=' . $pos[0] . '&longitude=' . $pos[1]
       . '&timezone=auto&forecast_days=' . (!empty($c['emu_ein']) ? 8 : 7) . '&past_hours=1&wind_speed_unit=kmh'
       . '&current=temperature_2m,relative_humidity_2m,dew_point_2m,surface_pressure,wind_speed_10m,wind_gusts_10m,wind_direction_10m,precipitation,shortwave_radiation,uv_index,weather_code'
       . '&hourly=temperature_2m,relative_humidity_2m,precipitation,precipitation_probability,snowfall,wind_speed_10m,wind_gusts_10m,shortwave_radiation,uv_index,weather_code' . (!empty($c['emu_ein']) ? ',wind_direction_10m,pressure_msl,cloud_cover_low,cloud_cover_mid,cloud_cover_high' : '')
       . '&daily=temperature_2m_max,temperature_2m_min,weather_code';
    return $key !== '' ? $u . '&apikey=' . rawurlencode($key) : $u;
}

/** Wandelt die Antwort in die eigene Form. Rueckgabe null, wenn sie nicht passt: Inhalt pruefen, nicht HTTP 200 glauben. */
function wd_om_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['hourly']['time'], $d['hourly']['temperature_2m'], $d['current'], $d['daily']['time']) || !is_array($d['hourly']['time']) || count($d['hourly']['time']) < 24) { return null; }
    $off = isset($d['utc_offset_seconds']) ? (int) $d['utc_offset_seconds'] : 0;
    $zt = function ($s) use ($off) { $t = strtotime((string) $s . ':00 UTC'); return $t === false ? 0 : $t - $off; };
    $h = $d['hourly']; $std = array();
    $w = function ($k, $i) use ($h) { return (isset($h[$k][$i]) && is_numeric($h[$k][$i])) ? (float) $h[$k][$i] : null; };
    foreach ($h['time'] as $i => $t) {
        $std[] = array('ts' => $zt($t), 't' => $w('temperature_2m', $i), 'rf' => $w('relative_humidity_2m', $i), 'regen' => $w('precipitation', $i), 'regen_w' => $w('precipitation_probability', $i),
            'schnee' => $w('snowfall', $i), 'wind' => $w('wind_speed_10m', $i), 'boe' => $w('wind_gusts_10m', $i), 'solar' => $w('shortwave_radiation', $i), 'uv' => $w('uv_index', $i),
            'code' => $w('weather_code', $i), 'richt' => $w('wind_direction_10m', $i), 'druck' => $w('pressure_msl', $i), 'wo1' => $w('cloud_cover_low', $i), 'wo2' => $w('cloud_cover_mid', $i), 'wo3' => $w('cloud_cover_high', $i));
    }
    $tage = array();
    foreach ($d['daily']['time'] as $i => $t) {
        $tage[] = array('datum' => (string) $t, 'tmax' => $d['daily']['temperature_2m_max'][$i] ?? null, 'tmin' => $d['daily']['temperature_2m_min'][$i] ?? null, 'code' => $d['daily']['weather_code'][$i] ?? null);
    }
    $c = $d['current'];
    $n = function ($k) use ($c) { return (isset($c[$k]) && is_numeric($c[$k])) ? (float) $c[$k] : null; };
    return array('stunden' => $std, 'tage' => $tage, 'jetzt' => array('temp_c' => $n('temperature_2m'), 'rf_pct' => $n('relative_humidity_2m'), 'taupunkt_c' => $n('dew_point_2m'), 'druck_hpa' => $n('surface_pressure'),
        'wind_kmh' => $n('wind_speed_10m'), 'boe_kmh' => $n('wind_gusts_10m'), 'windrichtung_grad' => $n('wind_direction_10m'), 'regen_mmh' => $n('precipitation'), 'strahlung_wm2' => $n('shortwave_radiation'), 'uv' => $n('uv_index'),
        'regnet' => $n('precipitation') === null ? null : ($n('precipitation') > 0 ? 1 : 0)));
}

function wd_om_holen($c, $jetzt, $zwingen = false)
{
    $m = wd_quelle_lesen('open_meteo');
    if (!$zwingen && !wd_faellig($m, $c['om_takt_min'], $jetzt)) { return; }
    $u = wd_om_url($c);
    if ($u === '') { return; }
    $m['versuch_ts'] = $jetzt;
    list($code, $b) = wd_http($u, 25);
    $d = $code === 200 ? wd_om_lesen($b) : null;
    if ($d !== null) {
        wd_json_schreiben(wd_datadir() . '/prog.json', $d);
        wd_bilanz_merken('open_meteo', $d['stunden'], $jetzt);
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1;
        $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
        wd_log_einmal('om', 'Open-Meteo: ' . $m['meldung']);
    }
    wd_quelle_merken('open_meteo', $m);
}

/* ---------------------------------- DWD-CAP ---------------------------------- */

/** ZIP lesen ohne ZipArchive: zentrales Verzeichnis, Verfahren gespeichert oder deflate. Rueckgabe Name => Inhalt oder null. */
function wd_zip_lesen($z)
{
    $n = strlen($z);
    if ($n < 22 || !function_exists('gzinflate')) { return null; }
    $eocd = strrpos($z, "PK\x05\x06");
    if ($eocd === false || $n - $eocd < 22) { return null; }
    $e = unpack('vanzahl/Vgroesse/Vstart', substr($z, $eocd + 10, 10));
    $o = (int) $e['start'];
    $aus = array();
    for ($i = 0; $i < (int) $e['anzahl'] && $i < 5000; $i++) {
        if (substr($z, $o, 4) !== "PK\x01\x02") { return null; }
        $f = unpack('vmethod', substr($z, $o + 10, 2));
        $g = unpack('Vcsize/Vusize/vnlen/vxlen/vclen', substr($z, $o + 20, 14));
        $lo = unpack('Vlocal', substr($z, $o + 42, 4));
        $name = substr($z, $o + 46, $g['nlen']);
        $o += 46 + $g['nlen'] + $g['xlen'] + $g['clen'];
        $l = (int) $lo['local'];
        if (substr($z, $l, 4) !== "PK\x03\x04" || (int) $g['usize'] > 8388608) { continue; }
        $h = unpack('vnlen/vxlen', substr($z, $l + 26, 4));
        $roh = substr($z, $l + 30 + $h['nlen'] + $h['xlen'], $g['csize']);
        if ($f['method'] === 0) { $aus[$name] = $roh; }
        elseif ($f['method'] === 8) { $x = @gzinflate($roh); if ($x !== false) { $aus[$name] = $x; } }
    }
    return $aus;
}

function wd_xml_wert($s)
{
    return html_entity_decode(trim((string) $s), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Eine CAP-Datei lesen, wenn sie die Warnzelle nennt. Liest mit Mustern, denn simplexml ist nicht ueberall da. Null = gehoert nicht zur Zelle. */
function wd_cap_lage($xml, $zelle)
{
    if (strpos($xml, '<value>' . $zelle . '</value>') === false) { return null; }
    if (!preg_match('#<info>(.*)</info>#s', $xml, $m)) { return null; }
    $i = preg_replace('#<area>.*</area>#s', '', $m[1]);
    $ein = function ($tag, $q) { return preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $q, $t) ? wd_xml_wert($t[1]) : ''; };
    $ec = array(); $gruppen = array();
    if (preg_match_all('#<eventCode>\s*<valueName>(.*?)</valueName>\s*<value>(.*?)</value>\s*</eventCode>#s', $i, $t, PREG_SET_ORDER)) {
        foreach ($t as $x) {
            if (trim($x[1]) === 'GROUP') { $gruppen[] = wd_xml_wert($x[2]); } else { $ec[trim($x[1])] = wd_xml_wert($x[2]); }
        }
    }
    $zt = function ($s) { $v = strtotime($s); return $v === false ? 0 : $v; };
    $verw = array();
    if (preg_match('#<references>(.*?)</references>#s', $xml, $r)) {
        foreach (preg_split('/\s+/', trim($r[1])) as $tr) { $p = explode(',', $tr); if (isset($p[1])) { $verw[] = $p[1]; } }
    }
    $art = $ein('event', $i);
    $beginn = $zt($ein('onset', $i));
    return array('id' => $ein('identifier', $xml), 'status' => $ein('status', $xml), 'msg' => $ein('msgType', $xml), 'verweise' => $verw,
        'ii' => wd_ii_zahl($ec['II'] ?? 0), 'gruppe' => $gruppen, 'art' => $art, 'stufe' => wd_stufe_aus_schwere($ein('severity', $i)),
        'vorab' => (stripos($art, 'VORABINFORMATION') !== false || stripos($art, 'PRE-WARNING') !== false || strtolower($ein('msgType', $xml)) === 'x' || strtolower($ein('urgency', $i)) === 'future') ? 1 : 0,
        'beginn' => $beginn > 0 ? $beginn : $zt($ein('effective', $i)), 'ende' => $zt($ein('expires', $i)),
        'titel' => $ein('headline', $i), 'volltext' => trim($ein('description', $i) . ' ' . $ein('instruction', $i)), 'quelle' => 'dwd');
}

/** Rueckgabe: array(Lagen) oder null, wenn die Datei nicht zu lesen war. Keine Lage = leere Liste, das ist eine Aussage. */
function wd_cap_auswerten($zip, $zelle)
{
    $dat = wd_zip_lesen($zip);
    if ($dat === null) { return null; }
    $o = array();
    foreach ($dat as $x) {
        if (strpos($x, '<alert') === false) { continue; }
        $l = wd_cap_lage($x, $zelle);
        if ($l !== null) { $o[] = $l; }
    }
    return $o;
}

function wd_dwd_url($c)
{
    $sp = (string) $c['ansage_sprache'] === 'en' ? 'EN' : 'DE';
    return 'https://opendata.dwd.de/weather/alerts/cap/COMMUNEUNION_DWD_STAT/Z_CAP_C_EDZW_LATEST_PVW_STATUS_PREMIUMDWD_COMMUNEUNION_' . $sp . '.zip';
}

function wd_dwd_holen($c, $jetzt, $zwingen = false)
{
    $m = wd_quelle_lesen('dwd');
    if (!$zwingen && !wd_faellig($m, $c['dwd_takt_min'], $jetzt)) { return; }
    if (trim((string) $c['warnzelle']) === '') { return; }
    $m['versuch_ts'] = $jetzt;
    $kopf = array();
    $f = wd_datadir() . '/warn_dwd.json';
    if ($m['etag'] !== '' && is_file($f)) { $kopf[] = 'If-None-Match: ' . $m['etag']; }
    list($code, $b, $hk) = wd_http(wd_dwd_url($c), 40, $kopf);
    if ($code === 304) { $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 304'; wd_quelle_merken('dwd', $m); return; }
    $l = $code === 200 ? wd_cap_auswerten($b, trim((string) $c['warnzelle'])) : null;
    if ($l !== null) {
        wd_json_schreiben($f, $l);
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
        $m['etag'] = preg_match('/^ETag:\s*(\S+)/mi', (string) $hk, $t) ? trim($t[1]) : '';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1; $m['etag'] = '';
        $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
        wd_log_einmal('dwd', 'DWD: ' . $m['meldung']);
    }
    wd_quelle_merken('dwd', $m);
}

/* ---------------------------------- Bright Sky ---------------------------------- */

function wd_bs_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['alerts']) || !is_array($d['alerts'])) { return null; }
    $zt = function ($s) { $v = strtotime((string) $s); return $v === false ? 0 : $v; };
    $o = array();
    foreach ($d['alerts'] as $a) {
        if (!is_array($a)) { continue; }
        $art = (string) ($a['event_de'] ?? '');
        $o[] = array('id' => (string) ($a['alert_id'] ?? ''), 'status' => (string) ($a['status'] ?? 'actual'), 'msg' => 'alert', 'verweise' => array(),
            'ii' => wd_ii_zahl($a['event_code'] ?? 0), 'gruppe' => '', 'art' => $art, 'stufe' => wd_stufe_aus_schwere($a['severity'] ?? ''), 'vorab' => stripos($art, 'VORABINFORMATION') !== false ? 1 : 0,
            'beginn' => $zt($a['onset'] ?? ($a['effective'] ?? '')), 'ende' => $zt($a['expires'] ?? ''), 'titel' => (string) ($a['headline_de'] ?? ''),
            'volltext' => trim((string) ($a['description_de'] ?? '') . ' ' . (string) ($a['instruction_de'] ?? '')), 'quelle' => 'brightsky');
    }
    return $o;
}

/** Bright Sky wird nur gefragt, wenn der DWD ausfaellt oder abgeschaltet ist (Fuehrung statt Vergleich). */
function wd_bs_holen($c, $jetzt, $zwingen = false)
{
    $m = wd_quelle_lesen('brightsky');
    if (!$zwingen && !wd_faellig($m, max(5, (int) $c['dwd_takt_min']), $jetzt)) { return; }
    $zl = trim((string) $c['warnzelle']);
    if ($zl === '') { return; }
    $m['versuch_ts'] = $jetzt;
    list($code, $b) = wd_http('https://api.brightsky.dev/alerts?warn_cell_id=' . rawurlencode($zl) . '&tz=UTC', 25);
    $l = $code === 200 ? wd_bs_lesen($b) : null;
    if ($l !== null) {
        wd_json_schreiben(wd_datadir() . '/warn_bs.json', $l);
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1;
        $m['meldung'] = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
        wd_log_einmal('bs', 'Bright Sky: ' . $m['meldung']);
    }
    wd_quelle_merken('brightsky', $m);
}

/** Warnzelle aus dem eingetragenen Standort vorschlagen. Rueckgabe array(Zelle, Name) oder null; gespeichert wird nur, was der Nutzer speichert. */
function wd_zelle_vorschlag($c)
{
    $pos = wd_position($c);
    if ($pos === null) { return null; }
    list($code, $b) = wd_http('https://api.brightsky.dev/alerts?lat=' . $pos[0] . '&lon=' . $pos[1], 20);
    $d = $code === 200 ? json_decode($b, true) : null;
    if (!is_array($d) || !isset($d['location']['warn_cell_id'])) { return null; }
    return array((string) $d['location']['warn_cell_id'], (string) ($d['location']['name'] ?? ''));
}

/* ---------------------------------- Station ueber die Weiche ---------------------------------- */

/** Wert und Einheit aus "12.3 km/h"; Platzhalter wie "---.-" ergeben null (Haus, 23.08.). */
function wd_st_zahl($e, $einheit_feld = true)
{
    if (!is_array($e) || !isset($e['val']) || !is_scalar($e['val'])) { return array(null, ''); }
    $v = trim((string) $e['val']);
    if (!preg_match('/^(-?[0-9]+(?:\.[0-9]+)?)\s*(.*)$/', $v, $m)) { return array(null, ''); }
    $u = trim($m[2]);
    if ($u === '' && $einheit_feld && isset($e['unit']) && is_scalar($e['unit'])) { $u = trim((string) $e['unit']); }
    return array((float) $m[1], $u);
}

function wd_st_umrechnen($w, $u, $art)
{
    if ($w === null) { return null; }
    $u = str_replace(array(' ', '/hr'), array('', '/h'), strtolower($u));
    switch ($art) {
        case 't': if ($u === 'f' || $u === "\xc2\xb0f") { return ($w - 32) * 5 / 9; } return ($u === '' || $u === 'c' || $u === "\xc2\xb0c") ? $w : null;
        case 'w': $f = array('' => 1.0, 'km/h' => 1.0, 'kmh' => 1.0, 'm/s' => 3.6, 'mph' => 1.609344, 'knots' => 1.852, 'kn' => 1.852, 'ft/s' => 1.09728); return isset($f[$u]) ? $w * $f[$u] : null;
        case 'r': $f = array('' => 1.0, 'mm/h' => 1.0, 'mm' => 1.0, 'in/h' => 25.4, 'in' => 25.4); return isset($f[$u]) ? $w * $f[$u] : null;
        case 'p': $f = array('' => 1.0, 'hpa' => 1.0, 'mbar' => 1.0, 'mb' => 1.0, 'inhg' => 33.8639, 'mmhg' => 1.33322, 'kpa' => 10.0); return isset($f[$u]) ? $w * $f[$u] : null;
        case 's': $f = array('' => 1.0, 'w/m2' => 1.0, "w/m\xc2\xb2" => 1.0, 'klux' => 7.8926, 'kfc' => 84.95); return isset($f[$u]) ? $w * $f[$u] : null;
    }
    return null;
}

/** Antwort der Station (live.php, get_livedata_info) in die eigene Form. Rueckgabe null, wenn die Aussenfeuchte fehlt (Regel der Weiche). */
function wd_st_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['common_list']) || !is_array($d['common_list'])) { return null; }
    $such = function ($l, $id) { foreach ((array) $l as $e) { if (is_array($e) && isset($e['id']) && (string) $e['id'] === $id) { return $e; } } return null; };
    $l = $d['common_list'];
    list($rf) = wd_st_zahl($such($l, '0x07'), false);
    if ($rf === null || $rf <= 0 || $rf > 100) { return null; }
    list($t, $tu) = wd_st_zahl($such($l, '0x02'));
    $t = wd_st_umrechnen($t, $tu, 't');
    if ($t === null) { return null; }
    $wert = function ($id, $art) use ($l, $such) { list($w, $u) = wd_st_zahl($such($l, $id)); return wd_st_umrechnen($w, $u, $art); };
    $regen = null; $regnet = null;
    foreach (array('piezoRain', 'rain') as $b) {
        if (!isset($d[$b]) || !is_array($d[$b])) { continue; }
        if ($regen === null) { list($w, $u) = wd_st_zahl($such($d[$b], '0x0E')); $regen = wd_st_umrechnen($w, $u, 'r'); }
        if ($regnet === null && $b === 'piezoRain') { list($w) = wd_st_zahl($such($d[$b], 'srain_piezo'), false); if ($w !== null) { $regnet = $w > 0 ? 1 : 0; } }
    }
    if ($regnet === null && $regen !== null) { $regnet = $regen > 0 ? 1 : 0; }
    $druck = null;
    if (isset($d['wh25'][0]) && is_array($d['wh25'][0])) {
        foreach (array('rel', 'abs') as $s) {
            if (isset($d['wh25'][0][$s]) && $druck === null && preg_match('/^(-?[0-9.]+)\s*(.*)$/', trim((string) $d['wh25'][0][$s]), $m)) { $druck = wd_st_umrechnen((float) $m[1], trim($m[2]), 'p'); }
        }
    }
    $innen = null;
    if (isset($d['wh25'][0]['intemp']) && preg_match('/^(-?[0-9.]+)\s*(.*)$/', trim((string) $d['wh25'][0]['intemp']), $m)) { $innen = wd_st_umrechnen((float) $m[1], trim($m[2]), 't'); }
    $rd = function ($x, $n = 1) { return $x === null ? null : round($x, $n); };
    return array('temp_c' => $rd($t), 'rf_pct' => $rd($rf, 0), 'taupunkt_c' => $rd($wert('0x03', 't')), 'druck_hpa' => $rd($druck), 'wind_kmh' => $rd($wert('0x0B', 'w')), 'boe_kmh' => $rd($wert('0x0C', 'w')),
        'windrichtung_grad' => $rd($wert('0x0A', 'x') ?? (function () use ($l, $such) { list($w) = wd_st_zahl($such($l, '0x0A'), false); return $w; })(), 0),
        'regen_mmh' => $rd($regen), 'regnet' => $regnet, 'strahlung_wm2' => $rd($wert('0x15', 's')), 'uv' => $rd((function () use ($l, $such) { list($w) = wd_st_zahl($such($l, '0x17'), false); return $w; })()), 'innen_c' => $rd($innen));
}

/** Ist die Weiche installiert? Der Ordner steht in der Einstellung; nichts wird geraten. */
function wd_st_vorhanden($c)
{
    $h = wd_paths()['lbhome'];
    return $h !== '' && is_dir($h . '/config/plugins/' . $c['station_ordner']);
}

/** Token der Weiche, nur im Speicher: Es steht in ihrer eigenen Konfiguration und wird hier nicht abgelegt. Die Datei heisst in der Weiche immer ecowitt.json, unabhaengig vom Ordner (ew_paths). */
function wd_st_token_datei($c) { return wd_paths()['lbhome'] . '/config/plugins/' . $c['station_ordner'] . '/ecowitt.json'; }

function wd_st_token($c)
{
    $d = wd_json_lesen(wd_st_token_datei($c));
    return (is_array($d) && isset($d['token']) && is_string($d['token'])) ? $d['token'] : '';
}

/** Station lesen und die Plausibilitaet pruefen: Sprung, Einfrieren, Abstand zur Prognose. Rueckgabe array(Werte oder null, Grund). */
function wd_st_holen($c, $jetzt)
{
    if (empty($c['station_ein'])) { return array(null, ''); }
    $art = (string) $c['station_art'];
    if ($art === 'weiche') {
        if (!wd_st_vorhanden($c)) { return array(null, ''); }
        $tok = wd_st_token($c);
        list($code, $b) = wd_http('http://127.0.0.1:' . wd_webport() . '/plugins/' . $c['station_ordner'] . '/live.php' . ($tok !== '' ? '?token=' . rawurlencode($tok) : ''), 8);
    } elseif ($art === 'direkt') {
        if (trim((string) $c['station_host1'] . (string) $c['station_host2']) === '') { return array(null, ''); }
        list($code, $b) = wd_st_direkt($c);
    } else {
        $e = wd_json_lesen(wd_tmpdir() . '/empfang.json');
        if (!is_array($e)) { return array(null, ''); }
        $code = $jetzt - (int) $e['ts'] <= 600 ? 200 : 0; $b = '';
    }
    $m = wd_quelle_lesen('station');
    $m['versuch_ts'] = $jetzt;
    $w = $code === 200 ? ($art === 'empfang' ? wd_empfang_lesen($e['e']) : wd_st_lesen($b)) : null;
    $grund = '';
    $verl = wd_json_lesen(wd_datadir() . '/station_verlauf.json');
    if (!is_array($verl)) { $verl = array('l' => array(), 'gleich_seit' => 0, 'sig' => ''); }
    if ($w === null) {
        $grund = $code === 200 ? wd_t('QUELLE.INHALT') : 'HTTP ' . $code;
    } else {
        $alt = end($verl['l']);
        if (is_array($alt) && $jetzt - (int) $alt['ts'] <= 900 && abs((float) $w['temp_c'] - (float) $alt['t']) > (float) $c['station_sprung_k']) { $grund = wd_t('QUELLE.SPRUNG'); }
        $pj = wd_json_lesen(wd_datadir() . '/prog.json');
        if (is_array($pj) && isset($pj['jetzt']['temp_c']) && $pj['jetzt']['temp_c'] !== null && abs((float) $w['temp_c'] - (float) $pj['jetzt']['temp_c']) > 20.0) { $grund = wd_t('QUELLE.SPRUNG'); }
        $sig = $w['temp_c'] . '|' . $w['rf_pct'] . '|' . $w['wind_kmh'] . '|' . $w['strahlung_wm2'] . '|' . $w['druck_hpa'];
        if ($sig !== $verl['sig']) { $verl['sig'] = $sig; $verl['gleich_seit'] = $jetzt; }
        elseif ($jetzt - (int) $verl['gleich_seit'] > (int) $c['station_gleich_min'] * 60) { $grund = wd_t('QUELLE.EINGEFROREN'); }
    }
    if ($grund === '' && $w !== null) {
        $verl['l'][] = array('ts' => $jetzt, 't' => $w['temp_c'], 'r' => $w['rf_pct'], 'w' => $w['wind_kmh']);
        $verl['l'] = array_values(array_filter($verl['l'], function ($z) use ($jetzt) { return $jetzt - (int) $z['ts'] <= 960; }));
        $m['ok_ts'] = $jetzt; $m['fehl'] = 0; $m['meldung'] = 'HTTP 200';
    } else {
        $m['fehl'] = (int) $m['fehl'] + 1; $m['meldung'] = $grund;
        wd_log_einmal('st', 'Station: ' . $grund);
    }
    wd_json_schreiben(wd_datadir() . '/station_verlauf.json', $verl);
    wd_quelle_merken('station', $m);
    return array($grund === '' ? $w : null, $grund);
}

/** 10-Minuten-Mittel fuer die gefuehlte Temperatur der Station. */
function wd_st_mittel($jetzt)
{
    $v = wd_json_lesen(wd_datadir() . '/station_verlauf.json');
    $s = array('t' => array(), 'r' => array(), 'w' => array());
    foreach (is_array($v) ? (array) $v['l'] : array() as $z) {
        if ($jetzt - (int) $z['ts'] > 600) { continue; }
        foreach ($s as $k => $_) { if (isset($z[$k]) && $z[$k] !== null) { $s[$k][] = (float) $z[$k]; } }
    }
    $mw = function ($o) { return $o ? array_sum($o) / count($o) : null; };
    return array($mw($s['t']), $mw($s['r']), $mw($s['w']));
}

/* ---------------------------------- Zusammenfuehrung ---------------------------------- */

/** Alle Werte und Signale aus den gespeicherten Antworten. Reine Lese-Funktion bis auf die Merker der Hysterese. */
function wd_zustand(array $c, $station, $jetzt)
{
    $st = array('ts' => $jetzt);
    $q = array();
    foreach (wd_quellen_liste() as $d => $h) {
        $m = wd_quelle_lesen($d);
        $alter = (int) $m['ok_ts'] > 0 ? $jetzt - (int) $m['ok_ts'] : null;
        $q[$d] = array('ein' => !empty($c[$h]), 'alter_s' => $alter, 'meldung' => $m['meldung'], 'fehl' => (int) $m['fehl']);
    }
    $om_grenze = (int) $c['prog_max_alter_min'] * 60; $warn_grenze = (int) $c['warn_max_alter_min'] * 60;
    foreach (array_keys(wd_prog_quellen()) as $d) { $q[$d]['ok'] = $q[$d]['ein'] && $q[$d]['alter_s'] !== null && $q[$d]['alter_s'] <= ($d === 'wttr' ? max($om_grenze, (wd_wttr_takt_min() + 30) * 60) : $om_grenze); }
    foreach (array('luft' => 180, 'pollen' => 2880, 'radar' => 20, 'nina' => 30, 'meteoalarm' => 60) as $d => $g) { $q[$d]['ok'] = $q[$d]['ein'] && $q[$d]['alter_s'] !== null && $q[$d]['alter_s'] <= $g * 60; }
    $q['dwd']['ok'] = $q['dwd']['ein'] && $q['dwd']['alter_s'] !== null && $q['dwd']['alter_s'] <= $warn_grenze;
    $q['brightsky']['ok'] = $q['brightsky']['ein'] && $q['brightsky']['alter_s'] !== null && $q['brightsky']['alter_s'] <= $warn_grenze;
    $q['station']['ok'] = $q['station']['ein'] && $station !== null;
    // 0.9.7: eigene Messwerte ueber Wunderground und von Loxone
    $wu = wd_wu_aktuell($c, $jetzt); $lox = wd_lox_aktuell($c, $jetzt);
    $q['wunderground']['ok'] = $q['wunderground']['ein'] && $wu !== null;
    $q['loxone']['ok'] = $q['loxone']['ein'] && $lox !== array();
    $st['ma_dienst'] = (!empty($c['ma_ein']) && function_exists('wd_ma_dienst')) ? wd_ma_dienst((string) $c['ma_land']) : '';
    $st['quellen'] = $q;

    // Prognose
    $prog = wd_prog_gesamt($c, $q, $jetzt);
    $st['prog_ok'] = is_array($prog) && isset($prog['stunden']);
    $std = $st['prog_ok'] ? $prog['stunden'] : array();
    $st['prog'] = $st['prog_ok'] ? wd_prog_fenster($std, $jetzt) : array();
    $st['prog_stunden'] = $std;      // nur fuer den Emulator; wd_lauf_sperrt nimmt es vor dem Schreiben heraus
    $st['tage'] = array();
    if ($st['prog_ok']) {
        $heute = date('Y-m-d', $jetzt);
        $i = 0;
        foreach ($prog['tage'] as $t) { if ((string) $t['datum'] >= $heute && $i < 3) { $st['tage'][$i++] = $t; } }
    }
    // 0.9.7: Stunden und Tage fuer die Wetterseite, ohne Standort. Wettercodes nur von Open-Meteo, solange es lebt.
    $omd = !empty($q['open_meteo']['ok']) ? wd_json_lesen(wd_datadir() . '/prog.json') : null;
    $st['seite'] = wd_seite_daten($std, (is_array($omd) && isset($omd['stunden']) && is_array($omd['stunden'])) ? $omd['stunden'] : array(), ($st['prog_ok'] && is_array($prog['tage'])) ? $prog['tage'] : array(), $jetzt);

    // Jetzt: Station gewinnt, solange sie plausibel ist; dann Wunderground, dann Loxone (0.9.7); Rueckfall Prognose "jetzt", ausdruecklich gekennzeichnet
    $jw = array(); $her = array();
    $om_j = $st['prog_ok'] ? $prog['jetzt'] : array();
    foreach (array('temp_c', 'rf_pct', 'taupunkt_c', 'druck_hpa', 'wind_kmh', 'boe_kmh', 'windrichtung_grad', 'regen_mmh', 'regnet', 'strahlung_wm2', 'uv') as $k) {
        if (is_array($station) && isset($station[$k]) && $station[$k] !== null) { $jw[$k] = $station[$k]; $her[$k] = 'station'; }
        elseif (is_array($wu) && isset($wu[$k]) && $wu[$k] !== null) { $jw[$k] = $wu[$k]; $her[$k] = 'wunderground'; }
        elseif (isset($lox[$k])) { $jw[$k] = $lox[$k]; $her[$k] = 'loxone'; }
        elseif (isset($om_j[$k]) && $om_j[$k] !== null) { $jw[$k] = $om_j[$k]; $her[$k] = $q['open_meteo']['ok'] ? 'open-meteo' : $prog['herkunft']; }
        else { $jw[$k] = null; $her[$k] = 'keine'; }
    }
    // Taupunkt passt nur zu Temperatur und Feuchte derselben Herkunft; kommt eins davon von Loxone, wird er gerechnet.
    if (($her['temp_c'] === 'loxone' || $her['rf_pct'] === 'loxone') && $her['taupunkt_c'] !== 'station' && $her['taupunkt_c'] !== 'wunderground') { $jw['taupunkt_c'] = null; }
    if ($jw['taupunkt_c'] === null && $jw['temp_c'] !== null && $jw['rf_pct'] !== null) { $jw['taupunkt_c'] = wd_taupunkt($jw['temp_c'], $jw['rf_pct']); $her['taupunkt_c'] = 'rechnung'; }
    $st['jetzt'] = $jw; $st['herkunft'] = $her;
    // 0.9.7: nur zur Anzeige im Reiter Werte (Vergleich eigene Messung gegen Wetterdienst); keine Logik haengt daran, kein MQTT-Thema.
    $st['dienst'] = array(); foreach (array_keys($jw) as $k) { $st['dienst'][$k] = (isset($om_j[$k]) && is_numeric($om_j[$k])) ? (float) $om_j[$k] : null; }
    $st['dienst_her'] = $st['prog_ok'] ? ($q['open_meteo']['ok'] ? 'open-meteo' : (string) $prog['herkunft']) : 'keine';
    $st['jetzt_ok'] = $jw['temp_c'] !== null;
    $st['ist_ersatz'] = ($st['jetzt_ok'] && !in_array($her['temp_c'], array('station', 'wunderground', 'loxone'), true)) ? 1 : 0;

    // Gefuehlt: Station 10-Minuten-Mittel, sonst Prognose jetzt; dieselbe Formel
    if ($her['temp_c'] === 'station') {
        list($mt, $mr, $mw) = wd_st_mittel($jetzt);
        list($g, $ga) = wd_gefuehlt($mt ?? $jw['temp_c'], $mr ?? $jw['rf_pct'], $mw ?? $jw['wind_kmh']);
    } else {
        list($g, $ga) = wd_gefuehlt($jw['temp_c'], $jw['rf_pct'], $jw['wind_kmh']);
    }
    $st['gefuehlt'] = $g; $st['gefuehlt_art'] = $ga;
    list($pg, $pl, $puv) = $st['prog_ok'] ? wd_prog_heute_rest($std, $jetzt) : array(null, null, null);

    // Schwellen mit Hysterese
    $hz = wd_json_lesen(wd_datadir() . '/schwellen.json');
    if (!is_array($hz)) { $hz = array(); }
    $basis_j = $c['hitze_basis'] === 'luft' ? $jw['temp_c'] : $g;
    $basis_p = $c['hitze_basis'] === 'luft' ? $pl : $pg;
    $hmax = ($basis_j === null && $basis_p === null) ? null : max((float) ($basis_j ?? -999), (float) ($basis_p ?? -999));
    $st['hitze_max'] = $hmax;
    $s = array(); $hh = (float) $c['hysterese_k'];
    foreach (array(1 => 'hitze_32', 2 => 'hitze_35', 3 => 'hitze_38') as $i => $n) { $s[$n] = wd_hysterese($hmax, (float) $c['hitze' . $i . '_c'], $hh, !empty($hz[$n])); }
    $tmin = $st['prog']['h12/tmin_c'] ?? null;
    $s['frost'] = $tmin === null ? null : wd_hysterese(-(float) $tmin, -(float) $c['frost_c'], $hh, !empty($hz['frost']));
    $boe = max((float) ($jw['boe_kmh'] ?? -1), (float) ($st['prog']['h12/boe_max_kmh'] ?? -1));
    $s['sturm'] = ($jw['boe_kmh'] === null && !isset($st['prog']['h12/boe_max_kmh'])) ? null : wd_hysterese($boe, (float) $c['sturm_kmh'], 5.0, !empty($hz['sturm']));
    $r1 = $st['prog']['h1/regen_mm'] ?? null; $r12 = $st['prog']['h12/regen_mm'] ?? null;
    $s['regen'] = ($r1 === null && $r12 === null && $jw['regen_mmh'] === null) ? null : (((float) ($jw['regen_mmh'] ?? 0) >= (float) $c['regen1h_mm'] || (float) ($r1 ?? 0) >= (float) $c['regen1h_mm'] || (float) ($r12 ?? 0) >= (float) $c['regen12h_mm']) ? 1 : 0);
    wd_json_schreiben(wd_datadir() . '/schwellen.json', array_map(function ($v) { return (int) $v; }, $s));
    $st['schwelle'] = $s;
    $st['schwelle_ok'] = $hmax !== null || $tmin !== null;
    $st['schwelle_regen'] = $s['regen']; $st['schwelle_sturm'] = $s['sturm'];
    $st['hitze_stufe'] = !empty($s['hitze_38']) ? 3 : (!empty($s['hitze_35']) ? 2 : (!empty($s['hitze_32']) ? 1 : 0));

    // Warnungen: Fuehrung statt Vergleich. DWD lebt = DWD. Sonst Bright Sky. Sonst keine Aussage.
    $quelle = 'keine'; $roh = null;
    if ($q['dwd']['ok']) { $roh = wd_json_lesen(wd_datadir() . '/warn_dwd.json'); $quelle = 'dwd'; }
    elseif ($q['brightsky']['ok']) { $roh = wd_json_lesen(wd_datadir() . '/warn_bs.json'); $quelle = 'brightsky'; }
    elseif ($q['meteoalarm']['ok']) { $roh = wd_json_lesen(wd_datadir() . '/meteoalarm.json'); $quelle = 'meteoalarm'; }
    if (!is_array($roh)) { $quelle = 'keine'; $roh = null; }
    $st['warn_ok'] = $roh !== null;
    $st['warn_quelle'] = $quelle;
    $st['lagen'] = $roh !== null ? wd_lagen_ordnen($roh, $jetzt) : array();
    $st['herkunft']['prog'] = $st['prog_ok'] ? $prog['herkunft'] : 'keine';
    $st['herkunft']['warnung'] = $quelle;
    $amtl_hitze = false; $amtl_uv = false; $stark = false; $storm = false; $vor = (int) $c['sturm_vor_min'] * 60;
    foreach ($st['lagen'] as $l) {
        if ($l['klasse'] === 'hitze') { $amtl_hitze = true; }
        if ($l['klasse'] === 'uv') { $amtl_uv = true; }
        if ($l['klasse'] === 'regen' && (int) $l['stufe'] >= 2) { $stark = true; }
        if (in_array($l['klasse'], array('wind', 'gewitter'), true) && (int) $l['stufe'] >= 2 && (int) $l['beginn'] <= $jetzt + $vor) { $storm = true; }
    }
    // Handlungssignale, getrennt von der amtlichen Stufe
    $st['hitze_warnung'] = ($st['hitze_stufe'] > 0 || $amtl_hitze) ? 1 : 0;
    $st['hitze_heute'] = $st['hitze_warnung'];
    $uv_now = $jw['uv']; $st['uv_max'] = max((float) ($uv_now ?? 0), (float) ($puv ?? 0));
    $st['uv_ok'] = $uv_now !== null || $puv !== null;
    $st['uv_hoch'] = $st['uv_ok'] ? (($st['uv_max'] >= (float) $c['uv_hoch'] || $amtl_uv) ? 1 : 0) : null;
    $st['frost_erwartet'] = $s['frost']; $st['frost_wert'] = $tmin === null ? '-' : round((float) $tmin);
    $st['starkregen'] = ($st['warn_ok'] || $s['regen'] !== null) ? (($stark || !empty($s['regen'])) ? 1 : 0) : null;
    $st['sturm_vor'] = ($st['warn_ok'] || $s['sturm'] !== null) ? (($storm || !empty($s['sturm'])) ? 1 : 0) : null;

    // Innen und Lueften: Wert von Loxone (wd.php?innen=, gilt 30 Minuten), sonst Innenfuehler der Station; ohne Wert keine Aussage.
    $i = wd_json_lesen(wd_datadir() . '/innen.json');
    $innen = (is_array($i) && $jetzt - (int) $i['ts'] <= 1800) ? (float) $i['c'] : (is_array($station) && isset($station['innen_c']) ? $station['innen_c'] : null);
    $st['innen_c'] = $innen;
    if ($innen === null || $jw['temp_c'] === null) {
        $st['kuehlen_moeglich'] = null;
        $st['nacht_kuehler'] = 0;
    } else {
        $st['kuehlen_moeglich'] = ($innen >= (float) $c['lueften_innen_min_c'] && (float) $jw['temp_c'] <= $innen - (float) $c['lueften_delta_k']) ? 1 : 0;
        $st['nacht_kuehler'] = $st['prog_ok'] && wd_nacht_kuehler($std, $jetzt, $innen - (float) $c['lueften_delta_k']) ? 1 : 0;
    }
    wd_umwelt($st, $c, $jetzt);
    return $st;
}

/** Zustand in die Thementabelle. null wird beim Senden zu "-". Lebenszeichen immer. */
function wd_werte(array $st, array $c, $zaehler)
{
    $w = array();
    foreach (array_keys(wd_mqtt_tabelle()) as $t) { $w[$t] = null; }
    $alle = $st['jetzt_ok'] || $st['prog_ok'] || $st['warn_ok'];
    $w['status/ok'] = $alle ? 1 : 0; $w['status/ts'] = (int) $st['ts']; $w['status/zaehler'] = (int) $zaehler % 1000;
    foreach ($st['quellen'] as $d => $q) { $w['quelle/' . $d . '/ok'] = $q['ok'] ? 1 : 0; $w['quelle/' . $d . '/alter_s'] = $q['alter_s']; }
    $w['jetzt/ok'] = $st['jetzt_ok'] ? 1 : 0; $w['jetzt/ist_ersatz'] = (int) $st['ist_ersatz'];
    foreach ($st['jetzt'] as $k => $v) { $w['jetzt/' . $k] = $v; $w['herkunft/' . $k] = $st['herkunft'][$k]; }
    $w['gefuehlt/ok'] = $st['gefuehlt'] !== null ? 1 : 0; $w['gefuehlt/c'] = $st['gefuehlt']; $w['gefuehlt/art'] = $st['gefuehlt_art'];
    $w['prog/ok'] = $st['prog_ok'] ? 1 : 0;
    foreach ($st['prog'] as $k => $v) { $w['prog/' . $k] = $v; }
    foreach ($st['tage'] as $i => $t) { $w['prog/tag' . $i . '/tmax_c'] = $t['tmax']; $w['prog/tag' . $i . '/tmin_c'] = $t['tmin']; $w['prog/tag' . $i . '/code'] = $t['code']; }
    $w['herkunft/prog'] = $st['herkunft']['prog']; $w['herkunft/warnung'] = $st['herkunft']['warnung'];
    $w['warnung/ok'] = $st['warn_ok'] ? 1 : 0;
    $w['warnung/zaehler'] = (int) ($st['warn_zaehler'] ?? 0);
    if ($st['warn_ok']) {
        $sp = (string) $c['ansage_sprache'];
        $l = $st['lagen'][0] ?? null;
        $w['warnung/stufe'] = $l ? (int) $l['stufe'] : 0; $w['warnung/anzahl'] = count($st['lagen']); $w['warnung/quelle'] = $st['warn_quelle'];
        if ($l) {
            $w['warnung/klasse'] = $l['klasse']; $w['warnung/art'] = $l['art']; $w['warnung/text'] = wd_warn_satz($l, (int) $st['ts'], $sp); $w['warnung/volltext'] = wd_kuerzen($l['volltext'], 900);
            $w['warnung/beginn_ts'] = (int) $l['beginn']; $w['warnung/ende_ts'] = (int) $l['ende']; $w['warnung/beginn_min'] = max(0, (int) ceil(((int) $l['beginn'] - (int) $st['ts']) / 60));
        }
    }
    $w['schwelle/ok'] = $st['schwelle_ok'] ? 1 : 0;
    foreach ($st['schwelle'] as $k => $v) { $w['schwelle/' . $k] = $v; }
    $w['hitze/warnung'] = $st['schwelle_ok'] || $st['warn_ok'] ? (int) $st['hitze_warnung'] : null;
    $w['hitze/gefuehlt'] = $st['hitze_max'];
    $w['kuehlen_moeglich'] = $st['kuehlen_moeglich']; $w['kuehlen/innen_c'] = $st['innen_c'];
    $w['uv/index'] = $st['jetzt']['uv']; $w['uv/hoch'] = $st['uv_hoch'];
    $w['frost/erwartet'] = $st['frost_erwartet']; $w['starkregen/warnung'] = $st['starkregen']; $w['sturm/vorwarnung'] = $st['sturm_vor'];
    $w['uv/prog'] = $st['uv_ok'] ? $st['uv_max'] : null;
    $lu = $st['luft'] ?? null;
    $w['luft/ok'] = is_array($lu) ? 1 : 0;
    foreach (array('index', 'pm10', 'pm25', 'ozon', 'no2') as $k) { $w['luft/' . $k] = is_array($lu) ? ($lu[$k] ?? null) : null; }
    $w['lueftung_sperren'] = $st['lueftung_sperren'];
    $po = $st['pollen'] ?? null;
    $w['pollen/ok'] = is_array($po) ? 1 : 0;
    foreach (wd_pollen_arten() as $a) { $k = strtolower($a); $w['pollen/' . $k . '/heute'] = is_array($po) ? ($po[$k][0] ?? null) : null; $w['pollen/' . $k . '/morgen'] = is_array($po) ? ($po[$k][1] ?? null) : null; }
    $ra = $st['radar'] ?? null;
    $w['radar/ok'] = is_array($ra) ? 1 : 0; $w['radar/regen_in_min'] = is_array($ra) ? $ra['min'] : null; $w['radar/regen_mmh'] = is_array($ra) ? $ra['mmh'] : null;
    $w['bilanz/fehler_k'] = $st['bilanz_fehler'] ?? null;
    $ni = $st['nina'] ?? null;
    $w['nina/ok'] = is_array($ni) ? 1 : 0;
    if (is_array($ni)) { $w['nina/stufe'] = isset($ni[0]) ? (int) $ni[0]['stufe'] : 0; $w['nina/anzahl'] = count($ni); $w['nina/text'] = isset($ni[0]) ? wd_kuerzen((string) $ni[0]['titel'], 900) : ''; }
    if (is_array($st['fassade'] ?? null)) { foreach ((array) $st['fassade']['w'] as $f => $v) { $w['prog/fassade' . (int) $f . '/wm2'] = $v; } }
    $w['fassung'] = wd_fassung();
    return $w;
}

/** UTF-8-sicher kuerzen ohne mbstring. */
function wd_kuerzen($s, $n)
{
    $s = (string) $s;
    if (strlen($s) <= $n) { return $s; }
    return preg_replace('/[\x80-\xBF]+\z/', '', substr($s, 0, $n)) . ' ...';
}

/** Fassung aus plugin.cfg, der einen Quelle. */
function wd_fassung()
{
    $p = wd_paths();
    $f = array();
    if ($p['lbhome'] !== '') { $f[] = $p['lbhome'] . '/data/system/plugindatabase.json'; }
    $l = dirname(dirname(__DIR__)) . '/plugin.cfg';
    if (is_file($l)) {
        return preg_match('/^VERSION=(\S+)/m', (string) file_get_contents($l), $m) ? $m[1] : '-';
    }
    if ($p['lbhome'] !== '' && class_exists('LBSystem', false)) { $v = @LBSystem::pluginversion(); if ($v) { return $v; } }
    $d = $f ? wd_json_lesen($f[0]) : null;
    foreach (is_array($d) && isset($d['plugins']) ? (array) $d['plugins'] : array() as $e) {
        if (is_array($e) && ($e['folder'] ?? '') === $p['plugin'] && isset($e['version'])) { return (string) $e['version']; }
    }
    return '-';
}

function wd_zaehler()
{
    $f = wd_tmpdir() . '/zaehler.txt';
    $n = is_file($f) ? (int) @file_get_contents($f) : 0;
    $n = ($n + 1) % 1000;
    @file_put_contents($f, (string) $n);
    return $n;
}

/** Neuer Anlass (neue Lage oder Stufenanstieg) erhoeht warnung/zaehler. Abstufung und Aufhebung nicht (Vorlage 6.3). */
function wd_warn_zaehlen(array &$st)
{
    $f = wd_datadir() . '/warn_zaehler.json';
    $d = wd_json_lesen($f);
    if (!is_array($d)) { $d = array('n' => 0, 'stand' => array()); }
    $neu = array(); $an = false; $jetzt = (int) $st['ts'];
    foreach ($st['lagen'] as $l) {
        $k = $l['klasse']; $neu[$k] = max((int) ($neu[$k] ?? 0), (int) $l['stufe']);
        if (!isset($d['stand'][$k]) || (int) $d['stand'][$k] < (int) $l['stufe']) { $an = true; }
    }
    if ($an) { $d['n'] = ((int) $d['n'] + 1) % 1000; }
    if ($st['warn_ok']) { $d['stand'] = $neu; }
    wd_json_schreiben($f, $d);
    $st['warn_zaehler'] = (int) $d['n'];
}

/* ---------------------------------- Der Lauf ---------------------------------- */

/** Tagesfenster fuer den Ansageplan aus den Einstellungen. */
function wd_tag(array $c, array $rg, $jetzt)
{
    $hm = date('H:i', $jetzt);
    $fen = function ($von, $min = 90) use ($hm, $jetzt) {
        $t = strtotime(date('Y-m-d', $jetzt) . ' ' . $von);
        return $jetzt >= $t && $jetzt < $t + $min * 60;
    };
    $nacht = !empty($rg['nachtruhe']) && function_exists('ansage_nachtruhe') && ansage_nachtruhe($rg, $jetzt);
    $bis = strtotime(date('Y-m-d', $jetzt) . ' ' . (string) $rg['bis']);
    return array('nacht' => $nacht, 'frueh' => !empty($rg['nachtruhe']) && !$nacht && $jetzt >= $bis && $jetzt < $bis + 7200, 'morgen' => $fen((string) $c['morgen_zeit']), 'mittag' => $fen((string) $c['mittag_zeit']),
        'abend' => $fen((string) $c['abend_zeit'], 120), 'letzte' => $fen((string) $c['lueften_letzte'], 10), 'heute' => date('Ymd', $jetzt));
}

function wd_ansagen(array $st, array $c, $jetzt)
{
    $k = wd_ansage_k($c);
    $rg = wd_ansage_regeln($c);
    list($neu, $her2, $g) = ansage_token_abgleich($c['tts'], isset($c['ansage_token_herkunft']) ? $c['ansage_token_herkunft'] : array(), $k);
    if ($g) { $c['tts'] = $neu; $c['ansage_token_herkunft'] = $her2; wd_config_speichern($c); wd_log('Ansage: Sprechtoken uebernommen.'); }
    $f = wd_datadir() . '/ansage_stand.json';
    $stand = wd_json_lesen($f);
    if (!is_array($stand)) { $stand = array('lagen' => array(), 'tag' => array()); }
    $tag = wd_tag($c, $rg, $jetzt);
    $plan = wd_ansage_plan($st, $c, $stand, $tag, $jetzt);
    if (!$plan) { wd_json_schreiben($f, wd_ansage_gesprochen($stand, array(), array(), $tag['heute'], $jetzt)); return 'nichts'; }
    $ein = array();
    foreach ($plan as $e) { $ein[] = array_intersect_key($e, array('schluessel' => 1, 'text' => 1, 'dringend' => 1)); }
    $a = ansage_regel_sprechen($ein, ansage_token_fuer_lauf($c['tts'], isset($c['ansage_token_herkunft']) ? $c['ansage_token_herkunft'] : array(), $k), $rg, $k);
    wd_json_schreiben($f, wd_ansage_gesprochen($stand, $plan, (array) $a['einzeln'], $tag['heute'], $jetzt));
    if ($a['r'] !== null || !in_array($a['regel'], array('AUS', 'KEIN_ZIEL'), true)) { wd_log('Ansage: ' . ansage_regel_kurz($a)); }
    return $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
}

/** Ein Minutenlauf. Jede Aufgabe laeuft getrennt: Ein Fehlschlag einer Quelle stoppt die anderen nicht. */
/**
 * Ein Lauf, nie zwei zugleich: Cron, Knopf und Endpunkt teilen die Sperre. Rueckgabe: der Zustand, null wenn schon einer laeuft.
 * Die Sperre liegt im Datenordner, der fuer Cron und Webserver derselbe ist; 'e', damit ein Kind (Sprachausgabe) sie nicht erbt.
 */
function wd_lauf($zwingen = false)
{
    $sp = @fopen(wd_datadir() . '/lauf.lock', 'ce');
    if ($sp === false || !flock($sp, LOCK_EX | LOCK_NB)) {
        if (is_resource($sp)) { fclose($sp); }
        return null;
    }
    try {
        return wd_lauf_sperrt($zwingen);
    } finally {
        flock($sp, LOCK_UN);
        fclose($sp);
    }
}

function wd_lauf_sperrt($zwingen)
{
    $jetzt = time();
    $c = wd_config();
    wd_abo_nachziehen($c);
    if (!empty($c['om_ein'])) { wd_om_holen($c, $jetzt, $zwingen); }
    if (!empty($c['dwd_ein'])) { wd_dwd_holen($c, $jetzt, $zwingen); }
    $d = wd_quelle_lesen('dwd');
    $dwd_lebt = !empty($c['dwd_ein']) && (int) $d['ok_ts'] > 0 && $jetzt - (int) $d['ok_ts'] <= (int) $c['warn_max_alter_min'] * 60 && (int) $d['fehl'] === 0;
    if (!empty($c['bs_ein']) && (!$dwd_lebt || $zwingen)) { wd_bs_holen($c, $jetzt, $zwingen); }
    wd_mehr_holen($c, $jetzt, $zwingen);
    list($station) = wd_st_holen($c, $jetzt);
    wd_wu_holen($c, $jetzt);     // 0.9.7: eigener Takt, nie oefter als alle 10 Minuten, auch nicht auf Knopfdruck
    if (is_array($station) && wd_bilanz_faellig($jetzt)) { wd_bilanz_rechnen($station['temp_c'], $jetzt); }
    $st = wd_zustand($c, $station, $jetzt);
    wd_warn_zaehlen($st);
    wd_emu_schreiben($c, $st['prog_stunden'], $jetzt);
    unset($st['prog_stunden']);
    wd_json_schreiben(wd_tmpdir() . '/zustand.json', $st);
    $werte = wd_werte($st, $c, wd_zaehler());
    $sig = md5((string) json_encode(array_diff_key($werte, array('status/ts' => 1, 'status/zaehler' => 1, 'quelle/open_meteo/alter_s' => 1, 'quelle/dwd/alter_s' => 1, 'quelle/brightsky/alter_s' => 1, 'quelle/station/alter_s' => 1, 'quelle/wunderground/alter_s' => 1, 'quelle/loxone/alter_s' => 1, 'warnung/beginn_min' => 1))));
    $sf = wd_tmpdir() . '/mqtt_sig.txt';
    $alt = is_file($sf) ? explode('|', (string) file_get_contents($sf)) : array('', '0');
    $voll = $sig !== $alt[0] || $jetzt - (int) ($alt[1] ?? 0) >= 300;
    wd_mqtt_senden($voll ? $werte : array_intersect_key($werte, array('status/ok' => 1, 'status/ts' => 1, 'status/zaehler' => 1)), $c);
    if ($voll) { @file_put_contents($sf, $sig . '|' . $jetzt); }
    wd_ansagen($st, $c, $jetzt);
    return $st;
}

/** Selbstpruefung ohne Netz und ohne Geraet. Zeile: array('frage','ok'(true|false|null),'hinweis'). Wird auch von bin/wd_takt.php --selbsttest gefahren. */
function wd_selbsttest()
{
    $z = array();
    $zeile = function ($f, $ok, $h = '') use (&$z) { $z[] = array('frage' => $f, 'ok' => $ok, 'hinweis' => $h); };
    $c = wd_config();
    $nah = function ($a, $b, $e = 0.15) { return abs((float) $a - (float) $b) <= $e; };
    // Rechnung gegen die veroeffentlichten Tafelwerte
    list($w, $a) = wd_gefuehlt(-1.111, 50, 16.09);
    $zeile(wd_t('PRUEF.WC'), $a === 'windchill' && $nah($w, -6.1, 0.4), $w . ' C');
    list($w, $a) = wd_gefuehlt(32.22, 70, 5);
    $zeile(wd_t('PRUEF.HI'), $a === 'hitzeindex' && $nah($w, 40.5, 0.7), $w . ' C');
    list($w, $a) = wd_gefuehlt(18, 50, 3);
    $zeile(wd_t('PRUEF.LUFT'), $a === 'luft' && $nah($w, 18), $w . ' C');
    // Hysterese
    $zeile(wd_t('PRUEF.HYST'), wd_hysterese(32, 32, 1, 0) === 1 && wd_hysterese(31.5, 32, 1, 1) === 1 && wd_hysterese(31.5, 32, 1, 0) === 0 && wd_hysterese(30.9, 32, 1, 1) === 0 && wd_hysterese(null, 32, 1, 1) === null);
    // Warnregeln
    $r = function ($kl, $s, $wr = 0, $v = 0) { return implode('/', wd_warn_regel(array('klasse' => $kl, 'stufe' => $s, 'weckrecht' => $wr, 'vorab' => $v))); };
    $soll = array(array('wind', 1, 0, 0, 'anzeige/still'), array('wind', 2, 0, 0, 'einmal/morgens'), array('wind', 3, 1, 0, 'dringend/weckt'), array('gewitter', 2, 1, 1, 'einmal/still'), array('schnee', 3, 0, 0, 'einmal/morgens_dringend'),
                  array('regen', 3, 1, 0, 'dringend/weckt'), array('regen', 4, 0, 0, 'dringend/weckt'), array('hitze', 3, 0, 0, 'tagesplan/nie'), array('uv', 1, 0, 0, 'tagesplan/nie'));
    $fehl = array();
    foreach ($soll as $s) { if ($r($s[0], $s[1], $s[2], $s[3]) !== $s[4]) { $fehl[] = $s[0] . $s[1]; } }
    $zeile(wd_t('PRUEF.REGELN'), !$fehl, count($soll) . ' Faelle' . ($fehl ? ': ' . implode(', ', $fehl) : ''));
    // Filter und Zusammenfuehrung
    $j = time();
    $roh = array(array('id' => 'a', 'status' => 'Test', 'ii' => 52, 'stufe' => 2, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'X'),
        array('id' => 'b', 'status' => 'Actual', 'ii' => 98, 'stufe' => 2, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'X'),
        array('id' => 'c', 'status' => 'Actual', 'ii' => 52, 'stufe' => 2, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'STURM'),
        array('id' => 'd', 'status' => 'Actual', 'ii' => 54, 'stufe' => 3, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'ORKAN', 'verweise' => array('alt')),
        array('id' => 'alt', 'status' => 'Actual', 'ii' => 51, 'stufe' => 1, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'BOEEN'),
        array('id' => 'e', 'status' => 'Actual', 'ii' => 22, 'stufe' => 1, 'beginn' => $j - 9000, 'ende' => $j - 10, 'art' => 'FROST'));
    $o = wd_lagen_ordnen($roh, $j);
    $zeile(wd_t('PRUEF.ORDNEN'), count($o) === 1 && $o[0]['id'] === 'd', count($o) . ' Lage(n)');
    // CAP
    $x = '<alert xmlns="urn"><identifier>i1</identifier><status>Actual</status><msgType>Alert</msgType><info><event>STURMB&#214;EN</event><severity>Moderate</severity><eventCode><valueName>II</valueName><value>52</value></eventCode>'
       . '<eventCode><valueName>GROUP</valueName><value>WIND</value></eventCode><onset>2026-01-01T10:00:00+01:00</onset><expires>2026-01-01T18:00:00+01:00</expires><headline>H</headline><description>D</description><area><geocode><valueName>WARNCELLID</valueName><value>123456789</value></geocode></area></info></alert>';
    $l = wd_cap_lage($x, '123456789');
    $zeile(wd_t('PRUEF.CAP'), is_array($l) && $l['ii'] === 52 && $l['stufe'] === 2 && wd_cap_lage($x, '987654321') === null);
    // ZIP: gespeichert und deflate
    $zp = function ($name, $roh2, $met) {
        $d = $met === 8 ? gzdeflate($roh2) : $roh2;
        $lk = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $met, 0, 0, crc32($roh2), strlen($d), strlen($roh2), strlen($name), 0) . $name . $d;
        $zk = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $met, 0, 0, crc32($roh2), strlen($d), strlen($roh2), strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        return $lk . $zk . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($zk), strlen($lk), 0);
    };
    $g1 = wd_zip_lesen($zp('a.xml', 'inhalt-ü', 8)); $g2 = wd_zip_lesen($zp('b.xml', 'x', 0));
    $zeile(wd_t('PRUEF.ZIP'), is_array($g1) && ($g1['a.xml'] ?? '') === 'inhalt-ü' && is_array($g2) && ($g2['b.xml'] ?? '') === 'x' && wd_zip_lesen('kein zip') === null);
    // Satz: Stufe 3 Wortlaut, keine Koordinate
    $sz = wd_warn_satz(array('ii' => 52, 'klasse' => 'wind', 'beginn' => $j, 'ende' => $j + 3600, 'quelle' => 'dwd', 'vorab' => 0), $j, 'de');
    $zeile(wd_t('PRUEF.SATZ'), strpos($sz, 'Deutschem Wetterdienst') !== false && strpos($sz, 'Sturmb') !== false, $sz);
    // Mischung: Tmin kleinster, Tmax groesster, Boe groesste, Regen Median, gleiches Gewicht ohne Bilanz; ein ausgeblendeter Faktor zaehlt nicht
    $j2 = $j - $j % 3600;
    $mx = wd_mischen(array('a' => array(array('ts' => $j2, 't' => 10.0, 'regen' => 1.0, 'boe' => 40.0)), 'b' => array(array('ts' => $j2, 't' => 12.0, 'regen' => 5.0, 'boe' => 70.0)), 'c' => array(array('ts' => $j2, 't' => 20.0, 'regen' => 3.0, 'boe' => 10.0))), array('a' => 1, 'b' => 1, 'c' => 0), array(), false, $j);
    $zeile(wd_t('PRUEF.MIX'), count($mx) === 1 && $nah($mx[0]['t'], 11) && $mx[0]['t_min'] == 10.0 && $mx[0]['t_max'] == 20.0 && $mx[0]['boe'] == 70.0 && $mx[0]['regen'] == 3.0 && $mx[0]['regen_max'] == 5.0);
    $zeile(wd_t('PRUEF.GEWICHT'), wd_gewicht(array('q' => array('f0' => array(13, 2.0, 0))), 'q', 'f0') == 1.0 && $nah(wd_gewicht(array('q' => array('f0' => array(14, 2.0, 0))), 'q', 'f0'), 0.25, 0.001));
    $zeile(wd_t('PRUEF.POLLEN'), wd_pollen_stufe('1-2') === 1.5 && wd_pollen_stufe('-1') === null && wd_pollen_stufe('3') === 3.0);
    $zeile(wd_t('PRUEF.WTTR_ORT'), wd_wttr_ort(12.3456, -67.8912) === '12.35,-67.89');
    $lf = array();
    foreach (array('pollen_region', 'ma_land') as $lk) { foreach (array_keys(wd_auswahl_liste($lk)) as $lw) { if (!wd_wert_pruefen($lk, (string) $lw)[0]) { $lf[] = $lk . ':' . $lw; } } }
    $zeile(wd_t('PRUEF.LISTEN'), !$lf, implode(', ', $lf));
    $tl = wd_st_token_lage($c);
    $zeile(wd_t('PRUEF.ST_TOKEN'), $tl, wd_t($tl === null ? 'PRUEF.ST_TOKEN_OHNE' : ($tl ? 'PRUEF.ST_TOKEN_OK' : 'PRUEF.ST_TOKEN_FEHL')));
    $e1 = wd_empfang_lesen(array('tempf' => 50.0, 'humidity' => 60.0, 'windspeedmph' => 10.0, 'rainratein' => 0.0));
    $zeile(wd_t('PRUEF.EMPFANG'), is_array($e1) && $nah($e1['temp_c'], 10) && $nah($e1['wind_kmh'], 16.1) && $e1['regnet'] === 0 && wd_empfang_lesen(array('tempf' => 50.0, 'humidity' => 0.0)) === null);
    $nl = wd_warn_regel(array('quelle' => 'nina', 'strom' => 'mow', 'stufe' => 3, 'klasse' => 'sonst'));
    $zeile(wd_t('PRUEF.NINA'), $nl === array('dringend', 'weckt') && wd_warn_regel(array('quelle' => 'nina', 'strom' => 'pol', 'stufe' => 2, 'klasse' => 'sonst')) === array('einmal', 'still'));
    // Vorabinformation (Profil 3.1.2: amtlich ab Stufe 3) weckt auf keiner Stufe, auch nicht bei einer Klasse mit Weckrecht
    $vf = array();
    foreach (array(2, 3, 4) as $vs) { if ($r('gewitter', $vs, 1, 1) !== 'einmal/still') { $vf[] = $vs; } }
    $zeile(wd_t('PRUEF.VORAB'), !$vf && $r('hitze', 3, 0, 1) === 'tagesplan/nie' && wd_warn_regel(array('quelle' => 'nina', 'strom' => 'mow', 'stufe' => 3, 'klasse' => 'sonst', 'vorab' => 1)) === array('dringend', 'weckt'), $vf ? implode(', ', $vf) : '2, 3, 4');
    // Vorab erkennen: englische <event> tragen das Wort nicht, urgency=Future schon (Profil, Abschnitt urgency)
    $capl = function ($ev, $ur, array $gr, $ii = '40') {
        $g = '';
        foreach ($gr as $g1) { $g .= '<eventCode><valueName>GROUP</valueName><value>' . $g1 . '</value></eventCode>'; }
        return wd_cap_lage('<alert><identifier>i</identifier><status>Actual</status><msgType>Alert</msgType><info><event>' . $ev . '</event><urgency>' . $ur . '</urgency><severity>Severe</severity><eventCode><valueName>II</valueName><value>' . $ii . '</value></eventCode>' . $g
            . '<onset>2026-01-01T10:00:00+01:00</onset><expires>2026-01-01T18:00:00+01:00</expires><area><geocode><valueName>WARNCELLID</valueName><value>123456789</value></geocode></area></info></alert>', '123456789');
    };
    $ve = $capl('severe thunderstorms', 'Future', array()); $vw = $capl('severe thunderstorms', 'Immediate', array()); $vd = $capl('VORABINFORMATION SCHWERES GEWITTER', 'Immediate', array());
    $zeile(wd_t('PRUEF.VORAB_EN'), $ve['vorab'] === 1 && $vw['vorab'] === 0 && $vd['vorab'] === 1);
    // Vorab klingt nicht wie die Warnung derselben Nummer
    $vl = array('ii' => 40, 'klasse' => 'gewitter', 'stufe' => 3, 'beginn' => $j, 'ende' => $j + 3600, 'quelle' => 'dwd', 'vorab' => 1);
    $wl = array('vorab' => 0) + $vl;
    $sv = wd_warn_satz($vl, $j, 'de'); $sw = wd_warn_satz($wl, $j, 'de'); $se = wd_warn_satz($vl, $j, 'en'); $s65 = wd_warn_satz(array('ii' => 65, 'klasse' => 'regen') + $vl, $j, 'de');
    $zeile(wd_t('PRUEF.VORAB_NAME'), strpos($sv, 'Vorabinformation schweres Gewitter') === 0 && substr_count($sv, 'Vorabinformation') === 1 && strpos($sw, 'Vorabinformation') === false && strpos($sw, 'Schweres Gewitter mit Orkanböen') === 0
        && strpos($se, 'Severe weather watch: severe thunderstorms') === 0 && strpos($s65, ' / ') === false && strpos($s65, 'heftiger oder ergiebiger Regen') !== false
        && wd_warn_name(array('ii' => 33, 'klasse' => 'gewitter', 'vorab' => 1), 'de') === 'Vorabinformation: Starkes Gewitter', $sv);
    // Eine Vorabinformation verdeckt keine Warnung, weder beim Ordnen noch beim Merken
    $vr = function ($id, $s, $v) use ($j) { return array('id' => $id, 'status' => 'Actual', 'ii' => 40, 'stufe' => $s, 'beginn' => $j, 'ende' => $j + 3600, 'art' => 'X', 'vorab' => $v, 'quelle' => 'dwd'); };
    $vo = wd_lagen_ordnen(array($vr('v', 3, 1), $vr('w', 3, 0)), $j); $vo2 = wd_lagen_ordnen(array($vr('v', 4, 1), $vr('w', 3, 0)), $j);
    $vc = array('ansage_sprache' => 'de', 'a_warn' => 1); $vt = array('nacht' => true, 'heute' => date('Ymd'));
    $vp = wd_ansage_plan(array('lagen' => array($vo[0] ?? array())), $vc, array('lagen' => array(array('klasse' => 'gewitter', 'stufe' => 3, 'beginn' => $j, 'ende' => $j + 3600, 'vorab' => 1))), $vt, $j);
    $vg = wd_ansage_gesprochen(array(), array(array('schluessel' => 's', 'lage' => array('klasse' => 'gewitter') + $vr('v', 3, 1))), array('s' => ''), date('Ymd'), $j);
    $vq = wd_ansage_plan(array('lagen' => array(array('klasse' => 'gewitter') + $vr('v', 3, 1))), $vc, array('lagen' => array()), array('nacht' => false, 'heute' => date('Ymd')), $j);
    $zeile(wd_t('PRUEF.VORAB_WARN'), count($vo) === 1 && $vo[0]['id'] === 'w' && count($vo2) === 2 && count($vp) === 1 && !empty($vp[0]['dringend']) && strpos($vp[0]['schluessel'], 'vorab') === false && !empty($vg['lagen'][0]['vorab']) && count($vq) === 1 && empty($vq[0]['dringend']) && strpos($vq[0]['schluessel'], 'vorab') !== false);
    // Amtliche Liste: Nummern und Namen
    $cd = wd_dwd_codes(); $nm = function ($ii, $sp) use ($cd) { return wd_warn_name(array('ii' => $ii, 'klasse' => $cd[$ii][0] ?? 'sonst'), $sp); };
    $zeile(wd_t('PRUEF.CODES'), ($cd[86][0] ?? '') === 'glaette' && $nm(86, 'de') === 'Extremes Glatteis' && $nm(76, 'de') === 'Extrem starke Schneeverwehung' && $nm(87, 'de') === 'Markante Glätte' && $nm(84, 'en') === 'Icy surfaces' && $nm(87, 'en') === 'Significant icy surfaces'
        && !isset($cd[24]) && !isset($cd[77]) && !isset($cd[78]) && ($cd[13] ?? array()) === array('wind', 0) && ($cd[16] ?? array()) === array('wind', 0) && $nm(16, 'de') === 'Schwerer Sturm');
    // Mehrere Gruppen: die erste zuordenbare gewinnt; Test und Leiterseil zaehlen nicht; ICE und GLAZE stehen nicht im Profil
    $lg = $capl('X', 'Immediate', array('NEU', 'SNOWDRIFT', 'WIND'), '97');
    $og = wd_lagen_ordnen(array(array('id' => 't', 'status' => 'Actual', 'ii' => 97, 'gruppe' => array('TEST'), 'stufe' => 3, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'X'), array('id' => 'p', 'status' => 'Actual', 'ii' => 97, 'gruppe' => 'POWERLINEVIBRATION', 'stufe' => 2, 'beginn' => $j, 'ende' => $j + 900, 'art' => 'X')), $j);
    $kg = true;
    foreach (array('SLIPPERINESS' => 'glaette', 'BLACK_ICE' => 'glaette', 'RIME_ICE' => 'glaette', 'SNOWDRIFT' => 'schnee', 'HAIL' => 'gewitter', 'TORNADO' => 'gewitter', 'ICE' => 'sonst', 'GLAZE' => 'sonst') as $g2 => $k2) { if (wd_klasse_aus_gruppe($g2) !== $k2) { $kg = false; } }
    $zeile(wd_t('PRUEF.GRUPPEN'), $lg['gruppe'] === array('NEU', 'SNOWDRIFT', 'WIND') && wd_klasse_aus_gruppe($lg['gruppe']) === 'schnee' && wd_klasse_aus_gruppe('wind') === 'wind' && !$og && $kg);
    // Unbekannte Nummer: Weckrecht wie bekannte derselben Art
    $rk = function ($gr, $art = 'X') use ($j) { $o = wd_lagen_ordnen(array(array('id' => 'n', 'status' => 'Actual', 'ii' => 97, 'gruppe' => array($gr), 'stufe' => 3, 'beginn' => $j, 'ende' => $j + 900, 'art' => $art)), $j); return implode('/', wd_warn_regel($o[0])); };
    $zeile(wd_t('PRUEF.RUECKFALL'), $rk('THUNDERSTORM') === 'dringend/weckt' && $rk('WIND') === 'dringend/weckt' && $rk('RAIN') === 'dringend/weckt' && $rk('RAIN', 'persistent rain') === 'einmal/morgens_dringend' && $rk('SNOWDRIFT') === 'einmal/morgens_dringend' && $rk('SLIPPERINESS') === 'einmal/morgens_dringend');
    // Der Code muss keine Zahl sein (Profil, Abschnitt eventCode)
    $lt = $capl('X', 'Immediate', array('THUNDERSTORM'), 'A7'); $l12 = $capl('X', 'Immediate', array('WIND'), '12a');
    $ot = wd_lagen_ordnen(array(array('status' => 'Actual', 'id' => 'z', 'beginn' => $j, 'ende' => $j + 900) + $lt), $j);
    $zeile(wd_t('PRUEF.II_TEXT'), $lt['ii'] === 0 && $l12['ii'] === 0 && wd_ii_zahl('52') === 52 && wd_ii_zahl(array()) === 0 && count($ot) === 1 && $ot[0]['klasse'] === 'gewitter' && wd_warn_name($ot[0], 'de') === 'Gewitter');
    // wttr.in: Schritte werden Stunden, eine Antwort von anderswo zaehlt nicht
    $ws = array(); foreach (array(0, 300, 600, 900, 1200, 1500, 1800, 2100) as $wz) { $ws[] = array('time' => (string) $wz, 'tempC' => (string) ($wz / 100), 'humidity' => '50', 'windspeedKmph' => '10', 'WindGustKmph' => '20', 'precipMM' => '3.0', 'chanceofrain' => '40', 'uvIndex' => '1'); }
    $wj = json_encode(array('nearest_area' => array(array('latitude' => '50.1', 'longitude' => '10.1')), 'weather' => array(array('date' => '2030-06-01', 'hourly' => $ws))));
    $wa = wd_wttr_lesen($wj, 50.0, 10.0);
    $zeile(wd_t('PRUEF.WTTR'), is_array($wa) && count($wa['stunden']) === 24 && $nah($wa['stunden'][4]['t'], 4) && $nah($wa['stunden'][4]['regen'], 1) && wd_wttr_lesen($wj, 20.0, 10.0) === null && wd_wttr_lesen('Unknown location', 50.0, 10.0) === null);
    // Emulator: Format 1, Lage nur aus der Anfrage
    $es = wd_emu_stunden(array(array('ts' => $j2, 't' => 10.0, 'rf' => 60.0, 'wind' => 8.0, 'boe' => 20.0, 'regen' => 0.0, 'regen_w' => 5.0, 'solar' => 100.0)), array(array('ts' => $j2, 'code' => 3)), $j);
    $ea = wd_emu_antwort(array('std' => $es), array('coord' => '1.5,2.5', 'asl' => '115'), $j); $eb = wd_emu_antwort(array('std' => $es), array(), $j);
    $ez = array_values(array_filter(explode("\n", $ea), function ($x) { return strpos($x, ";\t") !== false; }));
    $zeile(wd_t('PRUEF.EMU'), count($ez) === 1 && count(explode(';', rtrim($ez[0], ';'))) === 19 && strpos($ea, ';-;1.5;2.5;115;-;') !== false && strpos($eb, ';-;0;0;0;-;') !== false && $es[0]['l'] === 22 && wd_emu_koord('1,2;x') === null);
    // Retain: nur fassung
    $ret = array(); foreach (wd_mqtt_tabelle() as $t => $f) { if ($f[2]) { $ret[] = $t; } }
    $zeile(wd_t('PRUEF.RETAIN'), $ret === array('fassung'), implode(', ', $ret));
    // Gruppen haben ein ok
    $tb = wd_mqtt_tabelle(); $ohne = array();
    foreach (array('status', 'jetzt', 'gefuehlt', 'prog', 'warnung', 'schwelle') as $gr) { if (!isset($tb[$gr . '/ok'])) { $ohne[] = $gr; } }
    $zeile(wd_t('PRUEF.OK_THEMA'), !$ohne, implode(', ', $ohne));
    $nb = 0; foreach ($tb as $t => $f) { if ($f[1] && !preg_match('#(ok|zaehler|ist_ersatz|stufe)$#', $t)) { $nb++; } }
    $zeile(wd_t('PRUEF.NC'), $nb === 0, (string) $nb);
    // Protokoll streicht den Standort
    $zeile(wd_t('PRUEF.LOGSAUBER'), strpos(wd_log_sauber('x?latitude=1.5&longitude=2.5&warn_cell_id=9'), '1.5') === false);
    // Konfiguration und Installation
    $zeile(wd_t('PRUEF.KONFIG'), is_array($c) && wd_hat_inhalt($c) ? true : null, wd_t('PRUEF.KONFIG_H'));
    $pos = wd_position($c);
    $zeile(wd_t('PRUEF.STANDORT'), $pos !== null ? true : null, wd_t($pos !== null ? 'PRUEF.STANDORT_JA' : 'PRUEF.STANDORT_NEIN'));
    $zeile(wd_t('PRUEF.ZELLE'), trim((string) $c['warnzelle']) !== '' ? true : null, wd_t(trim((string) $c['warnzelle']) !== '' ? 'PRUEF.STANDORT_JA' : 'PRUEF.ZELLE_NEIN'));
    $zeile(wd_t('PRUEF.ANSAGE'), defined('ANSAGE_FASSUNG'), defined('ANSAGE_FASSUNG') ? ANSAGE_FASSUNG : '');
    return $z;
}
