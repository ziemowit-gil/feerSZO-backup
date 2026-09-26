<?php
/**
 * modules/sprawdz_konto/index.php — Sprawdzarka numeru konta do wpłat.
 *
 * Publiczna (bez logowania), dostępna WYŁĄCZNIE przez spersonalizowany
 * link z tokenem (?t=...) wysyłany przez organizację e-mailem — jak
 * helpdesk/track.php. Nie ma tu formularza z wpisywaniem identyfikatora
 * ani imienia i nazwiska (patrz modules/sprawdz_konto/logic/sprawdz_konto.php
 * — uzasadnienie i generowanie linków).
 *
 * Zabezpieczenia: limit prób odczytu tokenu per IP (login_attempts,
 * includes/auth_security.php), brak indeksowania, log prób bez
 * przechowywania danych osobowych. Strona jest samodzielna — celowo NIE
 * używa includes/header.php (wzorzec jak helpdesk/track.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/sprawdz_konto.php';
require_once dirname(__DIR__) . '/gdpr_clauses/logic/gdpr_clauses.php';
sprawdz_konto_migrate();

$org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$token = trim($_GET['t'] ?? '');

$result = null;
$error  = null;

if ($token !== '') {
    if (sprawdz_konto_rate_limited()) {
        $error = 'Zbyt wiele prób w krótkim czasie. Spróbuj ponownie za kilkanaście minut.';
    } else {
        sprawdz_konto_record_attempt();
        $result = sprawdz_konto_resolve_token($token);
        sprawdz_konto_log('token', mb_substr($token, 0, 16), $result !== null);
        if (!$result) {
            $error = 'Ten link jest nieprawidłowy, wygasł lub został unieważniony (np. przez wysłanie nowszego). '
                   . 'Poproś ' . h($org) . ' o aktualny link.';
        }
    }
}
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sprawdź numer konta do wpłat · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
<div class="col-12 col-md-8 col-lg-6 mx-auto">

  <div class="text-center mb-4">
    <h1 class="h4 fw-bold mb-1"><i class="bi bi-bank text-primary"></i> Sprawdź numer konta do wpłat</h1>
    <p class="text-muted small mb-0"><?= h($org) ?></p>
  </div>

  <?php if ($result): ?>
  <div class="card border-success mb-4">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-3"><i class="bi bi-check-circle-fill text-success"></i> Numer konta do wpłat</h2>
      <p class="mb-1 small text-muted">Numer konta</p>
      <p class="fs-5 fw-bold font-monospace mb-3"><?= h($result['numer_konta']) ?></p>
      <p class="mb-1 small text-muted">Rodzaj rachunku</p>
      <p class="mb-0"><?= h($result['typ_konta']) ?></p>
    </div>
  </div>
  <div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle"></i>
    Przed dokonaniem wpłaty zawsze porównaj ten numer z tym, który widnieje w otrzymanym dokumencie/mailu.
    W razie jakiejkolwiek rozbieżności — nie dokonuj wpłaty i skontaktuj się z nami telefonicznie.
  </div>

  <?php elseif ($error): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= $error ?></div>

  <?php else: ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Ta strona pokazuje numer konta wyłącznie po otwarciu spersonalizowanego linku, który wysyłamy
    e-mailem po zatwierdzeniu numeru konta (kursanci) albo na prośbę kontrahenta. Jeśli potrzebujesz
    takiego linku, skontaktuj się z <?= h($org) ?>.
  </div>
  <?php endif; ?>

  <div class="small text-muted mt-4">
    <p class="fw-semibold mb-1"><i class="bi bi-shield-check"></i> Informacja o przetwarzaniu danych (RODO)</p>
    <p class="mb-0">
      Link jest ważny ograniczony czas i jednoznacznie przypisany do jednej osoby/umowy. Otwarcie
      linku jest logowane (adres IP, znacznik czasu) w celu bezpieczeństwa i przeciwdziałania
      nadużyciom — logi przechowujemy przez 90 dni. Administratorem danych jest <?= h($org) ?>.<?= gdpr_clauses_footer_link(' ') ?>
    </p>
  </div>

</div>
</div>
</body>
</html>
