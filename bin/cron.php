<?php
/**
 * Wird jede Minute vom Cron aufgerufen: liest die zuletzt vom SAIC-MQTT-
 * Gateway veroeffentlichten Werte ein und prueft auf meldenswerte Ereignisse.
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
if (trim((string) $cfg['saic_user']) === '' || trim((string) $cfg['vin']) === '') {
    exit;   // noch nicht eingerichtet
}
list($ok, $info) = mg_snapshot(3);
if (!$ok) {
    mg_log_if_changed('verbindung', 'keine Werte vom Broker (' . $info . ')');
    exit;
}
mg_log_if_changed('verbindung', 'Broker erreichbar (' . $info . ')');
$st = mg_state();
mg_log_if_changed('zustand', 'SoC=' . $st['soc'] . ' % Ziel=' . $st['soc_ziel']
    . ' laedt=' . $st['laedt'] . ' Stecker=' . $st['stecker'] . ' Reichweite=' . $st['reichweite']);
mg_check_events($st);
