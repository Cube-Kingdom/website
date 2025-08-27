<?php
// admin.php – Verwaltung inkl. Bewerbungen (Kachel-Übersicht + Engere Auswahl), Nutzerverwaltung, Discord/Whitelist
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- Polyfills ---------- */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

/* ---------- Schema-Sicherung ---------- */
function ensure_schema(): void {
    $pdo = db();

    // applications (mit project_name / generated_password / created_user_id)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS applications (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          youtube_url TEXT NOT NULL,
          youtube_video_id TEXT,
          mc_name TEXT NOT NULL,
          mc_uuid TEXT,
          discord_name TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT 'pending',
          generated_password TEXT,
          created_user_id INTEGER,
          project_name TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");
    $cols  = $pdo->query("PRAGMA table_info(applications)")->fetchAll();
    $names = array_map(fn($r)=>$r['name'], $cols);
    if (!in_array('generated_password', $names, true)) $pdo->exec("ALTER TABLE applications ADD COLUMN generated_password TEXT");
    if (!in_array('created_user_id',   $names, true)) $pdo->exec("ALTER TABLE applications ADD COLUMN created_user_id INTEGER");
    if (!in_array('project_name',      $names, true)) $pdo->exec("ALTER TABLE applications ADD COLUMN project_name TEXT");

    // Einmalbewerbung-Indices
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_app_unique_mc ON applications(lower(mc_name));");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_app_unique_discord ON applications(lower(discord_name));");

    // users.discord_name nachrüsten
    $uCols  = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $uNames = array_map(fn($r)=>$r['name'], $uCols);
    if (!in_array('discord_name', $uNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN discord_name TEXT");
    }

    // posts.image_path (Titelbild)
    $pCols  = $pdo->query("PRAGMA table_info(posts)")->fetchAll();
    $pNames = array_map(fn($r)=>$r['name'], $pCols);
    if (!in_array('image_path', $pNames, true)) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN image_path TEXT");
    }

    // Whitelist-Gedächtnis
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS server_whitelist_seen (
          uuid TEXT PRIMARY KEY,
          first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");
}
ensure_schema();

/* ---------- Hilfsfunktionen ---------- */
if (!function_exists('server_swap_sort')) {
    function server_swap_sort(int $idA, int $idB): void {
        $pdo = db();
        $pdo->beginTransaction();
        $a = $pdo->prepare("SELECT id, sort_order FROM minecraft_servers WHERE id=?"); $a->execute([$idA]); $ra = $a->fetch();
        $b = $pdo->prepare("SELECT id, sort_order FROM minecraft_servers WHERE id=?"); $b->execute([$idB]); $rb = $b->fetch();
        if ($ra && $rb) {
            $pdo->prepare("UPDATE minecraft_servers SET sort_order=? WHERE id=?")->execute([$rb['sort_order'], $ra['id']]);
            $pdo->prepare("UPDATE minecraft_servers SET sort_order=? WHERE id=?")->execute([$ra['sort_order'], $rb['id']]);
        }
        $pdo->commit();
    }
}
if (!function_exists('generate_password')) {
    function generate_password(int $len = 12): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = ''; for ($i=0;$i<$len;$i++) $out .= $chars[random_int(0, strlen($chars)-1)];
        return $out;
    }
}

/* ---------- Discord-Helper (mit Guards) ---------- */
if (!function_exists('discord_cfg')) {
    function discord_cfg(): array {
        return [
            'token'       => get_setting('discord_bot_token', ''),
            'guild_id'    => get_setting('discord_guild_id', ''),
            'fallback_ch' => get_setting('discord_fallback_channel_id',''),
        ];
    }
}
if (!function_exists('http_json')) {
    function http_json(string $method, string $url, array $headers, ?array $body = null, int $timeout=8): ?array {
        if (!function_exists('curl_init')) { error_log('Discord: php-curl missing'); return null; }
        $ch = curl_init($url);
        $hdr = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => $hdr
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch); if ($resp === false) { curl_close($ch); return null; }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return ['code'=>$code,'json'=>json_decode($resp,true)];
    }
}
if (!function_exists('discord_find_user_id_by_name')) {
    function discord_find_user_id_by_name(string $name): ?string {
        $cfg = discord_cfg(); if ($cfg['token']==='' || $cfg['guild_id']==='') return null;
        $url = "https://discord.com/api/v10/guilds/{$cfg['guild_id']}/members/search?query=".rawurlencode($name)."&limit=5";
        $res = http_json('GET', $url, ['Authorization: Bot '.$cfg['token']]);
        if (!$res || $res['code'] !== 200 || !is_array($res['json'])) return null;
        $nameLower = mb_strtolower($name);
        foreach ($res['json'] as $m) {
            $u = $m['user'] ?? [];
            $cand = [mb_strtolower($u['global_name'] ?? ''), mb_strtolower($u['username'] ?? ''), mb_strtolower($m['nick'] ?? '')];
            if (in_array($nameLower, $cand, true)) return $u['id'] ?? null;
        }
        return $res['json'][0]['user']['id'] ?? null;
    }
}
if (!function_exists('discord_dm_user_id')) {
    function discord_dm_user_id(string $userId, string $message): bool {
        $cfg = discord_cfg(); if ($cfg['token']==='') return false;
        $dm = http_json('POST','https://discord.com/api/v10/users/@me/channels',
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['recipient_id'=>$userId]
        );
        $chId = $dm['json']['id'] ?? null; if (!$chId) return false;
        $send = http_json('POST',"https://discord.com/api/v10/channels/{$chId}/messages",
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['content'=>$message]
        );
        return ($send && $send['code']>=200 && $send['code']<300);
    }
}
if (!function_exists('discord_send_to_fallback')) {
    function discord_send_to_fallback(string $message): bool {
        $cfg = discord_cfg(); if ($cfg['token']==='' || $cfg['fallback_ch']==='') return false;
        $send = http_json('POST',"https://discord.com/api/v10/channels/{$cfg['fallback_ch']}/messages",
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['content'=>$message]
        );
        return ($send && $send['code']>=200 && $send['code']<300);
    }
}
if (!function_exists('discord_notify_by_name')) {
    function discord_notify_by_name(string $discordName, string $message): void {
        $uid = discord_find_user_id_by_name($discordName); $ok = false;
        if ($uid) $ok = discord_dm_user_id($uid, $message);
        if (!$ok) discord_send_to_fallback("Benachrichtigung für **{$discordName}** (DM nicht möglich): ".$message);
    }
}

/* ---------- Whitelist-Monitor ---------- */
if (!function_exists('normalize_uuid')) {
    function normalize_uuid(string $u): string { return strtolower(str_replace('-', '', trim($u))); }
}
if (!function_exists('whitelist_check_and_notify')) {
    function whitelist_check_and_notify(): int {
        $path = get_setting('whitelist_json_path', '/home/crafty/crafty-4/servers/8c66e586-dbda-4c99-a447-b944b8677c88/whitelist.json');
        if (!is_readable($path)) return 0;
        $json = @file_get_contents($path); if ($json === false) return 0;
        $arr = json_decode($json, true); if (!is_array($arr)) return 0;

        $pdo  = db(); $sent = 0;
        foreach ($arr as $entry) {
            $uuidDash = (string)($entry['uuid'] ?? ''); $name = (string)($entry['name'] ?? '');
            if ($uuidDash === '' || $name === '') continue;
            $uuid = normalize_uuid($uuidDash);

            $chk = $pdo->prepare("SELECT 1 FROM server_whitelist_seen WHERE uuid=?");
            $chk->execute([$uuid]); if ($chk->fetch()) continue;

            $pdo->prepare("INSERT INTO server_whitelist_seen(uuid) VALUES(?)")->execute([$uuid]);

            $app = $pdo->prepare("SELECT discord_name FROM applications WHERE lower(mc_uuid)=? OR lower(mc_name)=? ORDER BY datetime(created_at) DESC LIMIT 1");
            $app->execute([$uuid, strtolower($name)]);
            $disc = (string)($app->fetch()['discord_name'] ?? '');
            if ($disc === '') {
                $usr = $pdo->prepare("SELECT u.discord_name FROM users u WHERE lower(u.username)=? LIMIT 1");
                $usr->execute([strtolower($name)]);
                $disc = (string)($usr->fetch()['discord_name'] ?? '');
            }
            if ($disc !== '') { discord_notify_by_name($disc, "✅ **{$name}** wurde auf dem Server **whitelisted**."); $sent++; }
        }
        return $sent;
    }
}

/* ---------- POST-Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('Ungültiges CSRF-Token.','error'); header('Location: admin.php'); exit; }
    $a = $_POST['action'] ?? '';
    try {
        // Nutzer anlegen
        if ($a === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            if ($username===''||$password==='') { flash('Username/Passwort fehlt.','error'); }
            else {
                db()->prepare('INSERT INTO users (username,password_hash,is_admin) VALUES (?,?,?)')
                   ->execute([$username,password_hash($password,PASSWORD_DEFAULT),$is_admin]);
                flash('Nutzer angelegt.','success');
            }

        // Nutzer löschen
        } elseif ($a === 'delete_user') {
            $uid    = (int)($_POST['user_id'] ?? 0);
            $selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

            if ($uid <= 0) {
                flash('Ungültige Nutzer-ID.','error');
            } elseif ($uid === $selfId) {
                flash('Eigener Account kann hier nicht gelöscht werden.','error');
            } else {
                $pdo = db();
                $row = $pdo->prepare('SELECT is_admin FROM users WHERE id=?'); $row->execute([$uid]); $r=$row->fetch();
                if (!$r) {
                    flash('Benutzer existiert nicht.','error');
                } else {
                    if ((int)$r['is_admin'] === 1) {
                        $c = $pdo->query('SELECT COUNT(*) AS n FROM users WHERE is_admin=1')->fetch();
                        if ((int)$c['n'] <= 1) {
                            flash('Letzten Admin kannst du nicht löschen.','error');
                            header('Location: admin.php'); exit;
                        }
                    }
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM user_documents WHERE user_id=?')->execute([$uid]);
                        $pdo->prepare('UPDATE applications SET created_user_id=NULL, generated_password=NULL WHERE created_user_id=?')->execute([$uid]);
                        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
                        $pdo->commit();
                        flash('Nutzer gelöscht.','success');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('DELETE USER ERROR: '.$e->getMessage());
                        flash('Fehler beim Löschen: '.$e->getMessage(), 'error');
                    }
                }
            }

        // Passwort setzen
        } elseif ($a === 'admin_set_password') {
            $target_id = (int)($_POST['user_id'] ?? 0);
            $new       = (string)($_POST['new_password'] ?? '');
            $confirm   = (string)($_POST['confirm_password'] ?? '');
            if ($target_id<=0) flash('Ungültige Nutzer-ID.','error');
            elseif ($new!==$confirm) flash('Neues Passwort und Bestätigung stimmen nicht überein.','error');
            elseif (strlen($new)<8) flash('Neues Passwort muss mindestens 8 Zeichen lang sein.','error');
            else {
                db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$target_id]);
                flash('Passwort gesetzt.','success');
            }

        // Dokumente
        } elseif ($a === 'upload_document') {
            global $UPLOADS;
            $ok = isset($_FILES['document']) && $_FILES['document']['error']===UPLOAD_ERR_OK;
            if (!$ok) flash('Upload fehlgeschlagen.','error');
            else {
                $name = basename((string)$_FILES['document']['name']);
                $safe = preg_replace('/[^A-Za-z0-9._-]/','_',$name) ?: ('file_'.time());
                $target = $UPLOADS.'/'.$safe;
                if (!move_uploaded_file($_FILES['document']['tmp_name'],$target)) flash('Konnte Datei nicht speichern.','error');
                else { @chmod($target,0664); db()->prepare('INSERT INTO documents (filename,path) VALUES (?,?)')->execute([$safe,$target]); flash('Dokument hochgeladen.','success'); }
            }
        } elseif ($a === 'assign_document') {
            $user_id=(int)($_POST['user_id']??0); $doc_id=(int)($_POST['doc_id']??0);
            $st=db()->prepare('INSERT OR IGNORE INTO user_documents (user_id,document_id) VALUES (?,?)'); $st->execute([$user_id,$doc_id]);
            if ($st->rowCount()>0) {
                $pdo=db();
                $u=$pdo->prepare('SELECT username,discord_name FROM users WHERE id=?'); $u->execute([$user_id]); $usr=$u->fetch();
                $disc=(string)($usr['discord_name']??'');
                if ($disc==='') { $q=$pdo->prepare('SELECT discord_name FROM applications WHERE created_user_id=? ORDER BY datetime(created_at) DESC LIMIT 1'); $q->execute([$user_id]); $disc=(string)($q->fetch()['discord_name']??''); }
                $d=$pdo->prepare('SELECT filename FROM documents WHERE id=?'); $d->execute([$doc_id]); $file=(string)($d->fetch()['filename']??'ein Dokument');
                if ($disc!=='') discord_notify_by_name($disc,"📄 Dir wurde ein neues Dokument zugewiesen: **{$file}**.");
            }
            flash('Dokument zugewiesen.','success');
        } elseif ($a === 'unassign_document') {
            $user_id=(int)($_POST['user_id']??0); $doc_id=(int)($_POST['doc_id']??0);
            db()->prepare('DELETE FROM user_documents WHERE user_id=? AND document_id=?')->execute([$user_id,$doc_id]);
            flash('Zuweisung entfernt.','success');

        // Posts / Server / Settings
        } elseif ($a === 'create_post') {
            $title=trim((string)($_POST['title']??'')); $content=trim((string)($_POST['content']??'')); $published=isset($_POST['published'])?1:0;
            if ($title===''||$content==='') {
                flash('Titel/Inhalt darf nicht leer sein.','error');
            } else {
                // optionales Bild
                $imageUrl = null;
                if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === UPLOAD_ERR_OK) {
                    $tmp  = $_FILES['post_image']['tmp_name'];
                    $name = basename((string)$_FILES['post_image']['name']);

                    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                    $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
                    if ($finfo) finfo_close($finfo);
                    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
                    if (in_array($mime, $allowed, true)) {
                        $ext   = pathinfo($name, PATHINFO_EXTENSION) ?: 'img';
                        $base  = preg_replace('/[^A-Za-z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME)) ?: ('img_'.time());
                        $safe  = $base.'_'.substr(sha1((string)microtime(true)),0,6).'.'.$ext;

                        $dir = __DIR__.'/uploads/posts';
                        if (!is_dir($dir)) @mkdir($dir, 0775, true);
                        $dest = $dir.'/'.$safe;

                        if (move_uploaded_file($tmp, $dest)) {
                            @chmod($dest, 0664);
                            $imageUrl = '/uploads/posts/'.$safe;
                        } else {
                            flash('Bild konnte nicht gespeichert werden.','error');
                        }
                    } else {
                        flash('Ungültiges Bildformat. Erlaubt: JPG, PNG, GIF, WebP, SVG.','error');
                    }
                }

                db()->prepare('INSERT INTO posts (title,content,published,image_path) VALUES (?,?,?,?)')
                   ->execute([$title,$content,$published,$imageUrl]);
                flash('Post erstellt.','success');
            }
        } elseif ($a === 'delete_post') {
            $id=(int)($_POST['id']??0);

            // Bilddatei mitlöschen
            $st = db()->prepare('SELECT image_path FROM posts WHERE id=?');
            $st->execute([$id]);
            $img = $st->fetchColumn();
            if ($img && str_starts_with($img, '/uploads/posts/')) {
                $p = __DIR__.$img;
                if (is_file($p)) @unlink($p);
            }

            db()->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
            flash('Post gelöscht.','success');
        } elseif ($a === 'toggle_publish') {
            $id=(int)($_POST['id']??0); $r=db()->prepare('SELECT published FROM posts WHERE id=?'); $r->execute([$id]); $row=$r->fetch();
            if ($row){ $new=((int)$row['published']===1)?0:1; db()->prepare('UPDATE posts SET published=? WHERE id=?')->execute([$new,$id]); flash($new?'Post veröffentlicht.':'Post unveröffentlicht.','success'); }
        } elseif ($a === 'update_post') {
            $id=(int)($_POST['id']??0); $title=trim((string)($_POST['title']??'')); $content=trim((string)($_POST['content']??'')); $published=isset($_POST['published'])?1:0;

            $st = db()->prepare('SELECT image_path FROM posts WHERE id=?'); $st->execute([$id]);
            $current = $st->fetchColumn();
            $newImage = $current;

            if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['post_image']['tmp_name'];
                $name = basename((string)$_FILES['post_image']['name']);

                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
                if ($finfo) finfo_close($finfo);
                $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
                if (in_array($mime, $allowed, true)) {
                    $ext   = pathinfo($name, PATHINFO_EXTENSION) ?: 'img';
                    $base  = preg_replace('/[^A-Za-z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME)) ?: ('img_'.time());
                    $safe  = $base.'_'.substr(sha1((string)microtime(true)),0,6).'.'.$ext;

                    $dir = __DIR__.'/uploads/posts';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $dest = $dir.'/'.$safe;

                    if (move_uploaded_file($tmp, $dest)) {
                        @chmod($dest, 0664);
                        $newImage = '/uploads/posts/'.$safe;

                        // altes Bild entfernen
                        if ($current && str_starts_with($current, '/uploads/posts/')) {
                            $oldPath = __DIR__.$current;
                            if (is_file($oldPath)) @unlink($oldPath);
                        }
                    } else {
                        flash('Neues Bild konnte nicht gespeichert werden.','error');
                    }
                } else {
                    flash('Ungültiges Bildformat. Erlaubt: JPG, PNG, GIF, WebP, SVG.','error');
                }
            }

            db()->prepare('UPDATE posts SET title=?,content=?,published=?,image_path=? WHERE id=?')
               ->execute([$title,$content,$published,$newImage,$id]);
            flash('Post aktualisiert.','success');
        } elseif ($a === 'add_server') {
            $name=trim((string)($_POST['name']??'')); $host=trim((string)($_POST['host']??'')); $port=(int)($_POST['port']??25565); $enabled=isset($_POST['enabled'])?1:0; $sort=(int)($_POST['sort_order']??0);
            if ($name===''||$host==='') flash('Name/Host darf nicht leer sein.','error');
            else { db()->prepare('INSERT INTO minecraft_servers (name,host,port,enabled,sort_order) VALUES (?,?,?,?,?)')->execute([$name,$host,$port,$enabled,$sort]); flash('Server hinzugefügt.','success'); }
        } elseif ($a === 'delete_server') {
            $id=(int)($_POST['id']??0); db()->prepare('DELETE FROM minecraft_servers WHERE id=?')->execute([$id]); flash('Server gelöscht.','success');
        } elseif ($a === 'server_move_up' || $a === 'server_move_down') {
            $id=(int)$_POST['id']; $cur=db()->prepare('SELECT id,sort_order FROM minecraft_servers WHERE id=?'); $cur->execute([$id]); $c=$cur->fetch();
            if ($c){
                if ($a==='server_move_up'){
                    $n=db()->prepare('SELECT id,sort_order FROM minecraft_servers WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1'); $n->execute([$c['sort_order']]);
                } else {
                    $n=db()->prepare('SELECT id,sort_order FROM minecraft_servers WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1'); $n->execute([$c['sort_order']]);
                }
                $nn=$n->fetch(); if($nn) server_swap_sort((int)$c['id'],(int)$nn['id']);
            }
        } elseif ($a === 'save_apply_settings') {
            set_setting('apply_enabled', isset($_POST['apply_enabled'])?'1':'0');
            $title = trim((string)($_POST['apply_title'] ?? 'Projekt-Anmeldung'));
            set_setting('apply_title', $title !== '' ? $title : 'Projekt-Anmeldung');
            set_setting('discord_bot_token', trim((string)($_POST['discord_bot_token'] ?? '')));
            set_setting('discord_guild_id', trim((string)($_POST['discord_guild_id'] ?? '')));
            set_setting('discord_fallback_channel_id', trim((string)($_POST['discord_fallback_channel_id'] ?? '')));
            set_setting('whitelist_json_path', trim((string)($_POST['whitelist_json_path'] ?? '')) ?: '/home/crafty/crafty-4/servers/8c66e586-dbda-4c99-a447-b944b8677c88/whitelist.json');
            flash('Einstellungen gespeichert.','success');
        } elseif ($a === 'discord_test_message') {
            $name = trim((string)($_POST['discord_test_name'] ?? ''));
            if ($name !== '') {
                $uid = discord_find_user_id_by_name($name); $ok=false; if($uid) $ok=discord_dm_user_id($uid,"🔔 Testnachricht aus dem Admin-Panel.");
                if (!$ok) discord_send_to_fallback("Benachrichtigung für **{$name}** (DM nicht möglich): 🔔 Test.");
                flash('Testnachricht gesendet (oder Fallback).','success');
            }
        } elseif ($a === 'whitelist_check_now') {
            $n = whitelist_check_and_notify(); flash("Whitelist geprüft: {$n} neue Benachrichtigungen.",'success');
        } else {
            flash('Unbekannte Aktion.','error');
        }
    } catch (Throwable $e) {
        error_log('ADMIN ERROR: '.$e->getMessage()); flash('Fehler: '.$e->getMessage(),'error');
    }
    header('Location: admin.php'); exit;
}

/* ---------- Auto-Whitelist-Check bei Aufruf ---------- */
$autoSent = whitelist_check_and_notify();
if ($autoSent > 0) flash("Whitelist: {$autoSent} neue Einträge erkannt und benachrichtigt.", 'info');

/* ---------- Daten laden ---------- */
$users    = db()->query('SELECT id, username, is_admin, COALESCE(discord_name,"") AS discord_name FROM users ORDER BY username ASC')->fetchAll();
$docs     = db()->query('SELECT id, filename FROM documents ORDER BY filename ASC')->fetchAll();
$userDocs = [];
$stmt = db()->query("
  SELECT u.id AS uid, d.id AS did, d.filename
  FROM users u
  LEFT JOIN user_documents ud ON ud.user_id = u.id
  LEFT JOIN documents d ON d.id = ud.document_id
  ORDER BY u.username, d.filename
");
foreach ($stmt as $row) {
    $uid=(int)$row['uid'];
    if(!isset($userDocs[$uid])) $userDocs[$uid]=[];
    if(!empty($row['did'])) $userDocs[$uid][]=['id'=>(int)$row['did'],'filename'=>$row['filename']];
}
$posts   = db()->query('SELECT id, title, content, created_at, published FROM posts ORDER BY datetime(created_at) DESC')->fetchAll();
$servers = db()->query('SELECT id, name, host, port, enabled, sort_order FROM minecraft_servers ORDER BY sort_order, name')->fetchAll();
$apps    = db()->query("SELECT id, mc_name, mc_uuid, status FROM applications ORDER BY datetime(created_at) DESC")->fetchAll();

$apply_enabled = (get_setting('apply_enabled','0') === '1');
$apply_title   = get_setting('apply_title','Projekt-Anmeldung');
$cfg_token     = get_setting('discord_bot_token', '');
$cfg_guild     = get_setting('discord_guild_id', '');
$cfg_fallback  = get_setting('discord_fallback_channel_id', '');
$cfg_whitelist = get_setting('whitelist_json_path', '/home/crafty/crafty-4/servers/8c66e586-dbda-4c99-a447-b944b8677c88/whitelist.json');

/* ---------- Render ---------- */
render_header('Admin – Verwaltung');
?>
<style>
  .badge{padding:2px 10px;border-radius:999px;font-size:.85rem;display:inline-block;border:1px solid transparent}
  .badge.pending{background:#eef;border-color:#cce;color:#223}
  .badge.accepted{background:#e9f7ef;border-color:#c6e6cf;color:#185e2d}
  .badge.rejected{background:#fdecea;border-color:#f5c6cb;color:#8a1f1f}
  .badge.shortlisted{background:#fff7e6;border-color:#ffd08a;color:#8a5a00}
  .theme-dark .badge.shortlisted{background:#413214;border-color:#7a5a1e;color:#ffdca3}

  .btn-sm{padding:6px 8px;font-size:.9rem}
  .stack-sm{display:flex;gap:6px;flex-wrap:wrap}
  .mc-uuid{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;color:#666;font-size:.85rem}
  .doc-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border:1px solid var(--border);border-radius:999px;background:var(--card);margin:2px}

  /* Bewerber-Kacheln */
  .apps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
  .app-card{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--card);transition:transform .06s ease, box-shadow .06s ease;text-decoration:none;color:inherit}
  .app-card:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.08)}
  .app-head{width:32px;height:32px;border-radius:6px;background:#f0f0f0;object-fit:cover}
  .app-name{font-weight:600}
  .app-meta{display:flex;flex-direction:column}
  .app-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}

  /* Scrollbare Tabellen */
  .table-wrap{overflow:auto;max-height:360px;border:1px solid var(--border);border-radius:8px}
  .table-wrap table{width:100%;border-collapse:separate;border-spacing:0;min-width:800px}
  .table-wrap th,.table-wrap td{padding:8px 10px;border-bottom:1px solid var(--border);white-space:nowrap;background:var(--card)}
  .table-wrap thead th{position:sticky;top:0;background:var(--muted)}
</style>
<?php foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; } ?>

<!-- Benutzer/Passwörter/Dokumente -->
<section class="row">
  <div class="card">
    <h2>Benutzer anlegen</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="create_user">
      <label>Username<br><input type="text" name="username" required></label><br><br>
      <label>Passwort<br><input type="password" name="password" required></label><br><br>
      <label><input type="checkbox" name="is_admin" value="1"> Admin</label><br><br>
      <button class="btn btn-primary" type="submit">Erstellen</button>
    </form>
  </div>

  <div class="card" style="min-width:480px">
    <h2>Passwörter & Nutzer (scrollbar)</h2>
    <?php if (empty($users)): ?>
      <p><em>Keine Benutzer vorhanden.</em></p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Benutzer</th><th>Rolle</th><th>Discord</th><th>Passwort setzen</th><th>Aktion</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?=htmlspecialchars($u['username'])?></td>
              <td><?=((int)$u['is_admin']===1)?'Admin':'User'?></td>
              <td><?=htmlspecialchars($u['discord_name'] ?? '')?></td>
              <td>
                <form method="post" autocomplete="off" style="display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="admin_set_password">
                  <input type="hidden" name="user_id" value="<?=$u['id']?>">
                  <input type="password" name="new_password" placeholder="Neu" required>
                  <input type="password" name="confirm_password" placeholder="Bestätigen" required>
                  <button class="btn btn-sm" type="submit">Setzen</button>
                </form>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('Nutzer wirklich löschen?')">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="user_id" value="<?=$u['id']?>">
                  <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:6px"><small>Header ist fixiert, bei vielen Nutzern kannst du horizontal/vertikal scrollen.</small></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Dokument hochladen</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="upload_document">
      <input type="file" name="document" required><br><br>
      <button class="btn btn-primary" type="submit">Hochladen</button>
    </form>
  </div>
</section>

<section class="row">
  <div class="card">
    <h2>Dokument zuweisen</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="assign_document">
      <label>Benutzer<br>
        <select name="user_id" required>
          <option value="" disabled selected>– auswählen –</option>
          <?php foreach ($users as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['username'])?><?=$u['is_admin']?' (Admin)':''?></option>
          <?php endforeach; ?>
        </select>
      </label><br><br>
      <label>Dokument<br>
        <select name="doc_id" required>
          <option value="" disabled selected>– auswählen –</option>
          <?php foreach ($docs as $d): ?>
            <option value="<?=$d['id']?>"><?=htmlspecialchars($d['filename'])?></option>
          <?php endforeach; ?>
        </select>
      </label><br><br>
      <button class="btn btn-primary" type="submit">Zuweisen</button>
    </form>
  </div>

  <div class="card">
    <h2>Zuweisungen</h2>
    <?php if (empty($userDocs)): ?>
      <p><em>Keine Zuweisungen vorhanden.</em></p>
    <?php else: ?>
      <?php foreach ($users as $u): $uid=(int)$u['id']; $list=$userDocs[$uid]??[]; ?>
        <div style="margin-bottom:8px">
          <strong><?=htmlspecialchars($u['username'])?>:</strong>
          <?php if (empty($list)): ?><span style="color:#666">—</span>
          <?php else: foreach ($list as $d): ?>
            <span class="doc-chip">
              <?=htmlspecialchars($d['filename'])?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="unassign_document">
                <input type="hidden" name="user_id" value="<?=$uid?>">
                <input type="hidden" name="doc_id" value="<?=$d['id']?>">
                <button class="btn btn-sm" title="Entfernen" type="submit">✕</button>
              </form>
            </span>
          <?php endforeach; endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<section class="row">
  <div class="card" style="flex:1">
    <h2>Post erstellen</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="create_post">
      <label>Titel<br><input type="text" name="title" required></label><br><br>
      <label>Inhalt<br><textarea name="content" rows="6" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px" required></textarea></label><br><br>
      <label>Bild (optional)<br><input type="file" name="post_image" accept="image/*"></label><br><br>
      <label><input type="checkbox" name="published" value="1" checked> Veröffentlicht</label><br><br>
      <button class="btn btn-primary" type="submit">Speichern</button>
    </form>
  </div>

  <div class="card" style="flex:1">
    <h2>Minecraft-Server hinzufügen</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="add_server">
      <label>Name<br><input type="text" name="name" required></label><br><br>
      <label>Host / IP<br><input type="text" name="host" required></label><br><br>
      <label>Port<br><input type="text" name="port" value="25565" required></label><br><br>
      <label>Sortierung (Zahl)<br><input type="text" name="sort_order" value="0"></label><br><br>
      <label><input type="checkbox" name="enabled" value="1" checked> Aktiv</label><br><br>
      <button class="btn btn-primary" type="submit">Server speichern</button>
    </form>
  </div>
</section>

<section class="row">
  <div class="card" style="flex:1">
    <h2>Posts</h2>
    <?php if (empty($posts)): ?>
      <p><em>Keine Posts vorhanden.</em></p>
    <?php else: ?>
      <table>
        <thead><tr><th>Titel</th><th>Datum</th><th>Publiziert</th><th style="width:220px">Aktion</th></tr></thead>
        <tbody>
          <?php foreach ($posts as $p): ?>
            <tr>
              <td><?=htmlspecialchars($p['title'])?></td>
              <td><?=htmlspecialchars($p['created_at'])?></td>
              <td><?=((int)$p['published']===1)?'Ja':'Nein'?></td>
              <td class="stack-sm">
                <a class="btn btn-sm" href="admin.php?edit_post=<?=$p['id']?>">Bearbeiten</a>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="toggle_publish">
                  <input type="hidden" name="id" value="<?=$p['id']?>">
                  <button class="btn btn-sm" type="submit"><?=((int)$p['published']===1)?'Unpublish':'Publish'?></button>
                </form>
                <form method="post" onsubmit="return confirm('Post wirklich löschen?')">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="delete_post">
                  <input type="hidden" name="id" value="<?=$p['id']?>">
                  <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="flex:1">
    <h2>Minecraft-Server</h2>
    <?php if (empty($servers)): ?>
      <p><em>Keine Server konfiguriert.</em></p>
    <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Host:Port</th><th>Aktiv</th><th style="width:220px">Aktion</th></tr></thead>
        <tbody>
          <?php foreach ($servers as $s): ?>
            <tr>
              <td><?= (int)$s['sort_order'] ?></td>
              <td><?=htmlspecialchars($s['name'])?></td>
              <td><?=htmlspecialchars($s['host'])?>:<?= (int)$s['port']?></td>
              <td><?=((int)$s['enabled']===1)?'Ja':'Nein'?></td>
              <td class="stack-sm">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="server_move_up">
                  <input type="hidden" name="id" value="<?=$s['id']?>">
                  <button class="btn btn-sm" type="submit">↑ Up</button>
                </form>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="server_move_down">
                  <input type="hidden" name="id" value="<?=$s['id']?>">
                  <button class="btn btn-sm" type="submit">↓ Down</button>
                </form>
                <form method="post" onsubmit="return confirm('Server wirklich löschen?')">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="delete_server">
                  <input type="hidden" name="id" value="<?=$s['id']?>">
                  <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<?php if (isset($_GET['edit_post'])):
  $eid=(int)$_GET['edit_post']; $st=db()->prepare('SELECT id,title,content,published,image_path FROM posts WHERE id=?'); $st->execute([$eid]); $editPost=$st->fetch();
  if ($editPost): ?>
<section class="row">
  <div class="card" style="flex:1">
    <h2>Post bearbeiten</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="update_post">
      <input type="hidden" name="id" value="<?=$editPost['id']?>">
      <label>Titel<br><input type="text" name="title" value="<?=htmlspecialchars($editPost['title'])?>" required></label><br><br>
      <label>Inhalt<br><textarea name="content" rows="8" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px" required><?=htmlspecialchars($editPost['content'])?></textarea></label><br><br>

      <?php if (!empty($editPost['image_path'])): ?>
        <div style="margin-bottom:10px">
          <strong>Aktuelles Bild:</strong><br>
          <img src="<?=htmlspecialchars($editPost['image_path'])?>" alt="" style="max-width:100%;height:auto;border:1px solid var(--border);border-radius:8px">
        </div>
      <?php endif; ?>

      <label>Neues Bild (optional)<br><input type="file" name="post_image" accept="image/*"></label><br><br>

      <label><input type="checkbox" name="published" value="1" <?=((int)$editPost['published']===1)?'checked':''?>> Veröffentlicht</label><br><br>
      <button class="btn btn-primary" type="submit">Speichern</button>
      <a class="btn" href="admin.php">Abbrechen</a>
    </form>
  </div>
</section>
<?php endif; endif; ?>

<!-- ======= Bewerbungen – Kachel-Übersicht (mit Engere Auswahl) ======= -->
<section class="row">
  <div class="card" style="flex:1">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <h2 style="margin:0">Bewerbungen</h2>
      <a class="btn" href="admin_shortlist.php">Engere Auswahl ansehen</a>
    </div>

    <?php if (empty($apps)): ?>
      <p><em>Noch keine Bewerbungen eingegangen.</em></p>
    <?php else: ?>
      <div class="apps-grid">
        <?php foreach ($apps as $a):
          $uuid = $a['mc_uuid'] ? strtolower($a['mc_uuid']) : '';
          $avatar = $uuid ? 'https://crafatar.com/avatars/'.htmlspecialchars($uuid).'?size=32&overlay' : '';
          $st = strtolower($a['status'] ?? 'pending');
          $badgeClass = 'badge ' . (
              $st === 'accepted' ? 'accepted' :
              ($st === 'rejected' ? 'rejected' :
              ($st === 'shortlisted' ? 'shortlisted' : 'pending'))
          );
        ?>
          <div>
            <a class="app-card" href="admin_application.php?id=<?=$a['id']?>" title="Details ansehen">
              <?php if ($avatar): ?><img class="app-head" src="<?=$avatar?>" alt=""><?php else: ?><div class="app-head"></div><?php endif; ?>
              <div class="app-meta">
                <span class="app-name"><?=htmlspecialchars($a['mc_name'])?></span>
                <span class="<?=$badgeClass?>"><?=htmlspecialchars($a['status'])?></span>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="flex:1;max-width:680px">
    <h2>Projekt-Anmeldung & Discord</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="save_apply_settings">
      <label><input type="checkbox" name="apply_enabled" value="1" <?=$apply_enabled?'checked':''?>> Seite aktivieren</label><br><br>
      <label>Titel / Name des Formulars<br>
        <input type="text" name="apply_title" value="<?=htmlspecialchars($apply_title)?>" required>
      </label><br><br>

      <h3>Discord-Bot</h3>
      <label>Bot Token<br><input type="password" name="discord_bot_token" value="<?=htmlspecialchars($cfg_token)?>" placeholder="Bot-Token"></label><br><br>
      <label>Guild ID (zum Suchen)<br><input type="text" name="discord_guild_id" value="<?=htmlspecialchars($cfg_guild)?>" placeholder="1234567890"></label><br><br>
      <label>Fallback-Channel ID (optional)<br><input type="text" name="discord_fallback_channel_id" value="<?=htmlspecialchars($cfg_fallback)?>" placeholder="1234567890"></label><br><br>

      <h3>Whitelist</h3>
      <label>Pfad zur whitelist.json<br><input type="text" name="whitelist_json_path" value="<?=htmlspecialchars($cfg_whitelist)?>"></label><br><br>

      <button class="btn btn-primary" type="submit">Speichern</button>
    </form>

    <form method="post" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="discord_test_message">
      <label>Test an Discord-Namen senden<br><input type="text" name="discord_test_name" placeholder="Discord-Name"></label>
      <button class="btn" type="submit">Test senden</button>
    </form>

    <form method="post" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="whitelist_check_now">
      <button class="btn" type="submit">Whitelist jetzt prüfen</button>
    </form>
  </div>
</section>

<section>
  <a href="index.php" class="btn">← Zurück zur Startseite</a>
</section>
<?php render_footer(); ?>
