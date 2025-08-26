<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT filename, original_name FROM documents JOIN user_documents ON documents.id = user_documents.document_id WHERE documents.id = ? AND user_documents.user_id = ?');
$stmt->execute([$id, $_SESSION['user_id']]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    echo 'Datei nicht gefunden';
    exit;
}

$path = __DIR__ . '/uploads/' . $file['filename'];
if (!file_exists($path)) {
    http_response_code(404);
    echo 'Datei fehlt auf dem Server';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
readfile($path);
exit;
?>
