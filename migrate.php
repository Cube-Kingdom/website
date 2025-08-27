<?php
// migrate.php — idempotente Migrationen für fehlende Tabellen/Defaults
declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$pdo->beginTransaction();

try {
    // --- vorhandene Kern-Tabellen (nur zur Sicherheit; IF NOT EXISTS) ---
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      is_admin INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS documents (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      filename TEXT NOT NULL,
      path TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS user_documents (
      user_id INTEGER NOT NULL,
      document_id INTEGER NOT NULL,
      PRIMARY KEY (user_id, document_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS posts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      content TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      published INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS minecraft_servers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      host TEXT NOT NULL,
      port INTEGER NOT NULL DEFAULT 25565,
      enabled INTEGER NOT NULL DEFAULT 1,
      sort_order INTEGER NOT NULL DEFAULT 0,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS server_status_cache (
      server_id INTEGER PRIMARY KEY,
      online INTEGER NOT NULL,
      players_online INTEGER,
      players_max INTEGER,
      version TEXT,
      latency_ms REAL,
      raw_json TEXT,
      checked_at TEXT NOT NULL,
      FOREIGN KEY (server_id) REFERENCES minecraft_servers(id) ON DELETE CASCADE
    );
    ");

    // --- neue Tabellen/Settings für Bewerbungen ---
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS site_settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS applications (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      youtube_url TEXT NOT NULL,
      youtube_video_id TEXT,
      mc_name TEXT NOT NULL,
      mc_uuid TEXT,
      discord_name TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Defaults nur setzen, wenn nicht vorhanden
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO site_settings(key, value) VALUES(?, ?)");
    $stmt->execute(['apply_enabled', '0']);
    $stmt->execute(['apply_title', 'Projekt-Anmeldung']);

    $pdo->commit();

    echo "OK: Migration abgeschlossen.\n";
    echo "- Tabellen sichergestellt: users, documents, user_documents, posts, minecraft_servers, server_status_cache, site_settings, applications\n";
    echo "- Defaults: apply_enabled=0, apply_title='Projekt-Anmeldung' (nur gesetzt, wenn fehlten)\n";

    // OPTIONAL: Admin-Bootstrap über URL-Parameter ?bootstrap_admin=1
    if (isset($_GET['bootstrap_admin']) && $_GET['bootstrap_admin'] === '1') {
        $pdo->beginTransaction();
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $exists->execute(['admin']);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?,?,1)")
                ->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
            echo "Admin-Benutzer 'admin' mit Passwort 'admin' angelegt. Bitte sofort ändern.\n";
        } else {
            echo "Hinweis: Benutzer 'admin' existiert bereits, kein Bootstrap nötig.\n";
        }
        $pdo->commit();
    }

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "FEHLER: " . $e->getMessage() . "\n";
    exit;
}
