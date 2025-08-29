# Website

Ein kleines PHP-basiertes Webportal für einen Minecraft-Server.

## Funktionen
- Startseite mit Live-Status der hinterlegten Minecraft-Server. Der Status wird zwischengespeichert und bei Bedarf neu gepingt.
- Benutzer- und Dokumentenverwaltung mit SQLite-Datenbank (`users`, `documents`, `posts`, `minecraft_servers`, u.a.).
- Bewerbungsformular für Projekte mit YouTube-Link, Minecraft-Namen und Discord-Kontakt.
- Adminbereich zum Hochladen von Dokumenten, Verwalten von Bewerbungen und weiteren Verwaltungsfunktionen.

## Voraussetzungen
- PHP 8.x mit SQLite-Erweiterung
- Optional: cURL für externe API-Anfragen

## Installation
1. Repository klonen oder Dateien auf den Server kopieren.
2. Datenbank initialisieren:
   ```bash
   php migrate.php
   ```
   Optional kann darüber mit `?bootstrap_admin=1` ein erster Admin-Account (`admin`/`admin`) angelegt werden.
3. Server starten, z.B. mit dem eingebauten PHP-Server:
   ```bash
   php -S localhost:8000
   ```
   Die Anwendung ist anschließend unter http://localhost:8000 erreichbar.

## Dateiablage
- Die SQLite-Datenbank liegt in `database.sqlite`.
- Hochgeladene Dateien werden im Verzeichnis `uploads/` gespeichert.

## Lizenz
Es wurde keine Lizenz angegeben.
