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
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung.neu"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BASE/config/plugins/$PFOLDER.backup.json.alt" ] && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json.alt" 2>/dev/null
for T in zustand.json mqtt_sig.txt abruf_letzt empfang.json zaehler.txt emu_stunden.json emu_abruf.json; do
    rm -f "/tmp/$PFOLDER/$T" 2>/dev/null
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Daten einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
