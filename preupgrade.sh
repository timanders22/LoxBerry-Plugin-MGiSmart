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
# Loeschen tun. $1 ist eine Kennung, $6 der Arbeitsordner des Installers.

PFOLDER="${3:-mgismart}"

# DIE WURZEL - gelesen, nicht angenommen (Regeln/06). Bis 1.1.16 stand hier
# BASE="${5:-$LBHOMEDIR}", allenfalls mit "-d": ein fremder Baum ohne
# general.json wurde Wurzel, und ohne beides griff dieses Skript ab / zu (in
# WSL gemessen, Pruefung-MGiSmart-1.1.17, Faelle H1-H3). Wurzel ist, was
# config/plugins, data/plugins UND config/system/general.json traegt - erst
# $5, dann $LBHOMEDIR, dann vom eigenen Ablageort aufwaerts. Ohne brauchbare
# Wurzel wird gewarnt, nicht gehandelt.
mg_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
lb_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if mg_ist_wurzel "$v"; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
BASE=""
for MG_K in "$5" "$LBHOMEDIR"; do
    if mg_ist_wurzel "$MG_K"; then BASE="$MG_K"; break; fi
done
[ -n "$BASE" ] || BASE=$(lb_wurzel_suchen)

# DIE ABLAGE - ein ABSOLUTER Pfad. Der Installer ruft jeden Haken als
#   cd "$tempfolder" && "$script" "$tempfile" ... "$lbhomedir" "$tempfolder"
# (plugininstall.pl:853, :1311, :1337, LoxBerry 4.0.0.15, Geraet/2026-09-05):
# $1 ist eine Kennung, kein Pfad, $6 der Arbeitsordner, und das Skript selbst
# liegt in eben diesem Ordner. Bis 1.1.16 lag die Ablage unter dem RELATIVEN
# $1 - am Arbeitsordner, den kein Skript prueft (aus einem anderen Ordner
# gerufen, legte preupgrade.sh dort ab und postupgrade.sh fand nichts), und
# ein $1 mit "../" fuehrte hinaus (in WSL gemessen, Pruefung-MGiSmart-1.1.17,
# Faelle A1-A5). Jetzt: $6/$1, ersatzweise <Ordner dieses Skripts>/$1; $1 nur
# als schlichter Name.
MG_SELBST=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
ABLAGE=""
case "$1" in
    ''|.|..|*/*) ;;
    *)  for MG_A in "$6" "$MG_SELBST"; do
            case "$MG_A" in
                /*) if [ -d "$MG_A" ]; then ABLAGE="$MG_A/$1"; break; fi ;;
            esac
        done ;;
esac

if [ -z "$BASE" ]; then
    echo "<WARNING> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen - fuer das Update wurde nichts beiseitegelegt."
    echo "<WARNING> Die Einstellungen holt dann die Zweitschrift neben dem Konfigordner (<ordner>.backup.json) zurueck."
    exit 1
fi
if [ -z "$ABLAGE" ]; then
    echo "<WARNING> Kein brauchbarer Ablageort fuer das Update (Argument 1 und 6) - nichts beiseitegelegt."
    echo "<WARNING> Die Einstellungen holt dann die Zweitschrift neben dem Konfigordner (<ordner>.backup.json) zurueck."
    exit 1
fi
mkdir -p "$ABLAGE" 2>/dev/null

# Gemeldet wird, was nachgesehen wurde: bis 1.1.16 lief jedes cp stumm, auch
# wenn es scheiterte (unter "ulimit -f 0" gemessen, Pruefung-MGiSmart-1.1.17,
# Fall A6). Die Ablage raeumt der Installer mit seinem Arbeitsordner weg.
MG_FEHL=0
mg_ablegen() {   # $1 Quelle, $2 Name in der Ablage
    [ -f "$1" ] || return 0
    if cp -p "$1" "$ABLAGE/$2" 2>/dev/null && cmp -s "$1" "$ABLAGE/$2"; then
        echo "<OK> $2 fuer das Update beiseitegelegt."
    else
        MG_FEHL=1
        echo "<WARNING> $2 liess sich nicht beiseitelegen ($ABLAGE)."
    fi
}
mg_ablegen "$BASE/config/plugins/$PFOLDER/mg.json" mg.json
mg_ablegen "$BASE/log/plugins/$PFOLDER/mg.log" mg.log
# Die mitgeschriebenen Ladevorgaenge liegen unter data/ und werden vom
# Installer ebenfalls entfernt. Sie sind kein Zustand, den das Plugin
# wiederherstellen koennte - also mitnehmen.
mg_ablegen "$BASE/data/plugins/$PFOLDER/ladungen.json" ladungen.json
if [ "$MG_FEHL" != 0 ]; then
    echo "<WARNING> Die Einstellungen holt dann die Zweitschrift neben dem Konfigordner (<ordner>.backup.json) zurueck."
fi
exit 0
