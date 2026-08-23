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
$mg_lib = '';
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/mg_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/mg_lib.php',
    dirname(__DIR__) . '/webfrontend/html/mg_lib.php',
) as $mg_kand) {
    if (is_file($mg_kand)) { $mg_lib = $mg_kand; break; }
}
if ($mg_lib === '') {
    fwrite(STDERR, "mg_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $mg_lib;

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
