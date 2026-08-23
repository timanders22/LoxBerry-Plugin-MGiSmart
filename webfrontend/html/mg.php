<?php
/**
 * MG iSmart - Endpunkt fuer Loxone und Drittsoftware
 *
 * LESEND (ohne Merkwort)
 *   (ohne Parameter)        MG;OK=..;SOC=..;ZIEL=..;...
 *   ?zeile=laden|ort|technik  kuerzere Zeile mit eigenem Abfragetakt
 *   ?fahrzeug=2             das zweite eingerichtete Fahrzeug
 *   ?json=1                 kompletter Zustand als JSON
 *
 * MIT MERKWORT (sie tun etwas, oder sie geben mehr preis als eine Statuszeile)
 *   ?cmd=ziel&prozent=80&token=T   Befehl ans Fahrzeug
 *   ?cmd=ladeplan&von=22:00&bis=06:00&modus=until_configured_soc&token=T
 *   ?refresh=1&token=T             Werte sofort neu einlesen
 *   ?ptest=1&token=T               Test-Pushnachricht ausloesen
 *   ?debug=1&token=T               alle empfangenen MQTT-Themen
 *   ?ladungen=1&token=T            die mitgeschriebenen Ladevorgaenge
 *   ?selftest=1&token=T            nur pruefen, ob das Merkwort stimmt
 *
 * WARUM ?debug SEIT 1.1.0 EIN MERKWORT BRAUCHT
 * Die Begruendung "lesende Abrufe kosten nichts und verraten nichts
 * Schaltbares" trifft fuer die Statuszeile zu. Fuer ?debug=1 nicht: dort
 * stehen der iSMART-Benutzername, die Fahrzeug-Kennung und - seit die
 * Heimzone gebaut ist - die Standortthemen des Fahrzeugs. Wer die
 * LoxBerry-Oberflaeche im Netz erreicht, erreichte das bis 1.0.8 ohne ein
 * einziges Zugangswort.
 */

require_once __DIR__ . '/mg_lib.php';

/**
 * Einen GET-Parameter als Zeichenkette holen.
 *
 * WARUM NICHT UNMITTELBAR $_GET['x']:
 * "?token[]=x" liefert ein Feld. Unter PHP 7.4 war die Umwandlung eine
 * E_NOTICE, die das error_reporting verschluckte; unter PHP 8 ist sie eine
 * Warning - und die wird AUSGEGEBEN, bevor header() und
 * http_response_code(403) laufen. Gemessen unter 8.4:
 *   Warning: Array to string conversion ... mg.php on line 42
 *   Warning: Cannot modify header information - headers already sent
 *   Warning: http_response_code(): Cannot set response code
 * Die Abweisung ging damit als HTTP 200 hinaus. Ein Feld ist hier nie
 * gueltig, also wird es abgewiesen, nicht umgewandelt.
 */
function mg_get($name, $default = '')
{
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return (string) $_GET[$name];
}

function mg_gesetzt($name)
{
    return isset($_GET[$name]);
}

/** Nur Text ausgeben, wenn noch nichts gesendet wurde. */
function mg_kopf($typ = 'text/plain')
{
    if (!headers_sent()) {
        header('Content-Type: ' . $typ . '; charset=utf-8');
        header('Cache-Control: no-store');
    }
}

function mg_abweisen($code, $text)
{
    mg_kopf();
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo $text . "\n";
    exit;
}

/**
 * Merkwort pruefen. Wird von allen Aufrufen benutzt, die etwas TUN oder mehr
 * preisgeben als die Statuszeile.
 *
 * Verglichen wird mit hash_equals: ein einfaches == liesse sich ueber die
 * Antwortzeit Zeichen fuer Zeichen erraten. Fail-closed: ohne gesetztes
 * Merkwort wird NICHT durchgelassen - ein leeres Soll, das alles annimmt,
 * waere die gefaehrlichste Variante.
 */
function mg_token_pruefen($kopf = 'MG')
{
    $cfg = mg_config();
    $soll = (string) $cfg['aktionstoken'];
    $ist = mg_get('token');
    if ($soll === '') {
        mg_abweisen(403, $kopf . ';OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    if (!hash_equals($soll, $ist)) {
        mg_abweisen(403, $kopf . ';OK=0;ERR=TOKEN');
    }
}

/* ---------------- Selbsttest ----------------
 *
 * Ein Token muss sich pruefen lassen, OHNE dass etwas passiert. Sonst gibt
 * es nur zwei schlechte Moeglichkeiten: entweder man schaltet wirklich -
 * dann geht Licht und Hupe an -, oder man erfaehrt nie, ob die Adresse im
 * Miniserver noch stimmt. Kein Geraetekontakt, kein Schreibzugriff.
 */
if (mg_gesetzt('selftest')) {
    mg_token_pruefen('SELFTEST');
    mg_kopf();
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Welches Fahrzeug ---------------- */
$mg_cfg = mg_config();
$mg_anzahl = mg_fahrzeug_anzahl($mg_cfg);
$mg_nr = (int) mg_get('fahrzeug', '1');
if ($mg_nr < 1) {
    $mg_nr = 1;
}
if ($mg_anzahl > 0 && $mg_nr > $mg_anzahl) {
    mg_abweisen(404, 'MG;OK=0;ERR=FAHRZEUG_UNBEKANNT');
}

/* ---------------- Welche Zeile ---------------- */
$mg_zeile = mg_get('zeile', 'mg');
if (!isset(mg_zeilen()[$mg_zeile])) {
    if ($mg_zeile !== 'mg' && $mg_zeile !== '') {
        mg_abweisen(400, 'MG;OK=0;ERR=ZEILE_UNBEKANNT');
    }
    $mg_zeile = 'mg';
}

/* ---------------- Test-Pushnachricht ---------------- */
if (mg_gesetzt('ptest')) {
    mg_token_pruefen('PTEST');
    mg_ptest_ausloesen();
    mg_kopf();
    echo "PTEST;OK=1;DAUER=300\n";
    echo mg_t('ENDPUNKT.PTEST_HINWEIS') . "\n";
    exit;
}

/* ---------------- Ladevorgaenge ---------------- */
if (mg_gesetzt('ladungen')) {
    mg_token_pruefen('LADUNGEN');
    mg_kopf('application/json');
    echo json_encode(array('ladungen' => mg_ladungen_lesen(200)),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

/* ---------------- Schaltende Aufrufe ---------------- */
$mg_ergebnis = '';
if (mg_gesetzt('cmd')) {
    mg_token_pruefen('CMD');
    /* Der Befehlsname wird ABGEWIESEN, wenn er nicht ins Muster passt - nicht
     * zurechtgebogen. Bis 1.0.8 machte preg_replace aus "ZIEL_80" ein "_80"
     * und aus "rm -rf" ein "rmrf"; eingeschleust wurde dabei nie etwas, aber
     * die Antwort lautete dann "Unbekannter Befehl: _80" statt "so schreibt
     * man das nicht". */
    $mg_befehl = mg_get('cmd');
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $mg_befehl)) {
        mg_abweisen(400, 'CMD;OK=0;ERR=BEFEHL_UNGUELTIG');
    }
    /* Alle bekannten Zusatzwerte einsammeln und als Feld weiterreichen.
      * mg_befehl_aufloesen() nimmt sich daraus, was DIESER Befehl braucht -
      * ein Zahlenwert bei ziel und strom, zwei Uhrzeiten und ein Modus beim
      * Ladeplan. Bis 1.1.0 wurde nur der erste vorhandene genommen; damit
      * waere ein Befehl mit zwei Parametern nicht abbildbar gewesen. */
    $mg_wert = array();
    foreach (array('prozent', 'ampere', 'temp', 'stufe', 'modus', 'sekunden',
                   'wert', 'von', 'bis') as $mg_z) {
        if (mg_gesetzt($mg_z)) {
            $mg_wert[$mg_z] = mg_get($mg_z);
        }
    }
    list($mg_ok, $mg_info, $mg_code) = mg_send($mg_befehl, $mg_wert, $mg_nr);
    $mg_ergebnis = 'CMD;OK=' . (int) $mg_ok . ';CODE=' . $mg_code
                 . ';INFO=' . str_replace(array(';', "\n", "\r"), ' ', $mg_info);
    if (!$mg_ok && !headers_sent()) {
        // 409: der Aufruf war richtig geformt, ging aber nicht durch.
        http_response_code(in_array($mg_code, array('UNBEKANNT', 'WERT_FEHLT',
            'WERT_UNZULAESSIG', 'WERT_AUSSER_BEREICH'), true) ? 400 : 409);
    }
}

if (mg_gesetzt('refresh')) {
    mg_token_pruefen('REFRESH');
    list($mg_ok, $mg_info) = mg_snapshot(4);
    $mg_ergebnis = 'REFRESH;OK=' . (int) $mg_ok . ';INFO='
                 . str_replace(array(';', "\n", "\r"), ' ', $mg_info);
}

$mg_st = mg_state($mg_nr);

/* ---------------- Technische Auskunft ---------------- */
if (mg_gesetzt('debug')) {
    mg_token_pruefen('DEBUG');
    mg_kopf();
    $mg_roh = mg_raw();
    echo 'DEBUG  Momentaufnahme: '
       . ($mg_roh['zeit'] !== '' ? substr($mg_roh['zeit'], 0, 19) : '-')
       . '  Themen: ' . (int) $mg_roh['anzahl'] . "\n";
    echo 'Fahrzeuge: ' . $mg_anzahl . "\n";
    foreach (mg_fahrzeuge($mg_cfg) as $mg_i => $mg_f) {
        echo '  [' . $mg_i . '] ' . $mg_f['name'] . '  '
           . (mg_base_topic($mg_i) !== '' ? mg_base_topic($mg_i) : '-')
           . '  (' . mg_themen_anzahl($mg_i) . " Themen)\n";
    }
    echo 'mosquitto_sub: ' . (mg_has_mosquitto() ? 'ja' : 'NEIN - Paket mosquitto-clients fehlt') . "\n";
    echo 'mbstring: ' . (function_exists('mb_substr') ? 'ja' : 'nein (substr wird benutzt)') . "\n\n";
    ksort($mg_roh['werte']);
    foreach ($mg_roh['werte'] as $mg_t2 => $mg_v) {
        printf("  %-72s %s\n", $mg_t2, mg_kuerzen($mg_v, 60));
    }
    echo "\n";
    foreach (mg_zeilen() as $mg_zk => $mg_zi) {
        echo mg_line($mg_zk, $mg_nr, $mg_st);
    }
    exit;
}

/* ---------------- JSON ---------------- */
if (mg_gesetzt('json')) {
    mg_kopf('application/json');
    $mg_aus = array();
    foreach (mg_felder() as $mg_name => $mg_info) {
        $mg_aus[$mg_name] = isset($mg_st[$mg_name]) ? $mg_st[$mg_name] : -1;
    }
    $mg_fz = mg_fahrzeuge($mg_cfg);
    $mg_aus['_fahrzeug'] = $mg_nr;
    $mg_aus['_name'] = isset($mg_fz[$mg_nr]) ? $mg_fz[$mg_nr]['name'] : '';
    $mg_aus['_zeit'] = $mg_st['_zeit'];
    $mg_aus['_meldung'] = mg_push_text($mg_nr);
    $mg_aus['_klima'] = $mg_st['_klimatext'];
    $mg_aus['_stromgrenze'] = $mg_st['_grenze'];
    $mg_aus['_fehlertext'] = $mg_st['_fehlertext'];
    $mg_aus['_tueren'] = $mg_st['_tueren'];
    $mg_aus['_fenster'] = $mg_st['_fenster'];
    $mg_aus['_ladeplan'] = $mg_st['_ladeplan'];
    $mg_aus['_heizplan'] = $mg_st['_heizplan'];
    $mg_aus['_abbruchgrund'] = $mg_st['_abbruchgrund'];
    $mg_aus['_fahrzeugmeldung'] = $mg_st['_fahrzeugmeldung'];
    echo json_encode($mg_aus,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

/* ---------------- Die Statuszeile ---------------- */
mg_kopf();
if ($mg_ergebnis !== '') {
    echo $mg_ergebnis . "\n";
}
echo mg_line($mg_zeile, $mg_nr, $mg_st);
