# Document Website

Einfaches PHP-Projekt mit einer Adminoberfläche zum Hochladen von Dokumenten, zum Verwalten von Benutzerkonten und zum Zuweisen der Dokumente zu einzelnen Benutzern.

## Setup

Voraussetzungen sind PHP (mit SQLite-Erweiterung) sowie ein Webserver wie Apache 2. Beim ersten Aufruf wird eine SQLite-Datenbank samt Standard-Admin (`admin`/`admin`) erstellt.

Zum schnellen Testen kann der eingebaute PHP-Server genutzt werden:

```bash
php -S localhost:8000
```

## Nutzung

1. `index.php` aufrufen und als Admin anmelden.
2. Über `admin.php` weitere Benutzer anlegen, Dokumente hochladen und ihnen zuweisen.
3. Normale Benutzer melden sich über `index.php` an und können nur ihre zugewiesenen Dokumente herunterladen.
