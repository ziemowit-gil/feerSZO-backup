<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u   = current_user();
$uid = (int)$u['id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = $_POST['category']    ?? 'it_inne';
    $priority    = $_POST['priority']    ?? 'normalny';
    $req_name    = trim($_POST['req_name']    ?? $u['name'] ?? '');
    $req_email   = trim($_POST['req_email']   ?? $u['email'] ?? '');
    $req_phone   = preg_replace('/\D/', '', trim($_POST['req_phone'] ?? ''));

    if (!$title)                         $errors[] = 'Temat jest wymagany.';
    if (!$description)                   $errors[] = 'Opis problemu jest wymagany.';
    if (!isset(HD_CATEGORIES[$category])) $category = 'it_inne';
    if (!isset(HD_PRIORITIES[$priority])) $priority = 'normalny';

    if (!$errors) {
        $number = hd_next_number();

        $ticket_id = db_insert('helpdesk_tickets', [
            'number'         => $number,
            'title'          => $title,
            'description'    => $description,
            'category'       => $category,
            'priority'       => $priority,
            'status'         => 'nowe',
            'requester_id'   => $uid,
            'requester_name' => $req_name ?: ($u['name'] ?? ''),
            'requester_email'=> $req_email ?: ($u['email'] ?? ''),
            'requester_phone'=> $req_phone ?: null,
            'source'         => 'portal',
        ]);

        // Pierwsza wiadomość = opis
        db_insert('helpdesk_messages', [
            'ticket_id'   => $ticket_id,
            'user_id'     => $uid,
            'user_name'   => $req_name ?: ($u['name'] ?? ''),
            'body'        => $description,
            'is_internal' => 0,
        ]);

        // Załączniki
        if (!empty($_FILES['attachments']['name'][0])) {
            $dir = UPLOAD_DIR . 'helpdesk/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            foreach ($_FILES['attachments']['name'] as $i => $orig_name) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext   = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
                if (!in_array($ext, $allow, true)) continue;
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dir . $stored)) {
                    db_insert('helpdesk_attachments', [
                        'ticket_id'     => $ticket_id,
                        'original_name' => $orig_name,
                        'stored_path'   => 'helpdesk/' . $stored,
                        'file_size'     => $_FILES['attachments']['size'][$i],
                        'uploaded_by'   => $uid,
                    ]);
                }
            }
        }

        // E-mail potwierdzający do zgłaszającego
        $email_dest = $req_email ?: ($u['email'] ?? '');
        if ($email_dest && filter_var($email_dest, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $org     = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
                $url     = APP_URL . '/helpdesk/view.php?id=' . $ticket_id;
                $track   = h(hd_track_url(['id' => $ticket_id, 'access_token' => '']));
                $num_h   = h($number);
                $title_h = h($title);
                $name_h  = h($req_name ?: ($u['name'] ?? ''));
                $cat_h   = h(HD_CATEGORIES[$category] ?? $category);
                $pri_h   = h(HD_PRIORITIES[$priority]['label'] ?? $priority);
                $desc_h  = nl2br(h($description));
                $html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1e40af;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">✅ Potwierdzenie zgłoszenia — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name_h}</strong>!</p>
  <p>Twoje zgłoszenie zostało zarejestrowane w systemie Helpdesk IT.</p>
  <table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:.9em">
    <tr><td style="padding:5px 10px;color:#6c757d;width:130px">Numer</td><td style="padding:5px 10px"><strong style="font-family:monospace">{$num_h}</strong></td></tr>
    <tr style="background:#f8f9fa"><td style="padding:5px 10px;color:#6c757d">Temat</td><td style="padding:5px 10px">{$title_h}</td></tr>
    <tr><td style="padding:5px 10px;color:#6c757d">Kategoria</td><td style="padding:5px 10px">{$cat_h}</td></tr>
    <tr style="background:#f8f9fa"><td style="padding:5px 10px;color:#6c757d">Priorytet</td><td style="padding:5px 10px">{$pri_h}</td></tr>
  </table>
  <p style="font-size:.9em;color:#6c757d">Twój opis:</p>
  <div style="background:#f8f9fa;padding:12px 16px;border-radius:6px;font-size:.9em">{$desc_h}</div>
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#1e40af;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Śledź status →
    </a>
  </div>
  <p style="font-size:.85em;color:#6c757d;text-align:center">
    Bez logowania możesz śledzić i kontynuować zgłoszenie pod adresem:<br>
    <a href="{$track}" style="color:#1e40af">{$track}</a>
  </p>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · Helpdesk IT · odpowiedź zostanie wysłana na ten adres
  </p>
</div></body></html>
HTML;
                mail_queue_add($email_dest, $req_name ?: ($u['name'] ?? ''),
                    "[{$number}] Zgłoszenie przyjęte", $html);
            } catch (\Throwable $e) {}
        }

        // Powiadom operatorów o nowym zgłoszeniu
        try {
            $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL AND email != ''");
            $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
            $url = APP_URL . '/helpdesk/view.php?id=' . $ticket_id;
            foreach ($ops as $op) {
                if (empty($op['email'])) continue;
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $opname = h($op['name'] ?? '');
                $num_h  = h($number);
                $th     = h($title);
                mail_queue_add($op['email'], $op['name'] ?? '',
                    "[{$number}] Nowe zgłoszenie IT: {$title}",
                    <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#dc2626;padding:18px 22px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🎫 Nowe zgłoszenie Helpdesk — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:22px;border-radius:0 0 8px 8px">
  <p>Nowe zgłoszenie IT oczekuje na przypisanie:</p>
  <p><strong style="font-family:monospace">{$num_h}</strong> — {$th}</p>
  <div style="margin:16px 0;text-align:center">
    <a href="{$url}" style="background:#dc2626;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Przypisz i odpowiedz →
    </a>
  </div>
</div></body></html>
HTML
                );
            }
        } catch (\Throwable $e) {}

        flash_set('success', "Zgłoszenie {$number} zostało zarejestrowane. Potwierdzenie zostało wysłane na Twój adres e-mail.");
        header('Location: ' . APP_URL . '/helpdesk/view.php?id=' . $ticket_id);
        exit;
    }
}

$PAGE_TITLE = 'Nowe zgłoszenie IT — Helpdesk';
include dirname(__DIR__) . '/includes/header.php';

// Prefill z danych użytkownika
$pref_name  = $u['name'] ?? '';
$pref_email = $u['email'] ?? '';
$pref_phone = '';
try {
    $pu = db_one("SELECT telefon FROM umowy_wolontariat WHERE email=? AND status NOT IN ('zakończona','anulowana','rozwiązana') ORDER BY id DESC LIMIT 1", [$pref_email]);
    if ($pu && !empty($pu['telefon'])) $pref_phone = $pu['telefon'];
} catch (\Throwable $e) {}
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0 fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Nowe zgłoszenie IT</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-8">

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- Temat -->
      <div class="mb-3">
        <label class="form-label fw-semibold">Temat zgłoszenia <span class="text-danger">*</span></label>
        <input name="title" class="form-control" required maxlength="200"
               placeholder="Krótki opis problemu, np. Nie mogę zalogować się do VPN"
               value="<?= h($_POST['title'] ?? '') ?>">
      </div>

      <!-- Kategoria + Priorytet -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Kategoria</label>
          <select name="category" class="form-select">
            <?php foreach (HD_CATEGORIES as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['category'] ?? 'it_inne') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Pilność</label>
          <div class="d-flex gap-2 flex-wrap mt-1">
            <?php foreach (HD_PRIORITIES as $k => $p): $checked = ($_POST['priority'] ?? 'normalny') === $k; ?>
            <label class="btn btn-sm btn-outline-<?= $p['class'] ?> <?= $checked ? 'active' : '' ?>"
                   style="font-size:.82rem">
              <input type="radio" name="priority" value="<?= h($k) ?>" class="d-none" <?= $checked ? 'checked' : '' ?>>
              <?= h($p['label']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Opis -->
      <div class="mb-3">
        <label class="form-label fw-semibold">Opis problemu <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="6" required
                  placeholder="Opisz dokładnie co się dzieje, kiedy problem wystąpił, jakie komunikaty błędu widzisz, co już próbowałeś/aś zrobić..."><?= h($_POST['description'] ?? '') ?></textarea>
        <div class="form-text">Im więcej szczegółów, tym szybciej rozwiążemy problem.</div>
      </div>

      <!-- Załączniki -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Załączniki <span class="text-muted fw-normal">(zrzuty ekranu, logi)</span></label>
        <input name="attachments[]" type="file" class="form-control" multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
        <div class="form-text">Maks. 10 MB / plik. Dozwolone: PDF, Word, Excel, obrazy, ZIP, TXT.</div>
      </div>

      <hr class="my-4">

      <!-- Dane kontaktowe -->
      <div class="mb-1">
        <span class="fw-semibold">Dane kontaktowe</span>
        <span class="text-muted small ms-2">(wypełnione automatycznie z Twojego profilu)</span>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-sm-5">
          <label class="form-label small">Imię i nazwisko</label>
          <input name="req_name" class="form-control form-control-sm" value="<?= h($_POST['req_name'] ?? $pref_name) ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label small">E-mail</label>
          <input name="req_email" type="email" class="form-control form-control-sm" value="<?= h($_POST['req_email'] ?? $pref_email) ?>">
        </div>
        <div class="col-sm-3">
          <label class="form-label small">Telefon <span class="text-muted">(opcjonalnie)</span></label>
          <input name="req_phone" class="form-control form-control-sm" value="<?= h($_POST['req_phone'] ?? $pref_phone) ?>" placeholder="48XXXXXXXXX">
        </div>
      </div>

      <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-send me-1"></i>Wyślij zgłoszenie
      </button>
      <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
    </form>
  </div>
</div>

</div><!-- /col-8 -->
<div class="col-lg-4">
  <div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold bg-light py-2" style="font-size:.85rem">
      <i class="bi bi-info-circle text-primary me-1"></i>Jak opisać problem?
    </div>
    <div class="card-body small">
      <ul class="ps-3 mb-0" style="line-height:1.8">
        <li>Co dokładnie próbowałeś/aś zrobić?</li>
        <li>Kiedy problem wystąpił po raz pierwszy?</li>
        <li>Czy pojawia się zawsze, czy sporadycznie?</li>
        <li>Jaki komunikat błędu widzisz?</li>
        <li>Na jakim urządzeniu / systemie?</li>
      </ul>
    </div>
  </div>
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-header fw-semibold bg-light py-2" style="font-size:.85rem">
      <i class="bi bi-lightning text-warning me-1"></i>Priorytety
    </div>
    <div class="card-body small">
      <?php foreach (HD_PRIORITIES as $k => $p): ?>
      <div class="d-flex align-items-center gap-2 mb-1">
        <?= hd_priority_badge($k) ?>
        <span class="text-muted"><?= match($k) {
          'krytyczny' => 'Całkowity brak możliwości pracy',
          'wysoki'    => 'Poważne utrudnienie pracy',
          'normalny'  => 'Standardowy problem',
          'niski'     => 'Drobna niedogodność',
          default     => ''
        } ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<script>
// Podświetlanie aktywnego przycisku priorytetu
document.querySelectorAll('[name="priority"]').forEach(function(r) {
  r.closest('label').querySelector('input').addEventListener('change', function() {
    document.querySelectorAll('[name="priority"]').forEach(function(x) {
      x.closest('label').classList.remove('active');
    });
    this.closest('label').classList.add('active');
  });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
