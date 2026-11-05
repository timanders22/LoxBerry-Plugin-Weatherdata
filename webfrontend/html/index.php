<?php
/**
 * Absichtlich ohne Inhalt.
 *
 * Diese Datei verhindert, dass der Apache das Verzeichnis auflistet.
 * Die eigentliche Oberflaeche liegt unter webfrontend/htmlauth/.
 */
header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain; charset=utf-8');
echo "Hier gibt es nichts zu sehen. Die Oberflaeche des Plugins liegt im\n"
   . "angemeldeten Bereich von LoxBerry unter Plugins.\n";
