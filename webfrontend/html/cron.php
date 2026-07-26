<?php
/**
 * Wird jede Minute vom Cron aufgerufen: liest die zuletzt vom SAIC-MQTT-
 * Gateway veroeffentlichten Werte ein und prueft auf meldenswerte Ereignisse.
 */
require_once __DIR__ . '/mg_lib.php';

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
