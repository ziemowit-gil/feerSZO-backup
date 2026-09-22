<?php
/**
 * helpdesk/mini.php — samodzielny mini-helpdesk (publiczny formularz zgłoszeń).
 *
 * Lekka strona BEZ logowania do SZO: zgłaszający podaje temat/opis/kontakt, a
 * zgłoszenie ląduje jako zagadnienie (issue) w Redmine przez REST API.
 * Do podlinkowania w Redmine albo osadzenia w <iframe> na stronie WWW.
 *
 * Włączana osobno: settings.redmine_minihelpdesk_enabled = '1' (poza główną
 * integracją Helpdesku, żeby publiczny formularz nie był domyślnie otwarty).
 * Zabezpieczenia: honeypot + prosty limit zgłoszeń na sesję. Dla w pełni
 * publicznego użycia rozważ dodatkowo ochronę na poziomie Cloudflare.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$enabled = redmine_is_enabled() && org_setting('redmine_minihelpdesk_enabled') === '1';
$title_org = org_setting('org_nazwa') ?: 'Helpdesk';

$done   = null;   // ['num'=>int]
$error  = '';
$vals   = ['name' => '', 'email' => '', 'subject' => '', 'body' => ''];

if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot — pole „firma" jest ukryte; boty je wypełniają.
    if (trim($_POST['firma'] ?? '') !== '') {
        $error = 'Zgłoszenie odrzucone.';
    } else {
        // Prosty limit: maks. 5 zgłoszeń na sesję / godzinę.
        $now = time();
        $hist = array_values(array_filter((array)($_SESSION['mini_hd'] ?? []), fn($t) => $t > $now - 3600));
        if (count($hist) >= 5) {
            $error = 'Za dużo zgłoszeń w krótkim czasie — spróbuj później.';
        } else {
            $vals['name']    = trim((string)($_POST['name'] ?? ''));
            $vals['email']   = trim((string)($_POST['email'] ?? ''));
            $vals['subject'] = trim((string)($_POST['subject'] ?? ''));
            $vals['body']    = trim((string)($_POST['body'] ?? ''));

            if ($vals['subject'] === '' || $vals['body'] === '') {
                $error = 'Podaj temat i opis zgłoszenia.';
            } elseif ($vals['email'] !== '' && !filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Nieprawidłowy adres e-mail.';
            } else {
                $desc = $vals['body'] . "\n\n---\n"
                      . 'Zgłaszający: ' . ($vals['name'] !== '' ? $vals['name'] : '(nie podano)')
                      . ($vals['email'] !== '' ? ' <' . $vals['email'] . '>' : '') . "\n"
                      . 'Kanał: mini-helpdesk';
                try {
                    $res = redmine_create_issue([
                        'subject'     => $vals['subject'],
                        'description' => $desc,
                    ]);
                    $hist[] = $now;
                    $_SESSION['mini_hd'] = $hist;
                    $done = ['num' => (int)$res['id']];
                    $vals = ['name' => '', 'email' => '', 'subject' => '', 'body' => ''];
                } catch (\Throwable $e) {
                    error_log('[redmine] mini-helpdesk: ' . $e->getMessage());
                    $error = 'Nie udało się wysłać zgłoszenia. Spróbuj ponownie później.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zgłoszenie — <?= h($title_org) ?></title>
<style>
  :root { --brand:#b45309; --bg:#f8fafc; --card:#fff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .wrap { max-width:560px; margin:0 auto; padding:24px 16px; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  h1 { font-size:1.25rem; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:.9rem; margin:0 0 18px; }
  label { display:block; font-weight:600; font-size:.85rem; margin:12px 0 4px; }
  input[type=text], input[type=email], textarea {
    width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:9px; font:inherit; background:#fff; color:inherit;
  }
  textarea { min-height:120px; resize:vertical; }
  .row { display:flex; gap:12px; }
  .row > div { flex:1; }
  .hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
  button { margin-top:18px; width:100%; padding:11px; border:0; border-radius:9px; background:var(--brand); color:#fff; font-weight:700; font-size:1rem; cursor:pointer; }
  button:hover { filter:brightness(.95); }
  .alert { padding:11px 13px; border-radius:9px; font-size:.9rem; margin-bottom:14px; }
  .alert-ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
  .alert-err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  .muted { color:var(--muted); font-size:.8rem; margin-top:14px; text-align:center; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#e2e8f0; --muted:#94a3b8; }
    input, textarea { background:#0f172a; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Formularz zgłoszenia</h1>
    <p class="sub"><?= h($title_org) ?> — opisz problem, a my się nim zajmiemy.</p>

    <?php if (!$enabled): ?>
      <div class="alert alert-err">Formularz zgłoszeń jest obecnie niedostępny.</div>
    <?php elseif ($done): ?>
      <div class="alert alert-ok">
        Dziękujemy! Zgłoszenie zostało przyjęte pod numerem <strong>#<?= (int)$done['num'] ?></strong>.
        Zachowaj ten numer do kontaktu.
      </div>
      <p style="text-align:center"><a href="mini.php">Zgłoś kolejną sprawę</a></p>
    <?php else: ?>
      <?php if ($error !== ''): ?><div class="alert alert-err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <div class="hp" aria-hidden="true">
          <label>Firma (nie wypełniaj)</label>
          <input type="text" name="firma" tabindex="-1" autocomplete="off">
        </div>
        <div class="row">
          <div>
            <label for="name">Imię i nazwisko</label>
            <input type="text" id="name" name="name" value="<?= h($vals['name']) ?>" maxlength="120">
          </div>
          <div>
            <label for="email">E-mail (do kontaktu)</label>
            <input type="email" id="email" name="email" value="<?= h($vals['email']) ?>" maxlength="180">
          </div>
        </div>
        <label for="subject">Temat *</label>
        <input type="text" id="subject" name="subject" value="<?= h($vals['subject']) ?>" maxlength="200" required>
        <label for="body">Opis problemu *</label>
        <textarea id="body" name="body" maxlength="5000" required><?= h($vals['body']) ?></textarea>
        <button type="submit">Wyślij zgłoszenie</button>
      </form>
    <?php endif; ?>
    <p class="muted">Zgłoszenie trafia do systemu obsługi. Nie podawaj haseł ani danych wrażliwych.</p>
  </div>
</div>
</body>
</html>
