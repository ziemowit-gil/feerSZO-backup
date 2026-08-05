<?php
/**
 * panel/helpdesk.php — Helpdesk IT w widoku panelu wolontariusza.
 * Wolontariusz może tworzyć i śledzić zgłoszenia bez dostępu do panelu admina.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u   = current_user();
$uid = (int)$u['id'];

// ─── Pobierz dane wolontariusza ───────────────────────────────────────────────
$pref_phone = '';
try {
    $pu = db_one(
        "SELECT telefon FROM umowy_wolontariat
         WHERE email=? AND status NOT IN ('zakończona','anulowana','rozwiązana')
         ORDER BY id DESC LIMIT 1",
        [$u['email'] ?? '']
    );
    if ($pu && !empty($pu['telefon'])) $pref_phone = $pu['telefon'];
} catch (\Throwable $e) {}

$errors = [];

// ─── Obsługa POST: nowe zgłoszenie ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_new_ticket'])) {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = $_POST['category']    ?? 'it_inne';
    $priority    = $_POST['priority']    ?? 'normalny';
    $req_name    = trim($_POST['req_name']    ?? $u['name']  ?? '');
    $req_email   = trim($_POST['req_email']   ?? $u['email'] ?? '');
    $req_phone   = preg_replace('/\D/', '', trim($_POST['req_phone'] ?? ''));

    if (!$title)                          $errors[] = 'Temat jest wymagany.';
    if (!$description)                    $errors[] = 'Opis problemu jest wymagany.';
    if (!isset(HD_CATEGORIES[$category])) $category = 'it_inne';
    if (!isset(HD_PRIORITIES[$priority])) $priority = 'normalny';

    if (!$errors) {
        $number    = hd_next_number(hd_number_prefix_for($category));
        $ticket_id = db_insert('helpdesk_tickets', [
            'number'          => $number,
            'title'           => $title,
            'description'     => $description,
            'category'        => $category,
            'priority'        => $priority,
            'status'          => 'nowe',
            'requester_id'    => $uid,
            'requester_name'  => $req_name  ?: ($u['name']  ?? ''),
            'requester_email' => $req_email ?: ($u['email'] ?? ''),
            'requester_phone' => $req_phone ?: null,
            'source'          => 'panel_wolontariusza',
        ]);

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
            foreach ($_FILES['attachments']['name'] as $i => $orig) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
                if (!in_array($ext, $allow, true)) continue;
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dir . $stored)) {
                    db_insert('helpdesk_attachments', [
                        'ticket_id'     => $ticket_id,
                        'original_name' => $orig,
                        'stored_path'   => 'helpdesk/' . $stored,
                        'file_size'     => $_FILES['attachments']['size'][$i],
                        'uploaded_by'   => $uid,
                    ]);
                }
            }
        }

        // Mail potwierdzający do zgłaszającego
        $email_dest = $req_email ?: ($u['email'] ?? '');
        if ($email_dest && filter_var($email_dest, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $org     = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
                $num_h   = h($number);
                $title_h = h($title);
                $name_h  = h($req_name ?: ($u['name'] ?? ''));
                $url     = APP_URL . '/panel/helpdesk.php#ticket-' . $ticket_id;
                $track   = h(hd_track_url(['id' => $ticket_id, 'access_token' => '']));
                $cat_h   = h(HD_CATEGORIES[$category] ?? $category);
                $pri_h   = h(HD_PRIORITIES[$priority]['label'] ?? $priority);
                $desc_h  = nl2br(h($description));
                mail_queue_add($email_dest, $req_name ?: ($u['name'] ?? ''),
                    "[{$number}] Zgłoszenie przyjęte",
                    <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1e40af;padding:18px 22px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.05rem">✅ Zgłoszenie zarejestrowane — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:22px;border-radius:0 0 8px 8px">
  <p>Cześć, <strong>{$name_h}</strong>!</p>
  <table style="width:100%;border-collapse:collapse;margin:10px 0;font-size:.9em">
    <tr><td style="padding:4px 10px;color:#6c757d;width:120px">Numer</td><td style="padding:4px 10px"><strong style="font-family:monospace">{$num_h}</strong></td></tr>
    <tr style="background:#f8f9fa"><td style="padding:4px 10px;color:#6c757d">Temat</td><td style="padding:4px 10px">{$title_h}</td></tr>
    <tr><td style="padding:4px 10px;color:#6c757d">Kategoria</td><td style="padding:4px 10px">{$cat_h}</td></tr>
  </table>
  <a href="{$url}" style="background:#1e40af;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;margin-top:12px">
    Sprawdź status →
  </a>
  <p style="font-size:.85em;color:#6c757d;margin-top:14px">
    Bez logowania możesz śledzić i kontynuować zgłoszenie tutaj:<br>
    <a href="{$track}" style="color:#1e40af">{$track}</a>
  </p>
</div></body></html>
HTML
                );
            } catch (\Throwable $e) {}
        }

        // Powiadom operatorów
        try {
            $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL");
            $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
            $url_op = APP_URL . '/helpdesk/view.php?id=' . $ticket_id;
            foreach ($ops as $op) {
                if (empty($op['email'])) continue;
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add($op['email'], $op['name'] ?? '',
                    "[{$number}] Nowe zgłoszenie IT: {$title}",
                    '<p><strong style="font-family:monospace">' . h($number) . '</strong> — ' . h($title) . '</p>'
                    . '<p><a href="' . h($url_op) . '">Otwórz zgłoszenie →</a></p>'
                );
            }
        } catch (\Throwable $e) {}

        flash_set('success', "Zgłoszenie <strong>{$number}</strong> zostało zarejestrowane. Odpiszemy wkrótce.");
        header('Location: ' . APP_URL . '/panel/helpdesk.php');
        exit;
    }
}

// ─── Lista moich zgłoszeń ─────────────────────────────────────────────────────
$my_tickets = db_all(
    "SELECT t.*, u.name AS assigned_name
     FROM helpdesk_tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE t.requester_id = ?
     ORDER BY t.updated_at DESC
     LIMIT 50",
    [$uid]
);
$open_count = count(array_filter($my_tickets, fn($t) => !in_array($t['status'], ['zamknięte','rozwiązane'])));

$PAGE_TITLE = 'Helpdesk IT';
include __DIR__ . '/includes/header_panel.php';

// Mapowanie klasy Bootstrap statusu → modyfikator pv-sp-*
$sp_map = [
    'primary'   => 'new',
    'warning'   => 'pending',
    'secondary' => 'closed',
    'danger'    => 'cancelled',
    'dark'      => 'closed',
    'info'      => 'draft',
    'success'   => 'ok',
];
?>

<div class="pv-wrap">

<!-- Nagłówek -->
<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-headset" aria-hidden="true"></i>Helpdesk IT</h1>
    <p class="pv-page-sub">Zgłoś problem lub zapytanie do działu IT</p>
  </div>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="pv-alert pv-alert-err" role="alert">
  <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
  <ul class="mb-0 ps-3">
    <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
  </ul>
</div>
<?php endif; ?>

<!-- ═══ FORMULARZ NOWEGO ZGŁOSZENIA ═════════════════════════════════════════ -->
<details <?= $errors ? 'open' : '' ?> id="hd-form-section" class="tz-card">
<summary class="tz-card__hd" style="list-style:none;cursor:pointer">
  <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
  Nowe zgłoszenie IT
  <i class="bi bi-chevron-down ms-auto" id="hdFormChevron" aria-hidden="true"></i>
</summary>

<div class="tz-card__bd">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
    <input type="hidden" name="_new_ticket" value="1">

    <div class="mb-3">
      <label for="hd-title" class="form-label fw-semibold small">Temat <span class="text-danger" aria-hidden="true">*</span></label>
      <input id="hd-title" name="title" class="form-control form-control-sm" required aria-required="true" maxlength="200"
             placeholder="Krótki opis problemu, np. Nie mogę zalogować się do M365"
             value="<?= h($_POST['title'] ?? '') ?>">
    </div>

    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label for="hd-category" class="form-label fw-semibold small">Kategoria</label>
        <select id="hd-category" name="category" class="form-select form-select-sm">
          <?php foreach (HD_CATEGORIES as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($_POST['category'] ?? 'it_inne') === $k ? 'selected' : '' ?>>
            <?= h($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <fieldset>
          <legend class="form-label fw-semibold small">Pilność</legend>
          <div class="d-flex gap-2 flex-wrap mt-1">
            <?php foreach (HD_PRIORITIES as $k => $p):
              $checked = ($_POST['priority'] ?? 'normalny') === $k; ?>
            <label class="tz-btn tz-btn--ghost<?= $checked ? ' active' : '' ?>">
              <input type="radio" name="priority" value="<?= h($k) ?>" class="d-none" <?= $checked ? 'checked' : '' ?>>
              <?= h($p['label']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      </div>
    </div>

    <div class="mb-3">
      <label for="hd-description" class="form-label fw-semibold small">Opis problemu <span class="text-danger" aria-hidden="true">*</span></label>
      <textarea id="hd-description" name="description" class="form-control form-control-sm" rows="5" required aria-required="true"
                placeholder="Opisz dokładnie co się dzieje, kiedy problem wystąpił, co już próbowałeś/aś..."><?= h($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
      <label for="hd-attachments" class="form-label fw-semibold small">Załączniki (zrzuty ekranu, logi)</label>
      <input id="hd-attachments" name="attachments[]" type="file" class="form-control form-control-sm" multiple
             accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv"
             aria-label="Dodaj załączniki — PDF, Word, Excel, obrazy, ZIP, TXT">
      <div class="form-text">Maks. 10 MB / plik. PDF, Word, Excel, obrazy, ZIP, TXT.</div>
    </div>

    <hr class="my-3">

    <p class="fw-semibold small text-muted mb-2">Twoje dane kontaktowe</p>
    <div class="row g-2 mb-3">
      <div class="col-sm-5">
        <label for="hd-req-name" class="visually-hidden">Imię i nazwisko</label>
        <input id="hd-req-name" name="req_name" class="form-control form-control-sm" placeholder="Imię i nazwisko"
               value="<?= h($_POST['req_name'] ?? $u['name'] ?? '') ?>">
      </div>
      <div class="col-sm-4">
        <label for="hd-req-email" class="visually-hidden">E-mail</label>
        <input id="hd-req-email" name="req_email" type="email" class="form-control form-control-sm" placeholder="E-mail"
               value="<?= h($_POST['req_email'] ?? $u['email'] ?? '') ?>">
      </div>
      <div class="col-sm-3">
        <label for="hd-req-phone" class="visually-hidden">Telefon</label>
        <input id="hd-req-phone" name="req_phone" class="form-control form-control-sm" placeholder="Telefon (opcj.)"
               value="<?= h($_POST['req_phone'] ?? $pref_phone) ?>">
      </div>
    </div>

    <button type="submit" class="tz-btn">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij zgłoszenie
    </button>
  </form>
</div>
</details>

<!-- ═══ LISTA MOICH ZGŁOSZEŃ ════════════════════════════════════════════════ -->
<div class="vol-activity">
  <div class="vol-activity-header">
    <h2 class="vol-activity-title">
      <i class="bi bi-list-check me-1" aria-hidden="true"></i>Moje zgłoszenia
    </h2>
    <?php if ($open_count): ?>
    <span class="tz-badge"><?= $open_count ?> otwartych</span>
    <?php endif; ?>
  </div>

  <?php if (!$my_tickets): ?>
  <div class="tz-card__bd">
    <div id="ticket-0" class="tz-empty">
      <div class="tz-empty__icon"><i class="bi bi-headset fs-1" aria-hidden="true"></i></div>
      <p class="tz-empty__text">Brak zgłoszeń</p>
    </div>
  </div>
  <?php else: ?>
  <?php foreach ($my_tickets as $t):
    $s      = HD_STATUSES[$t['status']] ?? ['label'=>$t['status'],'class'=>'secondary','icon'=>'bi-circle'];
    $p      = HD_PRIORITIES[$t['priority']] ?? ['label'=>$t['priority'],'class'=>'secondary'];
    $closed = in_array($t['status'], ['zamknięte','rozwiązane']);
    $sp     = $sp_map[$s['class']] ?? 'closed';
  ?>
  <div id="ticket-<?= $t['id'] ?>" class="vol-activity-row<?= $closed ? ' opacity-75' : '' ?>">

    <div class="vol-activity-icon" aria-hidden="true">
      <i class="bi <?= h($s['icon']) ?>"></i>
    </div>

    <div class="flex-grow-1" style="min-width:0">
      <div class="fw-bold small text-truncate"><?= h($t['title']) ?></div>
      <div class="small text-muted mt-1">
        <span class="font-monospace"><?= h($t['number']) ?></span>
        &middot; <?= h(HD_CATEGORIES[$t['category']] ?? $t['category']) ?>
        &middot; <?= date('d.m.Y', strtotime($t['updated_at'])) ?>
        <?php if (!empty($t['assigned_name'])): ?>
        &middot; Przypisany: <?= h($t['assigned_name']) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
      <span class="pv-status-pill pv-sp-<?= h($sp) ?>"><?= h($s['label']) ?></span>
      <span class="tz-badge"><?= h($p['label']) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="text-muted small p-2 text-end"><?= count($my_tickets) ?> zgłoszeń łącznie</div>
  <?php endif; ?>
</div>

</div><!-- /.pv-wrap -->

<?php include __DIR__ . '/includes/footer_panel.php'; ?>

<script>
// Obróć chevron przy <details>
(function(){
  var det = document.getElementById('hd-form-section');
  var chv = document.getElementById('hdFormChevron');
  if (!det || !chv) return;
  det.addEventListener('toggle', function(){
    chv.style.transform = det.open ? 'rotate(180deg)' : '';
  });
})();
</script>
