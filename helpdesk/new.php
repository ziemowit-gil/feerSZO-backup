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
        $number = hd_next_number(hd_number_prefix_for($category));

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

        hd_redmine_sync_ticket($ticket_id); // integracja Redmine (best-effort)

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
echo hd_ui_css();

// Prefill z danych użytkownika
$pref_name  = $u['name'] ?? '';
$pref_email = $u['email'] ?? '';
$pref_phone = '';
try {
    $pu = db_one("SELECT telefon FROM umowy_wolontariat WHERE email=? AND status NOT IN ('zakończona','anulowana','rozwiązana') ORDER BY id DESC LIMIT 1", [$pref_email]);
    if ($pu && !empty($pu['telefon'])) $pref_phone = $pu['telefon'];
} catch (\Throwable $e) {}
?>
<style>
.hd-new-hero {
  display: flex; align-items: center; gap: 1rem;
  padding: 1.1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem;
  background: linear-gradient(120deg, var(--hd-accent) 0%, #7c3aed 100%); color: #fff;
  box-shadow: 0 4px 24px rgba(79,70,229,.25);
}
.hd-new-hero-icon {
  width: 46px; height: 46px; border-radius: 50%;
  background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center;
  font-size: 1.35rem; flex-shrink: 0;
}
.hd-new-card {
  background: var(--hd-panel); border: 1px solid var(--hd-bd); border-radius: 12px;
  box-shadow: var(--hd-shadow);
}
.hd-new-card-body { padding: 1.25rem 1.4rem; }
.hd-new-sec-head {
  font-size: .75rem; text-transform: uppercase; letter-spacing: .07em;
  color: var(--hd-tx3); font-weight: 700; margin-bottom: .65rem;
}
.hd-pri-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .32rem .75rem; border-radius: 8px; border: 1.5px solid var(--hd-bd);
  font-size: .8rem; cursor: pointer; background: var(--hd-panel); color: var(--hd-tx2);
  transition: all .12s; user-select: none;
}
.hd-pri-btn.active-pri { border-color: var(--hd-accent); background: var(--hd-accent-l); color: var(--hd-accent); font-weight: 600; }
.hd-info-card {
  background: var(--hd-panel); border: 1px solid var(--hd-bd); border-radius: 10px;
  overflow: hidden; margin-bottom: .75rem;
}
.hd-info-card-head { padding: .5rem .85rem; font-size: .78rem; font-weight: 700; background: var(--hd-bg); border-bottom: 1px solid var(--hd-bd-l); }
.hd-info-card-body { padding: .7rem .85rem; font-size: .8rem; }
</style>

<!-- Hero -->
<div class="hd-new-hero">
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:none" aria-label="Wróć do konsoli">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div class="hd-new-hero-icon"><i class="bi bi-plus-circle"></i></div>
  <div>
    <div style="font-weight:800;font-size:1.05rem">Nowe zgłoszenie IT</div>
    <div style="font-size:.82rem;opacity:.8">Opisz problem — zajmiemy się nim zgodnie z priorytetem SLA.</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2 mb-3"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li style="font-size:.85rem">' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-8">

<div class="hd-new-card">
  <div class="hd-new-card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- Temat -->
      <div class="mb-3">
        <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Temat zgłoszenia <span class="text-danger">*</span></label>
        <input name="title" class="form-control" required maxlength="200"
               placeholder="Krótki opis problemu, np. Nie mogę zalogować się do VPN"
               value="<?= h($_POST['title'] ?? '') ?>">
      </div>

      <!-- Kategoria + Priorytet -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Kategoria</label>
          <select name="category" class="form-select">
            <?php foreach (HD_CATEGORIES as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['category'] ?? 'it_inne') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Pilność</label>
          <div class="d-flex gap-2 flex-wrap mt-1">
            <?php foreach (HD_PRIORITIES as $k => $p): $checked = ($_POST['priority'] ?? 'normalny') === $k; ?>
            <label class="hd-pri-btn <?= $checked ? 'active-pri' : '' ?>">
              <input type="radio" name="priority" value="<?= h($k) ?>" class="visually-hidden hd-pri-radio" <?= $checked ? 'checked' : '' ?>>
              <?= h($p['label']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Opis -->
      <div class="mb-3">
        <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Opis problemu <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="6" required
                  placeholder="Opisz dokładnie co się dzieje, kiedy problem wystąpił, jakie komunikaty błędu widzisz, co już próbowałeś/aś zrobić..."><?= h($_POST['description'] ?? '') ?></textarea>
        <div class="form-text" style="font-size:.77rem">Im więcej szczegółów, tym szybciej rozwiążemy problem.</div>
      </div>

      <!-- Załączniki -->
      <div class="mb-4">
        <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Załączniki <span style="color:var(--hd-tx3);font-weight:400">(zrzuty ekranu, logi)</span></label>
        <input name="attachments[]" type="file" class="form-control" multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
        <div class="form-text" style="font-size:.77rem">Maks. 10 MB / plik. Dozwolone: PDF, Word, Excel, obrazy, ZIP, TXT.</div>
      </div>

      <div class="border-top mb-3 pt-3" style="border-color:var(--hd-bd-l)!important">
        <div class="hd-new-sec-head">Dane kontaktowe <span style="text-transform:none;font-weight:400;color:var(--hd-tx3);letter-spacing:0">(wypełnione z profilu)</span></div>
        <div class="row g-3">
          <div class="col-sm-5">
            <label class="form-label small mb-1">Imię i nazwisko</label>
            <input name="req_name" class="form-control form-control-sm" value="<?= h($_POST['req_name'] ?? $pref_name) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small mb-1">E-mail</label>
            <input name="req_email" type="email" class="form-control form-control-sm" value="<?= h($_POST['req_email'] ?? $pref_email) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label small mb-1">Telefon <span style="color:var(--hd-tx3)">(opcjon.)</span></label>
            <input name="req_phone" class="form-control form-control-sm" value="<?= h($_POST['req_phone'] ?? $pref_phone) ?>" placeholder="48XXXXXXXXX">
          </div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4" style="background:var(--hd-accent);border-color:var(--hd-accent)">
          <i class="bi bi-send-fill me-1"></i>Wyślij zgłoszenie
        </button>
        <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

</div><!-- /col-8 -->
<div class="col-lg-4">
  <div class="hd-info-card">
    <div class="hd-info-card-head"><i class="bi bi-info-circle me-1" style="color:var(--hd-accent)"></i>Jak opisać problem?</div>
    <div class="hd-info-card-body">
      <ul class="ps-3 mb-0" style="line-height:1.9;color:var(--hd-tx2)">
        <li>Co dokładnie próbowałeś/aś zrobić?</li>
        <li>Kiedy problem wystąpił po raz pierwszy?</li>
        <li>Czy pojawia się zawsze, czy sporadycznie?</li>
        <li>Jaki komunikat błędu widzisz?</li>
        <li>Na jakim urządzeniu / systemie?</li>
      </ul>
    </div>
  </div>
  <div class="hd-info-card">
    <div class="hd-info-card-head"><i class="bi bi-speedometer2 me-1" style="color:#f59e0b"></i>Priorytety SLA</div>
    <div class="hd-info-card-body p-0">
      <?php foreach (HD_PRIORITIES as $k => $p): ?>
      <div class="d-flex align-items-start gap-2 px-3 py-2" style="border-bottom:1px solid var(--hd-bd-l)">
        <?= hd_priority_badge($k) ?>
        <div>
          <div style="font-size:.79rem;color:var(--hd-tx2)"><?= match($k) {
            'krytyczny' => 'Całkowity brak możliwości pracy',
            'wysoki'    => 'Poważne utrudnienie pracy',
            'normalny'  => 'Standardowy problem',
            'niski'     => 'Drobna niedogodność',
            default     => ''
          } ?></div>
          <?php if (!empty($p['sla_response']) || !empty($p['sla_resolve'])): ?>
          <div style="font-size:.72rem;color:var(--hd-tx3)">
            reakcja <?= hd_fmt_secs((int)($p['sla_response'] ?? 0) * 60) ?>
            · rozwiązanie <?= hd_fmt_secs((int)($p['sla_resolve'] ?? 0) * 60) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<script>
document.querySelectorAll('.hd-pri-radio').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('.hd-pri-btn').forEach(function(l) { l.classList.remove('active-pri'); });
    this.closest('.hd-pri-btn').classList.add('active-pri');
  });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
