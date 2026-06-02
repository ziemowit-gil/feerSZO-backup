<?php
/**
 * portal.php — Ekran wyboru modułu po zalogowaniu.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

if (defined('CRM_STANDALONE') && CRM_STANDALONE) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
if (is_crm_only()) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (($_SESSION['user']['portal_scope'] ?? '') === 'tasks_only') {
    header('Location: ' . APP_URL . '/tasks/inbox.php'); exit;
}
if (($_SESSION['user']['portal_scope'] ?? '') === 'crm_only') {
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}

$_u     = current_user();
$_fn    = explode(' ', trim($_u['first_name'] ?? $_u['name'] ?? $_u['email'] ?? 'Użytkowniku'))[0];
$_h     = (int)date('G');
$_greet = $_h < 5 ? 'Dobranoc' : ($_h < 12 ? 'Dzień dobry' : ($_h < 18 ? 'Witaj' : 'Dobry wieczór'));
$_org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// ── Statystyki ────────────────────────────────────────────────────────────────
$stats_umowy = 0;
foreach (['zlecenie','uslugi','wolontariat','dzielo','praca','inne'] as $_ct) {
    try { $stats_umowy += (int)(db_one("SELECT COUNT(*) AS c FROM umowy_{$_ct} WHERE status IN ('podpisana','w realizacji','obowiązująca')")['c'] ?? 0); }
    catch (\Throwable $e) {}
}
$stats_osoby = $stats_granty = $stats_dzialania = $stats_approvals = 0;
try { $stats_osoby    = (int)(db_one("SELECT COUNT(*) AS c FROM persons")['c'] ?? 0); } catch (\Throwable $e) {}
try { $stats_granty   = (int)(db_one("SELECT COUNT(*) AS c FROM grants WHERE status NOT IN ('zakończony','anulowany')")['c'] ?? 0); } catch (\Throwable $e) {}
try { $stats_dzialania= (int)(db_one("SELECT COUNT(*) AS c FROM actions WHERE status IN ('planowane','w_przygotowaniu','w_trakcie')")['c'] ?? 0); } catch (\Throwable $e) {}
try { require_once __DIR__ . '/includes/amendments.php'; $stats_approvals = get_workflow_pending_count(); } catch (\Throwable $e) {}

require_once __DIR__ . '/includes/karty30.php';
karty30_migrate();
$k30_enabled = can_read('karty30') || is_admin();
$k30_today = $k30_clients = $k30_consult = 0;
if ($k30_enabled) {
    try { $k30_clients = (int)(db_one("SELECT COUNT(*) AS c FROM k30_clients")['c'] ?? 0); } catch (\Throwable $e) {}
    try { $k30_today   = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE DATE(start_time)=DATE('now') AND status IN ('preliminary','confirmed')")['c'] ?? 0); } catch (\Throwable $e) {}
    try { $k30_consult = (int)(db_one("SELECT COUNT(*) AS c FROM k30_consultations WHERE status='draft'")['c'] ?? 0); } catch (\Throwable $e) {}
}

$stats_dir_people = 0;
try { $stats_dir_people = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE is_active=1")['c'] ?? 0); } catch (\Throwable $e) {}

$crm_enabled = module_enabled('crm_enabled') && (can_read('crm') || is_admin());
$stats_crm = $stats_crm_new = $stats_crm_comm = $stats_crm_cases = 0;
try { $stats_crm      = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0); } catch (\Throwable $e) {}
try { $stats_crm_new  = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND created_at >= date('now','-30 days')")['c'] ?? 0); } catch (\Throwable $e) {}
try { $stats_crm_comm = (int)(db_one("SELECT COUNT(*) AS c FROM crm_communications WHERE sent_at >= date('now','-7 days')")['c'] ?? 0); } catch (\Throwable $e) {}
try { $stats_crm_cases= (int)(db_one("SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress')")['c'] ?? 0); } catch (\Throwable $e) {}

$sys_enabled = can_edit() || can_read('umowy') || is_admin();

// Branding
require_once __DIR__ . '/includes/branding.php';
$_b = branding_load();

// Powiadomienia
require_once __DIR__ . '/includes/notifications.php';
notif_migrate();
$_notif_unread = notif_unread_count((int)$_u['id']);
$_ann_list     = ann_list_for_user((int)$_u['id'], $_u['role'] ?? '');
$_ann_unread   = count(array_filter($_ann_list, fn($a) => !$a['is_read_by_me']));
$_total_unread = $_notif_unread + $_ann_unread;

// Inicjały
$_name = trim(($_u['first_name']??'').' '.($_u['last_name']??'')) ?: ($_u['name']??'');
$_ini = ''; foreach (preg_split('/\s+/', trim($_name)) as $w) $_ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
$_ini = mb_substr($_ini,0,2,'UTF-8') ?: '?';

// Admin stats
$cnt_users = $cnt_apps = 0;
if (is_admin()) {
    try { $cnt_users = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM user_applications WHERE status='nowy'"); $cnt_apps = (int)($r['c']??0); } catch(\Throwable $e) {}
}

// Liczba widocznych kart (do skrótu klawiaturowego)
$_card_count = (int)$sys_enabled + 1 + 1 + (int)$crm_enabled + (int)$k30_enabled + (int)is_admin();
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_org ?: 'System') ?> — wybierz moduł</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { min-height: 100vh; margin: 0; font-family: system-ui,-apple-system,'Segoe UI',sans-serif; background: #F1F5F9; }
*:focus-visible { outline: 3px solid var(--c,#2563eb); outline-offset: 2px; border-radius: 3px; }

/* Topbar */
.p-topbar {
  height: 56px; background: #fff; border-bottom: 1px solid #E5E7EB;
  display: flex; align-items: center; padding: 0 1.5rem; gap: 1rem;
  position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.p-brand { display:flex; align-items:center; gap:.6rem; text-decoration:none; color:inherit; flex-shrink:0; min-width:0; }
.p-brand-img  { height:34px; max-width:130px; object-fit:contain; }
.p-brand-icon { width:36px; height:36px; border-radius:9px; flex-shrink:0; background:var(--bp,#1e293b); color:var(--bp-text,#fff); display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
.p-brand-name { font-weight:800; font-size:.92rem; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.p-brand-sub  { font-size:.65rem; color:#9CA3AF; }
.p-topbar-r   { display:flex; align-items:center; gap:.4rem; margin-left:auto; flex-shrink:0; }

.p-ibtn {
  position:relative; display:flex; align-items:center; gap:.35rem;
  background:none; border:1.5px solid #E5E7EB; border-radius:8px; padding:.3rem .6rem;
  font-size:.82rem; font-weight:500; color:#6B7280; text-decoration:none; cursor:pointer;
  transition:border-color .12s,color .12s,background .12s; white-space:nowrap;
}
.p-ibtn:hover { border-color:var(--c,#2563eb); color:var(--c,#2563eb); background:var(--c-bg,#eff6ff); }
.p-ibtn i { font-size:.9rem; }
.p-ibtn-dot {
  position:absolute; top:-5px; right:-5px;
  background:#EF4444; color:#fff; font-size:.58rem; font-weight:700;
  min-width:17px; height:17px; border-radius:9px; padding:0 .25rem;
  display:flex; align-items:center; justify-content:center; border:2px solid #fff;
}
.p-avatar { width:28px; height:28px; border-radius:50%; background:var(--c,#2563eb); color:var(--c-text,#fff); font-size:.68rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

/* Layout */
.p-outer { min-height:calc(100vh - 56px); display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2.5rem 1.25rem 3rem; }

/* Powitanie */
.p-greet { text-align:center; margin-bottom:2rem; }
.p-greet-name { font-size:1.9rem; font-weight:800; color:#111827; letter-spacing:-.03em; line-height:1.15; }
.p-greet-meta { font-size:.85rem; color:#9CA3AF; margin-top:.3rem; }

/* Baner */
.p-banner { width:100%; max-width:960px; background:#FFFBEB; border:1.5px solid #FDE68A; border-radius:10px; padding:.6rem 1rem; display:flex; align-items:center; gap:.6rem; margin-bottom:1.5rem; font-size:.84rem; color:#92400E; text-decoration:none; transition:background .12s; }
.p-banner:hover { background:#FEF3C7; color:#92400E; }

/* Siatka */
.p-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(270px,1fr)); gap:1rem; width:100%; max-width:960px; }

/* Karta */
.p-card { background:#fff; border:1.5px solid #E5E7EB; border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:transform .14s,box-shadow .14s,border-color .14s; }
.p-card:hover { transform:translateY(-3px); box-shadow:0 10px 32px rgba(0,0,0,.1); border-color:transparent; color:inherit; }
.p-card:active { transform:translateY(-1px); }
.p-card.disabled { opacity:.4; pointer-events:none; }

.p-card-h { padding:1.2rem 1.2rem .9rem; display:flex; flex-direction:column; gap:.45rem; position:relative; overflow:hidden; }
.p-card-h::after { content:''; position:absolute; width:160px; height:160px; border-radius:50%; background:rgba(255,255,255,.07); right:-40px; bottom:-60px; pointer-events:none; }

.p-badge { display:inline-flex; align-items:center; gap:.3rem; background:rgba(255,255,255,.18); backdrop-filter:blur(4px); color:#fff; font-size:.7rem; font-weight:700; padding:.18rem .6rem; border-radius:2rem; align-self:flex-start; }
.p-icon  { width:42px; height:42px; border-radius:11px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#fff; }
.p-title { font-size:1rem; font-weight:800; color:#fff; line-height:1.2; }
.p-desc  { font-size:.74rem; color:rgba(255,255,255,.72); line-height:1.5; }
.p-cta   { display:flex; align-items:center; gap:.3rem; font-size:.76rem; font-weight:600; color:rgba(255,255,255,.82); transition:gap .14s; }
.p-card:hover .p-cta { gap:.5rem; }

.p-card-f { display:grid; grid-template-columns:1fr 1fr; flex:1; }
.p-s      { padding:.65rem .9rem; border-right:1px solid #F1F5F9; border-top:1px solid #F1F5F9; }
.p-s:nth-child(even) { border-right:none; }
.p-s:nth-child(1),.p-s:nth-child(2) { border-top:none; }
.p-sv { font-size:1.25rem; font-weight:800; color:#111827; line-height:1; }
.p-sl { font-size:.68rem; color:#9CA3AF; margin-top:.18rem; }

/* Szybkie akcje */
.p-quick { display:flex; flex-wrap:wrap; gap:.45rem; justify-content:center; max-width:960px; width:100%; margin-top:1.5rem; }
.p-ql { display:inline-flex; align-items:center; gap:.35rem; background:#fff; border:1.5px solid #E5E7EB; border-radius:2rem; padding:.32rem .8rem; font-size:.78rem; font-weight:500; color:#374151; text-decoration:none; transition:border-color .12s,color .12s,background .12s; }
.p-ql:hover { border-color:var(--c,#2563eb); color:var(--c,#2563eb); background:var(--c-bg,#eff6ff); }

/* Animacje */
@keyframes fu { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:none; } }
.p-greet  { animation:fu .4s ease both; }
.p-banner { animation:fu .4s .04s ease both; }
.p-grid   { animation:fu .45s .08s ease both; }
.p-quick  { animation:fu .45s .13s ease both; }
@media (prefers-reduced-motion:reduce) { .p-greet,.p-banner,.p-grid,.p-quick { animation:none; } }

@media (max-width:560px) { .p-grid { grid-template-columns:1fr; max-width:380px; } .p-greet-name { font-size:1.45rem; } .p-topbar { padding:0 .9rem; } }
@media (max-width:380px) { .p-ibtn span { display:none; } }
</style>
</head>
<body>

<a href="#p-main" style="position:absolute;top:-100%;left:.5rem;z-index:9999;background:var(--c,#2563eb);color:#fff;padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none" onfocus="this.style.top='0'" onblur="this.style.top='-100%'">Przejdź do treści</a>

<!-- Topbar -->
<header class="p-topbar" role="banner">
  <a href="<?= APP_URL ?>/portal.php" class="p-brand" aria-label="Strona główna — <?= h($_org) ?>">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>" class="p-brand-img">
    <?php else: ?>
    <div class="p-brand-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <div>
      <div class="p-brand-name"><?= h(mb_substr($_org ?: 'System', 0, 36, 'UTF-8')) ?></div>
      <div class="p-brand-sub">System zarządzania</div>
    </div>
  </a>

  <div class="p-topbar-r" role="navigation" aria-label="Narzędzia użytkownika">
    <a href="<?= APP_URL ?>/komunikaty/index.php" class="p-ibtn"
       aria-label="Komunikaty<?= $_total_unread ? ' — ' . $_total_unread . ' nieprzeczytanych' : '' ?>">
      <i class="bi bi-bell<?= $_total_unread ? '-fill' : '' ?>" aria-hidden="true"></i>
      <span class="d-none d-sm-inline">Komunikaty</span>
      <?php if ($_total_unread > 0): ?>
      <span class="p-ibtn-dot" aria-hidden="true"><?= min($_total_unread, 99) ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/panel/index.php" class="p-ibtn" aria-label="Moje konto">
      <div class="p-avatar" aria-hidden="true"><?= h($_ini) ?></div>
      <span class="d-none d-md-inline"><?= h(explode(' ', $_name)[0]) ?></span>
    </a>
    <a href="<?= APP_URL ?>/auth/logout.php" class="p-ibtn" aria-label="Wyloguj się"
       onclick="return confirm('Wylogować się z systemu?')">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
    </a>
  </div>
</header>

<!-- Główna treść -->
<main class="p-outer" id="p-main">

  <div class="p-greet" role="status">
    <div class="p-greet-name"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</div>
    <div class="p-greet-meta"><?= date('l, j F Y') ?><?= $_org ? ' · ' . h($_org) : '' ?></div>
  </div>

  <!-- Informacja o spójności wyglądu — jednorazowa na sesję -->
  <div id="consistency-notice" style="display:none;width:100%;max-width:860px;margin-bottom:1rem">
    <div style="background:#FFF7ED;border:1.5px solid #FED7AA;border-radius:10px;padding:.75rem 1rem .75rem 1.1rem;display:flex;align-items:flex-start;gap:.75rem;font-size:.82rem;color:#92400E">
      <i class="bi bi-info-circle-fill" aria-hidden="true" style="color:#D97706;font-size:1rem;flex-shrink:0;margin-top:.1rem"></i>
      <span style="flex:1;line-height:1.5">
        <strong>Informacja o wyglądzie systemu:</strong>
        Wygląd poszczególnych stron może nie być spójny — panel stanowi połączenie kilku systemów,
        z których korzystała Fundacja. Będziemy to ujednolicać w kolejnych wersjach.
      </span>
      <button type="button"
              aria-label="Zamknij informację"
              onclick="sessionStorage.setItem('cn_dismissed','1');this.closest('#consistency-notice').style.display='none'"
              style="background:none;border:none;color:#D97706;cursor:pointer;font-size:1rem;padding:0;flex-shrink:0;opacity:.7;line-height:1">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <script>
  (function(){
    if (!sessionStorage.getItem('cn_dismissed')) {
      document.getElementById('consistency-notice').style.display = '';
    }
  })();
  </script>

  <?php if ($_ann_unread > 0): ?>
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="p-banner"
     aria-label="Masz <?= $_ann_unread ?> nieprzeczytanych ogłoszeń">
    <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
    <strong><?= $_ann_unread ?></strong>&nbsp;<?= $_ann_unread === 1 ? 'nowe ogłoszenie' : 'nowe ogłoszenia' ?> — kliknij, by przeczytać
    <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i>
  </a>
  <?php endif; ?>

  <!-- Siatka kart -->
  <div class="p-grid" role="list" aria-label="Dostępne moduły">

    <?php if ($sys_enabled): ?>
    <a href="<?= APP_URL ?>/index.php" class="p-card" role="listitem"
       aria-label="System Zarządzania Organizacją<?= $stats_approvals ? ', ' . $stats_approvals . ' do akceptacji' : '' ?>">
      <div class="p-card-h" style="background:linear-gradient(135deg,#1E3A5F 0%,#1D4ED8 100%)">
        <?php if ($stats_approvals): ?><div class="p-badge" aria-hidden="true"><i class="bi bi-clock-fill"></i><?= $stats_approvals ?> do akceptacji</div><?php endif; ?>
        <div class="p-icon" aria-hidden="true"><i class="bi bi-building-fill"></i></div>
        <div class="p-title">System Zarządzania<br>Organizacją</div>
        <div class="p-desc">Umowy, granty, działania, wolontariat, korespondencja</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv"><?= number_format($stats_umowy) ?></div><div class="p-sl">Aktywnych umów</div></div>
        <div class="p-s"><div class="p-sv"><?= number_format($stats_osoby) ?></div><div class="p-sl">Stron umów</div></div>
        <div class="p-s"><div class="p-sv"><?= number_format($stats_granty) ?></div><div class="p-sl">Aktywnych grantów</div></div>
        <div class="p-s"><div class="p-sv"><?= number_format($stats_dzialania) ?></div><div class="p-sl">Działań w toku</div></div>
      </div>
    </a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/directory/" class="p-card" role="listitem"
       aria-label="Katalog współpracowników — <?= $stats_dir_people ?> osób">
      <div class="p-card-h" style="background:linear-gradient(135deg,#4338CA 0%,#6366F1 100%)">
        <div class="p-icon" aria-hidden="true"><i class="bi bi-person-lines-fill"></i></div>
        <div class="p-title">Katalog<br>współpracowników</div>
        <div class="p-desc">Profile, umiejętności, telefony, struktura organizacyjna</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv"><?= $stats_dir_people ?></div><div class="p-sl">Współpracowników</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Profili</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Jednostek</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Stanowisk</div></div>
      </div>
    </a>

    <a href="<?= APP_URL ?>/komunikaty/index.php" class="p-card" role="listitem"
       aria-label="Komunikaty<?= $_total_unread ? ' — ' . $_total_unread . ' nieprzeczytanych' : '' ?>">
      <div class="p-card-h" style="background:linear-gradient(135deg,#92400E 0%,#D97706 100%)">
        <?php if ($_total_unread > 0): ?><div class="p-badge" aria-hidden="true"><i class="bi bi-bell-fill"></i><?= $_total_unread ?> nowych</div><?php endif; ?>
        <div class="p-icon" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></div>
        <div class="p-title">Komunikaty<br>i powiadomienia</div>
        <div class="p-desc">Ogłoszenia organizacji, powiadomienia, wiadomości</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv" <?= $_ann_unread ? 'style="color:#D97706"' : '' ?>><?= $_ann_unread ?></div><div class="p-sl">Nowych ogłoszeń</div></div>
        <div class="p-s"><div class="p-sv" <?= $_notif_unread ? 'style="color:#D97706"' : '' ?>><?= $_notif_unread ?></div><div class="p-sl">Powiadomień</div></div>
        <div class="p-s"><div class="p-sv"><?= count($_ann_list) ?></div><div class="p-sl">Ogłoszeń łącznie</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Wiadomości PW</div></div>
      </div>
    </a>

    <?php if ($crm_enabled): ?>
    <a href="<?= APP_URL ?>/crm/dashboard.php" class="p-card" role="listitem"
       aria-label="CRM<?= $stats_crm_cases ? ' — ' . $stats_crm_cases . ' otwartych spraw' : '' ?>">
      <div class="p-card-h" style="background:linear-gradient(135deg,#14532D 0%,#16A34A 100%)">
        <?php if ($stats_crm_cases): ?><div class="p-badge" aria-hidden="true"><i class="bi bi-briefcase-fill"></i><?= $stats_crm_cases ?> spraw</div><?php endif; ?>
        <div class="p-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
        <div class="p-title">CRM</div>
        <div class="p-desc">Kontakty, grupy, kampanie, sprawy, kalendarz</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv"><?= number_format($stats_crm) ?></div><div class="p-sl">Kontaktów</div></div>
        <div class="p-s"><div class="p-sv" <?= $stats_crm_new ? 'style="color:#16A34A"' : '' ?>>+<?= $stats_crm_new ?></div><div class="p-sl">Nowych (30 dni)</div></div>
        <div class="p-s"><div class="p-sv"><?= $stats_crm_comm ?></div><div class="p-sl">Wiadom. (7 dni)</div></div>
        <div class="p-s"><div class="p-sv"><?= $stats_crm_cases ?></div><div class="p-sl">Otwartych spraw</div></div>
      </div>
    </a>
    <?php endif; ?>

    <?php if ($k30_enabled): ?>
    <a href="<?= APP_URL ?>/karty30/index.php" class="p-card" role="listitem"
       aria-label="TyfloKonsultacje Karty 30<?= $k30_today ? ' — ' . $k30_today . ' wizyt dziś' : '' ?>">
      <div class="p-card-h" style="background:linear-gradient(135deg,#581C87 0%,#7C3AED 100%)">
        <?php if ($k30_today): ?><div class="p-badge" aria-hidden="true"><i class="bi bi-calendar-check-fill"></i><?= $k30_today ?> wizyt dziś</div><?php endif; ?>
        <div class="p-icon" aria-hidden="true"><i class="bi bi-card-checklist"></i></div>
        <div class="p-title">TyfloKonsultacje<br><span style="font-size:.88rem;opacity:.82">Karty 30</span></div>
        <div class="p-desc">Beneficjenci, harmonogram wizyt, konsultacje, raporty</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv"><?= number_format($k30_clients) ?></div><div class="p-sl">Beneficjentów</div></div>
        <div class="p-s"><div class="p-sv" <?= $k30_today ? 'style="color:#7C3AED"' : '' ?>><?= $k30_today ?></div><div class="p-sl">Wizyt dziś</div></div>
        <div class="p-s"><div class="p-sv"><?= $k30_consult ?></div><div class="p-sl">Roboczych kons.</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Raport MRPiPS</div></div>
      </div>
    </a>
    <?php endif; ?>

    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/index.php" class="p-card" role="listitem"
       aria-label="Panel administratora">
      <div class="p-card-h" style="background:linear-gradient(135deg,#1E293B 0%,#475569 100%)">
        <?php if ($cnt_apps > 0): ?><div class="p-badge" aria-hidden="true"><i class="bi bi-inbox-fill"></i><?= $cnt_apps ?> wniosków</div><?php endif; ?>
        <div class="p-icon" aria-hidden="true"><i class="bi bi-shield-shaded"></i></div>
        <div class="p-title">Administrator</div>
        <div class="p-desc">Użytkownicy, role, ustawienia, integracje, moduły</div>
        <div class="p-cta" aria-hidden="true">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="p-card-f" aria-hidden="true">
        <div class="p-s"><div class="p-sv"><?= $cnt_users ?></div><div class="p-sl">Użytkowników</div></div>
        <div class="p-s"><div class="p-sv" <?= $cnt_apps ? 'style="color:#EF4444"' : '' ?>><?= $cnt_apps ?></div><div class="p-sl">Nowych wniosków</div></div>
        <div class="p-s"><div class="p-sv"><?= $stats_approvals ?></div><div class="p-sl">Do akceptacji</div></div>
        <div class="p-s"><div class="p-sv">—</div><div class="p-sl">Moduły</div></div>
      </div>
    </a>
    <?php endif; ?>

  </div><!-- /p-grid -->

  <!-- Szybkie akcje -->
  <nav class="p-quick" aria-label="Szybkie akcje">
    <?php if ($sys_enabled): ?>
    <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="p-ql"><i class="bi bi-heart text-danger"></i>Wolontariat</a>
    <a href="<?= APP_URL ?>/actions/index.php" class="p-ql"><i class="bi bi-lightning-charge" style="color:var(--c,#2563eb)"></i>Działania</a>
    <a href="<?= APP_URL ?>/grants/index.php" class="p-ql"><i class="bi bi-cash-coin text-success"></i>Granty</a>
    <?php endif; ?>
    <?php if ($crm_enabled): ?>
    <a href="<?= APP_URL ?>/crm/index.php" class="p-ql"><i class="bi bi-people" style="color:#16A34A"></i>Kontakty CRM</a>
    <a href="<?= APP_URL ?>/crm/calendar.php" class="p-ql"><i class="bi bi-calendar3" style="color:#16A34A"></i>Kalendarz CRM</a>
    <?php endif; ?>
    <?php if ($k30_enabled): ?>
    <a href="<?= APP_URL ?>/karty30/schedules/index.php" class="p-ql"><i class="bi bi-calendar3" style="color:#7C3AED"></i>Harmonogram K30</a>
    <?php endif; ?>
    <?php if ($_ann_unread > 0): ?>
    <a href="<?= APP_URL ?>/komunikaty/index.php" class="p-ql" style="border-color:#FDE68A;color:#92400E">
      <i class="bi bi-megaphone-fill" style="color:#D97706"></i><?= $_ann_unread ?> nowych ogłoszeń
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/directory/profile_edit.php" class="p-ql"><i class="bi bi-person-badge"></i>Mój profil</a>
    <a href="<?= APP_URL ?>/panel/index.php" class="p-ql"><i class="bi bi-person-circle"></i>Moje konto</a>
  </nav>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var _cards = document.querySelectorAll('.p-card');
document.addEventListener('keydown', function(e) {
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.ctrlKey || e.metaKey) return;
  var n = parseInt(e.key);
  if (n >= 1 && n <= _cards.length) { e.preventDefault(); _cards[n-1].click(); }
});
(function() {
  if (sessionStorage.getItem('ph')) return;
  sessionStorage.setItem('ph', '1');
  var el = document.createElement('div');
  el.style.cssText = 'position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);background:rgba(15,23,42,.9);color:#fff;font-size:.74rem;padding:.45rem 1.1rem;border-radius:2rem;z-index:999;white-space:nowrap;backdrop-filter:blur(8px);pointer-events:none';
  el.innerHTML = '<i class="bi bi-keyboard me-2"></i>Klawisze <kbd style="background:rgba(255,255,255,.15);border:none;padding:.1rem .4rem;border-radius:4px">1</kbd>–<kbd style="background:rgba(255,255,255,.15);border:none;padding:.1rem .4rem;border-radius:4px"><?= $_card_count ?></kbd> wybierają moduł';
  document.body.appendChild(el);
  setTimeout(function(){ el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(function(){ el.remove(); },400); },3000);
})();
</script>
</body>
</html>
