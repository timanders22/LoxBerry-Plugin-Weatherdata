#!/bin/bash
# Weatherdata - Port 6066 fuer den Emulator im Apache ein- und austragen. Nur als root: postroot.sh ruft es nach dem Schalter, oder der Nutzer von Hand.
# Bewusst keine sudo-Regel: Eine Regel auf ein Skript, das loxberry aendern darf, waere ein Weg zu root.
# Aufruf: wd_emu_apache.sh ein|aus|lage
# Rueckgabe: 0 erledigt, 2 falscher Aufruf oder kein root, 3 Port belegt (nichts geaendert), 4 Apache lehnt ab (zurueckgenommen), 5 Neuladen gescheitert (zurueckgenommen).
WAS="${1:-}"
ETC="${WD_APACHE_ETC:-/etc/apache2}"
PORT=6066
SELF="$(readlink -f "$0" 2>/dev/null || echo "$0")"
PLUGIN="$(basename "$(dirname "$SELF")")"
WURZEL="$(dirname "$(dirname "$(dirname "$(dirname "$SELF")")")")"
case "$PLUGIN" in ''|*[!A-Za-z0-9_-]*) echo "<FAIL> Unzulaessiger Ordnername '$PLUGIN'."; exit 2 ;; esac
NAME="001-$PLUGIN-emu"
DATEI="$ETC/sites-available/$NAME.conf"
DOCROOT="$WURZEL/webfrontend/html/plugins/$PLUGIN/emu"

fremd_aktiv() { # aktive Apache-Dateien mit Port 6066, ohne die eigene
    for F in "$ETC/ports.conf" "$ETC/apache2.conf" "$ETC"/sites-enabled/* "$ETC"/conf-enabled/*; do
        [ -f "$F" ] || continue
        [ "$(basename "$F")" = "$NAME.conf" ] && continue
        if grep -qiE "^[[:space:]]*(Listen[[:space:]]+([^[:space:]]+:)?$PORT([[:space:]]|\$)|<VirtualHost[^>]*:$PORT[[:space:]>])" "$F" 2>/dev/null; then
            basename "$F"
        fi
    done
}
port_lauscht() { # 0, wenn etwas auf dem Port lauscht
    command -v ss >/dev/null 2>&1 || return 1
    ss -ltn 2>/dev/null | grep -qE "[:.]$PORT[[:space:]]"
}
eigen_aktiv() { [ -e "$ETC/sites-enabled/$NAME.conf" ]; }

case "$WAS" in
    lage)
        if eigen_aktiv; then echo "<INFO> Port $PORT: eigene Apache-Datei aktiv ($NAME)."; else echo "<INFO> Port $PORT: eigene Apache-Datei nicht aktiv."; fi
        FR="$(fremd_aktiv)"
        [ -n "$FR" ] && echo "<INFO> Port $PORT belegt auch: $(echo $FR)"
        exit 0
        ;;
    ein|aus) ;;
    *) echo "Aufruf: wd_emu_apache.sh ein|aus|lage"; exit 2 ;;
esac
if [ "$(id -u)" != "0" ]; then
    echo "<FAIL> Das braucht root: $SELF $WAS"
    exit 2
fi

if [ "$WAS" = "aus" ]; then
    if [ ! -e "$DATEI" ] && ! eigen_aktiv; then echo "<OK> Port $PORT war nicht eingetragen, nichts zu tun."; exit 0; fi
    a2dissite "$NAME" >/dev/null 2>&1
    rm -f "$DATEI" "$ETC/sites-enabled/$NAME.conf"
    if apache2ctl graceful >/dev/null 2>&1; then
        echo "<OK> Port $PORT ausgetragen, Apache neu geladen."
    else
        echo "<WARNING> Port $PORT ausgetragen, der Apache liess sich aber nicht neu laden."
    fi
    exit 0
fi

# ein
[ -d "$DOCROOT" ] || { echo "<FAIL> Der Endpunkt fehlt: $DOCROOT"; exit 2; }
FR="$(fremd_aktiv)"
if [ -n "$FR" ]; then
    echo "<WARNING> Port $PORT ist schon belegt durch: $(echo $FR). Es wurde nichts geaendert."
    echo "<WARNING> Vermutlich der Emulator von Weather4Lox. Zwei Eintraege auf einem Port liessen den Apache nicht mehr starten. Bitte erst dort abschalten."
    exit 3
fi
if ! eigen_aktiv && port_lauscht; then
    echo "<WARNING> Auf Port $PORT lauscht schon ein anderes Programm. Es wurde nichts geaendert."
    exit 3
fi
ALT=""
[ -f "$DATEI" ] && ALT="$(cat "$DATEI")"
umask 022
if ! { printf '# Weatherdata: Emulator des Loxone-Wetterdienstes. Angelegt von wd_emu_apache.sh, entfernt von dessen Aufruf "aus" und der Deinstallation.\n'
       printf 'Listen %s\n\n<VirtualHost *:%s>\n    ServerSignature Off\n    DocumentRoot %s\n    # Die Abfrage traegt Seriennummer und Lage: nicht ins Zugriffsprotokoll.\n    CustomLog /dev/null combined\n' "$PORT" "$PORT" "$DOCROOT"
       printf '    <Directory %s>\n        Options None\n        AllowOverride None\n        Require all granted\n        DirectoryIndex index.php\n    </Directory>\n</VirtualHost>\n' "$DOCROOT"
     } > "$DATEI.neu" || ! mv -f "$DATEI.neu" "$DATEI"; then
    rm -f "$DATEI.neu"
    echo "<FAIL> $DATEI liess sich nicht schreiben."
    exit 2
fi
ruecknehmen() {
    a2dissite "$NAME" >/dev/null 2>&1
    rm -f "$ETC/sites-enabled/$NAME.conf"
    if [ -n "$ALT" ]; then printf '%s\n' "$ALT" > "$DATEI"; else rm -f "$DATEI"; fi
}
if ! a2ensite "$NAME" >/dev/null 2>&1; then
    ruecknehmen
    echo "<FAIL> a2ensite ist gescheitert, zurueckgenommen."
    exit 4
fi
if ! apache2ctl configtest >/dev/null 2>&1; then
    ruecknehmen
    echo "<FAIL> Der Apache lehnt die Einstellung ab (configtest), zurueckgenommen. Der laufende Apache ist unberuehrt."
    exit 4
fi
if ! apache2ctl graceful >/dev/null 2>&1; then
    ruecknehmen
    apache2ctl graceful >/dev/null 2>&1
    echo "<FAIL> Der Apache liess sich nicht neu laden, zurueckgenommen."
    exit 5
fi
echo "<OK> Port $PORT eingetragen ($NAME), Apache neu geladen."
exit 0
