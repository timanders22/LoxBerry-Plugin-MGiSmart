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

# Zurueckholen, was preupgrade weggelegt hat - aber nur, wenn nicht schon eine
# brauchbare Konfiguration dasteht. postinstall hat sie moeglicherweise bereits
# aus der Sicherung neben dem Ordner wiederhergestellt.
CF="$CDIR/mg.json"
if [ -f "$ARGV1/mg.json" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$ARGV1/mg.json" "$CF" && chmod 600 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus dem Upgrade uebernommen."
    fi
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
