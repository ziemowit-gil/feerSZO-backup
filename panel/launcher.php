<?php
/**
 * panel/launcher.php — Ekran powitalny panelu współpracownika.
 *
 * Pokazywany zaraz po zalogowaniu jako punkt startowy. Wolontariusz wybiera
 * jedno z trzech miejsc docelowych: Poczta, Zadania lub Zarządzaj umową.
 * Standalone volunteers są przekierowywani do panel/standalone.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';

require_login();
$user    = current_user();
$user_id = (int)$user['id'];

// Standalone volunteers mają inny panel
try {
    $sv = db_one("SELECT is_standalone_volunteer FROM users WHERE id=?", [$user_id]);
    if (!empty($sv['is_standalone_volunteer'])) {
        header('Location: ' . APP_URL . '/panel/standalone.php'); exit;
    }
} catch (\Throwable $e) {}

$_b    = branding_load();
$_org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_h    = (int)date('G');
$_greet = $_h < 5 ? 'Dobranoc' : ($_h < 12 ? 'Dzień dobry' : ($_h < 18 ? 'Witaj' : 'Dobry wieczór'));
$_fn   = explode(' ', trim($user['first_name'] ?? $user['name'] ?? $user['email'] ?? 'Użytkowniku'))[0];
$_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['name'] ?? '');
$_ini  = '';
foreach (preg_split('/\s+/', trim($_name)) as $w) {
    $_ini .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
}
$_ini  = mb_substr($_ini, 0, 2, 'UTF-8') ?: '?';

// URL do poczty z ustawień lub fallback
$mail_url = rtrim(org_setting('webmail_url') ?: 'https://poczta.feer.org.pl', '/');

$choices = [
    [
        'label' => 'Poczta',
        'desc'  => 'Twoja skrzynka pocztowa FEER — czytaj i wysyłaj wiadomości',
        'icon'  => 'bi-envelope-fill',
        'grad'  => 'linear-gradient(135deg,#1D4ED8,#3B82F6)',
        'url'   => $mail_url,
        'ext'   => true,
    ],
    [
        'label' => 'Zadania',
        'desc'  => 'Bieżące zadania, statusy i terminy — Twoja tablica pracy',
        'icon'  => 'bi-kanban-fill',
        'grad'  => 'linear-gradient(135deg,#9A3412,#EA580C)',
        'url'   => APP_URL . '/tasks/dashboard.php',
        'ext'   => false,
    ],
    [
        'label' => 'Zarządzaj umową',
        'desc'  => 'Umowa, dokumenty, godziny, pisma i dane kontaktowe',
        'icon'  => 'bi-file-earmark-text-fill',
        'grad'  => 'linear-gradient(135deg,#9D174D,#EC4899)',
        'url'   => APP_URL . '/panel/index.php',
        'ext'   => false,
    ],
    [
        'label' => 'Tożsamość',
        'desc'  => 'Konta, dostępy do systemów IT — Microsoft 365, Moodle i inne',
        'icon'  => 'bi-person-badge-fill',
        'grad'  => 'linear-gradient(135deg,#065F46,#10B981)',
        'url'   => APP_URL . '/tozsamosc/',
        'ext'   => false,
    ],
];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_org ?: 'Panel') ?> — wybierz gdzie idziesz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#F0F4F8;color:#1E293B}

/* topbar */
.pt{height:52px;background:#fff;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;padding:0 1.5rem;gap:.75rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.pt-brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:inherit;flex:1;min-width:0}
.pt-brand-img{height:30px;max-width:120px;object-fit:contain}
.pt-brand-icon{width:32px;height:32px;border-radius:8px;background:var(--c,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.pt-brand-name{font-weight:700;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pt-right{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.pt-avatar{width:26px;height:26px;border-radius:50%;background:var(--c,#2563eb);color:#fff;font-size:.62rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.pt-name{font-size:.8rem;color:#64748B;font-weight:500}
.pt-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .55rem;border-radius:8px;background:none;border:1.5px solid #E2E8F0;font-size:.78rem;font-weight:500;color:#64748B;text-decoration:none;transition:all .1s}
.pt-btn:hover{border-color:#EF4444;color:#EF4444;background:#FEF2F2}

/* layout */
.pw{max-width:1060px;margin:0 auto;padding:2.25rem 1.25rem 3.5rem}
.ph-greet{font-size:1.6rem;font-weight:800;letter-spacing:-.03em}
.ph-sub{font-size:.87rem;color:#64748B;margin-top:.3rem}

/* tiles */
.apps{display:grid;grid-template-columns:repeat(4,1fr);gap:1.1rem;margin-top:2rem}
.app{position:relative;border-radius:20px;overflow:hidden;text-decoration:none;color:#fff;display:flex;flex-direction:column;min-height:190px;padding:1.35rem 1.4rem 1.2rem;transition:transform .14s,box-shadow .14s}
.app::after{content:'';position:absolute;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.07);right:-55px;bottom:-75px;pointer-events:none}
.app:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(2,6,23,.22);color:#fff}
.app:focus-visible{outline:3px solid #FBBF24;outline-offset:3px}
.app-ic{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0}
.app-body{display:flex;flex-direction:column;flex:1;margin-top:.85rem;position:relative;z-index:1}
.app-title{font-size:1.2rem;font-weight:800;line-height:1.15}
.app-desc{font-size:.78rem;color:rgba(255,255,255,.8);margin-top:.3rem;line-height:1.45;flex:1}
.app-cta{display:inline-flex;align-items:center;gap:.3rem;margin-top:.9rem;font-size:.76rem;font-weight:700;color:rgba(255,255,255,.95)}
.ext-badge{position:absolute;top:.85rem;right:.9rem;z-index:2;display:inline-flex;align-items:center;gap:.2rem;background:rgba(0,0,0,.25);color:#fff;font-size:.62rem;font-weight:700;padding:.15rem .45rem;border-radius:20px}

@media(max-width:640px){
  .apps{grid-template-columns:1fr;gap:.85rem}
  .app{min-height:unset;flex-direction:row;align-items:center;padding:1rem 1.1rem;gap:1rem}
  .app-ic{width:46px;height:46px;font-size:1.35rem}
  .app::after{display:none}
  .app-body{margin-top:0}
  .app-cta{margin-top:.4rem}
}
@media(min-width:641px) and (max-width:960px){
  .apps{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<header class="pt">
  <a href="<?= APP_URL ?>/panel/launcher.php" class="pt-brand">
    <?php if (!empty($_b['logo_url'])): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>" class="pt-brand-img">
    <?php else: ?>
    <div class="pt-brand-icon"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <span class="pt-brand-name"><?= h(org_setting('org_short_name') ?: mb_substr($_org, 0, 28, 'UTF-8')) ?></span>
  </a>
  <div class="pt-right">
    <div class="pt-avatar"><?= h($_ini) ?></div>
    <span class="pt-name"><?= h($_fn) ?></span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="pt-btn"
       onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right"></i> Wyloguj
    </a>
  </div>
</header>

<main class="pw">
  <div class="ph-greet"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</div>
  <div class="ph-sub">Gdzie dziś chcesz przejść?</div>

  <div class="apps">
    <?php foreach ($choices as $c): ?>
    <a href="<?= h($c['url']) ?>"
       class="app"
       style="background:<?= $c['grad'] ?>"
       <?= !empty($c['ext']) ? 'target="_blank" rel="noopener"' : '' ?>>
      <?php if (!empty($c['ext'])): ?>
        <span class="ext-badge"><i class="bi bi-box-arrow-up-right"></i> zewnętrzna</span>
      <?php endif; ?>
      <div class="app-ic"><i class="bi <?= h($c['icon']) ?>"></i></div>
      <div class="app-body">
        <div class="app-title"><?= h($c['label']) ?></div>
        <div class="app-desc"><?= h($c['desc']) ?></div>
        <div class="app-cta">Otwórz <i class="bi bi-arrow-right"></i></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
