<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie MG iSmart (Gruppe 39, 1.1.28).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2)
 * abonniert <mqtt_praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt mg.php - mg_befehl_annehmen() in mg_lib.php, Quelle 'mqtt' - und gibt array(ok,
 * grund) fuer die Quittung zurueck (mg_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht; an seiner Stelle
 * stehen die Haken "Befehle ueber MQTT annehmen" (befehle_mqtt_ein) und, fuer die eingreifenden Befehle, "Eingreifende
 * Befehle ueber MQTT annehmen" (befehle_mqtt_steuern) ZUSAMMEN mit "Eingreifende Befehle erlauben" (gefahr_ein) -
 * alle ab Werk aus (E-E2: auch Entriegeln und Kofferraum; UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar).
 *
 * Quittungsart "uebergeben": die Bruecke wartet nie (mg_send($warten = false)) - angenommen heisst, der Befehl ging
 * an den Broker des Gateways (ABGESETZT), die Gleichwert-Bremse X-7 meldete UNVERAENDERT oder der Zielzustand lag
 * bei frischem Stand schon an (SCHON_SO). Die Wirkung zeigt der naechste Minutenlauf.
 *
 * Sperrzeiten (Bauvorschrift 2.2): Sollwerte und Zustaende 0 s - die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt
 * die eine Pruefung (UNVERAENDERT, also angenommen); Ereignisse 60 s wie die Drossel 'abstand'; finden_stopp 5 s.
 * Fahrzeug 1 unter <p>/befehl/<name>, Fahrzeug 2-9 unter <p>/befehl/<n>/<name> (Teil {nr}).
 * Regeln (Entwurf gemeinsam 2.5): die Datei setzt keine Konfiguration und schreibt nichts; wie der unangemeldete
 * Endpunkt legt die Bruecke nichts an (mg_nur_lesen(true)).
 */
if (!function_exists('mg_befehl_annehmen')) {
    $mg_kand = array();
    $mg_kand[] = 'REPLACELBPHTMLDIR/mg_lib.php';
    $mg_kand[] = dirname(__DIR__) . '/webfrontend/html/mg_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $mg_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/mg_lib.php';
    }
    foreach ($mg_kand as $mg_k) {
        if (strpos($mg_k, 'REPLACE') !== 0 && is_file($mg_k)) {
            require_once $mg_k;
            break;
        }
    }
}
$mg_grund_soll = 'Die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt die eine Pruefung; sie antwortet UNVERAENDERT, '
    . 'also angenommen (Bauvorschrift Gruppe 39, 2.2).';
$mg_grund_stopp = 'Ein Stopp muss wiederholbar sein - wie die Drossel abstand 5 s des Plugins (Bauvorschrift Gruppe 39, 2.2).';
/* Ein Befehl der Bruecke -> (Befehl, Zusatzwert) fuer mg_send(). */
$mg_tun = function ($wie) {
    return function ($w, $t) use ($wie) {
        mg_nur_lesen(true);
        $nr = isset($t['nr']) ? (int) $t['nr'] : 1;
        list($befehl, $wert) = $wie((string) $w);
        if ($befehl === 'aussentemp') {
            return mg_befehl_quittung(mg_aussentemp_merken($wert));
        }
        return mg_befehl_quittung(mg_befehl_annehmen($befehl, $wert, $nr, 'mqtt'));
    };
};
$mg_fest = function ($b) { return function ($w) use ($b) { return array($b, null); }; };
$mg_zahl = function ($b, $feld) { return function ($w) use ($b, $feld) { return array($b, array($feld => $w)); }; };
$mg_schalter = function ($an, $aus) { return function ($w) use ($an, $aus) { return array($w === '1' ? $an : $aus, null); }; };
/* Name => array(Art, eingreifend, sperre_s|null fuer die Vorgabe 60, Titel in Loxone, Kachel, Zusatz, Aufruf) */
$mg_def = array(
    'auffrischen'    => array('puls', false, null, 'MG Werte abfragen (MQTT)', 'BEFEHL.K_AUFFRISCHEN', array(), $mg_fest('auffrischen')),
    'laden'          => array('schalter', false, 0, 'MG Laden (MQTT)', 'BEFEHL.K_LADEN', array(), $mg_schalter('laden_start', 'laden_stopp')),
    'ziel'           => array('ganz', false, 0, 'MG Ziel-Ladestand (MQTT)', 'BEFEHL.K_ZIEL', array('min' => 40, 'max' => 100), $mg_zahl('ziel', 'prozent')),
    'strom'          => array('ganz', false, 0, 'MG Ladestromgrenze (MQTT)', 'BEFEHL.K_STROM', array('min' => 6, 'max' => 32), $mg_zahl('strom', 'ampere')),
    'klima_an'       => array('puls', false, 0, 'MG Standklima ein (MQTT)', 'BEFEHL.K_KLIMA_AN', array(), $mg_fest('klima_an')),
    'klima_aus'      => array('puls', false, 0, 'MG Standklima aus (MQTT)', 'BEFEHL.K_KLIMA_AUS', array(), $mg_fest('klima_aus')),
    'klima_vorn'     => array('puls', false, 0, 'MG Standklima vorn (MQTT)', 'BEFEHL.K_KLIMA_VORN', array(), $mg_fest('klima_vorn')),
    'klima_geblaese' => array('puls', false, 0, 'MG Nur Gebläse (MQTT)', 'BEFEHL.K_KLIMA_GEBLAESE', array(), $mg_fest('klima_geblaese')),
    'klimatemp'      => array('ganz', false, 0, 'MG Klima-Zieltemperatur (MQTT)', 'BEFEHL.K_KLIMATEMP', array('min' => 16, 'max' => 30), $mg_zahl('klimatemp', 'temp')),
    'sitzheizung_l'  => array('ganz', false, 0, 'MG Sitzheizung links (MQTT)', 'BEFEHL.K_SITZ_L', array('min' => 0, 'max' => 3), $mg_zahl('sitzheizung_l', 'stufe')),
    'sitzheizung_r'  => array('ganz', false, 0, 'MG Sitzheizung rechts (MQTT)', 'BEFEHL.K_SITZ_R', array('min' => 0, 'max' => 3), $mg_zahl('sitzheizung_r', 'stufe')),
    'heckscheibe'    => array('schalter', false, 0, 'MG Heckscheibenheizung (MQTT)', 'BEFEHL.K_HECKSCHEIBE', array(), $mg_schalter('heckscheibe_an', 'heckscheibe_aus')),
    'frontscheibe'   => array('schalter', false, 0, 'MG Frontscheibenheizung (MQTT)', 'BEFEHL.K_FRONTSCHEIBE', array(), $mg_schalter('frontscheibe_an', 'frontscheibe_aus')),
    'batterieheizung' => array('schalter', false, 0, 'MG Batterieheizung (MQTT)', 'BEFEHL.K_BATTHEIZ', array(), $mg_schalter('batterieheizung_an', 'batterieheizung_aus')),
    'abfrage_modus'  => array('auswahl', false, 0, 'MG Abfragemodus (MQTT)', 'BEFEHL.K_ABFRAGE_MODUS', array('werte' => array('periodic', 'off', 'charging_detection')), $mg_zahl('abfrage_modus', 'modus')),
    'abfrage_ruhe'   => array('ganz', false, 0, 'MG Abfragetakt Ruhe (MQTT)', 'BEFEHL.K_ABFRAGE_RUHE', array('min' => 300, 'max' => 604800), $mg_zahl('abfrage_ruhe', 'sekunden')),
    'abfrage_aktiv'  => array('ganz', false, 0, 'MG Abfragetakt aktiv (MQTT)', 'BEFEHL.K_ABFRAGE_AKTIV', array('min' => 30, 'max' => 86400), $mg_zahl('abfrage_aktiv', 'sekunden')),
    'ladeplan'       => array('schalter', false, 0, 'MG Ladeplan (MQTT)', 'BEFEHL.K_LADEPLAN', array(), $mg_schalter('ladeplan_ein', 'ladeplan_aus')),
    'heizplan'       => array('schalter', false, 0, 'MG Batterieheizplan (MQTT)', 'BEFEHL.K_HEIZPLAN', array(), $mg_schalter('heizplan_ein', 'heizplan_aus')),
    'finden'         => array('puls', true, null, 'MG Auto finden (MQTT)', 'BEFEHL.K_FINDEN', array(), $mg_fest('finden')),
    'finden_licht'   => array('puls', true, null, 'MG Auto finden Licht (MQTT)', 'BEFEHL.K_FINDEN_LICHT', array(), $mg_fest('finden_licht')),
    'finden_hupe'    => array('puls', true, null, 'MG Auto finden Hupe (MQTT)', 'BEFEHL.K_FINDEN_HUPE', array(), $mg_fest('finden_hupe')),
    'finden_stopp'   => array('puls', true, 5, 'MG Auto finden stoppen (MQTT)', 'BEFEHL.K_FINDEN_STOPP', array(), $mg_fest('finden_stopp')),
    'verriegeln'     => array('puls', true, null, 'MG Verriegeln (MQTT)', 'BEFEHL.K_VERRIEGELN', array(), $mg_fest('verriegeln')),
    // E-E2 (Hausherr 28.10.2026, abweichend von der Empfehlung): auch Entriegeln und Kofferraum ueber MQTT.
    'entriegeln'     => array('puls', true, null, 'MG Entriegeln (MQTT)', 'BEFEHL.K_ENTRIEGELN', array(), $mg_fest('entriegeln')),
    'kofferraum_auf' => array('puls', true, null, 'MG Kofferraum öffnen (MQTT)', 'BEFEHL.K_KOFFERRAUM', array(), $mg_fest('kofferraum_auf')),
);
$mg_befehle = array();
foreach (array('' => '', '{nr}/' => ' Fahrzeug N') as $mg_vor => $mg_zus) {
    foreach ($mg_def as $mg_name => $mg_d) {
        $mg_e = array('art' => $mg_d[0], 'eingreifend' => $mg_d[1],
                      'titel' => $mg_zus === '' ? $mg_d[3] : preg_replace('/^MG /', 'MG Fahrzeug N ', $mg_d[3]),
                      'kachel' => $mg_d[4], 'quittung' => 'uebergeben', 'tun' => $mg_tun($mg_d[6]));
        if ($mg_d[2] !== null) {
            $mg_e['sperre_s'] = $mg_d[2];
            $mg_e['begruendung'] = $mg_d[2] === 0 ? $mg_grund_soll : $mg_grund_stopp;
        }
        $mg_befehle[$mg_vor . $mg_name] = $mg_e + $mg_d[5];
    }
}
/* E-E4: die Aussentemperatur der Hauswetterstation aus Loxone - nur einmal, gilt fuer alle Fahrzeuge (30 min). */
$mg_befehle['aussentemp'] = array('art' => 'zahl', 'eingreifend' => false, 'min' => -40, 'max' => 60,
    'sperre_s' => 0, 'begruendung' => 'Ein Messwert: jeder neue Wert zaehlt, auch ein gleicher (frischt das Alter auf).',
    'titel' => 'MG Außentemperatur aus Loxone (MQTT)', 'kachel' => 'BEFEHL.K_AUSSENTEMP', 'quittung' => 'uebergeben',
    'tun' => $mg_tun(function ($w) { return array('aussentemp', $w); }));
return array(
    'fassung' => 1,
    'linie'   => 'mgismart',
    'plugin'  => 'MG',
    'geladen' => function_exists('mg_befehl_annehmen') && function_exists('mg_befehl_quittung')
                 && function_exists('mg_config') && function_exists('mg_aussentemp_merken'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        mg_nur_lesen(true);   // wie der unangemeldete Endpunkt: nichts anlegen, nichts heilen
        $c = mg_config();
        return array('praefix'     => trim((string) $c['mqtt_praefix'], '/ '),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']),
                     // E-E2: eingreifend nur mit dem zweiten Haken UND "Eingreifende Befehle erlauben"
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']) && !empty($c['gefahr_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        mg_log('Befehlsbruecke: ' . ($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $mg_befehle,
);
