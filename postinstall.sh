#!/bin/bash
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-weatherdata}"
BASE="${ARGV5:-$LBHOMEDIR}"
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
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

PCFG="$BASE/config/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
mkdir -p "$PCFG" "$PDATA" 2>/dev/null
CF="$PCFG/weatherdata.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
[ -f "$CF" ] || printf '{}\n' > "$CF"

wd_traegt_token() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "" ? 0 : 1);' -- "$1" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}
wd_form_leer() {
    [ ! -s "$1" ] || [ "$(tr -d ' \t\r\n' < "$1" 2>/dev/null)" = "{}" ]
}
wd_hinlegen() { # quelle ziel
    cp "$1" "$2.neu.$$" 2>/dev/null && chmod 600 "$2.neu.$$" 2>/dev/null \
        && cmp -s "$1" "$2.neu.$$" && mv -f "$2.neu.$$" "$2" 2>/dev/null && return 0
    rm -f "$2.neu.$$" 2>/dev/null
    return 1
}

if [ "$UPGRADE" = 1 ] && [ -f "$SICHER/weatherdata.json" ]; then
    if wd_hinlegen "$SICHER/weatherdata.json" "$CF"; then
        echo "<OK> Konfiguration aus der Update-Sicherung zurueckgestellt."
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT zurueckstellen: $SICHER/weatherdata.json"
    fi
fi

if [ -f "$BK" ]; then
    wd_traegt_token "$CF"; CF_RC=$?
    wd_traegt_token "$BK"; BK_RC=$?
    if [ "$CF_RC" = 0 ]; then
        :
    elif [ "$CF_RC" = 1 ] && [ "$BK_RC" = 0 ]; then
        if ! wd_form_leer "$CF"; then
            if cp -p "$CF" "$CF.kaputt" 2>/dev/null; then
                chmod 0600 "$CF.kaputt" 2>/dev/null
                echo "<WARNING> weatherdata.json trug kein Aktionstoken; der bisherige Inhalt liegt unter $CF.kaputt"
            fi
        fi
        if wd_hinlegen "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        else
            echo "<WARNING> Die Sicherungskopie liess sich nicht zurueckspielen: $BK"
        fi
    elif [ "$BK_RC" = 2 ] && wd_form_leer "$CF"; then
        if wd_hinlegen "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Inhalt der Konfiguration nicht pruefbar (kein php) - sie bleibt unveraendert."
    else
        echo "<WARNING> Die Sicherungskopie traegt kein Aktionstoken - nicht zurueckgespielt: $BK"
    fi
fi
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

if [ "$UPGRADE" = 1 ] && [ -d "$SICHER" ]; then
        OFFEN=0
    for Q in "$SICHER"/*; do
        [ -f "$Q" ] || continue
        F=$(basename "$Q")
        [ "$F" = "weatherdata.json" ] && continue
        if ! wd_hinlegen "$Q" "$PDATA/$F"; then
            OFFEN=1
            echo "<WARNING> $F liess sich NICHT zurueckstellen; die Update-Sicherung bleibt liegen: $SICHER"
        fi
    done
    rm -f "/tmp/$PFOLDER/zustand.json" "/tmp/$PFOLDER/mqtt_sig.txt" 2>/dev/null
    wd_traegt_token "$SICHER/weatherdata.json"; SI_RC=$?
    if [ "$OFFEN" != 0 ]; then
        :
    elif [ ! -f "$SICHER/weatherdata.json" ] || cmp -s "$SICHER/weatherdata.json" "$CF" || wd_traegt_token "$CF" || [ "$SI_RC" = 1 ]; then
        rm -rf "${SICHER:?}" 2>/dev/null
    else
        echo "<WARNING> Die Konfiguration ist nicht angekommen - die Update-Sicherung bleibt liegen: $SICHER"
    fi
fi

rm -f "$MARKE" 2>/dev/null
if wd_traegt_token "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
elif [ "$UPGRADE" = 1 ]; then
    echo "<WARNING> Die Konfiguration traegt nach dem Update KEIN Aktionstoken (unlesbar, abgeschnitten oder ohne Sicherung): $CF"
    echo "<WARNING> Beim ersten Oeffnen der Oberflaeche entsteht ein neues Token - die Adressen in Loxone muessen dann angepasst werden. Bitte Plugin-Oberflaeche oeffnen und Bundesland pruefen."
else
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Standort und Warnzelle selbst eintragen."
fi
exit 0
