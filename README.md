# 🌟 ExtraHelden – Bewerbungsportal

Das **ExtraHelden-Portal** ist eine PHP-Webanwendung zur Verwaltung von Bewerbungen, Dokumenten und Benutzerkonten.  
Es bietet Bewerbern eine einfache Möglichkeit, sich online zu bewerben, und Administratoren ein übersichtliches Panel zur Verwaltung aller Daten.  

---

## ✨ Features
- 🔐 Benutzerregistrierung & Login
- 📝 Online-Bewerbungsformular
- 📂 Dokumentenverwaltung mit Upload/Download
- 🛠 Adminpanel zur Verwaltung von Bewerbungen
- 🗂 Shortlist- & Auswahlverwaltung
- ⏰ Cronjob-Unterstützung (Whitelist-Management)
- 🎨 Anpassbares Theme-System

---

## 📂 Projektstruktur
| Datei / Ordner       | Beschreibung |
|-----------------------|--------------|
| `index.php`           | Startseite / Landingpage |
| `apply.php`           | Bewerbungsformular |
| `login.php` / `logout.php` | Benutzer-Login & -Logout |
| `account.php`         | Nutzerbereich |
| `documents.php`       | Dokumentenverwaltung |
| `admin.php`           | Admin-Dashboard |
| `admin_application.php` | Bewerbungsübersicht |
| `admin_shortlist.php` | Shortlist-Verwaltung |
| `uploads/`            | Upload-Verzeichnis (Dateien) |
| `assets/`             | Statische Ressourcen (CSS, JS, Bilder) |
| `db.php`              | Datenbankverbindung |
| `database.sqlite`     | SQLite-Datenbankdatei |
| `migrate.php`         | Migration & Setup |
| `cron_whitelist.php`  | Cronjob-Skript |

---

## ⚙️ Installation & Setup
### 1. Repository klonen
```bash
git clone https://github.com/<dein-username>/extrahelden.git
cd extrahelden
```

### 2. Voraussetzungen
- PHP 8.0 oder höher  
- SQLite mit aktiviertem PDO-SQLite  
- Webserver (Apache2 oder Nginx mit PHP-FPM)  

### 3. Webserver konfigurieren (Apache-Beispiel)
```apache
<VirtualHost *:80>
    ServerName extrahelden.local
    DocumentRoot /var/www/extrahelden

    <Directory /var/www/extrahelden>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Datenbankmigration ausführen
```bash
php migrate.php
```

### 5. Erste Anmeldung
Falls im Migrationsskript vorgesehen, wird ein Standard-Admin-Account erzeugt.

---

## 🛠 Entwicklung
- Layout und Navigation werden in `_layout.php` definiert.  
- Themes können in `theme.php` angepasst werden.  
- Cronjobs (z. B. automatische Whitelist-Verwaltung) laufen über `cron_whitelist.php`.  

---

## 🚫 Sicherheitshinweis
- Das Verzeichnis `uploads/` sollte per `.htaccess` oder Server-Config vor direktem Zugriff geschützt werden.  
- Die Datenbankdatei `database.sqlite` gehört **nicht** ins öffentliche Webverzeichnis.  

---

## 📜 Lizenz
Dieses Projekt ist **proprietär** und nicht frei zur Wiederverwendung vorgesehen.  
Weitere Informationen findest du auf [extrahelden.de](https://www.extrahelden.de).
