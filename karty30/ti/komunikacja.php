<?php
/**
 * karty30/ti/komunikacja.php — Komunikacja TI: wysyłka e-mail / SMS do kursantów.
 * Odbiorcy wg filtra: per grupa (kurs), per prowadzący, per dzień (lekcje tego dnia).
 * Podgląd odbiorców → wysyłka (mail_queue_add + sms_send) + log w k30_ti_comm_log.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
if (!$can_write) { http_response_code(403); die('Brak uprawnień do wysyłki.'); }
$PAGE_TITLE = 'Komunikacja — TI';
$sms_on     = function_exists('sms_is_enabled') ? sms_is_enabled() : false;

// ── Wejście (POST: podgląd lub wysyłka) ──────────────────────────────────────
$mode      = in_array($_POST['mode'] ?? '', ['grupa','prowadzacy','dzien'], true) ? $_POST['mode'] : 'grupa';
$course_ids= array_values(array_filter(array_map('intval', (array)($_POST['course_ids'] ?? []))));
$instr_id  = (int)($_POST['instructor_id'] ?? 0);
$date      = trim($_POST['date'] ?? '');
$ch_email  = !empty($_POST['ch_email']);
$ch_sms    = !empty($_POST['ch_sms']);
$subject   = trim($_POST['subject'] ?? '');
$body      = trim($_POST['body'] ?? '');
$op        = $_POST['_op'] ?? '';

$recipients = [];
$filter_label = '';
$did_preview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $opts = ['course_ids'=>$course_ids, 'instructor_id'=>$instr_id, 'date'=>$date];
    $cids = k30_ti_comm_course_ids($mode, $opts);
    $recipients = k30_ti_comm_recipients($cids);
    $did_preview = true;

    // Etykieta filtra (do logu i podglądu)
    if ($mode === 'grupa') {
        $names = $cids ? array_column(db_all("SELECT name FROM k30_ti_courses WHERE id IN (".implode(',',array_fill(0,count($cids),'?')).")", $cids), 'name') : [];
        $filter_label = 'Grupy: ' . (implode(', ', $names) ?: '—');
    } elseif ($mode === 'prowadzacy') {
        $in = $instr_id ? db_one("SELECT name FROM users WHERE id=?", [$instr_id]) : null;
        $filter_label = 'Prowadzący: ' . ($in['name'] ?? '—');
    } else {
        $filter_label = 'Dzień: ' . ($date ?: '—');
    }

    if ($op === 'send') {
        $errs = [];
        if (!$ch_email && !$ch_sms) $errs[] = 'Wybierz kanał: e-mail i/lub SMS.';
        if ($ch_sms && !$sms_on)    $errs[] = 'SMS jest wyłączony w ustawieniach systemu.';
        if ($body === '')           $errs[] = 'Wpisz treść wiadomości.';
        if ($ch_email && $subject === '') $errs[] = 'Podaj temat wiadomości e-mail.';
        if (!$recipients)           $errs[] = 'Brak odbiorców dla wybranego filtra.';

        if ($errs) {
            foreach ($errs as $e) flash_set('danger', $e);
        } else {
            $ok = 0; $fail = 0;
            $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#0f172a">'
                  . nl2br(h($body)) . '</div>';
            foreach ($recipients as $r) {
                if ($ch_email && trim((string)$r['email']) !== '') {
                    try { mail_queue_add(trim($r['email']), $r['name'] ?? '', $subject, $html, $body, 'ti_komunikacja', null, '', false); $ok++; }
                    catch (\Throwable $e) { $fail++; }
                }
                if ($ch_sms && $sms_on && trim((string)$r['phone']) !== '') {
                    try { sms_send(trim($r['phone']), $body); $ok++; }
                    catch (\Throwable $e) { $fail++; }
                }
            }
            $ch = trim(($ch_email ? 'email' : '') . ($ch_email && $ch_sms ? '+' : '') . ($ch_sms ? 'sms' : ''));
            db_insert('k30_ti_comm_log', [
                'channel'=>$ch, 'filter_type'=>$mode, 'filter_label'=>$filter_label,
                'subject'=>$subject, 'body'=>$body, 'recipients'=>count($recipients),
                'sent_ok'=>$ok, 'sent_fail'=>$fail, 'created_by'=>current_user()['id'] ?? null,
            ]);
            flash_set($fail ? 'warning' : 'success',
                'Wysłano wiadomości: ' . $ok . ($fail ? (', błędów: ' . $fail) : '') . ' (odbiorców: ' . count($recipients) . ').');
            header('Location: komunikacja.php'); exit;
        }
    }
}

$courses     = k30_ti_courses(false);
$instructors = k30_ti_instructors();
$log         = db_all("SELECT l.*, u.name AS by_name FROM k30_ti_comm_log l LEFT JOIN users u ON u.id=l.created_by ORDER BY l.id DESC LIMIT 10");

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Komunikacja</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-megaphone text-primary me-2"></i>Komunikacja — e-mail / SMS</h4>
</div>

<?= flash_html() ?>

<?php if (!$sms_on): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Kanał SMS jest wyłączony w ustawieniach systemu — dostępna jest tylko poczta e-mail.</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <div class="row g-4">
    <!-- Konfiguracja wysyłki -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold"><i class="bi bi-people me-2" aria-hidden="true"></i>Odbiorcy</div>
        <div class="card-body">
          <fieldset class="mb-3">
            <legend class="form-label fw-semibold">Filtr odbiorców</legend>
            <div class="form-check">
              <input class="form-check-input comm-mode" type="radio" name="mode" id="m_grupa" value="grupa" <?= $mode==='grupa'?'checked':'' ?>>
              <label class="form-check-label" for="m_grupa">Per grupa (kurs)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input comm-mode" type="radio" name="mode" id="m_prow" value="prowadzacy" <?= $mode==='prowadzacy'?'checked':'' ?>>
              <label class="form-check-label" for="m_prow">Per prowadzący</label>
            </div>
            <div class="form-check">
              <input class="form-check-input comm-mode" type="radio" name="mode" id="m_dzien" value="dzien" <?= $mode==='dzien'?'checked':'' ?>>
              <label class="form-check-label" for="m_dzien">Per dzień (lekcje danego dnia)</label>
            </div>
          </fieldset>

          <div class="comm-pane" data-mode="grupa" <?= $mode!=='grupa'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="course_ids">Grupy / kursy <span class="text-muted small">(wiele — Ctrl/Cmd)</span></label>
            <select class="form-select" id="course_ids" name="course_ids[]" multiple size="8" aria-label="Wybór kursów">
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'],$course_ids,true)?'selected':'' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="comm-pane" data-mode="prowadzacy" <?= $mode!=='prowadzacy'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="instructor_id">Prowadzący</label>
            <select class="form-select" id="instructor_id" name="instructor_id" aria-label="Wybór prowadzącego">
              <option value="">— wybierz —</option>
              <?php foreach ($instructors as $i): ?>
              <option value="<?= (int)$i['id'] ?>" <?= $instr_id===(int)$i['id']?'selected':'' ?>><?= h($i['name'] ?: $i['email']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Wiadomość trafi do kursantów ze wszystkich kursów tego prowadzącego.</div>
          </div>

          <div class="comm-pane" data-mode="dzien" <?= $mode!=='dzien'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="date">Dzień zajęć</label>
            <input type="date" class="form-control" id="date" name="date" value="<?= h($date) ?>" style="max-width:14rem">
            <div class="form-text">Wiadomość trafi do kursantów z kursów, które mają lekcję tego dnia.</div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header fw-semibold"><i class="bi bi-send me-2" aria-hidden="true"></i>Wiadomość</div>
        <div class="card-body">
          <fieldset class="mb-2">
            <legend class="form-label fw-semibold">Kanał</legend>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="ch_email" id="ch_email" value="1" <?= $ch_email||!$did_preview?'checked':'' ?>>
              <label class="form-check-label" for="ch_email"><i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="ch_sms" id="ch_sms" value="1" <?= $ch_sms?'checked':'' ?> <?= !$sms_on?'disabled':'' ?>>
              <label class="form-check-label" for="ch_sms"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS<?= !$sms_on?' (wyłączony)':'' ?></label>
            </div>
          </fieldset>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="subject">Temat <span class="text-muted small">(e-mail)</span></label>
            <input type="text" class="form-control" id="subject" name="subject" value="<?= h($subject) ?>" maxlength="200" placeholder="np. Zmiana terminu zajęć">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="body">Treść <span class="text-danger" aria-hidden="true">*</span></label>
            <textarea class="form-control" id="body" name="body" rows="5" required placeholder="Treść wiadomości (dla SMS bez formatowania)"><?= h($body) ?></textarea>
            <div class="form-text">Ta sama treść trafi do e-maila i SMS. SMS bez formatowania — pisz zwięźle.</div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" name="_op" value="preview" class="btn btn-outline-primary"><i class="bi bi-people me-1" aria-hidden="true"></i>Pokaż odbiorców</button>
            <button type="submit" name="_op" value="send" class="btn btn-primary" <?= $did_preview && $recipients ? '' : 'disabled' ?>
                    onclick="return confirm('Wysłać wiadomość do <?= count($recipients) ?> odbiorców?')">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
            </button>
          </div>
          <?php if (!$did_preview): ?><div class="form-text mt-1">Najpierw „Pokaż odbiorców", aby odblokować wysyłkę.</div><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Podgląd odbiorców -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold d-flex align-items-center">
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Odbiorcy
          <?php if ($did_preview): ?><span class="badge bg-secondary ms-2"><?= count($recipients) ?></span><?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$did_preview): ?>
          <p class="text-muted mb-0">Wybierz filtr i kliknij „Pokaż odbiorców".</p>
          <?php elseif (!$recipients): ?>
          <p class="text-muted mb-0">Brak odbiorców dla wybranego filtra (<?= h($filter_label) ?>).</p>
          <?php else:
            $no_email = count(array_filter($recipients, fn($r)=>trim((string)$r['email'])===''));
            $no_phone = count(array_filter($recipients, fn($r)=>trim((string)$r['phone'])===''));
          ?>
          <div class="small text-muted mb-2"><?= h($filter_label) ?></div>
          <?php if ($ch_email && $no_email): ?><div class="small text-warning-emphasis mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= $no_email ?> bez adresu e-mail — nie otrzyma maila.</div><?php endif; ?>
          <?php if ($ch_sms && $no_phone): ?><div class="small text-warning-emphasis mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= $no_phone ?> bez numeru telefonu — nie otrzyma SMS.</div><?php endif; ?>
          <div class="table-responsive" style="max-height:24rem;overflow:auto">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light"><tr><th scope="col">Kursant</th><th scope="col">E-mail</th><th scope="col">Telefon</th></tr></thead>
              <tbody>
                <?php foreach ($recipients as $r): ?>
                <tr>
                  <td class="fw-semibold"><?= h($r['name']) ?></td>
                  <td class="small"><?= trim((string)$r['email'])!=='' ? h($r['email']) : '<span class="text-muted">—</span>' ?></td>
                  <td class="small"><?= trim((string)$r['phone'])!=='' ? h($r['phone']) : '<span class="text-muted">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Historia wysyłek -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Ostatnie wysyłki</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.88rem">
      <thead class="table-light"><tr><th>Data</th><th>Kanał</th><th>Filtr</th><th>Temat</th><th class="text-end">Odb.</th><th class="text-end">OK</th><th class="text-end">Błędy</th><th>Przez</th></tr></thead>
      <tbody>
        <?php if (!$log): ?><tr><td colspan="8" class="text-center text-muted py-3">Brak wysyłek.</td></tr><?php endif; ?>
        <?php foreach ($log as $l): ?>
        <tr>
          <td class="text-nowrap small"><?= h(substr($l['created_at'],0,16)) ?></td>
          <td class="small"><?= h($l['channel']) ?></td>
          <td class="small"><?= h($l['filter_label']) ?></td>
          <td class="small"><?= $l['subject']!=='' ? h(mb_strimwidth($l['subject'],0,40,'…','UTF-8')) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><?= (int)$l['recipients'] ?></td>
          <td class="text-end text-success"><?= (int)$l['sent_ok'] ?></td>
          <td class="text-end <?= (int)$l['sent_fail']?'text-danger':'' ?>"><?= (int)$l['sent_fail'] ?></td>
          <td class="small"><?= h($l['by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  function sync(){
    var mode = (document.querySelector('.comm-mode:checked')||{}).value || 'grupa';
    document.querySelectorAll('.comm-pane').forEach(function(p){ p.hidden = (p.getAttribute('data-mode') !== mode); });
  }
  document.querySelectorAll('.comm-mode').forEach(function(r){ r.addEventListener('change', sync); });
  sync();
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
