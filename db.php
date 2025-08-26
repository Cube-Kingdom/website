<?php
// db.php - establishes SQLite connection and initializes schema

$dbFile = __DIR__ . '/database.sqlite';
$init = !file_exists($dbFile);

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($init) {
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, is_admin INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, filename TEXT, original_name TEXT)');
    $pdo->exec('CREATE TABLE user_documents (user_id INTEGER, document_id INTEGER, PRIMARY KEY(user_id, document_id))');

    $stmt = $pdo->prepare('INSERT INTO users (username, password, is_admin) VALUES (?, ?, 1)');
    $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
}
?>
