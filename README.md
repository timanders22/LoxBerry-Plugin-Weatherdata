# LoxBerry-Plugin: Weatherdata

Liefert Wetterwerte, Prognosen, amtliche Warnungen, Handlungssignale und fertige Sätze an Loxone, über das MQTT-Gateway V2. Es braucht weder eine eigene Wetterstation noch einen Schlüssel. Kompatibel mit LoxBerry 3.x und 4.x (reines PHP, 7.4 bis 8.5).

**Zusatzinformation, kein Warnsystem:** Fehlt eine Ansage, heißt das nicht, dass keine Gefahr besteht. NINA und amtliche Warnkanäle ersetzt dieses Plugin nicht.

## Was das Plugin kann

- **Prognose gemischt** aus Open-Meteo, DWD MOSMIX (über Bright Sky) und MET Norway, wahlweise wttr.in: Tiefst- und Höchstwerte, Regen, Schnee, Böen, Strahlung für 1, 3, 4, 12, 24 und 48 Stunden, Tageswerte für heute und zwei Folgetage. Regeln: Temperatur gewichtet, Tiefstwert der kleinste, Höchstwert und Böe der größte, Regen der Median (dazu `regen_max`), Wettercodes nie gemittelt (führend Open-Meteo). Fällt eine Quelle aus, ändert sich die Gewichtung über 30 Minuten gleitend.
- **Fehlerbilanz:** je Quelle und Frist (0 bis 6, 6 bis 24, 24 bis 72 Stunden) gegen die eigene Station, 30 Tage. Gewicht 1 durch Fehler im Quadrat, unter 14 Tagen gleich; Bias-Abzug nur als Haken, ab Werk aus. Ohne Station gibt es keine Bilanz, dann gilt gleiches Gewicht.
- **Umwelt:** DWD-Pollenflug je Teilregion (Hasel, Erle, Esche, Birke, Gräser, Roggen, Beifuß, Ambrosia), Luftindex, Feinstaub, Ozon und NO2 aus CAMS über Open-Meteo (Modellwert, keine Messung), UV. Signal `lueftung_sperren` nur für angehakte Pollenarten.
- **Radar** über Bright Sky: `radar/regen_in_min`, verdichtet auf den Standort.
- **Fassade:** höchste Strahlung auf senkrechter Fläche je Ausrichtung (ab Werk aus).
- **PV-Prognose ohne Konto** (ab 0.9.10, ab Werk aus): erwarteter Ertrag der eigenen Solaranlage heute und morgen aus der Strahlungsprognose von Open-Meteo, für bis zu vier Dachflächen; für andere Plugins unter `haus/pv/…`.
- **Station auf drei Wegen:** über die Ecowitt-Weiche, direkt (`get_livedata_info`, zwei Adressen) oder per Empfang (Ecowitt- und Wunderground-Protokoll an `wd_empfang.php` mit Token).
- **Wetterseite für die Loxone-App** (Reiter Wetterseite): aktuelle Werte, die nächsten 48 Stunden, bis zu 7 Tage, Warnungen, Pollen, Luft und Regenradar, fünf Farbdesigns, vier Symbolsets, hell, dunkel oder wie das Gerät, Deutsch und Englisch. Dazu der Kachelwert `wd.php?kachel=1` als JSON.
- **Ab Werk aus, abschaltbar und im Reiter Quellen benannt:** NINA (laut Hinweis in den Meldungen nur inhaltlich unverändert und nicht gewerblich; eine Lizenz nennt die Schnittstelle nicht; nur Nicht-Wetter-Meldungen, Wortlaut unverändert), Meteoalarm (Lizenz wie CC BY 4.0 mit Zusatzregeln, die das Plugin erfüllt; nur für Nutzer außerhalb Deutschlands), OpenWeather, WeatherAPI, Visual Crossing, Tomorrow.io, Meteoblue (je eigener Schlüssel; die Adapter sind nach der Dienstdoku gebaut und ohne Schlüssel nie gegen eine echte Antwort gefahren). Wer einschaltet, klärt die Bedingungen selbst.
- **Emulator des Loxone-Wetterdienstes,** ab Werk aus, nicht von Loxone, eigener Reiter, siehe eigener Abschnitt.
- **Warnungen** vom Deutschen Wetterdienst, je Warnzelle (DWD-CAP). Fällt der DWD aus, springt Bright Sky ein. Warnstufe 0 bis 4 ist die amtliche Schwere. Keine Warnung (0) und nichts bekannt (Strich) sind verschieden.
- **Ist-Werte**, wahlweise von der eigenen Station über die Ecowitt-Weiche. Fehlt die Station oder ist sie nicht plausibel, gilt der Prognosewert für jetzt, gekennzeichnet mit `jetzt/ist_ersatz`.
- **Gefühlte Temperatur** immer aus derselben Formel, selbst gerechnet: Windchill und Hitzeindex nach den Formeln des US National Weather Service, sonst Lufttemperatur. Das ist **nicht** die Gefühlte Temperatur des DWD.
- **Hitzebelastung draußen (WBGT)** als Schätzung aus den vorhandenen Werten, ab 0.9.14, ab Werk aus; siehe „Neu in 0.9.14“.
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

Für andere Plugins (ab 0.9.8, ab Werk aus): eine kleine Wetterübersicht unter `haus/wetter/…`, siehe „Neu in 0.9.8“. Sie braucht in Loxone nichts.

Ebenso (ab 0.9.10, ab Werk aus): die PV-Prognose unter `haus/pv/…`, siehe „Neu in 0.9.10“.

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
- WBGT-Schätzung (ab 0.9.14): Verfahren nach Liljegren, Carhart, Lawday, Tschopp und Sharp, „Modeling the Wet Bulb Globe Temperature Using Standard Meteorological Measurements“, J. Occup. Environ. Hyg. 5(10), 645–655 (2008); Formeln und Konstanten selbst geschrieben nach deren Referenzumsetzung `wbgt.c` (Copyright 2008 UChicago Argonne, LLC), keine Zeile übernommen. Sonnenstand nach den „General Solar Position Calculations“ der NOAA, Abstand Erde–Sonne nach Spencer (1971), Stufen nach TB MED 507 (2022), Tabelle 3-2.
- **Wettersymbole der Wetterseite** (`webfrontend/html/symbole/<set>/`, je Ordner `LICENSE.txt` und `QUELLE.txt`; Kommentare und unbenutzte Kennungen entfernt, Kennungen mit Präfix, sonst unverändert):
  - [Meteocons](https://github.com/basmilius/meteocons) von Bas Milius, npm `@meteocons/svg-static` 0.1.0, Stil `fill`, MIT. 21 Bilder, etwa 80 KB.
  - [Phosphor Icons](https://github.com/phosphor-icons/core), npm `@phosphor-icons/core` 2.1.1, Stil `duotone`, MIT. 21 Bilder, etwa 14 KB.
  - [Bootstrap Icons](https://github.com/twbs/icons) 1.13.2, MIT. 21 Bilder, etwa 15 KB.
  - „Klar“: selbst gezeichnet für dieses Plugin, MIT wie das Plugin. 21 Bilder, etwa 9 KB.
  - Keine Symbole aus Weather4Lox. Die Farbdesigns sind eigene Farbsätze.
- Die Ecowitt-Weiche ist ein eigenes Plugin und bleibt getrennt installiert.

Die Unwetterzentrale ist wegen ihrer Bedingungen bewusst nicht eingebaut. **WetterOnline ist ebenfalls nicht eingebaut, anders als bei Weather4Lox:** Es gibt keine öffentlich dokumentierte Schnittstelle; Weather4Lox nutzt Adressen, die für die WetterOnline-App gedacht sind. Die AGB (Fassung vom 24.02.2025, Ziffer 2) untersagen die unberechtigte Vervielfältigung und Speicherung der Inhalte und verweisen auf eine Anfrage vorab. Das Antwortformat des Emulators ist am öffentlichen Quelltext von Weather4Lox (Apache-2.0, Michael Schlenstedt, Jan Wachsmuth) abgelesen; es ist keine Zeile daraus übernommen. „Loxone“ ist eine Marke ihres Inhabers. Lizenz dieses Plugins: MIT.

## Neu in 0.9.18

- **Testansage mit übernommenem Sprechtoken:** Ist das Sprechtoken für Alexa-NG oder Chromecast 4 Lox NG hier leer, holt die echte Ansage es aus dem Nachbar-Plugin, die Testansage im Reiter *Test* tat das nicht. Echo- und Google-Lautsprecher blieben dort stumm mit „kein Token“, obwohl die Ansage sie erreicht hätte. Jetzt spricht die Testansage mit demselben Block wie die echte Ansage; gespeichert wird dabei nichts. Derselbe Fehler ist an AWM-Abfuhr am LoxBerry gemessen (13.11.2026); hier am Code festgestellt, am Gerät nicht gemessen.

## Neu in 0.9.17

- **Die Sicherung trägt jetzt die Schlüssel der Dienste** (Entscheidung des Hausherrn vom 12.11.2026): „Einstellungen sichern“ schreibt neben dem Aktionstoken die API-Schlüssel für Open-Meteo, OpenWeather, WeatherAPI, Visual Crossing, Tomorrow.io, Meteoblue und Wunderground mit in die Datei. Von 0.9.6 bis 0.9.16 blieben sie draußen; nach einem Umzug auf einen zweiten LoxBerry mussten sie neu eingetragen werden. Die Datei ist damit wie ein Kennwort zu behandeln. Sprechtoken für andere Plugins stehen weiterhin nicht darin.
- **Zurückspielen:** Trägt eine Datei Schlüssel der Dienste, muss sie alle tragen, die es zu ihrer Fassung gab; fehlt einer, wird sie als Ganzes abgewiesen und nichts geändert. Jeder Schlüssel wird mit derselben Regel geprüft wie im Formular (bis 128 Zeichen aus Buchstaben, Ziffern, `_` und `-`). Die Meldung nennt Namen, nie Werte.
- **Ein leerer Schlüssel überschreibt keinen bestehenden** (Entscheidung des Hausherrn vom 12.11.2026): Trägt die Sicherung einen Schlüssel leer, heißt das „nicht gesichert“. Ein hier eingetragener Schlüssel bleibt dann stehen, und der Hinweis nach dem Zurückspielen nennt ihn mit Namen, etwa „mb_key blieb, die Sicherung trug ihn leer“; den Wert nennt er nie. Ist er hier ebenfalls leer, bleibt er leer, ohne Hinweis. Geleert wird ein Schlüssel nur im Formular (Haken „Schlüssel löschen“).
- **Ältere Sicherungen:** Eine Datei von 0.9.6 bis 0.9.16 bleibt zurückspielbar; die hier eingetragenen Schlüssel bleiben dann, und der Hinweis nach dem Zurückspielen nennt ihre Namen. Eine Datei bis 0.9.5 trägt die sechs Schlüssel von damals; der Schlüssel für Wunderground, den es damals noch nicht gab, bleibt dann, wie er hier eingetragen ist.
- Wie bisher arbeitet der Minutentakt ab seinem nächsten Lauf mit den zurückgespielten Einstellungen; einen eigenen Dienst gibt es nicht. Das Aktionstoken wird beim Zurückspielen nicht neu erzeugt.
- Gemessen mit einem Prüfstand unter PHP 7.4 und 8.4 auf dem Arbeitsrechner (gültige, halb gültige, fremde und ältere Sicherung); am Gerät nicht gemessen.

## Neu in 0.9.16

- **Konfiguration wird vervollständigt:** Fehlen in `weatherdata.json` Einstellungen, die eine neuere Fassung mitbringt, trägt der Minutenlauf (`bin/wd_takt.php`) sie jetzt einmal mit ihrer Vorgabe in die Datei ein; die Zweitschrift zieht mit. Das Protokoll nennt die ergänzten Namen in einer Zeile, nie die Werte. Bisher ergänzte das Plugin fehlende Einstellungen nur beim Lesen; in die Datei kamen sie erst beim nächsten Speichern im Formular. Die Vorgaben sind die Werte, die das Plugin bisher ohnehin angenommen hat; am Verhalten ändert sich nichts. Ein nie gespeicherter Block der Sprachausgabe wird als leerer Block eingetragen und gilt damit weiter als „nie gesetzt“, also ab Werk an.
- Geschrieben werden die Werte, die in der Datei stehen, plus die fehlenden Vorgaben. Unbekannte Schlüssel bleiben stehen, geschrieben wird nur, wenn etwas fehlt. Eine fehlende, leere oder beschädigte Datei und eine Datei ohne Aktionstoken werden nicht angefasst, ebenso wenig während einer Aktualisierung; das bleibt Sache der Selbstheilung. Gemessen ist das im Prüfstand unter PHP 7.4 und 8.4 auf dem Arbeitsrechner, an einer Datei in der Gestalt der Fassung 0.9.12, nicht am Gerät.
- Leere Objekte (`{}`) in der Datei bleiben beim Vervollständigen erhalten, auch verschachtelte und solche unter unbekannten Schlüsseln; sie werden dabei nicht zu leeren Listen (`[]`).

## Neu in 0.9.15

- Gemeinsames Sprachmodul 1.4.3: Als LoxBerry-Wurzel aus `LBHOMEDIR` gilt für die Sprachausgabe nur noch ein Ordner mit `config/plugins`, `data/plugins` und `config/system/general.json`; bis 1.4.2 genügte `config/system`, und ein Prüfstand oder Rest mit diesem Ordner wurde wie ein LoxBerry gelesen. Auf einem LoxBerry ändert sich nichts.

## Neu in 0.9.14

- **Hitzebelastung draußen als Schätzung (WBGT), ab Werk aus.** Die Wet Bulb Globe Temperature fasst Lufttemperatur, Luftfeuchte, Wind und Sonne zu einer Zahl zusammen, nach der sich Arbeit und Sport im Freien richten. Gemessen wird sie mit einer schwarzen Kugel und einem feuchten Thermometer, etwa mit dem Ecowitt WN38. Weatherdata misst sie nicht, sondern rechnet sie aus den Werten, die es ohnehin hat: Temperatur, Feuchte, Wind und Strahlung aus der Tabelle „Jetzt“, dazu Luftdruck und Sonnenstand. Das Rechenverfahren stammt aus einer Veröffentlichung von Liljegren und anderen (2008); es schätzt auch die Temperatur der Kugel und des feuchten Thermometers.
- Einschalten im Reiter Einstellungen, Abschnitt „Hitzebelastung draußen“. Dort steht auch die Höhe des Windmessers (Vorgabe 10 m, so liefert Open-Meteo den Wind), denn das Verfahren braucht den Wind in 2 m Höhe.
- Gerechnet wird nur, wenn Temperatur, Feuchte, Wind und Strahlung vorliegen. Sonst steht ein Strich, und `jetzt/wbgt_ok` ist 0. Fehlt nur der Luftdruck, gilt 1013 hPa; der Reiter Werte sagt das dann.
- Neue MQTT-Themen, nur mit dem Haken: `jetzt/wbgt_ok`, `jetzt/wbgt_c`, `jetzt/wbgt_stufe` (0 bis 5), `jetzt/wbgt_wort` (Text), `jetzt/globe_c` (Kugel) und `jetzt/feuchtkugel_c` (natürliches Feuchtthermometer). Es sind Messwerte, keins bleibt im Broker liegen. Die Namen für Loxone stehen im Reiter „Einbindung in Loxone“, Abschnitt WBGT-Schätzung.
- Stufen nach der Tabelle 3-2 des US-Heeres (TB MED 507, 2022): Stufe 1 (weiß) ab 78 °F, 2 (grün) ab 82 °F, 3 (gelb) ab 85 °F, 4 (rot) ab 88 °F, 5 (schwarz) ab 90 °F; darunter Stufe 0. In Celsius mit einer Nachkommastelle: ab 25,6, 27,8, 29,5, 31,2 und 32,3 °C. Die Tabelle gilt dort für an Hitze gewöhnte Soldaten in Uniform; für Sport nennt dieselbe Schrift andere Bereiche.
- Grenzen: Eine Schätzung, keine Messung und keine amtliche Warnung. Sie gilt für den Ort der Station oder das Gebiet der Vorhersage. Ein beschatteter oder verschmutzter Strahlungsfühler macht sie zu niedrig. Gegen eine echte Kugel ist sie an keiner Anlage gemessen, und mit veröffentlichten Zahlenbeispielen des Verfahrens ließ sie sich nicht vergleichen, weil keine zu finden waren. Der Reiter Test prüft die Rechnung in sieben Zeilen.

## Neu in 0.9.13

- PV-Prognose: Hilfetext und Hinweis im Reiter Test sagen jetzt deutlich, dass das Einschalten nicht schadet. PV Solcast NG sendet nicht unter haus/pv, sondern unter seinem eigenen Thema; beide Prognosen können nebeneinander laufen. Bisher stand dort „braucht das hier nicht unbedingt“, und das ließ offen, ob es schadet.
- Reiter Werte, Tabelle „Jetzt“: Die Spaltenüberschrift „Wetterdienste: Mischung aus … Vorhersagen“ wird wieder vollständig gezeigt; bisher war sie nach „Wetterdienste: Mi“ abgeschnitten.
- Reiter Werte: Der Erklärtext unter der Tabelle „Jetzt“ läuft so breit wie die Tabelle; bisher war er schmaler als die Fußnote darüber.
- Wetter für andere Plugins: Neben `haus/wetter/datum` (Tag als Zahl JJJJMMTT, unverändert) gibt es jetzt `haus/wetter/datum_text` mit demselben Tag als lesbarem Text, etwa `06.11.2026`. So kann Loxone das Datum direkt anzeigen. Es bleibt wie das Datum im Broker und wird mit ihm abgeräumt; fehlt das Datum, steht dort ein Strich. Damit sind es 29 Themen, 27 davon bleiben im Broker.

## Neu in 0.9.12

- Gemeinsames Sprachmodul 1.4.2: Wird eine Ansage bewusst nicht gesprochen (gerade eben schon gesagt, gesperrt oder Ruhezeit), steht das im Protokoll jetzt so – und nicht mehr als Fehler.

## Neu in 0.9.11

Am Gerät und an einem echten Broker nicht gemessen; gegen Attrappen (Open-Meteo-Antwort, Prüf-Broker) gefahren. Keine zusätzlichen Abrufe: Schneehöhe und Diffusstrahlung kommen in derselben Anfrage mit.

- **PV-Prognose: Schnee.** Sagt die Prognose für eine Stunde mindestens 2 cm Schnee am Boden, rechnet das Plugin für Flächen flacher als 60 Grad in dieser Stunde mit 0 – so lange, bis die Prognose wieder weniger als 2 cm sagt. Das ist grob: Ob der Schnee früher von den Modulen rutscht oder taut, weiß die Prognose nicht. Neues Thema `haus/pv/schnee` (1 = in der laufenden Stunde liegt laut Prognose Schnee).
- **PV-Prognose: Schatten vor Ort.** Je Fläche optional „Schatten morgens bis“ und „Schatten abends ab“ (Uhrzeit, leer = kein Schatten). In diesen Minuten zählt statt der vollen Strahlung nur das diffuse Himmelslicht auf die schräge Fläche (Diffusstrahlung × (1 + cos Neigung) / 2). Einmal im Sommer und einmal im Winter nachsehen und ein Mittel eintragen – die Sonnenbahn wandert übers Jahr, das bildet eine feste Uhrzeit nur ungefähr ab.
- **PV-Prognose: Temperaturkoeffizient** als Einstellung (Vorgabe 0,38 %/K, erlaubt 0,20 bis 0,50; steht im Moduldatenblatt als „Temperaturkoeffizient Pmax“). Bisher fest 0,4 %/K. Die Erwärmung der Module (etwa +0,031 K je W/m²) und die Begrenzung auf 0,80 bis 1,10 bleiben.
- **Stundenreihen für andere Plugins:** `haus/pv/heute_stunden` und `haus/pv/morgen_stunden` – je 24 ganze Watt-Werte für 0 bis 23 Uhr, mit `;` getrennt (wie `haus/strompreis/vorschau`), −1 für eine fehlende Stunde, `-` ohne Prognose. Damit bleiben jetzt 15 Themen im Broker; beim Ausschalten und bei der Deinstallation werden alle 15 abgeräumt.
- **Hinweis bei zwei PV-Prognosen:** Ist auf demselben LoxBerry PV Solcast NG installiert und sendet es über MQTT, zeigen Abschnitt PV-Prognose und Reiter Test einen gelben Hinweis „Zwei PV-Prognosen? Nur eine sollte haus/pv liefern“. Weatherdata liest dafür nur die Plugin-Liste und die Einstellung von PV Solcast NG; es schaltet nichts ab.
- Nach dem Update holt das Plugin die Prognose einmal neu (die alte enthält Schnee und Diffusstrahlung noch nicht); bis dahin, höchstens einige Minuten, steht −1.

## Neu in 0.9.10

Am Gerät und an einem echten Broker nicht gemessen; gegen Attrappen (Open-Meteo-Antwort, Prüf-Broker) gefahren.

- **PV-Prognose ohne Konto** (Reiter Quellen, Abschnitt „PV-Prognose“, **ab Werk aus**): Weatherdata schätzt, wie viel Strom Ihre Solaranlage heute und morgen erzeugt. Es braucht kein Konto und keinen Schlüssel – die Strahlung auf die schräge Modulfläche kommt von Open-Meteo.
- **So geht es:** 1. Haken „PV-Prognose rechnen …“ setzen. 2. Je Dachfläche (bis zu vier, zum Beispiel Ost- und Westdach getrennt) die Leistung in kWp, die Neigung und die Ausrichtung eintragen. Die Ausrichtung misst man im Luftbild mit dem verlinkten Azimut-Werkzeug (polka-umwelt.de, „Orthogonales Messen“ an der Dachkante): 0 = Süden, −90 = Osten, 90 = Westen. 3. Speichern. Nach wenigen Minuten zeigt eine kleine Tabelle Ertrag und höchste Leistung für heute und morgen.
- **Es ist eine Schätzung.** Vergleichen Sie sie über einige Wochen mit dem, was Ihre Anlage wirklich erzeugt, und stellen Sie dann den Gesamt-Wirkungsgrad nach (Vorgabe 0,85; zu hoch → kleiner, zu tief → größer). Die Erwärmung der Module rechnet das Plugin selbst ein; mehr als der Wechselrichter abgeben kann, wird abgeschnitten, wenn dessen Höchstleistung eingetragen ist.
- **Nur ein Anbieter unter haus/pv:** Unter `haus/pv` darf nur ein Plugin im Haus senden. PV Solcast NG sendet dort nicht (eigenes Thema, Vorgabe `solcast/`), beide Prognosen können also nebeneinander laufen. Aus lassen nur, wenn schon ein anderes Plugin unter `haus/pv` sendet.
- **Für andere Plugins** (nur wenn auch „Werte über MQTT senden“ an ist): `haus/pv/jetzt_w`, `naechste_stunde_w` (W), `heute_kwh`, `heute_rest_kwh`, `morgen_kwh` (kWh), `spitze_heute_w`/`spitze_heute_zeit`, `spitze_morgen_w`/`spitze_morgen_zeit`, `kwp`, `datum`, `stand` – diese 12 bleiben im Broker; `haus/pv/ok` und das Lebenszeichen `haus/pv/zaehler/weatherdata` nie. Ist der letzte Abruf älter als 3 Stunden, steht überall −1 (Uhrzeiten `-`) und ok ist 0 – nie ein alter Wert. Haken aus, MQTT aus oder Deinstallation: Die 12 Themen werden im Broker abgeräumt. Die Liste steht im Abschnitt PV-Prognose.
- **Abrufe:** eine Anfrage je Dachfläche und Stunde (Flächen mit gleicher Neigung und Ausrichtung teilen sich eine), also höchstens 96 am Tag zusätzlich – weit unter der Grenze von Open-Meteo für die freie Nutzung (10.000 am Tag).
- **Reiter Test:** neue Zeile „haus/pv (PV-Prognose)“ mit an/aus, Flächen, letztem Abruf und Werten.

## Neu in 0.9.9

- Reiter „Einbindung in Loxone“: Die Tabellen der Musterseiten passen jetzt in die Seitenbreite. Bisher war die letzte Spalte („Eingänge verbinden mit“) nur über einen Schieberegler ganz unten an der Tabelle erreichbar; lange Adressen brechen jetzt um.
- Reiter MQTT: Die Themenliste „Wetter an andere Plugins geben“ passt ebenso in die Seitenbreite.
- Standort: Unter dem Feld „Breitengrad“ steht ein Link zu einer Karte (mapatlas.eu). Dort die Adresse eingeben und die Zahlen unter „Dezimalgrad“ kopieren. Beide Zahlen dürfen auch zusammen ins Feld Breitengrad kopiert werden („50.123, 8.456“); das Plugin teilt sie beim Speichern auf.
- Ausrichtung der Hauswände (Reiter Quellen, Feld „Ausrichtungen“): Link zum Azimut-Werkzeug von Polka Umwelt. Dort die Hauswand im Luftbild nachfahren; die angezeigte Gradzahl (Abweichung von Süden) gilt hier unverändert.

## Neu in 0.9.8

Am Gerät und an einem echten Broker nicht gemessen; gegen einen Prüf-Broker (MQTT 3.1.1 mit Retain-Speicher) gefahren.

- **Wetter für andere Plugins** (Reiter MQTT, Haken „Wetter an andere Plugins geben“, **ab Werk aus**): Weatherdata legt eine kleine Wetterübersicht unter `haus/wetter/…` im MQTT-Broker ab, damit andere Plugins sie lesen können – zuerst die Sprachsteuerung (ab 0.12.13) für Fragen wie „Wie warm ist es draußen?“, „Brauche ich einen Schirm?“ oder „Gibt es eine Wetterwarnung?“. Inhalt: Temperatur und gefühlt jetzt, Feuchte, Wind, Böen, Regen jetzt; für heute (ab jetzt) und morgen Tiefst- und Höchstwert, Regen in mm, Regenwahrscheinlichkeit, Wettercode und Wetter in Worten; amtliche Warnstufe mit kurzem Namen; stärkste Pollenbelastung heute. Nie der Standort, nie die Warnzelle.
- **So geht es:** 1. Reiter MQTT, „Wetter an andere Plugins geben“ anhaken, speichern. 2. In der Sprachsteuerung Reiter MQTT „Wetterfragen beantworten“ anhaken, speichern. 3. Nach höchstens zwei Minuten antwortet sie. In Loxone ist nichts einzurichten. Der Reiter Test zeigt in der Zeile „Wetter für andere Plugins“, ob die Übersicht ankommt.
- **Technik:** 28 Themen, 26 davon bleiben im Broker (retained), `haus/wetter/ok` und das Lebenszeichen `haus/wetter/zaehler/weatherdata` nie. Gesendet über eine kurze Verbindung direkt zum Broker: jede Minute ok und Lebenszeichen, die Werte bei Änderung und alle 30 Minuten vollständig. Ohne Wert: Temperaturen −999, andere Zahlen −1, Wörter `-`. Haken aus, „Werte über MQTT senden“ aus oder Deinstallation: Die 26 Themen werden im Broker abgeräumt und zurückgelesen. Die Liste steht im Reiter MQTT.
- Die bisherigen Themen `weatherdata/…` sind unverändert.
- **Reiter Werte:** Tabelle „Jetzt“ mit festen Spalten, Zahlen rechtsbündig mit Einheit. Ohne eigene Messung kommt jeder Wert einzeln aus Open-Meteo, sonst aus dem Mischwert der laufenden Stunde (bis 0.9.7 blieb ein fehlender Open-Meteo-Wert leer). Verglichen wird jetzt mit dem Mischwert aller antwortenden Vorhersagen; Taupunkt, Luftdruck und Windrichtung von Open-Meteo. Der Luftdruck wird auf Meereshöhe verglichen (`pressure_msl`) und ab 3 hPa markiert; die Windrichtung als kürzester Winkel, bei Windstille ohne Abweichung. Die Hilfe erklärt, warum ein Piezo-Regenmesser „regnet“ bei 0,0 mm/h melden kann. MQTT unverändert.
- **Reiter Quellen:** Weitere Dienste je Dienst in einem Kasten (NINA, Meteoalarm, Dienste mit Schlüssel, Wunderground), die Gemeindesuche direkt beim Nummernfeld, „Regionen mit aktueller Warnung zeigen“ direkt beim Regionsfeld; Suchen speichert nichts, die Eingaben bleiben stehen.
- **Reiter MQTT:** Die Themenliste passt in die Seitenbreite (lange Namen brechen um); breite Tabellen zeigen am Handy „zur Seite wischen“. Pflicht sind jetzt genau die 25 Themen, die die Musterseiten verdrahten (bis 0.9.7 zusätzlich 25 weitere, etwa die Quellen-ok-Themen); die Prüfzeile nennt nur, was fehlt, und sagt, wie man einen Haken setzt.
- **Reiter Test:** Prüfzeilen in Alltagssprache und metrischen Einheiten („Gefühlte Kälte bei −1,1 °C und 16 km/h“).
- **Reiter Einbindung mit Bildern:** Unter jeder der zwei Baustein-Tabellen steht jetzt das Bild der Musterseite („Weatherdata“, „Weatherdata Werte und Signale“), so wie sie im Musterprojekt angeordnet ist.

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
