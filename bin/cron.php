<?php
/**
 * MG iSmart - Dienst, jede Minute vom Cron aufgerufen.
 *
 * Liest die zuletzt vom SAIC-MQTT-Gateway veroeffentlichten Werte ein,
 * erkennt meldenswerte Ereignisse, schreibt abgeschlossene Ladevorgaenge
 * fort, veroeffentlicht die umgesetzten Werte auf Wunsch unter dem eigenen
 * MQTT-Praefix und wertet die beiden Automatiken aus.
 *
 * Liegt seit 1.0.3 unter bin/ und nicht mehr unter webfrontend/html/ - dort
 * war es ueber HTTP erreichbar, ohne Anmeldung, und jeder Aufruf band drei
 * Sekunden lang einen PHP-Arbeiter. Siehe cron/cron.01min.
 *
 * Die Bibliothek liegt weiterhin im html-Ordner, weil der Miniserver-Endpunkt
 * sie ebenso braucht. Gesucht wird sie an beiden moeglichen Stellen.
 */
/* Die Bibliothek. Welche Lage gilt, entscheidet der eigene Ablageort, nicht
 * die Reihenfolge der Versuche: liegt diese Datei unter .../plugins/<ordner>,
 * ist sie installiert (<Wurzel>/bin/plugins/<ordner>), sonst liegt sie in
 * einem ausgepackten Archiv. Bis 1.1.16 wurden drei Kandidaten der Reihe nach
 * probiert; aus einem Archiv unter /plugin waren die ersten beiden
 * //html/plugins/bin/mg_lib.php und //webfrontend/html/plugins/bin/mg_lib.php
 * - ab der Laufwerkswurzel, und was dort lag, lief als Bibliothek (in WSL im
 * eigenen Wurzelbaum gemessen, Pruefung-MGiSmart-1.1.17, Fall P1). */
if (basename(dirname(__DIR__)) === 'plugins') {
    $mg_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/mg_lib.php';
} else {
    $mg_lib = dirname(__DIR__) . '/webfrontend/html/mg_lib.php';
}
if (!is_file($mg_lib)) {
    fwrite(STDERR, "mg_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $mg_lib;

/* Gruppe 39 (1.1.28, V7-f/T3-3): erlaubt sind nur die drei Schalter unten. Jeder
 * andere Schalter - auch ein Wort ohne "--" - endet mit einem Satz auf STDERR und
 * Rueckgabe 2, OHNE Wirkung (vor Laufzaehler, Momentaufnahme und Automatik). Bis
 * 1.1.27 lief "--mqt-leeren" als voller Minutenlauf durch und schickte unter
 * Umstaenden Befehle ans Auto. */
$mg_erlaubt = array('--mqtt-leeren', '--gateway-leeren', '--haus-lesen');
foreach (array_slice(isset($argv) ? $argv : array(), 1) as $mg_a) {
    if (!in_array($mg_a, $mg_erlaubt, true)) {
        fwrite(STDERR, 'cron.php: unbekannter Schalter ' . $mg_a . ' - erlaubt sind ' . implode(' ', $mg_erlaubt)
            . ". Es wurde nichts getan.\n");
        exit(2);
    }
}

/* Ohne Anlage nichts tun: weder lesen noch senden noch schreiben. Bis 1.1.16
 * lief diese Datei aus einem ausgepackten Archiv unterhalb einer echten
 * Wurzel ohne jede Pruefung los - Momentaufnahme, Protokoll, MQTT und
 * Automatiken auf der Anlage (in WSL gemessen, Pruefung-MGiSmart-1.1.17,
 * Fall B5). Der Archivmodus steht in mg_paths(). */
$mg_p = mg_paths();
if ($mg_p['lbhome'] === '') {
    if ($mg_p['archiv'] !== '') {
        fwrite(STDERR, "cron.php: Diese Datei liegt nicht in der Installation unter " . $mg_p['archiv'] . "\n"
            . "(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,\n"
            . "wurde nichts gelesen, nichts gesendet und nichts geschrieben.\n"
            . "Abhilfe: das Programm aus " . $mg_p['archiv'] . "/bin/plugins/<ordner> aufrufen\n"
            . "oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.\n");
    } else {
        fwrite(STDERR, "cron.php: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.\n"
            . "\$LBHOMEDIR ist nicht gesetzt, und oberhalb von " . __DIR__ . " traegt kein\n"
            . "Verzeichnis config/plugins, data/plugins und config/system/general.json.\n"
            . "Es wurde nichts gelesen, nichts gesendet und nichts geschrieben.\n");
    }
    exit(1);
}

/* Aufruf aus uninstall/uninstall: die zurueckbehaltenen MQTT-Themen dieses
 * Plugins im Broker leeren (mg_mqtt_leeren()) - und sonst nichts. Nur lesen:
 * die Konfiguration wird dabei nicht angelegt und nicht geheilt. */
if (in_array('--mqtt-leeren', array_slice(isset($argv) ? $argv : array(), 1), true)) {
    mg_nur_lesen(true);
    list($mg_rc, $mg_zeilen) = mg_mqtt_leeren();
    foreach ($mg_zeilen as $mg_z) {
        echo $mg_z . "\n";
    }
    exit($mg_rc);
}
/* Seit 1.1.18 (Bauliste G7): die zurueckbehaltenen Themen des GATEWAYS unter
 * <prefix>/ - nur aus uninstall/uninstall und nur, nachdem dort ein Container
 * mit den eigenen Labels entfernt wurde (mg_gateway_themen_leeren()). */
if (in_array('--gateway-leeren', array_slice(isset($argv) ? $argv : array(), 1), true)) {
    mg_nur_lesen(true);
    list($mg_rc, $mg_zeilen) = mg_gateway_themen_leeren();
    foreach ($mg_zeilen as $mg_z) {
        echo $mg_z . "\n";
    }
    exit($mg_rc);
}

/* Gruppe 39 (1.1.28, 2.4): der abgekoppelte Leser der Haus-Themen
 * (mg_haus_abnehmer_starten() startet ihn, eigene Sperre haus_lesen.lock). Er
 * wartet bis 65 s am Broker, bis jeder Zaehler einmal live kam (Phasenfalle),
 * liest nur und schreibt <tmp>/haus_quellen.json. Kein Laufzaehler, keine
 * Automatik, kein Senden. */
if (in_array('--haus-lesen', array_slice(isset($argv) ? $argv : array(), 1), true)) {
    mg_haus_leser_lauf(MG_HAUS_LESEFENSTER);
    exit(0);
}

/* Welle Bild 19 (1.1.26, Entwurf Welle 19 mg E4): der Laufzaehler geht bei
 * JEDEM Minutenlauf eins weiter - gleich nach der Wurzelpruefung und nach den
 * beiden Aufrufen aus uninstall (die nur lesen sollen), vor jeder Frage nach
 * Einrichtung oder Broker. Er ist das Lebenszeichen der HTTP-Zeile (Feld
 * ZAEHLER) und speist status/zaehler; steht der Takt, bleibt er stehen. */
mg_laufzaehler_weiter();

/* Gruppe 39 (1.1.28, 2.2): der Waechter der Befehlsbruecke - vorn im Lauf, vor
 * "eingerichtet?" (wie ACTi E-E5). Er startet die Bruecke nur mit dem Haken
 * "Befehle ueber MQTT annehmen", haelt sie sonst an, startet nie waehrend eines
 * Updates und sendet <mqtt_praefix>/status/befehle (1/0/-1, nie retained).
 * befehlsbruecke.sh schliesst geerbte Deskriptoren (cron.lock) selbst. */
if (mg_bruecke_laden()) {
    list($mg_bbs, $mg_bbt) = bb_waechter();
    if ($mg_bbs === -1) {
        mg_log_if_changed('befehlsbruecke', 'Befehlsbruecke: ' . $mg_bbt);
    }
}

/* a2 (Verbesserungsbau 01.10.2026): hat dieses Plugin einen Gateway-Container
 * angelegt (Merkdatei) und weicht der Broker-Zugang, den es jetzt nimmt, von
 * dem ab, mit dem der Container angelegt wurde (etwa ein geaendertes Kennwort
 * des LoxBerry-Brokers), geht EINE LoxBerry-Benachrichtigung hinaus - bis
 * 1.1.20 sagte es nur die gelbe Ampel im Reiter. Vor der Frage
 * "eingerichtet?": der Container kann vor dem ersten Fahrzeug da sein. */
mg_broker_wechsel_pruefen();

$cfg = mg_config();
if (trim((string) $cfg['saic_user']) === '' || mg_fahrzeug_anzahl($cfg) === 0) {
    exit;   // noch nicht eingerichtet
}

/* Die Abo-Datei des MQTT-Gateways nachfuehren (seit 1.1.18, Befund M8): ein
 * Update legt die mitgelieferte mit dem Vorgabepraefix zurueck. Geschrieben
 * wird nur, wenn sie abweicht - wie beim Speichern im Reiter MQTT. */
mg_abo_datei($cfg['mqtt_praefix'], true);

/* Gruppe 39 (1.1.28, 2.4): hoechstens einen abgekoppelten Leser starten - nie
 * darauf warten; dieser Lauf nimmt den letzten Stand aus haus_quellen.json. */
mg_haus_abnehmer_starten($cfg, __FILE__);

list($ok, $info) = mg_snapshot(3);
/* Das Lebenszeichen geht bei JEDEM Lauf hinaus, am Aenderungsfilter vorbei
 * und fluechtig (seit 1.1.18, Befund M3) - auch wenn das Einlesen scheitert:
 * es sagt, dass der Takt laeuft, nicht, dass die Werte stimmen. */
mg_mqtt_lebenszeichen($cfg, $ok ? 1 : 0);   // Gruppe 39: dazu status/ok (2.1)
if (!$ok) {
    mg_log_if_changed('verbindung', 'keine Werte vom Broker (' . $info . ')');
    /* Fehlerzweig: je Fahrzeug ok=0, fluechtig; die Zustaende bleiben stehen.
     * Bis 1.1.17 ging hier nichts hinaus, und ok hielt im Miniserver die
     * letzte 1. */
    mg_mqtt_ok_null($cfg);
    exit;
}
mg_log_if_changed('verbindung', 'Broker erreichbar (' . $info . ')');
/* A1 (seit 1.1.19): die Anmeldung des Gateways bei MG als Zustand
 * <mqtt_praefix>/gateway/anmeldung - 1, 0 oder "-", bei jedem Takt nach einem
 * gelungenen Einlesen und FLUECHTIG wie ok: eine Aussage des Gateways ueber
 * sich selbst ist eine Dienstaussage (Entscheidung des Hausherrn 30.09.2026,
 * Regeln/07 vom 19.09.2026). Scheitert das Einlesen, geht nichts hinaus. */
mg_mqtt_anmeldung_senden($cfg);

foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
    $st = mg_state($nr);
    mg_log_if_changed('zustand' . $nr,
        $fz['name'] . ': SoC=' . $st['SOC'] . ' % Ziel=' . $st['ZIEL']
        . ' laedt=' . $st['LAEDT'] . ' Stecker=' . $st['STECKER']
        . ' Reichweite=' . $st['REICHWEITE'] . ' erreichbar=' . $st['ERREICHBAR']);

    // mg_check_events() gibt den VORHERIGEN Stand mit zurueck - die Ladung
    // braucht ihn, und ein zweites Einlesen derselben Datei waere eine
    // zweite Stelle, die dasselbe liest.
    list($melden, $vorher) = mg_check_events($st, $nr);
    // Gruppe 39 (1.1.28, 3.1): die Mitschrift der laufenden Ladung (Preis je Minute), dann der Abschluss.
    mg_ladung_mitschreiben($st, $nr, $cfg);
    mg_ladung_pruefen($st, $vorher, $nr);
    $st = mg_state($nr);   // die neue Ladung fuer ladung_kosten/kwh/preis
    mg_mqtt_senden($nr, $st);
}

foreach (mg_automatik() as $m) {
    // mg_automatik() protokolliert selbst; hier bleibt nichts zu tun.
    unset($m);
}
