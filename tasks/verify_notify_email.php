<?php
/**
 * tasks/verify_notify_email.php — potwierdzenie własnego adresu powiadomień (moduł Zadań).
 * Link z e-maila: ?t={64 hex}. Bez logowania — dowodem jest jednorazowy token wysłany
 * na potwierdzany adres (w bazie tylko sha256). GET pokazuje przycisk, aktywacja dopiero
 * po POST — skanery linków (np. Outlook Safe Links) otwierają GET i nie mogą potwierdzić za użytkownika.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // token w URL nie może wyciec w nagłówku Referer
header('X-Robots-Tag: noindex');

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$state = 'invalid';   // invalid | confirm | done
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activated = task_notify_confirm_email($token);
    if ($activated !== null) { $state = 'done'; $email = $activated; }
} else {
    $row = task_notify_email_token_lookup($token);
    if ($row) { $state = 'confirm'; $email = (string)$row['notify_email_pending']; }
}
$org = defined('ORG_NAME') ? ORG_NAME : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Potwierdzenie adresu powiadomień</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4 font-sans text-slate-800">
  <main class="w-full max-w-md bg-white rounded-xl shadow-sm border border-solid border-slate-200 p-6">
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">
      Zadania<?= $org !== '' ? ' · ' . h($org) : '' ?>
    </p>

    <?php if ($state === 'confirm'): ?>
    <h1 class="text-lg font-bold mb-2">Potwierdź adres do powiadomień</h1>
    <p class="text-sm text-slate-600 mb-4">
      Powiadomienia z modułu Zadań będą wysyłane na adres
      <strong class="text-slate-900 break-all"><?= h($email) ?></strong>.
    </p>
    <form method="post">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <button type="submit"
              class="w-full rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2">
        Potwierdzam
      </button>
    </form>
    <p class="text-xs text-slate-500 mt-3">Jeśli to nie Ty zmieniałeś/aś adres — po prostu zamknij tę stronę.</p>

    <?php elseif ($state === 'done'): ?>
    <h1 class="text-lg font-bold mb-2 text-green-700">Adres potwierdzony</h1>
    <p class="text-sm text-slate-600 mb-4">
      Od teraz powiadomienia z modułu Zadań trafiają na
      <strong class="text-slate-900 break-all"><?= h($email) ?></strong>.
    </p>
    <a href="<?= h(APP_URL . '/tasks/notification_settings.php') ?>"
       class="inline-block text-sm font-semibold text-blue-700 hover:underline">Ustawienia powiadomień →</a>

    <?php else: ?>
    <h1 class="text-lg font-bold mb-2 text-red-700">Link jest nieważny</h1>
    <p class="text-sm text-slate-600 mb-4">
      Link wygasł (ważny 48 godzin), został już użyty albo adres zmieniono ponownie.
      Wpisz adres jeszcze raz w ustawieniach powiadomień, aby dostać nowy link.
    </p>
    <a href="<?= h(APP_URL . '/tasks/notification_settings.php') ?>"
       class="inline-block text-sm font-semibold text-blue-700 hover:underline">Ustawienia powiadomień →</a>
    <?php endif; ?>
  </main>
</body>
</html>
