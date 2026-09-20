<?php
/**
 * modules/sprawdz_konto/index.php — Sprawdzarka numeru konta do wpłat.
 *
 * Publiczna (bez logowania) strona dla:
 *  - kursanta modułu TI — login panelu + imię i nazwisko,
 *  - kontrahenta z dowolnej umowy Rejestru Umów — numer umowy + imię i
 *    nazwisko / nazwa.
 *
 * Zabezpieczenia (patrz modules/sprawdz_konto/logic/sprawdz_konto.php):
 * dopasowanie dwóch pól łącznie, jeden generyczny komunikat błędu, honeypot,
 * limit prób per IP (login_attempts, includes/auth_security.php), CSRF,
 * brak indeksowania, log prób bez przechowywania imienia i nazwiska.
 * Strona jest samodzielna — celowo NIE używa includes/header.php (wzorzec
 * jak helpdesk/track.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/sprawdz_konto.php';
sprawdz_konto_migrate();

$org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot — pole niewidoczne dla ludzi, wypełniane wyłącznie przez boty.
    // Reakcja identyczna jak przy błędnym dopasowaniu, żeby nie zdradzać techniki.
    $is_bot = trim($_POST['strona_www'] ?? '') !== '';

    if (!$is_bot && sprawdz_konto_rate_limited()) {
        flash_set('danger', 'Zbyt wiele prób w krótkim czasie. Spróbuj ponownie za kilkanaście minut.');
    } else {
        sprawdz_konto_record_attempt();
        $typ           = ($_POST['_typ'] ?? '') === 'kontrahent' ? 'kontrahent' : 'kursant';
        $imie_nazwisko = trim($_POST['imie_nazwisko'] ?? '');
        $result        = null;

        if (!$is_bot) {
            if ($typ === 'kursant') {
                $identyfikator = trim($_POST['login'] ?? '');
                $result = sprawdz_konto_lookup_kursant($identyfikator, $imie_nazwisko);
            } else {
                $identyfikator = trim($_POST['numer_umowy'] ?? '');
                $result = sprawdz_konto_lookup_kontrahent($identyfikator, $imie_nazwisko);
            }
            sprawdz_konto_log($typ, $identyfikator, $result !== null);
        }

        if ($result) {
            $_SESSION['sprawdz_konto_result'] = $result;
        } else {
            flash_set('warning', 'Nie znaleziono zgodności podanych danych. Sprawdź poprawność ' .
                ($typ === 'kursant' ? 'loginu' : 'numeru umowy') .
                ' oraz imienia i nazwiska (lub nazwy) i spróbuj ponownie, albo skontaktuj się z ' . h($org) . '.');
        }
    }
    header('Location: ' . APP_URL . '/modules/sprawdz_konto/');
    exit;
}

$result = $_SESSION['sprawdz_konto_result'] ?? null;
unset($_SESSION['sprawdz_konto_result']);
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sprawdź numer konta do wpłat · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body{background:#f6f7fb;color:#111827}
  .sk-wrap{max-width:640px;margin:0 auto;padding:32px 16px 60px}
  .sk-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
  .sk-hp{position:absolute;left:-9999px;top:-9999px}
  .sk-result{border-left:4px solid #198754;background:#f0fdf4}
</style>
</head>
<body>
<div class="sk-wrap">

  <div class="text-center mb-4">
    <h1 class="h4 fw-bold mb-1"><i class="bi bi-bank text-primary"></i> Sprawdź numer konta do wpłat</h1>
    <p class="text-muted small mb-0"><?= h($org) ?></p>
  </div>

  <?= flash_html() ?>

  <?php if ($result): ?>
  <div class="sk-card sk-result p-4 mb-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-check-circle-fill text-success"></i> Numer konta do wpłat</h2>
    <p class="mb-1 small text-muted">Numer konta</p>
    <p class="fs-5 fw-bold font-monospace mb-3"><?= h($result['numer_konta']) ?></p>
    <p class="mb-1 small text-muted">Rodzaj rachunku</p>
    <p class="mb-0"><?= h($result['typ_konta']) ?></p>
  </div>
  <div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle"></i>
    Przed dokonaniem wpłaty zawsze porównaj ten numer z tym, który widnieje w otrzymanym dokumencie/mailu.
    W razie jakiejkolwiek rozbieżności — nie dokonuj wpłaty i skontaktuj się z nami telefonicznie.
  </div>
  <?php endif; ?>

  <div class="sk-card p-4 mb-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-mortarboard"></i> Jestem kursantem</h2>
    <form method="post" novalidate>
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_typ" value="kursant">
      <div class="sk-hp" aria-hidden="true">
        <label>Strona www <input type="text" name="strona_www" tabindex="-1" autocomplete="off"></label>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold" for="k_login">Login do panelu kursanta</label>
        <input type="text" class="form-control" id="k_login" name="login" required maxlength="60">
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold" for="k_name">Imię i nazwisko</label>
        <input type="text" class="form-control" id="k_name" name="imie_nazwisko" required maxlength="150">
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Sprawdź numer konta</button>
    </form>
  </div>

  <div class="sk-card p-4 mb-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-briefcase"></i> Jestem kontrahentem</h2>
    <form method="post" novalidate>
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_typ" value="kontrahent">
      <div class="sk-hp" aria-hidden="true">
        <label>Strona www <input type="text" name="strona_www" tabindex="-1" autocomplete="off"></label>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold" for="c_numer">Numer umowy</label>
        <input type="text" class="form-control" id="c_numer" name="numer_umowy" required maxlength="100" placeholder="np. Z/2026/014">
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold" for="c_name">Imię i nazwisko / nazwa</label>
        <input type="text" class="form-control" id="c_name" name="imie_nazwisko" required maxlength="150">
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Sprawdź numer konta</button>
    </form>
  </div>

  <div class="small text-muted">
    <p class="fw-semibold mb-1"><i class="bi bi-shield-check"></i> Informacja o przetwarzaniu danych (RODO)</p>
    <p class="mb-1">
      Podane dane (identyfikator oraz imię i nazwisko / nazwa) są wykorzystywane wyłącznie do
      jednorazowej weryfikacji numeru konta do wpłat i nie są zapisywane w tej formie.
      Zapisujemy jedynie podany identyfikator, wynik weryfikacji, adres IP i znacznik czasu —
      w celu bezpieczeństwa i przeciwdziałania nadużyciom — przez 90 dni.
    </p>
    <p class="mb-0">
      Podstawa prawna: art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes administratora —
      zapobieganie oszustwom płatniczym i pomyłkom we wpłatach). Administratorem danych jest
      <?= h($org) ?>.
    </p>
  </div>

</div>
</body>
</html>
