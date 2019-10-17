# CSVZipExport
Beschreibung des Moduls.

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

### 2. Vorraussetzungen

- IP-Symcon ab Version 5.0

### 3. Software-Installation

* Über den Module Store das 'CSVZipExport'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen `https://github.com/TillBrede/CSVZipExport/`

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' ist das 'CSVZipExport'-Modul unter dem Hersteller '(Gerät)' aufgeführt.

__Konfigurationsseite__:

Name                  | Beschreibung
----------------------| ------------------
Geloggte Variablen    | Auswahl der Variable welche exportiert werden soll
Start der Aggregation | Beginn des Aggregationszeitraums
Ende der Aggregation  | Ende des Aggregationszeitraums
Aggregationsstufe     | Stufe der Aggregation 
Exportieren           | Die aggregierten Daten der Variable werden exportiert

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden keine zusätzlichen Statusvariablen erstellt.

#### Profile
Es werden keine zusätzlichen Profile erstellt.

### 6. WebFront

Dieses Modul bietet keinerlei Funktion im Webfront.

### 7. PHP-Befehlsreferenze

`boolean CSV_Export(integer $InstanzID, integer $ArchiveVariable, integer $AggregationStage, integer $AggregationStart, integer $AggregationEnd);`
Erzeugt ein Zip-Archiv basierend auf den gegebenen Parametern und liefert den relativen Pfad des Archivs als Rückgabewert.

Beispiel:
`CSV_BeispielFunktion(12345, 54321, 4, 2293574400, 3127161600);`