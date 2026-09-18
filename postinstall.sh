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

# Traegt die Datei INHALT - also ein lesbares JSON-Objekt UND das Merkwort?
#
# "[ -s "$CF" ]" heisst nur "nicht leer" und ist zu schwach: eine beim
# Schreiben ABGESCHNITTENE mg.json ist nicht leer und nicht "{}", fuer
# json_decode aber unbrauchbar - sie lief hier bis 1.1.13 als brauchbare
# Konfiguration durch, und die Sicherung wurde nicht zurueckgeholt (gemessen
# 18.09.2026 in WSL, Fall hook_postinstall). Regeln/05: nach Inhalt
# entscheiden, nicht nach Form.
hat_inhalt() {
    [ -f "$1" ] || return 1
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$1" <<'PYEOF'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(1)
sys.exit(0 if isinstance(d, dict) and str(d.get("aktionstoken") or "").strip() else 1)
PYEOF
        return $?
    fi
    if command -v php >/dev/null 2>&1; then
        php -r '$d = json_decode((string) file_get_contents($argv[1]), true);
            exit((is_array($d) && $d !== array()
                  && trim((string) (isset($d["aktionstoken"]) ? $d["aktionstoken"] : "")) !== "")
                 ? 0 : 1);' "$1"
        return $?
    fi
    # Weder python3 noch php: die Frage laesst sich nicht beantworten. Ein
    # blosses grep nach dem Merkwort waere die falsche Antwort - eine
    # ABGESCHNITTENE Datei traegt es woertlich ebenfalls und liefe damit als
    # brauchbar durch (gemessen 18.09.2026). Also lieber gar keine Antwort:
    # "nein" fuer JEDE Datei bedeutet, dass hier nichts zurueckgeholt und
    # nichts ueberschrieben wird. Die Selbstheilung der Bibliothek holt die
    # Zweitschrift beim ersten Seitenaufruf ohnehin. Auf einem LoxBerry kommt
    # dieser Zweig nicht vor - dort liegen python3 und php beide.
    return 1
}

if [ ! -f "$CF" ]; then
    echo '{}' > "$CF"
fi
# Die Konfiguration enthaelt das Broker-Passwort und das Merkwort des
# Endpunkts - sie geht niemanden ausser loxberry etwas an.
chmod 600 "$CF" 2>/dev/null

if [ -f "$BK" ] && ! hat_inhalt "$CF" && hat_inhalt "$BK"; then
    # Was verdraengt wird, bleibt liegen - es koennen Zugangsdaten darin
    # stehen, und "{}" ist nichts wert.
    if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)" != "{}" ]; then
        cp -p "$CF" "$CF.kaputt" 2>/dev/null
        chmod 600 "$CF.kaputt" 2>/dev/null
        echo "<INFO> Der vorherige Inhalt liegt unter $CF.kaputt"
    fi
    cp -p "$BK" "$CF"
    chmod 600 "$CF" 2>/dev/null
    echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
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
