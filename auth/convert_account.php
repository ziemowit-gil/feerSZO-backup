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

$login_url     = APP_URL . '/auth/login.php';
$emergency_url = APP_URL . '/auth/awaryjne.php';
$tok           = csrf_token();
$has_pw        = false;
if (!$not_logged && !$no_ms && !empty($me['microsoft_id'])) {
    try { $has_pw = (bool)(db_one("SELECT password FROM users WHERE id=?", [(int)$me['id']])['password'] ?? ''); } catch (\Throwable $e) {}
}

// Widok: jasny wygląd ekranów wejścia (includes/auth_screen.php, tryb 'light'; przebudowa 2026-11-14). Logika powyżej bez zmian.
auth_screen_head([
    'title'           => 'Hasło awaryjne',
    'light'           => true,
    'width'           => 500,
    'main_id'         => 'conv-main',
    'account_buttons' => [],
    'head_html'       => '<style>'
        . '.cred-box{margin:0 0 1.25rem;padding:.5rem 1rem;background:#f9fafb;border:1px dashed var(--au-field);border-radius:var(--au-rs)}'
        . '.cred-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.25rem .75rem;padding:.5rem 0}'
        . '.cred-row + .cred-row{border-top:1px solid var(--au-line)}'
        . '.cred-k{font-size:.85rem;color:var(--au-muted)}'
        . '.cred-v{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.95rem;font-weight:700;overflow-wrap:anywhere;text-align:right}'
        . '.copy-btn{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;border:1px solid var(--au-field);'
        . 'border-radius:var(--au-rs);background:#fff;color:var(--au-ink);cursor:pointer}'
        . '.copy-btn:hover{background:var(--au-bg)}'
        . '.conv-ok{margin:0 0 .75rem;font-size:2.4rem;color:var(--au-ok)}'
        . '.au .conv-h2{margin:0 0 .5rem;font-size:1.1rem;font-weight:700}'
        . '.au .conv-p{margin:0 0 1rem;font-size:.95rem;line-height:1.6;color:var(--au-ink2)}'
        . '</style>',
]);
?>
<div class="ks-hero-ico" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
<h1 class="ks-h1">Hasło awaryjne do logowania lokalnego</h1>
<?php if ($not_logged): ?>
<p class="ks-lead">Zapasowy sposób wejścia, gdy logowanie przez Office nie działa.</p>
<?php else: ?>
<p class="ks-lead">Zalogowano jako <strong><?= h($me['email']) ?></strong></p>
<?php endif; ?>

<?php if ($err): ?>
<div class="l-alert l-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div><?= h($err) ?></div></div>
<?php endif; ?>

<?php if ($not_logged): ?>
<!-- ── Strona wyjaśniająca (użytkownik niezalogowany) ─────────────────── -->
<h2 class="conv-h2">Co to jest hasło awaryjne?</h2>
<p class="conv-p">
  Zwykle logujesz się przyciskiem <strong>„Zaloguj przez Microsoft 365"</strong> (Office).
  Czasem jednak logowanie Microsoft może nie działać — np. awaria po stronie Microsoftu,
  problem z kontem służbowym albo brak dostępu do telefonu z aplikacją uwierzytelniającą.
</p>
<p class="conv-p">
  <strong>Hasło awaryjne</strong> to zapasowy sposób wejścia do systemu: ustawiasz dodatkowe
  hasło lokalne, którym zalogujesz się <em>loginem (e-mail) i hasłem</em>, gdy Office zawiedzie.
</p>
<div class="l-alert l-alert-warn" role="note">
  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
  <div>
    Aby utworzyć hasło awaryjne, musisz <strong>najpierw raz zalogować się przez Microsoft 365</strong>
    (póki działa) — w ten sposób potwierdzasz swoją tożsamość. Zrób to <strong>zawczasu</strong>,
    zanim pojawią się problemy.
  </div>
</div>
<a href="<?= h($start_login_url) ?>" class="ks-btn ks-btn--primary"><i class="bi bi-microsoft" aria-hidden="true"></i>Zaloguj przez Microsoft 365 i utwórz hasło</a>
<p class="ks-note"><a href="<?= h($login_url) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do logowania</a></p>

<?php elseif ($no_ms): ?>
<!-- ── Konto bez powiązania z Microsoft 365 ───────────────────────────── -->
<h2 class="conv-h2">To konto nie wymaga hasła awaryjnego</h2>
<p class="conv-p">
  Funkcja jest przeznaczona dla kont logujących się przez Microsoft 365 (Office) — tworzy dla nich
  zapasowe hasło lokalne. Twoje konto <strong><?= h($me['email']) ?></strong> nie jest powiązane
  z Microsoft 365 i loguje się standardowo loginem i hasłem.
</p>
<p class="conv-p">Jeśli zapomniałeś hasła, użyj opcji odzyskiwania na stronie logowania.</p>
<p class="ks-note"><a href="<?= h(APP_URL) ?>/portal.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do systemu</a></p>

<?php elseif ($generated !== null): ?>
<!-- ── Wynik: hasło pokazane RAZ ───────────────────────────────────────── -->
<div class="conv-ok" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div>
<h2 class="conv-h2">Hasło zostało utworzone</h2>
<p class="conv-p">Zapisz te dane w bezpiecznym miejscu — <strong>hasło pokażemy tylko teraz</strong>.</p>
<div class="cred-box">
  <div class="cred-row">
    <span class="cred-k">Adres logowania awaryjnego</span>
    <a class="cred-v" href="<?= h($emergency_url) ?>"><?= h($emergency_url) ?></a>
  </div>
  <div class="cred-row">
    <span class="cred-k">Login (e-mail)</span>
    <span class="cred-v" id="c-login"><?= h($me['email']) ?></span>
  </div>
  <div class="cred-row">
    <span class="cred-k">Hasło tymczasowe</span>
    <span style="display:flex;align-items:center;gap:.5rem">
      <span class="cred-v" id="c-pass"><?= h($generated) ?></span>
      <button type="button" class="copy-btn" aria-label="Skopiuj hasło" onclick="convCopy('<?= h($generated) ?>', this)">
        <i class="bi bi-clipboard" aria-hidden="true"></i>
      </button>
    </span>
  </div>
</div>
<div class="l-alert l-alert-warn" role="note">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div>
    Przy pierwszym logowaniu lokalnym system poprosi o ustawienie własnego hasła.
    <?php if (account_is_office_only($me)): ?>
    Logowanie lokalne zostało odblokowane wyłącznie dla Twojego konta — pozostali użytkownicy
    @feer.org.pl nadal logują się tylko przez Office.
    <?php endif; ?>
  </div>
</div>
<p class="ks-note"><a href="<?= h(APP_URL) ?>/portal.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do systemu</a></p>

<?php else: ?>
<!-- ── Formularz potwierdzenia ─────────────────────────────────────────── -->
<h2 class="conv-h2">Utwórz hasło awaryjne</h2>
<p class="conv-p">
  Gdy logowanie przez Microsoft 365 nie działa, możesz wejść do systemu loginem i hasłem lokalnym.
  Wygenerujemy dla Ciebie silne hasło i pokażemy je <strong>jednorazowo</strong> na następnym ekranie.
</p>
<?php if (account_is_office_only($me)): ?>
<div class="l-alert l-alert-info" role="note">
  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
  <div>
    Twoje konto służbowe @feer.org.pl normalnie loguje się tylko przez Office — utworzenie hasła
    odblokuje dla niego również logowanie lokalne (jako awaryjne).
  </div>
</div>
<?php endif; ?>
<?php if ($has_pw): ?>
<div class="l-alert l-alert-warn" role="note">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div>Twoje konto ma już hasło lokalne — wygenerowanie nowego nadpisze stare.</div>
</div>
<?php endif; ?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
  <button class="ks-btn ks-btn--primary" type="submit"><i class="bi bi-key-fill" aria-hidden="true"></i>Wygeneruj hasło awaryjne</button>
</form>
<p class="ks-note"><a href="<?= h(APP_URL) ?>/portal.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Anuluj i wróć</a></p>
<?php endif; ?>
<?php
auth_screen_foot([
    'no_new_tiles' => true,
    'extra_js'     => '<script>
function convCopy(text, btn) {
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(text).then(function () {
    var old = btn.innerHTML, live = document.getElementById("login-live");
    btn.innerHTML = "<i class=\"bi bi-check2\" aria-hidden=\"true\"></i>";
    if (live) live.textContent = "Hasło skopiowane do schowka.";
    setTimeout(function () { btn.innerHTML = old; }, 1200);
  });
}
</script>',
]);
