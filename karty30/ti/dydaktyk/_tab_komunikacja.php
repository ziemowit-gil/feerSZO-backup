<?php /* ═══════════════════════ TAB: KOMUNIKACJA ═══════════════════════ */
$_komm_sms_on      = function_exists('sms_channel_ready') ? sms_channel_ready() : false;
$_komm_instructors = k30_ti_instructors();

// Dane podglądu przekazane z bloku POST (lub puste przy GET)
$_kp_done    = $komm_did_preview ?? false;
$_kp_recips  = $komm_recipients  ?? [];
$_kp_label   = $komm_filter_label ?? '';
$_kp_mode    = $komm_mode       ?? 'grupa';
$_kp_cids    = $komm_course_ids ?? [];
$_kp_instr   = $komm_instr_id   ?? 0;
$_kp_date    = $komm_date       ?? '';
$_kp_email   = $komm_ch_email   ?? false;
$_kp_sms     = $komm_ch_sms     ?? false;
$_kp_guard   = $komm_ch_guard   ?? false;
$_kp_subject = $komm_subject    ?? '';
$_kp_body    = $komm_body       ?? '';
$_kp_bhtml   = $komm_body_html  ?? '';

$_komm_log   = db_all(
    "SELECT l.*, u.name AS by_name
     FROM k30_ti_comm_log l LEFT JOIN users u ON u.id=l.created_by
     ORDER BY l.id DESC LIMIT 15"
);
?>
<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">

<div class="mb-3">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-send text-primary me-2" aria-hidden="true"></i>Komunikacja — e-mail / SMS
  </h5>
</div>

<?php if (!$_komm_sms_on): ?>
<div class="alert alert-info py-2 small mb-3">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Kanał SMS jest wyłączony w ustawieniach systemu — dostępna jest tylko poczta e-mail.
</div>
<?php endif; ?>

<form method="post" action="index.php?tab=komunikacja">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <div class="row g-4">

    <!-- ─── Konfiguracja ─────────────────────────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">
          <i class="bi bi-people me-2" aria-hidden="true"></i>Odbiorcy
        </div>
        <div class="card-body">
          <fieldset class="mb-3">
            <legend class="form-label fw-semibold">Filtr odbiorców</legend>
            <div class="form-check">
              <input class="form-check-input komm-mode" type="radio" name="mode" id="km_grupa" value="grupa" <?= $_kp_mode==='grupa'?'checked':'' ?>>
              <label class="form-check-label" for="km_grupa">Per grupa (kurs)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input komm-mode" type="radio" name="mode" id="km_prow" value="prowadzacy" <?= $_kp_mode==='prowadzacy'?'checked':'' ?>>
              <label class="form-check-label" for="km_prow">Per prowadzący</label>
            </div>
            <div class="form-check">
              <input class="form-check-input komm-mode" type="radio" name="mode" id="km_dzien" value="dzien" <?= $_kp_mode==='dzien'?'checked':'' ?>>
              <label class="form-check-label" for="km_dzien">Per dzień (lekcje danego dnia)</label>
            </div>
          </fieldset>

          <div class="komm-pane" data-mode="grupa" <?= $_kp_mode!=='grupa'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="komm_course_ids">
              Grupy / kursy <span class="text-muted small">(wiele — Ctrl/Cmd)</span>
            </label>
            <select class="form-select" id="komm_course_ids" name="course_ids[]" multiple size="8">
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'],$_kp_cids,true)?'selected':'' ?>>
                <?= h($c['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="komm-pane" data-mode="prowadzacy" <?= $_kp_mode!=='prowadzacy'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="komm_instructor_id">Prowadzący</label>
            <select class="form-select" id="komm_instructor_id" name="instructor_id">
              <option value="">— wybierz —</option>
              <?php foreach ($_komm_instructors as $i): ?>
              <option value="<?= (int)$i['id'] ?>" <?= $_kp_instr===(int)$i['id']?'selected':'' ?>>
                <?= h($i['name'] ?: $i['email']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Wiadomość trafi do kursantów ze wszystkich kursów tego prowadzącego.</div>
          </div>

          <div class="komm-pane" data-mode="dzien" <?= $_kp_mode!=='dzien'?'hidden':'' ?>>
            <label class="form-label fw-semibold" for="komm_date">Dzień zajęć</label>
            <input type="date" class="form-control" id="komm_date" name="date" value="<?= h($_kp_date) ?>" style="max-width:14rem">
            <div class="form-text">Wiadomość trafi do kursantów z kursów, które mają lekcję tego dnia.</div>
          </div>

          <hr class="my-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ch_guardians" id="komm_guardians" value="1" <?= $_kp_guard?'checked':'' ?>>
            <label class="form-check-label" for="komm_guardians">
              <i class="bi bi-people-fill me-1" aria-hidden="true"></i>Uwzględnij też rodziców / opiekunów małoletnich
            </label>
            <div class="form-text">Dodaje kontakt opiekuna każdego małoletniego kursanta z wybranego filtra.</div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header fw-semibold">
          <i class="bi bi-envelope me-2" aria-hidden="true"></i>Wiadomość
        </div>
        <div class="card-body">
          <fieldset class="mb-2">
            <legend class="form-label fw-semibold">Kanał</legend>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="ch_email" id="komm_ch_email" value="1" <?= $_kp_email||!$_kp_done?'checked':'' ?>>
              <label class="form-check-label" for="komm_ch_email">
                <i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail
              </label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="ch_sms" id="komm_ch_sms" value="1" <?= $_kp_sms?'checked':'' ?> <?= !$_komm_sms_on?'disabled':'' ?>>
              <label class="form-check-label" for="komm_ch_sms">
                <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS<?= !$_komm_sms_on?' (wyłączony)':'' ?>
              </label>
            </div>
          </fieldset>

          <!-- ── Szablon: informacja o logowaniu ── -->
          <div class="mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start d-flex align-items-center"
                    data-bs-toggle="collapse" data-bs-target="#komm-tmpl" aria-expanded="false">
              <i class="bi bi-journal-text me-2" aria-hidden="true"></i>Szablony wiadomości
              <i class="bi bi-chevron-down ms-auto komm-tmpl-chevron"></i>
            </button>
            <div class="collapse" id="komm-tmpl">
              <div class="card border-0 bg-body-secondary mt-1 p-3">
                <p class="fw-semibold mb-2 small">Informacja o logowaniu do panelu TI</p>
                <div class="mb-2">
                  <label class="form-label small mb-1" for="komm-zoom-link">Link Zoom (awaryjny) — opcjonalny</label>
                  <input type="text" class="form-control form-control-sm" id="komm-zoom-link" placeholder="https://us06web.zoom.us/j/...">
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="komm-tmpl-login-btn">
                  <i class="bi bi-magic me-1" aria-hidden="true"></i>Wstaw do edytora
                </button>
              </div>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold" for="komm_subject">
              Temat <span class="text-muted small">(e-mail)</span>
            </label>
            <input type="text" class="form-control" id="komm_subject" name="subject"
                   value="<?= h($_kp_subject) ?>" maxlength="200" placeholder="np. Zmiana terminu zajęć">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">
              Treść <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <div id="komm-quill" style="min-height:9rem;background:#fff;color:#0f172a;border:1px solid var(--bs-border-color);border-radius:.375rem"></div>
            <textarea id="komm_body" name="body" class="d-none" aria-hidden="true"><?= h($_kp_body) ?></textarea>
            <input type="hidden" name="body_html" id="komm_body_html" value="<?= h($_kp_bhtml) ?>">
            <div class="form-text mt-1" id="komm-sms-note" style="display:none">
              <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS używa wersji tekstowej (bez formatowania).
            </div>
            <div class="form-text" id="komm-email-note">Formatowanie zostanie zachowane w e-mailu.</div>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" name="_op" value="komm_preview" class="btn btn-outline-primary">
              <i class="bi bi-people me-1" aria-hidden="true"></i>Pokaż odbiorców
            </button>
            <button type="submit" name="_op" value="komm_send" class="btn btn-primary"
                    <?= $_kp_done && $_kp_recips ? '' : 'disabled' ?>
                    onclick="return confirm('Wysłać wiadomość do <?= count($_kp_recips) ?> odbiorców?')">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
            </button>
          </div>
          <?php if (!$_kp_done): ?>
          <div class="form-text mt-1">Najpierw „Pokaż odbiorców", aby odblokować wysyłkę.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ─── Podgląd odbiorców ─────────────────────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold d-flex align-items-center">
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Odbiorcy
          <?php if ($_kp_done): ?>
          <span class="badge bg-secondary ms-2"><?= count($_kp_recips) ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$_kp_done): ?>
          <p class="text-muted mb-0">Wybierz filtr i kliknij „Pokaż odbiorców".</p>
          <?php elseif (!$_kp_recips): ?>
          <p class="text-muted mb-0">Brak odbiorców dla wybranego filtra (<?= h($_kp_label) ?>).</p>
          <?php else:
            $_kp_no_email = count(array_filter($_kp_recips, fn($r)=>trim((string)$r['email'])===''));
            $_kp_no_phone = count(array_filter($_kp_recips, fn($r)=>trim((string)$r['phone'])===''));
          ?>
          <div class="small text-muted mb-2"><?= h($_kp_label) ?></div>
          <?php if ($_kp_email && $_kp_no_email): ?>
          <div class="small text-warning-emphasis mb-1">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= $_kp_no_email ?> bez adresu e-mail.
          </div>
          <?php endif; ?>
          <?php if ($_kp_sms && $_kp_no_phone): ?>
          <div class="small text-warning-emphasis mb-2">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= $_kp_no_phone ?> bez numeru telefonu.
          </div>
          <?php endif; ?>
          <div class="table-responsive" style="max-height:24rem;overflow:auto">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th scope="col">Odbiorca</th>
                  <?php if ($_kp_guard): ?><th scope="col">Rola</th><?php endif; ?>
                  <th scope="col">E-mail</th>
                  <th scope="col">Telefon</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($_kp_recips as $r): ?>
                <tr>
                  <td class="fw-semibold"><?= h($r['name']) ?></td>
                  <?php if ($_kp_guard): ?>
                  <td class="small">
                    <?= ($r['role'] ?? 'kursant') === 'opiekun'
                        ? '<span class="badge text-bg-info">Opiekun</span>'
                        : '<span class="badge text-bg-secondary">Kursant</span>' ?>
                  </td>
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

<!-- ─── Historia wysyłek ──────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Ostatnie wysyłki
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.88rem">
      <thead class="table-light">
        <tr>
          <th>Data</th><th>Kanał</th><th>Filtr</th><th>Temat</th>
          <th class="text-end">Odb.</th><th class="text-end">OK</th><th class="text-end">Błędy</th><th>Przez</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$_komm_log): ?>
        <tr><td colspan="8" class="text-center text-muted py-3">Brak wysyłek.</td></tr>
        <?php endif; ?>
        <?php foreach ($_komm_log as $l): ?>
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

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function(){
  // Filtr trybu
  function syncKommMode(){
    var mode = (document.querySelector('.komm-mode:checked')||{}).value || 'grupa';
    document.querySelectorAll('.komm-pane').forEach(function(p){
      p.hidden = (p.getAttribute('data-mode') !== mode);
    });
  }
  document.querySelectorAll('.komm-mode').forEach(function(r){ r.addEventListener('change', syncKommMode); });
  syncKommMode();

  // Quill
  var quill = new Quill('#komm-quill', {
    theme: 'snow',
    placeholder: 'Treść wiadomości…',
    modules: { toolbar: [
      ['bold','italic','underline'],
      [{ list:'ordered' },{ list:'bullet' }],
      ['link'],
      ['clean']
    ]}
  });
  var initHtml = document.getElementById('komm_body_html').value;
  var initText = document.getElementById('komm_body').value;
  if (initHtml) { quill.root.innerHTML = initHtml; }
  else if (initText) { quill.setText(initText); }

  // Sync pola ukryte przed wysłaniem
  document.querySelector('form[action="index.php?tab=komunikacja"]').addEventListener('submit', function(){
    document.getElementById('komm_body_html').value = quill.root.innerHTML;
    document.getElementById('komm_body').value = quill.getText().trim();
  });

  // Nota SMS
  function syncSmsNote(){
    var smsOn = document.getElementById('komm_ch_sms') && document.getElementById('komm_ch_sms').checked;
    var n = document.getElementById('komm-sms-note');
    var e = document.getElementById('komm-email-note');
    if (n) n.style.display = smsOn ? '' : 'none';
    if (e) e.style.display = smsOn ? 'none' : '';
  }
  var smsBox = document.getElementById('komm_ch_sms');
  if (smsBox) smsBox.addEventListener('change', syncSmsNote);
  syncSmsNote();

  // Szablon logowania
  var tmplBtn = document.getElementById('komm-tmpl-login-btn');
  if (tmplBtn) {
    tmplBtn.addEventListener('click', function(){
      var zoom = (document.getElementById('komm-zoom-link').value || '').trim();
      var zoomBlock = zoom
        ? '<p>🔗 <a href="' + zoom + '">' + zoom + '</a></p>'
        : '<p>🔗 <em>[Wklej tutaj link do spotkania Zoom]</em></p>';
      document.getElementById('komm_subject').value = 'Informacja o logowaniu do panelu TI';
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
        '<p>Prosimy o zachowanie powyższych odnośników na wypadek ewentualnych trudności technicznych.</p>',
        '<p>Z poważaniem,<br>Zespół Fundacji FEER</p>'
      ].join('');
      var c = document.getElementById('komm-tmpl');
      if (c && c.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(c).hide();
    });
    var tmplEl = document.getElementById('komm-tmpl');
    if (tmplEl) {
      tmplEl.addEventListener('show.bs.collapse', function(){
        document.querySelector('.komm-tmpl-chevron').style.transform = 'rotate(180deg)';
      });
      tmplEl.addEventListener('hide.bs.collapse', function(){
        document.querySelector('.komm-tmpl-chevron').style.transform = '';
      });
    }
  }
})();
</script>
