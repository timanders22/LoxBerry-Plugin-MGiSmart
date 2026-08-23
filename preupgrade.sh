#!/bin/bash
# MG iSmart - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# WAS HIER BIS 1.0.8 FALSCH WAR
# Dieses Skript sicherte "mgismart.json" und "mgismart.log". Gelesen und
# geschrieben werden aber "mg.json" und "mg.log" (mg_paths() in mg_lib.php).
# Die Quelle gab es also gar nicht - das Skript war fuer seinen erklaerten
# Zweck wirkungslos, und das Protokoll ging bei jedem Upgrade verloren. Genau
# derselbe Fehler war fuer postinstall.sh mit 1.0.3 behoben und hier stehen
# geblieben.
#
# WARUM HIER UND NICHT SPAETER
# Der Installer raeumt unmittelbar nach diesem Skript auf:
#   preupgrade -> rm -rf config/plugins/<ordner>/ und data/plugins/<ordner>/
#              -> config/* aus dem Archiv kopieren -> postinstall -> postupgrade
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Loeschen tun. $1 ist der Zwischenordner des Installers, nicht /tmp.

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-mgismart}"
BASE="${ARGV5:-$LBHOMEDIR}"

[ -n "$ARGV1" ] || exit 0
mkdir -p "$ARGV1" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/mg.json"        "$ARGV1/mg.json"        2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/mg.log"            "$ARGV1/mg.log"         2>/dev/null
# Die mitgeschriebenen Ladevorgaenge liegen unter data/ und werden vom
# Installer ebenfalls entfernt. Sie sind kein Zustand, den das Plugin
# wiederherstellen koennte - also mitnehmen.
cp -p "$BASE/data/plugins/$PFOLDER/ladungen.json"    "$ARGV1/ladungen.json"  2>/dev/null

exit 0
