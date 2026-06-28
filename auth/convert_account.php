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
    $is_office = account_is_office_only($me['email'] ?? '');

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

$org      = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$login_url = APP_URL . '/auth/login.php';
$tok      = csrf_token();
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hasło awaryjne · <?= h($org) ?></title>
<style>
  :root{ --brand:#2563eb; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
        background:#f1f5f9; color:#1e293b; min-height:100vh; display:flex;
        align-items:flex-start; justify-content:center; padding:2.5rem 1rem; }
  .wrap{ width:100%; max-width:560px; }
  .head{ text-align:center; margin-bottom:1.5rem; }
  .head h1{ font-size:1.4rem; margin:.2rem 0; }
  .head p{ color:#64748b; margin:0; font-size:.92rem; }
  .card{ background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.2rem 1.3rem;
         margin-bottom:.85rem; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  .card h2{ font-size:1rem; margin:0 0 .5rem; }
  .desc{ color:#475569; font-size:.9rem; line-height:1.5; }
  button.btn{ border:0; border-radius:9px; padding:.7rem 1rem; font-weight:600; font-size:1rem;
              cursor:pointer; }
  .btn-primary{ background:var(--brand); color:#fff; width:100%; }
  .btn-soft{ background:#f1f5f9; color:#1e293b; }
  .btn-soft:hover{ background:#e2e8f0; }
  .err{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:9px;
        padding:.55rem .8rem; margin-bottom:1rem; font-size:.88rem; }
  .cred{ background:#f8fafc; border:1px dashed #94a3b8; border-radius:10px; padding:.9rem 1rem;
         margin:.8rem 0; }
  .cred .row{ display:flex; justify-content:space-between; gap:1rem; padding:.25rem 0; font-size:.95rem; }
  .cred .k{ color:#64748b; }
  .cred .v{ font-weight:700; font-family:ui-monospace,Menlo,Consolas,monospace; }
  .warn{ background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:9px;
         padding:.6rem .8rem; font-size:.85rem; margin-top:.6rem; }
  a.link{ color:var(--brand); }
  .muted{ color:#94a3b8; font-size:.82rem; text-align:center; margin-top:.8rem; }
  .copybtn{ border:1px solid #cbd5e1; background:#fff; border-radius:7px; padding:.15rem .5rem;
            font-size:.78rem; cursor:pointer; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>🔐 Hasło awaryjne do logowania lokalnego</h1>
    <?php if ($not_logged): ?>
    <p>Zapasowy sposób wejścia do systemu, gdy logowanie przez Office nie działa</p>
    <?php else: ?>
    <p>Zalogowano jako <strong><?= h($me['email']) ?></strong></p>
    <?php endif; ?>
  </div>

  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <?php if ($not_logged): ?>
  <!-- Strona wyjaśniająca (użytkownik niezalogowany) -->
  <div class="card">
    <h2>Co to jest hasło awaryjne?</h2>
    <p class="desc">
      Zwykle logujesz się przyciskiem <strong>„Zaloguj przez Microsoft 365"</strong> (Office).
      Czasem jednak logowanie Microsoft może nie działać — np. awaria po stronie Microsoftu,
      problem z kontem służbowym albo brak dostępu do telefonu z aplikacją uwierzytelniającą.
    </p>
    <p class="desc">
      <strong>Hasło awaryjne</strong> to zapasowy sposób wejścia do systemu: ustawiasz dodatkowe
      hasło lokalne, którym zalogujesz się <em>loginem (e-mail) i hasłem</em>, gdy Office zawiedzie.
    </p>
    <div class="warn">
      Aby utworzyć hasło awaryjne, musisz <strong>najpierw raz zalogować się przez Microsoft 365</strong>
      (póki działa) — w ten sposób potwierdzasz swoją tożsamość. Zrób to <strong>zawczasu</strong>,
      zanim pojawią się problemy.
    </div>
    <p style="margin-top:1rem">
      <a class="btn btn-primary" style="display:block;text-align:center;text-decoration:none"
         href="<?= h($start_login_url) ?>">
        Zaloguj przez Microsoft 365 i utwórz hasło
      </a>
    </p>
    <p class="muted"><a class="link" href="<?= h($login_url) ?>">Wróć do logowania</a></p>
  </div>
  <?php elseif ($no_ms): ?>
  <!-- Konto bez powiązania z Microsoft 365 -->
  <div class="card">
    <h2>To konto nie wymaga hasła awaryjnego</h2>
    <p class="desc">
      Funkcja jest przeznaczona dla kont logujących się przez Microsoft 365 (Office) — tworzy dla nich
      zapasowe hasło lokalne. Twoje konto <strong><?= h($me['email']) ?></strong> nie jest powiązane
      z Microsoft 365 i loguje się standardowo loginem i hasłem.
    </p>
    <p class="desc">Jeśli zapomniałeś hasła, użyj opcji odzyskiwania na stronie logowania.</p>
    <p class="muted"><a class="link" href="<?= h(APP_URL) ?>/portal.php">Wróć do systemu</a></p>
  </div>
  <?php elseif ($generated !== null): ?>
  <!-- Wynik: hasło pokazane RAZ -->
  <div class="card">
    <h2>✅ Hasło zostało utworzone</h2>
    <p class="desc">Zapisz te dane w bezpiecznym miejscu — <strong>hasło pokażemy tylko teraz</strong>.</p>
    <div class="cred">
      <div class="row"><span class="k">Adres logowania</span><span class="v"><a class="link" href="<?= h($login_url) ?>"><?= h($login_url) ?></a></span></div>
      <div class="row"><span class="k">Login (e-mail)</span><span class="v" id="c-login"><?= h($me['email']) ?></span></div>
      <div class="row"><span class="k">Hasło tymczasowe</span><span class="v" id="c-pass"><?= h($generated) ?> <button type="button" class="copybtn" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= h($generated) ?>')">kopiuj</button></span></div>
    </div>
    <div class="warn">
      Przy pierwszym logowaniu lokalnym system poprosi o ustawienie własnego hasła.
      <?php if (account_is_office_only($me['email'] ?? '')): ?>
      Logowanie lokalne zostało odblokowane wyłącznie dla Twojego konta — pozostali użytkownicy @feer.org.pl nadal logują się tylko przez Office.
      <?php endif; ?>
    </div>
    <p class="muted"><a class="link" href="<?= h(APP_URL) ?>/portal.php">Wróć do systemu</a></p>
  </div>
  <?php else: ?>
  <!-- Formularz potwierdzenia -->
  <div class="card">
    <h2>Utwórz hasło awaryjne</h2>
    <p class="desc">
      Gdy logowanie przez Microsoft 365 nie działa, możesz wejść do systemu loginem i hasłem lokalnym.
      Wygenerujemy dla Ciebie silne hasło i pokażemy je <strong>jednorazowo</strong> na następnym ekranie.
      <?php if (account_is_office_only($me['email'] ?? '')): ?>
      <br><br>Twoje konto służbowe @feer.org.pl normalnie loguje się tylko przez Office — utworzenie hasła
      odblokuje dla niego również logowanie lokalne (jako awaryjne).
      <?php endif; ?>
      <?php if (!empty($me['microsoft_id']) && function_exists('db_one')):
        $has_pw = false;
        try { $has_pw = (bool)(db_one("SELECT password FROM users WHERE id=?", [(int)$me['id']])['password'] ?? ''); } catch(\Throwable $e){}
        if ($has_pw): ?>
      <br><br><span style="color:#b45309">Uwaga: Twoje konto ma już hasło lokalne — wygenerowanie nowego nadpisze stare.</span>
      <?php endif; endif; ?>
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <button class="btn btn-primary" type="submit">Wygeneruj hasło awaryjne</button>
    </form>
    <p class="muted"><a class="link" href="<?= h(APP_URL) ?>/portal.php">Anuluj i wróć</a></p>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
