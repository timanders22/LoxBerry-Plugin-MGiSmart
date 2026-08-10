<?php
/**
 * MG iSmart - Admin-Oberflaeche
 * Reiter: Einstellungen | Gateway einrichten | Einbindung in Loxone | Test | Protokoll
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein mg_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$mg_lbhome = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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

/* Aktiver Reiter.
 *
 * Er kommt aus dem abgesendeten Formular (activetab) oder aus der Adresse
 * (?form=...). Letzteres brauchen die Reiter, seit sie echte Verweise sind.
 * Die Positivliste MUSS jeden Reiter enthalten - fehlt einer, ist er sichtbar
 * und anklickbar, aber nach jedem Absenden springt die Seite zurueck auf
 * Einstellungen. */
$mg_muster = '/^tab-(settings|gateway|loxone|test|log)$/';
$mg_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$mg_tab = preg_match($mg_muster, $mg_wunsch) ? $mg_wunsch : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mg_logfile), 0775, true);
    mg_write_atomic($mg_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
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

/* Merkwort fuer die schaltenden Aufrufe an mg.php.
 *
 * Wird beim ersten Oeffnen der Oberflaeche erzeugt und danach nie wieder
 * angefasst - so bleiben einmal eingetragene Loxone-Adressen gueltig.
 * Bestehende Anlagen bekommen es beim naechsten Aufruf der Seite. */
if (empty($mg_cfg['aktionstoken']) && function_exists('mg_token_erzeugen')
    && function_exists('mg_config_save')) {
    $mg_cfg['aktionstoken'] = mg_token_erzeugen();
    mg_config_save($mg_cfg);
}
$mg_token = isset($mg_cfg['aktionstoken']) ? (string) $mg_cfg['aktionstoken'] : '';
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
/*
 * ?? statt ?: - der Unterschied ist hier keiner der Feinheit.
 *
 * "$_SERVER['HTTP_HOST'] ?: 'loxberry'" wertet die linke Seite AUS, auch wenn
 * es den Schluessel nicht gibt. Unter PHP 7.4 ist das eine Notice, die das
 * error_reporting dieser Datei verschluckt; unter PHP 8 eine Warning, und die
 * steht dann im Seitenkoerper. Fehlt HTTP_HOST bei einer Anfrage ohne
 * Host-Kopfzeile oder beim Aufruf von der Kommandozeile.
 *
 * Der Wert geht in die Beispieladressen fuer Loxone, deshalb wird er auf
 * unbedenkliche Zeichen begrenzt - er kommt vom Aufrufer, nicht vom Server.
 */
$mg_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
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

function mg_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * Platzhalter %TOKEN% in einer Beispieladresse durch das Merkwort ersetzen.
 *
 * Die Adressen stehen in den Sprachdateien, das Merkwort steht in der
 * Konfiguration - eines von beiden muss zum anderen. Der Platzhalter ist der
 * Weg, der ohne Verdopplung der Texte auskommt.
 *
 * Der Rest der Zeichenkette ist eine feste Vorgabe aus der Sprachdatei und
 * enthaelt bereits &amp;; nur das eingesetzte Merkwort wird maskiert.
 */
function mg_adr($vorlage)
{
    global $mg_token;
    return str_replace('%TOKEN%', mg_e($mg_token), (string) $vorlage);
}
function mg_z($v, $einheit = '', $ung = '&ndash;') { return $v === null || $v < 0 ? $ung : (rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',') . $einheit); }

if (class_exists('LBWeb')) {
    LBWeb::lbheader('MG iSmart', 'https://github.com/SAIC-iSmart-API/saic-python-mqtt-gateway', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>MG iSmart</title></head><body>';
}
?>
<style>
.sm-wrap { max-width: 1100px; margin: 0 auto; padding: 0 10px 40px; font-size: 0.95em; }
.sm-wrap h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; text-shadow: none; }
.sm-wrap label { display: block; font-weight: 600; margin: 8px 0 2px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select {
    width: 100%; padding: 7px 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; background: #fff; }
.sm-wrap .sm-row { display: flex; gap: 14px; flex-wrap: wrap; }
.sm-wrap .sm-row > div { flex: 1 1 210px; }
.sm-wrap .sm-small { color: #666; font-size: 0.88em; line-height: 1.45; }
.sm-wrap .sm-mono { font-family: monospace; background: #f4f4f4; padding: 1px 5px; border-radius: 4px; word-break: break-all; }
.sm-wrap .sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 8px; padding: 9px 18px;
    cursor: pointer; text-decoration: none; font-size: 0.95em; text-shadow: none !important; display: inline-block; margin: 3px 4px 3px 0; }
.sm-wrap .sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-wrap .sm-ok { background: #e8f5e9; border: 1px solid #6dac20; }
.sm-wrap .sm-warn { background: #fff8e1; border: 1px solid #ffb300; }
.sm-wrap .sm-err { background: #ffebee; border: 1px solid #c62828; }
.sm-wrap .sm-info { background: #eef4fb; border: 1px solid #90a4ae; }
.sm-wrap .sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-wrap .sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px;
    cursor: pointer; color: #444 !important; text-shadow: none !important;
    text-decoration: none !important; display: inline-block; }
.sm-wrap .sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-wrap .sm-pane { display: none; padding-top: 4px; }
.sm-wrap .sm-pane.sm-active { display: block; }
.sm-wrap .sm-tbl { border-collapse: collapse; margin: 6px 0 10px; width: 100%; }
.sm-wrap .sm-tbl th, .sm-wrap .sm-tbl td { border: 1px solid #ddd; padding: 5px 9px; text-align: left; vertical-align: top; }
.sm-wrap .sm-tbl th { background: #f4f4f4; }
.sm-wrap .sm-log, .sm-wrap .sm-code { background: #263238; color: #cfd8dc; font-family: monospace; font-size: 0.82em;
    padding: 10px; border-radius: 8px; max-height: 460px; overflow: auto; white-space: pre-wrap; box-shadow: none; }
.sm-wrap .sm-step { border-left: 4px solid #6dac20; padding: 4px 0 4px 12px; margin: 12px 0; }
.sm-wrap .sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-wrap .sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-wrap .sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
</style>
<div class="sm-wrap">
<h1 style="color:#6dac20;text-shadow:none;"><?php echo mg_t('TEXT.MG_ISMART'); ?><?php
/*
 * Die laufende Fassung neben dem Titel.
 *
 * Quelle ist LBSystem::pluginversion() - also die plugindatabase.json und
 * damit das, was LoxBerry TATSAECHLICH installiert hat. Eine hier fest
 * eingetragene Zahl waere beim naechsten Release schon falsch; die
 * mitgelieferte plugin.cfg dient nur als Rueckfallebene, wenn das Plugin
 * (noch) nicht in der Datenbank steht. Siehe mg_pluginversion().
 */
$mg_ver = function_exists('mg_pluginversion') ? mg_pluginversion() : '';
if ($mg_ver !== '') { ?><span class="sm-small" style="font-weight:400;"> <?= mg_e($mg_ver) ?></span><?php }
?></h1>
<div class="sm-small"><?php echo mg_t('TEXT.BRINGT_LADESTAND_REICHWEITE_LADEST'); ?> <b><?php echo mg_t('TEXT.SAIC_MQTT_GATEWAY'); ?></b><?php echo mg_t('TEXT.DIESES_PLUGIN_LIEST_SIE_VOM_MQTT_B'); ?></div>

<?php if ($mg_saved) { ?><div class="sm-alert sm-ok"><b><?php echo mg_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b></div><?php } ?>
<?php if ($mg_err !== '') { ?><div class="sm-alert sm-err"><?= mg_e($mg_err) ?></div><?php } ?>
<?php if ($mg_note !== '') { ?><div class="sm-alert <?= strpos($mg_note, 'fehlgeschlagen') !== false || strpos($mg_note, 'Keine') === 0 ? 'sm-err' : 'sm-ok' ?>"><?= mg_e($mg_note) ?></div><?php } ?>
<?php if (!$mg_hasmos) { ?>
<div class="sm-alert sm-err"><b><?php echo mg_t('TEXT.DAS_PAKET'); ?> <span class="sm-mono"><?php echo mg_t('TEXT.MOSQUITTO_CLIENTS'); ?></span> <?php echo mg_t('TEXT.FEHLT'); ?></b>
<?php echo mg_t('TEXT.OHNE'); ?> <span class="sm-mono"><?php echo mg_t('TEXT.MOSQUITTO_SUB'); ?></span> <?php echo mg_t('TEXT.KANN_DAS_PLUGIN_KEINE_WERTE_LESEN_'); ?><br>
<span class="sm-mono"><?php echo mg_t('TEXT.SUDO_APT_GET_UPDATE_SUDO_APT_GET_I'); ?></span></div>
<?php } ?>
<?php if (trim((string) $mg_cfg['saic_user']) === '' || trim((string) $mg_cfg['vin']) === '') { ?>
<div class="sm-alert sm-warn"><b><?php echo mg_t('TEXT.NOCH_NICHT_EINGERICHTET'); ?></b> <?php echo mg_t('TEXT.ZUERST_DEN_REITER'); ?> <b><?php echo mg_t('TEXT.GATEWAY_EINRICHTEN'); ?></b> <?php echo mg_t('TEXT.ABARBEITEN_DANN_HIER_UNTEN_BENUTZE'); ?></div>
<?php } ?>

<?php
/*
 * Die Reiter sind echte Verweise, und der SERVER setzt sm-active.
 *
 * Bis 1.0.2 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer: Ueberschrift und
 * Reiterleiste standen da, darunter nichts - und die Reiter liessen sich
 * nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * $mg_tab wurde serverseitig laengst ermittelt und nur ans JavaScript
 * weitergereicht. Jetzt setzt der Server die Klasse selbst; das JavaScript
 * spart nur noch den Seitenaufbau beim Umschalten.
 *
 * Diese Liste, die Positivliste in $mg_muster und die id der Flaechen
 * muessen deckungsgleich bleiben - alle drei.
 */
$mg_reiter = array(
    'tab-settings' => mg_t('REITER.EINSTELLUNGEN'),
    'tab-gateway'  => mg_t('REITER.GATEWAY'),
    'tab-loxone'   => mg_t('REITER.LOXONE'),
    'tab-test'     => mg_t('REITER.TEST'),
    'tab-log'      => mg_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($mg_reiter as $mg_id => $mg_bez) { ?>
    <a class="sm-tab<?= $mg_tab === $mg_id ? ' sm-active' : '' ?>" data-pane="<?= mg_e($mg_id) ?>"
       href="index.php?form=<?= mg_e(substr($mg_id, 4)) ?>"><?= $mg_bez ?></a>
<?php } ?>
</div>

<!-- ================= <?php echo mg_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane<?= $mg_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo mg_t('TEXT.MQTT_BROKER'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo mg_t('TEXT.ADRESSE'); ?></label>
        <input data-role="none" type="text" name="broker_host" value="<?= mg_e($mg_cfg['broker_host']) ?>" placeholder="127.0.0.1">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="broker_port" value="<?= (int) $mg_cfg['broker_port'] ?>" min="1" max="65535">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.BENUTZER_OPTIONAL'); ?></label>
        <input data-role="none" type="text" name="broker_user" value="<?= mg_e($mg_cfg['broker_user']) ?>">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.PASSWORT_OPTIONAL'); ?></label>
        <input data-role="none" type="password" name="broker_pass" value="" placeholder="<?= $mg_cfg['broker_pass'] !== '' ? 'gespeichert &mdash; leer lassen = unver&auml;ndert' : 'nur falls der Broker eines verlangt' ?>">
    </div>
</div>
<div class="sm-small"><?php echo mg_t('TEXT.BEI_LOXBERRY_IST_DAS_DER_EINGEBAUT'); ?> <span class="sm-mono">127.0.0.1:1883</span><?php echo mg_t('TEXT.BENUTZER_UND_PASSWORT_STEHEN_UNTER'); ?></div>

<h2><?php echo mg_t('TEXT.FAHRZEUG'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo mg_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="prefix" value="<?= mg_e($mg_cfg['prefix']) ?>" placeholder="saic">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.ISMART_BENUTZERNAME'); ?></label>
        <input data-role="none" type="text" name="saic_user" value="<?= mg_e($mg_cfg['saic_user']) ?>" placeholder="name@example.org">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.FAHRZEUG_ID_VIN'); ?></label>
        <input data-role="none" type="text" name="vin" value="<?= mg_e($mg_cfg['vin']) ?>" placeholder="LSJ…">
    </div>
    <div>
        <label><?php echo mg_t('TEXT.NUTZBARE_KAPAZITT_KWH'); ?></label>
        <input data-role="none" type="text" name="capacity" value="<?= mg_e($mg_cfg['capacity']) ?>" placeholder="61,1">
    </div>
</div>
<div class="sm-small"><?php echo mg_t('TEXT.BENUTZERNAME_UND_FAHRZEUG_ID_BILDE'); ?>
<span class="sm-mono"><?= mg_e($mg_cfg['prefix'] ?: 'saic') ?><?php echo mg_t('TEXT.BENUTZER_VEHICLES_FAHRZEUG_ID'); ?></span><br>
<?php echo mg_t('TEXT.WER_DIE_FAHRZEUG_ID_NICHT_KENNT_IM'); ?> <b><?php echo mg_t('TEXT.TEST'); ?></b> <?php echo mg_t('TEXT.AUF_WERTE_JETZT_EINLESEN_KLICKEN_D'); ?> <b><?php echo mg_t('TEXT.ISMART_PASSWORT_WIRD_HIER_NICHT_GE'); ?></b><?php echo mg_t('TEXT.ES_KENNT_NUR_DER_GATEWAY_CONTAINER'); ?></div>

<h2><?php echo mg_t('TEXT.STEUERUNG'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="commands" <?= !empty($mg_cfg['commands']) ? 'checked' : '' ?><?php echo mg_t('TEXT.STEUERBEFEHLE_ANS_FAHRZEUG_ERLAUBE'); ?>
</label>
<div class="sm-small"><?php echo mg_t('TEXT.AUSGESCHALTET_ARBEITET_DAS_PLUGIN_'); ?> <b>ein</b> <?php echo mg_t('TEXT.OHNE_BEFEHLE_GIBT_ES_KEIN_LADEN_NA'); ?></div>

<h2><?php echo mg_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="notify_push" <?= !empty($mg_notify['push']) ? 'checked' : '' ?><?php echo mg_t('TEXT.PUSH_FREIGABE_AN_LOXONE_MELDEN'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_voll" <?= !empty($mg_notify['soc_voll']) ? 'checked' : '' ?><?php echo mg_t('TEXT.ZIEL_LADESTAND_ERREICHT'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_stecker" <?= !empty($mg_notify['stecker']) ? 'checked' : '' ?><?php echo mg_t('TEXT.LADEKABEL_EIN_AUSGESTECKT'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="n_offen" <?= !empty($mg_notify['offen']) ? 'checked' : '' ?><?php echo mg_t('TEXT.AUTO_STEHT_UNVERSCHLOSSEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:260px;">
        <label><?php echo mg_t('TEXT.MELDEFENSTER_MINUTEN'); ?></label>
        <input data-role="none" type="number" name="push_minutes" value="<?= (int) $mg_notify['push_minutes'] ?>" min="1" max="60">
    </div>
</div>
<div class="sm-small"><?php echo mg_t('TEXT.TRITT_EINES_DER_EREIGNISSE_EIN_STE'); ?> <span class="sm-mono"><?php echo mg_t('TEXT.PUSHAKTIV_1'); ?></span> <?php echo mg_t('TEXT.FR_DIESE_ZEIT_DEN_PUSH_VERSCHICKT_'); ?></div>

<div style="margin-top:16px;"><button data-role="none" class="sm-btn" type="submit"><?php echo mg_t('TEXT.SPEICHERN'); ?></button></div>
</form>
</div>

<!-- ================= Gateway einrichten ================= -->
<div class="sm-pane<?= $mg_tab === 'tab-gateway' ? ' sm-active' : '' ?>" id="tab-gateway">
<h2><?php echo mg_t('TEXT.WARUM_EIN_ZUSTZLICHER_CONTAINER'); ?></h2>
<p><?php echo mg_t('TEXT.MG_SAIC_BIETET_KEINE_OFFENE_SCHNIT'); ?> <b><?php echo mg_t('TEXT.SAIC_MQTT_GATEWAY_2'); ?></b> <?php echo mg_t('TEXT.BILDET_DIESES_PROTOKOLL_NACH_MELDE'); ?></p>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_1_DOCKER_AUF_DEN_LOXBERRY_'); ?></b><br>
<?php echo mg_t('TEXT.DAFR_GIBT_ES_EIN_FERTIGES_PLUGIN'); ?> <b><?php echo mg_t('TEXT.DOCKER'); ?></b> <?php echo mg_t('TEXT.VON_MICHAEL_MIKLIS_ES_INSTALLIERT_'); ?> <b><?php echo mg_t('TEXT.PORTAINER'); ?></b> <?php echo mg_t('TEXT.EINE_OBERFLCHE_IN_DER_SICH_CONTAIN'); ?>
<div class="sm-small" style="margin-top:6px;"><?php echo mg_t('TEXT.OHNE_DIESES_PLUGIN_GEHT_ES_AUCH_DA'); ?></div>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_2_WEITERE_VORAUSSETZUNGEN'); ?></b><br>
<?php echo mg_t('TEXT.MQTT_GATEWAY_AKTIV_LOXBERRY_SYSTEM'); ?>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_3_CONTAINER_ANLEGEN'); ?></b><br>
<?php echo mg_t('TEXT.ENTWEDER_IN_PORTAINER_BER'); ?> <i><?php echo mg_t('TEXT.ADD_CONTAINER'); ?></i> <?php echo mg_t('TEXT.ODER_AM_LOXBERRY_PER_SSH_BEISPIEL_'); ?>
<div class="sm-code">docker run -d --name saic-gateway --restart unless-stopped \
  -e SAIC_USER="name@example.org" \
  -e SAIC_PASSWORD="IHR-ISMART-PASSWORT" \
  -e SAIC_REST_URI="https://gateway-sm-eu.soimt.com/api.app/v1/" \
  -e SAIC_REGION="eu" \
  -e MQTT_URI="tcp://<?= mg_e($mg_cfg['broker_host'] ?: '127.0.0.1') ?>:<?= (int) ($mg_cfg['broker_port'] ?: 1883) ?>" \
  -e MQTT_TOPIC="<?= mg_e($mg_cfg['prefix'] ?: 'saic') ?>" \
  -e HA_DISCOVERY_ENABLED="False" \
  -e BATTERY_CAPACITY_MAPPING="IHRE-VIN=<?= mg_e($mg_cfg['capacity']) ?>" \
  saicismartapi/saic-python-mqtt-gateway</div>
<div class="sm-small"><?php echo mg_t('TEXT.VERLANGT_DER_BROKER_EINE_ANMELDUNG'); ?>
<span class="sm-mono"><?php echo mg_t('TEXT.E_MQTT_USER_E_MQTT_PASSWORD'); ?></span> <?php echo mg_t('TEXT.ANGEBEN_DIE_ZEILE'); ?> <span class="sm-mono"><?php echo mg_t('TEXT.HA_DISCOVERY_ENABLED_FALSE'); ?></span> <?php echo mg_t('TEXT.SPART_DIE_HOME_ASSISTANT_THEMEN_DI'); ?></div>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_4_KONTROLLE'); ?></b><br>
<span class="sm-mono"><?php echo mg_t('TEXT.DOCKER_LOGS_F_SAIC_GATEWAY'); ?></span> <?php echo mg_t('TEXT.ZEIGT_DEN_ANMELDEVORGANG_DANACH_IM'); ?> <b>Test</b> <?php echo mg_t('TEXT.AUF_WERTE_JETZT_EINLESEN_KLICKEN_E'); ?>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_5_ABFRAGEINTERVALLE_BITTE_'); ?></b>
<div class="sm-alert sm-warn"><b><?php echo mg_t('TEXT.DAS_ABFRAGEN_WECKT_DIE_FAHRZEUGELE'); ?></b> <?php echo mg_t('TEXT.DIE_ENTWICKLER_DES_GATEWAYS_WARNEN'); ?>
<b><?php echo mg_t('TEXT.12_VOLT_BATTERIE'); ?></b><?php echo mg_t('TEXT.WHREND_DER_FAHRT_UND_BEIM_LADEN_FR'); ?> <span class="sm-mono"><?php echo mg_t('TEXT.FAHRZEUGSTATUS_JETZT_ABFRAGEN'); ?></span>
<?php echo mg_t('TEXT.DER_RICHTIGE_WEG_GEZIELT_STATT_DAU'); ?></div>
<div class="sm-small"><?php echo mg_t('TEXT.ZWEI_WEITERE_EIGENHEITEN_MELDET_SI'); ?> <b><?php echo mg_t('TEXT.LADEN_STARTEN_GILT_ALS_UNZUVERLSSI'); ?></b><?php echo mg_t('TEXT.WHREND_LADEN_STOPPEN_ZUVERLSSIG_FU'); ?></div>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?= $mg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo mg_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo mg_t('TEXT.ABFRAGE_300_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo mg_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo mg_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono"><?php echo mg_t('TEXT.HTTP'); ?><?= mg_e($mg_host) ?><?php echo mg_t('TEXT.PLUGINS'); ?><?= mg_e($mg_plugin) ?><?php echo mg_t('TEXT.MG_PHP'); ?></span></td></tr>
<tr><td><?php echo mg_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo mg_t('TEXT.300_SEKUNDEN'); ?></td></tr>
</table>
<div class="sm-small"><?php echo mg_t('TEXT.DER_ABRUF_BEIM_PLUGIN_KOSTET_NICHT'); ?> <i><?php echo mg_t('TEXT.AUTO'); ?></i> <?php echo mg_t('TEXT.GEFRAGT_WIRD_BESTIMMT_ALLEIN_DAS_G'); ?></div>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_2_BEFEHLSERKENNUNGEN'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mg_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo mg_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.ISOC_I_V'); ?></span></td><td><b><?php echo mg_t('TEXT.LADESTAND_IN'); ?></b></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.ISOCKWH_I_V'); ?></span></td><td><?php echo mg_t('TEXT.LADESTAND_IN_KWH_AUS_DER_KAPAZITT_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IZIEL_I_V'); ?></span></td><td><?php echo mg_t('TEXT.EINGESTELLTER_ZIEL_LADESTAND_IN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IREICHWEITE_I_V'); ?></span></td><td><?php echo mg_t('TEXT.RESTREICHWEITE_IN_KM'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.ILAEDT_I_V'); ?></span></td><td><?php echo mg_t('TEXT.1_LDT_GERADE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.ISTECKER_I_V'); ?></span></td><td><?php echo mg_t('TEXT.1_LADEKABEL_STECKT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.ILEISTUNG_I_V'); ?></span></td><td><?php echo mg_t('TEXT.LADELEISTUNG_IN_KW'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IRESTZEIT_I_V'); ?></span></td><td><?php echo mg_t('TEXT.VERBLEIBENDE_LADEZEIT_IN_MINUTEN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IVOLL_I_V'); ?></span></td><td><?php echo mg_t('TEXT.1_ZIEL_LADESTAND_ERREICHT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IZU_I_V'); ?></span> / <span class="sm-mono"><?php echo mg_t('TEXT.IKOFFER_I_V'); ?></span></td><td><?php echo mg_t('TEXT.1_VERSCHLOSSEN_KOFFERRAUM_ZU'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IINNEN_I_V'); ?></span> / <span class="sm-mono"><?php echo mg_t('TEXT.IAUSSEN_I_V'); ?></span></td><td><?php echo mg_t('TEXT.INNEN_UND_AUENTEMPERATUR'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IKM_I_V'); ?></span> / <span class="sm-mono"><?php echo mg_t('TEXT.IBATT12V_I_V'); ?></span></td><td><?php echo mg_t('TEXT.KILOMETERSTAND_SPANNUNG_DER_12_V_B'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IALTER_I_V'); ?></span></td><td><?php echo mg_t('TEXT.MINUTEN_SEIT_DEM_LETZTEN_DATENSATZ'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IPUSHAKTIV_I_V'); ?></span></td><td><?php echo mg_t('TEXT.1_EREIGNIS_EINGETRETEN_AUSLSER_FR_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mg_t('TEXT.IOK_I_V'); ?></span> / <span class="sm-mono"><?php echo mg_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo mg_t('TEXT.DATEN_VORHANDEN_TEST_PUSH'); ?></td></tr>
</table>
<div class="sm-small"><?php echo mg_t('TEXT.NICHT_JEDES_MODELL_LIEFERT_JEDEN_W'); ?> <span class="sm-mono">-1</span>
<?php echo mg_t('TEXT.BZW'); ?> <span class="sm-mono">-99</span> <?php echo mg_t('TEXT.BEI_TEMPERATUREN_IN_LOXONE_ALSO_NI'); ?></div>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_3_VIRTUELLER_AUSGANG_FR_BE'); ?></b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse</td><td><span class="sm-mono">http://<?= mg_e($mg_host) ?></span></td></tr>
<tr><td><?php echo mg_t('TEXT.AKTUELLES_MERKWORT'); ?></td><td><span class="sm-mono"><?= mg_e($mg_token) ?></span></td></tr>
</table>
<div class="sm-alert sm-warn"><?php echo mg_t('TEXT.MERKWORT_HINWEIS'); ?></div>
<table class="sm-tbl">
<tr><th><?php echo mg_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo mg_t('TEXT.WIRKUNG'); ?></th></tr>
<?php foreach ($mg_cmds as $mg_k => $mg_c) { ?>
<tr><td><span class="sm-mono">/plugins/<?= mg_e($mg_plugin) ?><?php echo mg_t('TEXT.MG_PHP_CMD'); ?><?= mg_e($mg_k) ?>&amp;token=<?= mg_e($mg_token) ?></span></td><td><?= mg_e($mg_c[2]) ?></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<b><?php echo mg_t('TEXT.4A_KACHELN_UND_GRUNDLOGIK'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mg_t('TEXT.BAUSTEIN'); ?></th><th><?php echo mg_t('TEXT.NAME'); ?></th><th>Einstellung</th><th><?php echo mg_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo mg_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo mg_t('TEXT.AUTO_KACHEL'); ?></td><td><?php echo mg_t('TEXT.TEXT_V1_0_V2_0_KM'); ?></td><td><?php echo mg_t('TEXT.I1_SOC_I2_REICHWEITE'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo mg_t('TEXT.LDT_GERADE'); ?></td><td><?php echo mg_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo mg_t('TEXT.LAEDT'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo mg_t('TEXT.STECKER_DRIN'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo mg_t('TEXT.STECKER'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo mg_t('TEXT.LADESTAND_NIEDRIG'); ?></td><td><?php echo mg_t('TEXT.EIN_30_AUS_40_INVERTIERT_AN_WENN'); ?> <i><?php echo mg_t('TEXT.UNTER'); ?></i> 30 %)</td><td><?php echo mg_t('TEXT.SOC'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_S4'); ?></td><td><?php echo mg_t('TEXT.DATEN_VERALTET'); ?></td><td><?php echo mg_t('TEXT.EIN_180_AUS_120_MINUTEN'); ?></td><td><?php echo mg_t('TEXT.ALTER'); ?></td></tr>
</table>
<b><?php echo mg_t('TEXT.4B_LADEN_NACH_PV_UUML_BERSCHUSS_OD'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo mg_t('TEXT.UND_U1'); ?></td><td><?php echo mg_t('TEXT.SPARLADEN_MGLICH'); ?></td><td></td><td><?php echo mg_t('TEXT.S2_STECKER_GNSTIGE_STUNDE_BZW_PV_U'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.VIRTUELLER_AUSGANG'); ?></td><td><?php echo mg_t('TEXT.LADESTROM_6_A'); ?></td><td><span class="sm-mono"><?= mg_adr(mg_t('TEXT.CMD_STROM_6')) ?></span> <?php echo mg_t('TEXT.DROSSELT_AUF_PV_NIVEAU'); ?></td><td><?php echo mg_t('TEXT.U1'); ?></td></tr>
<tr><td>Virtueller Ausgang</td><td><?php echo mg_t('TEXT.LADESTROM_MAXIMAL'); ?></td><td><span class="sm-mono"><?= mg_adr(mg_t('TEXT.CMD_STROM_MAX')) ?></span></td><td><?php echo mg_t('TEXT.NICHT_U1_BER_NICHT_BAUSTEIN'); ?></td></tr>
<tr><td>Virtueller Ausgang</td><td><?php echo mg_t('TEXT.LADEN_STOPPEN'); ?></td><td><span class="sm-mono"><?= mg_adr(mg_t('TEXT.CMD_LADEN_STOPP')) ?></span> <?php echo mg_t('TEXT.ZUVERLSSIGER_ALS_STARTEN'); ?></td><td><?php echo mg_t('TEXT.TEURE_STUNDE_NICHT_DRINGEND'); ?></td></tr>
<tr><td>Virtueller Ausgang</td><td><?php echo mg_t('TEXT.ZIEL_80'); ?></td><td><span class="sm-mono"><?= mg_adr(mg_t('TEXT.CMD_ZIEL_80')) ?></span> <?php echo mg_t('TEXT.SCHONT_DIE_BATTERIE_IM_ALLTAG'); ?></td><td><?php echo mg_t('TEXT.TASTER_IN_DER_APP'); ?></td></tr>
</table>
<b><?php echo mg_t('TEXT.4C_MELDUNGEN'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_S5'); ?></td><td><?php echo mg_t('TEXT.EREIGNIS_AKTIV'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo mg_t('TEXT.PUSHAKTIV'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.ODER_O1'); ?></td><td><?php echo mg_t('TEXT.PUSH_SAMMLER'); ?></td><td><?php echo mg_t('TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN'); ?></td><td>S5</td></tr>
<tr><td><?php echo mg_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo mg_t('TEXT.PUSH_AUTO'); ?></td><td><?php echo mg_t('TEXT.TEXT_Z_B_LADESTAND'); ?> <?= '&lt;v1.0&gt;' ?> <?php echo mg_t('TEXT.SIEHE_APP'); ?></td><td><?php echo mg_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo mg_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo mg_t('TEXT.TEST_PUSH'); ?></td><td><?php echo mg_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo mg_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST'); ?></td></tr>
</table>
<div class="sm-small"><b><?php echo mg_t('TEXT.PRAXIS_ERFAHRUNG_ZUM_BENACHRICHTIG'); ?></b> <?php echo mg_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?></div>
</div>

<div class="sm-step"><b><?php echo mg_t('TEXT.SCHRITT_5_JSON'); ?></b><br>
<?php echo mg_t('TEXT.ALLE_WERTE_AUCH_ALS_JSON_FR_DRITTS'); ?>
<span class="sm-mono">http://<?= mg_e($mg_host) ?>/plugins/<?= mg_e($mg_plugin) ?><?php echo mg_t('TEXT.MG_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?= $mg_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo mg_t('TEXT.ZUSTAND'); ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mg_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mg_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mg_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo mg_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php" target="_blank"><?php echo mg_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?json=1" target="_blank"><?php echo mg_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo mg_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?debug=1" target="_blank"><?php echo mg_t('TEXT.ALLE_MQTT_THEMEN_DEBUG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo mg_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:8px 0;">
    <input data-role="none" type="hidden" name="refreshnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo mg_t('TEXT.WERTE_JETZT_EINLESEN'); ?></button>
</form>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= mg_e($mg_plugin) ?>/mg.php?ptest=1" target="_blank"><?php echo mg_t('TEXT.TEST_PUSHNACHRICHT'); ?></a>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<?php foreach ($mg_cmds as $mg_k => $mg_c) { ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sendcmd" value="<?= mg_e($mg_k) ?>"><?= mg_e($mg_c[2]) ?></button>
<?php } ?>
</form>
</div>

<div class="sm-kacheln">
    <div class="sm-kachel"><?php echo mg_t('TEXT.LADESTAND'); ?><b><?= mg_z($mg_st['soc'], ' %') ?></b><span class="sm-small"><?= mg_z($mg_st['soc_kwh'], ' kWh') ?></span>
</div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.ZIEL'); ?><b><?= mg_z($mg_st['soc_ziel'], ' %') ?></b></div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.REICHWEITE'); ?><b><?= mg_z($mg_st['reichweite'], ' km') ?></b></div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.LDT'); ?><b><?= $mg_st['laedt'] === 1 ? 'ja' : ($mg_st['laedt'] === 0 ? mg_t('TEXT.NEIN') : '&ndash;') ?></b><span class="sm-small"><?= mg_z($mg_st['ladeleistung'], ' kW') ?></span></div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.STECKER_2'); ?><b><?= $mg_st['stecker'] === 1 ? 'drin' : ($mg_st['stecker'] === 0 ? 'ab' : '&ndash;') ?></b></div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.VERSCHLOSSEN'); ?><b><?= $mg_st['verschlossen'] === 1 ? 'ja' : ($mg_st['verschlossen'] === 0 ? '<span style="color:#c62828;">nein</span>' : '&ndash;') ?></b></div>
    <div class="sm-kachel"><?php echo mg_t('TEXT.DATENALTER'); ?><b><?= (int) $mg_st['alter_min'] >= 0 ? (int) $mg_st['alter_min'] . ' min' : '&ndash;' ?></b><span class="sm-small"><?= (int) $mg_st['themen'] ?> <?php echo mg_t('TEXT.THEMEN'); ?></span></div>
</div>

<h2><?php echo mg_t('TEXT.BEFEHLE_AUSPROBIEREN'); ?></h2>
<?php if (empty($mg_cfg['commands'])) { ?>
<div class="sm-alert sm-info"><?php echo mg_t('TEXT.STEUERBEFEHLE_SIND_IN_DEN_EINSTELL'); ?></div>
<?php } else { ?>

<div class="sm-small" style="margin-top:6px;"><?php echo mg_t('TEXT.DIE_BEFEHLE_GEHEN_AN_DAS_GATEWAY_U'); ?></div>
<?php } ?>

<h2><?php echo mg_t('TEXT.ROHDATEN_DER_LETZTEN_MOMENTAUFNAHM'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo mg_t('TEXT.STAND'); ?> <?= $mg_roh['zeit'] !== '' ? mg_e(date('d.m.Y H:i:s', strtotime($mg_roh['zeit']))) : 'noch keine' ?>,
<?= (int) $mg_roh['anzahl'] ?> <?php echo mg_t('TEXT.THEMEN_HIER_STEHT_AUCH_DIE_FAHRZEU'); ?></div>
<?php if ($mg_roh['anzahl'] > 0) { $mg_w = $mg_roh['werte']; ksort($mg_w); ?>
<div class="sm-log"><?php foreach (array_slice($mg_w, 0, 200, true) as $mg_t => $mg_v) {
    echo mg_e($mg_t) . ' = ' . mg_e(mb_substr((string) $mg_v, 0, 60)) . "\n"; } ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo mg_t('TEXT.NOCH_KEINE_WERTE_EMPFANGEN_PRFEN_S'); ?> <b>Gateway einrichten</b> <?php echo mg_t('TEXT.LUFT_DER_CONTAINER_UND_STIMMEN_BRO'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo mg_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?= $mg_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2>Protokoll</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo mg_t('TEXT.PROTOKOLLIERT_WERDEN_ZUSTANDSNDERU'); ?><br>
<?php echo mg_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= mg_e($mg_logfile) ?></span></div>
<?php if ($mg_loglines) { ?>
<div class="sm-log"><?= mg_e(implode("\n", $mg_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo mg_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo mg_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
</form>
</div>
</div>
<script>
(function () {
    var aktiv = <?= json_encode($mg_tab) ?>;
    var tabs = document.querySelectorAll('.sm-tab');
    function zeige(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) { e.preventDefault(); zeige(t.dataset.pane); });
    });
    // Die versteckten Formularfelder muessen den sichtbaren Reiter tragen,
    // sonst landet man nach dem Absenden auf einem anderen.
    document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = aktiv; });
    zeige(aktiv);
})();
</script>
<?php
if (class_exists('LBWeb')) {
    LBWeb::lbfooter();
} else {
    echo '</body></html>';
}
