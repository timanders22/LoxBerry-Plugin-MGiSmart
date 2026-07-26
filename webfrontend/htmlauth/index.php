<?php
/**
 * MG iSmart - Admin-Oberflaeche (v1.0.0)
 * Reiter: Einstellungen | Gateway einrichten | Einbindung in Loxone | Test | Protokoll
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein mg_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$mg_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$mg_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($mg_lbhome && is_dir($mg_lbhome . '/config/plugins/' . $mg_plugin) === false) {
    $mg_plugin = basename(dirname(__DIR__));
    if (is_dir($mg_lbhome . '/config/plugins/' . $mg_plugin) === false) {
        $mg_plugin = 'mgismart';
    }
}
if ($mg_lbhome) {
    $mg_sdk = $mg_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($mg_sdk)) {
        require_once $mg_sdk;
        require_once $mg_lbhome . '/libs/phplib/loxberry_web.php';
    }
    $mg_logfile = $mg_lbhome . '/log/plugins/' . $mg_plugin . '/mg.log';
} else {
    $mg_logfile = sys_get_temp_dir() . '/mgismart/mg.log';
}

foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mg_plugin . '/mg_lib.php',
    dirname(__DIR__) . '/html/mg_lib.php',
) as $mg_cand) {
    if (is_file($mg_cand)) { require_once $mg_cand; break; }
}

$mg_saved = false; $mg_note = ''; $mg_err = '';
$mg_tab = preg_match('/^tab-(settings|gateway|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mg_logfile), 0775, true);
    @file_put_contents($mg_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $mg_tab = 'tab-log';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refreshnow']) && function_exists('mg_snapshot')) {
    list($mg_ok, $mg_info) = mg_snapshot(4);
    $mg_note = $mg_ok ? ('Werte eingelesen: ' . $mg_info . '.') : ('Keine Werte empfangen: ' . $mg_info);
    $mg_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sendcmd']) && function_exists('mg_send')) {
    list($mg_ok, $mg_info) = mg_send(preg_replace('/[^a-z0-9_]/', '', (string) $_POST['sendcmd']));
    $mg_note = $mg_ok ? ('Befehl gesendet: ' . $mg_info) : ('Befehl fehlgeschlagen: ' . $mg_info);
    $mg_tab = 'tab-test';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && function_exists('mg_config')) {
    $mg_new = mg_config();
    $mg_new['broker_host'] = trim((string) (isset($_POST['broker_host']) ? $_POST['broker_host'] : '127.0.0.1'));
    $mg_new['broker_port'] = max(1, min(65535, (int) (isset($_POST['broker_port']) ? $_POST['broker_port'] : 1883)));
    $mg_new['broker_user'] = trim((string) (isset($_POST['broker_user']) ? $_POST['broker_user'] : ''));
    $mg_pw = (string) (isset($_POST['broker_pass']) ? $_POST['broker_pass'] : '');
    if ($mg_pw !== '') { $mg_new['broker_pass'] = $mg_pw; }
    $mg_new['prefix'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['prefix']) ? $_POST['prefix'] : 'saic')) ?: 'saic';
    $mg_new['saic_user'] = trim((string) (isset($_POST['saic_user']) ? $_POST['saic_user'] : ''));
    $mg_new['vin'] = trim((string) (isset($_POST['vin']) ? $_POST['vin'] : ''));
    $mg_new['capacity'] = max(1, min(200, (float) str_replace(',', '.', (string) (isset($_POST['capacity']) ? $_POST['capacity'] : 61.1))));
    $mg_new['commands'] = isset($_POST['commands']) ? 1 : 0;
    $mg_new['notify'] = array(
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'soc_voll' => isset($_POST['n_voll']) ? 1 : 0,
        'stecker' => isset($_POST['n_stecker']) ? 1 : 0,
        'offen' => isset($_POST['n_offen']) ? 1 : 0,
        'push_minutes' => max(1, min(60, (int) (isset($_POST['push_minutes']) ? $_POST['push_minutes'] : 5))),
    );
    if (mg_config_save($mg_new)) { $mg_saved = true; } else { $mg_err = 'Konfiguration konnte nicht gespeichert werden.'; }
}

$mg_cfg = function_exists('mg_config') ? mg_config() : array();
if (!is_array($mg_cfg)) { $mg_cfg = array(); }
$mg_notify = is_array($mg_cfg['notify']) ? $mg_cfg['notify'] : array();
$mg_notify += array('push' => 1, 'soc_voll' => 1, 'stecker' => 1, 'offen' => 1, 'push_minutes' => 5);
$mg_st = function_exists('mg_state') ? mg_state() : array();
$mg_roh = function_exists('mg_raw') ? mg_raw() : array('zeit' => '', 'anzahl' => 0, 'werte' => array());
$mg_hasmos = function_exists('mg_has_mosquitto') ? mg_has_mosquitto() : false;
$mg_cmds = function_exists('mg_commands') ? mg_commands() : array();

$mg_loglines = array();
if (is_file($mg_logfile)) {
    $mg_loglines = array_slice(array_reverse(file($mg_logfile, FILE_IGNORE_NEW_LINES) ?: array()), 0, 300);
}
$mg_host = $_SERVER['HTTP_HOST'] ?: 'loxberry';

function mg_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function mg_z($v, $einheit = '', $ung = '&ndash;') { return $v === null || $v < 0 ? $ung : (rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',') . $einheit); }

if (class_exists('LBWeb')) {
    LBWeb::lbheader('MG iSmart', 'https://github.com/SAIC-iSmart-API/saic-python-mqtt-gateway', '');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>MG iSmart</title></head><body>';
}
?>
<style>
.mgw { max-width: 1100px; margin: 0 auto; padding: 0 10px 40px; font-size: 0.95em; }
.mgw h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; text-shadow: none; }
.mgw label { display: block; font-weight: 600; margin: 8px 0 2px; }
.mgw input[type=text], .mgw input[type=password], .mgw input[type=number], .mgw select {
    width: 100%; padding: 7px 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; background: #fff; }
.mgw .mg-row { display: flex; gap: 14px; flex-wrap: wrap; }
.mgw .mg-row > div { flex: 1 1 210px; }
.mgw .mg-small { color: #666; font-size: 0.88em; line-height: 1.45; }
.mgw .mg-mono { font-family: monospace; background: #f4f4f4; padding: 1px 5px; border-radius: 4px; word-break: break-all; }
.mgw .mg-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 8px; padding: 9px 18px;
    cursor: pointer; text-decoration: none; font-size: 0.95em; text-shadow: none !important; display: inline-block; margin: 3px 4px 3px 0; }
.mgw .mg-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.mgw .mg-ok { background: #e8f5e9; border: 1px solid #6dac20; }
.mgw .mg-warn { background: #fff8e1; border: 1px solid #ffb300; }
.mgw .mg-err { background: #ffebee; border: 1px solid #c62828; }
.mgw .mg-info { background: #eef4fb; border: 1px solid #90a4ae; }
.mgw .mg-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.mgw .mg-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px;
    cursor: pointer; color: #444 !important; text-shadow: none !important; }
.mgw .mg-tab.mg-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.mgw .mg-pane { display: none; padding-top: 4px; }
.mgw .mg-pane.mg-active { display: block; }
.mgw .mg-tbl { border-collapse: collapse; margin: 6px 0 10px; width: 100%; }
.mgw .mg-tbl th, .mgw .mg-tbl td { border: 1px solid #ddd; padding: 5px 9px; text-align: left; vertical-align: top; }
.mgw .mg-tbl th { background: #f4f4f4; }
.mgw .mg-log, .mgw .mg-code { background: #263238; color: #cfd8dc; font-family: monospace; font-size: 0.82em;
    padding: 10px; border-radius: 8px; max-height: 460px; overflow: auto; white-space: pre-wrap; box-shadow: none; }
.mgw .mg-step { border-left: 4px solid #6dac20; padding: 4px 0 4px 12px; margin: 12px 0; }
.mgw .mg-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.mgw .mg-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.mgw .mg-kachel b { display: block; font-size: 1.35em; color: #33691e; }
</style>
<div class="mgw">
<h1 style="color:#6dac20;text-shadow:none;">MG iSmart</h1>
<div class="mg-small">Bringt Ladestand, Reichweite, Ladestatus und Klima eines MG-Elektroautos nach Loxone
&mdash; und schickt Befehle zur&uuml;ck. Die Daten liefert das quelloffene <b>SAIC-MQTT-Gateway</b>;
dieses Plugin liest sie vom MQTT-Broker und &uuml;bersetzt sie f&uuml;r den Miniserver.</div>

<?php if ($mg_saved) { ?><div class="mg-alert mg-ok"><b>Konfiguration gespeichert.</b></div><?php } ?>
<?php if ($mg_err !== '') { ?><div class="mg-alert mg-err"><?= mg_e($mg_err) ?></div><?php } ?>
<?php if ($mg_note !== '') { ?><div class="mg-alert <?= strpos($mg_note, 'fehlgeschlagen') !== false || strpos($mg_note, 'Keine') === 0 ? 'mg-err' : 'mg-ok' ?>"><?= mg_e($mg_note) ?></div><?php } ?>
<?php if (!$mg_hasmos) { ?>
<div class="mg-alert mg-err"><b>Das Paket <span class="mg-mono">mosquitto-clients</span> fehlt.</b>
Ohne <span class="mg-mono">mosquitto_sub</span> kann das Plugin keine Werte lesen. Am LoxBerry per SSH:<br>
<span class="mg-mono">sudo apt-get update &amp;&amp; sudo apt-get install -y mosquitto-clients</span></div>
<?php } ?>
<?php if (trim((string) $mg_cfg['saic_user']) === '' || trim((string) $mg_cfg['vin']) === '') { ?>
<div class="mg-alert mg-warn"><b>Noch nicht eingerichtet.</b> Zuerst den Reiter <b>Gateway einrichten</b> abarbeiten,
dann hier unten Benutzername und Fahrzeug-ID eintragen.</div>
<?php } ?>

<div class="mg-tabs">
    <div class="mg-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="mg-tab" data-pane="tab-gateway">Gateway einrichten</div>
    <div class="mg-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="mg-tab" data-pane="tab-test">Test</div>
    <div class="mg-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Einstellungen ================= -->
<div class="mg-pane" id="tab-settings">
<form method="post">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>MQTT-Broker</h2>
<div class="mg-row">
    <div>
        <label>Adresse</label>
        <input data-role="none" type="text" name="broker_host" value="<?= mg_e($mg_cfg['broker_host']) ?>" placeholder="127.0.0.1">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="broker_port" value="<?= (int) $mg_cfg['broker_port'] ?>" min="1" max="65535">
    </div>
    <div>
        <label>Benutzer (optional)</label>
        <input data-role="none" type="text" name="broker_user" value="<?= mg_e($mg_cfg['broker_user']) ?>">
    </div>
    <div>
        <label>Passwort (optional)</label>
        <input data-role="none" type="password" name="broker_pass" value="" placeholder="<?= $mg_cfg['broker_pass'] !== '' ? 'gespeichert &mdash; leer lassen = unver&auml;ndert' : 'nur falls der Broker eines verlangt' ?>">
    </div>
</div>
<div class="mg-small">Bei LoxBerry ist das der eingebaute Mosquitto: <span class="mg-mono">127.0.0.1:1883</span>.
Benutzer und Passwort stehen unter System-Einstellungen &rarr; MQTT Gateway.</div>

<h2>Fahrzeug</h2>
<div class="mg-row">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="prefix" value="<?= mg_e($mg_cfg['prefix']) ?>" placeholder="saic">
    </div>
    <div>
        <label>iSMART-Benutzername</label>
        <input data-role="none" type="text" name="saic_user" value="<?= mg_e($mg_cfg['saic_user']) ?>" placeholder="name@example.org">
    </div>
    <div>
        <label>Fahrzeug-ID / VIN</label>
        <input data-role="none" type="text" name="vin" value="<?= mg_e($mg_cfg['vin']) ?>" placeholder="LSJ…">
    </div>
    <div>
        <label>Nutzbare Kapazit&auml;t (kWh)</label>
        <input data-role="none" type="text" name="capacity" value="<?= mg_e($mg_cfg['capacity']) ?>" placeholder="61,1">
    </div>
</div>
<div class="mg-small">Benutzername und Fahrzeug-ID bilden zusammen den Themenpfad des Gateways:
<span class="mg-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>/&lt;Benutzer&gt;/vehicles/&lt;Fahrzeug-ID&gt;/…</span><br>
Wer die Fahrzeug-ID nicht kennt: Im Reiter <b>Test</b> auf &bdquo;Werte jetzt einlesen&ldquo; klicken &mdash; die Rohliste
zeigt alle Themen, dort steht sie drin. Das <b>iSMART-Passwort wird hier nicht gebraucht</b>; es kennt nur der
Gateway-Container. Die Kapazit&auml;t dient der Umrechnung von Prozent in kWh.</div>

<h2>Steuerung</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="commands" <?= !empty($mg_cfg['commands']) ? 'checked' : '' ?>> Steuerbefehle ans Fahrzeug erlauben
</label>
<div class="mg-small">Ausgeschaltet arbeitet das Plugin nur lesend. Empfohlen bleibt <b>ein</b> &mdash; ohne Befehle
gibt es kein Laden nach Spotpreis oder PV-&Uuml;berschuss. Es werden ausschlie&szlig;lich die in der Liste
hinterlegten Befehle gesendet; etwas anderes nimmt der Endpunkt nicht an.</div>

<h2>Benachrichtigungen</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="notify_push" <?= !empty($mg_notify['push']) ? 'checked' : '' ?>> Push-Freigabe an Loxone melden
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_voll" <?= !empty($mg_notify['soc_voll']) ? 'checked' : '' ?>> Ziel-Ladestand erreicht
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_stecker" <?= !empty($mg_notify['stecker']) ? 'checked' : '' ?>> Ladekabel ein-/ausgesteckt
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_offen" <?= !empty($mg_notify['offen']) ? 'checked' : '' ?>> Auto steht unverschlossen
</label>
<div class="mg-row" style="margin-top:6px;">
    <div style="max-width:260px;">
        <label>Meldefenster (Minuten)</label>
        <input data-role="none" type="number" name="push_minutes" value="<?= (int) $mg_notify['push_minutes'] ?>" min="1" max="60">
    </div>
</div>
<div class="mg-small">Tritt eines der Ereignisse ein, steht <span class="mg-mono">PUSHAKTIV=1</span> f&uuml;r diese Zeit.
Den Push verschickt der Miniserver &mdash; so landet er in der Loxone-App.</div>

<div style="margin-top:16px;"><button data-role="none" class="mg-btn" type="submit">Speichern</button></div>
</form>
</div>

<!-- ================= Gateway einrichten ================= -->
<div class="mg-pane" id="tab-gateway">
<h2>Warum ein zus&auml;tzlicher Container?</h2>
<p>MG/SAIC bietet keine offene Schnittstelle an. Die App spricht mit den Servern &uuml;ber ein verschl&uuml;sseltes,
tokenbasiertes Protokoll. Das quelloffene Projekt <b>SAIC MQTT Gateway</b> bildet dieses Protokoll nach, meldet sich
mit deinem iSMART-Konto an und ver&ouml;ffentlicht die Fahrzeugdaten per MQTT. Dieses Plugin setzt darauf auf &mdash;
eine Neuimplementierung in PHP w&auml;re aufwendig und w&uuml;rde bei jeder &Auml;nderung der Gegenstelle brechen.</p>

<div class="mg-step"><b>Schritt 1: Docker auf den LoxBerry bringen</b><br>
Daf&uuml;r gibt es ein fertiges Plugin: <b>Docker</b> (von Michael Miklis). Es installiert die
Container-Umgebung und zus&auml;tzlich <b>Portainer</b> &mdash; eine Oberfl&auml;che, in der sich Container
anklicken statt eintippen lassen. Nach der Installation erscheint Docker als eigener Punkt in der
LoxBerry-Oberfl&auml;che.
<div class="mg-small" style="margin-top:6px;">Ohne dieses Plugin geht es auch, dann muss Docker von Hand
installiert werden. Mit ist es deutlich bequemer &mdash; besonders, weil Portainer sp&auml;ter anzeigt,
ob der Container l&auml;uft und was er zuletzt gemeldet hat.</div>
</div>

<div class="mg-step"><b>Schritt 2: Weitere Voraussetzungen</b><br>
MQTT Gateway aktiv (LoxBerry &rarr; System-Einstellungen &rarr; MQTT Gateway),
iSMART-Konto vorhanden und das Fahrzeug dort registriert. Das Passwort ist dasselbe wie in der Handy-App.
</div>

<div class="mg-step"><b>Schritt 3: Container anlegen</b><br>
Entweder in Portainer &uuml;ber <i>Add container</i> oder am LoxBerry per SSH (Beispiel &mdash; Benutzer, Passwort und Broker-Adresse anpassen):
<div class="mg-code">docker run -d --name saic-gateway --restart unless-stopped \
  -e SAIC_USER="name@example.org" \
  -e SAIC_PASSWORD="IHR-ISMART-PASSWORT" \
  -e SAIC_REST_URI="https://gateway-mg-eu.soimt.com/api.app/v1/" \
  -e SAIC_REGION="eu" \
  -e MQTT_URI="tcp://<?= mg_e($mg_cfg['broker_host'] ?: '127.0.0.1') ?>:<?= (int) ($mg_cfg['broker_port'] ?: 1883) ?>" \
  -e MQTT_TOPIC="<?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>" \
  -e HA_DISCOVERY_ENABLED="False" \
  -e BATTERY_CAPACITY_MAPPING="IHRE-VIN=<?= mg_e($mg_cfg['capacity']) ?>" \
  saicismartapi/saic-python-mqtt-gateway</div>
<div class="mg-small">Verlangt der Broker eine Anmeldung, zus&auml;tzlich
<span class="mg-mono">-e MQTT_USER=… -e MQTT_PASSWORD=…</span> angeben.
Die Zeile <span class="mg-mono">HA_DISCOVERY_ENABLED=False</span> spart die Home-Assistant-Themen, die hier niemand braucht.</div>
</div>

<div class="mg-step"><b>Schritt 4: Kontrolle</b><br>
<span class="mg-mono">docker logs -f saic-gateway</span> zeigt den Anmeldevorgang.
Danach im Reiter <b>Test</b> auf &bdquo;Werte jetzt einlesen&ldquo; klicken &mdash; es sollten mehrere Dutzend Themen erscheinen.
Die Fahrzeug-ID aus der Rohliste in die Einstellungen &uuml;bernehmen.
</div>

<div class="mg-step"><b>Schritt 5: Abfrageintervalle &mdash; bitte lesen</b>
<div class="mg-alert mg-warn"><b>Das Abfragen weckt die Fahrzeugelektronik.</b> Die Entwickler des Gateways warnen
ausdr&uuml;cklich davor, das Ruheintervall (Standard: einmal t&auml;glich) zu verk&uuml;rzen &mdash; sonst leidet die
<b>12-Volt-Batterie</b>. W&auml;hrend der Fahrt und beim Laden fragt das Gateway von selbst h&auml;ufiger ab.
Braucht man zwischendurch frische Werte, ist der Befehl <span class="mg-mono">Fahrzeugstatus jetzt abfragen</span>
der richtige Weg &mdash; gezielt statt dauerhaft.</div>
<div class="mg-small">Zwei weitere Eigenheiten: Meldet sich die iSMART-App an, pausiert das Gateway rund 15 Minuten,
weil SAIC nur eine Sitzung gleichzeitig erlaubt. Und <b>&bdquo;Laden starten&ldquo; gilt als unzuverl&auml;ssig</b>,
w&auml;hrend &bdquo;Laden stoppen&ldquo; zuverl&auml;ssig funktioniert. Wer &uuml;ber die Wallbox schaltet, ist auf der
sicheren Seite; die Ladestrombegrenzung des Autos wirkt dagegen gut.</div>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="mg-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>

<div class="mg-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;MG iSmart&ldquo;</b> (Abfrage 300 s)
<table class="mg-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="mg-mono">http://<?= mg_e($mg_host) ?>/plugins/<?= mg_e($mg_plugin) ?>/mg.php</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
<div class="mg-small">Der Abruf beim Plugin kostet nichts &mdash; er liest nur die zuletzt empfangenen MQTT-Werte.
Wie oft das <i>Auto</i> gefragt wird, bestimmt allein das Gateway (siehe Reiter Gateway einrichten).</div>
</div>

<div class="mg-step"><b>Schritt 2: Befehlserkennungen</b>
<table class="mg-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="mg-mono">\iSOC=\i\v</span></td><td><b>Ladestand in %</b></td></tr>
<tr><td><span class="mg-mono">\iSOCKWH=\i\v</span></td><td>Ladestand in kWh (aus der Kapazit&auml;t gerechnet)</td></tr>
<tr><td><span class="mg-mono">\iZIEL=\i\v</span></td><td>eingestellter Ziel-Ladestand in %</td></tr>
<tr><td><span class="mg-mono">\iREICHWEITE=\i\v</span></td><td>Restreichweite in km</td></tr>
<tr><td><span class="mg-mono">\iLAEDT=\i\v</span></td><td>1 = l&auml;dt gerade</td></tr>
<tr><td><span class="mg-mono">\iSTECKER=\i\v</span></td><td>1 = Ladekabel steckt</td></tr>
<tr><td><span class="mg-mono">\iLEISTUNG=\i\v</span></td><td>Ladeleistung in kW</td></tr>
<tr><td><span class="mg-mono">\iRESTZEIT=\i\v</span></td><td>verbleibende Ladezeit in Minuten</td></tr>
<tr><td><span class="mg-mono">\iVOLL=\i\v</span></td><td>1 = Ziel-Ladestand erreicht</td></tr>
<tr><td><span class="mg-mono">\iZU=\i\v</span> / <span class="mg-mono">\iKOFFER=\i\v</span></td><td>1 = verschlossen / Kofferraum zu</td></tr>
<tr><td><span class="mg-mono">\iINNEN=\i\v</span> / <span class="mg-mono">\iAUSSEN=\i\v</span></td><td>Innen- und Au&szlig;entemperatur</td></tr>
<tr><td><span class="mg-mono">\iKM=\i\v</span> / <span class="mg-mono">\iBATT12V=\i\v</span></td><td>Kilometerstand / Spannung der 12-V-Batterie</td></tr>
<tr><td><span class="mg-mono">\iALTER=\i\v</span></td><td>Minuten seit dem letzten Datensatz</td></tr>
<tr><td><span class="mg-mono">\iPUSHAKTIV=\i\v</span></td><td>1 = Ereignis eingetreten &mdash; Ausl&ouml;ser f&uuml;r den Push</td></tr>
<tr><td><span class="mg-mono">\iOK=\i\v</span> / <span class="mg-mono">\iPTEST=\i\v</span></td><td>Daten vorhanden / Test-Push</td></tr>
</table>
<div class="mg-small">Nicht jedes Modell liefert jeden Wert. Fehlende Werte kommen als <span class="mg-mono">-1</span>
(bzw. <span class="mg-mono">-99</span> bei Temperaturen) &mdash; in Loxone also nicht blind weiterrechnen,
sondern vorher mit einem Schwellwertschalter auf &bdquo;gr&ouml;&szlig;er 0&ldquo; pr&uuml;fen.</div>
</div>

<div class="mg-step"><b>Schritt 3: Virtueller Ausgang f&uuml;r Befehle</b>
<table class="mg-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse</td><td><span class="mg-mono">http://<?= mg_e($mg_host) ?></span></td></tr>
</table>
<table class="mg-tbl">
<tr><th>Befehl bei EIN</th><th>Wirkung</th></tr>
<?php foreach ($mg_cmds as $mg_k => $mg_c) { ?>
<tr><td><span class="mg-mono">/plugins/<?= mg_e($mg_plugin) ?>/mg.php?cmd=<?= mg_e($mg_k) ?></span></td><td><?= mg_e($mg_c[2]) ?></td></tr>
<?php } ?>
</table>
</div>

<div class="mg-step"><b>Schritt 4: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br>
<b>4a) Kacheln und Grundlogik</b>
<table class="mg-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Statusbaustein</td><td>Auto-Kachel</td><td>Text: &bdquo;&lt;v1.0&gt; % &mdash; &lt;v2.0&gt; km&ldquo;</td><td>I1 &larr; SOC, I2 &larr; REICHWEITE</td></tr>
<tr><td>Schwellwertschalter S1</td><td>L&auml;dt gerade</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; LAEDT</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Stecker drin</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; STECKER</td></tr>
<tr><td>Schwellwertschalter S3</td><td>Ladestand niedrig</td><td>Ein 30 / Aus 40 (invertiert: an, wenn <i>unter</i> 30 %)</td><td>&larr; SOC</td></tr>
<tr><td>Schwellwertschalter S4</td><td>Daten veraltet</td><td>Ein 180 / Aus 120 (Minuten)</td><td>&larr; ALTER</td></tr>
</table>
<b>4b) Laden nach PV-&Uuml;berschuss oder Spotpreis</b>
<table class="mg-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>UND U1</td><td>Sparladen m&ouml;glich</td><td></td><td>S2 (Stecker) &amp; (g&uuml;nstige Stunde bzw. PV-&Uuml;berschuss)</td></tr>
<tr><td>Virtueller Ausgang</td><td>Ladestrom 6 A</td><td><span class="mg-mono">?cmd=strom_6</span> &mdash; drosselt auf PV-Niveau</td><td>&larr; U1</td></tr>
<tr><td>Virtueller Ausgang</td><td>Ladestrom maximal</td><td><span class="mg-mono">?cmd=strom_max</span></td><td>&larr; NICHT U1 (&uuml;ber NICHT-Baustein)</td></tr>
<tr><td>Virtueller Ausgang</td><td>Laden stoppen</td><td><span class="mg-mono">?cmd=laden_stopp</span> &mdash; zuverl&auml;ssiger als Starten</td><td>&larr; (teure Stunde &amp; nicht dringend)</td></tr>
<tr><td>Virtueller Ausgang</td><td>Ziel 80 %</td><td><span class="mg-mono">?cmd=ziel_80</span> &mdash; schont die Batterie im Alltag</td><td>&larr; Taster in der App</td></tr>
</table>
<b>4c) Meldungen</b>
<table class="mg-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S5</td><td>Ereignis aktiv</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSHAKTIV</td></tr>
<tr><td>ODER O1</td><td>Push-Sammler</td><td>einzige Quelle des Benachrichtigungs-Bausteins!</td><td>S5</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Auto&ldquo;</td><td>Text z. B. &bdquo;Ladestand <?= '&lt;v1.0&gt;' ?> % &mdash; siehe App.&ldquo;</td><td>&larr; O1</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST</td></tr>
</table>
<div class="mg-small"><b>Praxis-Erfahrung zum Benachrichtigungs-Baustein:</b> Er sendet nur bei einer 0&rarr;1-Flanke.
Niemals mehrere Quellen direkt an den Eingang legen &mdash; eine dauerhaft aktive Quelle verschluckt alle weiteren
Ausl&ouml;ser. Immer erst im ODER sammeln. F&uuml;r den Test einen EIGENEN Baustein verwenden.</div>
</div>

<div class="mg-step"><b>Schritt 5: JSON</b><br>
Alle Werte auch als JSON f&uuml;r Drittsoftware:
<span class="mg-mono">http://<?= mg_e($mg_host) ?>/plugins/<?= mg_e($mg_plugin) ?>/mg.php?json=1</span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="mg-pane" id="tab-test">
<h2>Zustand</h2>
<div class="mg-legende">
<span><i class="mg-punkt mg-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="mg-punkt mg-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="mg-punkt mg-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="mg-h3">Ansehen</h3>
<div class="mg-knopfreihe">
<a class="mg-btn mg-b-lesen" href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="mg-btn mg-b-lesen"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?json=1" target="_blank">JSON-Ansicht</a>
</div>

<h3 class="mg-h3">Technische Auskunft</h3>
<div class="mg-knopfreihe">
<a class="mg-btn mg-b-technik"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?debug=1" target="_blank">Alle MQTT-Themen (Debug)</a>
</div>

<h3 class="mg-h3">L&ouml;st etwas aus</h3>
<div class="mg-knopfreihe">
<form method="post" style="margin:8px 0;">
    <input data-role="none" type="hidden" name="refreshnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="mg-btn mg-b-aktion" type="submit">Werte jetzt einlesen</button>
</form>
<a class="mg-btn mg-b-aktion"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?ptest=1" target="_blank">Test-Pushnachricht</a>
<form method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<?php foreach ($mg_cmds as $mg_k => $mg_c) { ?>
<button data-role="none" class="mg-btn mg-b-aktion" type="submit" name="sendcmd" value="<?= mg_e($mg_k) ?>"><?= mg_e($mg_c[2]) ?></button>
<?php } ?>
</form>
</div>

<div class="mg-kacheln">
    <div class="mg-kachel">Ladestand<b><?= mg_z($mg_st['soc'], ' %') ?></b><span class="mg-small"><?= mg_z($mg_st['soc_kwh'], ' kWh') ?></span>
</div>
    <div class="mg-kachel">Ziel<b><?= mg_z($mg_st['soc_ziel'], ' %') ?></b></div>
    <div class="mg-kachel">Reichweite<b><?= mg_z($mg_st['reichweite'], ' km') ?></b></div>
    <div class="mg-kachel">L&auml;dt<b><?= $mg_st['laedt'] === 1 ? 'ja' : ($mg_st['laedt'] === 0 ? 'nein' : '&ndash;') ?></b><span class="mg-small"><?= mg_z($mg_st['ladeleistung'], ' kW') ?></span></div>
    <div class="mg-kachel">Stecker<b><?= $mg_st['stecker'] === 1 ? 'drin' : ($mg_st['stecker'] === 0 ? 'ab' : '&ndash;') ?></b></div>
    <div class="mg-kachel">Verschlossen<b><?= $mg_st['verschlossen'] === 1 ? 'ja' : ($mg_st['verschlossen'] === 0 ? '<span style="color:#c62828;">nein</span>' : '&ndash;') ?></b></div>
    <div class="mg-kachel">Datenalter<b><?= (int) $mg_st['alter_min'] >= 0 ? (int) $mg_st['alter_min'] . ' min' : '&ndash;' ?></b><span class="mg-small"><?= (int) $mg_st['themen'] ?> Themen</span></div>
</div>

<h2>Befehle ausprobieren</h2>
<?php if (empty($mg_cfg['commands'])) { ?>
<div class="mg-alert mg-info">Steuerbefehle sind in den Einstellungen gesperrt.</div>
<?php } else { ?>

<div class="mg-small" style="margin-top:6px;">Die Befehle gehen an das Gateway und von dort ans Fahrzeug &mdash;
bis das Auto reagiert, k&ouml;nnen einige Sekunden bis Minuten vergehen.</div>
<?php } ?>

<h2>Rohdaten der letzten Momentaufnahme</h2>
<div class="mg-small" style="margin-bottom:6px;">Stand: <?= $mg_roh['zeit'] !== '' ? mg_e(date('d.m.Y H:i:s', strtotime($mg_roh['zeit']))) : 'noch keine' ?>,
<?= (int) $mg_roh['anzahl'] ?> Themen. Hier steht auch die Fahrzeug-ID f&uuml;r die Einstellungen.</div>
<?php if ($mg_roh['anzahl'] > 0) { $mg_w = $mg_roh['werte']; ksort($mg_w); ?>
<div class="mg-log"><?php foreach (array_slice($mg_w, 0, 200, true) as $mg_t => $mg_v) {
    echo mg_e($mg_t) . ' = ' . mg_e(mb_substr((string) $mg_v, 0, 60)) . "\n"; } ?></div>
<?php } else { ?>
<div class="mg-alert mg-info">Noch keine Werte empfangen. Pr&uuml;fen Sie den Reiter <b>Gateway einrichten</b> &mdash;
l&auml;uft der Container, und stimmen Broker-Adresse und Topic-Pr&auml;fix?</div>
<?php } ?>
</div>

<!-- ================= Protokoll ================= -->
<div class="mg-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="mg-small" style="margin-bottom:8px;">Protokolliert werden Zustands&auml;nderungen, gesendete Befehle,
Meldungen und Fehler &mdash; kein Zahlenspam. Passw&ouml;rter werden maskiert. Neueste Eintr&auml;ge oben (max. 300).<br>
Datei: <span class="mg-mono"><?= mg_e($mg_logfile) ?></span></div>
<?php if ($mg_loglines) { ?>
<div class="mg-log"><?= mg_e(implode("\n", $mg_loglines)) ?></div>
<?php } else { ?>
<div class="mg-alert mg-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="mg-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
</div>
</div>
<script>
(function () {
    var aktiv = <?= json_encode($mg_tab) ?>;
    var tabs = document.querySelectorAll('.mg-tab');
    function zeige(id) {
        tabs.forEach(function (t) { t.classList.toggle('mg-active', t.dataset.pane === id); });
        document.querySelectorAll('.mg-pane').forEach(function (p) { p.classList.toggle('mg-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { zeige(t.dataset.pane); }); });
    zeige(aktiv);
})();
</script>
<?php
if (class_exists('LBWeb')) {
    LBWeb::lbfooter();
} else {
    echo '</body></html>';
}
