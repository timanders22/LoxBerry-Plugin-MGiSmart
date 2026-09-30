<?php
/**
 * MG iSmart - legt den SAIC-Gateway-Container im Hintergrund an (seit 1.1.18).
 *
 * Gestartet von der Oberflaeche (Reiter "Gateway einrichten", Knopf "Gateway
 * anlegen und starten") ueber mg_gw_vorgang_starten(). Im Hintergrund, weil
 * docker run beim ersten Mal das Abbild holt - das dauert, und die Seite soll
 * nicht so lange warten (Entscheidung 7, Bauliste G2). Der Vorgang schreibt
 * seinen Stand nach data/plugins/<ordner>/gateway_vorgang.json; die Seite
 * zeigt "wird angelegt ... seit N s" und laedt sich neu, solange er laeuft.
 *
 * Kein Takt und kein Hakenskript ruft diese Datei (G6). Die Kennwoerter
 * liest sie aus mg.json; auf der Befehlszeile steht nur der Auftrag.
 *
 * Aufruf: php gateway_vorgang.php anlegen
 */
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

$mg_p = mg_paths();
if ($mg_p['lbhome'] === '') {
    fwrite(STDERR, "gateway_vorgang.php: keine LoxBerry-Installation gefunden - nichts angelegt.\n");
    exit(1);
}
/* Laufzeitfehler in eine Datei, nicht auf die Fehlerausgabe: die geht beim
 * Start nach /dev/null (Regeln/03, "dritte Protokollart"). */
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', dirname($mg_p['log']) . '/gateway_vorgang.err');

$mg_auftrag = isset($argv[1]) ? (string) $argv[1] : '';
if ($mg_auftrag !== 'anlegen' || count($argv) !== 2) {
    fwrite(STDERR, "Unbekannter Auftrag - erlaubt ist nur: anlegen\n");
    exit(2);
}

$mg_v = mg_json_lesen(mg_gw_vorgang_datei());
$mg_start = isset($mg_v['start']) ? (int) $mg_v['start'] : time();
mg_gw_vorgang_schreiben(array('vorgang' => 'anlegen', 'zustand' => 'laeuft', 'pid' => getmypid(),
    'start' => $mg_start, 'schritt' => 'anlegen', 'meldung' => ''));
list($mg_ok, $mg_text, $mg_id) = mg_gw_anlegen();
/* Nacharbeit 30.09. (N1): angelegt ist angelegt - auch wenn der Container
 * nicht gleich laeuft. Die Merkdatei sagt uninstall, dass es ohne docker
 * warnen muss. Atomar, Rechte vor dem Inhalt (mg_write_atomic). */
if ($mg_id !== '') {
    if (!mg_write_atomic(mg_gw_merkdatei(), $mg_id . "\n" . date('c') . "\n", 0600)) {
        mg_log('Gateway anlegen: die Merkdatei ' . mg_gw_merkdatei() . ' liess sich nicht schreiben.');
    }
}
mg_gw_vorgang_schreiben(array('vorgang' => 'anlegen', 'zustand' => $mg_ok ? 'fertig' : 'fehler',
    'pid' => getmypid(), 'start' => $mg_start, 'ende' => time(), 'id' => $mg_id,
    'meldung' => $mg_text));
mg_log('Gateway anlegen: ' . ($mg_ok ? 'fertig' : 'gescheitert') . ' - ' . $mg_text);
exit($mg_ok ? 0 : 1);
