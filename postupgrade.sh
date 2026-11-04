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

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

if [ -d "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" ]; then
    echo "<WARNING> Die Update-Sicherung liegt noch da - die Rueckgabe in postinstall.sh ist nicht vollstaendig gelungen: $BASE/data/plugins/$PFOLDER.upgrade_sicherung"
fi

rm -f "/tmp/$PFOLDER/zustand.json" "/tmp/$PFOLDER/mqtt_sig.txt" 2>/dev/null
echo "<OK> Update abgeschlossen."
exit 0
