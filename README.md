# CSVZipExport

[![IP-Symcon is awesome!](https://img.shields.io/badge/IP--Symcon-7.0-blue.svg)](https://www.symcon.de)
[![Check Style](https://github.com/symcon/CSVZipExport/workflows/Check%20Style/badge.svg)](https://github.com/symcon/CSVZipExport/actions)
[![Run Tests](https://github.com/symcon/CSVZipExport/workflows/Run%20Tests/badge.svg)](https://github.com/symcon/CSVZipExport/actions)

_Das Modul „Smart Energy Box“ wurde in Kooperation zwischen der Symcon GmbH, Stark Elektronik GmbH und der Hochschule Biberach (Institut IGE) im Rahmen des Landesprojektes EnMa HAW: Konzept für automationsgestütztes Energiemanagement an nicht-universitären Hochschulen in Baden-Württemberg entwickelt._

Folgende Module beinhaltet das CSVZipExport Repository:

- __CSVZipExport__ ([Dokumentation](https://www.symcon.de/de/service/dokumentation/modulreferenz/csv-zip-export))

Dieses Modul bietet die Möglichkeit, die geloggten oder aggregierten Werte mehrerer Variablen als CSV-Dateien zu exportieren:

- __Eine Datei pro Variable__: Jede Variable wird in eine eigene CSV-Datei exportiert, die gemeinsam in einem ZIP-Archiv abgelegt werden.
- __Eine Datei mit mehreren Variablen__: Alle Variablen werden als Spalten in einer CSV-Datei exportiert, wahlweise in einem ZIP-Archiv.
- __MySQL__: Ist das Modul SyncMySQL installiert, kann eine geloggte Variable über ihren Ident ausgewählt werden.

Der Export kann direkt heruntergeladen oder zyklisch (stündlich, täglich, wöchentlich, monatlich oder jährlich) per Mail und/oder per SFTP, FTP oder FTPS versendet werden.
