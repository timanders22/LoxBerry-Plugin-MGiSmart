<?php
/**
 * MG iSmart - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Gateway einrichten | Einbindung in Loxone |
 *         Ladungen | Test | Logdateien
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein mg_-Praefix.
 *
 * REIHENFOLGE: die Bibliothek wird als ERSTES eingebunden.
 * Bis 1.0.8 rief Zeile 14 lb_wurzel_ermitteln() auf - eine Funktion, die
 * erst 149 Zeilen weiter unten in einem function_exists-Block stand und
 * deren echte Fassung in der noch gar nicht geladenen Bibliothek liegt. War
 * LBHOMEDIR nicht gesetzt, endete die ganze Oberflaeche mit
 * "Fatal error: Call to undefined function lb_wurzel_ermitteln()" - gemessen
 * unter PHP 7.4 UND 8.4. Der Rueckfall, den die Bibliothek ausdruecklich
 * vorsieht, war damit genau dort tot, wo man ihn braucht.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$mg_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mg_ordner . '/mg_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/mgismart/mg_lib.php',
    dirname(__DIR__) . '/html/mg_lib.php',
) as $mg_kandidat) {
    if (is_file($mg_kandidat)) {
        require_once $mg_kandidat;
        break;
    }
}
if (!function_exists('mg_config')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "mg_lib.php nicht gefunden - das Plugin bitte neu installieren.\n";
    exit;
}

$mg_p = mg_paths();
if ($mg_p['lbhome'] !== '' && file_exists($mg_p['lbhome'] . '/libs/phplib/loxberry_system.php')) {
    require_once $mg_p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $mg_p['lbhome'] . '/libs/phplib/loxberry_web.php';
    $mg_p = mg_paths();   // nach dem Einbinden neu holen
}
$mg_logfile = $mg_p['log'];
$mg_plugin = $mg_p['plugin'];


/* ==================================================================
 * Wachposten gegen fremde Absender - VOR allen Handlern.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass
 * der Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht: die Anmeldung schickt er automatisch mit.
 * Bis 1.0.8 liess sich so "Auto finden" ausloesen - Licht und Hupe.
 *
 * Einen einzelnen Handler kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 * ================================================================== */
$mg_meldungen = array();
$mg_fehler = array();
$mg_verwaiste = array();
$mg_fmt = mg_formtoken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mg_fmt === '') {
        $mg_fehler[] = mg_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!mg_formtoken_ok()) {
        $mg_fehler[] = mg_t('FEHLER.CSRF');
        mg_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if ($mg_fehler) {
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft. Den aktiven
        // Reiter behalten - die Meldung soll dort stehen, wo der Bediener war.
        $mg_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($mg_behalten !== null) { $_POST['activetab'] = $mg_behalten; }
    }
}

/* Aktiver Reiter. Die Positivliste steht ausgeschrieben - so findet
 * hausstandard_pruefen.py sie; die Kongruenz mit Leiste und Bereichen
 * prueft der Reiter Test nach. */
$mg_reiter = array('tab-settings', 'tab-mqtt', 'tab-gateway', 'tab-loxone',
                   'tab-ladungen', 'tab-test', 'tab-log');
$mg_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $mg_reiter, true)) {
    $mg_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $mg_reiter, true)) {
    $mg_tab = 'tab-' . (string) $_GET['form'];
}

/* ---------------- Handler ---------------- */

$mg_cfg = mg_config();

// Merkwort beim ersten Oeffnen erzeugen. Danach nur noch auf ausdruecklichen
// Wunsch - es steckt in den Adressen im Miniserver.
if (trim((string) $mg_cfg['aktionstoken']) === '') {
    $mg_cfg['aktionstoken'] = mg_token_erzeugen();
    mg_config_save($mg_cfg);
    $mg_cfg = mg_config();
    $mg_fmt = mg_formtoken($mg_cfg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    $mg_vnr = max(1, (int) (isset($_POST['vnr']) ? $_POST['vnr'] : 1));
    $mg_vzeile = isset($_POST['vzeile']) && is_string($_POST['vzeile']) ? $_POST['vzeile'] : 'mg';
    if ($_POST['vorlage'] === 'vo') {
        list($mg_vname, $mg_vinhalt) = mg_vorlage_vo($mg_vnr);
    } else {
        list($mg_vname, $mg_vinhalt) = mg_vorlage($mg_vnr, $mg_vzeile);
    }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mg_vname . '"');
    echo $mg_vinhalt;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mg_logfile), 0775, true);
    mg_write_atomic($mg_logfile, '[' . date('Y-m-d H:i:s') . '] '
        . mg_t('MELDUNG.LOG_GELEERT') . "\n");
    $mg_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwaiste_suchen'])) {
    $mg_verwaiste = mg_mqtt_verwaiste(3);
    $mg_meldungen[] = sprintf(mg_t('MELDUNG.VERWAISTE_GEFUNDEN'), count($mg_verwaiste));
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwaiste_loeschen'])) {
    $mg_liste = mg_mqtt_verwaiste(3);
    list($mg_n, $mg_f) = mg_mqtt_verwaiste_loeschen(array_keys($mg_liste));
    if ($mg_f !== '') {
        $mg_fehler[] = mg_t('MELDUNG.VERWAISTE_FEHLER') . ' ' . $mg_f;
    } else {
        $mg_meldungen[] = sprintf(mg_t('MELDUNG.VERWAISTE_GELOESCHT'), $mg_n);
    }
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearladungen'])) {
    mg_write_json(mg_ladungen_datei(), array('liste' => array()));
    $mg_meldungen[] = mg_t('MELDUNG.LADUNGEN_GELEERT');
    $mg_tab = 'tab-ladungen';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refreshnow'])) {
    list($mg_ok, $mg_info) = mg_snapshot(4);
    if ($mg_ok) {
        $mg_meldungen[] = mg_t('MELDUNG.EINGELESEN') . ' ' . $mg_info;
    } else {
        $mg_fehler[] = mg_t('MELDUNG.NICHT_EINGELESEN') . ' ' . $mg_info;
    }
    $mg_tab = 'tab-test';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ptest'])) {
    mg_ptest_ausloesen();
    $mg_meldungen[] = mg_t('MELDUNG.PTEST_AUSGELOEST');
    $mg_tab = 'tab-test';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sendcmd'])
    && is_string($_POST['sendcmd'])) {
    $mg_snr = max(1, (int) (isset($_POST['snr']) ? $_POST['snr'] : 1));
    $mg_swert = isset($_POST['swert']) && is_string($_POST['swert']) ? $_POST['swert'] : null;
    if (!preg_match('/^[a-z0-9_]{1,32}$/', (string) $_POST['sendcmd'])) {
        $mg_fehler[] = mg_t('MELDUNG.BEFEHL_UNGUELTIG');
    } else {
        list($mg_ok, $mg_info, $mg_code) = mg_send((string) $_POST['sendcmd'], $mg_swert, $mg_snr);
        if ($mg_ok) {
            $mg_meldungen[] = mg_t('MELDUNG.BEFEHL_GESENDET') . ' ' . $mg_info;
        } else {
            $mg_fehler[] = mg_t('MELDUNG.BEFEHL_FEHLGESCHLAGEN') . ' ' . $mg_info;
        }
    }
    $mg_tab = 'tab-test';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $mg_cfg['aktionstoken'] = mg_token_erzeugen();
    if (mg_config_save($mg_cfg)) {
        $mg_cfg = mg_config();
        $mg_fmt = mg_formtoken($mg_cfg);
        $mg_meldungen[] = mg_t('MELDUNG.TOKEN_NEU');
    } else {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
    }
    $mg_tab = 'tab-loxone';
}

/* Eigener Handler je Reiter mit eigenem Formular.
 * isset($_POST[...]) stellt einen Haken beim Absenden eines ANDEREN
 * Formulars auf 0 - deshalb hat jeder Reiter seinen eigenen Handler, und
 * jeder baut auf mg_config() auf statt auf einem leeren Feld. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $mg_neu = mg_config();
    $mg_neu['broker_host'] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['broker_host']) ? $_POST['broker_host'] : '127.0.0.1')));
    if ($mg_neu['broker_host'] === '') { $mg_neu['broker_host'] = '127.0.0.1'; }
    $mg_neu['broker_port'] = max(1, min(65535,
        (int) (isset($_POST['broker_port']) ? $_POST['broker_port'] : 1883)));
    $mg_neu['broker_user'] = mg_optionswert(isset($_POST['broker_user']) ? $_POST['broker_user'] : '');
    // Leeres Feld loescht nicht: ein gespeichertes Passwort bleibt stehen.
    // Beschnitten wird es wie der Benutzername - ein aus der Zwischenablage
    // eingefuegtes Passwort mit angehaengtem \r ergaebe sonst ein stilles
    // Falschpasswort in der Optionsdatei.
    $mg_pw = mg_optionswert(isset($_POST['broker_pass']) ? $_POST['broker_pass'] : '');
    if ($mg_pw !== '') { $mg_neu['broker_pass'] = $mg_pw; }
    if (!empty($_POST['broker_pass_loeschen'])) { $mg_neu['broker_pass'] = ''; }
    $mg_neu['prefix'] = trim(preg_replace('#[^\w/\-]#', '',
        (string) (isset($_POST['prefix']) ? $_POST['prefix'] : 'saic')));
    if ($mg_neu['prefix'] === '') { $mg_neu['prefix'] = 'saic'; }
    $mg_neu['saic_user'] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['saic_user']) ? $_POST['saic_user'] : '')));

    /* Fahrzeuge: eine halb ausgefuellte Zeile wird UEBERGANGEN und gemeldet,
     * sie verhindert aber nicht das Speichern der uebrigen. */
    $mg_vins = array();
    $mg_namen = array();
    $mg_rohvins = isset($_POST['vin']) && is_array($_POST['vin']) ? $_POST['vin'] : array();
    $mg_rohnamen = isset($_POST['fzname']) && is_array($_POST['fzname']) ? $_POST['fzname'] : array();
    foreach ($mg_rohvins as $mg_i => $mg_v) {
        $mg_v = trim(preg_replace('/[\x00-\x1F\x7F"\'\s]/', '', (string) $mg_v));
        if ($mg_v === '') { continue; }
        if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $mg_v)) {
            $mg_fehler[] = mg_t('FEHLER.VIN') . ' ' . mg_e(mg_kuerzen($mg_v, 24));
            continue;
        }
        $mg_vins[] = $mg_v;
        $mg_namen[] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
            (string) (isset($mg_rohnamen[$mg_i]) ? $mg_rohnamen[$mg_i] : '')));
    }
    $mg_neu['vins'] = $mg_vins;
    $mg_neu['namen'] = $mg_namen;

    $mg_neu['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $mg_neu['mqtt_praefix'] = trim(preg_replace('#[^\w/\-]#', '',
        (string) (isset($_POST['mqtt_praefix']) ? $_POST['mqtt_praefix'] : 'mg')));
    if ($mg_neu['mqtt_praefix'] === '') { $mg_neu['mqtt_praefix'] = 'mg'; }

    if (mg_config_save($mg_neu)) {
        $mg_meldungen[] = mg_t('MELDUNG.GESPEICHERT');
        $mg_cfg = mg_config();
        $mg_fmt = mg_formtoken($mg_cfg);
    } else {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
    }
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $mg_neu = mg_config();
    $mg_neu['capacity'] = max(1, min(200, (float) str_replace(',', '.',
        (string) (isset($_POST['capacity']) ? $_POST['capacity'] : 61.1))));
    $mg_neu['commands'] = isset($_POST['commands']) ? 1 : 0;
    $mg_neu['gefahr_ein'] = isset($_POST['gefahr_ein']) ? 1 : 0;
    $mg_neu['wirkung_pruefen'] = isset($_POST['wirkung_pruefen']) ? 1 : 0;
    $mg_neu['wartezeit'] = max(2, min(20, (int) (isset($_POST['wartezeit']) ? $_POST['wartezeit'] : 6)));
    $mg_neu['befehl_abstand'] = max(0, min(3600,
        (int) (isset($_POST['befehl_abstand']) ? $_POST['befehl_abstand'] : 60)));
    $mg_neu['strom_abstand'] = max(0, min(3600,
        (int) (isset($_POST['strom_abstand']) ? $_POST['strom_abstand'] : 300)));
    $mg_neu['befehle_stunde'] = max(1, min(500,
        (int) (isset($_POST['befehle_stunde']) ? $_POST['befehle_stunde'] : 30)));

    $mg_neu['ort_ein'] = isset($_POST['ort_ein']) ? 1 : 0;
    foreach (array('heim_breite' => 90, 'heim_laenge' => 180) as $mg_f => $mg_max) {
        $mg_w = trim(str_replace(',', '.', (string) (isset($_POST[$mg_f]) ? $_POST[$mg_f] : '')));
        if ($mg_w === '') {
            $mg_neu[$mg_f] = '';
        } elseif (preg_match('/^-?\d{1,3}(\.\d{1,8})?$/', $mg_w) && abs((float) $mg_w) <= $mg_max) {
            $mg_neu[$mg_f] = $mg_w;
        } else {
            $mg_fehler[] = mg_t('FEHLER.KOORDINATE') . ' ' . mg_e(mg_kuerzen($mg_w, 20));
        }
    }
    $mg_neu['heim_radius'] = max(20, min(20000,
        (int) (isset($_POST['heim_radius']) ? $_POST['heim_radius'] : 150)));

    $mg_neu['notify'] = array(
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'soc_voll' => isset($_POST['n_voll']) ? 1 : 0,
        'stecker' => isset($_POST['n_stecker']) ? 1 : 0,
        'offen' => isset($_POST['n_offen']) ? 1 : 0,
        'fenster' => isset($_POST['n_fenster']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'push_minutes' => max(1, min(60,
            (int) (isset($_POST['push_minutes']) ? $_POST['push_minutes'] : 5))),
    );

    $mg_neu['ladungen_ein'] = isset($_POST['ladungen_ein']) ? 1 : 0;

    /* Lade- und Batterieheizplan. Eine unbrauchbare Uhrzeit wird GEMELDET und
     * die Zeile uebergangen - sie verhindert aber nicht das Speichern der
     * uebrigen Einstellungen. */
    $mg_neu['plan_ein'] = isset($_POST['plan_ein']) ? 1 : 0;
    foreach (array('plan_von', 'plan_bis', 'heizplan_von') as $mg_f) {
        $mg_w = (string) (isset($_POST[$mg_f]) ? $_POST[$mg_f] : '');
        $mg_u = mg_uhrzeit($mg_w);
        if ($mg_u !== '') {
            $mg_neu[$mg_f] = $mg_u;
        } elseif (trim($mg_w) !== '') {
            $mg_fehler[] = mg_t('FEHLER.UHRZEIT') . ' ' . mg_e(mg_kuerzen($mg_w, 20));
        }
    }
    $mg_w = strtolower((string) (isset($_POST['plan_modus']) ? $_POST['plan_modus'] : ''));
    if (in_array($mg_w, mg_planmodi(), true)) {
        $mg_neu['plan_modus'] = $mg_w;
    }

    $mg_neu['abfahrt_ein'] = isset($_POST['abfahrt_ein']) ? 1 : 0;
    $mg_neu['abfahrt_praefix'] = trim(preg_replace('#[^\w/\-]#', '',
        (string) (isset($_POST['abfahrt_praefix']) ? $_POST['abfahrt_praefix'] : 'abfahrt')));
    if ($mg_neu['abfahrt_praefix'] === '') { $mg_neu['abfahrt_praefix'] = 'abfahrt'; }
    $mg_neu['abfahrt_vorlauf'] = max(1, min(180,
        (int) (isset($_POST['abfahrt_vorlauf']) ? $_POST['abfahrt_vorlauf'] : 20)));
    $mg_neu['abfahrt_temp'] = max(16, min(30,
        (int) (isset($_POST['abfahrt_temp']) ? $_POST['abfahrt_temp'] : 21)));
    $mg_neu['abfahrt_fahrzeug'] = max(1, (int) (isset($_POST['abfahrt_fahrzeug'])
        ? $_POST['abfahrt_fahrzeug'] : 1));

    $mg_neu['ladeempf_ein'] = isset($_POST['ladeempf_ein']) ? 1 : 0;
    $mg_neu['ladeempf_thema'] = trim(preg_replace('/[\x00-\x1F\x7F"\'\s]/', '',
        (string) (isset($_POST['ladeempf_thema']) ? $_POST['ladeempf_thema'] : '')));
    $mg_neu['ladeempf_grenze'] = (float) str_replace(',', '.',
        (string) (isset($_POST['ladeempf_grenze']) ? $_POST['ladeempf_grenze'] : 0));
    $mg_neu['ladeempf_unter'] = isset($_POST['ladeempf_unter']) ? 1 : 0;
    $mg_neu['ladeempf_fahrzeug'] = max(1, (int) (isset($_POST['ladeempf_fahrzeug'])
        ? $_POST['ladeempf_fahrzeug'] : 1));
    foreach (array('ladeempf_hoch', 'ladeempf_runter') as $mg_f) {
        $mg_w = (string) (isset($_POST[$mg_f]) ? $_POST[$mg_f] : '');
        list($mg_gueltig, , , , ) = mg_befehl_aufloesen($mg_w, null);
        $mg_neu[$mg_f] = $mg_gueltig ? $mg_w : $mg_neu[$mg_f];
    }

    if (mg_config_save($mg_neu)) {
        $mg_meldungen[] = mg_t('MELDUNG.GESPEICHERT');
        $mg_cfg = mg_config();
        $mg_fmt = mg_formtoken($mg_cfg);
    } else {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
    }
    $mg_tab = 'tab-settings';
}

/* ---------------- Anzeige vorbereiten ---------------- */

$mg_token = (string) $mg_cfg['aktionstoken'];
$mg_notify = $mg_cfg['notify'];
$mg_fahrzeuge = mg_fahrzeuge($mg_cfg);
$mg_anzahl = count($mg_fahrzeuge);
$mg_roh = mg_raw();
$mg_hasmos = mg_has_mosquitto();
$mg_cmds = mg_befehle();
$mg_felder = mg_felder();
$mg_zeilen = mg_zeilen();
$mg_host = mg_host();
$mg_ver = mg_pluginversion();
$mg_loglines = mg_log_tail($mg_logfile, 300);
$mg_pruefzeilen = mg_selbsttest();
$mg_ladungen = mg_ladungen_lesen(100);

// Das angezeigte Fahrzeug im Reiter Test.
$mg_nr = 1;
if (isset($_GET['fz']) && is_string($_GET['fz']) && (int) $_GET['fz'] >= 1
    && (int) $_GET['fz'] <= max(1, $mg_anzahl)) {
    $mg_nr = (int) $_GET['fz'];
}
$mg_st = mg_state($mg_nr);

/** Zahl anzeigen - "unbekannt" wird zum Strich, nicht zur Null. */
function mg_z($v, $einheit = '', $ung = '&ndash;')
{
    if ($v === null || (float) $v < 0) {
        return $ung;
    }
    return rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',') . $einheit;
}

/** Ja / Nein / Strich fuer die dreiwertigen Felder. */
function mg_jn($v)
{
    $v = (int) $v;
    if ($v === 1) { return mg_e(mg_t('WORT.JA')); }
    if ($v === 0) { return mg_e(mg_t('WORT.NEIN')); }
    return '&ndash;';
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Bis 1.1.1 standen die beiden Sicherungs-Handler DAHINTER. Der
 * Seitenkopf war damit schon geschrieben, und
 * header('Content-Type: application/json') kam zu spaet. Gemessen am
 * 26.08.2026 mit PHP 8.4 und GUELTIGEM Formularmerkmal - also so, wie
 * ein Bediener den Knopf drueckt:
 *
 *   WARNUNG|Cannot modify header information - headers already sent|index.php:395
 *   WARNUNG|dasselbe|index.php:396
 *   Antwortkoerper: <!-- lbheader: MG iSmart --> { "broker_host": ... }
 *
 * Der Knopf lieferte also keine Datei, sondern eine Seite. Am PHP-CLI
 * ist der Fehler unsichtbar (header() ist dort wirkungslos), und der
 * Wachposten wies die erste Messung ohne Merkmal ab - beides hat den
 * Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mg_sichern'])) {
    $mg_js = json_encode(mg_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($mg_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mgismart_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $mg_js;
        exit;
    }
    $mg_fehler[] = mg_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mg_zurueck'])) {
    if (!isset($_FILES['mg_sicherung']) || !is_array($_FILES['mg_sicherung'])
        || !isset($_FILES['mg_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['mg_sicherung']['tmp_name'])) {
        $mg_fehler[] = mg_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['mg_sicherung']['size'] > 262144) {
        $mg_fehler[] = mg_t('EINST.SICH_ZU_GROSS');
    } else {
        list($mg_neu, $mg_mangel, $mg_n) = mg_sicherung_lesen(
            (string) @file_get_contents($_FILES['mg_sicherung']['tmp_name']));
        if ($mg_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $mg_fehler[] = mg_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $mg_mangel);
        } elseif (mg_config_save($mg_neu)) {
            $mg_meldungen[] = sprintf(mg_t('EINST.SICH_UEBERNOMMEN'), $mg_n);
        } else {
            $mg_fehler[] = mg_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}


if (class_exists('LBWeb', false)) {
    LBWeb::lbheader('MG iSmart' . ($mg_ver !== '' ? ' ' . $mg_ver : ''),
        'https://github.com/SAIC-iSmart-API/saic-python-mqtt-gateway', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
       . '<title>MG iSmart</title></head><body>';
}

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Deshalb wird
   ausschliesslich der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. Die Klasse steht schon im
   ausgelieferten HTML - ohne das waere die Seite ohne JavaScript leer. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Tabellen mit vielen Spalten oder Eingabefeldern in einen Rollbehaelter. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number] {
    width: 100%; max-width: 520px; padding: 7px 9px; border: 1px solid #ccc;
    border-radius: 6px; box-sizing: border-box; background: #fff; }
/* Eigene Klasse, in der Hausstandard-Vorlage nicht enthalten: die dunkle
   Textflaeche fuer Protokoll und Rohdaten. Sie ist der einzige Zusatz dieses
   Plugins und steht hier ausdruecklich benannt - die Vorlage verlangt das
   fuer jede eigene sm--Klasse. */
.sm-wrap .sm-log { background: #263238; color: #cfd8dc; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 10px; border-radius: 8px; max-height: 460px; overflow: auto;
    white-space: pre-wrap; box-shadow: none; }
</style>
<div class="sm-wrap">
<h2 style="margin-top:6px;">MG iSmart<?php if ($mg_ver !== '') { ?> <span class="sm-hilfe" style="font-weight:400;"><?= mg_e($mg_ver) ?></span><?php } ?></h2>
<div class="sm-hilfe"><?php echo mg_t('KOPF.EINLEITUNG'); ?></div>

<?php foreach ($mg_meldungen as $mg_m) { ?><div class="sm-hinweis"><?= mg_e($mg_m) ?></div><?php } ?>
<?php foreach ($mg_fehler as $mg_m) { ?><div class="sm-warnung"><?= mg_e($mg_m) ?></div><?php } ?>

<?php if (!$mg_hasmos) { ?>
<div class="sm-warnung"><b><?php echo mg_t('WARN.MOSQUITTO'); ?></b><br>
<span class="sm-mono">sudo apt-get update &amp;&amp; sudo apt-get install -y mosquitto-clients</span></div>
<?php } ?>
<?php if (trim((string) $mg_cfg['saic_user']) === '' || $mg_anzahl === 0) { ?>
<div class="sm-warnung"><?php echo mg_t('WARN.NICHT_EINGERICHTET'); ?></div>
<?php } ?>
<?php if (mg_mqtt_gateway_autostart() === false) { ?>
<div class="sm-warnung"><?php echo mg_t('WARN.AUTOSTART'); ?></div>
<?php } ?>

<div class="sm-tabs">
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= mg_e(mg_t('REITER.EINSTELLUNGEN')) ?></a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-gateway' ? ' sm-active' : '' ?>" data-ziel="tab-gateway"
	   href="index.php?form=gateway"><?= mg_e(mg_t('REITER.GATEWAY')) ?></a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= mg_e(mg_t('REITER.LOXONE')) ?></a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-ladungen' ? ' sm-active' : '' ?>" data-ziel="tab-ladungen"
	   href="index.php?form=ladungen"><?= mg_e(mg_t('REITER.LADUNGEN')) ?></a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= mg_e(mg_t('REITER.TEST')) ?></a>
	<a data-role="none" class="sm-tab<?= $mg_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= mg_e(mg_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="save" value="1">

<h2><?= mg_e(mg_t('EINST.H_FAHRZEUG')) ?></h2>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.KAPAZITAET')) ?></label>
	<input data-role="none" type="text" name="capacity" value="<?= mg_e($mg_cfg['capacity']) ?>" placeholder="61,1">
	<div class="sm-hilfe"><?php echo mg_t('EINST.KAPAZITAET_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="commands" <?= !empty($mg_cfg['commands']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.COMMANDS')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.COMMANDS_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="gefahr_ein" <?= !empty($mg_cfg['gefahr_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.GEFAHR')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.GEFAHR_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="wirkung_pruefen" <?= !empty($mg_cfg['wirkung_pruefen']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.WIRKUNG')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.WIRKUNG_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.WARTEZEIT')) ?></label>
	<input data-role="none" type="number" name="wartezeit" min="2" max="20" value="<?= (int) $mg_cfg['wartezeit'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.BEFEHL_ABSTAND')) ?></label>
	<input data-role="none" type="number" name="befehl_abstand" min="0" max="3600" value="<?= (int) $mg_cfg['befehl_abstand'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.STROM_ABSTAND')) ?></label>
	<input data-role="none" type="number" name="strom_abstand" min="0" max="3600" value="<?= (int) $mg_cfg['strom_abstand'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.BEFEHLE_STUNDE')) ?></label>
	<input data-role="none" type="number" name="befehle_stunde" min="1" max="500" value="<?= (int) $mg_cfg['befehle_stunde'] ?>">
	<div class="sm-hilfe"><?php echo mg_t('EINST.DROSSEL_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_ORT')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ort_ein" <?= !empty($mg_cfg['ort_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.ORT_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.ORT_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_BREITE')) ?></label>
	<input data-role="none" type="text" name="heim_breite" value="<?= mg_e($mg_cfg['heim_breite']) ?>" placeholder="48.1372">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_LAENGE')) ?></label>
	<input data-role="none" type="text" name="heim_laenge" value="<?= mg_e($mg_cfg['heim_laenge']) ?>" placeholder="11.5756">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_RADIUS')) ?></label>
	<input data-role="none" type="number" name="heim_radius" min="20" max="20000" value="<?= (int) $mg_cfg['heim_radius'] ?>">
</div>

<h2><?= mg_e(mg_t('EINST.H_MELDUNGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="notify_push" <?= !empty($mg_notify['push']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_PUSH')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_voll" <?= !empty($mg_notify['soc_voll']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_VOLL')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_stecker" <?= !empty($mg_notify['stecker']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_STECKER')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_offen" <?= !empty($mg_notify['offen']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_OFFEN')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_fenster" <?= !empty($mg_notify['fenster']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_FENSTER')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_fehler" <?= !empty($mg_notify['fehler']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_FEHLER')) ?></label>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PUSH_MINUTEN')) ?></label>
	<input data-role="none" type="number" name="push_minutes" min="1" max="60" value="<?= (int) $mg_notify['push_minutes'] ?>">
	<div class="sm-hilfe"><?php echo mg_t('EINST.PUSH_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_AUTOMATIK')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="abfahrt_ein" <?= !empty($mg_cfg['abfahrt_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.ABFAHRT_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.ABFAHRT_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_PRAEFIX')) ?></label>
	<input data-role="none" type="text" name="abfahrt_praefix" value="<?= mg_e($mg_cfg['abfahrt_praefix']) ?>" placeholder="abfahrt">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_VORLAUF')) ?></label>
	<input data-role="none" type="number" name="abfahrt_vorlauf" min="1" max="180" value="<?= (int) $mg_cfg['abfahrt_vorlauf'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_TEMP')) ?></label>
	<input data-role="none" type="number" name="abfahrt_temp" min="16" max="30" value="<?= (int) $mg_cfg['abfahrt_temp'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_FAHRZEUG')) ?></label>
	<input data-role="none" type="number" name="abfahrt_fahrzeug" min="1" max="9" value="<?= (int) $mg_cfg['abfahrt_fahrzeug'] ?>">
</div>

<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladeempf_ein" <?= !empty($mg_cfg['ladeempf_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADEEMPF_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.LADEEMPF_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_THEMA')) ?></label>
	<input data-role="none" type="text" name="ladeempf_thema" value="<?= mg_e($mg_cfg['ladeempf_thema']) ?>" placeholder="pv/ueberschuss">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_GRENZE')) ?></label>
	<input data-role="none" type="text" name="ladeempf_grenze" value="<?= mg_e($mg_cfg['ladeempf_grenze']) ?>">
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladeempf_unter" <?= !empty($mg_cfg['ladeempf_unter']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADEEMPF_UNTER')) ?></label>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_HOCH')) ?></label>
	<select data-role="none" name="ladeempf_hoch">
	<?php foreach ($mg_cmds as $mg_k => $mg_c) { if (!empty($mg_c['zusatz']) || !empty($mg_c['gefahr'])) { continue; } ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_cfg['ladeempf_hoch'] === $mg_k ? ' selected' : '' ?>><?= mg_e(mg_t($mg_c['bez'])) ?></option>
	<?php } foreach (mg_aliasse() as $mg_k => $mg_a) { ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_cfg['ladeempf_hoch'] === $mg_k ? ' selected' : '' ?>><?= mg_e($mg_k) ?></option>
	<?php } ?>
	</select>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_RUNTER')) ?></label>
	<select data-role="none" name="ladeempf_runter">
	<?php foreach ($mg_cmds as $mg_k => $mg_c) { if (!empty($mg_c['zusatz']) || !empty($mg_c['gefahr'])) { continue; } ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_cfg['ladeempf_runter'] === $mg_k ? ' selected' : '' ?>><?= mg_e(mg_t($mg_c['bez'])) ?></option>
	<?php } foreach (mg_aliasse() as $mg_k => $mg_a) { ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_cfg['ladeempf_runter'] === $mg_k ? ' selected' : '' ?>><?= mg_e($mg_k) ?></option>
	<?php } ?>
	</select>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_FAHRZEUG')) ?></label>
	<input data-role="none" type="number" name="ladeempf_fahrzeug" min="1" max="9" value="<?= (int) $mg_cfg['ladeempf_fahrzeug'] ?>">
</div>

<h2><?= mg_e(mg_t('EINST.H_PLAN')) ?></h2>
<div class="sm-warnung"><?php echo mg_t('EINST.PLAN_WARNUNG'); ?></div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="plan_ein" <?= !empty($mg_cfg['plan_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.PLAN_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.PLAN_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_VON')) ?></label>
	<input data-role="none" type="text" name="plan_von" value="<?= mg_e($mg_cfg['plan_von']) ?>" placeholder="22:00">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_BIS')) ?></label>
	<input data-role="none" type="text" name="plan_bis" value="<?= mg_e($mg_cfg['plan_bis']) ?>" placeholder="06:00">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_MODUS')) ?></label>
	<select data-role="none" name="plan_modus">
	<?php foreach (mg_planmodi() as $mg_pm) { ?>
		<option value="<?= mg_e($mg_pm) ?>"<?= $mg_cfg['plan_modus'] === $mg_pm ? ' selected' : '' ?>><?= mg_e(mg_t('PLANMODUS.' . strtoupper($mg_pm))) ?></option>
	<?php } ?>
	</select>
	<div class="sm-hilfe"><?php echo mg_t('EINST.PLAN_MODUS_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIZPLAN_VON')) ?></label>
	<input data-role="none" type="text" name="heizplan_von" value="<?= mg_e($mg_cfg['heizplan_von']) ?>" placeholder="05:30">
</div>

<h2><?= mg_e(mg_t('EINST.H_LADUNGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladungen_ein" <?= !empty($mg_cfg['ladungen_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADUNGEN_EIN')) ?></label>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
<span><i class="sm-punkt sm-b-lesen"></i> <?= mg_e(mg_t('LEGENDE.LESEN')) ?></span>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= mg_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= mg_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= mg_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="mg_sichern" value="1"><?= mg_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
    <input data-role="none" type="file" name="mg_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mg_zurueck" value="1"><?= mg_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="mqtt_save" value="1">

<h2><?= mg_e(mg_t('MQTTR.H_BROKER')) ?></h2>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.HOST')) ?></label>
	<input data-role="none" type="text" name="broker_host" value="<?= mg_e($mg_cfg['broker_host']) ?>" placeholder="127.0.0.1">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PORT')) ?></label>
	<input data-role="none" type="number" name="broker_port" min="1" max="65535" value="<?= (int) $mg_cfg['broker_port'] ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.USER')) ?></label>
	<input data-role="none" type="text" name="broker_user" value="<?= mg_e($mg_cfg['broker_user']) ?>">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PASS')) ?></label>
	<input data-role="none" type="password" name="broker_pass" value="" placeholder="<?= $mg_cfg['broker_pass'] !== '' ? mg_e(mg_t('MQTTR.PASS_GESPEICHERT')) : mg_e(mg_t('MQTTR.PASS_LEER')) ?>">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:6px;">
	<input data-role="none" type="checkbox" name="broker_pass_loeschen" value="1">
	<?= mg_e(mg_t('MQTTR.PASS_LOESCHEN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.BROKER_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('MQTTR.H_THEMEN')) ?></h2>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PREFIX')) ?></label>
	<input data-role="none" type="text" name="prefix" value="<?= mg_e($mg_cfg['prefix']) ?>" placeholder="saic">
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.SAIC_USER')) ?></label>
	<input data-role="none" type="text" name="saic_user" value="<?= mg_e($mg_cfg['saic_user']) ?>" placeholder="name@example.org">
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.PFAD_HILFE'); ?>
	<span class="sm-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>/&lt;<?= mg_e(mg_t('MQTTR.BENUTZER')) ?>&gt;/vehicles/&lt;VIN&gt;</span></div>
</div>

<h3><?= mg_e(mg_t('MQTTR.H_FAHRZEUGE')) ?></h3>
<div class="sm-hilfe"><?php echo mg_t('MQTTR.FAHRZEUGE_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= mg_e(mg_t('MQTTR.VIN')) ?></th><th><?= mg_e(mg_t('MQTTR.FZNAME')) ?></th><th><?= mg_e(mg_t('MQTTR.GEFUNDEN')) ?></th></tr>
<?php for ($mg_i = 0; $mg_i < max(3, $mg_anzahl + 1); $mg_i++) {
    $mg_v = isset($mg_cfg['vins'][$mg_i]) ? $mg_cfg['vins'][$mg_i] : '';
    $mg_n = isset($mg_cfg['namen'][$mg_i]) ? $mg_cfg['namen'][$mg_i] : ''; ?>
<tr><td><?= $mg_i + 1 ?></td>
	<td><input data-role="none" type="text" name="vin[]" value="<?= mg_e($mg_v) ?>" placeholder="LSJ..."></td>
	<td><input data-role="none" type="text" name="fzname[]" value="<?= mg_e($mg_n) ?>" placeholder="MG <?= $mg_i + 1 ?>"></td>
	<td><?= $mg_v !== '' ? (int) mg_themen_anzahl($mg_i + 1) : '&ndash;' ?></td></tr>
<?php } ?>
</table>
</div>

<h2><?= mg_e(mg_t('MQTTR.H_EIGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="mqtt_ein" <?= !empty($mg_cfg['mqtt_ein']) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('MQTTR.EIGEN_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.EIGEN_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.EIGEN_PRAEFIX')) ?></label>
	<input data-role="none" type="text" name="mqtt_praefix" value="<?= mg_e($mg_cfg['mqtt_praefix']) ?>" placeholder="mg">
</div>
<div class="sm-hinweis"><b><?= mg_e(mg_t('MQTTR.ABO_TITEL')) ?></b><br>
<span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ')) ?>/#</span><br>
<?php echo mg_abo_text(); ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= mg_e(mg_t('MQTTR.H_AUFRAEUMEN')) ?></h2>
<div class="sm-warnung"><?php echo mg_t('MQTTR.AUFRAEUMEN_WARNUNG'); ?></div>
<?php if (!empty($mg_verwaiste)) { ?>
<div class="sm-hilfe"><?= mg_e(sprintf(mg_t('MELDUNG.VERWAISTE_GEFUNDEN'), count($mg_verwaiste))) ?></div>
<div class="sm-log"><?php foreach (array_slice($mg_verwaiste, 0, 200, true) as $mg_vt => $mg_vv) {
    echo mg_e($mg_vt) . ' = ' . mg_e(mg_kuerzen($mg_vv, 40)) . "\n"; } ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="verwaiste_suchen" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mg_e(mg_t('KNOPF.VERWAISTE_SUCHEN')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="verwaiste_loeschen" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.VERWAISTE_LOESCHEN')) ?></button>
</div>
</form>

<h2><?= mg_e(mg_t('MQTTR.H_TABELLE')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('MQTTR.TABELLE_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('MQTTR.THEMA')) ?></th><th><?= mg_e(mg_t('MQTTR.BEDEUTUNG')) ?></th></tr>
<?php foreach (mg_mqtt_themen() as $mg_th => $mg_bez) { ?>
<tr><td><span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ') . '/' . $mg_th) ?></span></td>
	<td><?= mg_e(mg_t($mg_bez)) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Gateway einrichten ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-gateway' ? ' sm-active' : '' ?>" id="tab-gateway">
<h2><?= mg_e(mg_t('GW.H_WARUM')) ?></h2>
<p class="sm-hilfe"><?php echo mg_t('GW.WARUM'); ?></p>

<div class="sm-step"><b><?= mg_e(mg_t('GW.S1_TITEL')) ?></b><br><?php echo mg_t('GW.S1'); ?></div>
<div class="sm-step"><b><?= mg_e(mg_t('GW.S2_TITEL')) ?></b><br><?php echo mg_t('GW.S2'); ?></div>
<div class="sm-step"><b><?= mg_e(mg_t('GW.S3_TITEL')) ?></b><br><?php echo mg_t('GW.S3'); ?>
<div class="sm-pre">docker run -d --name saic-gateway --restart unless-stopped \
  -e SAIC_USER="name@example.org" \
  -e SAIC_PASSWORD="IHR-ISMART-PASSWORT" \
  -e SAIC_REST_URI="https://gateway-sm-eu.soimt.com/api.app/v1/" \
  -e SAIC_REGION="eu" \
  -e MQTT_URI="tcp://<?= mg_e($mg_cfg['broker_host'] ?: '127.0.0.1') ?>:<?= (int) ($mg_cfg['broker_port'] ?: 1883) ?>" \
  -e MQTT_TOPIC="<?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>" \
  -e HA_DISCOVERY_ENABLED="False" \
  -e BATTERY_CAPACITY_MAPPING="IHRE-VIN=<?= mg_e($mg_cfg['capacity']) ?>" \
  saicismartapi/saic-python-mqtt-gateway</div>
<div class="sm-hilfe"><?php echo mg_t('GW.S3_HINWEIS'); ?></div>
</div>
<div class="sm-step"><b><?= mg_e(mg_t('GW.S4_TITEL')) ?></b><br><?php echo mg_t('GW.S4'); ?></div>
<div class="sm-step"><b><?= mg_e(mg_t('GW.S5_TITEL')) ?></b>
<div class="sm-warnung"><?php echo mg_t('GW.S5_WARNUNG'); ?></div>
<div class="sm-hilfe"><?php echo mg_t('GW.S5_HINWEIS'); ?></div>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= mg_e(mg_t('LOX.H')) ?></h2>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S1_TITEL')) ?></b><br><?php echo mg_t('LOX.S1'); ?></div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S2_TITEL')) ?></b><br><?php echo mg_t('LOX.S2'); ?>
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.ABO')) ?></th><th><?= mg_e(mg_t('LOX.WANN')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ')) ?>/#</span></td><td><?= mg_e(mg_t('LOX.ABO_EIGEN')) ?></td></tr>
<tr><td><span class="sm-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>/#</span></td><td><?= mg_e(mg_t('LOX.ABO_ROH')) ?></td></tr>
</table>
<?php
/* Was hier steht, haengt von der Fassung des MQTT-Gateways ab - siehe
 * mg_mqtt_gateway_info(). Ein pauschaler Satz waere fuer eine der beiden
 * Fassungen falsch. */
$mg_gw = mg_mqtt_gateway_info();
$mg_gwf = ($mg_gw === null) ? 0 : (int) $mg_gw['fassung'];
?>
<?php if ($mg_gwf >= 2) { ?>
<div class="sm-hinweis"><?php echo mg_t('LOX.ABO_V2'); ?></div>
<?php } elseif ($mg_gwf === 1) { ?>
<div class="sm-warnung"><?php echo mg_t('LOX.ABO_PFLICHT'); ?></div>
<?php } else { ?>
<div class="sm-warnung"><?php echo mg_t('LOX.ABO_PFLICHT'); ?></div>
<div class="sm-hilfe"><?php echo mg_t('LOX.ABO_V2'); ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S3_TITEL')) ?></b><br><?php echo mg_t('LOX.S3'); ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.ZEILE')) ?></th><th>URL</th><th><?= mg_e(mg_t('LOX.TAKT')) ?></th><th><?= mg_e(mg_t('LOX.FELDER')) ?></th></tr>
<?php foreach ($mg_zeilen as $mg_zk => $mg_zi) { ?>
<tr><td><?= mg_e(mg_t($mg_zi['bez'])) ?></td>
	<td><span class="sm-mono"><?= mg_e(mg_endpunkt(true, 'zeile=' . $mg_zk)) ?></span></td>
	<td><?= (int) $mg_zi['takt'] ?> s</td>
	<td><?= count(mg_felder_von($mg_zk)) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo mg_t('LOX.ZEILEN_HILFE'); ?></div>
</div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S4_TITEL')) ?></b><br><?php echo mg_t('LOX.S4'); ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.FELD')) ?></th><th><?= mg_e(mg_t('LOX.CHECK')) ?></th><th><?= mg_e(mg_t('LOX.EINHEIT')) ?></th><th><?= mg_e(mg_t('LOX.BEDEUTUNG')) ?></th></tr>
<?php foreach ($mg_felder as $mg_fn => $mg_fi) { ?>
<tr><td><span class="sm-mono">MG_<?= mg_e($mg_fn) ?></span></td>
	<td><span class="sm-mono"><?= mg_e(mg_check($mg_fn)) ?></span></td>
	<td><?= mg_e($mg_fi['einheit']) ?></td>
	<td><?= mg_e(mg_t($mg_fi['bez'])) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo mg_t('LOX.CHECK_HILFE'); ?></div>
</div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S5_TITEL')) ?></b><br><?php echo mg_t('LOX.S5'); ?>
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.EIGENSCHAFT')) ?></th><th><?= mg_e(mg_t('LOX.WERT')) ?></th></tr>
<tr><td><?= mg_e(mg_t('LOX.ADRESSE')) ?></td><td><span class="sm-mono">http://<?= mg_e($mg_host) ?></span></td></tr>
<tr><td><?= mg_e(mg_t('LOX.MERKWORT')) ?></td><td><span class="sm-mono"><?= mg_e($mg_token) ?></span></td></tr>
</table>
<div class="sm-warnung"><?php echo mg_t('LOX.MERKWORT_HINWEIS'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.BEFEHL')) ?></th><th><?= mg_e(mg_t('LOX.CMDON')) ?></th><th><?= mg_e(mg_t('LOX.WIRKUNG')) ?></th></tr>
<?php foreach ($mg_cmds as $mg_k => $mg_c) {
    if (!empty($mg_c['gefahr']) && empty($mg_cfg['gefahr_ein'])) { continue; }
    if (!empty($mg_c['plan']) && empty($mg_cfg['plan_ein'])) { continue; }
    // Der Zusatz je Befehl: eine Zahl als <v>, beim freien Plan zwei Uhrzeiten
    // und ein Modus - die kann ein virtueller Ausgang nicht liefern, deshalb
    // stehen sie hier als Beispielwerte statt als Platzhalter.
    $mg_zus = '';
    if (isset($mg_c['nutzlast']) && $mg_c['nutzlast'] === 'ladeplan') {
        $mg_zus = 'von=22:00&bis=06:00&modus=until_configured_soc';
    } elseif (isset($mg_c['nutzlast']) && $mg_c['nutzlast'] === 'heizplan') {
        $mg_zus = 'von=05:30&modus=on';
    } elseif (!empty($mg_c['zusatz'])) {
        $mg_zus = $mg_c['zusatz'] . '=<v>';
    } ?>
<tr><td><span class="sm-mono"><?= mg_e($mg_k) ?></span></td>
	<td><span class="sm-mono"><?= str_replace('&amp;lt;v&amp;gt;', '&lt;v&gt;',
	    mg_e(mg_aktionsadresse($mg_k, 1, $mg_zus, false))) ?></span></td>
	<td><?= mg_e(mg_t($mg_c['bez'])) ?><?= !empty($mg_c['plan']) ? ' <b>' . mg_e(mg_t('WORT.UNERPROBT')) . '</b>' : '' ?></td></tr>
<?php } ?>
</table>
</div>
<?php if (empty($mg_cfg['gefahr_ein'])) { ?>
<div class="sm-hilfe"><?php echo mg_t('LOX.GEFAHR_AUS'); ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S6_TITEL')) ?></b><br><?php echo mg_t('LOX.S6'); ?></div>

<div class="sm-warnung"><?php echo mg_t('LOX.WARNUNG_DATEN'); ?></div>

<h2><?= mg_e(mg_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-hinweis"><?php echo mg_t('LOX.VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="vorlage" value="vi">
<div class="sm-feld">
	<label><?= mg_e(mg_t('LOX.VORLAGE_ZEILE')) ?></label>
	<select data-role="none" name="vzeile">
	<?php foreach ($mg_zeilen as $mg_zk => $mg_zi) { ?>
		<option value="<?= mg_e($mg_zk) ?>"><?= mg_e(mg_t($mg_zi['bez'])) ?> (<?= count(mg_felder_von($mg_zk)) ?>)</option>
	<?php } ?>
	</select>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('LOX.VORLAGE_FAHRZEUG')) ?></label>
	<input data-role="none" type="number" name="vnr" min="1" max="9" value="1">
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mg_e(mg_t('KNOPF.VORLAGE_VI')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="vorlage" value="vo">
<input data-role="none" type="hidden" name="vnr" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mg_e(mg_t('KNOPF.VORLAGE_VO')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="token_neu" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.TOKEN_NEU')) ?></button>
</div>
<div class="sm-hilfe"><?php echo mg_t('LOX.TOKEN_NEU_HILFE'); ?></div>
</form>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S7_TITEL')) ?></b><br>
<?php echo mg_t('LOX.S7'); ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= mg_e(mg_t('LOX.BAUSTEIN')) ?></th><th><?= mg_e(mg_t('LOX.NAME')) ?></th><th><?= mg_e(mg_t('LOX.PARAMETER')) ?></th><th><?= mg_e(mg_t('LOX.EINGAENGE')) ?></th></tr>
<?php
/* Die Nummern werden GERECHNET, nicht getippt. Verweise in den
 * Erlaeuterungen stehen als {B7} im Text und werden unten aufgeloest -
 * eine getippte Zahl verschiebt sich lautlos, sobald eine Zeile dazukommt. */
$mg_bausteine = array(
    array('LOX.B_STATUS', 'LOX.BN_KACHEL', 'LOX.BP_KACHEL', 'LOX.BE_KACHEL'),
    array('LOX.B_SWS', 'LOX.BN_LAEDT', 'LOX.BP_05', 'MG_LAEDT'),
    array('LOX.B_SWS', 'LOX.BN_STECKER', 'LOX.BP_05', 'MG_STECKER'),
    array('LOX.B_SWS', 'LOX.BN_SOCNIEDRIG', 'LOX.BP_SOC', 'MG_SOC'),
    array('LOX.B_SWS', 'LOX.BN_AUSFALL', 'LOX.BP_AUSFALL', 'MG_FZALTER'),
    array('LOX.B_SWS', 'LOX.BN_UNERREICHBAR', 'LOX.BP_INVERS', 'MG_ERREICHBAR'),
    array('LOX.B_SWS', 'LOX.BN_ZUHAUSE', 'LOX.BP_05', 'MG_ZUHAUSE'),
    array('LOX.B_UND', 'LOX.BN_SPARLADEN', 'LOX.BP_LEER', 'LOX.BE_SPARLADEN'),
    array('LOX.B_VQ', 'LOX.BN_STROM6', 'LOX.BP_STROM6', 'LOX.BE_U1'),
    array('LOX.B_VQ', 'LOX.BN_STROMMAX', 'LOX.BP_STROMMAX', 'LOX.BE_NICHTU1'),
    array('LOX.B_VQ', 'LOX.BN_LADENSTOPP', 'LOX.BP_LADENSTOPP', 'LOX.BE_TEUER'),
    array('LOX.B_VQ', 'LOX.BN_ZIEL80', 'LOX.BP_ZIEL80', 'LOX.BE_TASTER'),
    array('LOX.B_SWS', 'LOX.BN_EREIGNIS', 'LOX.BP_05', 'MG_PUSHAKTIV'),
    array('LOX.B_ODER', 'LOX.BN_SAMMLER', 'LOX.BP_SAMMLER', 'LOX.BE_SAMMLER'),
    array('LOX.B_PUSH', 'LOX.BN_PUSHAUTO', 'LOX.BP_PUSHAUTO', 'LOX.BE_SAMMLEROUT'),
    array('LOX.B_PUSH', 'LOX.BN_PUSHTEST', 'LOX.BP_PUSHTEST', 'MG_PTEST'),
    array('LOX.B_SWS', 'LOX.BN_FENSTER', 'LOX.BP_FENSTER', 'MG_FENSTEROFFEN'),
);
$mg_nummern = array();
foreach ($mg_bausteine as $mg_ix => $mg_b) {
    $mg_nummern['{B' . ($mg_ix + 1) . '}'] = (string) ($mg_ix + 1);
}
foreach ($mg_bausteine as $mg_ix => $mg_b) { ?>
<tr><td><?= $mg_ix + 1 ?></td>
	<td><?= mg_e(mg_t($mg_b[0])) ?></td>
	<td><?= mg_e(mg_t($mg_b[1])) ?></td>
	<td><?= strpos($mg_b[2], 'LOX.') === 0 ? mg_t($mg_b[2]) : mg_e($mg_b[2]) ?></td>
	<td><?= strpos($mg_b[3], 'LOX.') === 0
	        ? strtr(mg_t($mg_b[3]), $mg_nummern)
	        : '<span class="sm-mono">' . mg_e($mg_b[3]) . '</span>' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= strtr(mg_t('LOX.BAUSTEIN_HILFE'), $mg_nummern) ?></div>
</div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S8_TITEL')) ?></b><br><?php echo mg_t('LOX.S8'); ?>
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.AUFRUF')) ?></th><th><?= mg_e(mg_t('LOX.ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mg_e(mg_endpunkt(true, 'selftest=1&token=' . $mg_token)) ?></span></td><td><span class="sm-mono">SELFTEST;OK=1;TOKEN=OK</span></td></tr>
<tr><td><span class="sm-mono"><?= mg_e(mg_endpunkt(true, 'json=1')) ?></span></td><td><?= mg_e(mg_t('LOX.ERW_JSON')) ?></td></tr>
</table>
</div>
</div>

<!-- ================= Ladungen ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-ladungen' ? ' sm-active' : '' ?>" id="tab-ladungen">
<h2><?= mg_e(mg_t('LAD.H')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('LAD.HILFE'); ?></div>
<?php if (!$mg_ladungen) { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('LAD.LEER')) ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LAD.BEGINN')) ?></th><th><?= mg_e(mg_t('LAD.ENDE')) ?></th><th><?= mg_e(mg_t('LAD.DAUER')) ?></th>
	<th><?= mg_e(mg_t('LAD.SOC')) ?></th><th><?= mg_e(mg_t('LAD.KWH')) ?></th><th><?= mg_e(mg_t('LAD.KM')) ?></th>
	<th><?= mg_e(mg_t('LAD.VERBRAUCH')) ?></th><th><?= mg_e(mg_t('LAD.FZ')) ?></th></tr>
<?php foreach ($mg_ladungen as $mg_l) {
    $mg_v100 = ($mg_l['strecke'] > 0 && $mg_l['verbrauch'] > 0)
        ? round($mg_l['verbrauch'] / $mg_l['strecke'] * 100, 1) : -1; ?>
<tr><td><?= $mg_l['beginn'] !== '' ? mg_e(date('d.m.Y H:i', strtotime($mg_l['beginn']))) : '&ndash;' ?></td>
	<td><?= $mg_l['ende'] !== '' ? mg_e(date('d.m.Y H:i', strtotime($mg_l['ende']))) : '&ndash;' ?></td>
	<td><?= $mg_l['dauer_min'] >= 0 ? (int) $mg_l['dauer_min'] . ' min' : '&ndash;' ?></td>
	<td><?= mg_z($mg_l['soc_start'], ' %') ?> &rarr; <?= mg_z($mg_l['soc_ende'], ' %') ?></td>
	<td><?= mg_z($mg_l['kwh'], ' kWh') ?></td>
	<td><?= mg_z($mg_l['km'], ' km') ?></td>
	<td><?= $mg_v100 >= 0 ? mg_e(number_format($mg_v100, 1, ',', '.')) . ' kWh/100&nbsp;km' : '&ndash;' ?></td>
	<td><?= (int) $mg_l['fz'] ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-ladungen">
<input data-role="none" type="hidden" name="clearladungen" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.LADUNGEN_LEEREN')) ?></button>
</div>
</form>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= mg_e(mg_t('TEST.H_PRUEFUNG')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('TEST.PRUEFUNG_HILFE'); ?></div>
<table class="sm-tbl">
<tr><th style="width:2.5em;"></th><th><?= mg_e(mg_t('TEST.FRAGE')) ?></th><th><?= mg_e(mg_t('TEST.BEFUND')) ?></th></tr>
<?php foreach ($mg_pruefzeilen as $mg_pz) { ?>
<tr><td style="text-align:center;font-weight:700;<?= $mg_pz['ok'] === 1 ? 'color:#1a7f1a;' : ($mg_pz['ok'] === 0 ? 'color:#b00000;' : 'color:#777;') ?>">
	<?= $mg_pz['ok'] === 1 ? '&#10003;' : ($mg_pz['ok'] === 0 ? '&#10007;' : '&ndash;') ?></td>
	<td><?= mg_e(mg_t($mg_pz['bez'])) ?></td>
	<td><?= mg_e($mg_pz['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?php echo mg_t('TEST.STRICH_HILFE'); ?></div>

<h2><?= mg_e(mg_t('TEST.H_ZUSTAND')) ?></h2>
<?php if ($mg_anzahl > 1) { ?>
<div class="sm-knopfreihe">
<?php foreach ($mg_fahrzeuge as $mg_i => $mg_f) { ?>
	<a data-role="none" class="sm-btn sm-b-lesen" href="index.php?form=test&amp;fz=<?= (int) $mg_i ?>"><?= mg_e($mg_f['name']) ?></a>
<?php } ?>
</div>
<?php } ?>
<div class="sm-kacheln">
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.SOC')) ?><b><?= mg_z($mg_st['SOC'], ' %') ?></b><span class="sm-hilfe"><?= mg_z($mg_st['SOCKWH'], ' kWh') ?></span></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.ZIEL')) ?><b><?= mg_z($mg_st['ZIEL'], ' %') ?></b></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.REICHWEITE')) ?><b><?= mg_z($mg_st['REICHWEITE'], ' km') ?></b></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.LAEDT')) ?><b><?= mg_jn($mg_st['LAEDT']) ?></b><span class="sm-hilfe"><?= mg_z($mg_st['ACLEISTUNG'], ' W') ?></span></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.RESTZEIT')) ?><b><?= mg_z($mg_st['RESTZEIT'], ' min') ?></b></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.ZU')) ?><b><?= mg_jn($mg_st['ZU']) ?></b></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.ZUHAUSE')) ?><b><?= mg_jn($mg_st['ZUHAUSE']) ?></b><span class="sm-hilfe"><?= mg_z($mg_st['ENTFERNUNG'], ' km') ?></span></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.ERREICHBAR')) ?><b><?= mg_jn($mg_st['ERREICHBAR']) ?></b><span class="sm-hilfe"><?= mg_z($mg_st['FZALTER'], ' min') ?></span></div>
	<div class="sm-kachel"><?= mg_e(mg_t('FELD.ALTER')) ?><b><?= mg_z($mg_st['ALTER'], ' min') ?></b><span class="sm-hilfe"><?= (int) $mg_st['THEMEN'] ?></span></div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mg_e(mg_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>

<h3><?= mg_e(mg_t('TEST.H_ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-lesen" href="<?= mg_e(mg_endpunkt(false, 'zeile=mg&fahrzeug=' . $mg_nr)) ?>" target="_blank"><?= mg_e(mg_t('KNOPF.ZEILE')) ?></a>
	<a data-role="none" class="sm-btn sm-b-lesen" href="<?= mg_e(mg_endpunkt(false, 'json=1&fahrzeug=' . $mg_nr)) ?>" target="_blank"><?= mg_e(mg_t('KNOPF.JSON')) ?></a>
</div>

<h3><?= mg_e(mg_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-technik" href="<?= mg_e(mg_endpunkt(false, 'debug=1&token=' . rawurlencode($mg_token))) ?>" target="_blank"><?= mg_e(mg_t('KNOPF.DEBUG')) ?></a>
	<a data-role="none" class="sm-btn sm-b-technik" href="<?= mg_e(mg_endpunkt(false, 'selftest=1&token=' . rawurlencode($mg_token))) ?>" target="_blank"><?= mg_e(mg_t('KNOPF.SELFTEST')) ?></a>
	<a data-role="none" class="sm-btn sm-b-technik" href="<?= mg_e(mg_endpunkt(false, 'ladungen=1&token=' . rawurlencode($mg_token))) ?>" target="_blank"><?= mg_e(mg_t('KNOPF.LADUNGEN_JSON')) ?></a>
</div>

<h3><?= mg_e(mg_t('TEST.H_AKTION')) ?></h3>
<div class="sm-hilfe"><?php echo mg_t('TEST.AKTION_HILFE'); ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="refreshnow" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.EINLESEN')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="ptest" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.PTEST')) ?></button>
</div>
</form>

<?php if (empty($mg_cfg['commands'])) { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('TEST.BEFEHLE_GESPERRT')) ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="snr" value="<?= (int) $mg_nr ?>">
<div class="sm-feld">
	<label><?= mg_e(mg_t('TEST.ZUSATZWERT')) ?></label>
	<input data-role="none" type="text" name="swert" value="" placeholder="80">
	<div class="sm-hilfe"><?php echo mg_t('TEST.ZUSATZ_HILFE'); ?></div>
</div>
<div class="sm-knopfreihe">
<?php foreach ($mg_cmds as $mg_k => $mg_c) {
    if (!empty($mg_c['gefahr']) && empty($mg_cfg['gefahr_ein'])) { continue; }
    if (!empty($mg_c['plan']) && empty($mg_cfg['plan_ein'])) { continue; }
    // Die freie Form des Plans braucht zwei Uhrzeiten - dafuer reicht das eine
    // Feld oben nicht. Sie steht im Reiter "Einbindung in Loxone" mit Adresse.
    if (isset($mg_c['nutzlast'])
        && in_array($mg_c['nutzlast'], array('ladeplan', 'heizplan'), true)) { continue; } ?>
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sendcmd" value="<?= mg_e($mg_k) ?>"><?= mg_e(mg_t($mg_c['bez'])) ?></button>
<?php } ?>
</div>
</form>
<?php } ?>

<h2><?= mg_e(mg_t('TEST.H_ROH')) ?></h2>
<div class="sm-hilfe"><?= mg_e(mg_t('TEST.STAND')) ?>
<?= $mg_roh['zeit'] !== '' ? mg_e(date('d.m.Y H:i:s', strtotime($mg_roh['zeit']))) : '&ndash;' ?>,
<?= (int) $mg_roh['anzahl'] ?> <?= mg_e(mg_t('TEST.THEMEN')) ?></div>
<?php if ($mg_roh['anzahl'] > 0) { $mg_w = $mg_roh['werte']; ksort($mg_w); ?>
<div class="sm-log"><?php foreach (array_slice($mg_w, 0, 300, true) as $mg_tt => $mg_vv) {
    echo mg_e($mg_tt) . ' = ' . mg_e(mg_kuerzen($mg_vv, 60)) . "\n"; } ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('TEST.KEINE_WERTE')) ?></div>
<?php } ?>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= mg_e(mg_t('REITER.LOG')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('LOG.HILFE'); ?><br>
<span class="sm-mono"><?= mg_e($mg_logfile) ?></span></div>
<?php if ($mg_loglines) { ?>
<div class="sm-log"><?= mg_e(implode("\n", $mg_loglines)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('LOG.LEER')) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<input data-role="none" type="hidden" name="clearlog" value="1">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.LOG_LEEREN')) ?></button>
</div>
</form>
</div>
</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($mg_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) {
    LBWeb::lbfooter();
} else {
    echo '</body></html>';
}
