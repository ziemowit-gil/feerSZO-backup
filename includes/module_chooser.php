<?php
/**
 * includes/module_chooser.php — Generyczny ekran wyboru modułu po zalogowaniu.
 *
 * Pokazywany, gdy użytkownik ma dostęp do więcej niż 2 modułów (panel
 * wolontariusza liczy się jako moduł). Dla kont zarządczych rolę „wyboru"
 * pełni pełny portal — ten ekran obsługuje wolontariuszy i konta zawężone.
 *
 * Wejście:
 *   $__choices — lista wpisów: ['label','desc','icon','grad','url','primary'(bool)]
 *                Pierwszy/„primary" wpis dostaje plakietkę „Twój moduł".
 */
require_once __DIR__ . '/branding.php';

$_u     = current_user();
$_org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_h     = (int)date('G');
$_greet = $_h < 5 ? 'Dobranoc' : ($_h < 12 ? 'Dzień dobry' : ($_h < 18 ? 'Witaj' : 'Dobry wieczór'));
$_fn    = explode(' ', trim($_u['first_name'] ?? $_u['name'] ?? $_u['email'] ?? 'Użytkowniku'))[0];
$_b     = branding_load();
$_name  = trim(($_u['first_name'] ?? '') . ' ' . ($_u['last_name'] ?? '')) ?: ($_u['name'] ?? '');
$_ini   = ''; foreach (preg_split('/\s+/', trim($_name)) as $w) $_ini .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
$_ini   = mb_substr($_ini, 0, 2, 'UTF-8') ?: '?';
$__choices = array_values(array_filter($__choices ?? []));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_org ?: 'System') ?> — wybór modułu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#F0F4F8;color:#1E293B}
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
.pw{max-width:980px;margin:0 auto;padding:1.75rem 1.25rem 3rem}
.ph-greet{font-size:1.55rem;font-weight:800;letter-spacing:-.03em}
.ph-sub{font-size:.84rem;color:#64748B;margin-top:.25rem}
.sec-h{display:flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94A3B8;margin:1.5rem .15rem .85rem}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem}
.app{position:relative;border-radius:18px;overflow:hidden;text-decoration:none;color:#fff;display:flex;flex-direction:column;min-height:158px;padding:1.15rem 1.2rem 1.05rem;transition:transform .14s,box-shadow .14s}
.app::after{content:'';position:absolute;width:170px;height:170px;border-radius:50%;background:rgba(255,255,255,.08);right:-45px;bottom:-65px;pointer-events:none}
.app:hover{transform:translateY(-3px);box-shadow:0 14px 34px rgba(2,6,23,.20);color:#fff}
.app:focus-visible{outline:3px solid #FBBF24;outline-offset:3px}
.app-ic{width:46px;height:46px;border-radius:13px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.app-title{font-size:1.08rem;font-weight:800;line-height:1.2;margin-top:.7rem;position:relative;z-index:1}
.app-desc{font-size:.76rem;color:rgba(255,255,255,.78);margin-top:.22rem;line-height:1.4;flex:1;position:relative;z-index:1}
.app-cta{display:inline-flex;align-items:center;gap:.25rem;margin-top:.85rem;font-size:.74rem;font-weight:700;color:rgba(255,255,255,.95);position:relative;z-index:1}
.app-badge{position:absolute;top:.85rem;right:.9rem;z-index:2;display:inline-flex;align-items:center;gap:.25rem;background:rgba(0,0,0,.28);color:#fff;font-size:.64rem;font-weight:700;padding:.16rem .5rem;border-radius:20px}
.note{display:flex;align-items:flex-start;gap:.55rem;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:.65rem 1rem;margin-top:1.5rem;font-size:.8rem;color:#1E40AF}
@media(max-width:600px){.apps{grid-template-columns:1fr 1fr;gap:.7rem}.app{min-height:148px;padding:.9rem .95rem}.pt{padding:0 .75rem}.pt-name{display:none}}
@media(max-width:380px){.apps{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="pt">
  <a href="<?= APP_URL ?>/portal.php" class="pt-brand">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>" class="pt-brand-img">
    <?php else: ?>
    <div class="pt-brand-icon"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <span class="pt-brand-name"><?= h(org_setting('org_short_name') ?: mb_substr($_org, 0, 28, 'UTF-8')) ?></span>
  </a>
  <div class="pt-right">
    <div class="pt-avatar"><?= h($_ini) ?></div>
    <span class="pt-name"><?= h($_fn) ?></span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="pt-btn" onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right"></i> Wyloguj
    </a>
  </div>
</header>

<main class="pw">
  <div class="ph-greet"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</div>
  <div class="ph-sub">Wybierz moduł, do którego chcesz przejść.</div>

  <div class="sec-h"><i class="bi bi-grid-3x3-gap-fill"></i> Twoje moduły</div>
  <div class="apps">
    <?php foreach ($__choices as $c):
      $primary = !empty($c['primary']);
    ?>
    <a href="<?= h($c['url']) ?>" class="app" style="background:<?= $c['grad'] ?>">
      <?php if ($primary): ?><span class="app-badge"><i class="bi bi-star-fill"></i> Twój moduł</span><?php endif; ?>
      <div class="app-ic"><i class="bi <?= h($c['icon']) ?>"></i></div>
      <div class="app-title"><?= h($c['label']) ?></div>
      <div class="app-desc"><?= h($c['desc'] ?? '') ?></div>
      <div class="app-cta">Otwórz <i class="bi bi-arrow-right"></i></div>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="note">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>Twoje konto ma dostęp do kilku modułów. Wybierz, gdzie chcesz przejść —
    możesz wrócić tutaj w każdej chwili, otwierając stronę startową.</div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
