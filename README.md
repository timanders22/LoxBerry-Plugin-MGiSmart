# LoxBerry-Plugin: MG iSmart

Version 1.1.0

Bringt die Daten eines oder mehrerer **MG-Elektrofahrzeuge** (iSMART / SAIC)
nach Loxone — Ladestand, Reichweite, Ladeleistung, Türen, Fenster, Reifendruck,
Klima, Standort — und schickt Befehle zurück: Laden stoppen, Ziel-Ladestand,
Ladestrombegrenzung, Standklima, „Auto finden".

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Was 1.1.0 bringt

### Vier Werte, die bisher still falsch waren

**1. Die Restladezeit war um den Faktor 60 zu groß.**
Das Gateway veröffentlicht `drivetrain/remainingChargingTime` mit
`transform=lambda x: x * 60` — der Rohwert des Autos ist in Minuten,
veröffentlicht wird in **Sekunden**. Das Plugin reichte ihn unverändert als
`RESTZEIT` mit der Einheit „min" nach Loxone weiter. Neunzig Minuten
Restladezeit erschienen dort als `5400 min`, also dreieinhalb Tage — und
zugleich über dem eigenen `MaxVal` von 3000. Kein Wert fehlte, nichts stand
auf `–`.

**2. `ALTER` maß den Broker, nicht das Auto.**
Die Werte des Gateways liegen **retained** auf dem Broker: Sie bleiben dort
stehen, auch wenn der Container tot ist oder das Auto seit einer Woche nicht
geantwortet hat. Da der Cron jede Minute neu einliest, stand `ALTER` praktisch
immer auf 0 — und der Schwellwertschalter „Daten veraltet", den die eigene
Anleitung vorschlug, konnte nie anschlagen.
*Jetzt* gibt es drei Felder, die die Frage wirklich beantworten:
`ERREICHBAR` (aus `available`), `GATEWAY` (aus dem letzten Willen des
Containers) und `FZALTER` (Minuten seit der letzten echten Statusabfrage).
`OK` steht außerdem nur noch auf 1, wenn das Gateway das Fahrzeug auch
erreicht hat.

**3. Der Energieinhalt wurde gerechnet, obwohl das Auto ihn liefert.**
Gesucht wurde `drivetrain/socKwh`; das Gateway veröffentlicht
`drivetrain/soc_kwh`, mit Unterstrich. Der Kandidat traf also nie. Dasselbe
galt für die Kapazität: `drivetrain/totalBatteryCapacity` gibt es, das Plugin
rechnete mit dem Handeintrag. Neun weitere Kandidatennamen
(`battery/soc`, `drivetrain/targetSoc`, `chargingState`, `chargingConnected`,
`chargingPower`, `chargingTimeRemaining`, `odometer`, `batteryVoltage`,
`rangeElectric`) kommen im Gateway überhaupt nicht vor — sie täuschten
Robustheit vor und sind entfernt.

**4. Die Loxone-Vorlage konnte „nicht bekannt" nicht transportieren.**
Zehn Felder senden `-1`, wenn ein Wert fehlt, aber die Vorlage setzte
`MinVal="0"` und damit `Signed="false"`. Loxone zeigte dann **0** — bei `ZU`
heißt 0 „unverschlossen", also das Gegenteil von „unbekannt". Bei
`INNEN`/`AUSSEN` lag der Fehlwert `-99` unterhalb der eigenen Untergrenze.
Und die Oberfläche riet gleichzeitig, „auf größer 0 zu prüfen" — mit der
mitgelieferten Vorlage war genau das nicht möglich.

### Fünf Dinge, die nicht taten, was sie sollten

**5. Der Knopf „Test-Pushnachricht" lieferte seit 1.0.3 immer HTTP 403.**
Er verlinkte `mg.php?ptest=1` ohne Merkwort, während der Endpunkt es seit
jener Fassung verlangt. Neunzehn Befehlsadressen daneben trugen es.

**6. Die Oberfläche starb, wenn `LBHOMEDIR` nicht gesetzt war.**
`index.php` rief in Zeile 14 `lb_wurzel_ermitteln()` auf — 149 Zeilen vor der
Definition und bevor die Bibliothek geladen war. Der ausdrücklich vorgesehene
Rückfall endete mit `Fatal error`, unter PHP 7.4 wie unter 8.4.

**7. `preupgrade.sh` und `postupgrade.sh` sicherten `mgismart.json`** — gelesen
wird `mg.json`. Beide waren für ihren Zweck wirkungslos, das Protokoll ging
bei jedem Upgrade verloren, und `postupgrade` legte danach genau die verwaiste
Datei mit Passwort und Merkwort wieder an, die `postinstall` aufräumen sollte.
Der Aufräumblock dort war beim Upgrade ohnehin unerreichbar: Der Installer
löscht das Konfigverzeichnis eine Zeile vorher.

**8. Eine unvollständige Momentaufnahme galt als gültig.** Ein einziges
empfangenes Thema genügte, um die alte vollständig zu ersetzen; die Zeile
meldete danach `OK=1` mit fast lauter Platzhaltern. Bricht die Themenzahl
jetzt um mehr als die Hälfte ein, bleibt der alte Stand stehen.

**9. „Auto geladen" konnte sich ohne Anlass wiederholen.** Fehlte
`drivetrain/socTarget` für einen einzigen Durchgang, fiel das Ziel auf 0 und
damit `voll` auf 0 — „unbekannt" und „nicht erreicht" waren ununterscheidbar.
Beim nächsten vollständigen Durchgang stieg die Flanke ein zweites Mal.

### Was dazugekommen ist

* **Erreichbarkeit** — `ERREICHBAR`, `GATEWAY`, `FZALTER`, `FEHLER`.
* **Standort als Heimzone** — `ZUHAUSE` und `ENTFERNUNG` aus Breite, Länge und
  Radius. Die *Rohkoordinaten* gehen bewusst nicht in die Loxone-Zeile.
* **Fahrt** — `LAEUFT` (Zündung), `TEMPO`, `KMTAG`, `KMLADUNG`.
* **Öffnungen** — `TUEROFFEN` und `FENSTEROFFEN` zählen Türen, Motorhaube,
  Fenster und Schiebedach; die Namen der offenen Teile gehen über MQTT.
* **Reifendruck** — vier Felder in bar.
* **Ladetechnik** — `ACLEISTUNG` (die Wechselstromseite, die eine
  PV-Überschussregelung wirklich braucht), `ACSTROM`, `ACSPANNUNG`,
  `LADEART`, `KABELVERR`, `STROMGRENZE`, `KAPAZITAET`, `VERBRTAG`,
  `VERBRLADUNG`, `FERTIGUM`, `BATTHEIZ`.
* **Klima vollständig** — `KLIMASOLL`, `HECKSCHEIBE`, `FRONTSCHEIBE`,
  `SITZHL`, `SITZHR`, dazu die passenden Befehle.
* **Mehrere Fahrzeuge** je Konto, über `&fahrzeug=<n>`.
* **Vier Zeilen statt einer** — `mg`, `laden`, `ort`, `technik`, jede mit
  eigenem Abfragetakt. Die Zeile `mg` enthält weiterhin alles.
* **Parametrierbare Befehle** — `?cmd=ziel&prozent=80` statt fünf fester
  Einträge; die alten Namen (`ziel_80`, `strom_16`, …) bleiben gültig.
* **Zweiter Haken** für eingreifende Befehle (Licht, Hupe, Ver- und
  Entriegeln), ab Werk aus.
* **Drosselung** — Mindestabstand je Befehl, Obergrenze je Stunde, und kein
  Senden, wenn der Zielzustand schon anliegt.
* **Wirkung statt Rückgabewert** — nach dem Senden liest das Plugin das
  Zustandsthema noch einmal und unterscheidet „gewirkt" von „abgesetzt,
  Ergebnis unbekannt".
* **Eigene MQTT-Veröffentlichung** unter einem kurzen Präfix (`mg/1/soc` …) —
  MQTT ist der Regelweg.
* **Vorklimatisierung** über den Abfahrts-Assistenten und **Ladeempfehlung**
  aus einem fremden MQTT-Thema (Photovoltaik, Spotpreis).
* **Ladevorgänge** werden mitgeschrieben — Dauer, kWh, SoC, kWh/100 km.
* **Ladeplan und Batterieheizplan** — hinter einem eigenen, ab Werk
  ausgeschalteten Haken. Siehe den eigenen Abschnitt unten.
* **Vorlage der Steuerbefehle** (VirtualOut) neben der Eingangsvorlage.
* **Selbstprüfung** im Reiter Test: siebzehn Zeilen mit Haken, Kreuz oder
  Strich. Ein Strich heißt „nicht feststellbar" und ist ausdrücklich kein Haken.
* **`?selftest=1&token=…`** — das Merkwort prüfen, ohne etwas zu schalten.
* **Merkmal gegen fremde Absender** in jedem Formular, und ein Knopf für ein
  neues Merkwort.
* **`?debug=1` verlangt jetzt das Merkwort.** Dort stehen iSMART-Benutzername,
  Fahrzeug-Kennung und die Standortthemen; „lesende Abrufe verraten nichts"
  stimmte dafür nicht.

## Wie es funktioniert

MG/SAIC bietet keine offene Schnittstelle an; die App spricht über ein
verschlüsseltes, tokenbasiertes Protokoll mit den Servern. Das quelloffene
[SAIC MQTT Gateway](https://github.com/SAIC-iSmart-API/saic-python-mqtt-gateway)
bildet dieses Protokoll nach und veröffentlicht die Fahrzeugdaten per MQTT.

```
Fahrzeug ─ iSMART-Server ─ SAIC-MQTT-Gateway (Docker) ─ MQTT-Broker ─ dieses Plugin ─ Loxone
```

Der Reiter **Gateway einrichten** enthält den kompletten `docker run`-Befehl
und die Stolperfallen.

## Endpunkte

Lesend, ohne Merkwort:

| Aufruf | Zweck |
|---|---|
| `/plugins/mgismart/mg.php` | Loxone-Zeile `MG;OK=..;SOC=..;…` |
| `/plugins/mgismart/mg.php?zeile=laden` | kürzere Zeile, eigener Abfragetakt |
| `/plugins/mgismart/mg.php?zeile=ort` | Ort und Fahrt |
| `/plugins/mgismart/mg.php?zeile=technik` | Diagnose |
| `/plugins/mgismart/mg.php?fahrzeug=2` | das zweite eingerichtete Fahrzeug |
| `/plugins/mgismart/mg.php?json=1` | Zustand als JSON |

Mit Merkwort — sie tun etwas, oder sie geben mehr preis als eine Statuszeile:

| Aufruf | Zweck |
|---|---|
| `/plugins/mgismart/mg.php?cmd=ziel&prozent=80&token=T` | Befehl ans Fahrzeug |
| `/plugins/mgismart/mg.php?cmd=ziel_80&token=T` | derselbe Befehl, alter Name |
| `/plugins/mgismart/mg.php?cmd=ladeplan&von=22:00&bis=06:00&modus=until_configured_soc&token=T` | Ladefenster setzen (nicht erprobt) |
| `/plugins/mgismart/mg.php?cmd=ladeplan_ein&token=T` | eingestelltes Ladefenster einschalten |
| `/plugins/mgismart/mg.php?refresh=1&token=T` | Werte sofort neu einlesen |
| `/plugins/mgismart/mg.php?ptest=1&token=T` | Test-Pushnachricht auslösen |
| `/plugins/mgismart/mg.php?debug=1&token=T` | alle empfangenen MQTT-Themen |
| `/plugins/mgismart/mg.php?ladungen=1&token=T` | die Ladevorgänge als JSON |
| `/plugins/mgismart/mg.php?selftest=1&token=T` | nur das Merkwort prüfen |

Das Merkwort steht im Reiter *Einbindung in Loxone*; die dort angezeigten
Adressen enthalten es bereits.

## Ladeplan und Batterieheizplan — belegt, aber nicht erprobt

Diese Gruppe steht hinter einem **eigenen Haken**, und der ist ab Werk aus.
Der Grund ist eine Unterscheidung, die es wert ist, benannt zu werden.

**Die Gestalt der Nachricht ist belegt.** Sie stammt nicht aus einer
Vermutung, sondern aus dem Quelltext des Gateways:

```
drivetrain/chargingSchedule/set
    {"startTime":"HH:MM","endTime":"HH:MM","mode":"UNTIL_CONFIGURED_SOC"}
drivetrain/batteryHeatingSchedule/set
    {"startTime":"HH:MM","mode":"ON"}
```

`src/handlers/command/drivetrain/drivetrain_charging_schedule.py` liest die
Zeiten mit `time.fromisoformat()` — also `HH:MM` oder `HH:MM:SS` — und den
Modus als `ScheduledChargingMode[payload["mode"].upper()]`. Die drei
zulässigen Werte stehen in `saic_ismart_client_ng`:

| Wert | Bedeutung |
|---|---|
| `DISABLED` | Ladeplan aus |
| `UNTIL_CONFIGURED_SOC` | bis zum Ziel-Ladestand |
| `UNTIL_CONFIGURED_TIME` | bis zur eingestellten Zeit |

`UNTIL_CONFIGURED_SOC` übergeht das Gateway von sich aus, wenn das Fahrzeug
keinen Ziel-Ladestand beherrscht — es schreibt dann eine Warnung in sein
Protokoll und tut nichts.

**Was nicht belegt ist: ob das Fahrzeug den Plan annimmt.** Das kann keine
Quelle beantworten, nur ein Auto. Deshalb der eigene Haken, deshalb die
Kennzeichnung *(nicht am Fahrzeug erprobt)* in der Befehlstabelle — und
deshalb tragen diese Befehle **keine Wirkungsprüfung**: Das Zustandsthema
trägt JSON, ein Textvergleich zwischen Gesendetem und Veröffentlichtem würde
zufällig mal passen und mal nicht. Sie melden `OK=2` — *abgesetzt, Ergebnis
unbekannt*. Ein Erfolg, den niemand geprüft hat, wird hier nicht behauptet.

Sechs Befehle erscheinen, sobald der Haken gesetzt ist:

| Befehl | Nutzlast |
|---|---|
| `ladeplan_ein` / `ladeplan_aus` | aus dem in den Einstellungen hinterlegten Fenster |
| `heizplan_ein` / `heizplan_aus` | aus der hinterlegten Startzeit |
| `ladeplan` | `&von=HH:MM&bis=HH:MM&modus=…` unmittelbar in der Adresse |
| `heizplan` | `&von=HH:MM&modus=on|off` |

Nur die vier festen Formen stehen in der Ausgangsvorlage — zwei Uhrzeiten und
einen Modus kann ein virtueller Ausgang nicht liefern, weder digital noch
analog. Eine unbrauchbare Uhrzeit wird **abgewiesen**, nicht gerundet:
`25:99`, `22:0` und `6:00` ergeben `WERT_UNZULAESSIG`, ein fehlendes `bis`
ergibt `WERT_FEHLT`.

## Es gibt ein zweites Plugin für dasselbe Auto

[mschlenstedt/LoxBerry-Plugin-MGiSMART](https://github.com/mschlenstedt/LoxBerry-Plugin-MGiSMART)
verfolgt einen anderen Ansatz: Es **installiert und betreibt das Gateway
selbst** — mit venv, Wächter, Aktualisierung und Healthcheck — und überlässt
die Anbindung an den Miniserver dann dem MQTT-Gateway. Dieses Plugin hier
setzt umgekehrt einen bereits laufenden Gateway-Container voraus und legt den
Schwerpunkt auf die Loxone-Seite: fertige Zeilen, Vorlagen, Bausteinliste,
Befehle, Automatiken.

Zwei Dinge sind praktisch wichtig:

* **Beide beanspruchen den Ordnernamen `mgismart`.** Die Autorenangaben
  unterscheiden sich, LoxBerry hält sie also für verschiedene Plugins und
  hängt beim zweiten `01` an den Ordner. Dieses Plugin fällt deshalb nur dann
  auf den Namen `mgismart` zurück, wenn dort auch wirklich seine eigene
  `mg.json` liegt.
* **Das MQTT-Gateway kennt zwei Fassungen.** V1 verlangt das Abo von Hand —
  ohne den Eintrag kommt am Miniserver nichts an. **V2 erkennt die
  Themengruppe selbst**; dort werden nur noch die Datenpunkte angehakt. Der
  Reiter *Einbindung in Loxone* liest `Mqtt.Gatewayversion` aus der
  `general.json` und zeigt den passenden Satz, statt pauschal einen von
  beiden zu behaupten.

## Wichtige Hinweise

- **12-Volt-Batterie:** Das Abfragen weckt die Fahrzeugelektronik. Das
  Ruheintervall des Gateways (Standard: einmal täglich) sollte man nicht
  verkürzen. Für frische Werte gezielt `?cmd=auffrischen` verwenden — und die
  Intervalle lassen sich seit 1.1.0 über `?cmd=abfrage_ruhe&sekunden=…`
  gezielt setzen.
- **Nur eine Sitzung:** Meldet sich die iSMART-App an, pausiert das Gateway
  rund 15 Minuten.
- **„Laden starten" ist unzuverlässig**, „Laden stoppen" funktioniert gut. Wer
  über die Wallbox schaltet, ist auf der sicheren Seite; die
  Ladestrombegrenzung des Autos wirkt dagegen zuverlässig — deshalb regelt die
  Ladeempfehlung über sie.
- Die Schnittstelle ist **inoffiziell** und kann sich jederzeit ändern.

## Voraussetzungen

- LoxBerry-Plugin **Docker** (für den Gateway-Container)
- Paket **mosquitto-clients** (wird bei der Installation mitinstalliert)
- iSMART-Konto mit registriertem Fahrzeug

## Datenschutz

Das **iSMART-Passwort kennt nur der Gateway-Container** — dieses Plugin
braucht es nicht.

Zugangsdaten liegen an **vier** Stellen, und wer das Gerät weitergibt, sollte
alle vier kennen:

| Datei | Inhalt | Rechte |
|---|---|---|
| `config/plugins/mgismart/mg.json` | Broker-Passwort, Merkwort | 0600 |
| `config/plugins/mgismart.backup.json` | dieselbe Datei, überlebt das Upgrade | 0600 |
| `data/plugins/mgismart/mosquitto/mosquitto_sub` | Broker-Benutzer und -Passwort im Klartext | 0600 im Ordner 0700 |
| `data/plugins/mgismart/mosquitto/mosquitto_pub` | dasselbe | 0600 im Ordner 0700 |

Die Deinstallation entfernt alle vier sowie `/tmp/mgismart`; die
Plugin-Ordner räumt LoxBerry selbst weg. Passwort **und** Merkwort werden im
Protokoll maskiert. Im Plugin sind keine persönlichen Daten enthalten.

Der **Standort** wird nur als Abstand zum eingetragenen Hausstandort in die
Loxone-Zeile gegeben. Die Rohkoordinaten stehen ausschließlich im Reiter Test
und in `?debug=1`, und das verlangt das Merkwort.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
