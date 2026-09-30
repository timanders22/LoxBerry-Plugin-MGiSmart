#!/bin/bash
# MG iSmart - preinstall (seit 1.1.18; Vorschlag des Installer-Pruefers vom 30.09.2026, um G8 erweitert)
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Der Installer ruft dieses Skript bei JEDEM Einbau, nach dem Aufraeumen der
# alten Fassung und VOR dem Kopieren von Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Aktualisierung erkennt es allein an der Marke data/plugins/<ordner>.upgrade_laeuft,
# die preupgrade.sh anlegt (kein Altersvergleich, Entscheidung 1 vom 29.09.2026).
# Dann tut es nichts. Ohne Marke ist es eine NEUINSTALLATION: eine
# liegengebliebene Zweitschrift (<ordner>.backup.json) und ihr beiseitegelegter
# Stand (<ordner>.backup.json.kaputt) einer frueheren Installation gehen nach
# <name>.alt, gemeldet mit genau einer <WARNING>. Hier und nicht erst in
# postinstall.sh, weil der Minutentakt (cron.php -> mg_config()) die
# Zweitschrift sonst schon VOR postinstall.sh in mg.json heilt (in WSL gemessen,
# Fall D2). Die Selbstheilung der Bibliothek liest .alt nie; uninstall raeumt es ab.

PFOLDER="${3:-mgismart}"
case "$PFOLDER" in
    ''|.|..|*/*) echo "<WARNING> Unbrauchbarer Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
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
if [ -z "$BASE" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
[ -f "$MARKE" ] && exit 0

BK="$BASE/config/plugins/$PFOLDER.backup.json"
BEISEITE=""
FEST=""
# Nacharbeit 30.09. (N1): die Merkdatei eines angelegten Gateway-Containers
# gehoert ebenfalls zur frueheren Installation - nach .alt wie die
# Zweitschriften (uninstall liest .alt mit und raeumt sie ab).
for ZIEL in "$BK" "$BK.kaputt" "$BASE/config/plugins/$PFOLDER.gateway_angelegt"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -f "$ZIEL.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null && [ ! -e "$ZIEL" ]; then
            [ -L "$ZIEL.alt" ] || chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    MG_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && MG_TEXT="$MG_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && MG_TEXT="$MG_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$MG_TEXT"
fi

# G8 (Entscheidung 7): ein Gateway-Container mit dem eigenen Label, den eine
# fruehere Installation liegen liess. Er wird NICHT uebernommen und nicht
# angefasst - kein stop, kein rm (G6). Gemeldet wird er einmal, und die Kennung
# geht in data/plugins/<ordner>.gateway_frueher (neben dem Datenordner, den der
# Installer leert); die Ampel im Reiter "Gateway einrichten" zeigt daran
# "Container einer frueheren Installation". Beide Labels als Filter (docker
# verknuepft mehrere label-Filter mit UND); jeder Aufruf mit timeout.
# Ohne docker oder ohne timeout: keine Aussage, kein Befund.
if command -v docker >/dev/null 2>&1 && command -v timeout >/dev/null 2>&1; then
    MG_IDS=$(timeout 15 docker ps -a -q --no-trunc \
        --filter "label=de.loxberry.plugin.folder=$PFOLDER" \
        --filter "label=de.loxberry.plugin.name=mgismart" 2>/dev/null)
    MG_FRUEHER=""
    for MG_ID in $MG_IDS; do
        case "$MG_ID" in
            *[!0-9a-f]*) continue ;;
        esac
        MG_FRUEHER="$MG_ID"
        break
    done
    if [ -n "$MG_FRUEHER" ]; then
        MG_FD="$BASE/data/plugins/$PFOLDER.gateway_frueher"
        rm -f "$MG_FD" 2>/dev/null
        printf '%s\n' "$MG_FRUEHER" > "$MG_FD" 2>/dev/null
        echo "<WARNING> Ein Gateway-Container einer frueheren Installation liegt noch vor (Kennung $(printf '%.12s' "$MG_FRUEHER")). Er wird nicht uebernommen und nicht angefasst - im Reiter Gateway einrichten entfernen oder neu anlegen."
    fi
fi
exit 0
