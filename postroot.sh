#!/bin/bash
# Weatherdata - laeuft als root nach postinstall und postupgrade. Einzige Aufgabe: Port 6066 fuer den Emulator richtet sich nach dem Schalter im Plugin.
# Schalter aus (ab Werk): Es wird nichts Systemweites angelegt; eine eigene Datei von frueher wird entfernt.
ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-weatherdata}"; BASE="${ARGV5:-$LBHOMEDIR}"
case "$PFOLDER" in ''|*[!A-Za-z0-9_-]*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - dieses Skript tut nichts."; exit 0 ;; esac
if [ -z "$BASE" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - dieses Skript tut nichts."
    exit 0
fi
ETC="${WD_APACHE_ETC:-/etc/apache2}"
SK="$BASE/bin/plugins/$PFOLDER/wd_emu_apache.sh"
CF="$BASE/config/plugins/$PFOLDER/weatherdata.json"
EIN=$(php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); echo (is_array($d) && !empty($d["emu_ein"])) ? 1 : 0;' -- "$CF" 2>/dev/null)
if [ "$EIN" = "1" ]; then
    A=$(bash "$SK" ein 2>&1); RC=$?
    echo "$A"
    if [ "$RC" != 0 ]; then
        echo "<INFO> Der Emulator bleibt ohne Port 6066 (Rueckgabe $RC); das Plugin selbst ist vollstaendig installiert. Lage: Reiter Emulator."
    fi
elif [ -e "$ETC/sites-available/001-$PFOLDER-emu.conf" ] || [ -e "$ETC/sites-enabled/001-$PFOLDER-emu.conf" ]; then
    bash "$SK" aus 2>&1
fi
exit 0
