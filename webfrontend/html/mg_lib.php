<?php
/**
 * MG iSmart - gemeinsame Bibliothek
 *
 * Bringt die Daten eines MG-Elektrofahrzeugs (iSMART / SAIC) nach Loxone:
 * Ladestand, Reichweite, Ladeleistung, Tueren, Klima, Standort - und
 * schickt Befehle zurueck (Laden stoppen, Ziel-SoC, Ladestrom, Klima).
 *
 * ARCHITEKTUR
 * Es gibt keine offizielle Schnittstelle von MG/SAIC. Die Daten holt das
 * quelloffene "SAIC MQTT Gateway" (Docker-Container) und veroeffentlicht sie
 * per MQTT. Dieses Plugin liest die Werte vom MQTT-Broker (mosquitto_sub),
 * uebersetzt sie in eine kompakte Loxone-Zeile und schickt Befehle zurueck
 * (mosquitto_pub). Eine Neuimplementierung der verschluesselten SAIC-API in
 * PHP waere aufwaendig und wuerde bei jeder API-Aenderung brechen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 * Alle Funktionen tragen das Praefix mg_ (LBWeb::lbheader() setzt SDK-Globals).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

function mg_paths()
{
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'mgismart';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/mg.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/mg.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/mgismart',
            'lbhome' => $lbhomedir,
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
    );
}

function mg_config()
{
    $p = mg_paths();
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === ''
         || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
        @chmod($p['config'], 0600);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg += array(
        'broker_host' => '127.0.0.1',   // Mosquitto des LoxBerry
        'broker_port' => 1883,
        'broker_user' => '',
        'broker_pass' => '',
        'prefix' => 'saic',             // MQTT_TOPIC des Gateways
        'saic_user' => '',              // Benutzername (Teil des Topic-Pfads)
        'vin' => '',                    // Fahrzeug-ID / VIN (Teil des Topic-Pfads)
        'capacity' => 61.1,             // nutzbare Batteriekapazitaet in kWh
        'notify' => array(),
        'commands' => 1,                // Steuerbefehle erlauben
        // Schuetzt ?cmd= im UNANGEMELDETEN Endpunkt mg.php. Ohne dieses
        // Merkwort koennte jeder, der die LoxBerry-Weboberflaeche im Netz
        // erreicht, Standklima einschalten, die Heckscheibenheizung anwerfen
        // oder ueber "Auto finden" Licht und Hupe ausloesen. Lesende Abrufe
        // bleiben frei - die kosten nichts und verraten nichts Schaltbares.
        'aktionstoken' => '',
    );
    if (!is_array($cfg['notify'])) {
        $cfg['notify'] = array();
    }
    $cfg['notify'] += array(
        'push' => 1,
        'soc_voll' => 1,      // melden, wenn der Ziel-SoC erreicht ist
        'stecker' => 1,       // melden, wenn der Stecker steckt/gezogen wurde
        'offen' => 1,         // melden, wenn das Auto unverschlossen steht
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
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($json === false || @file_put_contents($p['config'], $json) === false) {
        return false;
    }
    @chmod($p['config'], 0600);
    @copy($p['config'], $p['backup']);
    @chmod($p['backup'], 0600);
    return true;
}

/**
 * Ein neues Merkwort erzeugen.
 *
 * random_bytes ist die kryptografisch geeignete Quelle; faellt sie aus (sehr
 * alte PHP-Fassungen ohne Zufallsquelle), wird nicht stillschweigend auf
 * rand() ausgewichen - ein vorhersagbares Merkwort waere schlechter als
 * gar keins, weil es Sicherheit nur vortaeuscht.
 */
function mg_token_erzeugen()
{
    return bin2hex(random_bytes(12));
}

function mg_log($msg)
{
    $p = mg_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    $cfg = mg_config();
    foreach (array($cfg['broker_pass']) as $geheim) {
        if ($geheim !== '') {
            $msg = str_replace($geheim, '********', $msg);
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
        @file_put_contents($f, $line);
    }
}

/* ---------------- MQTT ---------------- */

function mg_has_mosquitto()
{
    $out = array();
    @exec('command -v mosquitto_sub 2>/dev/null', $out);
    return !empty($out);
}

/** Basis-Topic des Fahrzeugs, z. B. saic/user@example.org/vehicles/LSJ… */
function mg_base_topic()
{
    $cfg = mg_config();
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $user = trim((string) $cfg['saic_user']);
    $vin = trim((string) $cfg['vin']);
    if ($user === '' || $vin === '') {
        return '';
    }
    return $prefix . '/' . $user . '/vehicles/' . $vin;
}

function mg_broker_args()
{
    $cfg = mg_config();
    $args = ' -h ' . escapeshellarg((string) $cfg['broker_host']) . ' -p ' . (int) $cfg['broker_port'];
    if (trim((string) $cfg['broker_user']) !== '') {
        $args .= ' -u ' . escapeshellarg((string) $cfg['broker_user']);
    }
    if ((string) $cfg['broker_pass'] !== '') {
        $args .= ' -P ' . escapeshellarg((string) $cfg['broker_pass']);
    }
    return $args;
}

/**
 * Alle behaltenen (retained) Werte unterhalb des Prefix einlesen und als
 * Momentaufnahme ablegen. Das SAIC-Gateway veroeffentlicht retained, deshalb
 * liefert ein kurzes Mitlesen sofort den kompletten Stand.
 */
function mg_snapshot($sekunden = 3)
{
    $cfg = mg_config();
    if (!mg_has_mosquitto()) {
        mg_log('FEHLER: mosquitto_sub fehlt - Paket mosquitto-clients nachinstallieren.');
        return array(0, 'mosquitto-clients fehlt');
    }
    $prefix = trim((string) $cfg['prefix']) !== '' ? trim((string) $cfg['prefix']) : 'saic';
    $cmd = 'mosquitto_sub' . mg_broker_args()
         . ' -t ' . escapeshellarg($prefix . '/#')
         . ' -v -W ' . max(1, min(15, (int) $sekunden)) . ' 2>&1';
    $out = array();
    @exec($cmd, $out, $rc);
    $werte = array();
    foreach ($out as $zeile) {
        $pos = strpos($zeile, ' ');
        if ($pos === false) {
            continue;
        }
        $topic = substr($zeile, 0, $pos);
        $wert = trim(substr($zeile, $pos + 1));
        if (strpos($topic, $prefix . '/') === 0) {
            $werte[$topic] = $wert;
        }
    }
    if (!$werte) {
        $fehler = trim(implode(' ', array_slice($out, 0, 3)));
        mg_log('Momentaufnahme leer (rc=' . $rc . ')' . ($fehler !== '' ? ': ' . $fehler : ''));
        return array(0, $fehler !== '' ? $fehler : 'keine Werte empfangen');
    }
    $p = mg_paths();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    @file_put_contents($p['data'] . '/werte.json', json_encode(array(
        'zeit' => date('c'), 'anzahl' => count($werte), 'werte' => $werte,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return array(1, count($werte) . ' Themen');
}

/** Rohwerte der letzten Momentaufnahme. */
function mg_raw()
{
    $p = mg_paths();
    $d = @json_decode((string) @file_get_contents($p['data'] . '/werte.json'), true);
    return is_array($d) ? $d : array('zeit' => '', 'anzahl' => 0, 'werte' => array());
}

/**
 * Einen logischen Wert suchen. Uebergeben wird eine Liste moeglicher
 * Topic-Endungen - so bleibt das Plugin unabhaengig davon, ob das Gateway
 * einen Zweig etwas anders benennt oder ein Fahrzeug ihn gar nicht liefert.
 */
function mg_pick($kandidaten, $default = null)
{
    $roh = mg_raw();
    $base = mg_base_topic();
    foreach ((array) $kandidaten as $suffix) {
        if ($base !== '' && isset($roh['werte'][$base . '/' . $suffix])) {
            return $roh['werte'][$base . '/' . $suffix];
        }
    }
    // Ersatzweg: irgendein Topic, das so endet (falls Benutzer/VIN abweichen)
    foreach ((array) $kandidaten as $suffix) {
        foreach ($roh['werte'] as $t => $v) {
            if (substr($t, -strlen('/' . $suffix)) === '/' . $suffix) {
                return $v;
            }
        }
    }
    return $default;
}

function mg_num($kandidaten, $default = -1)
{
    $v = mg_pick($kandidaten, null);
    if ($v === null || $v === '') {
        return $default;
    }
    $v = str_replace(',', '.', (string) $v);
    return is_numeric($v) ? (float) $v : $default;
}

function mg_bool($kandidaten, $default = -1)
{
    $v = mg_pick($kandidaten, null);
    if ($v === null || $v === '') {
        return $default;
    }
    $v = strtolower(trim((string) $v));
    if (in_array($v, array('true', '1', 'on', 'yes', 'locked', 'charging'), true)) {
        return 1;
    }
    if (in_array($v, array('false', '0', 'off', 'no', 'unlocked'), true)) {
        return 0;
    }
    return is_numeric($v) ? ((float) $v > 0 ? 1 : 0) : $default;
}

/* ---------------- Zustand ---------------- */

function mg_state()
{
    $cfg = mg_config();
    $roh = mg_raw();
    $soc = mg_num(array('drivetrain/soc', 'drivetrain/socKwh', 'battery/soc'));
    $ziel = mg_num(array('drivetrain/socTarget', 'drivetrain/targetSoc'), 0);
    $laedt = mg_bool(array('drivetrain/charging', 'drivetrain/chargingState'), 0);
    $stecker = mg_bool(array('drivetrain/chargerConnected', 'drivetrain/chargingConnected'), -1);
    $leistung = mg_num(array('drivetrain/power', 'drivetrain/chargingPower'), 0);
    $alter = $roh['zeit'] !== '' ? max(0, (int) round((time() - strtotime($roh['zeit'])) / 60)) : -1;
    $kwh = $soc >= 0 ? round($soc / 100 * (float) $cfg['capacity'], 1) : -1;
    $st = array(
        'ok' => ($roh['anzahl'] > 0 && $soc >= 0) ? 1 : 0,
        'soc' => $soc,
        'soc_kwh' => $kwh,
        'soc_ziel' => $ziel,
        'reichweite' => mg_num(array('drivetrain/range', 'drivetrain/rangeElectric'), -1),
        'laedt' => $laedt,
        'stecker' => $stecker,
        'ladeleistung' => $leistung,
        'ladestrom_limit' => (string) mg_pick(array('drivetrain/chargeCurrentLimit'), ''),
        'restzeit_min' => mg_num(array('drivetrain/remainingChargingTime', 'drivetrain/chargingTimeRemaining'), -1),
        'kilometerstand' => mg_num(array('drivetrain/mileage', 'drivetrain/odometer'), -1),
        'batterie12v' => mg_num(array('drivetrain/auxiliaryBatteryVoltage', 'drivetrain/batteryVoltage'), -1),
        'verschlossen' => mg_bool(array('doors/locked'), -1),
        'kofferraum' => mg_bool(array('doors/boot'), -1),
        'innentemp' => mg_num(array('climate/interiorTemperature'), -99),
        'aussentemp' => mg_num(array('climate/exteriorTemperature'), -99),
        'klima' => (string) mg_pick(array('climate/remoteClimateState'), ''),
        'themen' => (int) $roh['anzahl'],
        'alter_min' => $alter,
        'zeit' => $roh['zeit'],
    );
    $st['voll'] = ($ziel > 0 && $soc >= $ziel) ? 1 : 0;
    return $st;
}

/** Loxone-Zeile. */
function mg_line($st = null)
{
    if ($st === null) {
        $st = mg_state();
    }
    return sprintf(
        "MG;OK=%d;SOC=%.1f;SOCKWH=%.1f;ZIEL=%.0f;REICHWEITE=%.0f;LAEDT=%d;STECKER=%d;LEISTUNG=%.2f;"
        . "RESTZEIT=%.0f;KM=%.0f;BATT12V=%.1f;ZU=%d;KOFFER=%d;INNEN=%.1f;AUSSEN=%.1f;VOLL=%d;ALTER=%d;THEMEN=%d\n",
        $st['ok'], $st['soc'], $st['soc_kwh'], $st['soc_ziel'], $st['reichweite'], $st['laedt'], $st['stecker'],
        $st['ladeleistung'], $st['restzeit_min'], $st['kilometerstand'], $st['batterie12v'],
        $st['verschlossen'], $st['kofferraum'], $st['innentemp'], $st['aussentemp'],
        $st['voll'], $st['alter_min'], $st['themen']
    );
}

/* ---------------- Befehle ---------------- */

/**
 * Erlaubte Befehle. Bewusst eine feste Liste - so kann ueber den Endpunkt
 * nichts anderes ins Fahrzeug geschickt werden.
 */
function mg_commands()
{
    return array(
        'laden_stopp' => array('drivetrain/charging/set', 'false', 'Ladevorgang stoppen'),
        'laden_start' => array('drivetrain/charging/set', 'true', 'Ladevorgang starten (laut Projekt unzuverlaessig)'),
        'ziel_60' => array('drivetrain/socTarget/set', '60', 'Ziel-Ladestand 60 %'),
        'ziel_70' => array('drivetrain/socTarget/set', '70', 'Ziel-Ladestand 70 %'),
        'ziel_80' => array('drivetrain/socTarget/set', '80', 'Ziel-Ladestand 80 %'),
        'ziel_90' => array('drivetrain/socTarget/set', '90', 'Ziel-Ladestand 90 %'),
        'ziel_100' => array('drivetrain/socTarget/set', '100', 'Ziel-Ladestand 100 %'),
        'strom_6' => array('drivetrain/chargeCurrentLimit/set', '6A', 'Ladestrom 6 A (PV-Ueberschuss)'),
        'strom_8' => array('drivetrain/chargeCurrentLimit/set', '8A', 'Ladestrom 8 A'),
        'strom_16' => array('drivetrain/chargeCurrentLimit/set', '16A', 'Ladestrom 16 A'),
        'strom_max' => array('drivetrain/chargeCurrentLimit/set', 'MAX', 'Ladestrom maximal'),
        'klima_an' => array('climate/remoteClimateState/set', 'on', 'Standklima einschalten'),
        'klima_aus' => array('climate/remoteClimateState/set', 'off', 'Standklima ausschalten'),
        'heckscheibe_an' => array('climate/rearWindowDefrosterHeating/set', 'on', 'Heckscheibenheizung ein'),
        'heckscheibe_aus' => array('climate/rearWindowDefrosterHeating/set', 'off', 'Heckscheibenheizung aus'),
        'finden' => array('location/findMyCar/set', 'activate', 'Auto finden (Licht und Hupe)'),
        'finden_licht' => array('location/findMyCar/set', 'lights_only', 'Auto finden (nur Licht)'),
        'finden_stopp' => array('location/findMyCar/set', 'stop', 'Auto finden beenden'),
        'auffrischen' => array('refresh/mode/set', 'force', 'Fahrzeugstatus jetzt abfragen'),
    );
}

function mg_send($befehl)
{
    $cfg = mg_config();
    if (empty($cfg['commands'])) {
        return array(0, 'Steuerbefehle sind in den Einstellungen gesperrt');
    }
    $liste = mg_commands();
    if (!isset($liste[$befehl])) {
        return array(0, 'Unbekannter Befehl: ' . $befehl);
    }
    if (!mg_has_mosquitto()) {
        return array(0, 'mosquitto_pub fehlt (Paket mosquitto-clients)');
    }
    $base = mg_base_topic();
    if ($base === '') {
        return array(0, 'Benutzername oder VIN fehlt in den Einstellungen');
    }
    list($topic, $wert, $text) = $liste[$befehl];
    $cmd = 'mosquitto_pub' . mg_broker_args()
         . ' -t ' . escapeshellarg($base . '/' . $topic)
         . ' -m ' . escapeshellarg($wert) . ' 2>&1';
    $out = array();
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        mg_log('FEHLER Befehl "' . $text . '": ' . trim(implode(' ', $out)));
        return array(0, trim(implode(' ', $out)) ?: ('Fehlercode ' . $rc));
    }
    mg_log('Befehl gesendet: ' . $text . ' (' . $topic . ' = ' . $wert . ')');
    return array(1, $text);
}

/* ---------------- Meldungen ---------------- */

function mg_push_active()
{
    $cfg = mg_config();
    if (empty($cfg['notify']['push'])) {
        return 0;
    }
    $p = mg_paths();
    $f = $p['tmp'] . '/meldung.json';
    $m = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($m) || empty($m['zeit'])) {
        return 0;
    }
    $min = max(1, (int) $cfg['notify']['push_minutes']);
    return (time() - strtotime($m['zeit'])) < $min * 60 ? 1 : 0;
}

function mg_push_text()
{
    $m = @json_decode((string) @file_get_contents(mg_paths()['tmp'] . '/meldung.json'), true);
    return is_array($m) && !empty($m['text']) ? (string) $m['text'] : '';
}

/** Ereignisse erkennen (wird vom Cron nach jeder Momentaufnahme aufgerufen). */
function mg_check_events($st)
{
    $cfg = mg_config();
    $p = mg_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    $vorher = @json_decode((string) @file_get_contents($p['tmp'] . '/vorher.json'), true);
    $melden = '';
    if (is_array($vorher)) {
        if (!empty($cfg['notify']['soc_voll']) && $st['voll'] && empty($vorher['voll'])) {
            $melden = 'Auto geladen: ' . round($st['soc']) . ' % erreicht.';
        } elseif (!empty($cfg['notify']['stecker']) && $st['stecker'] === 1 && (int) $vorher['stecker'] === 0) {
            $melden = 'Ladekabel wurde eingesteckt.';
        } elseif (!empty($cfg['notify']['stecker']) && $st['stecker'] === 0 && (int) $vorher['stecker'] === 1) {
            $melden = 'Ladekabel wurde abgezogen.';
        } elseif (!empty($cfg['notify']['offen']) && $st['verschlossen'] === 0 && (int) $vorher['verschlossen'] === 1) {
            $melden = 'Das Auto ist unverschlossen.';
        }
    }
    @file_put_contents($p['tmp'] . '/vorher.json', json_encode($st));
    if ($melden !== '') {
        @file_put_contents($p['tmp'] . '/meldung.json', json_encode(array('zeit' => date('c'), 'text' => $melden)));
        mg_log('Meldung: ' . $melden);
    }
    return $melden;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
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

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function mg_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
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
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
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
