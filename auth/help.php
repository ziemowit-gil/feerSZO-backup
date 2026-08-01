<?php
/**
 * auth/help.php — Pomoc z logowaniem: trzy scenariusze z bezpośrednimi akcjami.
 * Standalone, dostępna bez logowania.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';

auth_start();

if (current_user()) { header('Location: ' . APP_URL . '/portal.php'); exit; }

function _help_method_enabled(string $key, bool $default = true): bool {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        return $r !== null ? (bool)$r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

$ms_available = false;
try { $ms_available = ms_login_available() && _help_method_enabled('login_method_ms365', false); } catch (\Throwable $e) {}

$sms_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled() && _help_method_enabled('login_method_sms', false);
} catch (\Throwable $e) {}

$code_available = _help_method_enabled('login_method_code', true);

$_b             = branding_load();
$_login_tagline = '';
try { $_login_tagline = org_setting('login_tagline') ?: ''; } catch (\Throwable $e) {}
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pomoc z logowaniem — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

.skip-link{
  position:absolute;top:-100%;left:1rem;z-index:9999;
  background:var(--c,#2563eb);color:#fff;
  padding:.5rem 1.25rem;border-radius:0 0 8px 8px;
  font-weight:700;text-decoration:none;font-size:.95rem;
}
.skip-link:focus{top:0;outline:3px solid #FBBF24;outline-offset:2px}
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:3px!important}
*:focus:not(:focus-visible){outline:none}

/* ── Shell ───────────────────────────────────────────────────── */
.login-shell{min-height:100vh;display:flex;align-items:stretch}

/* ── Lewa ────────────────────────────────────────────────────── */
.login-left{
  width:300px;flex-shrink:0;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 55%,#1e3a5f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:2.25rem 1.75rem;
  border-right:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.login-left::after{
  content:'';position:absolute;width:260px;height:260px;border-radius:50%;
  border:55px solid rgba(255,255,255,.025);bottom:-80px;right:-80px;pointer-events:none;
}
.left-logo{max-height:44px;max-width:140px;object-fit:contain;filter:brightness(0)invert(1);opacity:.85;display:block;margin-bottom:1rem}
.left-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;margin-bottom:1rem}
.left-org{font-size:1.05rem;font-weight:800;color:#fff;margin:0 0 .25rem;line-height:1.3}
.left-tagline{font-size:.77rem;color:rgba(255,255,255,.45);margin:0 0 1.75rem;line-height:1.5}
.left-about-text{font-size:.79rem;color:rgba(255,255,255,.48);line-height:1.65;margin:0}
.left-footer{position:relative;z-index:1}
.left-security{display:flex;align-items:center;gap:.4rem;font-size:.73rem;color:rgba(255,255,255,.3);margin-bottom:.4rem}
.left-copyright{font-size:.68rem;color:rgba(255,255,255,.2)}

/* ── Prawa ───────────────────────────────────────────────────── */
.login-right{
  flex:1;background:#F1F5F9;
  display:flex;align-items:center;justify-content:center;
  padding:2.5rem 1.5rem;overflow-y:auto;
}
.help-box{
  width:100%;max-width:560px;
}

/* ── Nagłówek ────────────────────────────────────────────────── */
.help-eyebrow{font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c,#2563eb);margin:0 0 .3rem}
.help-heading{font-size:1.65rem;font-weight:800;color:#0f172a;margin:0 0 .5rem;letter-spacing:-.015em}
.help-lead{font-size:.92rem;color:#64748b;line-height:1.6;margin:0 0 1.5rem}

/* ── Login info ──────────────────────────────────────────────── */
.login-info{
  display:flex;align-items:flex-start;gap:.65rem;
  padding:.9rem 1rem;background:#fff;border:1px solid #e2e8f0;
  border-left:3px solid var(--c,#2563eb);border-radius:10px;
  margin-bottom:1.5rem;font-size:.85rem;color:#334155;line-height:1.55;
}
.login-info i{flex-shrink:0;color:var(--c,#2563eb);font-size:1rem;margin-top:.1rem}

/* ── Divider ─────────────────────────────────────────────────── */
.scenarios-label{font-size:.74rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#94a3b8;margin:0 0 .75rem}

/* ── Karty scenariuszy ───────────────────────────────────────── */
.scenario{
  display:flex;align-items:center;gap:1rem;
  background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
  padding:1.15rem 1.2rem;margin-bottom:.75rem;
  text-decoration:none;color:inherit;
  transition:border-color .15s,box-shadow .15s;
  position:relative;
}
.scenario:hover{
  border-color:var(--c,#2563eb);
  box-shadow:0 4px 18px rgba(37,99,235,.09);
  color:inherit;
}
.scenario-icon{
  width:46px;height:46px;border-radius:12px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1.25rem;
}
.scenario-icon.blue{background:var(--c-bg,#eff6ff);color:var(--c,#2563eb)}
.scenario-icon.amber{background:#fffbeb;color:#d97706}
.scenario-icon.green{background:#f0fdf4;color:#16a34a}
.scenario-icon.slate{background:#f1f5f9;color:#475569}
.scenario-body{flex:1;min-width:0}
.scenario-title{font-size:.95rem;font-weight:700;color:#0f172a;line-height:1.35;margin:0 0 .2rem}
.scenario-desc{font-size:.82rem;color:#64748b;line-height:1.5;margin:0}
.scenario-arrow{flex-shrink:0;color:#cbd5e1;font-size:1.1rem;transition:color .15s,transform .15s}
.scenario:hover .scenario-arrow{color:var(--c,#2563eb);transform:translateX(2px)}

/* Wariant rozwijany (accordion-like) */
.scenario-toggle{cursor:pointer;user-select:none}
.scenario-detail{
  display:none;
  background:#f8fafc;border:1.5px solid #e2e8f0;border-top:none;
  border-radius:0 0 14px 14px;padding:1rem 1.2rem 1.1rem;
  margin-top:-.75rem;margin-bottom:.75rem;
}
.scenario-detail.open{display:block}
.scenario-toggle.active{border-radius:14px 14px 0 0;border-color:var(--c,#2563eb);border-bottom-color:#e2e8f0}
.scenario-toggle.active .scenario-arrow{color:var(--c,#2563eb);transform:rotate(90deg)}

/* Kroki wewnątrz karty */
.mini-steps{list-style:none;padding:0;margin:0 0 .9rem;counter-reset:ms}
.mini-step{
  padding:.3rem 0 .3rem 2rem;position:relative;counter-increment:ms;
  font-size:.84rem;color:#334155;line-height:1.5;
}
.mini-step::before{
  content:counter(ms);position:absolute;left:0;top:.28rem;
  width:1.4rem;height:1.4rem;border-radius:50%;
  background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.75rem;
}
.mini-step strong{color:#0f172a}

/* ── Przycisk w detail ───────────────────────────────────────── */
.btn-scenario{
  display:inline-flex;align-items:center;gap:.45rem;
  background:var(--c,#2563eb);color:var(--c-text,#fff);
  border:2px solid var(--c,#2563eb);border-radius:8px;
  padding:.6rem 1.1rem;font-size:.88rem;font-weight:700;
  text-decoration:none;min-height:44px;
  transition:background .15s,border-color .15s;
}
.btn-scenario:hover{background:var(--c-dark,#1d4ed8);border-color:var(--c-dark,#1d4ed8);color:var(--c-text,#fff)}
.btn-scenario.ghost{
  background:#fff;color:#334155;border-color:#e2e8f0;
}
.btn-scenario.ghost:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb)}

/* ── Stopka ──────────────────────────────────────────────────── */
.help-footer{
  display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;
  gap:.5rem;padding-top:1.1rem;border-top:1px solid #e2e8f0;margin-top:1.1rem;
  font-size:.8rem;color:#94a3b8;
}
.help-footer a{color:#64748b;text-decoration:none;font-size:.8rem}
.help-footer a:hover{color:var(--c,#2563eb)}

/* ── High contrast ───────────────────────────────────────────── */
@media(prefers-contrast:high){
  .scenario{border-width:2px}
  .scenario:hover{border-width:3px}
  .btn-scenario{border-width:3px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important}}

/* ── Mobile ──────────────────────────────────────────────────── */
@media(max-width:680px){
  .login-shell{flex-direction:column}
  .login-left{
    width:100%;padding:.9rem 1.25rem;
    flex-direction:row;align-items:center;gap:.75rem;
    border-right:none;border-bottom:1px solid rgba(255,255,255,.07);
  }
  .login-left::after{display:none}
  .login-left .left-tagline,
  .login-left .left-about-text,
  .login-left .left-footer{display:none}
  .login-left .left-org{font-size:.9rem;margin:0}
  .login-left .left-logo{margin-bottom:0;max-height:28px}
  .login-left .left-icon{width:30px;height:30px;font-size:1rem;margin-bottom:0}
  .login-right{padding:1.25rem 1rem;align-items:flex-start;background:#F1F5F9}
  .help-box{padding:.25rem 0}
}
</style>
</head>
<body>

<a href="#help-content" class="skip-link">Przejdź do treści pomocy</a>

<div class="login-shell">

<!-- ══ Lewa — branding ══════════════════════════════════════════════════════ -->
<aside class="login-left" aria-label="Informacje o organizacji">
  <div>
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="left-logo">
    <?php else: ?>
    <div class="left-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <p class="left-org"><?= h($org_name) ?></p>
    <?php if ($_login_tagline): ?><p class="left-tagline"><?= h($_login_tagline) ?></p><?php endif; ?>
    <p class="left-about-text">Wybierz swoją sytuację — znajdziesz odpowiednie instrukcje i bezpośredni link do potrzebnej akcji.</p>
  </div>
  <div class="left-footer">
    <div class="left-security">
      <i class="bi bi-lock-fill" aria-hidden="true"></i>
      Połączenie szyfrowane HTTPS
    </div>
    <div class="left-copyright">&copy; <?= date('Y') ?> · <?= h($org_name) ?></div>
  </div>
</aside>

<!-- ══ Prawa — treść ═════════════════════════════════════════════════════════ -->
<div class="login-right">
<main class="help-box" id="help-content" tabindex="-1">

  <p class="help-eyebrow">Pomoc</p>
  <h1 class="help-heading">Jak się zalogować?</h1>
  <p class="help-lead">Wybierz sytuację, która Cię dotyczy — otrzymasz dokładne instrukcje i link do odpowiedniej strony.</p>

  <!-- ── Login info ──────────────────────────────────────────────────────── -->
  <div class="login-info" role="note">
    <i class="bi bi-person-badge-fill" aria-hidden="true"></i>
    <div>
      <strong>Twój login to adres e-mail.</strong>
      <?php if ($ms_available): ?>
      Masz konto <strong>@feer.org.pl</strong>? Zaloguj się przez Microsoft 365 — jedno kliknięcie, bez wpisywania hasła.
      Nie masz konta @feer.org.pl? Użyj prywatnego e-maila podanego w umowie lub do WiadomościFEER.
      <?php else: ?>
      Jeśli masz konto <strong>@feer.org.pl</strong>, użyj go. Jeśli nie — wpisz prywatny e-mail podany w umowie lub do WiadomościFEER.
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Scenariusze ────────────────────────────────────────────────────── -->
  <p class="scenarios-label">Wybierz swoją sytuację</p>

  <!-- 1. Pierwsze logowanie / mam kod -->
  <div>
    <button type="button"
            class="scenario scenario-toggle w-100 text-start"
            id="sc1-btn"
            aria-expanded="false"
            aria-controls="sc1-detail"
            onclick="toggleScenario('sc1')">
      <span class="scenario-icon blue" aria-hidden="true"><i class="bi bi-box-arrow-in-right"></i></span>
      <span class="scenario-body">
        <span class="scenario-title">Loguję się po raz pierwszy</span>
        <span class="scenario-desc">
          Mam kod jednorazowy<?= $ms_available ? ' lub konto @feer.org.pl' : '' ?><?= $sms_available ? ' lub zaloguję się kodem SMS' : '' ?>
        </span>
      </span>
      <i class="bi bi-chevron-right scenario-arrow" aria-hidden="true"></i>
    </button>
    <div class="scenario-detail" id="sc1-detail" role="region" aria-labelledby="sc1-btn">
      <ol class="mini-steps">
        <li class="mini-step">Otwórz stronę logowania i wpisz swój <strong>adres e-mail</strong>.</li>
        <?php if ($ms_available): ?>
        <li class="mini-step">Masz konto <strong>@feer.org.pl</strong>? Kliknij „Zaloguj przez Microsoft 365" — bez hasła.</li>
        <?php endif; ?>
        <?php if ($code_available): ?>
        <li class="mini-step">Masz <strong>kod jednorazowy</strong>? Rozwiń „Więcej opcji logowania" i wybierz „Kod jednorazowy".</li>
        <?php endif; ?>
        <?php if ($sms_available): ?>
        <li class="mini-step">Wolisz zalogować się <strong>kodem SMS</strong>? Rozwiń „Więcej opcji logowania" → „Kod SMS".</li>
        <?php endif; ?>
        <li class="mini-step">Po wejściu do systemu ustaw własne, łatwe do zapamiętania <strong>hasło</strong> w Ustawieniach konta.</li>
      </ol>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn-scenario">
        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Przejdź do logowania
      </a>
    </div>
  </div>

  <!-- 2. Nie pamiętam hasła -->
  <div>
    <button type="button"
            class="scenario scenario-toggle w-100 text-start"
            id="sc2-btn"
            aria-expanded="false"
            aria-controls="sc2-detail"
            onclick="toggleScenario('sc2')">
      <span class="scenario-icon amber" aria-hidden="true"><i class="bi bi-key-fill"></i></span>
      <span class="scenario-body">
        <span class="scenario-title">Nie pamiętam hasła</span>
        <span class="scenario-desc">Zresetuję hasło kodem SMS — samodzielnie, bez kontaktu z biurem</span>
      </span>
      <i class="bi bi-chevron-right scenario-arrow" aria-hidden="true"></i>
    </button>
    <div class="scenario-detail" id="sc2-detail" role="region" aria-labelledby="sc2-btn">
      <ol class="mini-steps">
        <li class="mini-step">Kliknij przycisk poniżej lub „Zapomniałem hasła" na stronie logowania.</li>
        <li class="mini-step">Wpisz swój <strong>e-mail</strong> i podaj ostatnie 5 cyfr PESEL lub numer dokumentu tożsamości.</li>
        <li class="mini-step">Odbierz <strong>kod SMS</strong> na numer telefonu z umowy i wpisz go w formularzu.</li>
        <li class="mini-step">Ustaw <strong>nowe hasło</strong> — od razu możesz się zalogować.</li>
      </ol>
      <a href="<?= APP_URL ?>/user/verify_reset.php" class="btn-scenario">
        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Odzyskaj hasło
      </a>
    </div>
  </div>

  <!-- 3. Mam umowę, nie mam konta -->
  <div>
    <button type="button"
            class="scenario scenario-toggle w-100 text-start"
            id="sc3-btn"
            aria-expanded="false"
            aria-controls="sc3-detail"
            onclick="toggleScenario('sc3')">
      <span class="scenario-icon green" aria-hidden="true"><i class="bi bi-person-plus-fill"></i></span>
      <span class="scenario-body">
        <span class="scenario-title">Mam umowę, ale nie mam konta</span>
        <span class="scenario-desc">Podpisałem umowę wolontariacką, ale nie mogę się zalogować</span>
      </span>
      <i class="bi bi-chevron-right scenario-arrow" aria-hidden="true"></i>
    </button>
    <div class="scenario-detail" id="sc3-detail" role="region" aria-labelledby="sc3-btn">
      <ol class="mini-steps">
        <li class="mini-step">Kliknij przycisk poniżej i wpisz swój <strong>adres e-mail</strong> oraz ostatnie 5 cyfr PESEL lub numer dokumentu.</li>
        <li class="mini-step">Odbierz <strong>kod SMS</strong> na numer telefonu z umowy i potwierdź tożsamość.</li>
        <li class="mini-step">Ustaw <strong>hasło</strong> — konto jest gotowe, możesz się zalogować.</li>
      </ol>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/user/register.php" class="btn-scenario">
          <i class="bi bi-person-plus" aria-hidden="true"></i> Załóż konto
        </a>
        <a href="<?= APP_URL ?>/auth/report_login_issue.php" class="btn-scenario ghost">
          <i class="bi bi-headset" aria-hidden="true"></i> Zgłoś problem
        </a>
      </div>
    </div>
  </div>

  <!-- 4. Nie wiem / inne -->
  <a href="<?= APP_URL ?>/auth/report_login_issue.php" class="scenario" style="margin-top:.25rem"
     aria-label="Mam inny problem z logowaniem — przejdź do formularza zgłoszenia">
    <span class="scenario-icon slate" aria-hidden="true"><i class="bi bi-headset"></i></span>
    <span class="scenario-body">
      <span class="scenario-title">Mam inny problem z logowaniem</span>
      <span class="scenario-desc">Nie wiem, co jest nie tak — zgłoszę problem do administratora</span>
    </span>
    <i class="bi bi-chevron-right scenario-arrow" aria-hidden="true"></i>
  </a>

  <!-- ── Stopka ──────────────────────────────────────────────────────────── -->
  <div class="help-footer">
    <a href="<?= APP_URL ?>/auth/login.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do logowania
    </a>
    <span>
      <i class="bi bi-lock-fill" aria-hidden="true"></i> Szyfrowane połączenie HTTPS
    </span>
  </div>

</main>
</div><!-- /login-right -->

</div><!-- /login-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleScenario(id) {
  var btn    = document.getElementById(id + '-btn');
  var detail = document.getElementById(id + '-detail');
  var open   = btn.getAttribute('aria-expanded') === 'true';

  // Zamknij wszystkie
  ['sc1','sc2','sc3'].forEach(function(s) {
    var b = document.getElementById(s + '-btn');
    var d = document.getElementById(s + '-detail');
    if (b && d) {
      b.setAttribute('aria-expanded', 'false');
      b.classList.remove('active');
      d.classList.remove('open');
    }
  });

  // Otwórz wybrany (jeśli był zamknięty)
  if (!open) {
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('active');
    detail.classList.add('open');
  }
}
</script>
</body>
</html>
