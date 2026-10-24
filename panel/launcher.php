<?php
/**
 * panel/launcher.php — Ekran powitalny panelu współpracownika.
 *
 * Pokazywany po każdym zalogowaniu jako punkt startowy (auth_default_landing()). Współpracownik wybiera
 * miejsce docelowe: Poczta, Zadania, Zarządzaj umową, Tożsamość (+ Dydaktyka – panel
 * dla dydaktyków). Wygląd METRO jak System Tożsamości (modules/panel_metro).
 * Standalone volunteers są przekierowywani do panel/standalone.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';

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

// Strona startowa po każdym logowaniu (auth_default_landing()) — nowa osoba albo bez złożonych oświadczeń
// najpierw przechodzi kolejkę pierwszego logowania: zgoda → kreator → oświadczenie RODO.
require_once dirname(__DIR__) . '/modules/first_run/lib/firstRun.php';
firstLoginGate($user, true, true);

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

// Kafel „Poczta" otwiera od razu Outlook w przeglądarce (skrzynki są na Microsoft 365).
$mail_url = 'https://outlook.office.com/mail/';

$choices = [
    [
        'label' => 'Poczta',
        'desc'  => 'Twoja skrzynka pocztowa FEER — czytaj i wysyłaj wiadomości',
        'icon'  => 'bi-envelope-fill',
        'color' => 'indigo',
        'url'   => $mail_url,
        'ext'   => true,
    ],
    [
        'label' => 'Zadania',
        'desc'  => 'Bieżące zadania, statusy i terminy — Twoja tablica pracy',
        'icon'  => 'bi-kanban-fill',
        'color' => 'orange',
        'url'   => APP_URL . '/tasks/dashboard.php',
        'ext'   => false,
    ],
    [
        'label' => 'Zarządzaj współpracą',
        'desc'  => 'Umowa, dokumenty, godziny, pisma i dane kontaktowe',
        'icon'  => 'bi-file-earmark-text-fill',
        'color' => 'magenta',
        'url'   => APP_URL . '/panel/index.php',
        'ext'   => false,
    ],
    [
        'label' => 'Tożsamość',
        'desc'  => 'Konta, dostępy do systemów IT — Microsoft 365 i inne',
        'icon'  => 'bi-person-badge-fill',
        'color' => 'dark',
        'url'   => APP_URL . '/tozsamosc/',
        'ext'   => false,
    ],
    [
        'label' => 'Usługi IT',
        'desc'  => 'Portal Usług IT — instrukcje, zgłoszenia i pomoc',
        'icon'  => 'bi-pc-display-horizontal',
        'color' => 'teal',
        'url'   => APP_URL . '/uslugi-it/',
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
/* Launcher — METRO jak System Tożsamości: ciemny app-bar, lekka duża typografia, płaskie kafle, 0 zaokrągleń */
html,body{min-height:100vh;margin:0;background:#fff;color:#111827;-webkit-font-smoothing:antialiased;
  font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif}
.lc-skip{position:absolute;left:-999px}.lc-skip:focus{left:1rem;top:1rem;z-index:1080;background:#fff;color:#111827;padding:.5rem 1rem;font-weight:600}
.lcbar{background:#1d1d1d;color:#fff;position:sticky;top:0;z-index:100}
.lcbar .inner{max-width:1100px;margin:0 auto;padding:.45rem 1rem;display:flex;align-items:center;gap:.75rem}
.lcbar .brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:#fff;flex:1;min-width:0}
.lcbar .brand img{height:30px;max-width:120px;object-fit:contain;background:#fff;padding:2px 4px}
.lcbar .mark{width:34px;height:34px;background:#0f6fa8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.lcbar .brand b{font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lcbar .brand small{display:block;font-size:.65rem;color:rgba(255,255,255,.65);font-weight:500}
.lcbar .ava{width:30px;height:30px;background:#0f6fa8;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem}
.lcbar .who{font-size:.8rem;color:rgba(255,255,255,.85)}
.lcbar .lnk{color:rgba(255,255,255,.85);text-decoration:none;font-size:.82rem;font-weight:500;padding:.4rem .65rem;display:inline-flex;align-items:center;gap:.4rem}
.lcbar .lnk:hover{background:rgba(255,255,255,.1);color:#fff}
.lcbar .lnk:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.lc{padding:2.25rem 1rem 3.5rem}
.lc.pvm .mt-tile .sub{white-space:normal;overflow:visible}
.lc.pvm .mt-tile{min-height:0}
@media(max-width:560px){.lcbar .who{display:none}}
</style>
</head>
<body>
<a href="#lc-main" class="lc-skip">Przejdź do treści</a>
<header class="lcbar">
  <div class="inner">
    <a href="<?= APP_URL ?>/panel/launcher.php" class="brand">
      <?php if (!empty($_b['logo_url'])): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="">
      <?php else: ?>
      <span class="mark" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
      <?php endif; ?>
      <span><b><?= h(org_setting('org_short_name') ?: mb_substr($_org, 0, 28, 'UTF-8')) ?></b><small>Panel współpracownika</small></span>
    </a>
    <span class="ava" aria-hidden="true"><?= h($_ini) ?></span>
    <span class="who"><?= h($_fn) ?></span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="lnk" onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Wyloguj</span>
    </a>
  </div>
</header>

<?php panelMetroAssets('#0f6fa8'); ?>
<main id="lc-main" class="lc pvm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · <?= h($_org) ?> · <?= date('d.m.Y') ?></div>
      <h1 class="mt-title"><?= h($_greet) ?>, <?= h($_fn) ?></h1>
      <p class="mt-lead">Gdzie dziś chcesz przejść?</p>
    </div>
  </div>

  <nav class="mt-grid" aria-label="Miejsca docelowe">
    <?php foreach ($choices as $c):
      panelMetroTile(['href'=>$c['url'],'icon'=>$c['icon'],'label'=>$c['label'],'sub'=>$c['desc'],'color'=>$c['color'],
                      'size'=>'w','external'=>!empty($c['ext']),'tag'=>!empty($c['ext']) ? 'zewnętrzna' : '']);
    endforeach;
    panelMetroDydaktykTile(); ?>
  </nav>
</main>
</body>
</html>
