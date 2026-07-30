<?php
/**
 * admin/never_logged_in.php — Lista użytkowników, którzy nigdy nie zalogowali się do SZO.
 *
 * Pokazuje aktywne konta bez odnotowanego logowania (żadna metoda). Umożliwia:
 *   - ręczne wysłanie przypomnienia e-mail + SMS per użytkownik,
 *   - podgląd liczby dotychczasowych przypomnień.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

require_role('admin');

$app_url       = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
$tozsamosc_url = $app_url . '/tozsamosc';
$contact_email = 'fundacja@feer.org.pl';
$org           = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER');

// ── Ręczne wysłanie przypomnienia ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_uid'])) {
    csrf_check();
    $send_uid = (int)$_POST['send_uid'];
    $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id=? AND is_active=1", [$send_uid]);
    if (!$u || !filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Nie znaleziono użytkownika lub brak adresu e-mail.');
        header('Location: ' . $app_url . '/admin/never_logged_in.php');
        exit;
    }

    $name_html = htmlspecialchars($u['name'] ?? '');
    $greeting  = $name_html ? "Dzień dobry, {$name_html}," : 'Dzień dobry,';
    $subject   = "Pilne: Do tej pory nie aktywowałeś/aś konta w systemie FEER (SZO)";
    $body_html = <<<HTML
<html><body style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#c2410c;padding:18px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">Fundacja FEER &mdash; System Zarządzania Organizacją (SZO)</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>{$greeting}</p>
  <p>Zauważyliśmy, że do tej pory nie aktywowałeś/aś swojego konta w zintegrowanym środowisku Fundacji FEER.</p>
  <p>Przypominamy, że w związku z posiadaną umową o współpracę przechodzimy na nasz centralny system zarządzania.
     Zgodnie z przyjętą zasadą, odchodzimy od rozproszonych rozwiązań &ndash; wszystkie zadania, zarządzanie
     umowami oraz komunikacja odbywają się obecnie w jednym, centralnym punkcie (SZO).</p>
  <p><strong>Co zyskujesz w module SZO?</strong></p>
  <ul>
    <li><strong>Zadania:</strong> Bieżący wgląd w powierzone zadania i ich statusy.</li>
    <li><strong>Zarządzanie umową:</strong> Przejrzysty dostęp do informacji i dokumentacji związanej z Twoją umową.</li>
    <li><strong>Komunikacja:</strong> Jeden, zintegrowany kanał wymiany informacji z fundacją.</li>
  </ul>
  <p><strong>Instrukcja szybkiej aktywacji konta</strong></p>
  <ol style="line-height:1.8">
    <li><strong>Węzeł tożsamości:</strong><br>
        Przejdź pod adres: <a href="{$tozsamosc_url}" style="color:#c2410c">{$tozsamosc_url}</a></li>
    <li><strong>Pierwsze logowanie:</strong><br>
        Użyj mechanizmu węzła tożsamości FEER, aby uwierzytelnić się w systemie.</li>
    <li><strong>Aktywacja konta w SZO:</strong><br>
        Po zalogowaniu system poprowadzi Cię przez proces aktywacji profilu.</li>
  </ol>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:20px 0;font-size:.92em">
    ⚠️ Prosimy o pilne dokończenie procesu aktywacji.
  </div>
  <p>W razie pytań: <a href="mailto:{$contact_email}" style="color:#c2410c">{$contact_email}</a>.</p>
  <p>Z pozdrowieniami,<br><strong>Zespół Fundacji FEER</strong></p>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:24px">
    Wiadomość wysłana automatycznie przez system SZO &mdash; {$org}.
  </p>
</div></body></html>
HTML;

    try {
        mail_queue_add($u['email'], $u['name'] ?? '', $subject, $body_html, '', 'user', $send_uid);
        mail_queue_process();

        // SMS jeśli możliwy
        $sms_sent = false;
        $phone = trim((string)($u['phone_number'] ?? ''));
        if ($phone && function_exists('sms_is_enabled') && sms_is_enabled()) {
            try {
                sms_send($phone, "FEER SZO: Nie aktywowałeś/aś jeszcze konta. Zaloguj się: {$tozsamosc_url} Pytania: {$contact_email}");
                $sms_sent = true;
            } catch (\Throwable $e) { /* ignoruj */ }
        }

        // Zaktualizuj metadane dedup
        $key  = "never_login_reminder_{$send_uid}";
        $meta = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $data = $meta ? (json_decode($meta['value'], true) ?? []) : [];
        $data['count'] = ((int)($data['count'] ?? 0)) + 1;
        $data['last']  = date('Y-m-d');
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
             ->execute([$key, json_encode($data)]);

        $msg = 'Wysłano e-mail z przypomnieniem do ' . h($u['email']);
        if ($sms_sent) $msg .= ' + SMS';
        flash_set('success', $msg . '.');
    } catch (\Throwable $e) {
        flash_set('danger', 'Błąd wysyłki: ' . $e->getMessage());
    }

    header('Location: ' . $app_url . '/admin/never_logged_in.php');
    exit;
}

// ── Pobierz listę ─────────────────────────────────────────────────────────────
$users = db_all(
    "SELECT
       u.id, u.name, u.email, u.role, u.created_at, u.phone_number,
       s.value AS reminder_json
     FROM users u
     LEFT JOIN settings s ON s.key_ = 'never_login_reminder_' || u.id
     WHERE u.is_active = 1
       AND LOWER(u.email) != 'serwis@local'
       AND NOT EXISTS (
           SELECT 1 FROM login_log l
           WHERE l.user_id = u.id
             AND l.action IN ('login', 'login_sms', 'login_x509', 'login_code')
       )
       AND NOT EXISTS (
           SELECT 1 FROM contract_audit_log cal
           WHERE cal.user_id = u.id
             AND cal.contract_type = 'auth'
             AND cal.action = 'login_ms'
       )
     ORDER BY u.created_at ASC",
    []
);

// Parsuj metadane przypomnień
foreach ($users as &$u) {
    $meta = $u['reminder_json'] ? (json_decode($u['reminder_json'], true) ?? []) : [];
    $u['reminder_count'] = (int)($meta['count'] ?? 0);
    $u['reminder_last']  = $meta['last'] ?? '';
    $created_ts = $u['created_at'] ? strtotime(substr($u['created_at'], 0, 10)) : 0;
    $u['days_since'] = $created_ts ? (int)floor((time() - $created_ts) / 86400) : 0;
}
unset($u);

$PAGE_TITLE = 'Konta bez logowania';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-person-x text-danger"></i> Konta bez logowania
  </h4>
  <a href="<?= $app_url ?>/admin/users.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Użytkownicy
  </a>
</div>

<?php echo flash_html(); ?>

<div class="alert alert-info d-flex gap-2 align-items-start mb-4">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    Lista aktywnych kont, które <strong>nigdy nie zalogowały się</strong> do SZO (żadną metodą: hasło,
    Microsoft 365, SMS, kod). Cron automatycznie wysyła przypomnienia max. 4× co 14 dni &mdash;
    poniżej możesz też wysłać je ręcznie.
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-envelope-exclamation text-danger"></i> Nieaktywni użytkownicy</span>
    <span class="badge bg-danger"><?= count($users) ?></span>
  </div>

  <?php if (!$users): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-check-circle-fill text-success fs-1"></i>
      <p class="mt-3">Wszyscy aktywni użytkownicy zalogowali się co najmniej raz. Brawo!</p>
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>Rola</th>
          <th>Konto założone</th>
          <th>Dni bez logowania</th>
          <th>Przypomnień wysłanych</th>
          <th>Ostatnie przypomnienie</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($u['name'] ?? '—') ?></div>
            <div class="text-muted small"><?= h($u['email']) ?></div>
            <?php if ($u['phone_number']): ?>
              <div class="text-muted small"><i class="bi bi-phone"></i> <?= h($u['phone_number']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-secondary"><?= h($u['role']) ?></span></td>
          <td class="text-nowrap small"><?= h(substr((string)$u['created_at'], 0, 10)) ?></td>
          <td class="text-center">
            <?php
              $days = $u['days_since'];
              $cls = $days >= 30 ? 'text-danger fw-bold' : ($days >= 14 ? 'text-warning' : 'text-muted');
            ?>
            <span class="<?= $cls ?>"><?= $days ?></span>
          </td>
          <td class="text-center">
            <?php if ($u['reminder_count'] >= 4): ?>
              <span class="badge bg-danger"><?= $u['reminder_count'] ?> / 4 (wyczerpano)</span>
            <?php elseif ($u['reminder_count'] > 0): ?>
              <span class="badge bg-warning text-dark"><?= $u['reminder_count'] ?> / 4</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small text-muted"><?= $u['reminder_last'] ? h($u['reminder_last']) : '—' ?></td>
          <td class="text-end text-nowrap">
            <?php if ($u['reminder_count'] < 4): ?>
            <form method="post" action="" class="d-inline" onsubmit="return confirm('Wysłać przypomnienie do <?= h(addslashes($u['email'])) ?>?')">
              <input type="hidden" name="send_uid" value="<?= (int)$u['id'] ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Wyślij e-mail + SMS">
                <i class="bi bi-send"></i> Przypomnij
              </button>
            </form>
            <?php else: ?>
              <span class="text-muted small">limit</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
