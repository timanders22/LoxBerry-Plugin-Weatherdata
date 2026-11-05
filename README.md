# LoxBerry-Plugin: Weatherdata

Liefert Wetterwerte, Prognosen, amtliche Warnungen, Handlungssignale und fertige Sätze an Loxone, über das MQTT-Gateway V2. Es braucht weder eine eigene Wetterstation noch einen Schlüssel. Kompatibel mit LoxBerry 3.x und 4.x (reines PHP, 7.4 bis 8.5).

**Zusatzinformation, kein Warnsystem:** Fehlt eine Ansage, heißt das nicht, dass keine Gefahr besteht. NINA und amtliche Warnkanäle ersetzt dieses Plugin nicht.

## Was das Plugin kann

- **Prognose gemischt** aus Open-Meteo, DWD MOSMIX (über Bright Sky) und MET Norway, wahlweise wttr.in: Tiefst- und Höchstwerte, Regen, Schnee, Böen, Strahlung für 1, 3, 4, 12, 24 und 48 Stunden, Tageswerte für heute und zwei Folgetage. Regeln: Temperatur gewichtet, Tiefstwert der kleinste, Höchstwert und Böe der größte, Regen der Median (dazu `regen_max`), Wettercodes nie gemittelt (führend Open-Meteo). Fällt eine Quelle aus, ändert sich die Gewichtung über 30 Minuten gleitend.
- **Fehlerbilanz:** je Quelle und Frist (0 bis 6, 6 bis 24, 24 bis 72 Stunden) gegen die eigene Station, 30 Tage. Gewicht 1 durch Fehler im Quadrat, unter 14 Tagen gleich; Bias-Abzug nur als Haken, ab Werk aus. Ohne Station gibt es keine Bilanz, dann gilt gleiches Gewicht.
- **Umwelt:** DWD-Pollenflug je Teilregion (Hasel, Erle, Esche, Birke, Gräser, Roggen, Beifuß, Ambrosia), Luftindex, Feinstaub, Ozon und NO2 aus CAMS über Open-Meteo (Modellwert, keine Messung), UV. Signal `lueftung_sperren` nur für angehakte Pollenarten.
- **Radar** über Bright Sky: `radar/regen_in_min`, verdichtet auf den Standort.
- **Fassade:** höchste Strahlung auf senkrechter Fläche je Ausrichtung (ab Werk aus).
- **Station auf drei Wegen:** über die Ecowitt-Weiche, direkt (`get_livedata_info`, zwei Adressen) oder per Empfang (Ecowitt- und Wunderground-Protokoll an `wd_empfang.php` mit Token).
- **Wetterseite für die Loxone-App** (Reiter Wetterseite): aktuelle Werte, die nächsten 48 Stunden, bis zu 7 Tage, Warnungen, Pollen, Luft und Regenradar, fünf Farbdesigns, vier Symbolsets, hell, dunkel oder wie das Gerät, Deutsch und Englisch. Dazu der Kachelwert `wd.php?kachel=1` als JSON.
- **Ab Werk aus, abschaltbar und im Reiter Quellen benannt:** NINA (laut Hinweis in den Meldungen nur inhaltlich unverändert und nicht gewerblich; eine Lizenz nennt die Schnittstelle nicht; nur Nicht-Wetter-Meldungen, Wortlaut unverändert), Meteoalarm (Lizenz wie CC BY 4.0 mit Zusatzregeln, die das Plugin erfüllt; nur für Nutzer außerhalb Deutschlands), OpenWeather, WeatherAPI, Visual Crossing, Tomorrow.io, Meteoblue (je eigener Schlüssel; die Adapter sind nach der Dienstdoku gebaut und ohne Schlüssel nie gegen eine echte Antwort gefahren). Wer einschaltet, klärt die Bedingungen selbst.
- **Emulator des Loxone-Wetterdienstes,** ab Werk aus, nicht von Loxone, eigener Reiter, siehe eigener Abschnitt.
- **Warnungen** vom Deutschen Wetterdienst, je Warnzelle (DWD-CAP). Fällt der DWD aus, springt Bright Sky ein. Warnstufe 0 bis 4 ist die amtliche Schwere. Keine Warnung (0) und nichts bekannt (Strich) sind verschieden.
- **Ist-Werte**, wahlweise von der eigenen Station über die Ecowitt-Weiche. Fehlt die Station oder ist sie nicht plausibel, gilt der Prognosewert für jetzt, gekennzeichnet mit `jetzt/ist_ersatz`.
- **Gefühlte Temperatur** immer aus derselben Formel, selbst gerechnet: Windchill und Hitzeindex nach den Formeln des US National Weather Service, sonst Lufttemperatur. Das ist **nicht** die Gefühlte Temperatur des DWD.
- **Eigene Schwellen** ab Werk 32, 35 und 38 °C, frei änderbar, mit Hysterese; dazu Frost, Sturm, Starkregen und UV. Meldung immer als eigene Rechnung, nie als Hitzewarnung.
- **Handlungssignale**, getrennt von der Warnstufe: `hitze/warnung`, `kuehlen_moeglich`, `uv/hoch`, `frost/erwartet`, `starkregen/warnung`, `sturm/vorwarnung`. Was daraus wird, entscheidet Loxone.
- **Sprachansagen** über das gemeinsame Sprachmodul.
- **Ersatz für Weather4Lox-Werte:** Neue Themen, nichts wird umbenannt; Tabelle im Reiter „Einbindung in Loxone“.

## Sprachansagen sind ab Werk an

Ausschalten im Reiter **Warnungen**: Ausgabeart „aus“, oder je Anlass der Haken. Ein bewusstes Aus bleibt aus. Es gelten Nachtruhe 22 bis 7 Uhr, höchstens eine Ansage je Anlass in 30 Minuten, und der Loxone-Schalter `haus/ansage/ruhe` schaltet auch Dringendes aus.

| Lage | Tag | Nachtruhe |
|---|---|---|
| Stufe 1: Windböen, Frost, leichter Schnee, Nebel, Glätte | Nur Anzeige; Haken je Klasse | Still |
| Stufe 2 | Einmal | Still, morgens nachholen, wenn noch gültig |
| Vorabinformation Unwetter | Einmal | Still |
| Stufe 3: Gewitter, orkanartige Böen, Orkan, heftiger Starkregen | Dringend | **Dringend, weckt** |
| Stufe 3: Glatteis, starker Schneefall, Verwehung, Tauwetter, Dauerregen | Einmal | Still, morgens dringend, wenn noch gültig |
| Stufe 4 | Dringend | **Dringend, weckt** |
| Hitze, eigene Hitzeschwellen, UV | Morgens und einmal mittags | Nie |

Hitze 248 hat technisch Stufe 3, UV 246 und Hitze 247 Stufe 1; die Stufe allein entscheidet deshalb nicht.

Eine Vorabinformation ist amtlich ab Stufe 3 und bleibt trotzdem still: Sie weckt auf keiner Stufe.

Gewitter der Stufe 1 (31, 90) werden nur angezeigt und nie gesprochen; für sie gibt es keinen Haken.

**Lüften:** An einem Hitzetag kommt eine Ansage, sobald es außen um mehr als die eingestellte Spanne kühler ist als innen. Wird es erst in der Nacht kühler, kommt kurz vor der Nachtruhe (ab Werk 21:50) ein Hinweis. Den Innenwert sendet Loxone über `wd.php?innen=`, sonst gilt der Innenfühler der Station. Ohne Innenwert gibt es keine Aussage.

## Standort und Warnzelle

Beides tragen Sie selbst ein. Die Warnzelle lässt sich aus dem Standort vorschlagen; übernommen wird sie erst, wenn Sie sie eintragen. Der Standort geht gerundet (ab Werk 3 Nachkommastellen) an die eingeschalteten Dienste, ab Werk Open-Meteo, Bright Sky (MOSMIX, Radar) und MET Norway, eingeschaltet auch wttr.in, dorthin immer mit 2 Nachkommastellen; die Warnzelle an den DWD und im Rückfall an Bright Sky; der Vorschlag der Warnzelle fragt einmal Bright Sky mit dem Standort. Jeder Dienst lässt sich im Reiter Quellen abschalten. Ins Protokoll und in MQTT kommt beides nie, in eine Sicherungsdatei nur als Ihre Einstellung. Warnungen gibt es nur für Deutschland.

## MQTT

Virtuelle Eingänge heißen wie der Gateway-Name; Haken je Thema nach der Hakenliste im Reiter MQTT (Pflicht und wahlweise getrennt). Retained ist nur `fassung`. Keine Aussage ist ein Strich `-`, nie ein leerer Wert und nie der alte Wert; die Logik hängt an den Zahlenthemen `.../ok`. Wie ein Virtueller Eingang den Strich behandelt, bitte an der eigenen Anlage messen.

## Emulator des Loxone-Wetterdienstes

**Ab Werk aus. Nicht am Miniserver gemessen, nur gegen eine Nachbildung seiner Abfrage; Rückmeldung erbeten.** Der Emulator stammt nicht von Loxone. Der Miniserver holt damit die Wetterdaten vom LoxBerry, als kämen sie vom Dienst; die Daten kommen aus dem Prognose-Bestand dieses Plugins. Prüfen Sie selbst, ob das zu Ihrem Loxone-Vertrag und den Bedingungen des Wetterdienstes passt. Das Plugin gibt dazu keine Zusage.

| Miniserver | Geht es? |
|---|---|
| Generation 1 | Unterstütztes Ziel. Sie kann kein HTTPS, die Zertifikatsprüfung trifft sie laut Weather4Lox-Wiki nicht. |
| Generation 2 bis Config 15 | Laut Weather4Lox-Wiki ja, ebenfalls unterstützt. |
| **Generation 2 ab Config 16** | **Nein.** Der Miniserver prüft über HTTPS das Zertifikat. Das Plugin versucht nicht, das zu umgehen. Dort bleibt der Weg über MQTT und Virtuelle Eingänge. |

Was Sie selbst tun müssen:

1. Generation, Config-Fassung und Vertrag prüfen.
2. Reiter „Emulator“: Schalter einschalten und speichern.
3. Port 6066 öffnen. Der Miniserver fragt fest diesen Port, und das Plugin ändert den Apache nicht still. Ohne Kommandozeile: das Plugin bei eingeschaltetem Schalter erneut einspielen (Plugin-Verwaltung, ZIP dieser Fassung). Für Geübte einmal als root `<LoxBerry>/bin/plugins/weatherdata/wd_emu_apache.sh ein`. Das Skript legt eine eigene Apache-Datei an, prüft sie mit `apache2ctl configtest` und nimmt sie zurück, wenn der Apache sie ablehnt. Ausschalten: `... aus` und Schalter aus. Die Deinstallation entfernt sie. Es gibt keine sudo-Regel und keinen Dauerdienst.
4. Namensauflösung: `weather.loxone.com` und `weather-beta.loxone.com` müssen für den Miniserver auf die Adresse des LoxBerry zeigen. Empfohlen: ein eigener Nameserver (Pi-hole, AdGuard Home oder auf dem LoxBerry das Plugin [DNSmasq](https://wiki.loxberry.de/plugins/dnsmasq/start) laut Weather4Lox-Wiki mit `address=/weather.loxone.com/<IP des LoxBerry>` und `address=/weather-beta.loxone.com/<IP des LoxBerry>`). **a) zuerst, kleinster Eingriff:** nur der Miniserver nutzt ihn (Loxone Config, Miniserver konfigurieren, Netzwerk: „Statische Adresse verwenden“, Feld „DNS Server“; Feldnamen aus der Sprachdatei von Config); die FRITZ!Box bleibt unverändert. **b) alle Geräte:** FRITZ!Box Heimnetz > Netzwerk > Netzwerkeinstellungen > Erweiterte Netzwerkeinstellungen ändern > IPv4, Abschnitt DHCP, Feld „Lokaler DNS-Server“ ([AVM 165](https://fritz.com/apps/knowledge-base/FRITZ-Box-4060/165_Andere-DNS-Server-in-FRITZ-Box-einrichten)); reicht die FRITZ!Box Anfragen an den eigenen Server weiter, beide Namen als Ausnahme im DNS-Rebind-Schutz eintragen ([AVM 3565](https://fritz.com/apps/knowledge-base/FRITZ-Box-4060/3565)). Gerätenamen der FRITZ!Box und eine hosts-Datei helfen hier nicht. Keiner der Wege ist mit diesem Plugin gemessen; das Plugin richtet keinen Nameserver ein.
5. Loxone Config: Unter Projekt den Wetterserver einschalten, Miniserver neu starten. Der Miniserver fragt laut Weather4Lox-Wiki nur einmal je Stunde.
6. Der Reiter zeigt die letzte Abfrage. Der Healthcheck meldet einen fehlenden Port, belegten Port, fehlende Daten und ausbleibende Abfragen.

**Konflikt:** Der Emulator von Weather4Lox belegt denselben Port. Zwei Einträge ließen den Apache nicht mehr starten. Das Skript und der Reiter erkennen jede aktive Apache-Datei, die Port 6066 belegt, dann ändert das Skript nichts. Zuerst dort abschalten. Ein Plugin namens `wxparser` haben wir nirgends gefunden; belegte es den Port, würde es genauso erkannt.

**Vertrag und Abo:** Der Emulator meldet dem Miniserver ein Abo-Enddatum (31.12. des Folgejahres). Laut Weather4Lox-Wiki zeigt die App danach keinen Hinweis auf das Loxone-Wetterabo mehr. Der Reiter sagt das offen; ob es zum eigenen Vertrag passt, prüft jeder selbst.

**Was der Endpunkt preisgibt:** nur Wetterdaten. Der eingetragene Standort steht nie in der Antwort; Länge, Breite, Höhe und Sonnenzeiten beziehen sich nur auf das, was die Anfrage selbst nennt. Ab Werk antwortet er nur den in LoxBerry eingetragenen Miniservern. Seriennummer und Lage aus der Abfrage kommen weder ins Protokoll des Plugins noch ins Zugriffsprotokoll des Apache auf Port 6066. Fehlen frische Daten, antwortet er 503, nie mit alten Werten. Ohne Open-Meteo bleiben Druck, Windrichtung und Wolken 0, das Wetterbild kommt dann aus Regen und Schnee. Mit eingeschaltetem Emulator holt das Plugin von Open-Meteo 8 statt 7 Tage und zusätzlich Druck, Windrichtung und Wolken.

**Nicht belegt:** Der Apache-Teil lief nur gegen Attrappen, nie gegen einen echten Apache. Der Inhalt der Antwort ist nur am Quelltext von Weather4Lox abgelesen; ob der Miniserver den Schneeanteil als 0 bis 1 oder als Prozent liest, ist offen (hier 0 bis 1). Die Bedeutung des Felds `valid_until` steht nirgends dokumentiert.

## Quellen und Lizenzen

Dieses Plugin enthält keinen fremden Code. Es liest:

- [Open-Meteo](https://open-meteo.com/): Wetterdaten, CC BY 4.0. Nicht gewerblich frei; Heimautomation ist in den Bedingungen genannt. Für gewerbliche Nutzung gibt es ein Feld für den eigenen Schlüssel.
- [Deutscher Wetterdienst](https://www.dwd.de/) (`opendata.dwd.de`): Warnungen, CC BY 4.0, ohne Gewähr und ohne Verfügbarkeitszusage. Der Wortlaut amtlicher Warnungen geht unverändert in `warnung/volltext`, gekürzt auf 900 Zeichen.
- [Bright Sky](https://brightsky.dev/): aufbereitete DWD-Daten, Einzelbetreiber, keine Zusage.
- [MET Norway](https://www.met.no/): Locationforecast, CC BY 4.0, Werte verändert (gemischt). Wahrer `User-Agent`, höchstens 4 Nachkommastellen, `Expires` wird beachtet.
- [wttr.in](https://wttr.in/): ab Werk aus, ohne Schlüssel. Der Programmcode des Dienstes steht unter Apache-2.0; für die Daten nennt das Projekt keine Bedingungen und keine Zusage; Antwort und Unterlagen verweisen auf World Weather Online. Wer einschaltet, klärt das selbst. Abruf höchstens alle drei Stunden.
- CAMS (Copernicus Atmosphere Monitoring Service) über Open-Meteo: Luftqualität.
- DWD Pollenflug-Gefahrenindex `s31fg.json`: CC BY 4.0.
- Formeln: US National Weather Service (Windchill, Hitzeindex).
- **Wettersymbole der Wetterseite** (`webfrontend/html/symbole/<set>/`, je Ordner `LICENSE.txt` und `QUELLE.txt`; Kommentare und unbenutzte Kennungen entfernt, Kennungen mit Präfix, sonst unverändert):
  - [Meteocons](https://github.com/basmilius/meteocons) von Bas Milius, npm `@meteocons/svg-static` 0.1.0, Stil `fill`, MIT. 21 Bilder, etwa 80 KB.
  - [Phosphor Icons](https://github.com/phosphor-icons/core), npm `@phosphor-icons/core` 2.1.1, Stil `duotone`, MIT. 21 Bilder, etwa 14 KB.
  - [Bootstrap Icons](https://github.com/twbs/icons) 1.13.2, MIT. 21 Bilder, etwa 15 KB.
  - „Klar“: selbst gezeichnet für dieses Plugin, MIT wie das Plugin. 21 Bilder, etwa 9 KB.
  - Keine Symbole aus Weather4Lox. Die Farbdesigns sind eigene Farbsätze.
- Die Ecowitt-Weiche ist ein eigenes Plugin und bleibt getrennt installiert.

Die Unwetterzentrale ist wegen ihrer Bedingungen bewusst nicht eingebaut. **WetterOnline ist ebenfalls nicht eingebaut, anders als bei Weather4Lox:** Es gibt keine öffentlich dokumentierte Schnittstelle; Weather4Lox nutzt Adressen, die für die WetterOnline-App gedacht sind. Die AGB (Fassung vom 24.02.2025, Ziffer 2) untersagen die unberechtigte Vervielfältigung und Speicherung der Inhalte und verweisen auf eine Anfrage vorab. Das Antwortformat des Emulators ist am öffentlichen Quelltext von Weather4Lox (Apache-2.0, Michael Schlenstedt, Jan Wachsmuth) abgelesen; es ist keine Zeile daraus übernommen. „Loxone“ ist eine Marke ihres Inhabers. Lizenz dieses Plugins: MIT.

## Neu in 0.9.7

Am Gerät, am Miniserver und in der Loxone-App nicht gemessen; im Browser in Handy- und Tabletbreite angesehen.

- **Neuer Reiter „Wetterseite“:** eine vollwertige Seite für den Baustein Webpage der Loxone-App: jetzt, 48 Stunden mit Temperaturkurve und Regenbalken, bis zu 7 Tage, amtliche Warnungen, NINA, Pollen, Luft, Regenradar, Quellenangaben samt MeteoAlarm-Pflichtsatz. Fehlende Werte sind ein Strich. Fünf Farbdesigns (Frisch ab Werk, Garten, Sand, Schiefer, Abend), vier Symbolsets (Meteocons ab Werk, Klar, Phosphor, Bootstrap Icons), hell, dunkel oder wie das Gerät, Deutsch oder Englisch. Auswahl im Reiter mit Vorschau, die fertige Adresse zum Kopieren und die Schritte in Loxone Config. Adresse: `wetter.php?mode=auto|light|dark&lang=de|en&theme=…&iconset=…`; unbekannte Werte fallen auf die Vorgabe. Kein Skript, nichts von außen, kein Standort, keine Anmeldung im Heimnetz.
- **Neuer Reiter „Emulator“:** Der Emulator zieht aus dem Reiter Einbindung um. Wegweiser „Brauchen Sie das?“, offener Hinweis auf das gemeldete Abo-Enddatum, Port ohne Kommandozeile (erneut einspielen), Namensauflösung Schritt für Schritt (eigener Nameserver, zuerst nur für den Miniserver; FRITZ!Box-Klickwege nach AVM, DNS-Rebind-Schutz), Hilfe bei Fehlern.
- **Einstellungen und Quellen in Alltagssprache:** Fachbegriffe ersetzt (Genauigkeit des Standorts statt Nachkommastellen, Abstand beim Zurücknehmen statt Hysterese, Gemeindenummer statt Regionalschlüssel), Hilfe zum Finden des Standorts. Hinweis auf das FOSHKplugin bei der Station. Keine Funktionsänderung.
- **Prognose bis zu 7 Tage** von Open-Meteo (bisher 4); MQTT-Themen unverändert.
- **Eigene Messwerte von Loxone** (Reiter Einbindung, ab Werk aus): Außentemperatur, Feuchte, Wind, Böe, Regen, Regensensor, Strahlung oder Helligkeit über `wd.php?lox_<wert>=<v.1>&token=…`, fertige Vorlage `VQ_weatherdata_messwerte.xml` für einen Virtuellen Ausgang. Jeder Wert gilt einstellbar lange (Vorgabe 30 Minuten), wird wie die Station geprüft und überschreibt nur die aktuellen Werte, nie die Vorhersage. Rangfolge je Wert: eigene Station, Wunderground, Loxone, Prognose. Rückkopplungssperre: eigene Endpunktnamen; kommt ein Wert im Gleichtakt mit dem von Weatherdata veröffentlichten zurück, wird er nicht angenommen und gemeldet.
- **Wunderground (eigene Station)** (ab Werk aus): aktuelle Beobachtung über die PWS-Schnittstelle der Weather Company (`api.weather.com/v2/pws/observations/current`, metrisch), höchstens alle 10 Minuten. Nur für Stationsbetreiber, die Daten an Wunderground senden; Schlüssel unter https://www.wunderground.com/member/api-keys. Der Schlüssel kommt in keine Sicherung und kein Protokoll. Mit einer echten Station nicht gemessen.
- **Meteoalarm nennt den nationalen Wetterdienst** des Landes (Bedingungen von MeteoAlarm, Ziffer 5), Namen wortgleich aus der Liste „Our Warning Providers“ (gelesen am 05.11.2026), auch auf der Wetterseite.
- **Reiter Werte:** eigene Messung neben dem Wetterdienst mit Abweichung; deutlich andere Werte farbig und in Worten (Schwellen im Hilfetext). Herkunft in Worten, die gefühlte Temperatur nennt ihre Rechnung.
- **Musterseiten** „Weatherdata“ und „Weatherdata Werte und Signale“; die Baustein-Liste im Reiter Einbindung gleicht ihnen Wort für Wort.

## Neu in 0.9.6

Am Gerät nur der Befund gemessen, die Korrektur gegen die echte Weiche und eine nachgebildete Station.

- **Station über die Ecowitt-Weiche:** Das Plugin suchte das Token in einer Datei, die es nicht gibt, und bekam darum HTTP 403, sobald die Weiche ein Token verlangt. Es liest jetzt `ecowitt.json` im Ordner der Weiche. Das Token bleibt im Speicher.
- **Quellenübersicht:** Anzeigenamen statt technischer Namen. Sie sagt, was fehlt („keine Teilregion eingetragen“), und dass Bright Sky für Warnungen nur Rückfall ist („nur Rückfall, nicht gefragt“). HTTP 403 und ähnliche Antworten stehen im Klartext.
- **wttr.in** bekommt den Standort immer mit genau 2 Nachkommastellen, gleich welche Rundung eingestellt ist.
- **NINA:** Suche nach der Gemeinde in der amtlichen Liste des Statistischen Bundesamts, nur auf Knopfdruck. Der Suchtext verlässt das Gerät nicht.
- **Meteoalarm:** Die Bedingungen sind gelesen (Stand 15.03.2024): Nutzung wie CC BY 4.0 mit Zusatzregeln. Das Plugin nennt deshalb bei jeder Warnung Ausgabezeit und Quelle, verweist auf www.meteoalarm.org und fragt alle 5 statt 15 Minuten. Auswahl des Landes mit Namen. Hinweis, dass der Dienst nur für Nutzer außerhalb Deutschlands gedacht ist. Regionen lassen sich aus dem Feed übernehmen; er nennt nur Regionen mit aktueller Warnung.
- **Pollen:** Auswahl der Teilregion mit den Namen aus der DWD-Datei. Ein schon eingetragener Wert bleibt gültig, auch beim Land von Meteoalarm.
- **Schlüssel:** Je Dienst ein Link zur Anmeldung und ein Satz zu den freien Grenzen, Stand 05.11.2026. Bei Meteoblue reicht das freie Guthaben bei stündlichem Abruf nicht das ganze Jahr.
- **Sicherung:** Die Schlüssel der Dienste stehen nicht mehr in der Datei; beim Zurückspielen bleiben die eingetragenen. Eine ältere Datei mit Schlüsseln wird weiter angenommen. Das Aktionstoken bleibt darin.
- **Reiter Quellen:** NINA, Meteoalarm und die Dienste mit Schlüssel haben einen eigenen Speichern-Knopf. Der Reiter sagt bei der Station, dass bei installierter Weiche nichts einzutragen ist.
- **Update:** Scheitert die Sicherung oder die Marke, bricht das Update ab und die bisherige Fassung bleibt.
- **Sprechtoken:** Ein von Hand eingetragenes Alexa- oder Google-Token wird nicht mehr vom Token des Nachbar-Plugins überschrieben.
- **Formular:** Tafel- und HomePod-Felder gehören jetzt zu den Feldern, die eine Beanstandung im Formular mitführt und markiert. Ein beanstandeter Tafel- oder HomePod-Name bleibt zur Korrektur im Feld stehen.

## Neu in 0.9.4

Am Gerät und am Miniserver nicht gemessen.

- **Neu: wttr.in als weitere Prognosequelle,** ab Werk aus, ohne Schlüssel, Standort gerundet. Abruf höchstens alle drei Stunden, ein Fehlschlag verkürzt das nicht: Der Dienst ist ein Einzelbetrieb, und seine Daten kommen ohnehin in 3-Stunden-Schritten. Die Schritte werden für die Mischung zu Stundenwerten. Passt die Antwort nicht zum Standort, zählt sie nicht. Die Böen von wttr.in liegen oft hoch; weil die Mischung die größte Böe nimmt, können sie Sturmsignale früher auslösen.
- **Neu: Emulator des Loxone-Wetterdienstes,** ab Werk aus, eigener Abschnitt oben. Gen. 2 ab Config 16 geht nicht.
- **WetterOnline ist nicht eingebaut,** Begründung unter „Quellen und Lizenzen“.
- **Sicherung:** Eine Datei von vor 0.9.4 wird weiter angenommen; die neuen Felder gelten dann mit ihrer Vorgabe.
- **Weitere Kleinigkeit:** `webfrontend/html/index.php` verhindert die Verzeichnisliste. `?abruf=1` rechnet in derselben Zeitzone wie der Minutenlauf.

## Neu in 0.9.3

- **Neu: LoxBerry-Healthcheck.** `bin/healthcheck` meldet nur aus Dateien, ohne Netz, ob die Konfiguration stimmt, Standort und Warnzelle eingetragen sind, der Minutenlauf läuft, Open-Meteo und DWD antworten und die Pflichthaken im MQTT-Gateway V2 gesetzt sind. Standort und Warnzelle stehen nie in der Antwort.

## Neu in 0.9.2

Korrekturen nach einer Fremdprüfung des Codes, dazu ein neues Symbol. Am Gerät nicht gemessen.

- **Update verliert nichts mehr still.** Ließ sich die Update-Sicherung nicht anlegen, lief das Update weiter und löschte die Originale. Jetzt bricht es ab, die bisherige Fassung bleibt installiert. Scheitert das Zurückstellen einer Datei, bleibt die Sicherung liegen.
- **Die Prognose-Bilanz übersteht ein Update.** Bisher fing die Gewichtung der Quellen nach jedem Update wieder bei null an (14 Tage Vorlauf). Gesichert wird jetzt der ganze Datenordner.
- **Nie zwei Läufe zugleich.** Der Knopf „Dienste jetzt abfragen“ und `?abruf=1` liefen neben dem Minutenlauf; Warnungen konnten doppelt gezählt und doppelt gesagt werden. Ist ein Lauf aktiv, sagt der Knopf das, der Endpunkt antwortet `429 ERR=LAEUFT`.
- **Die Bilanz misst, was sie soll.** Sie rechnet nicht mehr nur in Minute 00, ein verspäteter Lauf fiel sonst aus. Jede Frist merkt die Prognose ihres mittleren Vorlaufs (3, 12, 48 Stunden); bisher maß die kurze Frist Minuten statt Stunden. Die Bilanz ändert damit über 30 Tage allmählich ihre Bedeutung.
- **Sicherung zurückspielen prüft die Sprachausgabe.** Ungültige Werte werden abgewiesen, ein HomePod-Token in der Datei auch, das geltende HomePod-Token bleibt.
- **Nach einer Beanstandung im Reiter Warnungen** bleiben auch die Eingaben von Sprachausgabe, Regeln und weiteren Zielen stehen.
- **Protokoll leeren** verlangt einen Haken, prüft den Erfolg und leert auch `cron.err`.
- **Stufe-3-Warnung morgens:** Ist die Nachtruhe ausgeschaltet, gilt sie zwischen `bis` und zwei Stunden danach nicht mehr als dringend.
- **Präfix geändert:** Die Abo-Datei für den Rückfall auf Gateway V1 folgt ihm. Maßgeblich bleibt die Hakenliste für MQTT-Gateway V2.
- **Deinstallation:** Das Thema `fassung` wird zehnmal gelöscht, weil das Gateway Datagramme verliert; das Präfix kommt zur Not aus der Zweitschrift.
- **Kleineres:** Oberfläche und Endpunkte zeigen keine PHP-Fehler mehr an, das würde Downloads zerstören; ein Listen-Upload führt unter PHP 8 nicht mehr zu HTTP 500; `?status`, `?kachel` und `wetter.php` legen keinen Ordner mehr an; unbekannte Schalter von `wd_takt.php` enden mit Rückgabe 2; `ERR=SCHREIBEN` kommt als 500, `ERR=BREMSE` als 429.
- **Neues Symbol.**

## Neu in 0.9.1

Die Warncodes sind gegen die amtlichen Unterlagen des DWD geprüft: Liste der Warncodes (Stand 01.02.2024) und CAP-Profil 2.1.14, deutsch und englisch. Geprüft wurde an den Unterlagen und am Prüfstand, nicht an einer echten CAP-Meldung am Gerät.

- **Extremes Glatteis (86) fehlte.** Die Ansage sagte nur „Wetterwarnung“. Jetzt mit Klasse Glätte und eigenem Namen.
- **Vorabinformationen wecken nicht mehr.** Der DWD sendet sie ab Stufe 3; bisher griff darum die Regel für Warnungen und weckte nachts. Jetzt gilt immer: tags einmal, nachts still. Eine Warnung gleicher Klasse wird dadurch nicht verdeckt und bleibt nicht still.
- **Vorabinformationen klingen nicht mehr wie Warnungen.** Sie tragen eigene Namen, auch wenn die Nummer dieselbe ist, und werden auf Englisch zusätzlich an `urgency=Future` erkannt.
- **Namen wie in der Liste:** 76 Extrem starke Schneeverwehung, 87 Markante Glätte (bisher „Glatteis“). Alle englischen Namen stammen jetzt aus dem englischen Profil.
- **Nummern:** 24, 77 und 78 sind entfernt, sie sind nicht amtlich. 11 bis 16 (Küste und Hochsee) sind neu, Klasse Wind, sie wecken nicht.
- **Unbekannte Nummern:** Es zählen alle Gruppen einer Meldung, die erste zuordenbare gilt; neu sind SLIPPERINESS, BLACK_ICE, RIME_ICE, SNOWDRIFT, HAIL und TORNADO, gestrichen ICE und GLAZE, die im Profil nicht vorkommen. Gruppe TEST und POWERLINEVIBRATION lösen nichts aus. Auf Stufe 3 weckt eine unbekannte Nummer jetzt wie die Tabelle oben. Ein Code, der keine Zahl ist, führt nicht mehr zu einer falschen Nummer.
- **Warnzähler:** Er zählt je Klasse die höchste Stufe und schlägt bei zwei Lagen einer Klasse nicht mehr in jedem Lauf an.

**Nicht geändert, im Profil widersprüchlich:** 83 steht nur in den Gruppen, 94 nur in der Parametertabelle, beide nicht in der Liste; sie laufen über den Rückfall. 247 heißt in der Liste HITZE und im Profil STARKE HITZE; es bleibt bei „Starke Hitze“.

## Fassungen

### 0.9.0

Erste Fassung mit allen Stufen: Open-Meteo, MOSMIX, MET Norway, Mischung und Bilanz; DWD-CAP mit Rückfall Bright Sky; Pollen, Luft, UV; Radar; Station über Weiche, direkt oder Empfang; eigene gefühlte Temperatur, Schwellen, Signale, Ansagen; Reiter Einstellungen, Werte, Quellen, Warnungen, MQTT, Einbindung in Loxone, Test, Logdateien.

**Nicht enthalten oder nicht belegt:**

- NINA ist ab Werk aus, bis die Erlaubnis geklärt ist. Die Unwetterzentrale ist nicht eingebaut.
- UBA-Luftdaten, DWD-Biowetter und DWD-UV-Datei sind nicht gebaut (Lizenz offen oder Nutzen gering; UV kommt aus den Prognosen).
- Die Seite im Musterprojekt mit Bild fehlt; die Tabelle steht im Reiter „Einbindung in Loxone“.
- Dashboard und Bewässerung lesen den Kachelwert oder die Prognose noch nicht: Das sind andere Plugins.
- Empfang der Station und die fünf Dienste mit Schlüssel sind gegen keine echte Station und keine echte Antwort gemessen.
