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

/* Die Bibliothek: die Wurzel wird GELESEN, der Ordnername kommt aus dem
 * eigenen Ablageort.
 *
 * Bis 1.1.14 stand hier als zweiter Kandidat der FESTE Ordnername des
 * Plugins, und der erste nahm den Ordner aus der Umgebung (LBPPLUGINDIR ist
 * am Geraet nie gesetzt, Regeln/03). Gemessen am 18.09.2026 in WSL
 * (Pruefung-MGiSmart-1.1.14, Faelle H2/H3; Bestand-2026-09-18/klasse-H,
 * M6b): als Zweitinstallation mgismart01 ohne eigene Bibliothek lud die
 * Oberflaeche die des FREMDEN gleichnamigen Plugins und arbeitete danach auf
 * dessen Konfiguration. Ohne eigene Bibliothek endet die Seite jetzt mit der
 * Meldung darunter.
 *
 * Die erste Stufe fragt $LBHOMEDIR (Regeln/03, Hausform dreistufig; wie
 * Raumklima 0.11.10): liegt die Oberflaeche in einem zweiten Baum, laedt sie
 * die Bibliothek der Anlage, die die Umgebung nennt (Fall H6). Ein
 * ausgepacktes Archiv traegt hier den Ordnernamen "htmlauth", findet unter
 * $LBHOMEDIR also nichts und laedt seine eigene (Faelle H4/H5). */
$mg_ordner = basename(__DIR__);
$mg_home = getenv('LBHOMEDIR');
/* Welche Lage gilt, entscheidet der eigene Ablageort: liegt diese Datei unter
 * .../plugins/<ordner>, ist sie installiert, sonst liegt sie in einem
 * ausgepackten Archiv und laedt ausschliesslich die eigene Bibliothek. Bis
 * 1.1.16 kam der zweite Kandidat auch aus dem Archiv an die Reihe, VOR der
 * eigenen - aus /plugin/webfrontend/htmlauth war das
 * //html/plugins/htmlauth/mg_lib.php ab der Laufwerkswurzel (in WSL im eigenen
 * Wurzelbaum gemessen, Pruefung-MGiSmart-1.1.17, Fall P2). */
if (basename(dirname(__DIR__)) === 'plugins') {
    $mg_kandidaten = array(
        ($mg_home && is_dir($mg_home))
            ? rtrim($mg_home, '/') . '/webfrontend/html/plugins/' . $mg_ordner . '/mg_lib.php'
            : '',
        dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mg_ordner . '/mg_lib.php',
    );
} else {
    $mg_kandidaten = array(dirname(__DIR__) . '/html/mg_lib.php');
}
foreach ($mg_kandidaten as $mg_kandidat) {
    if ($mg_kandidat !== '' && is_file($mg_kandidat)) {
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
/* Nr. 16 und X-2 (Verbesserungsbau 01.10.2026): welche Felder beanstandet
 * wurden (mg_formfeld() und die Handler tragen sie ein) und die Eingaben,
 * die dann mit der Einmalmeldung reisen (mg_eingaben_sammeln()). */
$mg_beanstandet = array();
$mg_eingaben = null;
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

/* Merkwort beim ersten Oeffnen erzeugen. Danach nur noch auf ausdruecklichen
 * Wunsch - es steckt in den Adressen im Miniserver.
 *
 * EIN NEUES MERKWORT DARF NUR ENTSTEHEN, WENN NEBENAN KEINE ZWEITSCHRIFT MIT
 * MERKWORT LIEGT. Ein frisch gewuerfeltes Merkwort ist ein gueltiger Wert und
 * kaeme durch jede Wache, die nur den zu schreibenden Stand ansieht; die
 * Heilung in mg_config() wiederum greift nicht, wenn auch die Zweitschrift
 * kein lesbares Objekt mehr ist (abgeschnitten) - sie traegt das alte Merkwort
 * dann aber woertlich. Gemessen an FerienFeiertage 1.2.13 und am eigenen Fall
 * "zweitschrift_kaputt" (18.09.2026, WSL). */
if (trim((string) $mg_cfg['aktionstoken']) === '') {
    $mg_gerettet = mg_token_aus_zweitschrift();
    if ($mg_gerettet !== '') {
        $mg_cfg['aktionstoken'] = $mg_gerettet;
        mg_log('Das Merkwort fehlte in der Konfiguration und wurde aus der Zweitschrift '
            . 'uebernommen - die Adressen im Miniserver bleiben gueltig.');
    } else {
        $mg_cfg['aktionstoken'] = mg_token_erzeugen();
    }
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

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken, Broker- und
 * (seit 1.1.18) iSMART-Kennwort. Ohne sie stuenden nach dem Zurueckspielen
 * alle Felder richtig, und das Plugin kaeme trotzdem nicht an die Anlage; die
 * Datei waere wertlos. Damit traegt sie Geheimnisse, und der Hinweis am Knopf
 * sagt das. Seit 1.1.18 mit lesbarem Kopf (_hinweis, _stand; Befund U7) -
 * mg_sicherung_lesen() uebergeht ihn. Wie jeder Download steht dieser Zweig
 * vor lbheader() und ohne Umleitung. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mg_sichern'])) {
    /* X-3 (Verbesserungsbau 01.10.2026): wuerde diese Sicherung das eigene
     * Zurueckspielen nicht bestehen, steht das als _warnung im Kopf - nur
     * Namen, nie Werte. Geliefert wird sie trotzdem vollstaendig; das
     * Zurueckspielen uebergeht Schluessel mit '_'. */
    $mg_sich = mg_config();
    $mg_sich_namen = mg_rueckspiel_maengel($mg_sich);
    $mg_js = json_encode(array('_hinweis' => mg_t('EINST.SICH_KOPF'), '_stand' => date('Y-m-d H:i'))
        + ($mg_sich_namen ? array('_warnung' => sprintf(mg_t('EINST.SICH_WARN_KOPF'), implode(', ', $mg_sich_namen)))
                          : array())
        + $mg_sich, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($mg_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mgismart_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $mg_js;
        exit;
    }
    $mg_fehler[] = mg_t('EINST.SICH_SCHREIBFEHLER');
    $mg_tab = 'tab-settings';
}

/* Loeschende Knoepfe verlangen einen Bestaetigungshaken (seit 1.1.18, Befund
 * U9, Regeln/04 "Formregeln fuer einen loeschenden Knopf"); ohne ihn geschieht
 * nichts. Bis 1.1.17 leerte ein einziger Klick die Ladehistorie. */
$mg_bestaetigt = !empty($_POST['bestaetigt']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    if (!$mg_bestaetigt) {
        $mg_fehler[] = mg_t('FEHLER.NICHT_BESTAETIGT');
    } else {
        if (!is_dir(dirname($mg_logfile))) { @mkdir(dirname($mg_logfile), 0775, true); }
        mg_write_atomic($mg_logfile, '[' . date('Y-m-d H:i:s') . '] '
            . mg_t('MELDUNG.LOG_GELEERT') . "\n");
        $mg_meldungen[] = mg_t('MELDUNG.LOG_GELEERT');
    }
    $mg_tab = 'tab-log';
}

/* Verwaiste Themen: gefragt wird der Broker selbst (mg_mqtt_verwaiste_lage()).
 * War er nicht zu fragen - Anmeldung abgewiesen, Filter abgelehnt, keine
 * Verbindung -, gibt es KEINE Zahl: bis 1.1.16 hiess das "0 verwaiste Themen
 * gefunden" bzw. "0 geloescht" (Pruefung-MGiSmart-1.1.17, Faelle V1, V2, V4). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwaiste_suchen'])) {
    $mg_vl = mg_mqtt_verwaiste_lage();
    if ($mg_vl['lage'] !== 'ok') {
        $mg_fehler[] = sprintf(mg_t('MELDUNG.VERWAISTE_UNBEKANNT'), $mg_vl['grund']);
    } else {
        $mg_verwaiste = $mg_vl['themen'];
        $mg_meldungen[] = sprintf(mg_t('MELDUNG.VERWAISTE_GEFUNDEN'), count($mg_verwaiste));
    }
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwaiste_loeschen'])) {
    $mg_vl = $mg_bestaetigt ? mg_mqtt_verwaiste_lage() : array('lage' => 'nicht_bestaetigt');
    if ($mg_vl['lage'] === 'nicht_bestaetigt') {
        $mg_fehler[] = mg_t('FEHLER.NICHT_BESTAETIGT');
    } elseif ($mg_vl['lage'] !== 'ok') {
        $mg_fehler[] = sprintf(mg_t('MELDUNG.VERWAISTE_UNBEKANNT'), $mg_vl['grund']);
    } else {
        list($mg_n, $mg_f) = mg_mqtt_verwaiste_loeschen(array_keys($mg_vl['themen']));
        if ($mg_f !== '') {
            $mg_fehler[] = mg_t('MELDUNG.VERWAISTE_FEHLER') . ' ' . $mg_f;
        } else {
            $mg_meldungen[] = sprintf(mg_t('MELDUNG.VERWAISTE_GELOESCHT'), $mg_n);
        }
    }
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearladungen'])) {
    if (!$mg_bestaetigt) {
        $mg_fehler[] = mg_t('FEHLER.NICHT_BESTAETIGT');
    } else {
        mg_write_json(mg_ladungen_datei(), array('liste' => array()));
        $mg_meldungen[] = mg_t('MELDUNG.LADUNGEN_GELEERT');
    }
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
    $mg_alt_praefix = trim((string) $mg_neu['mqtt_praefix'], '/ ');
    $mg_alt_ein = !empty($mg_neu['mqtt_ein']);
    $mg_f0 = count($mg_fehler);   // Nr. 16: kam etwas dazu, wird nichts gespeichert
    /* Broker: ohne eigene Angaben gelten die Daten des LoxBerry
     * (Entscheidung 7, Befund U11/C1). Eigene Angaben nur mit dem Haken
     * "eigene Broker-Angaben verwenden"; ohne ihn werden eigener Benutzer und
     * eigenes Kennwort verworfen - mg_broker_zugang() nimmt dann general.json.
     * Ohne Haken wird auch die Adresse auf 127.0.0.1 gesetzt; der Port bleibt
     * gespeichert. Mit Haken gilt ein Broker auf einem anderen Rechner auch
     * ohne Benutzer (Nacharbeit 30.09., N2). */
    if (!empty($_POST['broker_eigen'])) {
        mg_formfeld('broker_host', 'MQTTR.HOST', $mg_neu, $mg_fehler);
        mg_formfeld('broker_port', 'MQTTR.PORT', $mg_neu, $mg_fehler);
        /* Beschnitten wird am Rand - ein aus der Zwischenablage eingefuegter
         * Wert mit angehaengtem \r ergaebe sonst ein stilles Falschpasswort in
         * der Optionsdatei. Ein Zeilenumbruch oder Tabulator IM Innern wurde
         * bis 1.1.20 still herausgenommen; jetzt ist er eine Beanstandung
         * (Nr. 16, Nachtrag B: stilles Zurechtbiegen zaehlt als Beanstandung). */
        $mg_roh = (isset($_POST['broker_user']) && is_string($_POST['broker_user'])) ? $_POST['broker_user'] : '';
        if (mg_optionswert($mg_roh) !== trim($mg_roh)) {
            $mg_fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t('MQTTR.USER'), mg_t('WERT.STEUERZEICHEN'));
            $mg_beanstandet[] = 'broker_user';
        } else {
            $mg_neu['broker_user'] = mg_optionswert($mg_roh);
        }
        // Leeres Feld loescht nicht: ein gespeichertes Passwort bleibt stehen.
        $mg_roh = (isset($_POST['broker_pass']) && is_string($_POST['broker_pass'])) ? $_POST['broker_pass'] : '';
        $mg_pw = mg_optionswert($mg_roh);
        if ($mg_pw !== trim($mg_roh)) {
            $mg_fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t('MQTTR.PASS'), mg_t('WERT.STEUERZEICHEN'));
            $mg_beanstandet[] = 'broker_pass';
            $mg_pw = '';
        } elseif ($mg_pw !== '') {
            $mg_neu['broker_pass'] = $mg_pw;
        }
        if (!empty($_POST['broker_pass_loeschen'])) { $mg_neu['broker_pass'] = ''; }
        // Ohne Benutzer ist nur ein Broker auf einem ANDEREN Rechner eine
        // eigene Angabe (Nacharbeit N2); lokal gaelte ohnehin general.json.
        if ($mg_neu['broker_user'] === '' && !in_array('broker_user', $mg_beanstandet, true)) {
            // Nr. 16: ein eingetipptes Kennwort ohne Benutzer fiel bis 1.1.20 still weg.
            if ($mg_pw !== '') {
                $mg_fehler[] = mg_t('MQTTR.PASS_OHNE_BENUTZER');
                $mg_beanstandet[] = 'broker_pass';
                $mg_beanstandet[] = 'broker_user';
            }
            $mg_neu['broker_pass'] = '';
            if (mg_broker_host_lokal($mg_neu['broker_host'])) {
                $mg_fehler[] = mg_t('MQTTR.EIGEN_OHNE_BENUTZER');
                $mg_beanstandet[] = 'broker_user';
            }
        }
    } else {
        // Ohne Haken: zurueck auf den Zugang des LoxBerry - dazu gehoert auch
        // die Adresse, sonst bliebe ein fremder Host eine eigene Angabe (N2).
        $mg_neu['broker_user'] = '';
        $mg_neu['broker_pass'] = '';
        $mg_neu['broker_host'] = '127.0.0.1';
    }
    /* Abweisen statt verbiegen (Befund U8): bis 1.1.17 wurde aus
     * 127.0.0.1"x still 127.0.0.1x, aus Port 70000 der Port 65535 und aus
     * "saic test" das Praefix "saictest". Jetzt bleibt der gespeicherte
     * Wert stehen, und die Meldung nennt Feld und Grenze. */
    mg_formfeld('prefix', 'MQTTR.PREFIX', $mg_neu, $mg_fehler);
    /* Nr. 16: Steuer- und Anfuehrungszeichen im iSMART-Benutzer wurden bis
     * 1.1.20 still entfernt - jetzt Beanstandung, am Rand wird beschnitten. */
    $mg_roh = (isset($_POST['saic_user']) && is_string($_POST['saic_user'])) ? trim($_POST['saic_user']) : '';
    if (preg_match('/[\x00-\x1F\x7F"\']/', $mg_roh)) {
        $mg_fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t('MQTTR.SAIC_USER'), mg_t('WERT.ZEICHEN'));
        $mg_beanstandet[] = 'saic_user';
    } else {
        $mg_neu['saic_user'] = $mg_roh;
    }

    /* Fahrzeuge (Nr. 16, Verbesserungsbau 01.10.2026): bis 1.1.20 wurde eine
     * unbrauchbare Zeile uebergangen und der Rest gespeichert, Leer-, Steuer-
     * und Anfuehrungszeichen verschwanden still aus VIN und Name, und ein
     * Name ohne VIN fiel still weg. Jetzt ist jedes davon eine Beanstandung,
     * und gespeichert wird nichts. Eine ganz leere Zeile bleibt leer. */
    $mg_vins = array();
    $mg_namen = array();
    $mg_rohvins = isset($_POST['vin']) && is_array($_POST['vin']) ? $_POST['vin'] : array();
    $mg_rohnamen = isset($_POST['fzname']) && is_array($_POST['fzname']) ? $_POST['fzname'] : array();
    foreach ($mg_rohvins as $mg_i => $mg_v) {
        $mg_v = is_string($mg_v) ? trim($mg_v) : '';
        $mg_n = (isset($mg_rohnamen[$mg_i]) && is_string($mg_rohnamen[$mg_i])) ? trim($mg_rohnamen[$mg_i]) : '';
        if ($mg_v === '') {
            if ($mg_n !== '') {
                $mg_fehler[] = mg_t('FEHLER.FZ_OHNE_VIN') . ' ' . mg_kuerzen($mg_n, 40);
                $mg_beanstandet[] = 'vin.' . (int) $mg_i;
            }
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $mg_v)) {
            $mg_fehler[] = mg_t('FEHLER.VIN') . ' ' . mg_kuerzen($mg_v, 24);
            $mg_beanstandet[] = 'vin.' . (int) $mg_i;
            continue;
        }
        if (strlen($mg_n) > 256 || preg_match('/[\x00-\x1F\x7F"\']/', $mg_n)) {
            $mg_fehler[] = mg_t('FEHLER.FZNAME') . ' ' . mg_kuerzen($mg_n, 40);
            $mg_beanstandet[] = 'fzname.' . (int) $mg_i;
            continue;
        }
        $mg_vins[] = $mg_v;
        $mg_namen[] = $mg_n;
    }
    $mg_neu['vins'] = $mg_vins;
    $mg_neu['namen'] = $mg_namen;

    $mg_neu['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    mg_formfeld('mqtt_praefix', 'MQTTR.EIGEN_PRAEFIX', $mg_neu, $mg_fehler);

    /* Zwei verschiedene Praefixe: unter 'prefix' HORCHT das Plugin auf
     * das Gateway, unter 'mqtt_praefix' SENDET es selbst. Fallen beide
     * zusammen, loescht der Aufraeumknopf die behaltenen Themen des
     * Gateways - er raeumt ja "nur unterhalb des eigenen Praefix" auf,
     * und das ist dann derselbe Baum. Der zuletzt gespeicherte Wert
     * bleibt in diesem Fall stehen. */
    $mg_gw = trim((string) $mg_neu['prefix'], '/ ');
    $mg_ep = trim((string) $mg_neu['mqtt_praefix'], '/ ');
    if ($mg_gw !== '' && ($mg_gw === $mg_ep
            || strncmp($mg_gw, $mg_ep . '/', strlen($mg_ep) + 1) === 0)) {
        $mg_fehler[] = sprintf(mg_t('FEHLER.PRAEFIX_KOLLISION'),
                               mg_kuerzen($mg_ep, 40));
        // Nr. 16: bis 1.1.20 still der alte Wert und der Rest gespeichert.
        $mg_beanstandet[] = 'mqtt_praefix';
        $mg_beanstandet[] = 'prefix';
    }

    if (count($mg_fehler) > $mg_f0) {
        $mg_fehler[] = mg_t('FEHLER.NICHTS_GESPEICHERT');   // Nr. 16 (MQTT)
        $mg_eingaben = mg_eingaben_sammeln('mqtt', $mg_beanstandet);   // X-2
    } elseif (mg_config_save($mg_neu)) {
        $mg_meldungen[] = mg_t('MELDUNG.GESPEICHERT');
        $mg_cfg = mg_config();
        $mg_fmt = mg_formtoken($mg_cfg);
        $mg_neu_praefix = trim((string) $mg_cfg['mqtt_praefix'], '/ ');
        /* Beim Wechsel des Praefixes und beim Ausschalten: die eigenen Themen
         * unter dem ALTEN Praefix abraeumen (seit 1.1.18, Befund M6), mit
         * derselben Logik wie die Deinstallation (Broker fragen, nur Themen
         * dieses Plugins, Kollisionsschutz, nachlesen). Danach gilt der
         * Merker der Veroeffentlichung nicht mehr - der naechste Lauf sendet
         * den ganzen Satz neu. */
        if ($mg_alt_praefix !== '' && ($mg_alt_praefix !== $mg_neu_praefix
                || ($mg_alt_ein && empty($mg_cfg['mqtt_ein'])))) {
            list($mg_lrc, $mg_lz) = mg_mqtt_leeren($mg_alt_praefix);
            foreach ($mg_lz as $mg_l) {
                $mg_l = trim(preg_replace('/^<[A-Z]+>\s*/', '', $mg_l));
                if ($mg_lrc === 0) { $mg_meldungen[] = $mg_l; } else { $mg_fehler[] = $mg_l; }
            }
            foreach (glob(mg_paths()['tmp'] . '/veroeffentlicht*.json') ?: array() as $mg_merk) {
                @unlink($mg_merk);
            }
        }
        /* Die Abo-Datei des MQTT-Gateways auf das Praefix (seit 1.1.18,
         * Befund M8) - nur geschrieben, wenn sie abweicht. */
        mg_abo_datei($mg_neu_praefix, true);
    } else {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
    }
    $mg_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $mg_neu = mg_config();
    $mg_f0 = count($mg_fehler);   // Nr. 16: kam etwas dazu, wird nichts gespeichert
    /* Abweisen statt verbiegen (seit 1.1.18, Befund U8): bis 1.1.17 wurde
     * jede Zahl still in ihre Grenzen geklemmt ("abc" -> Kapazitaet 1,
     * Wartezeit 25 -> 20, Radius 5 -> 20 ...) und "Konfiguration
     * gespeichert" gemeldet. Die Meldung nennt Feld und Grenze. Seit dem
     * Verbesserungsbau 01.10.2026 (Entscheidung Nr. 16) wird bei einer
     * Beanstandung NICHTS gespeichert, auch nicht die uebrigen Felder; die
     * eingetippten Werte stehen wieder im Formular (X-2). Bis 1.1.20 wurden
     * die uebrigen gespeichert (Bestandsmessung B, index.php:494). Die
     * Regeln stehen in mg_wert_regeln() - dieselben prueft das Zurueckspielen
     * einer Sicherung. */
    mg_formfeld('capacity', 'EINST.KAPAZITAET', $mg_neu, $mg_fehler);
    $mg_neu['commands'] = isset($_POST['commands']) ? 1 : 0;
    $mg_neu['gefahr_ein'] = isset($_POST['gefahr_ein']) ? 1 : 0;
    $mg_neu['wirkung_pruefen'] = isset($_POST['wirkung_pruefen']) ? 1 : 0;
    mg_formfeld('wartezeit', 'EINST.WARTEZEIT', $mg_neu, $mg_fehler);
    mg_formfeld('befehl_abstand', 'EINST.BEFEHL_ABSTAND', $mg_neu, $mg_fehler);
    mg_formfeld('strom_abstand', 'EINST.STROM_ABSTAND', $mg_neu, $mg_fehler);
    mg_formfeld('befehle_stunde', 'EINST.BEFEHLE_STUNDE', $mg_neu, $mg_fehler);

    $mg_neu['ort_ein'] = isset($_POST['ort_ein']) ? 1 : 0;
    foreach (array('heim_breite' => 90, 'heim_laenge' => 180) as $mg_f => $mg_max) {
        $mg_w = trim(str_replace(',', '.', (string) (isset($_POST[$mg_f]) ? $_POST[$mg_f] : '')));
        if ($mg_w === '') {
            $mg_neu[$mg_f] = '';
        } elseif (preg_match('/^-?\d{1,3}(\.\d{1,8})?$/', $mg_w) && abs((float) $mg_w) <= $mg_max) {
            $mg_neu[$mg_f] = $mg_w;
        } else {
            $mg_fehler[] = mg_t('FEHLER.KOORDINATE') . ' ' . mg_kuerzen($mg_w, 20);
            $mg_beanstandet[] = $mg_f;
        }
    }
    mg_formfeld('heim_radius', 'EINST.HEIM_RADIUS', $mg_neu, $mg_fehler);

    $mg_neu['notify'] = array(
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'soc_voll' => isset($_POST['n_voll']) ? 1 : 0,
        'stecker' => isset($_POST['n_stecker']) ? 1 : 0,
        'offen' => isset($_POST['n_offen']) ? 1 : 0,
        'fenster' => isset($_POST['n_fenster']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'push_minutes' => (int) $mg_cfg['notify']['push_minutes'],
    );
    mg_formfeld('push_minutes', 'EINST.PUSH_MINUTEN', $mg_neu, $mg_fehler, 'notify.push_minutes');

    $mg_neu['ladungen_ein'] = isset($_POST['ladungen_ein']) ? 1 : 0;

    /* Lade- und Batterieheizplan. Eine unbrauchbare Uhrzeit ist eine
     * Beanstandung, und gespeichert wird nichts (Nr. 16; bis 1.1.20 wurde sie
     * uebergangen und der Rest gespeichert). Seit Entscheidung Nr. 19
     * (01.10.2026) auch ein GELEERTES Zeitfeld (bis 1.1.20 blieb still der
     * alte Wert) und Sekunden (bis 1.1.20 still abgeschnitten). */
    $mg_neu['plan_ein'] = isset($_POST['plan_ein']) ? 1 : 0;
    foreach (array('plan_von', 'plan_bis', 'heizplan_von') as $mg_f) {
        if (!isset($_POST[$mg_f]) || !is_string($_POST[$mg_f])) {
            continue;   // gehoert zu einem anderen Formular
        }
        $mg_w = $_POST[$mg_f];
        $mg_u = mg_uhrzeit($mg_w);
        if ($mg_u !== '' && $mg_u === trim($mg_w)) {
            $mg_neu[$mg_f] = $mg_u;
        } else {
            $mg_fehler[] = mg_t('FEHLER.UHRZEIT') . ' ' . mg_kuerzen($mg_w, 20);
            $mg_beanstandet[] = $mg_f;
        }
    }
    /* Nr. 16: ein unbekannter Modus blieb bis 1.1.20 still beim alten Wert. */
    mg_formfeld('plan_modus', 'EINST.PLAN_MODUS', $mg_neu, $mg_fehler);

    $mg_neu['abfahrt_ein'] = isset($_POST['abfahrt_ein']) ? 1 : 0;
    /* abfahrt_praefix: seit 1.1.17 ungenutzt (die Vorklimatisierung liest
     * termin.php des Abfahrts-Assistenten). Der Schluessel bleibt in den
     * Vorgaben und in der Datei, damit aeltere Sicherungsdateien gueltig
     * bleiben; das Formular fasst ihn nicht mehr an. */
    mg_formfeld('abfahrt_vorlauf', 'EINST.ABFAHRT_VORLAUF', $mg_neu, $mg_fehler);
    mg_formfeld('abfahrt_temp', 'EINST.ABFAHRT_TEMP', $mg_neu, $mg_fehler);
    mg_formfeld('abfahrt_fahrzeug', 'EINST.ABFAHRT_FAHRZEUG', $mg_neu, $mg_fehler);

    $mg_neu['ladeempf_ein'] = isset($_POST['ladeempf_ein']) ? 1 : 0;
    /* Nr. 16: Leer-, Steuer- und Anfuehrungszeichen im Thema wurden bis 1.1.20
     * still entfernt - jetzt Beanstandung nach der Regel des Zurueckspielens. */
    mg_formfeld('ladeempf_thema', 'EINST.LADEEMPF_THEMA', $mg_neu, $mg_fehler);
    mg_formfeld('ladeempf_grenze', 'EINST.LADEEMPF_GRENZE', $mg_neu, $mg_fehler);
    $mg_neu['ladeempf_unter'] = isset($_POST['ladeempf_unter']) ? 1 : 0;
    mg_formfeld('ladeempf_fahrzeug', 'EINST.LADEEMPF_FAHRZEUG', $mg_neu, $mg_fehler);
    // Ein unbekannter Befehl wird gemeldet (bis 1.1.17 blieb still der alte).
    mg_formfeld('ladeempf_hoch', 'EINST.LADEEMPF_HOCH', $mg_neu, $mg_fehler);
    mg_formfeld('ladeempf_runter', 'EINST.LADEEMPF_RUNTER', $mg_neu, $mg_fehler);

    if (count($mg_fehler) > $mg_f0) {
        $mg_fehler[] = mg_t('FEHLER.NICHTS_GESPEICHERT');   // Nr. 16 (Einstellungen)
        $mg_eingaben = mg_eingaben_sammeln('settings', $mg_beanstandet);   // X-2
    } elseif (mg_config_save($mg_neu)) {
        $mg_meldungen[] = mg_t('MELDUNG.GESPEICHERT');
        $mg_cfg = mg_config();
        $mg_fmt = mg_formtoken($mg_cfg);
    } else {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
    }
    $mg_tab = 'tab-settings';
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen.
 *
 * Seit 1.1.18 (Befunde C5, U2-U5): jeder Wert wird vor dem Zusammenfuehren
 * gegen Typ und Regel geprueft (mg_sicherung_lesen(), Bauart E), ein leeres
 * Merkwort heisst "kein Merkwort gesichert", und die Meldung zaehlt die
 * Werte NACH dem Zuruecklesen der gespeicherten Datei. Bis 1.1.17 meldete
 * sie "44 Werte uebernommen", auch wenn die Selbstheilung die Datei gleich
 * danach mit der Zweitschrift ueberschrieben hatte. Die Seite danach zeigt
 * das neue Merkwort, weil der Zweig wie jeder andere mit einer Umleitung
 * endet (U1) - das folgende GET liest die Konfiguration neu (C6). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mg_zurueck'])) {
    if (!isset($_FILES['mg_sicherung']) || !is_array($_FILES['mg_sicherung'])
        || !isset($_FILES['mg_sicherung']['tmp_name'])
        || !is_string($_FILES['mg_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['mg_sicherung']['tmp_name'])) {
        $mg_fehler[] = mg_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['mg_sicherung']['size'] > 262144) {
        $mg_fehler[] = mg_t('EINST.SICH_ZU_GROSS');
    } else {
        list($mg_neu, $mg_mangel, $mg_n) = mg_sicherung_lesen(
            (string) @file_get_contents($_FILES['mg_sicherung']['tmp_name']), $mg_cfg);
        if ($mg_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $mg_fehler[] = mg_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $mg_mangel);
        } elseif (mg_config_save($mg_neu)) {
            $mg_datei = mg_json_lesen(mg_paths()['config']);
            $mg_soll = $mg_neu;
            $mg_soll['vin'] = isset($mg_neu['vins'][0]) ? (string) $mg_neu['vins'][0] : '';
            $mg_gleich = 0;
            $mg_anders = array();
            foreach ($mg_soll as $mg_k => $mg_v) {
                if (array_key_exists($mg_k, $mg_datei)
                    && json_encode($mg_datei[$mg_k]) === json_encode($mg_v)) {
                    $mg_gleich++;
                } else {
                    $mg_anders[] = $mg_k;
                }
            }
            if ($mg_anders) {
                $mg_fehler[] = sprintf(mg_t('EINST.SICH_ABWEICHUNG'), count($mg_anders),
                    implode(', ', $mg_anders));
            } else {
                $mg_meldungen[] = sprintf(mg_t('EINST.SICH_UEBERNOMMEN'), $mg_gleich);
            }
            mg_log('Sicherung zurueckgespielt: ' . $mg_gleich . ' Werte nach dem Zuruecklesen gleich'
                . ($mg_anders ? ', abweichend: ' . implode(', ', $mg_anders) : '') . '.');
        } else {
            $mg_fehler[] = mg_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $mg_tab = 'tab-settings';
}

/* ---------------- Gateway einrichten (Entscheidung 7, seit 1.1.18) ----------------
 *
 * formular=gateway: iSMART-E-Mail und -Kennwort uebernehmen und den
 * Container im Hintergrund anlegen (bzw. den eigenen neu anlegen). Das
 * Kennwortfeld wird nie vorbelegt; leer heisst unveraendert, geloescht wird
 * ueber den Haken daneben (Regeln/04). */
$mg_formular = (isset($_POST['formular']) && is_string($_POST['formular'])) ? $_POST['formular'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mg_formular === 'gateway') {
    $mg_neu = mg_config();
    $mg_ok = true;
    $mg_mail = isset($_POST['saic_user']) && is_string($_POST['saic_user']) ? trim($_POST['saic_user']) : '';
    /* Die E-Mail ist zugleich ein Glied des Themenpfads <prefix>/<user>/...:
     * ein Schraegstrich, # oder + darin braeche den Pfad. Abgewiesen, nicht
     * zurechtgebogen. */
    if (!preg_match('/^[^\s@\/#+"\'<>\x00-\x1F\x7F]+@[^\s@\/#+"\'<>\x00-\x1F\x7F]+\z/', $mg_mail)) {
        $mg_fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t('GW.MAIL'), mg_t('WERT.EMAIL'));
        $mg_beanstandet[] = 'saic_user';
        $mg_ok = false;
    } else {
        $mg_neu['saic_user'] = $mg_mail;
    }
    $mg_pw = isset($_POST['saic_pass']) && is_string($_POST['saic_pass']) ? $_POST['saic_pass'] : '';
    $mg_loeschen = !empty($_POST['saic_pass_loeschen']);
    if ($mg_pw !== '') {
        list($mg_pok, $mg_pw, $mg_grund) = mg_wert_pruefen('saic_pass', $mg_pw, true);
        if ($mg_pok) {
            $mg_neu['saic_pass'] = $mg_pw;
        } else {
            $mg_fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t('GW.PASS'), $mg_grund);
            $mg_beanstandet[] = 'saic_pass';
            $mg_ok = false;
        }
    }
    if ($mg_loeschen) {
        $mg_neu['saic_pass'] = '';
    }
    /* Nr. 16 (Verbesserungsbau 01.10.2026): bis 1.1.20 wurde mit dem Haken
     * "Kennwort loeschen" auch bei beanstandeter E-Mail gespeichert. */
    if (!$mg_ok) {
        $mg_fehler[] = mg_t('FEHLER.NICHTS_GESPEICHERT');   // Nr. 16 (Gateway)
        $mg_eingaben = mg_eingaben_sammeln('gateway', $mg_beanstandet);   // X-2
    } elseif (!mg_config_save($mg_neu)) {
        $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
        $mg_ok = false;
    } elseif ($mg_loeschen) {
        $mg_meldungen[] = mg_t('GW.PASS_GELOESCHT');
        $mg_ok = false;   // ohne Kennwort wird nichts angelegt
    }
    if ($mg_ok) {
        $mg_neu = mg_config();
        if ((string) $mg_neu['saic_pass'] === '') {
            $mg_fehler[] = mg_t('GW.FEHLT_PASS');
        } else {
            list($mg_gok, $mg_gtext) = mg_gw_vorgang_starten();
            if ($mg_gok) { $mg_meldungen[] = $mg_gtext; } else { $mg_fehler[] = $mg_gtext; }
        }
    }
    $mg_tab = 'tab-gateway';
}

/* a1 (Verbesserungsbau 01.10.2026): Neustart und Entfernen laufen wie das
 * Anlegen im Hintergrundvorgang (mg_gw_vorgang_starten); die Seite antwortet
 * sofort mit der Umleitung und zeigt danach "seit N s". Bis 1.1.20 liefen sie
 * hier im Seitenaufbau, und ein haengendes Docker hielt die Seite bis etwa
 * 2 min auf. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mg_formular === 'gw_neustart') {
    list($mg_gok, $mg_gtext) = mg_gw_vorgang_starten('neustart');
    if ($mg_gok) { $mg_meldungen[] = $mg_gtext; } else { $mg_fehler[] = $mg_gtext; }
    $mg_tab = 'tab-gateway';
}

/* A4 (seit 1.1.19): das Abbild aktualisieren - im Hintergrundvorgang, nie
 * im Seitenaufruf; ein laufender Vorgang verhindert einen zweiten
 * (mg_gw_vorgang_starten()). Das Merkmal prueft der Wachposten oben, die
 * Umleitung (PRG) folgt unten wie bei jedem POST. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mg_formular === 'gw_aktualisieren') {
    list($mg_gok, $mg_gtext) = mg_gw_vorgang_starten('aktualisieren');
    if ($mg_gok) { $mg_meldungen[] = $mg_gtext; } else { $mg_fehler[] = $mg_gtext; }
    $mg_tab = 'tab-gateway';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mg_formular === 'gw_entfernen') {
    if (!$mg_bestaetigt) {
        $mg_fehler[] = mg_t('FEHLER.NICHT_BESTAETIGT');
    } else {
        list($mg_gok, $mg_gtext) = mg_gw_vorgang_starten('entfernen');   // a1
        if ($mg_gok) { $mg_meldungen[] = $mg_gtext; } else { $mg_fehler[] = $mg_gtext; }
    }
    $mg_tab = 'tab-gateway';
}

/* Nach dem ersten Erfolg: die Kennungen, die das Gateway unter
 * <prefix>/<user>/vehicles/+ meldet, als Fahrzeuge uebernehmen - statt sie aus
 * der Rohdatenliste abzuschreiben. Angehaengt, nie umsortiert: die Nummer
 * eines Fahrzeugs steckt in jeder Loxone-Adresse. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mg_formular === 'gw_vins') {
    $mg_neu = mg_config();
    $mg_dazu = mg_gw_gefundene_vins($mg_neu);
    if (!$mg_dazu) {
        $mg_fehler[] = mg_t('GW.KEINE_NEUEN_VINS');
    } else {
        foreach ($mg_dazu as $mg_v) {
            $mg_neu['namen'] = array_pad($mg_neu['namen'], count($mg_neu['vins']), '');
            $mg_neu['vins'][] = $mg_v;
            $mg_neu['namen'][] = '';
        }
        if (mg_config_save($mg_neu)) {
            $mg_meldungen[] = sprintf(mg_t('GW.VINS_UEBERNOMMEN'), implode(', ', $mg_dazu));
        } else {
            $mg_fehler[] = mg_t('FEHLER.SPEICHERN');
        }
    }
    $mg_tab = 'tab-gateway';
}

/* ==================================================================
 * JEDER POST-ZWEIG ENDET MIT EINER UMLEITUNG (seit 1.1.18, Befund U1)
 *
 * 303 auf index.php?form=<reiter>; das Ergebnis reist als Einmalmeldung
 * (mg_flash_schreiben()), die nur das folgende GET liest. Bis 1.1.17 wurde
 * die Seite unmittelbar nach dem POST gebaut - F5 wiederholte jede Aktion,
 * beim Befehlsknopf also auch einen Befehl ans Fahrzeug. Auch ein
 * abgewiesenes Formular (Wachposten) laeuft hier durch. Die Downloads
 * (Vorlagen, Sicherung) haben oben schon mit exit geendet.
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // X-2: die Eingaben reisen nur nach einer Beanstandung mit (mg_eingaben_sammeln).
    mg_flash_schreiben(array('meldungen' => $mg_meldungen, 'fehler' => $mg_fehler,
                             'verwaiste' => $mg_verwaiste)
                       + ($mg_eingaben !== null ? array('eingaben' => $mg_eingaben) : array()));
    header('Location: index.php?form=' . rawurlencode(substr($mg_tab, 4)), true, 303);
    exit;
}
/* Das GET danach: die Einmalmeldung lesen - und nur hier, nie beim POST
 * (Regeln/04: sonst verhindert ein alter Fehlertext das naechste Speichern). */
$mg_flash = mg_flash_lesen();
foreach (array('meldungen' => 'mg_meldungen', 'fehler' => 'mg_fehler') as $mg_fk => $mg_fv) {
    if (isset($mg_flash[$mg_fk]) && is_array($mg_flash[$mg_fk])) {
        foreach ($mg_flash[$mg_fk] as $mg_m) {
            if (is_string($mg_m)) { ${$mg_fv}[] = $mg_m; }
        }
    }
}
if (isset($mg_flash['eingaben'])) {
    mg_eingaben_setzen($mg_flash['eingaben']);   // X-2
}
if (isset($mg_flash['verwaiste']) && is_array($mg_flash['verwaiste'])) {
    $mg_verwaiste = $mg_flash['verwaiste'];
}

/* ---------------- Anzeige vorbereiten ---------------- */

$mg_token = (string) $mg_cfg['aktionstoken'];
$mg_notify = $mg_cfg['notify'];
$mg_fahrzeuge = mg_fahrzeuge($mg_cfg);
$mg_anzahl = count($mg_fahrzeuge);
$mg_hasmos = mg_has_mosquitto();
$mg_cmds = mg_befehle();
$mg_felder = mg_felder();
$mg_zeilen = mg_zeilen();
$mg_host = mg_host();
$mg_ver = mg_pluginversion();
$mg_loglines = mg_log_tail($mg_logfile, 300);
$mg_cronerr = mg_log_tail($mg_p['cronerr'], 20);
/* Die Ampel des Gateways: gemessen (docker, kurze Zeitgrenzen) nur, wenn der
 * Reiter Gateway oder Test serverseitig offen ist oder "Zustand neu messen"
 * gedrueckt wurde - sonst der zuletzt gemessene Stand mit Messzeit. Kein
 * docker-Aufruf im Seitenaufbau der uebrigen Reiter (Regeln/04). */
$mg_messen = isset($_GET['messen']) && $_GET['messen'] === '1';
$mg_live = ($mg_tab === 'tab-test' || $mg_tab === 'tab-gateway');
$mg_ampel = ($mg_live || $mg_messen) ? mg_gw_ampel(true, $mg_messen) : mg_gw_ampel(false);
$mg_vorgang = mg_gw_vorgang();
$mg_gwlog = null;
if ($mg_tab === 'tab-gateway' && isset($_GET['gwlog']) && $_GET['gwlog'] === '1') {
    $mg_gwlog = mg_gw_protokoll();
}
$mg_roh = mg_raw();
$mg_pruefzeilen = mg_selbsttest($mg_tab === 'tab-test');
/* A2 (seit 1.1.19): derselbe Befund wie bin/healthcheck, hier ohne eigene
 * Messung - er liest die Ampel, die oben gemessen wurde, und die Staende des
 * Takts. */
$mg_befund = mg_befund(false);
list($mg_bg_status, $mg_bg_text) = mg_befund_gesamt($mg_befund);
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

/**
 * Ein Formularfeld pruefen und uebernehmen - oder abweisen (Befund U8).
 * Fehlt das Feld in der Absendung, bleibt der Wert, wie er ist (das Feld
 * gehoert zu einem anderen Formular). Sonst: am Rand beschnitten, gegen
 * mg_wert_regeln() geprueft; passt es nicht, bleibt der gespeicherte Wert,
 * und die Beanstandung nennt Feld und Grenze.
 */
function mg_formfeld($k, $bez, array &$neu, array &$fehler, $regel = null)
{
    if (!isset($_POST[$k]) || !is_string($_POST[$k])) {
        return;
    }
    list($ok, $w, $grund) = mg_wert_pruefen($regel === null ? $k : $regel, trim($_POST[$k]), true);
    if (!$ok) {
        $fehler[] = sprintf(mg_t('FEHLER.FELD'), mg_t($bez), $grund);
        // Nr. 16/X-2: das Feld merken (die Handler laufen auf oberster Ebene).
        $GLOBALS['mg_beanstandet'][] = $k;
        return;
    }
    if ($regel === 'notify.push_minutes') {
        $neu['notify']['push_minutes'] = $w;
    } else {
        $neu[$k] = $w;
    }
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
 * ALLE Handler samt Downloads, Umleitung, dann erst lbheader(), dann HTML.
 * Seit 1.1.18 steht der Sicherungs-Download oben bei der Vorlage.
 * ================================================================== */

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
/* Zweiter eigener Zusatz (seit 1.1.18): die Punkte der Ampel im Reiter
   "Gateway einrichten". Vier feste Klassen, keine zusammengesetzte. */
.sm-ampel { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
.sm-ampel-gruen { background: #6dac20; }
.sm-ampel-gelb  { background: #e0b40d; }
.sm-ampel-rot   { background: #b00000; }
.sm-ampel-grau  { background: #9e9e9e; }
/* X-2: das beanstandete Feld nach der Umleitung (eigene Zutat, nicht aus der Vorlage). */
.sm-beanstandet { outline: 2px solid #b00000 !important; outline-offset: 1px; }
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mg_e(mg_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="save" value="1">

<h2><?= mg_e(mg_t('EINST.H_FAHRZEUG')) ?></h2>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.KAPAZITAET')) ?></label>
	<input data-role="none" type="text" name="capacity" value="<?= mg_e(mg_eingabe('settings', 'capacity', $mg_cfg['capacity'])) ?>" placeholder="61,1"<?= mg_markierung('settings', 'capacity') ?>>
	<div class="sm-hilfe"><?php echo mg_t('EINST.KAPAZITAET_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="commands" <?= mg_eingabe_an('settings', 'commands', !empty($mg_cfg['commands'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.COMMANDS')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.COMMANDS_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="gefahr_ein" <?= mg_eingabe_an('settings', 'gefahr_ein', !empty($mg_cfg['gefahr_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.GEFAHR')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.GEFAHR_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="wirkung_pruefen" <?= mg_eingabe_an('settings', 'wirkung_pruefen', !empty($mg_cfg['wirkung_pruefen'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.WIRKUNG')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.WIRKUNG_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.WARTEZEIT')) ?></label>
	<input data-role="none" type="number" name="wartezeit" min="2" max="20" value="<?= mg_e(mg_eingabe('settings', 'wartezeit', (int) $mg_cfg['wartezeit'])) ?>"<?= mg_markierung('settings', 'wartezeit') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.BEFEHL_ABSTAND')) ?></label>
	<input data-role="none" type="number" name="befehl_abstand" min="0" max="3600" value="<?= mg_e(mg_eingabe('settings', 'befehl_abstand', (int) $mg_cfg['befehl_abstand'])) ?>"<?= mg_markierung('settings', 'befehl_abstand') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.STROM_ABSTAND')) ?></label>
	<input data-role="none" type="number" name="strom_abstand" min="0" max="3600" value="<?= mg_e(mg_eingabe('settings', 'strom_abstand', (int) $mg_cfg['strom_abstand'])) ?>"<?= mg_markierung('settings', 'strom_abstand') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.BEFEHLE_STUNDE')) ?></label>
	<input data-role="none" type="number" name="befehle_stunde" min="1" max="500" value="<?= mg_e(mg_eingabe('settings', 'befehle_stunde', (int) $mg_cfg['befehle_stunde'])) ?>"<?= mg_markierung('settings', 'befehle_stunde') ?>>
	<div class="sm-hilfe"><?php echo mg_t('EINST.DROSSEL_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_ORT')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ort_ein" <?= mg_eingabe_an('settings', 'ort_ein', !empty($mg_cfg['ort_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.ORT_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.ORT_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_BREITE')) ?></label>
	<input data-role="none" type="text" name="heim_breite" value="<?= mg_e(mg_eingabe('settings', 'heim_breite', $mg_cfg['heim_breite'])) ?>" placeholder="51.3183"<?= mg_markierung('settings', 'heim_breite') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_LAENGE')) ?></label>
	<input data-role="none" type="text" name="heim_laenge" value="<?= mg_e(mg_eingabe('settings', 'heim_laenge', $mg_cfg['heim_laenge'])) ?>" placeholder="9.4896"<?= mg_markierung('settings', 'heim_laenge') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIM_RADIUS')) ?></label>
	<input data-role="none" type="number" name="heim_radius" min="20" max="20000" value="<?= mg_e(mg_eingabe('settings', 'heim_radius', (int) $mg_cfg['heim_radius'])) ?>"<?= mg_markierung('settings', 'heim_radius') ?>>
</div>

<h2><?= mg_e(mg_t('EINST.H_MELDUNGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="notify_push" <?= mg_eingabe_an('settings', 'notify_push', !empty($mg_notify['push'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_PUSH')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_voll" <?= mg_eingabe_an('settings', 'n_voll', !empty($mg_notify['soc_voll'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_VOLL')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_stecker" <?= mg_eingabe_an('settings', 'n_stecker', !empty($mg_notify['stecker'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_STECKER')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_offen" <?= mg_eingabe_an('settings', 'n_offen', !empty($mg_notify['offen'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_OFFEN')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_fenster" <?= mg_eingabe_an('settings', 'n_fenster', !empty($mg_notify['fenster'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_FENSTER')) ?></label><br>
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="n_fehler" <?= mg_eingabe_an('settings', 'n_fehler', !empty($mg_notify['fehler'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.N_FEHLER')) ?></label>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PUSH_MINUTEN')) ?></label>
	<input data-role="none" type="number" name="push_minutes" min="1" max="60" value="<?= mg_e(mg_eingabe('settings', 'push_minutes', (int) $mg_notify['push_minutes'])) ?>"<?= mg_markierung('settings', 'push_minutes') ?>>
	<div class="sm-hilfe"><?php echo mg_t('EINST.PUSH_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('EINST.H_AUTOMATIK')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="abfahrt_ein" <?= mg_eingabe_an('settings', 'abfahrt_ein', !empty($mg_cfg['abfahrt_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.ABFAHRT_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.ABFAHRT_HILFE'); ?></div>
<?php if (!mg_abfahrt_da()) { ?>
	<div class="sm-hilfe"><b><?= mg_e(mg_t('EINST.ABFAHRT_FEHLT')) ?></b></div>
<?php } ?>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_VORLAUF')) ?></label>
	<input data-role="none" type="number" name="abfahrt_vorlauf" min="1" max="180" value="<?= mg_e(mg_eingabe('settings', 'abfahrt_vorlauf', (int) $mg_cfg['abfahrt_vorlauf'])) ?>"<?= mg_markierung('settings', 'abfahrt_vorlauf') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_TEMP')) ?></label>
	<input data-role="none" type="number" name="abfahrt_temp" min="16" max="30" value="<?= mg_e(mg_eingabe('settings', 'abfahrt_temp', (int) $mg_cfg['abfahrt_temp'])) ?>"<?= mg_markierung('settings', 'abfahrt_temp') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.ABFAHRT_FAHRZEUG')) ?></label>
	<input data-role="none" type="number" name="abfahrt_fahrzeug" min="1" max="9" value="<?= mg_e(mg_eingabe('settings', 'abfahrt_fahrzeug', (int) $mg_cfg['abfahrt_fahrzeug'])) ?>"<?= mg_markierung('settings', 'abfahrt_fahrzeug') ?>>
</div>

<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladeempf_ein" <?= mg_eingabe_an('settings', 'ladeempf_ein', !empty($mg_cfg['ladeempf_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADEEMPF_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.LADEEMPF_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_THEMA')) ?></label>
	<input data-role="none" type="text" name="ladeempf_thema" value="<?= mg_e(mg_eingabe('settings', 'ladeempf_thema', $mg_cfg['ladeempf_thema'])) ?>" placeholder="pv/ueberschuss"<?= mg_markierung('settings', 'ladeempf_thema') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_GRENZE')) ?></label>
	<input data-role="none" type="text" name="ladeempf_grenze" value="<?= mg_e(mg_eingabe('settings', 'ladeempf_grenze', $mg_cfg['ladeempf_grenze'])) ?>"<?= mg_markierung('settings', 'ladeempf_grenze') ?>>
</div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladeempf_unter" <?= mg_eingabe_an('settings', 'ladeempf_unter', !empty($mg_cfg['ladeempf_unter'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADEEMPF_UNTER')) ?></label>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_HOCH')) ?></label>
	<?php $mg_sel = mg_eingabe('settings', 'ladeempf_hoch', $mg_cfg['ladeempf_hoch']); /* X-2 */ ?>
	<select data-role="none" name="ladeempf_hoch"<?= mg_markierung('settings', 'ladeempf_hoch') ?>>
	<?php foreach ($mg_cmds as $mg_k => $mg_c) { if (!empty($mg_c['zusatz']) || !empty($mg_c['gefahr'])) { continue; } ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_sel === $mg_k ? ' selected' : '' ?>><?= mg_e(mg_t($mg_c['bez'])) ?></option>
	<?php } foreach (mg_aliasse() as $mg_k => $mg_a) { ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_sel === $mg_k ? ' selected' : '' ?>><?= mg_e($mg_k) ?></option>
	<?php } ?>
	</select>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_RUNTER')) ?></label>
	<?php $mg_sel = mg_eingabe('settings', 'ladeempf_runter', $mg_cfg['ladeempf_runter']); /* X-2 */ ?>
	<select data-role="none" name="ladeempf_runter"<?= mg_markierung('settings', 'ladeempf_runter') ?>>
	<?php foreach ($mg_cmds as $mg_k => $mg_c) { if (!empty($mg_c['zusatz']) || !empty($mg_c['gefahr'])) { continue; } ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_sel === $mg_k ? ' selected' : '' ?>><?= mg_e(mg_t($mg_c['bez'])) ?></option>
	<?php } foreach (mg_aliasse() as $mg_k => $mg_a) { ?>
		<option value="<?= mg_e($mg_k) ?>"<?= $mg_sel === $mg_k ? ' selected' : '' ?>><?= mg_e($mg_k) ?></option>
	<?php } ?>
	</select>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.LADEEMPF_FAHRZEUG')) ?></label>
	<input data-role="none" type="number" name="ladeempf_fahrzeug" min="1" max="9" value="<?= mg_e(mg_eingabe('settings', 'ladeempf_fahrzeug', (int) $mg_cfg['ladeempf_fahrzeug'])) ?>"<?= mg_markierung('settings', 'ladeempf_fahrzeug') ?>>
</div>

<h2><?= mg_e(mg_t('EINST.H_PLAN')) ?></h2>
<div class="sm-warnung"><?php echo mg_t('EINST.PLAN_WARNUNG'); ?></div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="plan_ein" <?= mg_eingabe_an('settings', 'plan_ein', !empty($mg_cfg['plan_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.PLAN_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('EINST.PLAN_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_VON')) ?></label>
	<input data-role="none" type="text" name="plan_von" value="<?= mg_e(mg_eingabe('settings', 'plan_von', $mg_cfg['plan_von'])) ?>" placeholder="22:00"<?= mg_markierung('settings', 'plan_von') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_BIS')) ?></label>
	<input data-role="none" type="text" name="plan_bis" value="<?= mg_e(mg_eingabe('settings', 'plan_bis', $mg_cfg['plan_bis'])) ?>" placeholder="06:00"<?= mg_markierung('settings', 'plan_bis') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.PLAN_MODUS')) ?></label>
	<?php $mg_sel = mg_eingabe('settings', 'plan_modus', $mg_cfg['plan_modus']); /* X-2 */ ?>
	<select data-role="none" name="plan_modus"<?= mg_markierung('settings', 'plan_modus') ?>>
	<?php foreach (mg_planmodi() as $mg_pm) { ?>
		<option value="<?= mg_e($mg_pm) ?>"<?= $mg_sel === $mg_pm ? ' selected' : '' ?>><?= mg_e(mg_t('PLANMODUS.' . strtoupper($mg_pm))) ?></option>
	<?php } ?>
	</select>
	<div class="sm-hilfe"><?php echo mg_t('EINST.PLAN_MODUS_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('EINST.HEIZPLAN_VON')) ?></label>
	<input data-role="none" type="text" name="heizplan_von" value="<?= mg_e(mg_eingabe('settings', 'heizplan_von', $mg_cfg['heizplan_von'])) ?>" placeholder="05:30"<?= mg_markierung('settings', 'heizplan_von') ?>>
</div>

<h2><?= mg_e(mg_t('EINST.H_LADUNGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="ladungen_ein" <?= mg_eingabe_an('settings', 'ladungen_ein', !empty($mg_cfg['ladungen_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('EINST.LADUNGEN_EIN')) ?></label>
</div>

<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= mg_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= mg_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= mg_t('EINST.SICH_WARNUNG') ?></div>
<?php $mg_rueck = mg_rueckspiel_maengel($mg_cfg); /* X-3 */ ?>
<?php if ($mg_rueck) { ?>
<div class="sm-warnung"><?= sprintf(mg_t('EINST.SICH_WARN_RUECK'), mg_e(implode(', ', $mg_rueck))) ?></div>
<?php } ?>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="mqtt_save" value="1">

<h2><?= mg_e(mg_t('MQTTR.H_BROKER')) ?></h2>
<?php
/* U11 (seit 1.1.18): ohne eigene Angaben gelten die Broker-Daten des
 * LoxBerry (mg_broker_zugang()). Angezeigt wird, woher sie kommen und mit
 * welchem Benutzer - das Kennwort erscheint nie im HTML, auch nicht das des
 * LoxBerry. */
$mg_zugang = mg_broker_zugang($mg_cfg);
$mg_eigen = ($mg_zugang['quelle'] === 'eigen');
?>
<div class="sm-hinweis"><?php if ($mg_zugang['quelle'] === 'loxberry') {
    echo mg_e(sprintf(mg_t('MQTTR.UEBERNOMMEN'), $mg_zugang['user'] !== '' ? $mg_zugang['user'] : '–',
        $mg_zugang['host'], (int) $mg_zugang['port']));
} elseif ($mg_eigen) {
    echo mg_e(sprintf(mg_t('MQTTR.EIGENE_GELTEN'), $mg_zugang['user'] !== '' ? $mg_zugang['user'] : '–',
        $mg_zugang['host'], (int) $mg_zugang['port']));
} else {
    echo mg_e(sprintf(mg_t('MQTTR.KEINE_GENERAL'), $mg_zugang['host'], (int) $mg_zugang['port']));
} ?></div>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="broker_eigen" value="1" <?= mg_eingabe_an('mqtt', 'broker_eigen', $mg_eigen) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('MQTTR.EIGEN_HAKEN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.EIGEN_HAKEN_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.HOST')) ?></label>
	<input data-role="none" type="text" name="broker_host" value="<?= mg_e(mg_eingabe('mqtt', 'broker_host', $mg_cfg['broker_host'])) ?>" placeholder="127.0.0.1"<?= mg_markierung('mqtt', 'broker_host') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PORT')) ?></label>
	<input data-role="none" type="number" name="broker_port" min="1" max="65535" value="<?= mg_e(mg_eingabe('mqtt', 'broker_port', (int) $mg_cfg['broker_port'])) ?>"<?= mg_markierung('mqtt', 'broker_port') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.USER')) ?></label>
	<input data-role="none" type="text" name="broker_user" value="<?= mg_e(mg_eingabe('mqtt', 'broker_user', $mg_cfg['broker_user'])) ?>"<?= mg_markierung('mqtt', 'broker_user') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PASS')) ?></label>
	<input data-role="none" type="password" name="broker_pass" value=""<?= mg_markierung('mqtt', 'broker_pass') ?> placeholder="<?= $mg_cfg['broker_pass'] !== '' ? mg_e(mg_t('MQTTR.PASS_GESPEICHERT')) : mg_e(mg_t('MQTTR.PASS_LEER')) ?>">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:6px;">
	<input data-role="none" type="checkbox" name="broker_pass_loeschen" value="1"<?= mg_eingabe_an('mqtt', 'broker_pass_loeschen', false) ? ' checked' : '' ?>>
	<?= mg_e(mg_t('MQTTR.PASS_LOESCHEN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.BROKER_HILFE'); ?></div>
</div>

<h2><?= mg_e(mg_t('MQTTR.H_THEMEN')) ?></h2>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.PREFIX')) ?></label>
	<input data-role="none" type="text" name="prefix" value="<?= mg_e(mg_eingabe('mqtt', 'prefix', $mg_cfg['prefix'])) ?>" placeholder="saic"<?= mg_markierung('mqtt', 'prefix') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.SAIC_USER')) ?></label>
	<input data-role="none" type="text" name="saic_user" value="<?= mg_e(mg_eingabe('mqtt', 'saic_user', $mg_cfg['saic_user'])) ?>" placeholder="name@example.org"<?= mg_markierung('mqtt', 'saic_user') ?>>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.PFAD_HILFE'); ?>
	<span class="sm-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>/&lt;<?= mg_e(mg_t('MQTTR.BENUTZER')) ?>&gt;/vehicles/&lt;VIN&gt;</span></div>
</div>

<h3><?= mg_e(mg_t('MQTTR.H_FAHRZEUGE')) ?></h3>
<div class="sm-hilfe"><?php echo mg_t('MQTTR.FAHRZEUGE_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= mg_e(mg_t('MQTTR.VIN')) ?></th><th><?= mg_e(mg_t('MQTTR.FZNAME')) ?></th><th><?= mg_e(mg_t('MQTTR.GEFUNDEN')) ?></th></tr>
<?php
/* X-2: nach einer Beanstandung die eingetippten Zeilen (vin, fzname), das
 * beanstandete Feld je Zeile markiert (vin.<n>, fzname.<n>). */
$mg_ev = mg_eingabe_liste('mqtt', 'vin');
$mg_en = mg_eingabe_liste('mqtt', 'fzname');
for ($mg_i = 0; $mg_i < max(3, $mg_anzahl + 1, $mg_ev !== null ? count($mg_ev) : 0); $mg_i++) {
    $mg_v = isset($mg_cfg['vins'][$mg_i]) ? $mg_cfg['vins'][$mg_i] : '';
    $mg_n = isset($mg_cfg['namen'][$mg_i]) ? $mg_cfg['namen'][$mg_i] : '';
    $mg_vz = ($mg_ev !== null) ? (isset($mg_ev[$mg_i]) ? $mg_ev[$mg_i] : '') : $mg_v;
    $mg_nz = ($mg_en !== null) ? (isset($mg_en[$mg_i]) ? $mg_en[$mg_i] : '') : $mg_n; ?>
<tr><td><?= $mg_i + 1 ?></td>
	<td><input data-role="none" type="text" name="vin[]" value="<?= mg_e($mg_vz) ?>" placeholder="LSJ..."<?= mg_markierung('mqtt', 'vin.' . $mg_i) ?>></td>
	<td><input data-role="none" type="text" name="fzname[]" value="<?= mg_e($mg_nz) ?>" placeholder="MG <?= $mg_i + 1 ?>"<?= mg_markierung('mqtt', 'fzname.' . $mg_i) ?>></td>
	<td><?= $mg_v !== '' ? (int) mg_themen_anzahl($mg_i + 1) : '&ndash;' ?></td></tr>
<?php } ?>
</table>
</div>

<h2><?= mg_e(mg_t('MQTTR.H_EIGEN')) ?></h2>
<div class="sm-feld">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
	<input data-role="none" type="checkbox" name="mqtt_ein" <?= mg_eingabe_an('mqtt', 'mqtt_ein', !empty($mg_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
	<?= mg_e(mg_t('MQTTR.EIGEN_EIN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('MQTTR.EIGEN_HILFE'); ?></div>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('MQTTR.EIGEN_PRAEFIX')) ?></label>
	<input data-role="none" type="text" name="mqtt_praefix" value="<?= mg_e(mg_eingabe('mqtt', 'mqtt_praefix', $mg_cfg['mqtt_praefix'])) ?>" placeholder="mg"<?= mg_markierung('mqtt', 'mqtt_praefix') ?>>
</div>
<div class="sm-hinweis"><b><?= mg_e(mg_t('MQTTR.ABO_TITEL')) ?></b><br>
<span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ')) ?>/#</span><br>
<?php echo mg_abo_text(); ?></div>

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
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
<input data-role="none" type="checkbox" name="bestaetigt" value="1">
<?= mg_e(mg_t('BESTAETIGEN.VERWAISTE')) ?></label>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.VERWAISTE_LOESCHEN')) ?></button>
</div>
</form>

<h2><?= mg_e(mg_t('MQTTR.H_TABELLE')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('MQTTR.TABELLE_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('MQTTR.THEMA')) ?></th><th><?= mg_e(mg_t('MQTTR.BEDEUTUNG')) ?></th><th><?= mg_e(mg_t('MQTTR.RETAIN')) ?></th></tr>
<?php foreach (mg_mqtt_themen() as $mg_th => $mg_bez) { ?>
<tr><td><span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ') . '/' . $mg_th) ?></span></td>
	<td><?= mg_e(mg_t($mg_bez)) ?></td>
	<td><?= mg_e(mg_t(mg_mqtt_behalten($mg_th, '1') ? 'WORT.JA' : 'WORT.NEIN')) ?></td></tr>
<?php }
/* Das Lebenszeichen (seit 1.1.18, Befund M3): je Lauf, nie zurueckbehalten.
 * mg_mqtt_behalten() fragt dieselbe Liste wie die Sendefunktion. */
foreach (mg_mqtt_status_themen() as $mg_th => $mg_bez) { ?>
<tr><td><span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ') . '/' . $mg_th) ?></span></td>
	<td><?= mg_e(mg_t($mg_bez)) ?></td>
	<td><?= mg_e(mg_t(mg_mqtt_behalten($mg_th, '1') ? 'WORT.JA' : 'WORT.NEIN')) ?></td></tr>
<?php }
/* A1 (seit 1.1.19): die Anmeldung bei MG, bei jedem Takt fluechtig (1, 0 oder
 * "-"). mg_mqtt_behalten() fragt dieselbe Liste wie die Sendezeile. */
$mg_anm_thema = mg_mqtt_anmeldung_thema($mg_cfg);
if ($mg_anm_thema !== '') { ?>
<tr><td><span class="sm-mono"><?= mg_e($mg_anm_thema) ?></span></td>
	<td><?= mg_e(mg_t('MQTT.ANMELDUNG')) ?></td>
	<td><?= mg_e(mg_t(mg_mqtt_behalten($mg_anm_thema, '1') ? 'WORT.JA' : 'WORT.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Gateway einrichten ================= -->
<?php
/* Seit 1.1.18 (Entscheidung 7, Bauliste G1): das Plugin legt den Container
 * selbst an. Kein docker-Befehl zum Abtippen mehr. Die Farben der Ampel sind
 * feste Klassen (Regeln/04: CSS-Klassen woertlich, nicht zusammengesetzt). */
$mg_farbe = array('gruen' => 'sm-ampel sm-ampel-gruen', 'gelb' => 'sm-ampel sm-ampel-gelb',
                  'rot' => 'sm-ampel sm-ampel-rot', 'grau' => 'sm-ampel sm-ampel-grau');
$mg_vlaeuft = in_array($mg_vorgang['zustand'], array('gestartet', 'laeuft'), true);
$mg_docker_weg = ($mg_ampel !== null && in_array($mg_ampel['docker'], array('fehlt', 'kein_zugriff'), true));
$mg_eigener = ($mg_ampel !== null && !empty($mg_ampel['eigen']));
?>
<div class="sm-seite<?= $mg_tab === 'tab-gateway' ? ' sm-active' : '' ?>" id="tab-gateway">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mg_e(mg_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<?php if ($mg_vlaeuft && $mg_tab === 'tab-gateway') { ?>
<meta http-equiv="refresh" content="5;url=index.php?form=gateway">
<?php } ?>
<h2><?= mg_e(mg_t('GW.H_WARUM')) ?></h2>
<p class="sm-hilfe"><?php echo mg_t('GW.WARUM'); ?></p>

<h2><?= mg_e(mg_t('GW.H_AMPEL')) ?></h2>
<?php if ($mg_vlaeuft) { ?>
<div class="sm-hinweis"><?= mg_e(sprintf(mg_gw_vorgang_text('seit', isset($mg_vorgang['vorgang']) ? $mg_vorgang['vorgang'] : ''),
    max(0, time() - (int) $mg_vorgang['start']))) ?></div>
<?php } elseif ($mg_vorgang['zustand'] === 'fertig' && time() - (int) (isset($mg_vorgang['ende']) ? $mg_vorgang['ende'] : 0) < 3600) { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('GW.VORGANG_FERTIG') . ' ' . $mg_vorgang['meldung']) ?></div>
<?php } elseif ($mg_vorgang['zustand'] === 'fehler' || $mg_vorgang['zustand'] === 'abgebrochen') { ?>
<div class="sm-warnung"><?= mg_e(mg_t($mg_vorgang['zustand'] === 'fehler' ? 'GW.VORGANG_FEHLER' : 'GW.VORGANG_ABGEBROCHEN')
    . ' ' . $mg_vorgang['meldung']) ?></div>
<?php } ?>
<?php if ($mg_ampel === null) { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('GW.A_UNGEMESSEN')) ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:2.5em;"></th><th><?= mg_e(mg_t('GW.A_ZEILE')) ?></th><th><?= mg_e(mg_t('TEST.BEFUND')) ?></th></tr>
<?php foreach (array('container' => 'GW.A_CONTAINER', 'anmeldung' => 'GW.A_ANMELDUNG', 'werte' => 'GW.A_WERTEZEILE') as $mg_ak => $mg_ab) {
    $mg_af = isset($mg_farbe[$mg_ampel[$mg_ak][0]]) ? $mg_farbe[$mg_ampel[$mg_ak][0]] : $mg_farbe['grau']; ?>
<tr><td style="text-align:center;"><i class="<?= $mg_af ?>"></i></td>
	<td><?= mg_e(mg_t($mg_ab)) ?></td>
	<td><?= mg_e($mg_ampel[$mg_ak][1]) ?></td></tr>
<?php } ?>
</table>
<?php /* b1 (seit 1.1.23): return code 4 beim Statusabruf ist kein Anmeldefehler. */
if (!empty($mg_ampel['fahrzeug_satz'])) { ?>
<div class="sm-hinweis"><?= mg_e($mg_ampel['fahrzeug_satz']) ?></div>
<?php } ?>
<div class="sm-hilfe"><?= mg_e(sprintf(mg_t('GW.A_MESSZEIT'), date('d.m.Y H:i:s', (int) $mg_ampel['zeit']))) ?>
<?= mg_e(mg_t('GW.A_HILFE')) ?></div>
<div class="sm-hilfe"><?= mg_e(sprintf(mg_t('GW.A_FASSUNG'),
    (isset($mg_ampel['fassung']) && $mg_ampel['fassung'] !== '') ? $mg_ampel['fassung'] : mg_t('WORT.UNBEKANNT'),
    (isset($mg_ampel['bild']) && $mg_ampel['bild'] !== '') ? $mg_ampel['bild'] : '-',
    (isset($mg_ampel['digest']) && $mg_ampel['digest'] !== '') ? $mg_ampel['digest'] : '-')) ?></div>
<?php if (isset($mg_ampel['mg_anmeldung']) && $mg_ampel['mg_anmeldung'] === 0) { ?>
<div class="sm-warnung"><?php echo mg_t('GW.ANM_HINWEIS'); ?></div>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-lesen" href="#mg-gw-zugang"><?= mg_e(mg_t('GW.K_ANM_NEU')) ?></a>
</div>
<?php } ?>
<?php } ?>
<div class="sm-hilfe"><?= mg_e(sprintf(mg_t('BEFUND.GESAMT'), mg_t('BEFUND.STATUS_' . $mg_bg_status), $mg_bg_text)) ?></div>

<?php if ($mg_docker_weg) { ?>
<div class="sm-warnung"><?php echo mg_t($mg_ampel['docker'] === 'fehlt' ? 'GW.VOR_FEHLT' : 'GW.VOR_KEIN_ZUGRIFF'); ?></div>
<?php } ?>

<h2 id="mg-gw-zugang"><?= mg_e(mg_t('GW.H_ZUGANG')) ?></h2>
<?php if ($mg_vlaeuft) { ?>
<div class="sm-hinweis"><?= mg_e(mg_gw_vorgang_text('laeuft', isset($mg_vorgang['vorgang']) ? $mg_vorgang['vorgang'] : '')) ?></div>
<?php } elseif (!$mg_docker_weg) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-gateway">
<input data-role="none" type="hidden" name="formular" value="gateway">
<div class="sm-feld">
	<label><?= mg_e(mg_t('GW.MAIL')) ?></label>
	<input data-role="none" type="email" name="saic_user" value="<?= mg_e(mg_eingabe('gateway', 'saic_user', $mg_cfg['saic_user'])) ?>" placeholder="name@example.org" autocomplete="off"<?= mg_markierung('gateway', 'saic_user') ?>>
</div>
<div class="sm-feld">
	<label><?= mg_e(mg_t('GW.PASS')) ?></label>
	<input data-role="none" type="password" name="saic_pass" value=""<?= mg_markierung('gateway', 'saic_pass') ?> autocomplete="new-password" placeholder="<?= (string) $mg_cfg['saic_pass'] !== '' ? mg_e(mg_t('MQTTR.PASS_GESPEICHERT')) : mg_e(mg_t('GW.PASS_LEER')) ?>">
	<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;margin-top:6px;">
	<input data-role="none" type="checkbox" name="saic_pass_loeschen" value="1"<?= mg_eingabe_an('gateway', 'saic_pass_loeschen', false) ? ' checked' : '' ?>>
	<?= mg_e(mg_t('GW.PASS_LOESCHEN')) ?></label>
	<div class="sm-hilfe"><?php echo mg_t('GW.ZUGANG_HILFE'); ?></div>
</div>
<div class="sm-hilfe"><?php echo mg_t('GW.ANLEGEN_HINWEIS'); ?></div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t($mg_eigener ? 'GW.K_NEU' : 'GW.K_ANLEGEN')) ?></button>
</div>
</form>
<?php } ?>

<h2><?= mg_e(mg_t('GW.H_CONTAINER')) ?></h2>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-lesen" href="index.php?form=gateway&amp;messen=1"><?= mg_e(mg_t('GW.K_MESSEN')) ?></a>
	<a data-role="none" class="sm-btn sm-b-technik" href="index.php?form=gateway&amp;gwlog=1"><?= mg_e(mg_t('GW.K_PROTOKOLL')) ?></a>
</div>
<?php if ($mg_gwlog !== null) { ?>
<?php if ($mg_gwlog[0]) { ?>
<div class="sm-log"><?= mg_e($mg_gwlog[1] !== '' ? $mg_gwlog[1] : mg_t('GW.LOGS_LEER')) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= mg_e($mg_gwlog[1]) ?></div>
<?php } ?>
<?php } ?>
<?php if (!$mg_vlaeuft) { /* a1: waehrend eines Vorgangs kein zweiter */ ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-gateway">
<input data-role="none" type="hidden" name="formular" value="gw_neustart">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('GW.K_NEUSTART')) ?></button>
</div>
</form>
<?php } ?>
<?php if ($mg_eigener && !$mg_vlaeuft) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-gateway">
<input data-role="none" type="hidden" name="formular" value="gw_aktualisieren">
<div class="sm-hilfe"><?php echo mg_t('GW.AKT_HILFE'); ?></div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('GW.K_AKTUALISIEREN')) ?></button>
</div>
</form>
<?php } ?>
<?php if (!$mg_vlaeuft) { /* a1 */ ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-gateway">
<input data-role="none" type="hidden" name="formular" value="gw_entfernen">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
<input data-role="none" type="checkbox" name="bestaetigt" value="1">
<?= mg_e(mg_t('BESTAETIGEN.GATEWAY')) ?></label>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('GW.K_ENTFERNEN')) ?></button>
</div>
</form>
<?php } ?>

<?php $mg_neue_vins = mg_gw_gefundene_vins($mg_cfg);
if ($mg_neue_vins) { ?>
<h3><?= mg_e(mg_t('GW.H_GEFUNDEN')) ?></h3>
<div class="sm-hinweis"><?= mg_e(sprintf(mg_t('GW.GEFUNDEN'), implode(', ', $mg_neue_vins))) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-gateway">
<input data-role="none" type="hidden" name="formular" value="gw_vins">
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('GW.K_VINS')) ?></button>
</div>
</form>
<?php } ?>

<details class="sm-step"><summary><b><?= mg_e(mg_t('GW.H_FORTGESCHRITTEN')) ?></b></summary>
<div class="sm-hilfe"><?php echo mg_t('GW.FORTGESCHRITTEN'); ?></div>
<?php
/* Nur zum Lesen: was das Plugin anlegt. Die Kennwoerter maskiert, nie im
 * HTML (Bauliste G1/G2). */
list($mg_eok, $mg_env) = mg_gw_env($mg_cfg, $mg_zugang);
$mg_envzeilen = array();
if ($mg_eok) {
    foreach (explode("\n", trim($mg_env)) as $mg_ez) {
        $mg_et = explode('=', $mg_ez, 2);
        if (in_array($mg_et[0], array('SAIC_PASSWORD', 'MQTT_PASSWORD'), true)) {
            $mg_ez = $mg_et[0] . '=' . sprintf(mg_t('GW.MASKIERT'), strlen(isset($mg_et[1]) ? $mg_et[1] : ''));
        }
        $mg_envzeilen[] = $mg_ez;
    }
}
?>
<div class="sm-log"><?= mg_e(mg_t('GW.F_BILD') . ' ' . MG_GW_BILD . "\n"
    . mg_t('GW.F_NAME') . ' ' . mg_gw_name() . "\n"
    . mg_t('GW.F_NETZ') . " host\n"
    . mg_t('GW.F_NEUSTART') . " unless-stopped\n"
    . mg_t('GW.F_LABELS') . ' de.loxberry.plugin.folder=' . $mg_p['plugin'] . ', de.loxberry.plugin.name=mgismart, de.loxberry.plugin.def=' . MG_GW_DEF . ', de.loxberry.plugin.brokerhash=' . mg_t('GW.F_HASH') . "\n"
    . mg_t('GW.F_VARIABLEN') . "\n  "
    . ($mg_envzeilen ? implode("\n  ", $mg_envzeilen) : $mg_env)) ?></div>
</details>

<div class="sm-step"><b><?= mg_e(mg_t('GW.S5_TITEL')) ?></b>
<div class="sm-warnung"><?php echo mg_t('GW.S5_WARNUNG'); ?></div>
<div class="sm-hilfe"><?php echo mg_t('GW.S5_HINWEIS'); ?></div>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= mg_e(mg_t('LOX.H')) ?></h2>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S1_TITEL')) ?></b><br><?php echo mg_t('LOX.S1'); ?></div>

<div class="sm-step"><b><?= mg_e(mg_t('LOX.S2_TITEL')) ?></b><br><?php echo mg_t('LOX.S2'); ?>
<table class="sm-tbl">
<tr><th><?= mg_e(mg_t('LOX.ABO')) ?></th><th><?= mg_e(mg_t('LOX.WANN')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mg_e(trim((string) $mg_cfg['mqtt_praefix'], '/ ')) ?>/#</span></td><td><?= mg_e(mg_t('LOX.ABO_EIGEN')) ?></td></tr>
<tr><td><span class="sm-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>/#</span></td><td><?= mg_e(mg_t('LOX.ABO_ROH')) ?></td></tr>
</table>
<div class="sm-warnung"><?php echo mg_t('LOX.ABO_ROH_WARNUNG'); ?></div>
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
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
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-ladungen">
<input data-role="none" type="hidden" name="clearladungen" value="1">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
<input data-role="none" type="checkbox" name="bestaetigt" value="1">
<?= mg_e(mg_t('BESTAETIGEN.LADUNGEN')) ?></label>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mg_e(mg_t('KNOPF.LADUNGEN_LEEREN')) ?></button>
</div>
</form>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $mg_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mg_e(mg_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= mg_e(mg_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<?php
/* Bauliste G9: steht eine Zeile der Gateway-Ampel auf Rot, sagt es der
 * Pruefungsreiter oben - mit dem Weg dorthin, wo man es beheben kann. */
$mg_ampel_rot = false;
if ($mg_ampel !== null) {
    foreach (array('container', 'anmeldung', 'werte') as $mg_ak) {
        if (isset($mg_ampel[$mg_ak][0]) && $mg_ampel[$mg_ak][0] === 'rot') { $mg_ampel_rot = true; }
    }
}
if ($mg_ampel_rot) { ?>
<div class="sm-warnung"><?= mg_e(mg_t('TEST.AMPEL_ROT')) ?>
<a href="index.php?form=gateway"><?= mg_e(mg_t('REITER.GATEWAY')) ?></a></div>
<?php } ?>
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

<h3><?= mg_e(mg_t('TEST.H_BEFUND')) ?></h3>
<div class="sm-hilfe"><?php echo mg_t('TEST.BEFUND_HILFE'); ?></div>
<table class="sm-tbl">
<tr><th style="width:2.5em;"></th><th><?= mg_e(mg_t('TEST.FRAGE')) ?></th><th><?= mg_e(mg_t('TEST.BEFUND')) ?></th></tr>
<?php foreach ($mg_befund as $mg_bf) {
    $mg_bs = $mg_bf['status'];
    $mg_bfarbe = $mg_bs === 5 ? 'color:#1a7f1a;' : ($mg_bs === 3 ? 'color:#b00000;' : ($mg_bs === 4 ? 'color:#b36b00;' : 'color:#777;'));
    $mg_bzeichen = $mg_bs === 5 ? '&#10003;' : ($mg_bs === 3 ? '&#10007;' : ($mg_bs === 4 ? '!' : ($mg_bs === 6 ? 'i' : '&ndash;'))); ?>
<tr><td style="text-align:center;font-weight:700;<?= $mg_bfarbe ?>"><?= $mg_bzeichen ?></td>
	<td><?= mg_e(mg_t($mg_bf['bez'])) ?></td>
	<td><?= mg_e($mg_bf['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= mg_e(sprintf(mg_t('BEFUND.GESAMT'), mg_t('BEFUND.STATUS_' . $mg_bg_status), $mg_bg_text)) ?></div>

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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mg_e(mg_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= mg_e(mg_t('REITER.LOG')) ?></h2>
<div class="sm-hilfe"><?php echo mg_t('LOG.HILFE'); ?><br>
<span class="sm-mono"><?= mg_e($mg_logfile) ?></span></div>
<?php if ($mg_loglines) { ?>
<div class="sm-log"><?= mg_e(implode("\n", $mg_loglines)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= mg_e(mg_t('LOG.LEER')) ?></div>
<?php } ?>
<?php if ($mg_cronerr) { ?>
<h3><?= mg_e(mg_t('LOG.CRONERR')) ?></h3>
<div class="sm-hilfe"><span class="sm-mono"><?= mg_e($mg_p['cronerr']) ?></span></div>
<div class="sm-log"><?= mg_e(implode("\n", $mg_cronerr)) ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= mg_e($mg_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<input data-role="none" type="hidden" name="clearlog" value="1">
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
<input data-role="none" type="checkbox" name="bestaetigt" value="1">
<?= mg_e(mg_t('BESTAETIGEN.LOG')) ?></label>
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
