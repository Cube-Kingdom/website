<?php
session_start();
require 'db.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$message = '';

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT id, password, is_admin FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];
        $_SESSION['username'] = $username;
        header('Location: index.php');
        exit;
    } else {
        $message = 'Ungültige Anmeldedaten';
    }
}

$loggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dokumente</title>
</head>
<body>
<?php if (!$loggedIn): ?>
    <h1>Login</h1>
    <?php if ($message) echo '<p>' . htmlspecialchars($message) . '</p>'; ?>
    <form method="post">
        <label>Benutzername: <input type="text" name="username"></label><br>
        <label>Passwort: <input type="password" name="password"></label><br>
        <button type="submit" name="login">Anmelden</button>
    </form>
<?php else: ?>
    <p>Willkommen, <?php echo htmlspecialchars($_SESSION['username']); ?> | <a href="?logout=1">Logout</a></p>
    <?php if (!empty($_SESSION['is_admin'])): ?>
        <p><a href="admin.php">Zur Admin-Seite</a></p>
    <?php endif; ?>
    <h2>Ihre Dokumente</h2>
    <ul>
        <?php
        $stmt = $pdo->prepare('SELECT documents.id, documents.original_name FROM documents JOIN user_documents ON documents.id = user_documents.document_id WHERE user_documents.user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$docs) {
            echo '<li>Keine Dokumente verfügbar.</li>';
        } else {
            foreach ($docs as $doc) {
                $name = htmlspecialchars($doc['original_name']);
                echo "<li><a href='download.php?id={$doc['id']}'>{$name}</a></li>";
            }
        }
        ?>
    </ul>
<?php endif; ?>
</body>
</html>
