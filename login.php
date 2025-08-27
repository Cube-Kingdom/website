<?php
declare(strict_types=1);

// Keine Ausgabe vor Headern!
ob_start();

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Session sicherstellen (ohne doppeltes Starten)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Bereits eingeloggt? -> weiter zur Startseite
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// CSRF bereitstellen
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// POST: Loginversuch
if (is_post()) {
    // CSRF prüfen
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error');
        header('Location: login.php'); exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('Bitte Benutzername und Passwort ausfüllen.', 'error');
        header('Location: login.php'); exit;
    }

    try {
        $stmt = db()->prepare('SELECT id, username, password_hash, is_admin FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            // Session härten
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$row['id'];
            $_SESSION['username'] = (string)$row['username'];
            $_SESSION['is_admin'] = (int)$row['is_admin'];

            flash('Erfolgreich angemeldet.', 'success');
            header('Location: index.php'); exit;
        } else {
            // Hinweis: vage Fehlermeldung aus Sicherheitsgründen
            flash('Ungültige Zugangsdaten.', 'error');
            header('Location: login.php'); exit;
        }
    } catch (Throwable $e) {
        // Nur knapper Hinweis im UI; Details ins PHP-Error-Log
        error_log('LOGIN ERROR: ' . $e->getMessage());
        flash('Interner Fehler beim Login.', 'error');
        header('Location: login.php'); exit;
    }
}

// GET: Formular rendern
render_header('Login', /*show_nav=*/false);
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>
<section class="row">
  <div class="card" style="max-width:420px">
    <h2>Anmelden</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <label>Benutzername<br><input type="text" name="username" required></label><br><br>
      <label>Passwort<br><input type="password" name="password" required></label><br><br>
      <button class="btn btn-primary" type="submit">Login</button>
    </form>
    <p style="margin-top:12px"><a class="btn" href="index.php">← Zurück</a></p>
  </div>
</section>
<?php render_footer();
ob_end_flush();
