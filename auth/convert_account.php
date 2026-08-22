<?php
/**
 * auth/convert_account.php — Hasło awaryjne na podstawie konta Microsoft.
 *
 * Rozwiązanie na problemy z logowaniem przez Office: użytkownik loguje się
 * Microsoftem, a tutaj generuje sobie LOKALNE hasło (login = e-mail). Hasło jest
 * pokazywane raz. Dla kont @feer.org.pl ustawienie hasła odblokowuje logowanie
 * lokalne tylko dla tego konta (flaga allow_local_fallback) — reszta polityki
 * „tylko Office" pozostaje bez zmian. Przy pierwszym logowaniu lokalnym system
 * wymusi zmianę hasła (must_change_password).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

auth_start();

$self_url = APP_URL . '/auth/convert_account.php';

// Operujemy na PRAWDZIWIE zalogowanym koncie (pomijamy ewentualny kontekst admina).
$me = function_exists('ctx_real_user') ? (ctx_real_user() ?? current_user()) : current_user();

// Niezalogowany → NIE przekierowujemy od razu na logowanie. Pokazujemy najpierw
// stronę WYJAŚNIAJĄCĄ, czym jest hasło awaryjne, z przyciskiem startu logowania
// Office. Adres startu logowania (Office, fallback klasyczny ekran):
$not_logged = !$me;
$start_login_url = (function_exists('ms_login_available') && ms_login_available())
    ? APP_URL . '/auth/ms365.php?redirect=' . urlencode($self_url)
    : APP_URL . '/auth/login.php?redirect=' . urlencode($self_url);

// Zalogowany, ale konto nie jest powiązane z Microsoft 365. NIE przekierowujemy
// do ms365.php (zalogowany użytkownik wróciłby od razu tutaj → pętla). Pokażemy
// komunikat informacyjny zamiast formularza.
$no_ms = !$not_logged && empty($me['microsoft_id']);

/** Generuje czytelne, silne hasło tymczasowe (bez znaków łatwych do pomylenia). */
function _conv_gen_password(int $len = 12): string {
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

$generated = null;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me && !$no_ms) {
    csrf_check();
    $pw   = _conv_gen_password();
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $is_office = account_is_office_only($me);

    // Upewnij się, że kolumny istnieją (nie zakładamy, że _auth_security_init()
    // zdążyło je dołożyć — ta strona nie używa require_login()).
    try { db()->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE users ADD COLUMN allow_local_fallback INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    try {
        db()->prepare(
            "UPDATE users SET password=?, must_change_password=1, allow_local_fallback=? WHERE id=?"
        )->execute([$hash, $is_office ? 1 : 0, (int)$me['id']]);
        $generated = $pw;
        authlog_write((int)$me['id'], 'local_fallback_created', $me['email'] ?? '',
            'Wygenerowano hasło awaryjne (logowanie lokalne)' . ($is_office ? ' + odblokowano logowanie lokalne dla konta Office' : ''));
    } catch (\Throwable $e) {
        $err = 'Nie udało się zapisać hasła. Spróbuj ponownie.';
    }
}

require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/auth_screen.php';
$_b       = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Organizacja');

$login_url     = APP_URL . '/auth/login.php';
$emergency_url = APP_URL . '/auth/awaryjne.php';
$tok           = csrf_token();
$has_pw        = false;
if (!$not_logged && !$no_ms && !empty($me['microsoft_id'])) {
    try { $has_pw = (bool)(db_one("SELECT password FROM users WHERE id=?", [(int)$me['id']])['password'] ?? ''); } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hasło awaryjne — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<?php auth_screen_bg_css(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { min-height: 100%; margin: 0; }
.page-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 2rem 1rem;
}
.login-card {
  width: 100%; max-width: 480px;
  background: #fff; border-radius: 16px;
  box-shadow: 0 4px 32px rgba(0,0,0,.1);
  overflow: hidden;
}
.card-top {
  background: linear-gradient(155deg, var(--c-darker) 0%, var(--c) 55%, var(--c-light) 100%);
  padding: 1.75rem 2rem 1.5rem; text-align: center;
}
.card-top-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto .75rem; font-size: 1.75rem; color: #fff;
}
.card-top h1 { color: #fff; font-size: 1.2rem; font-weight: 700; margin: 0; }
.card-top p  { color: rgba(255,255,255,.85); font-size: .85rem; margin: .4rem 0 0; }
.card-body-inner { padding: 2rem; }
.btn-primary { background: var(--c); border-color: var(--c); }
.btn-primary:hover { background: var(--c-darker); border-color: var(--c-darker); }
.cred-box { background: #f8fafc; border: 1px dashed #94a3b8; border-radius: 10px; padding: .9rem 1rem; }
.cred-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .3rem 0; }
.cred-row + .cred-row { border-top: 1px solid #e2e8f0; }
.cred-k { color: #64748b; font-size: .78rem; }
.cred-v { font-weight: 700; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .92rem; text-align: right; }
.copy-btn { border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; padding: .15rem .45rem; font-size: .75rem; }
</style>
</head>
<body>
<div class="page-wrap">
  <div class="login-card">

    <!-- Nagłówek -->
    <div class="card-top">
      <div class="card-top-icon"><i class="bi bi-shield-lock-fill"></i></div>
      <h1>Hasło awaryjne do logowania lokalnego</h1>
      <?php if ($not_logged): ?>
      <p>Zapasowy sposób wejścia, gdy logowanie przez Office nie działa</p>
      <?php else: ?>
      <p>Zalogowano jako <strong><?= h($me['email']) ?></strong></p>
      <?php endif; ?>
    </div>

    <div class="card-body-inner">

      <?php if ($err): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div><?= h($err) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($not_logged): ?>
      <!-- ── Strona wyjaśniająca (użytkownik niezalogowany) ─────────────────── -->
      <h6 class="fw-semibold mb-2">Co to jest hasło awaryjne?</h6>
      <p class="text-muted" style="font-size:.88rem;line-height:1.55">
        Zwykle logujesz się przyciskiem <strong>„Zaloguj przez Microsoft 365"</strong> (Office).
        Czasem jednak logowanie Microsoft może nie działać — np. awaria po stronie Microsoftu,
        problem z kontem służbowym albo brak dostępu do telefonu z aplikacją uwierzytelniającą.
      </p>
      <p class="text-muted" style="font-size:.88rem;line-height:1.55">
        <strong>Hasło awaryjne</strong> to zapasowy sposób wejścia do systemu: ustawiasz dodatkowe
        hasło lokalne, którym zalogujesz się <em>loginem (e-mail) i hasłem</em>, gdy Office zawiedzie.
      </p>
      <div class="alert alert-warning d-flex gap-2 align-items-start mb-3" style="font-size:.85rem">
        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
        <div>
          Aby utworzyć hasło awaryjne, musisz <strong>najpierw raz zalogować się przez Microsoft 365</strong>
          (póki działa) — w ten sposób potwierdzasz swoją tożsamość. Zrób to <strong>zawczasu</strong>,
          zanim pojawią się problemy.
        </div>
      </div>
      <div class="d-grid mb-3">
        <a href="<?= h($start_login_url) ?>" class="btn btn-primary fw-semibold">
          <i class="bi bi-microsoft me-1"></i>Zaloguj przez Microsoft 365 i utwórz hasło
        </a>
      </div>
      <div class="text-center">
        <a href="<?= h($login_url) ?>" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Wróć do logowania
        </a>
      </div>

      <?php elseif ($no_ms): ?>
      <!-- ── Konto bez powiązania z Microsoft 365 ───────────────────────────── -->
      <h6 class="fw-semibold mb-2">To konto nie wymaga hasła awaryjnego</h6>
      <p class="text-muted" style="font-size:.88rem;line-height:1.55">
        Funkcja jest przeznaczona dla kont logujących się przez Microsoft 365 (Office) — tworzy dla nich
        zapasowe hasło lokalne. Twoje konto <strong><?= h($me['email']) ?></strong> nie jest powiązane
        z Microsoft 365 i loguje się standardowo loginem i hasłem.
      </p>
      <p class="text-muted mb-3" style="font-size:.88rem">
        Jeśli zapomniałeś hasła, użyj opcji odzyskiwania na stronie logowania.
      </p>
      <div class="text-center">
        <a href="<?= h(APP_URL) ?>/portal.php" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Wróć do systemu
        </a>
      </div>

      <?php elseif ($generated !== null): ?>
      <!-- ── Wynik: hasło pokazane RAZ ───────────────────────────────────────── -->
      <div class="text-center mb-3">
        <i class="bi bi-check-circle-fill text-success" style="font-size:2.2rem"></i>
        <h6 class="fw-bold mt-2 mb-0">Hasło zostało utworzone</h6>
      </div>
      <p class="text-muted text-center mb-3" style="font-size:.85rem">
        Zapisz te dane w bezpiecznym miejscu — <strong>hasło pokażemy tylko teraz</strong>.
      </p>
      <div class="cred-box mb-3">
        <div class="cred-row">
          <span class="cred-k">Adres logowania awaryjnego</span>
          <a class="cred-v text-decoration-none" style="font-size:.78rem" href="<?= h($emergency_url) ?>"><?= h($emergency_url) ?></a>
        </div>
        <div class="cred-row">
          <span class="cred-k">Login (e-mail)</span>
          <span class="cred-v" id="c-login"><?= h($me['email']) ?></span>
        </div>
        <div class="cred-row">
          <span class="cred-k">Hasło tymczasowe</span>
          <span class="d-flex align-items-center gap-2">
            <span class="cred-v" id="c-pass"><?= h($generated) ?></span>
            <button type="button" class="copy-btn" onclick="convCopy('<?= h($generated) ?>', this)">
              <i class="bi bi-clipboard"></i>
            </button>
          </span>
        </div>
      </div>
      <div class="alert alert-warning d-flex gap-2 align-items-start mb-3" style="font-size:.83rem">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
          Przy pierwszym logowaniu lokalnym system poprosi o ustawienie własnego hasła.
          <?php if (account_is_office_only($me)): ?>
          Logowanie lokalne zostało odblokowane wyłącznie dla Twojego konta — pozostali użytkownicy
          @feer.org.pl nadal logują się tylko przez Office.
          <?php endif; ?>
        </div>
      </div>
      <div class="text-center">
        <a href="<?= h(APP_URL) ?>/portal.php" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Wróć do systemu
        </a>
      </div>

      <?php else: ?>
      <!-- ── Formularz potwierdzenia ─────────────────────────────────────────── -->
      <h6 class="fw-semibold mb-2">Utwórz hasło awaryjne</h6>
      <p class="text-muted" style="font-size:.88rem;line-height:1.55">
        Gdy logowanie przez Microsoft 365 nie działa, możesz wejść do systemu loginem i hasłem lokalnym.
        Wygenerujemy dla Ciebie silne hasło i pokażemy je <strong>jednorazowo</strong> na następnym ekranie.
      </p>
      <?php if (account_is_office_only($me)): ?>
      <div class="alert alert-info d-flex gap-2 align-items-start" style="font-size:.83rem">
        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
        <div>
          Twoje konto służbowe @feer.org.pl normalnie loguje się tylko przez Office — utworzenie hasła
          odblokuje dla niego również logowanie lokalne (jako awaryjne).
        </div>
      </div>
      <?php endif; ?>
      <?php if ($has_pw): ?>
      <div class="alert alert-warning d-flex gap-2 align-items-start" style="font-size:.83rem">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>Twoje konto ma już hasło lokalne — wygenerowanie nowego nadpisze stare.</div>
      </div>
      <?php endif; ?>
      <form method="post" class="mt-3">
        <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
        <div class="d-grid mb-2">
          <button class="btn btn-primary fw-semibold" type="submit">
            <i class="bi bi-key-fill me-1"></i>Wygeneruj hasło awaryjne
          </button>
        </div>
      </form>
      <div class="text-center">
        <a href="<?= h(APP_URL) ?>/portal.php" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Anuluj i wróć
        </a>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
function convCopy(text, btn) {
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(text).then(function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(function () { btn.innerHTML = old; }, 1200);
  });
}
</script>
</body>
</html>
