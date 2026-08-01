<?php
/**
 * auth/etoz.php — Strona informacyjna: eTożsamość / System Tożsamości.
 * Standalone, dostępna bez logowania.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';

auth_start();

$_b          = branding_load();
$_tagline    = '';
try { $_tagline = org_setting('login_tagline') ?: ''; } catch (\Throwable $e) {}
$org_name    = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');
$logged_in   = (bool) current_user();
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>eTożsamość — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

.skip-link{position:absolute;top:-100%;left:1rem;z-index:9999;background:var(--c,#2563eb);color:#fff;padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none;font-size:.95rem}
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
.login-left::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;border:55px solid rgba(255,255,255,.025);bottom:-80px;right:-80px;pointer-events:none}
.left-logo{max-height:44px;max-width:140px;object-fit:contain;filter:brightness(0)invert(1);opacity:.85;display:block;margin-bottom:1rem}
.left-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;margin-bottom:1rem}
.left-org{font-size:1.05rem;font-weight:800;color:#fff;margin:0 0 .25rem;line-height:1.3}
.left-tagline{font-size:.77rem;color:rgba(255,255,255,.45);margin:0 0 1.75rem;line-height:1.5}
.left-about{font-size:.8rem;color:rgba(255,255,255,.45);line-height:1.65;margin:0}
.left-footer{position:relative;z-index:1}
.left-security{display:flex;align-items:center;gap:.4rem;font-size:.73rem;color:rgba(255,255,255,.3);margin-bottom:.4rem}
.left-copyright{font-size:.68rem;color:rgba(255,255,255,.2)}

/* ── Prawa ───────────────────────────────────────────────────── */
.login-right{flex:1;background:#F1F5F9;display:flex;align-items:flex-start;justify-content:center;padding:2.5rem 1.5rem;overflow-y:auto}
.info-box{width:100%;max-width:620px}

/* ── Nagłówek ────────────────────────────────────────────────── */
.info-eyebrow{font-size:.75rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--c,#2563eb);margin:0 0 .35rem}
.info-heading{font-size:1.9rem;font-weight:900;color:#0f172a;margin:0 0 .5rem;letter-spacing:-.025em;line-height:1.15;text-wrap:balance}
.info-lead{font-size:.95rem;color:#475569;line-height:1.65;margin:0 0 2rem}

/* ── Badge ───────────────────────────────────────────────────── */
.badge-new{display:inline-block;font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#dcfce7;color:#15803d;padding:.2rem .55rem;border-radius:5px;margin-bottom:.75rem}

/* ── Systemy ─────────────────────────────────────────────────── */
.systems{display:flex;gap:.65rem;flex-wrap:wrap;margin-bottom:2rem}
.sys-chip{
  display:inline-flex;align-items:center;gap:.45rem;
  background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;
  padding:.55rem .85rem;font-size:.85rem;font-weight:600;color:#334155;
}
.sys-chip i{font-size:1rem}
.sys-chip.ms{border-color:#c7d9f5;background:#eef4ff;color:#1d4ed8}
.sys-chip.panel{border-color:#d1fae5;background:#f0fdf4;color:#15803d}

/* ── Sekcja ──────────────────────────────────────────────────── */
.info-section{margin-bottom:1.75rem}
.info-section-title{
  display:flex;align-items:center;gap:.5rem;
  font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 .9rem;
}
.info-section-title i{color:var(--c,#2563eb);font-size:1.1rem}

/* ── Karty ───────────────────────────────────────────────────── */
.info-card{
  display:flex;align-items:flex-start;gap:.8rem;
  background:#fff;border:1px solid #e2e8f0;border-radius:12px;
  padding:.95rem 1.1rem;margin-bottom:.6rem;
}
.info-card-icon{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);
}
.info-card-icon.green{background:#f0fdf4;color:#16a34a}
.info-card-icon.purple{background:#f5f3ff;color:#7c3aed}
.info-card-icon.amber{background:#fffbeb;color:#d97706}
.info-card-title{font-size:.9rem;font-weight:700;color:#0f172a;line-height:1.35;margin:0 0 .15rem}
.info-card-desc{font-size:.82rem;color:#64748b;line-height:1.55;margin:0}
.info-card-desc strong{color:#334155}

/* ── Highlight box ───────────────────────────────────────────── */
.highlight{
  background:#fff;border:1.5px solid var(--c,#2563eb);border-radius:14px;
  padding:1.25rem 1.3rem;margin-bottom:1.75rem;
  display:flex;align-items:flex-start;gap:1rem;
}
.highlight-num{
  font-size:2.5rem;font-weight:900;color:var(--c,#2563eb);line-height:1;
  flex-shrink:0;letter-spacing:-.03em;
}
.highlight-body h3{font-size:.95rem;font-weight:700;color:#0f172a;margin:0 0 .2rem}
.highlight-body p{font-size:.85rem;color:#64748b;line-height:1.55;margin:0}

/* ── Akcje ───────────────────────────────────────────────────── */
.info-actions{display:flex;flex-wrap:wrap;gap:.65rem;padding-top:1.5rem;border-top:1px solid #e2e8f0;margin-top:1.75rem}
.btn-info-primary{
  display:inline-flex;align-items:center;gap:.5rem;
  background:var(--c,#2563eb);color:#fff;border:2px solid var(--c,#2563eb);
  border-radius:9px;padding:.7rem 1.25rem;font-size:.9rem;font-weight:700;
  text-decoration:none;min-height:46px;
  transition:background .15s,border-color .15s;
}
.btn-info-primary:hover{background:var(--c-dark,#1d4ed8);border-color:var(--c-dark,#1d4ed8);color:#fff}
.btn-info-ghost{
  display:inline-flex;align-items:center;gap:.5rem;
  background:#fff;color:#334155;border:2px solid #e2e8f0;
  border-radius:9px;padding:.7rem 1.25rem;font-size:.9rem;font-weight:700;
  text-decoration:none;min-height:46px;
  transition:border-color .15s,color .15s;
}
.btn-info-ghost:hover{border-color:var(--c,#2563eb);color:var(--c,#2563eb)}

/* ── Footer ──────────────────────────────────────────────────── */
.info-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding-top:1rem;margin-top:.5rem;font-size:.78rem;color:#94a3b8}
.info-footer a{color:#64748b;text-decoration:none}
.info-footer a:hover{color:var(--c,#2563eb)}

@media(prefers-contrast:high){.btn-info-primary,.btn-info-ghost{border-width:3px}.info-card{border-width:2px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}

@media(max-width:680px){
  .login-shell{flex-direction:column}
  .login-left{width:100%;padding:.9rem 1.25rem;flex-direction:row;align-items:center;gap:.75rem;border-right:none;border-bottom:1px solid rgba(255,255,255,.07)}
  .login-left::after{display:none}
  .login-left .left-tagline,.login-left .left-about,.login-left .left-footer{display:none}
  .login-left .left-org{font-size:.9rem;margin:0}
  .login-left .left-logo{margin-bottom:0;max-height:28px}
  .login-left .left-icon{width:30px;height:30px;font-size:1rem;margin-bottom:0}
  .login-right{padding:1.25rem 1rem}
  .info-heading{font-size:1.5rem}
  .highlight{flex-direction:column;gap:.5rem}
  .highlight-num{font-size:2rem}
  .info-actions .btn-info-primary,.info-actions .btn-info-ghost{flex:1;justify-content:center}
}
</style>
</head>
<body>

<a href="#info-content" class="skip-link">Przejdź do treści</a>

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
    <?php if ($_tagline): ?><p class="left-tagline"><?= h($_tagline) ?></p><?php endif; ?>
    <p class="left-about">Wdrożyliśmy jednolity system tożsamości cyfrowej — bez osobnego loginu, 1 hasło do wszystkich systemów organizacji.</p>
  </div>
  <div class="left-footer">
    <div class="left-security"><i class="bi bi-lock-fill" aria-hidden="true"></i> Połączenie szyfrowane HTTPS</div>
    <div class="left-copyright">&copy; <?= date('Y') ?> · <?= h($org_name) ?></div>
  </div>
</aside>

<!-- ══ Prawa — treść ═════════════════════════════════════════════════════════ -->
<div class="login-right">
<main class="info-box" id="info-content" tabindex="-1">

  <span class="badge-new">Wdrożono</span>
  <p class="info-eyebrow">System Tożsamości</p>
  <h1 class="info-heading">eTożsamość —<br>bez loginu, 1&nbsp;hasło</h1>
  <p class="info-lead">
    Zunifikowaliśmy dostęp do wszystkich systemów cyfrowych organizacji.
    Bez osobnego loginu do każdego systemu — 1 hasło działa teraz wszędzie.
  </p>

  <!-- ── Objęte systemy ─────────────────────────────────────────────────── -->
  <div class="systems" aria-label="Systemy objęte eTożsamością">
    <span class="sys-chip panel"><i class="bi bi-grid-fill" aria-hidden="true"></i> Panel SZO</span>
    <span class="sys-chip ms"><i class="bi bi-microsoft" aria-hidden="true"></i> Microsoft 365</span>
    <span class="sys-chip ms"><i class="bi bi-envelope-at-fill" aria-hidden="true"></i> Outlook</span>
    <span class="sys-chip ms"><i class="bi bi-camera-video-fill" aria-hidden="true"></i> Teams</span>
    <span class="sys-chip ms"><i class="bi bi-files" aria-hidden="true"></i> SharePoint</span>
  </div>

  <!-- ── Zasada bez loginu 1 hasło ─────────────────────────────────────── -->
  <div class="highlight" role="note">
    <div class="highlight-num" aria-hidden="true">1</div>
    <div class="highlight-body">
      <h3>Bez osobnego loginu — 1 hasło do wszystkiego</h3>
      <p>
        Twój <strong>adres e-mail</strong> (służbowy <code>@feer.org.pl</code> lub prywatny z umowy) to Twój identyfikator.
        Hasło ustawione w eTożsamości obowiązuje we wszystkich systemach jednocześnie —
        zmiana w jednym miejscu aktualizuje je wszędzie.
      </p>
    </div>
  </div>

  <!-- ── Co się zmieniło ───────────────────────────────────────────────── -->
  <section class="info-section" aria-labelledby="h-zmiana">
    <h2 class="info-section-title" id="h-zmiana"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Co się zmieniło</h2>

    <div class="info-card">
      <span class="info-card-icon green" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
      <div>
        <p class="info-card-title">Jedno hasło do Panelu i Microsoft 365</p>
        <p class="info-card-desc">Wcześniej hasło panelowe i hasło Microsoft 365 były niezależne. Teraz zmiana hasła w eTożsamości aktualizuje <strong>oba systemy</strong> jednocześnie.</p>
      </div>
    </div>

    <div class="info-card">
      <span class="info-card-icon" aria-hidden="true"><i class="bi bi-phone-fill"></i></span>
      <div>
        <p class="info-card-title">Jeden numer telefonu do weryfikacji SMS</p>
        <p class="info-card-desc">Numer telefonu podany w eTożsamości służy do <strong>odzyskiwania dostępu</strong> i logowania dwuetapowego w całej organizacji.</p>
      </div>
    </div>

    <div class="info-card">
      <span class="info-card-icon purple" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></span>
      <div>
        <p class="info-card-title">Uwierzytelnianie dwuetapowe (MFA)</p>
        <p class="info-card-desc">Możesz włączyć <strong>logowanie dwuetapowe</strong> dla wyższego bezpieczeństwa — ustawienie dotyczy wszystkich systemów organizacji.</p>
      </div>
    </div>

    <div class="info-card">
      <span class="info-card-icon amber" aria-hidden="true"><i class="bi bi-key-fill"></i></span>
      <div>
        <p class="info-card-title">Samodzielne odzyskiwanie dostępu</p>
        <p class="info-card-desc">Zapomniane hasło resetujesz <strong>samodzielnie kodem SMS</strong> — bez kontaktu z administratorem. Nowe hasło działa od razu wszędzie.</p>
      </div>
    </div>
  </section>

  <!-- ── Co możesz teraz zrobić ────────────────────────────────────────── -->
  <section class="info-section" aria-labelledby="h-mozesz">
    <h2 class="info-section-title" id="h-mozesz"><i class="bi bi-person-vcard-fill" aria-hidden="true"></i> Co możesz teraz zrobić</h2>

    <div class="info-card">
      <span class="info-card-icon" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
      <div>
        <p class="info-card-title">Zmień hasło raz — działa wszędzie</p>
        <p class="info-card-desc">Wejdź w <strong>System Tożsamości → Zmień hasło</strong>. Nowe hasło obowiązuje w Panelu SZO i Microsoft 365 natychmiast po zmianie.</p>
      </div>
    </div>

    <div class="info-card">
      <span class="info-card-icon green" aria-hidden="true"><i class="bi bi-person-check-fill"></i></span>
      <div>
        <p class="info-card-title">Sprawdź swoje dane w eTożsamości</p>
        <p class="info-card-desc">W <strong>Systemie Tożsamości</strong> zobaczysz swój login, powiązane konto Microsoft 365, numer telefonu i status MFA.</p>
      </div>
    </div>

    <div class="info-card">
      <span class="info-card-icon amber" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
      <div>
        <p class="info-card-title">Odzyskaj dostęp bez pomocy administratora</p>
        <p class="info-card-desc">Nie pamiętasz hasła? Zresetuj je przez <strong>„Odzyskaj dostęp"</strong> — wystarczy e-mail i kod SMS. Dostęp odzyskasz w minutę.</p>
      </div>
    </div>
  </section>

  <!-- ── Akcje ──────────────────────────────────────────────────────────── -->
  <div class="info-actions">
    <?php if ($logged_in): ?>
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="btn-info-primary">
      <i class="bi bi-person-vcard-fill" aria-hidden="true"></i> Otwórz System Tożsamości
    </a>
    <a href="<?= APP_URL ?>/portal.php" class="btn-info-ghost">
      <i class="bi bi-grid" aria-hidden="true"></i> Wróć do panelu
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/login.php" class="btn-info-primary">
      <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Zaloguj się
    </a>
    <a href="<?= APP_URL ?>/user/verify_reset.php" class="btn-info-ghost">
      <i class="bi bi-key-fill" aria-hidden="true"></i> Odzyskaj dostęp
    </a>
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="btn-info-ghost">
      <i class="bi bi-person-vcard" aria-hidden="true"></i> System Tożsamości
    </a>
    <?php endif; ?>
  </div>

  <div class="info-footer">
    <a href="<?= APP_URL ?>/auth/login.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do logowania
    </a>
    <a href="<?= APP_URL ?>/auth/help.php">
      <i class="bi bi-question-circle" aria-hidden="true"></i> Jak się zalogować?
    </a>
  </div>

</main>
</div>

</div><!-- /login-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
