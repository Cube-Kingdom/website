<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad Request'); }

$user_id = (int)$_SESSION['user_id'];
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin']===1;

if ($is_admin) {
    $stmt = db()->prepare('SELECT filename, path FROM documents WHERE id = ?');
    $stmt->execute([$id]);
} else {
    $stmt = db()->prepare('
        SELECT d.filename, d.path
        FROM documents d
        JOIN user_documents ud ON ud.document_id = d.id
        WHERE d.id = ? AND ud.user_id = ?
    ');
    $stmt->execute([$id, $user_id]);
}
$file = $stmt->fetch();

if (!$file || !is_file($file['path'])) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$basename = basename($file['filename']);
$path = $file['path'];
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$basename.'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: no-cache');
readfile($path);
exit;
