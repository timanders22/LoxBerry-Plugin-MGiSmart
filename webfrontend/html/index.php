<?php
/**
 * Absichtlich ohne Inhalt (seit 1.1.42).
 *
 * Diese Datei verhindert, dass der Apache bei einem Aufruf von
 * /plugins/<ordner>/ das Verzeichnis auflistet (LoxBerry fuehrt den
 * html-Baum mit Options +Indexes; am Geraet gemessen 12.11.2026: bis 1.1.41
 * antwortete /plugins/mgismart/ mit einer Liste der Endpunkte und der
 * Bibliothek). Bauart Intercom, Regeln/03.
 *
 * Der Endpunkt fuer Loxone ist mg.php; die Oberflaeche liegt unter
 * webfrontend/htmlauth/.
 */
header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain; charset=utf-8');
echo "Hier gibt es nichts zu sehen. Die Oberflaeche des Plugins liegt im\n"
   . "angemeldeten Bereich von LoxBerry unter Plugins.\n";
