#!/bin/bash
ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-weatherdata}"; BASE="${ARGV5:-$LBHOMEDIR}"
umask 077
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - dieses Skript tut nichts."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - dieses Skript tut nichts."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation dieses Update fuer eine Neuinstallation und legte"
    echo "<FAIL> die Einstellungen beiseite. Die Aktualisierung wird abgebrochen; die bisherige"
    echo "<FAIL> Fassung bleibt unveraendert installiert."
    exit 2
fi

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
NEU="$SICHER.neu"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# purge_installation raeumt den Konfigurations- und den Datenordner danach ab. Was nicht wiederkommt, darf nicht ungesichert bleiben.
wd_abbruch() {
    echo "<FAIL> $1"
    echo "<FAIL> Die Aktualisierung wird abgebrochen; die bisherige Fassung bleibt unveraendert installiert."
    rm -rf "${NEU:?}" 2>/dev/null
    rm -f "$MARKE" 2>/dev/null
    exit 2
}

wd_traegt_token() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "" ? 0 : 1);' -- "$1" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}

rm -rf "${NEU:?}" 2>/dev/null
if ! mkdir -p "$NEU" 2>/dev/null; then
    wd_abbruch "Die Update-Sicherung liess sich nicht anlegen (Platz? Rechte?): $NEU"
fi
chmod 0700 "$NEU" 2>/dev/null

CF="$BASE/config/plugins/$PFOLDER/weatherdata.json"
if [ -f "$CF" ]; then
    if cp -p "$CF" "$NEU/weatherdata.json" 2>/dev/null && cmp -s "$CF" "$NEU/weatherdata.json"; then
        chmod 0600 "$NEU/weatherdata.json" 2>/dev/null
        wd_traegt_token "$CF"
        case $? in
            0) echo "<OK> Konfiguration gesichert." ;;
            1) echo "<WARNING> Die Konfiguration traegt kein Aktionstoken (unlesbar oder abgeschnitten): $CF"
               echo "<WARNING> Gesichert ist sie trotzdem; postinstall.sh holt das Token aus der Zweitschrift, wenn es eine gibt." ;;
            *) echo "<OK> Konfiguration gesichert (Inhalt ohne php nicht pruefbar)." ;;
        esac
    else
        if wd_traegt_token "$BK"; then
            echo "<WARNING> Die Konfiguration liess sich NICHT sichern (Platz? Rechte?): $CF - die Zweitschrift traegt sie."
        else
            wd_abbruch "Die Konfiguration liess sich NICHT sichern (Platz? Rechte?), und es gibt keine brauchbare Zweitschrift: $CF"
        fi
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi

# Der ganze Datenordner: Eine Liste vergass bis 0.9.1 die Prognose-Bilanz (bilanz.json, 14 bis 30 Tage Vorlauf) und andere Merker.
for Q in "$BASE/data/plugins/$PFOLDER"/*; do
    [ -f "$Q" ] || continue
    F=$(basename "$Q")
    case "$F" in einmalmeldung.json|lauf.lock) continue ;; esac
    if cp -p "$Q" "$NEU/$F" 2>/dev/null && cmp -s "$Q" "$NEU/$F"; then
        chmod 0600 "$NEU/$F" 2>/dev/null
    else
        rm -f "$NEU/$F" 2>/dev/null
        [ "$F" = "bilanz.json" ] && wd_abbruch "Die Prognose-Bilanz liess sich NICHT sichern (Platz? Rechte?): $Q"
        echo "<WARNING> $F liess sich NICHT sichern: $Q"
    fi
done

rm -rf "${SICHER:?}" 2>/dev/null
if ! mv "$NEU" "$SICHER" 2>/dev/null; then
    wd_abbruch "Die Update-Sicherung liess sich nicht an ihren Platz bringen: $SICHER"
fi
exit 0
