<?php
session_start();
require 'db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$message = '';

if (isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $message = 'Benutzer erstellt';
        } catch (Exception $e) {
            $message = 'Fehler beim Erstellen des Benutzers';
        }
    }
}

if (isset($_POST['upload_doc']) && isset($_FILES['document'])) {
    if ($_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['document']['name']);
        $storedName = uniqid() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $origName);
        if (move_uploaded_file($_FILES['document']['tmp_name'], __DIR__ . '/uploads/' . $storedName)) {
            $stmt = $pdo->prepare('INSERT INTO documents (filename, original_name) VALUES (?, ?)');
            $stmt->execute([$storedName, $origName]);
            $message = 'Dokument hochgeladen';
        } else {
            $message = 'Fehler beim Speichern des Dokuments';
        }
    } else {
        $message = 'Fehler beim Upload';
    }
}

if (isset($_POST['assign'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($userId && $docId) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO user_documents (user_id, document_id) VALUES (?, ?)');
        $stmt->execute([$userId, $docId]);
        $message = 'Dokument zugewiesen';
    }
}

$users = $pdo->query('SELECT id, username FROM users')->fetchAll(PDO::FETCH_ASSOC);
$docs = $pdo->query('SELECT id, original_name FROM documents')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Admin</title>
</head>
<body>
<p><a href="index.php">Zurück</a></p>
<?php if ($message) echo '<p>' . htmlspecialchars($message) . '</p>'; ?>

<h2>Benutzer anlegen</h2>
<form method="post">
    <label>Benutzername: <input type="text" name="username"></label>
    <label>Passwort: <input type="password" name="password"></label>
    <button type="submit" name="create_user">Anlegen</button>
</form>

<h2>Dokument hochladen</h2>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="document">
    <button type="submit" name="upload_doc">Hochladen</button>
</form>

<h2>Dokument zuweisen</h2>
<form method="post">
    <select name="user_id">
        <?php foreach ($users as $u) { echo '<option value="' . $u['id'] . '">' . htmlspecialchars($u['username']) . '</option>'; } ?>
    </select>
    <select name="doc_id">
        <?php foreach ($docs as $d) { echo '<option value="' . $d['id'] . '">' . htmlspecialchars($d['original_name']) . '</option>'; } ?>
    </select>
    <button type="submit" name="assign">Zuweisen</button>
</form>
</body>
</html>
