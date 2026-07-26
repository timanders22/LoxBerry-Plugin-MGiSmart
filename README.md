# LoxBerry-Plugin: MG iSmart

Bringt die Daten eines **MG-Elektrofahrzeugs** (iSMART / SAIC) nach Loxone —
Ladestand, Reichweite, Ladeleistung, Türen, Klima, Standort — und schickt
Befehle zurück: Laden stoppen, Ziel-Ladestand, Ladestrombegrenzung, Standklima,
„Auto finden".

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

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
