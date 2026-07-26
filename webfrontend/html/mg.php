<?php
/**
 * MG iSmart - Endpunkt fuer Loxone und Drittsoftware
 *
 *   (ohne Parameter) -> MG;OK=..;SOC=..;ZIEL=..;REICHWEITE=..;LAEDT=..;STECKER=..;...
 *   ?cmd=ziel_80     -> Befehl ans Fahrzeug (erlaubte Liste siehe Oberflaeche)
 *   ?json=1          -> kompletter Zustand als JSON
 *   ?debug=1         -> alle empfangenen MQTT-Themen im Klartext
 *   ?refresh=1       -> Werte sofort neu einlesen (Momentaufnahme)
 *   ?ptest=1         -> Test-Pushnachricht ausloesen (PTEST=1 fuer 5 Minuten)
 */

require_once __DIR__ . '/mg_lib.php';

if (isset($_GET['ptest'])) {
    $p = mg_paths();
    @mkdir($p['tmp'], 0775, true);
    @file_put_contents($p['tmp'] . '/ptest', time());
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
    list($mg_ok, $mg_info) = mg_send(preg_replace('/[^a-z0-9_]/', '', (string) $_GET['cmd']));
    $mg_ergebnis = 'CMD;OK=' . $mg_ok . ';INFO=' . $mg_info;
    // Nach einem Befehl lohnt sich ein frischer Blick
    if ($mg_ok) {
        sleep(2);
        mg_snapshot(3);
    }
}
if (isset($_GET['refresh'])) {
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
