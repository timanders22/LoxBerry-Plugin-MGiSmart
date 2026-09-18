#!/bin/bash
# MG iSmart - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Laeuft als LETZTES, nach postinstall. Das ist der Grund, warum hier bis 1.0.8
# etwas Unangenehmes geschah: Das Skript legte eine "mgismart.json" aus der
# Sicherung an - also genau die verwaiste Datei, die postinstall.sh eine Zeile
# vorher aufraeumen sollte. Sie enthielt Broker-Passwort und Merkwort, wurde von
# niemandem gelesen und kam bei jedem Upgrade neu. Die README behauptete
# derweil, sie werde aufgeraeumt.
#
# Jetzt: die RICHTIGEN Dateinamen (mg.json, mg.log), und nichts wird angelegt,
# was das Plugin nicht auch liest.

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-mgismart}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<WARNING> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen."
    exit 1
fi

CDIR="$BASE/config/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
mkdir -p "$CDIR" "$LDIR" "$DDIR" 2>/dev/null

# Traegt die Datei INHALT - also ein lesbares JSON-Objekt UND das Merkwort?
#
# "[ -s "$CF" ]" heisst nur "nicht leer" und ist zu schwach: eine beim
# Schreiben ABGESCHNITTENE mg.json ist nicht leer und nicht "{}", fuer
# json_decode aber unbrauchbar - sie lief hier bis 1.1.13 als brauchbare
# Konfiguration durch, und die weggelegte Fassung wurde nicht zurueckgeholt
# (gemessen 18.09.2026 in WSL, Fall hook_postupgrade). Regeln/05: nach Inhalt
# entscheiden, nicht nach Form. Wortgleich mit postinstall.sh.
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

# Zurueckholen, was preupgrade weggelegt hat - aber nur, wenn nicht schon eine
# brauchbare Konfiguration dasteht. postinstall hat sie moeglicherweise bereits
# aus der Sicherung neben dem Ordner wiederhergestellt.
CF="$CDIR/mg.json"
if [ -f "$ARGV1/mg.json" ] && ! hat_inhalt "$CF" && hat_inhalt "$ARGV1/mg.json"; then
    if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)" != "{}" ]; then
        cp -p "$CF" "$CF.kaputt" 2>/dev/null
        chmod 600 "$CF.kaputt" 2>/dev/null
        echo "<INFO> Der vorherige Inhalt liegt unter $CF.kaputt"
    fi
    cp -p "$ARGV1/mg.json" "$CF" && chmod 600 "$CF" 2>/dev/null
    echo "<OK> Konfiguration aus dem Upgrade uebernommen."
fi
if [ -f "$ARGV1/mg.log" ] && [ ! -s "$LDIR/mg.log" ]; then
    cp -p "$ARGV1/mg.log" "$LDIR/mg.log" 2>/dev/null
    echo "<OK> Protokoll aus dem Upgrade uebernommen."
fi
if [ -f "$ARGV1/ladungen.json" ] && [ ! -s "$DDIR/ladungen.json" ]; then
    cp -p "$ARGV1/ladungen.json" "$DDIR/ladungen.json" 2>/dev/null
    echo "<OK> Aufgezeichnete Ladevorgaenge uebernommen."
fi

# Altlast aus 1.0.2: cron.php lag im UNANGEMELDETEN Webordner und war damit
# fuer jeden erreichbar, der die LoxBerry-Oberflaeche im Netz sieht. Jeder
# Aufruf startet mosquitto_sub mit -W 3, haelt also drei Sekunden lang einen
# PHP-Arbeiter fest. Seit 1.0.3 liegt die Datei unter bin/.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi

# Altlast aus 1.0.2 bis 1.0.8: die nie gelesene Datei unter falschem Namen.
# Sie entstand bis dahin GENAU HIER, in diesem Skript.
if [ -f "$CDIR/mgismart.json" ]; then
    rm -f "$CDIR/mgismart.json"
    echo "<OK> Verwaiste mgismart.json entfernt (sie wurde bis 1.0.8 hier angelegt)."
fi

exit 0
