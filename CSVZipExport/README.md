# CSVZipExport
Dieses Modul bietet die Möglichkeit, die aggregierten Werte einer Variable als CSV-Datei in einem ZIP-Archiv zu exportieren. 

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Exportieren von aggregierten Daten einer Variable
* Daten in CSV-Datei ein einem ZIP-Archiv exportieren
* Auflistung aller geloggten Variablen
* Zeitraum der Aggregierung frei wählbar
* Aggregierungsstufe kann ausgewählt werden
* Zyklisches erstellen und versenden eines Archivs

### 2. Voraussetzungen

- IP-Symcon ab Version 5.5

### 3. Software-Installation

* Über den Module Store das 'CSVZipExport'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen `https://github.com/symcon/CSVZipExport/`

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' ist das 'CSVZipExport'-Modul unter dem Hersteller '(Gerät)' aufgeführt.

__Konfigurationsseite__:

Name                         | Beschreibung
---------------------------- | ------------------
Filter                       | Filtriert die Auswahl der geloggten Variablen
Geloggte Variablen           | Auswahl der Variable welche exportiert werden soll
Start der Aggregation        | Beginn des Aggregationszeitraums
Ende der Aggregation         | Ende des Aggregationszeitraums
Aggregationsstufe            | Stufe der Aggregation 
Exportieren                  | Die aggregierten Daten der Variable werden exportiert
Zyklisches senden aktivieren | Aktiviert die Versendung per E-Mail
SMPT-Instanz                 | Auswahl der E-Mail-Instanz
E-Mail Intervall             | Intervall in welchem die E-Mail versendet wird. Bei "Wöchtenlich" wird die E-Mail am Montag versendet und bei "Monatlich" wird die E-Mail jeweils am 1. des Monats versendet zum jeweilig gewählten Zeitpunkt
Zeitpunkt der Mail           | Zeitpunkt zu welchem die E-Mail versendet werden soll 
Jetzt Mail senden            | Sendet manuell eine Mail

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden keine zusätzlichen Statusvariablen erstellt.

#### Profile
Es werden keine zusätzlichen Profile erstellt.

### 6. WebFront

Dieses Modul bietet keinerlei Funktion im Webfront.

### 7. PHP-Befehlsreferenze
`string CSV_Export(integer $InstanzID, integer $ArchiveVariable, integer $AggregationStage, integer $AggregationStart, integer $AggregationEnd);`
Erzeugt ein Zip-Archiv basierend auf den gegebenen Parametern und liefert den relativen Pfad des Archivs als Rückgabewert.

Beispiel:
`CSV_Export(12345, 54321, 4, 2293574400, 3127161600);`


`void CSV_SendMail(integer $InstanzID);`
Senden durch eine SMTP Instaz eine Mail mit einer erzeugten Zip-Datei

Beispiel:
`CSV_SendMail(12345);`


`void CSV_DeleteZip(integer $InstanzID);`
Entfernt die generierte Datei.

Beispiel:
`CSV_DeleteZip(12345);`
