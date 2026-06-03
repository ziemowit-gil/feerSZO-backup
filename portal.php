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
if (($_SESSION['user']['portal_scope'] ?? '') === 'tasks_only') { header('Location: ' . APP_URL . '/tasks/inbox.php'); exit; }
if (($_SESSION['user']['portal_scope'] ?? '') === 'crm_only') { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
if (is_crm_only()) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }

$_u   = current_user();
$_fn  = explode(' ', trim($_u['first_name'] ?? $_u['name'] ?? $_u['email'] ?? 'Użytkowniku'))[0];
$_h   = (int)date('G');
$_greet = $_h < 5 ? 'Dobranoc' : ($_h < 12 ? 'Dzień dobry' : ($_h < 18 ? 'Witaj' : 'Dobry wieczór'));
$_org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// ── Statystyki ─────────────────────────────────────────────────────────────
$stats = ['umowy'=>0,'osoby'=>0,'granty'=>0,'dzialania'=>0,'approvals'=>0,'crm'=>0,'k30'=>0];
foreach (['zlecenie','uslugi','wolontariat','dzielo','praca','inne'] as $ct) {
    try { $stats['umowy'] += (int)(db_one("SELECT COUNT(*) AS c FROM umowy_{$ct} WHERE status IN ('podpisana','w realizacji','obowiązująca')")['c'] ?? 0); } catch(\Throwable $e) {}
}
try { $stats['osoby']    = (int)(db_one("SELECT COUNT(*) AS c FROM persons")['c'] ?? 0); } catch(\Throwable $e) {}
try { $stats['granty']   = (int)(db_one("SELECT COUNT(*) AS c FROM grants WHERE status NOT IN ('zakończony','anulowany')")['c'] ?? 0); } catch(\Throwable $e) {}
try { $stats['dzialania']= (int)(db_one("SELECT COUNT(*) AS c FROM actions WHERE status IN ('planowane','w_przygotowaniu','w_trakcie')")['c'] ?? 0); } catch(\Throwable $e) {}
try { require_once __DIR__ . '/includes/amendments.php'; $stats['approvals'] = get_workflow_pending_count(); } catch(\Throwable $e) {}
try { $stats['crm']      = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0); } catch(\Throwable $e) {}

require_once __DIR__ . '/includes/karty30.php'; karty30_migrate();
$k30_enabled = can_read('karty30') || is_admin();
$k30_today = 0;
if ($k30_enabled) { try { $k30_today = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE DATE(start_time)=DATE('now') AND status IN ('preliminary','confirmed')")['c'] ?? 0); } catch(\Throwable $e) {} }

$crm_enabled = module_enabled('crm_enabled') && (can_read('crm') || is_admin());

require_once __DIR__ . '/includes/notifications.php'; notif_migrate();
$_notif_unread = notif_unread_count((int)$_u['id']);
$_ann_list     = ann_list_for_user((int)$_u['id'], $_u['role'] ?? '');
$_ann_unread   = count(array_filter($_ann_list, fn($a) => !$a['is_read_by_me']));
$_total_unread = $_notif_unread + $_ann_unread;

require_once __DIR__ . '/includes/branding.php';
$_b = branding_load();
$_name = trim(($_u['first_name']??'').' '.($_u['last_name']??'')) ?: ($_u['name']??'');
$_ini  = ''; foreach (preg_split('/\s+/', trim($_name)) as $w) $_ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
$_ini  = mb_substr($_ini,0,2,'UTF-8') ?: '?';

// Ostatnie umowy
$_recent = [];
foreach (['wolontariat','zlecenie','dzielo','praca','uslugi','inne'] as $slug) {
    try {
        $rows = db_all("SELECT id,'{$slug}' AS type, numer_umowy, imie_nazwisko, status, created_at FROM umowy_{$slug} ORDER BY created_at DESC LIMIT 3");
        $_recent = array_merge($_recent, $rows);
    } catch(\Throwable $e) {}
}
usort($_recent, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
$_recent = array_slice($_recent, 0, 5);

$_icons = ['wolontariat'=>'bi-heart','zlecenie'=>'bi-person-lines-fill','dzielo'=>'bi-palette','praca'=>'bi-briefcase','uslugi'=>'bi-building','inne'=>'bi-file-text'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_org ?: 'System') ?> — panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#F0F4F8;color:#1E293B}

/* ── Topbar ───────────────────────────── */
.pt{height:52px;background:#fff;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;padding:0 1.5rem;gap:.75rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.pt-brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:inherit;flex:1;min-width:0}
.pt-brand-img{height:30px;max-width:120px;object-fit:contain}
.pt-brand-icon{width:32px;height:32px;border-radius:8px;background:var(--c,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.pt-brand-name{font-weight:700;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pt-right{display:flex;align-items:center;gap:.35rem;flex-shrink:0}
.pt-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .55rem;border-radius:8px;background:none;border:1.5px solid #E2E8F0;font-size:.78rem;font-weight:500;color:#64748B;text-decoration:none;cursor:pointer;transition:all .1s;white-space:nowrap;position:relative}
.pt-btn:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb);background:#EFF6FF}
.pt-avatar{width:26px;height:26px;border-radius:50%;background:var(--c,#2563eb);color:#fff;font-size:.62rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.pt-dot{position:absolute;top:-4px;right:-4px;min-width:15px;height:15px;border-radius:8px;background:#EF4444;color:#fff;font-size:.55rem;font-weight:700;padding:0 .2rem;display:flex;align-items:center;justify-content:center;border:2px solid #fff}

/* ── Layout ───────────────────────────── */
.pw{max-width:1100px;margin:0 auto;padding:1.75rem 1.25rem 3rem}

/* ── Header splash ────────────────────── */
.ph{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem}
.ph-greet{font-size:1.65rem;font-weight:800;letter-spacing:-.03em;line-height:1.15}
.ph-sub{font-size:.84rem;color:#64748B;margin-top:.25rem}
.ph-stats{display:flex;gap:.6rem;flex-wrap:wrap;align-self:flex-end}
.ph-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:.5rem .9rem;text-align:center;min-width:72px}
.ph-stat-n{font-size:1.3rem;font-weight:800;line-height:1;color:#0F172A}
.ph-stat-l{font-size:.65rem;color:#94A3B8;margin-top:.15rem;white-space:nowrap}

/* ── Grid główny ──────────────────────── */
.pg{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:auto auto;gap:1rem}
.pg-main{grid-column:1/3;grid-row:1/2}
.pg-side{grid-column:3/4;grid-row:1/3;display:flex;flex-direction:column;gap:1rem}
.pg-row2{grid-column:1/3;grid-row:2/3;display:grid;grid-template-columns:1fr 1fr;gap:1rem}

/* ── Karty modułów ────────────────────── */
.pm{border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;display:block;transition:transform .14s,box-shadow .14s}
.pm:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.12);color:inherit}
.pm-h{padding:1.25rem 1.35rem 1rem;position:relative;overflow:hidden}
.pm-h::after{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.07);right:-40px;bottom:-70px;pointer-events:none}
.pm-icon{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;margin-bottom:.6rem}
.pm-title{font-size:1.05rem;font-weight:800;color:#fff;line-height:1.2}
.pm-desc{font-size:.75rem;color:rgba(255,255,255,.72);margin-top:.25rem;line-height:1.4}
.pm-cta{display:inline-flex;align-items:center;gap:.3rem;margin-top:.65rem;font-size:.74rem;font-weight:600;color:rgba(255,255,255,.8);padding:.25rem .6rem;border-radius:20px;background:rgba(255,255,255,.15);transition:background .1s}
.pm:hover .pm-cta{background:rgba(255,255,255,.25)}
.pm-badge{display:inline-flex;align-items:center;gap:.25rem;background:rgba(0,0,0,.25);color:#fff;font-size:.68rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;margin-bottom:.4rem}

.pm-f{background:#fff;border:1px solid #E2E8F0;display:grid;grid-template-columns:1fr 1fr;border-top:none}
.pm-s{padding:.6rem .85rem;border-right:1px solid #F1F5F9;border-top:1px solid #F1F5F9}
.pm-s:nth-child(even){border-right:none}
.pm-s:nth-child(1),.pm-s:nth-child(2){border-top:none}
.pm-sv{font-size:1.2rem;font-weight:800;color:#0F172A;line-height:1}
.pm-sl{font-size:.65rem;color:#94A3B8;margin-top:.12rem}

/* ── Panel boczny — Ostatnie + Akcje ──── */
.ps-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden}
.ps-head{padding:.65rem 1rem;border-bottom:1px solid #F1F5F9;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94A3B8;display:flex;align-items:center;gap:.4rem}
.ps-item{display:flex;align-items:center;gap:.65rem;padding:.55rem 1rem;border-bottom:1px solid #F8FAFC;text-decoration:none;color:inherit;transition:background .1s;font-size:.82rem}
.ps-item:last-child{border-bottom:none}
.ps-item:hover{background:#F8FAFC}
.ps-item-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
.ps-item-name{font-weight:600;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ps-item-sub{font-size:.71rem;color:#94A3B8}

/* ── Szybkie akcje ────────────────────── */
.qa{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:1.25rem}
.qa-btn{display:inline-flex;align-items:center;gap:.3rem;background:#fff;border:1.5px solid #E2E8F0;border-radius:20px;padding:.3rem .75rem;font-size:.77rem;font-weight:500;color:#374151;text-decoration:none;transition:all .1s;white-space:nowrap}
.qa-btn:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb);background:#EFF6FF}

/* ── Banner ───────────────────────────── */
.pban{display:flex;align-items:center;gap:.6rem;background:#FFFBEB;border:1.5px solid #FDE68A;border-radius:10px;padding:.55rem 1rem;margin-bottom:1.25rem;font-size:.82rem;color:#92400E;text-decoration:none;transition:background .1s}
.pban:hover{background:#FEF3C7;color:#92400E}

@media(max-width:900px){.pg{grid-template-columns:1fr 1fr}.pg-main,.pg-row2{grid-column:1/3}.pg-side{grid-column:1/3;grid-row:auto;flex-direction:row}}
@media(max-width:600px){.pg{grid-template-columns:1fr}.pg-main,.pg-row2,.pg-side{grid-column:1/2}.pg-row2{grid-template-columns:1fr}.ph-greet{font-size:1.3rem}.ph-stats{display:none}.pt{padding:0 .75rem}}
@keyframes fi{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.ph{animation:fi .35s ease both}.pban{animation:fi .35s .05s ease both}.pg{animation:fi .4s .08s ease both}.qa{animation:fi .4s .12s ease both}
@media(prefers-reduced-motion:reduce){.ph,.pban,.pg,.qa{animation:none}}
</style>
</head>
<body>
<a href="#pw" style="position:absolute;top:-100%;left:.5rem;z-index:9999;background:var(--c,#2563eb);color:#fff;padding:.4rem 1rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none" onfocus="this.style.top='0'" onblur="this.style.top='-100%'">Przejdź do treści</a>

<!-- Topbar -->
<header class="pt">
  <a href="<?= APP_URL ?>/portal.php" class="pt-brand">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>" class="pt-brand-img">
    <?php else: ?>
    <div class="pt-brand-icon"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <span class="pt-brand-name"><?= h(org_setting('org_short_name') ?: mb_substr($_org,0,28,'UTF-8')) ?></span>
  </a>
  <div class="pt-right">
    <a href="<?= APP_URL ?>/komunikaty/index.php" class="pt-btn">
      <i class="bi bi-bell<?= $_total_unread ? '-fill' : '' ?>"></i>
      <?php if ($_total_unread): ?><span class="pt-dot"><?= min($_total_unread,99) ?></span><?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/panel/index.php" class="pt-btn">
      <div class="pt-avatar"><?= h($_ini) ?></div>
      <span class="d-none d-sm-inline"><?= h(explode(' ',$_name)[0]) ?></span>
    </a>
    <a href="<?= APP_URL ?>/auth/logout.php" class="pt-btn" onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</header>

<main class="pw" id="pw">

  <!-- Nagłówek z powitaniem i statystykami -->
  <div class="ph">
    <div>
      <div class="ph-greet"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</div>
      <div class="ph-sub"><?= date('l, j F Y') ?><?= $_org ? ' · ' . h($_org) : '' ?></div>
    </div>
    <div class="ph-stats">
      <div class="ph-stat">
        <div class="ph-stat-n" style="color:#2563EB"><?= $stats['umowy'] ?></div>
        <div class="ph-stat-l">Aktywnych umów</div>
      </div>
      <?php if ($stats['granty']): ?>
      <div class="ph-stat">
        <div class="ph-stat-n" style="color:#16A34A"><?= $stats['granty'] ?></div>
        <div class="ph-stat-l">Grantów</div>
      </div>
      <?php endif; ?>
      <?php if ($stats['approvals']): ?>
      <div class="ph-stat">
        <div class="ph-stat-n" style="color:#D97706"><?= $stats['approvals'] ?></div>
        <div class="ph-stat-l">Do akceptacji</div>
      </div>
      <?php endif; ?>
      <?php if ($_total_unread): ?>
      <div class="ph-stat">
        <div class="ph-stat-n" style="color:#EF4444"><?= $_total_unread ?></div>
        <div class="ph-stat-l">Powiadomień</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($_ann_unread > 0): ?>
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="pban">
    <i class="bi bi-megaphone-fill"></i>
    <strong><?= $_ann_unread ?></strong>&nbsp;<?= $_ann_unread === 1 ? 'nowe ogłoszenie' : 'nowe ogłoszenia' ?> — kliknij, by przeczytać
    <i class="bi bi-arrow-right ms-auto"></i>
  </a>
  <?php endif; ?>

  <!-- Siatka -->
  <div class="pg">

    <!-- GŁÓWNY moduł — SZO -->
    <a href="<?= APP_URL ?>/index.php" class="pm pg-main">
      <div class="pm-h" style="background:linear-gradient(135deg,#1E3A5F,#1D6EF9)">
        <?php if ($stats['approvals']): ?>
        <div class="pm-badge"><i class="bi bi-clock-fill"></i><?= $stats['approvals'] ?> do akceptacji</div>
        <?php endif; ?>
        <div class="pm-icon"><i class="bi bi-building-fill"></i></div>
        <div class="pm-title">System Zarządzania Organizacją</div>
        <div class="pm-desc">Umowy wolontariackie, zlecenia, granty, działania, korespondencja, raporty</div>
        <div class="pm-cta">Wejdź <i class="bi bi-arrow-right"></i></div>
      </div>
      <div class="pm-f">
        <div class="pm-s"><div class="pm-sv"><?= $stats['umowy'] ?></div><div class="pm-sl">Aktywnych umów</div></div>
        <div class="pm-s"><div class="pm-sv"><?= $stats['osoby'] ?></div><div class="pm-sl">Stron umów</div></div>
        <div class="pm-s"><div class="pm-sv"><?= $stats['granty'] ?></div><div class="pm-sl">Aktywnych grantów</div></div>
        <div class="pm-s"><div class="pm-sv"><?= $stats['dzialania'] ?></div><div class="pm-sl">Działań w toku</div></div>
      </div>
    </a>

    <!-- Panel boczny -->
    <div class="pg-side">

      <!-- Ostatnie umowy -->
      <div class="ps-card" style="flex:1">
        <div class="ps-head"><i class="bi bi-clock-history"></i> Ostatnie umowy</div>
        <?php foreach ($_recent as $r):
          $st = STATUS_LABELS[$r['status']] ?? ['class'=>'secondary','label'=>$r['status']];
        ?>
        <a href="<?= APP_URL ?>/contracts/<?= h($r['type']) ?>/view.php?id=<?= (int)$r['id'] ?>" class="ps-item">
          <div class="ps-item-icon" style="background:#EFF6FF;color:#2563EB">
            <i class="bi <?= h($_icons[$r['type']] ?? 'bi-file-text') ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div class="ps-item-name"><?= h($r['imie_nazwisko'] ?? $r['numer_umowy'] ?? '—') ?></div>
            <div class="ps-item-sub"><?= h($r['numer_umowy'] ?? '') ?> · <span class="badge bg-<?= h($st['class']) ?>" style="font-size:.6rem"><?= h($st['label']) ?></span></div>
          </div>
        </a>
        <?php endforeach; ?>
        <?php if (!$_recent): ?>
        <div class="ps-item text-muted" style="justify-content:center;font-size:.8rem">Brak umów</div>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="ps-item" style="color:#2563EB;font-size:.78rem;justify-content:center;border-top:1px solid #F1F5F9">
          Wszystkie umowy <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <!-- Moje konto -->
      <div class="ps-card">
        <div class="ps-head"><i class="bi bi-person-circle"></i> Moje konto</div>
        <a href="<?= APP_URL ?>/panel/index.php" class="ps-item">
          <div class="ps-item-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-house"></i></div>
          <div><div class="ps-item-name">Mój panel</div><div class="ps-item-sub">Umowy, wnioski, dokumenty</div></div>
        </a>
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="ps-item">
          <div class="ps-item-icon" style="background:#FFFBEB;color:#D97706"><i class="bi bi-megaphone"></i></div>
          <div style="flex:1">
            <div class="ps-item-name">Komunikaty</div>
            <div class="ps-item-sub"><?= $_total_unread ? "{$_total_unread} nieprzeczytanych" : 'Brak nowych' ?></div>
          </div>
          <?php if ($_total_unread): ?><span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $_total_unread ?></span><?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/auth/logout.php" class="ps-item" onclick="return confirm('Wylogować się?')" style="color:#EF4444">
          <div class="ps-item-icon" style="background:#FEF2F2;color:#EF4444"><i class="bi bi-box-arrow-right"></i></div>
          <div><div class="ps-item-name">Wyloguj się</div></div>
        </a>
      </div>

    </div>

    <!-- Drugi rząd — moduły dodatkowe -->
    <div class="pg-row2">

      <?php if ($crm_enabled): ?>
      <a href="<?= APP_URL ?>/crm/dashboard.php" class="pm">
        <div class="pm-h" style="background:linear-gradient(135deg,#14532D,#16A34A)">
          <div class="pm-icon"><i class="bi bi-diagram-2-fill"></i></div>
          <div class="pm-title">CRM</div>
          <div class="pm-desc">Kontakty, sprawy, kampanie</div>
          <div class="pm-cta">Wejdź <i class="bi bi-arrow-right"></i></div>
        </div>
        <div class="pm-f">
          <div class="pm-s"><div class="pm-sv"><?= $stats['crm'] ?></div><div class="pm-sl">Kontaktów</div></div>
          <div class="pm-s"><div class="pm-sv">—</div><div class="pm-sl">Otwartych spraw</div></div>
        </div>
      </a>
      <?php endif; ?>

      <?php if ($k30_enabled): ?>
      <a href="<?= APP_URL ?>/karty30/index.php" class="pm">
        <div class="pm-h" style="background:linear-gradient(135deg,#581C87,#7C3AED)">
          <?php if ($k30_today): ?><div class="pm-badge"><i class="bi bi-calendar-check-fill"></i><?= $k30_today ?> wizyt dziś</div><?php endif; ?>
          <div class="pm-icon"><i class="bi bi-card-checklist"></i></div>
          <div class="pm-title">Karty 30</div>
          <div class="pm-desc">Beneficjenci, harmonogram</div>
          <div class="pm-cta">Wejdź <i class="bi bi-arrow-right"></i></div>
        </div>
        <div class="pm-f">
          <div class="pm-s"><div class="pm-sv"><?= $k30_today ?></div><div class="pm-sl">Wizyt dziś</div></div>
          <div class="pm-s"><div class="pm-sv">—</div><div class="pm-sl">Beneficjentów</div></div>
        </div>
      </a>
      <?php endif; ?>

      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/index.php" class="pm">
        <div class="pm-h" style="background:linear-gradient(135deg,#1E293B,#475569)">
          <div class="pm-icon"><i class="bi bi-shield-shaded"></i></div>
          <div class="pm-title">Administrator</div>
          <div class="pm-desc">Ustawienia, użytkownicy, moduły</div>
          <div class="pm-cta">Wejdź <i class="bi bi-arrow-right"></i></div>
        </div>
        <div class="pm-f">
          <div class="pm-s"><div class="pm-sv" <?= $stats['approvals'] ? 'style="color:#D97706"' : '' ?>><?= $stats['approvals'] ?></div><div class="pm-sl">Do akceptacji</div></div>
          <div class="pm-s"><div class="pm-sv">—</div><div class="pm-sl">Powiadomień sys.</div></div>
        </div>
      </a>
      <?php endif; ?>

      <a href="<?= APP_URL ?>/directory/" class="pm">
        <div class="pm-h" style="background:linear-gradient(135deg,#4338CA,#6366F1)">
          <div class="pm-icon"><i class="bi bi-person-lines-fill"></i></div>
          <div class="pm-title">Katalog</div>
          <div class="pm-desc">Współpracownicy, kontakty</div>
          <div class="pm-cta">Wejdź <i class="bi bi-arrow-right"></i></div>
        </div>
        <div class="pm-f">
          <div class="pm-s"><div class="pm-sv">—</div><div class="pm-sl">Osób</div></div>
          <div class="pm-s"><div class="pm-sv">—</div><div class="pm-sl">Jednostek</div></div>
        </div>
      </a>

    </div>

  </div><!-- /pg -->

  <!-- Szybkie akcje -->
  <nav class="qa">
    <a href="<?= APP_URL ?>/contracts/wolontariat/add.php" class="qa-btn"><i class="bi bi-plus-circle text-primary"></i>Nowa umowa wolontariatu</a>
    <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="qa-btn"><i class="bi bi-heart" style="color:#EF4444"></i>Wolontariusze</a>
    <a href="<?= APP_URL ?>/actions/index.php" class="qa-btn"><i class="bi bi-lightning-charge text-primary"></i>Działania</a>
    <a href="<?= APP_URL ?>/grants/index.php" class="qa-btn"><i class="bi bi-cash-coin text-success"></i>Granty</a>
    <?php if ($crm_enabled): ?><a href="<?= APP_URL ?>/crm/index.php" class="qa-btn"><i class="bi bi-people" style="color:#16A34A"></i>Kontakty CRM</a><?php endif; ?>
    <a href="<?= APP_URL ?>/reports/index.php" class="qa-btn"><i class="bi bi-bar-chart-line text-primary"></i>Raporty</a>
    <a href="<?= APP_URL ?>/search.php" class="qa-btn"><i class="bi bi-search text-muted"></i>Szukaj</a>
  </nav>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
