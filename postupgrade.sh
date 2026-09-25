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

ARGV3=$3
PFOLDER="${ARGV3:-mgismart}"

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
    echo "<WARNING> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen - nichts zurueckgeholt."
    exit 1
fi
# Unten heisst die Ablage ARGV1; leer, wenn es keine gibt - dann wird aus ihr
# nichts gelesen (sonst stuende "$ARGV1/mg.json" fuer /mg.json).
ARGV1="$ABLAGE"

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

# Zurueckholen, was preupgrade weggelegt hat - aber nur, wenn nicht schon eine
# brauchbare Konfiguration dasteht. postinstall hat sie moeglicherweise bereits
# aus der Sicherung neben dem Ordner wiederhergestellt.
CF="$CDIR/mg.json"
# Dieselbe Frage wie am Ende von postinstall.sh: iSMART-Benutzername oder
# Fahrzeug-Kennung eingetragen?
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
MG_VORHER=0; mg_eingerichtet "$CF" && MG_VORHER=1
MG_GESICHERT=0; [ -n "$ARGV1" ] && mg_eingerichtet "$ARGV1/mg.json" && MG_GESICHERT=1
if [ -n "$ARGV1" ] && [ -f "$ARGV1/mg.json" ] && ! hat_inhalt "$CF" && hat_inhalt "$ARGV1/mg.json"; then
    if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)" != "{}" ]; then
        if mg_kopieren "$CF" "$CF.kaputt" 600; then
            echo "<INFO> Der vorherige Inhalt liegt unter $CF.kaputt"
        else
            echo "<WARNING> Der vorherige Inhalt liess sich nicht nach $CF.kaputt legen."
        fi
    fi
    if mg_kopieren "$ARGV1/mg.json" "$CF" 600; then
        echo "<OK> Konfiguration aus dem Upgrade uebernommen."
    else
        echo "<WARNING> Die Konfiguration von vor dem Upgrade liess sich nicht nach $CF kopieren."
    fi
fi
if [ -n "$ARGV1" ] && [ -f "$ARGV1/mg.log" ] && [ ! -s "$LDIR/mg.log" ]; then
    if cp -p "$ARGV1/mg.log" "$LDIR/mg.log" 2>/dev/null \
       && cmp -s "$ARGV1/mg.log" "$LDIR/mg.log"; then
        echo "<OK> Protokoll aus dem Upgrade uebernommen."
    else
        echo "<WARNING> Das Protokoll von vor dem Upgrade liess sich nicht uebernehmen."
    fi
fi

# Die mitgeschriebenen Ladevorgaenge - nach INHALT, und zusammengefuehrt.
#
# Bis 1.1.14 hiess es "Ziel leer? dann kopieren" (Groesse). Zwei Faelle, beide
# gemessen am 18.09.2026 in WSL (Pruefung-MGiSmart-1.1.14):
#  * Im Upgrade laeuft der Minutentakt schon vor postinstall.sh (Regeln/06,
#    Einspeisebremse 0.9.19). Endet in dieser Minute eine Ladung, schreibt
#    cron.php eine ladungen.json mit genau diesem einen Eintrag - und die
#    gesicherten fielen weg (Fall K6: 1 statt 4 Eintraege).
#  * Eine abgeschnittene Ablage wurde als "uebernommen" gemeldet und lag
#    danach unlesbar im Datenordner (Fall K7).
# Zusammengefuehrt wird ueber die Kennung, wie mg_ladung_pruefen() sie
# vergibt; die Obergrenze zieht der naechste Eintrag nach (array_slice auf
# ladungen_max in mg_lib.php). php braucht das Plugin ohnehin - cron.php ist PHP.
mg_ladungen_lesbar() {   # $1 Datei -> Zahl der Eintraege; 1 = unlesbar, 2 = kein php
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !isset($d["liste"]) || !is_array($d["liste"])) { exit(1); }
        echo count($d["liste"]);
        exit(0);' -- "$1" 2>/dev/null
}
mg_ladungen_zusammen() {   # $1 Ablage, $2 Datei im Datenordner, $3 Ausgabe
    php -r '
        $lies = function ($f) {
            if (!is_file($f)) { return array(); }
            $d = json_decode((string) @file_get_contents($f), true);
            return (is_array($d) && isset($d["liste"]) && is_array($d["liste"])) ? $d["liste"] : array();
        };
        $liste = $lies($argv[1]);
        $da = array();
        foreach ($liste as $e) {
            if (is_array($e) && isset($e["id"])) { $da[(string) $e["id"]] = true; }
        }
        foreach ($lies($argv[2]) as $e) {
            if (!is_array($e) || !isset($e["id"]) || !isset($da[(string) $e["id"]])) { $liste[] = $e; }
        }
        $j = json_encode(array("liste" => array_values($liste)),
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false || @file_put_contents($argv[3], $j) !== strlen($j)) { exit(1); }
        exit(0);' -- "$1" "$2" "$3" 2>/dev/null
}
LA="$ARGV1/ladungen.json"
LZ="$DDIR/ladungen.json"
if [ -n "$ARGV1" ] && [ -f "$LA" ]; then
    MG_N=$(mg_ladungen_lesbar "$LA"); MG_RC=$?
    if [ "$MG_RC" = 2 ]; then
        echo "<WARNING> php fehlt - die gesicherten Ladevorgaenge wurden nicht uebernommen."
    elif [ "$MG_RC" != 0 ]; then
        echo "<WARNING> Die gesicherten Ladevorgaenge sind nicht lesbar und wurden nicht uebernommen."
        if mg_kopieren "$LA" "$LZ.kaputt" 600; then
            echo "<WARNING> Der Stand liegt unter $LZ.kaputt"
        fi
    else
        # Was im Datenordner liegt und nicht lesbar ist, kommt vorher beiseite.
        if [ -f "$LZ" ] && ! mg_ladungen_lesbar "$LZ" >/dev/null; then
            if mg_kopieren "$LZ" "$LZ.kaputt" 600; then
                echo "<INFO> Die unlesbare ladungen.json liegt unter $LZ.kaputt"
            fi
        fi
        MG_NEU="$LZ.neu.$$"
        if mg_ladungen_zusammen "$LA" "$LZ" "$MG_NEU" && chmod 644 "$MG_NEU" 2>/dev/null \
           && mv -f "$MG_NEU" "$LZ" 2>/dev/null && MG_Z=$(mg_ladungen_lesbar "$LZ"); then
            echo "<OK> Aufgezeichnete Ladevorgaenge uebernommen ($MG_Z Eintraege, davon $MG_N gesichert)."
        else
            rm -f "$MG_NEU" 2>/dev/null
            echo "<WARNING> Die gesicherten Ladevorgaenge ($MG_N Eintraege) liessen sich nicht uebernehmen."
        fi
    fi
fi

# Altlast aus 1.0.2: cron.php lag im UNANGEMELDETEN Webordner und war damit
# fuer jeden erreichbar, der die LoxBerry-Oberflaeche im Netz sieht. Jeder
# Aufruf startet mosquitto_sub mit -W 3, haelt also drei Sekunden lang einen
# PHP-Arbeiter fest. Seit 1.0.3 liegt die Datei unter bin/.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
# Gemeldet wird, was nachgesehen wurde: bis 1.1.14 kam die <OK>-Zeile auch,
# wenn rm scheiterte (gemessen 18.09.2026 in WSL, Pruefung-MGiSmart-1.1.14,
# Fall K10) - hier also ein unangemeldet erreichbarer Endpunkt als "entfernt".
if [ -f "$ALT" ]; then
    rm -f "$ALT" 2>/dev/null
    if [ -e "$ALT" ]; then
        echo "<WARNING> Die alte, ueber HTTP erreichbare cron.php liess sich nicht entfernen: $ALT"
    else
        echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
    fi
fi

# Altlast aus 1.0.2 bis 1.0.8: die nie gelesene Datei unter falschem Namen.
# Sie entstand bis dahin GENAU HIER, in diesem Skript.
if [ -f "$CDIR/mgismart.json" ]; then
    rm -f "$CDIR/mgismart.json" 2>/dev/null
    if [ -e "$CDIR/mgismart.json" ]; then
        echo "<WARNING> Die verwaiste mgismart.json liess sich nicht entfernen: $CDIR/mgismart.json"
    else
        echo "<OK> Verwaiste mgismart.json entfernt (sie wurde bis 1.0.8 hier angelegt)."
    fi
fi

# Das Schlusswort zur Konfiguration steht hier nur, wenn postinstall.sh es
# hierher verwiesen hat: dort war mg.json noch nicht eingerichtet, die
# Ablage von preupgrade.sh aber schon.
if [ $MG_VORHER = 0 ] && [ $MG_GESICHERT = 1 ]; then
    if mg_eingerichtet "$CF"; then
        echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
    else
        echo "<WARNING> Die Einstellungen liessen sich nicht zurueckholen."
        echo "<WARNING> Bitte die Plugin-Oberflaeche oeffnen. Im Reiter MQTT gehoeren die"
        echo "<WARNING> Zugangsdaten des Brokers, der iSMART-Benutzername und die"
        echo "<WARNING> Fahrzeug-Kennung (VIN) hinein."
    fi
fi

exit 0
