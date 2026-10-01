<?php
/**
 * MG iSmart - legt den SAIC-Gateway-Container im Hintergrund an (seit 1.1.18),
 * aktualisiert ihn (1.1.19), startet ihn neu oder entfernt ihn (a1,
 * Verbesserungsbau 01.10.2026).
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
 * Seit 1.1.19 (A4) auch "aktualisieren": docker pull, und nur bei neuem
 * Abbild den eigenen Container mit denselben Einstellungen neu anlegen
 * (mg_gw_aktualisieren()). Auch das nur auf Knopfdruck.
 *
 * a1 (Verbesserungsbau 01.10.2026): auch "neustart" und "entfernen" laufen
 * hier - bis 1.1.20 liefen sie im Seitenaufbau, und ein haengendes Docker
 * hielt die Seite bis etwa 2 min auf. Die Liste der Auftraege steht einmal in
 * mg_gw_auftraege(). Neu anlegen (auch beim Aktualisieren) haelt den alten
 * Container zurueck, bis der neue laeuft (mg_gw_anlegen()).
 *
 * Aufruf: php gateway_vorgang.php anlegen|aktualisieren|neustart|entfernen
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
if (!in_array($mg_auftrag, mg_gw_auftraege(), true) || count($argv) !== 2) {
    fwrite(STDERR, "Unbekannter Auftrag - erlaubt sind nur: " . implode(', ', mg_gw_auftraege()) . "\n");
    exit(2);
}

$mg_v = mg_json_lesen(mg_gw_vorgang_datei());
$mg_start = isset($mg_v['start']) ? (int) $mg_v['start'] : time();
mg_gw_vorgang_schreiben(array('vorgang' => $mg_auftrag, 'zustand' => 'laeuft', 'pid' => getmypid(),
    'start' => $mg_start, 'schritt' => $mg_auftrag, 'meldung' => ''));
if ($mg_auftrag === 'aktualisieren') {
    list($mg_ok, $mg_text, $mg_id) = mg_gw_aktualisieren();
} elseif ($mg_auftrag === 'neustart') {
    // a1: nur der eigene Container, Reste eines frueheren Neuanlegens nie.
    list($mg_ok, $mg_text) = mg_gw_neustart();
    $mg_id = '';
} elseif ($mg_auftrag === 'entfernen') {
    // a1: mg_gw_entfernen() loescht die Merkdatei erst nach bestaetigtem Entfernen.
    list($mg_ok, $mg_text) = mg_gw_entfernen();
    $mg_id = '';
} else {
    list($mg_ok, $mg_text, $mg_id) = mg_gw_anlegen();
}
/* Nacharbeit 30.09. (N1): angelegt ist angelegt - auch wenn der Container
 * nicht gleich laeuft. Die Merkdatei sagt uninstall, dass es ohne docker
 * warnen muss. Atomar, Rechte vor dem Inhalt (mg_write_atomic). */
if ($mg_id !== '') {
    /* a2 (Verbesserungsbau 01.10.2026): dritte Zeile brokerhash= aus dem
     * Label des Containers, mit dem er WIRKLICH laeuft (nach einem
     * gescheiterten Neuanlegen ist das der alte) - der Takt vergleicht daran
     * den Broker-Zugang, ohne docker zu fragen (mg_broker_wechsel_pruefen). */
    $mg_info = mg_gw_inspect($mg_id);
    $mg_hash = ($mg_info !== null && mg_gw_ist_eigen($mg_info)) ? mg_gw_kurz($mg_info)['brokerhash'] : '';
    if (!mg_gw_merk_schreiben($mg_id, $mg_hash)) {
        mg_log('Gateway anlegen: die Merkdatei ' . mg_gw_merkdatei() . ' liess sich nicht schreiben.');
    }
}
mg_gw_vorgang_schreiben(array('vorgang' => $mg_auftrag, 'zustand' => $mg_ok ? 'fertig' : 'fehler',
    'pid' => getmypid(), 'start' => $mg_start, 'ende' => time(), 'id' => $mg_id,
    'meldung' => $mg_text));
mg_log('Gateway ' . $mg_auftrag . ': ' . ($mg_ok ? 'fertig' : 'gescheitert') . ' - ' . $mg_text);
exit($mg_ok ? 0 : 1);
