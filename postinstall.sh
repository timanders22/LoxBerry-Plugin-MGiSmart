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

# Kopieren ueber eine Nebendatei: umbenannt wird erst, wenn die Nebendatei
# byteweise der Quelle gleicht. Ein cp unmittelbar aufs Ziel kuerzt es zuerst
# auf null; scheitert das Schreiben danach (volle Karte), ist der alte Stand
# fort und der neue halb. Bis 1.1.14 stand hier genau das, und gemeldet wurde
# ohne Blick auf die Wirkung - gemessen am 18.09.2026 in WSL unter
# "ulimit -f 0" (Pruefung-MGiSmart-1.1.14, Faelle K2/K4): mg.json danach
# 0 Byte, gemeldet "wiederhergestellt" und "liegt unter ...kaputt".
# Rueckgabe 0 nur, wenn das Ziel danach der Quelle gleicht.
mg_kopieren() {   # $1 Quelle, $2 Ziel, $3 Rechte
    mg_neu="$2.neu.$$"
    if ( umask 077 && cp -p "$1" "$mg_neu" ) 2>/dev/null \
       && cmp -s "$1" "$mg_neu" \
       && chmod "$3" "$mg_neu" 2>/dev/null \
       && mv -f "$mg_neu" "$2" 2>/dev/null \
       && cmp -s "$1" "$2"; then
        return 0
    fi
    rm -f "$mg_neu" 2>/dev/null
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
        if mg_kopieren "$CF" "$CF.kaputt" 600; then
            echo "<INFO> Der vorherige Inhalt liegt unter $CF.kaputt"
        else
            echo "<WARNING> Der vorherige Inhalt liess sich nicht nach $CF.kaputt legen."
        fi
    fi
    if mg_kopieren "$BK" "$CF" 600; then
        echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
    else
        echo "<WARNING> Die Sicherung liess sich nicht nach $CF zurueckspielen."
        echo "<WARNING> Sie liegt unveraendert unter $BK; die Bibliothek versucht es"
        echo "<WARNING> beim naechsten Lesen der Konfiguration erneut."
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

# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade; danach war der Rat falsch und
# legte nahe, die Zugangsdaten seien verloren. "Eingerichtet" heisst: in
# mg.json steht der iSMART-Benutzername oder mindestens eine Fahrzeug-Kennung
# (vins, oder vin aus der Zeit bis 1.0.8). Das Merkwort, nach dem hat_inhalt()
# die Sicherung beurteilt, reicht nicht: es entsteht beim ersten Oeffnen der
# Oberflaeche ohne jede Eintragung. Gleichlautend in postupgrade.sh.
mg_eingerichtet() {
    [ -s "$1" ] || return 1
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$1" <<'PYEOF'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(1)
def voll(w):
    return isinstance(w, str) and w.strip() != ""
if not isinstance(d, dict):
    sys.exit(1)
vins = d.get("vins") if isinstance(d.get("vins"), list) else []
sys.exit(0 if voll(d.get("saic_user")) or voll(d.get("vin")) or any(voll(v) for v in vins) else 1)
PYEOF
        return $?
    fi
    if command -v php >/dev/null 2>&1; then
        php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
            if (!is_array($d)) { exit(1); }
            $voll = function ($w) { return is_string($w) && trim($w) !== ""; };
            $v = isset($d["vins"]) && is_array($d["vins"]) ? $d["vins"] : array();
            exit(($voll(isset($d["saic_user"]) ? $d["saic_user"] : null)
                  || $voll(isset($d["vin"]) ? $d["vin"] : null)
                  || count(array_filter($v, $voll)) > 0) ? 0 : 1);' "$1" 2>/dev/null
        return $?
    fi
    return 1
}
if mg_eingerichtet "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
    echo "<INFO> Der Reiter Test beantwortet mit Haken und Kreuzen, ob die Einrichtung traegt."
elif [ -n "$1" ] && mg_eingerichtet "$1/mg.json"; then
    # Ohne Sicherung neben dem Ordner holt erst postupgrade.sh die
    # Konfiguration zurueck (aus der Ablage von preupgrade.sh) und meldet
    # dort, ob es gelang.
    echo "<OK> Installation abgeschlossen. Die Einstellungen holt postupgrade.sh gleich zurueck."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen. Im Reiter MQTT gehoeren die"
    echo "<INFO> Zugangsdaten des Brokers, der iSMART-Benutzername und die"
    echo "<INFO> Fahrzeug-Kennung (VIN) hinein - ein Konto darf mehrere Fahrzeuge"
    echo "<INFO> fuehren. Der Reiter Test beantwortet danach mit Haken und Kreuzen,"
    echo "<INFO> ob die Einrichtung traegt."
fi
exit 0
