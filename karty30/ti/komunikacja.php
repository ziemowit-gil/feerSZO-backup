<?php
/**
 * karty30/ti/komunikacja.php — Komunikacja TI: wysyłka e-mail / SMS do kursantów
 * (opcjonalnie też do rodziców/opiekunów małoletnich — kontakt opiekuna z konta kursanta).
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
$ch_guard  = !empty($_POST['ch_guardians']);
$subject   = trim($_POST['subject'] ?? '');
$body      = trim($_POST['body'] ?? '');
$body_html = trim($_POST['body_html'] ?? '');
$op        = $_POST['_op'] ?? '';

$recipients = [];
$filter_label = '';
$did_preview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $opts = ['course_ids'=>$course_ids, 'instructor_id'=>$instr_id, 'date'=>$date];
    $cids = k30_ti_comm_course_ids($mode, $opts);
    $recipients = k30_ti_comm_recipients($cids);
    if ($ch_guard) {
        $recipients = array_merge($recipients, k30_ti_comm_guardian_recipients($cids));
        // Ten sam kontakt (np. e-mail rodzica bywa tożsamy z e-mailem klienta) — wyślij raz,
        // żeby ta sama osoba nie dostała identycznej wiadomości dwukrotnie.
        $seen = [];
        $recipients = array_values(array_filter($recipients, function($r) use (&$seen) {
            $key = trim(mb_strtolower((string)$r['email'])) ?: trim((string)$r['phone']);
            if ($key === '' || isset($seen[$key])) return $key === '';
            $seen[$key] = true;
            return true;
        }));
    }
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
    if ($ch_guard) $filter_label .= ' + rodzice/opiekunowie';

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
            $email_inner = $body_html !== ''
                ? $body_html
                : '<div>' . nl2br(h($body)) . '</div>';
            $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#0f172a">'
                  . $email_inner . '</div>';
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
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
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

          <hr class="my-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ch_guardians" id="ch_guardians" value="1" <?= $ch_guard?'checked':'' ?>>
            <label class="form-check-label" for="ch_guardians">
              <i class="bi bi-people-fill me-1" aria-hidden="true"></i>Uwzględnij też rodziców / opiekunów małoletnich kursantów
            </label>
            <div class="form-text">Dodaje do odbiorców kontakt opiekuna (e-mail/telefon) każdego małoletniego kursanta z wybranego filtra — niezależnie od wysyłki do samego kursanta.</div>
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
          <!-- ── Szablony wiadomości ─────────────────────────────────────────── -->
          <div class="mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start d-flex align-items-center"
                    data-bs-toggle="collapse" data-bs-target="#comm-templates" aria-expanded="false" aria-controls="comm-templates">
              <i class="bi bi-journal-text me-2" aria-hidden="true"></i>Szablony wiadomości
              <i class="bi bi-chevron-down ms-auto comm-tmpl-chevron"></i>
            </button>
            <div class="collapse" id="comm-templates">
              <div class="card border-0 bg-body-secondary mt-1 p-3">
                <p class="fw-semibold mb-2 small">Informacja o logowaniu do panelu TI</p>
                <div class="mb-2">
                  <label class="form-label small mb-1" for="tmpl-zoom-link">Link Zoom (awaryjny) — opcjonalny</label>
                  <input type="text" class="form-control form-control-sm" id="tmpl-zoom-link"
                         placeholder="https://us06web.zoom.us/j/...">
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="tmpl-login-info-btn">
                  <i class="bi bi-magic me-1" aria-hidden="true"></i>Wstaw do edytora
                </button>
                <div class="form-text mt-1">Uzupełnia temat i treść — edytuj przed wysyłką według potrzeb.</div>
              </div>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold" for="subject">Temat <span class="text-muted small">(e-mail)</span></label>
            <input type="text" class="form-control" id="subject" name="subject" value="<?= h($subject) ?>" maxlength="200" placeholder="np. Zmiana terminu zajęć">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="comm-quill-editor">Treść <span class="text-danger" aria-hidden="true">*</span></label>
            <div id="comm-quill-editor" style="min-height:9rem;background:#fff;color:#0f172a;border:1px solid var(--bs-border-color);border-radius:.375rem"></div>
            <textarea id="body" name="body" class="d-none" aria-hidden="true"><?= h($body) ?></textarea>
            <input type="hidden" name="body_html" id="body_html" value="<?= h($body_html) ?>">
            <div class="form-text mt-1" id="comm-sms-note" style="display:none"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS używa wersji tekstowej (bez formatowania).</div>
            <div class="form-text" id="comm-email-note">Formatowanie zostanie zachowane w e-mailu.</div>
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
              <thead class="table-light"><tr><th scope="col">Odbiorca</th><?php if ($ch_guard): ?><th scope="col">Rola</th><?php endif; ?><th scope="col">E-mail</th><th scope="col">Telefon</th></tr></thead>
              <tbody>
                <?php foreach ($recipients as $r): ?>
                <tr>
                  <td class="fw-semibold"><?= h($r['name']) ?></td>
                  <?php if ($ch_guard): ?>
                  <td class="small"><?= ($r['role'] ?? 'kursant') === 'opiekun' ? '<span class="badge text-bg-info">Opiekun</span>' : '<span class="badge text-bg-secondary">Kursant</span>' ?></td>
                  <?php endif; ?>
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

<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function(){
  // Filtr trybu odbiorców
  function syncMode(){
    var mode = (document.querySelector('.comm-mode:checked')||{}).value || 'grupa';
    document.querySelectorAll('.comm-pane').forEach(function(p){ p.hidden = (p.getAttribute('data-mode') !== mode); });
  }
  document.querySelectorAll('.comm-mode').forEach(function(r){ r.addEventListener('change', syncMode); });
  syncMode();

  // Quill WYSIWYG
  var quill = new Quill('#comm-quill-editor', {
    theme: 'snow',
    placeholder: 'Treść wiadomości…',
    modules: { toolbar: [
      ['bold','italic','underline'],
      [{ list:'ordered' },{ list:'bullet' }],
      ['link'],
      ['clean']
    ]}
  });

  // Wczytaj zapisaną treść (błąd walidacji lub edycja po podglądzie)
  var initHtml = document.getElementById('body_html').value;
  var initText = document.getElementById('body').value;
  if (initHtml) {
    quill.root.innerHTML = initHtml;
  } else if (initText) {
    quill.setText(initText);
  }

  // Pokaż informację o SMS gdy zaznaczony
  function syncSmsNote(){
    var smsOn = document.getElementById('ch_sms') && document.getElementById('ch_sms').checked;
    var n = document.getElementById('comm-sms-note');
    var e = document.getElementById('comm-email-note');
    if (n) n.style.display = smsOn ? '' : 'none';
    if (e) e.style.display = smsOn ? 'none' : '';
  }
  var smsBox = document.getElementById('ch_sms');
  if (smsBox) smsBox.addEventListener('change', syncSmsNote);
  syncSmsNote();

  // Przed wysyłką formularza: wypełnij ukryte pola
  var form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function(){
      document.getElementById('body_html').value = quill.root.innerHTML;
      document.getElementById('body').value = quill.getText().trim();
    });
  }

  // ── Szablon: informacja o logowaniu ──────────────────────────────────────
  var tmplBtn = document.getElementById('tmpl-login-info-btn');
  if (tmplBtn) {
    tmplBtn.addEventListener('click', function(){
      var zoom = (document.getElementById('tmpl-zoom-link').value || '').trim();
      var zoomBlock = zoom
        ? '<p>🔗 <a href="' + zoom + '">' + zoom + '</a></p>'
        : '<p>🔗 <em>[Wklej tutaj link do spotkania Zoom]</em></p>';

      document.getElementById('subject').value = 'Informacja o logowaniu do panelu TI';

      quill.root.innerHTML = [
        '<p>Dzień dobry,</p>',
        '<p>Do zajęć logujemy się przez panel kursanta dostępny pod adresem',
        ' <a href="https://ti.feer.org.pl">ti.feer.org.pl</a>.',
        ' To podstawowy adres, z którego należy korzystać na co dzień.</p>',
        '<p>Gdyby panel kursanta był chwilowo niedostępny, prosimy o skorzystanie',
        ' z bezpośrednich adresów logowania:</p>',
        '<p>👨‍🏫 Dla dydaktyka:<br>',
        '<a href="https://szo.feer.org.pl/karty30/ti/login.php?tab=dydaktyk">',
        'https://szo.feer.org.pl/karty30/ti/login.php?tab=dydaktyk</a></p>',
        '<p>🎓 Dla kursanta:<br>',
        '<a href="https://szo.feer.org.pl/karty30/ti/login.php?tab=kursant">',
        'https://szo.feer.org.pl/karty30/ti/login.php?tab=kursant</a></p>',
        '<p>W przypadku problemów technicznych uniemożliwiających logowanie,',
        ' udostępniamy również awaryjny link do spotkania w aplikacji Zoom:</p>',
        zoomBlock,
        '<p>Prosimy o zachowanie powyższych odnośników na wypadek ewentualnych',
        ' trudności technicznych.</p>',
        '<p>Z poważaniem,<br>Zespół Fundacji FEER</p>'
      ].join('');

      // zwiń sekcję szablonów
      var collapseEl = document.getElementById('comm-templates');
      if (collapseEl && collapseEl.classList.contains('show')) {
        bootstrap.Collapse.getOrCreateInstance(collapseEl).hide();
      }
    });

    // chevron animacja
    var tmplCollapse = document.getElementById('comm-templates');
    if (tmplCollapse) {
      tmplCollapse.addEventListener('show.bs.collapse', function(){
        document.querySelector('.comm-tmpl-chevron').style.transform = 'rotate(180deg)';
      });
      tmplCollapse.addEventListener('hide.bs.collapse', function(){
        document.querySelector('.comm-tmpl-chevron').style.transform = '';
      });
    }
  }
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
