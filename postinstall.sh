#!/bin/bash
# MG iSmart - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Laeuft IMMER, auch beim Upgrade - dort unmittelbar nachdem der Installer
# config/plugins/<ordner>/ und data/plugins/<ordner>/ geloescht und die
# mitgelieferte Konfiguration hineinkopiert hat. Alles, was hier von einem
# frueheren Stand erwartet wird, ist zu diesem Zeitpunkt bereits fort; die
# einzige Quelle, die den Loeschschritt uebersteht, ist die Sicherung NEBEN
# dem Konfigordner.

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
LDIR="$BASE/log/plugins/$PFOLDER"
CF="$CDIR/mg.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$CDIR" "$DDIR" "$LDIR" 2>/dev/null

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

# Ordner fuer die Zugangsdaten von mosquitto_sub/_pub. 0700, denn hier steht
# das Broker-Passwort - es soll gerade NICHT auf der Kommandozeile stehen,
# wo jeder lokale Benutzer es ueber /proc mitlesen koennte.
mkdir -p "$DDIR/mosquitto" 2>/dev/null
chmod 700 "$DDIR/mosquitto" 2>/dev/null

if ! command -v mosquitto_sub >/dev/null 2>&1; then
    echo "<WARNING> mosquitto_sub wurde nicht gefunden."
    echo "<INFO> Nachinstallieren mit: sudo apt-get install -y mosquitto-clients"
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen. Im Reiter MQTT gehoeren die"
echo "<INFO> Zugangsdaten des Brokers, der iSMART-Benutzername und die"
echo "<INFO> Fahrzeug-Kennung (VIN) hinein - ein Konto darf mehrere Fahrzeuge"
echo "<INFO> fuehren. Der Reiter Test beantwortet danach mit Haken und Kreuzen,"
echo "<INFO> ob die Einrichtung traegt."
exit 0
