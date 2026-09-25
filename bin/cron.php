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

$cfg = mg_config();
if (trim((string) $cfg['saic_user']) === '' || mg_fahrzeug_anzahl($cfg) === 0) {
    exit;   // noch nicht eingerichtet
}

list($ok, $info) = mg_snapshot(3);
if (!$ok) {
    mg_log_if_changed('verbindung', 'keine Werte vom Broker (' . $info . ')');
    exit;
}
mg_log_if_changed('verbindung', 'Broker erreichbar (' . $info . ')');

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
    mg_ladung_pruefen($st, $vorher, $nr);
    mg_mqtt_senden($nr, $st);
}

foreach (mg_automatik() as $m) {
    // mg_automatik() protokolliert selbst; hier bleibt nichts zu tun.
    unset($m);
}
