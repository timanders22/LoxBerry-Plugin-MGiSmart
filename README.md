# LoxBerry-Plugin: MG iSmart

Bringt die Daten eines **MG-Elektrofahrzeugs** (iSMART / SAIC) nach Loxone —
Ladestand, Reichweite, Ladeleistung, Türen, Klima, Standort — und schickt
Befehle zurück: Laden stoppen, Ziel-Ladestand, Ladestrombegrenzung, Standklima,
„Auto finden".

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Was 1.0.3 behebt

Sechs Befunde. Jeder vor der Korrektur nachgemessen — auch die, die sich dabei
als etwas anderes herausgestellt haben als gemeldet.

**1. Das Broker-Passwort stand in der Prozessliste.**
`mosquitto_sub` und `mosquitto_pub` bekamen es als `-P <passwort>` auf der
Kommandozeile. `/proc/<pid>/cmdline` hat die Rechte **444** — jeder lokale
Benutzer liest dort mit. Und das ist kein Augenblick: Der minütliche Cron lässt
`mosquitto_sub -W 3` laufen, das Passwort steht also dauerhaft rund **5 % der
Zeit** offen im System.
*Jetzt*: Benutzer und Passwort stehen in der Optionsdatei, die
`mosquitto_sub`/`_pub` laut ihrer Anleitung aus `$XDG_CONFIG_HOME` lesen —
Rechte 0600 im Ordner 0700. Auf der Kommandozeile steht nur noch der Pfad.

**2. Keine Befehlseinschleusung — aber `escapeshellarg()` verstümmelt.**
Als „RCE / Command Injection" gemeldet. In **zehn** Versuchen mit `;`, `$( )`,
Backticks, Zeilenumbruch, einfachen Anführungszeichen und ungültigem UTF-8,
gegen PHP 7.4 **und** 8.1 und zwei Locales, wurde **nichts** ausgeführt.
`escapeshellarg` verwirft solche Bytes, statt sie durchzulassen — das
Anführungszeichen lässt sich damit nicht schließen.

Die Sorge dahinter war aber berechtigt, nur mit anderer Folge. Gemessen:

| Eingabe | Bytes im Argument |
|---|---|
| `ff fe` | **0** (jede Locale, beide PHP-Fassungen) |
| `c3 28` | 1 von 2 |
| „ü" (`c3 bc`) unter **PHP 7.4** mit `LC_ALL=C` | **0** von 2 |

Der letzte Fall ist der böse: Apache läuft meist unter einer UTF-8-Locale, der
Cron unter `C`. Ein Passwort mit Umlaut hätte im Reiter *Test* funktioniert und
wäre im minütlichen Lauf still gescheitert — unter PHP 7.4, also auf jedem
heutigen LoxBerry. Dazu: ein NULL-Byte im Passwort ließ `escapeshellarg`
abbrechen (PHP 8: ungefangene `ValueError`), ein sehr langes Passwort sprengte
die Argumentliste (`exec(): Unable to fork`).
Alle vier Folgen verschwinden mit derselben Korrektur wie Befund 1.

**3. Nicht atomar geschriebene Zwischenspeicher.**
`file_put_contents()` kürzt die Datei zuerst auf null. Ein Testlauf mit
gleichzeitigem Lesen und Schreiben über sechs Sekunden:

| | halbe Lesevorgänge | **leere** |
|---|---|---|
| unmittelbar | 5.490 | **818.249** |
| atomar | 0 | 0 |

Die leeren sind der häufigere Fall und der unangenehmere: `mg_raw()` liefert
dann ein leeres Feld, `mg_state()` daraufhin `SOC=-1` und `OK=0` — in Loxone
steht kurz „Auto nicht erreichbar", ohne dass etwas war. (Der Test maximiert
die Überlappung bewusst; im Betrieb schreibt der Cron einmal je Minute, das
Fenster ist also klein — vorhanden ist es trotzdem, und es kostet nichts, es
zu schließen.)

**4. `cron.php` lag im unangemeldeten Webordner** (eigener Fund).
`cron/cron.01min` rief `REPLACELBPHTMLDIR/cron.php` auf — das Skript war damit
über `http://loxberry/plugins/mgismart/cron.php` für jeden erreichbar, der die
Oberfläche im Netz sieht, und jeder Aufruf band drei Sekunden lang einen
PHP-Arbeiter. Ein unbeabsichtigter Endpunkt, den niemand brauchte: Der Cron
ruft über das Dateisystem auf. Liegt jetzt unter `bin/`.

**5. `?refresh=1` und `?ptest=1` waren ohne Merkwort erreichbar** (eigener Fund).
Der Kommentar begründete das damit, lesende Abrufe kosteten nichts. Für
`?status` stimmt das. `?refresh=1` startet aber `mosquitto_sub -W 4` und hält
einen PHP-Arbeiter **vier Sekunden** fest — im Sekundentakt aufgerufen legt das
die Weboberfläche lahm, ohne ein einziges Zugangswort. Und `?ptest=1` löst eine
Push-Nachricht aus; das ist kein Lesen, das ist ein Klingeln am Telefon des
Besitzers. Beide brauchen jetzt dasselbe Merkwort wie `?cmd=`.

**6. Zwei Fehler in `postinstall.sh`.**
Die Schlussmeldung bat darum, „das Bundesland zu wählen" — ein Überbleibsel aus
*Ferien & Feiertage*. Schwerer: Die Datei hieß dort `mgismart.json`, gelesen
wird aber `mg.json`. Das Wiederherstellen aus der Sicherung legte also eine
Datei an, die niemand liest, und meldete trotzdem Erfolg. Dass die Einstellungen
nach einer Neuinstallation dennoch da waren, lag allein an `mg_config()`, das
die Sicherung beim ersten Lesen selbst zurückholt. Beides behoben, die verwaiste
Datei wird beim Aktualisieren aufgeräumt.

**Erweiterung: `LBSystem::pluginversion()`.**
Vorgeschlagen mit der Begründung, das Plugin baue eigenes Parsing oder
hardcodiere die Version. Zutreffend war das hier nicht — die Oberfläche zeigte
**gar keine** Version (null Fundstellen). Die Anregung ist trotzdem gut und
umgesetzt: Die Fassung steht jetzt neben dem Titel und kommt aus der
`plugindatabase.json`, also aus dem, was LoxBerry tatsächlich installiert hat.
Rückfallebene ist die `plugin.cfg` — und die wird **zeilenweise** gelesen, nicht
mit `parse_ini_file()`: LoxBerry schreibt `#`-Kommentare, PHP erkennt seit 7.0
nur `;`, und das Ausrufezeichen in der zweiten Zeile jeder `plugin.cfg`
(`# NEVER CHANGE this information … updates!`) lässt `parse_ini_file` für die
**ganze** Datei scheitern.

**Hausstandard.** Die Reiter waren `<div>` ohne Verweis, und `sm-active` vergab
allein das JavaScript — ohne JavaScript war die Seite leer und die Reiter nicht
einmal anklickbar. Jetzt echte Verweise mit serverseitigem `sm-active`, alle
fünf über `?form=…` geprüft. Dazu: Klassenpräfix auf `sm-` vereinheitlicht,
`uninstall` und `prerelease.cfg` ergänzt (`PRERELEASECFG` war leer bei
eingeschaltetem Auto-Update), eine PHP-8-Warnung aus `$_SERVER['HTTP_HOST']`
beseitigt, vier tote Sprachschlüssel entfernt — 250 Schlüssel, deutsch und
englisch deckungsgleich. Beide PHP-Fassungen liefern zeichengleiche Ausgabe
ohne eine einzige Meldung.

## Wie es funktioniert

MG/SAIC bietet keine offene Schnittstelle an; die App spricht über ein
verschlüsseltes, tokenbasiertes Protokoll mit den Servern. Das quelloffene
Projekt [SAIC MQTT Gateway](https://github.com/SAIC-iSmart-API/saic-python-mqtt-gateway)
bildet dieses Protokoll nach und veröffentlicht die Fahrzeugdaten per MQTT.

Dieses Plugin setzt darauf auf:

```
Fahrzeug ─ iSMART-Server ─ SAIC-MQTT-Gateway (Docker) ─ MQTT-Broker ─ dieses Plugin ─ Loxone
```

Eine Neuimplementierung der SAIC-API in PHP wäre aufwendig und würde bei jeder
Änderung der Gegenstelle brechen. Der Reiter **Gateway einrichten** enthält den
kompletten `docker run`-Befehl und die Stolperfallen.

## Funktionen

- **Loxone-Zeile** mit Ladestand (% und kWh), Ziel-SoC, Reichweite, Ladestatus,
  Stecker, Ladeleistung, Restladezeit, Kilometerstand, 12-V-Spannung,
  Verriegelung, Innen-/Außentemperatur
- **Steuerbefehle** aus einer festen, geprüften Liste — nichts anderes nimmt der
  Endpunkt an: Laden stoppen/starten, Ziel-SoC 60–100 %, Ladestrom 6/8/16 A/MAX,
  Standklima, Heckscheibenheizung, Auto finden, Status jetzt abfragen
- **Meldungen** für Loxone: Ziel-Ladestand erreicht, Kabel ein-/ausgesteckt,
  Auto steht unverschlossen — als `PUSHAKTIV=1` für ein einstellbares Fenster
- **Robuste Zuordnung**: Das Plugin sucht jeden Wert über mehrere mögliche
  Themennamen. Liefert ein Modell etwas nicht, kommt `-1` statt eines Fehlers
- **Debug-Ansicht** mit allen empfangenen MQTT-Themen — dort steht auch die
  Fahrzeug-ID
- Reiter: Einstellungen, Gateway einrichten, Einbindung in Loxone (inkl.
  kompletter Baustein-Liste), Test, Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/mgismart/mg.php` | Loxone-Zeile `MG;OK=..;SOC=..;ZIEL=..;LAEDT=..;…` |
| `/plugins/mgismart/mg.php?cmd=ziel_80` | Befehl ans Fahrzeug |
| `/plugins/mgismart/mg.php?json=1` | Zustand als JSON |
| `/plugins/mgismart/mg.php?debug=1` | alle empfangenen MQTT-Themen |
| `/plugins/mgismart/mg.php?refresh=1` | Werte sofort neu einlesen |
| `/plugins/mgismart/mg.php?ptest=1` | Test-Pushnachricht auslösen |

## Wichtige Hinweise

- **12-Volt-Batterie:** Das Abfragen weckt die Fahrzeugelektronik. Das
  Ruheintervall des Gateways (Standard: einmal täglich) sollte man nicht
  verkürzen. Für frische Werte gezielt `?cmd=auffrischen` verwenden.
- **Nur eine Sitzung:** Meldet sich die iSMART-App an, pausiert das Gateway rund
  15 Minuten.
- **„Laden starten" ist unzuverlässig**, „Laden stoppen" funktioniert gut. Wer
  über die Wallbox schaltet, ist auf der sicheren Seite; die
  Ladestrombegrenzung des Autos wirkt dagegen zuverlässig.
- Die Schnittstelle ist **inoffiziell** und kann sich jederzeit ändern.

## Voraussetzungen

- LoxBerry-Plugin **Docker** (für den Gateway-Container)
- Paket **mosquitto-clients** (wird bei der Installation mitinstalliert)
- iSMART-Konto mit registriertem Fahrzeug

## Datenschutz

Das **iSMART-Passwort kennt nur der Gateway-Container** — dieses Plugin braucht
es nicht. Broker-Zugangsdaten liegen in `config/plugins/mgismart/mg.json`, die
Datei wird auf `chmod 600` gesetzt. Passwörter werden im Protokoll maskiert.
Im Plugin sind **keine persönlichen Daten** enthalten.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
