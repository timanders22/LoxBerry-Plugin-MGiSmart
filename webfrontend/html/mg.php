<?php
/**
 * MG iSmart - Endpunkt fuer Loxone und Drittsoftware
 *
 *   (ohne Parameter) -> MG;OK=..;SOC=..;ZIEL=..;REICHWEITE=..;LAEDT=..;STECKER=..;...
 *   ?cmd=ziel_80&token=T -> Befehl ans Fahrzeug (erlaubte Liste siehe Oberflaeche)
 *                       Schaltender Aufruf - erfordert das Merkwort aus dem
 *                       Reiter "Einbindung in Loxone", sonst HTTP 403.
 *   ?json=1          -> kompletter Zustand als JSON
 *   ?debug=1         -> alle empfangenen MQTT-Themen im Klartext
 *
 * Ebenfalls mit Merkwort (sie tun etwas, sie lesen nicht nur):
 *   ?refresh=1&token=T -> Werte sofort neu einlesen (dauert bis zu 4 s)
 *   ?ptest=1&token=T   -> Test-Pushnachricht ausloesen (PTEST=1 fuer 5 Minuten)
 */

require_once __DIR__ . '/mg_lib.php';

/**
 * Merkwort pruefen. Wird von allen Aufrufen benutzt, die etwas TUN -
 * nicht nur von ?cmd=.
 *
 * Bis 1.0.2 galt die Pruefung allein fuer ?cmd=. Die Begruendung im Kommentar
 * lautete, lesende Abrufe kosteten nichts. Fuer ?status stimmt das - fuer
 * diese beiden nicht:
 *
 *   ?refresh=1  startet mosquitto_sub mit -W 4. Der Aufruf haelt einen
 *               PHP-Arbeiter VIER SEKUNDEN fest und erzeugt einen Prozess.
 *               Wer das im Sekundentakt aufruft, legt die Weboberflaeche des
 *               LoxBerry lahm, ohne ein einziges Zugangswort zu kennen.
 *   ?ptest=1    schreibt eine Datei und loest fuer fuenf Minuten eine
 *               Push-Nachricht aus. Das ist kein Lesen, das ist ein Klingeln
 *               am Telefon des Besitzers.
 *
 * Beides bleibt erreichbar, aber nur mit demselben Merkwort wie ?cmd=.
 * Fail-closed: ohne gesetztes Merkwort wird nicht durchgelassen.
 */
function mg_token_pruefen()
{
    $cfg = mg_config();
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($soll === '' || !hash_equals($soll, $ist)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(403);
        echo "MG;OK=0;ERR=TOKEN\n";
        exit;
    }
}

if (isset($_GET['ptest'])) {
    mg_token_pruefen();
    $p = mg_paths();
    @mkdir($p['tmp'], 0775, true);
    mg_write_atomic($p['tmp'] . '/ptest', (string) time());
    header('Content-Type: text/plain; charset=utf-8');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt zyklisch - die Push-Nachricht kommt innerhalb von 5 Minuten,\n"
       . "sofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 4) verdrahtet ist.\n";
    exit;
}

function mg_ptest_active()
{
    $f = mg_paths()['tmp'] . '/ptest';
    if (!is_file($f)) {
        return 0;
    }
    if (time() - (int) file_get_contents($f) > 300) {
        @unlink($f);
        return 0;
    }
    return 1;
}

$mg_ergebnis = '';
if (isset($_GET['cmd'])) {
    /* Merkwort-Pruefung fuer den SCHALTENDEN Aufruf.
     *
     * Diese Datei liegt im unangemeldeten Bereich, damit Loxone sie ohne
     * Zugangsdaten erreicht. Ohne diese Pruefung koennte jeder im Netz, der
     * die Weboberflaeche erreicht, Standklima einschalten oder ueber
     * "Auto finden" Licht und Hupe ausloesen.
     *
     * Verglichen wird mit hash_equals: ein einfaches == liesse sich ueber
     * die Antwortzeit Zeichen fuer Zeichen erraten.
     *
     * Fail-closed: ist noch kein Merkwort gesetzt, wird NICHT durchgelassen.
     * Ein leeres Soll, das alles annimmt, waere die gefaehrlichste Variante.
     */
    mg_token_pruefen();
    list($mg_ok, $mg_info) = mg_send(preg_replace('/[^a-z0-9_]/', '', (string) $_GET['cmd']));
    $mg_ergebnis = 'CMD;OK=' . $mg_ok . ';INFO=' . $mg_info;
    // Nach einem Befehl lohnt sich ein frischer Blick
    if ($mg_ok) {
        sleep(2);
        mg_snapshot(3);
    }
}
if (isset($_GET['refresh'])) {
    mg_token_pruefen();
    list($mg_ok, $mg_info) = mg_snapshot(4);
    $mg_ergebnis = 'REFRESH;OK=' . $mg_ok . ';INFO=' . $mg_info;
}

$mg_st = mg_state();

if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $roh = mg_raw();
    echo 'DEBUG  Momentaufnahme: ' . ($roh['zeit'] !== '' ? substr($roh['zeit'], 0, 19) : 'noch keine')
       . '  Themen: ' . (int) $roh['anzahl'] . "\n";
    echo 'Basis-Topic: ' . (mg_base_topic() !== '' ? mg_base_topic() : '(Benutzer/VIN fehlen)') . "\n";
    echo 'mosquitto_sub vorhanden: ' . (mg_has_mosquitto() ? 'ja' : 'NEIN - Paket mosquitto-clients fehlt') . "\n\n";
    ksort($roh['werte']);
    foreach ($roh['werte'] as $t => $v) {
        printf("  %-70s %s\n", $t, mb_substr((string) $v, 0, 60));
    }
    echo "\n";
    echo mg_line($mg_st);
    exit;
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $mg_st['push_aktiv'] = mg_push_active();
    $mg_st['meldung'] = mg_push_text();
    $mg_st['ptest'] = mg_ptest_active();
    echo json_encode($mg_st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
if ($mg_ergebnis !== '') {
    echo $mg_ergebnis . "\n";
}
echo rtrim(mg_line($mg_st), "\n")
   . sprintf(";PUSH=%d;PUSHAKTIV=%d;PTEST=%d\n",
       empty(mg_config()['notify']['push']) ? 0 : 1, mg_push_active(), mg_ptest_active());
