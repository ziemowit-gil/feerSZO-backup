<?php
/**
 * portal.php — Ekran wyboru modułu po zalogowaniu (launcher modułów).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

if (defined('CRM_STANDALONE') && CRM_STANDALONE) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (($_SESSION['user']['portal_scope'] ?? '') === 'tasks_only') { header('Location: ' . APP_URL . '/tasks/inbox.php'); exit; }
if (is_viewer()) {
    // Standalone volunteer (bez umowy) → tablica zadań, nie panel umów
    try {
        $__sv = db_one("SELECT is_standalone_volunteer FROM users WHERE id=?", [(int)current_user()['id']]);
        if (!empty($__sv['is_standalone_volunteer'])) {
            header('Location: ' . APP_URL . '/panel/standalone.php'); exit;
        }
    } catch (\Throwable $e) {}
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}
// Konta zawężone (crm_only / ezd_only): jeśli przyznano dodatkowe moduły ponad
// rolę — pokaż launcher z modułem bazowym + dodatkowymi; w przeciwnym razie
// przekieruj do modułu bazowego (zachowanie jak dotychczas).
require_once __DIR__ . '/includes/permissions.php';
$__base = scoped_base_module();
if ($__base !== null) {
    $__uid = (int)current_user()['id'];
    $__extra = scoped_launcher_module_keys($__uid);
    if (!$__extra) {
        header('Location: ' . APP_URL . ($__base === 'crm' ? '/crm/dashboard.php' : '/ezd/index.php'));
        exit;
    }
    $__launch_keys = array_merge([$__base], $__extra);
    include __DIR__ . '/includes/scoped_launcher.php';
    exit;
}

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

// Moduł rezerwacji szkoleń (TidyCal)
require_once __DIR__ . '/includes/tidycal.php';
$szkolenia_enabled = tidycal_enabled() && (can_read('szkolenia') || is_admin());

// Moduł Strategii
require_once __DIR__ . '/includes/strategy.php';
$strategy_enabled = can_read('umowy') || is_admin();
$strat_active = 0; $strat_at_risk = 0;
if ($strategy_enabled) {
    try {
        $strat_objs = db_all("SELECT * FROM v_strategy_dashboard WHERE status='aktywny'");
        $strat_active = count($strat_objs);
        foreach ($strat_objs as $_o) { if (strategy_health_score($_o) === 'red') $strat_at_risk++; }
    } catch(\Throwable $e) {}
}

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

// ── Definicja modułów (launcher) ─────────────────────────────────────────────
// Każdy moduł jest zawsze widoczny. Gdy brak dostępu → kafel wyszarzony,
// nieklikalny, z plakietką „Brak dostępu".
$modules = [
    [
        'title'  => 'System Zarządzania',
        'desc'   => 'Umowy, granty, działania, korespondencja, raporty',
        'icon'   => 'bi-building-fill',
        'grad'   => 'linear-gradient(135deg,#1E3A5F,#1D6EF9)',
        'url'    => APP_URL . '/index.php',
        'access' => true,
        'stat'   => $stats['umowy'] . ' aktywnych umów',
        'badge'  => $stats['approvals'] ? ('<i class="bi bi-clock-fill"></i> ' . $stats['approvals'] . ' do akceptacji') : null,
    ],
    [
        'title'  => 'CRM',
        'desc'   => 'Kontakty, sprawy, kampanie',
        'icon'   => 'bi-diagram-2-fill',
        'grad'   => 'linear-gradient(135deg,#14532D,#16A34A)',
        'url'    => APP_URL . '/crm/dashboard.php',
        'access' => $crm_enabled,
        'stat'   => $stats['crm'] . ' kontaktów',
        'badge'  => null,
    ],
    [
        'title'  => 'Dydaktyka',
        'desc'   => 'Zajęcia TI, beneficjenci, wizyty (Karty 30)',
        'icon'   => 'bi-card-checklist',
        'grad'   => 'linear-gradient(135deg,#7c2d12,#c2410c)',
        'url'    => APP_URL . '/karty30/index.php',
        'access' => $k30_enabled,
        'stat'   => $k30_today . ' wizyt dziś',
        'badge'  => $k30_today ? ('<i class="bi bi-calendar-check-fill"></i> ' . $k30_today . ' dziś') : null,
    ],
    [
        'title'  => 'Strategia NGO',
        'desc'   => 'Cele strategiczne, sfery pożytku, sprawozdawczość',
        'icon'   => 'bi-bullseye',
        'grad'   => 'linear-gradient(135deg,#4C1D95,#7C3AED)',
        'url'    => APP_URL . '/strategy/index.php',
        'access' => $strategy_enabled,
        'stat'   => $strat_active . ' aktywnych celów',
        'badge'  => $strat_at_risk > 0 ? ('<i class="bi bi-exclamation-triangle-fill"></i> ' . $strat_at_risk . ' zagrożone') : null,
    ],
    [
        'title'  => 'Umów się na szkolenie',
        'desc'   => 'Rezerwacja terminu szkolenia online',
        'icon'   => 'bi-calendar2-check',
        'grad'   => 'linear-gradient(135deg,#7C3AED,#C084FC)',
        'url'    => APP_URL . '/szkolenia/index.php',
        'access' => $szkolenia_enabled,
        'stat'   => null,
        'badge'  => null,
    ],
    [
        'title'  => 'Katalog',
        'desc'   => 'Współpracownicy, kontakty, jednostki',
        'icon'   => 'bi-person-lines-fill',
        'grad'   => 'linear-gradient(135deg,#4338CA,#6366F1)',
        'url'    => APP_URL . '/directory/',
        'access' => true,
        'stat'   => null,
        'badge'  => null,
    ],
    [
        'title'  => 'Administrator',
        'desc'   => 'Ustawienia, użytkownicy, moduły',
        'icon'   => 'bi-shield-shaded',
        'grad'   => 'linear-gradient(135deg,#1E293B,#475569)',
        'url'    => APP_URL . '/admin/index.php',
        'access' => is_admin(),
        'stat'   => $stats['approvals'] ? ($stats['approvals'] . ' do akceptacji') : null,
        'badge'  => null,
    ],
];
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
.pw{max-width:1120px;margin:0 auto;padding:1.75rem 1.25rem 3rem}

/* ── Header splash ────────────────────── */
.ph{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem}
.ph-greet{font-size:1.65rem;font-weight:800;letter-spacing:-.03em;line-height:1.15}
.ph-sub{font-size:.84rem;color:#64748B;margin-top:.25rem}
.ph-stats{display:flex;gap:.5rem;flex-wrap:wrap;align-self:flex-end}
.chip{display:flex;align-items:center;gap:.5rem;background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:.45rem .75rem}
.chip-n{font-size:1.2rem;font-weight:800;line-height:1}
.chip-l{font-size:.66rem;color:#94A3B8;line-height:1.15;white-space:nowrap}

/* ── Banner ───────────────────────────── */
.pban{display:flex;align-items:center;gap:.6rem;background:#FFFBEB;border:1.5px solid #FDE68A;border-radius:12px;padding:.6rem 1rem;margin-bottom:1.5rem;font-size:.82rem;color:#92400E;text-decoration:none;transition:background .1s}
.pban:hover{background:#FEF3C7;color:#92400E}

/* ── Nagłówki sekcji ──────────────────── */
.sec-h{display:flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94A3B8;margin:0 .15rem .85rem}
.sec-h .bi{font-size:.9rem}

/* ── Launcher: siatka aplikacji ───────── */
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem}
.app{position:relative;border-radius:18px;overflow:hidden;text-decoration:none;color:#fff;display:flex;flex-direction:column;min-height:168px;padding:1.15rem 1.2rem 1.05rem;transition:transform .14s,box-shadow .14s}
.app::after{content:'';position:absolute;width:170px;height:170px;border-radius:50%;background:rgba(255,255,255,.08);right:-45px;bottom:-65px;pointer-events:none}
.app:hover{transform:translateY(-3px);box-shadow:0 14px 34px rgba(2,6,23,.20);color:#fff}
.app-ic{width:46px;height:46px;border-radius:13px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff}
.app-title{font-size:1.08rem;font-weight:800;line-height:1.2;margin-top:.7rem;position:relative;z-index:1}
.app-desc{font-size:.76rem;color:rgba(255,255,255,.78);margin-top:.22rem;line-height:1.4;flex:1;position:relative;z-index:1}
.app-foot{display:flex;align-items:center;justify-content:space-between;gap:.4rem;margin-top:.85rem;position:relative;z-index:1}
.app-stat{font-size:.71rem;font-weight:700;color:#fff;background:rgba(255,255,255,.18);padding:.18rem .55rem;border-radius:20px}
.app-cta{display:inline-flex;align-items:center;gap:.25rem;font-size:.74rem;font-weight:700;color:rgba(255,255,255,.95);margin-left:auto}
.app-badge{position:absolute;top:.85rem;right:.9rem;z-index:2;display:inline-flex;align-items:center;gap:.25rem;background:rgba(0,0,0,.28);color:#fff;font-size:.64rem;font-weight:700;padding:.16rem .5rem;border-radius:20px}

/* ── Kafel zablokowany (brak dostępu) ── */
.app-locked{filter:grayscale(1);opacity:.6;cursor:not-allowed}
.app-locked:hover{transform:none;box-shadow:none}
.app-lock{position:absolute;top:.85rem;right:.9rem;z-index:2;display:inline-flex;align-items:center;gap:.3rem;background:rgba(0,0,0,.48);color:#fff;font-size:.64rem;font-weight:700;padding:.16rem .55rem;border-radius:20px}

/* ── Sekcja dolna: ostatnie + akcje ───── */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.75rem}
.ps-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden}
.ps-head{padding:.7rem 1rem;border-bottom:1px solid #F1F5F9;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94A3B8;display:flex;align-items:center;gap:.4rem}
.ps-item{display:flex;align-items:center;gap:.65rem;padding:.55rem 1rem;border-bottom:1px solid #F8FAFC;text-decoration:none;color:inherit;transition:background .1s;font-size:.82rem}
.ps-item:last-child{border-bottom:none}
.ps-item:hover{background:#F8FAFC}
.ps-item-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
.ps-item-name{font-weight:600;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ps-item-sub{font-size:.71rem;color:#94A3B8}

/* ── Szybkie akcje ────────────────────── */
.qa{display:flex;flex-wrap:wrap;gap:.45rem;padding:.85rem 1rem 1rem}
.qa-btn{display:inline-flex;align-items:center;gap:.35rem;background:#F8FAFC;border:1.5px solid #E2E8F0;border-radius:20px;padding:.35rem .8rem;font-size:.77rem;font-weight:500;color:#374151;text-decoration:none;transition:all .1s;white-space:nowrap}
.qa-btn:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb);background:#EFF6FF}

@media(max-width:760px){.grid2{grid-template-columns:1fr}.ph-greet{font-size:1.35rem}}
@media(max-width:600px){.apps{grid-template-columns:1fr 1fr;gap:.7rem}.app{min-height:150px;padding:.9rem .95rem}.ph-stats{display:none}.pt{padding:0 .75rem}}
@media(max-width:380px){.apps{grid-template-columns:1fr}}
@keyframes fi{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.ph{animation:fi .35s ease both}.pban{animation:fi .35s .05s ease both}.apps{animation:fi .4s .08s ease both}.grid2{animation:fi .4s .12s ease both}
@media(prefers-reduced-motion:reduce){.ph,.pban,.apps,.grid2{animation:none}}
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
      <div class="chip">
        <div class="chip-n" style="color:#2563EB"><?= $stats['umowy'] ?></div>
        <div class="chip-l">Aktywnych<br>umów</div>
      </div>
      <?php if ($stats['granty']): ?>
      <div class="chip">
        <div class="chip-n" style="color:#16A34A"><?= $stats['granty'] ?></div>
        <div class="chip-l">Grantów</div>
      </div>
      <?php endif; ?>
      <?php if ($stats['approvals']): ?>
      <div class="chip">
        <div class="chip-n" style="color:#D97706"><?= $stats['approvals'] ?></div>
        <div class="chip-l">Do<br>akceptacji</div>
      </div>
      <?php endif; ?>
      <?php if ($_total_unread): ?>
      <div class="chip">
        <div class="chip-n" style="color:#EF4444"><?= $_total_unread ?></div>
        <div class="chip-l">Powiadomień</div>
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

  <!-- Launcher: wszystkie moduły -->
  <div class="sec-h"><i class="bi bi-grid-3x3-gap-fill"></i> Moduły</div>
  <div class="apps">
    <?php foreach ($modules as $m):
      $acc  = (bool)$m['access'];
      $tag  = $acc ? 'a' : 'div';
      $href = $acc ? ' href="' . h($m['url']) . '"' : '';
    ?>
    <<?= $tag ?><?= $href ?> class="app<?= $acc ? '' : ' app-locked' ?>" style="background:<?= $m['grad'] ?>">
      <?php if (!$acc): ?>
        <span class="app-lock"><i class="bi bi-lock-fill"></i>Brak dostępu</span>
      <?php elseif (!empty($m['badge'])): ?>
        <span class="app-badge"><?= $m['badge'] ?></span>
      <?php endif; ?>
      <div class="app-ic"><i class="bi <?= h($m['icon']) ?>"></i></div>
      <div class="app-title"><?= h($m['title']) ?></div>
      <div class="app-desc"><?= h($m['desc']) ?></div>
      <div class="app-foot">
        <?php if ($acc && !empty($m['stat'])): ?><span class="app-stat"><?= h($m['stat']) ?></span><?php endif; ?>
        <?php if ($acc): ?><span class="app-cta">Otwórz <i class="bi bi-arrow-right"></i></span><?php endif; ?>
      </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>

  <!-- Sekcja dolna: ostatnie umowy + szybkie akcje -->
  <div class="grid2">

    <!-- Ostatnie umowy -->
    <div class="ps-card">
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

    <!-- Szybkie akcje -->
    <div class="ps-card">
      <div class="ps-head"><i class="bi bi-lightning-charge-fill"></i> Szybkie akcje</div>
      <nav class="qa">
        <a href="<?= APP_URL ?>/contracts/wolontariat/add.php" class="qa-btn"><i class="bi bi-plus-circle text-primary"></i>Nowa umowa</a>
        <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="qa-btn"><i class="bi bi-heart" style="color:#EF4444"></i>Wolontariusze</a>
        <?php if (module_enabled('dyspozycyjnosc_enabled')):
          $_urlop_pending = 0;
          try { require_once __DIR__ . '/includes/dyspozycyjnosc.php'; $_urlop_pending = urlop_pending_count(); } catch (\Throwable $e) {}
        ?>
        <a href="<?= APP_URL ?>/contracts/wolontariat/urlopy.php" class="qa-btn"><i class="bi bi-airplane" style="color:#D97706"></i>Urlopy<?php if ($_urlop_pending): ?> <span class="badge bg-danger rounded-pill"><?= $_urlop_pending ?></span><?php endif; ?></a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/strategy/actions/index.php" class="qa-btn"><i class="bi bi-lightning-charge text-primary"></i>Działania</a>
        <a href="<?= APP_URL ?>/grants/index.php" class="qa-btn"><i class="bi bi-cash-coin text-success"></i>Granty</a>
        <?php if ($crm_enabled): ?><a href="<?= APP_URL ?>/crm/index.php" class="qa-btn"><i class="bi bi-people" style="color:#16A34A"></i>Kontakty CRM</a><?php endif; ?>
        <a href="<?= APP_URL ?>/reports/index.php" class="qa-btn"><i class="bi bi-bar-chart-line text-primary"></i>Raporty</a>
        <a href="<?= APP_URL ?>/panel/index.php" class="qa-btn"><i class="bi bi-house text-success"></i>Mój panel</a>
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="qa-btn"><i class="bi bi-megaphone" style="color:#D97706"></i>Komunikaty<?php if ($_total_unread): ?> <span class="badge bg-danger rounded-pill"><?= $_total_unread ?></span><?php endif; ?></a>
        <a href="<?= APP_URL ?>/search.php" class="qa-btn"><i class="bi bi-search text-muted"></i>Szukaj</a>
      </nav>
    </div>

  </div>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
