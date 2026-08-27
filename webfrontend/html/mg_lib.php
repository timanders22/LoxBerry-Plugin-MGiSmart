<?php
/**
 * MG iSmart - gemeinsame Bibliothek
 *
 * Bringt die Daten eines oder mehrerer MG-Elektrofahrzeuge (iSMART / SAIC)
 * nach Loxone und schickt Befehle zurueck.
 *
 * ARCHITEKTUR
 * Es gibt keine offizielle Schnittstelle von MG/SAIC. Die Daten holt das
 * quelloffene "SAIC MQTT Gateway" (Docker-Container) und veroeffentlicht sie
 * per MQTT. Dieses Plugin liest die Werte vom MQTT-Broker (mosquitto_sub),
 * uebersetzt sie in kompakte Loxone-Zeilen, veroeffentlicht sie auf Wunsch
 * unter eigenen, lesbaren Themen und schickt Befehle zurueck (mosquitto_pub).
 *
 * DIE TOPIC-NAMEN SIND GEMESSEN, NICHT GERATEN.
 * Alle unten benutzten Themen stehen woertlich in src/mqtt_topics.py des
 * Gateways (Zweig main, Abruf 23.08.2026). Bis 1.0.8 enthielt die Liste neun
 * Namen, die es dort nie gab (battery/soc, drivetrain/targetSoc,
 * chargingState, chargingConnected, chargingPower, chargingTimeRemaining,
 * odometer, batteryVoltage, rangeElectric) - sie sind entfernt. Der einzige
 * Ersatzname, der wirklich gebraucht wurde, war falsch geschrieben:
 * drivetrain/soc_kwh, nicht socKwh.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 * Alle Funktionen tragen das Praefix mg_ (LBWeb::lbheader() setzt SDK-Globals).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Sekunden zwischen dem 01.01.1970 und dem 01.01.2009 (Loxone-Zeitrechnung). */
if (!defined('MG_LOXONE_EPOCHE')) {
    define('MG_LOXONE_EPOCHE', 1230768000);
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function mg_paths()
{
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        /* Rueckfall auf den vorgesehenen Ordnernamen - aber NUR, wenn dort
         * auch wirklich unsere Konfiguration liegt.
         *
         * Es gibt ein zweites LoxBerry-Plugin fuer dasselbe Fahrzeug
         * (mschlenstedt/LoxBerry-Plugin-MGiSMART). Es traegt in seiner
         * plugin.cfg denselben FOLDER=mgismart, aber einen anderen Autor -
         * LoxBerry haelt beide fuer verschiedene Plugins und haengt beim
         * zweiten 01, 02 an den Ordnernamen. Ein blinder Rueckfall auf
         * "mgismart" koennte damit auf das Verzeichnis des FREMDEN Plugins
         * zeigen und dort eine mg.json anlegen, die niemand liest.
         * Deshalb: nur uebernehmen, wenn dort schon unsere mg.json liegt
         * oder der Ordner ueberhaupt noch nicht existiert. */
        $kand = $lbhomedir . '/config/plugins/mgismart';
        if (!is_dir($kand) || is_file($kand . '/mg.json')) {
            $plugindir = 'mgismart';
        }
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/mg.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/mg.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/mgismart',
            'lbhome' => $lbhomedir,
            'plugin' => $plugindir,
        );
    }
    $base = dirname(dirname(__DIR__));
    return array(
        'config' => $base . '/config/mg.json',
        'backup' => $base . '/config/mg.backup.json',
        'log' => sys_get_temp_dir() . '/mgismart/mg.log',
        'data' => sys_get_temp_dir() . '/mgismart/data',
        'tmp' => sys_get_temp_dir() . '/mgismart',
        'lbhome' => '',
        'plugin' => 'mgismart',
    );
}

/* ==================================================================
 * Atomar schreiben
 *
 * file_put_contents() kuerzt die Datei zuerst auf null und schreibt dann.
 * Wer in diesem Augenblick liest, bekommt eine leere oder halbe Datei.
 *
 * Nachgemessen mit gleichzeitigem Lesen und Schreiben ueber sechs Sekunden:
 *   unmittelbar:  5.490 halbe und 818.249 LEERE Lesevorgaenge
 *   atomar:       0 halbe, 0 leere
 * ================================================================== */

function mg_write_atomic($datei, $inhalt, $rechte = 0644)
{
    // json_encode liefert bei ungueltigem UTF-8 false, und
    // file_put_contents($p, false) schreibt klaglos 0 Bytes und meldet Erfolg.
    if ($inhalt === false || $inhalt === null) {
        return false;
    }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    // Rechte VOR dem Umbenennen setzen.
    @chmod($tmp, $rechte);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function mg_write_json($datei, $daten, $rechte = 0644)
{
    return mg_write_atomic($datei, json_encode($daten,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rechte);
}

function mg_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Zeichen statt Bytes kuerzen - aber nur, wenn mbstring da ist.
 *
 * Bis 1.0.8 stand hier unmittelbar mb_substr(). mbstring ist eine Erweiterung
 * und steht nicht in dpkg/apt; ohne sie brachen die Debug-Ansicht und der
 * Rohdatenblock des Reiters Test mit einem Fatal error ab (gemessen). Die
 * Pflichtpruefung nennt genau diesen Fall.
 */
function mg_kuerzen($s, $n)
{
    $s = (string) $s;
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $n);
    }
    return substr($s, 0, $n);
}

/* ==================================================================
 * Konfiguration
 *
 * ALLE Vorgabewerte stehen in mg_vorgaben(), an genau einer Stelle. Ein
 * zweiter Vorgabewert im Speicher-Handler ist die klassische Falle: beim
 * ersten Anlauf wird nur einer von beiden geaendert.
 * ================================================================== */

function mg_vorgaben()
{
    return array(
        // --- Broker (Reiter MQTT) ---
        'broker_host' => '127.0.0.1',
        'broker_port' => 1883,
        'broker_user' => '',
        'broker_pass' => '',
        'prefix' => 'saic',             // MQTT_TOPIC des Gateways
        'saic_user' => '',              // Benutzername (Teil des Topic-Pfads)
        'vins' => array(),              // Fahrzeug-Kennungen, ab 1.1.0 mehrere
        'vin' => '',                    // Altbestand bis 1.0.8, wird uebernommen

        // --- Fahrzeug ---
        'capacity' => 61.1,             // nutzbare Kapazitaet, nur Rueckfallebene
        'namen' => array(),             // Anzeigenamen je Fahrzeug

        // --- Steuerung ---
        'commands' => 1,                // Steuerbefehle erlauben
        // ZWEITER Haken, ab Werk AUS: Befehle, die in den Betrieb eingreifen
        // (Licht und Hupe, Ver- und Entriegeln). Bis 1.0.8 stand "Auto finden"
        // hinter demselben Haken wie "Ladestrom 6 A".
        'gefahr_ein' => 0,
        // Drosselung. Das Schalten weckt die Fahrzeugelektronik; ein
        // virtueller Ausgang, der bei jedem Zyklus feuert, sendet sonst bei
        // jedem Zyklus ans Auto.
        'befehl_abstand' => 60,         // Sekunden zwischen zwei gleichen Befehlen
        'strom_abstand' => 300,         // Sekunden fuer die Ladestromgrenze
        'befehle_stunde' => 30,         // Obergrenze je Stunde ueber alle Befehle
        'wirkung_pruefen' => 1,         // nach dem Senden nachsehen, ob es wirkte
        'wartezeit' => 6,               // Sekunden, die dafuer gewartet wird

        // --- Heimzone (Standort) ---
        'ort_ein' => 1,
        'heim_breite' => '',
        'heim_laenge' => '',
        'heim_radius' => 150,           // Meter

        // --- Benachrichtigungen ---
        'notify' => array(),

        // --- Eigene MQTT-Veroeffentlichung (Regelweg laut Hausregeln) ---
        'mqtt_ein' => 0,
        'mqtt_praefix' => 'mg',

        // --- Vorklimatisierung ueber den Abfahrts-Assistenten ---
        'abfahrt_ein' => 0,
        'abfahrt_praefix' => 'abfahrt',
        'abfahrt_vorlauf' => 20,        // Minuten vor der Abfahrt
        'abfahrt_temp' => 21,
        'abfahrt_fahrzeug' => 1,

        // --- Ladeempfehlung aus einem fremden Thema (PV, Spotpreis) ---
        'ladeempf_ein' => 0,
        'ladeempf_thema' => '',
        'ladeempf_grenze' => 0,
        'ladeempf_unter' => 0,          // 1 = ausloesen, wenn UNTER der Grenze
        'ladeempf_hoch' => 'strom_max', // Befehl bei "Ueberschuss da"
        'ladeempf_runter' => 'strom_6', // Befehl bei "kein Ueberschuss"
        'ladeempf_fahrzeug' => 1,

        /* --- Ladeplan und Batterieheizplan ---
         *
         * AB WERK AUS, und das ist kein Zufall. Die Gestalt der Nutzlast ist
         * belegt (siehe mg_nutzlast()), aber ob das FAHRZEUG sie annimmt, ist
         * hier nie an einem Auto erprobt worden - und das kann keine Quelle
         * beantworten. Ein Schalter, der das sagt, ist ehrlicher als eine
         * Funktion, die so tut, als sei sie gemessen. */
        'plan_ein' => 0,
        'plan_von' => '22:00',
        'plan_bis' => '06:00',
        'plan_modus' => 'until_configured_soc',
        'heizplan_von' => '05:30',

        // --- Ladungen mitschreiben ---
        'ladungen_ein' => 1,
        'ladungen_max' => 200,

        // --- Endpunkt ---
        'aktionstoken' => '',
    );
}

function mg_config()
{
    $p = mg_paths();
    /* Erst fragen, dann oeffnen. Ein @file_get_contents() auf eine fehlende
     * Datei ist stumm, aber nicht folgenlos: ein gesetzter Fehlerbehandler
     * sieht die Warnung trotzdem - im Pruefstand steht sie dann als Befund
     * da. Und die Konfigurationsdatei fehlt regelmaessig, naemlich vor dem
     * ersten Speichern. Dasselbe gilt fuer mkdir() auf einen Ordner, den es
     * schon gibt. */
    $roh = is_file($p['config'])
        ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        if (!is_dir(dirname($p['config']))) {
            @mkdir(dirname($p['config']), 0775, true);
        }
        @copy($p['backup'], $p['config']);
        @chmod($p['config'], 0600);
    }
    $cfg = mg_json_lesen($p['config']);
    $cfg += mg_vorgaben();

    /* Altbestand: bis 1.0.8 gab es genau eine VIN in 'vin'. Sie wandert in die
     * Liste, ohne dass jemand etwas anklicken muss - und 'vin' bleibt stehen,
     * damit eine zurueckgerollte Fassung sie noch findet. */
    if (!is_array($cfg['vins'])) {
        $cfg['vins'] = array();
    }
    $cfg['vins'] = array_values(array_filter(array_map('trim', $cfg['vins']), 'strlen'));
    if (!$cfg['vins'] && trim((string) $cfg['vin']) !== '') {
        $cfg['vins'] = array(trim((string) $cfg['vin']));
    }
    if (!is_array($cfg['namen'])) {
        $cfg['namen'] = array();
    }
    if (!is_array($cfg['notify'])) {
        $cfg['notify'] = array();
    }
    $cfg['notify'] += array(
        'push' => 1,
        'soc_voll' => 1,      // melden, wenn der Ziel-SoC erreicht ist
        'stecker' => 1,       // melden, wenn der Stecker steckt/gezogen wurde
        'offen' => 1,         // melden, wenn das Auto unverschlossen steht
        'fenster' => 1,       // melden, wenn ein Fenster offen steht
        'fehler' => 1,        // melden, wenn ein Befehl scheiterte
        'push_minutes' => 5,
    );
    return $cfg;
}

function mg_config_save(array $cfg)
{
    $p = mg_paths();
    if (!is_dir(dirname($p['config']))) {
        @mkdir(dirname($p['config']), 0775, true);
    }
    // 'vin' mitfuehren: siehe mg_config().
    $cfg['vin'] = isset($cfg['vins'][0]) ? (string) $cfg['vins'][0] : '';
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // 0600, weil das Broker-Passwort darin steht.
    if (!mg_write_atomic($p['config'], $json, 0600)) {
        return false;
    }
    /* Die Sicherung wird GEPRUEFT geschrieben.
     *
     * Bis 1.0.8 stand hier ein blankes @copy() und danach return true. Diese
     * Sicherung liegt neben dem Konfigordner und ist damit der einzige Weg,
     * der ein Upgrade uebersteht - ein misslungenes copy() haette dem
     * Anwender beim naechsten Upgrade stillschweigend Passwort und Merkwort
     * eines aelteren Standes zurueckgegeben. */
    if (!mg_write_atomic($p['backup'], $json, 0600)) {
        mg_log('FEHLER: Sicherung ' . $p['backup'] . ' liess sich nicht schreiben.');
        return false;
    }
    // Die Optionsdatei fuer mosquitto traegt dieselben Zugangsdaten.
    mg_broker_optionsdatei(true);
    return true;
}

/**
 * Ein neues Merkwort erzeugen.
 *
 * random_bytes ist die kryptografisch geeignete Quelle; faellt sie aus, wird
 * nicht stillschweigend auf rand() ausgewichen - ein vorhersagbares Merkwort
 * waere schlechter als gar keins.
 */
function mg_token_erzeugen()
{
    return bin2hex(random_bytes(12));
}

/**
 * Merkmal gegen fremde Absender (Formulartoken).
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit. Bis 1.0.8
 * konnte ein untergeschobenes Formular im angemeldeten Browser "Auto finden"
 * ausloesen, also Licht und Hupe.
 *
 * Fail closed: ohne hinterlegtes Merkwort gibt es nichts zu vergleichen, und
 * hash_equals('', '') waere wahr.
 */
function mg_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $grund = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($grund === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $grund);
}

function mg_formtoken_ok($cfg = null)
{
    $soll = mg_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return ($soll !== '' && hash_equals($soll, $ist));
}

/**
 * Die laufende Fassung des Plugins.
 *
 * Erste Wahl ist LBSystem::pluginversion() - sie liest die
 * plugindatabase.json, also das, was LoxBerry TATSAECHLICH installiert hat.
 * Rueckfallebene ist die VERSION-Zeile der plugin.cfg, und die wird
 * ZEILENWEISE gelesen: LoxBerry schreibt '#'-Kommentare, PHP erkennt seit 7.0
 * nur ';', und das Ausrufezeichen in der zweiten Zeile jeder plugin.cfg
 * laesst parse_ini_file fuer die GANZE Datei scheitern.
 */
function mg_pluginversion()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $aus = @LBSystem::pluginversion();
        if ($aus !== null && trim((string) $aus) !== '') {
            $v = trim((string) $aus);
            return $v;
        }
    }
    $v = '';
    foreach (array(
        dirname(dirname(dirname(__FILE__))) . '/plugin.cfg',
        dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/plugin.cfg',
    ) as $kand) {
        if (!is_file($kand)) {
            continue;
        }
        foreach (file($kand, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '' || $zeile[0] === '#' || $zeile[0] === ';') {
                continue;
            }
            if (preg_match('/^VERSION\s*=\s*(.+)$/i', $zeile, $tr)) {
                $v = trim($tr[1], " \t\"'");
                break 2;
            }
        }
    }
    return $v;
}

/* ---------------- Protokoll ---------------- */

function mg_log($msg)
{
    $p = mg_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        mg_write_atomic($f, implode("\n", $tail) . "\n");
    }
    $cfg = mg_config();
    foreach (array($cfg['broker_pass'], $cfg['aktionstoken']) as $geheim) {
        if ((string) $geheim !== '') {
            $msg = str_replace((string) $geheim, '********', $msg);
        }
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function mg_log_if_changed($key, $line)
{
    $p = mg_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    $f = $p['tmp'] . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) {
        mg_log($key . ': ' . $line);
        mg_write_atomic($f, $line);
    }
}

/**
 * Die letzten Zeilen des Protokolls - RUECKWAERTS gelesen.
 *
 * Bis 1.0.8 wurde die ganze Datei mit file() eingelesen und umgedreht. Auf
 * einem LoxBerry mit SD-Karte ist eine halbe Megabyte grosse Datei bei jedem
 * Seitenaufbau kein Schoenheitsfehler.
 */
function mg_log_tail($datei, $anzahl = 300, $block = 8192)
{
    if (!is_file($datei)) {
        return array();
    }
    $fh = @fopen($datei, 'rb');
    if (!$fh) {
        return array();
    }
    fseek($fh, 0, SEEK_END);
    $pos = ftell($fh);
    $rest = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fh, $pos, SEEK_SET);
        $rest = fread($fh, $lese) . $rest;
        $teile = explode("\n", $rest);
        $rest = array_shift($teile);   // kann eine halbe Zeile sein
        $zeilen = array_merge($teile, $zeilen);
    }
    fclose($fh);
    if ($rest !== '') {
        array_unshift($zeilen, $rest);
    }
    $zeilen = array_values(array_filter($zeilen, 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ---------------- MQTT ---------------- */

function mg_has_mosquitto()
{
    static $da = null;
    if ($da === null) {
        $out = array();
        @exec('command -v mosquitto_sub 2>/dev/null', $out);
        $da = !empty($out);
    }
    return $da;
}

/** Die eingerichteten Fahrzeuge als Liste (1-basiert nummeriert). */
function mg_fahrzeuge($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $aus = array();
    foreach ($cfg['vins'] as $i => $vin) {
        $nr = $i + 1;
        $name = isset($cfg['namen'][$i]) ? trim((string) $cfg['namen'][$i]) : '';
        $aus[$nr] = array('nr' => $nr, 'vin' => (string) $vin,
                          'name' => $name !== '' ? $name : ('MG ' . $nr));
    }
    return $aus;
}

function mg_fahrzeug_anzahl($cfg = null)
{
    return count(mg_fahrzeuge($cfg));
}

/** Basis-Topic eines Fahrzeugs, z. B. saic/user@example.org/vehicles/LSJ... */
function mg_base_topic($nr = 1, $cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $user = trim((string) $cfg['saic_user']);
    $fz = mg_fahrzeuge($cfg);
    if ($user === '' || !isset($fz[(int) $nr])) {
        return '';
    }
    return $prefix . '/' . $user . '/vehicles/' . $fz[(int) $nr]['vin'];
}

/* ==================================================================
 * Zugangsdaten des Brokers - NICHT ueber die Kommandozeile
 *
 * Bis 1.0.2 stand das Passwort als "-P <passwort>" in der Aufrufzeile.
 * /proc/<pid>/cmdline hat die Rechte 444 - jeder lokale Benutzer liest dort
 * mit, und der minuetliche Cron laesst mosquitto_sub rund 5 % der Zeit
 * laufen. Dazu verwirft escapeshellarg() Bytes, die im eingestellten
 * Zeichensatz kein gueltiges Zeichen ergeben: ein Passwort mit Umlaut haette
 * unter PHP 7.4 mit LC_ALL=C still versagt.
 *
 * mosquitto_sub und mosquitto_pub lesen Vorgabeoptionen aus
 * $XDG_CONFIG_HOME/mosquitto_sub bzw. .../mosquitto_pub, eine Option je
 * Zeile. Auf der Kommandozeile steht dann nur noch der PFAD.
 * ================================================================== */

function mg_broker_optionsordner()
{
    $p = mg_paths();
    return $p['data'] . '/mosquitto';
}

/**
 * Eine Zeile fuer die Optionsdatei absichern.
 *
 * Die Datei ist zeilenorientiert - ein Zeilenumbruch im Wert erzeugt eine
 * ZUSAETZLICHE Option. Bis 1.0.8 wurde der Benutzername beschnitten, das
 * Passwort nicht: ein aus der Zwischenablage eingefuegtes Passwort mit
 * angehaengtem \r ergab ein stilles Falschpasswort, und das Plugin meldete
 * danach nur noch "keine Werte vom Broker".
 */
function mg_optionswert($v)
{
    return trim(str_replace(array("\r", "\n", "\t"), '', (string) $v));
}

/**
 * Die Optionsdateien schreiben. $erzwingen = true schreibt immer neu,
 * sonst nur, wenn sie fehlen oder aelter als die Konfiguration sind.
 */
function mg_broker_optionsdatei($erzwingen = false)
{
    $p = mg_paths();
    $ordner = mg_broker_optionsordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        mg_log('FEHLER: Ordner fuer die Broker-Zugangsdaten nicht anlegbar: ' . $ordner);
        return '';
    }
    @chmod($ordner, 0700);

    $cfg = mg_config();
    $zeilen = '';
    $u = mg_optionswert($cfg['broker_user']);
    $pw = mg_optionswert($cfg['broker_pass']);
    if ($u !== '') {
        $zeilen .= '-u ' . $u . "\n";
    }
    if ($pw !== '') {
        $zeilen .= '-P ' . $pw . "\n";
    }

    foreach (array('mosquitto_sub', 'mosquitto_pub') as $name) {
        $datei = $ordner . '/' . $name;
        if (!$erzwingen && is_file($datei) && is_file($p['config'])
            && filemtime($datei) >= filemtime($p['config'])) {
            continue;
        }
        // Auch wenn nichts drinsteht, wird die Datei geschrieben (leer) -
        // sonst bliebe eine alte mit den vorherigen Zugangsdaten liegen.
        mg_write_atomic($datei, $zeilen, 0600);
    }
    return $ordner;
}

/** Der Teil der Aufrufzeile, der oeffentlich sein darf: Rechner und Port. */
function mg_broker_args()
{
    $cfg = mg_config();
    return ' -h ' . escapeshellarg((string) $cfg['broker_host'])
         . ' -p ' . (int) $cfg['broker_port'];
}

/** Vorspann fuer den Aufruf: setzt XDG_CONFIG_HOME auf den Optionsordner. */
function mg_broker_umgebung()
{
    $ordner = mg_broker_optionsdatei();
    return $ordner !== '' ? 'XDG_CONFIG_HOME=' . escapeshellarg($ordner) . ' ' : '';
}

/**
 * Auf eine Liste von Themen horchen und die empfangenen Werte zurueckgeben.
 * Gemeinsame Grundlage von mg_snapshot() und mg_horcher_lesen().
 */
function mg_sub($themen, $sekunden)
{
    if (!mg_has_mosquitto() || !$themen) {
        return array(array(), 1, 'mosquitto-clients fehlt');
    }
    $t = '';
    foreach ((array) $themen as $thema) {
        $t .= ' -t ' . escapeshellarg((string) $thema);
    }
    $cmd = mg_broker_umgebung() . 'mosquitto_sub' . mg_broker_args() . $t
         . ' -v -W ' . max(1, min(15, (int) $sekunden)) . ' 2>&1';
    $out = array();
    @exec($cmd, $out, $rc);
    $werte = array();
    $fehler = array();
    foreach ($out as $zeile) {
        $pos = strpos($zeile, ' ');
        if ($pos === false) {
            if (trim($zeile) !== '') { $fehler[] = trim($zeile); }
            continue;
        }
        $werte[substr($zeile, 0, $pos)] = trim(substr($zeile, $pos + 1));
    }
    return array($werte, $rc, implode(' ', array_slice($fehler, 0, 3)));
}

/**
 * Alle behaltenen (retained) Werte unterhalb des Prefix einlesen und als
 * Momentaufnahme ablegen.
 *
 * PLAUSIBILITAET (neu in 1.1.0): Bis 1.0.8 genuegte EIN empfangenes Thema,
 * damit die Momentaufnahme als gelungen galt und die alte vollstaendig
 * ersetzte. Gemessen mit vier Themen lieferte die Loxone-Zeile danach
 * OK=1 - also "Fahrzeugdaten gueltig" - waehrend fast alles Platzhalter war.
 * Bricht die Themenzahl gegenueber dem letzten Stand um mehr als die Haelfte
 * ein, bleibt die alte Momentaufnahme stehen; ein uebersprungener Lauf ist
 * kein Fehler, ein halber Datensatz ist einer.
 */
function mg_snapshot($sekunden = 3)
{
    $cfg = mg_config();
    if (!mg_has_mosquitto()) {
        mg_log_if_changed('mosquitto', 'FEHLER: mosquitto_sub fehlt - Paket mosquitto-clients nachinstallieren.');
        return array(0, 'mosquitto-clients fehlt');
    }
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    // Der letzte Wille des Gateways liegt AUSSERHALB des Benutzerpfads, aber
    // unterhalb desselben Prefix - "saic/#" deckt ihn mit ab.
    list($alle, $rc, $fehler) = mg_sub(array($prefix . '/#'), $sekunden);
    $werte = array();
    foreach ($alle as $topic => $wert) {
        if (strpos($topic, $prefix . '/') === 0) {
            $werte[$topic] = $wert;
        }
    }
    if (!$werte) {
        return array(0, $fehler !== '' ? $fehler : 'keine Werte empfangen (rc=' . $rc . ')');
    }
    $p = mg_paths();
    $alt = mg_raw();
    if ((int) $alt['anzahl'] > 4 && count($werte) * 2 < (int) $alt['anzahl']) {
        mg_log('Momentaufnahme verworfen: nur ' . count($werte) . ' von zuletzt '
            . (int) $alt['anzahl'] . ' Themen - alter Stand bleibt stehen.');
        return array(0, 'unvollstaendig (' . count($werte) . ' von ' . (int) $alt['anzahl'] . ')');
    }
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    mg_write_json($p['data'] . '/werte.json', array(
        'zeit' => date('c'), 'anzahl' => count($werte), 'werte' => $werte,
    ));
    return array(1, count($werte) . ' Themen');
}

/** Rohwerte der letzten Momentaufnahme. */
function mg_raw()
{
    $p = mg_paths();
    $d = mg_json_lesen($p['data'] . '/werte.json');
    // Nicht nur "ist ein Feld", sondern "hat die erwartete Form".
    if (!isset($d['werte']) || !is_array($d['werte'])) {
        return array('zeit' => '', 'anzahl' => 0, 'werte' => array());
    }
    $d += array('zeit' => '', 'anzahl' => count($d['werte']));
    return $d;
}

/**
 * Einen Wert eines Fahrzeugs holen.
 *
 * Ab 1.1.0 wird NUR noch unter dem Basispfad des angefragten Fahrzeugs
 * gesucht. Der Ersatzweg von 1.0.8 - "irgendein Thema, das so endet" - war
 * bei einem Fahrzeug bequem und bei zweien falsch: er griff willkuerlich
 * eines heraus.
 */
function mg_pick($suffix, $default = null, $nr = 1)
{
    $roh = mg_raw();
    $base = mg_base_topic($nr);
    if ($base === '') {
        return $default;
    }
    foreach ((array) $suffix as $s) {
        if (isset($roh['werte'][$base . '/' . $s])) {
            $v = $roh['werte'][$base . '/' . $s];
            if (trim((string) $v) !== '') {
                return $v;
            }
        }
    }
    return $default;
}

/** Ein Thema ausserhalb des Fahrzeugpfads, z. B. saic/_internal/lwt. */
function mg_pick_abs($topic, $default = null)
{
    $roh = mg_raw();
    return isset($roh['werte'][$topic]) ? $roh['werte'][$topic] : $default;
}

function mg_num($suffix, $default = -1, $nr = 1)
{
    $v = mg_pick($suffix, null, $nr);
    if ($v === null || $v === '') {
        return $default;
    }
    $v = str_replace(',', '.', (string) $v);
    return is_numeric($v) ? (float) $v : $default;
}

function mg_bool($suffix, $default = -1, $nr = 1)
{
    return mg_bool_wert(mg_pick($suffix, null, $nr), $default);
}

function mg_bool_wert($v, $default = -1)
{
    if ($v === null || $v === '') {
        return $default;
    }
    $v = strtolower(trim((string) $v));
    if (in_array($v, array('true', '1', 'on', 'yes', 'locked', 'charging', 'online', 'open'), true)) {
        return 1;
    }
    if (in_array($v, array('false', '0', 'off', 'no', 'unlocked', 'offline', 'closed'), true)) {
        return 0;
    }
    return is_numeric($v) ? ((float) $v > 0 ? 1 : 0) : $default;
}

function mg_txt($suffix, $default = '', $nr = 1)
{
    $v = mg_pick($suffix, null, $nr);
    return $v === null ? $default : (string) $v;
}

/**
 * Wie viele der uebergebenen Themen stehen auf "offen"?
 * Rueckgabe: array(anzahl, namen) - anzahl -1, wenn KEINES bekannt ist.
 */
function mg_zaehle_offen($paare, $nr)
{
    $anzahl = 0;
    $bekannt = 0;
    $namen = array();
    foreach ($paare as $suffix => $name) {
        $b = mg_bool($suffix, -1, $nr);
        if ($b === -1) {
            continue;
        }
        $bekannt++;
        if ($b === 1) {
            $anzahl++;
            $namen[] = $name;
        }
    }
    return $bekannt === 0 ? array(-1, array()) : array($anzahl, $namen);
}

/** Entfernung zweier Punkte in Metern (Haversine, PHP-Bordmittel). */
function mg_entfernung($b1, $l1, $b2, $l2)
{
    $r = 6371000.0;
    $p1 = deg2rad((float) $b1);
    $p2 = deg2rad((float) $b2);
    $db = deg2rad((float) $b2 - (float) $b1);
    $dl = deg2rad((float) $l2 - (float) $l1);
    $a = sin($db / 2) * sin($db / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* ==================================================================
 * Die Feldliste - EINE Quelle fuer alles
 *
 * Aus ihr entstehen: die Loxone-Zeilen, die Vorlage fuer Loxone Config, die
 * Tabelle der Befehlserkennungen im Reiter "Einbindung in Loxone", die
 * Themenliste der eigenen MQTT-Veroeffentlichung und die Kacheln im Reiter
 * Test. Bis 1.0.8 standen die Suchtexte an 39 Stellen woertlich im Bestand.
 *
 * 'min' traegt den UNBEKANNT-Wert, wenn es einen gibt. Ein Feld, das -1 als
 * "nicht bekannt" sendet, braucht MinVal="-1" - sonst zeigt Loxone eine 0,
 * und 0 heisst bei "verschlossen" das Gegenteil von "unbekannt".
 *
 * EIN NEUES FELD GEHOERT ANS ENDE. Die Reihenfolge dieser Liste ist die
 * Reihenfolge in der Statuszeile; eine Einfuegung in der Mitte verschiebt
 * jede beim Anwender eingetragene Befehlserkennung.
 * ================================================================== */

function mg_felder()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    // analog, min, max, Einheit, Zeilen, MQTT-Name
    $roh = array(
        // ---- Bestand bis 1.0.8, Reihenfolge unveraendert ----
        'OK'           => array(0, 0, 1,       '',     'mg,laden,ort,technik', 'ok'),
        'SOC'          => array(1, -1, 100,    '%',    'mg,laden', 'soc'),
        'SOCKWH'       => array(1, -1, 200,    'kWh',  'mg,laden', 'energie'),
        'ZIEL'         => array(1, -1, 100,    '%',    'mg,laden', 'ziel'),
        'REICHWEITE'   => array(1, -1, 1000,   'km',   'mg', 'reichweite'),
        'LAEDT'        => array(0, -1, 1,      '',     'mg,laden', 'laedt'),
        'STECKER'      => array(0, -1, 1,      '',     'mg,laden', 'stecker'),
        'LEISTUNG'     => array(1, -100, 350,  'kW',   'mg,laden', 'leistung'),
        'RESTZEIT'     => array(1, -1, 3000,   'min',  'mg,laden', 'restzeit'),
        'KM'           => array(1, -1, 1000000, 'km',  'mg,ort', 'kilometerstand'),
        'BATT12V'      => array(1, -1, 20,     'V',    'mg,technik', 'batterie12v'),
        'ZU'           => array(0, -1, 1,      '',     'mg', 'verschlossen'),
        'KOFFER'       => array(0, -1, 1,      '',     'mg', 'kofferraum'),
        'INNEN'        => array(1, -99, 80,    '°C',   'mg', 'innentemperatur'),
        'AUSSEN'       => array(1, -99, 80,    '°C',   'mg', 'aussentemperatur'),
        'VOLL'         => array(0, -1, 1,      '',     'mg,laden', 'voll'),
        'ALTER'        => array(1, -1, 100000, 'min',  'mg,technik', 'alter'),
        'THEMEN'       => array(1, 0, 1000,    '',     'mg,technik', 'themen'),
        'PUSH'         => array(0, 0, 1,       '',     'mg', 'push'),
        'PUSHAKTIV'    => array(0, 0, 1,       '',     'mg', 'push_aktiv'),
        'PTEST'        => array(0, 0, 1,       '',     'mg', 'push_test'),

        // ---- Neu in 1.1.0: Erreichbarkeit ----
        'ERREICHBAR'   => array(0, -1, 1,      '',     'mg,technik', 'erreichbar'),
        'GATEWAY'      => array(0, -1, 1,      '',     'mg,technik', 'gateway'),
        'FZALTER'      => array(1, -1, 100000, 'min',  'mg,technik', 'fahrzeugalter'),
        'FEHLER'       => array(0, -1, 1,      '',     'mg,technik', 'fehler'),

        // ---- Neu: Fahrt und Ort ----
        'LAEUFT'       => array(0, -1, 1,      '',     'mg,ort', 'laeuft'),
        'TEMPO'        => array(1, -1, 250,    'km/h', 'mg,ort', 'tempo'),
        'ZUHAUSE'      => array(0, -1, 1,      '',     'mg,ort', 'zuhause'),
        'ENTFERNUNG'   => array(1, -1, 20000,  'km',   'mg,ort', 'entfernung'),
        'KMTAG'        => array(1, -1, 3000,   'km',   'mg,ort', 'km_tag'),
        'KMLADUNG'     => array(1, -1, 3000,   'km',   'mg,ort', 'km_seit_ladung'),

        // ---- Neu: Oeffnungen ----
        'TUEROFFEN'    => array(1, -1, 5,      '',     'mg', 'tueren_offen'),
        'FENSTEROFFEN' => array(1, -1, 5,      '',     'mg', 'fenster_offen'),

        // ---- Neu: Reifendruck ----
        'RDVL'         => array(1, -1, 6,      'bar',  'mg,technik', 'reifen_vl'),
        'RDVR'         => array(1, -1, 6,      'bar',  'mg,technik', 'reifen_vr'),
        'RDHL'         => array(1, -1, 6,      'bar',  'mg,technik', 'reifen_hl'),
        'RDHR'         => array(1, -1, 6,      'bar',  'mg,technik', 'reifen_hr'),

        // ---- Neu: Ladetechnik ----
        'ACLEISTUNG'   => array(1, -1, 25000,  'W',    'mg,laden', 'ac_leistung'),
        'ACSTROM'      => array(1, -1, 64,     'A',    'mg,laden', 'ac_strom'),
        'ACSPANNUNG'   => array(1, -1, 500,    'V',    'mg,laden', 'ac_spannung'),
        'LADEART'      => array(1, -1, 99,     '',     'mg,laden', 'ladeart'),
        'KABELVERR'    => array(0, -1, 1,      '',     'mg,laden', 'kabel_verriegelt'),
        'STROMGRENZE'  => array(1, -1, 64,     'A',    'mg,laden', 'stromgrenze'),
        'KAPAZITAET'   => array(1, -1, 200,    'kWh',  'mg,laden,technik', 'kapazitaet'),
        'VERBRTAG'     => array(1, -1, 200,    'kWh',  'mg,laden', 'verbrauch_tag'),
        'VERBRLADUNG'  => array(1, -1, 200,    'kWh',  'mg,laden', 'verbrauch_seit_ladung'),
        'FERTIGUM'     => array(1, -1, 2000000000, 's', 'mg,laden', 'fertig_um'),
        'BATTHEIZ'     => array(0, -1, 1,      '',     'mg,laden', 'batterieheizung'),

        // ---- Neu: Klima ----
        'KLIMA'        => array(0, -1, 1,      '',     'mg', 'klima'),
        'KLIMASOLL'    => array(1, -1, 40,     '°C',   'mg', 'klima_soll'),
        'HECKSCHEIBE'  => array(0, -1, 1,      '',     'mg', 'heckscheibe'),
        'FRONTSCHEIBE' => array(0, -1, 1,      '',     'mg', 'frontscheibe'),
        'SITZHL'       => array(1, -1, 3,      '',     'mg', 'sitzheizung_l'),
        'SITZHR'       => array(1, -1, 3,      '',     'mg', 'sitzheizung_r'),

        // Ein neues Feld gehoert ANS ENDE - eine Einfuegung in der Mitte
        // verschiebt jede beim Anwender eingetragene Befehlserkennung.
        'STECKERFZ'    => array(0, -1, 1,      '',     'mg,laden', 'stecker_fahrzeug'),
        'STECKERSAEULE' => array(0, -1, 1,     '',     'mg,laden', 'stecker_saeule'),
    );
    $f = array();
    foreach ($roh as $name => $r) {
        $f[$name] = array(
            'analog' => $r[0], 'min' => $r[1], 'max' => $r[2], 'einheit' => $r[3],
            'bez' => 'FELD.' . $name, 'zeilen' => explode(',', $r[4]), 'mqtt' => $r[5],
        );
    }
    return $f;
}

/**
 * Die Befehlserkennung fuer ein Feld - an GENAU EINER Stelle.
 *
 * Das Semikolon gehoert hinein. "\iKM=" trifft in einer Zeile, die auch
 * "INSPKM=" enthaelt, die falsche Fundstelle - Loxone nimmt die erste. Der
 * Fehler ist die teuerste Sorte: beide Zahlen sehen aus wie ein
 * Kilometerstand. In jeder Antwortzeile dieses Plugins steht vor jedem
 * Feldnamen ein Semikolon, auch vor dem ersten (MG;OK=...).
 */
function mg_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/** Die Zeilenarten. */
function mg_zeilen()
{
    return array(
        'mg' => array('kopf' => 'MG', 'bez' => 'ZEILE.MG', 'takt' => 300),
        'laden' => array('kopf' => 'MGL', 'bez' => 'ZEILE.LADEN', 'takt' => 120),
        'ort' => array('kopf' => 'MGO', 'bez' => 'ZEILE.ORT', 'takt' => 60),
        'technik' => array('kopf' => 'MGT', 'bez' => 'ZEILE.TECHNIK', 'takt' => 1800),
    );
}

function mg_felder_von($zeile)
{
    $aus = array();
    foreach (mg_felder() as $name => $info) {
        if (in_array($zeile, $info['zeilen'], true)) {
            $aus[$name] = $info;
        }
    }
    return $aus;
}

/* ---------------- Zustand ---------------- */

/**
 * Der vollstaendige Zustand eines Fahrzeugs, mit den Feldnamen als
 * Schluessel. So gibt es keine zweite Liste, die auseinanderlaufen kann.
 */
function mg_state($nr = 1)
{
    $cfg = mg_config();
    $roh = mg_raw();
    $st = array();
    foreach (mg_felder() as $name => $info) {
        $st[$name] = -1;
    }

    $soc = mg_num('drivetrain/soc', -1, $nr);
    $ziel = mg_num('drivetrain/socTarget', -1, $nr);

    /* Kapazitaet und Energieinhalt kommen vom Auto, wenn es sie liefert.
     * Bis 1.0.8 wurde beides aus dem Handeintrag gerechnet, und der gesuchte
     * Name war falsch geschrieben (socKwh statt soc_kwh) - der echte Wert
     * wurde also nie getroffen. */
    $kapazitaet = mg_num('drivetrain/totalBatteryCapacity', -1, $nr);
    if ($kapazitaet <= 0) {
        $kapazitaet = (float) $cfg['capacity'];
    }
    $kwh = mg_num('drivetrain/soc_kwh', -1, $nr);
    if ($kwh < 0 && $soc >= 0 && $kapazitaet > 0) {
        $kwh = round($soc / 100 * $kapazitaet, 1);
    }

    /* Restladezeit: das Gateway veroeffentlicht SEKUNDEN.
     * Belegt in src/status_publisher/charge/chrg_mgmt_data.py:
     *     transform=lambda x: x * 60
     * Der Rohwert des Autos ist in Minuten, veroeffentlicht wird mal 60.
     * Bis 1.0.8 ging der Wert unveraendert als "min" nach Loxone - eine
     * Ladezeit von 90 Minuten erschien dort als 5400 min, also 90 Stunden. */
    $restsek = mg_num('drivetrain/remainingChargingTime', -1, $nr);
    $restmin = $restsek >= 0 ? (float) round($restsek / 60) : -1;

    $st['SOC'] = $soc;
    $st['SOCKWH'] = $kwh;
    $st['ZIEL'] = $ziel;
    $st['KAPAZITAET'] = $kapazitaet > 0 ? round($kapazitaet, 1) : -1;
    $st['REICHWEITE'] = mg_num('drivetrain/range', -1, $nr);
    $st['LAEDT'] = mg_bool('drivetrain/charging', -1, $nr);
    $st['STECKER'] = mg_bool('drivetrain/chargerConnected', -1, $nr);
    $st['LEISTUNG'] = mg_num('drivetrain/power', -1, $nr);
    $st['RESTZEIT'] = $restmin;
    $st['KM'] = mg_num('drivetrain/mileage', -1, $nr);
    $st['BATT12V'] = mg_num('drivetrain/auxiliaryBatteryVoltage', -1, $nr);
    $st['ZU'] = mg_bool('doors/locked', -1, $nr);
    $st['KOFFER'] = mg_bool('doors/boot', -1, $nr);
    $st['INNEN'] = mg_num('climate/interiorTemperature', -99, $nr);
    $st['AUSSEN'] = mg_num('climate/exteriorTemperature', -99, $nr);

    // --- Erreichbarkeit ---
    $st['ERREICHBAR'] = mg_bool('available', -1, $nr);
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $st['GATEWAY'] = mg_bool_wert(mg_pick_abs($prefix . '/_internal/lwt'), -1);
    $letzte = mg_txt(array('refresh/lastVehicleState', 'refresh/lastActivity'), '', $nr);
    $ts = $letzte !== '' ? strtotime($letzte) : false;
    $st['FZALTER'] = $ts ? max(0, (float) round((time() - $ts) / 60)) : -1;
    $fehlertext = mg_txt('command/error', '', $nr);
    $st['FEHLER'] = $fehlertext !== '' ? 1 : (($st['ERREICHBAR'] === -1) ? -1 : 0);

    // --- Fahrt und Ort ---
    $st['LAEUFT'] = mg_bool('drivetrain/running', -1, $nr);
    $st['TEMPO'] = mg_num('location/speed', -1, $nr);
    $st['KMTAG'] = mg_num('drivetrain/mileageOfTheDay', -1, $nr);
    $st['KMLADUNG'] = mg_num('drivetrain/mileageSinceLastCharge', -1, $nr);
    if (!empty($cfg['ort_ein']) && (string) $cfg['heim_breite'] !== ''
        && (string) $cfg['heim_laenge'] !== '') {
        $b = mg_num('location/latitude', -999, $nr);
        $l = mg_num('location/longitude', -999, $nr);
        if ($b > -900 && $l > -900 && !($b == 0 && $l == 0)) {
            $m = mg_entfernung($cfg['heim_breite'], $cfg['heim_laenge'], $b, $l);
            $st['ENTFERNUNG'] = round($m / 1000, 2);
            $st['ZUHAUSE'] = $m <= max(20, (float) $cfg['heim_radius']) ? 1 : 0;
        }
    }

    // --- Oeffnungen ---
    list($ta, $tn) = mg_zaehle_offen(array(
        'doors/driver' => 'TEIL.TUER_VL', 'doors/passenger' => 'TEIL.TUER_VR',
        'doors/rearLeft' => 'TEIL.TUER_HL', 'doors/rearRight' => 'TEIL.TUER_HR',
        'doors/bonnet' => 'TEIL.HAUBE',
    ), $nr);
    $st['TUEROFFEN'] = $ta;
    list($fa, $fn) = mg_zaehle_offen(array(
        'windows/driver' => 'TEIL.FENSTER_VL', 'windows/passenger' => 'TEIL.FENSTER_VR',
        'windows/rearLeft' => 'TEIL.FENSTER_HL', 'windows/rearRight' => 'TEIL.FENSTER_HR',
        'windows/sunRoof' => 'TEIL.SCHIEBEDACH',
    ), $nr);
    $st['FENSTEROFFEN'] = $fa;

    // --- Reifendruck (Gateway rechnet bereits in bar: Rohwert mal 0,04) ---
    $st['RDVL'] = mg_num('tyres/frontLeftPressure', -1, $nr);
    $st['RDVR'] = mg_num('tyres/frontRightPressure', -1, $nr);
    $st['RDHL'] = mg_num('tyres/rearLeftPressure', -1, $nr);
    $st['RDHR'] = mg_num('tyres/rearRightPressure', -1, $nr);

    // --- Ladetechnik ---
    $ein = mg_num('obc/powerSinglePhase', -1, $nr);
    $drei = mg_num('obc/powerThreePhase', -1, $nr);
    $st['ACLEISTUNG'] = $drei > 0 ? $drei : $ein;
    $st['ACSTROM'] = mg_num('obc/current', -1, $nr);
    $st['ACSPANNUNG'] = mg_num('obc/voltage', -1, $nr);
    $st['LADEART'] = mg_num('drivetrain/chargingType', -1, $nr);
    /* Stecker am Fahrzeug und an der Saeule. Zusammen mit LADEART die Antwort
     * auf die Frage, ob gerade an einer Gleichstromsaeule geladen wird - eine
     * Ueberschussregelung darf dort NICHT eingreifen. Die Zuordnung der
     * Zahlenwerte von chargingType zu Wechsel- und Gleichstrom steht in der
     * Bibliothek des Gateways, nicht im Gateway selbst; sie ist deshalb
     * NICHT nachgemessen und wird als Rohwert durchgereicht. */
    $st['STECKERFZ'] = mg_bool('ccu/onboardChargerPlugStatus', -1, $nr);
    $st['STECKERSAEULE'] = mg_bool('ccu/offboardChargerPlugStatus', -1, $nr);
    $st['KABELVERR'] = mg_bool('drivetrain/chargingCableLock', -1, $nr);
    $grenze = mg_txt('drivetrain/chargeCurrentLimit', '', $nr);
    $st['STROMGRENZE'] = mg_stromgrenze_zahl($grenze);
    $st['VERBRTAG'] = mg_num('drivetrain/powerUsageOfDay', -1, $nr);
    $st['VERBRLADUNG'] = mg_num('drivetrain/powerUsageSinceLastCharge', -1, $nr);
    $st['BATTHEIZ'] = mg_bool('drivetrain/batteryHeating', -1, $nr);
    // Fertig um: Loxone rechnet in Sekunden seit dem 01.01.2009.
    $st['FERTIGUM'] = ($restmin > 0 && (int) $st['LAEDT'] === 1)
        ? (float) (time() + (int) round($restmin * 60) - MG_LOXONE_EPOCHE) : -1;

    // --- Klima ---
    $st['KLIMASOLL'] = mg_num('climate/remoteTemperature', -1, $nr);
    $klima = strtolower(mg_txt('climate/remoteClimateState', '', $nr));
    $st['KLIMA'] = $klima === '' ? -1 : (in_array($klima, array('off', 'false', '0'), true) ? 0 : 1);
    $st['HECKSCHEIBE'] = mg_bool('climate/rearWindowDefrosterHeating', -1, $nr);
    $st['FRONTSCHEIBE'] = mg_bool('climate/frontWindowDefrosterHeating', -1, $nr);
    $st['SITZHL'] = mg_num('climate/heatedSeatsFrontLeftLevel', -1, $nr);
    $st['SITZHR'] = mg_num('climate/heatedSeatsFrontRightLevel', -1, $nr);

    // --- Abgeleitetes ---
    $st['THEMEN'] = (float) mg_themen_anzahl($nr);
    /* ALTER misst, wie alt die Momentaufnahme des Plugins ist - also den Weg
     * zum Broker. Es sagt NICHTS darueber, wann das Auto zuletzt geantwortet
     * hat: die Werte liegen retained auf dem Broker und stehen auch dann noch
     * da, wenn der Container tot ist. Wer wissen will, wie alt die
     * FAHRZEUGDATEN sind, nimmt FZALTER. */
    $st['ALTER'] = $roh['zeit'] !== ''
        ? max(0, (float) round((time() - strtotime($roh['zeit'])) / 60)) : -1;
    $st['VOLL'] = ($ziel > 0 && $soc >= 0) ? ($soc >= $ziel ? 1 : 0) : -1;
    /* OK heisst "Fahrzeugdaten gueltig". Dazu gehoert seit 1.1.0 auch, dass
     * das Gateway das Fahrzeug ueberhaupt erreicht hat - ERREICHBAR=0 mit
     * retained Werten von vorgestern ist kein gueltiger Datensatz. */
    $st['OK'] = ($st['THEMEN'] > 0 && $soc >= 0 && (int) $st['ERREICHBAR'] !== 0) ? 1 : 0;

    $st['PUSH'] = empty($cfg['notify']['push']) ? 0 : 1;
    $st['PUSHAKTIV'] = mg_push_active($nr);
    $st['PTEST'] = mg_ptest_active();

    // Nicht-Feld-Angaben tragen einen Unterstrich und landen nie in der Zeile.
    $st['_nr'] = (int) $nr;
    $st['_zeit'] = $roh['zeit'];
    $st['_fehlertext'] = $fehlertext;
    $st['_klimatext'] = $klima;
    $st['_grenze'] = $grenze;
    $st['_tueren'] = $tn;
    $st['_fenster'] = $fn;
    /* Drei Textwerte, die in keine Statuszeile gehoeren, aber ueber MQTT und
     * in ?json=1 brauchbar sind.
     *
     * Der Ladeplan wird nur GELESEN. Setzen waere ueber
     * drivetrain/chargingSchedule/set moeglich, aber die genaue Gestalt der
     * Nutzlast ({startTime,endTime,mode}) ist in der Anleitung des Gateways
     * nicht festgelegt und hier NICHT nachgemessen - ein geratener
     * JSON-Aufbau waere eine Behauptung, keine Funktion. */
    $st['_ladeplan'] = mg_txt('drivetrain/chargingSchedule', '', $nr);
    $st['_heizplan'] = mg_txt('drivetrain/batteryHeatingSchedule', '', $nr);
    $st['_abbruchgrund'] = mg_txt('drivetrain/chargingStopReason', '', $nr);
    $st['_fahrzeugmeldung'] = mg_txt(array('info/lastMessage/title',
        'events/vehicleMessage'), '', $nr);
    return $st;
}

/** Wie viele Themen liegen fuer dieses Fahrzeug vor? */
function mg_themen_anzahl($nr = 1)
{
    $roh = mg_raw();
    $base = mg_base_topic($nr);
    if ($base === '') {
        return 0;
    }
    $n = 0;
    $pfad = $base . '/';
    $len = strlen($pfad);
    foreach ($roh['werte'] as $t => $v) {
        if (strncmp($t, $pfad, $len) === 0) {
            $n++;
        }
    }
    return $n;
}

/** "16A" -> 16, "MAX" -> 32. Fuer Loxone ist eine Zahl brauchbarer als Text. */
function mg_stromgrenze_zahl($s)
{
    $s = strtoupper(trim((string) $s));
    if ($s === '') {
        return -1;
    }
    if ($s === 'MAX') {
        return 32;
    }
    return preg_match('/^(\d+)\s*A?$/', $s, $t) ? (float) $t[1] : -1;
}

/**
 * Eine Loxone-Zeile bilden.
 *
 * Kopf und Felder kommen aus mg_zeilen() und mg_felder(); es gibt keine
 * zweite Aufzaehlung. Vor jedem Feldnamen steht ein Semikolon - dieselbe
 * Bedingung, die mg_check() voraussetzt.
 */
function mg_line($zeile = 'mg', $nr = 1, $st = null)
{
    $zn = mg_zeilen();
    if (!isset($zn[$zeile])) {
        $zeile = 'mg';
    }
    if ($st === null) {
        $st = mg_state($nr);
    }
    $aus = $zn[$zeile]['kopf'];
    if ((int) $nr > 1) {
        $aus .= ';FZ=' . (int) $nr;
    }
    foreach (mg_felder_von($zeile) as $name => $info) {
        $v = isset($st[$name]) ? $st[$name] : -1;
        $aus .= ';' . $name . '=' . ($info['analog']
            ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')
            : (string) (int) $v);
    }
    return $aus . "\n";
}

/* ==================================================================
 * Befehle
 *
 * Bewusst eine feste Liste - ueber den Endpunkt kann nichts anderes ins
 * Fahrzeug geschickt werden. 'zusatz' macht aus fuenf festen Zielwerten
 * einen Befehl mit einem Zahlenwert; die alten Namen bleiben als Aliasse.
 * 'gefahr' = 1 verlangt den zweiten Haken.
 * 'pruef' nennt das Thema, an dem sich nachsehen laesst, ob es gewirkt hat.
 * ================================================================== */

function mg_befehle()
{
    return array(
        'auffrischen' => array('topic' => 'refresh/mode/set', 'wert' => 'force',
            'bez' => 'BEFEHL.AUFFRISCHEN', 'gefahr' => 0, 'gegen' => '',
            'pruef' => '', 'abstand' => 60),
        'laden_start' => array('topic' => 'drivetrain/charging/set', 'wert' => 'true',
            'bez' => 'BEFEHL.LADEN_START', 'gefahr' => 0, 'gegen' => 'laden_stopp',
            'pruef' => 'drivetrain/charging', 'erwartet' => '1', 'abstand' => 60),
        'laden_stopp' => array('topic' => 'drivetrain/charging/set', 'wert' => 'false',
            'bez' => 'BEFEHL.LADEN_STOPP', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'drivetrain/charging', 'erwartet' => '0', 'abstand' => 60),
        'ziel' => array('topic' => 'drivetrain/socTarget/set', 'zusatz' => 'prozent',
            'werte' => array(40, 50, 60, 70, 80, 90, 100),
            'bez' => 'BEFEHL.ZIEL', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'drivetrain/socTarget', 'abstand' => 60),
        /* 'norm' => 'ampere': Ein ANALOGER virtueller Ausgang in Loxone sendet
         * eine Zahl - aus <v> wird also "16", nie "16A". Ohne diese
         * Umschrift haette die eigene Ausgangsvorlage einen Befehl erzeugt,
         * den der eigene Endpunkt abweist. Gemessen an der erzeugten
         * VQ_mgismart.xml, bevor es jemand am Miniserver gemerkt haette. */
        'strom' => array('topic' => 'drivetrain/chargeCurrentLimit/set', 'zusatz' => 'ampere',
            'werte' => array('6A', '8A', '16A', 'MAX'), 'norm' => 'ampere',
            'bez' => 'BEFEHL.STROM', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'drivetrain/chargeCurrentLimit', 'abstand' => 300),
        'klima_an' => array('topic' => 'climate/remoteClimateState/set', 'wert' => 'on',
            'bez' => 'BEFEHL.KLIMA_AN', 'gefahr' => 0, 'gegen' => 'klima_aus',
            'pruef' => 'climate/remoteClimateState', 'erwartet' => 'on', 'abstand' => 60),
        'klima_aus' => array('topic' => 'climate/remoteClimateState/set', 'wert' => 'off',
            'bez' => 'BEFEHL.KLIMA_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/remoteClimateState', 'erwartet' => 'off', 'abstand' => 60),
        'klima_vorn' => array('topic' => 'climate/remoteClimateState/set', 'wert' => 'front',
            'bez' => 'BEFEHL.KLIMA_VORN', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/remoteClimateState', 'erwartet' => 'front', 'abstand' => 60),
        'klima_geblaese' => array('topic' => 'climate/remoteClimateState/set', 'wert' => 'blowingonly',
            'bez' => 'BEFEHL.KLIMA_GEBLAESE', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/remoteClimateState', 'erwartet' => 'blowingonly', 'abstand' => 60),
        'klimatemp' => array('topic' => 'climate/remoteTemperature/set', 'zusatz' => 'temp',
            'bereich' => array(16, 30),
            'bez' => 'BEFEHL.KLIMATEMP', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/remoteTemperature', 'abstand' => 60),
        'sitzheizung_l' => array('topic' => 'climate/heatedSeatsFrontLeftLevel/set', 'zusatz' => 'stufe',
            'bereich' => array(0, 3),
            'bez' => 'BEFEHL.SITZHEIZUNG_L', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/heatedSeatsFrontLeftLevel', 'abstand' => 60),
        'sitzheizung_r' => array('topic' => 'climate/heatedSeatsFrontRightLevel/set', 'zusatz' => 'stufe',
            'bereich' => array(0, 3),
            'bez' => 'BEFEHL.SITZHEIZUNG_R', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/heatedSeatsFrontRightLevel', 'abstand' => 60),
        'heckscheibe_an' => array('topic' => 'climate/rearWindowDefrosterHeating/set', 'wert' => 'on',
            'bez' => 'BEFEHL.HECKSCHEIBE_AN', 'gefahr' => 0, 'gegen' => 'heckscheibe_aus',
            'pruef' => 'climate/rearWindowDefrosterHeating', 'erwartet' => 'on', 'abstand' => 60),
        'heckscheibe_aus' => array('topic' => 'climate/rearWindowDefrosterHeating/set', 'wert' => 'off',
            'bez' => 'BEFEHL.HECKSCHEIBE_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/rearWindowDefrosterHeating', 'erwartet' => 'off', 'abstand' => 60),
        'frontscheibe_an' => array('topic' => 'climate/frontWindowDefrosterHeating/set', 'wert' => 'on',
            'bez' => 'BEFEHL.FRONTSCHEIBE_AN', 'gefahr' => 0, 'gegen' => 'frontscheibe_aus',
            'pruef' => 'climate/frontWindowDefrosterHeating', 'erwartet' => 'on', 'abstand' => 60),
        'frontscheibe_aus' => array('topic' => 'climate/frontWindowDefrosterHeating/set', 'wert' => 'off',
            'bez' => 'BEFEHL.FRONTSCHEIBE_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'climate/frontWindowDefrosterHeating', 'erwartet' => 'off', 'abstand' => 60),
        'batterieheizung_an' => array('topic' => 'drivetrain/batteryHeating/set', 'wert' => 'true',
            'bez' => 'BEFEHL.BATTHEIZ_AN', 'gefahr' => 0, 'gegen' => 'batterieheizung_aus',
            'pruef' => 'drivetrain/batteryHeating', 'erwartet' => '1', 'abstand' => 300),
        'batterieheizung_aus' => array('topic' => 'drivetrain/batteryHeating/set', 'wert' => 'false',
            'bez' => 'BEFEHL.BATTHEIZ_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'drivetrain/batteryHeating', 'erwartet' => '0', 'abstand' => 300),
        /* 'textwert' => 1: die zulaessigen Werte sind Woerter, keine Zahlen.
         * Ein analoger Ausgang kann sie nicht senden - die Ausgangsvorlage
         * macht daraus je Wert einen eigenen DIGITALEN Befehl. */
        'abfrage_modus' => array('topic' => 'refresh/mode/set', 'zusatz' => 'modus',
            'werte' => array('periodic', 'off', 'charging_detection'), 'textwert' => 1,
            'bez' => 'BEFEHL.ABFRAGE_MODUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'refresh/mode', 'abstand' => 60),
        'abfrage_ruhe' => array('topic' => 'refresh/period/inActive/set', 'zusatz' => 'sekunden',
            'bereich' => array(300, 604800),
            'bez' => 'BEFEHL.ABFRAGE_RUHE', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'refresh/period/inActive', 'abstand' => 300),
        'abfrage_aktiv' => array('topic' => 'refresh/period/active/set', 'zusatz' => 'sekunden',
            'bereich' => array(30, 86400),
            'bez' => 'BEFEHL.ABFRAGE_AKTIV', 'gefahr' => 0, 'gegen' => '',
            'pruef' => 'refresh/period/active', 'abstand' => 300),

        /* ---- Ladeplan und Batterieheizplan ----
         *
         * Eigener, dritter Haken (plan_ein). 'pruef' bleibt LEER: das
         * Zustandsthema traegt JSON, und ein Textvergleich zwischen
         * Gesendetem und Veroeffentlichtem wuerde zufaellig mal passen und
         * mal nicht. Diese Befehle melden deshalb ehrlich OK=2 - abgesetzt,
         * Ergebnis unbekannt - statt einen Erfolg zu behaupten. */
        'ladeplan' => array('topic' => 'drivetrain/chargingSchedule/set',
            'nutzlast' => 'ladeplan', 'plan' => 1,
            'bez' => 'BEFEHL.LADEPLAN', 'gefahr' => 0, 'gegen' => '',
            'pruef' => '', 'abstand' => 300),
        'ladeplan_ein' => array('topic' => 'drivetrain/chargingSchedule/set',
            'nutzlast' => 'ladeplan_config', 'plan' => 1,
            'bez' => 'BEFEHL.LADEPLAN_EIN', 'gefahr' => 0, 'gegen' => 'ladeplan_aus',
            'pruef' => '', 'abstand' => 300),
        'ladeplan_aus' => array('topic' => 'drivetrain/chargingSchedule/set',
            'nutzlast' => 'ladeplan_aus', 'plan' => 1,
            'bez' => 'BEFEHL.LADEPLAN_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => '', 'abstand' => 300),
        'heizplan' => array('topic' => 'drivetrain/batteryHeatingSchedule/set',
            'nutzlast' => 'heizplan', 'plan' => 1,
            'bez' => 'BEFEHL.HEIZPLAN', 'gefahr' => 0, 'gegen' => '',
            'pruef' => '', 'abstand' => 300),
        'heizplan_ein' => array('topic' => 'drivetrain/batteryHeatingSchedule/set',
            'nutzlast' => 'heizplan_config', 'plan' => 1,
            'bez' => 'BEFEHL.HEIZPLAN_EIN', 'gefahr' => 0, 'gegen' => 'heizplan_aus',
            'pruef' => '', 'abstand' => 300),
        'heizplan_aus' => array('topic' => 'drivetrain/batteryHeatingSchedule/set',
            'nutzlast' => 'heizplan_aus', 'plan' => 1,
            'bez' => 'BEFEHL.HEIZPLAN_AUS', 'gefahr' => 0, 'gegen' => '',
            'pruef' => '', 'abstand' => 300),

        // ---- Ab hier: eingreifend. Zweiter Haken noetig. ----
        'finden' => array('topic' => 'location/findMyCar/set', 'wert' => 'activate',
            'bez' => 'BEFEHL.FINDEN', 'gefahr' => 1, 'gegen' => 'finden_stopp',
            'pruef' => '', 'abstand' => 60),
        'finden_licht' => array('topic' => 'location/findMyCar/set', 'wert' => 'lights_only',
            'bez' => 'BEFEHL.FINDEN_LICHT', 'gefahr' => 1, 'gegen' => '',
            'pruef' => '', 'abstand' => 60),
        'finden_hupe' => array('topic' => 'location/findMyCar/set', 'wert' => 'horn_only',
            'bez' => 'BEFEHL.FINDEN_HUPE', 'gefahr' => 1, 'gegen' => '',
            'pruef' => '', 'abstand' => 60),
        'finden_stopp' => array('topic' => 'location/findMyCar/set', 'wert' => 'stop',
            'bez' => 'BEFEHL.FINDEN_STOPP', 'gefahr' => 1, 'gegen' => '',
            'pruef' => '', 'abstand' => 5),
        'verriegeln' => array('topic' => 'doors/locked/set', 'wert' => 'true',
            'bez' => 'BEFEHL.VERRIEGELN', 'gefahr' => 1, 'gegen' => 'entriegeln',
            'pruef' => 'doors/locked', 'erwartet' => '1', 'abstand' => 60),
        'entriegeln' => array('topic' => 'doors/locked/set', 'wert' => 'false',
            'bez' => 'BEFEHL.ENTRIEGELN', 'gefahr' => 1, 'gegen' => '',
            'pruef' => 'doors/locked', 'erwartet' => '0', 'abstand' => 60),
        'kofferraum_auf' => array('topic' => 'doors/boot/set', 'wert' => 'false',
            'bez' => 'BEFEHL.KOFFERRAUM_AUF', 'gefahr' => 1, 'gegen' => '',
            'pruef' => '', 'abstand' => 60),
    );
}

/**
 * Die alten Befehlsnamen aus 1.0.8.
 *
 * Sie bleiben gueltig, sonst braechen alle beim Anwender eingetragenen
 * Loxone-Adressen. Jeder loest denselben Befehl mit festem Zusatzwert aus.
 */
function mg_aliasse()
{
    $a = array();
    foreach (array(60, 70, 80, 90, 100) as $p) {
        $a['ziel_' . $p] = array('ziel', (string) $p);
    }
    foreach (array('6' => '6A', '8' => '8A', '16' => '16A', 'max' => 'MAX') as $k => $v) {
        $a['strom_' . $k] = array('strom', $v);
    }
    return $a;
}

/**
 * Eine Uhrzeit pruefen und auf HH:MM bringen.
 *
 * Das Gateway liest sie mit time.fromisoformat(); das nimmt HH:MM und
 * HH:MM:SS. Alles andere wird ABGEWIESEN, nicht zurechtgebogen - eine still
 * auf 00:00 gerundete Ladezeit waere eine Falschaussage gegenueber dem, der
 * sie geschickt hat.
 */
function mg_uhrzeit($v)
{
    $v = trim((string) $v);
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $v, $t)) {
        return '';
    }
    return $t[1] . ':' . $t[2];
}

/** Die zulaessigen Werte fuer mode des Ladeplans. */
function mg_planmodi()
{
    /* Belegt in saic_ismart_client_ng, api/vehicle_charging/schema.py:
     *     class ScheduledChargingMode(Enum):
     *         DISABLED = 2
     *         UNTIL_CONFIGURED_SOC = 3
     *         UNTIL_CONFIGURED_TIME = 1
     * Der Handler des Gateways schreibt den Wert selbst gross
     * (payload_json["mode"].upper()), Kleinschreibung ist also erlaubt. */
    return array('disabled', 'until_configured_soc', 'until_configured_time');
}

/**
 * Die JSON-Nutzlast fuer Lade- und Heizplan bauen.
 *
 * GESTALT BELEGT, WIRKUNG NICHT.
 * Die Felder stammen aus den Handlern des Gateways:
 *   src/handlers/command/drivetrain/drivetrain_charging_schedule.py
 *       time.fromisoformat(payload["startTime"])
 *       time.fromisoformat(payload["endTime"])
 *       ScheduledChargingMode[payload["mode"].upper()]
 *   src/handlers/command/drivetrain/drivetrain_battery_heating_schedule.py
 *       time.fromisoformat(payload["startTime"])
 *       payload["mode"].upper() == "ON"
 * Was NICHT belegt ist: ob das FAHRZEUG den Plan annimmt. Das kann keine
 * Quelle beantworten, nur ein Auto. Deshalb der eigene Haken plan_ein, ab
 * Werk aus, und deshalb tragen diese Befehle kein "pruef" - sie melden OK=2
 * (abgesetzt, Ergebnis unbekannt), statt einen Erfolg zu behaupten.
 *
 * Rueckgabe: array(ok, code, jsontext)
 */
function mg_nutzlast($art, $wert, $cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $feld = is_array($wert) ? $wert : array();
    $hol = function ($name) use ($feld) {
        return (isset($feld[$name]) && !is_array($feld[$name])) ? (string) $feld[$name] : '';
    };

    if ($art === 'ladeplan' || $art === 'ladeplan_config' || $art === 'ladeplan_aus') {
        if ($art === 'ladeplan') {
            $von = mg_uhrzeit($hol('von'));
            $bis = mg_uhrzeit($hol('bis'));
            $modus = strtolower(trim($hol('modus')));
            if (trim($hol('von')) === '' || trim($hol('bis')) === '' || $modus === '') {
                return array(0, 'WERT_FEHLT', '');
            }
            if ($von === '' || $bis === '') {
                return array(0, 'WERT_UNZULAESSIG', '');
            }
        } else {
            $von = mg_uhrzeit($cfg['plan_von']);
            $bis = mg_uhrzeit($cfg['plan_bis']);
            $modus = ($art === 'ladeplan_aus')
                ? 'disabled' : strtolower(trim((string) $cfg['plan_modus']));
            if ($von === '' || $bis === '') {
                return array(0, 'WERT_UNZULAESSIG', '');
            }
        }
        if (!in_array($modus, mg_planmodi(), true)) {
            return array(0, 'WERT_UNZULAESSIG', '');
        }
        return array(1, '', json_encode(array(
            'startTime' => $von, 'endTime' => $bis, 'mode' => strtoupper($modus),
        ), JSON_UNESCAPED_SLASHES));
    }

    if ($art === 'heizplan' || $art === 'heizplan_config' || $art === 'heizplan_aus') {
        if ($art === 'heizplan') {
            $von = mg_uhrzeit($hol('von'));
            $modus = strtolower(trim($hol('modus')));
            if (trim($hol('von')) === '' || $modus === '') {
                return array(0, 'WERT_FEHLT', '');
            }
            if ($von === '') {
                return array(0, 'WERT_UNZULAESSIG', '');
            }
        } else {
            $von = mg_uhrzeit($cfg['heizplan_von']);
            $modus = ($art === 'heizplan_aus') ? 'off' : 'on';
            if ($von === '') {
                return array(0, 'WERT_UNZULAESSIG', '');
            }
        }
        if (!in_array($modus, array('on', 'off'), true)) {
            return array(0, 'WERT_UNZULAESSIG', '');
        }
        return array(1, '', json_encode(array(
            'startTime' => $von, 'mode' => strtoupper($modus),
        ), JSON_UNESCAPED_SLASHES));
    }
    return array(0, 'UNBEKANNT', '');
}

/** Aus Befehlsname und Zusatzwert das Paar (Thema, Wert) bilden - oder einen Fehler. */
function mg_befehl_aufloesen($befehl, $wert = null)
{
    $befehl = (string) $befehl;
    $alias = mg_aliasse();
    if (isset($alias[$befehl])) {
        $wert = $alias[$befehl][1];
        $befehl = $alias[$befehl][0];
    }
    $liste = mg_befehle();
    if (!isset($liste[$befehl])) {
        return array(0, 'UNBEKANNT', $befehl, '', '');
    }
    $b = $liste[$befehl];
    if (!empty($b['nutzlast'])) {
        list($nok, $ncode, $njson) = mg_nutzlast($b['nutzlast'], $wert);
        return $nok ? array(1, '', $befehl, $b['topic'], $njson)
                    : array(0, $ncode, $befehl, '', '');
    }
    /* Kommt der Zusatzwert als Feld (so sammelt ihn der Endpunkt), wird
     * daraus der Eintrag genommen, den DIESER Befehl braucht. */
    if (is_array($wert)) {
        $wert = (!empty($b['zusatz']) && isset($wert[$b['zusatz']])
                 && !is_array($wert[$b['zusatz']])) ? $wert[$b['zusatz']] : null;
    }
    if (empty($b['zusatz'])) {
        return array(1, '', $befehl, $b['topic'], (string) $b['wert']);
    }
    /* Ein Wert ausserhalb der Liste wird ABGEWIESEN, nicht zurechtgebogen.
     * Ein stillschweigend gerundeter Zielladestand waere eine Falschaussage
     * gegenueber dem, der ihn geschickt hat. */
    $wert = trim((string) $wert);
    if ($wert === '') {
        return array(0, 'WERT_FEHLT', $befehl, '', '');
    }
    if (isset($b['norm']) && $b['norm'] === 'ampere' && preg_match('/^\d+$/', $wert)) {
        // 32 und mehr heisst MAX; 0 waere kein gueltiger Ladestrom.
        $wert = ((int) $wert >= 32) ? 'MAX' : ((int) $wert . 'A');
    }
    if (isset($b['werte'])) {
        foreach ($b['werte'] as $zul) {
            if (strcasecmp((string) $zul, $wert) === 0) {
                return array(1, '', $befehl, $b['topic'], (string) $zul);
            }
        }
        return array(0, 'WERT_UNZULAESSIG', $befehl, '', '');
    }
    if (isset($b['bereich'])) {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $wert)) {
            return array(0, 'WERT_UNZULAESSIG', $befehl, '', '');
        }
        $z = (float) $wert;
        if ($z < $b['bereich'][0] || $z > $b['bereich'][1]) {
            return array(0, 'WERT_AUSSER_BEREICH', $befehl, '', '');
        }
        return array(1, '', $befehl, $b['topic'], (string) (int) round($z));
    }
    return array(0, 'UNBEKANNT', $befehl, '', '');
}

/**
 * Einen Zusatzwert lesbar machen - auch wenn er ein Feld ist.
 *
 * WARUM DAS EINE EIGENE FUNKTION IST:
 * Seit der Endpunkt alle Zusatzwerte als Feld einsammelt, ist $wert bei den
 * Planbefehlen ein Feld. Ein "(string) $wert" darauf ergibt woertlich "Array"
 * und unter PHP 8 zusaetzlich eine Warning - und die wird AUSGEGEBEN, bevor
 * http_response_code() laufen kann. Gemessen: die Abweisung eines falschen
 * Ladeplans ging ohne Statuscode hinaus, also als HTTP 200.
 *
 * Das ist dieselbe Fehlerklasse, die in mg.php schon einmal behoben wurde
 * (mg_get()). Sie ist hier ein zweites Mal entstanden, weil sich der Typ des
 * Parameters geaendert hat - und das ist die Lehre: wer einen Typ erweitert,
 * sucht jede Stelle, die ihn in eine Zeichenkette zwingt.
 */
function mg_wert_text($wert)
{
    if (!is_array($wert)) {
        return (string) $wert;
    }
    $teile = array();
    foreach ($wert as $k => $v) {
        if (!is_array($v)) {
            $teile[] = $k . '=' . (string) $v;
        }
    }
    return implode(' ', $teile);
}

/** Merkdatei der zuletzt gesendeten Befehle (fuer die Drosselung). */
function mg_gesendet_lesen()
{
    $d = mg_json_lesen(mg_paths()['tmp'] . '/gesendet.json');
    return isset($d['liste']) && is_array($d['liste']) ? $d : array('liste' => array());
}

/**
 * Darf dieser Befehl jetzt gesendet werden?
 *
 * Zwei Bremsen. Erstens ein Mindestabstand je Befehl und Fahrzeug: ein
 * virtueller Ausgang, der bei jedem Zyklus feuert, sendet sonst bei jedem
 * Zyklus ans Auto - und jedes Senden weckt die Fahrzeugelektronik.
 * Zweitens eine Obergrenze je Stunde ueber alle Befehle.
 *
 * Rueckgabe: array(darf, restsekunden) - restsekunden -1 heisst
 * "Stundengrenze erreicht".
 */
function mg_drossel_pruefen($befehl, $nr, $cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $liste = mg_befehle();
    $abstand = isset($liste[$befehl]['abstand']) ? (int) $liste[$befehl]['abstand'] : 60;
    if ($befehl === 'strom') {
        $abstand = max($abstand, (int) $cfg['strom_abstand']);
    } else {
        $abstand = max($abstand, (int) $cfg['befehl_abstand']);
    }
    $d = mg_gesendet_lesen();
    $jetzt = time();
    $schluessel = (int) $nr . ':' . $befehl;
    if (isset($d['liste'][$schluessel]) && $jetzt - (int) $d['liste'][$schluessel] < $abstand) {
        return array(0, (int) ($abstand - ($jetzt - (int) $d['liste'][$schluessel])));
    }
    $stunde = 0;
    foreach ($d['liste'] as $z) {
        if ($jetzt - (int) $z < 3600) { $stunde++; }
    }
    if ($stunde >= max(1, (int) $cfg['befehle_stunde'])) {
        return array(0, -1);
    }
    return array(1, 0);
}

function mg_drossel_merken($befehl, $nr)
{
    $d = mg_gesendet_lesen();
    $jetzt = time();
    $d['liste'][(int) $nr . ':' . $befehl] = $jetzt;
    foreach ($d['liste'] as $k => $z) {
        if ($jetzt - (int) $z > 7200) { unset($d['liste'][$k]); }
    }
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    mg_write_json($p['tmp'] . '/gesendet.json', $d);
}

/**
 * Einen Befehl absetzen.
 *
 * Rueckgabe array(ok, meldung, code):
 *   ok = 1  die Wirkung wurde gesehen (oder der Zielzustand lag schon an)
 *   ok = 2  abgesetzt, Ergebnis in der Wartezeit unbekannt
 *   ok = 0  abgewiesen oder das Gateway hat einen Fehler gemeldet
 *
 * Bis 1.0.8 galt der Rueckgabewert von mosquitto_pub als Erfolg. Der beweist
 * genau eines: die Nachricht hat den BROKER erreicht. Ob das Gateway sie
 * angenommen und ob das Auto sie ausgefuehrt hat, sagt er nicht - und die
 * eigene Anleitung schreibt ausdruecklich, dass "Laden starten" unzuverlaessig
 * ist. Geprueft wird deshalb die Wirkung am Zustandsthema.
 */
function mg_send($befehl, $wert = null, $nr = 1)
{
    $cfg = mg_config();
    if (empty($cfg['commands'])) {
        return array(0, mg_t('MELDUNG.GESPERRT'), 'GESPERRT');
    }
    list($ok, $code, $name, $topic, $sendewert) = mg_befehl_aufloesen($befehl, $wert);
    if (!$ok) {
        /* Die Meldung nennt, WAS abgewiesen wurde: bei einem unbekannten
         * Namen den Namen, bei einem unzulaessigen Zusatzwert den Wert.
         * "nicht zulaessig: ziel" schickt den Leser sonst auf die Suche nach
         * einem Fehler im Befehlsnamen, den es nicht gibt. */
        $was = ($code === 'UNBEKANNT') ? $befehl : ($befehl . ' = ' . mg_wert_text($wert));
        return array(0, mg_t('MELDUNG.' . $code) . ': ' . mg_kuerzen($was, 60), $code);
    }
    $liste = mg_befehle();
    if (!empty($liste[$name]['gefahr']) && empty($cfg['gefahr_ein'])) {
        return array(0, mg_t('MELDUNG.EINGREIFEND_GESPERRT'), 'EINGREIFEND_GESPERRT');
    }
    if (!empty($liste[$name]['plan']) && empty($cfg['plan_ein'])) {
        return array(0, mg_t('MELDUNG.PLAN_GESPERRT'), 'PLAN_GESPERRT');
    }
    if (!mg_has_mosquitto()) {
        return array(0, mg_t('MELDUNG.KEIN_MOSQUITTO'), 'KEIN_MOSQUITTO');
    }
    $base = mg_base_topic($nr);
    if ($base === '') {
        return array(0, mg_t('MELDUNG.NICHT_EINGERICHTET'), 'NICHT_EINGERICHTET');
    }

    /* Kein erneutes Senden, wenn der Zielzustand schon anliegt. "Ziel 80 %"
     * an ein Auto, das auf 80 steht, ist eine vermeidbare Weckung. */
    if (!empty($liste[$name]['pruef'])) {
        $ist = mg_txt($liste[$name]['pruef'], '', $nr);
        if ($ist !== '' && mg_wirkung_gleich($ist, $liste[$name], $sendewert)) {
            return array(1, mg_t('MELDUNG.SCHON_SO'), 'SCHON_SO');
        }
    }

    list($darf, $rest) = mg_drossel_pruefen($name, $nr, $cfg);
    if (!$darf) {
        return array(0, $rest >= 0
            ? mg_t('MELDUNG.GEDROSSELT') . ' (' . $rest . ' s)'
            : mg_t('MELDUNG.STUNDENGRENZE'), 'GEDROSSELT');
    }

    $cmd = mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
         . ' -t ' . escapeshellarg($base . '/' . $topic)
         . ' -m ' . escapeshellarg($sendewert) . ' 2>&1';
    $out = array();
    @exec($cmd, $out, $rc);
    mg_drossel_merken($name, $nr);
    if ($rc !== 0) {
        $text = trim(implode(' ', $out));
        mg_log('FEHLER Befehl "' . $name . '": ' . ($text !== '' ? $text : 'Fehlercode ' . $rc));
        return array(0, $text !== '' ? $text : ('Fehlercode ' . $rc), 'BROKER');
    }
    mg_log('Befehl gesendet (Fahrzeug ' . (int) $nr . '): ' . $name
        . ' -> ' . $topic . ' = ' . $sendewert);

    if (empty($cfg['wirkung_pruefen']) || empty($liste[$name]['pruef'])) {
        return array(2, mg_t('MELDUNG.ABGESETZT'), 'ABGESETZT');
    }

    // Die Wirkung nachsehen. Das Gateway braucht dafuer einige Sekunden.
    sleep(max(2, min(20, (int) $cfg['wartezeit'])));
    mg_snapshot(3);
    $fehler = mg_txt('command/error', '', $nr);
    if ($fehler !== '') {
        mg_log('Gateway meldet Fehler zu "' . $name . '": ' . $fehler);
        return array(0, mg_t('MELDUNG.GATEWAY_FEHLER') . ': ' . mg_kuerzen($fehler, 120),
            'GATEWAY_FEHLER');
    }
    $ist = mg_txt($liste[$name]['pruef'], '', $nr);
    if ($ist !== '' && mg_wirkung_gleich($ist, $liste[$name], $sendewert)) {
        return array(1, mg_t('MELDUNG.GEWIRKT'), 'OK');
    }
    return array(2, mg_t('MELDUNG.ABGESETZT'), 'ABGESETZT');
}

/** Passt der gelesene Ist-Wert zu dem, was gesendet wurde? */
function mg_wirkung_gleich($ist, $bdef, $sendewert)
{
    $ist = trim((string) $ist);
    if (isset($bdef['erwartet'])) {
        $e = (string) $bdef['erwartet'];
        if ($e === '1' || $e === '0') {
            return mg_bool_wert($ist, -1) === (int) $e;
        }
        return strcasecmp($ist, $e) === 0;
    }
    if (is_numeric($ist) && is_numeric($sendewert)) {
        return abs((float) $ist - (float) $sendewert) < 0.51;
    }
    return strcasecmp($ist, (string) $sendewert) === 0;
}

/* ---------------- Meldungen ---------------- */

function mg_meldungsdatei($nr = 1)
{
    return mg_paths()['tmp'] . '/meldung' . (int) $nr . '.json';
}

function mg_push_active($nr = 1)
{
    $cfg = mg_config();
    if (empty($cfg['notify']['push'])) {
        return 0;
    }
    $m = mg_json_lesen(mg_meldungsdatei($nr));
    if (empty($m['zeit'])) {
        return 0;
    }
    $min = max(1, (int) $cfg['notify']['push_minutes']);
    return (time() - strtotime($m['zeit'])) < $min * 60 ? 1 : 0;
}

function mg_push_text($nr = 1)
{
    $m = mg_json_lesen(mg_meldungsdatei($nr));
    return !empty($m['text']) ? (string) $m['text'] : '';
}

function mg_ptest_ausloesen()
{
    $p = mg_paths();
    @mkdir($p['tmp'], 0775, true);
    return mg_write_atomic($p['tmp'] . '/ptest', (string) time());
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

/**
 * Ereignisse erkennen (wird vom Cron nach jeder Momentaufnahme aufgerufen).
 *
 * Jeder Zweig prueft ausdruecklich auf "bekannt". Bis 1.0.8 fiel der
 * Ladeziel-Zweig auf 0 zurueck, wenn das Zielthema einmal fehlte - "Ziel
 * unbekannt" und "Ziel nicht erreicht" waren ununterscheidbar, und beim
 * naechsten vollstaendigen Durchgang stieg die Flanke ein zweites Mal.
 * Gemessen ueber fuenf Durchgaenge: Meldung in Durchgang 2 und noch einmal
 * in Durchgang 5.
 */
function mg_check_events($st, $nr = 1)
{
    $cfg = mg_config();
    $p = mg_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    $vf = $p['tmp'] . '/vorher' . (int) $nr . '.json';
    $vorher = mg_json_lesen($vf);
    $melden = '';
    $n = $cfg['notify'];
    $fz = mg_fahrzeuge($cfg);
    $wagen = isset($fz[(int) $nr]) ? $fz[(int) $nr]['name'] : ('MG ' . (int) $nr);

    if ($vorher) {
        $v = function ($k) use ($vorher) {
            return isset($vorher[$k]) ? (int) $vorher[$k] : -1;
        };
        if (!empty($n['soc_voll']) && (int) $st['VOLL'] === 1 && $v('VOLL') === 0) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.GELADEN') . ' ' . round($st['SOC']) . ' %.';
        } elseif (!empty($n['stecker']) && (int) $st['STECKER'] === 1 && $v('STECKER') === 0) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.KABEL_EIN');
        } elseif (!empty($n['stecker']) && (int) $st['STECKER'] === 0 && $v('STECKER') === 1) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.KABEL_AB');
        } elseif (!empty($n['offen']) && (int) $st['ZU'] === 0 && $v('ZU') === 1) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.UNVERSCHLOSSEN');
        } elseif (!empty($n['fenster']) && (int) $st['FENSTEROFFEN'] > 0 && $v('FENSTEROFFEN') === 0) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.FENSTER_OFFEN');
        } elseif (!empty($n['fehler']) && (int) $st['FEHLER'] === 1 && $v('FEHLER') === 0) {
            $melden = $wagen . ': ' . mg_t('MELDUNG.BEFEHL_FEHLER');
        }
    }
    // Nur die Feldwerte merken - der Rest wechselt ohnehin bei jedem Lauf.
    $merk = array();
    foreach (mg_felder() as $name => $info) {
        $merk[$name] = isset($st[$name]) ? $st[$name] : -1;
    }
    mg_write_json($vf, $merk);
    if ($melden !== '') {
        mg_write_json(mg_meldungsdatei($nr), array('zeit' => date('c'), 'text' => $melden));
        mg_log('Meldung: ' . $melden);
    }
    return array($melden, $vorher);
}

/* ==================================================================
 * Ladungen mitschreiben
 *
 * Aus drivetrain/charging/lastStart, .../lastEnd, soc_kwh und
 * powerUsageSinceLastCharge laesst sich je Ladevorgang eine Zeile bilden.
 * Wer einen Stromtarif rechnet, braucht kWh je Ladung, nicht Prozentpunkte.
 * ================================================================== */

function mg_ladungen_datei()
{
    return mg_paths()['data'] . '/ladungen.json';
}

function mg_ladungen_lesen($grenze = 200)
{
    $d = mg_json_lesen(mg_ladungen_datei());
    $l = isset($d['liste']) && is_array($d['liste']) ? $d['liste'] : array();
    return array_slice(array_reverse($l), 0, max(1, (int) $grenze));
}

/**
 * Eine abgeschlossene Ladung erkennen und fortschreiben.
 * Erkannt wird an der Flanke von LAEDT 1 -> 0.
 */
function mg_ladung_pruefen($st, $vorher, $nr = 1)
{
    $cfg = mg_config();
    if (empty($cfg['ladungen_ein']) || !is_array($vorher)) {
        return false;
    }
    $war = isset($vorher['LAEDT']) ? (int) $vorher['LAEDT'] : -1;
    if ($war !== 1 || (int) $st['LAEDT'] === 1) {
        return false;
    }
    $beginn = mg_num('drivetrain/charging/lastStart', -1, $nr);
    $ende = mg_num('drivetrain/charging/lastEnd', -1, $nr);
    $d = mg_json_lesen(mg_ladungen_datei());
    $liste = isset($d['liste']) && is_array($d['liste']) ? $d['liste'] : array();
    $kennung = (int) $nr . '-' . (int) $beginn;
    foreach ($liste as $e) {
        if (isset($e['id']) && $e['id'] === $kennung) {
            return false;   // schon eingetragen
        }
    }
    $eintrag = array(
        'id' => $kennung,
        'fz' => (int) $nr,
        'beginn' => $beginn > 0 ? date('c', (int) $beginn) : '',
        'ende' => $ende > 0 ? date('c', (int) $ende) : date('c'),
        'dauer_min' => ($beginn > 0 && $ende > $beginn) ? (int) round(($ende - $beginn) / 60) : -1,
        'soc_start' => isset($vorher['SOC']) ? (float) $vorher['SOC'] : -1,
        'soc_ende' => isset($st['SOC']) ? (float) $st['SOC'] : -1,
        'kwh' => mg_num('drivetrain/lastChargeEndingPower', -1, $nr),
        'km' => isset($st['KM']) ? (float) $st['KM'] : -1,
        'verbrauch' => isset($vorher['VERBRLADUNG']) ? (float) $vorher['VERBRLADUNG'] : -1,
        'strecke' => isset($vorher['KMLADUNG']) ? (float) $vorher['KMLADUNG'] : -1,
    );
    $liste[] = $eintrag;
    $liste = array_slice($liste, -max(10, (int) $cfg['ladungen_max']));
    mg_write_json(mg_ladungen_datei(), array('liste' => $liste));
    mg_log('Ladung eingetragen: Fahrzeug ' . (int) $nr . ', '
        . ($eintrag['dauer_min'] >= 0 ? $eintrag['dauer_min'] . ' min' : 'Dauer unbekannt')
        . ', SoC ' . $eintrag['soc_start'] . ' -> ' . $eintrag['soc_ende'] . ' %');
    return true;
}

/* ==================================================================
 * Fremde Themen: Vorklimatisierung und Ladeempfehlung
 *
 * Der Horcher fuehrt seine Themenliste an EINER Stelle. Fuehrte sie zweimal -
 * einmal im Dienst, einmal in der Oberflaeche -, muesste eine Selbstpruefung
 * beide vergleichen; ein Abo, das der Dienst nach einer Aenderung nicht
 * nachgezogen hat, ist sonst unsichtbar.
 * ================================================================== */

function mg_horcher_themen($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $t = array();
    if (!empty($cfg['abfahrt_ein'])) {
        $pfad = trim((string) $cfg['abfahrt_praefix'], '/ ');
        if ($pfad !== '') {
            $t[] = $pfad . '/ABFAHRT_IN';
            $t[] = $pfad . '/OK';
        }
    }
    if (!empty($cfg['ladeempf_ein'])) {
        $th = trim((string) $cfg['ladeempf_thema']);
        if ($th !== '') {
            $t[] = $th;
        }
    }
    sort($t);
    return $t;
}

/** Die fremden Themen einlesen und ablegen. */
function mg_horcher_lesen($sekunden = 2)
{
    $themen = mg_horcher_themen();
    if (!$themen) {
        return array();
    }
    list($werte, , ) = mg_sub($themen, $sekunden);
    $p = mg_paths();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    mg_write_json($p['data'] . '/fremd.json', array(
        'zeit' => date('c'), 'werte' => $werte,
    ));
    return $werte;
}

function mg_horcher_zustand()
{
    $d = mg_json_lesen(mg_paths()['data'] . '/fremd.json');
    return array(
        'zeit' => isset($d['zeit']) ? (string) $d['zeit'] : '',
        'werte' => isset($d['werte']) && is_array($d['werte']) ? $d['werte'] : array(),
        'themen' => mg_horcher_themen(),
    );
}

/**
 * Die Automatiken auswerten. Rueckgabe: Liste der Meldungen.
 *
 * Beide Automatiken merken sich, was sie zuletzt getan haben - sonst senden
 * sie bei jedem Cron-Lauf denselben Befehl, und jedes Senden weckt das Auto.
 */
function mg_automatik()
{
    $cfg = mg_config();
    $meldungen = array();
    if (empty($cfg['abfahrt_ein']) && empty($cfg['ladeempf_ein'])) {
        return $meldungen;
    }
    $werte = mg_horcher_lesen(2);
    $p = mg_paths();
    $merk = mg_json_lesen($p['tmp'] . '/automatik.json');
    $jetzt = time();

    // --- Vorklimatisierung ---
    if (!empty($cfg['abfahrt_ein'])) {
        $pfad = trim((string) $cfg['abfahrt_praefix'], '/ ');
        $in = isset($werte[$pfad . '/ABFAHRT_IN'])
            ? (float) str_replace(',', '.', $werte[$pfad . '/ABFAHRT_IN']) : -1;
        $ok = isset($werte[$pfad . '/OK']) ? mg_bool_wert($werte[$pfad . '/OK'], 0) : 0;
        $vorlauf = max(1, (int) $cfg['abfahrt_vorlauf']);
        $nr = max(1, (int) $cfg['abfahrt_fahrzeug']);
        $zuletzt = isset($merk['abfahrt']) ? (int) $merk['abfahrt'] : 0;
        if ($ok === 1 && $in >= 0 && $in <= $vorlauf
            && $jetzt - $zuletzt > max(600, $vorlauf * 60)) {
            mg_send('klimatemp', (string) (int) $cfg['abfahrt_temp'], $nr);
            list($o2, $m2) = mg_send('klima_an', null, $nr);
            $merk['abfahrt'] = $jetzt;
            $meldungen[] = 'Vorklimatisierung (' . (int) $in . ' min bis Abfahrt): ' . $m2;
            mg_log('Automatik Vorklimatisierung: Fahrzeug ' . $nr . ', ' . (int) $in
                . ' min bis Abfahrt, Zieltemperatur ' . (int) $cfg['abfahrt_temp'] . ' Grad -> ' . $m2);
        }
    }

    // --- Ladeempfehlung ---
    if (!empty($cfg['ladeempf_ein'])) {
        $th = trim((string) $cfg['ladeempf_thema']);
        $nr = max(1, (int) $cfg['ladeempf_fahrzeug']);
        if ($th !== '' && isset($werte[$th])
            && is_numeric(str_replace(',', '.', $werte[$th]))) {
            $wert = (float) str_replace(',', '.', $werte[$th]);
            $grenze = (float) $cfg['ladeempf_grenze'];
            $hoch = !empty($cfg['ladeempf_unter']) ? ($wert < $grenze) : ($wert > $grenze);
            $soll = $hoch ? (string) $cfg['ladeempf_hoch'] : (string) $cfg['ladeempf_runter'];
            $zuletzt = isset($merk['ladeempf']) ? (string) $merk['ladeempf'] : '';
            if ($soll !== '' && $soll !== $zuletzt) {
                list($o, $m) = mg_send($soll, null, $nr);
                if ($o) {
                    $merk['ladeempf'] = $soll;
                    $meldungen[] = 'Ladeempfehlung: ' . $soll . ' (' . $wert . ') -> ' . $m;
                    mg_log('Automatik Ladeempfehlung: ' . $th . ' = ' . $wert
                        . ' -> ' . $soll . ' -> ' . $m);
                }
            }
        }
    }
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    mg_write_json($p['tmp'] . '/automatik.json', $merk);
    return $meldungen;
}

/* ==================================================================
 * Eigene MQTT-Veroeffentlichung
 *
 * Die Hausregeln nennen MQTT den Regelweg. Ein Abo auf saic/# waere zwar
 * moeglich, aber die Namen der virtuellen Eingaenge traegen dann den
 * iSMART-Benutzernamen. Hier stehen die UMGESETZTEN Werte unter einem
 * kurzen, lesbaren Praefix - erzeugt aus derselben Feldliste wie die
 * Loxone-Zeile, damit die Tabelle im Reiter MQTT nicht davon abweichen kann.
 * ================================================================== */

function mg_mqtt_themen()
{
    $t = array();
    foreach (mg_felder() as $name => $info) {
        $t['<n>/' . $info['mqtt']] = $info['bez'];
    }
    foreach (array(
        'name' => 'MQTT.NAME', 'vin' => 'MQTT.VIN',
        'klima_text' => 'MQTT.KLIMA_TEXT', 'stromgrenze_text' => 'MQTT.GRENZE_TEXT',
        'tueren_namen' => 'MQTT.TUEREN', 'fenster_namen' => 'MQTT.FENSTER',
        'fehlertext' => 'MQTT.FEHLERTEXT', 'meldung' => 'MQTT.MELDUNG',
        'ladeplan' => 'MQTT.LADEPLAN', 'heizplan' => 'MQTT.HEIZPLAN',
        'abbruchgrund' => 'MQTT.ABBRUCH',
        'fahrzeugmeldung' => 'MQTT.FAHRZEUGMELDUNG',
    ) as $k => $b) {
        $t['<n>/' . $k] = $b;
    }
    return $t;
}

function mg_mqtt_werte($nr, $st)
{
    $cfg = mg_config();
    $fz = mg_fahrzeuge($cfg);
    $paare = array();
    foreach (mg_felder() as $name => $info) {
        $v = isset($st[$name]) ? $st[$name] : -1;
        $paare[$info['mqtt']] = $info['analog']
            ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')
            : (string) (int) $v;
    }
    $teile = function ($liste) {
        $aus = array();
        foreach ((array) $liste as $s) { $aus[] = mg_t($s); }
        return implode(', ', $aus);
    };
    $paare['name'] = isset($fz[$nr]) ? $fz[$nr]['name'] : ('MG ' . (int) $nr);
    $paare['vin'] = isset($fz[$nr]) ? $fz[$nr]['vin'] : '';
    $paare['klima_text'] = isset($st['_klimatext']) ? (string) $st['_klimatext'] : '';
    $paare['stromgrenze_text'] = isset($st['_grenze']) ? (string) $st['_grenze'] : '';
    $paare['tueren_namen'] = isset($st['_tueren']) ? $teile($st['_tueren']) : '';
    $paare['fenster_namen'] = isset($st['_fenster']) ? $teile($st['_fenster']) : '';
    $paare['fehlertext'] = isset($st['_fehlertext']) ? mg_kuerzen($st['_fehlertext'], 200) : '';
    $paare['meldung'] = mg_push_text($nr);
    $paare['ladeplan'] = isset($st['_ladeplan']) ? mg_kuerzen($st['_ladeplan'], 200) : '';
    $paare['heizplan'] = isset($st['_heizplan']) ? mg_kuerzen($st['_heizplan'], 200) : '';
    $paare['abbruchgrund'] = isset($st['_abbruchgrund']) ? mg_kuerzen($st['_abbruchgrund'], 200) : '';
    $paare['fahrzeugmeldung'] = isset($st['_fahrzeugmeldung'])
        ? mg_kuerzen($st['_fahrzeugmeldung'], 200) : '';
    return $paare;
}

/**
 * Die Werte eines Fahrzeugs unter dem eigenen Praefix veroeffentlichen.
 *
 * mosquitto_pub kann mit -l zwar viele Nachrichten aus der Standardeingabe
 * senden, aber nur an EIN festes Thema. Deshalb wird die Themenliste in eine
 * Datei geschrieben und in EINER Shell-Schleife abgearbeitet - ein Prozess
 * statt eines Aufrufs je Feld aus PHP heraus.
 */
/**
 * Die Argumente je Thema, fertig angefuehrt - ohne sie auszufuehren.
 *
 * Dafuer gibt es diese Funktion getrennt: die Veroeffentlichung liess sich bis
 * 1.1.2 nirgends pruefen, ohne einen Broker zu haben. Der Fehler steckte aber
 * gar nicht im Broker, sondern in der Zerlegung der Befehlszeile durch die
 * Schale - und das laesst sich ohne jedes Netz messen (mg_mqtt_probe()).
 */
function mg_mqtt_argumente($nr, $st)
{
    $cfg = mg_config();
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($praefix === '') {
        return array();
    }
    $basis = $praefix . '/' . (int) $nr . '/';
    $aus = array();
    foreach (mg_mqtt_werte($nr, $st) as $name => $wert) {
        $aus[$basis . $name] = str_replace(array("\r", "\n"), ' ', (string) $wert);
    }
    return $aus;
}

/** Eine vollstaendige mosquitto_pub-Zeile fuer ein Thema. */
function mg_mqtt_zeile($thema, $wert)
{
    return mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
         . ' -r -t ' . escapeshellarg($thema)
         . ' -m ' . escapeshellarg($wert);
}

function mg_mqtt_senden($nr, $st)
{
    $cfg = mg_config();
    if (empty($cfg['mqtt_ein']) || !mg_has_mosquitto()) {
        return 0;
    }
    $paare = mg_mqtt_argumente($nr, $st);
    if (!$paare) {
        return 0;
    }

    /* Nur senden, was sich geaendert hat.
     *
     * Achtundsechzig Themen je Fahrzeug und Minute waeren achtundsechzig
     * Prozesse je Minute - auf einem LoxBerry mit SD-Karte ist das kein
     * Schoenheitsfehler. Im Beharrungszustand aendert sich fast nichts.
     * Alle halbe Stunde geht der ganze Satz hinaus, damit ein neu gestarteter
     * Broker die behaltenen Werte wiederbekommt. */
    $merk = mg_paths()['tmp'] . '/veroeffentlicht' . (int) $nr . '.json';
    $alt = mg_json_lesen($merk);
    $vollstaendig = (!isset($alt['zeit']) || (time() - (int) $alt['zeit']) > 1800
                     || !isset($alt['werte']) || !is_array($alt['werte'])
                     || array_keys($alt['werte']) !== array_keys($paare));
    $zu_senden = array();
    foreach ($paare as $thema => $wert) {
        if ($vollstaendig || !isset($alt['werte'][$thema])
            || (string) $alt['werte'][$thema] !== (string) $wert) {
            $zu_senden[$thema] = $wert;
        }
    }
    if (!$zu_senden) {
        return 0;
    }

    /* DIE ARGUMENTE FUEHRT PHP AN, DIE SCHALE ZERLEGT NICHTS MEHR.
     *
     * Bis 1.1.2 stand hier eine Schleife, die eine Datei mit Tabulatoren
     * zurueckgelesen hat:
     *
     *     'while IFS="\t" read -r t v; do ...'
     *
     * Das ist ein EINFACH angefuehrter PHP-String - an die Schale ging
     * woertlich IFS="\t", und in einer POSIX-Schale sind das die beiden
     * Zeichen Backslash und t, nicht der Tabulator. Gemessen mit dash ueber
     * die echten 68 Themen: 26 bekamen den ganzen Zeileninhalt als
     * Themennamen und eine LEERE Nutzlast - und leer zusammen mit -r loescht
     * ein behaltenes Thema -, die uebrigen 42 brachen am ersten "t" ihres
     * Namens ab. Kein einziges Thema kam richtig an.
     *
     * Jetzt schreibt PHP eine fertige Befehlsdatei: jedes Argument geht durch
     * escapeshellarg(). Es gibt kein Trennzeichen mehr, das falsch verstanden
     * werden koennte. */
    $zeilen = '';
    foreach ($zu_senden as $thema => $wert) {
        $zeilen .= mg_mqtt_zeile($thema, $wert) . ' || exit 1' . "\n";
    }
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $tmp = $p['tmp'] . '/publish.' . getmypid() . '.' . mt_rand(1000, 9999) . '.sh';
    if (!mg_write_atomic($tmp, $zeilen, 0600)) {
        return 0;
    }
    $out = array();
    @exec('sh ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        mg_log_if_changed('mqtt', 'Veroeffentlichung fehlgeschlagen: '
            . trim(implode(' ', array_slice($out, 0, 2))));
        return 0;
    }
    mg_write_json($merk, array('zeit' => $vollstaendig ? time() : (int) $alt['zeit'],
        'werte' => $paare), 0600);
    mg_log_if_changed('mqtt', 'Veroeffentlichung laeuft (' . count($paare)
        . ' Themen je Fahrzeug)');
    return count($zu_senden);
}

/**
 * Kommt am Ende der Schale wieder das an, was hineingegeben wurde?
 *
 * Geprueft wird die WIRKUNG, nicht die Schreibweise: die erzeugten
 * Befehlszeilen laufen wirklich durch "sh" - nur steht statt mosquitto_pub
 * ein "set --", das die Argumente uebernimmt und Thema und Nutzlast
 * zurueckgibt. Weicht auch nur eines ab, ist die Zeile rot.
 *
 * Genau diese Probe haette den Befund von 1.1.2 am ersten Tag gefunden, und
 * sie braucht keinen Broker, kein Netz und kein Fahrzeug.
 *
 * Rueckgabe: array(ok, text)
 */
function mg_mqtt_probe($nr = 1)
{
    $paare = mg_mqtt_argumente($nr, mg_state($nr));
    if (!$paare) {
        return array(2, '');
    }
    /* Die Probendatei liegt unter data/, nicht unter /tmp - anders als die
     * Sendedatei, die jede Minute geschrieben wird und deshalb auf die
     * Ramdisk gehoert. Grund: /tmp ist nicht ueberall dasselbe Verzeichnis.
     * Auf einem LoxBerry schon; auf einem Pruefstand sehen PHP und die Schale
     * unter /tmp unter Umstaenden verschiedene Orte, und dann scheitert die
     * Probe an der Umgebung statt am Pruefling - gemessen am 27.08.2026, sie
     * meldete 1 von 68, und die eine war die Fehlermeldung der Schale.
     * data/ leitet sich aus LBHOMEDIR ab und ist damit ueberall derselbe Ort.
     * Eine Pruefzeile, die nur auf dem Geraet laufen kann, ist keine. */
    $p = mg_paths();
    if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
    $datei = $p['data'] . '/probe.' . getmypid() . '.' . mt_rand(1000, 9999) . '.sh';
    /* Aus "mosquitto_pub … -r -t <thema> -m <wert>" wird "set -- … -r -t
     * <thema> -m <wert>". Die Argumente sind dann $(n-2) und $n; gedruckt
     * werden sie mit einem Trennzeichen, das in keinem Thema vorkommen kann. */
    $zeilen = '';
    foreach ($paare as $thema => $wert) {
        $zeilen .= 'set -- -t ' . escapeshellarg($thema) . ' -m ' . escapeshellarg($wert)
                 . '; printf \'%s\\034%s\\n\' "$2" "$4"' . "\n";
    }
    if (!mg_write_atomic($datei, $zeilen, 0600)) {
        return array(2, '');
    }
    $out = array();
    @exec('sh ' . escapeshellarg($datei) . ' 2>&1', $out, $rc);
    @unlink($datei);
    $themen = array_keys($paare);
    if ($rc !== 0 || count($out) !== count($themen)) {
        return array(0, count($out) . '/' . count($themen));
    }
    $richtig = 0;
    foreach ($themen as $i => $thema) {
        $teile = explode(chr(28), $out[$i], 2);
        if ($teile[0] === $thema
            && isset($teile[1]) && $teile[1] === (string) $paare[$thema]) {
            $richtig++;
        }
    }
    return array($richtig === count($themen) ? 1 : 0,
        $richtig . '/' . count($themen));
}


/* ==================================================================
 * Verwaiste Themen aus 1.1.0 bis 1.1.2 aufraeumen
 *
 * Die kaputte Veroeffentlichung jener Fassungen hat unter dem eigenen Praefix
 * Themen angelegt, die es nie geben sollte: solche mit einem Tabulator und dem
 * Wert IM Namen, und solche, deren Name am ersten "t" abbrach. Sie liegen
 * BEHALTEN im Broker und verschwinden von selbst nicht mehr - auch dann nicht,
 * wenn das Plugin jetzt richtig sendet.
 *
 * Geloescht wird nur, was unterhalb des EIGENEN Praefix liegt und in der
 * heutigen Themenliste nicht vorkommt. Fremde Themen werden nicht angefasst,
 * und die Liste wird dem Bediener vorher gezeigt.
 * ================================================================== */

/** Alle Themen unter dem eigenen Praefix, die heute nicht mehr vorkommen. */
function mg_mqtt_verwaiste($sekunden = 3)
{
    $cfg = mg_config();
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($praefix === '' || !mg_has_mosquitto()) {
        return array();
    }
    list($vorhanden, , ) = mg_sub(array($praefix . '/#'), $sekunden);
    $soll = array();
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        foreach (mg_mqtt_argumente($nr, mg_state($nr)) as $thema => $wert) {
            $soll[$thema] = 1;
        }
    }
    $aus = array();
    foreach ($vorhanden as $thema => $wert) {
        if (!isset($soll[$thema])) {
            $aus[$thema] = $wert;
        }
    }
    ksort($aus);
    return $aus;
}

/**
 * Die uebergebenen Themen im Broker loeschen (leere Nutzlast, behalten).
 * Rueckgabe: array(anzahl, fehlertext)
 */
function mg_mqtt_verwaiste_loeschen($themen)
{
    $cfg = mg_config();
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($praefix === '' || !mg_has_mosquitto() || !$themen) {
        return array(0, '');
    }
    $zeilen = '';
    $n = 0;
    foreach ((array) $themen as $thema) {
        // Fail closed: nur unterhalb des eigenen Praefix. Ein Thema, das von
        // aussen hereingereicht wurde und woanders liegt, wird uebergangen.
        if (strncmp((string) $thema, $praefix . '/', strlen($praefix) + 1) !== 0) {
            continue;
        }
        $zeilen .= mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
                 . ' -r -t ' . escapeshellarg((string) $thema) . " -m ''\n";
        $n++;
    }
    if ($n === 0) {
        return array(0, '');
    }
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $datei = $p['tmp'] . '/aufraeumen.' . getmypid() . '.sh';
    if (!mg_write_atomic($datei, $zeilen, 0600)) {
        return array(0, 'Datei nicht schreibbar');
    }
    $out = array();
    @exec('sh ' . escapeshellarg($datei) . ' 2>&1', $out, $rc);
    @unlink($datei);
    if ($rc !== 0) {
        return array(0, trim(implode(' ', array_slice($out, 0, 2))));
    }
    mg_log('Verwaiste MQTT-Themen geloescht: ' . $n);
    // Der Merker der Veroeffentlichung wird verworfen, damit der naechste
    // Lauf den ganzen Satz neu sendet.
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        @unlink($p['tmp'] . '/veroeffentlicht' . (int) $nr . '.json');
    }
    return array($n, '');
}

/** Hausstandard: Gateway-Autostart aus general.json. */
function mg_mqtt_gateway_autostart()
{
    $m = mg_mqtt_gateway_info();
    return $m === null ? null : $m['autostart'];
}

/**
 * Zustand und FASSUNG des LoxBerry-MQTT-Gateways.
 *
 * Die Fassung steht als Mqtt.Gatewayversion in general.json (ab Werk 1). Sie
 * entscheidet, was der Anwender eintragen muss:
 *   V1  Das Abo wird von Hand eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Das Gateway erkennt die Themengruppe selbst; in den Subscriptions
 *       werden nur noch die gewuenschten Datenpunkte angehakt.
 * Bis 1.1.0 stand hier pauschal der V1-Satz. Wer V2 fahrt, sucht danach einen
 * Eingabeplatz, den es nicht mehr gibt.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst ein Feld mit
 * autostart (bool) und fassung (int, 0 = unbekannt).
 */
function mg_mqtt_gateway_info()
{
    $p = mg_paths();
    if ($p['lbhome'] === '') {
        return null;
    }
    $d = mg_json_lesen($p['lbhome'] . '/config/system/general.json');
    if (!isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return null;
    }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    $fassung = isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0;
    return array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung' => $fassung,
    );
}

/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken: was nicht gemessen werden konnte, sagt das.
 * ================================================================== */

function mg_selbsttest()
{
    $cfg = mg_config();
    $z = array();
    $add = function ($schluessel, $ok, $text) use (&$z) {
        $z[] = array('bez' => $schluessel, 'ok' => (int) $ok, 'text' => (string) $text);
    };

    $add('PRUEF.MOSQUITTO', mg_has_mosquitto() ? 1 : 0,
        mg_has_mosquitto() ? 'mosquitto_sub' : 'mosquitto-clients');
    $add('PRUEF.MBSTRING', function_exists('mb_substr') ? 1 : 2,
        function_exists('mb_substr') ? 'mbstring' : 'substr');

    $anzahl = mg_fahrzeug_anzahl($cfg);
    $add('PRUEF.FAHRZEUGE', $anzahl > 0 ? 1 : 0, (string) $anzahl);
    $add('PRUEF.BENUTZER', trim((string) $cfg['saic_user']) !== '' ? 1 : 0,
        trim((string) $cfg['saic_user']));

    $roh = mg_raw();
    $add('PRUEF.THEMEN', $roh['anzahl'] > 0 ? 1 : 0, (string) (int) $roh['anzahl']);
    $alter = $roh['zeit'] !== '' ? (int) round((time() - strtotime($roh['zeit'])) / 60) : -1;
    $add('PRUEF.MOMENTAUFNAHME', ($alter >= 0 && $alter <= 5) ? 1 : ($alter < 0 ? 0 : 2),
        $alter >= 0 ? ($alter . ' min') : '');

    // Trifft der eingetragene Basispfad wirklich etwas?
    $treffer = 0;
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        if (mg_themen_anzahl($nr) > 0) { $treffer++; }
    }
    $add('PRUEF.BASISPFAD', ($anzahl > 0 && $treffer === $anzahl) ? 1 : ($treffer > 0 ? 2 : 0),
        $treffer . '/' . $anzahl);

    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $lwt = mg_bool_wert(mg_pick_abs($prefix . '/_internal/lwt'), -1);
    $add('PRUEF.GATEWAY', $lwt === 1 ? 1 : ($lwt === 0 ? 0 : 2),
        $lwt === 1 ? 'online' : ($lwt === 0 ? 'offline' : ''));

    $erreicht = 0;
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        if (mg_bool('available', -1, $nr) === 1) { $erreicht++; }
    }
    $add('PRUEF.ERREICHBAR', ($anzahl > 0 && $erreicht === $anzahl) ? 1 : ($erreicht > 0 ? 2 : 0),
        $erreicht . '/' . $anzahl);

    $auto = mg_mqtt_gateway_autostart();
    $add('PRUEF.AUTOSTART', $auto === true ? 1 : ($auto === false ? 0 : 2), '');
    $add('PRUEF.MERKWORT', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0, '');

    // Ein Zustand fuer die beiden folgenden Proben - einmal gebildet.
    $probe_st = mg_state(1);

    // Die eigene Vorlage: wohlgeformt?
    if (function_exists('simplexml_load_string')) {
        list(, $inhalt) = mg_vorlage(1, 'mg');
        $add('PRUEF.VORLAGE', (@simplexml_load_string($inhalt) === false) ? 0 : 1, '');
    } else {
        $add('PRUEF.VORLAGE', 2, '');
    }

    /* Trifft jede Befehlserkennung genau eine Stelle?
     *
     * Geprueft wird die WIRKUNG, nicht die Schreibweise: der Suchtext aus
     * mg_check() wird auf die ECHTE Antwortzeile losgelassen. Ein Vergleich
     * der blossen Namen waere zu streng - FZALTER endet zwar auf ALTER, aber
     * ";ALTER=" kommt in ";FZALTER=" nicht vor, und genau dafuer traegt der
     * Suchtext das Semikolon. Eine Pruefzeile, die ohne Fehler rot wird, ist
     * schlimmer als keine. */
    $eindeutig = 1;
    $doppelt = '';
    foreach (mg_zeilen() as $zk => $zi) {
        $zeile = mg_line($zk, 1, $probe_st);
        foreach (array_keys(mg_felder_von($zk)) as $feld) {
            $treffer = substr_count($zeile, ';' . $feld . '=');
            if ($treffer !== 1) {
                $eindeutig = 0;
                $doppelt = $zi['kopf'] . ': ' . $feld . ' = ' . $treffer . 'x';
            }
        }
    }
    $add('PRUEF.EINDEUTIG', $eindeutig, $doppelt);
    /* Kommt am Ende der Schale wieder an, was die Veroeffentlichung
     * hineingegeben hat? Diese Zeile braucht keinen Broker - und genau sie
     * haette den Befund von 1.1.2 am ersten Tag gefunden. */
    if (!empty($cfg['mqtt_ein'])) {
        list($mq_ok, $mq_txt) = mg_mqtt_probe(1);
        $add('PRUEF.MQTT', $mq_ok, $mq_txt);
    }
    $add('PRUEF.REITER', mg_reiter_stimmig(), '');
    list($sa_ok, $sa_txt) = mg_smactive_probe();
    $add('PRUEF.SMACTIVE', $sa_ok, $sa_txt);
    list($fo_ok, $fo_txt) = mg_formularprobe();
    $add('PRUEF.FORMULAR', $fo_ok, $fo_txt);

    if (!empty($cfg['abfahrt_ein']) || !empty($cfg['ladeempf_ein'])) {
        $h = mg_horcher_zustand();
        $gefunden = 0;
        foreach ($h['themen'] as $t) {
            if (isset($h['werte'][$t])) { $gefunden++; }
        }
        $add('PRUEF.HORCHER',
            ($h['themen'] && $gefunden === count($h['themen'])) ? 1 : ($gefunden ? 2 : 0),
            $gefunden . '/' . count($h['themen']));
    }
    return $z;
}

/**
 * Reiterleiste, Bereiche und Positivliste der index.php gegeneinander zaehlen.
 *
 * Alle drei Stellen stehen in der index.php ausgeschrieben - das muessen sie,
 * weil hausstandard_pruefen.py sie als Literal sucht und eine erzeugte Leiste
 * als "trifft nicht zu" meldet. Ausgeschrieben koennen sie aber auseinander
 * laufen; genau dafuer gibt es diese Zeile.
 */
function mg_reiter_stimmig()
{
    $datei = dirname(__DIR__) . '/htmlauth/index.php';
    if (!is_file($datei)) {
        return 2;
    }
    $q = (string) @file_get_contents($datei);
    if (!preg_match('/\$mg_reiter\s*=\s*array\((.*?)\);/s', $q, $t)) {
        return 0;
    }
    preg_match_all("/'tab-([a-z]+)'/", $t[1], $n);
    $liste = $n[1];
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $q, $l);
    $leiste = $l[1];
    preg_match_all('/id="tab-([a-z]+)"/', $q, $b);
    $bereiche = $b[1];
    if (!$liste || !$leiste || !$bereiche) {
        return 0;
    }
    sort($liste);
    sort($leiste);
    sort($bereiche);
    return ($liste === $leiste && $liste === $bereiche) ? 1 : 0;
}

/**
 * Setzt der SERVER das sm-active - an der Leiste UND an den Bereichen?
 *
 * .sm-seite steht auf display:none. Vergibt die Klasse allein das
 * JavaScript, ist die Seite ohne Skript vollstaendig leer - genau das war
 * bis 1.0.2 der Fall. Und hausstandard_pruefen.py kann es nicht sehen: eine
 * zusammengesetzte Klasse meldet es als "nicht pruefbar", und ein
 * "nicht pruefbar" liest sich beim Ueberfliegen wie ein Haken.
 */
function mg_smactive_probe()
{
    $datei = dirname(__DIR__) . '/htmlauth/index.php';
    if (!is_file($datei)) {
        return array(2, '');
    }
    $s = (string) @file_get_contents($datei);
    $anzahl = preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $leiste = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl) {
        return array(1, $anzahl . '/' . $anzahl);
    }
    return array(0, $leiste . ' / ' . $bereiche . ' ' . mg_t('WORT.VON') . ' ' . $anzahl);
}

/**
 * Traegt JEDES Formular das Merkmal gegen fremde Absender?
 *
 * Der Wachposten am Eingang nuetzt nichts, wenn ein Formular das Merkmal
 * nicht mitschickt - dann tut es einfach nichts mehr, und der Anwender sucht
 * den Fehler bei sich. Die leere Menge zuerst: "alle 0 von 0 in Ordnung" ist
 * kein Haken.
 */
function mg_formularprobe()
{
    $datei = dirname(__DIR__) . '/htmlauth/index.php';
    if (!is_file($datei)) {
        return array(2, '');
    }
    $s = (string) @file_get_contents($datei);
    $gesamt = 0;
    $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false) { $ohne++; }
        }
    }
    if ($gesamt === 0) {
        return array(0, '0/0');
    }
    return array($ohne > 0 ? 0 : 1, ($gesamt - $ohne) . '/' . $gesamt);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch.
 * ================================================================== */

function mg_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function mg_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if ($k && is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . mg_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * templateType: 1 = UDP-Eingang, 2 = HTTP-Eingang, 3 = Ausgang.
 * ================================================================== */

function mg_vx($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function mg_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . mg_vx($kopf['title']) . '" ';
    $o .= 'Comment="' . mg_vx(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . mg_vx(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . mg_vx(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . mg_vx($c['title']) . '" ';
        $o .= 'Comment="' . mg_vx($c['comment']) . '" ';
        $o .= 'Check="' . mg_vx($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . mg_vx(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Ausgangsvorlage.
 *
 * Ein analoger VirtualOutCmd traegt vier Attribute mehr als ein digitaler:
 * SourceValLow, DestValLow, SourceValHigh, DestValHigh zwischen RepeatRate
 * und HintText. CmdOnMethod="GET" steht auch an einem Ausgang mit
 * Geraetepfad - das ist an einer echten Ausfuhr gemessen.
 */
function mg_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" ';
    $o .= 'Title="' . mg_vx($kopf['title']) . '" ';
    $o .= 'Comment="' . mg_vx(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . mg_vx(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $analog = !empty($c['analog']);
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . mg_vx($c['title']) . '" ';
        $o .= 'Comment="' . mg_vx(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . mg_vx($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . mg_vx(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" RepeatRate="0" ';
        if ($analog) {
            $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" ';
        }
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Der Rechnername, unter dem der Miniserver den LoxBerry erreicht. */
function mg_host()
{
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
        && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    return gethostname() ?: 'loxberry';
}

/**
 * Die Adresse des Endpunkts - an EINER Stelle gebildet.
 *
 * Bis 1.0.8 setzten die Oberflaeche und mg_vorlage() sie unabhaengig
 * voneinander zusammen, mit verschiedenen Rueckfallebenen fuer den
 * Ordnernamen; die Baustein-Liste zeigte eine dritte, abgeschnittene Form.
 * Zwei Stellen, die dasselbe zusammensetzen, laufen auseinander.
 */
function mg_endpunkt($absolut = true, $parameter = '')
{
    $p = mg_paths();
    $pfad = '/plugins/' . $p['plugin'] . '/mg.php';
    if ($parameter !== '') {
        $pfad .= '?' . ltrim($parameter, '?');
    }
    return $absolut ? ('http://' . mg_host() . $pfad) : $pfad;
}

/** Adresse eines schaltenden Aufrufs, samt Merkwort. */
function mg_aktionsadresse($befehl, $nr = 1, $zusatz = '', $absolut = false)
{
    $cfg = mg_config();
    $q = 'cmd=' . rawurlencode($befehl);
    if ($zusatz !== '') {
        $q .= '&' . $zusatz;
    }
    if ((int) $nr > 1) {
        $q .= '&fahrzeug=' . (int) $nr;
    }
    $q .= '&token=' . rawurlencode((string) $cfg['aktionstoken']);
    return mg_endpunkt($absolut, $q);
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function mg_vorlage($nr = 1, $zeile = 'mg')
{
    $zn = mg_zeilen();
    if (!isset($zn[$zeile])) {
        $zeile = 'mg';
    }
    $cfg = mg_config();
    $fz = mg_fahrzeuge($cfg);
    $name = isset($fz[$nr]) ? $fz[$nr]['name'] : ('MG ' . (int) $nr);
    /* Fahrzeug 1 behaelt die Titel aus 1.0.8 (MG_SOC), damit eine bestehende
     * Anlage nach dem erneuten Import nicht zwei Namensfamilien fuehrt. */
    $praefix = ((int) $nr > 1) ? ('MG' . (int) $nr . '_') : 'MG_';
    $q = 'zeile=' . $zeile;
    if ((int) $nr > 1) {
        $q .= '&fahrzeug=' . (int) $nr;
    }
    $cmds = array();
    foreach (mg_felder_von($zeile) as $feld => $info) {
        $einheit = $info['einheit'];
        $cmds[] = array(
            'title' => $praefix . $feld,
            'comment' => mg_t($info['bez']) . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => mg_check($feld),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $info['analog'], 'min' => $info['min'], 'max' => $info['max'],
        );
    }
    $dateiname = 'VI_mgismart_' . $zeile . ((int) $nr > 1 ? '_' . (int) $nr : '') . '.xml';
    return array($dateiname, mg_xml_virtual_in_http(array(
        'title' => $name . ' (' . mg_t($zn[$zeile]['bez']) . ')',
        'address' => mg_endpunkt(true, $q),
        'polling' => (string) $zn[$zeile]['takt'],
        'comment' => mg_t('VORLAGE.KOPF') . ' ' . date('d.m.Y') . '. ' . mg_t('VORLAGE.HINWEIS'),
    ), $cmds));
}

/**
 * Vorlage der Steuerbefehle (VirtualOut).
 *
 * Ein Zustand gehoert an EINEN Ausgang mit Ein- und Ausbefehl, nicht an zwei
 * Ausgaenge - "Klima ein/aus" ist genau so ein Fall. Befehle mit Zusatzwert
 * werden analog und tragen <v> als Platzhalter.
 */
function mg_vorlage_vo($nr = 1)
{
    $cfg = mg_config();
    $fz = mg_fahrzeuge($cfg);
    $name = isset($fz[$nr]) ? $fz[$nr]['name'] : ('MG ' . (int) $nr);
    $liste = mg_befehle();
    /* Wer als Gegenstueck eines anderen gefuehrt wird, bekommt keinen eigenen
     * Ausgang - er steht dort als Ausbefehl. */
    $gegenstuecke = array();
    foreach ($liste as $k => $b) {
        if (!empty($b['gegen'])) {
            $gegenstuecke[$b['gegen']] = $k;
        }
    }
    $cmds = array();
    foreach ($liste as $k => $b) {
        if (isset($gegenstuecke[$k])) {
            continue;
        }
        if (!empty($b['gefahr']) && empty($cfg['gefahr_ein'])) {
            continue;
        }
        if (!empty($b['plan']) && empty($cfg['plan_ein'])) {
            continue;
        }
        /* Die FREIE Form des Plans braucht zwei Uhrzeiten und einen Modus -
         * das kann ein virtueller Ausgang nicht liefern, weder digital noch
         * analog. In der Vorlage stehen deshalb nur die beiden Schalter, die
         * das eingestellte Fenster ein- und ausschalten. */
        if (isset($b['nutzlast'])
            && in_array($b['nutzlast'], array('ladeplan', 'heizplan'), true)) {
            continue;
        }
        $titel = (((int) $nr > 1) ? ('MG' . (int) $nr . ' ') : 'MG ') . mg_t($b['bez']);
        if (!empty($b['textwert'])) {
            foreach ($b['werte'] as $wert) {
                $cmds[] = array(
                    'title' => $titel . ' - ' . $wert,
                    'comment' => $b['topic'] . ' = ' . $wert,
                    'on' => mg_aktionsadresse($k, $nr, $b['zusatz'] . '=' . $wert, false),
                    'off' => '', 'analog' => false,
                );
            }
            continue;
        }
        $analog = !empty($b['zusatz']);
        $zusatz = $analog ? ($b['zusatz'] . '=<v>') : '';
        $ein = mg_aktionsadresse($k, $nr, $zusatz, false);
        $aus = '';
        if (!empty($b['gegen']) && isset($liste[$b['gegen']])) {
            $aus = mg_aktionsadresse($b['gegen'], $nr, '', false);
        }
        $cmds[] = array(
            'title' => $titel,
            'comment' => $b['topic'],
            'on' => $ein, 'off' => $aus, 'analog' => $analog,
        );
    }
    $dateiname = 'VQ_mgismart' . ((int) $nr > 1 ? '_' . (int) $nr : '') . '.xml';
    return array($dateiname, mg_xml_virtual_out(array(
        'title' => $name . ' (' . mg_t('VORLAGE.BEFEHLE') . ')',
        'address' => 'http://' . mg_host(),
        'comment' => mg_t('VORLAGE.KOPF') . ' ' . date('d.m.Y') . '. ' . mg_t('VORLAGE.HINWEIS'),
    ), $cmds));
}

/**
 * Der Abo-Hinweis in der Fassung, die zum Gateway passt.
 *
 * MGiSmart verzweigte seit 1.1.0 an EINER Stelle (LOX.ABO_PFLICHT) und
 * behauptete an der zweiten (MQTTR.ABO_HINWEIS) weiter unbedingt den
 * V1-Satz. Unter Gateway V2 standen dadurch beide Texte auf der Seite -
 * gefunden am 25.08.2026 durch gateway_wirkung.py, nicht durch Lesen.
 */
function mg_abo_text()
{
    $g = mg_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    if ($f <= 0) {
        return mg_t('MQTTR.ABO_UNBEKANNT');
    }
    return mg_t($f >= 2 ? 'MQTTR.ABO_V2' : 'MQTTR.ABO_HINWEIS')
         . ' <span class="sm-mono">'
         . sprintf(mg_t('MQTTR.ABO_GEMESSEN'), $f) . '</span>';
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function mg_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(mg_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = mg_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(mg_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = mg_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
