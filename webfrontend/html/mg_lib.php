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

/* Ab welchem Alter der Momentaufnahme (Minuten) OK auf 0 faellt: das
 * Dreifache des Abruftakts (cron.01min, also 3 min) - Entscheidung 4 vom
 * 29.09.2026. Dieselbe Grenze nutzen die Selbstpruefung und die Ampel im
 * Reiter "Gateway einrichten"; sie steht deshalb genau hier und nirgends
 * sonst. Bis 1.1.17 blieb OK=1 bei jedem Alter stehen. */
if (!defined('MG_OK_ALTER_MIN')) {
    define('MG_OK_ALTER_MIN', 3);
}

/* Der Gateway-Container, den das Plugin selbst anlegt (Entscheidung 7 vom
 * 30.09.2026). Das Abbild steht in der README des Gateways
 * (github.com/SAIC-iSmart-API/saic-python-mqtt-gateway, gelesen 30.09.2026).
 * MG_GW_DEF ist die Fassung der Container-Beschreibung (Labels, Variablen,
 * Netz): weicht sie am laufenden Container ab, zeigt die Ampel "veraltet". */
if (!defined('MG_GW_BILD')) {
    define('MG_GW_BILD', 'saicismartapi/saic-python-mqtt-gateway');
}
if (!defined('MG_GW_DEF')) {
    define('MG_GW_DEF', '1');
}
/* Zeitgrenze fuer docker pull beim Aktualisieren (A4, seit 1.1.19): so lang
 * wie docker run beim Anlegen, das das Abbild ebenfalls holt. */
if (!defined('MG_GW_PULL_SEK')) {
    define('MG_GW_PULL_SEK', 900);
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json enthaelt
 * (Regeln/06). Bis 1.1.14 genuegten config/plugins und webfrontend: gemessen
 * am 18.09.2026 in WSL (Pruefung-MGiSmart-1.1.14, Fall P1) hielt die
 * Bibliothek eines ausgepackten Archivs einen fremden Baum mit diesen beiden
 * Ordnern fuer die Wurzel und legte dort beim ersten Oeffnen der Oberflaeche
 * Konfiguration und Zweitschrift an. Ein LoxBerry hat immer general.json.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die LoxBerry-Wurzel: $LBHOMEDIR, wenn dort config/plugins und data/plugins
 * liegen, sonst die Aufwaertssuche lb_wurzel_ermitteln() (mit general.json).
 * '' heisst: keine Wurzel. */
function mg_lbhome()
{
    $h = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return $h;
    }
    return lb_wurzel_ermitteln();
}

function mg_paths()
{
    $home = mg_lbhome();
    /* Der Ordnername. LBPPLUGINDIR ist die Auskunft von LoxBerry SELBST und
     * hat Vorrang; von ihr zaehlt nur der letzte Pfadteil, und Namen, die
     * nachweislich kein Pluginordner sind, gelten auch dort nicht. Sonst der
     * eigene Ablageort: installiert liegt diese Datei unter
     * webfrontend/html/plugins/<ordner>/ - auch in der Upgrade-Luecke, in der
     * purge_installation config/plugins/<ordner>/ geloescht hat (Regeln/06).
     * Der feste Name greift nur, wo der abgeleitete kein Pluginordner sein
     * KANN - aus dem ausgepackten Archiv heisst er 'html'. */
    $nie = array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend');
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = !in_array($lbp, $nie, true);
    $ordner = basename(__DIR__);
    if ($lbp_gilt) {
        $ordner = $lbp;
    } elseif (in_array($ordner, $nie, true)) {
        $ordner = 'mgismart';
    }
    /* ARCHIVMODUS. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort
     * installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>, physisch
     * verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt
     * ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer
     * Attrappe, und so ruft uninstall/uninstall die Bibliothek). Sonst ist das
     * ein ausgepacktes Archiv oder ein Pruefordner.
     *
     * Bis 1.1.16 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
     * und ueber den Rueckfall den Ordner 'mgismart' - Konfiguration, Daten,
     * Protokoll der Anlage; mit $LBHOMEDIR allein, wie es am Geraet in
     * /etc/environment steht, ebenso; tmp war in beiden Zweigen
     * /tmp/mgismart (in WSL gemessen, Pruefung-MGiSmart-1.1.17, Faelle B1,
     * B2, B5, B6). Bauart awm_paths() (AWM-Abfuhr 1.4.13), dort aus
     * tb_paths() (Spotpreis-Tibber 0.9.19). */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home !== '') {
        return array(
            'config' => $home . '/config/plugins/' . $ordner . '/mg.json',
            'backup' => $home . '/config/plugins/' . $ordner . '.backup.json',
            'log' => $home . '/log/plugins/' . $ordner . '/mg.log',
            // Fehlerausgabe des Minutentakts (cron/cron.01min, seit 1.1.18).
            'cronerr' => $home . '/log/plugins/' . $ordner . '/cron.err',
            'datadir' => $home . '/data/plugins/' . $ordner,
            /* Je Ordner ein eigener Zwischenspeicher: bis 1.1.16 teilten
             * sich eine Zweitinstallation (mgismart01) und die erste
             * /tmp/mgismart - Drosselung, Meldungen, Veroeffentlichungsstand. */
            'tmp' => '/tmp/' . $ordner,
            'lbhome' => $home,
            'plugin' => $ordner,
            'archiv' => '',
        );
    }
    /* Keine Wurzel (Entwicklung, Pruefstand, fremder Baum) oder Archivmodus:
     * die Ersatzpfade unter dem Temp-Ordner, unter einem EIGENEN Namen - nie
     * ein Pfad der Anlage, nie einer ab der Laufwerkswurzel und nie der
     * Zwischenspeicher /tmp/<ordner> der Anlage. bin/cron.php steigt in beiden
     * Faellen vorher aus. */
    $tmp = sys_get_temp_dir() . '/mgismart-archiv';
    return array(
        'config' => $tmp . '/mg.json',
        'backup' => $tmp . '/mg.backup.json',
        'log' => $tmp . '/mg.log',
        'cronerr' => $tmp . '/cron.err',
        'datadir' => $tmp . '/data',
        'tmp' => $tmp,
        'lbhome' => '',
        'plugin' => $ordner,
        // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert liegt
        // (Archivmodus) - fuer die Meldung von bin/cron.php; sonst leer.
        'archiv' => $gefunden,
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
    /* Rechte VOR dem Inhalt setzen, nicht erst danach. Sonst liegt das
     * Aktionstoken einen Augenblick lang mit 0644 im Ordner - kurz, aber
     * lesbar. Erst anlegen, dann beschraenken, dann fuellen. */
    if (@file_put_contents($tmp, '') === false) {
        return false;
    }
    @chmod($tmp, $rechte);
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
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
        /* iSMART-Kennwort, seit 1.1.18: das Plugin legt den Gateway-Container
         * selbst an und braucht es fuer "neu anlegen" (FRAGEN_7UHR Nr. 8).
         * Es liegt wie broker_pass in dieser 0600-Datei, in der Zweitschrift
         * und in der Sicherungsdatei - und wird nie ausgegeben. */
        'saic_pass' => '',
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

/**
 * Nur-Lesen fuer den ganzen Vorgang an- oder abfragen.
 *
 * mg.php - der unangemeldete Endpunkt - setzt das einmal ganz oben.
 * Danach legt mg_config() nichts mehr an, gleich ueber welchen der
 * 27 Aufrufwege es erreicht wird. Ein Beiwert an einzelnen
 * Aufrufstellen haette die 25 mittelbaren nicht gedeckt und waere beim
 * naechsten Umbau vergessen worden.
 */
function mg_nur_lesen($setzen = null)
{
    static $an = false;
    if ($setzen !== null) {
        $an = (bool) $setzen;
    }
    return $an;
}

/**
 * Traegt diese Datei ueberhaupt etwas?
 *
 * Nicht "ist sie leer oder {}?", sondern "laesst sie sich als JSON-Objekt mit
 * mindestens einem Schluessel lesen?". Der Unterschied ist gemessen
 * (18.09.2026, WSL, Fall "kaputt"): eine ABGESCHNITTENE mg.json - nicht leer,
 * nicht "{}", fuer json_decode aber unbrauchbar - ging bis 1.1.13 an der
 * Selbstheilung vorbei. mg_json_lesen() gab daraus array(), mg_config()
 * lieferte die blanken Vorgaben, die Oberflaeche wuerfelte ein NEUES
 * Aktionstoken und mg_config_save() schrieb es samt Zweitschrift: das alte
 * Merkwort war fort, jede Loxone-Adresse bekam HTTP 403.
 *
 * Rueckgabe: die gelesenen Daten oder null, wenn die Datei nichts traegt.
 * Bauart: sp_inhalt_oder_null() aus Sprachsteuerung 0.11.7/0.11.8.
 */
function mg_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) {
        return null;
    }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') {
        return null;
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || $d === array()) {
        return null;
    }
    return $d;
}

/**
 * Traegt diese Konfiguration das, was nur sie tragen kann?
 *
 * Das Aktionstoken. Es steht in JEDER Loxone-Adresse dieses Plugins (Reiter
 * "Einbindung in Loxone"); geht es verloren, scheitern alle virtuellen
 * Eingaenge im Miniserver, und es gibt keinen Weg, es zurueckzurechnen. Alles
 * andere - Broker, Praefix, VIN - laesst sich in der Oberflaeche noch einmal
 * eintragen.
 *
 * Eine gespeicherte Konfiguration OHNE Merkwort gibt es auf keinem Weg der
 * Oberflaeche: index.php fuellt es beim ersten Seitenaufbau. Steht dort
 * keines, ist die Datei nicht aus einem gespeicherten Stand hervorgegangen.
 */
function mg_config_hat_inhalt($c)
{
    return is_array($c) && $c !== array()
        && trim((string) (isset($c['aktionstoken']) ? $c['aktionstoken'] : '')) !== '';
}

/**
 * Den verdraengten Stand beiseitelegen, statt ihn wegzuwerfen.
 *
 * 0600, denn in der Datei koennen Broker-Passwort und Merkwort stehen. Eine
 * leere Datei und eine mit "{}" sind nichts wert und werden nicht abgelegt.
 * Rueckgabe: der Ablageort, oder '' wenn nichts abzulegen war.
 */
function mg_kaputt_beiseite($datei)
{
    if (!is_file($datei)) {
        return '';
    }
    $roh = @file_get_contents($datei);
    if ($roh === false) {
        return '';
    }
    $rest = preg_replace('/\s+/', '', $roh);
    if ($rest === '' || $rest === '{}' || $rest === '[]') {
        return '';
    }
    /* Ueber eine Nebendatei, nicht mit copy(): copy() kuerzt ein schon
     * liegendes .kaputt zuerst auf null. Scheitert das Schreiben danach
     * (volle Karte), bleibt ein HALBER Stand - gemessen am 18.09.2026 in WSL
     * unter "ulimit -f 1" (Pruefung-MGiSmart-1.1.14, Fall L1: 1024 von 3000
     * Byte). mg_write_atomic() setzt die Rechte vor dem Inhalt. */
    $ziel = $datei . '.kaputt';
    if (!mg_write_atomic($ziel, $roh, 0600)) {
        return '';
    }
    return $ziel;
}

/**
 * Der ZUERST festgestellte Zustand der Konfiguration.
 *
 * Er wird fuer die Dauer des Vorgangs festgehalten und von einem spaeteren
 * "ok" NICHT ueberschrieben: der erste Aufruf von mg_config() heilt die Datei,
 * der zweite - den der Reiter Test macht - saehe sonst eine heile Datei und
 * meldete "in Ordnung", obwohl beim selben Seitenaufruf etwas kaputt war
 * (Regeln/05, Robonect 1.1.0). Werte: ok, leer, aus der Zweitschrift, kaputt,
 * kaputt ohne Zweitschrift.
 */
function mg_config_lage($setzen = null)
{
    static $lage = '';
    if ($setzen !== null && $lage === '') {
        $lage = (string) $setzen;
    }
    return $lage;
}

/**
 * Die Konfiguration lesen.
 *
 * Geheilt wird nach INHALT (mg_inhalt_oder_null(), mg_config_hat_inhalt()),
 * nicht nach Form: eine fehlende, eine leere, eine "{}" und eine
 * ABGESCHNITTENE mg.json sind derselbe Fall, naemlich "traegt nichts". Geheilt
 * wird nur aus einer Zweitschrift, die selbst Inhalt traegt - ein Stand ohne
 * Inhalt darf keinen anderen ersetzen, in keine der beiden Richtungen. Was
 * vorher in der Datei stand, liegt danach als <datei>.kaputt daneben.
 *
 * Im Nur-Lesen-Betrieb (mg.php, der unangemeldete Endpunkt) wird NICHTS
 * geschrieben - auch kein .kaputt. Gelesen wird dann die Zweitschrift
 * unmittelbar; die Tokenpruefung bleibt also moeglich, nur der Schreibzugriff
 * faellt weg. Eine einzige tokenlose Anfrage hat sonst auf jeder Anlage, die
 * schon einmal gespeichert hat, die mg.json angelegt.
 */
function mg_config()
{
    $p = mg_paths();
    /* Erst fragen, dann oeffnen. Ein @file_get_contents() auf eine fehlende
     * Datei ist stumm, aber nicht folgenlos: ein gesetzter Fehlerbehandler
     * sieht die Warnung trotzdem - im Pruefstand steht sie dann als Befund
     * da. Und die Konfigurationsdatei fehlt regelmaessig, naemlich vor dem
     * ersten Speichern. Dasselbe gilt fuer mkdir() auf einen Ordner, den es
     * schon gibt. */
    static $gemeldet = false;
    $quelle = $p['config'];
    $eigen = mg_inhalt_oder_null($p['config']);
    if (mg_config_hat_inhalt($eigen)) {
        mg_config_lage('ok');
    } else {
        $zweit = mg_inhalt_oder_null($p['backup']);
        $hat_zweit = mg_config_hat_inhalt($zweit);
        /* "leer" heisst: es war ohnehin nichts da (fehlende Datei,
         * Aktualisierungsfall "{}"). Nur dann ist der Zustand harmlos. */
        $rest = is_file($p['config'])
            ? preg_replace('/\s+/', '', (string) @file_get_contents($p['config'])) : '';
        $leer = ($rest === '' || $rest === '{}' || $rest === '[]');
        if (mg_nur_lesen()) {
            mg_config_lage($hat_zweit ? 'aus der Zweitschrift' : ($leer ? 'leer' : 'kaputt'));
            if ($hat_zweit) {
                $quelle = $p['backup'];
            }
        } else {
            /* Nur EINMAL je Vorgang ablegen und melden - mg_config() wird auf
             * 27 Wegen gerufen, und eine Zeile je Weg waere ein volles
             * Protokoll ohne einen einzigen neuen Messwert (Regeln/05,
             * "einmal wiederherstellen, einmal melden"). */
            $erstmals = !$gemeldet;
            $abgelegt = '';
            if ($erstmals) {
                $gemeldet = true;
                $abgelegt = mg_kaputt_beiseite($p['config']);
            }
            if ($hat_zweit) {
                if (!is_dir(dirname($p['config']))) {
                    @mkdir(dirname($p['config']), 0775, true);
                }
                /* Ueber mg_write_atomic(), nicht copy() + chmod(): copy() legt die
                 * Datei mit den Rechten der umask an (0644/0664), schreibt Merkwort
                 * und Broker-Passwort hinein und setzt 0600 erst danach - und es
                 * kuerzt eine vorhandene Datei zuerst auf null (bis 1.1.17; Befund
                 * C8/I4 des Durchgangs 29.09.2026). mg_write_atomic() setzt die
                 * Rechte vor dem Inhalt und benennt erst den fertigen Stand um. */
                $mg_heil = @file_get_contents($p['backup']);
                if ($mg_heil !== false && mg_write_atomic($p['config'], $mg_heil, 0600)) {
                    mg_config_lage($leer ? 'aus der Zweitschrift' : 'kaputt');
                    if ($erstmals) {
                        mg_log('Die Konfiguration trug kein Merkwort und wurde aus der '
                            . 'Zweitschrift wiederhergestellt: ' . $p['backup']
                            . ($abgelegt !== '' ? ' (der vorherige Inhalt liegt unter '
                                . $abgelegt . ')' : '') . '.');
                    }
                } else {
                    mg_config_lage('kaputt');
                }
            } else {
                mg_config_lage($leer ? 'leer' : 'kaputt ohne Zweitschrift');
                if ($erstmals && $abgelegt !== '') {
                    mg_log('WARNUNG: Die Konfiguration war unbrauchbar und es liegt keine '
                        . 'Zweitschrift mit Merkwort daneben. Der vorherige Inhalt liegt '
                        . 'unter ' . $abgelegt . '.');
                }
            }
        }
    }
    $cfg = mg_json_lesen($quelle);
    $cfg += mg_vorgaben();

    /* Altbestand: bis 1.0.8 gab es genau eine VIN in 'vin'. Sie wandert in die
     * Liste, ohne dass jemand etwas anklicken muss - und 'vin' bleibt stehen,
     * damit eine zurueckgerollte Fassung sie noch findet. */
    if (!is_array($cfg['vins'])) {
        $cfg['vins'] = array();
    }
    /* Typfest: nur Zeichenketten und Zahlen gehen durch trim(). Bis 1.1.17
     * stand hier array_map('trim') unmittelbar - eine Sicherung mit
     * "vins": [["x"]] warf unter PHP 8 "TypeError: trim(): Argument #1 must
     * be of type string, array given", und zwar bei JEDEM Aufruf: Oberflaeche,
     * Endpunkt und Takt waren tot (Befund C5/U3 vom 30.09.2026). */
    $cfg['vins'] = array_values(array_filter(array_map('trim',
        array_filter($cfg['vins'], 'is_scalar')), 'strlen'));
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
     * eines aelteren Standes zurueckgegeben.
     *
     * Seit 1.1.13 geht sie ausserdem durch mg_zweitschrift_ziehen(): ein
     * Stand OHNE Merkwort ersetzt keine Zweitschrift MIT Merkwort. Das
     * Speichern selbst wird dadurch nicht verhindert - nur der einzige
     * Rueckweg nicht zerstoert; das Protokoll sagt es. */
    if (!mg_zweitschrift_ziehen($json, $cfg)) {
        mg_log('FEHLER: Sicherung ' . $p['backup'] . ' liess sich nicht schreiben.');
        return false;
    }
    // Die Optionsdatei fuer mosquitto traegt dieselben Zugangsdaten.
    mg_broker_optionsdatei(true);
    return true;
}

/**
 * Was die Zweitschrift traegt und der neue Stand nicht.
 *
 * Leere Rueckgabe heisst: die Zweitschrift darf erneuert werden. Verglichen
 * wird, ob ein SCHLUESSEL fehlt oder leer ist, nicht ob sich ein Wert
 * geaendert hat - ein geleertes Broker-Passwort ist ein gewolltes Loeschen und
 * wird nachgezogen. Ein leeres Aktionstoken gibt es auf keinem Weg der
 * Oberflaeche und gilt deshalb als fehlend.
 *
 * Bauart uebernommen aus Sprachsteuerung 0.11.8 / Intercom 2.2.11.
 */
function mg_zweitschrift_fehlt(array $neu, array $felder)
{
    $p = mg_paths();
    $z = mg_inhalt_oder_null($p['backup']);
    if ($z === null) {
        return array();
    }
    $fehlt = array();
    foreach ($felder as $feld) {
        if (!array_key_exists($feld, $z)) {
            continue;
        }
        if (trim((string) $z[$feld]) === '') {
            continue;
        }
        if (!array_key_exists($feld, $neu) || trim((string) $neu[$feld]) === '') {
            $fehlt[] = $feld;
        }
    }
    return $fehlt;
}

/**
 * Die Zweitschrift erneuern - oder begruendet nicht.
 *
 * Rueckgabe false heisst NUR: das Schreiben ist misslungen. Eine begruendete
 * Verweigerung gibt true zurueck und steht im Protokoll - der gespeicherte
 * Stand ist ja da, es fehlt nur der Rueckweg, und den hat gerade diese
 * Verweigerung erhalten.
 */
function mg_zweitschrift_ziehen($json, array $neu)
{
    $p = mg_paths();
    $fehlt = mg_zweitschrift_fehlt($neu, array('aktionstoken'));
    if ($fehlt) {
        mg_log('WARNUNG: Die Zweitschrift bleibt unveraendert - der gespeicherte Stand '
            . 'traegt nicht, was dort steht (' . implode(', ', $fehlt) . '): ' . $p['backup']);
        return true;
    }
    /* Eine Zweitschrift, die kein lesbares Objekt mehr ist, kann das alte
     * Merkwort woertlich noch tragen (abgeschnitten beim Schreiben). Bevor sie
     * ersetzt wird, kommt sie als .kaputt daneben. */
    if (is_file($p['backup']) && mg_inhalt_oder_null($p['backup']) === null) {
        mg_kaputt_beiseite($p['backup']);
    }
    return mg_write_atomic($p['backup'], $json, 0600);
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
 * Das Merkwort aus der Zweitschrift holen - auch aus einer abgeschnittenen.
 *
 * DER DRITTE WEG, gemessen an FerienFeiertage 1.2.13 (18.09.2026): eine
 * Heilung nach Inhalt und eine Wache vor der Zweitschrift genuegen NICHT.
 * Sind BEIDE Dateien abgeschnitten, traegt keine von beiden "Inhalt" im Sinne
 * von mg_config_hat_inhalt() - geheilt wird also nicht, ein frisch
 * gewuerfeltes Merkwort ist aber ein voellig gueltiger Wert und kommt durch
 * jede Wache, die nur den zu schreibenden Stand ansieht. Das alte Merkwort
 * steht in der abgeschnittenen Zweitschrift woertlich da und ist das, was in
 * den Adressen des Miniservers steht; es wird deshalb von dort geholt.
 *
 * Gesucht wird die Form, die mg_token_erzeugen() liefert: bin2hex von zwoelf
 * Zufallsbytes, also 24 Stellen aus [0-9a-f]. Die Obergrenze 64 laesst ein
 * laenger gesetztes Merkwort zu, eine ganze Datei aber nicht.
 *
 * Rueckgabe: das Merkwort, oder '' wenn nebenan keines liegt - dann und nur
 * dann darf ein neues entstehen.
 */
function mg_token_aus_zweitschrift()
{
    $p = mg_paths();
    $z = mg_inhalt_oder_null($p['backup']);
    if (mg_config_hat_inhalt($z)) {
        return trim((string) $z['aktionstoken']);
    }
    if (!is_file($p['backup'])) {
        return '';
    }
    $roh = (string) @file_get_contents($p['backup']);
    if (preg_match('/"aktionstoken"\s*:\s*"([0-9a-fA-F]{24,64})"/', $roh, $t)) {
        return $t[1];
    }
    return '';
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
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $aus = @LBSystem::pluginversion(basename(__DIR__));
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
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        mg_write_atomic($f, implode("\n", $tail) . "\n");
    }
    $cfg = mg_config();
    /* Maskiert wird jedes Geheimnis, das das Plugin kennt: seit 1.1.18 auch
     * das iSMART-Kennwort und das Broker-Kennwort des LoxBerry (general.json),
     * das mg_broker_zugang() benutzt, wenn keine eigenen Angaben stehen. */
    $mg_geheim = array($cfg['broker_pass'], $cfg['aktionstoken'], $cfg['saic_pass']);
    $mg_lb = mg_general_mqtt();
    if ($mg_lb !== null) {
        $mg_geheim[] = $mg_lb['pass'];
    }
    foreach ($mg_geheim as $geheim) {
        if (is_scalar($geheim) && (string) $geheim !== '') {
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
    return $p['datadir'] . '/mosquitto';
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
    return trim(str_replace(array("\r", "\n", "\t"), '', is_scalar($v) ? (string) $v : ''));
}

/**
 * Der MQTT-Abschnitt der general.json des LoxBerry, feldweise gelesen.
 *
 * Rueckgabe null, wenn es keine Wurzel, keine lesbare Datei oder keinen
 * Abschnitt Mqtt gibt - sonst array(host, port, user, pass). Beide
 * Schreibweisen der Schluessel (Brokeruser/brokeruser), Bauart
 * abfahrt_mqtt_behalten_liste() aus Abfahrts-Assistent 1.6.17. Das Kennwort
 * wird nur zurueckgegeben, nie ausgegeben oder protokolliert.
 */
function mg_general_mqtt()
{
    $p = mg_paths();
    if ($p['lbhome'] === '') {
        return null;
    }
    $f = $p['lbhome'] . '/config/system/general.json';
    if (!is_file($f)) {
        return null;
    }
    $g = json_decode((string) @file_get_contents($f), true);
    if (!is_array($g)) {
        return null;
    }
    $m = null;
    if (isset($g['Mqtt']) && is_array($g['Mqtt'])) {
        $m = $g['Mqtt'];
    } elseif (isset($g['mqtt']) && is_array($g['mqtt'])) {
        $m = $g['mqtt'];
    }
    if ($m === null) {
        return null;
    }
    $hol = function ($gross, $klein) use ($m) {
        $w = isset($m[$gross]) ? $m[$gross] : (isset($m[$klein]) ? $m[$klein] : '');
        return is_scalar($w) ? (string) $w : '';
    };
    $host = mg_optionswert($hol('Brokerhost', 'brokerhost'));
    $port = (int) $hol('Brokerport', 'brokerport');
    return array(
        'host' => $host !== '' ? $host : '127.0.0.1',
        'port' => ($port > 0 && $port < 65536) ? $port : 1883,
        'user' => mg_optionswert($hol('Brokeruser', 'brokeruser')),
        'pass' => mg_optionswert($hol('Brokerpass', 'brokerpass')),
    );
}

/**
 * Mit welchen Daten meldet sich das Plugin beim Broker an?
 *
 * Entscheidung 7 vom 30.09.2026 (Befund C1/M1): ohne eigene Angaben nimmt
 * das Plugin die Broker-Daten des LoxBerry. Am Geraet stand bis 1.1.17 ein
 * leerer broker_user in mg.json, der Broker des LoxBerry verlangt eine
 * Anmeldung, und mosquitto_sub endete mit rc 5 "not authorised" - es kam nie
 * ein Wert an, obwohl der LoxBerry die Zugangsdaten die ganze Zeit kannte.
 *
 * "Eigene Angaben" heisst: broker_user ist gesetzt (der Haken im Reiter MQTT
 * setzt oder leert ihn). Dann gelten Host, Port, Benutzer und Kennwort aus
 * mg.json; sonst alle vier aus general.json -> Mqtt.
 *
 * Rueckgabe array(host, port, user, pass, quelle) mit quelle
 * 'eigen' | 'loxberry' | 'keine' (general.json ohne Mqtt: Host und Port aus
 * mg.json, ohne Anmeldung). Speist die Optionsdatei von mosquitto_sub/_pub,
 * die Aufrufzeile, die Rueckfrage beim Broker und den Gateway-Container.
 */
/** Zeigt die Adresse auf diesen LoxBerry? Leer zaehlt als lokal. */
function mg_broker_host_lokal($host)
{
    $h = strtolower(trim((string) $host));
    return in_array($h, array('', '127.0.0.1', 'localhost', '::1', '[::1]'), true);
}

function mg_broker_zugang($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $u = mg_optionswert($cfg['broker_user']);
    $host = mg_optionswert($cfg['broker_host']);
    $port = is_numeric($cfg['broker_port']) ? (int) $cfg['broker_port'] : 1883;
    /* Eigene Angaben gelten, wenn ein Benutzer eingetragen ist ODER die
     * Adresse auf einen anderen Rechner zeigt (Nacharbeit 30.09., N2): so
     * bleibt ein anonymer Broker anderswo einstellbar, ohne neuen Schluessel.
     * Nur ohne beides greift der Rueckfall auf general.json. */
    $fremd = !mg_broker_host_lokal($host);
    if ($host === '') { $host = '127.0.0.1'; }
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    if ($u !== '' || $fremd) {
        return array('host' => $host, 'port' => $port, 'user' => $u,
                     'pass' => mg_optionswert($cfg['broker_pass']), 'quelle' => 'eigen');
    }
    $g = mg_general_mqtt();
    if ($g === null) {
        return array('host' => $host, 'port' => $port, 'user' => '', 'pass' => '',
                     'quelle' => 'keine');
    }
    $g['quelle'] = 'loxberry';
    return $g;
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

    $zugang = mg_broker_zugang();
    $zeilen = '';
    $u = $zugang['user'];
    $pw = $zugang['pass'];
    if ($u !== '') {
        $zeilen .= '-u ' . $u . "\n";
    }
    if ($pw !== '') {
        $zeilen .= '-P ' . $pw . "\n";
    }

    /* Neu geschrieben wird auch, wenn die general.json juenger ist: die
     * Zugangsdaten koennen seit 1.1.18 aus ihr stammen, und nach einem neuen
     * Broker-Kennwort im LoxBerry bliebe sonst das alte in der Optionsdatei
     * stehen (Befund C1/M1, :957-958 in 1.1.17 verglich nur mit mg.json). */
    $gen = $p['lbhome'] !== '' ? $p['lbhome'] . '/config/system/general.json' : '';
    clearstatcache();
    foreach (array('mosquitto_sub', 'mosquitto_pub') as $name) {
        $datei = $ordner . '/' . $name;
        if (!$erzwingen && is_file($datei) && is_file($p['config'])
            && filemtime($datei) >= filemtime($p['config'])
            && ($gen === '' || !is_file($gen) || filemtime($datei) >= filemtime($gen))) {
            continue;
        }
        // Auch wenn nichts drinsteht, wird die Datei geschrieben (leer) -
        // sonst bliebe eine alte mit den vorherigen Zugangsdaten liegen.
        //
        // Der Rueckgabewert wurde frueher verworfen. Schlaegt das
        // Schreiben fehl, bleibt genau die alte Datei mit den
        // VORHERIGEN Zugangsdaten liegen - der Fall, den der Absatz
        // darueber verhindern soll. Das gehoert ins Protokoll.
        if (!mg_write_atomic($datei, $zeilen, 0600)) {
            mg_log('Zugangsdatei nicht schreibbar: ' . $datei
                   . ' - es gilt weiter der vorherige Stand');
        }
    }
    return $ordner;
}

/** Der Teil der Aufrufzeile, der oeffentlich sein darf: Rechner und Port. */
function mg_broker_args()
{
    $z = mg_broker_zugang();
    return ' -h ' . escapeshellarg((string) $z['host'])
         . ' -p ' . (int) $z['port'];
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
    /* Die Fehlerausgabe geht in eine EIGENE Datei (Befund C2/M2). Bis 1.1.17
     * stand hier "2>&1", und jede Zeile mit einem Leerzeichen galt als
     * "Thema Wert": aus "Connection error: Connection Refused: not
     * authorised." wurde das Thema "Connection", das danach wegfiel - uebrig
     * blieb die nackte Zahl "rc=5". Eine Fehlerzeile ist nie ein Thema. */
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $errdatei = $p['tmp'] . '/sub_fehler.' . getmypid() . '.' . mt_rand(1000, 9999) . '.txt';
    $cmd = mg_broker_umgebung() . 'mosquitto_sub' . mg_broker_args() . $t
         . ' -v -W ' . max(1, min(15, (int) $sekunden)) . ' 2>' . escapeshellarg($errdatei);
    $out = array();
    $rc = 0;
    @exec($cmd, $out, $rc);
    $fehler = array();
    if (is_file($errdatei)) {
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($errdatei)) as $fz) {
            if (trim($fz) !== '') { $fehler[] = trim($fz); }
        }
        @unlink($errdatei);
    }
    $werte = array();
    foreach ($out as $zeile) {
        $pos = strpos($zeile, ' ');
        if ($pos === false) {
            continue;   // ein Thema ohne Nutzlast traegt nichts
        }
        $werte[substr($zeile, 0, $pos)] = trim(substr($zeile, $pos + 1));
    }
    return array($werte, (int) $rc, implode(' ', array_slice($fehler, 0, 3)));
}

/**
 * Der Rueckgabewert von mosquitto_sub als ganzer Satz - EIN Satzvorrat fuer
 * die Meldung nach "Werte jetzt einlesen", die Zeile im Reiter Test und die
 * Ampel im Reiter "Gateway einrichten" (Befund C2/M2).
 *
 * Belegt am echten Programm ist nur rc 5 (am Geraet 30.09.2026, "not
 * authorised"). 4, 14 und 27 folgen mosquitto.h bzw. dem CONNACK-Code und
 * sind am Geraet noch nachzumessen (BAUBERICHT, Anmerkungen).
 *   5, 4  der Broker weist die Anmeldung ab
 *   14    der Broker ist nicht erreichbar
 *   27    Zeitablauf - angemeldet, aber unter <prefix>/ liegt nichts
 *   sonst die Nummer und die Fehlerzeile des Programms
 */
function mg_sub_klartext($rc, $fehlertext = '', $cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $z = mg_broker_zugang($cfg);
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $rc = (int) $rc;
    if ($rc === 5 || $rc === 4) {
        $woher = ($z['quelle'] === 'eigen') ? mg_t('RC.QUELLE_EIGEN')
            : (($z['quelle'] === 'loxberry') ? sprintf(mg_t('RC.QUELLE_LOXBERRY'), $z['user'])
                                              : mg_t('RC.QUELLE_KEINE'));
        return sprintf(mg_t('RC.ABGEWIESEN'), $rc, $woher);
    }
    if ($rc === 14) {
        return sprintf(mg_t('RC.NICHT_ERREICHBAR'), $z['host'], (int) $z['port']);
    }
    if ($rc === 27) {
        return sprintf(mg_t('RC.LEER'), $prefix);
    }
    return sprintf(mg_t('RC.ANDERS'), $rc) . ($fehlertext !== '' ? ' ' . $fehlertext : '');
}

/** Die Lage des letzten Einlesens (fuer Reiter Test und Ampel, ohne Netz). */
function mg_lesen_lage_datei()
{
    return mg_paths()['tmp'] . '/lesen_lage.json';
}

function mg_lesen_lage()
{
    $d = mg_json_lesen(mg_lesen_lage_datei());
    return array(
        'zeit' => isset($d['zeit']) ? (int) $d['zeit'] : 0,
        'rc' => isset($d['rc']) ? (int) $d['rc'] : -1,
        'ok' => !empty($d['ok']) ? 1 : 0,
        'text' => isset($d['text']) ? (string) $d['text'] : '',
    );
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
    $p = mg_paths();
    if (!$werte) {
        $text = mg_sub_klartext($rc, $fehler, $cfg);
        mg_lesen_lage_merken($rc, 0, $text);
        return array(0, $text);
    }
    $alt = mg_raw();
    /* Die Verwerfung ist BEGRENZT (Befund C4/M7). Bis 1.1.17 blieb ein
     * dauerhaft kleinerer Stand fuer immer verworfen - ein entferntes zweites
     * Fahrzeug oder ein neu aufgesetztes Gateway, und das Plugin las bis zum
     * naechsten Update nie wieder ein. Jetzt gilt: dreimal hintereinander
     * derselbe kleinere Stand, dann ist er der neue. Gezaehlt wird im
     * Zwischenspeicher; ein vollstaendiger Stand setzt die Zaehlung zurueck. */
    $vf = $p['tmp'] . '/verwurf.json';
    if ((int) $alt['anzahl'] > 4 && count($werte) * 2 < (int) $alt['anzahl']) {
        $v = mg_json_lesen($vf);
        $folge = (isset($v['anzahl'], $v['folge']) && (int) $v['anzahl'] === count($werte))
            ? (int) $v['folge'] + 1 : 1;
        if ($folge < 3) {
            if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
            mg_write_json($vf, array('anzahl' => count($werte), 'folge' => $folge));
            mg_log_if_changed('verwurf', 'Momentaufnahme verworfen: nur ' . count($werte)
                . ' von zuletzt ' . (int) $alt['anzahl'] . ' Themen - alter Stand bleibt stehen;'
                . ' bleibt es dreimal hintereinander bei dieser Zahl, gilt der neue Stand.');
            $text = 'unvollstaendig (' . count($werte) . ' von ' . (int) $alt['anzahl'] . ')';
            mg_lesen_lage_merken($rc, 0, $text);
            return array(0, $text);
        }
        mg_log('Momentaufnahme: dreimal hintereinander nur ' . count($werte) . ' von zuletzt '
            . (int) $alt['anzahl'] . ' Themen - der neue Stand gilt ab jetzt.');
    }
    foreach (array($vf, $p['tmp'] . '/last_verwurf.txt') as $mg_weg) {
        // die Zaehlung und die Meldebremse zuruecksetzen
        if (is_file($mg_weg)) { @unlink($mg_weg); }
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    mg_write_json($p['datadir'] . '/werte.json', array(
        'zeit' => date('c'), 'anzahl' => count($werte), 'werte' => $werte,
    ));
    mg_lesen_lage_merken($rc, 1, count($werte) . ' Themen');
    return array(1, count($werte) . ' Themen');
}

function mg_lesen_lage_merken($rc, $ok, $text)
{
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    mg_write_json(mg_lesen_lage_datei(), array('zeit' => time(), 'rc' => (int) $rc,
        'ok' => (int) $ok, 'text' => (string) $text));
}

/** Rohwerte der letzten Momentaufnahme. */
function mg_raw()
{
    $p = mg_paths();
    $d = mg_json_lesen($p['datadir'] . '/werte.json');
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
     * retained Werten von vorgestern ist kein gueltiger Datensatz.
     *
     * Seit 1.1.18 zwei weitere Bedingungen:
     *   - die Momentaufnahme ist hoechstens MG_OK_ALTER_MIN (3) Minuten alt
     *     (Entscheidung 4 vom 29.09.2026, Befund C3). Bis 1.1.17 blieb OK=1
     *     bei jedem Alter stehen: scheiterte das Einlesen, las Loxone
     *     "Fahrzeugdaten gueltig" mit dem Stand von gestern - in den Zeilen
     *     laden und ort sogar ohne ALTER daneben. ALTER selbst bleibt.
     *   - der Letzte Wille des Gateways sagt nicht "offline" (Befund M4):
     *     GATEWAY=0 heisst, der Container ist tot, und jeder Wert ist ein
     *     stehengebliebener Retain. */
    $st['OK'] = ($st['THEMEN'] > 0 && $soc >= 0 && (int) $st['ERREICHBAR'] !== 0
                 && (int) $st['GATEWAY'] !== 0
                 && $st['ALTER'] >= 0 && $st['ALTER'] <= MG_OK_ALTER_MIN) ? 1 : 0;

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
    return mg_paths()['datadir'] . '/ladungen.json';
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
    /* Die Vorklimatisierung horcht seit 1.1.17 nicht mehr hier: der
     * Abfahrts-Assistent sendet ABFAHRT_IN und OK fluechtig (Zeitbezug), und
     * ein Abo von zwei Sekunden traf sie praktisch nie. Sie liest seinen
     * Stand ueber mg_abfahrt_lesen(). */
    $t = array();
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
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    mg_write_json($p['datadir'] . '/fremd.json', array(
        'zeit' => date('c'), 'werte' => $werte,
    ));
    return $werte;
}

function mg_horcher_zustand()
{
    $d = mg_json_lesen(mg_paths()['datadir'] . '/fremd.json');
    return array(
        'zeit' => isset($d['zeit']) ? (string) $d['zeit'] : '',
        'werte' => isset($d['werte']) && is_array($d['werte']) ? $d['werte'] : array(),
        'themen' => mg_horcher_themen(),
    );
}

/* ==================================================================
 * Der Abfahrts-Assistent auf DIESEM LoxBerry
 *
 * Die Vorklimatisierung braucht "Minuten bis zur Abfahrt". Bis 1.1.16 kamen
 * sie ueber ein Abo von zwei Sekunden auf <praefix>/ABFAHRT_IN und
 * <praefix>/OK. Der Abfahrts-Assistent sendet beide seit 1.6.13 fluechtig
 * (Zeitbezug, so entschieden); ein Abo, das zwei Sekunden je Minute lauscht,
 * sah sie praktisch nie, und die Vorklimatisierung loeste nicht aus (in WSL
 * gemessen, Pruefung-MGiSmart-1.1.17, Fall Z1).
 *
 * Gelesen wird jetzt, was der Abfahrts-Assistent dafuer anbietet: seinen
 * unangemeldeten Leseendpunkt termin.php ohne Parameter. Er rechnet nicht und
 * schreibt nichts; er gibt den Stand aus, den sein Dienst im Minutentakt
 * fortschreibt, samt ALTER (Sekunden seit der Berechnung) als
 * Ausfallerkennung. Dieselbe Bauart nutzt er selbst fuer das Ferien-Plugin
 * (abfahrt_lokal_url(): 127.0.0.1, Port aus general.json -> Webserver.Port).
 * Seine Zwischendatei stand.json ist innerer Aufbau und wird nicht gelesen.
 * ================================================================== */

/** Der Ordner des Abfahrts-Assistenten - FOLDER aus dessen plugin.cfg, seine
 *  Kennung. Eine Zweitinstallation (abfahrtsassistent01) wird nicht gesucht. */
function mg_abfahrt_ordner()
{
    return 'abfahrtsassistent';
}

/** Liegt der Abfahrts-Assistent auf dieser Anlage? Nur unter der gelesenen
 *  Wurzel (mg_paths()), nie ab der Laufwerkswurzel. */
function mg_abfahrt_da()
{
    $p = mg_paths();
    return $p['lbhome'] !== ''
        && is_file($p['lbhome'] . '/webfrontend/html/plugins/' . mg_abfahrt_ordner() . '/termin.php');
}

/**
 * Eine Adresse holen: Rumpf und die LETZTE Statuszeile.
 *
 * Ueber fopen und stream_get_meta_data(), nicht ueber file_get_contents und
 * die magische Variable $http_response_header: die meldet PHP 8.5 schon beim
 * Uebersetzen als "Deprecated" - auch in einem Zweig, der nie laeuft
 * (Befund C7/U15, 1.1.17 mg_lib.php:2416-2417; unter 8.5 mit angezeigten
 * Fehlern begann damit jede Seite und jede Endpunktantwort mit zwei
 * Hinweiszeilen). wrapper_data traegt dieselben Zeilen, in PHP 7.4 bis 8.5
 * gleich; mit ignore_errors oeffnet fopen auch 4xx und 5xx. Scheitert fopen,
 * gilt http_get_last_response_headers(), wo es sie gibt (ab 8.4). Bauart
 * abfahrt_http_holen(), Abfahrts-Assistent 1.6.17.
 *
 * Rueckgabe array(rumpf|false, statuszeile) - '' wenn keine kam.
 */
function mg_http_holen($url, $sekunden)
{
    $ctx = stream_context_create(array('http' => array(
        'timeout' => (float) $sekunden, 'user_agent' => 'LoxBerry MG iSmart',
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1)));
    $alt = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', (string) (int) max(1, $sekunden));
    $roh = false;
    $koepfe = null;
    set_error_handler(function () { return true; });
    $fh = fopen($url, 'rb', false, $ctx);
    restore_error_handler();
    if ($fh !== false) {
        $roh = stream_get_contents($fh);
        $meta = stream_get_meta_data($fh);
        fclose($fh);
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            $koepfe = $meta['wrapper_data'];
        }
    } elseif (function_exists('http_get_last_response_headers')) {
        $koepfe = http_get_last_response_headers();
    }
    ini_set('default_socket_timeout', (string) $alt);
    $status = '';
    if (is_array($koepfe)) {
        foreach ($koepfe as $kopf) {
            if (preg_match('#^HTTP/\S+\s+\d{3}#', (string) $kopf)) {
                $status = (string) $kopf;
            }
        }
    }
    return array($roh, $status);
}

/** Der Port des LoxBerry-Webservers (general.json, Webserver.Port; sonst 80). */
function mg_webport()
{
    $p = mg_paths();
    if ($p['lbhome'] === '') {
        return 80;
    }
    $g = mg_json_lesen($p['lbhome'] . '/config/system/general.json');
    foreach (array('Webserver', 'WEBSERVER') as $ab) {
        if (isset($g[$ab]['Port']) && is_scalar($g[$ab]['Port'])
            && (int) $g[$ab]['Port'] > 0 && (int) $g[$ab]['Port'] < 65536) {
            return (int) $g[$ab]['Port'];
        }
    }
    return 80;
}

/**
 * Den Stand des Abfahrts-Assistenten lesen.
 *
 * Rueckgabe array('lage' => 'ok'|'veraltet'|'fehlt'|'unbekannt',
 *                 'ok' => 0|1, 'in' => Minuten bis zur Abfahrt,
 *                 'alter' => Sekunden, 'grund' => Text).
 * 'ok' heisst: Antwort gelesen und der Stand hoechstens 300 s alt - der
 * Dienst des Abfahrts-Assistenten rechnet jede Minute; ein aelterer Stand
 * gilt nicht (steht der Dienst, bliebe sonst die letzte Restzeit stehen).
 * Nur mit 'ok' darf die Vorklimatisierung ausloesen.
 */
function mg_abfahrt_lesen()
{
    $aus = array('lage' => 'fehlt', 'ok' => 0, 'in' => 9999, 'alter' => -1, 'grund' => '');
    if (mg_paths()['lbhome'] === '') {
        $aus['lage'] = 'unbekannt';
        $aus['grund'] = 'keine LoxBerry-Wurzel';
        return $aus;
    }
    if (!mg_abfahrt_da()) {
        $aus['grund'] = 'Abfahrts-Assistent nicht installiert';
        return $aus;
    }
    $port = mg_webport();
    $url = 'http://127.0.0.1' . ($port === 80 ? '' : ':' . $port)
         . '/plugins/' . mg_abfahrt_ordner() . '/termin.php';
    list($roh, $status) = mg_http_holen($url, 4);
    if ($roh === false || !preg_match('#^HTTP/\S+\s+200\b#', $status)) {
        $aus['lage'] = 'unbekannt';
        $aus['grund'] = 'termin.php antwortet nicht' . ($status !== '' ? ' (' . $status . ')' : '');
        return $aus;
    }
    $zeile = '';
    foreach (preg_split('/\r?\n/', (string) $roh) as $z) {
        if (strpos($z, 'TERMIN;') === 0) { $zeile = $z; }
    }
    $w = array();
    if (preg_match_all('/;([A-Z_]+)=(-?[0-9]+(?:\.[0-9]+)?)(?=;|$)/', $zeile, $t, PREG_SET_ORDER)) {
        foreach ($t as $m) { $w[$m[1]] = $m[2]; }
    }
    if (!isset($w['OK'], $w['ABFAHRT_IN'], $w['ALTER'])) {
        $aus['lage'] = 'unbekannt';
        $aus['grund'] = 'Antwort ohne OK, ABFAHRT_IN oder ALTER';
        return $aus;
    }
    $aus['ok'] = ((int) $w['OK'] === 1) ? 1 : 0;
    $aus['in'] = (int) $w['ABFAHRT_IN'];
    $aus['alter'] = (int) $w['ALTER'];
    if ($aus['alter'] < 0 || $aus['alter'] > 300) {
        $aus['lage'] = 'veraltet';
        $aus['grund'] = 'Stand ' . $aus['alter'] . ' s alt - laeuft der Dienst des Abfahrts-Assistenten?';
        return $aus;
    }
    $aus['lage'] = 'ok';
    return $aus;
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
        /* Nur ein frischer Stand des Abfahrts-Assistenten zaehlt
         * (mg_abfahrt_lesen()); fehlt er, ist er veraltet oder nicht zu
         * lesen, bleibt die Vorklimatisierung aus, und das Protokoll sagt
         * es einmal. */
        $ab = mg_abfahrt_lesen();
        mg_log_if_changed('abfahrt', $ab['lage'] === 'ok'
            ? 'Abfahrts-Assistent gelesen'
            : 'Vorklimatisierung ausgesetzt: ' . $ab['grund']);
        $in = $ab['lage'] === 'ok' ? (float) $ab['in'] : -1;
        $ok = $ab['lage'] === 'ok' ? (int) $ab['ok'] : 0;
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
    $retain = mg_mqtt_retain_liste();
    $aus = array();
    foreach (mg_mqtt_werte($nr, $st) as $name => $wert) {
        $w = str_replace(array("\r", "\n"), ' ', (string) $wert);
        /* Entscheidung 5 vom 29.09.2026 (Befund M5): ein zurueckbehaltener
         * Zustand ohne Aussage geht als "-" hinaus - nie leer (eine leere
         * Nutzlast loescht nur, mg_mqtt_behalten() schickt sie deshalb
         * fluechtig) und nie als stehenbleibender Altwert. Bis 1.1.17 blieb
         * klima_text "on" zurueckbehalten stehen, waehrend klima schon -1
         * sagte. Zahlen tragen fuer "nicht bekannt" weiter -1 (FRAGEN_7UHR
         * Nr. 4); betroffen sind also die Textzustaende. Der Strich steht
         * NACH dem Saeubern (Regeln/07). */
        if (trim($w) === '' && isset($retain[$name])) {
            $w = '-';
        }
        $aus[$basis . $name] = $w;
    }
    return $aus;
}

/**
 * Die Themen, die zurueckbehalten (-r) hinausgehen - eine POSITIVLISTE.
 *
 * Bis 1.1.16 stand hier eine Ausnahmeliste mit der Vorgabe "retained": jedes
 * Thema, das nicht ausdruecklich ausgenommen war, ging mit -r hinaus - auch
 * jedes neue (Bestandsliste Klasse E vom 19.09.2026). Jetzt geht nur
 * zurueckbehalten hinaus, was hier steht; alles andere fluechtig.
 *
 * Die Frage je Thema (Regeln/07, Entscheidungen vom 18. und 19.09.2026, dazu
 * "Werte, die allein durch die Uhr falsch werden" vom 24.09.2026): Wer sagt
 * das - das Fahrzeug bzw. eine Einstellung, oder der Dienst ueber sich
 * selbst? Und wird der Wert allein durch den Lauf der Uhr falsch?
 *
 * Zurueckbehalten: Zustaende des Fahrzeugs (Ladestand, Stecker, Tueren,
 * Klima ...), Einstellungen (Ziel, Stromgrenze, Anzeigename, Kennung),
 * Zaehler, die wahr bleiben (Kilometerstand, seit der letzten Ladung), und
 * fertig_um (ein fester Zeitpunkt).
 *
 * Nicht zurueckbehalten - jedes Thema, das hier fehlt, darunter:
 *   ok, themen          Aussagen des Dienstes ueber seinen eigenen Lauf
 *   erreichbar, gateway, fehler       Aussagen des SAIC-Gateways, eines fremden
 *                       Dienstes in der Kette, ueber sich selbst: gateway
 *                       spiegelt dessen Letzten Willen, und stirbt dieser
 *                       Minutenlauf, bliebe der Spiegel stehen (entschieden
 *                       vom Hausherrn am 25.09.2026)
 *   alter, fahrzeugalter, restzeit    Alter und Dauer (seit 1.1.15)
 *   leistung, tempo, innentemperatur, aussentemperatur, ac_leistung,
 *   ac_strom, ac_spannung             Messwerte mit Zeitbezug
 *   km_tag, verbrauch_tag             Tageswerte - um Mitternacht falsch
 *   push_aktiv, push_test             Fenster, die mit der Uhr enden
 *   batterie12v, reifen_vl/vr/hl/hr   Messwerte (seit 1.1.18, Entscheidung 3
 *                       vom 29.09.2026, "ohne Rueckfrage entschieden"); ihre
 *                       Altwerte raeumt mg_mqtt_altlast() einmal ab, der Merker
 *                       entsteht erst aus der Antwort des Brokers
 *   die acht Textthemen, die regelmaessig leer werden (seit 1.1.12)
 *   gateway/anmeldung   (seit 1.1.19, <praefix>/gateway/anmeldung) die Anmeldung
 *                       des Gateways bei MG - eine Aussage des Gateways ueber
 *                       sich selbst (Entscheidung des Hausherrn 30.09.2026);
 *                       geht bei jedem Takt fluechtig hinaus
 * Gemessen am empfangenen Paket in WSL, Pruefung-MGiSmart-1.1.17, Faelle R1-R3.
 */
function mg_mqtt_retain_liste()
{
    static $l = null;
    if ($l === null) {
        $l = array_fill_keys(array(
            'soc', 'energie', 'ziel', 'reichweite', 'laedt', 'stecker',
            'kilometerstand', 'verschlossen', 'kofferraum', 'voll',
            'push', 'laeuft', 'zuhause',
            'entfernung', 'km_seit_ladung', 'tueren_offen', 'fenster_offen',
            'ladeart',
            'kabel_verriegelt', 'stromgrenze', 'kapazitaet', 'verbrauch_seit_ladung',
            'fertig_um', 'batterieheizung', 'klima', 'klima_soll', 'heckscheibe',
            'frontscheibe', 'sitzheizung_l', 'sitzheizung_r', 'stecker_fahrzeug',
            'stecker_saeule', 'name', 'vin', 'klima_text', 'stromgrenze_text',
        ), 1);
    }
    return $l;
}

/** Geht dieser Wert behalten (-r) hinaus? Nie bei einem leeren Wert - eine
 *  leere Nutzlast mit -r LOESCHT das Thema im Broker -, sonst genau dann,
 *  wenn der Name in mg_mqtt_retain_liste() steht. */
function mg_mqtt_behalten($thema, $wert)
{
    if ((string) $wert === '') {
        return false;
    }
    $name = substr((string) $thema, strrpos('/' . $thema, '/'));
    $liste = mg_mqtt_retain_liste();
    return isset($liste[$name]);
}

/** Die Namen, die heute fluechtig hinausgehen - jeder davon kann aus einer
 *  frueheren Fassung noch zurueckbehalten im Broker liegen. */
function mg_mqtt_altlast_liste()
{
    $liste = mg_mqtt_retain_liste();
    $aus = array();
    foreach (array_keys(mg_mqtt_themen()) as $t) {
        $name = substr($t, strlen('<n>/'));
        if (!isset($liste[$name])) {
            $aus[] = $name;
        }
    }
    sort($aus);
    return $aus;
}

/**
 * Den Broker fragen, welche Themen er zurueckbehaelt - in EINER Verbindung,
 * ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'grund' => Text,
 *                 'behalten' => array(thema => wert)).
 * 'ok' heisst: Anmeldung angenommen (CONNACK 0) und JEDER Filter bestaetigt
 * (SUBACK-Rueckgabe unter 0x80); was dann nicht unter 'behalten' steht, liegt
 * nicht zurueckbehalten im Broker. 'unbekannt': er war nicht zu fragen - keine
 * Verbindung, Anmeldung abgewiesen, ein Filter abgelehnt, keine Antwort. Das
 * heisst NIE "nichts belegt": ein Broker, der das Lesen verweigert, schickt
 * nach dem SUBACK nichts (Bauart bw_mqtt_behalten_liste(),
 * Beschattungswaechter 0.9.21; in WSL gemessen, Pruefung-MGiSmart-1.1.17,
 * Faelle R6, R7, V1, V2).
 *
 * Belegt ist ein Thema nur am EMPFANGENEN Paket mit Retain-Merkmal und nicht
 * leerer Nutzlast. Angemeldet wird mit den Zugangsdaten aus dem Reiter MQTT -
 * derselbe Broker, an den mosquitto_pub sendet. Das Kennwort steht nur im
 * CONNECT-Paket, nie in einem Protokoll und nie auf einer Kommandozeile.
 * MQTT 3.1.1 von Hand (CONNECT, SUBSCRIBE mit QoS 0, DISCONNECT), ohne fremde
 * Bibliothek.
 */
function mg_mqtt_rueckfrage(array $filter)
{
    /* 'connack' (seit 1.1.18): -1 = keine Antwort auf die Anmeldung, sonst der
     * Rueckgabecode - die Zeile "Nimmt der Broker die Anmeldung an?" im Reiter
     * Test braucht ihn als Zahl, nicht als Satz. */
    $aus = array('lage' => 'unbekannt', 'grund' => '', 'behalten' => array(), 'connack' => -1);
    $soll = array();
    foreach ($filter as $f) {
        if ((string) $f !== '') { $soll[(string) $f] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    // Dieselben Zugangsdaten wie mosquitto_sub/_pub (mg_broker_zugang(), 1.1.18).
    $zugang = mg_broker_zugang();
    $host = trim((string) $zugang['host']);
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $zugang['port'];
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $zugang['user'];
    $kennwort = $zugang['pass'];
    $ziel = (strpos($host, ':') !== false) ? '[' . $host . ']' : $host;
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $ziel . ':' . $port, $errno, $errstr, 2);
    if (!$s) {
        $aus['grund'] = 'keine Verbindung zu ' . $host . ':' . $port;
        return $aus;
    }
    stream_set_timeout($s, 1);
    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('mgrueck' . getmypid() . mt_rand(100, 999));
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) === false) {
        fclose($s);
        $aus['grund'] = 'Anmeldung nicht zu senden';
        return $aus;
    }
    $ack = $paket();
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) {
        $aus['grund'] = 'keine Antwort auf die Anmeldung';
    } elseif (ord($ack[1][1]) !== 0) {
        $aus['connack'] = ord($ack[1][1]);
        $aus['grund'] = 'Anmeldung abgewiesen, CONNACK ' . ord($ack[1][1]);
    } else {
        $aus['connack'] = 0;
        $sub = pack('n', 1);
        foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
        @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
        $bestaetigt = false;
        $abgelehnt = false;
        $behalten = array();
        $ende = microtime(true) + 3.0;
        while (microtime(true) < $ende) {
            $pk = $paket();
            if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
            $art = $pk[0] >> 4;
            if ($art === 9) {
                /* Je Filter ein Rueckgabebyte hinter der Paketkennung;
                   0x80 heisst abgelehnt. */
                $rc = (string) substr($pk[1], 2);
                if (strlen($rc) !== count($soll)) { $abgelehnt = true; }
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                }
                if ($abgelehnt) { break; }
                $bestaetigt = true;
                // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                $ende = min($ende, microtime(true) + 1.0);
            } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                $tl = unpack('n', substr($pk[1], 0, 2));
                $t = substr($pk[1], 2, $tl[1]);
                $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                $wert = (string) substr($pk[1], $versatz);
                // Am empfangenen Paket: nur mit gesetztem Retain-Merkmal.
                if (($pk[0] & 1) && $wert !== '') { $behalten[$t] = $wert; }
            }
        }
        if ($bestaetigt && !$abgelehnt) {
            $aus['lage'] = 'ok';
            $aus['behalten'] = $behalten;
        } else {
            $aus['grund'] = $abgelehnt ? 'Abonnement abgelehnt, SUBACK 0x80'
                                       : 'keine Bestaetigung des Abonnements';
        }
    }
    @fwrite($s, chr(0xE0) . chr(0));
    fclose($s);
    return $aus;
}

/*
 * Die Altlast: fluechtige Themen, die eine fruehere Fassung zurueckbehalten
 * gesendet hat, liegen weiter im Broker und kaemen nach jedem Neustart von
 * Broker oder MQTT-Gateway als frisch beim Miniserver an. Fort ist ein Wert
 * erst, wenn eine LEERE Nutzlast mit -r auf dasselbe Thema faellt.
 *
 * Abgeraeumt wird nach der ANTWORT DES BROKERS, nie auf den Sendeerfolg
 * (Regeln/07, Nachtrag 19.09.2026; Vorbild VolkswagenID 0.9.24
 * mqtt_altlast_abraeumen(), Funkwacht 1.0.6):
 *   - Merker liegt mit der Kennung dieses Stamms -> nichts zu tun.
 *   - Broker fragen (mg_mqtt_rueckfrage()). Nichts belegt -> Merker, nichts
 *     abraeumen. Einiges belegt -> genau das, leere Nutzlast unmittelbar vor
 *     dem gueltigen Wert; danach NACHLESEN, und erst wenn der Broker nichts
 *     mehr hat, der Merker.
 *   - Nicht zu fragen -> KEIN Merker; alle fluechtigen Themen des Stamms
 *     gehen mit leerer Nutzlast vor dem gueltigen Wert hinaus.
 * Gefragt wird hoechstens alle 30 Minuten je Stamm (retain_altlast_gefragt),
 * damit ein Broker, der das Lesen verweigert, nicht jede Minute 24 leere
 * Nachrichten je Fahrzeug bekommt.
 *
 * Merker retain_altlast_bestaetigt im Datenordner, eine Zeile je Stamm
 * "leer-bestaetigt <praefix>/<fahrzeug>: <Themenliste>": ein anderes Praefix,
 * ein weiteres Fahrzeug oder eine laengere Liste gilt nicht, und die Merker
 * der Vorfassungen (retain_zeitbezug_geraeumt, mqtt_fluechtig_abgeraeumt)
 * haben einen anderen Namen und gelten deshalb ebenfalls nicht - die Merker
 * bis 1.1.16 entstanden auf den Sendeerfolg, der Textmerker galt nur fuer
 * das erste Fahrzeug (gemessen, Pruefung-MGiSmart-1.1.17, Faelle R1, R4, R5,
 * R8). purge_installation raeumt den Datenordner bei jedem Update; dann wird
 * einmal nachgefragt.
 */
function mg_mqtt_altlast_merker()
{
    return mg_paths()['datadir'] . '/retain_altlast_bestaetigt';
}

function mg_mqtt_altlast_gefragt_datei()
{
    return mg_paths()['datadir'] . '/retain_altlast_gefragt';
}

/** Die Zeilen einer kleinen Merkdatei, leere weggelassen. */
function mg_mqtt_merkzeilen($datei)
{
    if (!is_file($datei)) {
        return array();
    }
    $roh = @file_get_contents($datei);
    if ($roh === false) {
        return array();
    }
    return array_values(array_filter(preg_split('/\r?\n/', $roh), 'strlen'));
}

function mg_mqtt_altlast_kennung($stamm)
{
    return 'leer-bestaetigt ' . $stamm . ': ' . implode(' ', mg_mqtt_altlast_liste());
}

/** Traegt der Merker die Kennung dieses Stamms? */
function mg_mqtt_altlast_bestaetigt($stamm)
{
    return in_array(mg_mqtt_altlast_kennung($stamm),
                    mg_mqtt_merkzeilen(mg_mqtt_altlast_merker()), true);
}

/** Den Stamm als bestaetigt eintragen; ein Fehlschlag wird gemeldet. */
function mg_mqtt_altlast_bestaetigen($stamm)
{
    $f = mg_mqtt_altlast_merker();
    $neu = array();
    foreach (mg_mqtt_merkzeilen($f) as $z) {
        if (strpos($z, 'leer-bestaetigt ' . $stamm . ': ') !== 0) { $neu[] = $z; }
    }
    $neu[] = mg_mqtt_altlast_kennung($stamm);
    if (!mg_write_atomic($f, implode("\n", $neu) . "\n", 0644)) {
        mg_log_if_changed('retain_merker', 'Der Merker ' . $f . ' liess sich nicht'
            . ' schreiben; der Broker wird deshalb in 30 Minuten erneut gefragt.');
        return false;
    }
    return true;
}

/** Wann wurde fuer diesen Stamm zuletzt gefragt? 0 = nie oder unlesbar.
 *  Der Inhalt wird als Zahl geprueft, bevor mit ihm gerechnet wird. */
function mg_mqtt_altlast_gefragt($stamm)
{
    foreach (mg_mqtt_merkzeilen(mg_mqtt_altlast_gefragt_datei()) as $z) {
        if (preg_match('/^(\S+) ([0-9]{1,12})$/', $z, $t) && $t[1] === $stamm) {
            return (int) $t[2];
        }
    }
    return 0;
}

function mg_mqtt_altlast_fragen_merken($stamm)
{
    $f = mg_mqtt_altlast_gefragt_datei();
    $neu = array();
    foreach (mg_mqtt_merkzeilen($f) as $z) {
        if (strpos($z, $stamm . ' ') !== 0) { $neu[] = $z; }
    }
    $neu[] = $stamm . ' ' . time();
    return mg_write_atomic($f, implode("\n", $neu) . "\n", 0644);
}

/**
 * Welche Altwerte gehen in diesem Lauf mit leerer Nutzlast vor dem gueltigen
 * Wert hinaus? Rueckgabe array('lage' => 'erledigt'|'wartet'|'belegt'|
 * 'unbekannt', 'themen' => array(<volles Thema>, ...)).
 */
function mg_mqtt_altlast($stamm)
{
    if (mg_mqtt_altlast_bestaetigt($stamm)) {
        return array('lage' => 'erledigt', 'themen' => array());
    }
    $zuletzt = mg_mqtt_altlast_gefragt($stamm);
    $vergangen = time() - $zuletzt;
    if ($zuletzt > 0 && $vergangen >= 0 && $vergangen < 1800) {
        return array('lage' => 'wartet', 'themen' => array());
    }
    mg_mqtt_altlast_fragen_merken($stamm);
    $voll = array();
    foreach (mg_mqtt_altlast_liste() as $n) {
        $voll[] = $stamm . '/' . $n;
    }
    $f = mg_mqtt_rueckfrage($voll);
    if ($f['lage'] === 'ok') {
        $belegt = array_values(array_intersect($voll, array_keys($f['behalten'])));
        if (!$belegt) {
            if (mg_mqtt_altlast_bestaetigen($stamm)) {
                mg_log('MQTT: unter ' . $stamm . '/ steht keiner der frueher zurueckbehaltenen'
                    . ' Werte im Broker (' . count($voll) . ' Themen; vom Broker bestaetigt).');
            }
            return array('lage' => 'erledigt', 'themen' => array());
        }
        mg_log('MQTT: im Broker stehen noch zurueckbehaltene Altwerte unter ' . $stamm
            . '/ (' . implode(', ', array_map('basename', $belegt)) . ') - sie gehen mit leerer'
            . ' Nutzlast unmittelbar vor dem gueltigen Wert hinaus und werden danach nachgelesen.');
        return array('lage' => 'belegt', 'themen' => $belegt);
    }
    mg_log_if_changed('altlast_' . substr(md5($stamm), 0, 8), 'MQTT: der Broker liess sich nicht'
        . ' befragen (' . $f['grund'] . ') - die frueher zurueckbehaltenen Werte unter ' . $stamm
        . '/ gehen deshalb alle 30 Minuten mit leerer Nutzlast unmittelbar vor dem gueltigen'
        . ' Wert hinaus; ein Merker entsteht so nicht. Siehe README, Fassung 1.1.17.');
    return array('lage' => 'unbekannt', 'themen' => $voll);
}

/** Nach dem Abraeumen: steht noch etwas? Erst wenn nicht, der Merker. */
function mg_mqtt_altlast_nachlesen($stamm, array $themen)
{
    // Die leeren Nachrichten kamen ueber eigene Verbindungen (mosquitto_pub);
    // der Broker bekommt einen Augenblick, sie zu uebernehmen.
    usleep(300000);
    $f = mg_mqtt_rueckfrage($themen);
    if ($f['lage'] !== 'ok') {
        mg_log('MQTT: das Nachlesen unter ' . $stamm . '/ gelang nicht (' . $f['grund']
            . ') - kein Merker; in 30 Minuten wird wieder gefragt.');
        return false;
    }
    $rest = array_values(array_intersect($themen, array_keys($f['behalten'])));
    if ($rest) {
        mg_log('MQTT: unter ' . $stamm . '/ stehen nach dem Abraeumen noch zurueckbehaltene Werte ('
            . implode(', ', array_map('basename', $rest)) . ') - der Broker hat die leere'
            . ' Nachricht nicht uebernommen; kein Merker, in 30 Minuten wird wieder gefragt.');
        return false;
    }
    if (mg_mqtt_altlast_bestaetigen($stamm)) {
        mg_log('MQTT: die frueher zurueckbehaltenen Werte unter ' . $stamm . '/ sind abgeraeumt ('
            . count($themen) . ' Themen; vom Broker bestaetigt).');
    }
    return true;
}

/** Eine vollstaendige mosquitto_pub-Zeile fuer ein Thema. */
function mg_mqtt_zeile($thema, $wert)
{
    return mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
         . (mg_mqtt_behalten($thema, $wert) ? ' -r' : '')
         . ' -t ' . escapeshellarg($thema)
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

    /* Die Altlast (mg_mqtt_altlast()). Das steht VOR der Pruefung "nichts
     * geaendert", damit die Abraeumung nicht auf die naechste Aenderung
     * wartet; der gueltige Wert geht in derselben Datei ohne -r hinterher. */
    $stamm = trim((string) $cfg['mqtt_praefix'], '/ ') . '/' . (int) $nr;
    $altlast = mg_mqtt_altlast($stamm);
    $raeumen = array();
    foreach ($altlast['themen'] as $thema) {
        if (array_key_exists($thema, $paare)) {
            $raeumen[] = $thema;
            $zu_senden[$thema] = $paare[$thema];
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
    $p = mg_paths();
    $zeilen = '';
    foreach ($raeumen as $thema) {
        $zeilen .= mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
                 . ' -r -t ' . escapeshellarg((string) $thema) . " -m '' || exit 1\n";
    }
    foreach ($zu_senden as $thema => $wert) {
        $zeilen .= mg_mqtt_zeile($thema, $wert) . ' || exit 1' . "\n";
    }
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
    // Der Merker entsteht erst aus der Antwort des Brokers, nie aus dem
    // Senden: war einiges belegt, jetzt nachlesen.
    if ($raeumen && $altlast['lage'] === 'belegt') {
        mg_mqtt_altlast_nachlesen($stamm, $raeumen);
    }
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
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $datei = $p['datadir'] . '/probe.' . getmypid() . '.' . mt_rand(1000, 9999) . '.sh';
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
 * Lebenszeichen und Fehlerzweig (seit 1.1.18, Befund M3)
 *
 * Bis 1.1.17 ging bei einem unveraenderten Lauf NICHTS hinaus, und bei einem
 * Lesefehler auch nicht: ok blieb im virtuellen Eingang auf der letzten 1,
 * und ein toter Takt war von einem gesunden nicht zu unterscheiden
 * (Regeln/07, "Lebenszeichen aus vier Themen", "bei Fehlschlag
 * ausschliesslich ok=0").
 *   <praefix>/status/ts       Unix-Sekunden des Laufs
 *   <praefix>/status/zaehler  0...999, laeuft um
 * Beide je Lauf, am Aenderungsfilter vorbei, NIE zurueckbehalten (sie
 * stehen nicht in mg_mqtt_retain_liste()). Das vierte Thema aus Regeln/07,
 * status/<dienst>, gibt es hier nicht: die Linie hat keinen Dauerlaeufer.
 * ================================================================== */

/** Die Lebenszeichen-Themen fuer die Tabelle im Reiter MQTT. */
function mg_mqtt_status_themen()
{
    return array('status/ts' => 'MQTT.STATUS_TS', 'status/zaehler' => 'MQTT.STATUS_ZAEHLER');
}

/**
 * Einige Themen in EINER Schale senden, jedes Argument angefuehrt.
 * Zurueckbehalten wird nach mg_mqtt_behalten() - fuer die Themen dieses
 * Abschnitts also nie. Rueckgabe: true, wenn jeder Aufruf mit 0 endete (der
 * Broker hat die Nachricht angenommen - mehr sagt das nicht).
 */
function mg_mqtt_einzeln(array $paare)
{
    if (!$paare || !mg_has_mosquitto()) {
        return false;
    }
    $zeilen = '';
    foreach ($paare as $thema => $wert) {
        $zeilen .= mg_mqtt_zeile((string) $thema, (string) $wert) . ' || exit 1' . "\n";
    }
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $tmp = $p['tmp'] . '/einzeln.' . getmypid() . '.' . mt_rand(1000, 9999) . '.sh';
    if (!mg_write_atomic($tmp, $zeilen, 0600)) {
        return false;
    }
    $out = array();
    $rc = 0;
    @exec('sh ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    if (is_file($tmp)) { @unlink($tmp); }
    if ($rc !== 0) {
        mg_log_if_changed('mqtt_einzeln', 'Lebenszeichen/ok nicht gesendet: '
            . trim(implode(' ', array_slice($out, 0, 2))));
        return false;
    }
    return true;
}

/** Das Lebenszeichen eines Laufs; der Zaehler liegt im Zwischenspeicher. */
function mg_mqtt_lebenszeichen($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if (empty($cfg['mqtt_ein']) || $praefix === '' || !mg_has_mosquitto()) {
        return false;
    }
    $p = mg_paths();
    $f = $p['tmp'] . '/lebenszeichen_zaehler';
    $alt = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    $n = preg_match('/^[0-9]{1,3}$/', $alt) ? ((int) $alt + 1) % 1000 : 0;
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    mg_write_atomic($f, (string) $n);
    return mg_mqtt_einzeln(array(
        $praefix . '/status/ts' => (string) time(),
        $praefix . '/status/zaehler' => (string) $n,
    ));
}

/**
 * Der Fehlerzweig des Takts: je Fahrzeug ok=0, fluechtig. Die Zustaende
 * bleiben unberuehrt - sie sind das Letzte, was wirklich gemessen wurde
 * (Regeln/07, "Ueber MQTT geht bei einer Stoerung nur das Signal hinaus").
 * Im Merker der Veroeffentlichung steht ok danach auf 0, damit der naechste
 * gelungene Lauf die 1 wieder sendet - der Aenderungsfilter hielte sie sonst
 * zurueck, und ok bliebe im Miniserver auf 0.
 */
function mg_mqtt_ok_null($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if (empty($cfg['mqtt_ein']) || $praefix === '' || !mg_has_mosquitto()) {
        return false;
    }
    $paare = array();
    foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
        $paare[$praefix . '/' . (int) $nr . '/ok'] = '0';
    }
    if (!$paare || !mg_mqtt_einzeln($paare)) {
        return false;
    }
    $p = mg_paths();
    foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
        $merk = $p['tmp'] . '/veroeffentlicht' . (int) $nr . '.json';
        $alt = mg_json_lesen($merk);
        $thema = $praefix . '/' . (int) $nr . '/ok';
        if (isset($alt['werte']) && is_array($alt['werte']) && isset($alt['werte'][$thema])) {
            $alt['werte'][$thema] = '0';
            mg_write_json($merk, $alt, 0600);
        }
    }
    return true;
}

/**
 * Die Abo-Datei des MQTT-Gateways: config/plugins/<ordner>/mqtt_subscriptions.cfg
 * (seit 1.1.18, Befund M8; Bauart eb_abo_datei() aus Einspeisebremse 0.9.28,
 * abfahrt_abo_datei() aus Abfahrts-Assistent 1.6.17).
 *
 * Das Gateway (V1) liest sie selbst und abonniert jede Zeile (Regeln/07, am
 * Geraet belegt 13.09.2026). Das Archiv bringt sie mit dem Vorgabepraefix mg/#
 * mit; das Praefix ist einstellbar, deshalb fuehrt der Reiter MQTT sie beim
 * Speichern nach und der Minutentakt ebenso (ein Update legt die Datei des
 * Archivs zurueck) - geschrieben wird nur, wenn sie abweicht, mit
 * Protokollzeile. Rueckgabe: array(Pfad, traegt das Abo).
 */
function mg_abo_datei($praefix, $schreiben = false)
{
    $p = mg_paths();
    $dir = dirname($p['config']);
    $pfad = $dir . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/ ') . '/#';
    if ($p['lbhome'] === '' || $soll === '/#') {
        return array($pfad, false);
    }
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $roh !== $soll . "\n" && is_dir($dir)) {
        if (mg_write_atomic($pfad, $soll . "\n", 0644)) {
            mg_log('MQTT: Gateway-Abo nachgefuehrt: ' . $soll . ' (mqtt_subscriptions.cfg)');
            $da = true;
        } else {
            mg_log_if_changed('abo_datei', 'MQTT: ' . $pfad . ' liess sich nicht schreiben - das'
                . ' Gateway (V1) abonniert ' . $soll . ' dann nicht von selbst.');
        }
    }
    return array($pfad, $da);
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

/**
 * Alle Themen unter dem eigenen Praefix, die zurueckbehalten im Broker liegen
 * und heute nicht mehr vorkommen - gefragt ueber mg_mqtt_rueckfrage().
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'grund' => Text,
 *                 'themen' => array(thema => wert)).
 * Bis 1.1.16 fragte mosquitto_sub; abgewiesene Anmeldung und abgelehnter
 * Filter ergaben eine leere Liste, und die Oberflaeche meldete "0 verwaiste
 * Themen gefunden" bzw. "0 geloescht" (in WSL gemessen,
 * Pruefung-MGiSmart-1.1.17, Faelle V1, V2, V4). Gezaehlt wurde dabei auch,
 * was nur gerade gesendet, nicht zurueckbehalten war.
 */
function mg_mqtt_verwaiste_lage()
{
    $cfg = mg_config();
    $aus = array('lage' => 'ok', 'grund' => '', 'themen' => array());
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($praefix === '') {
        return $aus;
    }
    $f = mg_mqtt_rueckfrage(array($praefix . '/#'));
    if ($f['lage'] !== 'ok') {
        $aus['lage'] = 'unbekannt';
        $aus['grund'] = $f['grund'];
        return $aus;
    }
    $soll = array();
    foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
        foreach (mg_mqtt_argumente($nr, mg_state($nr)) as $thema => $wert) {
            $soll[$thema] = 1;
        }
    }
    // seit 1.1.19 (A1): gateway/anmeldung ist ein eigenes Thema, nicht verwaist
    $soll[$praefix . '/gateway/anmeldung'] = 1;
    foreach ($f['behalten'] as $thema => $wert) {
        if (!isset($soll[$thema])
            && strncmp((string) $thema, $praefix . '/', strlen($praefix) + 1) === 0) {
            $aus['themen'][$thema] = $wert;
        }
    }
    ksort($aus['themen']);
    return $aus;
}

/**
 * Die uebergebenen Themen im Broker loeschen (leere Nutzlast, behalten).
 * Rueckgabe: array(anzahl, fehlertext) - die Anzahl nach dem Nachlesen.
 */
function mg_mqtt_verwaiste_loeschen($themen)
{
    $cfg = mg_config();
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($praefix === '') {
        return array(0, '');
    }
    /* Fail closed gegen die Selbstkollision. Gesendet wird unter
     * mqtt_praefix, GEHORCHT wird unter prefix - dem Themenbaum des
     * Gateways. Sind beide gleich, oder liegt der des Gateways
     * unterhalb des eigenen, dann trifft "nur unterhalb des eigenen
     * Praefix" genau die behaltenen Themen des Gateways, und ein Klick
     * auf Aufraeumen loescht sie. Aeltere Konfigurationen koennen so
     * eingestellt sein - deshalb wird hier geprueft und nicht nur beim
     * Speichern. */
    $gw = trim((string) $cfg['prefix'], '/ ');
    if ($gw !== '' && ($gw === $praefix
            || strncmp($gw, $praefix . '/', strlen($praefix) + 1) === 0)) {
        return array(0, 'Eigenes MQTT-Praefix und Gateway-Praefix'
                        . ' kollidieren (' . $praefix . ') -'
                        . ' es wurde nichts geloescht');
    }
    if (!mg_has_mosquitto() || !$themen) {
        return array(0, '');
    }
    $zeilen = '';
    $gesendet = array();
    foreach ((array) $themen as $thema) {
        // Fail closed: nur unterhalb des eigenen Praefix. Ein Thema, das von
        // aussen hereingereicht wurde und woanders liegt, wird uebergangen.
        if (strncmp((string) $thema, $praefix . '/', strlen($praefix) + 1) !== 0) {
            continue;
        }
        $zeilen .= mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
                 . ' -r -t ' . escapeshellarg((string) $thema) . " -m ''\n";
        $gesendet[] = (string) $thema;
    }
    if (!$gesendet) {
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
    // Gemeldet wird, was der Broker danach nicht mehr hat.
    usleep(300000);
    $f = mg_mqtt_rueckfrage($gesendet);
    $n = count($gesendet);
    $fehler = '';
    if ($f['lage'] === 'ok') {
        $rest = array_intersect($gesendet, array_keys($f['behalten']));
        $n -= count($rest);
        if ($rest) {
            $fehler = count($rest) . ' Themen stehen weiter im Broker: '
                . implode(', ', array_slice($rest, 0, 5));
        }
    }
    mg_log('Verwaiste MQTT-Themen geloescht: ' . $n . ($f['lage'] === 'ok'
        ? ' (vom Broker bestaetigt)' : ' (nicht nachgelesen: ' . $f['grund'] . ')'));
    // Der Merker der Veroeffentlichung wird verworfen, damit der naechste
    // Lauf den ganzen Satz neu sendet.
    foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
        @unlink($p['tmp'] . '/veroeffentlicht' . (int) $nr . '.json');
    }
    return array($n, $fehler);
}

/**
 * Die Deinstallation: jedes zurueckbehaltene Thema dieses Plugins im Broker
 * leeren (bin/cron.php --mqtt-leeren, aufgerufen von uninstall/uninstall).
 *
 * Bis 1.1.16 blieben sie stehen - nach dem Entfernen des Plugins lieferte der
 * Broker bei jedem Neustart des MQTT-Gateways den letzten Ladestand, die
 * letzte Kennung usw. an den Miniserver (in WSL gemessen,
 * Pruefung-MGiSmart-1.1.17, Fall U1). Geleert wird unter
 * <mqtt_praefix>/<nummer>/<name> JEDES Thema, das eine Fassung je gesendet
 * haben kann (heute zurueckbehalten oder frueher) - auch unter einer
 * Fahrzeugnummer, die nicht mehr eingerichtet ist, soweit der Broker sie
 * nennt. Ist er nicht zu fragen: blind fuer die eingerichteten Fahrzeuge,
 * und das wird so gesagt. Die Themen des SAIC-Gateways (<prefix>/...) bleiben
 * unberuehrt; kollidieren beide Praefixe, wird gar nichts geloescht.
 * Rueckgabe array(rc, zeilen) - rc 0 in Ordnung, 1 Warnung.
 *
 * $praefix (seit 1.1.18, Befund M6): ein ANDERES eigenes Praefix als das
 * eingestellte - der Reiter MQTT raeumt damit beim Wechsel des Praefixes und
 * beim Ausschalten die Themen unter dem ALTEN ab. Bis 1.1.17 blieben dort 41
 * zurueckbehaltene Zustaende fuer immer liegen. Dieselbe Logik wie bei der
 * Deinstallation: Broker fragen, nur Themen dieses Plugins, Kollisionsschutz
 * gegen das Gateway-Praefix, nachlesen, Ergebnis melden.
 */
function mg_mqtt_leeren($praefix = null)
{
    $z = array();
    $cfg = mg_config();
    $praefix = trim((string) ($praefix === null ? $cfg['mqtt_praefix'] : $praefix), '/ ');
    if ($praefix === '' || strpbrk($praefix, '#+') !== false) {
        $z[] = '<INFO> Kein eigenes MQTT-Praefix eingestellt - im Broker ist nichts abzuraeumen.';
        return array(0, $z);
    }
    $gw = trim((string) $cfg['prefix'], '/ ');
    if ($gw !== '' && ($gw === $praefix
            || strncmp($gw, $praefix . '/', strlen($praefix) + 1) === 0)) {
        $z[] = '<WARNING> Eigenes MQTT-Praefix und Gateway-Praefix kollidieren (' . $praefix
            . ') - im Broker wurde nichts geloescht, sonst traefe es die Themen des Gateways.';
        return array(1, $z);
    }
    if (!mg_has_mosquitto()) {
        $z[] = '<WARNING> mosquitto_pub fehlt - die zurueckbehaltenen Themen unter ' . $praefix
            . '/ bleiben im Broker.';
        return array(1, $z);
    }
    $namen = array();
    foreach (array_keys(mg_mqtt_themen()) as $t) {
        $namen[substr($t, strlen('<n>/'))] = true;
    }
    $f = mg_mqtt_rueckfrage(array($praefix . '/#'));
    $ziel = array();
    if ($f['lage'] === 'ok') {
        $muster = '#^' . preg_quote($praefix, '#') . '/[0-9]{1,3}/(.+)$#';
        foreach (array_keys($f['behalten']) as $t) {
            if (preg_match($muster, (string) $t, $m) && isset($namen[$m[1]])) {
                $ziel[] = (string) $t;
            } elseif ((string) $t === $praefix . '/gateway/anmeldung') {
                // seit 1.1.19 (A1): der einzige eigene Zustand ausserhalb der Fahrzeuge
                $ziel[] = (string) $t;
            }
        }
        if (!$ziel) {
            $z[] = '<OK> Unter ' . $praefix . '/ liegt nichts dieses Plugins zurueckbehalten im'
                . ' Broker (vom Broker bestaetigt).';
            return array(0, $z);
        }
    } else {
        $n = max(1, mg_fahrzeug_anzahl($cfg));
        for ($i = 1; $i <= $n; $i++) {
            foreach (array_keys($namen) as $nm) {
                $ziel[] = $praefix . '/' . $i . '/' . $nm;
            }
        }
        $ziel[] = $praefix . '/gateway/anmeldung';
        $z[] = '<WARNING> Der Broker liess sich nicht befragen (' . $f['grund'] . ') - geleert'
            . ' werden blind die ' . count($ziel) . ' Themen der Fahrzeuge 1 bis ' . $n
            . ' samt ' . $praefix . '/gateway/anmeldung; ob es wirkte, ist nicht nachpruefbar.';
    }
    $zeilen = '';
    foreach ($ziel as $t) {
        $zeilen .= mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
                 . ' -r -t ' . escapeshellarg($t) . " -m '' || echo MGFEHL\n";
    }
    $p = mg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $datei = $p['tmp'] . '/leeren.' . getmypid() . '.sh';
    if (!mg_write_atomic($datei, $zeilen, 0600)) {
        $z[] = '<WARNING> Die Befehlsdatei liess sich nicht schreiben (' . $datei . ') - im Broker'
            . ' wurde nichts geleert.';
        return array(1, $z);
    }
    $out = array();
    @exec('sh ' . escapeshellarg($datei) . ' 2>&1', $out, $rc);
    @unlink($datei);
    $fehl = count(array_keys($out, 'MGFEHL', true));
    if ($f['lage'] !== 'ok') {
        $z[] = '<INFO> ' . (count($ziel) - $fehl) . ' leere Nachrichten gesendet, ' . $fehl
            . ' gescheitert.';
        return array(1, $z);
    }
    usleep(300000);
    $g = mg_mqtt_rueckfrage($ziel);
    if ($g['lage'] !== 'ok') {
        $z[] = '<WARNING> ' . count($ziel) . ' Themen leer gesendet; das Nachlesen gelang nicht ('
            . $g['grund'] . ') - nicht nachpruefbar.';
        return array(1, $z);
    }
    $rest = array_values(array_intersect($ziel, array_keys($g['behalten'])));
    if ($rest) {
        $z[] = '<WARNING> ' . count($rest) . ' von ' . count($ziel) . ' Themen stehen weiter im'
            . ' Broker: ' . implode(', ', array_slice($rest, 0, 10));
        return array(1, $z);
    }
    $z[] = '<OK> ' . count($ziel) . ' zurueckbehaltene Themen unter ' . $praefix . '/ geleert'
        . ' (vom Broker bestaetigt).';
    return array(0, $z);
}

/**
 * Die Deinstallation, zweiter Teil (seit 1.1.18, Bauliste G7 Schritt 5): die
 * zurueckbehaltenen Themen des GATEWAYS unter <prefix>/ leeren - aufgerufen
 * von uninstall/uninstall ueber bin/cron.php --gateway-leeren, und NUR, wenn
 * dort zuvor ein Container mit den eigenen Labels entfernt wurde. Einen
 * handbetriebenen Gateway fasst das Skript nicht an, dessen Themen also auch
 * nicht.
 *
 * Welche Themen es sind, weiss nur der Broker - blind wird hier nichts
 * geleert. Nicht geleert wird ausserdem,
 *  - wenn das eigene Praefix mit dem Gateway-Praefix kollidiert (das raeumt
 *    mg_mqtt_leeren() nach seinen Regeln),
 *  - wenn eine zweite Installation dieses Plugins (ein anderer Ordner unter
 *    config/plugins mit einer mg.json) dasselbe Gateway-Praefix nutzt - ihr
 *    Gateway schreibt unter denselben Baum.
 * Rueckgabe array(rc, zeilen) - rc 0 in Ordnung, 1 Warnung.
 */
function mg_gateway_themen_leeren()
{
    $z = array();
    $cfg = mg_config();
    $gw = trim((string) $cfg['prefix'], '/ ');
    if ($gw === '') {
        $gw = 'saic';
    }
    if (strpbrk($gw, '#+') !== false) {
        $z[] = '<WARNING> Das Gateway-Praefix enthaelt # oder + - im Broker wurde nichts geleert.';
        return array(1, $z);
    }
    $eigen = trim((string) $cfg['mqtt_praefix'], '/ ');
    if ($eigen !== '' && ($eigen === $gw || strncmp($eigen, $gw . '/', strlen($gw) + 1) === 0
            || strncmp($gw, $eigen . '/', strlen($eigen) + 1) === 0)) {
        $z[] = '<WARNING> Gateway-Praefix und eigenes Praefix kollidieren (' . $gw . ') - die Themen'
            . ' des Gateways wurden nicht geleert.';
        return array(1, $z);
    }
    $p = mg_paths();
    foreach (glob($p['lbhome'] . '/config/plugins/*/mg.json') ?: array() as $f) {
        if (basename(dirname($f)) === $p['plugin']) {
            continue;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        $ap = (is_array($d) && isset($d['prefix']) && is_string($d['prefix'])) ? trim($d['prefix'], '/ ') : '';
        if ($ap === '') {
            $ap = 'saic';
        }
        if ($ap === $gw) {
            $z[] = '<INFO> Eine weitere Installation (' . basename(dirname($f)) . ') nutzt dasselbe'
                . ' Gateway-Praefix ' . $gw . '/ - dessen Themen bleiben im Broker.';
            return array(0, $z);
        }
    }
    if (!mg_has_mosquitto()) {
        $z[] = '<WARNING> mosquitto_pub fehlt - die zurueckbehaltenen Themen des Gateways unter '
            . $gw . '/ bleiben im Broker.';
        return array(1, $z);
    }
    $f = mg_mqtt_rueckfrage(array($gw . '/#'));
    if ($f['lage'] !== 'ok') {
        $z[] = '<WARNING> Der Broker liess sich nicht befragen (' . $f['grund'] . ') - die Themen des'
            . ' Gateways unter ' . $gw . '/ wurden nicht geleert (blind geht es hier nicht).'
            . ' Von Hand: mosquitto_sub -t \'' . $gw . '/#\' --retained-only';
        return array(1, $z);
    }
    $ziel = array();
    foreach (array_keys($f['behalten']) as $t) {
        if (strncmp((string) $t, $gw . '/', strlen($gw) + 1) === 0) {
            $ziel[] = (string) $t;
        }
    }
    if (!$ziel) {
        $z[] = '<OK> Unter ' . $gw . '/ liegt nichts zurueckbehalten im Broker (vom Broker bestaetigt).';
        return array(0, $z);
    }
    $zeilen = '';
    foreach ($ziel as $t) {
        $zeilen .= mg_broker_umgebung() . 'mosquitto_pub' . mg_broker_args()
                 . ' -r -t ' . escapeshellarg($t) . " -m '' || echo MGFEHL\n";
    }
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    $datei = $p['tmp'] . '/gwleeren.' . getmypid() . '.sh';
    if (!mg_write_atomic($datei, $zeilen, 0600)) {
        $z[] = '<WARNING> Die Befehlsdatei liess sich nicht schreiben - die Themen des Gateways'
            . ' bleiben im Broker.';
        return array(1, $z);
    }
    $out = array();
    @exec('sh ' . escapeshellarg($datei) . ' 2>&1', $out, $rc);
    @unlink($datei);
    usleep(300000);
    $g = mg_mqtt_rueckfrage($ziel);
    if ($g['lage'] !== 'ok') {
        $z[] = '<WARNING> ' . count($ziel) . ' Themen des Gateways leer gesendet; das Nachlesen gelang'
            . ' nicht (' . $g['grund'] . ') - nicht nachpruefbar.';
        return array(1, $z);
    }
    $rest = array_values(array_intersect($ziel, array_keys($g['behalten'])));
    if ($rest) {
        $z[] = '<WARNING> ' . count($rest) . ' von ' . count($ziel) . ' Themen des Gateways stehen'
            . ' weiter im Broker: ' . implode(', ', array_slice($rest, 0, 10));
        return array(1, $z);
    }
    $z[] = '<OK> ' . count($ziel) . ' zurueckbehaltene Themen des Gateways unter ' . $gw
        . '/ geleert (vom Broker bestaetigt).';
    return array(0, $z);
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
 * Der Gateway-Container - das Plugin legt ihn selbst an (seit 1.1.18)
 *
 * Entscheidung 7 vom 30.09.2026. Bis 1.1.17 bot der Reiter "Gateway
 * einrichten" nur einen docker-Befehl zum Abtippen, und der trug so nicht:
 * 127.0.0.1 ohne --network host zeigt im Container auf den Container selbst,
 * die Broker-Anmeldung fehlte, SAIC_REST_URI zeigte auf gateway-sm-eu statt
 * auf die Vorgabe des Gateways (gateway-mg-eu). Am Geraet gab es keinen
 * Container.
 *
 * Grundsaetze:
 *   - Nur der EIGENE Container wird angefasst: gefunden ueber das Label
 *     de.loxberry.plugin.folder=<ordner> und gegengeprueft mit docker inspect
 *     auf beide Labels (folder UND name). Nie nach dem Namen allein - ein von
 *     Hand angelegter Container gleichen Namens bleibt unberuehrt.
 *   - Jeder docker-Aufruf argumentweise (proc_open mit Liste, keine Schale)
 *     und mit timeout.
 *   - Kennwoerter nie auf eine Befehlszeile: sie gehen ueber eine
 *     Umgebungsdatei (0600, umask 077), die nach docker run in jedem Fall
 *     geloescht wird. docker inspect zeigt sie trotzdem, und wer in der
 *     Gruppe docker ist, sieht sie - das sagt die Hilfe.
 *   - Kein Takt und kein Haken ruft run, stop oder rm (G6). Das Anlegen
 *     laeuft im Hintergrund (bin/gateway_vorgang.php), weil das Holen des
 *     Abbilds Minuten dauern kann; Neustart und Entfernen laufen im Handler.
 * ================================================================== */

/** Der Name des eigenen Containers. */
function mg_gw_name()
{
    return 'lb-' . mg_paths()['plugin'] . '-saic-gateway';
}

/** Pfad zum docker-Programm, oder ''. */
function mg_docker_bin()
{
    static $pfad = null;
    if ($pfad === null) {
        $out = array();
        @exec('command -v docker 2>/dev/null', $out);
        $k = isset($out[0]) ? trim($out[0]) : '';
        $pfad = ($k !== '' && is_file($k)) ? $k : '';
    }
    return $pfad;
}

/**
 * docker argumentweise ausfuehren, begrenzt durch timeout.
 * Rueckgabe array(rc, stdout, stderr); rc 124 heisst Zeitablauf, 127 docker
 * fehlt. proc_open mit einer Liste gibt es seit PHP 7.4 - keine Schale, also
 * kann kein Argument als Befehl gelesen werden.
 */
function mg_docker(array $args, $sekunden)
{
    $bin = mg_docker_bin();
    if ($bin === '' || !function_exists('proc_open')) {
        return array(127, '', 'docker fehlt');
    }
    $cmd = array('timeout', (string) max(1, (int) $sekunden), $bin);
    foreach ($args as $a) {
        $cmd[] = (string) $a;
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $pipes = array();
    $proc = @proc_open($cmd, $desk, $pipes);
    if (!is_resource($proc)) {
        return array(127, '', 'proc_open gescheitert');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $aus = array(1 => '', 2 => '');
    $offen = array(1 => $pipes[1], 2 => $pipes[2]);
    $runden = 0;
    while ($offen && $runden < 100000) {
        $runden++;
        $lesen = array_values($offen);
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 1) === false) {
            break;
        }
        foreach ($offen as $n => $h) {
            $t = fread($h, 65536);
            if ($t !== false && $t !== '' && strlen($aus[$n]) < 1048576) {
                $aus[$n] .= $t;
            }
            if (feof($h)) {
                fclose($h);
                unset($offen[$n]);
            }
        }
    }
    foreach ($offen as $h) {
        fclose($h);
    }
    $rc = proc_close($proc);
    return array((int) $rc, $aus[1], $aus[2]);
}

/**
 * Ist Docker erreichbar? Rueckgabe array(lage, satz) mit lage
 * ok | fehlt | kein_zugriff | dienst_aus | haengt | fehler.
 * Bauart dk_zustand() aus Docker NG 1.3.9.
 */
function mg_docker_lage($sekunden = 10)
{
    if (mg_docker_bin() === '') {
        return array('fehlt', mg_t('GW.DOCKER_FEHLT'));
    }
    list($rc, $out, $err) = mg_docker(array('info', '--format', '{{.ServerVersion}}'), $sekunden);
    if ($rc === 0) {
        return array('ok', sprintf(mg_t('GW.DOCKER_OK'), trim($out)));
    }
    if ($rc === 124) {
        return array('haengt', sprintf(mg_t('GW.DOCKER_HAENGT'), (int) $sekunden));
    }
    $t = strtolower($err);
    if (strpos($t, 'permission denied') !== false) {
        return array('kein_zugriff', mg_t('GW.DOCKER_KEIN_ZUGRIFF'));
    }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) {
        return array('dienst_aus', mg_t('GW.DOCKER_DIENST_AUS'));
    }
    return array('fehler', sprintf(mg_t('GW.DOCKER_FEHLER'), $rc) . ' ' . mg_kuerzen(trim($err), 200));
}

/** docker inspect eines Containers (Kennung oder Name); null, wenn es ihn nicht gibt. */
function mg_gw_inspect($ref, $sekunden = 20)
{
    list($rc, $out, ) = mg_docker(array('inspect', '--type', 'container', (string) $ref), $sekunden);
    if ($rc !== 0) {
        return null;
    }
    $d = json_decode($out, true);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

/** Traegt dieser Container BEIDE eigenen Labels? */
function mg_gw_ist_eigen($info)
{
    $l = (is_array($info) && isset($info['Config']['Labels']) && is_array($info['Config']['Labels']))
        ? $info['Config']['Labels'] : array();
    return isset($l['de.loxberry.plugin.folder'], $l['de.loxberry.plugin.name'])
        && $l['de.loxberry.plugin.folder'] === mg_paths()['plugin']
        && $l['de.loxberry.plugin.name'] === 'mgismart';
}

/**
 * Die eigenen Container: per Label gesucht, per inspect gegengeprueft.
 * Rueckgabe array(ok, liste) - ok false, wenn docker nicht zu fragen war.
 */
function mg_gw_eigene($sekunden = 20)
{
    list($rc, $out, ) = mg_docker(array('ps', '-a', '-q', '--no-trunc', '--filter',
        'label=de.loxberry.plugin.folder=' . mg_paths()['plugin']), $sekunden);
    if ($rc !== 0) {
        return array(false, array());
    }
    $liste = array();
    foreach (preg_split('/\s+/', trim($out)) as $id) {
        if (!preg_match('/^[0-9a-f]{12,64}\z/', $id)) {
            continue;
        }
        $info = mg_gw_inspect($id, $sekunden);
        if ($info !== null && mg_gw_ist_eigen($info)) {
            $liste[] = $info;
        }
    }
    return array(true, $liste);
}

/** Kurzfassung des Zustands aus docker inspect. */
function mg_gw_kurz($info)
{
    $s = isset($info['State']) && is_array($info['State']) ? $info['State'] : array();
    $l = isset($info['Config']['Labels']) && is_array($info['Config']['Labels'])
        ? $info['Config']['Labels'] : array();
    $start = isset($s['StartedAt']) ? strtotime((string) $s['StartedAt']) : false;
    return array(
        'id' => isset($info['Id']) ? substr((string) $info['Id'], 0, 12) : '',
        'id_voll' => isset($info['Id']) ? (string) $info['Id'] : '',
        'laeuft' => !empty($s['Running']),
        'status' => isset($s['Status']) ? (string) $s['Status'] : '',
        'neustarts' => isset($info['RestartCount']) ? (int) $info['RestartCount'] : 0,
        'seit' => ($start !== false && $start > 0) ? max(0, time() - $start) : -1,
        'def' => isset($l['de.loxberry.plugin.def']) ? (string) $l['de.loxberry.plugin.def'] : '',
        'brokerhash' => isset($l['de.loxberry.plugin.brokerhash'])
            ? (string) $l['de.loxberry.plugin.brokerhash'] : '',
        // A4 (seit 1.1.19): Name des Abbilds und die Kennung, mit der er laeuft.
        'bild' => isset($info['Config']['Image']) && is_string($info['Config']['Image'])
            ? $info['Config']['Image'] : '',
        'abbild' => isset($info['Image']) && is_string($info['Image']) ? $info['Image'] : '',
    );
}

/** Der Fingerabdruck des Broker-Zugangs, der im Label des Containers steht (G4). */
function mg_gw_brokerhash($zugang)
{
    return substr(hash('sha256', $zugang['host'] . "\n" . (int) $zugang['port'] . "\n"
        . $zugang['user'] . "\n" . $zugang['pass']), 0, 12);
}

/**
 * Die Umgebungsdatei des Containers bauen (Variablen laut README des
 * Gateways, gelesen 30.09.2026). SAIC_REST_URI und SAIC_REGION werden
 * NICHT gesetzt - es gilt die Vorgabe des Gateways (gateway-mg-eu, eu; G0).
 * Rueckgabe array(ok, inhalt | grund).
 */
function mg_gw_env($cfg, $zugang)
{
    $user = is_string($cfg['saic_user']) ? $cfg['saic_user'] : '';
    $pass = is_string($cfg['saic_pass']) ? $cfg['saic_pass'] : '';
    if (trim($user) === '') {
        return array(false, mg_t('GW.FEHLT_USER'));
    }
    if ($pass === '') {
        return array(false, mg_t('GW.FEHLT_PASS'));
    }
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $host = ($zugang['host'] === 'localhost') ? '127.0.0.1' : $zugang['host'];
    if (strpos($host, ':') !== false && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $v = array(
        'SAIC_USER' => $user,
        'SAIC_PASSWORD' => $pass,
        // --network host: 127.0.0.1 ist im Container der LoxBerry selbst.
        'MQTT_URI' => 'tcp://' . $host . ':' . (int) $zugang['port'],
        'MQTT_TOPIC' => $prefix,
        'HA_DISCOVERY_ENABLED' => 'False',
    );
    if ($zugang['user'] !== '') {
        $v['MQTT_USER'] = $zugang['user'];
        $v['MQTT_PASSWORD'] = $zugang['pass'];
    }
    $kap = array();
    $k = is_numeric($cfg['capacity']) ? (float) $cfg['capacity'] : 0;
    foreach ($cfg['vins'] as $vin) {
        if ($k > 0 && preg_match('/^[A-Za-z0-9]{6,32}\z/', (string) $vin)) {
            $kap[] = $vin . '=' . rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.');
        }
    }
    if ($kap) {
        $v['BATTERY_CAPACITY_MAPPING'] = implode(',', $kap);
    }
    $zeilen = '';
    foreach ($v as $name => $wert) {
        // Eine Zeile je Variable: ein Zeilenumbruch im Wert ergaebe eine zweite.
        if (preg_match('/[\r\n\x00]/', (string) $wert)) {
            return array(false, sprintf(mg_t('GW.WERT_ZEILENUMBRUCH'), $name));
        }
        $zeilen .= $name . '=' . $wert . "\n";
    }
    return array(true, $zeilen);
}

/** Geheimnisse in einem Text unkenntlich machen (Protokoll, Fehlerausgabe). */
function mg_gw_maskiert($text, $cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $geheim = array($cfg['saic_pass'], $cfg['broker_pass'], $cfg['aktionstoken']);
    $g = mg_general_mqtt();
    if ($g !== null) {
        $geheim[] = $g['pass'];
    }
    foreach ($geheim as $s) {
        if (is_scalar($s) && strlen((string) $s) >= 3) {
            $text = str_replace((string) $s, '********', (string) $text);
        }
    }
    return (string) $text;
}

/** Datei des Hintergrundvorgangs, und der Merker "Container einer frueheren Installation" (G8). */
function mg_gw_vorgang_datei()
{
    return mg_paths()['datadir'] . '/gateway_vorgang.json';
}

/**
 * Die Merkdatei "dieses Plugin hat einen Gateway-Container angelegt"
 * (Nacharbeit 30.09., N1): config/plugins/<ordner>.gateway_angelegt, NEBEN
 * dem Konfigordner wie die Zweitschrift - sie uebersteht den purge eines
 * Upgrades. Inhalt: Kurzkennung und Zeit, Rechte 0600. uninstall liest nur,
 * OB sie da ist: fehlt docker, warnt es dann (rc 1), sonst nur eine INFO.
 * Kein Schluessel in mg.json - Sicherungen bleiben kompatibel.
 */
function mg_gw_merkdatei()
{
    $p = mg_paths();
    return dirname($p['backup']) . '/' . $p['plugin'] . '.gateway_angelegt';
}

function mg_gw_frueher_datei()
{
    return mg_paths()['datadir'] . '.gateway_frueher';
}

function mg_gw_vorgang_schreiben(array $d)
{
    $p = mg_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    return mg_write_json(mg_gw_vorgang_datei(), $d, 0600);
}

/** Laeuft der Prozess $pid wirklich als bin/gateway_vorgang.php? (argumentweise) */
function mg_gw_vorgang_prozess($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_readable('/proc/' . $pid . '/cmdline')) {
        return false;
    }
    $a = explode("\0", (string) @file_get_contents('/proc/' . $pid . '/cmdline'));
    return isset($a[1]) && basename($a[1]) === 'gateway_vorgang.php'
        && preg_match('#(^|/)php[0-9.]*$#', (string) $a[0]);
}

/**
 * Der Stand des Hintergrundvorgangs. zustand: keiner | gestartet | laeuft |
 * fertig | fehler | abgebrochen. "abgebrochen": die Datei sagt "laeuft",
 * aber der Prozess ist fort - oder er ist nach 20 s nie angelaufen.
 */
function mg_gw_vorgang()
{
    $d = mg_json_lesen(mg_gw_vorgang_datei());
    if (!isset($d['zustand'])) {
        return array('zustand' => 'keiner');
    }
    $d += array('start' => 0, 'pid' => 0, 'meldung' => '', 'schritt' => '');
    if ($d['zustand'] === 'laeuft' && !mg_gw_vorgang_prozess($d['pid'])) {
        $d['zustand'] = 'abgebrochen';
    }
    if ($d['zustand'] === 'gestartet' && time() - (int) $d['start'] > 20) {
        $d['zustand'] = 'abgebrochen';
    }
    return $d;
}

/** Das Programm des Hintergrundvorgangs. */
function mg_gw_vorgang_programm()
{
    $p = mg_paths();
    if ($p['lbhome'] !== '') {
        return $p['lbhome'] . '/bin/plugins/' . $p['plugin'] . '/gateway_vorgang.php';
    }
    return dirname(dirname(__DIR__)) . '/bin/gateway_vorgang.php';
}

/**
 * Das Anlegen im Hintergrund starten. Kein Warten im Seitenaufbau: die Seite
 * zeigt danach "wird angelegt ... seit N s" und laedt sich neu, solange der
 * Vorgang laeuft. Gemeldet wird erst, wenn der Vorgang nachweislich
 * angelaufen ist (seine Prozessnummer steht in der Datei), nicht auf den
 * Rueckgabewert der Schale - die meldet nur, dass sie abgezweigt hat.
 * Rueckgabe array(ok, satz).
 */
function mg_gw_vorgang_starten($auftrag = 'anlegen')
{
    // A4 (seit 1.1.19): zweiter Auftrag "aktualisieren"; sonst nichts.
    if (!in_array($auftrag, array('anlegen', 'aktualisieren'), true)) {
        return array(false, mg_t('GW.VORGANG_START'));
    }
    $v = mg_gw_vorgang();
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) {
        return array(false, mg_t((isset($v['vorgang']) && $v['vorgang'] === 'aktualisieren')
            ? 'GW.VORGANG_LAEUFT_AKT' : 'GW.VORGANG_LAEUFT_SCHON'));
    }
    $prog = mg_gw_vorgang_programm();
    if (!is_file($prog) || !function_exists('proc_open')) {
        return array(false, sprintf(mg_t('GW.VORGANG_FEHLT'), $prog));
    }
    if (!mg_gw_vorgang_schreiben(array('vorgang' => $auftrag, 'zustand' => 'gestartet',
            'start' => time(), 'pid' => 0, 'schritt' => '', 'meldung' => ''))) {
        return array(false, mg_t('GW.VORGANG_DATEI'));
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
                  2 => array('file', '/dev/null', 'w'));
    $pipes = array();
    // setsid loest den Vorgang von Apache; "&" laesst die Schale sofort enden.
    $proc = @proc_open(array('sh', '-c', 'setsid "$0" "$@" </dev/null >/dev/null 2>&1 &',
        'php', $prog, $auftrag), $desk, $pipes);
    if (!is_resource($proc)) {
        return array(false, mg_t('GW.VORGANG_START'));
    }
    proc_close($proc);
    $satz = mg_t($auftrag === 'aktualisieren' ? 'GW.VORGANG_GESTARTET_AKT' : 'GW.VORGANG_GESTARTET');
    for ($i = 0; $i < 20; $i++) {
        $v = mg_gw_vorgang();
        if ($v['zustand'] !== 'gestartet') {
            return array(true, $satz);
        }
        usleep(100000);
    }
    return array(true, $satz);
}

/**
 * Den Container anlegen - vom Hintergrundvorgang gerufen.
 * Rueckgabe array(ok, satz, kurzkennung).
 */
function mg_gw_anlegen()
{
    $cfg = mg_config();
    list($lage, $satz) = mg_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $satz, '');
    }
    $zugang = mg_broker_zugang($cfg);
    list($ok, $env) = mg_gw_env($cfg, $zugang);
    if (!$ok) {
        return array(false, $env, '');
    }
    $name = mg_gw_name();
    $da = mg_gw_inspect($name);
    if ($da !== null) {
        if (!mg_gw_ist_eigen($da)) {
            // Namenskollision mit einem Container ohne das eigene Label: nichts anlegen.
            return array(false, sprintf(mg_t('GW.NAME_BELEGT'), $name), '');
        }
        // "Neu anlegen": nur der eigene Container - anhalten, entfernen, anlegen.
        mg_docker(array('stop', '-t', '20', (string) $da['Id']), 60);
        mg_docker(array('rm', (string) $da['Id']), 30);
        if (mg_gw_inspect((string) $da['Id']) !== null) {
            return array(false, mg_t('GW.ALT_BLEIBT'), substr((string) $da['Id'], 0, 12));
        }
    }
    $p = mg_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $envdatei = $p['datadir'] . '/gateway.env';
    $alt_umask = umask(077);
    $rc = 1;
    $err = '';
    try {
        if (!mg_write_atomic($envdatei, $env, 0600)) {
            return array(false, mg_t('GW.ENV_DATEI'), '');
        }
        list($rc, , $err) = mg_docker(array('run', '-d', '--name', $name,
            '--restart', 'unless-stopped', '--network', 'host',
            '--label', 'de.loxberry.plugin.folder=' . $p['plugin'],
            '--label', 'de.loxberry.plugin.name=mgismart',
            '--label', 'de.loxberry.plugin.def=' . MG_GW_DEF,
            '--label', 'de.loxberry.plugin.brokerhash=' . mg_gw_brokerhash($zugang),
            '--env-file', $envdatei, MG_GW_BILD), 900);
    } finally {
        // Die Umgebungsdatei traegt beide Kennwoerter - fort, auch im Fehlerfall.
        umask($alt_umask);
        if (is_file($envdatei)) {
            @unlink($envdatei);
        }
    }
    if ($rc === 124) {
        return array(false, mg_t('GW.RUN_ZEITABLAUF'), '');
    }
    if ($rc !== 0) {
        return array(false, sprintf(mg_t('GW.RUN_FEHLER'), $rc) . ' '
            . mg_kuerzen(mg_gw_maskiert(trim($err), $cfg), 300), '');
    }
    $neu = mg_gw_inspect($name);
    if ($neu === null || !mg_gw_ist_eigen($neu)) {
        return array(false, mg_t('GW.NICHT_DA'), '');
    }
    $k = mg_gw_kurz($neu);
    if (is_file(mg_gw_frueher_datei())) {
        @unlink(mg_gw_frueher_datei());
    }
    return array($k['laeuft'], sprintf(mg_t($k['laeuft'] ? 'GW.ANGELEGT' : 'GW.ANGELEGT_STEHT'),
        $k['id'], $k['status']), $k['id']);
}

/* ==================================================================
 * Das Gateway-Abbild aktualisieren (seit 1.1.19, Bauliste A4)
 *
 * Nur auf Knopfdruck, im Hintergrundvorgang (bin/gateway_vorgang.php
 * aktualisieren), nie im Seitenaufruf und nie von selbst. Angefasst wird nur
 * der EIGENE Container (beide Labels, mg_gw_eigene()); einer ohne das Label
 * des Plugins bleibt unberuehrt (Entscheidung 7/9).
 *   1. docker pull <abbild>, begrenzt durch MG_GW_PULL_SEK.
 *   2. Vorher = die Kennung (Digest), mit der der eigene Container laeuft;
 *      nachher = die Kennung des Abbilds nach dem Holen. Gleich -> "schon
 *      aktuell", nichts neu angelegt.
 *   3. Sonst mg_gw_anlegen(): den eigenen Container anhalten, entfernen und
 *      mit denselben Einstellungen (mg.json, Broker-Zugang) neu anlegen.
 *   4. Scheitert das, nennt die Meldung den Weg zurueck; die Zugangsdaten
 *      stehen unveraendert in mg.json.
 * ================================================================== */

/** Die Kennung eines Abbilds (docker image inspect), oder ''. */
function mg_gw_abbild_id($bild, $sekunden = 30)
{
    list($rc, $out, ) = mg_docker(array('image', 'inspect', '--format', '{{.Id}}', (string) $bild), $sekunden);
    $id = trim((string) $out);
    return ($rc === 0 && preg_match('/^(sha256:)?[0-9a-f]{12,64}\z/', $id)) ? $id : '';
}

/** Eine Kennung kurz fuer Menschen: sha256: und zwoelf Zeichen. */
function mg_gw_digest_kurz($id)
{
    $id = trim((string) $id);
    if (preg_match('/^(sha256:)?([0-9a-f]{12})[0-9a-f]*\z/', $id, $m)) {
        return 'sha256:' . $m[2];
    }
    return mg_kuerzen($id, 24);
}

/**
 * Das Abbild holen und den eigenen Container bei neuem Abbild neu anlegen.
 * Rueckgabe array(ok, satz, kurzkennung, art) - art: aktuell | neu | fehler.
 */
function mg_gw_aktualisieren()
{
    list($lage, $lagesatz) = mg_docker_lage();
    if ($lage !== 'ok') {
        return array(false, $lagesatz, '', 'fehler');
    }
    list($ok, $liste) = mg_gw_eigene();
    if (!$ok) {
        return array(false, mg_t('GW.A_DOCKER_STUMM'), '', 'fehler');
    }
    if (!$liste) {
        return array(false, mg_t('GW.AKT_KEIN_EIGENER'), '', 'fehler');
    }
    if (count($liste) > 1) {
        return array(false, sprintf(mg_t('GW.AKT_MEHRERE'), count($liste)), '', 'fehler');
    }
    $k = mg_gw_kurz($liste[0]);
    $name = isset($liste[0]['Name']) ? ltrim((string) $liste[0]['Name'], '/') : '';
    if ($name !== mg_gw_name()) {
        // mg_gw_anlegen() arbeitet ueber den Namen; ein eigener Container unter
        // anderem Namen bliebe sonst neben dem neuen stehen.
        return array(false, sprintf(mg_t('GW.AKT_ANDERER_NAME'), $name, mg_gw_name()), $k['id'], 'fehler');
    }
    $vorher = $k['abbild'];
    list($rc, , $err) = mg_docker(array('pull', MG_GW_BILD), MG_GW_PULL_SEK);
    if ($rc === 124) {
        return array(false, sprintf(mg_t('GW.AKT_PULL_ZEIT'), (int) MG_GW_PULL_SEK), $k['id'], 'fehler');
    }
    if ($rc !== 0) {
        return array(false, sprintf(mg_t('GW.AKT_PULL_FEHLER'), $rc) . ' '
            . mg_kuerzen(mg_gw_maskiert(trim($err)), 200), $k['id'], 'fehler');
    }
    $nachher = mg_gw_abbild_id(MG_GW_BILD);
    if ($nachher === '') {
        return array(false, mg_t('GW.AKT_KEINE_KENNUNG'), $k['id'], 'fehler');
    }
    if ($vorher !== '' && $vorher === $nachher) {
        return array(true, sprintf(mg_t('GW.AKT_AKTUELL'), mg_gw_digest_kurz($nachher)), $k['id'], 'aktuell');
    }
    list($a_ok, $a_satz, $a_id) = mg_gw_anlegen();
    if (!$a_ok) {
        return array(false, sprintf(mg_t('GW.AKT_NEU_FEHLER'), $a_satz), $a_id, 'fehler');
    }
    return array(true, sprintf(mg_t('GW.AKT_NEU'), mg_gw_digest_kurz($nachher),
        $vorher !== '' ? mg_gw_digest_kurz($vorher) : mg_t('WORT.UNBEKANNT'), $a_satz), $a_id, 'neu');
}

/** Den eigenen Container neu starten. Rueckgabe array(ok, satz). */
function mg_gw_neustart()
{
    list($ok, $liste) = mg_gw_eigene();
    if (!$ok) {
        return array(false, mg_docker_lage()[1]);
    }
    if (!$liste) {
        return array(false, mg_t('GW.KEIN_EIGENER'));
    }
    $saetze = array();
    $alle = true;
    foreach ($liste as $info) {
        $k = mg_gw_kurz($info);
        list($rc, , $err) = mg_docker(array('restart', '-t', '20', $k['id_voll']), 60);
        $nach = mg_gw_inspect($k['id_voll']);
        $laeuft = $nach !== null && mg_gw_kurz($nach)['laeuft'];
        $alle = $alle && $rc === 0 && $laeuft;
        $saetze[] = sprintf(mg_t($rc === 0 && $laeuft ? 'GW.NEU_GESTARTET' : 'GW.NEUSTART_FEHLER'),
            $k['id'], $rc === 124 ? mg_t('GW.ZEITABLAUF') : mg_kuerzen(trim($err), 120));
    }
    return array($alle, implode(' ', $saetze));
}

/** Den eigenen Container entfernen. Rueckgabe array(ok, satz). */
function mg_gw_entfernen()
{
    list($ok, $liste) = mg_gw_eigene();
    if (!$ok) {
        return array(false, mg_docker_lage()[1]);
    }
    if (!$liste) {
        return array(false, mg_t('GW.KEIN_EIGENER'));
    }
    $saetze = array();
    $alle = true;
    foreach ($liste as $info) {
        $k = mg_gw_kurz($info);
        list($rc1, , ) = mg_docker(array('stop', '-t', '20', $k['id_voll']), 60);
        list($rc2, , ) = mg_docker(array('rm', $k['id_voll']), 30);
        list($rc3, $out, ) = mg_docker(array('ps', '-a', '-q', '--no-trunc', '--filter',
            'id=' . $k['id_voll']), 20);
        $weg = ($rc3 === 0 && trim($out) === '');
        $alle = $alle && $weg;
        $saetze[] = sprintf(mg_t($weg ? 'GW.ENTFERNT' : 'GW.ENTFERNEN_FEHLER'), $k['id'],
            ($rc1 === 124 || $rc2 === 124) ? mg_t('GW.ZEITABLAUF') : '');
    }
    if ($alle && is_file(mg_gw_frueher_datei())) {
        @unlink(mg_gw_frueher_datei());
    }
    // Die Merkdatei (N1) erst nach BESTAETIGTEM Entfernen aller eigenen.
    if ($alle && is_file(mg_gw_merkdatei())) {
        @unlink(mg_gw_merkdatei());
    }
    return array($alle, implode(' ', $saetze));
}

/** Die letzten 50 Zeilen des eigenen Containers, maskiert, ohne Farbcodes. */
function mg_gw_protokoll()
{
    list($ok, $liste) = mg_gw_eigene();
    if (!$ok) {
        return array(false, mg_docker_lage()[1]);
    }
    if (!$liste) {
        return array(false, mg_t('GW.KEIN_EIGENER'));
    }
    $k = mg_gw_kurz($liste[0]);
    list($rc, $out, $err) = mg_docker(array('logs', '--tail', '50', $k['id_voll']), 20);
    if ($rc !== 0) {
        return array(false, sprintf(mg_t('GW.LOGS_FEHLER'), $rc));
    }
    // Das Gateway schreibt auf beide Kanaele; Farbcodes stoeren beim Lesen (Regeln/03).
    $text = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', trim($out . "\n" . $err));
    return array(true, mg_gw_maskiert($text));
}

/** Fahrzeug-Kennungen, die das Gateway meldet, aber noch nicht eingetragen sind. */
function mg_gw_gefundene_vins($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $user = trim((string) $cfg['saic_user']);
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    if ($user === '') {
        return array();
    }
    $muster = '#^' . preg_quote($prefix . '/' . $user . '/vehicles/', '#') . '([A-Za-z0-9]{6,32})/#';
    $gefunden = array();
    foreach (array_keys(mg_raw()['werte']) as $t) {
        if (preg_match($muster, (string) $t, $m) && !in_array($m[1], $cfg['vins'], true)) {
            $gefunden[$m[1]] = true;
        }
    }
    return array_keys($gefunden);
}

/**
 * Die Ampel des Reiters "Gateway einrichten" (G1) und des Reiters Test (G9).
 *
 * $messen = false liefert die zuletzt gemessene Ampel (Zwischenspeicher, mit
 * Messzeit) - kein docker-Aufruf im Seitenaufbau eines anderen Reiters.
 * $messen = true fragt docker (kurze Zeitgrenzen) und liest die
 * Momentaufnahme; $lesen = true liest vorher den Broker neu ("Zustand neu
 * messen"). Farben: gruen, gelb, rot, grau (grau = nicht feststellbar).
 * Protokollzeilen des Gateways zu Erfolg und Misserfolg der Anmeldung sind
 * NICHT belegt - auf sie baut hier nichts.
 */
function mg_gw_ampel($messen = false, $lesen = false)
{
    $p = mg_paths();
    $datei = $p['tmp'] . '/gw_ampel.json';
    if (!$messen) {
        $d = mg_json_lesen($datei);
        return isset($d['zeit']) ? $d : null;
    }
    $cfg = mg_config();
    if ($lesen) {
        mg_snapshot(3);
    }
    list($lage, $lagesatz) = mg_docker_lage(5);
    $a = array('zeit' => time(), 'docker' => $lage, 'docker_satz' => $lagesatz,
               'container' => array('grau', $lagesatz), 'anmeldung' => array('grau', ''),
               'werte' => array('grau', ''), 'laeuft' => 0, 'eigen' => 0, 'id' => '');
    $k = null;
    if ($lage === 'ok') {
        list($ok, $liste) = mg_gw_eigene(10);
        if (!$ok) {
            $a['container'] = array('grau', mg_t('GW.A_DOCKER_STUMM'));
        } elseif (!$liste) {
            $fremd = mg_gw_inspect(mg_gw_name(), 10);
            $a['container'] = ($fremd !== null)
                ? array('rot', sprintf(mg_t('GW.NAME_BELEGT'), mg_gw_name()))
                : array('rot', mg_t('GW.A_NICHT_ANGELEGT'));
        } else {
            $k = mg_gw_kurz($liste[0]);
            $a['eigen'] = 1;
            $a['id'] = $k['id'];
            $a['laeuft'] = $k['laeuft'] ? 1 : 0;
            if (!$k['laeuft']) {
                $a['container'] = array('rot', sprintf(mg_t('GW.A_ANGEHALTEN'), $k['status']));
            } elseif ($k['neustarts'] > 5) {
                $a['container'] = array('rot', sprintf(mg_t('GW.A_SCHLEIFE'), $k['neustarts']));
            } else {
                $a['container'] = array('gruen', sprintf(mg_t('GW.A_LAEUFT'),
                    mg_dauer_text($k['seit']), $k['neustarts']));
            }
            /* G4: weicht der Broker-Zugang oder die Beschreibung ab, zeigt die
             * Ampel gelb - geaendert wird nichts selbsttaetig. */
            if ($a['container'][0] !== 'rot' && ($k['def'] !== MG_GW_DEF
                    || $k['brokerhash'] !== mg_gw_brokerhash(mg_broker_zugang($cfg)))) {
                $a['container'] = array('gelb', mg_t('GW.A_VERALTET'));
            }
            // G8: ein Container, den eine fruehere Installation hinterlassen hat.
            $frueher = is_file(mg_gw_frueher_datei())
                ? trim((string) @file_get_contents(mg_gw_frueher_datei())) : '';
            if ($frueher !== '' && strpos($k['id_voll'], $frueher) === 0) {
                $a['container'] = array('gelb', mg_t('GW.A_FRUEHER'));
            }
        }
    }
    // Zeile 2: Anmeldung bei MG - am Broker abgelesen, nicht am Protokoll.
    $roh = mg_raw();
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $user = trim((string) $cfg['saic_user']);
    $fz = 0;
    if ($user !== '') {
        $muster = '#^' . preg_quote($prefix . '/' . $user . '/vehicles/', '#') . '([^/]+)/#';
        $vins = array();
        foreach (array_keys($roh['werte']) as $t) {
            if (preg_match($muster, (string) $t, $m)) { $vins[$m[1]] = true; }
        }
        $fz = count($vins);
    }
    $lwt = mg_bool_wert(mg_pick_abs($prefix . '/_internal/lwt'), -1);
    /* A1 (seit 1.1.19): eine eigene Stufe "Anmeldung bei MG gescheitert",
     * wenn account/lastLoginError juenger ist als account/lastLogin oder es
     * nur den Fehler gibt - mit Zeitpunkt und Text (mg_gw_anmeldung()). Der
     * Reiter zeigt dazu den Hinweis auf E-Mail und Kennwort. */
    $anm = mg_gw_anmeldung($cfg, $roh);
    $a['mg_anmeldung'] = $anm['stufe'];
    /* A4 (seit 1.1.19): die Fassung, die das Gateway selbst meldet, und
     * Abbild und Digest des laufenden eigenen Containers. */
    $gv = ($user !== '' && isset($roh['werte'][$prefix . '/' . $user . '/account/gatewayVersion']))
        ? $roh['werte'][$prefix . '/' . $user . '/account/gatewayVersion'] : '';
    $a['fassung'] = mg_zeit_da($gv) ? mg_kuerzen(mg_zeit_text($gv), 40) : '';
    $a['bild'] = ($k !== null) ? $k['bild'] : '';
    $a['digest'] = ($k !== null && $k['abbild'] !== '') ? mg_gw_digest_kurz($k['abbild']) : '';
    if ($user === '') {
        $a['anmeldung'] = array('grau', mg_t('GW.A_KEIN_USER'));
    } elseif ($anm['stufe'] === 0) {
        $a['anmeldung'] = array('rot', $anm['satz']);
    } elseif ($anm['stufe'] === -1) {
        /* Entscheidung des Hausherrn 30.09.2026: unbekannt ist grau, nie
         * gruen - auch wenn Fahrzeugthemen vorliegen (die sind retained und
         * sagen nichts ueber die letzte Anmeldung). */
        $a['anmeldung'] = array('grau', $anm['grund'] === 'fehlt'
            ? mg_t('GW.A_ANM_UNBEKANNT') : $anm['satz']);
    } elseif ($a['laeuft'] && $lwt === 0) {
        $a['anmeldung'] = array('rot', mg_t('GW.A_LWT_OFFLINE'));
    } elseif ($fz > 0) {
        $a['anmeldung'] = array('gruen', sprintf(mg_t('GW.A_ANGEMELDET'), $user, $fz));
    } elseif (!$a['laeuft']) {
        $a['anmeldung'] = array('grau', mg_t('GW.A_KEIN_CONTAINER'));
    } elseif ($k !== null && $k['seit'] >= 0 && $k['seit'] < 300) {
        $a['anmeldung'] = array('grau', sprintf(mg_t('GW.A_WARTEN'), (int) $k['seit']));
    } else {
        $a['anmeldung'] = array('grau', sprintf(mg_t('GW.A_NICHTS'), $prefix . '/' . $user . '/vehicles/'));
    }
    // Zeile 3: Werte kommen - Alter der Momentaufnahme, Klartext des Einlesens.
    $alter = $roh['zeit'] !== '' ? (int) round((time() - strtotime($roh['zeit'])) / 60) : -1;
    $ll = mg_lesen_lage();
    if ($alter >= 0 && $alter <= MG_OK_ALTER_MIN && (int) $roh['anzahl'] > 0) {
        $a['werte'] = array('gruen', sprintf(mg_t('GW.A_WERTE'), $alter, (int) $roh['anzahl']));
    } elseif ($ll['zeit'] > 0 && !$ll['ok'] && in_array($ll['rc'], array(4, 5, 14), true)) {
        $a['werte'] = array('rot', $ll['text']);
    } elseif ($alter < 0) {
        $a['werte'] = array('rot', mg_t('GW.A_NIE') . ($ll['text'] !== '' ? ' ' . $ll['text'] : ''));
    } else {
        $a['werte'] = array('rot', sprintf(mg_t('GW.A_ALT'), $alter)
            . ($ll['zeit'] > 0 && !$ll['ok'] && $ll['text'] !== '' ? ' ' . $ll['text'] : ''));
    }
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    mg_write_json($datei, $a);
    return $a;
}

/* ==================================================================
 * Anmeldung des Gateways bei MG (seit 1.1.19, Bauliste A1)
 *
 * Das SAIC-Gateway veroeffentlicht unter <prefix>/<benutzer>/account/ die
 * Themen lastLogin (letzte erfolgreiche Anmeldung bei MG) und lastLoginError
 * (letzter Anmeldefehler). Welche Form die Werte haben, ist am Geraet nicht
 * gemessen. Gedeutet werden deshalb Unix-Sekunden, Unix-Millisekunden, eine
 * ISO-Zeit am Anfang des Wertes (dahinter darf ein Text stehen) und ein
 * JSON-Objekt mit einem Zeitfeld. Was sich nicht deuten laesst, heisst
 * "unbekannt", nie "in Ordnung".
 *
 * Gelesen wird aus der Momentaufnahme (mg_raw(), Abo <prefix>/# in
 * mg_snapshot()) - derselbe Weg wie fuer jedes andere Gateway-Thema, keine
 * neue Verbindung. Die Zeile "Hat das Gateway jedes Fahrzeug zuletzt
 * erreicht?" (PRUEF.ERREICHBAR) bleibt davon getrennt.
 * ================================================================== */

/** Liegt in einem Gateway-Wert ueberhaupt etwas? (leer, None, null zaehlen nicht) */
function mg_zeit_da($v)
{
    if (!is_scalar($v)) {
        return false;
    }
    $v = strtolower(trim((string) $v));
    return !in_array($v, array('', 'none', 'null', 'nil', '-'), true);
}

/** Ein Zeitpunkt ist nur plausibel zwischen 01.01.2000 und morgen; sonst -1. */
function mg_zeit_plausibel($t)
{
    $t = (int) $t;
    return ($t >= 946684800 && $t <= time() + 86400) ? $t : -1;
}

/** Text eines Gateway-Werts: ohne Steuerzeichen, hoechstens 200 Zeichen. */
function mg_zeit_text($t)
{
    $t = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $t);
    return mg_kuerzen(trim(preg_replace('/\s+/', ' ', (string) $t)), 200);
}

/**
 * Zahl oder ISO-Zeit am Anfang einer Zeichenkette.
 * Rueckgabe array(zeit, rest): zeit Unix-Sekunden oder -1.
 */
function mg_zeit_einfach($v)
{
    $v = trim((string) $v);
    if (preg_match('/^[0-9]{9,13}(\.[0-9]+)?\z/', $v)) {
        $z = (float) $v;
        if ($z > 100000000000) {
            $z = $z / 1000;          // Millisekunden
        }
        return array(mg_zeit_plausibel(floor($z)), '');
    }
    $muster = '/^([0-9]{4}-[0-9]{2}-[0-9]{2})(?:[T ]([0-9]{2}:[0-9]{2}(?::[0-9]{2})?)'
            . '(?:[.,]([0-9]+))?\s?(Z|UTC|[+-][0-9]{2}:?[0-9]{2})?)?(.*)\z/s';
    if (preg_match($muster, $v, $m)) {
        $iso = $m[1] . (isset($m[2]) && $m[2] !== '' ? 'T' . $m[2] : '')
             . (isset($m[3]) && $m[3] !== '' ? '.' . substr($m[3], 0, 6) : '')
             . (isset($m[4]) && $m[4] !== '' ? ($m[4] === 'UTC' ? 'Z' : $m[4]) : '');
        $t = strtotime($iso);
        return array($t === false ? -1 : mg_zeit_plausibel($t),
                     ltrim((string) (isset($m[5]) ? $m[5] : ''), " \t:;,-|"));
    }
    return array(-1, $v);
}

/**
 * Einen Wert des Gateways als Zeitpunkt (und Text) deuten.
 * Rueckgabe array(zeit, text): zeit in Unix-Sekunden oder -1 (fehlt oder
 * nicht lesbar), text die Beschreibung ohne Steuerzeichen.
 */
function mg_zeit_deuten($v)
{
    if (!mg_zeit_da($v)) {
        return array(-1, '');
    }
    $v = trim((string) $v);
    if ($v[0] === '{') {
        $d = json_decode($v, true);
        if (is_array($d)) {
            $zeit = -1;
            $text = '';
            foreach (array('message', 'error', 'reason', 'detail', 'text') as $k) {
                if (isset($d[$k]) && is_scalar($d[$k]) && trim((string) $d[$k]) !== '') {
                    $text = (string) $d[$k];
                    break;
                }
            }
            foreach (array('time', 'timestamp', 'date', 'datetime', 'at') as $k) {
                if (isset($d[$k]) && is_scalar($d[$k]) && mg_zeit_da($d[$k])) {
                    list($zeit, ) = mg_zeit_einfach((string) $d[$k]);
                    break;
                }
            }
            return array($zeit, mg_zeit_text($text));
        }
    }
    list($zeit, $rest) = mg_zeit_einfach($v);
    return array($zeit, mg_zeit_text($rest));
}

/**
 * Hat sich das Gateway bei MG angemeldet? (A1)
 *
 * Rueckgabe array(
 *   'stufe'  => 1 in Ordnung | 0 gescheitert | -1 unbekannt,
 *   'grund'  => ok | juenger | nur_fehler | fehlt | unlesbar | gleich | kein_user,
 *   'login'  => Unix-Sekunden oder -1, 'fehler' => Unix-Sekunden oder -1,
 *   'text'   => Fehlertext des Gateways (maskiert), 'satz' => ganzer Satz).
 * Regeln: lastLogin juenger -> in Ordnung; lastLoginError juenger oder nur
 * der Fehler -> gescheitert; beides fehlt, gleiche Zeit oder ein Zeitpunkt
 * nicht lesbar -> unbekannt.
 */
function mg_gw_anmeldung($cfg = null, $roh = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    if (!is_array($roh) || !isset($roh['werte']) || !is_array($roh['werte'])) {
        $roh = mg_raw();
    }
    $aus = array('stufe' => -1, 'grund' => '', 'login' => -1, 'fehler' => -1,
                 'text' => '', 'satz' => '');
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $user = trim((string) $cfg['saic_user']);
    if ($user === '') {
        $aus['grund'] = 'kein_user';
        $aus['satz'] = mg_t('GW.A_KEIN_USER');
        return $aus;
    }
    $basis = $prefix . '/' . $user . '/account/';
    $l_roh = isset($roh['werte'][$basis . 'lastLogin']) ? $roh['werte'][$basis . 'lastLogin'] : null;
    $f_roh = isset($roh['werte'][$basis . 'lastLoginError'])
        ? $roh['werte'][$basis . 'lastLoginError'] : null;
    $l_da = mg_zeit_da($l_roh);
    $f_da = mg_zeit_da($f_roh);
    list($lz, ) = mg_zeit_deuten($l_roh);
    list($fz, $ft) = mg_zeit_deuten($f_roh);
    $aus['login'] = $lz;
    $aus['fehler'] = $fz;
    $aus['text'] = $ft !== '' ? mg_kuerzen(mg_gw_maskiert($ft, $cfg), 200) : '';
    if (!$l_da && !$f_da) {
        $aus['grund'] = 'fehlt';
    } elseif (!$l_da) {
        $aus['stufe'] = 0;
        $aus['grund'] = 'nur_fehler';
    } elseif ($lz < 0 || ($f_da && $fz < 0)) {
        $aus['grund'] = 'unlesbar';
    } elseif ($f_da && $fz > $lz) {
        $aus['stufe'] = 0;
        $aus['grund'] = 'juenger';
    } elseif ($f_da && $fz === $lz) {
        $aus['grund'] = 'gleich';
    } else {
        $aus['stufe'] = 1;
        $aus['grund'] = 'ok';
    }
    if ($aus['stufe'] === 1) {
        $aus['satz'] = sprintf(mg_t('GW.ANM_OK'), date('d.m.Y H:i:s', $lz));
    } elseif ($aus['stufe'] === 0) {
        $aus['satz'] = sprintf(mg_t('GW.ANM_GESCHEITERT'),
            $fz >= 0 ? date('d.m.Y H:i:s', $fz) : mg_t('GW.ANM_ZEIT_UNBEKANNT'),
            $aus['text'] !== '' ? $aus['text'] : mg_t('GW.ANM_OHNE_TEXT'));
    } elseif ($aus['grund'] === 'fehlt') {
        $aus['satz'] = mg_t('GW.ANM_FEHLT');
    } elseif ($aus['grund'] === 'gleich') {
        $aus['satz'] = mg_t('GW.ANM_GLEICH');
    } else {
        $aus['satz'] = mg_t('GW.ANM_UNLESBAR');
    }
    return $aus;
}

/** Das eigene MQTT-Thema der Anmeldung, oder '' ohne eigenes Praefix. */
function mg_mqtt_anmeldung_thema($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $praefix = trim((string) $cfg['mqtt_praefix'], '/ ');
    return ($praefix === '' || strpbrk($praefix, '#+') !== false) ? '' : $praefix . '/gateway/anmeldung';
}

/** 1 in Ordnung, 0 gescheitert, "-" unbekannt - nie leer (Entscheidung 5). */
function mg_mqtt_anmeldung_wert($stufe)
{
    return $stufe === 1 ? '1' : ($stufe === 0 ? '0' : '-');
}

/**
 * Die Sendezeile fuer <mqtt_praefix>/gateway/anmeldung, oder '' ohne Thema.
 *
 * FLUECHTIG (Entscheidung des Hausherrn vom 30.09.2026): die Anmeldung ist
 * eine Aussage des Gateways ueber sich selbst, also eine Dienstaussage, und
 * nach Regeln/07 (19.09.2026) nie retained - wie ok. Die Zeile baut
 * mg_mqtt_zeile(); ob -r gesetzt wird, entscheidet mg_mqtt_behalten() mit der
 * Positivliste, in der "anmeldung" nicht steht. Dieselbe Frage stellt die
 * Themenliste im Reiter MQTT - Anzeige und Senden koennen nicht auseinander
 * laufen.
 */
function mg_mqtt_anmeldung_zeile($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $thema = mg_mqtt_anmeldung_thema($cfg);
    if ($thema === '') {
        return '';
    }
    $a = mg_gw_anmeldung($cfg);
    return mg_mqtt_zeile($thema, mg_mqtt_anmeldung_wert($a['stufe']));
}

/**
 * <mqtt_praefix>/gateway/anmeldung senden - fluechtig, bei JEDEM Takt nach
 * einem gelungenen Einlesen (wie ok), ohne Aenderungsfilter. Ein retained
 * Altstand raeumt die Deinstallation ab (mg_mqtt_leeren()). Rueckgabe: true,
 * wenn mosquitto_pub mit 0 endete.
 */
function mg_mqtt_anmeldung_senden($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    if (empty($cfg['mqtt_ein']) || !mg_has_mosquitto()) {
        return false;
    }
    $zeile = mg_mqtt_anmeldung_zeile($cfg);
    if ($zeile === '') {
        return false;
    }
    $out = array();
    $rc = 0;
    @exec($zeile . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        mg_log_if_changed('mqtt_anmeldung', 'MQTT: ' . mg_mqtt_anmeldung_thema($cfg) . ' nicht gesendet: '
            . trim(implode(' ', array_slice($out, 0, 2))));
        return false;
    }
    return true;
}

/* ==================================================================
 * Der gemeinsame Befund (seit 1.1.19, Bauliste A2)
 *
 * EINE Funktion fuer Oberflaeche (Reiter Gateway einrichten), Reiter Test
 * und bin/healthcheck (Regeln/03, "Der Befund gehoert in EINE Funktion").
 * Status nach der Skala des LoxBerry-Healthchecks: 3 Fehler, 4 Warnung,
 * 5 in Ordnung, 6 Hinweis; -1 heisst "entfaellt" (etwa kein Container, den
 * das Plugin eingerichtet hat) und zaehlt nicht mit.
 *
 * Geurteilt wird ueber ZEIT, nie ueber einen einzelnen Abruf (Regeln/04,
 * MarstekVenus): massgeblich ist das Alter der letzten gelungenen
 * Momentaufnahme gegen das Dreifache des Takts (MG_OK_ALTER_MIN,
 * Entscheidung 4). Ein einzelner misslungener Lauf bleibt darunter.
 *
 * $live = true (nur bin/healthcheck): docker wird mit kurzer Zeitgrenze
 * gefragt, und ist die letzte Momentaufnahme zu alt, fragt eine einzelne
 * Anmeldung beim Broker nach dem Grund (mg_mqtt_rueckfrage(), 2 s Aufbau,
 * 1 s je Lesen). Sonst werden nur die zuletzt gemessenen Staende gelesen.
 * Geschrieben wird in beiden Faellen nichts.
 * ================================================================== */
function mg_befund($live = false)
{
    $cfg = mg_config();
    $b = array();
    $add = function ($bez, $status, $text) use (&$b) {
        $b[] = array('bez' => (string) $bez, 'status' => (int) $status, 'text' => (string) $text);
    };
    $grenze = MG_OK_ALTER_MIN * 60;
    $jetzt = time();
    $eingerichtet = trim((string) $cfg['saic_user']) !== '' && mg_fahrzeug_anzahl($cfg) > 0;
    $roh = mg_raw();
    $wz = $roh['zeit'] !== '' ? strtotime((string) $roh['zeit']) : false;
    $w_alter = ($wz !== false && $wz > 0) ? max(0, $jetzt - $wz) : -1;
    $ll = mg_lesen_lage();

    // 1. Laeuft der Takt? Cron-Eintrag und das Alter des letzten Einlesens.
    list($cr_ok, $cr_text) = mg_cron_probe();
    if ($cr_ok === 0) {
        $add('BEFUND.TAKT', 3, $cr_text);
    } elseif (!$eingerichtet) {
        $add('BEFUND.TAKT', 6, mg_t('BEFUND.NICHT_EINGERICHTET'));
    } elseif ($ll['zeit'] <= 0) {
        $add('BEFUND.TAKT', 4, mg_t('BEFUND.TAKT_NIE'));
    } elseif ($jetzt - $ll['zeit'] > $grenze) {
        $add('BEFUND.TAKT', 3, sprintf(mg_t('BEFUND.TAKT_ALT'), mg_dauer_text($jetzt - $ll['zeit'])));
    } else {
        $add('BEFUND.TAKT', 5, sprintf(mg_t('BEFUND.TAKT_OK'), mg_dauer_text(max(0, $jetzt - $ll['zeit']))));
    }

    // 2. Nimmt der Broker die Anmeldung an?
    if (!$eingerichtet) {
        $add('BEFUND.BROKER', 6, mg_t('BEFUND.NICHT_EINGERICHTET'));
    } elseif ($w_alter >= 0 && $w_alter <= $grenze) {
        $add('BEFUND.BROKER', 5, sprintf(mg_t('BEFUND.BROKER_OK'), mg_dauer_text($w_alter)));
    } elseif ($ll['zeit'] > 0 && !$ll['ok'] && in_array($ll['rc'], array(4, 5, 14), true)) {
        $add('BEFUND.BROKER', 3, $ll['text']);
    } elseif ($ll['zeit'] > 0 && $ll['rc'] === 27) {
        $add('BEFUND.BROKER', 5, $ll['text']);
    } elseif ($live) {
        $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
        $rf = mg_mqtt_rueckfrage(array($prefix . '/_internal/lwt'));
        if ($rf['lage'] === 'ok' || $rf['connack'] === 0) {
            $add('BEFUND.BROKER', 5, mg_t('BEFUND.BROKER_JETZT'));
        } elseif ($rf['connack'] > 0) {
            $add('BEFUND.BROKER', 3, mg_sub_klartext($rf['connack'] === 4 ? 4 : 5, '', $cfg));
        } elseif (strpos($rf['grund'], 'keine Verbindung') === 0) {
            $add('BEFUND.BROKER', 3, mg_sub_klartext(14, '', $cfg));
        } else {
            $add('BEFUND.BROKER', 4, $rf['grund']);
        }
    } else {
        $add('BEFUND.BROKER', 6, $ll['zeit'] > 0 && $ll['text'] !== '' ? $ll['text'] : mg_t('RC.NICHT_GEMESSEN'));
    }

    // 3. Laeuft der Gateway-Container - nur, wenn das Plugin ihn eingerichtet hat.
    $amp = mg_gw_ampel(false);
    $angelegt = is_file(mg_gw_merkdatei()) || ($amp !== null && !empty($amp['eigen']));
    if (!$angelegt) {
        $add('BEFUND.CONTAINER', -1, mg_t('BEFUND.CONTAINER_ENTFAELLT'));
    } elseif ($live) {
        list($lage, $lagesatz) = mg_docker_lage(5);
        if ($lage !== 'ok') {
            $add('BEFUND.CONTAINER', in_array($lage, array('fehlt', 'dienst_aus'), true) ? 3 : 4, $lagesatz);
        } else {
            list($d_ok, $liste) = mg_gw_eigene(5);
            if (!$d_ok) {
                $add('BEFUND.CONTAINER', 4, mg_t('GW.A_DOCKER_STUMM'));
            } elseif (!$liste) {
                $add('BEFUND.CONTAINER', 3, mg_t('BEFUND.CONTAINER_FEHLT'));
            } else {
                $k = mg_gw_kurz($liste[0]);
                if (!$k['laeuft']) {
                    $add('BEFUND.CONTAINER', 3, sprintf(mg_t('GW.A_ANGEHALTEN'), $k['status']));
                } elseif ($k['neustarts'] > 5) {
                    $add('BEFUND.CONTAINER', 3, sprintf(mg_t('GW.A_SCHLEIFE'), $k['neustarts']));
                } else {
                    $add('BEFUND.CONTAINER', 5, sprintf(mg_t('GW.A_LAEUFT'), mg_dauer_text($k['seit']), $k['neustarts']));
                }
            }
        }
    } elseif ($amp === null) {
        $add('BEFUND.CONTAINER', 6, mg_t('GW.A_UNGEMESSEN'));
    } else {
        $farbe = isset($amp['container'][0]) ? $amp['container'][0] : 'grau';
        $add('BEFUND.CONTAINER', $farbe === 'gruen' ? 5 : ($farbe === 'gelb' ? 4 : ($farbe === 'rot' ? 3 : 6)),
            (isset($amp['container'][1]) ? $amp['container'][1] : '') . ' '
            . sprintf(mg_t('GW.A_MESSZEIT'), date('d.m.Y H:i', (int) $amp['zeit'])));
    }

    // 4. Hat sich das Gateway bei MG angemeldet? (A1)
    $anm = mg_gw_anmeldung($cfg, $roh);
    $add('BEFUND.ANMELDUNG', $anm['stufe'] === 1 ? 5 : ($anm['stufe'] === 0 ? 3 : 6), $anm['satz']);

    // 5. Sind die letzten Werte frisch? (Entscheidung 4: 3x Takt)
    if (!$eingerichtet) {
        $add('BEFUND.WERTE', 6, mg_t('BEFUND.NICHT_EINGERICHTET'));
    } elseif ($w_alter < 0 || (int) $roh['anzahl'] <= 0) {
        $add('BEFUND.WERTE', 3, mg_t('GW.A_NIE'));
    } elseif ($w_alter > $grenze) {
        $add('BEFUND.WERTE', 3, sprintf(mg_t('GW.A_ALT'), (int) round($w_alter / 60)));
    } else {
        $add('BEFUND.WERTE', 5, sprintf(mg_t('GW.A_WERTE'), (int) round($w_alter / 60), (int) $roh['anzahl']));
    }

    /* 6. Ein Befehl, den das FAHRZEUG nicht angenommen hat, ist kein Fehler
     * des Plugins - hoechstens ein Hinweis. Rueckgabecode 4 ("remote control
     * instruction failed") heisst in der Regel: das Fahrzeug schlaeft. */
    foreach (mg_fahrzeuge($cfg) as $nr => $fz) {
        $fe = mg_txt('command/error', '', $nr);
        if ($fe === '') {
            continue;
        }
        if (preg_match('/remote control instruction failed|return code\D{0,3}4\b|\b(rc|code)\s*[=:]?\s*4\b/i', $fe)) {
            $add('BEFUND.FAHRZEUG', 6, sprintf(mg_t('BEFUND.SCHLAEFT'), $fz['name']));
        } else {
            $add('BEFUND.FAHRZEUG', 6, sprintf(mg_t('BEFUND.BEFEHLSFEHLER'), $fz['name'],
                mg_kuerzen(mg_zeit_text(mg_gw_maskiert($fe, $cfg)), 120)));
        }
    }
    return $b;
}

/**
 * Die Zusammenfassung: nie besser als der schlechteste Punkt. Reihenfolge
 * vom schlechtesten zum besten 3, 4, 6, 5; "entfaellt" (-1) zaehlt nicht.
 * Rueckgabe array(status, text).
 */
function mg_befund_gesamt(array $b)
{
    $rang = array(3 => 0, 4 => 1, 6 => 2, 5 => 3);
    $schlecht = 5;
    $teile = array();
    $gezaehlt = 0;
    foreach ($b as $e) {
        if (!isset($rang[$e['status']])) {
            continue;
        }
        $gezaehlt++;
        if ($rang[$e['status']] < $rang[$schlecht]) {
            $schlecht = $e['status'];
        }
        if ($e['status'] !== 5) {
            // "Anmeldung bei MG: Anmeldung bei MG gescheitert" nicht doppelt
            $name = mg_t($e['bez']);
            $teile[] = (strpos($e['text'], $name) === 0) ? $e['text'] : $name . ': ' . $e['text'];
        }
    }
    if ($gezaehlt === 0) {
        return array(6, mg_t('BEFUND.LEER'));
    }
    if (!$teile) {
        return array(5, sprintf(mg_t('BEFUND.ALLES_OK'), $gezaehlt));
    }
    return array($schlecht, implode(' | ', $teile));
}

/** Eine Dauer in Sekunden fuer Menschen (Sekunde, Minute, Stunde, Tag). */
function mg_dauer_text($s)
{
    $s = (int) $s;
    if ($s < 0) {
        return mg_t('WORT.UNBEKANNT');
    }
    if ($s < 120) {
        return sprintf(mg_t('DAUER.S'), $s);
    }
    if ($s < 7200) {
        return sprintf(mg_t('DAUER.M'), (int) round($s / 60));
    }
    if ($s < 172800) {
        return sprintf(mg_t('DAUER.H'), (int) round($s / 3600));
    }
    return sprintf(mg_t('DAUER.T'), (int) round($s / 86400));
}

/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken: was nicht gemessen werden konnte, sagt das.
 * ================================================================== */

/**
 * $live = true (nur wenn der Reiter Test serverseitig der offene ist, Regeln/04):
 * die Zeilen, die das Netz oder docker fragen, messen jetzt - die Anmeldung
 * beim Broker, der eigene Endpunkt, die Ampel. Sonst nennen sie den letzten
 * gemessenen Stand oder einen Strich mit Grund.
 */
function mg_selbsttest($live = false)
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

    /* Nimmt der Broker die Anmeldung des Plugins an? (seit 1.1.18, Befund
     * U10/C2). Bis 1.1.17 zeigte der Reiter bei abgewiesener Anmeldung und
     * bei fehlendem Container dieselben Kreuze, und die Meldung sagte nur
     * "rc=5". Gemessen wird mit denselben Zugangsdaten wie mosquitto_sub
     * (mg_broker_zugang()); ohne offenen Reiter Test gilt das letzte
     * Einlesen des Takts. */
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $angenommen = -1;       // -1 unbekannt, 0 abgewiesen, 1 angenommen
    $lwt_live = null;
    if ($live) {
        $rf = mg_mqtt_rueckfrage(array($prefix . '/_internal/lwt'));
        if ($rf['lage'] === 'ok' || $rf['connack'] === 0) {
            $angenommen = 1;
            $zug = mg_broker_zugang($cfg);
            $add('PRUEF.ANMELDUNG', 1, sprintf(mg_t('RC.ANGENOMMEN'), mg_t($zug['quelle'] === 'eigen'
                ? 'RC.QUELLE_EIGEN' : ($zug['quelle'] === 'loxberry' ? 'RC.QUELLE_LOXBERRY_KURZ'
                                                                       : 'RC.QUELLE_KEINE'))));
            if ($rf['lage'] === 'ok') {
                $t = $prefix . '/_internal/lwt';
                $lwt_live = isset($rf['behalten'][$t]) ? mg_bool_wert($rf['behalten'][$t], -1) : -1;
            }
        } elseif ($rf['connack'] > 0) {
            $angenommen = 0;
            $add('PRUEF.ANMELDUNG', 0, mg_sub_klartext($rf['connack'] === 4 ? 4 : 5, '', $cfg));
        } elseif (strpos($rf['grund'], 'keine Verbindung') === 0) {
            $angenommen = 0;
            $add('PRUEF.ANMELDUNG', 0, mg_sub_klartext(14, '', $cfg));
        } else {
            $add('PRUEF.ANMELDUNG', 2, $rf['grund']);
        }
    } else {
        $ll = mg_lesen_lage();
        if ($ll['zeit'] <= 0) {
            $add('PRUEF.ANMELDUNG', 2, mg_t('RC.NICHT_GEMESSEN'));
        } elseif (in_array($ll['rc'], array(4, 5, 14), true) && !$ll['ok']) {
            $angenommen = 0;
            $add('PRUEF.ANMELDUNG', 0, $ll['text'] . ' ' . sprintf(mg_t('RC.STAND'), date('H:i', $ll['zeit'])));
        } elseif ($ll['ok'] || $ll['rc'] === 27) {
            $angenommen = 1;
            $add('PRUEF.ANMELDUNG', 1, sprintf(mg_t('RC.STAND'), date('H:i', $ll['zeit'])));
        } else {
            $add('PRUEF.ANMELDUNG', 2, $ll['text']);
        }
    }

    $roh = mg_raw();
    $add('PRUEF.THEMEN', $roh['anzahl'] > 0 ? 1 : 0, (string) (int) $roh['anzahl']);
    $alter = $roh['zeit'] !== '' ? (int) round((time() - strtotime($roh['zeit'])) / 60) : -1;
    // Dieselbe Grenze wie OK im Endpunkt (Entscheidung 4): bis 1.1.17 5 min.
    $add('PRUEF.MOMENTAUFNAHME', ($alter >= 0 && $alter <= MG_OK_ALTER_MIN) ? 1 : ($alter < 0 ? 0 : 2),
        $alter >= 0 ? ($alter . ' min') : '');

    // Trifft der eingetragene Basispfad wirklich etwas?
    $treffer = 0;
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        if (mg_themen_anzahl($nr) > 0) { $treffer++; }
    }
    $add('PRUEF.BASISPFAD', ($anzahl > 0 && $treffer === $anzahl) ? 1 : ($treffer > 0 ? 2 : 0),
        $treffer . '/' . $anzahl);

    $lwt = ($lwt_live !== null) ? $lwt_live : mg_bool_wert(mg_pick_abs($prefix . '/_internal/lwt'), -1);
    /* Kein Letzter Wille, aber der Broker nimmt die Anmeldung an: dann
     * meldet sich schlicht kein Gateway - das ist ein Befund, kein Strich
     * (seit 1.1.18, Befund U10; bis 1.1.17 stand hier in dieser Lage ein
     * Strich, genau wie bei abgewiesener Anmeldung). */
    if ($lwt === -1 && $angenommen === 1) {
        $add('PRUEF.GATEWAY', 0, mg_t('RC.KEIN_GATEWAY'));
    } else {
        $add('PRUEF.GATEWAY', $lwt === 1 ? 1 : ($lwt === 0 ? 0 : 2),
            $lwt === 1 ? 'online' : ($lwt === 0 ? 'offline' : ''));
    }

    $erreicht = 0;
    foreach (mg_fahrzeuge($cfg) as $nr => $f) {
        if (mg_bool('available', -1, $nr) === 1) { $erreicht++; }
    }
    $add('PRUEF.ERREICHBAR', ($anzahl > 0 && $erreicht === $anzahl) ? 1 : ($erreicht > 0 ? 2 : 0),
        $erreicht . '/' . $anzahl);

    $auto = mg_mqtt_gateway_autostart();
    $add('PRUEF.AUTOSTART', $auto === true ? 1 : ($auto === false ? 0 : 2), '');
    $add('PRUEF.MERKWORT', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0, '');
    /* War die Konfiguration heil, als sie zum ERSTEN Mal gelesen wurde?
     *
     * Ein geheilter Schaden ist kein Nicht-Schaden: die Zweitschrift kann
     * aelter sein als das, was verlorenging, und die Ursache (volles
     * Dateisystem, Stromausfall beim Schreiben) besteht fort. Ohne diese Zeile
     * erfaehrt der Bediener nie, dass etwas war - nur das Protokoll wuesste
     * es. mg_config_lage() haelt deshalb den ZUERST gesehenen Zustand fest;
     * das mg_config() eine Zeile weiter oben wuerde sonst "ok" melden. */
    $mg_lage = mg_config_lage();
    $add('PRUEF.KONFIG',
        ($mg_lage === 'ok' || $mg_lage === '') ? 1 : ($mg_lage === 'leer' ? 2 : 0),
        $mg_lage);

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
    /* Geht nur zurueckbehalten hinaus, was in der Positivliste steht? Und
     * steht dort kein Thema, das nach Regeln/07 nie retained sein darf (der
     * Dienst ueber sich selbst, Alter/Dauer, Messwerte mit Zeitbezug,
     * Tageswerte, die Fenster der Meldungen)? Jeder Name der Liste muss
     * ausserdem wirklich gesendet werden - sonst ist sie veraltet. */
    $re_liste = mg_mqtt_retain_liste();
    $re_namen = array();
    foreach (array_keys(mg_mqtt_themen()) as $re_t) {
        $re_namen[substr($re_t, strlen('<n>/'))] = true;
    }
    $re_nie = array('ok', 'themen', 'erreichbar', 'gateway', 'fehler', 'alter', 'fahrzeugalter', 'restzeit', 'leistung', 'tempo',
                    'innentemperatur', 'aussentemperatur', 'ac_leistung', 'ac_strom',
                    'ac_spannung', 'km_tag', 'verbrauch_tag', 'push_aktiv', 'push_test',
                    'batterie12v', 'reifen_vl', 'reifen_vr', 'reifen_hl', 'reifen_hr',
                    'anmeldung');   // seit 1.1.19: gateway/anmeldung, Dienstaussage
    $re_fehler = array();
    if (mg_mqtt_behalten('x/1/mg_unbekannt', '1')) { $re_fehler[] = 'mg_unbekannt'; }
    foreach ($re_nie as $re_n) {
        if (mg_mqtt_behalten('x/1/' . $re_n, '1')) { $re_fehler[] = $re_n; }
    }
    foreach (array_keys($re_liste) as $re_n) {
        if (!isset($re_namen[$re_n])) { $re_fehler[] = $re_n . '?'; }
    }
    $add('PRUEF.RETAIN', ($re_liste && !$re_fehler) ? 1 : 0,
        $re_fehler ? implode(', ', $re_fehler)
                   : (count($re_liste) . '/' . count($re_namen)));

    if (!empty($cfg['abfahrt_ein'])) {
        $ab = mg_abfahrt_lesen();
        $add('PRUEF.ABFAHRT', $ab['lage'] === 'ok' ? 1 : ($ab['lage'] === 'unbekannt' ? 2 : 0),
            $ab['lage'] === 'ok'
                ? ('OK=' . $ab['ok'] . ', ABFAHRT_IN=' . $ab['in'] . ', ' . $ab['alter'] . ' s')
                : $ab['grund']);
    }
    if (!empty($cfg['ladeempf_ein'])) {
        $h = mg_horcher_zustand();
        $gefunden = 0;
        foreach ($h['themen'] as $t) {
            if (isset($h['werte'][$t])) { $gefunden++; }
        }
        $add('PRUEF.HORCHER',
            ($h['themen'] && $gefunden === count($h['themen'])) ? 1 : ($gefunden ? 2 : 0),
            $gefunden . '/' . count($h['themen']));
    }

    /* Die Pflichtzeilen aus Regeln/04 (seit 1.1.18, Befund U13): der eigene
     * Endpunkt wird wirklich aufgerufen (nur im offenen Reiter Test), und der
     * Cron-Eintrag wird an ALLEN Cron-Orten gesucht. */
    if ($live) {
        list($ep_ok, $ep_text) = mg_endpunkt_probe($cfg);
        $add('PRUEF.ENDPUNKT', $ep_ok, $ep_text);
    } else {
        $add('PRUEF.ENDPUNKT', 2, mg_t('PRUEF.NUR_LIVE'));
    }
    list($cr_ok, $cr_text) = mg_cron_probe();
    $add('PRUEF.CRON', $cr_ok, $cr_text);

    /* Docker und die drei Zeilen der Ampel (G9), je mit ganzem Satz. Gelb
     * ("veraltet", "fruehere Installation") verlangt eine Handlung und steht
     * deshalb als Kreuz; grau ist "nicht feststellbar". Gemessen hat der
     * Aufrufer (index.php misst im offenen Reiter Test oder Gateway einmal);
     * hier wird nur gelesen, damit docker nicht zweimal gefragt wird. */
    $amp = mg_gw_ampel(false);
    if ($amp === null) {
        foreach (array('PRUEF.DOCKER', 'PRUEF.GW_CONTAINER', 'PRUEF.GW_ANMELDUNG', 'PRUEF.GW_WERTE') as $b) {
            $add($b, 2, mg_t('GW.A_UNGEMESSEN'));
        }
    } else {
        $bei = ' ' . sprintf(mg_t('GW.A_MESSZEIT'), date('d.m.Y H:i', (int) $amp['zeit']));
        $add('PRUEF.DOCKER', $amp['docker'] === 'ok' ? 1 : ($amp['docker'] === 'fehlt' ? 2 : 0),
            $amp['docker_satz'] . $bei);
        foreach (array('container' => 'PRUEF.GW_CONTAINER', 'anmeldung' => 'PRUEF.GW_ANMELDUNG',
                       'werte' => 'PRUEF.GW_WERTE') as $zk => $b) {
            $farbe = $amp[$zk][0];
            $add($b, $farbe === 'gruen' ? 1 : ($farbe === 'grau' ? 2 : 0), $amp[$zk][1] . $bei);
        }
    }
    /* A1 (seit 1.1.19): dieselbe Aussage wie die Ampel, aus derselben
     * Funktion - unabhaengig davon, ob die Ampel schon gemessen ist. */
    $anm = mg_gw_anmeldung($cfg);
    $add('PRUEF.MG_ANMELDUNG', $anm['stufe'] === 1 ? 1 : ($anm['stufe'] === 0 ? 0 : 2), $anm['satz']);
    return $z;
}

/**
 * Antwortet der eigene Endpunkt? Ein echter Aufruf auf 127.0.0.1 (Regeln/04):
 * nur er findet getrennte Baeume, in denen mg.php mit HTTP 500 antwortet.
 * Drei Ausgaenge: 1 HTTP 200 mit der erwarteten Kennung, 0 eine andere
 * Antwort (mit Code und Rumpfanfang), 2 gar keine Antwort - ein Webserver,
 * der nur eine Anfrage zugleich bedient, kann sich nicht selbst aufrufen.
 */
function mg_endpunkt_probe($cfg = null)
{
    if ($cfg === null) {
        $cfg = mg_config();
    }
    $token = is_string($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    if ($token === '' || mg_paths()['lbhome'] === '') {
        return array(2, mg_t('PRUEF.ENDPUNKT_KEIN'));
    }
    $port = mg_webport();
    $url = 'http://127.0.0.1' . ($port === 80 ? '' : ':' . $port)
         . mg_endpunkt(false, 'selftest=1&token=' . rawurlencode($token));
    list($roh, $status) = mg_http_holen($url, 3);
    if ($roh === false && $status === '') {
        return array(2, mg_t('PRUEF.ENDPUNKT_STUMM'));
    }
    if (preg_match('#^HTTP/\S+\s+200\b#', $status) && strpos((string) $roh, 'SELFTEST;OK=1;TOKEN=OK') !== false) {
        return array(1, 'HTTP 200, SELFTEST;OK=1;TOKEN=OK');
    }
    return array(0, trim($status) . ' - ' . mg_kuerzen(trim(preg_replace('/\s+/', ' ', (string) $roh)), 80));
}

/**
 * Steht der Cron-Eintrag? Gesucht an ALLEN Cron-Orten (glob ueber
 * system/cron/cron.*), Bauart Raumklima 0.11.8: ein fester Pfad zeigte dort
 * am Geraet einen Strich. Ein Verzeichnis an der Stelle ist ein Befund - es
 * laeuft nie (Regeln/06).
 */
function mg_cron_probe()
{
    $p = mg_paths();
    if ($p['lbhome'] === '') {
        return array(2, mg_t('PRUEF.CRON_KEINE_WURZEL'));
    }
    $dateien = array();
    $verz = array();
    foreach (glob($p['lbhome'] . '/system/cron/cron.*/' . $p['plugin']) ?: array() as $f) {
        if (is_dir($f)) {
            $verz[] = $f;
        } elseif (is_file($f)) {
            $dateien[] = $f;
        }
    }
    if ($verz) {
        return array(0, sprintf(mg_t('PRUEF.CRON_VERZEICHNIS'), implode(', ', $verz)));
    }
    if (!$dateien) {
        return array(0, sprintf(mg_t('PRUEF.CRON_FEHLT'), 'system/cron/cron.*/' . $p['plugin']));
    }
    return array(1, implode(', ', $dateien));
}

/**
 * Reiterleiste, Bereiche und Positivliste der index.php gegeneinander zaehlen.
 *
 * Alle drei Stellen stehen in der index.php ausgeschrieben - das muessen sie,
 * weil hausstandard_pruefen.py sie als Literal sucht und eine erzeugte Leiste
 * als "trifft nicht zu" meldet. Ausgeschrieben koennen sie aber auseinander
 * laufen; genau dafuer gibt es diese Zeile.
 */
/**
 * Die Admin-Oberflaeche finden - auch im INSTALLIERTEN Aufbau.
 *
 * Im entpackten Archiv liegen html/ und htmlauth/ nebeneinander,
 * installiert aber in getrennten Baeumen mit einer plugins-Ebene
 * dazwischen:
 *     <home>/webfrontend/html/plugins/<ordner>/mg_lib.php
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/index.php
 * dirname(__DIR__) traf deshalb NUR im Archiv. Die drei
 * Selbstpruefungen darunter lieferten am Geraet dauerhaft den Strich
 * "nicht feststellbar", waehrend die Pruefkette im Archiv gruene Haken
 * sah - der Fehler war also gerade dort unsichtbar, wo gemessen wurde.
 *
 * Rueckgabe: der Pfad, oder '' wenn die Datei nirgends liegt.
 */
function mg_htmlauth_index()
{
    $p = mg_paths();
    $kandidaten = array(dirname(__DIR__) . '/htmlauth/index.php');
    if (!empty($p['lbhome'])) {
        $wurzel = $p['lbhome'] . '/webfrontend/htmlauth/plugins/';
        $kandidaten[] = $wurzel . basename(__DIR__) . '/index.php';
        $ordner = getenv('LBPPLUGINDIR');
        if ($ordner) {
            $kandidaten[] = $wurzel . $ordner . '/index.php';
        }
        /* Kein fester Ordnername als letzter Kandidat: bis 1.1.14 las die
         * Selbstpruefung sonst die Oberflaeche des FREMDEN gleichnamigen
         * Plugins, sobald die eigene fehlte (gemessen 18.09.2026 in WSL,
         * Pruefung-MGiSmart-1.1.14, Fall P5). "nicht feststellbar" ist dann
         * die richtige Antwort. */
    }
    foreach ($kandidaten as $k) {
        if (is_file($k)) {
            return $k;
        }
    }
    return '';
}

function mg_reiter_stimmig()
{
    $datei = mg_htmlauth_index();
    if ($datei === '') {
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
    $datei = mg_htmlauth_index();
    if ($datei === '') {
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
    $datei = mg_htmlauth_index();
    if ($datei === '') {
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
        /* Welche Sprachdateien gelten, entscheiden mg_paths() und der eigene
         * Ablageort - nie ein Pfad ab der Laufwerkswurzel. Bis 1.1.16 wurde
         * ohne Wurzel '' . '/templates/plugins/html/lang' gefragt, also ab /,
         * und zwar VOR den eigenen Dateien (in WSL im eigenen Wurzelbaum
         * gemessen, Pruefung-MGiSmart-1.1.17, Fall P3).
         *   1. Anlage: <Wurzel>/templates/plugins/<ordner>/lang
         *   2. ausgepacktes Archiv (diese Datei liegt nicht unter
         *      .../plugins/<ordner>): dessen eigenes templates/lang */
        $p = mg_paths();
        $pfad = '';
        if ($p['lbhome'] !== ''
            && is_dir($p['lbhome'] . '/templates/plugins/' . $p['plugin'] . '/lang')) {
            $pfad = $p['lbhome'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        } elseif (basename(dirname(__DIR__)) !== 'plugins') {
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = $pfad === '' ? false
            : @parse_ini_file($pfad . '/language_' . mg_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = $pfad === '' ? false
            : @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
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
        /* Der Comment wird in Loxone Config zum Kachelnamen (Regeln/07) und
         * soll 40 Zeichen nicht ueberschreiten. Die Feldbeschreibung hat zwei
         * Abnehmer: die Spalte "Bedeutung" braucht den ganzen Satz, die
         * Vorlage einen kurzen Namen. Seit 1.1.18 (Befund U16, 10 von 56
         * Befehlen ueber 40 Zeichen) steht der kurze in [KACHEL]; fehlt einer,
         * gilt der lange. Der TITEL bleibt, sonst legte ein erneuter Import
         * neue Bausteine an. */
        $kachel = mg_t('KACHEL.' . $feld);
        $name = ($kachel !== 'KACHEL.' . $feld) ? $kachel : mg_t($info['bez']);
        $cmds[] = array(
            'title' => $praefix . $feld,
            'comment' => $name . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
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

/* ==================================================================
 * Werte pruefen - EINE Regel je Einstellung (seit 1.1.18)
 *
 * Dieselbe Pruefung fuer die Formulare (Befund U8) und fuer das
 * Zurueckspielen einer Sicherung (Bauart E, Befund C5/U2). Bis 1.1.17
 * wurden Formularwerte still verbogen ("abc" wurde zur Kapazitaet 1, der
 * Port 70000 zu 65535, "saic test" zu "saictest"), und eine Sicherung wurde
 * nur an ihren Schluesseln geprueft, nicht an den Werten: ein Aktionstoken
 * als Liste landete in mg.json, danach nahm der Endpunkt "token=Array" an;
 * eine Liste von Listen unter "vins" legte das Plugin unter PHP 8 still.
 *
 * Arten:
 *   ganz min max     ganze Zahl im Bereich
 *   zahl min max     Dezimalzahl im Bereich (im Formular auch mit Komma)
 *   flag             0 oder 1 (nur Datei; im Formular ist es ein Haken)
 *   muster re grund  Zeichenkette, die auf das Muster passt
 *   frei max         Zeichenkette ohne Steuerzeichen, hoechstens max Byte
 *   koord max        leer oder Dezimalzahl bis +-max
 *   uhrzeit          HH:MM (auch HH:MM:SS, gespeichert als HH:MM)
 *   modus            eine Betriebsart des Ladeplans
 *   befehl           ein Befehlsname, den mg_befehl_aufloesen() ohne Zusatz kennt
 *   vins, namen, notify, token   eigene Pruefung
 *
 * Die Grenzen sind die des Formulars (min/max der Eingabefelder und bis
 * 1.1.17 die Klemmen im Speicher-Handler). ladungen_max hat kein Feld; 10 bis
 * 10000 ist eine eigene Wahl (mg_ladung_pruefen() nimmt mindestens 10).
 * ================================================================== */
function mg_wert_regeln()
{
    $praefix = array('muster', '/^(?=.*[A-Za-z0-9_\-])[A-Za-z0-9_\-\/]{1,64}\z/', 'WERT.PRAEFIX');
    return array(
        'broker_host' => array('muster', '/^[A-Za-z0-9.\-:\[\]]{1,253}\z/', 'WERT.HOST'),
        'broker_port' => array('ganz', 1, 65535),
        'broker_user' => array('frei', 256),
        'broker_pass' => array('frei', 256),
        'prefix' => $praefix,
        'saic_user' => array('frei', 256),
        'saic_pass' => array('frei', 256),
        'vins' => array('vins'),
        'vin' => array('muster', '/^([A-Za-z0-9]{6,32})?\z/', 'WERT.VIN'),
        'capacity' => array('zahl', 1, 200),
        'namen' => array('namen'),
        'commands' => array('flag'),
        'gefahr_ein' => array('flag'),
        'befehl_abstand' => array('ganz', 0, 3600),
        'strom_abstand' => array('ganz', 0, 3600),
        'befehle_stunde' => array('ganz', 1, 500),
        'wirkung_pruefen' => array('flag'),
        'wartezeit' => array('ganz', 2, 20),
        'ort_ein' => array('flag'),
        'heim_breite' => array('koord', 90),
        'heim_laenge' => array('koord', 180),
        'heim_radius' => array('ganz', 20, 20000),
        'notify' => array('notify'),
        'notify.push_minutes' => array('ganz', 1, 60),
        'mqtt_ein' => array('flag'),
        'mqtt_praefix' => $praefix,
        'abfahrt_ein' => array('flag'),
        'abfahrt_praefix' => $praefix,
        'abfahrt_vorlauf' => array('ganz', 1, 180),
        'abfahrt_temp' => array('ganz', 16, 30),
        'abfahrt_fahrzeug' => array('ganz', 1, 9),
        'ladeempf_ein' => array('flag'),
        'ladeempf_thema' => array('muster', '/^[^\s"\'\x00-\x1F\x7F]{0,200}\z/', 'WERT.THEMA'),
        'ladeempf_grenze' => array('zahl', -1000000000, 1000000000),
        'ladeempf_unter' => array('flag'),
        'ladeempf_hoch' => array('befehl'),
        'ladeempf_runter' => array('befehl'),
        'ladeempf_fahrzeug' => array('ganz', 1, 9),
        'plan_ein' => array('flag'),
        'plan_von' => array('uhrzeit'),
        'plan_bis' => array('uhrzeit'),
        'plan_modus' => array('modus'),
        'heizplan_von' => array('uhrzeit'),
        'ladungen_ein' => array('flag'),
        'ladungen_max' => array('ganz', 10, 10000),
        'aktionstoken' => array('token'),
    );
}

/** Eine Zahl so schreiben, wie sie in der Meldung stehen soll. */
function mg_wert_zahltext($z)
{
    return rtrim(rtrim(number_format((float) $z, 6, ',', ''), '0'), ',');
}

/**
 * Einen Wert pruefen.
 *
 * $formular = true: der Wert kommt als Text aus einem Formular (schon am Rand
 * beschnitten - das Formular darf trimmen, wenn es den beschnittenen Wert
 * speichert). false: der Wert kommt aus einer Datei; dann muss auch der TYP
 * zu dem der Vorgabe passen, und ein Rand wird nicht beschnitten, sondern
 * abgewiesen (Regeln/05, "geprueft wird der Wert, der gespeichert wird").
 *
 * Rueckgabe array(ok, wert, grund) - wert in der Form, die gespeichert wird;
 * grund ein Satzteil fuer die Meldung.
 */
function mg_wert_pruefen($k, $w, $formular = false)
{
    $regeln = mg_wert_regeln();
    $vorg = mg_vorgaben();
    if (!isset($regeln[$k])) {
        return array(false, null, mg_t('WERT.UNBEKANNT'));
    }
    $r = $regeln[$k];
    $art = $r[0];
    $d = array_key_exists($k, $vorg) ? $vorg[$k] : 0;
    /* Der Typ. Aus einem Formular kommt immer Text; aus einer Datei muss er
     * zur Vorgabe passen: Zahl zu Zahl, Text zu Text, Liste zu Liste. */
    if ($formular) {
        if (!is_string($w)) {
            return array(false, null, mg_t('WERT.TYP_TEXT'));
        }
    } elseif (is_array($d)) {
        if (!is_array($w)) {
            return array(false, null, mg_t('WERT.TYP_LISTE'));
        }
    } elseif (is_int($d) || is_float($d)) {
        if (!is_int($w) && !is_float($w)) {
            return array(false, null, mg_t('WERT.TYP_ZAHL'));
        }
    } elseif (!is_string($w)) {
        return array(false, null, mg_t('WERT.TYP_TEXT'));
    }

    if ($art === 'ganz' || $art === 'zahl') {
        $s = is_string($w) ? $w : (is_int($w) ? (string) $w
            : rtrim(rtrim(sprintf('%.6F', $w), '0'), '.'));
        if ($formular && $art === 'zahl' && substr_count($s, ',') === 1 && strpos($s, '.') === false) {
            $s = str_replace(',', '.', $s);
        }
        $muster = ($art === 'ganz') ? '/^-?[0-9]{1,10}\z/' : '/^-?[0-9]{1,10}(\.[0-9]{1,6})?\z/';
        $grund = sprintf(mg_t($art === 'ganz' ? 'WERT.GANZ' : 'WERT.ZAHL'),
            mg_wert_zahltext($r[1]), mg_wert_zahltext($r[2]));
        if (!preg_match($muster, $s)) {
            return array(false, null, $grund);
        }
        $z = ($art === 'ganz') ? (int) $s : (float) $s;
        if ($z < $r[1] || $z > $r[2]) {
            return array(false, null, $grund);
        }
        return array(true, $z, '');
    }
    if ($art === 'flag') {
        if ((is_int($w) || is_float($w)) && ((float) $w === 0.0 || (float) $w === 1.0)) {
            return array(true, (int) $w, '');
        }
        return array(false, null, mg_t('WERT.FLAG'));
    }
    if ($art === 'muster') {
        if (!is_string($w) || !preg_match($r[1], $w)) {
            return array(false, null, mg_t($r[2]));
        }
        return array(true, $w, '');
    }
    if ($art === 'frei') {
        if (!is_string($w) || strlen($w) > $r[1] || preg_match('/[\x00-\x1F\x7F]/', $w)) {
            return array(false, null, sprintf(mg_t('WERT.TEXT'), $r[1]));
        }
        return array(true, $w, '');
    }
    if ($art === 'koord') {
        $s = is_string($w) ? $w : '';
        if ($formular) {
            $s = str_replace(',', '.', $s);
        }
        if ($s === '') {
            return array(true, '', '');
        }
        if (!preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,8})?\z/', $s) || abs((float) $s) > $r[1]) {
            return array(false, null, sprintf(mg_t('WERT.KOORD'), $r[1]));
        }
        return array(true, $s, '');
    }
    if ($art === 'uhrzeit') {
        $u = is_string($w) ? mg_uhrzeit($w) : '';
        if ($u === '' || (!$formular && trim($w) !== $w)) {
            return array(false, null, mg_t('WERT.UHRZEIT'));
        }
        return array(true, $u, '');
    }
    if ($art === 'modus') {
        if (!is_string($w) || !in_array($w, mg_planmodi(), true)) {
            return array(false, null, sprintf(mg_t('WERT.MODUS'), implode(', ', mg_planmodi())));
        }
        return array(true, $w, '');
    }
    if ($art === 'befehl') {
        $ok = false;
        if (is_string($w) && $w !== '') {
            list($ok, , , , ) = mg_befehl_aufloesen($w, null);
        }
        return $ok ? array(true, $w, '') : array(false, null, mg_t('WERT.BEFEHL'));
    }
    if ($art === 'token') {
        if (!is_string($w) || !preg_match('/^[A-Za-z0-9_.\-]{0,64}\z/', $w)) {
            return array(false, null, mg_t('WERT.TOKEN'));
        }
        return array(true, $w, '');
    }
    if ($art === 'vins') {
        if (!is_array($w) || count($w) > 20 || ($w && array_keys($w) !== range(0, count($w) - 1))) {
            return array(false, null, mg_t('WERT.VINS'));
        }
        foreach ($w as $v) {
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9]{6,32}\z/', $v)) {
                return array(false, null, mg_t('WERT.VINS'));
            }
        }
        return array(true, array_values($w), '');
    }
    if ($art === 'namen') {
        if (!is_array($w) || count($w) > 20) {
            return array(false, null, mg_t('WERT.NAMEN'));
        }
        foreach ($w as $v) {
            if (!is_string($v) || strlen($v) > 256 || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                return array(false, null, mg_t('WERT.NAMEN'));
            }
        }
        return array(true, array_values($w), '');
    }
    if ($art === 'notify') {
        if (!is_array($w)) {
            return array(false, null, mg_t('WERT.NOTIFY'));
        }
        $aus = array();
        foreach ($w as $nk => $nv) {
            if (in_array($nk, array('push', 'soc_voll', 'stecker', 'offen', 'fenster', 'fehler'), true)) {
                list($ok, $nw, ) = mg_wert_pruefen('commands', $nv, false);
            } elseif ($nk === 'push_minutes') {
                list($ok, $nw, ) = mg_wert_pruefen('notify.push_minutes', $nv, false);
            } else {
                $ok = false;
            }
            if (!$ok) {
                return array(false, null, mg_t('WERT.NOTIFY'));
            }
            $aus[$nk] = $nw;
        }
        return array(true, $aus, '');
    }
    return array(false, null, mg_t('WERT.UNBEKANNT'));
}

/**
 * Schluessel, die eine Sicherung aus einer FRUEHEREN Fassung nicht tragen
 * kann. Fehlt einer davon, behaelt er seinen jetzigen Wert, statt die ganze
 * Datei abzuweisen - sonst waere jede Sicherung aus 1.1.17 mit dem Einbau
 * des iSMART-Kennworts (1.1.18) wertlos geworden. Jeder andere fehlende
 * Schluessel bleibt eine Beanstandung.
 */
function mg_sicherung_neue_schluessel()
{
    return array('saic_pass');
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
 * stammen aus einer anderen Fassung oder einem anderen Plugin. Der lesbare
 * Kopf (_hinweis, _stand - alles, was mit _ beginnt) wird uebergangen (seit
 * 1.1.18, Befund U7; bis 1.1.17 wurde eine Datei mit Kopf als "unbekannte
 * Einstellung _stand" abgewiesen).
 *
 * Seit 1.1.18 wird JEDER Wert vor dem Zusammenfuehren gegen Typ und Regel
 * der Vorgabe geprueft (mg_wert_pruefen(), Bauart E). Ein leeres Merkwort
 * heisst "kein Merkwort gesichert": es gilt das jetzige (Befund C5/U4 - bis
 * 1.1.17 wurde es leer gespeichert, die Selbstheilung kopierte danach die
 * Zweitschrift darueber, und die Meldung "44 Werte uebernommen" stimmte fuer
 * keinen einzigen Wert).
 *
 * Die Beanstandungen tragen KEINE Auszeichnung und werden hier NICHT
 * maskiert: die Oberflaeche gibt sie maskiert aus (Befund U6 - bis 1.1.17
 * stand dort "&lt;b&gt;nicht&lt;/b&gt;" und ein doppelt maskierter Name).
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], Werte in der Datei).
 */
function mg_sicherung_lesen($roh, $jetzt = null)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(mg_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $vorg = mg_vorgaben();
    $neu = array();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!array_key_exists($k, $vorg)) {
            $mangel[] = sprintf(mg_t('EINST.SICH_FREMD'), $k);
            continue;
        }
        list($ok, $wert, $grund) = mg_wert_pruefen($k, $w, false);
        if (!$ok) {
            $mangel[] = sprintf(mg_t('EINST.SICH_WERT'), $k, $grund);
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = mg_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Eine Datei mit einem einzigen Schluessel lief bis 1.1.x ohne
     * Beanstandung durch, wurde gespeichert, und alle uebrigen Einstellungen
     * fielen auf Werk zurueck - quittiert mit "1 Wert uebernommen" (gemessen
     * an VolkswagenID 0.9.11 am 03.09.2026, am 07.09.2026 ueber den Bestand
     * ausgerollt). Ausgenommen sind nur die Schluessel, die eine aeltere
     * Fassung nicht kennen konnte (mg_sicherung_neue_schluessel()). */
    $fehlend = array();
    $behalten = array();
    foreach (array_keys($vorg) as $fk) {
        if (array_key_exists($fk, $daten)) {
            continue;
        }
        if (in_array($fk, mg_sicherung_neue_schluessel(), true)) {
            $behalten[] = $fk;
        } else {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(mg_t('EINST.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    if ($mangel) {
        return array(null, $mangel, 0);
    }
    if ($jetzt === null) {
        $jetzt = mg_config();
    }
    foreach ($behalten as $fk) {
        $neu[$fk] = $jetzt[$fk];
    }
    if ($neu['aktionstoken'] === '') {
        $neu['aktionstoken'] = is_string($jetzt['aktionstoken']) ? $jetzt['aktionstoken'] : '';
    }
    return array($neu + $vorg, array(), $anzahl);
}

/* ==================================================================
 * Einmalmeldung fuer die Umleitung nach jedem POST (seit 1.1.18, Befund U1)
 *
 * Jeder POST-Zweig der Oberflaeche endet mit 303 auf index.php?form=...
 * (Regeln/04). Das Ergebnis reist in data/plugins/<ordner>/einmalmeldung.json,
 * 0600, wird NUR beim GET gelesen, dabei geloescht, und ist nach 120 s
 * verworfen. Bis 1.1.17 wiederholte F5 jede Aktion - auch einen Befehl ans
 * Fahrzeug. Bauart abf_flash_*() aus Abfahrts-Assistent 1.6.17.
 * ================================================================== */
function mg_flash_datei()
{
    return mg_paths()['datadir'] . '/einmalmeldung.json';
}

function mg_flash_schreiben(array $inhalt)
{
    $p = mg_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $inhalt['zeit'] = time();
    $js = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && mg_write_atomic(mg_flash_datei(), $js, 0600);
}

function mg_flash_lesen()
{
    $f = mg_flash_datei();
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > 120) {
        return array();
    }
    return $d;
}

/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function mg_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
