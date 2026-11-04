# LoxBerry-Plugin: Weatherdata

Liefert Wetterwerte, Prognosen, amtliche Warnungen, Handlungssignale und fertige Sätze an Loxone, über das MQTT-Gateway V2. Es braucht weder eine eigene Wetterstation noch einen Schlüssel. Kompatibel mit LoxBerry 3.x und 4.x (reines PHP, 7.4 bis 8.5).

**Zusatzinformation, kein Warnsystem:** Fehlt eine Ansage, heißt das nicht, dass keine Gefahr besteht. NINA und amtliche Warnkanäle ersetzt dieses Plugin nicht.

## Was das Plugin kann

- **Prognose gemischt** aus Open-Meteo, DWD MOSMIX (über Bright Sky) und MET Norway: Tiefst- und Höchstwerte, Regen, Schnee, Böen, Strahlung für 1, 3, 4, 12, 24 und 48 Stunden, Tageswerte für heute und zwei Folgetage. Regeln: Temperatur gewichtet, Tiefstwert der kleinste, Höchstwert und Böe der größte, Regen der Median (dazu `regen_max`), Wettercodes nie gemittelt (führend Open-Meteo). Fällt eine Quelle aus, ändert sich die Gewichtung über 30 Minuten gleitend.
- **Fehlerbilanz:** je Quelle und Frist (0 bis 6, 6 bis 24, 24 bis 72 Stunden) gegen die eigene Station, 30 Tage. Gewicht 1 durch Fehler im Quadrat, unter 14 Tagen gleich; Bias-Abzug nur als Haken, ab Werk aus. Ohne Station gibt es keine Bilanz, dann gilt gleiches Gewicht.
- **Umwelt:** DWD-Pollenflug je Teilregion (Hasel, Erle, Esche, Birke, Gräser, Roggen, Beifuß, Ambrosia), Luftindex, Feinstaub, Ozon und NO2 aus CAMS über Open-Meteo (Modellwert, keine Messung), UV. Signal `lueftung_sperren` nur für angehakte Pollenarten.
- **Radar** über Bright Sky: `radar/regen_in_min`, verdichtet auf den Standort.
- **Fassade:** höchste Strahlung auf senkrechter Fläche je Ausrichtung (ab Werk aus).
- **Station auf drei Wegen:** über die Ecowitt-Weiche, direkt (`get_livedata_info`, zwei Adressen) oder per Empfang (Ecowitt- und Wunderground-Protokoll an `wd_empfang.php` mit Token).
- **Kachelwert und kleine Seite:** `wd.php?kachel=1` als JSON, `wetter.php` für den Webseiten-Baustein der App.
- **Ab Werk aus, abschaltbar und im Reiter Quellen benannt:** NINA (Nutzungsbedingungen nicht geklärt, Rückfrage beim BBK steht aus; nur Nicht-Wetter-Meldungen, Wortlaut unverändert), Meteoalarm (Bedingungen nicht gelesen; nur für Nutzer außerhalb Deutschlands), OpenWeather, WeatherAPI, Visual Crossing, Tomorrow.io, Meteoblue (je eigener Schlüssel; die Adapter sind nach der Dienstdoku gebaut und ohne Schlüssel nie gegen eine echte Antwort gefahren). Wer einschaltet, klärt die Bedingungen selbst.
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

Beides tragen Sie selbst ein. Die Warnzelle lässt sich aus dem Standort vorschlagen; übernommen wird sie erst, wenn Sie sie eintragen. Der Standort geht gerundet (ab Werk 3 Nachkommastellen) an die eingeschalteten Dienste, ab Werk Open-Meteo, Bright Sky (MOSMIX, Radar) und MET Norway; die Warnzelle an den DWD und im Rückfall an Bright Sky; der Vorschlag der Warnzelle fragt einmal Bright Sky mit dem Standort. Jeder Dienst lässt sich im Reiter Quellen abschalten. Ins Protokoll und in MQTT kommt beides nie, in eine Sicherungsdatei nur als Ihre Einstellung. Warnungen gibt es nur für Deutschland.

## MQTT

Virtuelle Eingänge heißen wie der Gateway-Name; Haken je Thema nach der Hakenliste im Reiter MQTT (Pflicht und wahlweise getrennt). Retained ist nur `fassung`. Keine Aussage ist ein Strich `-`, nie ein leerer Wert und nie der alte Wert; die Logik hängt an den Zahlenthemen `.../ok`. Wie ein Virtueller Eingang den Strich behandelt, bitte an der eigenen Anlage messen.

## Quellen und Lizenzen

Dieses Plugin enthält keinen fremden Code. Es liest:

- [Open-Meteo](https://open-meteo.com/): Wetterdaten, CC BY 4.0. Nicht gewerblich frei; Heimautomation ist in den Bedingungen genannt. Für gewerbliche Nutzung gibt es ein Feld für den eigenen Schlüssel.
- [Deutscher Wetterdienst](https://www.dwd.de/) (`opendata.dwd.de`): Warnungen, CC BY 4.0, ohne Gewähr und ohne Verfügbarkeitszusage. Der Wortlaut amtlicher Warnungen geht unverändert in `warnung/volltext`, gekürzt auf 900 Zeichen.
- [Bright Sky](https://brightsky.dev/): aufbereitete DWD-Daten, Einzelbetreiber, keine Zusage.
- [MET Norway](https://www.met.no/): Locationforecast, CC BY 4.0, Werte verändert (gemischt). Wahrer `User-Agent`, höchstens 4 Nachkommastellen, `Expires` wird beachtet.
- CAMS (Copernicus Atmosphere Monitoring Service) über Open-Meteo: Luftqualität.
- DWD Pollenflug-Gefahrenindex `s31fg.json`: CC BY 4.0.
- Formeln: US National Weather Service (Windchill, Hitzeindex).
- Die Ecowitt-Weiche ist ein eigenes Plugin und bleibt getrennt installiert.

Die Unwetterzentrale ist wegen ihrer Bedingungen bewusst nicht eingebaut. Lizenz dieses Plugins: MIT.

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
