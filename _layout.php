<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ---- Flash-API ---- */
if (!function_exists('flash')) {
    function flash(string $msg, string $type='info'): void { $_SESSION['flash'][] = [$type, $msg]; }
}
if (!function_exists('consume_flashes')) {
    function consume_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
}

/* ---- Layout ---- */
if (!function_exists('render_header')) {
    function render_header(string $title, bool $show_nav = true): void {
        $username = $_SESSION['username'] ?? null;
        $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

        // apply.php Link nur anzeigen, wenn Setting aktiv (db.php muss vorher eingebunden sein)
        $applyEnabled = function_exists('get_setting') ? (get_setting('apply_enabled','0') === '1') : false;
        $applyTitle   = function_exists('get_setting') ? get_setting('apply_title','Projekt-Anmeldung') : 'Projekt-Anmeldung';

        // Theme
        $theme = $_COOKIE['theme'] ?? 'light';
        if (!in_array($theme, ['light','dark'], true)) { $theme = 'light'; }
        $metaThemeColor = ($theme === 'dark') ? '#1c2230' : '#ffffff';
        $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
        ?>
<!doctype html>
<html lang="de" class="theme-<?=htmlspecialchars($theme)?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars($title)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?=$metaThemeColor?>">

  <!-- Favicons -->
  <link rel="icon" href="/assets/icons/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/icons/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
  <link rel="mask-icon" href="/assets/icons/safari-pinned-tab.svg" color="#0d6efd">
  <link rel="manifest" href="/assets/icons/site.webmanifest">

  <style>
    /* ====== Design-Variablen (Light als Default) ====== */
    :root{
      --bg:#f6f7fb;
      --text:#0f1220;
      --card:#ffffff;
      --muted:#eef1f6;
      --border:#dce1ea;
      --link:#0b62f6;
      --primary:#0b62f6;
      --primary-contrast:#ffffff;
      --danger:#dc3545;
      --danger-contrast:#ffffff;
      --ok:#1a7f37;
      --warn:#a16300;
      --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 12px rgba(0,0,0,.04);
      --radius:12px;
      --radius-sm:8px;
      --radius-lg:16px;
      --pad:14px;
    }

    /* ====== Dark Mode ====== */
    .theme-dark{
      --bg:#1c2230;
      --text:#e9eef6;
      --card:#232a3b;
      --muted:#202738;
      --border:#35405a;
      --link:#7fb3ff;
      --primary:#3b82f6;
      --primary-contrast:#0e1422;
      --danger:#ef4444;
      --danger-contrast:#0e1422;
      --shadow:0 1px 3px rgba(0,0,0,.35),0 4px 14px rgba(0,0,0,.25);
    }

    /* ====== Grundlayout ====== */
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      color:var(--text);
      background:var(--bg);
      font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,"Noto Sans","Liberation Sans",sans-serif;
    }
    a{color:var(--link);text-decoration:none}
    a:hover{text-decoration:underline}

    .container{max-width:1200px;margin:0 auto;padding:18px}
    header.site{
      position:sticky;top:0;z-index:50;
      background:rgba(255,255,255,.85);
      backdrop-filter:blur(8px);
      border-bottom:1px solid var(--border);
    }
    .theme-dark header.site{ background:rgba(28,34,48,.8); }
    .nav{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    /* Brand */
    .brand{
      display:inline-flex;align-items:center;gap:10px;
      font-weight:700;letter-spacing:.2px;margin-right:8px;color:inherit;text-decoration:none
    }
    .brand-logo{width:24px;height:24px;object-fit:contain;border-radius:6px;display:block}

    .spacer{flex:1}
    main{padding:24px 0}

    /* ====== Layout-Utilities ====== */
    .row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}
    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:var(--pad);
      box-shadow:var(--shadow);
      flex:1 1 320px;
      min-width:300px;
    }

    /* ====== Inputs/Buttons ====== */
    input[type=text],input[type=password],input[type=url],input[type=number],select,textarea,input[type=file]{
      width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#111
    }
    .theme-dark input[type=text],.theme-dark input[type=password],.theme-dark input[type=url],.theme-dark input[type=number],.theme-dark select,.theme-dark textarea,.theme-dark input[type=file]{
      background:#1f2534;color:var(--text);border-color:var(--border)
    }
    textarea{min-height:120px;resize:vertical}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
      padding:8px 12px;border:1px solid var(--border);border-radius:10px;
      background:var(--muted);color:var(--text);cursor:pointer;text-decoration:none;white-space:nowrap
    }
    .btn:hover{filter:brightness(1.02);text-decoration:none}
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:var(--primary-contrast)}
    .btn-danger{background:var(--danger);border-color:var(--danger);color:var(--danger-contrast)}
    .btn-ghost{background:transparent}
    .btn-sm{padding:6px 8px;font-size:.9rem;border-radius:8px}
    .is-active{outline:2px solid var(--primary);outline-offset:1px}

    /* ====== Tabellen ====== */
    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top;background:var(--card)}
    thead th{position:sticky;top:0;background:var(--muted);z-index:1}
    .table-wrap{overflow:auto;max-height:360px;border:1px solid var(--border);border-radius:8px}
    .table-wrap table{min-width:800px}

    /* ====== Badges / Chips ====== */
    .badge{padding:2px 10px;border-radius:999px;font-size:.85rem;display:inline-block;border:1px solid transparent}
    .badge.pending{background:#eef;border-color:#cce;color:#223}
    .badge.accepted{background:#e9f7ef;border-color:#c6e6cf;color:#185e2d}
    .badge.rejected{background:#fdecea;border-color:#f5c6cb;color:#8a1f1f}
    .doc-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border:1px solid var(--border);border-radius:999px;background:#f7f7f7;margin:2px}
    .theme-dark .doc-chip{background:#263049}

    /* ====== Bewerber-Kacheln ====== */
    .apps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
    .app-card{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--card);transition:transform .06s ease, box-shadow .06s ease;text-decoration:none;color:inherit}
    .app-card:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.08)}
    .app-head{width:32px;height:32px;border-radius:6px;background:#f0f0f0;object-fit:cover}
    .theme-dark .app-head{background:#2a3348}
    .app-name{font-weight:600}
    .app-meta{display:flex;flex-direction:column}

    /* ====== Flash ====== */
    .flash{padding:10px 12px;border-radius:10px;border:1px solid var(--border);margin:10px 0}
    .flash.success{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.35)}
    .flash.error{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.35)}
    .flash.info{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.30)}

    /* ===== Menüs (Hamburger) ===== */
    .menu{position:relative}
    .menu .hamburger{display:inline-block;width:18px;height:14px;position:relative}
    .menu .hamburger span,
    .menu .hamburger::before,
    .menu .hamburger::after{
      content:"";display:block;height:2px;background:currentColor;border-radius:2px;position:absolute;left:0;right:0
    }
    .menu .hamburger span{top:6px}
    .menu .hamburger::before{top:0}
    .menu .hamburger::after{bottom:0}
    .dropdown{
      position:absolute; right:0; top:calc(100% + 6px);
      background:var(--card); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow);
      min-width:220px; z-index:60; overflow:hidden
    }
    .dropdown a{
      display:block; padding:10px 12px; color:var(--text); text-decoration:none; border-bottom:1px solid var(--border)
    }
    .dropdown a:last-child{border-bottom:none}
    .dropdown a:hover{background:var(--muted); text-decoration:none}
  </style>
</head>
<body>
  <header class="site">
    <div class="container">
      <div class="nav">
        <!-- Brand: Logo + Wortmarke -->
        <a href="index.php" class="brand btn btn-ghost" style="padding:6px 8px" title="Extrahelden – Startseite">
          <img class="brand-logo"
               src="/logo.png"
               onerror="this.src='/assets/icons/apple-touch-icon.png'; this.onerror=null;"
               alt="Extrahelden Logo">
          <strong>Extrahelden</strong>
        </a>

        <?php if ($show_nav): ?>
          <?php if (!$username): ?>
            <!-- Besucher: Direkt sichtbare Tabs -->
            <a class="btn btn-ghost" href="index.php">Start</a>
            <a class="btn btn-ghost" href="world_downloads.php">World Downloads</a>
            <?php if ($applyEnabled): ?>
              <a class="btn btn-ghost" href="apply.php"><?=htmlspecialchars($applyTitle)?> Bewerbungen</a>
            <?php endif; ?>
          <?php else: ?>
            <!-- Mitglieder: Hamburger-Menü -->
            <div class="menu">
              <button id="memberMenuBtn" class="btn" aria-haspopup="true" aria-expanded="false" aria-controls="memberMenu" title="Mitglieder-Menü">
                <span class="hamburger" aria-hidden="true"><span></span></span>
                <span style="margin-left:6px">Menü</span>
              </button>
              <div id="memberMenu" class="dropdown" hidden>
                <!--<a href="index.php">Start</a>-->
                <a href="documents.php">Dokumente</a>
                <?php if ($applyEnabled): ?>
                  <!--<a href="apply.php"><?=htmlspecialchars($applyTitle)?></a>-->
                <?php endif; ?>
                <a href="support.php">Support</a>
                <a href="account.php">Konto</a>
              </div>
            </div>
          <?php endif; ?>

          <!-- Admin-Menü als Hamburger -->
          <?php if ($is_admin): ?>
            <div class="menu">
              <button id="adminMenuBtn" class="btn" aria-haspopup="true" aria-expanded="false" aria-controls="adminMenu" title="Admin-Menü">
                <span class="hamburger" aria-hidden="true"><span></span></span>
                <span style="margin-left:6px">Admin</span>
              </button>
              <div id="adminMenu" class="dropdown" hidden>
                <a href="admin.php">Admin-Dashboard</a>
                <a href="admin_calendar.php">Kalender</a>
                <a href="admin_tickets.php">Tickets</a>
              </div>
            </div>
          <?php endif; ?>

          <span class="spacer"></span>

          <!-- Theme Toggle -->
          <?php $r = urlencode($reqUri); ?>
          <a class="btn btn-sm <?= $theme==='light'?'is-active':'' ?>" href="theme.php?t=light&r=<?=$r?>">Light</a>
          <a class="btn btn-sm <?= $theme==='dark'?'is-active':''  ?>" href="theme.php?t=dark&r=<?=$r?>">Dark</a>

          <?php if ($username): ?>
            <span style="margin-left:8px">Angemeldet als <strong><?=htmlspecialchars($username)?></strong></span>
            <a class="btn" href="logout.php">Logout</a>
          <?php else: ?>
            <a class="btn btn-primary" href="login.php">Login</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($show_nav): ?>
    <script>
      (function(){
        // generische Menu-Toggler
        function attachMenu(btnId, menuId){
          const btn  = document.getElementById(btnId);
          const menu = document.getElementById(menuId);
          if (!btn || !menu) return;

          function openMenu(){ menu.hidden = false; btn.setAttribute('aria-expanded','true'); }
          function closeMenu(){ menu.hidden = true; btn.setAttribute('aria-expanded','false'); }

          btn.addEventListener('click', (e)=>{
            e.preventDefault();
            if (menu.hidden) openMenu(); else closeMenu();
          });

          document.addEventListener('click', (e)=>{
            if (menu.hidden) return;
            if (e.target === btn || btn.contains(e.target)) return;
            if (!menu.contains(e.target)) closeMenu();
          });

          document.addEventListener('keydown', (e)=>{
            if (e.key === 'Escape') closeMenu();
          });
        }

        attachMenu('memberMenuBtn','memberMenu');
        attachMenu('adminMenuBtn','adminMenu');
      })();
    </script>
    <?php endif; ?>
  </header>

  <main>
    <div class="container">
<?php
    }
}

if (!function_exists('render_footer')) {
    function render_footer(): void { ?>
    </div>
  </main>
</body>
</html>
<?php }
}
