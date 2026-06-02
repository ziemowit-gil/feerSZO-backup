<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';

require_role('admin');
$PAGE_TITLE = 'Ponowna wysyłka maili powitalnych';

// ── Masowa wysyłka ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_send'])) {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['contract_ids'] ?? []));
    if (empty($ids)) { flash_set('warning', 'Nie wybrano żadnej umowy.'); header('Location: resend_welcome.php'); exit; }

    $org     = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $sent    = 0;
    $errors  = [];

    foreach ($ids as $cid) {
        $row = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$cid]);
        if (!$row) continue;
        $email = trim($row['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ($row['imie_nazwisko'] ?? "#$cid") . ' — brak e-mail';
            continue;
        }

        // Znajdź lub utwórz konto
        $portal_user = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
        if (!$portal_user) {
            // Utwórz konto
            $plain = substr(str_replace(['+','/','-'],'',base64_encode(random_bytes(18))),0,12);
            $uid   = db_insert('users', [
                'name'      => $row['imie_nazwisko'] ?: $email,
                'email'     => $email,
                'password'  => password_hash($plain, PASSWORD_BCRYPT),
                'role'      => 'viewer',
                'is_active' => 1,
                'created_at'=> date('Y-m-d H:i:s'),
            ]);
        } else {
            // Reset hasła
            $plain = substr(str_replace(['+','/','-'],'',base64_encode(random_bytes(18))),0,12);
            db()->prepare("UPDATE users SET password=?, login_code=NULL WHERE id=?")->execute([password_hash($plain, PASSWORD_BCRYPT), (int)$portal_user['id']]);
        }

        $name         = h($row['imie_nazwisko'] ?? $email);
        $numer        = h($row['numer_umowy'] ?? '');
        $m365_login   = trim($row['m365_login'] ?? '');
        $is_technical = !empty($row['is_technical']);
        $portal_scope = $row['portal_scope'] ?? '';
        $login_url    = APP_URL . '/auth/login.php';

        $login_block = <<<HTML
<table style="background:#f8f9fa;border-radius:8px;padding:16px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr><td style="padding:5px 14px;color:#6c757d;width:130px;font-size:.9em">Adres e-mail</td><td style="padding:5px 14px"><strong>{$email}</strong></td></tr>
  <tr><td style="padding:5px 14px;color:#6c757d;font-size:.9em">Hasło</td><td style="padding:5px 14px"><strong style="font-family:monospace;font-size:1.15em;letter-spacing:.05em">{$plain}</strong></td></tr>
</table>
HTML;

        [$subject, $accent, $intro] = match(true) {
            $is_technical           => ["Twoje dane logowania do platformy — {$org}", '#7c3aed', 'Poniżej znajdziesz dane logowania do platformy organizacji, która zastępuje Trello i inne narzędzia.'],
            $portal_scope==='tasks_only' => ["Dane logowania — tablica zadań — {$org}", '#0ea5e9', 'Poniżej znajdziesz dane logowania do tablicy zadań organizacji.'],
            $portal_scope==='crm_only'   => ["Dane logowania — CRM — {$org}", '#16a34a', 'Poniżej znajdziesz dane logowania do systemu CRM organizacji.'],
            default                 => ["Twoje dane logowania do portalu — {$org}", '#1d6ef9', 'Poniżej znajdziesz dane logowania do portalu wolontariusza organizacji ' . $org . '.'],
        };

        $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,{$accent},{$accent}cc);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🔑 Dane logowania — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>{$intro}</p>
  <div style="background:#fff8e1;border-left:3px solid #f59e0b;border-radius:4px;padding:10px 14px;margin:14px 0;font-size:.88em">
    Hasło zostało wygenerowane przez administratora. Po zalogowaniu możesz je zmienić w <strong>Mój panel</strong>.
  </div>
  {$login_block}
  <div style="margin:20px 0;text-align:center">
    <a href="{$login_url}" style="background:{$accent};color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj się →
    </a>
  </div>
  <p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Wiadomość wysłana automatycznie przez system {$org}.
  </p>
</div></body></html>
HTML;

        mail_queue_add($email, $row['imie_nazwisko'] ?? $email, $subject, $body);
        log_contract_action('wolontariat', $cid, (int)current_user()['id'], 'note',
            'Admin: ponownie wysłano e-mail powitalny na: ' . $email);
        $sent++;
    }

    mail_queue_process();

    $msg = "Wysłano {$sent} e-maili powitalnych.";
    if ($errors) $msg .= ' Błędy: ' . implode('; ', $errors);
    flash_set($errors ? 'warning' : 'success', $msg);
    header('Location: resend_welcome.php'); exit;
}

// ── Pobierz dane ──────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all'; // all | no_account | active

$contracts = db_all(
    "SELECT w.id, w.imie_nazwisko, w.email, w.numer_umowy, w.status,
            w.portal_scope, w.is_technical, w.created_at,
            u.id AS user_id, u.is_active AS user_active
     FROM umowy_wolontariat w
     LEFT JOIN users u ON LOWER(u.email) = LOWER(w.email)
     WHERE w.email IS NOT NULL AND w.email != ''
       AND w.status NOT IN ('anulowana','rozwiązana')
     ORDER BY w.created_at DESC"
);

// Filtruj
$filtered = array_filter($contracts, function($r) use ($filter) {
    if ($filter === 'no_account') return empty($r['user_id']);
    if ($filter === 'inactive')   return !empty($r['user_id']) && !$r['user_active'];
    return true;
});

$cnt_all       = count($contracts);
$cnt_no_acct   = count(array_filter($contracts, fn($r) => empty($r['user_id'])));
$cnt_inactive  = count(array_filter($contracts, fn($r) => !empty($r['user_id']) && !$r['user_active']));

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-envelope-heart text-primary me-2"></i>Ponowna wysyłka maili powitalnych</h4>
    <div class="text-muted small mt-1">Wyślij dane logowania do wybranych wolontariuszy. Hasło zostanie zresetowane.</div>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?filter=all"       class="btn btn-sm <?= $filter==='all'       ? 'btn-primary' : 'btn-outline-secondary' ?>">
    Wszystkie <span class="badge bg-secondary ms-1"><?= $cnt_all ?></span>
  </a>
  <a href="?filter=no_account" class="btn btn-sm <?= $filter==='no_account' ? 'btn-danger' : 'btn-outline-danger' ?>">
    <i class="bi bi-person-x me-1"></i>Brak konta
    <?php if ($cnt_no_acct): ?><span class="badge bg-danger ms-1"><?= $cnt_no_acct ?></span><?php endif; ?>
  </a>
  <a href="?filter=inactive" class="btn btn-sm <?= $filter==='inactive' ? 'btn-warning text-dark' : 'btn-outline-warning' ?>">
    <i class="bi bi-person-slash me-1"></i>Konto nieaktywne
    <?php if ($cnt_inactive): ?><span class="badge bg-warning text-dark ms-1"><?= $cnt_inactive ?></span><?php endif; ?>
  </a>
</div>

<?php if (empty($filtered)): ?>
<div class="alert alert-secondary">
  <i class="bi bi-check-circle me-1"></i>
  Brak umów spełniających kryterium.
</div>
<?php else: ?>

<form method="post" id="resendForm">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="d-flex gap-2 align-items-center">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" id="selectAll"
               onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)">
        <label class="form-check-label small fw-semibold" for="selectAll">Zaznacz wszystkie</label>
      </div>
      <span class="text-muted small" id="selectedCount">(0 zaznaczonych)</span>
    </div>
    <button type="submit" name="_send" value="1" class="btn btn-primary" id="btnSend" disabled
            onclick="return confirm('Wysłać e-maile powitalne do zaznaczonych wolontariuszy?\nHasła zostaną zresetowane.')">
      <i class="bi bi-send me-1"></i>Wyślij zaznaczone
    </button>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
        <thead class="table-light">
          <tr>
            <th style="width:40px"></th>
            <th>Wolontariusz</th>
            <th>E-mail</th>
            <th>Numer umowy</th>
            <th>Typ maila</th>
            <th>Konto portalu</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($filtered as $r):
          $scope   = $r['portal_scope'] ?? '';
          $is_tech = !empty($r['is_technical']);
          $mail_type = $is_tech ? 'Platforma (migracja)' : match($scope) {
              'tasks_only' => 'Tablica zadań',
              'crm_only'   => 'CRM',
              default      => 'Portal wolontariusza',
          };
          $mail_color = $is_tech ? '#7c3aed' : match($scope) {
              'tasks_only' => '#0ea5e9', 'crm_only' => '#16a34a', default => '#1d6ef9',
          };
        ?>
        <tr>
          <td>
            <input type="checkbox" name="contract_ids[]" value="<?= $r['id'] ?>"
                   class="form-check-input row-check"
                   onchange="updateCount()">
          </td>
          <td>
            <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $r['id'] ?>"
               class="fw-semibold text-decoration-none" target="_blank">
              <?= h($r['imie_nazwisko'] ?: '—') ?>
            </a>
          </td>
          <td class="text-muted"><?= h($r['email']) ?></td>
          <td class="font-monospace small"><?= h($r['numer_umowy'] ?: '—') ?></td>
          <td>
            <span class="badge" style="background:<?= $mail_color ?>1A;color:<?= $mail_color ?>;border:1px solid <?= $mail_color ?>44;font-size:.72rem">
              <?= h($mail_type) ?>
            </span>
          </td>
          <td>
            <?php if (!$r['user_id']): ?>
            <span class="badge bg-danger" style="font-size:.72rem"><i class="bi bi-x me-1"></i>Brak konta</span>
            <?php elseif (!$r['user_active']): ?>
            <span class="badge bg-warning text-dark" style="font-size:.72rem"><i class="bi bi-pause me-1"></i>Nieaktywne</span>
            <?php else: ?>
            <span class="badge bg-success" style="font-size:.72rem"><i class="bi bi-check me-1"></i>Aktywne</span>
            <?php endif; ?>
          </td>
          <td><?= status_badge($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<script>
function updateCount() {
  var n = document.querySelectorAll('.row-check:checked').length;
  document.getElementById('selectedCount').textContent = '(' + n + ' zaznaczonych)';
  document.getElementById('btnSend').disabled = n === 0;
}
document.getElementById('selectAll').addEventListener('change', function() {
  setTimeout(updateCount, 10);
});
document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateCount));
</script>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
