<?php
/**
 * auth/help.php — Krótkie wprowadzenie: jak się zalogować i jak odzyskać
 * login (e-mail) oraz hasło.
 *
 * Strona STANDALONE — nie includuje header.php. Dostępna bez logowania.
 * Styl spójny z auth/login.php (dwukolumnowy shell, branding, focus-ringi).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';

auth_start();

// Zalogowany użytkownik nie potrzebuje instrukcji logowania.
if (current_user()) { header('Location: ' . APP_URL . '/portal.php'); exit; }

// ── Które metody są włączone (tak samo jak w login.php) ───────────────────
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

// ── Branding ──────────────────────────────────────────────────────────────
$_b             = branding_load();
$_login_tagline = '';
try { $_login_tagline = org_setting('login_tagline') ?: ''; } catch (\Throwable $e) {}
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jak się zalogować — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

/* ── Skip link ───────────────────────────────────────────── */
.skip-link{
  position:absolute;top:-100%;left:1rem;z-index:9999;
  background:var(--c,#2563eb);color:#fff;
  padding:.5rem 1.25rem;border-radius:0 0 8px 8px;
  font-weight:700;text-decoration:none;font-size:.95rem;
}
.skip-link:focus{top:0;outline:3px solid #FBBF24;outline-offset:2px}

/* ── Global focus ────────────────────────────────────────── */
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:3px!important}
*:focus:not(:focus-visible){outline:none}

/* ── Shell (jak login.php) ───────────────────────────────── */
.login-shell{min-height:100vh;display:flex;align-items:stretch}

/* ── Lewa (dark) ─────────────────────────────────────────── */
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

/* ── Prawa (light) ───────────────────────────────────────── */
.login-right{
  flex:1;background:#F1F5F9;
  display:flex;align-items:center;justify-content:center;
  padding:2.5rem 1.5rem;overflow-y:auto;
}
.help-box{
  width:100%;max-width:600px;
  background:#fff;border-radius:16px;
  box-shadow:0 8px 40px rgba(0,0,0,.13),0 2px 8px rgba(0,0,0,.06);
  padding:2rem 2.25rem;
}

/* Mobile org name */
.mobile-top{
  display:none;align-items:center;gap:.6rem;
  padding-bottom:1.25rem;margin-bottom:1.5rem;
  border-bottom:1px solid #e2e8f0;
}
.mobile-top-logo{max-height:26px;object-fit:contain;filter:none}
.mobile-top-org{font-size:.9rem;font-weight:700;color:#0f172a}

/* ── Nagłówek ────────────────────────────────────────────── */
.help-eyebrow{font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c,#2563eb);margin:0 0 .35rem}
.help-heading{font-size:1.55rem;font-weight:800;color:#0f172a;margin:0 0 .55rem;letter-spacing:-.01em}
.help-lead{font-size:.92rem;color:#475569;line-height:1.6;margin:0 0 1.6rem}

/* ── Sekcja ──────────────────────────────────────────────── */
.help-section{margin-bottom:1.7rem}
.help-section-title{
  display:flex;align-items:center;gap:.5rem;
  font-size:1.05rem;font-weight:700;color:#0f172a;margin:0 0 .8rem;
}
.help-section-title i{color:var(--c,#2563eb)}

/* ── Kroki logowania ─────────────────────────────────────── */
.help-steps{list-style:none;margin:0;padding:0;counter-reset:step}
.help-step{
  position:relative;padding:0 0 1rem 2.6rem;counter-increment:step;
}
.help-step:last-child{padding-bottom:0}
.help-step::before{
  content:counter(step);position:absolute;left:0;top:0;
  width:1.85rem;height:1.85rem;border-radius:50%;
  background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.9rem;
}
.help-step::after{
  content:'';position:absolute;left:.9rem;top:1.95rem;bottom:.15rem;
  width:2px;background:#e2e8f0;
}
.help-step:last-child::after{display:none}
.help-step-title{font-size:.92rem;font-weight:700;color:#0f172a;line-height:1.4}
.help-step-desc{font-size:.85rem;color:#64748b;line-height:1.6;margin-top:.2rem}
.help-step-desc strong{color:#334155}

/* ── Karty: login / hasło ────────────────────────────────── */
.help-card{
  display:flex;align-items:flex-start;gap:.8rem;
  border:1px solid #e2e8f0;border-radius:12px;
  padding:1rem 1.1rem;margin-bottom:.7rem;background:#fff;
}
.help-card-icon{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1.05rem;background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);
}
.help-card-icon.alt{background:#f1f5f9;color:#475569}
.help-card-title{font-size:.9rem;font-weight:700;color:#0f172a;line-height:1.35}
.help-card-desc{font-size:.83rem;color:#64748b;line-height:1.55;margin-top:.15rem}
.help-card-desc strong{color:#334155;font-weight:700}

/* ── Wskazówka / box informacyjny ────────────────────────── */
.help-note{
  display:flex;align-items:flex-start;gap:.6rem;
  padding:.85rem 1rem;border-radius:8px;background:#eff6ff;
  border-left:3px solid #2563eb;margin:0 0 1.7rem;
  font-size:.85rem;color:#1e293b;line-height:1.55;
}
.help-note i{flex-shrink:0;color:#2563eb;margin-top:.15rem}
.help-note.warm{background:#fffbeb;border-left-color:#f59e0b}
.help-note.warm i{color:#d97706}

/* ── Przyciski akcji ─────────────────────────────────────── */
.help-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.9rem;padding-top:1.5rem;border-top:1px solid #e2e8f0}
.btn-help-primary{
  display:inline-flex;align-items:center;justify-content:center;gap:.5rem;
  background:var(--c,#2563eb);color:var(--c-text,#fff);
  border:2px solid var(--c,#2563eb);border-radius:8px;
  padding:.7rem 1.3rem;font-size:.95rem;font-weight:700;
  text-decoration:none;min-height:48px;
  transition:background .15s,border-color .15s;
}
.btn-help-primary:hover{background:var(--c-dark,#1d4ed8);border-color:var(--c-dark,#1d4ed8);color:var(--c-text,#fff)}
.btn-help-ghost{
  display:inline-flex;align-items:center;justify-content:center;gap:.5rem;
  background:#fff;color:#334155;
  border:2px solid #e2e8f0;border-radius:8px;
  padding:.7rem 1.3rem;font-size:.95rem;font-weight:700;
  text-decoration:none;min-height:48px;
  transition:border-color .15s,color .15s;
}
.btn-help-ghost:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb)}

/* ── High contrast ───────────────────────────────────────── */
@media(prefers-contrast:high){
  .btn-help-primary{background:#000!important;border-color:#000!important;color:#fff!important;border-width:3px}
  .btn-help-ghost{border-width:3px;border-color:#000}
  .help-card{border-width:2px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important}}

/* ── Mobile ──────────────────────────────────────────────── */
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
  .login-right{padding:1.25rem 1rem;align-items:flex-start;background:#F1F5F9}
  .help-box{box-shadow:none;border-radius:12px;padding:1.5rem 1.25rem}
  .mobile-top{display:flex}
  .login-left .left-logo{margin-bottom:0;max-height:28px}
  .login-left .left-icon{width:30px;height:30px;font-size:1rem;margin-bottom:0}
  .help-actions .btn-help-primary,.help-actions .btn-help-ghost{flex:1}
}
</style>
</head>
<body>

<a href="#help-content" class="skip-link">Przejdź do treści instrukcji</a>

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
    <p class="left-about-text">Ta strona pomoże Ci zalogować się po raz pierwszy oraz ustalić, jaki masz login i hasło.</p>
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

  <div class="mobile-top" aria-hidden="true">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="" class="mobile-top-logo">
    <?php endif; ?>
    <span class="mobile-top-org"><?= h($org_name) ?></span>
  </div>

  <p class="help-eyebrow">Pierwsze logowanie</p>
  <h1 class="help-heading">Jak się zalogować?</h1>
  <p class="help-lead">
    Nie wiesz, jak wejść do systemu? To prostsze, niż się wydaje. Poniżej znajdziesz
    krok po kroku, co zrobić oraz jak ustalić swój <strong>login</strong> (adres e-mail)
    i <strong>hasło</strong>.
  </p>

  <!-- ── Kroki ──────────────────────────────────────────────────────────── -->
  <section class="help-section" aria-labelledby="h-steps">
    <h2 class="help-section-title" id="h-steps"><i class="bi bi-signpost-2-fill" aria-hidden="true"></i> Krok po kroku</h2>
    <ol class="help-steps">
      <li class="help-step">
        <div class="help-step-title">Otwórz stronę logowania</div>
        <div class="help-step-desc">Wejdź na ekran logowania systemu — to ta sama strona, z której tu trafiłeś.</div>
      </li>
      <li class="help-step">
        <div class="help-step-title">Wybierz, kim jesteś</div>
        <div class="help-step-desc">
          Masz konto <strong>@feer.org.pl</strong>
          <?= $ms_available ? '— zaloguj się przez Microsoft 365 lub e-mailem służbowym i hasłem.' : '— zaloguj się e-mailem służbowym i hasłem.' ?>
          Nie masz takiego konta — użyj swojego <strong>prywatnego e-maila</strong>
          (tego podanego do WiadomościFEER) i hasła.
        </div>
      </li>
      <li class="help-step">
        <div class="help-step-title">Wpisz login i hasło</div>
        <div class="help-step-desc">Loginem jest Twój <strong>adres e-mail</strong>. Jeśli nie znasz hasła — zajrzyj niżej, do sekcji „Jak ustalić hasło”.</div>
      </li>
      <li class="help-step">
        <div class="help-step-title">Gotowe — kliknij „Zaloguj się”</div>
        <div class="help-step-desc">Po pierwszym logowaniu warto od razu ustawić własne, łatwe do zapamiętania hasło.</div>
      </li>
    </ol>
  </section>

  <!-- ── Jak ustalić login ──────────────────────────────────────────────── -->
  <section class="help-section" aria-labelledby="h-login">
    <h2 class="help-section-title" id="h-login"><i class="bi bi-person-badge" aria-hidden="true"></i> Jak ustalić login (e-mail)</h2>
    <p class="help-lead" style="margin-bottom:1rem">Twój login to po prostu adres e-mail. Wybierz ten, który Cię dotyczy:</p>

    <div class="help-card">
      <span class="help-card-icon" aria-hidden="true"><i class="bi bi-envelope-at-fill"></i></span>
      <div>
        <div class="help-card-title">Masz konto służbowe @feer.org.pl</div>
        <div class="help-card-desc">Loginem jest Twój adres <strong>imie.nazwisko@feer.org.pl</strong>.<?= $ms_available ? ' Możesz też kliknąć „Zaloguj przez Microsoft 365” — bez wpisywania hasła.' : '' ?></div>
      </div>
    </div>

    <div class="help-card">
      <span class="help-card-icon alt" aria-hidden="true"><i class="bi bi-envelope-heart"></i></span>
      <div>
        <div class="help-card-title">Nie masz konta @feer.org.pl</div>
        <div class="help-card-desc">Loginem jest Twój <strong>prywatny adres e-mail</strong> — ten sam, który podałeś, zapisując się do <strong>WiadomościFEER</strong> lub wpisany w Twojej umowie.</div>
      </div>
    </div>
  </section>

  <!-- ── Jak ustalić hasło ──────────────────────────────────────────────── -->
  <section class="help-section" aria-labelledby="h-pass">
    <h2 class="help-section-title" id="h-pass"><i class="bi bi-key-fill" aria-hidden="true"></i> Jak ustalić hasło</h2>

    <?php if ($code_available): ?>
    <div class="help-card">
      <span class="help-card-icon" aria-hidden="true"><i class="bi bi-1-circle-fill"></i></span>
      <div>
        <div class="help-card-title">Logujesz się pierwszy raz</div>
        <div class="help-card-desc">Użyj <strong>kodu jednorazowego</strong> — otrzymujesz go e-mailem lub od administratora. Na ekranie logowania rozwiń <strong>„Więcej opcji logowania”</strong> i wybierz „Kod jednorazowy”. Po wejściu ustaw własne hasło.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="help-card">
      <span class="help-card-icon" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
      <div>
        <div class="help-card-title">Nie pamiętasz hasła</div>
        <div class="help-card-desc">Kliknij <strong>„Zapomniałem hasła”</strong> przy polu hasła. Potwierdzisz tożsamość (e-mail, numer umowy oraz PESEL lub numer dokumentu), odbierzesz kod SMS i ustawisz nowe hasło — wszystko samodzielnie.</div>
      </div>
    </div>

    <?php if ($sms_available): ?>
    <div class="help-card">
      <span class="help-card-icon alt" aria-hidden="true"><i class="bi bi-phone-fill"></i></span>
      <div>
        <div class="help-card-title">Wolisz bez hasła — kod SMS</div>
        <div class="help-card-desc">Jeśli podałeś numer telefonu (np. w umowie wolontariackiej), możesz zalogować się <strong>kodem SMS</strong>. Rozwiń „Więcej opcji logowania” → „Kod SMS”.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="help-card">
      <span class="help-card-icon alt" aria-hidden="true"><i class="bi bi-person-gear"></i></span>
      <div>
        <div class="help-card-title">Hasło nadane przez administratora</div>
        <div class="help-card-desc">Jeśli administrator założył Ci konto i przekazał hasło — zaloguj się nim. System poprosi o ustawienie własnego hasła przy pierwszym wejściu.</div>
      </div>
    </div>
  </section>

  <div class="help-note warm" role="note">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <span>Nadal nie możesz się zalogować? Skontaktuj się z administratorem systemu w swojej organizacji — pomoże ustalić login i zresetować dostęp.</span>
  </div>

  <!-- ── Akcje ──────────────────────────────────────────────────────────── -->
  <div class="help-actions">
    <a href="<?= APP_URL ?>/auth/login.php" class="btn-help-primary">
      <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Przejdź do logowania
    </a>
    <a href="<?= APP_URL ?>/user/verify_reset.php" class="btn-help-ghost">
      <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Odzyskaj hasło
    </a>
  </div>

</main>
</div><!-- /login-right -->

</div><!-- /login-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
