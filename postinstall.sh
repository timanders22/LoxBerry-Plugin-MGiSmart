#!/bin/bash
# MG iSmart - postinstall
#
# ZWEI FEHLER AUS 1.0.2, BEIDE HIER:
#
# 1. Die Schlussmeldung bat darum, "das Bundesland zu waehlen". Das ist ein
#    Ueberbleibsel aus dem Plugin Ferien & Feiertage. MG drosselt seine Autos
#    nicht nach Bundesland.
#
# 2. Schwerer: Die Datei hiess hier mgismart.json, die Bibliothek liest aber
#    mg.json (mg_paths()). Das Wiederherstellen aus der Sicherung legte also
#    eine Datei an, die niemand liest, und meldete trotzdem Erfolg. Dass die
#    Einstellungen nach einer Neuinstallation dennoch wieder da waren, lag
#    allein an mg_config(), das die Sicherung beim ersten Lesen selbst
#    zurueckholt - der Umweg hier war wirkungslos, aber nicht folgenlos: Er
#    hinterliess eine verwaiste mgismart.json neben der echten.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-mgismart}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen."
    exit 1
fi

CDIR="$BASE/config/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
CF="$CDIR/mg.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$CDIR" "$DDIR" 2>/dev/null

if [ ! -f "$CF" ]; then
    echo '{}' > "$CF"
fi
# Die Konfiguration enthaelt das Broker-Passwort und das Merkwort des
# Endpunkts - sie geht niemanden ausser loxberry etwas an.
chmod 600 "$CF" 2>/dev/null

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        chmod 600 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
    fi
fi

# Altlast aus 1.0.2 aufraeumen: die nie gelesene Datei unter falschem Namen.
if [ -f "$CDIR/mgismart.json" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        # Sie koennte die einzige vorhandene Konfiguration sein.
        cp -p "$CDIR/mgismart.json" "$CF"
        chmod 600 "$CF" 2>/dev/null
        echo "<OK> Einstellungen aus der alten mgismart.json uebernommen."
    fi
    rm -f "$CDIR/mgismart.json"
    echo "<INFO> Verwaiste mgismart.json aus Fassung 1.0.2 entfernt."
fi

# Ordner fuer die Zugangsdaten von mosquitto_sub/_pub. 0700, denn hier steht
# das Broker-Passwort - es soll gerade NICHT mehr auf der Kommandozeile stehen.
mkdir -p "$DDIR/mosquitto" 2>/dev/null
chmod 700 "$DDIR/mosquitto" 2>/dev/null

if ! command -v mosquitto_sub >/dev/null 2>&1; then
    echo "<WARNING> mosquitto_sub wurde nicht gefunden."
    echo "<INFO> Nachinstallieren mit: sudo apt-get install -y mosquitto-clients"
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen und im Reiter Einstellungen"
echo "<INFO> die Zugangsdaten des MQTT-Brokers, den SAIC-Benutzernamen und die"
echo "<INFO> Fahrzeug-Kennung (VIN) eintragen."
exit 0
